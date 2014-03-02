<?php

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

