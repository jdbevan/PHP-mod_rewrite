<?php

/**
 * Some bit magic<br>TODO: handle whitespace
 * @param string $flag_string The 3rd argument on RewriteCond
 * @param int $htaccess_line Which line we're on
 * @return int Bit flags indicating which options are set
 */
function parse_rule_flags($flag_string, $htaccess_line) {
	$opts = FLAG_RULE_NONE;
	
	if (empty($flag_string)) {
		return $opts;
	}
	
	$trim_flags = preg_replace("/(^\[|\]$)/", "", $flag_string);
	$flags = explode(",", $trim_flags);
	
	foreach($flags as $flag) {
		switch ($flag) {
			case 'B':
				$opts = $opts | FLAG_RULE_ESCAPE;
				output("B (escape) flag not implemented yet", $htaccess_line, LOG_COMMENT);
				break;
			case 'C':
			case 'chain':
				$opts = $opts | FLAG_RULE_CHAIN;
				output("Chain flag not implemented yet", $htaccess_line, LOG_COMMENT);
				break;
			case 'DPI':
				$opts = $opts | FLAG_RULE_DISCARDPATH;
				output("Discard Path flag not supported", $htaccess_line, LOG_FAILURE);
				break;
			case 'END':
				$opts = $opts | FLAG_RULE_END;
				output("END flag not implemented yet", $htaccess_line, LOG_COMMENT);
				break;
			case 'F':
			case 'forbidden':
				$opts = $opts | FLAG_RULE_FORBIDDEN;
				output("A '403 Forbidden' HTTP Status code will be sent to the client", $htaccess_line, LOG_HELP);
				break;
			case 'G':
			case 'gone':
				$opts = $opts | FLAG_RULE_GONE;
				output("A '410 Gone' HTTP Status code will be sent to the client", $htaccess_line, LOG_HELP);
				break;
			case 'L':
			case 'last':
				$opts = $opts | FLAG_RULE_LAST;
				output("Stop processing rules", $htaccess_line, LOG_COMMENT);
				break;
			case 'NC':
			case 'nocase':
				$opts = $opts | FLAG_RULE_NOCASE;
				output("Case insensitive match", $htaccess_line, LOG_COMMENT);
				break;
			case 'NE':
			case 'noescape':
				$opts = $opts | FLAG_RULE_NOESCAPE;
				output("No escape flag not implemented yet", $htaccess_line, LOG_COMMENT);
				break;
			case 'NS':
			case 'nosubreq':
				$opts = $opts | FLAG_RULE_NOSUBREQ;
				output("No sub request flag not supported", $htaccess_line, LOG_FAILURE);
				break;
			case 'P':
			case 'proxy':
				$opts = $opts | FLAG_RULE_PROXY;
				output("Proxy flag not supported as it requires mod_proxy", $htaccess_line, LOG_FAILURE);
				break;
			case 'PT':
			case 'passthrough':
				$opts = $opts | FLAG_RULE_PASSTHRU;
				output("Pass through flag not supported", $htaccess_line, LOG_FAILURE);
				break;
			case 'QSA':
			case 'qsappend':
				$opts = $opts | FLAG_RULE_QSAPPEND;
				output("Append original query string", $htaccess_line, LOG_COMMENT);
				break;
			case 'QSD':
			case 'qsdiscard':
				$opts = $opts | FLAG_RULE_QSDISCARD;
				output("Discard original query string", $htaccess_line, LOG_COMMENT);
				break;
			default:
				$opts = $opts | handle_complex_flags($flag, $htaccess_line);
		}
	}
	return $opts;
}

/**
 * Parse more complex flags
 * @param string $flag The flag
 * @return Bit flags indicating which flag is set
 */
function handle_complex_flags($flag, $htaccess_line) {
	$opts = FLAG_RULE_NONE;
	$flag_args = array();
	if (preg_match("/^(CO|cookie)=(.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_COOKIE;
		output("Cookie flag not supported", $htaccess_line, LOG_FAILURE);
		
	} else if (preg_match("/^(R|redirect)(=.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_REDIRECT;
		output("Redirect flag not implemented yet", $htaccess_line, LOG_COMMENT);
		
	} else if (preg_match("/^(S|skip)(=.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_SKIP;
		output("Skip flag not implemented yet", $htaccess_line, LOG_COMMENT);
		
	} else if (preg_match("/^(T|type)=(.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_TYPE;
		output("Type flag not supported", $htaccess_line, LOG_FAILURE);
		
	} else if (preg_match("/^(N|next)(=.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_NEXT;
		output("Next flag not implemented", $htaccess_line, LOG_COMMENT);
		
	} else if (preg_match("/^(H|handler)=(.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_HANDLER;
		output("Handler flag not supported", $htaccess_line, LOG_FAILURE);
		
	} else if (preg_match("/^(E|env)=(.*)/", $flag, $flag_args)) {
		$opts = $opts | FLAG_RULE_ENV;
		output("Environment variable flag not supported", $htaccess_line, LOG_FAILURE);
		
	} else {
		output("Flag not known: `$flag`", $htaccess_line, LOG_FAILURE);
	}
	
	return $opts;
}

/**
 * TODO: handle invalid substitutions
 * Process RewriteRule and preceeding RewriteConds, and return new URL
 * @param string $orig_pattern	The pattern to match REQUEST_URI against
 * @param string $substitution	The substitution string
 * @param string $flags			The string of flags to process
 * @param int $parsed_flags		Bit mask for flags
 * @param array $server_vars	Array of server variables
 * @param array $rewrite_conds	Array of RewriteConds
 * @param int $htaccess_line	Which line we're on
 * @return string|boolean New URL or false if no match
 */
function interpret_rule($orig_pattern, $substitution, $flags, &$parsed_flags, $server_vars, $rewrite_conds, $htaccess_line) {
	$new_url = null;
	$url_path = $server_vars['REQUEST_URI'];
	$orig_url = $server_vars['REQUEST_SCHEME'] . "://" . $server_vars['HTTP_HOST'] . $url_path;
	if (!empty($server_vars['QUERY_STRING'])) {
		$orig_url .= "?" . $server_vars['QUERY_STRING'];
	}
    
	// Step 1
	$parsed_flags = parse_rule_flags($flags, $htaccess_line);
	
	// Step 2
	$negative_match = substr($orig_pattern, 0, 1) === "!";
	if ($negative_match) {
		$rewrite_pattern = substr($orig_pattern, 1);
	} else {
		$rewrite_pattern = $orig_pattern;
	}
	
	// Step 3
	$no_change			= ($substitution === "-");
	$case_insensitive	= ($parsed_flags & FLAG_RULE_NOCASE) == FLAG_RULE_NOCASE;
	$qs_append			= ($parsed_flags & FLAG_RULE_QSAPPEND) == FLAG_RULE_QSAPPEND;
	$qs_discard			= ($parsed_flags & FLAG_RULE_QSDISCARD) == FLAG_RULE_QSDISCARD;
	
	// Remove leading slash
	$old_url_path = preg_replace("/^\//", "", $url_path);

    // TODO: swap in RewriteCond backreferences
	output("RewriteRule matching against " . ($old_url_path === "" ? 'a blank request string' : "`" . $old_url_path . "`"),
			$htaccess_line, LOG_HELP);
	$matches = regex_match($rewrite_pattern, $old_url_path, $negative_match, $case_insensitive, $htaccess_line);
	$retval = true;
	if ( $matches === false ) {
		$retval = false;
	}

    $condition_passed	= true;
    $skip_if_or_flag	= false;
	$or_flag			= false;
    $last_cond_groups	= array();
	
    for ($i=0,$m=count($rewrite_conds); $i<$m; $i++) {
        $cond = $rewrite_conds[$i];
        $rc = interpret_cond($cond['args'][0], $cond['args'][1], $cond['args'][2],
                            $htaccess_line - $m + $i, $matches, $last_cond_groups, $server_vars);
        
		$this_has_or_flag = $rc['flags'] & FLAG_COND_OR;
        if (is_array($rc)) {
			
            if ($skip_if_or_flag) {
                output("Skipping as previous RewriteCond matched and had OR flag", $htaccess_line - $m + $i, LOG_SUCCESS);
            } else if ($or_flag and ! $condition_passed) {
                output("Last RewriteCond had OR flag, so we still check this condition", $htaccess_line - $m + $i, LOG_HELP);
            }
			if ( ! $condition_passed and ! $or_flag) {
                output("Skipping as previous RewriteCond failed", $htaccess_line - $m + $i, LOG_FAILURE);
			} else {
				// success can be true or an array
				if ($rc['success'] !== false) {
					$condition_passed			= true;
					$last_cond_groups	= $rc['success'];
					
				} else if ( ! $skip_if_or_flag) {
					// Try the next condition
					$condition_passed = false;
				}
				// Make sure we don't keep skipping if the condition failed and there
				// is not OR flag
				if ($this_has_or_flag and $condition_passed) {
					$skip_if_or_flag = true;
				} else {
					$skip_if_or_flag = false;
				}
				$or_flag = $this_has_or_flag;
			}
        } else {
            $skip_if_or_flag	= false;
			$condition_passed		= false;
            while ($i<$m) {
                output("Skipping...", $htaccess_line - $m + ++$i, LOG_FAILURE);
            }
            $retval = false;
			break;
        }
    }
	
	if ( ! $condition_passed) {
		output("Not matched as RewriteCond failed", $htaccess_line, LOG_FAILURE);
		$retval = false;
		
	} else if ($no_change) {
		$retval = $orig_url;
		
	} else if ($retval !== false) {
		$new_url		= expand_teststring($substitution, $matches, $last_cond_groups, $htaccess_line, $server_vars);
		$parsed_new_url = parse_url($new_url);
		if (!preg_match("/^(f|ht)tps?/", $new_url)) {
			$new_url = $server_vars['REQUEST_SCHEME'] . "://" . $server_vars['HTTP_HOST'] . $new_url;
		}
		$new_query_string = '';
		// QSA - if new url contains query string, overwrite old query string unless QSA flag set
		if ( empty($parsed_new_url['query']) ) {
			if ( ! empty($server_vars['QUERY_STRING']) and ! $qs_discard) {
				$new_query_string = "?" . $server_vars['QUERY_STRING'];
			}
		} else {
			if ( ! empty($server_vars['QUERY_STRING']) and $qs_append and ! $qs_discard) {
				$new_query_string = "&" . $server_vars['QUERY_STRING'];
			}
		}
		
		// QSD - if orig url contains query string, and new doesn't, keep unless QSD
		if ( ! empty($server_vars['QUERY_STRING']) ) {
			if ( empty($parsed_new_url['query']) and ! $qs_discard ) {
				$new_query_string = "?" . $server_vars['QUERY_STRING'];
			}
		}
		$new_url .= $new_query_string;
		$retval = $new_url;
	}
    return $retval;
	/**
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
