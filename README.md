# zabbix-webcrawler
A webcrawler designed to be used with zabbix to check the health of a website.

## Work in progress
It's early days still; functional in my setup but treat as experimental.

## Getting started
- Download
- Setup the database ( mysql < schema.sql)
- mv/cp config.php.example config.php
- Test with:
  - php crawl.php "site-url" scan //Returns how many pages were scanned (run multiple times)
  - php crawl.php "site-url" status.urls //Will tell you how many urls have been found
  - php crawl.php "site-url" status.links //Will tell you home many links have been found
  - php crawl.php "site-url" status.deadurls //Will tell you how many urls have returned a status other than 200
  - php crawl.php "site-url" status.deadlinks //Will tell you how many links have return a status other than 200

## Extra commands
- php crawl.php list get a list of websites in the database
- php crawl.php "site-url" clear - remove a website from the database

## Configure in zabbix
- Setup zabbix agent - add the following to zabbix_agent.conf or in a new zabbix_agent.d/.conf file.
 - UserParameter=spider[*],/usr/bin/php <PATH TO crawl.php>/crawl.php $1 $2
- Import the webcrawler template into zabbix (template.xml)
- Add template to hosts - the template expects the host name to be a domain name. In the future this may change to use a URL macro.

## Setup options
You can install this on any server that has the zabbix_agent installed - just configure the agent interface to point to this server
