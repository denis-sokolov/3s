<?php

$config = array(
	// Show error messages?
	'debug' => false,

	// Show Dashboard?
	'dashboard' => true,

	'cache' => array(
		// Should 3s always check mtimes of files or will you clean the tmp yourself?
		'autoinvalidate' => true,
	),

	'css' => array(
		// Array of name => array of absolute paths
		'bundles' => array(),
		'pretty' => false,
		'hooks' => array(),
	),

	'codes' => array(
		// To prevent guessing the URLS you can enable strict codes.
		// Then all automatic redirection will be disabled and only paths
		// generated with $threes->path will work.
		'strict' => false,
		'entropy' => 'Put a lot of random characters in here in your local config.',
	),

	'js' => array(
		'bundles' => array(),
		// Prettification disables minification
		'pretty' => false,
		'hooks' => array(),

		// Level of minification
		// 0 - no minification
		// 1 - simple minification
		// 2 - advanced minification
		'minify' => 2,
	),
);

if (file_exists(dirname(__FILE__).'/config.local.php'))
	@include dirname(__FILE__).'/config.local.php';
