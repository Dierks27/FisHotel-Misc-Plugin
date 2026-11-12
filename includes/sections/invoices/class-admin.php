<?php
/**
 * Invoices admin — FisHotel Tools → Invoices: the list (the invoices
 * built here, and read-only the ones made by hand), the picker (New /
 * Edit), one invoice's page, the forum list and Settings; and the AJAX
 * behind them.
 *
 * Every write path checks manage_woocommerce AND a nonce before it reads
 * anything else of the request. The handlers are thin: they unpack the
 * request, hand it to the Publisher / Search / Settings, and send back
 * what those answered — the rules live there, not here.
 *
 * The page posts the whole invoice as ONE JSON string (`payload`), so a
 * long invoice never runs into PHP's max_input_vars; every value in it
 * is cleaned by Publisher::compose() / Lines::normalize().
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action of every AJAX call of the section.
	 *
	 * @var string
	 */
	const NONCE = 'fishotel_invoices_admin';

	const PER_PAGE      = 30;
	const HAND_PER_PAGE = 30;

	/**
	 * The largest request body the handlers read (bytes).
	 *
	 * @var int
	 */
	const MAX_PAYLOAD = 400000;

	/**
	 * The AJAX operations: wp_ajax_fishotel_invoices_<op> → ajax_<op>().
	 *
	 * @var string[]
	 */
	const OPERATIONS = array( 'search', 'handles', 'customers', 'save', 'precheck', 'publish', 'update', 'trash', 'discard', 'forum', 'settings' );

	const VIEWS = array( 'list', 'new', 'edit', 'invoice', 'forum', 'settings' );

	/**
	 * Stand-ins for the store, the shop and the tanks (tests only).
	 *
	 * @var array|null
	 */
	private static $stand_in = null;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		foreach ( self::OPERATIONS as $operation ) {
			add_action( 'wp_ajax_fishotel_invoices_' . $operation, array( $this, 'ajax_' . $operation ) );
		}
	}

	/**
	 * URL of a view.
	 *
	 * @param string $view  list|new|edit|invoice|forum|settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $view = 'list', array $extra = array() ) {
		$args = array( 'page' => Invoices::MENU_SLUG );
		if ( 'list' !== $view ) {
			$args['view'] = $view;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/* ============================================================== */
	/*  The page                                                       */
	/* ============================================================== */

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Invoices::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
		if ( ! in_array( $view, self::VIEWS, true ) ) {
			$view = 'list';
		}
		$request = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		list( , $store, $shop, $tanks, $settings ) = self::services();

		$tables_ok = Store::ready();
		$shop_ok   = $shop->available();
		$tab       = in_array( $view, array( 'edit', 'invoice' ), true ) ? 'list' : $view;

		// Each view reads what it needs from $data (and may use $store, $shop, $settings, $tab, $tables_ok, $shop_ok).
		try {
			switch ( $view ) {
				case 'new':
				case 'edit':
					$data = self::edit_data( $store, $shop, $tanks, $settings, $request );
					if ( empty( $data['state'] ) ) {
						// Not editable: show the invoice itself, with the reason.
						$notice = (string) $data['notice'];
						$data   = self::invoice_data( $store, $shop, $tanks, $settings, (int) $data['id'] );
						if ( ! $data ) {
							$data = array( 'message' => $notice );
							self::view( 'missing', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
							return;
						}
						$data['notice'] = $notice;
						self::view( 'invoice', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
						return;
					}
					$tab = 0 === (int) $data['state']['id'] ? 'new' : 'list';
					self::view( 'edit', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
					return;

				case 'invoice':
					$data = self::invoice_data( $store, $shop, $tanks, $settings, isset( $request['id'] ) && is_scalar( $request['id'] ) ? absint( $request['id'] ) : 0 );
					if ( ! $data ) {
						$data = array( 'message' => __( 'This invoice does not exist.', 'fishotel-misc-plugin' ) );
						self::view( 'missing', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
						return;
					}
					$data['notice'] = '';
					self::view( 'invoice', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
					return;

				case 'forum':
					$bounds = Format::day_bounds_utc( Invoices::today(), Invoices::timezone() );
					$data   = array(
						'todays'   => $bounds ? $store->invoices_published_between( $bounds[0], $bounds[1] ) : array(),
						'recent'   => $store->invoices( array( 'state' => array( 'published', 'paid' ) ), 60 ),
						'selected' => isset( $request['ids'] ) && is_scalar( $request['ids'] ) ? Store::ids( explode( ',', (string) $request['ids'] ) ) : array(),
					);
					self::view( 'forum', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
					return;

				case 'settings':
					$data = array(
						'classes'  => $shop->shipping_classes(),
						'images'   => array(
							'image_under' => $settings['image_under'] > 0 ? $shop->attachment( $settings['image_under'] ) : null,
							'image_over'  => $settings['image_over'] > 0 ? $shop->attachment( $settings['image_over'] ) : null,
						),
						'category' => $shop->category( $settings['category_slug'] ),
					);
					self::view( 'settings', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
					return;
			}

			$data = self::list_data( $store, $shop, $tanks, $settings, $request );
			self::view( 'list', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
		} catch ( \Throwable $e ) {
			Invoices::log( 'error', 'The Invoices page failed: ' . $e->getMessage() );
			$data = array( 'message' => __( 'This page could not be put together. The reason is in the FisHotel Health log.', 'fishotel-misc-plugin' ) );
			self::view( 'missing', compact( 'data', 'store', 'shop', 'settings', 'tab', 'tables_ok', 'shop_ok' ) );
		}
	}

	/**
	 * Include a view with its variables. A view file that is not there (a
	 * half-finished deploy) gives a plain message instead of PHP warnings.
	 *
	 * @param string $name View name.
	 * @param array  $vars data, store, shop, settings, tab, tables_ok, shop_ok.
	 */
	private static function view( $name, array $vars ) {
		$file = Invoices::path() . 'views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			/* translators: %s: file name */
			$vars['data'] = array( 'message' => sprintf( __( 'A file of this screen is missing (%s). Update the plugin again.', 'fishotel-misc-plugin' ), 'views/' . $name . '.php' ) );
			$file         = Invoices::path() . 'views/missing.php';
			if ( ! is_readable( $file ) ) {
				echo '<div class="wrap"><p>' . esc_html( $vars['data']['message'] ) . '</p></div>';
				return;
			}
		}

		$data      = $vars['data'];
		$store     = $vars['store'];
		$shop      = $vars['shop'];
		$settings  = $vars['settings'];
		$tab       = $vars['tab'];
		$tables_ok = $vars['tables_ok'];
		$shop_ok   = $vars['shop_ok'];

		include $file;
	}

	/**
	 * What the list shows: a page of this section's invoices with what
	 * each really is right now, the counts per state, and a page of the
	 * invoices made by hand.
	 *
	 * @param object $store    Store.
	 * @param object $shop     Shop.
	 * @param object $tanks    Tanks.
	 * @param array  $settings Settings.
	 * @param array  $request  The query (unslashed): state, s, paged, hpaged.
	 * @return array
	 */
	public static function list_data( $store, $shop, $tanks, array $settings, array $request ) {
		$state  = isset( $request['state'] ) && is_scalar( $request['state'] ) ? sanitize_key( $request['state'] ) : '';
		$search = isset( $request['s'] ) && is_scalar( $request['s'] ) ? Lines::text( $request['s'], 120 ) : '';
		$paged  = max( 1, isset( $request['paged'] ) && is_scalar( $request['paged'] ) ? absint( $request['paged'] ) : 1 );
		$hpaged = max( 1, isset( $request['hpaged'] ) && is_scalar( $request['hpaged'] ) ? absint( $request['hpaged'] ) : 1 );

		$filters = array();
		if ( in_array( $state, Rules::STATES, true ) ) {
			$filters['state'] = array( $state );
		} elseif ( 'all' !== $state ) {
			$state            = '';
			$filters['state'] = array( 'draft', 'published', 'paid' );
		}
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		$check = new Precheck( $store, $shop, $tanks, $settings );
		$rows  = $store->invoices( $filters, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$total = $store->count_invoices( $filters );
		$check->prime( array_filter( array_column( $rows, 'product_id' ) ) );

		$lines = $store->lines_for_invoices( array_column( $rows, 'id' ) );
		$items = array();
		foreach ( $rows as $row ) {
			$facts   = $check->facts( $row );
			$row     = Sync::reconcile( $store, $shop, $row, $facts['effective'] );
			$items[] = array(
				'invoice'   => $row,
				'effective' => $facts['effective'],
				'product'   => $facts['product'],
				'charged'   => self::charged( $row, $facts['product'] ),
				'summary'   => Lines::summary( isset( $lines[ $row['id'] ] ) ? $lines[ $row['id'] ] : array() ),
				'who'       => $shop->user_name( $row['created_by'] ),
			);
		}

		// The invoices made by hand: the Invoices category, minus the products built here.
		$hand = array();
		$products  = $shop->available() ? $shop->invoice_products(
			$settings['category_slug'],
			array(
				'limit' => self::HAND_PER_PAGE,
				'page'  => $hpaged,
			)
		) : array();
		$hand_more = count( $products ) >= self::HAND_PER_PAGE;
		$ours      = array();
		foreach ( $store->invoices_by_products( array_column( $products, 'id' ) ) as $row ) {
			$ours[ (int) $row['product_id'] ] = true;
		}
		$theirs = array();
		foreach ( $products as $product ) {
			if ( ! isset( $ours[ (int) $product['id'] ] ) && ! $check->is_ours( $product ) ) {
				$theirs[] = $product;
			}
		}
		$check->prime( array_column( $theirs, 'id' ) );
		foreach ( $theirs as $product ) {
			$hand[] = array(
				'product' => $product,
				'paid'    => $check->hand_facts( $product ),
				'handle'  => Rules::handle_from_title( $product['name'], $settings['title_format'] ),
				'summary' => Lines::summary(
					array_map(
						static function ( $row ) {
							return array(
								'kind' => 'credit' === $row['kind'] ? 'note' : $row['kind'],
								'name' => $row['name'],
								'qty'  => $row['qty'],
							);
						},
						Grammar::product_lines( $product['lines_json'], $product['description'] )
					)
				),
			);
		}

		return array(
			'items'     => $items,
			'total'     => $total,
			'pages'     => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			'paged'     => $paged,
			'state'     => $state,
			'search'    => $search,
			'counts'    => $store->state_counts(),
			'hand'      => $hand,
			'hpaged'    => $hpaged,
			'hand_more' => $hand_more,
			'category'  => $shop->available() ? $shop->category( $settings['category_slug'] ) : null,
		);
	}

	/**
	 * What one invoice's page shows, or null when the invoice is gone.
	 *
	 * @param object $store      Store.
	 * @param object $shop       Shop.
	 * @param object $tanks      Tanks.
	 * @param array  $settings   Settings.
	 * @param int    $invoice_id Invoice id.
	 * @return array|null
	 */
	public static function invoice_data( $store, $shop, $tanks, array $settings, $invoice_id ) {
		$invoice = $store->invoice( (int) $invoice_id );
		if ( ! $invoice ) {
			return null;
		}

		$check   = new Precheck( $store, $shop, $tanks, $settings );
		$facts   = $check->facts( $invoice );
		$invoice = Sync::reconcile( $store, $shop, $invoice, $facts['effective'] );
		$lines   = $store->lines( $invoice['id'] );
		$writes  = 'draft' === $invoice['state'] ? $check->writes( $invoice, $lines, Invoices::today(), 'publish' ) : null;
		$others  = $store->invoices_for_customer(
			array(
				'handle_key'     => $invoice['handle_key'],
				'customer_id'    => (int) $invoice['customer_id'],
				'customer_email' => $invoice['customer_email'],
			),
			$invoice['id']
		);

		return array(
			'invoice'    => $invoice,
			'lines'      => $lines,
			'effective'  => $facts['effective'],
			'product'    => $facts['product'],
			'orders'     => is_array( $facts['orders'] ) ? $facts['orders'] : array(),
			'writes'     => $writes,
			'others'     => $others,
			'line_notes' => $check->line_notes( $invoice, $lines ),
			'who'        => array(
				'created'   => $shop->user_name( $invoice['created_by'] ),
				'updated'   => $shop->user_name( $invoice['updated_by'] ),
				'published' => $shop->user_name( (int) $invoice['published_by'] ),
			),
			'customer'   => ! empty( $invoice['customer_id'] ) ? $shop->customer( (int) $invoice['customer_id'] ) : null,
		);
	}

	/**
	 * The invoice as the picker holds it in the page: its fields, its
	 * lines, and for each line picked from a shop product what that
	 * product is right now (whether it tracks stock, how many are left).
	 *
	 * @param object  $shop    Shop.
	 * @param array   $invoice The invoice (stored, or blank).
	 * @param array[] $lines   Its lines.
	 * @param string  $mode    draft|update.
	 * @return array
	 */
	public static function editor_state( $shop, array $invoice, array $lines, $mode ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( 'discount' === $line['kind'] ) {
				continue; // Recomputed by the page from the flat total.
			}
			$row = array(
				'id'           => (int) ( isset( $line['id'] ) ? $line['id'] : 0 ),
				'kind'         => $line['kind'],
				'name'         => $line['name'],
				'qty'          => (int) $line['qty'],
				'unit_price'   => 'credit' === $line['kind'] && null !== $line['unit_price'] ? abs( (float) $line['unit_price'] ) : $line['unit_price'],
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) $line['variation_id'],
				'species_id'   => (int) $line['species_id'],
				'stay_id'      => (int) $line['stay_id'],
				'size_slug'    => (string) $line['size_slug'],
				'size_label'   => (string) $line['size_label'],
				'source'       => $line['source'],
				'take_stock'   => ! empty( $line['take_stock'] ),
				'managed'      => false,
				'stock'        => null,
				'gone'         => false,
			);
			if ( 'fish' === $line['kind'] && 'product' === $line['source'] && $row['product_id'] > 0 ) {
				$pick           = $shop->pick_facts( $row['product_id'], $row['variation_id'] );
				$row['managed'] = $pick['exists'] && $pick['managed'];
				$row['stock']   = $pick['exists'] ? $pick['stock'] : null;
				$row['gone']    = ! $pick['exists'];
			}
			$out[] = $row;
		}

		return array(
			'id'             => (int) ( isset( $invoice['id'] ) ? $invoice['id'] : 0 ),
			'version'        => (int) ( isset( $invoice['version'] ) ? $invoice['version'] : 0 ),
			'mode'           => 'update' === $mode ? 'update' : 'draft',
			'handle'         => (string) ( isset( $invoice['handle'] ) ? $invoice['handle'] : '' ),
			'forum_user_id'  => ! empty( $invoice['forum_user_id'] ) ? (int) $invoice['forum_user_id'] : '',
			'customer_id'    => ! empty( $invoice['customer_id'] ) ? (int) $invoice['customer_id'] : 0,
			'customer_name'  => (string) ( isset( $invoice['customer_name'] ) ? $invoice['customer_name'] : '' ),
			'customer_email' => (string) ( isset( $invoice['customer_email'] ) ? $invoice['customer_email'] : '' ),
			'shipping'       => (string) ( isset( $invoice['shipping'] ) ? $invoice['shipping'] : 'premium' ),
			'shipping_slug'  => 'other' === ( isset( $invoice['shipping'] ) ? $invoice['shipping'] : '' ) ? (string) $invoice['shipping_slug'] : '',
			'flat_on'        => isset( $invoice['flat_total'] ) && null !== $invoice['flat_total'],
			'flat_total'     => isset( $invoice['flat_total'] ) && null !== $invoice['flat_total'] ? (float) $invoice['flat_total'] : '',
			'notes'          => (string) ( isset( $invoice['notes'] ) ? $invoice['notes'] : '' ),
			'title'          => (string) ( isset( $invoice['title'] ) ? $invoice['title'] : '' ),
			'lines'          => $out,
		);
	}

	/**
	 * What the picker opens with. `state` is empty when the invoice asked
	 * for cannot be edited — `notice` then says why and `id` which one.
	 *
	 * A new invoice may start from another one (`from`): the customer of
	 * that invoice, none of its lines — "Add fish for this customer".
	 * A published invoice opens only on the deliberate path (`unpaid=1`)
	 * and only while it is unpaid and in no live order.
	 *
	 * @param object $store    Store.
	 * @param object $shop     Shop.
	 * @param object $tanks    Tanks.
	 * @param array  $settings Settings.
	 * @param array  $request  The query (unslashed): id, from, unpaid.
	 * @return array
	 */
	public static function edit_data( $store, $shop, $tanks, array $settings, array $request ) {
		$id   = isset( $request['id'] ) && is_scalar( $request['id'] ) ? absint( $request['id'] ) : 0;
		$from = isset( $request['from'] ) && is_scalar( $request['from'] ) ? absint( $request['from'] ) : 0;
		$base = array(
			'state'     => null,
			'notice'    => '',
			'id'        => $id,
			'classes'   => $shop->shipping_classes(),
			'effective' => null,
			'product'   => null,
			'tanks_on'  => $tanks->available(),
			'tanks_why' => $tanks->available() ? '' : $tanks->reason(),
		);

		if ( $id <= 0 ) {
			$blank = array( 'shipping' => 'premium' );
			if ( $from > 0 ) {
				$source = $store->invoice( $from );
				if ( $source ) {
					foreach ( array( 'handle', 'forum_user_id', 'customer_id', 'customer_name', 'customer_email', 'shipping', 'shipping_slug' ) as $key ) {
						$blank[ $key ] = $source[ $key ];
					}
				}
			}
			$base['state'] = self::editor_state( $shop, $blank, array(), 'draft' );
			return $base;
		}

		$invoice = $store->invoice( $id );
		if ( ! $invoice ) {
			$base['notice'] = __( 'This invoice does not exist.', 'fishotel-misc-plugin' );
			return $base;
		}

		if ( 'draft' === $invoice['state'] ) {
			$base['state'] = self::editor_state( $shop, $invoice, $store->lines( $id ), 'draft' );
			return $base;
		}

		$check   = new Precheck( $store, $shop, $tanks, $settings );
		$facts   = $check->facts( $invoice );
		$invoice = Sync::reconcile( $store, $shop, $invoice, $facts['effective'] );

		if ( ! $facts['effective']['can_edit'] || 'published' !== $facts['effective']['state'] ) {
			$base['notice'] = '' !== $facts['effective']['lock_reason'] ? $facts['effective']['lock_reason'] : __( 'This invoice cannot be edited.', 'fishotel-misc-plugin' );
			return $base;
		}
		if ( empty( $request['unpaid'] ) || '1' !== (string) $request['unpaid'] ) {
			$base['notice'] = __( 'A published invoice is not opened for editing by accident. Use "Edit this unpaid invoice" on its page — or, better, "Add fish for this customer".', 'fishotel-misc-plugin' );
			return $base;
		}

		$base['state']     = self::editor_state( $shop, $invoice, $store->lines( $id ), 'update' );
		$base['effective'] = $facts['effective'];
		$base['product']   = $facts['product'];
		return $base;
	}

	/* ============================================================== */
	/*  Small things the views share                                   */
	/* ============================================================== */

	/**
	 * A stored (UTC) time on the site's clock: "Nov 3, 2026 9:15 am".
	 *
	 * @param string|null $utc Y-m-d H:i:s in UTC.
	 * @return string '' without a time.
	 */
	public static function site_time( $utc ) {
		$ts = empty( $utc ) ? false : strtotime( $utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'M j, Y g:i a', $ts ) : gmdate( 'M j, Y g:i a', $ts ) . ' UTC';
	}

	/**
	 * A state as a small coloured badge (escaped HTML).
	 *
	 * @param string $state draft|published|paid|cancelled|trashed|hand.
	 * @param string $label The text ('' = the state's own label).
	 * @return string
	 */
	public static function badge( $state, $label = '' ) {
		$state = in_array( (string) $state, array_merge( Rules::STATES, array( 'hand', 'locked' ) ), true ) ? (string) $state : 'draft';
		$label = '' !== (string) $label ? (string) $label : Rules::state_label( $state );
		return '<span class="fh-inv-badge fh-inv-badge--' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Where a line's fish came from, in a few words.
	 *
	 * @param array $line A stored line.
	 * @return string
	 */
	public static function source_text( array $line ) {
		if ( 'fish' !== $line['kind'] ) {
			return '';
		}
		switch ( (string) $line['source'] ) {
			case 'tank':
				/* translators: %d: tank group (Clean Room stay) id */
				return sprintf( __( 'tank group #%d', 'fishotel-misc-plugin' ), (int) $line['stay_id'] );
			case 'product':
				return ! empty( $line['variation_id'] )
					/* translators: 1: product id, 2: variation id */
					? sprintf( __( 'shop product #%1$d, variation #%2$d', 'fishotel-misc-plugin' ), (int) $line['product_id'], (int) $line['variation_id'] )
					/* translators: %d: product id */
					: sprintf( __( 'shop product #%d', 'fishotel-misc-plugin' ), (int) $line['product_id'] );
			case 'species':
				/* translators: %d: species-list id */
				return sprintf( __( 'species list #%d', 'fishotel-misc-plugin' ), (int) $line['species_id'] );
		}
		return __( 'typed', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Enqueue CSS / JS on the section page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Invoices::MENU_SLUG ) ) {
			return;
		}

		$settings = Settings::get();

		// The Settings screen picks its two images from the media library.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( isset( $_GET['view'] ) && 'settings' === sanitize_key( wp_unslash( $_GET['view'] ) ) && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style( 'fishotel-invoices-admin', Invoices::url() . 'css/invoices-admin.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-invoices-admin', Invoices::url() . 'js/invoices-admin.js', array( 'jquery' ), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-invoices-admin',
			'fhInvoices',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'listUrl'       => self::page_url( 'list' ),
				'viewUrl'       => self::page_url( 'invoice', array( 'id' => '__ID__' ) ),
				'editUrl'       => self::page_url( 'edit', array( 'id' => '__ID__' ) ),
				'forumUrl'      => self::page_url( 'forum', array( 'ids' => '__IDS__' ) ),
				'titleFormat'   => $settings['title_format'],
				'excerptFormat' => $settings['excerpt_format'],
				'titleDate'     => Format::date_njy( Invoices::today() ),
				'i18n'          => self::strings(),
			)
		);
	}

	/**
	 * The texts the page script uses.
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			'error'          => __( 'Something went wrong. Nothing was changed. Please try again.', 'fishotel-misc-plugin' ),
			'saving'         => __( 'Saving…', 'fishotel-misc-plugin' ),
			'checking'       => __( 'Reading this customer\'s orders and invoices…', 'fishotel-misc-plugin' ),
			'publishing'     => __( 'Publishing…', 'fishotel-misc-plugin' ),
			'searching'      => __( 'Searching…', 'fishotel-misc-plugin' ),
			'copied'         => __( 'Copied.', 'fishotel-misc-plugin' ),
			'copyFailed'     => __( 'Could not copy — select the text and copy it by hand.', 'fishotel-misc-plugin' ),
			'noResults'      => __( 'Nothing found.', 'fishotel-misc-plugin' ),
			'noLines'        => __( 'No lines yet. Search for a fish above, or add a credit or a note.', 'fishotel-misc-plugin' ),
			'fish'           => __( 'Fish', 'fishotel-misc-plugin' ),
			'credit'         => __( 'Credit', 'fishotel-misc-plugin' ),
			'note'           => __( 'Note', 'fishotel-misc-plugin' ),
			'discount'       => __( 'Discount', 'fishotel-misc-plugin' ),
			'adjustment'     => __( 'Adjustment', 'fishotel-misc-plugin' ),
			'reason'         => __( 'reason, e.g. lost Cleaner Wrasse', 'fishotel-misc-plugin' ),
			'noteText'       => __( 'a note the customer reads', 'fishotel-misc-plugin' ),
			'fishName'       => __( 'fish', 'fishotel-misc-plugin' ),
			'remove'         => __( 'Remove', 'fishotel-misc-plugin' ),
			'up'             => __( 'Move up', 'fishotel-misc-plugin' ),
			'down'           => __( 'Move down', 'fishotel-misc-plugin' ),
			'takeStock'      => __( 'take from shop stock when paid', 'fishotel-misc-plugin' ),
			/* translators: 1: shop stock, 2: quantity on the invoice */
			'stockLow'       => __( 'only %1$s in shop stock, %2$s on this invoice', 'fishotel-misc-plugin' ),
			/* translators: %s: shop stock */
			'stockHas'       => __( 'shop stock %s', 'fishotel-misc-plugin' ),
			'stockNone'      => __( 'shop stock is not touched', 'fishotel-misc-plugin' ),
			'productGone'    => __( 'this shop product no longer exists', 'fishotel-misc-plugin' ),
			/* translators: 1: fish alive in the tank group, 2: quantity on the invoice */
			'aliveLow'       => __( 'only %1$s alive in that tank group, %2$s on this invoice', 'fishotel-misc-plugin' ),
			'fromTank'       => __( 'from the tanks', 'fishotel-misc-plugin' ),
			'fromProduct'    => __( 'shop product', 'fishotel-misc-plugin' ),
			'fromSpecies'    => __( 'species list', 'fishotel-misc-plugin' ),
			'fromTyped'      => __( 'typed', 'fishotel-misc-plugin' ),
			'noPrice'        => __( 'no price', 'fishotel-misc-plugin' ),
			'sumOfLines'     => __( 'Sum of the lines', 'fishotel-misc-plugin' ),
			'total'          => __( 'Total', 'fishotel-misc-plugin' ),
			'negativeTotal'  => __( 'The total is below zero: lower the credit.', 'fishotel-misc-plugin' ),
			'changed'        => __( 'The invoice was changed after this pre-check. Run it again before publishing.', 'fishotel-misc-plugin' ),
			'confirmEach'    => __( 'Tick every fish that already appears on another invoice to confirm it.', 'fishotel-misc-plugin' ),
			'confirmDiscard' => __( 'Withdraw this draft? It is kept in the list under "Withdrawn", but can no longer be published.', 'fishotel-misc-plugin' ),
			'confirmTrash'   => __( 'Move this invoice to the Trash? The customer\'s link stops working. It can be restored from Products → Trash.', 'fishotel-misc-plugin' ),
			'confirmEdit'    => __( 'The customer may already have the link to this invoice and may be looking at it — or paying it — right now. Extra fish normally go on a NEW invoice. Edit this unpaid invoice anyway?', 'fishotel-misc-plugin' ),
			'leave'          => __( 'There are changes that were not saved.', 'fishotel-misc-plugin' ),
			'handleFirst'    => __( 'Type the customer\'s handle first.', 'fishotel-misc-plugin' ),
			'unlink'         => __( 'unlink', 'fishotel-misc-plugin' ),
			'pickSome'       => __( 'Tick at least one invoice.', 'fishotel-misc-plugin' ),
			'saved'          => __( 'Saved.', 'fishotel-misc-plugin' ),
		);
	}

	/* ============================================================== */
	/*  AJAX                                                           */
	/* ============================================================== */

	/**
	 * Capability, then nonce — before anything else of the request is
	 * read. Ends the request when either fails.
	 */
	private static function guard() {
		if ( ! current_user_can( Invoices::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * The invoice the page posted (one JSON string), as an array.
	 *
	 * @return array
	 */
	private static function payload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by guard(); decoded as JSON and every value cleaned by Publisher::compose().
		$raw = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		if ( '' === $raw || strlen( $raw ) > self::MAX_PAYLOAD ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * A posted scalar as text.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * A store, a shop and the tanks to use instead of the real ones — the
	 * headless tests hand in their in-memory twins, so the handlers and
	 * the screens run end to end without a database. Null puts the real
	 * ones back.
	 *
	 * @param object|null $store Store twin.
	 * @param object|null $shop  Shop (the real class on the WooCommerce stand-ins).
	 * @param object|null $tanks Tanks twin.
	 */
	public static function stand_in( $store = null, $shop = null, $tanks = null ) {
		self::$stand_in = $store && $shop && $tanks ? array( $store, $shop, $tanks ) : null;
	}

	/**
	 * The publisher, the store, the shop, the tanks and the settings every
	 * screen and handler works with.
	 *
	 * @return array{0: Publisher, 1: object, 2: object, 3: object, 4: array}
	 */
	private static function services() {
		if ( self::$stand_in ) {
			list( $store, $shop, $tanks ) = self::$stand_in;
		} else {
			$store = new Store();
			$shop  = new Shop();
			$tanks = new Tanks();
		}
		$settings = Settings::get();
		return array( new Publisher( $store, $shop, $tanks, $settings ), $store, $shop, $tanks, $settings );
	}

	/**
	 * The pre-check report as HTML (the view escapes everything).
	 *
	 * @param array $report Precheck::report().
	 * @return string
	 */
	public static function report_html( array $report ) {
		$file = Invoices::path() . 'views/precheck.php';
		if ( ! is_readable( $file ) ) {
			return '<p>' . esc_html__( 'The pre-check cannot be shown: a file of this screen is missing. Update the plugin again.', 'fishotel-misc-plugin' ) . '</p>';
		}

		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Send what the Publisher answered: its message, the pre-check as
	 * HTML when it carries a report, the invoice as the picker holds it,
	 * and the links.
	 *
	 * @param array  $result The Publisher's answer.
	 * @param object $store  Store.
	 * @param object $shop   Shop.
	 * @param string $mode   draft|update — how the picker is open.
	 * @param int    $asked  The invoice the request was about (for a refusal that names none).
	 */
	private static function reply( array $result, $store, $shop, $mode, $asked = 0 ) {
		$id   = ! empty( $result['id'] ) ? (int) $result['id'] : (int) $asked;
		$data = array(
			'code'    => isset( $result['code'] ) ? (string) $result['code'] : '',
			'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
		);

		foreach ( array( 'token', 'url', 'title', 'total', 'warnings', 'product_id' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$data[ $key ] = $result[ $key ];
			}
		}
		if ( isset( $result['report'] ) && is_array( $result['report'] ) ) {
			$data['html']     = self::report_html( $result['report'] );
			$data['dupKeys']  = $result['report']['dup_keys'];
			$data['blocked']  = ! empty( $result['report']['errors'] );
			$data['lineErrs'] = $result['report']['line_errors'];
		}

		// The invoice as it is stored now: the page keeps its id in step — and its version, but
		// only after its OWN successful save. A refusal hands no version over (a window that is
		// behind would adopt it and undo the newer save on the next click), and neither does the
		// pre-check of an edit, which saves nothing.
		$invoice = $id > 0 ? $store->invoice( $id ) : null;
		if ( $invoice ) {
			$data['id'] = (int) $invoice['id'];
			if ( ! empty( $result['ok'] ) && ( 'update' !== $mode || 'updated' === $data['code'] ) ) {
				$data['version'] = (int) $invoice['version'];
			}
			$data['state']   = (string) $invoice['state'];
			$data['viewUrl'] = self::page_url( 'invoice', array( 'id' => (int) $invoice['id'] ) );
			if ( ! empty( $result['ok'] ) && in_array( $data['code'], array( 'saved', 'checked' ), true ) && 'draft' === $invoice['state'] && 'draft' === $mode ) {
				$data['invoice'] = self::editor_state( $shop, $invoice, $store->lines( $invoice['id'] ), 'draft' );
			}
		}

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $data );
		}
		wp_send_json_error( $data, 'conflict' === $data['code'] ? 409 : 400 );
	}

	/**
	 * AJAX: the picker's search box.
	 */
	public function ajax_search() {
		self::guard();
		list( , $store, $shop, $tanks, $settings ) = self::services();

		$groups = Search::run( $shop, $tanks, $store, $settings, self::posted( 'term' ), absint( self::posted( 'invoice_id' ) ) );
		wp_send_json_success( array( 'groups' => $groups ) );
	}

	/**
	 * AJAX: handles used before.
	 */
	public function ajax_handles() {
		self::guard();
		list( , $store ) = self::services();
		wp_send_json_success( array( 'handles' => $store->handles( self::posted( 'term' ), 8 ) ) );
	}

	/**
	 * AJAX: site customers by name or e-mail.
	 */
	public function ajax_customers() {
		self::guard();
		list( , , $shop ) = self::services();
		wp_send_json_success( array( 'customers' => $shop->search_customers( self::posted( 'term' ), 8 ) ) );
	}

	/**
	 * AJAX: save the draft.
	 */
	public function ajax_save() {
		self::guard();
		list( $publisher, $store, $shop ) = self::services();

		$payload = self::payload();
		self::reply( $publisher->save_draft( $payload, get_current_user_id() ), $store, $shop, 'draft', isset( $payload['id'] ) ? absint( $payload['id'] ) : 0 );
	}

	/**
	 * AJAX: the pre-check — of a draft (saved first), or of the changes
	 * to an unpaid published invoice (nothing saved).
	 */
	public function ajax_precheck() {
		self::guard();
		list( $publisher, $store, $shop ) = self::services();

		$payload = self::payload();
		$id      = isset( $payload['id'] ) ? absint( $payload['id'] ) : 0;
		$update  = isset( $payload['mode'] ) && 'update' === $payload['mode'];
		$result  = $update
			? $publisher->precheck_update( $id, $payload, get_current_user_id(), Invoices::today() )
			: $publisher->precheck( $payload, get_current_user_id(), Invoices::today() );

		self::reply( $result, $store, $shop, $update ? 'update' : 'draft', $id );
	}

	/**
	 * The confirmed fish-on-two-invoices keys the page posted.
	 *
	 * @return string[]
	 */
	private static function confirmed() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		$raw = isset( $_POST['confirmed'] ) && is_array( $_POST['confirmed'] ) ? wp_unslash( $_POST['confirmed'] ) : array();
		$out = array();
		foreach ( $raw as $key ) {
			if ( is_string( $key ) && preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * AJAX: publish a draft.
	 */
	public function ajax_publish() {
		self::guard();
		list( $publisher, $store, $shop ) = self::services();

		$id = absint( self::posted( 'id' ) );
		self::reply( $publisher->publish( $id, self::posted( 'token' ), self::confirmed(), get_current_user_id(), Invoices::today() ), $store, $shop, 'draft', $id );
	}

	/**
	 * AJAX: save the changes to an unpaid published invoice.
	 */
	public function ajax_update() {
		self::guard();
		list( $publisher, $store, $shop ) = self::services();

		$payload = self::payload();
		$id      = isset( $payload['id'] ) ? absint( $payload['id'] ) : 0;
		self::reply( $publisher->update( $id, $payload, self::posted( 'token' ), self::confirmed(), get_current_user_id(), Invoices::today() ), $store, $shop, 'update', $id );
	}

	/**
	 * AJAX: move an invoice to the Trash.
	 */
	public function ajax_trash() {
		self::guard();
		list( $publisher, $store, $shop ) = self::services();

		$id = absint( self::posted( 'id' ) );
		self::reply( $publisher->trash( $id, get_current_user_id() ), $store, $shop, 'draft', $id );
	}

	/**
	 * AJAX: withdraw a draft.
	 */
	public function ajax_discard() {
		self::guard();
		list( $publisher, $store, $shop ) = self::services();

		$id = absint( self::posted( 'id' ) );
		self::reply( $publisher->discard( $id, get_current_user_id() ), $store, $shop, 'draft', $id );
	}

	/**
	 * What the customer is charged for an invoice right now: the price of
	 * its product (the sale price, when somebody set one by hand) — which
	 * is the stored total unless the product was changed by hand since.
	 *
	 * @param array      $invoice The stored invoice.
	 * @param array|null $product Shop::product() facts, or null.
	 * @return array{amount: float, differs: bool}
	 */
	public static function charged( array $invoice, $product ) {
		$total = (float) $invoice['total'];
		if ( ! is_array( $product ) || 'publish' !== $product['status'] || '' === (string) $product['price'] || ! is_numeric( $product['price'] ) ) {
			return array(
				'amount'  => $total,
				'differs' => false,
			);
		}

		return array(
			'amount'  => (float) $product['price'],
			'differs' => Lines::cents( $product['price'] ) !== Lines::cents( $total ),
		);
	}

	/**
	 * The forum list of a set of invoices (the testable core of
	 * ajax_forum()): one line each, for the ones a customer can open —
	 * published or paid, with a product that is not in the Trash. The
	 * amount on a line is what the customer is charged (charged()): when
	 * the product's price is no longer the invoice's total, the line shows
	 * the product's price and a note says so.
	 *
	 * @param object  $shop     Shop.
	 * @param array[] $invoices Stored invoices, in the order to list them.
	 * @return array{text: string, count: int, skipped: string[], notes: string[]}
	 */
	public static function forum_text( $shop, array $invoices ) {
		$rows    = array();
		$skipped = array();
		$notes   = array();

		foreach ( $invoices as $invoice ) {
			$product = ! empty( $invoice['product_id'] ) ? $shop->product( (int) $invoice['product_id'] ) : null;
			$name    = '' !== $invoice['title'] ? $invoice['title'] : $invoice['handle'];

			if ( ! in_array( $invoice['state'], array( 'published', 'paid' ), true ) || ! $product || 'publish' !== $product['status'] ) {
				/* translators: 1: invoice title or handle, 2: its state */
				$skipped[] = sprintf( __( '%1$s is left out: %2$s.', 'fishotel-misc-plugin' ), $name, Rules::state_label( $invoice['state'] ) );
				continue;
			}

			$charged = self::charged( $invoice, $product );
			if ( $charged['differs'] ) {
				/* translators: 1: invoice title, 2: the product's price, 3: the invoice's total */
				$notes[] = sprintf( __( '%1$s: the product\'s price is %2$s, not the invoice\'s total of %3$s — it was changed by hand, or carries a sale price. The list shows %2$s, what the customer is charged.', 'fishotel-misc-plugin' ), $name, Lines::dollars( $charged['amount'] ), Lines::dollars( $invoice['total'] ) );
			}

			$rows[] = array(
				'handle'        => $invoice['handle'],
				'forum_user_id' => $invoice['forum_user_id'],
				'total'         => $charged['amount'],
				'url'           => $product['permalink'],
			);
		}

		return array(
			'text'    => Format::forum_list( $rows ),
			'count'   => count( $rows ),
			'skipped' => $skipped,
			'notes'   => $notes,
		);
	}

	/**
	 * AJAX: the forum list — of the ticked invoices, or of everything
	 * published today.
	 */
	public function ajax_forum() {
		self::guard();
		list( , $store, $shop ) = self::services();

		if ( '1' === self::posted( 'today' ) ) {
			$bounds   = Format::day_bounds_utc( Invoices::today(), Invoices::timezone() );
			$invoices = $bounds ? $store->invoices_published_between( $bounds[0], $bounds[1] ) : array();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
			$ids      = Store::ids( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array() );
			$invoices = $ids ? $store->invoices( array( 'ids' => $ids ), Store::LIST_LIMIT ) : array();
			usort(
				$invoices,
				static function ( $a, $b ) {
					return strcasecmp( $a['handle'], $b['handle'] );
				}
			);
		}

		wp_send_json_success( self::forum_text( $shop, $invoices ) );
	}

	/**
	 * AJAX: save the settings.
	 */
	public function ajax_settings() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by guard(); every value is cleaned by Settings::sanitize().
		$raw   = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$clean = Settings::save( $raw );

		list( , , $shop ) = self::services();
		$messages         = $clean['errors'];
		if ( $shop->available() && ! $shop->category( $clean['settings']['category_slug'] ) ) {
			/* translators: %s: category slug */
			$messages[] = sprintf( __( 'Saved — but the product category "%s" does not exist yet, so nothing can be published until it does.', 'fishotel-misc-plugin' ), $clean['settings']['category_slug'] );
		}
		foreach ( array( 'premium', 'free' ) as $choice ) {
			$slug = $clean['settings']['shipping'][ $choice ];
			if ( $shop->available() && '' !== $slug && ! $shop->shipping_class( $slug ) ) {
				/* translators: 1: Premium / Free, 2: class slug */
				$messages[] = sprintf( __( 'Saved — but the shipping class "%2$s" chosen for "%1$s" does not exist.', 'fishotel-misc-plugin' ), Settings::shipping_label( $choice ), $slug );
			}
		}
		foreach ( array( 'image_under', 'image_over' ) as $key ) {
			if ( $clean['settings'][ $key ] > 0 && ! $shop->attachment( $clean['settings'][ $key ] ) ) {
				/* translators: %d: attachment id */
				$messages[] = sprintf( __( 'Saved — but image #%d is not in the media library.', 'fishotel-misc-plugin' ), $clean['settings'][ $key ] );
			}
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Settings saved.', 'fishotel-misc-plugin' ),
				'notes'    => $messages,
				'settings' => $clean['settings'],
			)
		);
	}
}
