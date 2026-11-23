<?php
/**
 * Clean Room admin — "Fish with no product yet": the view of the Species
 * tab that lists them, the marks the other screens show (an order line's
 * tag, a fish order's count, the Board's chip, the Stays tab's words) and
 * the two acts that take a fish from the list to a product — "Link to a
 * product…" and "Start a draft product".
 *
 * The rule, the gathering into fish, the list's rows and what a draft is
 * made of are No_Product's (pure). This file reads what that needs — in
 * batches, never one query per row: the fish products' names (read once
 * per request), the orders with their lines, the open stays, the species
 * list, and ONE read of the post statuses of the products involved — and
 * does the writes through the store. The only WooCommerce calls of the
 * section's "no product" feature are in No_Product_Shop, at the foot.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_No_Product
 */
class Admin_No_Product {

	const ACTION = 'fishotel_cleanroom_no_product';

	/**
	 * "Start a draft product" makes a product: it has a nonce of its own
	 * and needs the right to edit products besides the section's own.
	 */
	const NONCE_DRAFT      = 'fishotel_cleanroom_draft_product';
	const DRAFT_CAPABILITY = 'edit_products';

	/**
	 * One click at a time per fish while its draft is being made: the
	 * database's own lock (Store::lock()), which is taken or refused in a
	 * single step. This transient only stands in where the database has no
	 * such lock.
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'fishotel_cleanroom_draft_';
	const LOCK_SECONDS   = 60;

	/**
	 * Register the AJAX action.
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
	}

	/* ============================================================== */
	/*  Where the list is                                              */
	/* ============================================================== */

	/**
	 * The URL of the list — a view of the Species tab.
	 *
	 * @param array $extra closed ('1' = the fish of closed fish orders too), order (one order's fish), paged.
	 * @return string
	 */
	public static function url( array $extra = array() ) {
		return Admin::page_url( 'species', array_merge( array( 'view' => No_Product::VIEW ), $extra ) );
	}

	/**
	 * Whether a request asks for the list.
	 *
	 * @param array $request $_GET-like.
	 * @return bool
	 */
	public static function is_asked( $request ) {
		return is_array( $request ) && isset( $request['view'] ) && is_scalar( $request['view'] ) && No_Product::VIEW === sanitize_key( (string) $request['view'] );
	}

	/**
	 * The two views of the Species tab, for the strip under the tabs.
	 *
	 * @param string $current '' (the species list) or No_Product::VIEW.
	 * @return array[] label, url, active.
	 */
	public static function nav( $current ) {
		return array(
			array(
				'label'  => __( 'Species list', 'fishotel-misc-plugin' ),
				'url'    => Admin::page_url( 'species' ),
				'active' => No_Product::VIEW !== $current,
			),
			array(
				'label'  => __( 'Fish with no product yet', 'fishotel-misc-plugin' ),
				'url'    => self::url(),
				'active' => No_Product::VIEW === $current,
			),
		);
	}

	/* ============================================================== */
	/*  Reads                                                          */
	/* ============================================================== */

	/**
	 * The fish products' names by their key (the store reads them once per request).
	 *
	 * @param object $store Store or stand-in.
	 * @return array Shop_Price::name_index().
	 */
	public static function names( $store ) {
		return is_object( $store ) && method_exists( $store, 'fish_product_names' ) ? Shop_Price::name_index( (array) $store->fish_product_names() ) : array();
	}

	/**
	 * The products that still exist among those the lines and stays at
	 * hand point at — ONE read. Null when the store cannot say (a
	 * stand-in without product_statuses()): every id then counts.
	 *
	 * @param object  $store  Store or stand-in.
	 * @param array[] $orders Orders with lines.
	 * @param array[] $stays  Stays.
	 * @param array   $names  names().
	 * @param array   $listed No_Product::listed_products().
	 * @return array|null No_Product::alive().
	 */
	public static function alive_for( $store, array $orders, array $stays, array $names, array $listed = array() ) {
		if ( ! is_object( $store ) || ! method_exists( $store, 'product_statuses' ) ) {
			return null;
		}
		$ids = No_Product::product_ids( $orders, $stays, $names, $listed );
		return $ids ? No_Product::alive( (array) $store->product_statuses( $ids ) ) : array();
	}

	/**
	 * Orders with the mark on their lines and their count of fish
	 * (No_Product::mark_orders()) — for the Fish orders list and an
	 * order's own page. One read of the statuses, and — only when the
	 * counts are wanted, or an order is closed (its lines read their
	 * product through the species list) — one of the species list.
	 *
	 * @param object  $store  Store or stand-in.
	 * @param array[] $orders Orders with lines.
	 * @param bool    $counts Whether the per-order count has to tell two lines of one fish apart by the species list.
	 * @return array[]
	 */
	public static function mark_orders( $store, array $orders, $counts = true ) {
		if ( ! $orders ) {
			return $orders;
		}
		try {
			$names  = self::names( $store );
			$closed = in_array( 'closed', array_column( $orders, 'status' ), true );
			$rows   = ( $counts || $closed ) && is_object( $store ) && method_exists( $store, 'species_brief' ) ? (array) $store->species_brief() : array();
			$listed = No_Product::listed_products( $rows );
			return No_Product::mark_orders( $orders, $names, self::alive_for( $store, $orders, array(), $names, $listed ), Stats::species_index( $rows )['names'], $listed );
		} catch ( \Throwable $e ) {
			return $orders; // A mark is never worth a broken page.
		}
	}

	/**
	 * One order with the mark on its lines.
	 *
	 * @param object $store Store or stand-in.
	 * @param array  $order The order with its lines.
	 * @return array
	 */
	public static function mark_order( $store, array $order ) {
		$marked = self::mark_orders( $store, array( $order ), false );
		return $marked[0];
	}

	/**
	 * Which of a set of stays have no product yet — the Stays tab's words.
	 *
	 * @param object  $store Store or stand-in.
	 * @param array[] $stays Stays.
	 * @return array<int, string> stay id => unknown|linked|none.
	 */
	public static function stay_states( $store, array $stays ) {
		$out = array();
		try {
			$alive = self::alive_for( $store, array(), $stays, array() );
		} catch ( \Throwable $e ) {
			$alive = null;
		}
		foreach ( $stays as $stay ) {
			if ( is_array( $stay ) ) {
				$out[ (int) ( $stay['id'] ?? 0 ) ] = No_Product::stay_state( $stay, $alive );
			}
		}
		return $out;
	}

	/**
	 * How many fish have no product yet NOW — the Board's chip. The Board
	 * has its stays already (the tanks' occupants); this adds the lines of
	 * the fish orders that are not closed, in two reads.
	 *
	 * @param object  $store Store or stand-in.
	 * @param array[] $tanks The Board's tanks, each with 'occupants'.
	 * @return int
	 */
	public static function board_count( $store, array $tanks ) {
		$stays = array();
		foreach ( $tanks as $tank ) {
			foreach ( (array) ( $tank['occupants'] ?? array() ) as $stay ) {
				if ( is_array( $stay ) ) {
					$stays[] = $stay;
				}
			}
		}
		try {
			$gathered = self::gathered( $store, self::open_orders( $store ), $stays, array() );
		} catch ( \Throwable $e ) {
			return 0;
		}
		return count( $gathered['fish'] );
	}

	/**
	 * The fish orders that are not closed, with their lines as stored
	 * (two reads; no figures, no prices).
	 *
	 * @param object $store Store or stand-in.
	 * @return array[]
	 */
	public static function open_orders( $store ) {
		$orders = (array) $store->orders( array( 'open' => true ) );
		$orders = array_values(
			array_filter(
				$orders,
				static function ( $order ) {
					return is_array( $order ) && 'closed' !== (string) ( $order['status'] ?? '' ); // Also for a stand-in that knows no "open" filter.
				}
			)
		);
		if ( ! $orders ) {
			return array();
		}
		$lines = (array) $store->lines_for_orders( array_column( $orders, 'id' ) );
		foreach ( $orders as $index => $order ) {
			$orders[ $index ]['lines'] = isset( $lines[ (int) $order['id'] ] ) ? $lines[ (int) $order['id'] ] : array();
		}
		return $orders;
	}

	/**
	 * Gather orders and stays into fish with what the store knows: the
	 * product names, the statuses (one read), the species list (one read),
	 * the standard sizes when they are asked for.
	 *
	 * @param object  $store  Store or stand-in.
	 * @param array[] $orders Orders with lines.
	 * @param array[] $stays  Open stays.
	 * @param array   $ctx    closed_too, order_id, sizes, species (rows — read when not given).
	 * @return array No_Product::gather().
	 */
	public static function gathered( $store, array $orders, array $stays, array $ctx ) {
		$names   = self::names( $store );
		$species = isset( $ctx['species'] ) && is_array( $ctx['species'] ) ? $ctx['species'] : ( method_exists( $store, 'species_brief' ) ? (array) $store->species_brief() : array() );
		return No_Product::gather(
			$orders,
			$stays,
			array_merge(
				$ctx,
				array(
					'names'   => $names,
					'alive'   => self::alive_for( $store, $orders, $stays, $names, No_Product::listed_products( $species ) ),
					'species' => $species,
				)
			)
		);
	}

	/**
	 * Everything the list page shows.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $request  $_GET-like: closed, order, paged.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array rows (a page of fish), total, pages, paged, unknown, closed_too, order (the one
	 *               order asked for, or null), titles (product titles of the candidates), orders_read,
	 *               orders_capped.
	 */
	public static function data( $store, array $request, $today, array $settings ) {
		$closed_too = isset( $request['closed'] ) && is_scalar( $request['closed'] ) && '1' === (string) $request['closed'];
		$order_id   = isset( $request['order'] ) && is_scalar( $request['order'] ) ? absint( $request['order'] ) : 0;
		$paged      = isset( $request['paged'] ) && is_scalar( $request['paged'] ) ? max( 1, absint( $request['paged'] ) ) : 1;

		$one = $order_id > 0 ? $store->order( $order_id ) : null;
		if ( $one ) {
			$orders = array( Admin_Workbook::resolve_order( $store, $one ) ); // What matters now of it — the fish its count counted —, or with "closed" all.
		} else {
			$order_id = 0;
			$orders   = Admin_Workbook::resolved_orders( $store, $closed_too ? array() : array( 'open' => true ) );
			if ( ! $closed_too ) {
				$orders = array_values(
					array_filter(
						$orders,
						static function ( $order ) {
							return 'closed' !== (string) ( $order['status'] ?? '' );
						}
					)
				);
			}
		}

		$stays    = (array) $store->open_stays( array( 'limit' => 2000 ), $today, $settings );
		// A group put in a tank by hand names its fish order by a ref only. An order that is not at hand
		// — a closed one — is read for it (one more read of orders, whatever the groups; none without one).
		$refs     = $one ? array() : No_Product::missing_refs( $stays, $orders );
		$gathered = self::gathered(
			$store,
			$orders,
			$stays,
			array(
				'closed_too' => $closed_too,
				'order_id'   => $order_id,
				'ref_orders' => $refs ? Admin_Workbook::resolved_orders( $store, array( 'refs' => $refs ) ) : array(),
				'sizes'      => Sizes::standard( $store, $settings )['sizes'],
			)
		);
		$page     = No_Product::page( $gathered['fish'], $paged );
		$page     = array_merge( $page, array( 'rows' => self::with_twins( $store, $page['rows'] ) ) );

		return array(
			'rows'          => $page['rows'],
			'total'         => $page['total'],
			'pages'         => $page['pages'],
			'paged'         => $page['paged'],
			'unknown'       => $gathered['unknown'],
			'closed_too'    => $closed_too,
			'order'         => $one ? $one : null,
			'titles'        => Admin_Workbook::product_titles( $store, array_filter( array_column( $page['rows'], 'candidate' ) ) ),
			'orders_read'   => count( $orders ),
			'orders_capped' => ! $one && count( $orders ) >= Store::ORDERS_LIMIT,
		);
	}

	/**
	 * The rows of one page with the product the shop ALREADY has under a
	 * fish's exact name, wherever it is — a product the owner made by hand
	 * outside the fish categories is invisible to the rule and to the fish
	 * search, and "Start a draft product" would otherwise be offered for a
	 * fish that has one. One query for the page; the row gets twins (how
	 * many products carry its name) and, when there is exactly one,
	 * candidate (that product) and elsewhere (true: the rule did not see
	 * it, so another pick stays possible). With several, none is offered:
	 * the owner picks.
	 *
	 * @param object  $store Store or stand-in.
	 * @param array[] $rows  A page of gather()'s fish.
	 * @return array[]
	 */
	public static function with_twins( $store, array $rows ) {
		$ask = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['candidate'] ) && '' !== (string) ( $row['name'] ?? '' ) ) {
				$ask[] = (string) $row['name'];
			}
		}
		$titled = $ask && is_object( $store ) && method_exists( $store, 'products_titled' ) ? (array) $store->products_titled( $ask ) : array();
		foreach ( $rows as $index => $row ) {
			$key                         = Species::key( (string) ( $row['name'] ?? '' ) );
			$found                       = empty( $row['candidate'] ) && ! empty( $titled[ $key ] ) ? array_values( (array) $titled[ $key ] ) : array();
			$rows[ $index ]['elsewhere'] = false;
			$rows[ $index ]['twins']     = count( $found );
			if ( 1 === count( $found ) ) {
				$rows[ $index ]['candidate'] = (int) $found[0];
				$rows[ $index ]['elsewhere'] = true;
			}
		}
		return $rows;
	}

	/**
	 * Render the list (the Species tab's view=no-product).
	 *
	 * @param array  $ctx   store, settings, today, tables_ok, can_settings (Admin::render_page()).
	 * @param string $flash One-time notice.
	 */
	public static function render( array $ctx, $flash = '' ) {
		$store        = $ctx['store'];
		$settings     = $ctx['settings'];
		$today        = $ctx['today'];
		$tables_ok    = $ctx['tables_ok'];
		$can_settings = $ctx['can_settings'];
		$data         = self::data( $store, (array) wp_unslash( $_GET ), $today, $settings ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$sizes        = Sizes::standard( $store, $settings )['sizes'];
		$can_draft    = current_user_can( self::DRAFT_CAPABILITY );

		include Clean_Room::path() . 'views/no-product.php';
	}

	/**
	 * What the page's script needs: the action, the draft's own nonce (only
	 * for a user who may make products) and its words.
	 *
	 * @return array
	 */
	public static function js_config() {
		return array(
			'action'     => self::ACTION,
			'draftNonce' => current_user_can( self::DRAFT_CAPABILITY ) ? wp_create_nonce( self::NONCE_DRAFT ) : '',
			'i18n'       => array(
				'linking'  => __( 'Linking…', 'fishotel-misc-plugin' ),
				'making'   => __( 'Making the draft…', 'fishotel-misc-plugin' ),
				'finish'   => __( 'Finish it in the shop →', 'fishotel-misc-plugin' ),
				'reload'   => __( 'Reload the list', 'fishotel-misc-plugin' ),
				'pickHint' => __( 'type a product\'s name, then pick it', 'fishotel-misc-plugin' ),
			),
		);
	}

	/* ============================================================== */
	/*  One fish, for a write                                          */
	/* ============================================================== */

	/**
	 * One fish as it stands now, read for a write: its species-list row,
	 * EVERY line of it (closed orders too), its open stays, its row as the
	 * list would show it with closed orders (null when nothing of it is
	 * without a product any more) and the product it is linked to already.
	 *
	 * @param object $store    Store or stand-in.
	 * @param string $key      The fish key the page posted ("s:<id>" or "n:<name>").
	 * @param string $name     The name the page showed.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array fish (row|null), lines, stays, species (row|null), name, linked (product id|null).
	 */
	public static function fish_at_hand( $store, $key, $name, $today, array $settings ) {
		$key        = is_scalar( $key ) ? (string) $key : '';
		$name       = is_scalar( $name ) ? trim( (string) preg_replace( '/\s+/', ' ', (string) $name ) ) : '';
		$species_id = 0 === strpos( $key, 's:' ) ? (int) substr( $key, 2 ) : 0;
		$typed      = 0 === strpos( $key, 'n:' ) ? (string) substr( $key, 2 ) : '';

		$species = $species_id > 0 ? $store->species( $species_id ) : null;
		if ( ! $species ) {
			// A page loaded before the fish got its species-list row still posts its name key.
			$species = $store->species_by_name( '' !== $typed ? $typed : $name );
		}
		$species_id = $species ? (int) $species['id'] : 0;
		$key        = $species ? 's:' . $species_id : $key;
		$search     = array_values( array_unique( array_filter( array( $species ? (string) $species['name'] : '', $typed, $species ? '' : $name ), 'strlen' ) ) );

		$all = $search || $species_id > 0 ? (array) $store->lines_of_fish( $species_id, $search ) : array();

		// The lines as orders, for the same gathering the list does.
		$index  = Stats::species_index( $species ? array( $species ) : array() );
		$orders = array();
		$lines  = array();
		foreach ( $all as $line ) {
			if ( Stats::fish_key( $line, $index['names'] ) !== $key ) {
				continue; // The same name on a line tied to another species row is another fish.
			}
			$lines[]  = $line;
			$order_id = (int) $line['order_id'];
			if ( ! isset( $orders[ $order_id ] ) ) {
				$orders[ $order_id ] = array(
					'id'            => $order_id,
					'ref'           => (string) ( $line['order_ref'] ?? '' ),
					'status'        => (string) ( $line['order_status'] ?? '' ),
					'order_date'    => $line['order_date'] ?? null,
					'received_date' => $line['received_date'] ?? null,
					'created_at'    => (string) ( $line['order_created'] ?? '' ),
					'lines'         => array(),
				);
			}
			$orders[ $order_id ]['lines'][] = $line;
		}

		// Its open stays: by every name the fish goes under.
		$stay_names = $search;
		foreach ( $lines as $line ) {
			$stay_names[] = (string) $line['species'];
		}
		$stays = array();
		foreach ( array_unique( array_filter( array_map( 'trim', $stay_names ), 'strlen' ) ) as $stay_name ) {
			foreach ( (array) $store->open_stays( array( 'species' => $stay_name, 'limit' => 2000 ), $today, $settings ) as $stay ) {
				$stays[ (int) $stay['id'] ] = $stay;
			}
		}

		$gathered = self::gathered(
			$store,
			array_values( $orders ),
			array_values( $stays ),
			array(
				'closed_too' => true,
				'species'    => $species ? array( $species ) : array(),
				'sizes'      => Sizes::standard( $store, $settings )['sizes'],
			)
		);
		$fish     = No_Product::find( $gathered['fish'], $key, '' );
		$mine     = array();
		foreach ( (array) ( $fish['stay_ids'] ?? array() ) as $stay_id ) {
			if ( isset( $stays[ (int) $stay_id ] ) ) {
				$mine[] = $stays[ (int) $stay_id ];
			}
		}

		// The product the fish is linked to already: the species row's, else the one named like it.
		$shown  = $fish ? (string) $fish['name'] : ( $species ? (string) $species['name'] : ( '' !== $name ? $name : $typed ) );
		$names  = self::names( $store );
		$linked = Shop_Price::product_of(
			array(
				'species'            => $shown,
				'species_product_id' => $species ? (int) ( $species['product_id'] ?? 0 ) : 0,
			),
			$names
		);
		if ( null !== $linked && method_exists( $store, 'product_statuses' ) && ! No_Product::exists( $linked, No_Product::alive( (array) $store->product_statuses( array( $linked ) ) ) ) ) {
			$linked = null;
		}

		return array(
			'fish'    => $fish,
			'lines'   => $lines,
			'stays'   => $mine,
			'species' => $species ? $species : null,
			'name'    => $shown,
			'linked'  => $linked,
		);
	}

	/**
	 * The context a link writes with (as Identify::stay()).
	 *
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private static function write_ctx( $today, array $settings ) {
		return array(
			'today'    => $today,
			'settings' => $settings,
			'actor_id' => (int) get_current_user_id(),
			'source'   => 'manual',
		);
	}

	/**
	 * The Species tab's own "Link product" changed — or removed — a row's
	 * product: the open groups of that fish that carried the product the
	 * row had go with it (No_Product::relink_stays()). Without this a
	 * wrong pick made on "Fish with no product yet" could be put right for
	 * the fish order lines only, and the groups in the tanks kept the
	 * wrong product for good.
	 *
	 * @param object $store      Store or stand-in.
	 * @param array  $row        The species row as it was BEFORE the link (id, name, product_id).
	 * @param int    $product_id The product it is linked to now (0 = none).
	 * @param string $today      Site date.
	 * @param array  $settings   Settings.
	 * @return int[] The stays that followed.
	 */
	public static function species_relinked( $store, array $row, $product_id, $today, array $settings ) {
		$from = (int) ( $row['product_id'] ?? 0 );
		$name = (string) ( $row['name'] ?? '' );
		if ( $from <= 0 || $from === (int) $product_id || '' === trim( $name ) ) {
			return array();
		}
		$stays = array();
		foreach ( (array) $store->open_stays( array( 'species' => $name, 'limit' => 2000 ), $today, $settings ) as $stay ) {
			if ( Species::key( (string) ( $stay['species'] ?? '' ) ) === Species::key( $name ) ) {
				$stays[] = $stay; // The search is "contains": only the groups called exactly like the row.
			}
		}
		return No_Product::relink_stays( $store, $stays, $from, (int) $product_id, (int) ( $row['id'] ?? 0 ), self::write_ctx( $today, $settings ) );
	}

	/**
	 * "Link to a product…" — the testable half of the AJAX: the fish the
	 * page named, the product picked, and No_Product::link().
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $raw      Unslashed payload: key, name, product_id.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array status, ok, message — and product_id, lines, stays on success.
	 */
	public static function link_request( $store, array $raw, $today, array $settings ) {
		$refuse     = static function ( $message, $status = 400 ) {
			return array(
				'status'  => $status,
				'ok'      => false,
				'message' => $message,
			);
		};
		$product_id = isset( $raw['product_id'] ) && is_scalar( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
		$title      = $product_id > 0 ? (string) $store->product_title( $product_id ) : '';
		if ( '' === $title || ( method_exists( $store, 'product_statuses' ) && ! No_Product::exists( $product_id, No_Product::alive( (array) $store->product_statuses( array( $product_id ) ) ) ) ) ) {
			return $refuse( __( 'Pick a product from the list first (a product in the trash cannot be linked).', 'fishotel-misc-plugin' ) );
		}

		$hand = self::fish_at_hand( $store, $raw['key'] ?? '', $raw['name'] ?? '', $today, $settings );
		if ( ! $hand['fish'] ) {
			return $refuse( null !== $hand['linked'] ? __( 'That fish has a product already — reload the list.', 'fishotel-misc-plugin' ) : __( 'That fish is no longer on the list — reload it.', 'fishotel-misc-plugin' ), 409 );
		}

		// The species list (or the shop, by the fish's name) ties this fish to another product already — only a
		// group in a tank lacks the link. Linking it elsewhere would move every fish order line of the fish too.
		if ( null !== $hand['linked'] && (int) $hand['linked'] !== $product_id ) {
			/* translators: 1: fish, 2: product title */
			return $refuse( sprintf( __( '%1$s already has the product "%2$s". Use "Link to it" — or change the fish\'s product on the Species list first.', 'fishotel-misc-plugin' ), (string) $hand['fish']['name'], (string) $store->product_title( (int) $hand['linked'] ) ), 409 );
		}

		$result = No_Product::link( $store, $hand['fish'], $hand['lines'], $hand['stays'], $product_id, self::write_ctx( $today, $settings ) );
		if ( empty( $result['ok'] ) ) {
			return $refuse( (string) $result['error'], 500 );
		}

		return array(
			'status'     => 200,
			'ok'         => true,
			'message'    => No_Product::link_message( (string) $hand['fish']['name'], $title, $result ),
			'product_id' => $product_id,
			'lines'      => $result['lines'],
			'stays'      => $result['stays'],
		);
	}

	/**
	 * "Start a draft product" — the testable half of the AJAX. The
	 * capabilities (the section's and the right to edit products) and the
	 * draft's own nonce first; then the fish, what its draft is made of
	 * (No_Product::draft_plan()), and No_Product::draft_decision(): the
	 * product it has already is opened, a draft started for it before is
	 * adopted, a product the shop has under exactly that name — in
	 * whatever category — is named and nothing is made, and only otherwise
	 * one is made: a draft, never published here. The fish is then linked
	 * to it as "Link to a product…" does.
	 *
	 * @param object $store    Store or stand-in.
	 * @param object $shop     No_Product_Shop or a test's: create( array $plan ) → array( ok, id, error ), edit_url( int ).
	 * @param array  $raw      Unslashed payload: nonce, key, name.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array status, ok, message — and act (create|adopt|open), product_id, edit_url, plan on success.
	 */
	public static function draft_request( $store, $shop, array $raw, $today, array $settings ) {
		$refuse = static function ( $message, $status = 400 ) {
			return array(
				'status'  => $status,
				'ok'      => false,
				'message' => $message,
			);
		};
		if ( ! current_user_can( Clean_Room::CAPABILITY ) || ! current_user_can( self::DRAFT_CAPABILITY ) ) {
			return $refuse( __( 'You are not allowed to make products.', 'fishotel-misc-plugin' ), 403 );
		}
		if ( ! isset( $raw['nonce'] ) || ! is_string( $raw['nonce'] ) || ! wp_verify_nonce( $raw['nonce'], self::NONCE_DRAFT ) ) {
			return $refuse( __( 'This page has expired — reload it and try again.', 'fishotel-misc-plugin' ), 403 );
		}

		$hand = self::fish_at_hand( $store, $raw['key'] ?? '', $raw['name'] ?? '', $today, $settings );
		$fish = $hand['fish'];
		if ( ! $fish && null === $hand['linked'] ) {
			return $refuse( __( 'That fish is no longer on the list — reload it.', 'fishotel-misc-plugin' ), 409 );
		}

		$standard = Sizes::standard( $store, $settings );
		$plan     = No_Product::draft_plan( $fish ? $fish : array( 'name' => $hand['name'] ), $standard );
		if ( '' === $plan['name'] ) {
			return $refuse( __( 'That fish has no name to make a product from.', 'fishotel-misc-plugin' ) );
		}

		// One click at a time per fish. The database's own lock is taken or refused in ONE step, so two clicks
		// that arrive in the same instant cannot both find "no draft yet"; where the database has no such lock,
		// the short transient stands in as before.
		$busy   = __( 'A draft for this fish is being made right now — give it a moment, then reload the list.', 'fishotel-misc-plugin' );
		$name   = 'draft:' . $plan['marker'];
		$lock   = self::LOCK_TRANSIENT . md5( $plan['marker'] );
		$held   = method_exists( $store, 'lock' ) ? $store->lock( $name ) : null;
		$unlock = static function () use ( $store, $name, $lock, $held ) {
			if ( true === $held ) {
				$store->unlock( $name );
			} else {
				delete_transient( $lock );
			}
		};
		if ( false === $held ) {
			return $refuse( $busy, 409 );
		}
		if ( null === $held ) {
			if ( false !== get_transient( $lock ) ) {
				return $refuse( $busy, 409 );
			}
			set_transient( $lock, 1, self::LOCK_SECONDS );
		}

		try {
			// Read the fish again, now that no other click can be at work on it: the one before may just have linked it.
			$hand = self::fish_at_hand( $store, $raw['key'] ?? '', $raw['name'] ?? '', $today, $settings );
			$fish = $hand['fish'];
			if ( $fish ) {
				$plan = No_Product::draft_plan( $fish, $standard );
			}

			// The product the fish has already: the one it is linked to, else the one the species list or the shop has under its name.
			$has    = null !== $hand['linked'] ? (int) $hand['linked'] : ( $fish && ! empty( $fish['candidate'] ) ? (int) $fish['candidate'] : null );
			$marked = method_exists( $store, 'product_with_meta' ) ? (int) $store->product_with_meta( No_Product::DRAFT_META, $plan['marker'] ) : 0;
			// A product of exactly that name anywhere in the shop — the fish search does not reach every category.
			$titled   = null === $has && 0 === $marked && method_exists( $store, 'products_titled' ) ? (array) $store->products_titled( array( $plan['name'] ) ) : array();
			$twin     = isset( $titled[ Species::key( $plan['name'] ) ][0] ) ? (int) $titled[ Species::key( $plan['name'] ) ][0] : 0;
			$decision = No_Product::draft_decision( $has, $marked, $twin );
			$id       = (int) $decision['product_id'];

			if ( 'twin' === $decision['act'] ) {
				$unlock();
				$status = method_exists( $store, 'product_statuses' ) ? (array) $store->product_statuses( array( $id ) ) : array();
				return $refuse( No_Product::twin_message( $plan['name'], $id, (string) ( $status[ $id ] ?? '' ) ), 409 );
			}

			if ( 'create' === $decision['act'] ) {
				$made = (array) $shop->create( $plan );
				if ( empty( $made['ok'] ) || (int) ( $made['id'] ?? 0 ) <= 0 ) {
					$unlock();
					return $refuse( '' !== (string) ( $made['error'] ?? '' ) ? (string) $made['error'] : __( 'The draft product could not be made.', 'fishotel-misc-plugin' ), 500 );
				}
				$id = (int) $made['id'];
			}

			$message = No_Product::draft_message( $plan, $decision['act'] );
			if ( $fish ) {
				$linked = No_Product::link( $store, $fish, $hand['lines'], $hand['stays'], $id, self::write_ctx( $today, $settings ) );
				if ( empty( $linked['ok'] ) ) {
					/* translators: %s: why the link failed */
					$message = sprintf( __( 'The draft product exists, but the fish could not be linked to it (%s). Click again: no second product is made.', 'fishotel-misc-plugin' ), (string) $linked['error'] );
				}
			}
		} catch ( \Throwable $e ) {
			$unlock();
			return $refuse( $e->getMessage(), 500 );
		}
		$unlock();

		return array(
			'status'     => 200,
			'ok'         => true,
			'message'    => $message,
			'act'        => (string) $decision['act'],
			'product_id' => $id,
			'edit_url'   => (string) $shop->edit_url( $id ),
			'plan'       => $plan,
		);
	}

	/**
	 * AJAX: link (the section's capability and nonce) or draft (its own).
	 */
	public function ajax() {
		$raw = (array) wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below, per op.
		$op  = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';

		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		if ( 'draft' !== $op ) {
			check_ajax_referer( Admin::NONCE_AJAX, 'nonce' ); // The draft's own nonce is checked in draft_request().
		}
		if ( ! Store::tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ) ), 500 );
		}

		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();

		try {
			if ( 'link' === $op ) {
				$out = self::link_request( $store, $raw, $today, $settings );
			} elseif ( 'draft' === $op ) {
				$out = self::draft_request( $store, new No_Product_Shop(), $raw, $today, $settings );
			} else {
				$out = array(
					'status'  => 400,
					'ok'      => false,
					'message' => __( 'Unknown action.', 'fishotel-misc-plugin' ),
				);
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		if ( empty( $out['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) $out['message'] ), (int) $out['status'] );
		}
		unset( $out['plan'], $out['status'], $out['ok'] );
		wp_send_json_success( $out );
	}
}

/**
 * The shop behind "Start a draft product": the WooCommerce calls, and
 * nothing else. A product made here is a DRAFT — invisible to customers —
 * shaped as this shop's fish products are: a product with variations,
 * the fish category on the product itself, the scientific name in its
 * meta and as the short description, the size attribute with one
 * variation per size (managed stock, 0 in stock, the price planned for
 * that size when there is one). The shipping class is left unset: the
 * theme gives a fish its class when it is published.
 */
class No_Product_Shop {

	/**
	 * Make the draft.
	 *
	 * @param array $plan No_Product::draft_plan().
	 * @return array{ok: bool, id: int, error: string}
	 */
	public function create( array $plan ) {
		$failed = static function ( $error ) {
			return array(
				'ok'    => false,
				'id'    => 0,
				'error' => (string) $error,
			);
		};
		if ( ! class_exists( '\WC_Product_Variable' ) || ! class_exists( '\WC_Product_Variation' ) || ! class_exists( '\WC_Product_Attribute' ) ) {
			return $failed( __( 'WooCommerce is not active, so no product can be made here.', 'fishotel-misc-plugin' ) );
		}
		$category = get_term_by( 'slug', (string) $plan['category'], 'product_cat' );
		if ( ! $category || is_wp_error( $category ) ) {
			/* translators: %s: category slug */
			return $failed( sprintf( __( 'The shop has no product category "%s" — nothing was made.', 'fishotel-misc-plugin' ), (string) $plan['category'] ) );
		}

		// The size terms, by the slugs the plan names; a slug that is no term (any more) is left out.
		$taxonomy = (string) ( $plan['taxonomy'] ?? '' );
		$prices   = array_map( 'strval', array_column( (array) ( $plan['sizes'] ?? array() ), 'price', 'slug' ) );
		$terms    = array();
		if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
			foreach ( (array) ( $plan['sizes'] ?? array() ) as $size ) {
				$term = get_term_by( 'slug', (string) $size['slug'], $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$terms[] = $term;
				}
			}
		}

		try {
			$product = new \WC_Product_Variable();
			$product->set_name( (string) $plan['name'] );
			$product->set_status( 'draft' ); // Never published by this tool.
			$product->set_short_description( (string) $plan['short_description'] );
			$product->set_category_ids( array( (int) $category->term_id ) );
			if ( $terms ) {
				$attribute = new \WC_Product_Attribute();
				$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$product->set_attributes( array( $attribute ) );
			}
			$product->update_meta_data( No_Product::DRAFT_META, (string) $plan['marker'] );
			if ( '' !== (string) $plan['sci_name'] ) {
				$product->update_meta_data( No_Product::SCI_META, (string) $plan['sci_name'] );
			}
			$id = (int) $product->save();
			if ( $id <= 0 ) {
				return $failed( __( 'WooCommerce did not save the product.', 'fishotel-misc-plugin' ) );
			}

			foreach ( $terms as $order => $term ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $id );
				$variation->set_attributes( array( $taxonomy => (string) $term->slug ) );
				if ( isset( $prices[ (string) $term->slug ] ) && '' !== $prices[ (string) $term->slug ] ) {
					$variation->set_regular_price( $prices[ (string) $term->slug ] ); // The price planned for THIS size; a size without one stays empty.
				}
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( 0 );
				$variation->set_menu_order( (int) $order );
				$variation->save();
			}
			if ( $terms ) {
				\WC_Product_Variable::sync( $id );
			}
		} catch ( \Throwable $e ) {
			return $failed( $e->getMessage() );
		}

		return array(
			'ok'    => true,
			'id'    => $id,
			'error' => '',
		);
	}

	/**
	 * The product's edit screen.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function edit_url( $product_id ) {
		$url = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( (int) $product_id, 'raw' ) : '';
		return is_string( $url ) && '' !== $url ? $url : admin_url( 'post.php?post=' . (int) $product_id . '&action=edit' );
	}
}
