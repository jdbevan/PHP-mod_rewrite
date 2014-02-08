<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>mod_rewrite.php</title>
<style>
#outer-container {
	clear:left;
	float:left;
	width:100%;
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
	width:42%;
	position:relative;
	left:50%;
	overflow:hidden;
}
#col-right {
	float:left;
	width:54%;
	position:relative;
	left:50%;
	overflow:hidden;
}
label { display: inline-block; width: 75px; }
td { vertical-align:bottom; padding: 3px 15px 3px 3px; }
thead tr, tbody tr:nth-child(2n) { background-color: #F8F8F8; }
</style>
</head>
<?php flush(); ?>
<body>
<?php
error_reporting(E_ALL);
ini_set("display_errors", true);

require_once './consts.inc.php';

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

<Location /foo/bar>
	RewriteRule . http://foo.bar/redirect [R=301,L]
</Location>

# Comment comment comment
RewriteCond %{REMOTE_PORT} -lt61234
RewriteCond %{REMOTE_PORT} -ge1234
RewriteCond %{REMOTE_PORT} >=1234
RewriteCond %{HTTP_HOST} ^domain.com
RewriteRule (.*) http://www.domain.com/$1 [NC,L,R=301]

RewriteCond %{THE_REQUEST} ^POST
RewriteCond %{REQUEST_URI} (.*)
RewriteRule . /api/post/%1	[L,R=301]
EOS;

$htaccess = Globals::POST("HTACCESS_RULES", $sample_htaccess);

$output_table = array();
$htaccess_line_count = 0;

// ----------------------------------------
/**
 * TODO: fix this, make output handling a LOT better
 */
function logger($message, $level = LOG_NORMAL) {
	// YUCK!
	global $output_table;
	global $htaccess_line_count;
	
	$content = preg_match("/^\s*$/", trim($message)) ? "&nbsp;" : htmlentities($message);
	$line = "<span style='color:$level;display:block;'>" . $content . "</span>\n";
	if (!isset($output_table[$htaccess_line_count])) {
		$output_table[$htaccess_line_count] = array("htaccess" => "", "info" => "");
	}
	if ($level === LOG_NORMAL) {
		$output_table[$htaccess_line_count]['htaccess'] = $line;
	} else {
		$output_table[$htaccess_line_count]['info'] .= $line;
	}
}

function is_quote($char) {
	return ($char == "'" || $char == '"') ? $char : false;
}
function is_space($char) {
	return preg_match("/\s/", $char);
}

/**
 * Helper function for parse_rewrite_rule_cond() - obtain the next argument
 * value based on specified string offset
 * @param string &$line The RewriteRule/RewriteCond line
 * @param int &$char_pos The string offset
 * @returns string The next argument value
 */
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

/**
 * Extract arguments from RewriteRule or RewriteCond line
 * @param string $line The line containing directive and arguments
 * @param string &$arg1 First argument (TestString or Pattern)
 * @param string &$arg2 Second argument (CondPattern or Substitution)
 * @param string &$arg3 Optional 3rd argument (Flags)
 * @returns boolean True on successful parse, false on failed parse
 */
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

/**
 * Find a closing curly brace in a string starting at an offset
 * @param string $str String to search through
 * @param int $offset Offset in string to start searching
 * @return boolean|int False if no closing brace found, else position in<br>
 * $str of closing curly brace
 */
function find_closing_curly($str, $offset) {
    $len = strlen($str);
    for ($depth = 1; $offset < $len; $offset++) {
        if ($str[$offset] == "}" && --$depth == 0) {
            return $offset;
        }
        else if ($str[$offset] == "{") {
            ++$depth;
        }
    }

    return false;
}

/**
 * Find a specified character inside a string, starting at $offset
 * @param string $haystack The string to search in
 * @param string $needle The character to search for
 * @param int $offset The offset to start searching at
 * @return boolean|int False if no closing brace found, else position in<br>
 * $str of the specified $needle
 */
function find_char_in_curlies($haystack, $needle, $offset) {
    $len = strlen($haystack);
    for ($depth = 1; $offset < $len; $offset++) {
        if ($haystack[$offset] == $needle && $depth == 1) {
            return $offset;
        }
        else if ($haystack[$offset] == "}" && --$depth == 0) {
            return false;
        }
        else if ($haystack[$offset] == "{") {
            ++$depth;
        }
    }

    return false;
}

/**
 * 
 * @param type $string
 * @return mixed Null for unknown, false for unsupported, string if<br>
 * value found
 */
function lookup_variable($string) {
    // Yuck
    global $server_vars;
    
    $varlen = strlen($string);
    if ($varlen < 4) {
        return "";
    }
    
    $result = null;
    
    if ($string[3] == ":") {
        if (isset($string[4]) and !strncasecmp($string, "ENV", 3)) {
            $var_name = substr($string, 4);
            if (isset($server_vars[$var_name])) {
                $result = $server_vars[$var_name];
            }
        }
        else if (isset($string[4]) and !strncasecmp($string, "SSL", 3)) {
            // TODO: ssl vars
            $result = false;
        }
    }
    else if ($string[4] == ":" and isset($string[5])) {
        
        if (!strncasecmp($string, "HTTP", 4)) {
            // TODO: check HTTP headers - add another form element for extra headers
        }
        else if (!strncasecmp($string, "LA-U", 4)) {
            // Not supported
            $result = false;
        }
        else if (!strncasecmp($string, "LA-F", 4)) {
            // Not supported
            $result = false;
        }
    }
    else {
        if (isset($server_vars[$string])) {
            $result = $server_vars[$string];
        }
    }
    
    return $result;
}

/**
 * Expand a RewriteCond test string
 * TODO: backreferences
 * @global array $server_vars Fix this
 * @param string $input The test string to expand
 * @return string|boolean The expanded test string or false on unsupported<br>
 * expansion
 */
function expand_teststring($input) {
	global $server_vars;
	
    $result = new SingleLinkedList;
    $current = &$result;

	$span = strcspn($input, "\\$%");
    $inputlength = strlen($input);

    // fast exit
	if ($span === $inputlength) {
        return $input;
	}
    
    $str_pos        = $span;
    $outlen         = $span;
    $current->next  = null;
    $current->string = substr($input, 0, $str_pos);
    $current->length = $span;

    do {
        if ($current->length) {
            $current->next = new SingleLinkedList;
            $current = &$current->next;
            $current->next = null;
            $current->length = 0;
        }

        // escaped chars
        if ($input[$str_pos] == "\\") {
            $current->length = 1;
            $outlen++;
            if ( ! isset($input[$str_pos + 1])) {
                $current->string = substr($input, $str_pos);
                break;
            } else {
                $current->string = substr($input, ++$str_pos);
                $str_pos++;
            }
        }

        // variable or map lookup
        else if ($input[$str_pos + 1] == "{") {
            $close_curly = find_closing_curly($input, $str_pos + 2);

            if ($close_curly === false) {
                $current->length = 2;
                $current->string = substr($input, $str_pos);
                $outlen += 2;
                $str_pos += 2;
            }

            // variable lookup
            else if ($input[$str_pos] == "%") {
                $sysvar = lookup_variable( substr($input, $str_pos+2, $close_curly-$str_pos-2) );

                $span = strlen($sysvar);
                $current->length = $span;
                $current->string = $sysvar;
                $outlen += $span;
                $str_pos = $close_curly + 1;
            }
            
            // map lookup
            else {
                // Unsupported
                logger("# Sorry Rewrite Maps aren't supported", LOG_FAILURE);
                
                $key_pos = find_char_in_curlies($input, ":", $str_pos+2);
                if ($key_pos === false) {
                    $current->length = 2;
                    $current->string = substr($input, $str_pos);
                    $outlen += 2;
                    $str_pos += 2;
                } else {
                    $map = substr($input, $str_pos+2, $close_curly-$str_pos-2);
                    $key = substr($input, $key_pos, $close_curly-$key_pos);
                    $default_pos = find_char_in_curlies($input, "|", $key_pos);
                    
                    // Can't lookup/expand as no map support
                    
                    $str_pos = $close_curly + 1;
                }
                // Quit while I'm behind
                return false;
            }
        }
        
        // backreferences
        else if (preg_match("/^\d$/", $input[$str_pos + 1])) {
            
            $n = (int)$input[$str_pos + 1];
            $backRefType = $input[$str_pos] == "$"
                        ? BACKREF_REWRITE_RULE
                        : BACKREF_REWRITE_COND;
            
            // TODO: obtain backreferences
            // TODO: check for escapebackreferenceflag?
            logger("# Backreferences aren't implemented yet", LOG_COMMENT);
            
            $span = 0; // length of backreference value
            $current->length = $span;
            $current->string = ""; // backreference value
            $outlen += $span;
            
            $str_pos += 2;
            
            // Quit while I'm behind
            return false;
        }
        
        // just copy it
        else {
            $current->length = 1;
            $current->string = substr($input, $str_pos);
            $outlen++;
        }
        
        // checks
        if (($span = strcspn(substr($input, $str_pos), "\\$%")) > 0) {
            if ($current->length) {
                $current->next = new SingleLinkedList;
                $current = &$current->next;
                $current->next = null;
            }
            
            $current->length = $span;
            $current->string = substr($input, $str_pos);
            $str_pos += $span;
            $outlen += $span;
        }

    } while ($str_pos < $inputlength);
    
    $return = '';
    do {
        if ($result->length) {
            $return .= $result->string;
        }
        $result = $result->next;
    } while ($result);
    
    return $return;
}

/**
 * Determine the kind of RewriteCond comparison required
 * @param string $cond_pattern The 2nd argument on a RewriteCond line
 * @returns array Type constant indicating comparison required, pattern indicating
 * what to compare against
 */
function process_cond_pattern($cond_pattern) {
	if ($cond_pattern === "expr") {
		logger("# ap_expr not supported yet", LOG_FAILURE);
		return false;
	} else if (substr($cond_pattern, 0, 1) == "-") {
		$pref_3 = substr($cond_pattern, 0, 3);
		$pref_2 = substr($cond_pattern, 0, 2);
		
		switch ($pref_3) {
			case "-eq":
				return array("type" => COND_COMPARE_INT_EQ, "pattern" => substr($cond_pattern, 3));
				break;
			case "-ge":
				return array("type" => COND_COMPARE_INT_GTE, "pattern" => substr($cond_pattern, 3));
				break;
			case "-gt":
				return array("type" => COND_COMPARE_INT_GT, "pattern" => substr($cond_pattern, 3));
				break;
			case "-le":
				return array("type" => COND_COMPARE_INT_LTE, "pattern" => substr($cond_pattern, 3));
				break;
			case "-lt":
				return array("type" => COND_COMPARE_INT_LT, "pattern" => substr($cond_pattern, 3));
				break;
			default:
				break;
		}
		switch ($pref_2) {
			case "-d":
				logger("# Can't determine existing directories", LOG_FAILURE);
				return false;
				break;
			case "-f":
			case "-F":
				logger("# Can't determine existing files", LOG_FAILURE);
				return false;
				break;
			case "-H":
			case "-l":
			case "-L":
				logger("# Can't determine existing symbolic links", LOG_FAILURE);
				return false;
				break;
			case "-s":
				logger("# Can't determine file sizes", LOG_FAILURE);
				return false;
				break;
			case "-U":
				logger("# Can't do internal URL request check", LOG_FAILURE);
				return false;
				break;
			case "-x":
				logger("# Can't determine file permissions", LOG_FAILURE);
				return false;
				break;
			default:
				break;
		}
		logger("# Unknown condition", LOG_FAILURE);
		return false;
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
		return array("type" => COND_COMPARE_REGEX, "pattern" => $cond_pattern);
	}
}

/**
 * Evaluates a RewriteCond line
 * TODO: Flags
 * TODO: Handle RewriteRule back references, $0 to $9 from groups in RewriteRule line: http://httpd.apache.org/docs/current/rewrite/intro.html#InternalBackRefs
 * TODO: Handle RewriteCond back references, %0 to %9 from groups in last matched RewriteCond in set
 * @param string $test_string First param, the string to match against
 * @param string $orig_cond_pattern Second param, the condition to match first param against
 * @param string $flags Flags indicating case-insensitivity NC, of the OR logic flag (ignore NV flag)
 * @return Boolean true on success/match, false on failure/no match
 */
function interpret_cond($test_string, $orig_cond_pattern, $flags) {
	$expanded_test_string = expand_teststring($test_string);
    if ($expanded_test_string === false) {
        return false;
    }
	logger("# $test_string » $expanded_test_string", LOG_HELP);
	
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
		$strcmp = strcmp($expanded_test_string, $pattern_type['pattern']);
		$lt = (int)$expanded_test_string < (int)$pattern_type['pattern'];
		$eq = (int)$expanded_test_string === (int)$pattern_type['pattern'];

		switch ($pattern_type["type"]) {
			case COND_COMPARE_STR_LT:
				if ($strcmp < 0) {
					logger("# MATCH: $expanded_test_string < {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string >= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_GT:
				if ($strcmp > 0) {
					logger("# MATCH: $expanded_test_string > {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string <= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_EQ:
				if ($strcmp === 0) {
					logger("# MATCH: $expanded_test_string = {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string != {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_LTE:
				if ($strcmp <= 0) {
					logger("# MATCH: $expanded_test_string <= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string > {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_GTE:
				if ($strcmp >= 0) {
					logger("# MATCH: $expanded_test_string >= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string < {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_EQ:
				if ($eq) {
					logger("# MATCH: $expanded_test_string == {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string != {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_GT:
				if ( ! $lt and ! $eq) {
					logger("# MATCH: $expanded_test_string > {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string <= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_GTE:
				if ( ! $lt or $eq) {
					logger("# MATCH: $expanded_test_string >= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string < {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_LT:
				if ($lt) {
					logger("# MATCH: $expanded_test_string < {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string >= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_LTE:
				if ($lt or $eq) {
					logger("# MATCH: $expanded_test_string <= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH: $expanded_test_string > {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_REGEX:
				return regex_match($pattern_type['pattern'], $expanded_test_string, $negative_match);
				break;
			default:
				logger("# $cond_pattern not supported yet", LOG_FAILURE);
				return false;
				break;
		}
	} else {
		logger("# Unknown", LOG_FAILURE);
		return false;
	}
}

/**
 * Perform a regular expression match
 * TODO: add regex flags? Ie case-insensitive
 * @param string $cond_pattern The regular expression
 * @param string $test_string The string to match against the regular expression
 * @param boolean $negative_match True to perform a negative regex match
 * @returns boolean True on successful match, false on failure to match
 */
function regex_match($cond_pattern, $test_string, $negative_match){
	$match = preg_match("/$cond_pattern/", $test_string, $groups);
	if ($match === false) {
		logger("# $cond_pattern invalid regex", LOG_FAILURE);
		return false;
	}
	if ($negative_match and $match === 0) {
		logger("# MATCH: $cond_pattern negative matches $test_string", LOG_SUCCESS);
		return true;
	} else if (!$negative_match and $match === 1) {
		logger("# MATCH: $cond_pattern matches $test_string", LOG_SUCCESS);
		return true;
	} else {
		logger("# NO MATCH: $cond_pattern doesn't match $test_string", LOG_FAILURE);
		return false;
	}
}

function interpret_rule() {
}


/**
 * TODO: handle RewriteEngine Off
 * TODO: handle RewriteBase
 * TODO: multiple RewriteConds/parse RewriteRules before RewriteConds... ie buffer RewriteConds (with line num)
 * until RewriteRule reached then eval RewriteRule in case of forward? backreferences in the RewriteConds
 */
function parse_directive($line, $directives) {
	$trimmed = trim($line);
    // Skip whitespace lines
	if (preg_match("/^\s*$/", $trimmed)) {
		return true;
	}
	$directive_match = false;
	
	// Check it matches a directive we know about
	foreach ($directives as $directive_name => $line_regex) {
		$directive_regex = "/^$directive_name/";
		
		if (preg_match($directive_regex, $trimmed)) {
			if ($line_regex === false) {
				$directive_match = true;
				logger("# Directive: $directive_name is not supported yet", LOG_FAILURE);
				
			} else if ($line_regex === true) {
				// Remove directive from the line
				$trimmed = preg_replace("/^$directive_name/", "", $trimmed);
				
				// Check for args
				if (parse_rewrite_rule_cond($trimmed, $arg1, $arg2, $arg3)) {
					logger("# A1: $arg1, A2: $arg2, A3: $arg3", LOG_COMMENT);

					// Parse the RewriteRule or RewriteCond
					if ($directive_name == "RewriteCond") {
						$interpret = interpret_cond($arg1, $arg2, $arg3);
						// NB this should be conditional
						$directive_match = true;
						
					} else if ($directive_name == "RewriteRule") {
						$interpret = interpret_rule($arg1, $arg2, $arg3);
						// NB this should be conditional
						$directive_match = true;
						
					} else {
						$directive_match = false;
						logger("# Unknown directive $directive_name", LOG_FAILURE);
					}
				} else {
					$directive_match = false;
					logger("# Directive syntax error", LOG_FAILURE);
				}
				
			} else if ( preg_match($line_regex, $trimmed, $matches) ) {
				$directive_match = true;
                // TODO: handle rewrite base
                if (stripos($matches[0], "RewriteEngine") === 0) {
                    if (strtolower($matches[1]) === "on") {
                        logger("# Excellent start!", LOG_SUCCESS);
                    } else {
                        logger("# Well this is the first problem!", LOG_FAILURE);
                    }
                } else {
                    logger("# Not implemented yet", LOG_COMMENT);
                }
				
			} else {
				$directive_match = false;
				logger("# Directive syntax error/regex error...", LOG_FAILURE);
			}
			// Early quit from for loop
			break;
		}
	}
	return $directive_match;
}

// Process stuff

if (!empty($_POST)) {
    $lines = explode("\n", $htaccess);
    $inside_directive = false;
    foreach($lines as $line) {

        // Is it a comment?
        if (preg_match("/^\s*#/", $line)) {
            logger("# No comment...", LOG_COMMENT);

        // Is it another module directive?
        } else if (preg_match("/^\s*<(\/?)(.*)>\s*$/", $line, $match)) {

            if ( ! preg_match("/IfModule\s+mod_rewrite/i", $match[2])) {
                if ($match[1] == "/") {
                    if ($inside_directive) {
                        logger("# Finally! Back to business...", LOG_FAILURE);
                    }
                    $inside_directive = false;
                } else {
                    logger("# Unknown directive, Ignoring...", LOG_FAILURE);
                    $inside_directive = true;
                }
            } else {
                logger("# This is kind of assumed :)", LOG_HELP);
            }

        // Does it match a directive
        } else if ( ! $inside_directive and parse_directive($line, $directives)) {
            //

        } else if ($inside_directive) {
            logger("# Ignoring...", LOG_FAILURE);

        } else {
            logger("# Unknown directive", LOG_FAILURE);
        }

        logger($line);
        $htaccess_line_count++;
    }
}
?>
<div id="outer-container">
    <div id="inner-container">
        <div id="col-left">
            <form method="POST">
                <label>URL</label> <input size="50" type="text" name="URL" value="http://www.domain.com" /><br>
                <label></label>
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
                <label>User Agent</label> <input type="text" size="50" name="USER_AGENT" value="<?php echo $server_vars['HTTP_USER_AGENT']; ?>" /><br>
                <label>Referer</label> <input type="text" size="50" name="HTTP_REFERER" value="" /><br>
                <label>Doc Root</label> <input type="text" size="50" name="DOCUMENT_ROOT" value="/var/vhosts/www/" /><br>
                <textarea rows="30" cols="60" name="HTACCESS_RULES"><?php echo htmlentities($htaccess); ?></textarea><br>
                <input type="submit" value="Debug!" />
            </form>
        </div>
        <div id="col-right">
            <table style='font-family:monospace;width:100%'>
                <thead>
                    <tr><th width="60%">htaccess</th><th>info</th></tr>
                </thead>
                <tbody>
                <?php
                foreach($output_table as $line => $cols) {
                ?>
                    <tr>
                        <td><?php echo $cols['htaccess']; ?></td>
                        <td><?php echo $cols['info']; ?></td>
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
