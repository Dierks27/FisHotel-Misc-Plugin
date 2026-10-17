<?php
/**
 * Clean Room import — the workbook's mapped data (Import_Map::map()) into
 * the Store: species upserted by name, box rates merged into the option,
 * orders created by ref with their lines, the recent batches checked in
 * as stays, the treatment logs as dose / copper / note logs. Pure apart
 * from the store it is handed (the real Store or the tests' in-memory
 * one), exactly as Orders::check_in() is.
 *
 * Idempotent: a second run of the same file updates the species, merges
 * the same box rates, skips every order whose ref exists, skips every
 * line that already has a stay, and skips every log whose import marker
 * ("import:<ref>#<row>") is already in the building's logs.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Import
 */
class Import {

	/**
	 * The marker an imported log carries at the end of its note, followed
	 * by "<ref>#<row>" — how a second run recognises it.
	 *
	 * @var string
	 */
	const MARKER = 'import:';

	/**
	 * "import:2026-12#7" — the marker of one log row of one batch.
	 *
	 * @param string $ref Order ref.
	 * @param int    $row The log row's index in the sheet's log.
	 * @return string
	 */
	public static function marker( $ref, $row ) {
		return self::MARKER . trim( (string) $ref ) . '#' . (int) $row;
	}

	/**
	 * A note without its import marker (" · import:2026-12#7" and any
	 * separator before it); a note without one comes back unchanged.
	 *
	 * @param string $note The log note.
	 * @return string
	 */
	public static function strip_marker( $note ) {
		$note = (string) $note;
		if ( false === strpos( $note, self::MARKER ) ) {
			return $note;
		}
		$note = preg_replace( '/\s*(?:·\s*)?' . preg_quote( self::MARKER, '/' ) . '\S*/u', '', $note );
		return trim( (string) $note, " \t\n\r·" );
	}

	/**
	 * The marker in a note, or '' when it has none.
	 *
	 * @param string $note The log note.
	 * @return string
	 */
	public static function marker_of( $note ) {
		return 1 === preg_match( '/' . preg_quote( self::MARKER, '/' ) . '[^\s#]+#\d+/', (string) $note, $m ) ? $m[0] : '';
	}
}
