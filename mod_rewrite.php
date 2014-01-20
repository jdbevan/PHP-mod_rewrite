<?php

require_once './globals.class.php';
require_once './consts.inc.php';

// Server-variables
$server_vars = array(
"HTTP_USER_AGENT"	=> Globals::SERVER('HTTP_USER_AGENT', USER_AGENT_CHROME_LINUX),
"HTTP_REFERER"		=> Globals::POST('HTTP_REFERER', ""),
"HTTP_COOKIE"		=> false,
"HTTP_FORWARDED"	=> false,
"HTTP_HOST"		=> parse_url( Globals::POST('URL', ''), PHP_URL_HOST ),
"HTTP_PROXY_CONNECTION"	=> false,
"HTTP_ACCEPT"		=> Globals::SERVER('HTTP_ACCEPT', false),
"REMOTE_ADDR"		=> Globals::SERVER('REMOTE_ADDR', ""),
"REMOTE_HOST"		=> Globals::POST('REMOTE_HOST', false),
"REMOTE_PORT"		=> Globals::SERVER('REMOTE_PORT', mt_rand(49152,65535)),
"REMOTE_USER"		=> false,
"REMOTE_IDENT"		=> false,
"REQUEST_METHOD"	=> Globals::POST('REQUEST_METHOD', 'GET'),
"SCRIPT_FILENAME"	=> parse_url( Globals::POST('URL', ''), PHP_URL_PATH ),
"PATH_INFO"		=> false,
"QUERY_STRING"		=> parse_url( Globals::POST('URL', ''), PHP_URL_QUERY ),
"AUTH_TYPE"		=> false,
"DOCUMENT_ROOT"		=> Globals::POST('DOCUMENT_ROOT', false),
"SERVER_ADMIN"		=> false,
"SERVER_NAME"		=> false,
"SERVER_ADDR"		=> Globals::POST('SERVER_ADDR', false),
"SERVER_PORT"		=> parse_url( Globals::POST('URL', ''), PHP_URL_SCHEME ) == "https" ? 443 : 80,
"SERVER_PROTOCOL"	=> Globals::POST('HTTP_VERSION', 'HTTP/1.1'),
"SERVER_SOFTWARE"	=> Globals::POST('SERVER_SOFTWARE', false),
"TIME_YEAR"		=> (int)date("Y"),
"TIME_MON"		=> (int)date("n"),
"TIME_DAY"		=> (int)date("j"),
"TIME_HOUR"		=> (int)date("G"),
"TIME_MIN"		=> (int)date("i"),
"TIME_SEC"		=> (int)date("s"),
"TIME_WDAY"		=> (int)date("N"),
"TIME"			=> date("H:i:s"),
"API_VERSION"		=> Globals::POST('API_VERSION', false),
//"THE_REQUEST",
//"REQUEST_URI",
//"REQUEST_FILENAME",
"IS_SUBREQ"		=> "false",
//"HTTPS"			=> parse_url( Globals::POST('URL', ''), PHP_URL_SCHEME ) == "https" ? "on" : "off",
"REQUEST_SCHEME"	=> parse_url( Globals::POST('URL', ''), PHP_URL_SCHEME )
);

$server_vars["HTTPS"]		= $server_vars["REQUEST_SCHEME"] == "https" ? "on" : "off";
$server_vars["REQUEST_URI"]	= $server_vars["SCRIPT_FILENAME"];
$server_vars["REQUEST_FILENAME"] = $server_vars["SCRIPT_FILENAME"];
$server_vars["REQUEST_URI"]	= $server_vars["REQUEST_METHOD"] . " " . $server_vars["REQUEST_URI"];
$server_vars["REQUEST_URI"]	.= !empty($server_vars["QUERY_STRING"]) ? "?" . $server_vars["QUERY_STRING"] : "";
$server_vars["REQUEST_URI"]	.= " " . $server_vars["SERVER_PROTOCOL"];


$directives = array(
"RewriteBase" => "/^\s*RewriteBase\s+(\/.*?)\s*$/",
"RewriteEngine" => "/^\s*RewriteEngine\s+(on|off)\s*$/i",
"RewriteCond" => "/^\s*RewriteCond\s+([^\s]+)\s+(.+)\s*$/",
"RewriteMap" => false,
"RewriteOptions" => false,
"RewriteRule" => "/^\s*RewriteRule\s+(.+?)\s+(.+?)(\s+\[[\w,:!-]+\])?\s*$/"
);

$flags = array(
"B",
"C",
"DPI",
"E",
"F",
"G",
"H",
"L",
"N",
"NC",
"NE",
"NS",
"P",
"PT",
"QSA",
"QSD",
"R",
"END",
"S",
"T"
);

$sample_htaccess = <<<EOS
RewriteEngine On
RewriteBase /

RewriteCond %{HTTP_HOST} ^domain.com
RewriteRule (.*) http://www.domain.com/$1 [NC,L,R=301]

RewriteCond %{THE_REQUEST} ^POST
RewriteCond %{REQUEST_URI} (.*)
RewriteRule . /api/post/%1	[L,R=301]
EOS;

// ----------------------------------------

function matches_directive($line, $directives) {
    $trimmed = trim($line);
    $match = false;
    foreach ($directives as $directive_name => $line_regex) {
        $directive_regex = "/^$directive_name/";
        if (preg_match($directive_regex, $trimmed)) {
            if ($line_regex === false) {
				$match = true;
                echo "# Directive: $directive_name is not supported yet\n";
            } else if ( preg_match($line_regex, $trimmed, $matches) ) {
				$match = true;
                echo "# ", str_replace(array("\r\n", "\r", "\n"), "", var_export($matches, true)), "\n";
            } else {
                echo "# Directive syntax error/regex error...\n";
            }
            break;
        }
    }
    return $match;
}

$lines = explode("\n", $sample_htaccess);

echo "<pre>\n";

foreach($lines as $line) {

    // Does it match a directive
    if (matches_directive($line, $directives)) {
        //
    }

    echo $line, "\n";
}

echo "</pre>\n";

?>
