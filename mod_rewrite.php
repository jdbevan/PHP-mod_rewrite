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
$rewriteConds = array();

// ----------------------------------------
/**
 * TODO: make output handling better
 */
function output($message, $line, $level = LOG_NORMAL) {
	// YUCK!
	global $output_table;
	
	$content = preg_match("/^\s*$/", trim($message)) ? "&nbsp;" : htmlentities($message);
	$html = "<span style='color:$level;display:block;'>" . $content . "</span>\n";
	if (!isset($output_table[$line])) {
		$output_table[$line] = array("htaccess" => "", "info" => "");
	}
	if ($level === LOG_NORMAL) {
		$output_table[$line]['htaccess'] = $html;
	} else {
		$output_table[$line]['info'] .= $html;
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
function lookup_variable($string, $server_vars) {
    
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
 * @param string $input The test string to expand
 * @param int $htaccess_line Output line number
 * @param array $server_vars Server variable values
 * @return string|boolean The expanded test string or false on unsupported<br>
 * expansion
 */
function expand_teststring($input, $htaccess_line, $server_vars) {
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
				$lookup_string = substr($input, $str_pos+2, $close_curly-$str_pos-2);
                $sysvar = lookup_variable($lookup_string, $server_vars );
				
				if ($sysvar === null) {
					output("# Unknown variable: $lookup_string", $htaccess_line, LOG_FAILURE);
					// Quit while I'm behind
					return false;
				} else if ($sysvar === false) {
					output("# Unsupported variable: $lookup_string", $htaccess_line, LOG_FAILURE);
					// Quit while I'm behind
					return false;
				}

                $span = strlen($sysvar);
                $current->length = $span;
                $current->string = $sysvar;
                $outlen += $span;
                $str_pos = $close_curly + 1;
            }
            
            // map lookup
            else {
                // Unsupported
                output("# Sorry Rewrite Maps aren't supported", $htaccess_line, LOG_FAILURE);
                
                $key_pos = find_char_in_curlies($input, ":", $str_pos+2);
                if ($key_pos === false) {
                    $current->length = 2;
                    $current->string = substr($input, $str_pos);
                    $outlen += 2;
                    $str_pos += 2;
                } else {
                    // $map = substr($input, $str_pos+2, $close_curly-$str_pos-2);
                    // $key = substr($input, $key_pos, $close_curly-$key_pos);
                    // $default_pos = find_char_in_curlies($input, "|", $key_pos);
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
            output("# Backreferences aren't implemented yet", $htaccess_line, LOG_COMMENT);
            
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
function process_cond_pattern($cond_pattern, $htaccess_line) {
    $match = array();
	if ($cond_pattern === "expr") {
		output("# ap_expr not supported yet", $htaccess_line, LOG_FAILURE);
		return false;
		
	} else if (substr($cond_pattern, 0, 1) == "-") {
		$pref_3 = substr($cond_pattern, 0, 3);
		$pref_2 = substr($cond_pattern, 0, 2);
		$retval = false;
		
		switch ($pref_3) {
			case "-eq":
				$retval = array("type" => COND_COMPARE_INT_EQ, "pattern" => substr($cond_pattern, 3));
				break;
			case "-ge":
				$retval = array("type" => COND_COMPARE_INT_GTE, "pattern" => substr($cond_pattern, 3));
				break;
			case "-gt":
				$retval = array("type" => COND_COMPARE_INT_GT, "pattern" => substr($cond_pattern, 3));
				break;
			case "-le":
				$retval = array("type" => COND_COMPARE_INT_LTE, "pattern" => substr($cond_pattern, 3));
				break;
			case "-lt":
				$retval = array("type" => COND_COMPARE_INT_LT, "pattern" => substr($cond_pattern, 3));
				break;
			default:
				break;
		}
		if ($retval === false) {
			switch ($pref_2) {
				case "-d":
					output("# Can't determine existing directories", $htaccess_line, LOG_FAILURE);
					break;
				case "-f":
				case "-F":
					output("# Can't determine existing files", $htaccess_line, LOG_FAILURE);
					break;
				case "-H":
				case "-l":
				case "-L":
					output("# Can't determine existing symbolic links", $htaccess_line, LOG_FAILURE);
					break;
				case "-s":
					output("# Can't determine file sizes", $htaccess_line, LOG_FAILURE);
					break;
				case "-U":
					output("# Can't do internal URL request check", $htaccess_line, LOG_FAILURE);
					break;
				case "-x":
					output("# Can't determine file permissions", $htaccess_line, LOG_FAILURE);
					break;
				default:
					break;
			}
		}
		if ($retval === false) {
			output("# Unknown condition", $htaccess_line, LOG_FAILURE);
		}
		return $retval;
		
	} else if (preg_match("/^(<=?|>=?|=)(.*)$/", $cond_pattern, $match)) {
		$retval = false;
		switch($match[1]) {
			case "<":
				$retval = array("type" => COND_COMPARE_STR_LT, "pattern" => $match[2]);
				break;
			case ">":
				$retval = array("type" => COND_COMPARE_STR_GT, "pattern" => $match[2]);
				break;
			case "=":
				$retval = array("type" => COND_COMPARE_STR_EQ, "pattern" => $match[2]);
				break;
			case "<=":
				$retval = array("type" => COND_COMPARE_STR_LTE, "pattern" => $match[2]);
				break;
			case ">=":
				$retval = array("type" => COND_COMPARE_STR_GTE, "pattern" => $match[2]);
				break;
		}
		return $retval;
	} else {
		return array("type" => COND_COMPARE_REGEX, "pattern" => $cond_pattern);
	}
}


function parse_cond_flags($flag_string, $htaccess_line) {
	$opts = FLAG_COND_NONE;
	
	if (empty($flag_string)) {
		return $opts;
	}
	
	$trim_flags = preg_replace("/(^\[|\]$)/", "", $flag_string);
	$flags = explode(",", $trim_flags);
	
	foreach($flags as $flag) {
		switch ($flag) {
			case 'NV':
				$opts = $opts | FLAG_COND_NV;
				output("# No Vary flag... ignoring", $htaccess_line, LOG_HELP);
				break;
			case 'NC':
				$opts = $opts | FLAG_COND_NC;
				output("# Case-insensitive flag", $htaccess_line, LOG_HELP);
				break;
			case 'OR':
				$opts = $opts | FLAG_COND_OR;
				output("# OR flag - not implemented yet", $htaccess_line, LOG_FAILURE);
				break;
			default:
				output("# Unknown flag: $flag", $htaccess_line, LOG_FAILURE);
				break;
		}
	}
	return $opts;
}

/**
 * Evaluates a RewriteCond line
 * TODO: Flags
 * TODO: Handle RewriteRule back references, $0 to $9 from groups in RewriteRule line: http://httpd.apache.org/docs/current/rewrite/intro.html#InternalBackRefs
 * TODO: Handle RewriteCond back references, %0 to %9 from groups in last matched RewriteCond in set
 * @param string $test_string First param, the string to match against
 * @param string $orig_cond_pattern Second param, the condition to match first param against
 * @param string $flags Flags indicating case-insensitivity NC, of the OR logic flag (ignore NV flag)
 * @param int $htaccess_line
 * @param array $server_vars
 * @return Boolean true on success/match, false on failure/no match
 */
function interpret_cond($test_string, $orig_cond_pattern, $flags, $htaccess_line, $server_vars) {
	
	// Step 1
	$parsed_flags = parse_cond_flags($flags, $htaccess_line);

	// Step 2
	$expanded_test_string = expand_teststring($test_string, $htaccess_line, $server_vars);
    if ($expanded_test_string === false) {
        return false;
    }
	output("# $test_string contains $expanded_test_string", $htaccess_line, LOG_HELP);
	
	// Step 3
	$negative_match = substr($orig_cond_pattern, 0, 1) === "!";
	if ($negative_match) {
		$cond_pattern = substr($orig_cond_pattern, 1);
	} else {
		$cond_pattern = $orig_cond_pattern;
	}
	$pattern_type = process_cond_pattern($cond_pattern, $htaccess_line);
	
	// Do the comparison
	if ($pattern_type === false) {
		return false;
		
	} else if (is_array($pattern_type)) {
	
		if ($parsed_flags & FLAG_COND_NC) {
			$strcmp = strcasecmp($expanded_test_string, $pattern_type['pattern']);
		} else {
			$strcmp = strcmp($expanded_test_string, $pattern_type['pattern']);
		}
	
		$lt = (int)$expanded_test_string < (int)$pattern_type['pattern'];
		$eq = (int)$expanded_test_string === (int)$pattern_type['pattern'];
		$retval = false;

		switch ($pattern_type["type"]) {
			case COND_COMPARE_STR_LT:
				if ($strcmp < 0) {
					output("# MATCH: $expanded_test_string < {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string >= {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_STR_GT:
				if ($strcmp > 0) {
					output("# MATCH: $expanded_test_string > {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string <= {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_STR_EQ:
				if ($strcmp === 0) {
					output("# MATCH: $expanded_test_string = {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string != {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_STR_LTE:
				if ($strcmp <= 0) {
					output("# MATCH: $expanded_test_string <= {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string > {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_STR_GTE:
				if ($strcmp >= 0) {
					output("# MATCH: $expanded_test_string >= {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string < {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_INT_EQ:
				if ($eq) {
					output("# MATCH: $expanded_test_string == {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string != {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_INT_GT:
				if ( ! $lt and ! $eq) {
					output("# MATCH: $expanded_test_string > {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string <= {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_INT_GTE:
				if ( ! $lt or $eq) {
					output("# MATCH: $expanded_test_string >= {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string < {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_INT_LT:
				if ($lt) {
					output("# MATCH: $expanded_test_string < {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string >= {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_INT_LTE:
				if ($lt or $eq) {
					output("# MATCH: $expanded_test_string <= {$pattern_type['pattern']}", $htaccess_line, LOG_SUCCESS);
					$retval = true;
				} else {
					output("# NO MATCH: $expanded_test_string > {$pattern_type['pattern']}", $htaccess_line, LOG_FAILURE);
					$retval = false;
				}
				break;
			case COND_COMPARE_REGEX:
				$retval = regex_match($pattern_type['pattern'], $expanded_test_string, $negative_match, $htaccess_line);
				break;
			default:
				output("# $cond_pattern not supported yet", $htaccess_line, LOG_FAILURE);
				$retval = false;
				break;
		}
		return $retval;
	} else {
		output("# Unknown", $htaccess_line, LOG_FAILURE);
		return false;
	}
}

/**
 * Perform a regular expression match
 * TODO: add regex flags? Ie case-insensitive
 * @param string $cond_pattern The regular expression
 * @param string $test_string The string to match against the regular expression
 * @param boolean $negative_match True to perform a negative regex match
 * @returns array|boolean Array of matched groups on successful match, false on failure to match
 */
function regex_match($cond_pattern, $test_string, $negative_match, $htaccess_line){
    $groups = array();
	$match = preg_match("/$cond_pattern/", $test_string, $groups);
	if ($match === false) {
		output("# $cond_pattern invalid regex", $htaccess_line, LOG_FAILURE);
		return false;
	}
	if ($negative_match and $match === 0) {
		output("# MATCH: $cond_pattern negative matches $test_string", $htaccess_line, LOG_SUCCESS);
		return $groups;
	} else if (!$negative_match and $match === 1) {
		output("# MATCH: $cond_pattern matches $test_string", $htaccess_line, LOG_SUCCESS);
		return $groups;
	} else {
		output("# NO MATCH: $cond_pattern doesn't match $test_string", $htaccess_line, LOG_FAILURE);
		return false;
	}
}

/**
cmd_rewriterule(cmd_parms *cmd, void *in_dconf,
                                   const char *in_str)
{
    rewrite_perdir_conf *dconf = in_dconf;
    char *str = apr_pstrdup(cmd->pool, in_str);
    rewrite_server_conf *sconf;
    rewriterule_entry *newrule;
    ap_regex_t *regexp;
    char *a1;
    char *a2;
    char *a3;
    const char *err;

    sconf = ap_get_module_config(cmd->server->module_config, &rewrite_module);

    //  make a new entry in the internal rewrite rule list
    if (cmd->path == NULL) {   // is server command
        newrule = apr_array_push(sconf->rewriterules);
    }
    else {                     // is per-directory command
        newrule = apr_array_push(dconf->rewriterules);
    }

    //  parse the argument line ourself
    if (parseargline(str, &a1, &a2, &a3)) {
        return apr_pstrcat(cmd->pool, "RewriteRule: bad argument line '", str,
                           "'", NULL);
    }

    // arg3: optional flags field
    newrule->forced_mimetype     = NULL;
    newrule->forced_handler      = NULL;
    newrule->forced_responsecode = HTTP_MOVED_TEMPORARILY;
    newrule->flags  = RULEFLAG_NONE;
    newrule->env = NULL;
    newrule->cookie = NULL;
    newrule->skip   = 0;
    newrule->maxrounds = REWRITE_MAX_ROUNDS;
    if (a3 != NULL) {
        if ((err = cmd_parseflagfield(cmd->pool, newrule, a3,
                                      cmd_rewriterule_setflag)) != NULL) {
            return apr_pstrcat(cmd->pool, "RewriteRule: ", err, NULL);
        }
    }

    // arg1: the pattern
    // try to compile the regexp to test if is ok
    //
    if (*a1 == '!') {
        newrule->flags |= RULEFLAG_NOTMATCH;
        ++a1;
    }

    regexp = ap_pregcomp(cmd->pool, a1, AP_REG_EXTENDED |
                                        ((newrule->flags & RULEFLAG_NOCASE)
                                         ? AP_REG_ICASE : 0));
    if (!regexp) {
        return apr_pstrcat(cmd->pool,
                           "RewriteRule: cannot compile regular expression '",
                           a1, "'", NULL);
    }

    newrule->pattern = a1;
    newrule->regexp  = regexp;

    // arg2: the output string
    newrule->output = a2;
    if (*a2 == '-' && !a2[1]) {
        newrule->flags |= RULEFLAG_NOSUB;
    }

    // now, if the server or per-dir config holds an
    // array of RewriteCond entries, we take it for us
    // and clear the array
    //
    if (cmd->path == NULL) {  // is server command
        newrule->rewriteconds   = sconf->rewriteconds;
        sconf->rewriteconds = apr_array_make(cmd->pool, 2,
                                             sizeof(rewritecond_entry));
    }
    else {                    // is per-directory command
        newrule->rewriteconds   = dconf->rewriteconds;
        dconf->rewriteconds = apr_array_make(cmd->pool, 2,
                                             sizeof(rewritecond_entry));
    }

    return NULL;
} */
function interpret_rule($orig_pattern, $substitution, $flags, $url_path, $rewrite_conds, $htaccess_line) {
	
	$new_uri = null;
	
	// Step 1
	$parsed_flags = 0;
	
	// Step 2
	$negative_match = substr($orig_pattern, 0, 1) === "!";
	if ($negative_match) {
		$rewrite_pattern = substr($orig_pattern, 1);
	} else {
		$rewrite_pattern = $orig_pattern;
	}
	
	// Step 3
	$no_change = ($substitution === "-");
	
	$matches = regex_match($rewrite_pattern, $url_path, $negative_match, $htaccess_line);
	if ( $matches === false ) {
		return false;
	}
	
	/**
	for (i = 0; i < rewriteconds->nelts; ++i) {
        rewritecond_entry *c = &conds[i];

        rc = apply_rewrite_cond(c, ctx);
        //
        // Reset vary_this if the novary flag is set for this condition.
        if (c->flags & CONDFLAG_NOVARY) {
            ctx->vary_this = NULL;
        }
        if (c->flags & CONDFLAG_ORNEXT) {
            if (!rc) {
                // One condition is false, but another can be still true.
                ctx->vary_this = NULL;
                continue;
            }
            else {
                // skip the rest of the chained OR conditions
                while (   i < rewriteconds->nelts
                       && c->flags & CONDFLAG_ORNEXT) {
                    c = &conds[++i];
                }
            }
        }
        else if (!rc) {
            return 0;
        }

        // If some HTTP header was involved in the condition, remember it
        // for later use
        if (ctx->vary_this) {
            ctx->vary = ctx->vary
                        ? apr_pstrcat(r->pool, ctx->vary, ", ", ctx->vary_this,
                                      NULL)
                        : ctx->vary_this;
            ctx->vary_this = NULL;
        }
    }

    // expand the result
    if (!(p->flags & RULEFLAG_NOSUB)) {
        newuri = do_expand(p->output, ctx, p);
        rewritelog((r, 2, ctx->perdir, "rewrite '%s' -> '%s'", ctx->uri,
                    newuri));
    }

    // expand [E=var:val] and [CO=<cookie>]
    do_expand_env(p->env, ctx);
    do_expand_cookie(p->cookie, ctx);

    // non-substitution rules ('RewriteRule <pat> -') end here.
    if (p->flags & RULEFLAG_NOSUB) {
        force_type_handler(p, ctx);

        if (p->flags & RULEFLAG_STATUS) {
            rewritelog((r, 2, ctx->perdir, "forcing responsecode %d for %s",
                        p->forced_responsecode, r->filename));

            r->status = p->forced_responsecode;
        }

        return 2;
    }

    // Now adjust API's knowledge about r->filename and r->args
    r->filename = newuri;

    if (ctx->perdir && (p->flags & RULEFLAG_DISCARDPATHINFO)) {
        r->path_info = NULL;
    }

    splitout_queryargs(r, p->flags & RULEFLAG_QSAPPEND, p->flags & RULEFLAG_QSDISCARD);

    // Add the previously stripped per-directory location prefix, unless
    // (1) it's an absolute URL path and
    // (2) it's a full qualified URL
    if (   ctx->perdir && !is_proxyreq && *r->filename != '/'
        && !is_absolute_uri(r->filename, NULL)) {
        rewritelog((r, 3, ctx->perdir, "add per-dir prefix: %s -> %s%s",
                    r->filename, ctx->perdir, r->filename));

        r->filename = apr_pstrcat(r->pool, ctx->perdir, r->filename, NULL);
    }

    // If this rule is forced for proxy throughput
    // (`RewriteRule ... ... [P]') then emulate mod_proxy's
    // URL-to-filename handler to be sure mod_proxy is triggered
    // for this URL later in the Apache API. But make sure it is
    // a fully-qualified URL. (If not it is qualified with
    // ourself).
    if (p->flags & RULEFLAG_PROXY) {
        // For rules evaluated in server context, the mod_proxy fixup
        // hook can be relied upon to escape the URI as and when
        // necessary, since it occurs later.  If in directory context,
        // the ordering of the fixup hooks is forced such that
        // mod_proxy comes first, so the URI must be escaped here
        // instead.  See PR 39746, 46428, and other headaches.
        if (ctx->perdir && (p->flags & RULEFLAG_NOESCAPE) == 0) {
            char *old_filename = r->filename;

            r->filename = ap_escape_uri(r->pool, r->filename);
            rewritelog((r, 2, ctx->perdir, "escaped URI in per-dir context "
                        "for proxy, %s -> %s", old_filename, r->filename));
        }

        fully_qualify_uri(r);

        rewritelog((r, 2, ctx->perdir, "forcing proxy-throughput with %s",
                    r->filename));

        r->filename = apr_pstrcat(r->pool, "proxy:", r->filename, NULL);
        apr_table_setn(r->notes, "rewrite-proxy", "1");
        return 1;
    }

    // If this rule is explicitly forced for HTTP redirection
    // (`RewriteRule .. .. [R]') then force an external HTTP
    // redirect. But make sure it is a fully-qualified URL. (If
    // not it is qualified with ourself).
    if (p->flags & RULEFLAG_FORCEREDIRECT) {
        fully_qualify_uri(r);

        rewritelog((r, 2, ctx->perdir, "explicitly forcing redirect with %s",
                    r->filename));

        r->status = p->forced_responsecode;
        return 1;
    }

    // Special Rewriting Feature: Self-Reduction
    // We reduce the URL by stripping a possible
    // http[s]://<ourhost>[:<port>] prefix, i.e. a prefix which
    // corresponds to ourself. This is to simplify rewrite maps
    // and to avoid recursion, etc. When this prefix is not a
    // coincidence then the user has to use [R] explicitly (see
    // above).
    reduce_uri(r);

    // If this rule is still implicitly forced for HTTP
    // redirection (`RewriteRule .. <scheme>://...') then
    // directly force an external HTTP redirect.
    if (is_absolute_uri(r->filename, NULL)) {
        rewritelog((r, 2, ctx->perdir, "implicitly forcing redirect (rc=%d) "
                    "with %s", p->forced_responsecode, r->filename));

        r->status = p->forced_responsecode;
        return 1;
    }

    // Finally remember the forced mime-type
    force_type_handler(p, ctx);

    // Puuhhhhhhhh... WHAT COMPLICATED STUFF ;_)
    // But now we're done for this particular rule.
    return 1;
	*/
}

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
        output("# Directive: $directive_name is not supported yet", $htaccess_line, LOG_FAILURE);

    } else if ($line_regex === true) {
        // Remove directive from the line
        $line = preg_replace("/^$directive_name/", "", $line);

        // Check for args
        $arg1 = $arg2 = $arg3 = '';
        if (parse_rewrite_rule_cond($line, $arg1, $arg2, $arg3)) {
            output("# A1: $arg1, A2: $arg2, A3: $arg3", $htaccess_line, LOG_COMMENT);

            // Parse the RewriteRule or RewriteCond
            if ($directive_name == "RewriteCond") {
                //$interpret = interpret_cond($arg1, $arg2, $arg3);
                $rewriteConds[] = array("args" => array($arg1, $arg2, $arg3),
                                        "line" => $htaccess_line);
                
                $directive_match = true;

            } else if ($directive_name == "RewriteRule") {
                $interpret = interpret_rule($arg1, $arg2, $arg3, $server_vars['REQUEST_URI'], $rewriteConds,
											$htaccess_line);
                
                foreach ($rewriteConds as $cond) {
                    
                    $interpret = interpret_cond($cond['args'][0], $cond['args'][1], $cond['args'][2],
												$cond['line'], $server_vars);
                    
                    
                }
                $rewriteConds = array();
                
                
                // NB this should be conditional
                $directive_match = true;

            } else {
                $directive_match = false;
                output("# Unknown directive $directive_name", $htaccess_line, LOG_FAILURE);
            }
        } else {
            $directive_match = false;
            output("# Directive syntax error", $htaccess_line, LOG_FAILURE);
        }

    } else if ( preg_match($line_regex, $line, $matches) ) {
        $directive_match = true;
        // TODO: handle rewrite base
        if (stripos($matches[0], "RewriteEngine") === 0) {
            if (strtolower($matches[1]) === "on") {
                output("# Excellent start!", $htaccess_line, LOG_SUCCESS);
            } else {
                output("# Well this is the first problem!", $htaccess_line, LOG_FAILURE);
            }
        } else {
            output("# Not implemented yet", $htaccess_line, LOG_COMMENT);
        }

    } else {
        $directive_match = false;
        output("# Directive syntax error/regex error...", $htaccess_line, LOG_FAILURE);
    }
    return $directive_match;
}

/**
 * TODO: handle RewriteBase
 * TODO: multiple RewriteConds/parse RewriteRules before RewriteConds... ie buffer RewriteConds (with line num)
 * until RewriteRule reached then eval RewriteRule in case of forward? backreferences in the RewriteConds
 */
function find_directive_match($line, $directives, $htaccess_line, $server_vars, &$rewriteConds) {
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
            $directive_match = process_directive($line_regex, $directive_name, $trimmed, $htaccess_line, $server_vars, $rewriteConds);
			// Early quit from for loop
			break;
		}
	}
	return $directive_match;
}

// Process stuff

if (!empty($_POST)) {
    $lines	= explode("\n", $htaccess);
    $inside_directive = false;
	$rewriteConds	= array();
    foreach($lines as $line) {

        // Is it a comment?
        if (preg_match("/^\s*#/", $line)) {
            output("# No comment...", $htaccess_line_count, LOG_COMMENT);

        // Is it another module directive?
        } else if (preg_match("/^\s*<(\/?)(.*)>\s*$/", $line, $match)) {

            if ( ! preg_match("/IfModule\s+mod_rewrite/i", $match[2])) {
                if ($match[1] == "/") {
                    if ($inside_directive) {
                        output("# Finally! Back to business...", $htaccess_line_count, LOG_FAILURE);
                    }
                    $inside_directive = false;
                } else {
                    output("# Unknown directive, Ignoring...", $htaccess_line_count, LOG_FAILURE);
                    $inside_directive = true;
                }
            } else {
                output("# This is kind of assumed :)", $htaccess_line_count, LOG_HELP);
            }

        // Does it match a directive
        } else if ( ! $inside_directive and find_directive_match($line, $directives, $htaccess_line_count,
																	$server_vars, $rewriteConds)) {
            //

        } else if ($inside_directive) {
            output("# Ignoring...", $htaccess_line_count, LOG_FAILURE);

        } else {
            output("# Unknown directive", $htaccess_line_count, LOG_FAILURE);
        }

        output($line, $htaccess_line_count);
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
