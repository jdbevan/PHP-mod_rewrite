<?php

require_once './globals.class.php';
require_once './consts.inc.php';

$parsed_url = parse_url( Globals::POST('URL', '') );

// Server-variables
$server_vars = array(
	"HTTP_USER_AGENT"	=> Globals::SERVER('HTTP_USER_AGENT', Globals::POST('USER_AGENT', USER_AGENT_CHROME_LINUX) ),
	"HTTP_REFERER"		=> Globals::POST('HTTP_REFERER', ""),
	"HTTP_COOKIE"		=> false,
	"HTTP_FORWARDED"	=> false,
	"HTTP_HOST"			=> empty($parsed_url['host']) ? '' : $parsed_url['host'],
	"HTTP_PROXY_CONNECTION"	=> false,
	"HTTP_ACCEPT"		=> Globals::SERVER('HTTP_ACCEPT', false),
	"REMOTE_ADDR"		=> Globals::SERVER('REMOTE_ADDR', ""),
	"REMOTE_HOST"		=> Globals::POST('REMOTE_HOST', false),
	"REMOTE_PORT"		=> Globals::SERVER('REMOTE_PORT', mt_rand(49152,65535)),
	"REMOTE_USER"		=> false,
	"REMOTE_IDENT"		=> false,
	"REQUEST_METHOD"	=> Globals::POST('REQUEST_METHOD', 'GET'),
	"SCRIPT_FILENAME"	=> empty($parsed_url['path']) ? "/" : $parsed_url['path'],
	"PATH_INFO"			=> false,
	"QUERY_STRING"		=> empty($parsed_url['query']) ? "" : $parsed_url['query'],
	"AUTH_TYPE"			=> false,
	"DOCUMENT_ROOT"		=> Globals::POST('DOCUMENT_ROOT', false),
	"SERVER_ADMIN"		=> false,
	"SERVER_NAME"		=> false,
	"SERVER_ADDR"		=> Globals::POST('SERVER_ADDR', false),
	"SERVER_PORT"		=> isset($parsed_url['scheme']) and $parsed_url['scheme'] == "https" ? 443 : 80,
	"SERVER_PROTOCOL"	=> Globals::POST('HTTP_VERSION', 'HTTP/1.1'),
	"SERVER_SOFTWARE"	=> Globals::POST('SERVER_SOFTWARE', false),
	"TIME_YEAR"			=> (int)date("Y"),
	"TIME_MON"			=> (int)date("n"),
	"TIME_DAY"			=> (int)date("j"),
	"TIME_HOUR"			=> (int)date("G"),
	"TIME_MIN"			=> (int)date("i"),
	"TIME_SEC"			=> (int)date("s"),
	"TIME_WDAY"			=> (int)date("N"),
	"TIME"				=> date("H:i:s"),
	"API_VERSION"		=> Globals::POST('API_VERSION', false),
	//"THE_REQUEST",
	//"REQUEST_URI",
	//"REQUEST_FILENAME",
	"IS_SUBREQ"		=> "false",
	//"HTTPS"			=> parse_url( Globals::POST('URL', ''), PHP_URL_SCHEME ) == "https" ? "on" : "off",
	"REQUEST_SCHEME"	=> isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'http'
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

RewriteCond %{REMOTE_PORT} >=1234
RewriteCond %{HTTP_HOST} ^domain.com
RewriteRule (.*) http://www.domain.com/$1 [NC,L,R=301]

RewriteCond %{THE_REQUEST} ^POST
RewriteCond %{REQUEST_URI} (.*)
RewriteRule . /api/post/%1	[L,R=301]
EOS;

$htaccess = Globals::POST("HTACCESS_RULES", $sample_htaccess);

// ----------------------------------------

function is_quote($char) {
	return ($char == "'" || $char == '"') ? $char : false;
}
function is_space($char) {
	return preg_match("/\s/", $char);
}
function parse_for_arg(&$line, &$char_pos) {
	$quote = is_quote($line[$char_pos]);
	if ($quote) {
		$char_pos++;
	}
	$init_pos = $char_pos;

	for ($len = strlen($line); $char_pos < $len; $char_pos++) {
		// If find a space and not in quote, or if find end of quote
		if ((is_space($line[$char_pos]) and !$quote) or $line[$char_pos] === $quote) {
			break;
		}
		if ($line[$char_pos] == '\\' and $len > ($char_pos + 1) and is_space($line[$char_pos + 1])) {
			$char_pos++;
			continue;
		}
	}
	return substr($line, $init_pos, $char_pos - $init_pos);
}
function parse_rewrite_rule_cond($line, &$arg1, &$arg2, &$arg3) {
	$line = ltrim($line);

	$arg1 = null;
	$arg2 = null;
	$arg3 = null;
	$char_pos = 0;
	
	$arg1 = parse_for_arg($line, $char_pos);
	$len = strlen($line);
	
	while ($char_pos < $len and is_space($line[$char_pos])) {
		$char_pos++;
	}
	if ($char_pos === $len) {
		return false;
	}

	// ----

	$arg2 = parse_for_arg($line, $char_pos);
	$len = strlen($line);
	
	while ($char_pos < $len-1 and is_space($line[$char_pos])) {
		$char_pos++;
	}
	if ($char_pos === $len) {
		return true;
	}
	
	// -----
	
	$arg3 = parse_for_arg($line, $char_pos);
	return true;
}


function expand_teststring($test_string) {
	global $server_vars;
	$ret_string = $test_string;
	foreach($server_vars as $name => $value) {
		$ret_string = str_replace("%{{$name}}", $value, $ret_string);
	}
	return $ret_string;
}

function process_cond_pattern($cond_pattern) {
	if ($cond_pattern === "expr") {
		echo "# ap_expr not supported yet\n";
		return false;
	} else if (substr($cond_pattern, 0, 1) == "-") {
		switch ($cond_pattern) {
			case "-d":
				echo "# Can't determine existing directories\n";
				return false;
				break;
			case "-f":
			case "-F":
				echo "# Can't determine existing files\n";
				return false;
				break;
			case "-H":
			case "-l":
			case "-L":
				echo "# Can't determine existing symbolic links\n";
				return false;
				break;
			case "-s":
				echo "# Can't determine file sizes\n";
				return false;
				break;
			case "-U":
				echo "# Can't do internal URL request check\n";
				return false;
				break;
			case "-x":
				echo "# Can't determine file permissions\n";
				return false;
				break;
			case "-eq":
				return COND_COMPARE_INT_EQ;
				break;
			case "-ge":
				return COND_COMPARE_INT_GTE;
				break;
			case "-gt":
				return COND_COMPARE_INT_GT;
				break;
			case "-le":
				return COND_COMPARE_INT_LTE;
				break;
			case "-lt":
				return COND_COMPARE_INT_LT;
				break;
			default:
				echo "Unknown condition\n";
				return false;
				break;
		}
	} else if (preg_match("/^(<=?|>=?|=)(.*)$/", $cond_pattern, $match)) {
		switch($match[1]) {
			case "<":
				return array("type" => COND_COMPARE_STR_LT, "pattern" => $match[2]);
				break;
			case ">":
				return array("type" => COND_COMPARE_STR_GT, "pattern" => $match[2]);
				break;
			case "=":
				return array("type" => COND_COMPARE_STR_EQ, "pattern" => $match[2]);
				break;
			case "<=":
				return array("type" => COND_COMPARE_STR_LTE, "pattern" => $match[2]);
				break;
			case ">=":
				return array("type" => COND_COMPARE_STR_GTE, "pattern" => $match[2]);
				break;
		}
	} else {
		return COND_COMPARE_REGEX;
	}
}

function interpret_cond($test_string, $orig_cond_pattern, $flags) {
	$expanded_test_string = expand_teststring($test_string);
	echo "# Expanded test string: $expanded_test_string\n";
	$negative_match = substr($orig_cond_pattern, 0, 1) === "!";
	if ($negative_match) {
		$cond_pattern = substr($orig_cond_pattern, 1);
	} else {
		$cond_pattern = $orig_cond_pattern;
	}
	$pattern_type = process_cond_pattern($cond_pattern);
	
	if ($pattern_type === false) {
		return false;
	} else if (is_array($pattern_type)) {
		
	
	
		return;
	} else if (is_integer($pattern_type)) {
		switch ($pattern_type) {
			case COND_COMPARE_INT_EQ:
				
				break;
			case COND_COMPARE_INT_GT:
				
				break;
			case COND_COMPARE_INT_GTE:
				
				break;
			case COND_COMPARE_INT_LT:
				
				break;
			case COND_COMPARE_INT_LTE:
				
				break;
			case COND_COMPARE_REGEX:
				return regex_match($cond_pattern, $expanded_test_string, $negative_match);
				break;
			default:
				echo "# $cond_pattern not supported yet\n";
				break;
		}
	} else {
		echo "# Unknown";
		return false;
	}
}
function regex_match($cond_pattern, $test_string, $negative_match){
	$match = preg_match("/$cond_pattern/", $test_string, $groups);
	if ($match === false) {
		echo "# $cond_pattern invalid regex\n";
		return false;
	}
	if ($negative_match and $match === 0) {
		echo "# MATCH >> $cond_pattern negative matches $test_string\n";
		return true;
	} else if (!$negative_match and $match === 1) {
		echo "# MATCH >> $cond_pattern matches $test_string\n";
		return true;
	} else {
		echo "# NO MATCH >> $cond_pattern doesn't match $test_string\n";
		return false;
	}
}

function interpret_rule() {
}


function matches_directive($line, $directives) {
	$trimmed = trim($line);
	$match = false;
	foreach ($directives as $directive_name => $line_regex) {
		$directive_regex = "/^$directive_name/";
		if (preg_match($directive_regex, $trimmed)) {
			if ($line_regex === false) {
				$match = true;
				echo "# Directive: $directive_name is not supported yet\n";
				
			} else if ($line_regex === true) {
				$match = true;
				// Remove directive
				$trimmed = preg_replace("/^$directive_name/", "", $trimmed);
				// Check for args
				if (parse_rewrite_rule_cond($trimmed, $arg1, $arg2, $arg3)) {
					echo "# A1: $arg1, A2: $arg2, A3: $arg3\n";
					if ($directive_name == "RewriteCond") {
						interpret_cond($arg1, $arg2, $arg3);
					} else if ($directive_name == "RewriteCond") {
						interpret_rule();
					}
					
					
				} else {
					echo "# Directive syntax error\n";
				}
				
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

if (!empty($_POST)) {
	$lines = explode("\n", $htaccess);
	echo "<pre>\n";
	foreach($lines as $line) {

		// Does it match a directive
		if (matches_directive($line, $directives)) {
			//
		}

		echo $line, "\n";
	}

	echo "</pre>\n";
}
?>
<hr>
<form method="POST">
	<label>URL</label> <input size="80" type="text" name="URL" value="http://www.domain.com" />
	<select name="REQUEST_METHOD">
		<option>GET</option>
		<option>POST</option>
		<option>HEAD</option>
		<option>PUT</option>
		<option>DELETE</option>
		<option>OPTIONS</option>
		<option>TRACE</option>
		<option>CONNECT</option>
	</select>
	<select name="HTTP_VERSION">
		<option>HTTP/1.1</option>
		<option>HTTP/1.0</option>
	</select><br>
	<label>User Agent</label> <input type="text" size="120" name="USER_AGENT" value="<?php echo $server_vars['HTTP_USER_AGENT']; ?>" /><br>
	<label>Referer</label> <input type="text" size="80" name="HTTP_REFERER" value="" /><br>
	<label>Doc Root</label> <input type="text" size="50" name="DOCUMENT_ROOT" value="/var/vhosts/www/" /><br>
	<textarea rows="15" cols="90" name="HTACCESS_RULES"><?php echo $htaccess; ?></textarea><br>
	<input type="submit" />
</form>