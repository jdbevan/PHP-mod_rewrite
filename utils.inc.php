<?php
/**
 * TODO: make output handling better
 */
function output($message, $line, $level = LOG_NORMAL) {
	// YUCK!
	global $output_table;
	
	$content = preg_match("/^\s*$/", trim($message)) ? "&nbsp;" : htmlentities($message);
	$html = "<span class='$level'>" . $content . "</span>\n";
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
 * TODO: add regex flags? Ie case-insensitive
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
		output("# $cond_pattern invalid regex", $htaccess_line, LOG_FAILURE);
		return false;
	}
	if ($test_string==="") {
		$test_string = "an empty string";
	}
	if ($match === 1) {
		// There is a regex match
		if ($negative_match) {
			output("# FAIL: $cond_pattern matches $test_string, but we don't want it to", $htaccess_line, LOG_FAILURE);
			return false;
		} else {
			output("# PASS: $cond_pattern matches $test_string", $htaccess_line, LOG_SUCCESS);
			return $groups;
		}
	} else {
		// There is no regex match
		if ($negative_match) {
			output("# PASS: $cond_pattern doesn't match $test_string, and we don't want it to", $htaccess_line, LOG_SUCCESS);
			return $groups;
		} else {
			output("# FAIL: $cond_pattern doesn't match $test_string", $htaccess_line, LOG_FAILURE);
			return false;
		}
	}
}
