<?php
/**
 * Clean Room admin — the workbook tabs of the Room Board page: Arrivals
 * (the supplier orders list and the order editor with the sheet's
 * costing and the check-in that creates the stays), Species (the species
 * master with inline edit and the product link) and Stats (the yearly
 * figures, weighted and as the spreadsheet averaged them), their AJAX,
 * and the XLSX / CSV exports of orders, species, stats and stays.
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
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_cleanroom_order', array( $this, 'ajax_order' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_species', array( $this, 'ajax_species' ) );
		add_action( 'admin_post_fishotel_cleanroom_export', array( $this, 'download_export' ) );
		add_action( 'admin_post_' . self::SALE_DATE_ACTION, array( $this, 'handle_sale_date' ) );
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
	 * @param string $what   orders|species|stats|stays.
	 * @param string $format xlsx|csv.
	 * @param array  $extra  year, closed.
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
	public static function flash( $message ) {
		set_transient( self::FLASH_TRANSIENT . (int) get_current_user_id(), (string) $message, 2 * MINUTE_IN_SECONDS );
	}

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
		return is_string( $message ) ? $message : '';
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
			$years = $store->order_years();
			$year  = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : ( $years ? (int) $years[0] : (int) substr( $today, 0, 4 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stats = self::stats_for_year( $store, $year );
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

		// Arrivals: the editor when an order is named, else the list.
		$which = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'new' === $which || absint( $which ) > 0 ) {
			$order_id = 'new' === $which ? 0 : absint( $which );
			$order    = $order_id > 0 ? $store->order( $order_id ) : null;
			if ( $order_id > 0 && ! $order ) {
				echo '<div class="wrap fishotel-misc-wrap fh-cr-wrap"><div class="fishotel-misc-notice fishotel-misc-notice--error">' . esc_html__( 'That order no longer exists.', 'fishotel-misc-plugin' ) . '</div></div>';
				return;
			}
			$order         = $order ? self::resolve_order( $store, $order ) : self::blank_order( $store, $today );
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
			$can_checkin = self::can_check_in( $order );
			$is_new      = 0 === (int) $order['id'];
			$list_url    = Admin::page_url( 'arrivals' );
			$batch       = array();
			if ( ! $is_new ) {
				$batch_stays = $store->open_stays( array( 'order_id' => (int) $order['id'] ), $today, $settings );
				$batch       = self::batch_sale_panel( $order, $batch_stays, self::product_titles( $store, array_column( $batch_stays, 'product_id' ) ), $settings );
			}

			include Clean_Room::path() . 'views/order.php';
			return;
		}

		$years = $store->order_years();
		$year  = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : ( $years ? (int) $years[0] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = array();
		$counts = $store->batch_sale_counts();
		foreach ( self::resolved_orders( $store, $year > 0 ? array( 'year' => $year ) : array() ) as $order ) {
			$rows[] = array_merge(
				$order,
				array(
					'summary' => Orders::summary( $order ),
					'on_sale' => self::on_sale_cell( $order, isset( $counts[ (int) $order['id'] ] ) ? $counts[ (int) $order['id'] ] : array() ),
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
	 * Orders with their lines resolved (live figures, costing).
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
		$figures = $stay_ids ? $store->stay_figures( $stay_ids ) : array();

		$out = array();
		foreach ( $orders as $order ) {
			$order['lines'] = isset( $lines[ (int) $order['id'] ] ) ? $lines[ (int) $order['id'] ] : array();
			$out[]          = Orders::resolve( $order, $figures );
		}
		return $out;
	}

	/**
	 * One order with its lines resolved.
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
		return Orders::resolve( $order, $stay_ids ? $store->stay_figures( $stay_ids ) : array() );
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
	 * date applies to, and the groups that have their own date (product,
	 * tank, date, the product's edit link).
	 *
	 * @param array   $order    The order (id, ref, sale_date, received_date).
	 * @param array[] $stays    Its open stays (Store::open_stays( order_id )).
	 * @param array   $titles   Product titles by id.
	 * @param array   $settings Settings.
	 * @return array{date: string, suggested: string, open: int, applies: int, own: array[], nonce_action: string}
	 */
	public static function batch_sale_panel( array $order, array $stays, array $titles, array $settings ) {
		$own     = array();
		$applies = 0;
		foreach ( $stays as $stay ) {
			if ( 'closed' === (string) ( $stay['status'] ?? '' ) ) {
				continue;
			}
			if ( ! Replay::is_date( $stay['sale_ready_override'] ?? null ) ) {
				$applies++;
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
			);
		}

		return array(
			'date'         => Replay::is_date( $order['sale_date'] ?? null ) ? (string) $order['sale_date'] : '',
			'suggested'    => self::suggested_batch_date( $order, $stays, $settings ),
			'open'         => $applies + count( $own ),
			'applies'      => $applies,
			'own'          => $own,
			'nonce_action' => self::NONCE_SALE_DATE . (int) ( $order['id'] ?? 0 ),
		);
	}

	/**
	 * The line under the "Goes on sale" picker (pure): how many groups
	 * still in tanks the batch's date applies to, and whether they are for
	 * sale yet.
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
			return sprintf( _n( 'Applies to %1$d group still in tanks — for sale since %2$s.', 'Applies to %1$d groups still in tanks — for sale since %2$s.', $applies, 'fishotel-misc-plugin' ), $applies, $date );
		}
		/* translators: %d: groups */
		return sprintf( _n( 'Applies to %d group still in tanks.', 'Applies to %d groups still in tanks.', $applies, 'fishotel-misc-plugin' ), $applies );
	}

	/**
	 * The Arrivals list's "On sale" cell (pure): the batch's date or "not
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
		$c     = (array) ( $order['costing'] ?? array() );
		$mode  = (string) ( $c['mode'] ?? 'box' );
		$lines = array();
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			$linked  = ! empty( $line['linked'] );
			$lines[] = array(
				'id'              => (int) ( $line['id'] ?? 0 ),
				'linked'          => $linked,
				'units'           => Costing::num( $line['units'] ?? null, '—', 'box' === $mode ? 2 : 0 ),
				'per_box_used'    => (string) (int) ( $line['per_box_used'] ?? 0 ),
				'landed_total'    => Costing::money( $line['landed_total'] ?? null ),
				'landed_per_fish' => Costing::money( $line['landed_per_fish'] ?? null ),
				'suggested_price' => Costing::num( $line['suggested_price'] ?? null, '—', 0 ),
				'profit'          => Costing::money( $line['profit'] ?? null ),
				'margin'          => Costing::pct( $line['margin'] ?? null ),
				'worth_it'        => ! empty( $line['worth_it'] ) ? __( 'Yes', 'fishotel-misc-plugin' ) : __( 'No', 'fishotel-misc-plugin' ),
				'worth_flag'      => ! empty( $line['worth_it'] ),
				'revenue'         => Costing::money( $line['revenue'] ?? null ),
				'doa_cost'        => Costing::money( $line['doa_cost'] ?? null ),
				'live'            => $linked && null !== ( $line['live'] ?? null ) ? (string) (int) $line['live'] : '—',
				'sold'            => $linked && null !== ( $line['sold'] ?? null ) ? (string) (int) $line['sold'] : '—',
				'survived'        => null !== ( $line['survived'] ?? null ) ? (string) (int) $line['survived'] : '—',
				'doa'             => (string) (int) ( $line['doa'] ?? 0 ),
			);
		}

		$up_down = (float) ( $c['up_down'] ?? 0 );

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
				'ratio'             => Costing::num( $c['ratio'] ?? null ),
				'fill_rate'         => Costing::pct( $c['fill_rate'] ?? null ),
				'survival_rate'     => Costing::pct( $c['survival_rate'] ?? null ),
				'doa_rate'          => Costing::pct( $c['doa_rate'] ?? null ),
				'doa_cost_sheet'    => Costing::money( $c['doa_cost_sheet'] ?? null ),
				'doa_cost_per_fish' => Costing::money( $c['doa_cost_per_fish'] ?? null ),
			),
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
	 * (the stays), delete (a draft without stays).
	 */
	public function ajax_order() {
		self::guard();

		$raw      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
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
					$result = array(
						'message'  => __( 'Order deleted.', 'fishotel-misc-plugin' ),
						'redirect' => Admin::page_url( 'arrivals' ),
					);
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
	 * Save an order from the editor's payload: the header, then the lines
	 * (upsert + delete), then the landed-total cache, then the figures.
	 *
	 * @param Store  $store Store.
	 * @param array  $raw   Unslashed payload.
	 * @param int    $id    Order id (0 = new).
	 * @param string $today Site date.
	 * @return array message, order_id, is_new, redirect (new), line_ids, figures, can_checkin — or error.
	 */
	public static function save_order( $store, array $raw, $id, $today ) {
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

		$saved_id = $store->save_order( $header );
		if ( ! $saved_id ) {
			return array( 'error' => __( 'The order could not be saved.', 'fishotel-misc-plugin' ) );
		}

		$lines = Orders::normalize_lines( isset( $raw['lines'] ) && is_array( $raw['lines'] ) ? $raw['lines'] : array() );
		$saved = $store->save_order_lines( $saved_id, $lines );
		if ( false === $saved ) {
			return array( 'error' => __( 'The lines could not be saved.', 'fishotel-misc-plugin' ) );
		}

		$order = self::resolve_order( $store, $store->order( $saved_id ) );
		self::cache_landed_totals( $store, $order );

		return array(
			'message'  => __( 'Order saved.', 'fishotel-misc-plugin' ),
			'order_id' => (int) $saved_id,
			'is_new'   => 0 === (int) $id,
			'redirect' => 0 === (int) $id ? self::order_url( $saved_id ) : '',
			'ref'      => (string) $order['ref'],
			'status'   => (string) $order['status'],
			'line_ids'    => array_values( array_map( 'intval', array_column( $order['lines'], 'id' ) ) ),
			'figures'     => self::figures( $order ),
			'can_checkin' => self::can_check_in( $order ),
		);
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
					$items = $store->species_suggestions( $term, self::SUGGEST_LIMIT );
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
					wp_send_json_success( array( 'items' => $items ) );
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
					if ( ! $store->species( $id ) ) {
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
							/* translators: %s: product title */
							'message' => $product_id > 0 ? sprintf( __( 'Linked to "%s".', 'fishotel-misc-plugin' ), $title ) : __( 'Product link removed.', 'fishotel-misc-plugin' ),
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
	 * date (type=date, Y-m-d; anything else is refused and nothing
	 * changes) or op "clear". A real change is written with
	 * Store::update_order_fields() and logged through fishotel_log — who,
	 * the order and old → new; an unchanged date writes nothing.
	 *
	 * @param object $store Store (or the tests' stand-in).
	 * @param array  $raw   Unslashed POST: order_id, _wpnonce, op (save|clear), sale_date.
	 * @return array{status: int, ok: bool, message: string, redirect: string, old: string|null, new: string|null}
	 */
	public static function sale_date_request( $store, array $raw ) {
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
			sprintf( 'Sale date of supplier order %1$s changed by %2$s: %3$s → %4$s', $ref, $login, null !== $old ? $old : 'not set', null !== $new ? $new : 'not set' ),
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
	 * the stats of a year, or the stays. Without the zip extension an
	 * XLSX request falls back to CSV with a notice on the next page.
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
				wp_die( esc_html__( 'Unknown export.', 'fishotel-misc-plugin' ), '', array( 'response' => 400 ) );
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
	 * figures) and a "Lines" sheet (one row per line) — pure.
	 *
	 * @param array[] $orders Resolved orders.
	 * @return array{Orders: array[], Lines: array[]}
	 */
	public static function orders_sheets( array $orders ) {
		$head  = array( 'id', 'ref', 'supplier', 'origin', 'order_date', 'received_date', 'sale_date', 'status', 'cost_mode', 'boxes', 'tariffs', 'domestic_flight', 'import_fees', 'credit', 'markup', 'worth_threshold', 'schedule', 'building', 'lines', 'ordered', 'received', 'survived', 'doa', 'fill_rate', 'survival_rate', 'doa_rate', 'fee_rate', 'total', 'grand_total', 'revenue', 'up_down', 'ratio', 'doa_cost_sheet', 'doa_cost_per_fish', 'notes' );
		$lhead = array( 'order_ref', 'line_id', 'species', 'sci_name', 'species_id', 'group', 'ordered', 'received', 'per_box', 'bags', 'units', 'unit_price', 'landed_total', 'landed_per_fish', 'suggested_price', 'worth', 'profit', 'margin', 'worth_it', 'survived', 'doa', 'revenue', 'doa_cost', 'live', 'sold', 'stay_id', 'tank', 'notes' );
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
				(float) ( $c['up_down'] ?? 0 ),
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
					$line['worth'],
					self::rate( $line['profit'] ?? null ),
					self::rate( $line['margin'] ?? null ),
					! empty( $line['worth_it'] ),
					isset( $line['survived'] ) ? $line['survived'] : null,
					(int) ( $line['doa'] ?? 0 ),
					self::rate( $line['revenue'] ?? null ),
					self::rate( $line['doa_cost'] ?? null ),
					isset( $line['live'] ) ? $line['live'] : null,
					isset( $line['sold'] ) ? $line['sold'] : null,
					$line['stay_id'],
					$line['tank_number'],
					(string) $line['notes'],
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
	 * By supplier, By origin, By group.
	 *
	 * @param array $stats Stats::build().
	 * @param int   $year  The year (0 = all).
	 * @return array<string, array[]>
	 */
	public static function stats_sheets( array $stats, $year = 0 ) {
		$orders = array( array( 'ref', 'supplier', 'origin', 'year', 'order_date', 'received_date', 'status', 'cost_mode', 'lines', 'ordered', 'received', 'survived', 'doa', 'fill_rate', 'survival_rate', 'doa_rate', 'total', 'grand_total', 'revenue', 'up_down', 'ratio' ) );
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
		);

		$group_sheet = static function ( array $groups, $label ) {
			$rows = array( array( $label, 'orders', 'lines', 'ordered', 'received', 'survived', 'doa', 'fill_rate', 'survival_rate', 'doa_rate', 'total', 'revenue', 'up_down', 'ratio' ) );
			foreach ( $groups as $g ) {
				$rows[] = array(
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
			}
			return $rows;
		};

		return array(
			'Orders'      => $orders,
			'Totals'      => $totals,
			'By supplier' => $group_sheet( (array) $stats['by_supplier'], 'supplier' ),
			'By origin'   => $group_sheet( (array) $stats['by_origin'], 'origin' ),
			'By group'    => $group_sheet( (array) $stats['by_group'], 'species_group' ),
		);
	}

	/**
	 * The stays sheet (pure): the CSV's columns, typed; sale_ready_date is
	 * the effective sale date (the stay's own, else its batch's), empty
	 * when none is set.
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
				(string) ( $stay['state_label'] ?? $stay['status'] ?? '' ),
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
