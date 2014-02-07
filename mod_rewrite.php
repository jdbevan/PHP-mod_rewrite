<!DOCTYPE html>
<html>
<head>
<title>mod_rewrite.php</title>
<style>
td { vertical-align:bottom; padding: 3px 15px 3px 3px; }
thead tr, tbody tr:nth-child(2n) { background-color: #F8F8F8; }
</style>
</head>
<body>
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

/*
	TODO: replace with:
	
/* perform all the expansions on the input string
 * putting the result into a new string
 *
 * for security reasons this expansion must be performed in a
 * single pass, otherwise an attacker can arrange for the result
 * of an earlier expansion to include expansion specifiers that
 * are interpreted by a later expansion, producing results that
 * were not intended by the administrator.
 * /
static char *do_expand(char *input, rewrite_ctx *ctx, rewriterule_entry *entry)
{
    result_list *result, *current;
    result_list sresult[SMALL_EXPANSION];
    unsigned spc = 0;
    apr_size_t span, inputlen, outlen;
    char *p, *c;
    apr_pool_t *pool = ctx->r->pool;

    span = strcspn(input, "\\$%");
    inputlen = strlen(input);

    // fast exit
    if (inputlen == span) {
        return apr_pstrmemdup(pool, input, inputlen);
    }

    // well, actually something to do
    result = current = &(sresult[spc++]);

    p = input + span;
    current->next = NULL;
    current->string = input;
    current->len = span;
    outlen = span;

    // loop for specials
    do {
        // prepare next entry
        if (current->len) {
            current->next = (spc < SMALL_EXPANSION)
                            ? &(sresult[spc++])
                            : (result_list *)apr_palloc(pool,
                                                        sizeof(result_list));
            current = current->next;
            current->next = NULL;
            current->len = 0;
        }

        // escaped character
        if (*p == '\\') {
            current->len = 1;
            ++outlen;
            if (!p[1]) {
                current->string = p;
                break;
            }
            else {
                current->string = ++p;
                ++p;
            }
        }

        // variable or map lookup
        else if (p[1] == '{') {
            char *endp;

            endp = find_closing_curly(p+2);
            if (!endp) {
                current->len = 2;
                current->string = p;
                outlen += 2;
                p += 2;
            }

            // variable lookup
            else if (*p == '%') {
                p = lookup_variable(apr_pstrmemdup(pool, p+2, endp-p-2), ctx);

                span = strlen(p);
                current->len = span;
                current->string = p;
                outlen += span;
                p = endp + 1;
            }

            // map lookup
            else {     // *p == '$'
                char *key;

                /*
                 * To make rewrite maps useful, the lookup key and
                 * default values must be expanded, so we make
                 * recursive calls to do the work. For security
                 * reasons we must never expand a string that includes
                 * verbatim data from the network. The recursion here
                 * isn't a problem because the result of expansion is
                 * only passed to lookup_map() so it cannot be
                 * re-expanded, only re-looked-up. Another way of
                 * looking at it is that the recursion is entirely
                 * driven by the syntax of the nested curly brackets.
                 * /

                key = find_char_in_curlies(p+2, ':');
                if (!key) {
                    current->len = 2;
                    current->string = p;
                    outlen += 2;
                    p += 2;
                }
                else {
                    char *map, *dflt;

                    map = apr_pstrmemdup(pool, p+2, endp-p-2);
                    key = map + (key-p-2);
                    *key++ = '\0';
                    dflt = find_char_in_curlies(key, '|');
                    if (dflt) {
                        *dflt++ = '\0';
                    }

                    // reuse of key variable as result
                    key = lookup_map(ctx->r, map, do_expand(key, ctx, entry));

                    if (!key && dflt && *dflt) {
                        key = do_expand(dflt, ctx, entry);
                    }

                    if (key) {
                        span = strlen(key);
                        current->len = span;
                        current->string = key;
                        outlen += span;
                    }

                    p = endp + 1;
                }
            }
        }

        // backreference
        else if (apr_isdigit(p[1])) {
            int n = p[1] - '0';
            backrefinfo *bri = (*p == '$') ? &ctx->briRR : &ctx->briRC;

            // see ap_pregsub() in server/util.c
            if (bri->source && n < AP_MAX_REG_MATCH
                && bri->regmatch[n].rm_eo > bri->regmatch[n].rm_so) {
                span = bri->regmatch[n].rm_eo - bri->regmatch[n].rm_so;
                if (entry && (entry->flags & RULEFLAG_ESCAPEBACKREF)) {
                    // escape the backreference
                    char *tmp2, *tmp;
                    tmp = apr_pstrmemdup(pool, bri->source + bri->regmatch[n].rm_so, span);
                    tmp2 = escape_uri(pool, tmp);
                    rewritelog((ctx->r, 5, ctx->perdir, "escaping backreference '%s' to '%s'",
                            tmp, tmp2));

                    current->len = span = strlen(tmp2);
                    current->string = tmp2;
                } else {
                    current->len = span;
                    current->string = bri->source + bri->regmatch[n].rm_so;
                }

                outlen += span;
            }

            p += 2;
        }

        // not for us, just copy it
        else {
            current->len = 1;
            current->string = p++;
            ++outlen;
        }

        // check the remainder
        if (*p && (span = strcspn(p, "\\$%")) > 0) {
            if (current->len) {
                current->next = (spc < SMALL_EXPANSION)
                                ? &(sresult[spc++])
                                : (result_list *)apr_palloc(pool,
                                                           sizeof(result_list));
                current = current->next;
                current->next = NULL;
            }

            current->len = span;
            current->string = p;
            p += span;
            outlen += span;
        }

    } while (p < input+inputlen);

    // assemble result
    c = p = apr_palloc(pool, outlen + 1); // don't forget the \0
    do {
        if (result->len) {
            ap_assert(c+result->len <= p+outlen); // XXX: can be removed after
                                                  // extensive testing and
                                                  // review
            memcpy(c, result->string, result->len);
            c += result->len;
        }
        result = result->next;
    } while (result);

    p[outlen] = '\0';

    return p;
}
*/
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
 * 
 * @param string $test_string
 * @param string $orig_cond_pattern
 * @param string $flags
 * @return Boolean true on success/match, false on failure/no match
 */
function interpret_cond($test_string, $orig_cond_pattern, $flags) {
	$expanded_test_string = expand_teststring($test_string);
	logger("# Expanded test string: $expanded_test_string", LOG_HELP);
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
					logger("# MATCH >> $expanded_test_string < {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string >= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_GT:
				if ($strcmp > 0) {
					logger("# MATCH >> $expanded_test_string > {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string <= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_EQ:
				if ($strcmp === 0) {
					logger("# MATCH >> $expanded_test_string = {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string != {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_LTE:
				if ($strcmp <= 0) {
					logger("# MATCH >> $expanded_test_string <= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string > {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_STR_GTE:
				if ($strcmp >= 0) {
					logger("# MATCH >> $expanded_test_string >= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string < {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_EQ:
				if ($eq) {
					logger("# MATCH >> $expanded_test_string == {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string != {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_GT:
				if ( ! $lt and ! $eq) {
					logger("# MATCH >> $expanded_test_string > {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string <= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_GTE:
				if ( ! $lt or $eq) {
					logger("# MATCH >> $expanded_test_string >= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string < {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_LT:
				if ($lt) {
					logger("# MATCH >> $expanded_test_string < {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string >= {$pattern_type['pattern']}", LOG_FAILURE);
					return false;
				}
				break;
			case COND_COMPARE_INT_LTE:
				if ($lt or $eq) {
					logger("# MATCH >> $expanded_test_string <= {$pattern_type['pattern']}", LOG_SUCCESS);
					return true;
				} else {
					logger("# NO MATCH >> $expanded_test_string > {$pattern_type['pattern']}", LOG_FAILURE);
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

function regex_match($cond_pattern, $test_string, $negative_match){
	$match = preg_match("/$cond_pattern/", $test_string, $groups);
	if ($match === false) {
		logger("# $cond_pattern invalid regex", LOG_FAILURE);
		return false;
	}
	if ($negative_match and $match === 0) {
		logger("# MATCH >> $cond_pattern negative matches $test_string", LOG_SUCCESS);
		return true;
	} else if (!$negative_match and $match === 1) {
		logger("# MATCH >> $cond_pattern matches $test_string", LOG_SUCCESS);
		return true;
	} else {
		logger("# NO MATCH >> $cond_pattern doesn't match $test_string", LOG_FAILURE);
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
				logger("# Directive: $directive_name is not supported yet", LOG_FAILURE);
				
			} else if ($line_regex === true) {
				// Remove directive
				$trimmed = preg_replace("/^$directive_name/", "", $trimmed);
				// Check for args
				if (parse_rewrite_rule_cond($trimmed, $arg1, $arg2, $arg3)) {
					logger("# A1: $arg1, A2: $arg2, A3: $arg3", LOG_COMMENT);
					if ($directive_name == "RewriteCond") {
						$match = interpret_cond($arg1, $arg2, $arg3);
					} else if ($directive_name == "RewriteRule") {
						$match = interpret_rule($arg1, $arg2, $arg3);
					} else {
						$match = false;
						logger("# Unknown directive $directive_name", LOG_FAILURE);
					}
				} else {
					logger("# Directive syntax error", LOG_FAILURE);
				}
				
			} else if ( preg_match($line_regex, $trimmed, $matches) ) {
				$match = true;
				logger("# " . str_replace(array("\r\n", "\r", "\n"), "", var_export($matches, true)), LOG_HELP);
				
			} else {
				logger("# Directive syntax error/regex error...", LOG_FAILURE);
			}
			break;
		}
	}
	return $match;
}

if (!empty($_POST)) {
	$lines = explode("\n", $htaccess);
	foreach($lines as $line) {

		// Does it match a directive
		if (matches_directive($line, $directives)) {
			//
		}
		logger($line);
		$htaccess_line_count++;
	}
?>
	<table style='font-family:monospace;'>
		<thead>
			<tr><th>htaccess</th><th>info</th></tr>
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
<?php
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
	<textarea rows="15" cols="90" name="HTACCESS_RULES"><?php echo htmlentities($htaccess); ?></textarea><br>
	<input type="submit" />
</form>
</body>
</html>