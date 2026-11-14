<?php
/**
 * Gift Cards modes — what the section is allowed to do, in one option
 * (fishotel_gift_cards_mode, autoloaded):
 *
 *   off     nothing runs: no checkout box, no account page, no e-mail, no
 *           job. Only this section's own admin screen, to switch it on.
 *   shadow  the old plugin (WooCommerce Gift Cards) is still in charge.
 *           wp-admin only: import, the list, search, stats, the audit log
 *           and its export. Nothing on the shop, no e-mails, and no card
 *           is issued or changed here (they would not exist in the old
 *           plugin, which customers still use).
 *   live    this section is in charge: checkout, My Account, buying cards,
 *           e-mails, issuing and changing cards in wp-admin.
 *
 * The guard: Live cannot be chosen while the old plugin is switched on,
 * nor while its tables hold cards that were not all imported cleanly
 * (live_blockers()). If the old plugin is switched on again while the
 * stored mode is Live, the section behaves as Shadow by itself — so two
 * systems never take money for the same cards — and an admin notice
 * says why (effective()).
 *
 * effective() and live_blockers() are pure.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Modes
 */
class Modes {

	const OPTION = 'fishotel_gift_cards_mode';
	const MODES  = array( 'off', 'shadow', 'live' );

	/**
	 * The old plugin's main file, as WordPress lists it.
	 *
	 * @var string
	 */
	const LEGACY_PLUGIN = 'woocommerce-gift-cards/woocommerce-gift-cards.php';

	/**
	 * The stored mode ('off' unless one was saved).
	 *
	 * @return string
	 */
	public static function stored() {
		$mode = function_exists( 'get_option' ) ? get_option( self::OPTION, 'off' ) : 'off';
		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	/**
	 * Whether the old plugin is loaded or switched on (its functions, its
	 * class, or its entry in the active plugin list — all in memory).
	 *
	 * @return bool
	 */
	public static function legacy_loaded() {
		if ( function_exists( 'WC_GC' ) || class_exists( 'WC_GC_Gift_Card', false ) ) {
			return true;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( self::is_legacy_file( $plugin ) ) {
				return true;
			}
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin ) {
				if ( self::is_legacy_file( $plugin ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a plugin file is the old plugin's (whatever its folder).
	 *
	 * @param string $plugin Plugin file.
	 * @return bool
	 */
	public static function is_legacy_file( $plugin ) {
		return 'woocommerce-gift-cards.php' === basename( (string) $plugin );
	}

	/**
	 * What the section really does now (pure): Live falls back to Shadow
	 * while the old plugin is loaded.
	 *
	 * @param string $stored The stored mode.
	 * @param bool   $legacy Whether the old plugin is loaded.
	 * @return string
	 */
	public static function effective( $stored, $legacy ) {
		$stored = in_array( $stored, self::MODES, true ) ? $stored : 'off';
		return 'live' === $stored && $legacy ? 'shadow' : $stored;
	}

	/**
	 * The stored and the effective mode, and whether Live fell back.
	 *
	 * @return array{stored: string, effective: string, legacy: bool, fallback: bool}
	 */
	public static function current() {
		$stored = self::stored();
		$legacy = 'live' === $stored ? self::legacy_loaded() : false;
		$eff    = self::effective( $stored, $legacy );
		return array(
			'stored'    => $stored,
			'effective' => $eff,
			'legacy'    => $legacy,
			'fallback'  => 'live' === $stored && 'live' !== $eff,
		);
	}

	/**
	 * Why Live cannot be switched on now (pure). Empty = it can.
	 *
	 * @param bool       $legacy_loaded The old plugin is loaded / switched on.
	 * @param array      $old           Importer::old_state(): exists (bool), cards / activity (rows, max_id).
	 * @param array|null $last_import   The last import report, or null.
	 * @return string[]
	 */
	public static function live_blockers( $legacy_loaded, array $old, $last_import ) {
		$out = array();
		if ( $legacy_loaded ) {
			$out[] = __( 'The old gift card plugin (WooCommerce Gift Cards) is still switched on. Switch it off on the Plugins page first — keep it installed: its tables stay as the backup.', 'fishotel-misc-plugin' );
		}
		if ( empty( $old['exists'] ) ) {
			return $out;
		}
		if ( ! is_array( $last_import ) || 'import' !== ( $last_import['kind'] ?? '' ) ) {
			$out[] = __( 'The old cards have not been imported yet. Run the dry run and then the import (Import tab).', 'fishotel-misc-plugin' );
			return $out;
		}
		if ( empty( $last_import['ok'] ) || empty( $last_import['reconciled'] ) ) {
			$out[] = __( 'The last import did not match the old cards to the cent. Open the Import tab, fix what it lists and import again.', 'fishotel-misc-plugin' );
		}
		$seen = isset( $last_import['legacy_max'] ) && is_array( $last_import['legacy_max'] ) ? $last_import['legacy_max'] : array();
		foreach ( array( 'cards', 'activity' ) as $table ) {
			$now_max  = (int) ( $old[ $table ]['max_id'] ?? 0 );
			$now_rows = (int) ( $old[ $table ]['rows'] ?? 0 );
			if ( $now_max !== (int) ( $seen[ $table ]['max_id'] ?? -1 ) || $now_rows !== (int) ( $seen[ $table ]['rows'] ?? -1 ) ) {
				$out[] = __( 'The old plugin has new cards or new activity since the last import. Run the import again (it only adds what is new).', 'fishotel-misc-plugin' );
				break;
			}
		}
		return $out;
	}

	/**
	 * A mode in words.
	 *
	 * @param string $mode A MODES entry.
	 * @return string
	 */
	public static function label( $mode ) {
		switch ( (string) $mode ) {
			case 'shadow':
				return __( 'Shadow — the old plugin is in charge; this screen only reads, imports and counts', 'fishotel-misc-plugin' );
			case 'live':
				return __( 'Live — these gift cards are in charge (checkout, My Account, e-mails)', 'fishotel-misc-plugin' );
		}
		return __( 'Off — nothing runs', 'fishotel-misc-plugin' );
	}
}
