<?php
/**
 * Snippet: Example — footer marker
 * Description: Shows how a snippet file looks. Prints an HTML comment before
 *   </body> on the front end. Safe to leave off; delete once real snippets exist.
 * Type: php
 * Scope: front-end
 * Priority: 10
 * Origin: written by hand as the template for new snippets
 * Default: off
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_footer',
	static function () {
		echo "\n<!-- FisHotel Snippets: example snippet is on -->\n";
	}
);
