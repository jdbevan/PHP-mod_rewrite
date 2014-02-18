<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>mod_rewrite.php</title>
<style>
body { font-family: sans-serif; }
h1 { margin-top: 10px; }
code { background-color: #EEEEEE; }
.log-normal { color: #000000; }
.log-failure { padding-left: 15px; color: #FF0000; }
.log-success { padding-left: 15px; color: #0088FF; }
.log-comment { padding-left: 15px; color: #888888; }
.log-help { padding-left: 15px; color: #CC33CC; }
.log-url { color: white; background-color: #00AA00; }
#outer-container {
	clear:left;
	float:left;
	width: 100%;
	overflow:hidden;
}
#inner-container {
	float:left;
	width:100%;
	position:relative;
	right:50%;
}
#col-left {
	float:left;
	width:45%;
	position:relative;
	left:50%;
	overflow:hidden;
    padding-right:5px;
    border-right:1px solid black;
    margin-right:5px;
}
#col-right {
    float:left;
	width:54%;
    position:relative;
    left:50%;
    overflow:hidden;
}
label { display: inline-block; width: 100px; }
td { vertical-align:bottom; padding: 3px 15px 3px 3px; }
/* TODO: fix table width in 2nd column esp for long URLs */
thead tr, tbody tr:nth-child(2n) { background-color: #F8F8F8; }
table tbody span { display: block; }
</style>
</head>
<?php flush(); ?>
<body>
<?php
error_reporting(E_ALL);
ini_set("display_errors", true);

require_once './consts.inc.php';
require_once './utils.inc.php';
require_once './rewrite_cond.php';
require_once './rewrite_rule.php';

spl_autoload_register(function ($class) {
    $path = 'classes/' . $class . '.class.php';
    if (file_exists($path)) {
        include_once $path;
    }
});

$parsed_url = parse_url( Globals::POST('URL', '') );

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

<Location /foo/bar>
	RewriteRule . http://foo.bar/redirect [R=301,L]
</Location>

# Comment comment comment
RewriteCond %{REMOTE_PORT} -lt61234
RewriteCond %{REMOTE_PORT} -ge1234
RewriteCond %{REMOTE_PORT} >=1234
RewriteCond %{HTTP_HOST} ^domain.com
RewriteRule (.*) http://www.domain.com/$1 [NC,L,R=301]

RewriteCond %{THE_REQUEST} ^GET
RewriteCond %{REQUEST_URI} (.*)
RewriteRule . /api/post/%1	[L,R=301]
EOS;

// ----------------------------------------

/**
 * 
 * @param boolean|string $line_regex False if directive not supported, true if supported
 * and requires parsing, string if actual regular expression to match
 * @param string $directive_name The mod rewrite directive
 * @param string $line The trimmed mod_rewrite line
 * @param int $htaccess_line
 * @param array $server_vars
 * @param array $rewriteConds
 * @return boolean True if directive can be processed, false otherwise
 */
function process_directive($line_regex, $directive_name, $line, $htaccess_line, $server_vars, &$rewriteConds) {
    
    $matches = array();
    if ($line_regex === false) {
        $directive_match = true;
        output("Directive: $directive_name is not supported yet", $htaccess_line, LOG_FAILURE);

    } else if ($line_regex === true) {
        // Remove directive from the line
        $line = preg_replace("/^$directive_name/", "", $line);

        // Check for args
        $arg1 = $arg2 = $arg3 = '';
        if (parse_rewrite_rule_cond($line, $arg1, $arg2, $arg3)) {
            //output("A1: $arg1, A2: $arg2, A3: $arg3", $htaccess_line, LOG_COMMENT);

            // Parse the RewriteRule or RewriteCond
            if ($directive_name == "RewriteCond") {
                //$interpret = interpret_cond($arg1, $arg2, $arg3);
                $rewriteConds[] = array("args" => array($arg1, $arg2, $arg3),
                                        "line" => $htaccess_line);
                
                $directive_match = true;

            } else if ($directive_name == "RewriteRule") {
                $interpret = interpret_rule($arg1, $arg2, $arg3, $server_vars, $rewriteConds,
											$htaccess_line);
                // Reset conditions
				$rewriteConds = array();
                
                // NB this should be conditional
                $directive_match = $interpret;

            } else {
                $directive_match = false;
                output("Unknown directive $directive_name", $htaccess_line, LOG_FAILURE);
            }
        } else {
            $directive_match = false;
            output("Directive syntax error", $htaccess_line, LOG_FAILURE);
        }

    } else if ( preg_match($line_regex, $line, $matches) ) {
        $directive_match = true;
        // TODO: handle rewrite base
        if (stripos($matches[0], "RewriteEngine") === 0) {
            if (strtolower($matches[1]) === "on") {
                output("Excellent start!", $htaccess_line, LOG_SUCCESS);
            } else {
                output("Well this is the first problem!", $htaccess_line, LOG_FAILURE);
            }
        } else {
            output("Not implemented yet", $htaccess_line, LOG_COMMENT);
        }

    } else {
        $directive_match = false;
        output("Directive syntax error/regex error...", $htaccess_line, LOG_FAILURE);
    }
    return $directive_match;
}

/**
 * TODO: handle RewriteBase
 * TODO: multiple RewriteConds/parse RewriteRules before RewriteConds... ie buffer RewriteConds (with line num)
 * until RewriteRule reached then eval RewriteRule in case of forward? backreferences in the RewriteConds
 * @param string $line The line we're investigating
 * @param array $directives The directives we know
 * @param int $htaccess_line Which line we're on, for output usage
 * @param array $server_vars The server variables
 * @param array &$rewriteConds The RewriteConds preceding this RewriteRule
 * @return string|boolean True on success, false on failure, string containing new URL on new URL
 */
function find_directive_match($line, $directives, $htaccess_line, $server_vars, &$rewriteConds) {
	$trimmed = trim($line);
    // Skip whitespace lines
	if (preg_match("/^\s*$/", $trimmed)) {
		return true;
	}
	$directive_match = null;
	
	// Check it matches a directive we know about
	foreach ($directives as $directive_name => $line_regex) {
		$directive_regex = "/^$directive_name/";
		
		if (preg_match($directive_regex, $trimmed)) {
            $directive_match = process_directive($line_regex, $directive_name, $trimmed, $htaccess_line, $server_vars, $rewriteConds);
			// Early quit from for loop
			break;
		}
	}
    if ($directive_match === null) {
        output("Unknown directive", $htaccess_line, LOG_FAILURE);
        
        // Handle any queued RewriteConds
        for ($i=0,$m=count($rewriteConds); $i<$m; $i++) {
            $cond = $rewriteConds[$i];
            interpret_cond($cond['args'][0], $cond['args'][1], $cond['args'][2], $htaccess_line - $m + $i, array(), $server_vars);
            output("Skipping due to unknown directive in ".($m-$i)." line".($m-$i==1?"":"s"), $htaccess_line - $m + $i, LOG_FAILURE);
        }
        $rewriteConds = array();
        $directive_match = false;
    }
	return $directive_match;
}


// ------------------------------------------

// Process stuff
$htaccess = Globals::POST("HTACCESS_RULES", $sample_htaccess);

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
	
		$new_url = find_directive_match($line, $directives, $htaccess_line_count,
										$server_vars, $rewriteConds);
		
		if ($new_url !== true and $new_url !== false) {
			if ($num_restarts < $max_restarts) {
				output("REPROCESSING NEW URL....................................", $htaccess_line_count, LOG_URL);
				// Overwrite remaining htaccess lines, read for re-parsing
				$lines = array_merge(array_slice($lines, 0, $htaccess_line_count + 1), $orig_lines);
				$total_lines = count($lines);
				$num_restarts++;
				
				$parsed_url = parse_url($new_url);
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
			<hr>
            <form method="POST">
                <label>URL</label> <input size="50" type="text" name="URL" value="<?php echo Globals::POST('URL', 'http://www.example.com/foo.php?bar=baz') ?>" /><br>
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
                    <tr><th width="60%">htaccess <!--</th><th> -->info</th></tr>
                </thead>
                <tbody>
                <?php
                foreach($output_table as $line => $cols) {
                ?>
                    <tr>
                        <td><?php echo $cols['htaccess']; ?> <!--</td>
                        <td>--><?php echo $cols['info']; ?></td>
                    </tr>
                <?php
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
if (file_exists("analytics.php")){
	include 'analytics.php';
} ?>
</body>
</html>
