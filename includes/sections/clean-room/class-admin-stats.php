<?php
/**
 * Clean Room admin — the four further views of the Stats tab and their
 * exports:
 *
 *   Compare years    two or more years side by side (Stats::compare());
 *   Species          one row per fish and year, sortable, with a search
 *                    (Stats::by_species());
 *   Prices to raise  the fish priced under what their latest supplier
 *                    order suggests (Stats_Prices) — read-only: no price
 *                    is ever changed from here;
 *   Real sales       what customers actually paid per fish and year, from
 *                    WooCommerce's paid orders, beside the estimates and
 *                    the landed cost (Stats_Sales).
 *
 * The first three and every "estimate" on the tab are what the
 * spreadsheet computed: survivors × the FisHotel price typed on the
 * supplier order. Real sales is the only ACTUAL figure; every view says
 * which is which.
 *
 * Real sales reads WooCommerce's order tables through the Store, a year
 * at a time, in bounded pages, and keeps each year's ledger in ONE
 * transient (Stats_Sales::TRANSIENT) for twelve hours — dropped when an
 * order changes status or WooCommerce updates its order tables
 * (Stats_Sales::flush(), hooked in Clean_Room::boot()) and by the
 * Refresh button. A read that fails part-way is said on the screen and
 * never cached. Nothing here runs outside the Stats tab; which fish a
 * sale is gets decided on every view from the species list as it is now,
 * so linking a species to its product shows at once, without a refresh.
 *
 * One read of every supplier order stops at the store's newest 500:
 * Compare years and the landed costs of the year Real sales shows read
 * their years one at a time, as "By year" does; Prices to raise keeps to
 * that one read and says so when it came back full.
 *
 * The Invoices section, while it is switched on, is asked for the lines
 * of the invoices an order paid (fishotel_invoices_for_order() — the
 * function is declared whenever this plugin is active, so the section
 * itself is asked whether it is on; or the filter
 * fishotel_cleanroom_sales_invoice_lines) — without it invoice fish are
 * matched by their exact name only, and the view says so.
 *
 * Two writes, both admin-post with their own nonce: Refresh
 * (manage_woocommerce) and the list of order statuses that count as a
 * sale (the Settings tab: manage_options, like every setting of the
 * section). Neither touches an order, a product or a price.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Stats
 */
class Admin_Stats {

	/**
	 * The views of the Stats tab ('year' is the tab as it was).
	 *
	 * @var string[]
	 */
	const VIEWS = array( 'year', 'compare', 'species', 'prices', 'sales' );

	/**
	 * The exports this class answers (Admin_Workbook::download_export()'s
	 * "what").
	 *
	 * @var string[]
	 */
	const EXPORTS = array( 'compare', 'species-years', 'prices', 'sales' );

	/**
	 * The most years one comparison shows.
	 *
	 * @var int
	 */
	const COMPARE_MAX = 6;

	/**
	 * admin-post actions and their nonces.
	 */
	const REFRESH_ACTION  = 'fishotel_cleanroom_sales_refresh';
	const STATUSES_ACTION = 'fishotel_cleanroom_sales_statuses';

	/**
	 * The filter another section (or a test) answers with the invoices an
	 * order paid, before fishotel_invoices_for_order() is asked:
	 * apply_filters( …, null, $order_id ) — in exactly the shape that
	 * function answers (Stats_Sales::invoice_parts()).
	 *
	 * @var string
	 */
	const INVOICE_FILTER = 'fishotel_cleanroom_sales_invoice_lines';

	/**
	 * The columns the Species view sorts by, and the Real sales view.
	 *
	 * @var string[]
	 */
	const SPECIES_SORTS = array( 'name', 'group', 'year', 'orders', 'ordered', 'received', 'doa', 'doa_rate', 'survived', 'survival_rate', 'total', 'landed_per_fish', 'revenue', 'profit', 'margin' );
	const SALES_SORTS   = array( 'name', 'group', 'units', 'revenue', 'avg_paid', 'list_price', 'est_revenue', 'landed_per_fish', 'cost_sold', 'profit' );

	/* ============================================================== */
	/*  Views, URLs                                                    */
	/* ============================================================== */

	/**
	 * The view a request asks for.
	 *
	 * @param array $request $_GET-like.
	 * @return string One of VIEWS.
	 */
	public static function view_from_request( $request ) {
		$view = is_array( $request ) && isset( $request['view'] ) && is_scalar( $request['view'] ) ? sanitize_key( (string) $request['view'] ) : 'year';
		return in_array( $view, self::VIEWS, true ) ? $view : 'year';
	}

	/**
	 * The URL of a view.
	 *
	 * @param string $view  One of VIEWS.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function view_url( $view, array $extra = array() ) {
		return Admin::page_url( 'stats', array_merge( 'year' === $view ? array() : array( 'view' => $view ), $extra ) );
	}

	/**
	 * The strip under the tabs: every view with its label and whether it
	 * is the one shown.
	 *
	 * @param string $current The view shown.
	 * @return array[] key, label, url, active.
	 */
	public static function nav( $current ) {
		$labels = array(
			'year'    => __( 'By year', 'fishotel-misc-plugin' ),
			'compare' => __( 'Compare years', 'fishotel-misc-plugin' ),
			'species' => __( 'Species', 'fishotel-misc-plugin' ),
			'prices'  => __( 'Prices to raise', 'fishotel-misc-plugin' ),
			'sales'   => __( 'Real sales', 'fishotel-misc-plugin' ),
		);
		$out    = array();
		foreach ( $labels as $key => $label ) {
			$out[] = array(
				'key'    => $key,
				'label'  => $label,
				'url'    => self::view_url( $key ),
				'active' => $key === $current,
			);
		}
		return $out;
	}

	/**
	 * Render one of the further views. The context is what
	 * Admin::render_page() gathered for every tab.
	 *
	 * @param string $view  compare|species|prices|sales.
	 * @param array  $ctx   store, settings, today, tables_ok, can_settings.
	 * @param string $flash A one-time notice.
	 */
	public static function render( $view, array $ctx, $flash = '' ) {
		$store        = $ctx['store'];
		$today        = (string) $ctx['today'];
		$tables_ok    = ! empty( $ctx['tables_ok'] );
		$can_settings = ! empty( $ctx['can_settings'] );
		$tab          = 'stats';
		$request      = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.

		switch ( $view ) {
			case 'compare':
				$data = self::compare_data( $store, self::years_from_request( $request ) );
				break;
			case 'species':
				$data = self::species_data( $store, self::species_filters( $request, $store, $today ) );
				break;
			case 'prices':
				$data = self::prices_data( $store, self::prices_filters( $request ) );
				break;
			default:
				$view = 'sales';
				$data = self::sales_data( $store, self::sales_filters( $request ) );
		}

		include Clean_Room::path() . 'views/stats-' . $view . '.php';
	}

	/* ============================================================== */
	/*  Requests → filters                                             */
	/* ============================================================== */

	/**
	 * A text filter from a request: unslashed already, stripped, cut.
	 *
	 * @param array  $request Request.
	 * @param string $key     Key.
	 * @param int    $max     Longest value kept.
	 * @return string
	 */
	private static function text( array $request, $key, $max = 120 ) {
		return isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ? substr( sanitize_text_field( (string) $request[ $key ] ), 0, $max ) : '';
	}

	/**
	 * A sort column and its direction from a request, against the columns
	 * a view allows ('' = the view's own order).
	 *
	 * @param array    $request Request.
	 * @param string[] $allowed Columns.
	 * @return array{0: string, 1: string}
	 */
	private static function sort( array $request, array $allowed ) {
		$sort = isset( $request['sort'] ) && is_scalar( $request['sort'] ) ? sanitize_key( (string) $request['sort'] ) : '';
		$dir  = isset( $request['dir'] ) && is_scalar( $request['dir'] ) && 'asc' === sanitize_key( (string) $request['dir'] ) ? 'asc' : 'desc';
		return array( in_array( $sort, $allowed, true ) ? $sort : '', $dir );
	}

	/**
	 * The years a request names: years[]=2025&years[]=2026, or
	 * years=2025,2026.
	 *
	 * @param array $request Request.
	 * @return int[]
	 */
	public static function years_from_request( $request ) {
		$raw = is_array( $request ) && isset( $request['years'] ) ? $request['years'] : array();
		if ( is_scalar( $raw ) ) {
			$raw = explode( ',', (string) $raw );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $year ) {
			$year = is_scalar( $year ) ? absint( $year ) : 0;
			if ( $year >= 1900 && $year <= 9998 && ! in_array( $year, $out, true ) ) {
				$out[] = $year;
			}
		}
		return $out;
	}

	/**
	 * The Species view's filters: the year (0 = every year; without one in
	 * the request, the latest year that has orders), the search, the sort.
	 *
	 * @param array  $request Request.
	 * @param object $store   Store.
	 * @param string $today   Site date.
	 * @return array{year: int, search: string, sort: string, dir: string}
	 */
	public static function species_filters( array $request, $store, $today ) {
		list( $sort, $dir ) = self::sort( $request, self::SPECIES_SORTS );
		if ( isset( $request['year'] ) && is_scalar( $request['year'] ) ) {
			$year = absint( $request['year'] );
		} else {
			$years = (array) $store->order_years();
			$year  = $years ? (int) $years[0] : (int) substr( (string) $today, 0, 4 );
		}
		return array(
			'year'   => $year,
			'search' => self::text( $request, 's' ),
			'sort'   => $sort,
			'dir'    => $dir,
		);
	}

	/**
	 * The Prices view's filters.
	 *
	 * @param array $request Request.
	 * @return array{supplier: string, group: string, all: bool}
	 */
	public static function prices_filters( array $request ) {
		return array(
			'supplier' => self::text( $request, 'supplier', 80 ),
			'group'    => self::text( $request, 'group', 60 ),
			'all'      => isset( $request['all'] ) && is_scalar( $request['all'] ) && '1' === (string) $request['all'],
		);
	}

	/**
	 * The Real sales view's filters (year 0 = the latest year with sales).
	 *
	 * @param array $request Request.
	 * @return array{year: int, search: string, sort: string, dir: string}
	 */
	public static function sales_filters( array $request ) {
		list( $sort, $dir ) = self::sort( $request, self::SALES_SORTS );
		return array(
			'year'   => isset( $request['year'] ) && is_scalar( $request['year'] ) ? absint( $request['year'] ) : 0,
			'search' => self::text( $request, 's' ),
			'sort'   => $sort,
			'dir'    => $dir,
		);
	}

	/**
	 * Whether a read of every order came back full: the store hands out
	 * its newest ORDERS_LIMIT orders at most, so a full read may have left
	 * older orders out. A store without that limit is never full.
	 *
	 * @param object  $store  Store.
	 * @param array[] $orders What Store::orders() returned without a year.
	 * @return bool
	 */
	public static function orders_window_full( $store, array $orders ) {
		$limit = self::orders_limit( $store );
		return $limit > 0 && count( $orders ) >= $limit;
	}

	/**
	 * How many orders one read of the store returns at most (0: no limit).
	 *
	 * @param object $store Store.
	 * @return int
	 */
	public static function orders_limit( $store ) {
		$name = is_object( $store ) ? get_class( $store ) . '::ORDERS_LIMIT' : '';
		return '' !== $name && defined( $name ) ? max( 0, (int) constant( $name ) ) : 0;
	}

	/* ============================================================== */
	/*  Compare years                                                  */
	/* ============================================================== */

	/**
	 * The comparison of the years picked — or, with fewer than two picked
	 * that have orders, of the last two years that have orders.
	 *
	 * @param object $store  Store.
	 * @param int[]  $picked The years asked for.
	 * @return array{years: int[], picked: int[], defaulted: bool, compare: array, exports: array}
	 */
	public static function compare_data( $store, array $picked ) {
		// The years come from the store and each one is read by itself, exactly as "By year" reads it:
		// one read of every order stops at the newest Store::ORDERS_LIMIT and would lose the old years.
		$years   = array_values( array_map( 'intval', (array) $store->order_years() ) );
		$chosen  = array_values( array_intersect( $years, array_map( 'intval', $picked ) ) );
		$default = count( $chosen ) < 2;
		if ( $default ) {
			$chosen = array_slice( $years, 0, 2 );
		}
		$chosen = array_slice( $chosen, 0, self::COMPARE_MAX );
		sort( $chosen );

		$stats = array();
		foreach ( $chosen as $year ) {
			$stats[ $year ] = Admin_Workbook::stats_for_year( $store, $year );
		}

		$args = array( 'years' => implode( ',', $chosen ) );

		return array(
			'years'     => $years,
			'picked'    => $chosen,
			'defaulted' => $default && (bool) $picked,
			'compare'   => Stats::compare( $stats ),
			'exports'   => array(
				'xlsx' => Admin_Workbook::export_url( 'compare', 'xlsx', $args ),
				'csv'  => Admin_Workbook::export_url( 'compare', 'csv', $args ),
			),
		);
	}

	/**
	 * The headings of the compared figures, in column order.
	 *
	 * @return array<string, string>
	 */
	public static function figure_labels() {
		return array(
			'orders'        => __( 'Orders', 'fishotel-misc-plugin' ),
			'ordered'       => __( 'Ordered', 'fishotel-misc-plugin' ),
			'received'      => __( 'Received', 'fishotel-misc-plugin' ),
			'fill_rate'     => __( 'Fill', 'fishotel-misc-plugin' ),
			'survived'      => __( 'Survived', 'fishotel-misc-plugin' ),
			'survival_rate' => __( 'Survival', 'fishotel-misc-plugin' ),
			'doa'           => __( 'DOA', 'fishotel-misc-plugin' ),
			'doa_rate'      => __( 'DOA rate', 'fishotel-misc-plugin' ),
			'total'         => __( 'Landed', 'fishotel-misc-plugin' ),
			'revenue'       => __( 'Est. revenue', 'fishotel-misc-plugin' ),
			'up_down'       => __( 'Est. up / down', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * One compared figure as the screen prints it (pure): a dash when the
	 * row has none that year.
	 *
	 * @param int|float|null $value The figure.
	 * @param string         $kind  count|rate|money|signed.
	 * @return string
	 */
	public static function figure_text( $value, $kind ) {
		if ( null === $value ) {
			return '—';
		}
		if ( 'count' === $kind ) {
			return (string) (int) $value;
		}
		if ( 'rate' === $kind ) {
			return Costing::pct( $value );
		}
		return ( 'signed' === $kind && $value > 0 ? '+' : '' ) . Costing::money( $value );
	}

	/**
	 * A change between two years as the screen prints it (pure): "+3
	 * (+12.5%)", "+1,200.00 (+8.0%)", "+3.2 pts", "-150.00" — a dash when
	 * either year has no figure.
	 *
	 * @param array  $change abs, pct.
	 * @param string $kind   count|rate|money|signed.
	 * @return string
	 */
	public static function change_text( array $change, $kind ) {
		$abs = isset( $change['abs'] ) ? $change['abs'] : null;
		if ( null === $abs ) {
			return '—';
		}
		$plus = $abs > 0 ? '+' : '';

		if ( 'rate' === $kind ) {
			$points = round( (float) $abs, 1 );
			/* translators: %s: a change in percentage points, signed */
			return sprintf( __( '%s pts', 'fishotel-misc-plugin' ), ( $points > 0 ? '+' : '' ) . number_format( 0.0 === $points ? 0.0 : $points, 1, '.', ',' ) );
		}

		$text = 'count' === $kind ? $plus . (int) $abs : $plus . Costing::money( $abs );
		$pct  = isset( $change['pct'] ) ? $change['pct'] : null;
		if ( null !== $pct ) {
			$text .= ' (' . ( $pct > 0 ? '+' : '' ) . Costing::pct( $pct ) . ')';
		}
		return $text;
	}

	/**
	 * Whether a change is good news, bad news or neither (pure): more fish
	 * surviving, a better fill and more money in are good; more DOA is
	 * bad; ordering or landing more is neither.
	 *
	 * @param string $key    Figure.
	 * @param array  $change abs.
	 * @return string fh-cr-up | fh-cr-down | ''.
	 */
	public static function change_class( $key, array $change ) {
		$abs = isset( $change['abs'] ) ? $change['abs'] : null;
		if ( null === $abs || abs( (float) $abs ) < 1e-9 ) {
			return '';
		}
		if ( in_array( $key, array( 'fill_rate', 'survived', 'survival_rate', 'revenue', 'up_down' ), true ) ) {
			return $abs > 0 ? 'fh-cr-up' : 'fh-cr-down';
		}
		if ( in_array( $key, array( 'doa', 'doa_rate' ), true ) ) {
			return $abs > 0 ? 'fh-cr-down' : 'fh-cr-up';
		}
		return '';
	}

	/**
	 * The comparison as sheets (pure): one per section — Totals, By
	 * supplier, By origin, By group — or, for a CSV, all four in one table
	 * with a leading "section" column. Per name: a row per year, then per
	 * pair of consecutive years a "change" row (absolute; a rate's in
	 * points) and a "change %" row (a fraction, where a percent is
	 * defined). A year the name has no order in is a row of empty cells.
	 *
	 * @param array $compare Stats::compare().
	 * @param bool  $one     One table with a "section" column (the CSV).
	 * @return array<string, array[]>
	 */
	public static function compare_sheets( array $compare, $one = false ) {
		$years    = (array) ( $compare['years'] ?? array() );
		$figures  = array_keys( Stats::COMPARE_FIGURES );
		$sections = array(
			'Totals'      => array( $compare['totals'] ?? array() ),
			'By supplier' => (array) ( $compare['by_supplier'] ?? array() ),
			'By origin'   => (array) ( $compare['by_origin'] ?? array() ),
			'By group'    => (array) ( $compare['by_group'] ?? array() ),
		);
		$firsts   = array(
			'Totals'      => 'name',
			'By supplier' => 'supplier',
			'By origin'   => 'origin',
			'By group'    => 'species_group',
		);

		$sheets = array();
		$all    = array( array_merge( array( 'section', 'name', 'row' ), $figures ) );
		foreach ( $sections as $title => $rows ) {
			$sheet = array( array_merge( array( $firsts[ $title ], 'row' ), $figures ) );
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['values'] ) ) {
					continue;
				}
				$name  = 'Totals' === $title ? 'all' : (string) $row['name'];
				$lines = array();
				foreach ( $years as $position => $year ) {
					$cells = array( (string) $year );
					foreach ( $figures as $key ) {
						$cells[] = $row['values'][ $key ][ $year ];
					}
					$lines[] = $cells;

					if ( $position < 1 ) {
						continue;
					}
					$label = $years[ $position - 1 ] . ' to ' . $year;
					$abs   = array( $label . ' change' );
					$pct   = array( $label . ' change %' );
					foreach ( $figures as $key ) {
						$change = $row['changes'][ $key ][ $position - 1 ];
						$abs[]  = $change['abs'];
						$pct[]  = $change['pct'];
					}
					$lines[] = $abs;
					$lines[] = $pct;
				}
				foreach ( $lines as $cells ) {
					$sheet[] = array_merge( array( $name ), $cells );
					$all[]   = array_merge( array( $title, $name ), $cells );
				}
			}
			$sheets[ $title ] = $sheet;
		}

		return $one ? array( 'Compare' => $all ) : $sheets;
	}

	/* ============================================================== */
	/*  Species, per year                                              */
	/* ============================================================== */

	/**
	 * The Species view: every fish of a year (or of every year), searched
	 * and sorted.
	 *
	 * @param object $store   Store.
	 * @param array  $filters year (0 = all), search, sort, dir.
	 * @return array{filters: array, years: int[], rows: array[], totals: array, all: int, year_totals: array, exports: array}
	 */
	public static function species_data( $store, array $filters ) {
		$year    = (int) ( $filters['year'] ?? 0 );
		$orders  = Admin_Workbook::resolved_orders( $store, $year > 0 ? array( 'year' => $year ) : array() );
		// Grouped as "By year" groups the same lines, so the two views add up group by group.
		$all     = Stats::by_species( $orders, (array) $store->species_brief(), Costing::DEFAULT_PER_BOX, true );
		$rows    = Stats::search_rows( $all, (string) ( $filters['search'] ?? '' ) );
		$sort    = (string) ( $filters['sort'] ?? '' );
		$dir     = 'asc' === ( $filters['dir'] ?? '' ) ? 'asc' : 'desc';
		$years   = (array) $store->order_years();
		$filters = array(
			'year'   => $year,
			'search' => (string) ( $filters['search'] ?? '' ),
			'sort'   => $sort,
			'dir'    => $dir,
		);
		if ( '' !== $sort ) {
			$rows = self::sort_species( $rows, $sort, $dir );
		}
		if ( $year > 0 && ! in_array( $year, $years, true ) ) {
			$years[] = $year;
			rsort( $years );
		}

		// The year always rides along: without one the export would fall back to the latest year.
		$args = array_merge(
			array( 'year' => $year ),
			array_filter(
				array(
					's'    => $filters['search'],
					'sort' => $sort,
					'dir'  => '' !== $sort ? $dir : '',
				),
				'strlen'
			)
		);

		return array(
			'filters'     => $filters,
			'years'       => $years,
			'rows'        => $rows,
			'totals'      => Stats::species_totals( $rows ),
			'all'         => count( $all ),
			'year_totals' => Stats::species_totals( $all ),
			'exports'     => array(
				'xlsx' => Admin_Workbook::export_url( 'species-years', 'xlsx', $args ),
				'csv'  => Admin_Workbook::export_url( 'species-years', 'csv', $args ),
			),
		);
	}

	/**
	 * Species rows sorted by a column (pure). A fish without any survivor
	 * count has no survivors figure and no estimate — the screen prints a
	 * dash there — so by those columns it sorts last, not as a 0 or as a
	 * loss of its whole landed cost.
	 *
	 * @param array[] $rows Stats::by_species() rows.
	 * @param string  $sort Column.
	 * @param string  $dir  asc|desc.
	 * @return array[]
	 */
	public static function sort_species( array $rows, $sort, $dir ) {
		if ( ! in_array( $sort, array( 'survived', 'revenue', 'profit', 'margin' ), true ) ) {
			return Stats::sort_rows( $rows, $sort, $dir );
		}
		$keyed = array();
		foreach ( $rows as $row ) {
			$row['_sort'] = empty( $row['survived_known'] ) ? null : ( $row[ $sort ] ?? null );
			$keyed[]      = $row;
		}
		$out = array();
		foreach ( Stats::sort_rows( $keyed, '_sort', $dir ) as $row ) {
			unset( $row['_sort'] );
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The Species view as a sheet (pure, typed cells). The three estimates
	 * are empty for a fish none of whose lines has a survivor count yet.
	 *
	 * @param array[] $rows Stats::by_species() rows.
	 * @return array[]
	 */
	public static function species_rows( array $rows ) {
		$out = array( array( 'species', 'group', 'year', 'orders', 'lines', 'ordered', 'received', 'doa', 'doa_rate', 'survived', 'survival_rate', 'landed_total', 'landed_per_fish', 'est_revenue', 'est_profit', 'est_margin', 'lines_without_survivors', 'species_id' ) );
		foreach ( $rows as $row ) {
			$known = ! empty( $row['survived_known'] ); // Without a survivor count there is nothing to estimate from.
			$out[] = array(
				(string) $row['name'],
				(string) $row['group'],
				$row['year'],
				(int) $row['orders'],
				(int) $row['lines'],
				(int) $row['ordered'],
				(int) $row['received'],
				(int) $row['doa'],
				self::number( $row['doa_rate'] ),
				! empty( $row['survived_known'] ) ? (int) $row['survived'] : null,
				self::number( $row['survival_rate'] ),
				(float) $row['total'],
				self::number( $row['landed_per_fish'] ),
				$known ? (float) $row['revenue'] : null,
				$known ? (float) $row['profit'] : null,
				$known ? self::number( $row['margin'] ) : null,
				(int) $row['unknown_lines'],
				$row['species_id'],
			);
		}
		return $out;
	}

	/* ============================================================== */
	/*  Prices to raise                                                */
	/* ============================================================== */

	/**
	 * The Prices view: every fish with a priced line that received fish,
	 * what its latest order suggests and what FisHotel charges — the ones
	 * priced under the suggestion, or all of them.
	 *
	 * @param object $store   Store.
	 * @param array  $filters supplier, group, all.
	 * @return array{filters: array, rows: array[], counts: array, suppliers: string[], groups: string[], window: int, exports: array}
	 *               window: 0, or the number of newest orders the list rests on when there are more.
	 */
	public static function prices_data( $store, array $filters ) {
		$filters = array(
			'supplier' => trim( (string) ( $filters['supplier'] ?? '' ) ),
			'group'    => trim( (string) ( $filters['group'] ?? '' ) ),
			'all'      => ! empty( $filters['all'] ),
		);
		$species = (array) $store->species_brief();
		// The latest order of a fish and its last three: the newest orders are the ones that matter here,
		// so this view keeps to one read — and says so when that read came back full.
		$orders = Admin_Workbook::resolved_orders( $store, array() );
		$built  = Stats_Prices::build( $orders, $species, self::product_prices( $store, $species ), $filters );

		$args = array_filter(
			array(
				'supplier' => $filters['supplier'],
				'group'    => $filters['group'],
				'all'      => $filters['all'] ? '1' : '',
			),
			'strlen'
		);

		return array(
			'filters'   => $filters,
			'rows'      => Stats_Prices::select( $built['rows'], $filters['all'] ),
			'counts'    => $built['counts'],
			'suppliers' => $built['suppliers'],
			'groups'    => $built['groups'],
			'window'    => self::orders_window_full( $store, $orders ) ? self::orders_limit( $store ) : 0,
			'exports'   => array(
				'xlsx' => Admin_Workbook::export_url( 'prices', 'xlsx', $args ),
				'csv'  => Admin_Workbook::export_url( 'prices', 'csv', $args ),
			),
		);
	}

	/**
	 * The current prices of the products the species list links, in one
	 * read; empty when the store cannot read them.
	 *
	 * @param object  $store   Store.
	 * @param array[] $species Species rows.
	 * @return array
	 */
	public static function product_prices( $store, array $species ) {
		$ids = array();
		foreach ( $species as $row ) {
			if ( (int) ( $row['product_id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $row['product_id'];
			}
		}
		return $ids && method_exists( $store, 'product_prices' ) ? (array) $store->product_prices( $ids ) : array();
	}

	/**
	 * Where a FisHotel price comes from, in words.
	 *
	 * @param string $source variation|product|list|''.
	 * @param bool   $from   Whether it is the lowest of several variation prices.
	 * @return string
	 */
	public static function price_source_label( $source, $from = false ) {
		switch ( $source ) {
			case 'variation':
				return __( 'the variation of that size', 'fishotel-misc-plugin' );
			case 'product':
				return $from ? __( 'the product, lowest variation', 'fishotel-misc-plugin' ) : __( 'the product', 'fishotel-misc-plugin' );
			case 'list':
				return __( 'the species list', 'fishotel-misc-plugin' );
		}
		return __( 'no price on file', 'fishotel-misc-plugin' );
	}

	/**
	 * The Prices view as a sheet (pure, typed cells).
	 *
	 * @param array[] $rows Stats_Prices rows.
	 * @return array[]
	 */
	public static function prices_rows( array $rows ) {
		$out = array( array( 'species', 'group', 'latest_order', 'latest_date', 'supplier', 'landed_per_fish_latest', 'landed_per_fish_avg', 'avg_over_orders', 'markup', 'suggested_price', 'fishotel_price', 'price_source', 'price_is_lowest_variation', 'gap', 'margin', 'under_suggested', 'orders', 'species_id', 'product_id' ) );
		foreach ( $rows as $row ) {
			$out[] = array(
				(string) $row['name'],
				(string) $row['group'],
				(string) $row['latest_ref'],
				'' !== (string) $row['latest_date'] ? (string) $row['latest_date'] : null,
				(string) $row['supplier'],
				(float) $row['landed_latest'],
				(float) $row['landed_avg'],
				(int) $row['avg_orders'],
				(float) $row['markup'],
				(float) $row['suggested'],
				self::number( $row['price'] ),
				(string) $row['price_source'],
				! empty( $row['price_from'] ),
				self::number( $row['gap'] ),
				self::number( $row['margin'] ),
				! empty( $row['under'] ),
				(int) $row['orders'],
				$row['species_id'],
				$row['product_id'],
			);
		}
		return $out;
	}

	/* ============================================================== */
	/*  Real sales                                                     */
	/* ============================================================== */

	/**
	 * Whether something can answer for an order's invoices: the filter,
	 * or the Invoices section — its function is declared whenever this
	 * plugin is active and answers nothing while the section is switched
	 * off, so the section is asked whether it is on. (A year read while it
	 * was off is read again once it is on: ledger().)
	 *
	 * @return bool
	 */
	public static function invoices_available() {
		if ( function_exists( 'has_filter' ) && false !== has_filter( self::INVOICE_FILTER ) ) {
			return true;
		}
		if ( ! function_exists( 'fishotel_invoices_for_order' ) ) {
			return false;
		}
		$section = '\\FisHotel\\Misc\\Sections\\Invoices\\Invoices';
		return ! class_exists( $section, false ) || ! method_exists( $section, 'is_on' ) || true === $section::is_on();
	}

	/**
	 * The lines of the invoices an order paid, as Stats_Sales reads them:
	 * the filter first, then the Invoices section when it is there; null
	 * when nobody answers. Whatever goes wrong over there never breaks
	 * the Stats tab.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array[]|null Stats_Sales::invoice_parts().
	 */
	public static function invoice_lines_for( $order_id ) {
		try {
			$lines = apply_filters( self::INVOICE_FILTER, null, (int) $order_id );
			if ( null === $lines && self::invoices_available() && function_exists( 'fishotel_invoices_for_order' ) ) {
				$lines = fishotel_invoices_for_order( (int) $order_id );
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null === $lines ? null : Stats_Sales::invoice_parts( $lines );
	}

	/**
	 * Read one year of sales from the store: the lookup rows in pages
	 * (at most Stats_Sales::MAX_PAGES of Stats_Sales::PAGE_ROWS — past
	 * that the ledger says "truncated"), the names and invoice flags of
	 * the lines each page points at, the Invoices section's lines for the
	 * orders that hold invoice lines (at most
	 * Stats_Sales::INVOICE_ORDERS_MAX), and the refunds that named no
	 * line. When one of those reads fails (the store answers null, not an
	 * empty list) the ledger holds what was read until then and says
	 * "failed".
	 *
	 * @param object   $store     Store.
	 * @param int      $year      The year.
	 * @param string[] $statuses  The statuses that count.
	 * @param int      $page_rows Rows per read.
	 * @param int      $max_pages Reads at most.
	 * @return array A finished ledger.
	 */
	public static function build_ledger( $store, $year, array $statuses, $page_rows = Stats_Sales::PAGE_ROWS, $max_pages = Stats_Sales::MAX_PAGES ) {
		$ledger    = Stats_Sales::new_ledger( $year, $statuses );
		$page_rows = max( 1, min( Stats_Sales::PAGE_ROWS, (int) $page_rows ) );
		$max_pages = max( 1, min( Stats_Sales::MAX_PAGES, (int) $max_pages ) );
		$after     = array( 0, 0 );
		$pages     = 0;
		$more      = false;

		$failed    = false;

		do {
			// An empty page is the end of the year; a read that failed (null) is not: the ledger is then
			// marked "failed" — incomplete, said so on the screen, and never cached.
			$rows    = $store->sales_rows( $year, $ledger['statuses'], $after[0], $after[1], $page_rows );
			$details = is_array( $rows ) && $rows ? $store->sales_item_details( Stats_Sales::source_ids( $rows ) ) : array();
			if ( ! is_array( $rows ) || ! is_array( $details ) ) {
				$failed = true;
				$more   = false;
				break;
			}
			if ( ! $rows ) {
				$more = false;
				break;
			}
			Stats_Sales::add_rows( $ledger, $rows, $details );
			$last  = end( $rows );
			$after = array( (int) $last['row_id'], (int) $last['order_id'] ); // The lookup table's key: line id, then order id.
			$more  = count( $rows ) >= $page_rows;
			$pages++;
		} while ( $more && $pages < $max_pages );
		$ledger['truncated'] = $more;

		$parts = array();
		if ( self::invoices_available() ) {
			$orders                         = Stats_Sales::invoice_orders( $ledger );
			$ledger['invoices']['provider'] = true;
			$ledger['invoices']['capped']   = count( $orders ) > Stats_Sales::INVOICE_ORDERS_MAX;
			foreach ( array_slice( $orders, 0, Stats_Sales::INVOICE_ORDERS_MAX ) as $order_id ) {
				$ledger['invoices']['orders']++;
				$found = self::invoice_lines_for( $order_id );
				if ( $found ) {
					$parts[ $order_id ] = $found;
					$ledger['invoices']['answered']++;
				}
			}
		}

		$refunds            = $store->sales_refund_rows( $year, $ledger['statuses'] );
		$ledger             = Stats_Sales::finish( $ledger, $parts, is_array( $refunds ) ? $refunds : array() );
		$ledger['failed']   = $failed || ! is_array( $refunds );
		$ledger['built_at'] = gmdate( 'Y-m-d H:i:s' );

		return $ledger;
	}

	/**
	 * The cache as it is usable now: the stored one when it was built for
	 * this version and these statuses, else an empty one.
	 *
	 * @param string[] $statuses The statuses that count.
	 * @return array{v: int, statuses: string[], years: array, sales_years: int[]|null}
	 */
	private static function cache( array $statuses ) {
		$cache = get_transient( Stats_Sales::TRANSIENT );
		if ( is_array( $cache ) && Stats_Sales::CACHE_VERSION === (int) ( $cache['v'] ?? 0 ) && $statuses === ( $cache['statuses'] ?? null ) && isset( $cache['years'] ) && is_array( $cache['years'] ) ) {
			return $cache;
		}
		return array(
			'v'           => Stats_Sales::CACHE_VERSION,
			'statuses'    => $statuses,
			'years'       => array(),
			'sales_years' => null,
		);
	}

	/**
	 * One year's ledger: the cached one, else read now and cached (the
	 * cache keeps the Stats_Sales::CACHE_YEARS years read last). A cached
	 * year that was read while the Invoices section was not there is read
	 * again once it is (and the other way round): what it knows about the
	 * invoice lines is part of the ledger. A ledger whose reading failed
	 * part-way is handed back but not cached.
	 *
	 * @param object   $store    Store.
	 * @param int      $year     The year.
	 * @param string[] $statuses The statuses that count.
	 * @return array A finished ledger, with 'cached' true when it was not read now.
	 */
	public static function ledger( $store, $year, array $statuses ) {
		$year     = (int) $year;
		$statuses = Stats_Sales::normalize_statuses( $statuses );
		$cache    = self::cache( $statuses );

		if ( isset( $cache['years'][ $year ] ) && is_array( $cache['years'][ $year ] ) && ! empty( $cache['years'][ $year ]['invoices']['provider'] ) === self::invoices_available() ) {
			return array_merge( $cache['years'][ $year ], array( 'cached' => true ) );
		}

		$ledger = self::build_ledger( $store, $year, $statuses );
		if ( ! empty( $ledger['failed'] ) ) {
			// Part of a year must never be served again as the year: the next view reads it afresh.
			return array_merge( $ledger, array( 'cached' => false ) );
		}
		unset( $cache['years'][ $year ] );
		$cache['years'][ $year ] = $ledger;
		while ( count( $cache['years'] ) > Stats_Sales::CACHE_YEARS ) {
			reset( $cache['years'] );
			unset( $cache['years'][ key( $cache['years'] ) ] );
		}
		set_transient( Stats_Sales::TRANSIENT, $cache, Stats_Sales::CACHE_TTL );

		return array_merge( $ledger, array( 'cached' => false ) );
	}

	/**
	 * The years that have a sale in a status that counts (cached with the
	 * ledgers).
	 *
	 * @param object   $store    Store.
	 * @param string[] $statuses The statuses that count.
	 * @return int[]
	 */
	public static function sales_years( $store, array $statuses ) {
		$statuses = Stats_Sales::normalize_statuses( $statuses );
		$cache    = self::cache( $statuses );
		if ( is_array( $cache['sales_years'] ) ) {
			return array_map( 'intval', $cache['sales_years'] );
		}
		$read = $store->sales_years( $statuses );
		if ( ! is_array( $read ) ) {
			return array(); // The read failed: nothing to offer now, and nothing to remember.
		}
		$cache['sales_years'] = array_values( array_map( 'intval', $read ) );
		set_transient( Stats_Sales::TRANSIENT, $cache, Stats_Sales::CACHE_TTL );
		return $cache['sales_years'];
	}

	/**
	 * The Real sales view of one year: the sales matched to the species
	 * list as it is now, beside the supplier orders.
	 *
	 * @param object $store   Store.
	 * @param array  $filters year (0 = the latest with sales), search, sort, dir.
	 * @return array
	 */
	public static function sales_data( $store, array $filters ) {
		$statuses  = Stats_Sales::statuses();
		$available = is_object( $store ) && method_exists( $store, 'sales_tables_exist' ) && $store->sales_tables_exist();
		$species   = (array) $store->species_brief();

		$sales_years = $available ? self::sales_years( $store, $statuses ) : array();
		$years       = array_values( array_unique( array_merge( $sales_years, array_map( 'intval', (array) $store->order_years() ) ) ) );
		rsort( $years );
		$year = (int) ( $filters['year'] ?? 0 );
		if ( $year < 1 ) {
			$year = $sales_years ? (int) $sales_years[0] : ( $years ? (int) $years[0] : (int) substr( Clean_Room::today(), 0, 4 ) );
		}
		if ( ! in_array( $year, $years, true ) ) {
			$years[] = $year;
			rsort( $years );
		}

		// One read of every order: this year's landed costs and, for a fish that sold without one, the last
		// earlier year's. When that read came back full (the store hands out its newest orders only), the
		// year shown is read again by itself, as "By year" reads it, so its costs are complete; what is said
		// about earlier years then rests on the newest orders alone.
		$orders = Admin_Workbook::resolved_orders( $store, array() );
		$landed = Stats::by_species( $orders, $species );
		if ( self::orders_window_full( $store, $orders ) ) {
			$landed = array_merge(
				array_values(
					array_filter(
						$landed,
						static function ( $row ) use ( $year ) {
							return (int) $row['year'] !== $year;
						}
					)
				),
				Stats::by_species( Admin_Workbook::resolved_orders( $store, array( 'year' => $year ) ), $species )
			);
		}

		$ledger = $available ? self::ledger( $store, $year, $statuses ) : array_merge( Stats_Sales::new_ledger( $year, $statuses ), array( 'cached' => false ) );
		$linked = array();
		foreach ( $species as $row ) {
			if ( (int) ( $row['product_id'] ?? 0 ) > 0 ) {
				$linked[] = (int) $row['product_id'];
			}
		}
		$match = Stats_Sales::match(
			$ledger,
			array(
				'species' => $species,
				'titles'  => Admin_Workbook::product_titles( $store, array_merge( $linked, Stats_Sales::product_ids( $ledger ) ) ),
				'aliases' => method_exists( $store, 'aliases' ) ? (array) $store->aliases() : array(),
			)
		);

		$product_prices = self::product_prices( $store, $species );
		$prices         = array();
		foreach ( $species as $row ) {
			$prices[ (int) $row['id'] ] = Stats_Prices::fish_price( $row, $product_prices );
		}

		$all    = Stats_Sales::rows( $year, $match, $species, $landed, $prices );
		$rows   = Stats::search_rows( $all, (string) ( $filters['search'] ?? '' ) );
		$sort   = (string) ( $filters['sort'] ?? '' );
		$dir    = 'asc' === ( $filters['dir'] ?? '' ) ? 'asc' : 'desc';
		$search = (string) ( $filters['search'] ?? '' );
		if ( '' !== $sort ) {
			$rows = Stats::sort_rows( $rows, $sort, $dir );
		}

		// The export is the view as shown: its year, its search, its sort.
		$args = array_merge(
			array( 'year' => $year ),
			array_filter(
				array(
					's'    => $search,
					'sort' => $sort,
					'dir'  => '' !== $sort ? $dir : '',
				),
				'strlen'
			)
		);

		return array(
			'filters'   => array(
				'year'   => $year,
				'search' => $search,
				'sort'   => $sort,
				'dir'    => $dir,
			),
			'available' => $available,
			'years'     => $years,
			'statuses'  => $statuses,
			'labels'    => self::status_labels( $statuses ),
			'ledger'    => $ledger,
			'match'     => $match,
			'rows'      => $rows,
			'all'       => count( $all ),
			'totals'    => Stats_Sales::row_totals( $all ),
			'invoices'  => self::invoices_available(),
			'exports'   => array(
				'xlsx' => Admin_Workbook::export_url( 'sales', 'xlsx', $args ),
				'csv'  => Admin_Workbook::export_url( 'sales', 'csv', $args ),
			),
		);
	}

	/**
	 * Statuses as the screens name them: WooCommerce's label when the
	 * status is registered, else the slug.
	 *
	 * @param string[] $statuses Slugs.
	 * @return string[]
	 */
	public static function status_labels( array $statuses ) {
		$registered = Stats_Sales::registered_statuses();
		$out        = array();
		foreach ( $statuses as $status ) {
			$out[] = isset( $registered[ $status ] ) ? $registered[ $status ] : (string) $status;
		}
		return $out;
	}

	/**
	 * How a sale was matched to its fish, in words.
	 *
	 * @param string $via product|invoice|name|title|alias.
	 * @return string
	 */
	public static function via_label( $via ) {
		switch ( $via ) {
			case 'product':
				return __( 'product link', 'fishotel-misc-plugin' );
			case 'invoice':
				return __( 'invoice line', 'fishotel-misc-plugin' );
			case 'title':
				return __( 'exact product title', 'fishotel-misc-plugin' );
			case 'alias':
				return __( 'learned alias', 'fishotel-misc-plugin' );
		}
		return __( 'exact name', 'fishotel-misc-plugin' );
	}

	/**
	 * The kinds of "not matched", in words: a heading and what would get
	 * them matched.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unmatched_labels() {
		return array(
			'products' => array( __( 'Shop products without a species', 'fishotel-misc-plugin' ), __( 'Link the species to its product on the Species tab (Link product), or give a species the product\'s exact title: its sales then count at once.', 'fishotel-misc-plugin' ) ),
			'names'    => array( __( 'Invoice fish whose name is no species', 'fishotel-misc-plugin' ), __( 'A name typed on an invoice counts only when it is exactly a species name, the title of a species\' linked product, or a learned alias.', 'fishotel-misc-plugin' ) ),
			'invoices' => array( __( 'Invoices kept as one line', 'fishotel-misc-plugin' ), __( 'An invoice that was not itemised into its fish is split only when its lines carry prices that add up to what was paid for it, to the cent.', 'fishotel-misc-plugin' ) . self::backfill_hint() ),
			'other'    => array( __( 'Lines without a product', 'fishotel-misc-plugin' ), __( 'Order lines that name no product and are not invoice fish.', 'fishotel-misc-plugin' ) ),
			'credits'  => array( __( 'Credits inside invoices kept as one line', 'fishotel-misc-plugin' ), __( 'A credit that was part of such an invoice: money off, no fish.', 'fishotel-misc-plugin' ) ),
		);
	}

	/**
	 * Where the fish lines of an old invoice order can be recorded: the
	 * theme's Tools button, when that theme is the one running ('' when it
	 * is not — this section never needs it). The button saves order lines
	 * without touching an order's status, so the hint also says to press
	 * Refresh.
	 *
	 * @return string '' or a sentence with a leading space.
	 */
	public static function backfill_hint() {
		if ( ! class_exists( 'FisHotel_Invoice_Lines' ) || ! method_exists( 'FisHotel_Invoice_Lines', 'handle_backfill' ) ) {
			return '';
		}
		return ' ' . __( 'For orders from before invoices were itemised ("no fish lines recorded on the order"), the theme can record them: FisHotel Theme → Tools → "Add fish lines to past invoice orders" — then press Refresh here.', 'fishotel-misc-plugin' );
	}

	/**
	 * Why an invoice kept as one line was not split, in words.
	 *
	 * @param string $reason One of Stats_Sales::UNSPLIT.
	 * @return string
	 */
	public static function unsplit_label( $reason ) {
		switch ( $reason ) {
			case 'no_lines':
				return __( 'no fish lines recorded on the order', 'fishotel-misc-plugin' );
			case 'quantity':
				return __( 'bought more than once in one line', 'fishotel-misc-plugin' );
			case 'unpriced':
				return __( 'a fish on it has no price', 'fishotel-misc-plugin' );
			case 'mismatch':
				return __( 'its lines do not add up to what was paid', 'fishotel-misc-plugin' );
			case 'credits':
				return __( 'credits', 'fishotel-misc-plugin' );
		}
		return (string) $reason;
	}

	/**
	 * What a "not matched" item is called on the screen and in the sheet.
	 *
	 * @param string $kind products|names|invoices|other|credits.
	 * @param array  $item label (+ gone).
	 * @return string
	 */
	public static function unmatched_item_label( $kind, array $item ) {
		$label = (string) ( $item['label'] ?? '' );
		if ( 'invoices' === $kind || 'credits' === $kind ) {
			return self::unsplit_label( $label );
		}
		if ( 'products' === $kind && ! empty( $item['gone'] ) ) {
			/* translators: %s: "#123", a product id */
			return sprintf( __( '%s (no longer a product)', 'fishotel-misc-plugin' ), $label );
		}
		return $label;
	}

	/**
	 * What the profit cell says for a fish that sold without a landed cost
	 * that year (pure).
	 *
	 * @param array $row Stats_Sales::rows() row.
	 * @return string '' when the row has a profit or did not sell.
	 */
	public static function profit_note( array $row ) {
		if ( empty( $row['no_cost'] ) ) {
			return '';
		}
		/* translators: %d: year */
		$text = sprintf( __( 'no landed cost in %d', 'fishotel-misc-plugin' ), (int) $row['year'] );
		if ( ! empty( $row['earlier'] ) ) {
			/* translators: 1: money per fish, 2: year */
			$text .= ' ' . sprintf( __( '(last landed: %1$s a fish in %2$d)', 'fishotel-misc-plugin' ), Costing::money( $row['earlier']['landed_per_fish'] ), (int) $row['earlier']['year'] );
		}
		return $text;
	}

	/**
	 * The Real sales view as sheets (pure, typed cells): "Sales by
	 * species" and "Not matched" — the kinds with their biggest items,
	 * then the sums that show matched + not matched = every paid line,
	 * and the refunds that named no line.
	 *
	 * A CSV is one table, so it carries both behind a leading "section"
	 * column, under the species sheet's headings plus "order_lines":
	 * "Sales by species" (the fish), "Not matched" (one row per kind: the
	 * kind under "group", "all" under "species"), "Not matched, biggest
	 * items" (the items of those kinds, already counted in them) and
	 * "Sums". Without a search, the revenue of the first two sections adds
	 * up to every paid line.
	 *
	 * @param array $data sales_data() (rows, match, filters, ledger).
	 * @param bool  $one  One table with a "section" column (the CSV).
	 * @return array<string, array[]>
	 */
	public static function sales_sheets( array $data, $one = false ) {
		$head  = array( 'species', 'group', 'year', 'units_sold', 'actual_revenue', 'avg_price_paid', 'list_price', 'list_price_source', 'est_revenue', 'received', 'survived', 'landed_per_fish', 'cost_of_units_sold', 'actual_profit', 'profit_note', 'matched_by', 'species_id' );
		$sales = array( $head );
		$all   = array( array_merge( array( 'section' ), $head, array( 'order_lines' ) ) );
		foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
			$cells = array(
				(string) $row['name'],
				(string) $row['group'],
				(int) $row['year'],
				(int) $row['units'],
				(float) $row['revenue'],
				self::number( $row['avg_paid'] ),
				self::number( $row['list_price'] ),
				(string) $row['price_source'],
				self::number( $row['est_revenue'] ),
				$row['received'],
				$row['survived'],
				self::number( $row['landed_per_fish'] ),
				self::number( $row['cost_sold'] ),
				self::number( $row['profit'] ),
				self::profit_note( $row ),
				implode( ', ', (array) $row['via'] ),
				$row['species_id'],
			);
			$sales[] = $cells;
			$all[]   = array_merge( array( 'Sales by species' ), $cells, array( (int) ( $row['lines'] ?? 0 ) ) );
		}

		$match  = (array) ( $data['match'] ?? array() );
		$totals = (array) ( $match['totals'] ?? array() );
		$labels = self::unmatched_labels();
		$missed = array( array( 'kind', 'item', 'units', 'revenue', 'lines' ) );
		$items  = array();
		foreach ( (array) ( $match['unmatched'] ?? array() ) as $kind => $bucket ) {
			if ( (int) $bucket['lines'] < 1 ) {
				continue;
			}
			$title    = isset( $labels[ $kind ] ) ? $labels[ $kind ][0] : (string) $kind;
			$missed[] = array( $title, 'all', (int) $bucket['units'], (float) $bucket['revenue'], (int) $bucket['lines'] );
			foreach ( (array) $bucket['items'] as $item ) {
				$missed[] = array( $title, self::unmatched_item_label( $kind, $item ), (int) $item['units'], (float) $item['revenue'], (int) $item['lines'] );
				$items[]  = count( $missed ) - 1;
			}
		}
		$foot     = count( $missed );
		$missed[] = array( 'matched to a species', 'all', (int) ( $totals['matched_units'] ?? 0 ), (float) ( $totals['matched_revenue'] ?? 0 ), null );
		$missed[] = array( 'not matched', 'all', (int) ( $totals['unmatched_units'] ?? 0 ), (float) ( $totals['unmatched_revenue'] ?? 0 ), null );
		$missed[] = array( 'every paid line', 'all', (int) ( $totals['units'] ?? 0 ), (float) ( $totals['revenue'] ?? 0 ), null );
		$missed[] = array( 'refunded without naming a line (not taken off any fish)', 'all', null, (float) ( $match['refunds']['amount'] ?? 0 ), (int) ( $match['refunds']['count'] ?? 0 ) );
		if ( ! empty( $data['ledger']['failed'] ) || ! empty( $data['ledger']['truncated'] ) ) {
			$missed[] = array( 'INCOMPLETE: not every order line of the year was read', 'all', null, null, null );
		}

		if ( ! $one ) {
			return array(
				'Sales by species' => $sales,
				'Not matched'      => $missed,
			);
		}

		$year  = isset( $data['filters']['year'] ) ? (int) $data['filters']['year'] : null;
		$blank = array_fill( 0, count( $head ) - 5, null );
		foreach ( $missed as $n => $line ) {
			if ( 0 === $n ) {
				continue;
			}
			$section = $n >= $foot ? 'Sums' : ( in_array( $n, $items, true ) ? 'Not matched, biggest items' : 'Not matched' );
			$all[]   = array_merge( array( $section, (string) $line[1], (string) $line[0], $year, $line[2], $line[3] ), $blank, array( $line[4] ) );
		}

		return array( 'Real sales' => $all );
	}

	/* ============================================================== */
	/*  Exports                                                        */
	/* ============================================================== */

	/**
	 * The sheets of one of this class's exports, for
	 * Admin_Workbook::download_export(): null when "what" is not one of
	 * EXPORTS.
	 *
	 * @param string $what    compare|species-years|prices|sales.
	 * @param string $format  xlsx|csv.
	 * @param object $store   Store.
	 * @param array  $request Unslashed $_GET.
	 * @param string $today   Site date.
	 * @return array{sheets: array<string, array[]>, csv: string}|null
	 */
	public static function export( $what, $format, $store, array $request, $today ) {
		switch ( $what ) {
			case 'compare':
				$data = self::compare_data( $store, self::years_from_request( $request ) );
				return array(
					'sheets' => self::compare_sheets( $data['compare'], 'csv' === $format ),
					'csv'    => 'Compare',
				);
			case 'species-years':
				$data = self::species_data( $store, self::species_filters( $request, $store, $today ) );
				return array(
					'sheets' => array( 'Species by year' => self::species_rows( $data['rows'] ) ),
					'csv'    => 'Species by year',
				);
			case 'prices':
				$data = self::prices_data( $store, self::prices_filters( $request ) );
				return array(
					'sheets' => array( 'Prices to raise' => self::prices_rows( $data['rows'] ) ),
					'csv'    => 'Prices to raise',
				);
			case 'sales':
				$data = self::sales_data( $store, self::sales_filters( $request ) );
				$one  = 'csv' === $format; // A CSV is one table: the fish and the "not matched", by section.
				return array(
					'sheets' => self::sales_sheets( $data, $one ),
					'csv'    => $one ? 'Real sales' : 'Sales by species',
				);
		}
		return null;
	}

	/**
	 * A figure for a sheet: a float, or null when undefined.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function number( $value ) {
		return null === $value || ! is_numeric( $value ) ? null : (float) $value;
	}

	/* ============================================================== */
	/*  Sorting links                                                  */
	/* ============================================================== */

	/**
	 * The link of a sortable column heading: the same view and filters,
	 * sorted by that column — most first on the first click (A to Z for a
	 * text column), the other way round on the next.
	 *
	 * @param string $view    species|sales.
	 * @param array  $filters The view's filters (year, search, sort, dir).
	 * @param string $key     The column.
	 * @param bool   $text    Whether it is a text column.
	 * @return array{url: string, dir: string} dir: '' when the column is not the sort, else asc|desc.
	 */
	public static function sort_link( $view, array $filters, $key, $text = false ) {
		$active = (string) ( $filters['sort'] ?? '' ) === (string) $key;
		$first  = $text ? 'asc' : 'desc';
		$dir    = $active ? ( 'asc' === ( $filters['dir'] ?? '' ) ? 'desc' : 'asc' ) : $first;
		$args   = array(
			'year' => (int) ( $filters['year'] ?? 0 ),
			'sort' => (string) $key,
			'dir'  => $dir,
		);
		if ( '' !== (string) ( $filters['search'] ?? '' ) ) {
			$args['s'] = (string) $filters['search'];
		}
		return array(
			'url' => self::view_url( $view, $args ),
			'dir' => $active ? ( 'asc' === ( $filters['dir'] ?? '' ) ? 'asc' : 'desc' ) : '',
		);
	}

	/* ============================================================== */
	/*  Refresh (admin-post)                                           */
	/* ============================================================== */

	/**
	 * admin-post: forget what was read from WooCommerce, then back to Real
	 * sales, which reads it again.
	 */
	public static function handle_refresh() {
		$outcome = self::refresh_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in refresh_request().
		if ( 403 === $outcome['status'] ) {
			wp_die( esc_html( $outcome['message'] ), '', array( 'response' => 403 ) );
		}
		Admin_Workbook::flash( $outcome['message'] );
		wp_safe_redirect( $outcome['redirect'] );
		exit;
	}

	/**
	 * The Refresh button — the testable half of handle_refresh(): the
	 * capability (manage_woocommerce) and the nonce first, then the cached
	 * ledgers are dropped. Nothing else is written.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, year.
	 * @return array{status: int, ok: bool, message: string, redirect: string}
	 */
	public static function refresh_request( array $raw ) {
		$year = isset( $raw['year'] ) && is_scalar( $raw['year'] ) ? absint( $raw['year'] ) : 0;
		$out  = array(
			'status'   => 403,
			'ok'       => false,
			'message'  => '',
			'redirect' => self::view_url( 'sales', $year > 0 ? array( 'year' => $year ) : array() ),
		);

		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			$out['message'] = __( 'You do not have permission to refresh the sales.', 'fishotel-misc-plugin' );
			return $out;
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::REFRESH_ACTION ) ) {
			$out['message'] = __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		delete_transient( Stats_Sales::TRANSIENT );

		$out['status']  = 200;
		$out['ok']      = true;
		$out['message'] = __( 'Sales read again from WooCommerce.', 'fishotel-misc-plugin' );
		return $out;
	}

	/* ============================================================== */
	/*  Settings → Sales statuses (admin-post)                         */
	/* ============================================================== */

	/**
	 * What the Settings card shows: every registered status (and any saved
	 * one that is no longer registered) with whether it counts, whether it
	 * is suggested, and whether WooCommerce leaves it out of its own
	 * reports; the suggested list, and whether a list was ever saved.
	 *
	 * @return array{rows: array[], saved: bool, suggested: string[], woocommerce: bool}
	 */
	public static function statuses_panel() {
		$registered = Stats_Sales::registered_statuses();
		$current    = Stats_Sales::statuses();
		$suggested  = Stats_Sales::suggested_statuses();
		$excluded   = Stats_Sales::excluded_statuses();
		$stored     = Stats_Sales::normalize_statuses( get_option( Stats_Sales::OPTION, array() ) );

		$rows = array();
		foreach ( $registered as $slug => $label ) {
			$rows[ $slug ] = array(
				'slug'       => $slug,
				'label'      => $label,
				'counts'     => in_array( $slug, $current, true ),
				'suggested'  => in_array( $slug, $suggested, true ),
				'registered' => true,
				'excluded'   => in_array( $slug, $excluded, true ),
			);
		}
		foreach ( array_merge( $current, $suggested ) as $slug ) {
			if ( ! isset( $rows[ $slug ] ) ) {
				$rows[ $slug ] = array(
					'slug'       => $slug,
					'label'      => $slug,
					'counts'     => in_array( $slug, $current, true ),
					'suggested'  => in_array( $slug, $suggested, true ),
					'registered' => false,
					'excluded'   => in_array( $slug, $excluded, true ),
				);
			}
		}

		return array(
			'rows'        => array_values( $rows ),
			'saved'       => (bool) $stored,
			'suggested'   => $suggested,
			'woocommerce' => (bool) $registered,
		);
	}

	/**
	 * admin-post: save the statuses that count, then back to Settings.
	 */
	public static function handle_statuses() {
		$outcome = self::statuses_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in statuses_request().
		if ( 403 === $outcome['status'] ) {
			wp_die( esc_html( $outcome['message'] ), '', array( 'response' => 403 ) );
		}
		Admin_Workbook::flash( $outcome['message'] );
		wp_safe_redirect( $outcome['redirect'] );
		exit;
	}

	/**
	 * The Sales statuses form — the testable half of handle_statuses():
	 * the capability (manage_options, as every setting of the section)
	 * and the nonce first; op "save" stores the ticked statuses (at least
	 * one — an empty list is refused and nothing changes), op "reset"
	 * forgets the saved list so the suggested one applies again. A real
	 * change drops the cached sales, which were read for the old list.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, op (save|reset), statuses[].
	 * @return array{status: int, ok: bool, message: string, redirect: string, statuses: string[]}
	 */
	public static function statuses_request( array $raw ) {
		$out = array(
			'status'   => 403,
			'ok'       => false,
			'message'  => '',
			'redirect' => Admin::page_url( 'settings' ) . '#fishotel-cleanroom-sales-statuses',
			'statuses' => Stats_Sales::statuses(),
		);

		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			$out['message'] = __( 'You do not have permission to change these settings.', 'fishotel-misc-plugin' );
			return $out;
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::STATUSES_ACTION ) ) {
			$out['message'] = __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$op = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : 'save';
		if ( 'reset' === $op ) {
			delete_option( Stats_Sales::OPTION );
			delete_transient( Stats_Sales::TRANSIENT );
			$out['status']   = 200;
			$out['ok']       = true;
			$out['statuses'] = Stats_Sales::statuses();
			/* translators: %s: comma-separated statuses */
			$out['message']  = sprintf( __( 'Sales statuses back to the suggested list: %s.', 'fishotel-misc-plugin' ), implode( ', ', $out['statuses'] ) );
			return $out;
		}

		$list = Stats_Sales::normalize_statuses( isset( $raw['statuses'] ) && is_array( $raw['statuses'] ) ? $raw['statuses'] : array() );
		if ( ! $list ) {
			$out['status']  = 400;
			$out['message'] = __( 'Tick at least one status: with none, no order would count as a sale. Nothing was changed.', 'fishotel-misc-plugin' );
			return $out;
		}

		$known = array_merge( array_keys( Stats_Sales::registered_statuses() ), $out['statuses'], Stats_Sales::suggested_statuses() );
		$list  = array_values( array_intersect( $list, $known ) );
		if ( ! $list ) {
			$out['status']  = 400;
			$out['message'] = __( 'None of those is an order status of this store. Nothing was changed.', 'fishotel-misc-plugin' );
			return $out;
		}

		update_option( Stats_Sales::OPTION, $list, false );
		delete_transient( Stats_Sales::TRANSIENT );

		$out['status']   = 200;
		$out['ok']       = true;
		$out['statuses'] = $list;
		/* translators: %s: comma-separated statuses */
		$out['message']  = sprintf( __( 'Sales statuses saved: %s.', 'fishotel-misc-plugin' ), implode( ', ', $list ) );
		return $out;
	}
}
