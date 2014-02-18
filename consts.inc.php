<?php

define("USER_AGENT_GOOGLE_BOT", "Googlebot/2.1 (+http://www.google.com/bot.html)");
define("USER_AGENT_SAFARI_IPAD", "Mozilla/5.0 (iPad; U; CPU OS 3_2_1 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Mobile/7B405");
define("USER_AGENT_CHROME_LINUX", "Mozilla/5.0 (X11; Linux i686) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/31.0.1650.63 Chrome/31.0.1650.63 Safari/537.36");

define("COND_COMPARE_INT_EQ",	1);
define("COND_COMPARE_INT_GTE",	2);
define("COND_COMPARE_INT_GT",	3);
define("COND_COMPARE_INT_LT",	4);
define("COND_COMPARE_INT_LTE",	5);
define("COND_COMPARE_STR_LT",	6);
define("COND_COMPARE_STR_GT",	7);
define("COND_COMPARE_STR_EQ",	8);
define("COND_COMPARE_STR_LTE",	9);
define("COND_COMPARE_STR_GTE",	10);
define("COND_COMPARE_REGEX",	11);

define("LOG_NORMAL",	"log-normal");
define("LOG_FAILURE",	"log-failure");
define("LOG_SUCCESS",	"log-success");
define("LOG_COMMENT",	"log-comment");
define("LOG_HELP",		"log-help");
define("LOG_URL",		"log-url");

// Not entirely sure what this is for...
define("SMALL_EXPANSION",       5);

define("BACKREF_REWRITE_RULE",  1);
define("BACKREF_REWRITE_COND",  2);

define("FLAG_COND_NONE",		0);
define("FLAG_COND_NC",			1);
define("FLAG_COND_OR",			2);
define("FLAG_COND_NV",			4);

define("FLAG_RULE_NONE",		0);
define("FLAG_RULE_ESCAPE",		1);
define("FLAG_RULE_CHAIN",		2);
define("FLAG_RULE_COOKIE",		4);
define("FLAG_RULE_DISCARDPATH",	8);
define("FLAG_RULE_ENV",			16);
define("FLAG_RULE_FORBIDDEN",	32);
define("FLAG_RULE_GONE",		64);
define("FLAG_RULE_HANDLER",		128);
define("FLAG_RULE_LAST",		256);
define("FLAG_RULE_NEXT",		512);
define("FLAG_RULE_NOCASE",		1024);
define("FLAG_RULE_NOESCAPE",	2048);
define("FLAG_RULE_NOSUBREQ",	4096);
define("FLAG_RULE_PROXY",		8192);
define("FLAG_RULE_PASSTHRU",	16384);
define("FLAG_RULE_QSAPPEND",	32768);
define("FLAG_RULE_QSDISCARD",	65536);
define("FLAG_RULE_REDIRECT",	131072);
define("FLAG_RULE_END",			262144);
define("FLAG_RULE_SKIP",		524288);
define("FLAG_RULE_TYPE",		1048576);