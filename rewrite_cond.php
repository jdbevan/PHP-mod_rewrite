<?php

/**
 * Lookup RewriteCond variables
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
 * Expand a RewriteCond test string using a SingleLinkedList implementation
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
 * Determine the kind of RewriteCond comparison required
 * @param string $cond_pattern The 2nd argument on a RewriteCond line
 * @return array Type constant indicating comparison required, pattern indicating
 * what to compare against
 */
function process_cond_pattern($cond_pattern, $htaccess_line) {
    $match = array();
	if ($cond_pattern === "expr") {
		output("ap_expr not supported yet", $htaccess_line, LOG_FAILURE);
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
					output("Can't determine existing directories", $htaccess_line, LOG_FAILURE);
					break;
				case "-f":
				case "-F":
					output("Can't determine existing files", $htaccess_line, LOG_FAILURE);
					break;
				case "-H":
				case "-l":
				case "-L":
					output("Can't determine existing symbolic links", $htaccess_line, LOG_FAILURE);
					break;
				case "-s":
					output("Can't determine file sizes", $htaccess_line, LOG_FAILURE);
					break;
				case "-U":
					output("Can't do internal URL request check", $htaccess_line, LOG_FAILURE);
					break;
				case "-x":
					output("Can't determine file permissions", $htaccess_line, LOG_FAILURE);
					break;
				default:
					break;
			}
		}
		if ($retval === false) {
			output("Unknown condition", $htaccess_line, LOG_FAILURE);
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


/**
 * Some bit magic<br>TODO: handle whitespace
 * @param string $flag_string The 3rd argument on RewriteCond
 * @param int $htaccess_line Which line we're on
 * @return int Bit flags indicating which options are set
 */
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
				output("No Vary flag... ignoring", $htaccess_line, LOG_COMMENT);
				break;
			case 'NC':
				$opts = $opts | FLAG_COND_NC;
				output("Case-insensitive flag", $htaccess_line, LOG_COMMENT);
				break;
			case 'OR':
				$opts = $opts | FLAG_COND_OR;
				output("OR flag", $htaccess_line, LOG_COMMENT);
				break;
			default:
				output("Unknown flag: `$flag`", $htaccess_line, LOG_FAILURE);
				break;
		}
	}
	return $opts;
}


/**
 * Evaluates a RewriteCond line
 * @param string $test_string First param, the string to match against
 * @param string $orig_cond_pattern Second param, the condition to match first param against
 * @param string $flags Flags indicating case-insensitivity NC, of the OR logic flag (ignore NV flag)
 * @param int $htaccess_line Which line we're on/to put output on
 * @param array $rewrite_backreferences Backreferences to the following RewriteRule
 * @param array $cond_backreferences Backreferences to the preceeding RewriteCond
 * @param array $server_vars Server variables
 * @return array|Boolean Array on success/regex match (contains "success" and "flags" keys), false on failure
 */
function interpret_cond($test_string, $orig_cond_pattern, $flags, $htaccess_line, $rewrite_backreferences, $cond_backreferences, $server_vars) {
	
	// Step 1
	$parsed_flags = parse_cond_flags($flags, $htaccess_line);

	// Step 2
	$expanded_test_string = expand_teststring($test_string, $rewrite_backreferences, $cond_backreferences, $htaccess_line, $server_vars);
    if ($expanded_test_string === false) {
        return false;
    }
    if (empty($expanded_test_string)) {
    	output("`$test_string` contains nothing", $htaccess_line, LOG_HELP);
    } else {
        output("`$test_string` contains `$expanded_test_string`", $htaccess_line, LOG_HELP);
    }
	
	// Step 3
	$negative_match = substr($orig_cond_pattern, 0, 1) === "!";
	if ($negative_match) {
		output("Negative match", $htaccess_line, LOG_COMMENT);
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
                    if ($negative_match) {
                        output("FAIL: string comparison `$expanded_test_string` < `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: string comparison `$expanded_test_string` < `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: string comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: string comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_STR_GT:
				if ($strcmp > 0) {
                    if ($negative_match) {
                        output("FAIL: string comparison `$expanded_test_string` > `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: string comparison `$expanded_test_string` > `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: string comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: string comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_STR_EQ:
				if ($strcmp === 0) {
                    if ($negative_match) {
                        output("FAIL: string comparison `$expanded_test_string` = `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: string comparison `$expanded_test_string` = `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;                        
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: string comparison `$expanded_test_string` != `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: string comparison `$expanded_test_string` != `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_STR_LTE:
                if ($strcmp <= 0) {
                    if ($negative_match) {
                        output("FAIL: string comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: string comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: string comparison `$expanded_test_string` > `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: string comparison `$expanded_test_string` > `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_STR_GTE:
				if ($strcmp >= 0) {
                    if ($negative_match) {
                        output("FAIL: string comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: string comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: string comparison `$expanded_test_string` < `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: string comparison `$expanded_test_string` < `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_INT_EQ:
				if ($eq) {
                    if ($negative_match) {
                        output("FAIL: integer comparison `$expanded_test_string` == `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: integer comparison `$expanded_test_string` == `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: integer comparison `$expanded_test_string` != `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: integer comparison `$expanded_test_string` != `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_INT_GT:
				if ( ! $lt and ! $eq) {
                    if ($negative_match) {
                        output("FAIL: integer comparison `$expanded_test_string` > `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: integer comparison `$expanded_test_string` > `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: integer comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: integer comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_INT_GTE:
				if ( ! $lt or $eq) {
                    if ($negative_match) {
                        output("FAIL: integer comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: integer comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: integer comparison `$expanded_test_string` < `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: integer comparison `$expanded_test_string` < `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_INT_LT:
				if ($lt) {
                    if ($negative_match) {
                        output("FAIL: integer comparison `$expanded_test_string` < `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: integer comparison `$expanded_test_string` < `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: integer comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: integer comparison `$expanded_test_string` >= `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_INT_LTE:
				if ($lt or $eq) {
                    if ($negative_match) {
                        output("FAIL: integer comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`, but we don't want it to be", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    } else {
                        output("PASS: integer comparison `$expanded_test_string` <= `{$pattern_type['pattern']}`", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    }
				} else {
                    if ($negative_match) {
                        output("PASS: integer comparison `$expanded_test_string` > `{$pattern_type['pattern']}`, and we don't want it to be", $htaccess_line, LOG_SUCCESS);
                        $retval = true;
                    } else {
                        output("FAIL: integer comparison `$expanded_test_string` > `{$pattern_type['pattern']}`", $htaccess_line, LOG_FAILURE);
                        $retval = false;
                    }
				}
				break;
			case COND_COMPARE_REGEX:
				$retval = regex_match($pattern_type['pattern'], $expanded_test_string, $negative_match, $parsed_flags & FLAG_COND_NC, $htaccess_line);
				break;
			default:
				output("`$cond_pattern` not supported yet", $htaccess_line, LOG_FAILURE);
				$retval = false;
				break;
		}
		return array("success" => $retval, "flags" => $parsed_flags);
	} else {
		output("Unknown", $htaccess_line, LOG_FAILURE);
		return false;
	}
}

