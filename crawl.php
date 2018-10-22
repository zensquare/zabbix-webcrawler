<?php
if(php_sapi_name() !== 'cli'){
    die("Please run from commandline");
}

define("ZABBIX_CRAWLER", true);

include('config.php');

if (count($argv) <= 1) {
    $action = "help";
} else if (count($argv) == 2) {
    $action = $argv[1];
} else {
    $action = $argv[2];
    $website = cleanWebsiteURL($argv[1]);
    $wid = getWebsiteID($website);
}

if(isset($argv[3]) && $argv[3] == "debug"){
    $debug = true;
    $max_queries = 1;
    $time_limit = 30;
}


$args = array();
if (strpos($action, '.') > 0) {
    $args = explode('.', $action);
    $action = $args[0];
}

switch ($action) {
    case "scan":
        scan($website, $wid);
        break;
    case "status":
        if (function_exists("status_" . $args[1])) {
            call_user_func("status_" . $args[1], $wid, $website);
        } else {
            echo "undefined";
        }
        break;
    case "list":
        listWebsites();
        break;
    case "help":
    default:
        echo "Usage: $argv[0] [website] [action]\n";
        echo "actions - scan, status.deadlinks, status.links";
}
exit();

function listWebsites() {
    $result = mysql_query("SELECT * FROM website");
    while($row = mysql_fetch_assoc($result)) {
        echo $row['aid'] . " : " . $row['name'] . " : " . $row['url'] . PHP_EOL;
    }
}

function scan($website, $wid) {
    global $time_limit, $max_queries;
    $pages = selectURLS($wid, $website, $max_queries);

    $script_start = time();

    $count = 0;
    foreach ($pages as $page) {
        $result = scanPage($page);
        recordAccess($page, $result);
        $count++;
        if (time() - $script_start >= $time_limit) {
            break;
        }
    }

    echo $count;
}

function status_deadurls($wid) {
    statusQuery("SELECT count(*) as stat FROM `page` WHERE aid IN (SELECT child FROM `link`) AND `status` != '200' AND NOT `status` IS NULL AND website = $wid;");
}

function status_deadlinks($wid) {
    statusQuery("SELECT count(*) as stat FROM `page` JOIN `link` ON `page`.aid = `link`.child AND `status` != '200' AND NOT `status` IS NULL AND website = $wid;");
}

function status_links($wid) {
    statusQuery("SELECT count(*) as stat FROM `page` JOIN `link` ON `page`.aid = `link`.child AND `website` = $wid");
}

function status_urls($wid) {
    statusQuery("SELECT count(*) as stat FROM `page` WHERE `website` = $wid");
}

function statusQuery($sql) {
    $result = mysql_query($sql);
    if (mysql_num_rows($result) > 0) {
        $row = mysql_fetch_assoc($result);
        echo $row['stat'];
        return;
    }
    echo 0;
}

function cleanWebsiteURL($url) {
    $url = mysql_real_escape_string($url);
    $prefix = "http://";
    if (preg_match("/^(https?:\/\/)(.+)[\/\s]*/", $url, $matches)) {
        $prefix = $matches[1];
        $url = $matches[2];
    }
    $url = preg_replace("/\/\/+/", "/", $url);

    return $prefix . $url;
}

function getWebsiteID($website) {
    $sql = "SELECT * FROM `website` WHERE url = '$website'";
    $result = mysql_query($sql);
    if (mysql_num_rows($result) > 0) {
        $row = mysql_fetch_assoc($result);
        return $row['aid'];
    }
    
    $name = $website;
    if (preg_match("/^(https?:\/\/)(.+)[\/\s]*/", $website, $matches)) {
        $name = $matches[2];
    }
    
    
    mysql_query("INSERT INTO `website` (name,url) VALUE ('$name','$website')");
    echo mysql_error();

    $wid = mysql_insert_id();
    insertPage($wid, '', 'page');

    return $wid;
}

function insertPage($wid, $url, $type = "", $external=false, $title="", $depth=0) {
    
    $title = cleanTitle($title);
    $type = clean($type);
    $url = clean($url);
    
    $sql = "INSERT INTO `page` (website,url,type,crawl,title,`depth`) VALUE ('$wid','$url','page',".($external?0:1).",'$title','$depth')";
    mysql_query($sql);
    return mysql_insert_id();
}

function cleanTitle($title){
    if(preg_match('/^\s*<img (.*)\/>\s*$/',$title,$matches) ){
        preg_match_all("/\s*(\w+)[\s]*=\s*[\"\'](.*?)[\"\']\s*/", $matches[1], $attributes);
        
        $attributes = array_combine($attributes[1],$attributes[2]);
        if(isset($attributes['src'])){
            $title = "Image Link: ".$attributes['src'];
            if(isset($attributes['height'],$attributes['width'])){
                $title .= " (" .$attributes['width'] ."x".$attributes['height'].")";
            }
            
        }
        if(isset($attributes['title'])){
            $title = "Image Link: ".$attributes['title'];
        }
        if(isset($attributes['alt'])){
            $title = "Image Link: ".$attributes['alt'];
        }
        
    }
    $title = strip_tags($title);
    
    return clean($title);
}

function clean($value){
    if($value == null){
        return "";
    }
    if($value instanceof String){
        return mysql_real_escape_string($value);
    }
    $value = preg_replace("/\s\s+/"," ",$value);
    $value = trim($value);
    
    return $value;
}

function selectURLS($wid, $baseURL, $limit = 30) {
    $sql = "SELECT * FROM `page` WHERE website = '$wid' ORDER BY ts_last, ts_first LIMIT $limit";
    $result = mysql_query($sql);
    $row_count = mysql_num_rows($result);
    if($row_count == 0){
        insertPage($wid, '');
        $sql = "SELECT * FROM `page` WHERE website = '$wid' ORDER BY ts_last, ts_first LIMIT $limit";
        $result = mysql_query($sql);
    }

    $urls = array();
    while ($row = mysql_fetch_assoc($result)) {
        if ($row['url'] == "" || $row['url'][0] == '/') {
            $row['url'] = $baseURL . $row['url'];
        }
        $urls[] = $row;
    }
    
    return $urls;
}

function recordAccess($page, $result) {
    $count = isset($result['count']) ? $result['count'] : 0;
    $code = isset($result['code']) ? $result['code'] : -1;
    $size = isset($result['size']) ? $result['size'] : -1;
    $duration = isset($result['duration']) ? $result['duration'] : -1;

    $sql = "INSERT INTO `access` (page,size,duration,link_count,response) VALUE ('$page[aid]','$size','$duration','$count','$code')";
    mysql_query($sql);
    echo mysql_error();

    $type = "";
    if (isset($result['type'])) {
        $type = ",type = '$result[type]'";
        if (!preg_match('/text\/html/', $result['type'])) {
            $type .= ", crawl=0";
        }
    }
    
    if(!empty($result['title'])){
        $type .= ", title=\"".clean($result['title'])."\" ";
    }

    $sql = "UPDATE `page` SET ts_last = CURRENT_TIMESTAMP, status = '".clean($result['code'])."' $type WHERE aid = $page[aid]";
    mysql_query($sql);
    debug(mysql_error());
}

function insertLink($parent, $url, $title="", $external=false) {
    if($url == "/"){
        $url = "";
    }
    
    if($title == "" ){
        $title = $url;
    }
    $depth = $parent['depth']+1;
    
    $title = cleanTitle($title);
    //Get an existing page if it exists
    $sql = "SELECT aid, depth FROM `page` WHERE url = '$url' AND website = '$parent[website]'";
    $query = mysql_query($sql);
    $aid = 0;
    if (mysql_num_rows($query) > 0) {
        $row = mysql_fetch_assoc($query);
        $aid = $row['aid'];
        if($row['depth']>$depth){
            $sql = "UPDATE `page` SET `depth` = '$depth' WHERE aid = $row[aid]";
            mysql_query($sql);
        }
    } else {
        $aid = insertPage($parent['website'], $url,"", $external,$title, $depth);
    }
    
    $sql = "INSERT IGNORE INTO `link` (parent,child,link_title,depth) VALUE ($parent[aid],$aid, '$title','$depth')";
    mysql_query($sql);
    echo mysql_error();
    
    $sql = "SELECT COUNT(*) as links, parent FROM `link` WHERE `child` = $aid AND link_title = '$title' ORDER BY `depth`";
    $result = mysql_query($sql);
    $row = mysql_fetch_assoc($result);
    if($row['links'] > 5){
        $sql = "UPDATE `link` SET `hidden` = 1 WHERE `parent` != $row[parent] AND `child` = $aid";
        mysql_query($sql);
        echo mysql_error();
    }
    
}

function scanPage($page) {
    global $website, $proxy;
    $url = str_replace(" ","%20",$page['url']);
    
    $ref = "http://".$website;
    debug("Scanning $url\n");

    //Clear old links
    $sql = "DELETE FROM links WHERE parent = $page[aid]";
    mysql_query($sql);

    $start = microtime(true);
    $user_agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
    curl_setopt($ch, CURLOPT_HTTPGET, 1);
    if(!empty($proxy)){
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    if ($page['crawl'] == 0) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
        debug("No body : ");
    }
    
    curl_setopt($ch, CURLOPT_HEADER, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_REFERER, $ref);
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
    $html = curl_exec($ch);
    debug("Return length : ".strlen($html)."    Status Code: ".curl_getinfo($ch, CURLINFO_HTTP_CODE)."\n");
    
    
    $duration = (microtime(true) - $start);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    
    debug($effective_url.PHP_EOL);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        $title = $url;
        try {
            curl_close($ch);
        } catch (Exception $exc) {
            
        }
        return array("success" => false, "title"=>$title, "size" => $size, "duration" => $duration, "error_msg" =>$error , "code" => $code, "type" => $type);
    }

    try {
        curl_close($ch);
    } catch (Exception $exc) {
        
    }
    
    if(preg_match("/<\s*title[^>]*>\s*(.+)\s*<\/\s*title\s*>/ims", $html, $matches_title)){
        $title = preg_replace("/\s\s+/"," ",$matches_title[1]);
        debug("Page title: $title\n");
    } else {
        $title = NULL;
    }
    
    if ($page['crawl'] == 0) {
        return array("success" => true, "size" => $size, "duration" => $duration, "code" => $code, "type" => $type);
    }


    preg_match_all("/a[\s]+[^>]*?href[\s]?=[\s\"\']+" .
            "(.*?)[\"\']+.*?>" . "([^<]+|.*?)?<\/a>/", $html, $matches);


    $titles = $matches[2];
    $matches = $matches[1];
    $list = array();

    reset($titles);
    foreach ($matches as $var) {
        $link_title = current($titles);
        next($titles);
        if(strpos($var,"#") !== FALSE) {
            debug("Before: $var      ");
            $var = preg_replace("/#.+$/","",$var);
            debug("After: $var\n");
        }
        if(empty($var)){
            continue;
        }
        debug("URL: $var\n");
        if(preg_match("/^https?:\/\/([^\/]+)(.*)?$/", $var, $url)){
            $domain = $url[1];
            $link = $url[2];
            debug("$domain | $link\n");
            if($domain == $website){
                insertLink($page, $link, $link_title);
            } else {
                insertLink($page, $var, $link_title, true);
            }
        } else {
            if (!preg_match("/^\//", $var)) {
                $var = $effective_url."/".$var;
            }
            insertLink($page, $var, $link_title);
        }   
            
        debug("Final URL: $var\n");
    }
    preg_match_all("/<link[^>]+>/i", $html, $matches);

    $matches = $matches[0];
    debug("Links found" . count($matches) . PHP_EOL);

    foreach ($matches as $var) {
        $url = "";
        $ltype = "";
        
            debug("Link : " . $var . PHP_EOL);
        if(preg_match("/href=\s*('[^']*'|\"[^\"]*\")/",$var, $m)){
            $url = substr($m[1],1,-1);
        } else {
            continue;
        }
        if(preg_match("/rel=\s*('[^']*'|\"[^\"]*\")/",$var, $m)){
            $ltype = substr($m[1],1,-1) . " -";
        }
        if(preg_match("/type=\s*('[^']*'|\"[^\"]*\")/",$var, $m)){
            $ltype = substr($m[1],1,-1) . " -";
        }
        debug("Link URL: $ltype $url\n");
        
        if(preg_match("/^https?:\/\/([^\/]+)(.*)?$/", $var, $url_match)){
            $domain = $url_match[1];
            $link = $url_match[2];
            debug("$domain | $link\n");
            if($domain == $website){
                insertLink($page, $link, "Resource: $ltype " .  array_pop(explode('/',$link)));
            } else {
                insertLink($page, $url[2], "Resource: $ltype " .  array_pop(explode('/',$link)), true); 
            }
        } else {
            insertLink($page, $url,  "Resource: $ltype " . array_pop(explode('/',$url)));
        }
    }
    
    if(preg_match('/Error:?\s*(\d)/',$title,$matches)){
        $code = $matches[1];
    }
    
    return array("success" => true, "size" => $size, "title"=>$title, "duration" => $duration, "links" => count($matches), "code" => $code, "type" => $type);
}

function debug($message) {
    global $debug;
    if ($debug) {
        echo $message;
    }
}
