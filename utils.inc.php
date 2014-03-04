<?php

/**
 * Actually process the directives, queuing up RewriteConds until we reach a RewriteRule
 * @param boolean|string $line_regex False if directive not supported, true if supported
 *									and requires parsing, string if actual regular expression to match
 * @param string $directive_name	The mod rewrite directive
 * @param string $line				The trimmed mod_rewrite line
 * @param int $htaccess_line		Which line we're on
 * @param array $server_vars		Server variables
 * @param array $rewriteConds		Passed by reference array of RewriteConds that need parsing after RewriteRule is found
 * @return boolean|array			False on failure, true on success, array on RewriteRule success
 */
function process_directive($line_regex, $directive_name, $line, $htaccess_line, $server_vars, &$rewriteConds) {
    
    $matches = array();
    if ($line_regex === false) {
        $directive_match = true;
        output("Directive: `$directive_name` is not supported yet", $htaccess_line, LOG_FAILURE);

    } else if ($line_regex === true) {
        // Remove directive from the line
        $line = preg_replace("/^$directive_name/", "", $line);

        // Check for args
        $arg1 = $arg2 = $arg3 = '';
        if (parse_rewrite_rule_cond($line, $arg1, $arg2, $arg3)) {
            //output("A1: $arg1, A2: $arg2, A3: $arg3", $htaccess_line, LOG_COMMENT);

            $directive_match = process_args($directive_name, $arg1, $arg2, $arg3, $htaccess_line, $server_vars, $rewriteConds);
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
 * Queue up RewriteConds, fail if unknown directive, and process RewriteRules with preceeding Conditions
 * @param type $directive_name	The directive to handle
 * @param type $arg1			The directive's 1st argument
 * @param type $arg2			The directive's 2nd argument
 * @param type $arg3			The directive's 3rd argument
 * @param type $htaccess_line	Which line we're on
 * @param type $server_vars		The server variables
 * @param type $rewriteConds	The queued RewriteConds
 * @return boolean|array True for RewriteConds, False for unknown directives and for failed RewriteRules,<br>
 *						array for RewriteRule success
 */
function process_args($directive_name, $arg1, $arg2, $arg3, $htaccess_line, $server_vars, &$rewriteConds) {
	
	// Parse the RewriteRule or RewriteCond
	if ($directive_name == "RewriteCond") {
		//$interpret = interpret_cond($arg1, $arg2, $arg3);
		$rewriteConds[] = array("args" => array($arg1, $arg2, $arg3),
								"line" => $htaccess_line);

		$directive_match = true;

	} else if ($directive_name == "RewriteRule") {
		$parsed_flags = FLAG_RULE_NONE;
		$interpret = interpret_rule($arg1, $arg2, $arg3, $parsed_flags, $server_vars, $rewriteConds,
									$htaccess_line);
		// Reset conditions
		$rewriteConds = array();

		if ($interpret === false) {
			$directive_match = false;
		} else {
			$directive_match = array("new_url" => $interpret, "flags" => $parsed_flags);
		}

	} else {
		$directive_match = false;
		output("Unknown directive `$directive_name`", $htaccess_line, LOG_FAILURE);
	}
	return $directive_match;
}

/**
 * TODO: handle RewriteBase
 * @param string $line The line we're investigating
 * @param array $directives The directives we know
 * @param int $htaccess_line Which line we're on, for output usage
 * @param array $server_vars The server variables
 * @param array &$rewriteConds The RewriteConds preceding this RewriteRule
 * @return array|boolean True on success, false on failure, array containing new URL and RewriteRule flags on new URL
 */
function consume_directives($line, $directives, $htaccess_line, $server_vars, &$rewriteConds) {
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
            $directive_match = process_directive($line_regex, $directive_name, $trimmed,
												$htaccess_line, $server_vars, $rewriteConds);
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

/**
 * Expand a RewriteCond/RewriteRule string using a SingleLinkedList implementation
 * @param string $input The test string to expand
 * @param array $rewrite_backreferences Array of RewriteRule backreferences
 * @param array $cond_backreferences Array of RewriteCond backreferences
 * @param int $htaccess_line Output line number
 * @param array $server_vars Server variable values
 * @return string|boolean The expanded test string or false on unsupported<br>
 * expansion
 */
function expand_teststring($input, $rewrite_backreferences, $cond_backreferences, $htaccess_line, $server_vars) {
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
					output("Unknown variable: `$lookup_string`", $htaccess_line, LOG_FAILURE);
					// Quit while I'm behind
					return false;
				} else if ($sysvar === false) {
					output("Unsupported variable: `$lookup_string`", $htaccess_line, LOG_FAILURE);
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
                output("Sorry Rewrite Maps aren't supported", $htaccess_line, LOG_FAILURE);
                
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
        else if (strcspn($input[$str_pos], "$%")===0 and preg_match("/^\d$/", $input[$str_pos + 1])) {
            
            $n = (int)$input[$str_pos + 1];
            $backRefType = $input[$str_pos] == "$"
                        ? BACKREF_REWRITE_RULE
                        : BACKREF_REWRITE_COND;
            
            // TODO: check for escapebackreferenceflag?
			if ($backRefType == BACKREF_REWRITE_RULE) {
				if (isset($rewrite_backreferences[ $n ])) {
					$span = strlen($rewrite_backreferences[ $n ]);
					$current->length = $span;
					$current->string = $rewrite_backreferences[ $n ]; // backreference value
					$outlen += $span;
					$str_pos += 2;
				} else {
					$span = 0;
					$current->length = $span;
					$current->string = ""; // backreference value
					$outlen += $span;
					output("RewriteRule back-reference `\$$n` not matched", $htaccess_line, LOG_FAILURE);
					$str_pos += 2;
				}
			} else {
				if (isset($cond_backreferences[ $n ])) {
                    $span = strlen($cond_backreferences[ $n ]); // length of backreference value
                    $current->length = $span;
                    $current->string = $cond_backreferences[ $n ]; // backreference value
                    $outlen += $span;
                    $str_pos += 2;
                } else {
					$span = 0;
					$current->length = $span;
					$current->string = ""; // backreference value
					$outlen += $span;
					output("RewriteCond back-reference `%$n` not matched", $htaccess_line, LOG_FAILURE);
					$str_pos += 2;
                }
			}
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
            $current->string = substr($input, $str_pos, $span);
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
 * Lookup server variables
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
			$result = false;
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
 * TODO: make output handling better
 */
function output($message, $line, $level = LOG_NORMAL) {
	// YUCK!
	global $output_table;
	
	$content = preg_match("/^\s*$/", trim($message)) ? "&nbsp;" : htmlentities($message);
    $code = preg_replace("/`([^`]+)`/", "<code>$1</code>", $content);
	$html = "<p class='$level'>" . $code . "</p>\n";
	if (!isset($output_table[$line])) {
		$output_table[$line] = array("htaccess" => "", "info" => "");
	}
	if ($level === LOG_NORMAL || $level === LOG_NORMAL_PASS || $level === LOG_NORMAL_FAIL) {
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

	$arg2 = parse_for_arg($line, $char_pos);
	$len = strlen($line);
	
	while ($char_pos < $len-1 and is_space($line[$char_pos])) {
		$char_pos++;
	}
	if ($char_pos === $len) {
		return true;
	}
	
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
 * Perform a regular expression match
 * @param string $cond_pattern The regular expression
 * @param string $test_string The string to match against the regular expression
 * @param boolean $negative_match True to perform a negative regex match
 * @returns array|boolean Array of matched groups on successful match, false on failure to match
 */
function regex_match($cond_pattern, $test_string, $negative_match, $case_insensitive, $htaccess_line){
    $groups = array();
	if ($case_insensitive) {
		$match = preg_match("#$cond_pattern#i", $test_string, $groups);
	} else {
		$match = preg_match("#$cond_pattern#", $test_string, $groups);
	}
	if ($match === false) {
		output("`$cond_pattern` invalid regex", $htaccess_line, LOG_FAILURE);
		return false;
	}
	if ($test_string==="") {
		$test_string = "nothing";
    } else {
        $test_string = "`$test_string`";
    }
	if ($match === 1) {
		// There is a regex match
		if ($negative_match) {
			output("FAIL: `$cond_pattern` matches $test_string, but we don't want it to", $htaccess_line, LOG_FAILURE);
			return false;
		} else {
			output("PASS: `$cond_pattern` matches $test_string", $htaccess_line, LOG_SUCCESS);
            if (count($groups)>1) {
    			output("Matched groups: " . format_matched_groups($groups), $htaccess_line, LOG_COMMENT);
            }
			return $groups;
		}
	} else {
		// There is no regex match
		if ($negative_match) {
			output("PASS: `$cond_pattern` doesn't match $test_string, and we don't want it to", $htaccess_line, LOG_SUCCESS);
            if (count($groups)>1) {
    			output("Matched groups: " . format_matched_groups($groups), $htaccess_line, LOG_COMMENT);
            }
			return $groups;
		} else {
			output("FAIL: `$cond_pattern` doesn't match $test_string", $htaccess_line, LOG_FAILURE);
			return false;
		}
	}
}

function format_matched_groups($array) {
    $str = '';
    foreach ($array as $index => $value) {
        $str .= "$index => `$value`, ";
    }
    return substr($str, 0, -2);
}