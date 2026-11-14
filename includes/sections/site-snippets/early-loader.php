<?php
/**
 * Snippets early loader — included by fishotel-misc-plugin.php while
 * WordPress is still loading plugins.
 *
 * PHP snippets must run at plugins_loaded priority 1, as Code Snippets
 * runs them (a snippet may hook plugins_loaded, init or a filter another
 * plugin applies early). The section framework boots at plugins_loaded 10,
 * so this file does the one thing that cannot wait: when the Snippets
 * section is enabled — the same autoloaded option the Section Manager
 * reads — it loads the section's classes and hooks Loader::run_php() at
 * priority 1. With the section off it costs one in_array() on an option
 * WordPress already has in memory.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Hook the PHP snippets when the section is enabled.
 */
function early_load() {
	$enabled = get_option( \FisHotel\Misc\Section_Manager::OPTION_KEY, array() );

	if ( ! is_array( $enabled ) || ! in_array( 'site-snippets', $enabled, true ) ) {
		return;
	}

	require_once __DIR__ . '/class-site-snippets.php';
	Site_Snippets::load();
	Loader::hook();
}

early_load();
