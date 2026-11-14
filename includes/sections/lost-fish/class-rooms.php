<?php
/**
 * Lost Fish rooms — the Clean Room section, reached only through
 * class_exists() / method_exists(): a tank group (a "stay"), the open
 * groups that may be of the same kind, and how long a loss can be undone.
 *
 * The Clean Room may be switched off or older. Then available() is false,
 * every read gives null / an empty list, no loss ever arrives (it is the
 * Clean Room that fires the action), and the Lost Fish screen says so —
 * cases opened by hand from an order line keep working.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rooms
 */
class Rooms {

	const STORE_CLASS      = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Store';
	const SECTION_CLASS    = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Clean_Room';
	const SETTINGS_CLASS   = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Settings';
	const FRONT_DESK_CLASS = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Front_Desk';

	/**
	 * The undo window when the Clean Room does not say (seconds).
	 *
	 * @var int
	 */
	const DEFAULT_UNDO_WINDOW = 900;

	/**
	 * The Clean Room store, once.
	 *
	 * @var object|null
	 */
	private $store = null;

	/**
	 * Whether the Clean Room's classes are loaded and its tables exist.
	 *
	 * @return bool
	 */
	public function available() {
		if ( ! class_exists( self::STORE_CLASS ) || ! class_exists( self::SECTION_CLASS ) || ! class_exists( self::SETTINGS_CLASS ) ) {
			return false;
		}
		try {
			return (bool) call_user_func( array( self::STORE_CLASS, 'tables_exist' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * How long after a loss the phone can still undo it (seconds) — the
	 * Clean Room's own constant when it is there.
	 *
	 * @return int
	 */
	public function undo_window() {
		if ( class_exists( self::FRONT_DESK_CLASS ) && defined( self::FRONT_DESK_CLASS . '::UNDO_WINDOW' ) ) {
			return max( 0, (int) constant( self::FRONT_DESK_CLASS . '::UNDO_WINDOW' ) );
		}
		return self::DEFAULT_UNDO_WINDOW;
	}

	/**
	 * One tank group.
	 *
	 * @param int $stay_id Stay id.
	 * @return array|null
	 */
	public function stay( $stay_id ) {
		if ( (int) $stay_id <= 0 || ! $this->available() ) {
			return null;
		}
		try {
			$stay = $this->store()->stay( (int) $stay_id, $this->today(), $this->settings() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $stay ) ? $stay : null;
	}

	/**
	 * The open tank groups that may be of a kind: for a product, its open
	 * groups; without one, the open groups whose species contains the
	 * name (Cover::stay_matches() then decides exactly).
	 *
	 * @param array $kind Cover::kind_of_stay().
	 * @return array[]
	 */
	public function open_stays( array $kind ) {
		if ( ! $this->available() ) {
			return array();
		}

		$filters = (int) ( $kind['product_id'] ?? 0 ) > 0
			? array( 'product_id' => (int) $kind['product_id'] )
			: array( 'species' => (string) ( $kind['species'] ?? '' ) );

		try {
			$stays = $this->store()->open_stays( $filters, $this->today(), $this->settings() );
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $stays ) ? $stays : array();
	}

	/**
	 * A product's title, as the Clean Room reads it ('' when unknown).
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function product_title( $product_id ) {
		if ( (int) $product_id <= 0 ) {
			return '';
		}
		try {
			if ( $this->available() && method_exists( $this->store(), 'product_title' ) ) {
				return (string) $this->store()->product_title( (int) $product_id );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return function_exists( 'get_the_title' ) ? (string) get_the_title( (int) $product_id ) : '';
	}

	/**
	 * The link to the tank group's place in the Clean Room ('' when the
	 * Clean Room is not there).
	 *
	 * @param array $stay A stay.
	 * @return string
	 */
	public function stays_url( array $stay ) {
		if ( ! class_exists( self::SECTION_CLASS ) || ! defined( self::SECTION_CLASS . '::MENU_SLUG' ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'page' => constant( self::SECTION_CLASS . '::MENU_SLUG' ),
				'tab'  => 'stays',
				's'    => (string) ( $stay['species'] ?? '' ),
			),
			admin_url( 'admin.php' )
		);
	}

	private function store() {
		if ( null === $this->store ) {
			$class       = self::STORE_CLASS;
			$this->store = new $class();
		}
		return $this->store;
	}

	private function today() {
		return (string) call_user_func( array( self::SECTION_CLASS, 'today' ) );
	}

	private function settings() {
		$settings = call_user_func( array( self::SETTINGS_CLASS, 'get' ) );
		return is_array( $settings ) ? $settings : array();
	}
}
