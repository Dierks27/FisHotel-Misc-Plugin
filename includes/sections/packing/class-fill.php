<?php
/**
 * "Fill empty ones from the map" — so the Bag size does not have to be set
 * on hundreds of variations by hand.
 *
 * The size → bag map already answers for every variation that has no Bag
 * size of its own; this tool WRITES that answer onto the variation, so the
 * product record shows it. Two steps:
 *
 *   preview  counts, and changes nothing: how many variations have no Bag
 *            size, how many of those the map would set (and to which bag),
 *            how many carry a size the map has no bag for, how many carry
 *            no size at all;
 *   run      sets them, a batch at a time (the page asks again until it is
 *            done). It never touches a variation that already has a value:
 *            the list comes from the variations WITHOUT one, and each is
 *            checked once more on the product object just before it is
 *            written.
 *
 * A value is written through the WooCommerce product object
 * (update_meta_data() + save_meta_data()): the hidden meta `_fh_bag_size`,
 * nothing else — no attribute, no variation, no price, no stock.
 *
 * plan() is pure; preview_request() and run_request() check the capability
 * (manage_woocommerce) and the nonce themselves and answer with a status.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fill
 */
class Fill {

	/**
	 * Nonce action of the tool.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_packing_fill';

	/**
	 * Variations looked at per request while running.
	 *
	 * @var int
	 */
	const BATCH = 150;

	/**
	 * Register the AJAX actions.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_packing_fill_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_fishotel_packing_fill_run', array( $this, 'ajax_run' ) );
	}

	/**
	 * What the map would do with the variations that have no Bag size
	 * (pure).
	 *
	 * @param array[] $rows     Store::variations_without_bag(): id, parent, attributes.
	 * @param array   $settings Settings (bags, size_map).
	 * @param array   $standard Size_List::standard().
	 * @return array{empty: int, set: array<int, int>, by_bag: array<int, int>, unmapped: array<string, int>, no_size: int}
	 *         empty = variations without a value; set = variation id → bag id;
	 *         by_bag = bag id → how many; unmapped = size slug → how many;
	 *         no_size = how many carry no size.
	 */
	public static function plan( array $rows, array $settings, array $standard ) {
		$out = array(
			'empty'    => 0,
			'set'      => array(),
			'by_bag'   => array(),
			'unmapped' => array(),
			'no_size'  => 0,
		);

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) < 1 ) {
				continue;
			}
			$out['empty']++;

			$size = Size_List::size_of( isset( $row['attributes'] ) && is_array( $row['attributes'] ) ? $row['attributes'] : array(), $standard );
			if ( '' === $size ) {
				$out['no_size']++;
				continue;
			}

			$resolved = Bags::resolve( '', $size, $settings );
			if ( ! $resolved['bag'] ) {
				$out['unmapped'][ $size ] = ( $out['unmapped'][ $size ] ?? 0 ) + 1;
				continue;
			}

			$bag_id                   = (int) $resolved['bag']['id'];
			$out['set'][ (int) $row['id'] ] = $bag_id;
			$out['by_bag'][ $bag_id ] = ( $out['by_bag'][ $bag_id ] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * The preview in words.
	 *
	 * @param array $plan     plan().
	 * @param array $settings Settings.
	 * @param array $standard Size_List::standard().
	 * @param array $counts   Store::variation_counts() (total, with_bag) — optional.
	 * @return string[]
	 */
	public static function describe( array $plan, array $settings, array $standard, array $counts = array() ) {
		$lines = array();

		if ( isset( $counts['total'] ) ) {
			/* translators: 1: variations in all, 2: variations that have a bag size */
			$lines[] = sprintf( __( '%1$d variations in all; %2$d already have a Bag size and are not touched.', 'fishotel-misc-plugin' ), (int) $counts['total'], (int) ( $counts['with_bag'] ?? 0 ) );
		}

		$would = count( $plan['set'] );
		if ( 0 === (int) $plan['empty'] ) {
			$lines[] = __( 'No variation is without a Bag size: there is nothing to fill.', 'fishotel-misc-plugin' );
			return $lines;
		}

		$parts = array();
		foreach ( $settings['bags'] as $bag ) {
			if ( ! empty( $plan['by_bag'][ (int) $bag['id'] ] ) ) {
				$parts[] = $bag['name'] . ': ' . (int) $plan['by_bag'][ (int) $bag['id'] ];
			}
		}
		/* translators: 1: variations without a bag size, 2: how many would be set */
		$line = sprintf( __( '%1$d variations have no Bag size. The map would set %2$d of them', 'fishotel-misc-plugin' ), (int) $plan['empty'], $would );
		$lines[] = $line . ( $parts ? ' (' . implode( ', ', $parts ) . ').' : '.' );

		if ( $plan['unmapped'] ) {
			$parts = array();
			foreach ( $plan['unmapped'] as $slug => $count ) {
				$parts[] = Size_List::label( $standard['sizes'], (string) $slug ) . ': ' . (int) $count;
			}
			/* translators: 1: how many variations, 2: the sizes with their counts */
			$lines[] = sprintf( __( '%1$d carry a size the map has no bag for (%2$s) — they stay empty.', 'fishotel-misc-plugin' ), array_sum( $plan['unmapped'] ), implode( ', ', $parts ) );
		}
		if ( $plan['no_size'] > 0 ) {
			/* translators: %d: how many variations */
			$lines[] = sprintf( __( '%d carry no size (any size, or none) — they stay empty.', 'fishotel-misc-plugin' ), (int) $plan['no_size'] );
		}

		return $lines;
	}

	/**
	 * Set one variation's Bag size through its product object — only when
	 * it still has none.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $bag_id       Bag id.
	 * @return string set | skipped (it has a value by now) | failed.
	 */
	public static function write( $variation_id, $bag_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'failed';
		}

		try {
			$product = wc_get_product( (int) $variation_id );
			if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) || ! method_exists( $product, 'save_meta_data' ) ) {
				return 'failed';
			}
			if ( '' !== Bags::own_value( $product ) ) {
				return 'skipped';
			}
			$product->update_meta_data( Packing::META_BAG, (string) (int) $bag_id );
			$product->save_meta_data();
		} catch ( \Throwable $e ) {
			return 'failed';
		}

		return 'set';
	}

	/**
	 * One batch of the run: the next variations without a Bag size after a
	 * cursor, each one the map answers for set.
	 *
	 * @param object        $store    Store (or the tests' stand-in) with variations_without_bag().
	 * @param array         $settings Settings.
	 * @param array         $standard Size_List::standard().
	 * @param int           $after_id The cursor: the last variation id looked at (0 = start).
	 * @param int           $limit    Variations to look at.
	 * @param callable|null $writer   function ( $variation_id, $bag_id ): 'set'|'skipped'|'failed' (null = write()).
	 * @return array{set: int, skipped: int, failed: int, looked: int, last_id: int, done: bool}
	 */
	public static function run( $store, array $settings, array $standard, $after_id = 0, $limit = self::BATCH, $writer = null ) {
		$limit = max( 1, (int) $limit );
		$rows  = is_object( $store ) && method_exists( $store, 'variations_without_bag' ) ? (array) $store->variations_without_bag( (int) $after_id, $limit ) : array();
		$plan  = self::plan( $rows, $settings, $standard );

		$out = array(
			'set'     => 0,
			'skipped' => 0,
			'failed'  => 0,
			'looked'  => count( $rows ),
			'last_id' => (int) $after_id,
			'done'    => count( $rows ) < $limit,
		);

		foreach ( $rows as $row ) {
			$id             = (int) ( $row['id'] ?? 0 );
			$out['last_id'] = max( $out['last_id'], $id );
			if ( ! isset( $plan['set'][ $id ] ) ) {
				continue;
			}
			$outcome = null === $writer ? self::write( $id, $plan['set'][ $id ] ) : call_user_func( $writer, $id, $plan['set'][ $id ] );
			if ( 'set' === $outcome ) {
				$out['set']++;
			} elseif ( 'skipped' === $outcome ) {
				$out['skipped']++;
			} else {
				$out['failed']++;
			}
		}

		return $out;
	}

	/**
	 * Why the tool cannot run, or '' when it can.
	 *
	 * @param array $settings Settings.
	 * @param array $standard Size_List::standard().
	 * @return string
	 */
	public static function blocked( array $settings, array $standard ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce is not active: there are no variations to fill.', 'fishotel-misc-plugin' );
		}
		if ( ! $settings['bags'] ) {
			return __( 'Add the bag sizes first.', 'fishotel-misc-plugin' );
		}
		if ( ! $settings['size_map'] ) {
			return __( 'The size → bag map is empty: choose a bag for the sizes first.', 'fishotel-misc-plugin' );
		}
		if ( ! $standard['sizes'] ) {
			return __( 'There is no list of sizes to go by.', 'fishotel-misc-plugin' );
		}
		return '';
	}

	/**
	 * The preview — the testable half of its AJAX handler. Changes nothing.
	 *
	 * @param array       $raw      Unslashed POST: _wpnonce.
	 * @param object|null $store    Store (null = a new one).
	 * @param array|null  $standard Size_List::standard() (null = resolve it).
	 * @return array{status: int, ok: bool, message: string, would_set?: int, empty?: int, lines?: string[]}
	 */
	public static function preview_request( array $raw, $store = null, $standard = null ) {
		$denied = Settings::guard( $raw, self::NONCE_ACTION );
		if ( $denied ) {
			return $denied;
		}

		$settings = Settings::get();
		$standard = is_array( $standard ) ? $standard : Size_List::standard();
		$blocked  = self::blocked( $settings, $standard );
		if ( '' !== $blocked ) {
			return array(
				'status'  => 409,
				'ok'      => false,
				'message' => $blocked,
			);
		}

		$store  = is_object( $store ) ? $store : new Store();
		$plan   = self::plan( (array) $store->variations_without_bag(), $settings, $standard );
		$counts = method_exists( $store, 'variation_counts' ) ? (array) $store->variation_counts() : array();
		$lines  = self::describe( $plan, $settings, $standard, $counts );

		return array(
			'status'    => 200,
			'ok'        => true,
			'message'   => implode( ' ', $lines ),
			'lines'     => $lines,
			'would_set' => count( $plan['set'] ),
			'empty'     => (int) $plan['empty'],
		);
	}

	/**
	 * One batch of the run — the testable half of its AJAX handler.
	 *
	 * @param array         $raw      Unslashed POST: _wpnonce, after (the cursor).
	 * @param object|null   $store    Store (null = a new one).
	 * @param array|null    $standard Size_List::standard() (null = resolve it).
	 * @param callable|null $writer   See run().
	 * @return array{status: int, ok: bool, message: string, set?: int, skipped?: int, failed?: int, last_id?: int, done?: bool}
	 */
	public static function run_request( array $raw, $store = null, $standard = null, $writer = null ) {
		$denied = Settings::guard( $raw, self::NONCE_ACTION );
		if ( $denied ) {
			return $denied;
		}

		$settings = Settings::get();
		$standard = is_array( $standard ) ? $standard : Size_List::standard();
		$blocked  = self::blocked( $settings, $standard );
		if ( '' !== $blocked ) {
			return array(
				'status'  => 409,
				'ok'      => false,
				'message' => $blocked,
			);
		}

		$after = isset( $raw['after'] ) && is_scalar( $raw['after'] ) ? absint( $raw['after'] ) : 0;
		$store = is_object( $store ) ? $store : new Store();
		$batch = self::run( $store, $settings, $standard, $after, self::BATCH, $writer );

		/* translators: %d: how many variations were set in this step */
		$message = sprintf( _n( '%d variation set.', '%d variations set.', $batch['set'], 'fishotel-misc-plugin' ), $batch['set'] );
		if ( $batch['failed'] > 0 ) {
			/* translators: %d: how many could not be saved */
			$message .= ' ' . sprintf( __( '%d could not be saved.', 'fishotel-misc-plugin' ), $batch['failed'] );
		}

		return array(
			'status'  => 200,
			'ok'      => true,
			'message' => $message,
			'set'     => $batch['set'],
			'skipped' => $batch['skipped'],
			'failed'  => $batch['failed'],
			'last_id' => $batch['last_id'],
			'done'    => $batch['done'],
		);
	}

	/**
	 * AJAX: the preview.
	 */
	public function ajax_preview() {
		Settings::reply( self::preview_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * AJAX: one batch of the run.
	 */
	public function ajax_run() {
		Settings::reply( self::run_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}
}
