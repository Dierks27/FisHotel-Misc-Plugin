<?php
/**
 * Clean Room admin — the workbook tabs of the Room Board page: Fish orders
 * (the fish orders list and the order editor with the sheet's
 * costing and the check-in that creates the stays), Species (the species
 * master with inline edit and the product link) and Stats (the yearly
 * figures, weighted and as the spreadsheet averaged them — its further
 * views, Compare years / Species / Prices to raise / Real sales, are
 * Admin_Stats'), their AJAX, and the XLSX / CSV exports of orders,
 * species, stats and stays.
 *
 * The order editor also takes, per line, a size (one of the standard
 * sizes — Sizes), the box the line came out of and the percent of ONE box
 * it took; under the lines the percents are added up per box (under /
 * about full / over) and against the order's Boxes field
 * (Costing::box_summary(), which the page's JS recalculates as the
 * numbers are typed). Saving an order remembers each line's percent per
 * fish for its supplier + origin + fish + size (Box_Shares), which the
 * next order from the same place offers back as a "suggested N%" chip and,
 * while it is a draft or ordered, adds up into a "How full" line.
 *
 * A line can be an unknown fish (the "??" box), and a line that received
 * fish can be corrected — "Correct the fish…" (identify_line()), or, once
 * it became a stay, simply by changing its species, its "??" box or its
 * size in the grid: the stays follow, the line keeps what the label said
 * (Identify). The Fish orders list marks the orders with fish still to
 * identify; the Stats tab counts the mislabeled lines per supplier and
 * per origin.
 *
 * Every write checks manage_woocommerce and the admin nonce; every
 * download is an admin-post action with its own nonce. The XLSX writer
 * never writes a formula; the CSV guards every cell (Admin::csv_cell).
 *
 * The batch calendar: the order page's "Goes on sale" date is the day
 * every group checked in from that order goes on sale (unless a group
 * has its own date, set on its product page). It is saved by its own
 * nonce'd admin-post form (sale_date_request(), manage_woocommerce like
 * every order edit) and every change goes to the Health log through
 * fishotel_log with who and old → new. Nothing on the storefront moves
 * by itself: stock, visibility and purchasability stay manual.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Workbook
 */
class Admin_Workbook {

	/**
	 * Nonce action of the export downloads.
	 *
	 * @var string
	 */
	const NONCE_EXPORT = 'fishotel_cleanroom_export';

	/**
	 * The tabs rendered here.
	 *
	 * @var string[]
	 */
	const TABS = array( 'arrivals', 'species', 'stats' );

	const SPECIES_PER_PAGE = 50;
	const SUGGEST_LIMIT    = 10;

	/**
	 * Transient (per user) carrying a one-time notice across a download
	 * (the CSV fallback of an XLSX export) or a redirect (the batch sale
	 * date).
	 *
	 * @var string
	 */
	const FLASH_TRANSIENT = 'fishotel_cleanroom_flash_';

	/**
	 * Nonce action of the batch sale-date form (+ the order id).
	 *
	 * @var string
	 */
	const NONCE_SALE_DATE = 'fishotel_cleanroom_sale_date_';

	/**
	 * The admin-post action of that form.
	 *
	 * @var string
	 */
	const SALE_DATE_ACTION = 'fishotel_cleanroom_sale_date';

	/**
	 * The order page's two views of its lines, and the user meta that
	 * remembers which one each person left it in.
	 *
	 * @var string[]
	 */
	const VIEWS     = array( 'entry', 'numbers' );
	const VIEW_META = 'fishotel_cleanroom_order_view';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_cleanroom_order', array( $this, 'ajax_order' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_species', array( $this, 'ajax_species' ) );
		add_action( 'admin_post_fishotel_cleanroom_export', array( $this, 'download_export' ) );
		add_action( 'admin_post_' . self::SALE_DATE_ACTION, array( $this, 'handle_sale_date' ) );
		// The Stats tab's further views (Admin_Stats): Real sales' Refresh and the statuses that count as a sale.
		add_action( 'admin_post_' . Admin_Stats::REFRESH_ACTION, array( Admin_Stats::class, 'handle_refresh' ) );
		add_action( 'admin_post_' . Admin_Stats::STATUSES_ACTION, array( Admin_Stats::class, 'handle_statuses' ) );
	}

	/* ============================================================== */
	/*  URLs and the flash notice                                      */
	/* ============================================================== */

	/**
	 * The order editor's URL ('new' for a new order).
	 *
	 * @param int|string $id Order id or 'new'.
	 * @return string
	 */
	public static function order_url( $id ) {
		return Admin::page_url( 'arrivals', array( 'order' => 'new' === $id ? 'new' : (int) $id ) );
	}

	/**
	 * A nonced export URL.
	 *
	 * @param string $what   orders|species|stats|stays, or one of Admin_Stats::EXPORTS.
	 * @param string $format xlsx|csv.
	 * @param array  $extra  year, closed — or that export's own filters.
	 * @return string
	 */
	public static function export_url( $what, $format, array $extra = array() ) {
		$args = array_merge(
			array(
				'action' => 'fishotel_cleanroom_export',
				'what'   => $what,
				'format' => $format,
			),
			$extra
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE_EXPORT );
	}

	/**
	 * Keep a notice for the user's next Clean Room page.
	 *
	 * @param string $message The notice.
	 */
	public static function flash( $message, $ok = false ) {
		// A notice of something done is kept with that word (shown green); any other stays a plain text (yellow).
		set_transient( self::FLASH_TRANSIENT . (int) get_current_user_id(), $ok ? array( 'message' => (string) $message, 'ok' => true ) : (string) $message, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether the notice last taken (take_flash()) says something was done.
	 *
	 * @var bool
	 */
	private static $flash_ok = false;

	/**
	 * The pending notice, once.
	 *
	 * @return string
	 */
	public static function take_flash() {
		$key     = self::FLASH_TRANSIENT . (int) get_current_user_id();
		$message = get_transient( $key );
		if ( false !== $message ) {
			delete_transient( $key );
		}
		self::$flash_ok = is_array( $message ) && ! empty( $message['ok'] );
		if ( is_array( $message ) ) {
			$message = (string) ( $message['message'] ?? '' );
		}
		return is_string( $message ) ? $message : '';
	}

	/**
	 * Whether the notice taken says something was done (green) — else it is
	 * a warning (yellow).
	 *
	 * @return bool
	 */
	public static function flash_ok() {
		return self::$flash_ok;
	}

	/* ============================================================== */
	/*  Rendering                                                      */
	/* ============================================================== */

	/**
	 * Render one of the workbook tabs. The context is what
	 * Admin::render_page() gathered for every tab.
	 *
	 * @param string $tab arrivals|species|stats.
	 * @param array  $ctx store, settings, today, tables_ok, can_settings, labels_url.
	 */
	public static function render( $tab, array $ctx ) {
		$store        = $ctx['store'];
		$settings     = $ctx['settings'];
		$today        = $ctx['today'];
		$tables_ok    = $ctx['tables_ok'];
		$can_settings = $ctx['can_settings'];
		$labels_url   = $ctx['labels_url'];
		$flash        = self::take_flash();

		if ( 'species' === $tab ) {
			// "Fish with no product yet" is a view of this tab (Admin_No_Product).
			if ( Admin_No_Product::is_asked( wp_unslash( $_GET ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
				Admin_No_Product::render( $ctx, $flash );
				return;
			}

			$filters = array(
				'search' => isset( $_GET['s'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 120 ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
				'group'  => isset( $_GET['group'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['group'] ) ), 0, 60 ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
			$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$total   = $store->species_count( $filters );
			$pages   = max( 1, (int) ceil( $total / self::SPECIES_PER_PAGE ) );
			$paged   = min( $paged, $pages );
			$rows    = $store->species_list( $filters, self::SPECIES_PER_PAGE, ( $paged - 1 ) * self::SPECIES_PER_PAGE );
			$counts  = $store->open_stay_counts();
			$groups  = $store->species_groups();
			$titles  = self::product_titles( $store, array_filter( array_column( $rows, 'product_id' ) ) );
			$exports = array(
				'xlsx' => self::export_url( 'species', 'xlsx' ),
				'csv'  => self::export_url( 'species', 'csv' ),
			);

			include Clean_Room::path() . 'views/species.php';
			return;
		}

		if ( 'stats' === $tab ) {
			// Compare years, Species, Prices to raise and Real sales are Admin_Stats'; "By year" is the tab as it was.
			$view = Admin_Stats::view_from_request( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
			if ( 'year' !== $view ) {
				Admin_Stats::render( $view, $ctx, $flash );
				return;
			}

			$years = $store->order_years();
			$year  = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : ( $years ? (int) $years[0] : (int) substr( $today, 0, 4 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stats    = self::stats_for_year( $store, $year );
			$existing = Stats::existing_stock( $store->existing_stays( $today, $ctx['settings'] ) ); // Fish already here: no year, no batch.
			if ( $year > 0 && ! in_array( $year, $years, true ) ) {
				$years[] = $year;
				rsort( $years );
			}
			$exports = array(
				'xlsx' => self::export_url( 'stats', 'xlsx', array( 'year' => $year ) ),
				'csv'  => self::export_url( 'stats', 'csv', array( 'year' => $year ) ),
			);

			include Clean_Room::path() . 'views/stats.php';
			return;
		}

		// Fish orders: the editor when an order is named, else the list.
		$which = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'new' === $which || absint( $which ) > 0 ) {
			$order_id = 'new' === $which ? 0 : absint( $which );
			$order    = $order_id > 0 ? $store->order( $order_id ) : null;
			if ( $order_id > 0 && ! $order ) {
				echo '<div class="wrap fishotel-misc-wrap fh-cr-wrap"><div class="fishotel-misc-notice fishotel-misc-notice--error">' . esc_html__( 'That order no longer exists.', 'fishotel-misc-plugin' ) . '</div></div>';
				return;
			}
			$order         = $order ? Admin_No_Product::mark_order( $store, self::resolve_order( $store, $order ) ) : self::blank_order( $store, $today );
			$figures       = self::figures( $order );
			$spa_tanks     = Orders::spa_tanks( $store );
			$spa_buildings = array();
			foreach ( $store->buildings() as $building ) {
				if ( 'spa' === (string) $building['area'] && 'offline' !== (string) $building['status'] ) {
					$spa_buildings[] = $building;
				}
			}
			$box_rates   = Settings::box_rates();
			$pick        = self::pick_lists( $store, $box_rates );
			$sizes       = Sizes::standard( $store, $settings )['sizes'];
			$shares      = $store->box_shares( array( 'location_key' => Box_Shares::location_key( $order['supplier'], $order['origin'] ) ) );
			$boxes       = self::boxes_panel( $order, $shares );
			$can_checkin = self::can_check_in( $order );
			$is_new      = 0 === (int) $order['id'];
			$list_url    = Admin::page_url( 'arrivals' );
			$batch       = array();
			if ( ! $is_new ) {
				$batch_stays = $store->open_stays( array( 'order_id' => (int) $order['id'] ), $today, $settings );
				$batch       = self::batch_sale_panel( $order, $batch_stays, self::product_titles( $store, array_column( $batch_stays, 'product_id' ) ), $settings );
			}
			$view  = self::order_view( $order, self::view_prefs( get_user_meta( get_current_user_id(), self::VIEW_META, true ) ) );
			$view  = self::view_asked( $view, wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which view to draw, nothing more.
			$split = self::split_panel( $store, $order, $today, $settings );

			include Clean_Room::path() . 'views/order.php';
			return;
		}

		$years = $store->order_years();
		$year  = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : ( $years ? (int) $years[0] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = array();
		$counts = $store->batch_sale_counts();
		// The fish of each order that have no product yet: one read of the products' statuses for the whole list.
		foreach ( Admin_No_Product::mark_orders( $store, self::resolved_orders( $store, $year > 0 ? array( 'year' => $year ) : array() ) ) as $order ) {
			$rows[] = array_merge(
				$order,
				array(
					'summary'     => Orders::summary( $order ),
					'on_sale'     => self::on_sale_cell( $order, isset( $counts[ (int) $order['id'] ] ) ? $counts[ (int) $order['id'] ] : array() ),
					'to_identify' => self::unknown_lines( $order ),
				)
			);
		}
		$exports = array(
			'xlsx' => self::export_url( 'orders', 'xlsx', $year > 0 ? array( 'year' => $year ) : array() ),
			'csv'  => self::export_url( 'orders', 'csv', $year > 0 ? array( 'year' => $year ) : array() ),
		);
		$new_url = self::order_url( 'new' );

		include Clean_Room::path() . 'views/arrivals.php';
	}

	/* ============================================================== */
	/*  Data                                                           */
	/* ============================================================== */

	/**
	 * Orders with their lines resolved (live figures — a split group's
	 * sister stays counted in —, the shop price read for every line,
	 * costing).
	 *
	 * @param Store $store   Store.
	 * @param array $filters Store::orders() filters.
	 * @return array[]
	 */
	public static function resolved_orders( $store, array $filters = array() ) {
		$orders = $store->orders( $filters );
		if ( ! $orders ) {
			return array();
		}
		$lines    = $store->lines_for_orders( array_column( $orders, 'id' ) );
		$stay_ids = array();
		foreach ( $lines as $list ) {
			foreach ( $list as $line ) {
				if ( ! empty( $line['stay_id'] ) ) {
					$stay_ids[] = (int) $line['stay_id'];
				}
			}
		}
		$figures = Orders::figures_for( $store, $stay_ids );

		foreach ( $orders as $index => $order ) {
			$orders[ $index ]['lines'] = isset( $lines[ (int) $order['id'] ] ) ? $lines[ (int) $order['id'] ] : array();
		}

		$out = array();
		foreach ( Shop_Price::fill_orders( $store, $orders ) as $order ) {
			$out[] = Orders::resolve( $order, $figures );
		}
		return $out;
	}

	/**
	 * One order with its lines resolved (as resolved_orders()).
	 *
	 * @param Store $store Store.
	 * @param array $order Store::order().
	 * @return array
	 */
	public static function resolve_order( $store, array $order ) {
		$stay_ids = array();
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( ! empty( $line['stay_id'] ) ) {
				$stay_ids[] = (int) $line['stay_id'];
			}
		}
		return Orders::resolve( Shop_Price::fill_order( $store, $order ), Orders::figures_for( $store, $stay_ids ) );
	}

	/**
	 * A new, unsaved order: the next ref of the year, today as the order
	 * date, no lines.
	 *
	 * @param Store  $store Store.
	 * @param string $today Site date.
	 * @return array
	 */
	public static function blank_order( $store, $today ) {
		$order = Orders::normalize_order(
			array(
				'ref'        => Orders::next_ref( $store->order_refs(), (int) substr( (string) $today, 0, 4 ) ),
				'status'     => 'draft',
				'order_date' => $today,
			)
		);
		$order['lines']           = array();
		$order['line_count']      = 0;
		$order['building_number'] = null;
		$order['building_name']   = '';
		return Orders::resolve( $order, array() );
	}

	/**
	 * The Stats tab's figures for a year (0 = every year).
	 *
	 * @param Store $store Store.
	 * @param int   $year  Year.
	 * @return array Stats::build().
	 */
	public static function stats_for_year( $store, $year ) {
		return Stats::build( self::resolved_orders( $store, $year > 0 ? array( 'year' => (int) $year ) : array() ) );
	}

	/**
	 * Suppliers and origins already used, plus those in the box rates.
	 *
	 * @param Store   $store     Store.
	 * @param array[] $box_rates Settings::box_rates().
	 * @return array{suppliers: string[], origins: string[]}
	 */
	public static function pick_lists( $store, array $box_rates ) {
		$lists = $store->order_pick_lists();
		foreach ( $box_rates as $rate ) {
			if ( '' !== $rate['supplier'] && ! in_array( $rate['supplier'], $lists['suppliers'], true ) ) {
				$lists['suppliers'][] = $rate['supplier'];
			}
			if ( '' !== $rate['origin'] && ! in_array( $rate['origin'], $lists['origins'], true ) ) {
				$lists['origins'][] = $rate['origin'];
			}
		}
		natcasesort( $lists['suppliers'] );
		natcasesort( $lists['origins'] );
		return array(
			'suppliers' => array_values( $lists['suppliers'] ),
			'origins'   => array_values( $lists['origins'] ),
		);
	}

	/**
	 * Product titles by id, in one query (Store::product_titles()); a
	 * product that does not exist is left out.
	 *
	 * @param Store $store Store.
	 * @param int[] $ids   Product ids.
	 * @return array<int, string>
	 */
	public static function product_titles( $store, array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return $ids ? (array) $store->product_titles( $ids ) : array();
	}

	/**
	 * How many lines of an order are fish that still have to be identified
	 * (pure) — the marker on the Fish orders list and on the order page.
	 *
	 * @param array $order The order with its lines.
	 * @return int
	 */
	public static function unknown_lines( array $order ) {
		$n = 0;
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( is_array( $line ) && ! empty( $line['needs_id'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Whether "Check in received fish" has anything to do: a line with
	 * received fish and no stay yet (pure).
	 *
	 * @param array $order The order with its lines.
	 * @return bool
	 */
	public static function can_check_in( array $order ) {
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( empty( $line['stay_id'] ) && (int) ( $line['qty_received'] ?? 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which stage an order is in for its page's default view (pure):
	 * 'working' while it is a draft, ordered, or being received — fish still
	 * to check in, or none checked in yet —, 'done' once it is closed or
	 * every line that received fish is a stay.
	 *
	 * @param array $order The order with its lines.
	 * @return string working|done
	 */
	public static function order_stage( array $order ) {
		$status = (string) ( $order['status'] ?? 'draft' );
		if ( 'closed' === $status ) {
			return 'done';
		}
		if ( 'received' !== $status || self::can_check_in( $order ) ) {
			return 'working';
		}
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( ! empty( $line['stay_id'] ) ) {
				return 'done';
			}
		}
		return 'working';
	}

	/**
	 * What a user remembers of the order page, cleaned (pure): the view
	 * they left a working order in, the one they left a finished order in,
	 * and whether "More columns" was on. Anything else is the default —
	 * Entry for an order being worked on, Numbers for one that is done.
	 *
	 * @param mixed $raw The stored user meta.
	 * @return array{working: string, done: string, more: bool}
	 */
	public static function view_prefs( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$view = static function ( $key, $default ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], self::VIEWS, true ) ? $raw[ $key ] : $default;
		};
		return array(
			'working' => $view( 'working', 'entry' ),
			'done'    => $view( 'done', 'numbers' ),
			'more'    => ! empty( $raw['more'] ),
		);
	}

	/**
	 * The view an order page opens in (pure).
	 *
	 * @param array $order The order with its lines.
	 * @param array $prefs view_prefs().
	 * @return array{view: string, stage: string, more: bool}
	 */
	public static function order_view( array $order, array $prefs ) {
		$stage = self::order_stage( $order );
		// An order being worked on that has no line yet has nothing to read in Numbers, and "Add line"
		// and "Import lines" are Entry's: it opens in Entry whatever was left on (what is remembered
		// stays as it is).
		$empty = 'working' === $stage && empty( $order['lines'] );
		return array(
			'view'  => $empty ? 'entry' : $prefs[ $stage ],
			'stage' => $stage,
			'more'  => ! empty( $prefs['more'] ),
		);
	}

	/**
	 * The view a link asks for, over the remembered one, for this one page
	 * load (pure; nothing is stored): `view=entry` or `view=numbers` — what
	 * "Import lines" sends the page back with, so the lines it just wrote
	 * land in Entry —, and `import=1` (a new order from pasted lines), which
	 * is Entry's panel.
	 *
	 * @param array $view order_view().
	 * @param array $get  Unslashed query.
	 * @return array
	 */
	public static function view_asked( array $view, array $get ) {
		$asked = isset( $get['view'] ) && is_string( $get['view'] ) ? strtolower( trim( $get['view'] ) ) : '';
		if ( in_array( $asked, self::VIEWS, true ) ) {
			$view['view'] = $asked;
		} elseif ( isset( $get['import'] ) && '1' === (string) ( is_scalar( $get['import'] ) ? $get['import'] : '' ) ) {
			$view['view'] = 'entry';
		}
		return $view;
	}

	/**
	 * Remember the view a user switched to (the testable half of the "view"
	 * op): the view of the stage the order is in, and the "More columns"
	 * switch. Returns what is stored now.
	 *
	 * @param mixed $stored The user meta as stored.
	 * @param array $raw    Unslashed payload: stage, view, more.
	 * @return array view_prefs().
	 */
	public static function remember_view( $stored, array $raw ) {
		$prefs = self::view_prefs( $stored );
		$stage = isset( $raw['stage'] ) && 'done' === $raw['stage'] ? 'done' : 'working';
		if ( isset( $raw['view'] ) && is_string( $raw['view'] ) && in_array( $raw['view'], self::VIEWS, true ) ) {
			$prefs[ $stage ] = $raw['view'];
		}
		if ( isset( $raw['more'] ) && is_scalar( $raw['more'] ) ) {
			$prefs['more'] = in_array( (string) $raw['more'], array( '1', 'true', 'on' ), true );
		}
		return $prefs;
	}

	/**
	 * What the order page needs for its Split buttons: the tanks a part may
	 * go into (Splits::tanks(): the empty ones first), each line's plan as
	 * its hidden field posts it, and — for a line that is no stay yet, has
	 * no plan and did not come empty (received typed as 0) — whether its
	 * fish was split last time (the offer).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $order    The order with its lines.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array{tanks: array[], fields: array<int, string>, offers: array<int, string>, last: array<int, int>}
	 *               fields: line id → 'none' | the JSON of [[qty, tank id], …];
	 *               offers: line id → 'ones' | 'some';
	 *               last: line id → over how many tanks "one per tank" went last time (the
	 *               offer lays out no more than that by itself).
	 */
	public static function split_panel( $store, array $order, $today, array $settings ) {
		$all = Splits::all();
		$out = array(
			'tanks'  => Splits::tanks( $store, $today, $settings ),
			'fields' => array(),
			'offers' => array(),
			'last'   => array(),
		);
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			$id = (int) ( $line['id'] ?? 0 );
			if ( $id < 1 || ! empty( $line['stay_id'] ) ) {
				continue;
			}
			$plan = isset( $all['plans'][ $id ] ) ? $all['plans'][ $id ] : null;
			if ( is_array( $plan ) ) {
				$rows = array();
				foreach ( $plan as $part ) {
					$rows[] = array( (int) $part['qty'], (int) $part['tank_id'] );
				}
				$out['fields'][ $id ] = (string) wp_json_encode( $rows );
				continue;
			}
			if ( Splits::NONE === $plan ) {
				$out['fields'][ $id ] = Splits::NONE;
				continue;
			}
			// A line that did not come — received typed, and 0 — has no fish to lay out: no offer.
			if ( isset( $line['qty_received'] ) && '' !== (string) $line['qty_received'] && (int) $line['qty_received'] < 1 ) {
				continue;
			}
			$memory = Splits::remembered( $line, $all['species'] );
			if ( null !== $memory ) {
				$out['offers'][ $id ] = $memory['ones'] ? 'ones' : 'some';
				$out['last'][ $id ]   = $memory['ones'] ? (int) ( $memory['n'] ?? 0 ) : 0;
			}
		}
		return $out;
	}

	/**
	 * What js/order-entry.js says on the order page: the Split rows, their
	 * running count and mistakes (Splits::strings()), the offers, the
	 * pencil.
	 *
	 * @return array{i18n: array<string, string>}
	 */
	public static function entry_js_config() {
		return array(
			'i18n' => array_merge(
				Splits::strings(),
				array(
					'split'          => __( 'Split', 'fishotel-misc-plugin' ),
					'edit'           => __( 'Edit split', 'fishotel-misc-plugin' ),
					/* translators: %s: species */
					'title'          => __( 'Split %s — how many go into which tank:', 'fishotel-misc-plugin' ),
					'thisLine'       => __( 'this line', 'fishotel-misc-plugin' ),
					'howMany'        => __( 'How many', 'fishotel-misc-plugin' ),
					'whichTank'      => __( 'Which tank', 'fishotel-misc-plugin' ),
					'pickTank'       => __( '— tank —', 'fishotel-misc-plugin' ),
					'hasFish'        => __( 'has fish', 'fishotel-misc-plugin' ),
					'goneTank'       => __( '(no longer a tank for arrivals)', 'fishotel-misc-plugin' ),
					'firstTank'      => __( 'first tank', 'fishotel-misc-plugin' ),
					'theRest'        => __( 'rest', 'fishotel-misc-plugin' ),
					'removePart'     => __( 'Take this tank out of the split', 'fishotel-misc-plugin' ),
					'addTank'        => __( '+ tank', 'fishotel-misc-plugin' ),
					'ones'           => __( '1 per tank', 'fishotel-misc-plugin' ),
					'onesTitle'      => __( 'One fish to a tank over the next empty Spa tanks of the order\'s building (received − DOA).', 'fishotel-misc-plugin' ),
					'keep'           => __( 'Keep together', 'fishotel-misc-plugin' ),
					'keepTitle'      => __( 'No split: the whole line goes into one tank.', 'fishotel-misc-plugin' ),
					/* translators: 1: fish placed, 2: fish alive */
					'placed'         => __( '%1$d of %2$d placed', 'fishotel-misc-plugin' ),
					/* translators: %1$d: fish placed */
					'placedOnly'     => __( '%1$d placed — the received count is not typed yet', 'fishotel-misc-plugin' ),
					/* translators: %d: fish */
					'rest'           => __( '%d stay in the first tank', 'fishotel-misc-plugin' ),
					/* translators: 1: fish, 2: the tank */
					'part'           => __( '%1$s → %2$s', 'fishotel-misc-plugin' ),
					/* translators: %d: tanks */
					'saidTanks'      => __( '%d tanks', 'fishotel-misc-plugin' ),
					'onesNoCount'    => __( 'Type the received count first: one fish goes to each tank.', 'fishotel-misc-plugin' ),
					'onesNone'       => __( 'No empty Spa tank is left for this line in the order\'s building (the lines above took them, or they hold fish): nothing is laid out.', 'fishotel-misc-plugin' ),
					/* translators: 1: empty tanks, 2: fish */
					'onesShort'      => __( 'Only %1$d empty Spa tanks: the other %2$d stay in the first tank.', 'fishotel-misc-plugin' ),
					'offerOnes'      => __( 'This fish was split one per tank last time, so the same is laid out here — a proposal: nothing is planned until you press "Use this split" (or change a row). "Keep together" if this group stays as one.', 'fishotel-misc-plugin' ),
					'offerOnesLater' => __( 'This fish was split one per tank last time. Once the received count is typed, "1 per tank" lays it out — or "Keep together" if this group stays as one.', 'fishotel-misc-plugin' ),
					'offerOnesOne'   => __( 'This fish was split one per tank last time. This line has fewer than two live fish, so there is nothing to split.', 'fishotel-misc-plugin' ),
					'offerOnesTanks' => __( 'This fish was split one per tank last time, but two empty Spa tanks are not left for it in the order\'s building (the lines above took them, or they hold fish): nothing is laid out. Add the tanks yourself, or "Keep together".', 'fishotel-misc-plugin' ),
					/* translators: 1: fish last time, 2: fish on this line */
					'offerOnesMore'  => __( 'This fish was split one per tank last time — %1$d of them. This line has %2$d, so nothing is laid out by itself: press "1 per tank", add the tanks yourself, or "Keep together".', 'fishotel-misc-plugin' ),
					'use'            => __( 'Use this split', 'fishotel-misc-plugin' ),
					'useTitle'       => __( 'Plan the split as it is laid out here. Until then it is only a proposal: the line stays together.', 'fishotel-misc-plugin' ),
					'offerSome'      => __( 'This fish was split over tanks last time. Add the tanks, or "Keep together" if this group stays as one.', 'fishotel-misc-plugin' ),
					'worthTyped'     => __( 'A price of your own for this line — kept on Save.', 'fishotel-misc-plugin' ),
					'worthCleared'   => __( 'No price of your own: the shop price is read again on Save.', 'fishotel-misc-plugin' ),
				)
			),
		);
	}

	/**
	 * The suggested sale date of a batch (pure): the earliest check-in of
	 * its groups still in tanks, else its received date, plus the
	 * sale_ready_days setting — only the hint beside the date picker.
	 *
	 * @param array   $order    The order (received_date).
	 * @param array[] $stays    Its open stays (hydrated).
	 * @param array   $settings Settings (sale_ready_days).
	 * @return string Y-m-d, or '' when the fish are not in yet.
	 */
	public static function suggested_batch_date( array $order, array $stays, array $settings ) {
		$checkin = '';
		foreach ( $stays as $stay ) {
			$date = (string) ( $stay['checkin_date'] ?? '' );
			if ( Replay::is_date( $date ) && ( '' === $checkin || strcmp( $date, $checkin ) < 0 ) ) {
				$checkin = $date;
			}
		}
		if ( '' === $checkin && Replay::is_date( $order['received_date'] ?? null ) ) {
			$checkin = (string) $order['received_date'];
		}
		return Replay::suggested_sale_date( $checkin, (int) ( $settings['sale_ready_days'] ?? Replay::DEFAULT_SALE_READY_DAYS ) );
	}

	/**
	 * What the order page's "Goes on sale" card shows (pure): the batch's
	 * date (or none), the suggestion, how many groups still in tanks the
	 * date applies to (and how many of those are for sale while still in
	 * the Spa), and the groups that have their own date (product, tank,
	 * date, the product's edit link, the "still in the Spa" flag).
	 *
	 * @param array   $order    The order (id, ref, sale_date, received_date).
	 * @param array[] $stays    Its open stays (Store::open_stays( order_id )).
	 * @param array   $titles   Product titles by id.
	 * @param array   $settings Settings.
	 * @return array{date: string, suggested: string, open: int, applies: int, in_spa: int, own: array[], nonce_action: string}
	 */
	public static function batch_sale_panel( array $order, array $stays, array $titles, array $settings ) {
		$own     = array();
		$applies = 0;
		$in_spa  = 0;
		foreach ( $stays as $stay ) {
			if ( 'closed' === (string) ( $stay['status'] ?? '' ) ) {
				continue;
			}
			if ( ! Replay::is_date( $stay['sale_ready_override'] ?? null ) ) {
				$applies++;
				if ( ! empty( $stay['ready_in_spa'] ) ) {
					$in_spa++;
				}
				continue;
			}
			$product = ! empty( $stay['product_id'] ) ? (int) $stay['product_id'] : 0;
			$own[]   = array(
				'stay_id'    => (int) $stay['id'],
				'species'    => (string) $stay['species'],
				'product_id' => $product,
				'product'    => $product && isset( $titles[ $product ] ) ? (string) $titles[ $product ] : '',
				'edit_url'   => $product && function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $product, 'raw' ) : '',
				'tank'       => ! empty( $stay['tank_number'] ) ? (int) $stay['tank_number'] : null,
				'date'       => (string) $stay['sale_ready_override'],
				'ready'      => ! empty( $stay['sale_ready'] ),
				'in_spa'     => ! empty( $stay['ready_in_spa'] ),
			);
		}

		return array(
			'date'         => Replay::is_date( $order['sale_date'] ?? null ) ? (string) $order['sale_date'] : '',
			'suggested'    => self::suggested_batch_date( $order, $stays, $settings ),
			'open'         => $applies + count( $own ),
			'applies'      => $applies,
			'in_spa'       => $in_spa,
			'own'          => $own,
			'nonce_action' => self::NONCE_SALE_DATE . (int) ( $order['id'] ?? 0 ),
		);
	}

	/**
	 * The line under the "Goes on sale" picker (pure): how many groups
	 * still in tanks the batch's date applies to, whether they are for
	 * sale yet, and how many of those are still in the Spa.
	 *
	 * @param array  $batch batch_sale_panel().
	 * @param string $today Site date.
	 * @return string
	 */
	public static function batch_sale_status( array $batch, $today ) {
		$applies = (int) ( $batch['applies'] ?? 0 );
		$date    = (string) ( $batch['date'] ?? '' );
		if ( 0 === $applies ) {
			return 0 === (int) ( $batch['open'] ?? 0 )
				? __( 'No group of this batch is in a tank now.', 'fishotel-misc-plugin' )
				: __( 'Every group of this batch still in tanks has its own date (below).', 'fishotel-misc-plugin' );
		}
		if ( '' === $date ) {
			/* translators: %d: groups */
			return sprintf( _n( 'Not set: %d group of this batch still in tanks is not for sale until a date is chosen.', 'Not set: %d groups of this batch still in tanks are not for sale until a date is chosen.', $applies, 'fishotel-misc-plugin' ), $applies );
		}
		if ( Replay::is_sale_ready( $date, $today, 'cleared' ) ) {
			/* translators: 1: groups, 2: date */
			$line   = sprintf( _n( 'Applies to %1$d group still in tanks — for sale since %2$s.', 'Applies to %1$d groups still in tanks — for sale since %2$s.', $applies, 'fishotel-misc-plugin' ), $applies, $date );
			$in_spa = (int) ( $batch['in_spa'] ?? 0 );
			if ( $in_spa > 0 ) {
				/* translators: %d: groups */
				$line .= ' ' . sprintf( _n( '%d of them is still in the Spa.', '%d of them are still in the Spa.', $in_spa, 'fishotel-misc-plugin' ), $in_spa );
			}
			return $line;
		}
		/* translators: %d: groups */
		return sprintf( _n( 'Applies to %d group still in tanks.', 'Applies to %d groups still in tanks.', $applies, 'fishotel-misc-plugin' ), $applies );
	}

	/**
	 * The Fish orders list's "On sale" cell (pure): the batch's date or "not
	 * set", and a note when some of its groups in tanks have their own.
	 *
	 * @param array $order  The order (sale_date).
	 * @param array $counts Store::batch_sale_counts() for it: open, own.
	 * @return array{date: string, text: string, note: string}
	 */
	public static function on_sale_cell( array $order, array $counts ) {
		$date = Replay::is_date( $order['sale_date'] ?? null ) ? (string) $order['sale_date'] : '';
		$own  = (int) ( $counts['own'] ?? 0 );
		$open = (int) ( $counts['open'] ?? 0 );
		$note = '';
		if ( $own > 0 ) {
			/* translators: 1: groups with their own date, 2: groups in tanks */
			$note = sprintf( _n( '%1$d of %2$d groups has its own date', '%1$d of %2$d groups have their own date', $own, 'fishotel-misc-plugin' ), $own, $open );
		}
		return array(
			'date' => $date,
			'text' => '' !== $date ? $date : __( 'not set', 'fishotel-misc-plugin' ),
			'note' => $note,
		);
	}

	/**
	 * The computed columns of a resolved order as display strings — what
	 * the editor renders and what the Save reply fills in (pure).
	 *
	 * @param array $order Orders::resolve().
	 * @return array{lines: array[], order: array}
	 */
	public static function figures( array $order ) {
		$c       = (array) ( $order['costing'] ?? array() );
		$mode    = (string) ( $c['mode'] ?? 'box' );
		$none    = self::no_price_text();
		$lines   = array();
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			$linked  = ! empty( $line['linked'] );
			$sells   = Costing::worth_of( $line );
			$priced  = null !== $sells['price'];
			$lines[] = array(
				'id'              => (int) ( $line['id'] ?? 0 ),
				'linked'          => $linked,
				'units'           => Costing::num( $line['units'] ?? null, '—', 'box' === $mode ? 2 : 0 ),
				'per_box_used'    => (string) (int) ( $line['per_box_used'] ?? 0 ),
				'landed_total'    => Costing::money( $line['landed_total'] ?? null ),
				'landed_per_fish' => Costing::money( $line['landed_per_fish'] ?? null ),
				'suggested_price' => Costing::num( $line['suggested_price'] ?? null, '—', 0 ),
				'shop_price'      => $priced ? Costing::money( $sells['price'] ) : $none,
				'shop_from'       => $sells['from'],
				'shop_note'       => Shop_Price::source_text( array_merge( $line, array( 'worth_from' => $sells['from'] ) ) ),
				'priced'          => $priced,
				'profit'          => $priced ? Costing::money( $line['profit'] ?? null ) : $none,
				'margin'          => $priced ? Costing::pct( $line['margin'] ?? null ) : $none,
				'worth_it'        => ! $priced ? $none : ( ! empty( $line['worth_it'] ) ? __( 'Yes', 'fishotel-misc-plugin' ) : __( 'No', 'fishotel-misc-plugin' ) ),
				'worth_flag'      => $priced && ! empty( $line['worth_it'] ),
				'revenue'         => $priced ? Costing::money( $line['revenue'] ?? null ) : '—',
				'doa_cost'        => Costing::money( $line['doa_cost'] ?? null ),
				'live'            => $linked && null !== ( $line['live'] ?? null ) ? (string) (int) $line['live'] : '—',
				'sold'            => $linked && null !== ( $line['sold'] ?? null ) ? (string) (int) $line['sold'] : '—',
				'survived'        => null !== ( $line['survived'] ?? null ) ? (string) (int) $line['survived'] : '—',
				'doa'             => (string) (int) ( $line['doa'] ?? 0 ),
				// The tag beside the fish: '' when it has a product, or is none of this rule's business.
				'no_product'      => ! empty( $line['no_product'] ) ? No_Product::tag_text() : '',
			);
		}

		// "Up or down" compares the revenue with what the lines that HAVE a price cost: a fish with
		// no shop price yet is not a fish that sells for nothing.
		$unpriced = (int) ( $c['unpriced'] ?? 0 );
		$up_down  = (float) ( $c['up_down_priced'] ?? ( $c['up_down'] ?? 0 ) );
		$ratio    = array_key_exists( 'ratio_priced', $c ) ? $c['ratio_priced'] : ( $c['ratio'] ?? null );

		return array(
			'lines' => $lines,
			'order' => array(
				'mode'              => $mode,
				'lines'             => (string) (int) ( $c['lines'] ?? 0 ),
				'ordered'           => (string) (int) ( $c['ordered'] ?? 0 ),
				'received'          => (string) (int) ( $c['received'] ?? 0 ),
				'doa'               => (string) (int) ( $c['doa'] ?? 0 ),
				'survived'          => ! empty( $c['survived_known'] ) ? (string) (int) $c['survived'] : '—',
				'units'             => Costing::num( $c['units'] ?? null, '—', 'box' === $mode ? 2 : 0 ),
				'fee_rate'          => Costing::money( $c['fee_rate'] ?? null ),
				'fee_label'         => 'box' === $mode ? __( 'Fees per full box', 'fishotel-misc-plugin' ) : __( 'Shipping per bag', 'fishotel-misc-plugin' ),
				'units_label'       => 'box' === $mode ? __( 'Boxes (Σ fractions)', 'fishotel-misc-plugin' ) : __( 'Bags / units', 'fishotel-misc-plugin' ),
				'total'             => Costing::money( $c['total'] ?? null ),
				'credit'            => Costing::money( $c['credit'] ?? null ),
				'grand_total'       => Costing::money( $c['grand_total'] ?? null ),
				'revenue'           => Costing::money( $c['revenue'] ?? null ),
				'up_down'           => ( $up_down > 0 ? '+' : '' ) . Costing::money( $up_down ),
				'up_down_flag'      => $up_down >= 0 ? 'up' : 'down',
				'ratio'             => Costing::num( $ratio ),
				'fill_rate'         => Costing::pct( $c['fill_rate'] ?? null ),
				'survival_rate'     => Costing::pct( $c['survival_rate'] ?? null ),
				'doa_rate'          => Costing::pct( $c['doa_rate'] ?? null ),
				'doa_cost_sheet'    => Costing::money( $c['doa_cost_sheet'] ?? null ),
				'doa_cost_per_fish' => Costing::money( $c['doa_cost_per_fish'] ?? null ),
				'unpriced'          => (string) $unpriced,
				'no_price'          => self::unpriced_text( $unpriced ),
				'worth_rule'        => self::worth_rule_text( $c['worth_threshold'] ?? null ),
				'target_head'       => self::target_head( $c['markup'] ?? null ),
			),
		);
	}

	/**
	 * What a line without a shop price shows in its shop price, profit,
	 * margin and "Worth it?" cells.
	 *
	 * @return string
	 */
	public static function no_price_text() {
		return __( 'no shop price yet', 'fishotel-misc-plugin' );
	}

	/**
	 * The Numbers panel's line about the fish without a shop price ('' when
	 * every line has one).
	 *
	 * @param int $count Lines without a price.
	 * @return string
	 */
	public static function unpriced_text( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}
		/* translators: %d: order lines */
		return sprintf( _n( '%d line has no shop price yet: it is left out of the revenue and of "up or down".', '%d lines have no shop price yet: they are left out of the revenue and of "up or down".', $count, 'fishotel-misc-plugin' ), $count );
	}

	/**
	 * The worth-it rule in plain words, from the order's own threshold: a
	 * margin above 2.75 is a shop price above 3.75× the landed cost.
	 *
	 * @param mixed $threshold The order's worth-it margin.
	 * @return string
	 */
	public static function worth_rule_text( $threshold ) {
		/* translators: %s: a multiple, e.g. 3.75 */
		return sprintf( __( 'Worth it when the shop price is more than %s× what the fish cost landed.', 'fishotel-misc-plugin' ), Costing::num( Costing::worth_times( $threshold ) ) );
	}

	/**
	 * The head of the target-price column as the owner's sheet heads it:
	 * the order's markup × 100 ("400%").
	 *
	 * @param mixed $markup The order's markup.
	 * @return string
	 */
	public static function target_head( $markup ) {
		return Costing::num( Costing::money_in( $markup, Costing::DEFAULT_MARKUP ) * 100, '', 0 ) . '%';
	}

	/* ============================================================== */
	/*  Percent of a box                                               */
	/* ============================================================== */

	/**
	 * The sentences of the Boxes card, the suggestion chip and the "How
	 * full" line — one table for the page and for its JS (which fills the
	 * same templates as the numbers are typed).
	 *
	 * @return array<string, string>
	 */
	public static function box_strings() {
		return array_merge(
			array(
				/* translators: 1: box number, 2: percent, 3: under / about full / over */
				'box'        => __( 'Box %1$s · %2$s%% · %3$s', 'fishotel-misc-plugin' ),
				/* translators: 1: percent, 2: lines */
				'unboxed'    => __( 'no box number · %1$s%% · lines: %2$d', 'fishotel-misc-plugin' ),
				'under'      => __( 'under', 'fishotel-misc-plugin' ),
				'full'       => __( 'about full', 'fishotel-misc-plugin' ),
				'over'       => __( 'over', 'fishotel-misc-plugin' ),
				'none'       => __( 'No line has a percent yet: every line uses its per-box count.', 'fishotel-misc-plugin' ),
				/* translators: %1$s: boxes */
				'total'      => __( 'Boxes by the percents: %1$s · Boxes field: not set', 'fishotel-misc-plugin' ),
				/* translators: 1: boxes, 2: the order's Boxes field */
				'totalField' => __( 'Boxes by the percents: %1$s · Boxes field: %2$s', 'fishotel-misc-plugin' ),
				/* translators: %1$d: order lines */
				'missing'    => __( 'received lines without a %%: %1$d (still on their per-box count)', 'fishotel-misc-plugin' ),
			),
			Box_Shares::strings()
		);
	}

	/**
	 * The Boxes card's chips (pure): one per box number — "Box 1 · 95% ·
	 * about full" — then the lines with a percent but no box number.
	 *
	 * @param array      $summary Costing::box_summary().
	 * @param array|null $strings box_strings() (null = the site's).
	 * @return array[] state (under|full|over|unboxed), text.
	 */
	public static function box_summary_rows( array $summary, $strings = null ) {
		$s    = is_array( $strings ) ? $strings : self::box_strings();
		$rows = array();
		foreach ( (array) ( $summary['boxes'] ?? array() ) as $box ) {
			$state  = in_array( (string) ( $box['state'] ?? '' ), Costing::BOX_STATES, true ) ? (string) $box['state'] : 'under';
			$rows[] = array(
				'state' => $state,
				'text'  => sprintf( $s['box'], (string) (int) $box['box_no'], Costing::num( $box['pct'], '0', 2 ), $s[ $state ] ),
			);
		}
		if ( (int) ( $summary['unboxed']['lines'] ?? 0 ) > 0 ) {
			$rows[] = array(
				'state' => 'unboxed',
				'text'  => sprintf( $s['unboxed'], Costing::num( $summary['unboxed']['pct'], '0', 2 ), (int) $summary['unboxed']['lines'] ),
			);
		}
		return $rows;
	}

	/**
	 * The Boxes card's total (pure): the boxes the percents add up to (the
	 * sum ÷ 100, to two decimals) beside the order's Boxes field — and,
	 * while some received lines have a percent and others none, how many
	 * have none: those still take their share by their per-box count, so
	 * the percents are only right "compared to each other" once every
	 * received line has one.
	 *
	 * @param array      $summary Costing::box_summary().
	 * @param array|null $strings box_strings() (null = the site's).
	 * @return string
	 */
	public static function box_total_text( array $summary, $strings = null ) {
		$s = is_array( $strings ) ? $strings : self::box_strings();
		if ( (int) ( $summary['lines'] ?? 0 ) < 1 ) {
			return $s['none'];
		}
		// The sum is a whole number of hundredths, so this rounds the same in PHP and in JS.
		$boxes   = Costing::num( round( (float) ( $summary['pct_total'] ?? 0 ) ) / 100, '0', 2 );
		$text    = null === ( $summary['order_boxes'] ?? null ) ? sprintf( $s['total'], $boxes ) : sprintf( $s['totalField'], $boxes, (string) (int) $summary['order_boxes'] );
		$missing = (int) ( $summary['missing'] ?? 0 );
		return $missing > 0 ? $text . ' · ' . sprintf( $s['missing'], $missing ) : $text;
	}

	/**
	 * What the order page's Boxes card shows (pure): the chips, the total,
	 * the "How full" line while the order is a draft or ordered ('' once
	 * the fish are in), and the location's remembered shares as the page's
	 * JS reads them.
	 *
	 * @param array   $order  The order with its lines (boxes, status, cost_mode).
	 * @param array[] $shares The remembered shares of its supplier + origin.
	 * @return array{summary: array, rows: array[], total: string, how_full: string, shares: array[]}
	 */
	public static function boxes_panel( array $order, array $shares ) {
		$lines   = (array) ( $order['lines'] ?? array() );
		$summary = Costing::box_summary( $lines, $order['boxes'] ?? null );

		return array(
			'summary'  => $summary,
			'rows'     => self::box_summary_rows( $summary ),
			'total'    => self::box_total_text( $summary ),
			'how_full' => in_array( (string) ( $order['status'] ?? '' ), Box_Shares::HOW_FULL_STATUSES, true ) ? Box_Shares::how_full_text( Box_Shares::how_full( $lines, $shares ) ) : '',
			'shares'   => Box_Shares::for_js( $shares ),
		);
	}

	/* ============================================================== */
	/*  AJAX: orders                                                   */
	/* ============================================================== */

	/**
	 * Capability + nonce, or die with JSON.
	 */
	private static function guard() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		check_ajax_referer( Admin::NONCE_AJAX, 'nonce' );
		if ( ! Store::tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ) ), 500 );
		}
	}

	/**
	 * AJAX: save (header + lines, the recomputed figures back), checkin
	 * (the stays), delete (a draft without stays), identify (correct the
	 * fish of one line that received fish — and its stays), shares (the
	 * remembered box shares of a supplier + origin — read only, for the
	 * suggestion chips when either field changes on the page), view (which
	 * of Entry / Numbers this user left the page in — nothing of the order).
	 */
	public function ajax_order() {
		self::guard();

		$raw      = self::unpack_lines( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$op       = isset( $raw['op'] ) ? sanitize_key( $raw['op'] ) : '';
		$id       = isset( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;
		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();
		$current  = $id > 0 ? $store->order( $id ) : null;

		if ( $id > 0 && ! $current ) {
			wp_send_json_error( array( 'message' => __( 'That order no longer exists.', 'fishotel-misc-plugin' ) ), 404 );
		}

		try {
			switch ( $op ) {
				case 'save':
					$result = self::save_order( $store, $raw, $id, $today );
					if ( ! empty( $result['kept'] ) ) {
						self::flash( (string) $result['message'] ); // The page reloads on those lines as they are: say why there.
					}
					break;
				case 'checkin':
					if ( ! $current ) {
						wp_send_json_error( array( 'message' => __( 'Save the order first.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$outcome = Orders::check_in( $store, $current, $today, $settings, array( 'actor_id' => (int) get_current_user_id() ) );
					$result  = array(
						'message' => Orders::check_in_message( $outcome ),
						'created' => count( $outcome['created'] ),
						'skipped' => (int) $outcome['skipped'],
						'errors'  => $outcome['errors'],
					);
					break;
				case 'delete':
					if ( ! $current ) {
						wp_send_json_error( array( 'message' => __( 'Nothing to delete.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$deleted = $store->delete_order( $id );
					if ( 'referenced' === $deleted ) {
						wp_send_json_error( array( 'message' => __( 'This order has lines that became stays; it cannot be deleted. Close it instead.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( ! $deleted ) {
						wp_send_json_error( array( 'message' => __( 'The order could not be deleted.', 'fishotel-misc-plugin' ) ), 500 );
					}
					Box_Shares::release_lines( $store, (array) ( $current['lines'] ?? array() ) ); // Its percents are no longer remembered.
					Splits::forget( array_column( (array) ( $current['lines'] ?? array() ), 'id' ) );
					$result = array(
						'message'  => __( 'Fish order deleted.', 'fishotel-misc-plugin' ),
						'redirect' => Admin::page_url( 'arrivals' ),
					);
					break;
				case 'identify':
					if ( ! $current ) {
						wp_send_json_error( array( 'message' => __( 'Save the order first.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$result = self::identify_line( $store, $current, $raw, $today, $settings, (int) get_current_user_id() );
					break;
				case 'shares':
					$result = array( 'shares' => self::shares_for( $store, $raw ) );
					break;
				case 'view':
					// Which view (and "More columns") this user left the order page in.
					$prefs = self::remember_view( get_user_meta( get_current_user_id(), self::VIEW_META, true ), $raw );
					update_user_meta( get_current_user_id(), self::VIEW_META, $prefs );
					$result = array( 'prefs' => $prefs );
					break;
				default:
					wp_send_json_error( array( 'message' => __( 'Unknown action.', 'fishotel-misc-plugin' ) ), 400 );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['error'] ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * The order form's lines as one field. An order of 150 fish is some
	 * 2,500 form fields — more than PHP reads by default (max_input_vars),
	 * and a save that lost its last lines would delete them. So the page
	 * posts the line rows as one JSON field, lines_json, which is unpacked
	 * here into the lines[n][field] rows every reader of the form expects.
	 * A request without it (an older page) is left as it is. A lines_json
	 * that cannot be read (half a character in a name, a field cut short)
	 * is marked lines_unread — the save is refused, never taken for an
	 * order whose lines were all removed.
	 *
	 * @param array $raw Unslashed payload.
	 * @return array
	 */
	public static function unpack_lines( array $raw ) {
		unset( $raw['lines_unread'] ); // Ours to set, never the page's.
		if ( ! isset( $raw['lines_json'] ) || ! is_string( $raw['lines_json'] ) ) {
			return $raw;
		}
		$rows  = json_decode( $raw['lines_json'], true );
		$lines = array();
		if ( ! is_array( $rows ) ) {
			// A field that cannot be read is NOT an order without lines: every reader of the form
			// refuses it (lines_unread), or a Save would delete every line that has no stay.
			$raw['lines_unread'] = true;
		}
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( $row as $field => $value ) {
				if ( is_string( $field ) && ( is_scalar( $value ) || null === $value ) && ! is_bool( $value ) ) {
					$clean[ $field ] = (string) $value;
				}
			}
			$lines[] = $clean;
			if ( count( $lines ) >= Orders::MAX_LINES + 50 ) {
				break;
			}
		}
		unset( $raw['lines_json'] );
		$raw['lines'] = $lines;
		return $raw;
	}

	/**
	 * What a save says when the page's lines could not be read.
	 *
	 * @return string
	 */
	public static function lines_unread_text() {
		return __( 'The lines of this order did not arrive in one piece, so nothing was saved and no line was touched. Reload the page and try again; if it happens again, look for an odd character (half an emoji) in a name or a note.', 'fishotel-misc-plugin' );
	}

	/**
	 * The remembered box shares of a posted supplier + origin, as the
	 * page's JS reads them (Box_Shares::for_js()).
	 *
	 * @param object $store Store (or the tests' stand-in).
	 * @param array  $raw   Unslashed payload: supplier, origin.
	 * @return array[]
	 */
	public static function shares_for( $store, array $raw ) {
		$text = static function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? substr( sanitize_text_field( (string) $raw[ $key ] ), 0, 80 ) : '';
		};
		return Box_Shares::for_js( (array) $store->box_shares( array( 'location_key' => Box_Shares::location_key( $text( 'supplier' ), $text( 'origin' ) ) ) ) );
	}

	/**
	 * Correct the fish of one order line — the order page's "Correct the
	 * fish…" (the testable half of the "identify" op): the line must have
	 * received fish or have become a stay (before that, its species is
	 * simply retyped); the fish is what Identify::fish_from() reads from
	 * the payload. The line, and every stay of it that still carried its
	 * old name, are corrected together (Identify::line()).
	 *
	 * @param object $store    Store (or the tests' stand-in).
	 * @param array  $order    The order with its lines (Store::order()).
	 * @param array  $raw      Unslashed payload: line_id, species, product_id, unknown, size.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @param int    $actor_id User.
	 * @return array message, line_id, stays, label — or error.
	 */
	public static function identify_line( $store, array $order, array $raw, $today, array $settings, $actor_id = 0 ) {
		$line_id = isset( $raw['line_id'] ) && is_scalar( $raw['line_id'] ) ? absint( $raw['line_id'] ) : 0;
		$line    = null;
		foreach ( (array) ( $order['lines'] ?? array() ) as $candidate ) {
			if ( $line_id > 0 && (int) $candidate['id'] === $line_id ) {
				$line = $candidate;
				break;
			}
		}
		if ( ! $line ) {
			return array( 'error' => __( 'That line is not on this order any more. Reload the order.', 'fishotel-misc-plugin' ) );
		}
		if ( empty( $line['stay_id'] ) && (int) ( $line['qty_received'] ?? 0 ) < 1 ) {
			return array( 'error' => __( 'Nothing has arrived on that line yet: retype its species and save the order.', 'fishotel-misc-plugin' ) );
		}

		$fish = Identify::fish_from( $store, $raw, $settings, $line );
		if ( ! empty( $fish['error'] ) ) {
			return array( 'error' => (string) $fish['error'] );
		}

		$done = Identify::line(
			$store,
			$order,
			$line,
			$fish,
			array(
				'today'    => $today,
				'settings' => $settings,
				'actor_id' => (int) $actor_id,
				'source'   => 'manual',
			)
		);
		if ( ! $done['ok'] ) {
			return array( 'error' => (string) $done['error'] );
		}

		// The landed totals do not move (a fish is costed by its counts and its percent), but the
		// cache follows the line as it is stored now.
		$fresh = $store->order( (int) $order['id'] );
		if ( $fresh ) {
			self::cache_landed_totals( $store, self::resolve_order( $store, $fresh ) );
		}

		return array(
			'message' => '' !== $done['message'] ? $done['message'] . '.' : ( $done['changed'] ? __( 'Line saved.', 'fishotel-misc-plugin' ) : __( 'Nothing changed.', 'fishotel-misc-plugin' ) ),
			'changed' => (bool) $done['changed'],
			'line_id' => (int) $line['id'],
			'stays'   => array_values( array_map( 'intval', (array) $done['stays'] ) ),
			'label'   => $done['label'],
		);
	}

	/**
	 * Save an order from the editor's payload: the header, then the lines
	 * (upsert + delete), then the corrections, then the landed-total
	 * cache, then the remembered box shares, then the figures. A box
	 * number or a percent of a box that was typed wrong refuses the save
	 * before anything is written (Orders::line_errors()); a size that is no
	 * longer in the list leaves that line without one and is named in the
	 * message. A line typed with a leading "??" is an unknown fish
	 * (Identify::flag_lines()); a line's notes are kept as stored (the
	 * grid has no field for them — only for the supplier's code in them).
	 * A split planned in a Tank cell is checked and kept by line id
	 * (split_plans(), Splits::keep()); it is never carried out here. On a line that already became a stay, a
	 * changed species, "??" box or size is a correction: its stays follow
	 * and the line keeps what the label said (Identify::line()). A line
	 * without a stay is the order as typed: retyping its species records
	 * no label ("Correct the fish…" does) — but ticking "??" on a named
	 * line that received fish keeps the name as what the label said, so a
	 * fish marked unknown while unloading still counts as mislabeled once
	 * it is identified as something else; unticking it on a line that
	 * arrived unknown keeps the "??" name in the label's place, so no later
	 * correction reads as a mislabel.
	 *
	 * A page loaded before a fish was corrected elsewhere must not undo
	 * the correction: every row posts the token it was rendered with
	 * (lines[n][seen], Identify::seen()), and a line whose stored fish is
	 * no longer that keeps its stored fish (Identify::keep_corrected()) —
	 * the rest of the row is saved, the reply says so and asks for a
	 * reload. The reply carries the tokens of the lines as saved, so the
	 * page that stays open is not stale after its own save — except for a
	 * line that kept such a fish: the page still shows the old one, so it
	 * gets its stale token back and a second Save before the reload keeps
	 * the correction again.
	 *
	 * @param Store  $store Store.
	 * @param array  $raw   Unslashed payload.
	 * @param int    $id    Order id (0 = new).
	 * @param string $today Site date.
	 * @return array message, order_id, is_new, redirect (new), line_ids, seen (their tokens), figures,
	 *               can_checkin, shares, warnings, followed (stays corrected with their lines), kept
	 *               (lines that kept a fish corrected elsewhere), reload (the page shows labels,
	 *               stays or fish that changed) — or error.
	 */
	public static function save_order( $store, array $raw, $id, $today ) {
		if ( ! empty( $raw['lines_unread'] ) ) {
			return array( 'error' => self::lines_unread_text() );
		}
		$header = Orders::normalize_order( array_merge( $raw, array( 'id' => $id ) ) );
		if ( '' === $header['ref'] ) {
			$year          = Stats::order_year( $header );
			$header['ref'] = Orders::next_ref( $store->order_refs(), $year ? $year : (int) substr( (string) $today, 0, 4 ) );
		}
		$other = $store->order_by_ref( $header['ref'] );
		if ( $other && (int) $other['id'] !== (int) $id ) {
			/* translators: %s: order ref */
			return array( 'error' => sprintf( __( 'The ref "%s" is already used by another order.', 'fishotel-misc-plugin' ), $header['ref'] ) );
		}
		if ( null !== $header['building_id'] ) {
			$building = $store->building( $header['building_id'] );
			if ( ! $building || 'spa' !== (string) $building['area'] ) {
				$header['building_id'] = null; // Only a Spa building receives fish.
			}
		}

		$raw_lines = isset( $raw['lines'] ) && is_array( $raw['lines'] ) ? $raw['lines'] : array();
		$mistakes  = Orders::line_errors( $raw_lines );
		if ( $mistakes ) {
			return array( 'error' => implode( ' ', $mistakes ) . ' ' . __( 'Nothing was saved.', 'fishotel-misc-plugin' ) );
		}

		// The lines as stored before this save: a stale size stays selectable on its
		// own line, and a line the save removes gives its remembered share back.
		$before  = array();
		$current = $id > 0 ? $store->order( $id ) : null;
		foreach ( (array) ( $current['lines'] ?? array() ) as $line ) {
			$before[ (int) $line['id'] ] = $line;
		}

		// The splits planned in the Tank cells: a plan that cannot work refuses the save like a
		// mistyped percent does; a plan on a line that already is a stay is refused plainly, the
		// rest of the save goes on.
		$extras   = Orders::posted_extras( $raw_lines );
		$warnings = array();
		$planned  = self::split_plans( $store, $extras, $before, $today, $warnings );
		if ( ! empty( $planned['errors'] ) ) {
			return array( 'error' => implode( ' ', $planned['errors'] ) . ' ' . __( 'Nothing was saved.', 'fishotel-misc-plugin' ) );
		}

		$lines = Identify::flag_lines( Orders::normalize_lines( $raw_lines ) );
		foreach ( $planned['plans'] as $index => $plan ) {
			// A split line arrives in the first tank of its plan.
			if ( is_array( $plan ) && isset( $lines[ $index ] ) && empty( $before[ (int) $lines[ $index ]['id'] ]['stay_id'] ) ) {
				$lines[ $index ]['tank_id'] = (int) $plan[0]['tank_id'];
			}
		}
		foreach ( $lines as $index => $line ) {
			if ( '' === $line['notes'] && isset( $before[ (int) $line['id'] ] ) ) {
				$lines[ $index ]['notes'] = (string) $before[ (int) $line['id'] ]['notes'];
			}
			// The supplier's code typed (or emptied) under "More columns" lives in the notes, as an import writes it.
			if ( isset( $extras[ $index ] ) && null !== $extras[ $index ]['code'] && $extras[ $index ]['code'] !== Order_Import::code_of( $lines[ $index ]['notes'] ) ) {
				$lines[ $index ]['notes'] = Order_Import::note_with( $lines[ $index ]['notes'], Order_Import::NOTE_CODE, $extras[ $index ]['code'] );
			}
		}

		// A page loaded before a fish was corrected elsewhere (the phone, the Stays tab, another
		// tab) still holds the old fish: such a line keeps the fish as stored, never the page's copy.
		$seen = array();
		foreach ( $raw_lines as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['seen'] ) && is_scalar( $row['id'] ) && is_string( $row['seen'] ) && (int) $row['id'] > 0 ) {
				$seen[ (int) $row['id'] ] = $row['seen'];
			}
		}
		$kept  = array();
		$lines = Identify::keep_corrected( $lines, $seen, $before, $kept );
		// …and a page loaded before a fish was linked to a product posts the line's species-list id
		// empty: the line keeps the id it has, or the save would take the fish off its product again.
		$lines = No_Product::keep_species_ids( $lines, $before );

		$sized = array_filter(
			$lines,
			static function ( $line ) {
				return null !== $line['size_slug'];
			}
		);
		$unsized  = array();
		$lines    = Sizes::apply_to_lines( $lines, $sized ? Sizes::standard( $store, Settings::get() )['sizes'] : array(), $before, $unsized );
		$warnings = array_merge( $warnings, $unsized );

		$saved_id = $store->save_order( $header );
		if ( ! $saved_id ) {
			return array( 'error' => __( 'The order could not be saved.', 'fishotel-misc-plugin' ) );
		}

		$saved = $store->save_order_lines( $saved_id, $lines );
		if ( false === $saved ) {
			return array( 'error' => __( 'The lines could not be saved.', 'fishotel-misc-plugin' ) );
		}

		// Keep the plans by the ids the lines have now; a line the save removed takes its plan along.
		$by_line = array();
		$aligned = count( (array) $saved['ids'] ) >= count( $lines ); // Every posted row was written, in order.
		foreach ( $planned['plans'] as $index => $plan ) {
			$line_id = isset( $lines[ $index ] ) && (int) $lines[ $index ]['id'] > 0 ? (int) $lines[ $index ]['id'] : ( $aligned && isset( $lines[ $index ], $saved['ids'][ $index ] ) ? (int) $saved['ids'][ $index ] : 0 );
			if ( $line_id > 0 ) {
				$by_line[ $line_id ] = $plan;
			}
		}
		Splits::keep( $by_line, array_diff( array_keys( $before ), array_map( 'intval', (array) $saved['ids'] ) ) );

		// A line that became a stay and whose fish or size was changed in the grid: one
		// correction — its stays follow, the line keeps what the label said.
		$followed = 0;
		$labeled  = 0;
		$stored   = $store->order( $saved_id );
		foreach ( (array) ( $stored['lines'] ?? array() ) as $line ) {
			$line_id = (int) $line['id'];
			if ( ! isset( $before[ $line_id ] ) || ! Identify::line_differs( $before[ $line_id ], $line ) ) {
				continue;
			}
			if ( empty( $line['stay_id'] ) ) {
				// Not a stay yet: the grid is the order as typed. Only the "??" box says something
				// about the label — ticked, the fish that arrived is not known to be what the line
				// said; unticked, a fish that arrived unknown had no label to be wrong.
				$flagged = ! empty( $line['needs_id'] ) !== ! empty( $before[ $line_id ]['needs_id'] );
				$label   = $flagged && (int) ( $line['qty_received'] ?? 0 ) > 0 ? Identify::label_for( $before[ $line_id ], (string) $line['species'] ) : null;
				if ( null !== $label && $store->update_line_fields( $line_id, array( 'label_name' => $label ) ) && ! Identify::is_unknown_name( $label ) ) {
					$labeled++;
				}
				continue;
			}
			$done = Identify::line(
				$store,
				$stored,
				$before[ $line_id ],
				Identify::fish_of_line( $store, $line ),
				array(
					'today'    => $today,
					'settings' => Settings::get(),
					'actor_id' => (int) get_current_user_id(),
					'source'   => 'manual',
				)
			);

			$followed += count( (array) $done['stays'] );
			$labeled  += null !== $done['label'] ? 1 : 0;
		}

		// The save that CLOSES an order freezes it: every line without a price of its own takes the
		// shop price as it is read now, and a closed order is never read for again — its numbers,
		// and the past years of the Stats, stay what they were when the shop's prices move.
		if ( 'closed' === (string) $header['status'] && 'closed' !== (string) ( $current['status'] ?? '' ) ) {
			Shop_Price::freeze( $store, (array) $store->order( $saved_id ) );
		}

		$order = Admin_No_Product::mark_order( $store, self::resolve_order( $store, $store->order( $saved_id ) ) );
		self::cache_landed_totals( $store, $order );
		Box_Shares::remember( $store, $order, array_values( $before ), $today );

		$message = __( 'Fish order saved.', 'fishotel-misc-plugin' );
		if ( $followed > 0 ) {
			/* translators: %d: stays */
			$message .= ' ' . sprintf( _n( '%d stay follows its corrected line.', '%d stays follow their corrected lines.', $followed, 'fishotel-misc-plugin' ), $followed );
		}
		if ( $kept ) {
			/* translators: %d: order lines */
			$message .= ' ' . sprintf( _n( '%d line was corrected after this page was loaded and keeps that fish.', '%d lines were corrected after this page was loaded and keep that fish.', count( $kept ), 'fishotel-misc-plugin' ), count( $kept ) );
			foreach ( $kept as $was ) {
				if ( $was['typed'] ) {
					/* translators: %s: the fish the line was corrected to */
					$warnings[] = sprintf( __( 'the fish typed over %s (corrected after this page was loaded — change it on the reloaded page)', 'fishotel-misc-plugin' ), $was['species'] );
				}
			}
		}
		if ( $warnings ) {
			/* translators: %s: what was not saved */
			$message .= ' ' . sprintf( __( 'Not saved: %s.', 'fishotel-misc-plugin' ), implode( '; ', $warnings ) );
		}

		// The tokens the page goes on with: the lines as saved — but a line that kept a fish corrected
		// elsewhere still shows the old one on that page, so it stays stale there until the reload.
		$tokens = array();
		foreach ( $order['lines'] as $line ) {
			$tokens[] = isset( $kept[ (int) $line['id'] ] ) ? $seen[ (int) $line['id'] ] : Identify::seen( $line );
		}

		return array(
			'message'  => $message,
			'order_id' => (int) $saved_id,
			'is_new'   => 0 === (int) $id,
			'redirect' => 0 === (int) $id ? self::order_url( $saved_id ) : '',
			'ref'      => (string) $order['ref'],
			'status'   => (string) $order['status'],
			'line_ids'    => array_values( array_map( 'intval', array_column( $order['lines'], 'id' ) ) ),
			'seen'        => $tokens,
			'figures'     => self::figures( $order ),
			'can_checkin' => self::can_check_in( $order ),
			'shares'      => self::shares_for( $store, $order ),
			'warnings'    => $warnings,
			'followed'    => $followed,
			'kept'        => count( $kept ),
			'reload'      => $followed > 0 || $labeled > 0 || count( $kept ) > 0,
		);
	}

	/**
	 * What a save keeps of the posted split plans (the testable half of the
	 * save's split step). A line that is not a stay yet: a plan with one
	 * part is only its tank, a plan with more is checked (Splits::errors())
	 * — a mistake refuses the whole save —, NONE and "nothing said" are
	 * kept as they are. A line that already IS a stay cannot be split from
	 * the order page: a plan posted for it (a page loaded before the
	 * check-in) is refused plainly — the warning says how that group is
	 * split now — and nothing is kept for it.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $splits   Orders::posted_extras().
	 * @param array  $before   The lines as stored, by id.
	 * @param string $today    Site date.
	 * @param array  $warnings What was not saved (by reference).
	 * @return array{plans: array, errors: string[]} plans: line index → parts | NONE | null.
	 */
	public static function split_plans( $store, array $splits, array $before, $today, array &$warnings ) {
		$out = array(
			'plans'  => array(),
			'errors' => array(),
		);

		$tanks = null;
		foreach ( $splits as $index => $posted ) {
			$plan   = $posted['plan'];
			$linked = $posted['id'] > 0 && ! empty( $before[ $posted['id'] ]['stay_id'] );
			if ( $linked ) {
				if ( is_array( $plan ) && Splits::is_split( $plan ) ) {
					/* translators: %s: species */
					$warnings[] = sprintf( __( 'the split of %s — that line is already checked in: split the group from its tank instead (Move, with a smaller count)', 'fishotel-misc-plugin' ), $posted['species'] );
				}
				$out['plans'][ $index ] = null;
				continue;
			}
			if ( empty( $posted['said'] ) ) {
				continue; // The row has no split field (a page loaded before Split existed): the stored plan is not this save's to forget.
			}
			if ( ! is_array( $plan ) ) {
				$out['plans'][ $index ] = $plan; // NONE, or nothing said.
				continue;
			}

			if ( null === $tanks ) {
				$tanks = Splits::by_id( Splits::tanks( $store, $today, Settings::get() ) );
			}
			foreach ( Splits::errors( $plan, $posted['live'], $tanks ) as $mistake ) {
				/* translators: 1: line number, 2: species, 3: what is wrong */
				$out['errors'][] = sprintf( __( 'Line %1$d (%2$s): %3$s.', 'fishotel-misc-plugin' ), $index + 1, $posted['species'], $mistake );
			}
			$out['plans'][ $index ] = $plan; // One part is only the line's first tank — kept so the Tank cell shows it.
		}

		return $out;
	}

	/**
	 * Write each line's landed total into its cache column when it
	 * changed (so an export or a later reader can show it without
	 * recomputing).
	 *
	 * @param Store $store Store.
	 * @param array $order Resolved order.
	 */
	private static function cache_landed_totals( $store, array $order ) {
		if ( ! method_exists( $store, 'cache_line_total' ) ) {
			return;
		}
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			$landed = isset( $line['landed_total'] ) ? Costing::cents( $line['landed_total'] ) : null;
			$cached = isset( $line['landed_cached'] ) ? $line['landed_cached'] : null;
			if ( null === $landed && null === $cached ) {
				continue;
			}
			if ( null !== $landed && null !== $cached && abs( $landed - (float) $cached ) < 0.005 ) {
				continue;
			}
			$store->cache_line_total( (int) $line['id'], $landed );
		}
	}

	/* ============================================================== */
	/*  AJAX: species                                                  */
	/* ============================================================== */

	/**
	 * What the Species tab's "Link product" did, in a sentence (pure).
	 *
	 * @param string $title The product's title ('' = the link was removed).
	 * @param int[]  $stays The groups in the tanks that followed (Admin_No_Product::species_relinked()).
	 * @return string
	 */
	public static function species_link_message( $title, array $stays ) {
		/* translators: %s: product title */
		$text = '' !== (string) $title ? sprintf( __( 'Linked to "%s".', 'fishotel-misc-plugin' ), (string) $title ) : __( 'Product link removed.', 'fishotel-misc-plugin' );
		if ( $stays ) {
			/* translators: %d: groups in tanks */
			$text .= ' ' . sprintf( _n( '%d group in the tanks that carried the old product follows.', '%d groups in the tanks that carried the old product follow.', count( $stays ), 'fishotel-misc-plugin' ), count( $stays ) );
		}
		return $text;
	}

	/**
	 * AJAX: suggest (the search box: products, aliases and species rows),
	 * products (products only, for the product link), save, delete, link.
	 */
	public function ajax_species() {
		self::guard();

		$raw   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$op    = isset( $raw['op'] ) ? sanitize_key( $raw['op'] ) : '';
		$store = new Store();

		try {
			switch ( $op ) {
				case 'suggest':
				case 'products':
					$term  = isset( $raw['term'] ) ? substr( sanitize_text_field( (string) $raw['term'] ), 0, 80 ) : '';
					// The search boxes also find the fish of the fish orders; the product link looks at products only.
					$items = 'products' === $op ? $store->species_suggestions( $term, self::SUGGEST_LIMIT ) : $store->fish_search( $term, self::SUGGEST_LIMIT );
					$hint  = $store->search_hint( $term, $items ); // A product of that name outside the fish categories: said, so it is not a riddle.
					if ( 'products' === $op ) {
						$items = array_values(
							array_filter(
								$items,
								static function ( $item ) {
									return ! empty( $item['product_id'] ) && 'species' !== (string) ( $item['match'] ?? '' );
								}
							)
						);
					}
					wp_send_json_success(
						array(
							'items' => $items,
							'hint'  => $hint,
						)
					);
					break;

				case 'save':
					$fields = Species::normalize( $raw );
					if ( '' === $fields['name'] ) {
						wp_send_json_error( array( 'message' => __( 'A species needs a name.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$other = $store->species_by_name( $fields['name'] );
					if ( $other && (int) $other['id'] !== (int) $fields['id'] ) {
						/* translators: %s: species name */
						wp_send_json_error( array( 'message' => sprintf( __( '"%s" is already in the list.', 'fishotel-misc-plugin' ), $other['name'] ) ), 400 );
					}
					if ( $fields['id'] > 0 && ! $store->species( $fields['id'] ) ) {
						wp_send_json_error( array( 'message' => __( 'That species no longer exists.', 'fishotel-misc-plugin' ) ), 404 );
					}
					if ( null !== $fields['product_id'] && '' === $store->product_title( $fields['product_id'] ) ) {
						wp_send_json_error( array( 'message' => __( 'No product has that ID.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$saved = $store->save_species( $fields );
					if ( ! $saved ) {
						wp_send_json_error( array( 'message' => __( 'The species could not be saved.', 'fishotel-misc-plugin' ) ), 500 );
					}
					wp_send_json_success(
						array(
							'message' => $fields['id'] > 0 ? __( 'Species saved.', 'fishotel-misc-plugin' ) : __( 'Species added.', 'fishotel-misc-plugin' ),
							'id'      => (int) $saved,
						)
					);
					break;

				case 'delete':
					$id      = isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
					$deleted = $store->delete_species( $id );
					if ( 'referenced' === $deleted ) {
						wp_send_json_error( array( 'message' => __( 'An order line names this species; it stays in the list.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( ! $deleted ) {
						wp_send_json_error( array( 'message' => __( 'The species could not be removed.', 'fishotel-misc-plugin' ) ), 400 );
					}
					wp_send_json_success( array( 'message' => __( 'Species removed.', 'fishotel-misc-plugin' ) ) );
					break;

				case 'link':
					$id         = isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
					$product_id = isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
					$before = $store->species( $id );
					if ( ! $before ) {
						wp_send_json_error( array( 'message' => __( 'That species no longer exists.', 'fishotel-misc-plugin' ) ), 404 );
					}
					$title = $product_id > 0 ? $store->product_title( $product_id ) : '';
					if ( $product_id > 0 && '' === $title ) {
						wp_send_json_error( array( 'message' => __( 'No product has that ID.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( ! $store->link_species_product( $id, $product_id ) ) {
						wp_send_json_error( array( 'message' => __( 'The link could not be saved.', 'fishotel-misc-plugin' ) ), 500 );
					}
					wp_send_json_success(
						array(
							'message' => self::species_link_message( $title, Admin_No_Product::species_relinked( $store, $before, $product_id, Clean_Room::today(), Settings::get() ) ),
							'title'   => $title,
						)
					);
					break;

				default:
					wp_send_json_error( array( 'message' => __( 'Unknown action.', 'fishotel-misc-plugin' ) ), 400 );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ============================================================== */
	/*  The batch sale date (admin-post)                               */
	/* ============================================================== */

	/**
	 * admin-post: save or clear the batch's "Goes on sale" date, then back
	 * to the order with a notice.
	 */
	public function handle_sale_date() {
		$outcome = self::sale_date_request( new Store(), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in sale_date_request().
		if ( 403 === $outcome['status'] ) {
			wp_die( esc_html( $outcome['message'] ), '', array( 'response' => 403 ) );
		}
		self::flash( $outcome['message'] );
		wp_safe_redirect( $outcome['redirect'] );
		exit;
	}

	/**
	 * The batch sale date from the order page's form — the testable half
	 * of handle_sale_date(): the capability (manage_woocommerce, as every
	 * order edit) and the nonce (per order) first, then op "save" with a
	 * date (type=date, Y-m-d from Replay::SALE_DATE_MIN to two years after
	 * today; anything else is refused and nothing changes) or op "clear".
	 * A real change is written with Store::update_order_fields() and
	 * logged through fishotel_log — who, the order and old → new; an
	 * unchanged date writes nothing.
	 *
	 * @param object $store Store (or the tests' stand-in).
	 * @param array  $raw   Unslashed POST: order_id, _wpnonce, op (save|clear), sale_date.
	 * @param string $today Site date ('' = Clean_Room::today()).
	 * @return array{status: int, ok: bool, message: string, redirect: string, old: string|null, new: string|null}
	 */
	public static function sale_date_request( $store, array $raw, $today = '' ) {
		$order_id = isset( $raw['order_id'] ) && is_scalar( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;
		$out      = array(
			'status'   => 403,
			'ok'       => false,
			'message'  => '',
			'redirect' => $order_id > 0 ? self::order_url( $order_id ) : Admin::page_url( 'arrivals' ),
			'old'      => null,
			'new'      => null,
		);

		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			$out['message'] = __( 'You do not have permission to change sale dates.', 'fishotel-misc-plugin' );
			return $out;
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( $order_id <= 0 || ! wp_verify_nonce( $nonce, self::NONCE_SALE_DATE . $order_id ) ) {
			$out['message'] = __( 'This form has expired. Reload the order and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$order = $store->order( $order_id );
		if ( ! $order ) {
			$out['status']   = 404;
			$out['message']  = __( 'That order no longer exists.', 'fishotel-misc-plugin' );
			$out['redirect'] = Admin::page_url( 'arrivals' );
			return $out;
		}

		$op  = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : 'save';
		$old = Replay::is_date( $order['sale_date'] ?? null ) ? (string) $order['sale_date'] : null;
		$new = null;
		if ( 'clear' !== $op ) {
			$date = isset( $raw['sale_date'] ) && is_scalar( $raw['sale_date'] ) ? trim( sanitize_text_field( (string) $raw['sale_date'] ) ) : '';
			if ( '' === $date ) {
				$out['status']  = 400;
				$out['message'] = __( 'Pick a date first (or use Clear). Nothing was changed.', 'fishotel-misc-plugin' );
				return $out;
			}
			if ( ! Replay::is_date( $date ) ) {
				$out['status']  = 400;
				/* translators: %s: what was typed */
				$out['message'] = sprintf( __( '"%s" is not a date. Nothing was changed.', 'fishotel-misc-plugin' ), substr( $date, 0, 40 ) );
				return $out;
			}
			$today = Replay::is_date( $today ) ? (string) $today : Clean_Room::today();
			if ( ! Replay::is_sale_date_in_range( $date, $today ) ) {
				$out['status']  = 400;
				/* translators: 1: what was typed, 2: earliest date, 3: latest date */
				$out['message'] = sprintf( __( '%1$s is outside the dates a sale can be set to (%2$s to %3$s). Nothing was changed.', 'fishotel-misc-plugin' ), $date, Replay::SALE_DATE_MIN, Replay::sale_date_max( $today ) );
				return $out;
			}
			$new = $date;
		}
		$out['old'] = $old;
		$out['new'] = $new;

		$ref = (string) $order['ref'];
		if ( $old === $new ) {
			$out['status']  = 200;
			$out['ok']      = true;
			$out['message'] = null === $new
				/* translators: %s: order ref */
				? sprintf( __( 'Order %s has no sale date; nothing changed.', 'fishotel-misc-plugin' ), $ref )
				/* translators: 1: order ref, 2: date */
				: sprintf( __( 'Order %1$s already goes on sale %2$s; nothing changed.', 'fishotel-misc-plugin' ), $ref, $new );
			return $out;
		}

		if ( ! $store->update_order_fields( $order_id, array( 'sale_date' => $new ) ) ) {
			$out['status']  = 500;
			$out['message'] = __( 'The sale date could not be saved.', 'fishotel-misc-plugin' );
			return $out;
		}

		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$login = is_object( $user ) && ! empty( $user->user_login ) ? (string) $user->user_login : 'user #' . (int) get_current_user_id();
		do_action(
			'fishotel_log',
			'info',
			'clean-room',
			sprintf( 'Sale date of fish order %1$s changed by %2$s: %3$s → %4$s', $ref, $login, null !== $old ? $old : 'not set', null !== $new ? $new : 'not set' ),
			array(
				'order_id'   => $order_id,
				'order_ref'  => $ref,
				'user_id'    => (int) get_current_user_id(),
				'user_login' => $login,
				'old'        => $old,
				'new'        => $new,
			)
		);

		$out['status']  = 200;
		$out['ok']      = true;
		$out['message'] = null === $new
			/* translators: 1: order ref, 2: the old date */
			? sprintf( __( 'Order %1$s: sale date cleared (was %2$s). Its groups without their own date are not for sale until a date is set.', 'fishotel-misc-plugin' ), $ref, (string) $old )
			/* translators: 1: order ref, 2: date, 3: the old date or "not set" */
			: sprintf( __( 'Order %1$s goes on sale %2$s (was %3$s).', 'fishotel-misc-plugin' ), $ref, $new, null !== $old ? $old : __( 'not set', 'fishotel-misc-plugin' ) );
		return $out;
	}

	/* ============================================================== */
	/*  Exports                                                        */
	/* ============================================================== */

	/**
	 * admin-post: an XLSX (or CSV) of the orders (+ lines), the species,
	 * the stats of a year, the stays — or one of the Stats tab's further
	 * views (Admin_Stats::export(): the comparison of years, the species
	 * by year, the prices to raise, the real sales). Without the zip
	 * extension an XLSX request falls back to CSV with a notice on the
	 * next page.
	 */
	public function download_export() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to download this.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_EXPORT );

		$what   = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		$format = isset( $_GET['format'] ) && 'csv' === sanitize_key( wp_unslash( $_GET['format'] ) ) ? 'csv' : 'xlsx';
		$year   = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : 0;
		$closed = isset( $_GET['closed'] ) && '1' === (string) wp_unslash( $_GET['closed'] );

		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();

		switch ( $what ) {
			case 'orders':
				$sheets = self::orders_sheets( self::resolved_orders( $store, $year > 0 ? array( 'year' => $year ) : array() ) );
				$csv    = 'Lines';
				break;
			case 'species':
				$rows   = $store->species_list( array(), Store::SPECIES_LIMIT, 0 );
				$sheets = array( 'Species' => self::species_rows( $rows, $store->open_stay_counts(), self::product_titles( $store, array_filter( array_column( $rows, 'product_id' ) ) ) ) );
				$csv    = 'Species';
				break;
			case 'stats':
				$sheets = self::stats_sheets( self::stats_for_year( $store, $year ), $year );
				$csv    = 'Orders';
				break;
			case 'stays':
				$stays = $store->open_stays( array(), $today, $settings );
				if ( $closed ) {
					$stays = array_merge( $stays, $store->closed_stays( $today, $settings, 2000 ) );
				}
				$sheets = array( 'Stays' => self::stays_rows( $stays ) );
				$csv    = 'Stays';
				break;
			default:
				// Compare years, Species by year, Prices to raise, Real sales.
				$more = Admin_Stats::export( $what, $format, $store, wp_unslash( $_GET ), $today );
				if ( null === $more ) {
					wp_die( esc_html__( 'Unknown export.', 'fishotel-misc-plugin' ), '', array( 'response' => 400 ) );
				}
				$sheets = $more['sheets'];
				$csv    = $more['csv'];
		}

		self::send( 'fishotel-' . $what, $format, $sheets, $csv );
	}

	/**
	 * Send a workbook (or its CSV sheet) and exit.
	 *
	 * @param string $base      File name base.
	 * @param string $format    xlsx|csv.
	 * @param array  $sheets    Sheet name => rows.
	 * @param string $csv_sheet The sheet a CSV carries.
	 */
	private static function send( $base, $format, array $sheets, $csv_sheet ) {
		$stamp = gmdate( 'Ymd-His' );

		if ( 'xlsx' === $format ) {
			$bytes = Xlsx::build( $sheets );
			if ( '' !== $bytes ) {
				nocache_headers();
				header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
				header( 'Content-Disposition: attachment; filename="' . $base . '-' . $stamp . '.xlsx"' );
				header( 'Content-Length: ' . strlen( $bytes ) );
				echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
				exit;
			}
			self::flash( __( 'The Excel file could not be written on this server (the PHP zip extension is missing), so a CSV was sent instead.', 'fishotel-misc-plugin' ) );
		}

		$rows = isset( $sheets[ $csv_sheet ] ) ? $sheets[ $csv_sheet ] : (array) reset( $sheets );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $base . '-' . $stamp . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		self::write_csv( $out, $rows );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Typed rows → guarded CSV (pure apart from the stream).
	 *
	 * @param resource $out  Stream.
	 * @param array[]  $rows Rows.
	 * @return int Rows written.
	 */
	public static function write_csv( $out, array $rows ) {
		$n = 0;
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( self::class, 'csv_value' ), is_array( $row ) ? $row : array( $row ) ) );
			$n++;
		}
		return $n;
	}

	/**
	 * A typed cell as a guarded CSV string (pure). A typed number is our
	 * own arithmetic (digits, a sign, a point, an exponent — never a
	 * formula), so it is written as is: guarding it would turn a negative
	 * profit into the text '-50.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_value( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return is_nan( $value ) || is_infinite( $value ) ? '' : Xlsx::number( $value );
		}
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}
		return Admin::csv_cell( $value );
	}

	/**
	 * The orders workbook: an "Orders" sheet (one row per order with its
	 * figures) and a "Lines" sheet (one row per line; its size label, box
	 * number and percent of a box, then needs_id — the fish still has to
	 * be identified — and label_name — what the supplier's label called a
	 * corrected fish — are the last columns, added after the older ones) —
	 * pure.
	 *
	 * @param array[] $orders Resolved orders.
	 * @return array{Orders: array[], Lines: array[]}
	 */
	public static function orders_sheets( array $orders ) {
		$head  = array( 'id', 'ref', 'supplier', 'origin', 'order_date', 'received_date', 'sale_date', 'status', 'cost_mode', 'boxes', 'tariffs', 'domestic_flight', 'import_fees', 'credit', 'markup', 'worth_threshold', 'schedule', 'building', 'lines', 'ordered', 'received', 'survived', 'doa', 'fill_rate', 'survival_rate', 'doa_rate', 'fee_rate', 'total', 'grand_total', 'revenue', 'up_down', 'ratio', 'doa_cost_sheet', 'doa_cost_per_fish', 'notes' );
		$lhead = array( 'order_ref', 'line_id', 'species', 'sci_name', 'species_id', 'group', 'ordered', 'received', 'per_box', 'bags', 'units', 'unit_price', 'landed_total', 'landed_per_fish', 'suggested_price', 'worth', 'profit', 'margin', 'worth_it', 'survived', 'doa', 'revenue', 'doa_cost', 'live', 'sold', 'stay_id', 'tank', 'notes', 'size', 'box_no', 'box_pct', 'needs_id', 'label_name' );
		$rows  = array( $head );
		$lines = array( $lhead );

		foreach ( $orders as $order ) {
			$c      = (array) ( $order['costing'] ?? array() );
			$rows[] = array(
				(int) $order['id'],
				(string) $order['ref'],
				(string) $order['supplier'],
				(string) $order['origin'],
				$order['order_date'],
				$order['received_date'],
				$order['sale_date'] ?? null,
				(string) $order['status'],
				(string) $order['cost_mode'],
				$order['boxes'],
				(float) $order['tariffs'],
				(float) $order['domestic_flight'],
				(float) $order['import_fees'],
				(float) $order['credit'],
				(float) $order['markup'],
				(float) $order['worth_threshold'],
				(string) $order['schedule'],
				! empty( $order['building_number'] ) ? (int) $order['building_number'] : null,
				(int) ( $c['lines'] ?? 0 ),
				(int) ( $c['ordered'] ?? 0 ),
				(int) ( $c['received'] ?? 0 ),
				! empty( $c['survived_known'] ) ? (int) $c['survived'] : null,
				(int) ( $c['doa'] ?? 0 ),
				self::rate( $c['fill_rate'] ?? null ),
				self::rate( $c['survival_rate'] ?? null ),
				self::rate( $c['doa_rate'] ?? null ),
				(float) ( $c['fee_rate'] ?? 0 ),
				(float) ( $c['total'] ?? 0 ),
				(float) ( $c['grand_total'] ?? 0 ),
				(float) ( $c['revenue'] ?? 0 ),
				(float) ( $c['up_down_priced'] ?? ( $c['up_down'] ?? 0 ) ),
				self::rate( $c['ratio'] ?? null ),
				(float) ( $c['doa_cost_sheet'] ?? 0 ),
				(float) ( $c['doa_cost_per_fish'] ?? 0 ),
				(string) $order['notes'],
			);
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				$lines[] = array(
					(string) $order['ref'],
					(int) $line['id'],
					(string) $line['species'],
					(string) $line['sci_name'],
					$line['species_id'],
					(string) ( $line['group_name'] ?? '' ),
					(int) $line['qty_ordered'],
					$line['qty_received'],
					(int) ( $line['per_box_used'] ?? 0 ),
					$line['bags'],
					self::rate( $line['units'] ?? null ),
					$line['unit_price'],
					self::rate( $line['landed_total'] ?? null ),
					self::rate( $line['landed_per_fish'] ?? null ),
					self::rate( $line['suggested_price'] ?? null ),
					Costing::worth_of( $line )['price'], // The typed price, else the shop's as it is read now; blank = none.
					self::rate( $line['profit'] ?? null ),
					self::rate( $line['margin'] ?? null ),
					isset( $line['worth_it'] ) && null !== $line['worth_it'] ? (bool) $line['worth_it'] : null,
					isset( $line['survived'] ) ? $line['survived'] : null,
					(int) ( $line['doa'] ?? 0 ),
					self::rate( $line['revenue'] ?? null ),
					self::rate( $line['doa_cost'] ?? null ),
					isset( $line['live'] ) ? $line['live'] : null,
					isset( $line['sold'] ) ? $line['sold'] : null,
					$line['stay_id'],
					$line['tank_number'],
					(string) $line['notes'],
					isset( $line['size_label'] ) ? $line['size_label'] : null,
					isset( $line['box_no'] ) ? $line['box_no'] : null,
					self::rate( $line['box_pct'] ?? null ),
					! empty( $line['needs_id'] ),
					isset( $line['label_name'] ) ? $line['label_name'] : null,
				);
			}
		}

		return array(
			'Orders' => $rows,
			'Lines'  => $lines,
		);
	}

	/**
	 * The species sheet (pure).
	 *
	 * @param array[] $rows   Hydrated species rows.
	 * @param array   $counts Store::open_stay_counts().
	 * @param array   $titles Product titles by id.
	 * @return array[]
	 */
	public static function species_rows( array $rows, array $counts, array $titles = array() ) {
		$out = array( array( 'id', 'name', 'sci_name', 'group', 'per_box', 'my_price', 'online_price', 'online_site', 'online_date', 'reef_safe', 'max_size', 'product_id', 'product', 'open_stays', 'notes' ) );
		foreach ( $rows as $row ) {
			$out[] = array(
				(int) $row['id'],
				(string) $row['name'],
				(string) $row['sci_name'],
				(string) $row['group_name'],
				(int) $row['per_box'],
				$row['my_price'],
				$row['online_price'],
				$row['online_site'],
				$row['online_date'],
				(string) $row['reef_safe'],
				(string) $row['max_size'],
				$row['product_id'],
				$row['product_id'] && isset( $titles[ (int) $row['product_id'] ] ) ? (string) $titles[ (int) $row['product_id'] ] : null,
				self::open_stays_of( $row, $counts ),
				(string) $row['notes'],
			);
		}
		return $out;
	}

	/**
	 * The open stays of a species row: by its product when linked, else
	 * by its name (pure).
	 *
	 * @param array $row    Species row.
	 * @param array $counts Store::open_stay_counts().
	 * @return int
	 */
	public static function open_stays_of( array $row, array $counts ) {
		if ( ! empty( $row['product_id'] ) && isset( $counts['product'][ (int) $row['product_id'] ] ) ) {
			return (int) $counts['product'][ (int) $row['product_id'] ];
		}
		$key = Species::key( (string) ( $row['name'] ?? '' ) );
		return isset( $counts['name'][ $key ] ) ? (int) $counts['name'][ $key ] : 0;
	}

	/**
	 * The stats workbook (pure): Orders, Totals (weighted and sheet-style),
	 * By supplier, By origin, By group. How the labels held up — the lines
	 * that received fish, the mislabeled ones, their rate, the unknown fish
	 * not identified yet — are the last columns of Orders (so the CSV has
	 * them), By supplier and By origin, and the last rows of Totals.
	 *
	 * @param array $stats Stats::build().
	 * @param int   $year  The year (0 = all).
	 * @return array<string, array[]>
	 */
	public static function stats_sheets( array $stats, $year = 0 ) {
		$orders = array( array( 'ref', 'supplier', 'origin', 'year', 'order_date', 'received_date', 'status', 'cost_mode', 'lines', 'ordered', 'received', 'survived', 'doa', 'fill_rate', 'survival_rate', 'doa_rate', 'total', 'grand_total', 'revenue', 'up_down', 'ratio', 'received_lines', 'mislabeled', 'mislabeled_rate', 'not_identified' ) );
		foreach ( (array) $stats['orders'] as $row ) {
			$orders[] = array(
				(string) $row['ref'],
				(string) $row['supplier'],
				(string) $row['origin'],
				$row['year'],
				$row['order_date'],
				$row['received_date'],
				(string) $row['status'],
				(string) $row['mode'],
				(int) $row['lines'],
				(int) $row['ordered'],
				(int) $row['received'],
				! empty( $row['survived_known'] ) ? (int) $row['survived'] : null,
				(int) $row['doa'],
				self::rate( $row['fill_rate'] ),
				self::rate( $row['survival_rate'] ),
				self::rate( $row['doa_rate'] ),
				(float) $row['total'],
				(float) $row['grand_total'],
				(float) $row['revenue'],
				(float) $row['up_down'],
				self::rate( $row['ratio'] ),
				(int) ( $row['received_lines'] ?? 0 ),
				(int) ( $row['mislabeled'] ?? 0 ),
				self::rate( $row['mislabeled_rate'] ?? null ),
				(int) ( $row['unidentified'] ?? 0 ),
			);
		}

		$t      = (array) $stats['totals'];
		$s      = (array) $stats['sheet'];
		$totals = array(
			array( 'figure', 'weighted (sum / sum)', 'as the spreadsheet averaged them' ),
			array( 'year', $year > 0 ? (int) $year : 'all', $year > 0 ? (int) $year : 'all' ),
			array( 'orders / batches', (int) $t['orders'], (int) $s['batches'] ),
			array( 'ordered', (int) $t['ordered'], null ),
			array( 'received', (int) $t['received'], null ),
			array( 'survived', ! empty( $t['survived_known'] ) ? (int) $t['survived'] : null, (int) $s['through_qt'] ),
			array( 'doa', (int) $t['doa'], null ),
			array( 'fill_rate', self::rate( $t['fill_rate'] ), self::rate( $s['fill_rate'] ) ),
			array( 'survival_rate', self::rate( $t['survival_rate'] ), self::rate( $s['survival_rate'] ) ),
			array( 'doa_rate', self::rate( $t['doa_rate'] ), self::rate( $s['doa_rate'] ) ),
			array( 'total', (float) $t['total'], null ),
			array( 'revenue', (float) $t['revenue'], null ),
			array( 'up_down', (float) $t['up_down'], null ),
			array( 'ratio', self::rate( $t['ratio'] ), null ),
			array( 'received_lines', (int) ( $t['received_lines'] ?? 0 ), null ),
			array( 'mislabeled', (int) ( $t['mislabeled'] ?? 0 ), null ),
			array( 'mislabeled_rate', self::rate( $t['mislabeled_rate'] ?? null ), null ),
			array( 'not_identified', (int) ( $t['unidentified'] ?? 0 ), null ),
		);

		$group_sheet = static function ( array $groups, $label, $labels = false ) {
			$rows = array( array_merge( array( $label, 'orders', 'lines', 'ordered', 'received', 'survived', 'doa', 'fill_rate', 'survival_rate', 'doa_rate', 'total', 'revenue', 'up_down', 'ratio' ), $labels ? array( 'received_lines', 'mislabeled', 'mislabeled_rate', 'not_identified' ) : array() ) );
			foreach ( $groups as $g ) {
				$cells = array(
					(string) $g['name'],
					(int) $g['orders'],
					(int) $g['lines'],
					(int) $g['ordered'],
					(int) $g['received'],
					! empty( $g['survived_known'] ) ? (int) $g['survived'] : null,
					(int) $g['doa'],
					self::rate( $g['fill_rate'] ),
					self::rate( $g['survival_rate'] ),
					self::rate( $g['doa_rate'] ),
					(float) $g['total'],
					(float) $g['revenue'],
					(float) $g['up_down'],
					self::rate( $g['ratio'] ),
				);
				if ( $labels ) {
					$cells[] = (int) ( $g['received_lines'] ?? 0 );
					$cells[] = (int) ( $g['mislabeled'] ?? 0 );
					$cells[] = self::rate( $g['mislabeled_rate'] ?? null );
					$cells[] = (int) ( $g['unidentified'] ?? 0 );
				}
				$rows[] = $cells;
			}
			return $rows;
		};

		return array(
			'Orders'      => $orders,
			'Totals'      => $totals,
			'By supplier' => $group_sheet( (array) $stats['by_supplier'], 'supplier', true ),
			'By origin'   => $group_sheet( (array) $stats['by_origin'], 'origin', true ),
			'By group'    => $group_sheet( (array) $stats['by_group'], 'species_group' ),
		);
	}

	/**
	 * The stays sheet (pure): the CSV's columns, typed; sale_ready_date is
	 * the effective sale date (the stay's own, else its batch's), empty
	 * when none is set; needs_id is 1 while the fish still has to be
	 * identified.
	 *
	 * @param array[] $stays Hydrated stays.
	 * @return array[]
	 */
	public static function stays_rows( array $stays ) {
		$out = array( Admin::csv_header() );
		foreach ( $stays as $stay ) {
			$tank  = ! empty( $stay['tank_number'] ) ? (int) $stay['tank_number'] : null;
			$out[] = array(
				(int) ( $stay['id'] ?? 0 ),
				(string) ( $stay['species'] ?? '' ),
				isset( $stay['product_id'] ) ? $stay['product_id'] : null,
				(int) ( $stay['qty_arrived'] ?? 0 ),
				(int) ( $stay['qty_live'] ?? 0 ),
				(string) ( $stay['checkin_date'] ?? '' ),
				isset( $stay['day'] ) ? $stay['day'] : null,
				Admin::state_cell( $stay ),
				isset( $stay['cleared_date'] ) ? $stay['cleared_date'] : null,
				isset( $stay['sale_ready_date'] ) && Replay::is_date( $stay['sale_ready_date'] ) ? (string) $stay['sale_ready_date'] : null,
				isset( $stay['closed_date'] ) ? $stay['closed_date'] : null,
				$tank,
				null !== $tank ? (int) ( floor( $tank / 100 ) * 100 ) : null,
				(string) ( $stay['area'] ?? '' ),
				(string) ( $stay['source'] ?? '' ),
				isset( $stay['supplier'] ) ? $stay['supplier'] : null,
				isset( $stay['order_ref'] ) ? $stay['order_ref'] : null,
				isset( $stay['landed_cost'] ) && null !== $stay['landed_cost'] ? (float) $stay['landed_cost'] : null,
				(string) ( $stay['notes'] ?? '' ),
				isset( $stay['size_label'] ) ? $stay['size_label'] : null,
				! empty( $stay['needs_id'] ) ? 1 : 0,
			);
		}
		return $out;
	}

	/**
	 * A ratio / money figure for a sheet: a float, or null when undefined.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function rate( $value ) {
		return null === $value || ! is_numeric( $value ) ? null : (float) $value;
	}
}
