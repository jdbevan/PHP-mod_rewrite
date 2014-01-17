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
"REMOTE_PORT"		=> Globals::SERVER('REMOTE_PORT'),
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

$sample_htaccess = <<<EOS

EOS;

?>
