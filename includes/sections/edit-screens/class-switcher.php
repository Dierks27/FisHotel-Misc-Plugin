<?php
/**
 * The once-per-person switch to the FisHotel layout, its undo and its
 * reset.
 *
 * WooCommerce already wrote a product layout into every person's user
 * meta the first time they opened a product, so a "default only" layout
 * would never show. Instead, the first time a person opens a screen of
 * Layouts::screens() while this section is on:
 *
 *   1. their own layout of that screen — the three user meta WordPress
 *      keeps (box order, closed boxes, hidden boxes) — is copied to a
 *      backup (user meta `fh_edit_screens_backup`), "absent" included;
 *   2. the FisHotel layout is written in its place;
 *   3. the layout version is stored (user meta `fh_edit_screens`), with a
 *      notice to show: "This screen was tidied — Put my old layout back".
 *
 * After that their own drags are kept: nothing is written again until the
 * layout version goes up (then once more, the same way). "Put my old
 * layout back" puts the backup back exactly; "Reset to the FisHotel
 * layout" (Screen Options) backs up what they have now and writes the
 * layout again. While the section is switched off, a person's backup is
 * put back the next time they open that screen (restore_off()) — so
 * switching the section off gives back each person's old screen — and
 * switching it on again tidies once more.
 *
 * Every write goes through the user store handed in (User_Store on the
 * site, a stand-in in the tests), so the rules run headless.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Switcher
 */
class Switcher {

	/**
	 * User meta: per screen, the version written, and its state.
	 *
	 * @var string
	 */
	const STATE = 'fh_edit_screens';

	/**
	 * User meta: per screen, the person's own layout before the switch.
	 *
	 * @var string
	 */
	const BACKUP = 'fh_edit_screens_backup';

	/**
	 * The user store.
	 *
	 * @var object
	 */
	private $store;

	/**
	 * @param object $store read( uid, key ), write( uid, key, value ), put_back( uid, key, pair ),
	 *                      get( uid, key ), set( uid, key, value ).
	 */
	public function __construct( $store ) {
		$this->store = $store;
	}

	/**
	 * Whether a person's screen is due for the switch (pure): never
	 * switched, switched to an older layout, or given back while the
	 * section was off.
	 *
	 * @param mixed $entry The screen's entry of the state (null = none).
	 * @return bool
	 */
	public static function due( $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['v'] ) ) {
			return true;
		}
		if ( ! empty( $entry['off'] ) ) {
			return true;
		}
		return (int) $entry['v'] < Layouts::VERSION;
	}

	/**
	 * The state of a person, per screen.
	 *
	 * @param int $uid User ID.
	 * @return array
	 */
	public function state( $uid ) {
		$state = $this->store->get( (int) $uid, self::STATE );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * The entry of one screen (null = never switched).
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return array|null
	 */
	public function entry( $uid, $screen ) {
		$state = $this->state( $uid );
		return isset( $state[ $screen ] ) && is_array( $state[ $screen ] ) ? $state[ $screen ] : null;
	}

	/**
	 * Store the entry of one screen.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @param array  $entry  The entry.
	 */
	private function set_entry( $uid, $screen, array $entry ) {
		$state            = $this->state( $uid );
		$state[ $screen ] = $entry;
		$this->store->set( (int) $uid, self::STATE, $state );
	}

	/**
	 * The backup of one screen, or null.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return array|null part => pair (plain, prefixed).
	 */
	public function backup( $uid, $screen ) {
		$all = $this->store->get( (int) $uid, self::BACKUP );
		return is_array( $all ) && isset( $all[ $screen ] ) && is_array( $all[ $screen ] ) ? $all[ $screen ] : null;
	}

	/**
	 * Copy a person's own layout of a screen to the backup.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 */
	private function take_backup( $uid, $screen ) {
		$copy = array();
		foreach ( Layouts::PARTS as $part => $prefix ) {
			$copy[ $part ] = $this->store->read( (int) $uid, $prefix . $screen );
		}
		$all            = $this->store->get( (int) $uid, self::BACKUP );
		$all            = is_array( $all ) ? $all : array();
		$all[ $screen ] = $copy;
		$this->store->set( (int) $uid, self::BACKUP, $all );
	}

	/**
	 * Write the FisHotel layout of a screen.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return bool
	 */
	private function write_layout( $uid, $screen ) {
		$layout = Layouts::for_screen( $screen );
		if ( null === $layout ) {
			return false;
		}
		foreach ( Layouts::PARTS as $part => $prefix ) {
			$this->store->write( (int) $uid, $prefix . $screen, $layout[ $part ] );
		}
		return true;
	}

	/**
	 * The switch, when it is due: backup, the FisHotel layout, the version
	 * and the notice.
	 *
	 * @param int    $uid    User ID (0 = nobody: nothing happens).
	 * @param string $screen Screen id.
	 * @return bool Whether the screen was switched now.
	 */
	public function maybe_switch( $uid, $screen ) {
		$uid = (int) $uid;
		if ( $uid < 1 || ! Layouts::is_screen( $screen ) || ! self::due( $this->entry( $uid, $screen ) ) ) {
			return false;
		}
		$this->take_backup( $uid, $screen );
		$this->write_layout( $uid, $screen );
		$this->set_entry(
			$uid,
			$screen,
			array(
				'v'      => Layouts::VERSION,
				'notice' => 1,
			)
		);
		return true;
	}

	/**
	 * "Put my old layout back": the backup, exactly — a part that was
	 * absent is deleted again. The switch is not done again for this
	 * version.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return bool Whether there was a backup to put back.
	 */
	public function undo( $uid, $screen ) {
		$uid    = (int) $uid;
		$backup = Layouts::is_screen( $screen ) ? $this->backup( $uid, $screen ) : null;
		if ( $uid < 1 || null === $backup ) {
			return false;
		}
		$this->put_back( $uid, $screen, $backup );
		$this->set_entry(
			$uid,
			$screen,
			array(
				'v'      => Layouts::VERSION,
				'undone' => 1,
			)
		);
		return true;
	}

	/**
	 * Put a backup's parts back.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @param array  $backup part => pair.
	 */
	private function put_back( $uid, $screen, array $backup ) {
		foreach ( Layouts::PARTS as $part => $prefix ) {
			$pair = isset( $backup[ $part ] ) && is_array( $backup[ $part ] ) ? $backup[ $part ] : array(
				'plain'    => null,
				'prefixed' => null,
			);
			$this->store->put_back( $uid, $prefix . $screen, $pair );
		}
	}

	/**
	 * "Reset to the FisHotel layout": what the person has now is backed
	 * up (so the undo puts it back), then the layout is written again.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return bool
	 */
	public function reset( $uid, $screen ) {
		$uid = (int) $uid;
		if ( $uid < 1 || ! Layouts::is_screen( $screen ) ) {
			return false;
		}
		$this->take_backup( $uid, $screen );
		$this->write_layout( $uid, $screen );
		$this->set_entry(
			$uid,
			$screen,
			array(
				'v'      => Layouts::VERSION,
				'notice' => 1,
			)
		);
		return true;
	}

	/**
	 * Hide the notice of a screen.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return bool
	 */
	public function dismiss( $uid, $screen ) {
		$entry = $this->entry( (int) $uid, $screen );
		if ( null === $entry ) {
			return false;
		}
		unset( $entry['notice'] );
		$this->set_entry( (int) $uid, $screen, $entry );
		return true;
	}

	/**
	 * Whether the notice "This screen was tidied" is due on a screen.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return bool
	 */
	public function notice_due( $uid, $screen ) {
		$entry = $this->entry( (int) $uid, $screen );
		return is_array( $entry ) && ! empty( $entry['notice'] ) && empty( $entry['off'] );
	}

	/**
	 * While the section is off: a person's tidied screen gets their own
	 * layout back, once.
	 *
	 * @param int    $uid    User ID.
	 * @param string $screen Screen id.
	 * @return bool Whether something was put back.
	 */
	public function restore_off( $uid, $screen ) {
		$uid   = (int) $uid;
		$entry = Layouts::is_screen( $screen ) ? $this->entry( $uid, $screen ) : null;
		if ( $uid < 1 || null === $entry || ! empty( $entry['off'] ) ) {
			return false;
		}
		$backup = $this->backup( $uid, $screen );
		if ( empty( $entry['undone'] ) && null !== $backup ) {
			$this->put_back( $uid, $screen, $backup );
		}
		$entry['off'] = 1;
		unset( $entry['notice'] );
		$this->set_entry( $uid, $screen, $entry );
		return true;
	}
}
