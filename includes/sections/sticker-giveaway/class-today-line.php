<?php
/**
 * The Sticker line on the Today page and its widget (the Today section's
 * `fishotel_today_lines` filter): "Stickers to reorder: Night Glow (40
 * left)", linking to the Sticker Giveaway page.
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section, and only when a
 * design's stock is down to its "Reorder at" number. The Today section itself knows
 * nothing about stickers: this is one more line through its seam, as
 * Packing's is.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Today_Line
 */
class Today_Line {

	/**
	 * The Today section's filter, this line's key and its heading there
	 * (Customers — the heading this section stands under on the Dashboard
	 * and in the menu, as every section's line stands under its own).
	 *
	 * @var string
	 */
	const FILTER = 'fishotel_today_lines';
	const KEY    = 'stickers';
	const GROUP  = 'customers';

	/**
	 * How many designs the line names before "and N more", and how long
	 * the Today section lets a line be.
	 *
	 * @var int
	 */
	const NAMED  = 4;
	const LENGTH = 160;

	/**
	 * Where the rows come from (null = Reorder::due()); a test hands in
	 * its own.
	 *
	 * @var callable|null
	 */
	private static $source = null;

	/**
	 * The line worked out once for this request (array( line|null )).
	 *
	 * @var array|null
	 */
	private static $cached = null;

	/**
	 * Hook the line (boot(), wp-admin only).
	 */
	public static function register() {
		add_filter( self::FILTER, array( self::class, 'filter' ), 20, 1 );
	}

	/**
	 * Use another source for the rows, and forget the cached line.
	 *
	 * @param callable|null $source Returns what Reorder::due() returns.
	 */
	public static function use_source( $source = null ) {
		self::$source = is_callable( $source ) ? $source : null;
		self::$cached = null;
	}

	/**
	 * The line (pure): null when no design needs reordering.
	 *
	 * @param array[] $due Reorder::due() rows.
	 * @param string  $url The Sticker Giveaway page.
	 * @return array|null
	 */
	public static function line( array $due, $url ) {
		$due   = array_values( array_filter( $due, 'is_array' ) );
		$count = count( $due );
		if ( $count < 1 ) {
			return null;
		}

		$prefix = __( 'Stickers to reorder:', 'fishotel-misc-plugin' ) . ' ';
		$named  = array();

		foreach ( array_slice( $due, 0, self::NAMED ) as $row ) {
			$named[] = sprintf( '%s (%s)', (string) ( $row['name'] ?? '' ), Reorder::left_text( $row ) );
		}

		// Name as many as fit the line; say how many are left out.
		do {
			$more = $count - count( $named );
			$text = $prefix . implode( ', ', $named );
			if ( $more > 0 ) {
				/* translators: %d: designs */
				$text .= ' ' . sprintf( __( 'and %d more', 'fishotel-misc-plugin' ), $more );
			}
			if ( strlen( $text ) <= self::LENGTH || count( $named ) <= 1 ) {
				break;
			}
			array_pop( $named );
		} while ( true );

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			'text'   => $text,
			'detail' => '',
			'url'    => (string) $url,
			'count'  => $count,
			'tone'   => 'todo',
		);
	}

	/**
	 * The lines with this one added (pure): a line under the same key is
	 * replaced, never doubled; no line leaves the others untouched.
	 *
	 * @param mixed      $lines What the filter carries so far.
	 * @param array|null $line  line().
	 * @return array[]
	 */
	public static function with_line( $lines, $line ) {
		$out = array();
		foreach ( is_array( $lines ) ? $lines : array() as $existing ) {
			if ( is_array( $existing ) && self::KEY === (string) ( $existing['key'] ?? '' ) ) {
				continue;
			}
			$out[] = $existing;
		}
		if ( is_array( $line ) ) {
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Filter callback: the Today lines gain this section's one, for a
	 * user who may open it. Whatever goes wrong adds nothing.
	 *
	 * @param mixed $lines The lines so far.
	 * @return array[]
	 */
	public static function filter( $lines = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Sticker_Giveaway::SETTINGS_CAP ) ) {
			return $lines;
		}

		if ( null === self::$cached ) {
			try {
				$due          = null !== self::$source ? call_user_func( self::$source ) : Reorder::due();
				$url          = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . Sticker_Giveaway::MENU_SLUG ) : '';
				self::$cached = array( self::line( is_array( $due ) ? $due : array(), $url ) );
			} catch ( \Throwable $e ) {
				self::$cached = array( null );
			}
		}

		return self::with_line( $lines, self::$cached[0] );
	}
}
