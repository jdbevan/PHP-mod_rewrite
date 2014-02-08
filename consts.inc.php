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

define("LOG_NORMAL",	"#000000");
define("LOG_FAILURE",	"#FF0000");
define("LOG_SUCCESS",	"#00DD00");
define("LOG_COMMENT",	"#888888");
define("LOG_HELP",		"#0088FF");

// Not entirely sure what this is for...
define("SMALL_EXPANSION",       5);

define("BACKREF_REWRITE_RULE",  1);
define("BACKREF_REWRITE_COND",  2);