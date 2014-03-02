<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>mod_rewrite.php</title>
    <link rel="stylesheet" href="css/styles.css" />
</head>
<?php flush(); ?>
<body>
<?php
error_reporting(E_ALL);
ini_set("display_errors", true);

require_once './autoload.php';
require_once './consts.inc.php';
require_once './utils.inc.php';
require_once './rewrite_cond.php';
require_once './rewrite_rule.php';

$parsed_url = parse_url( Globals::POST('URL', DEFAULT_URL) );

// Server-variables
// TODO: turn into iterable class with magic gets
// should take $_POST and $_SERVER as constructor args
$server_vars = array(
	"HTTP_COOKIE"		=> false,
	"HTTP_FORWARDED"	=> false,
	"HTTP_PROXY_CONNECTION"	=> false,
	"REMOTE_USER"		=> false,
	"REMOTE_IDENT"		=> false,
	"PATH_INFO"			=> false,
	"AUTH_TYPE"			=> false,
	"SERVER_ADMIN"		=> false,
	"SERVER_NAME"		=> false,
	"HTTP_HOST"			=> empty($parsed_url['host']) ? '' : $parsed_url['host'],
	"SCRIPT_FILENAME"	=> empty($parsed_url['path']) ? "/" : $parsed_url['path'],
	"QUERY_STRING"		=> empty($parsed_url['query']) ? "" : $parsed_url['query'],
	"SERVER_PORT"		=> isset($parsed_url['scheme']) and $parsed_url['scheme'] == "https" ? 443 : 80,
	"REQUEST_SCHEME"	=> isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'http',
	"HTTP_USER_AGENT"	=> Globals::SERVER('HTTP_USER_AGENT', Globals::POST('USER_AGENT', USER_AGENT_CHROME_LINUX) ),
	"HTTP_ACCEPT"		=> Globals::SERVER('HTTP_ACCEPT', false),
	"REMOTE_ADDR"		=> Globals::SERVER('REMOTE_ADDR', ""),
	"REMOTE_PORT"		=> Globals::SERVER('REMOTE_PORT', mt_rand(49152,65535)),
	"HTTP_REFERER"		=> Globals::POST('HTTP_REFERER', ""),
	"REMOTE_HOST"		=> Globals::POST('REMOTE_HOST', false),
	"REQUEST_METHOD"	=> Globals::POST('REQUEST_METHOD', 'GET'),
	"DOCUMENT_ROOT"		=> Globals::POST('DOCUMENT_ROOT', false),
	"SERVER_ADDR"		=> Globals::POST('SERVER_ADDR', false),
	"SERVER_PROTOCOL"	=> Globals::POST('HTTP_VERSION', 'HTTP/1.1'),
	"SERVER_SOFTWARE"	=> Globals::POST('SERVER_SOFTWARE', false),
	"API_VERSION"		=> Globals::POST('API_VERSION', false),
	"TIME_YEAR"			=> (int)date("Y"),
	"TIME_MON"			=> (int)date("n"),
	"TIME_DAY"			=> (int)date("j"),
	"TIME_HOUR"			=> (int)date("G"),
	"TIME_MIN"			=> (int)date("i"),
	"TIME_SEC"			=> (int)date("s"),
	"TIME_WDAY"			=> (int)date("N"),
	"TIME"				=> date("H:i:s"),
	"IS_SUBREQ"			=> "false"
);

$server_vars["HTTPS"]			= $server_vars["REQUEST_SCHEME"] == "https" ? "on" : "off";
$server_vars["REQUEST_URI"]		= $server_vars["SCRIPT_FILENAME"];
$server_vars["REQUEST_FILENAME"] = $server_vars["SCRIPT_FILENAME"];
$server_vars["THE_REQUEST"]		= $server_vars["REQUEST_METHOD"] . " " . $server_vars["REQUEST_URI"];
$server_vars["THE_REQUEST"]		.= !empty($server_vars["QUERY_STRING"]) ? "?" . $server_vars["QUERY_STRING"] : "";
$server_vars["THE_REQUEST"]		.= " " . $server_vars["SERVER_PROTOCOL"];


$directives = array(
	"RewriteBase" => "/^\s*RewriteBase\s+(\/.*?)\s*$/",
	"RewriteEngine" => "/^\s*RewriteEngine\s+(on|off)\s*$/i",
	"RewriteCond" => true,
	"RewriteMap" => false,
	"RewriteOptions" => false,
	"RewriteRule" => true
);

$sample_htaccess = <<<EOS
RewriteEngine On
RewriteBase /

# Comment comment comment
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP_HOST} ^domain.com
RewriteRule (.*) http://www.domain.com/$1 [NC,L,R=301]

RewriteCond %{THE_REQUEST} ^GET
RewriteCond %{REQUEST_URI} !api
RewriteCond %{REQUEST_URI} (.*)
RewriteRule . /api/post/%1	[L,R=301]
EOS;


// Process stuff
$htaccess = Globals::POST("HTACCESS_RULES", $sample_htaccess);
$orig_url = Globals::POST('URL', DEFAULT_URL);
$old_url  = $orig_url;

$output_table		= array();
$htaccess_line_count = 0;

$orig_lines			= explode("\n", $htaccess);
$lines				= $orig_lines;
$total_lines		= count($lines);
$inside_directive	= 0;
$rewriteConds		= array();
$num_restarts		= 0;
$max_restarts		= 5;

while($htaccess_line_count < $total_lines) {
	$line = $lines[ $htaccess_line_count ];
	// Is it a comment?
	if (preg_match("/^\s*#/", $line)) {
		// output("No comment...", $htaccess_line_count, LOG_COMMENT);

	// Is it another module directive?
	} else if (preg_match("/^\s*<(\/?)(.*)>\s*$/", $line, $match)) {

		if ( ! preg_match("/IfModule\s+mod_rewrite/i", $match[2])) {
			if ($match[1] == "/") {
				if (--$inside_directive === 0) {
					output("Finally! Back to business...", $htaccess_line_count, LOG_FAILURE);
				} else {
					output("Ignoring...", $htaccess_line_count, LOG_FAILURE);
				}
			} else {
				output("Unknown directive, Ignoring...", $htaccess_line_count, LOG_FAILURE);
				$inside_directive++;
			}
		} else {
			output("This is kind of assumed :)", $htaccess_line_count, LOG_HELP);
		}

	// Does it match a directive
	} else if ($inside_directive < 1) {
	
		// Returns true or false if directory
		$new_url = consume_directives($line, $directives, $htaccess_line_count,
										$server_vars, $rewriteConds);
		
		if (is_array($new_url)) {
		
			output("Old URL: " . $old_url,				$htaccess_line_count, LOG_URL);
			output("New URL: " . $new_url['new_url'],	$htaccess_line_count, LOG_URL);		
			if ($new_url['new_url'] === $old_url) {
				output("WARNING: OLD AND NEW URLS MATCH", $htaccess_line_count, LOG_FAILURE);
			}
			
			$new_host = parse_url($new_url['new_url'], PHP_URL_HOST);
            // TODO: should this check the original URL (for preventing parsing redirects to external URLs)
            // or should it check the old URLs
			$orig_host = parse_url($orig_url, PHP_URL_HOST);
			$hosts_match = false;
			if (!empty($new_host) and
				!empty($orig_host) and
				(stripos($new_host, $orig_host)!==false or stripos($orig_host, $new_host)!==false))
			{
				$hosts_match = true;
			}
            $old_url = $new_url['new_url'];
			
			if ($new_url['flags'] & FLAG_RULE_LAST or $new_url['flags'] & FLAG_RULE_END) {
                if ( ! $hosts_match ) {
                    output("STOPPING... REDIRECT TO EXTERNAL SITE...", $htaccess_line_count, LOG_COMMENT);
                    break;
                } else if ($num_restarts < $max_restarts) {
					output("REPROCESSING NEW URL....................................", $htaccess_line_count, LOG_URL);
					// Overwrite remaining htaccess lines, read for re-parsing
					$lines = array_merge(array_slice($lines, 0, $htaccess_line_count + 1), $orig_lines);
					$total_lines = count($lines);
					$num_restarts++;

					$parsed_url = parse_url($new_url['new_url']);
					$server_vars["HTTP_HOST"]		= empty($parsed_url['host']) ? '' : $parsed_url['host'];
					$server_vars["SCRIPT_FILENAME"]	= empty($parsed_url['path']) ? "/" : $parsed_url['path'];
					$server_vars["QUERY_STRING"]	= empty($parsed_url['query']) ? "" : $parsed_url['query'];
					$server_vars["SERVER_PORT"]		= isset($parsed_url['scheme']) and $parsed_url['scheme'] == "https" ? 443 : 80;
					$server_vars["REQUEST_SCHEME"]	= isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'http';
					$server_vars["HTTPS"]			= $server_vars["REQUEST_SCHEME"] == "https" ? "on" : "off";
					$server_vars["REQUEST_URI"]		= $server_vars["SCRIPT_FILENAME"];
					$server_vars["REQUEST_FILENAME"] = $server_vars["SCRIPT_FILENAME"];
					$server_vars["THE_REQUEST"]		= $server_vars["REQUEST_METHOD"] . " " . $server_vars["REQUEST_URI"];
					$server_vars["THE_REQUEST"]		.= !empty($server_vars["QUERY_STRING"]) ? "?" . $server_vars["QUERY_STRING"] : "";
					$server_vars["THE_REQUEST"]		.= " " . $server_vars["SERVER_PROTOCOL"];

				} else {
					output("STOPPING... TOO MANY REDIRECTS...........................", $htaccess_line_count, LOG_FAILURE);
					break;
				}
			}
		}
	} else if ($inside_directive > 0) {
		output("Ignoring...", $htaccess_line_count, LOG_FAILURE);

	} else {
		output("Unknown directive", $htaccess_line_count, LOG_FAILURE);
	}

	output($line, $htaccess_line_count);
	$htaccess_line_count++;
}

$request_method = Globals::POST('REQUEST_METHOD', 'GET');
$request_methods = array("GET", "POST", "HEAD", "PUT", "DELETE", "OPTIONS", "TRACE", "CONNECT");
?>
<div id="outer-container">
    <div id="inner-container">
        <div id="col-left">
			<h1>mod_rewrite in PHP</h1>
            <a href="#" id="show-hide-help">show/hide help</a>
            <div id="blurb" <?php echo !empty($_POST)?"style='display:none;'":"";?>>
                <p>This is a partial (but fairly complete) implementation of <a href="https://httpd.apache.org/docs/current/mod/mod_rewrite.html">Apache's mod_rewrite</a> in a PHP web page for testing/debugging purposes.</p>
                <p>This implementation assumes the rules are in an .htaccess file in the specified document root</p>
                <p>It's easier to list the things that <strong>aren't</strong> supported.</p>
                <ul>
                    <li>Any modules that are not mod_rewrite - including the <a href="https://httpd.apache.org/docs/current/mod/core.html">core Apache module</a> and <a href="https://httpd.apache.org/docs/current/mod/mod_alias.html">mod_alias</a></li>
                    <li>File-based comparisons like <code>%{REQUEST_FILENAME} -f</code> (yet...)</li>
                    <li><a href="https://httpd.apache.org/docs/current/mod/mod_rewrite.html#rewritemap">RewriteMaps</a> and <a href="https://httpd.apache.org/docs/current/mod/mod_rewrite.html#rewriteoptions">RewriteOptions</a></li>
                    <li>Environment variables</li>
                    <li>SSL variables like <code>%{SSL:SSL_PROTOCOL}</code>, but <code>%{HTTPS}</code> is supported</li>
                    <li><code>%{HTTP_COOKIE}</code>, <code>%{HTTP_FORWARDED}</code>, <code>%{HTTP_PROXY_CONNECTION}</code>,
                        <code>%{REMOTE_USER}</code>, <code>%{REMOTE_IDENT}</code>, <code>%{PATH_INFO}</code>, <code>%{AUTH_TYPE}</code>,
                        <code>%{SERVER_ADMIN}</code> and <code>%{SERVER_NAME}</code></li>
                </ul>
            </div>
			<hr>
            <form method="POST">
                <label>URL</label> <input size="50" type="text" name="URL" value="<?php echo Globals::POST('URL', DEFAULT_URL); ?>" /><br>
                <label></label>
                <select name="REQUEST_METHOD">
				<?php foreach($request_methods as $req) { ?>
					<option <?php echo $request_method == $req ? "selected":''; ?>><?php echo $req; ?></option>
				<?php } ?>
                </select>
                <select name="HTTP_VERSION">
                    <option>HTTP/1.1</option>
                    <option>HTTP/1.0</option>
                </select><br>
                <label>User Agent</label> <input type="text" size="50" name="USER_AGENT" value="<?php echo $server_vars['HTTP_USER_AGENT']; ?>" /><br>
                <label>Referer</label> <input type="text" size="50" name="HTTP_REFERER" value="" /><br>
                <label>Doc Root</label> <input type="text" size="50" name="DOCUMENT_ROOT" value="/var/vhosts/www/" /><br>
                <textarea rows="20" cols="60" name="HTACCESS_RULES"><?php echo htmlentities($htaccess); ?></textarea><br>
                <input type="submit" value="Debug!" />
            </form>
        </div>
        <div id="col-right">
            <table style='font-family:monospace;width:100%'>
                <thead>
                    <tr><th width="60%">htaccess processing info</th></tr>
                </thead>
                <tbody>
                <?php
                foreach($output_table as $line => $cols) {
                ?>
                    <tr>
                        <td><?php echo $cols['htaccess'], $cols['info']; ?></td>
                    </tr>
                <?php
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
document.getElementById("show-hide-help").onclick = function(e) {
    e.preventDefault();
    var blurb = document.getElementById("blurb");
    if (blurb.style.display == "none") {
        blurb.style.display = "block";
    } else {
        blurb.style.display = "none";
    }
    return false;
};
</script>
<?php
if (file_exists("analytics.php")){
	include 'analytics.php';
} ?>
</body>
</html>
