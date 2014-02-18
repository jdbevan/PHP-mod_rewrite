<?php

/**
 * TODO: handle invalid substitutions
 */
function interpret_rule($orig_pattern, $substitution, $flags, $server_vars, $rewrite_conds, $htaccess_line) {
	$new_url = null;
	$url_path = $server_vars['REQUEST_URI'];
	$orig_url = $server_vars['REQUEST_SCHEME'] . "://" . $server_vars['HTTP_HOST'] . $url_path;
	if (!empty($server_vars['QUERY_STRING'])) {
		$orig_url .= "?" . $server_vars['QUERY_STRING'];
	}
    
	// Step 1
    // TODO: handle rewriterule flags
	$parsed_flags = FLAG_RULE_NONE;
	
	// Step 2
	$negative_match = substr($orig_pattern, 0, 1) === "!";
	if ($negative_match) {
		$rewrite_pattern = substr($orig_pattern, 1);
	} else {
		$rewrite_pattern = $orig_pattern;
	}
	
	// Step 3
	$no_change = ($substitution === "-");
	
	$case_insensitive = $parsed_flags & FLAG_RULE_NOCASE;
	
	// Remove leading slash
	$old_url_path = preg_replace("/^\//", "", $url_path);
    // TODO: swap in RewriteCond backreferences
	output("RewriteRule matching against ". ($old_url_path===""?'an empty request string':$old_url_path), $htaccess_line, LOG_HELP);
	$matches = regex_match($rewrite_pattern, $old_url_path, $negative_match, $case_insensitive, $htaccess_line);
	$retval = true;
	if ( $matches === false ) {
		$retval = false;
	}

    $cond_pass			= true;
    $skip_if_condor		= false;
	$cond_or			= false;
    $last_cond_groups	= array();
	
    for ($i=0,$m=count($rewrite_conds); $i<$m; $i++) {
        $cond = $rewrite_conds[$i];
        $rc = interpret_cond($cond['args'][0], $cond['args'][1], $cond['args'][2],
                            $htaccess_line - $m + $i, $matches, $last_cond_groups, $server_vars);
        
		$cond_or_flag = ($rc['flags'] & FLAG_COND_OR);
        if (is_array($rc)) {
			
            if ($skip_if_condor) {
                output("Skipping as previous RewriteCond matched and had OR flag", $htaccess_line - $m + $i, LOG_SUCCESS);
            }
			if ( ! $cond_pass and ! $cond_or) {
                output("Skipping as previous RewriteCond failed", $htaccess_line - $m + $i, LOG_FAILURE);
			} else {
				// success can be true or an array
				if ($rc['success'] !== false) {
					$cond_pass			= true;
					$last_cond_groups	= $rc['success'];
					
				} else if ( ! $skip_if_condor) {
					// Try the next condition
					$cond_pass = false;
				}
				// Make sure we don't keep skipping if the condition failed and there
				// is not OR flag
				if ($cond_or_flag and $cond_pass) {
					$skip_if_condor = true;
				} else {
					$skip_if_condor = false;
				}
				$cond_or = $cond_or_flag;
			}
        } else {
            $skip_if_condor	= false;
			$cond_pass		= false;
            while ($i<$m) {
                output("Skipping...", $htaccess_line - $m + ++$i, LOG_FAILURE);
            }
            $retval = false;
			break;
        }
    }
	
	if ( ! $cond_pass) {
		output("Not matched as RewriteCond failed", $htaccess_line, LOG_FAILURE);
        
	} else if ($retval !== false) {
		$find = array();
		$replace = array();
		for ($i=1,$m=count($matches); $i<$m; $i++) {
			$find[] = "\$$i";
			$replace[] = $matches[$i];
		}
		for ($i=1,$m=count($last_cond_groups); $i<$m; $i++) {
			$find[] = "%$i";
			$replace[] = $last_cond_groups[$i - 1];
		}
        
		$new_url = str_replace($find, $replace, $substitution);
		if (!preg_match("/^(f|ht)tps?/", $new_url)) {
			$new_url = $server_vars['REQUEST_SCHEME'] . "://" . $server_vars['HTTP_HOST'] . $new_url;
		}
		if (!empty($server_vars['QUERY_STRING'])) {
			$new_url .= "?".$server_vars['QUERY_STRING'];
		}
		
        output("Old URL: " . $orig_url, $htaccess_line, LOG_URL);
		output("New URL: " . $new_url, $htaccess_line, LOG_URL);		
        if ($new_url === $orig_url) {
			output("WARNING: OLD AND NEW URLS MATCH", $htaccess_line, LOG_FAILURE);
		}
		
		$new_host = parse_url($new_url, PHP_URL_HOST);
		$orig_host = parse_url($orig_url, PHP_URL_HOST);
		if (!empty($new_host) and
			!empty($orig_host) and
			(stripos($new_host, $orig_host)!==false or stripos($orig_host, $new_host)!==false))
		{
			$retval = $new_url;
		}
	}
    return $retval;
	/**
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
