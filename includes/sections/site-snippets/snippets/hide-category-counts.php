<?php
/**
 * Snippet: Hide category product counts
 * Description: Hides the "(12)" product count on category tiles in the shop and
 *   category archives. Moved from Code Snippets, unchanged.
 * Type: php
 * Scope: global
 * Priority: 10
 * Origin: Code Snippets #20, imported 2026-10-18
 * Default: on
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hide category product count in product archives
 */
add_filter( 'woocommerce_subcategory_count_html', '__return_false' );
