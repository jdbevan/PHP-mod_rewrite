PHP parser for mod_rewrite .htaccess rules

Code based on: http://svn.apache.org/repos/asf/httpd/httpd/trunk/modules/mappers/mod_rewrite.c

**Preview**

![preview](https://github.com/jdbevan/PHP-mod_rewrite/raw/master/img/Screenshot\ from\ 2014-02-19\ 23:48:29.png "Preview from 2014-02-19")

**Instructions**

* Fork project and `git checkout`/download PHP files.
* Run `php -S localhost:8080` in the directory your files are in
* Browse to http://localhost:8080/mod_rewrite.php

**Features**

It's easier to list the things that aren't supported:

* Any modules that are not mod_rewrite - including the core Apache module and mod_alias
* File-based comparisons like `%{REQUEST_FILENAME} -f` [(yet...)](https://github.com/jdbevan/PHP-mod_rewrite/issues/5)
* [RewriteMaps](https://httpd.apache.org/docs/current/mod/mod_rewrite.html#rewritemap) and [RewriteOptions](https://httpd.apache.org/docs/current/mod/mod_rewrite.html#rewriteoptions)
* Environment variables
* SSL variables like `%{SSL:SSL_PROTOCOL}` (but `%{HTTPS}` is supported)
* `%{HTTP_COOKIE}`, `%{HTTP_FORWARDED}`, `%{HTTP_PROXY_CONNECTION}`, `%{REMOTE_USER}`, `%{REMOTE_IDENT}`, `%{PATH_INFO}`, `%{AUTH_TYPE}`, `%{SERVER_ADMIN}` and `%{SERVER_NAME}`

**Bugs**

Create an issue or open a pull request and I'll see what I can do.

Pull requests for extending the functionality of this code outside that of mod_rewrite will probably be declined.

**Online version**

http://htaccess.jdbevan.com

**License**

[Original mod_rewrite Apache 2.0 license](http://www.apache.org/licenses/LICENSE-2.0)

