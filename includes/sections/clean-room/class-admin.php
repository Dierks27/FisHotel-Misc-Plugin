<?php
/**
 * Clean Room admin — the Room Board page under FisHotel Tools (Board /
 * Stays / Events / Settings; the Arrivals / Species / Stats tabs are
 * handed to Admin_Workbook), the stays CSV, the printable QR labels, the
 * stay edits from the Stays tab (counts, dates, a move, the seam fields),
 * the per-row Undo on the Events tab, the Coming Soon release button and
 * the product meta box — which also edits the sale date of each open
 * stay of the product, saved with the product's Update button
 * (save_meta_box(): its own nonce, edit_post AND manage_woocommerce;
 * nothing on autosave, quick / bulk edit or REST; never in the way of
 * the product save).
 *
 * Every write path checks manage_woocommerce (settings: manage_options)
 * and a nonce. The CSV guards every cell that starts with = + - @ or a
 * tab with a leading apostrophe so a species name can never become a
 * spreadsheet formula.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action for the AJAX calls from the Board / Stays tabs.
	 *
	 * @var string
	 */
	const NONCE_AJAX = 'fishotel_cleanroom_admin';

	/**
	 * Nonce actions for the two admin-post downloads.
	 *
	 * @var string
	 */
	const NONCE_CSV    = 'fishotel_cleanroom_csv';
	const NONCE_LABELS = 'fishotel_cleanroom_labels';

	const EVENTS_LIMIT = 200;
	const LOGS_LIMIT   = 100;

	/**
	 * The Coming Soon section's meta: a UNIX timestamp release moment on
	 * the (parent) product, and its dispatched flag. Defined here so the
	 * release button works even when that section's class is not loaded.
	 */
	const RELEASE_META       = '_fh_release_datetime';
	const RELEASE_DISPATCHED = '_fh_release_dispatched';

	/**
	 * Time of day (site zone) a release defaults to on the sale date.
	 *
	 * @var string
	 */
	const RELEASE_TIME = '09:00:00';

	/**
	 * The product meta box's sale dates: the nonce action (+ the product
	 * id), its field name, the posted array (fh_cr_sale[<stay id>][date |
	 * shown | batch]) and the per-user notice carried across the redirect.
	 */
	const NONCE_PRODUCT       = 'fishotel_cleanroom_product_sale_dates_';
	const NONCE_PRODUCT_FIELD = 'fh_cr_sale_nonce';
	const SALE_FIELD          = 'fh_cr_sale';
	const SALE_NOTICE         = 'fishotel_cleanroom_sale_notice_';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_fishotel_cleanroom_csv', array( $this, 'download_csv' ) );
		add_action( 'admin_post_fishotel_cleanroom_labels', array( $this, 'print_labels' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_stay', array( $this, 'ajax_stay' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_release', array( $this, 'ajax_release' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_meta_box' ), 20, 3 );
		add_action( 'admin_notices', array( $this, 'sale_date_notices' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab   board|stays|arrivals|species|stats|events|settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'board', array $extra = array() ) {
		$args = array( 'page' => Clean_Room::MENU_SLUG );
		if ( 'board' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The nonced URL of the printable labels page.
	 *
	 * @return string
	 */
	public static function labels_url() {
		return wp_nonce_url( add_query_arg( 'action', 'fishotel_cleanroom_labels', admin_url( 'admin-post.php' ) ), self::NONCE_LABELS );
	}

	/**
	 * The nonced URL of the CSV download.
	 *
	 * @param bool $closed Include closed stays.
	 * @return string
	 */
	public static function csv_url( $closed = false ) {
		$args = array( 'action' => 'fishotel_cleanroom_csv' );
		if ( $closed ) {
			$args['closed'] = '1';
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE_CSV );
	}

	/* ============================================================== */
	/*  Page                                                           */
	/* ============================================================== */

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'board'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		if ( ! in_array( $tab, array( 'board', 'stays', 'arrivals', 'species', 'stats', 'events', 'settings' ), true ) ) {
			$tab = 'board';
		}

		$can_settings = current_user_can( Clean_Room::SETTINGS_CAPABILITY );
		$store        = new Store();
		$settings     = Settings::get();
		$today        = Clean_Room::today();
		$tables_ok    = Store::tables_exist();
		$labels_url   = self::labels_url();

		if ( in_array( $tab, Admin_Workbook::TABS, true ) ) {
			Admin_Workbook::render(
				$tab,
				array(
					'store'        => $store,
					'settings'     => $settings,
					'today'        => $today,
					'tables_ok'    => $tables_ok,
					'can_settings' => $can_settings,
					'labels_url'   => $labels_url,
				)
			);
			return;
		}

		if ( 'settings' === $tab && $can_settings ) {
			$protocols     = Settings::protocols();
			$template_name = isset( $_GET['template'] ) ? Protocol::template_name( sanitize_text_field( wp_unslash( $_GET['template'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only picker.
			if ( '' === $template_name || ! isset( $protocols['templates'][ $template_name ] ) ) {
				$template_name = $protocols['default'];
			}
			$protocol  = $protocols['templates'][ $template_name ];
			$buildings = $store->buildings();
			$tanks     = $store->tanks();
			$groups    = Layout::group( $buildings, $tanks );
			$aliases   = $store->aliases();
			$box_rates = Settings::box_rates();
			$import    = Admin_Import::panel_data( $store );
			$flash     = Admin_Workbook::take_flash();

			include Clean_Room::path() . 'views/settings.php';
			return;
		}

		if ( 'stays' === $tab ) {
			$show_closed  = isset( $_GET['closed'] ) && '1' === (string) wp_unslash( $_GET['closed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filters      = self::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stays        = $store->open_stays( $filters, $today, $settings );
			$closed_stays = $show_closed ? $store->closed_stays( $today, $settings ) : array();
			$csv_url      = self::csv_url( $show_closed );
			$xlsx_url     = Admin_Workbook::export_url( 'stays', 'xlsx', $show_closed ? array( 'closed' => '1' ) : array() );
			$flash        = Admin_Workbook::take_flash();

			include Clean_Room::path() . 'views/stays.php';
			return;
		}

		if ( 'events' === $tab ) {
			$events = $store->latest_events( self::EVENTS_LIMIT );
			$logs   = $store->tank_logs( 0, self::LOGS_LIMIT );
			$undone = Replay::undone_ids( $events );

			include Clean_Room::path() . 'views/events.php';
			return;
		}

		$filters   = self::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$protocols = Settings::protocols();
		$tanks     = $store->tanks_with_occupants( $today, $settings, $protocols );
		$groups    = Layout::group( $store->buildings(), $tanks );
		$totals    = self::totals( $tanks );
		$doses     = $store->latest_doses();

		include Clean_Room::path() . 'views/board.php';
	}

	/**
	 * area / species / ready (for sale today) from a request array.
	 *
	 * @param array $request $_GET-like.
	 * @return array{area: string, species: string, ready: bool}
	 */
	public static function filters_from_request( $request ) {
		$request = is_array( $request ) ? $request : array();

		$area = isset( $request['area'] ) ? sanitize_key( wp_unslash( $request['area'] ) ) : '';
		if ( ! isset( Layout::AREAS[ $area ] ) ) {
			$area = '';
		}
		$species = isset( $request['s'] ) ? sanitize_text_field( wp_unslash( $request['s'] ) ) : '';

		return array(
			'area'    => $area,
			'species' => substr( $species, 0, 120 ),
			'ready'   => isset( $request['ready'] ) && '1' === (string) wp_unslash( $request['ready'] ),
		);
	}

	/**
	 * Counts across the room (pure): groups in QT / cleared / for sale
	 * (a group for sale counts as ready, wherever it is), fish alive,
	 * tanks; and the two warnings — groups for sale while still in the
	 * Spa, and groups without a sale date.
	 *
	 * @param array[] $tanks Tanks with occupants.
	 * @return array{in_qt: int, cleared: int, ready: int, live: int, tanks: int, empty: int, ready_in_spa: int, no_sale_date: int}
	 */
	public static function totals( array $tanks ) {
		$out = array(
			'in_qt'        => 0,
			'cleared'      => 0,
			'ready'        => 0,
			'live'         => 0,
			'tanks'        => 0,
			'empty'        => 0,
			'ready_in_spa' => 0,
			'no_sale_date' => 0,
		);
		foreach ( $tanks as $tank ) {
			if ( 'offline' === (string) ( $tank['status'] ?? '' ) ) {
				continue;
			}
			$out['tanks']++;
			$occupants = (array) ( $tank['occupants'] ?? array() );
			if ( ! $occupants ) {
				$out['empty']++;
			}
			foreach ( $occupants as $stay ) {
				$out['live'] += (int) ( $stay['qty_live'] ?? 0 );
				if ( ! empty( $stay['sale_ready'] ) ) {
					$out['ready']++;
				} elseif ( 'cleared' === (string) ( $stay['status'] ?? '' ) ) {
					$out['cleared']++;
				} else {
					$out['in_qt']++;
				}
				if ( ! empty( $stay['ready_in_spa'] ) ) {
					$out['ready_in_spa']++;
				}
				if ( empty( $stay['sale_ready_date'] ) ) {
					$out['no_sale_date']++;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a stay matches the species filter (pure).
	 *
	 * @param array  $stay    Stay.
	 * @param string $species Filter.
	 * @return bool
	 */
	public static function matches_species( array $stay, $species ) {
		$species = trim( (string) $species );
		return '' === $species || false !== stripos( (string) ( $stay['species'] ?? '' ), $species );
	}

	/* ============================================================== */
	/*  Product meta box                                               */
	/* ============================================================== */

	/**
	 * Register the meta box on the classic product edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'fishotel_cleanroom',
			__( 'Clean room', 'fishotel-misc-plugin' ),
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * The meta box: this product's stays with day counts, the facts line,
	 * a sale-date row per open stay (saved with the product's Update
	 * button) and a link to the board.
	 *
	 * @param \WP_Post $post The product.
	 */
	public function render_meta_box( $post ) {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			echo '<p>' . esc_html__( 'No access.', 'fishotel-misc-plugin' ) . '</p>';
			return;
		}

		$product_id = (int) ( is_object( $post ) && isset( $post->ID ) ? $post->ID : 0 );
		$settings   = Settings::get();
		$today      = Clean_Room::today();
		$store      = new Store();

		try {
			$stays = $store->stays_for_product( $product_id, $today, $settings );
			$facts = $store->product_facts( $product_id, $today, $settings );
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
			return;
		}

		$board_url    = self::page_url( 'stays' );
		$release_ts   = (int) get_post_meta( $product_id, self::RELEASE_META, true );
		$release_url  = '';
		$nonce_action = self::NONCE_PRODUCT . $product_id;

		include Clean_Room::path() . 'views/meta-box.php';
	}

	/**
	 * save_post_product: the sale dates typed in the meta box, saved with
	 * the product. Only the classic edit form carries the box's nonce
	 * field, so an autosave, a quick or bulk edit and a REST save do
	 * nothing here; the nonce (per product), edit_post on the product AND
	 * the section's capability are checked; it runs once per request (the
	 * product save can fire this hook twice). Anything that goes wrong is
	 * caught and logged — the product save itself is never stopped.
	 *
	 * @param int           $post_id Product id.
	 * @param \WP_Post|null $post    The product (unused).
	 * @param bool          $update  Whether an existing post was updated (unused).
	 * @param object|null   $store   A store to use instead of the real one (the tests' stand-in).
	 * @return array|false save_product_sale_dates() — false when nothing was looked at.
	 */
	public function save_meta_box( $post_id, $post = null, $update = false, $store = null ) {
		static $done = array();
		unset( $post, $update );

		$post_id = (int) $post_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified below; its absence means "not our form".
		if ( $post_id <= 0 || empty( $_POST[ self::NONCE_PRODUCT_FIELD ] ) || isset( $done[ $post_id ] ) ) {
			return false;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) || ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_PRODUCT_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $nonce, self::NONCE_PRODUCT . $post_id ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( Clean_Room::CAPABILITY ) ) {
			return false;
		}
		$done[ $post_id ] = true;

		$raw = isset( $_POST[ self::SALE_FIELD ] ) && is_array( $_POST[ self::SALE_FIELD ] ) ? wp_unslash( $_POST[ self::SALE_FIELD ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; every value is checked in save_product_sale_dates().

		try {
			$result = self::save_product_sale_dates( $store ? $store : new Store(), $post_id, (array) $raw, Clean_Room::today(), Settings::get(), (int) get_current_user_id() );
		} catch ( \Throwable $e ) {
			do_action(
				'fishotel_log',
				'error',
				'clean-room',
				sprintf( 'The sale dates of product #%1$d could not be saved: %2$s', $post_id, $e->getMessage() ),
				array( 'product_id' => $post_id )
			);
			self::queue_sale_notice( array( __( 'The Clean room sale dates could not be saved (see FisHotel Health). The product itself was saved.', 'fishotel-misc-plugin' ) ), 'error' );
			return false;
		}

		$lines = array();
		if ( $result['events'] > 0 ) {
			/* translators: %d: groups */
			$lines[] = sprintf( _n( 'Clean room: the sale date of %d group was saved.', 'Clean room: the sale dates of %d groups were saved.', $result['events'], 'fishotel-misc-plugin' ), $result['events'] );
		}
		foreach ( $result['invalid'] as $label ) {
			/* translators: %s: the group (species, tank) and what was typed */
			$lines[] = sprintf( __( 'Clean room: %s is not a date — ignored, that group keeps its sale date.', 'fishotel-misc-plugin' ), $label );
		}
		foreach ( $result['failed'] as $label ) {
			/* translators: %s: the group */
			$lines[] = sprintf( __( 'Clean room: the sale date of %s could not be saved.', 'fishotel-misc-plugin' ), $label );
		}
		if ( $lines ) {
			self::queue_sale_notice( $lines, $result['invalid'] || $result['failed'] ? 'warning' : 'success' );
		}

		return $result;
	}

	/**
	 * The sale dates from the meta box into the stays (the testable core
	 * of save_meta_box()). Per open stay of the product present in the
	 * post: "Use the batch date" ticked → no own date; else a date equal
	 * to what the box showed → untouched (so the batch's date shown in the
	 * picker is never copied into an own date); else empty → no own date;
	 * else a real Y-m-d → that own date; anything else is reported and
	 * ignored. Only a stay whose own date really changes gets an event —
	 * the same "sale_ready:" note event the Stays tab and the phone write,
	 * so the Replay and the stay's history stay complete.
	 *
	 * @param object $store      Store (or stand-in) with stays_for_product() and record_event().
	 * @param int    $product_id Product id.
	 * @param array  $raw        Unslashed fh_cr_sale: stay id => { date, shown, batch }.
	 * @param string $today      Site date.
	 * @param array  $settings   Settings.
	 * @param int    $actor_id   User.
	 * @return array{events: int, changed: int[], invalid: string[], failed: string[]}
	 */
	public static function save_product_sale_dates( $store, $product_id, array $raw, $today, array $settings, $actor_id = 0 ) {
		$out = array(
			'events'  => 0,
			'changed' => array(),
			'invalid' => array(),
			'failed'  => array(),
		);
		if ( ! $raw ) {
			return $out;
		}

		foreach ( $store->stays_for_product( (int) $product_id, $today, $settings ) as $stay ) {
			$id = (int) $stay['id'];
			if ( 'closed' === (string) $stay['status'] || ! isset( $raw[ $id ] ) || ! is_array( $raw[ $id ] ) ) {
				continue;
			}
			$row   = $raw[ $id ];
			$date  = isset( $row['date'] ) && is_scalar( $row['date'] ) ? trim( sanitize_text_field( (string) $row['date'] ) ) : '';
			$shown = isset( $row['shown'] ) && is_scalar( $row['shown'] ) ? trim( sanitize_text_field( (string) $row['shown'] ) ) : '';
			$label = sprintf( '%s (%s)', (string) $stay['species'], ! empty( $stay['tank_number'] ) ? 'Tank ' . (int) $stay['tank_number'] : '#' . $id );

			if ( ! empty( $row['batch'] ) && '0' !== (string) $row['batch'] ) {
				$wanted = null;
			} elseif ( $date === $shown ) {
				continue; // Untouched.
			} elseif ( '' === $date ) {
				$wanted = null;
			} elseif ( Replay::is_date( $date ) ) {
				$wanted = $date;
			} else {
				$out['invalid'][] = $label . ': "' . substr( $date, 0, 20 ) . '"';
				continue;
			}

			$current = Replay::is_date( $stay['sale_ready_override'] ?? null ) ? (string) $stay['sale_ready_override'] : null;
			if ( $wanted === $current ) {
				continue;
			}

			$written = $store->record_event(
				$id,
				array(
					'type'     => 'note',
					'note'     => Replay::NOTE_SALE_READY . ( null !== $wanted ? $wanted : '' ),
					'event_at' => gmdate( 'Y-m-d H:i:s' ),
					'actor_id' => (int) $actor_id,
					'source'   => 'manual',
				),
				$today,
				$settings
			);
			if ( $written ) {
				$out['events']++;
				$out['changed'][] = $id;
			} else {
				$out['failed'][] = $label;
			}
		}

		return $out;
	}

	/**
	 * Keep notice lines for this user's next admin page (the product edit
	 * screen after the redirect).
	 *
	 * @param string[] $lines Lines.
	 * @param string   $type  success|warning|error.
	 */
	private static function queue_sale_notice( array $lines, $type ) {
		set_transient(
			self::SALE_NOTICE . (int) get_current_user_id(),
			array(
				'type'  => in_array( $type, array( 'success', 'warning', 'error' ), true ) ? $type : 'warning',
				'lines' => array_values( array_map( 'strval', $lines ) ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * admin_notices: the meta box's notice, once.
	 */
	public function sale_date_notices() {
		$key    = self::SALE_NOTICE . (int) get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['lines'] ) ) {
			return;
		}
		delete_transient( $key );

		echo '<div class="notice notice-' . esc_attr( (string) $notice['type'] ) . ' is-dismissible">';
		foreach ( (array) $notice['lines'] as $line ) {
			echo '<p>' . esc_html( (string) $line ) . '</p>';
		}
		echo '</div>';
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Enqueue CSS / JS on the section page (and the small bits on the
	 * product edit screen).
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_page    = false !== strpos( (string) $hook_suffix, Clean_Room::MENU_SLUG );
		$on_product = $screen && 'product' === $screen->post_type && in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );

		if ( ! $on_page && ! $on_product ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-cleanroom-admin',
			Clean_Room::url() . 'css/clean-room-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-cleanroom-admin',
			Clean_Room::url() . 'js/clean-room-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-cleanroom-admin',
			'fhCleanRoomAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_AJAX ),
				'settingsNonce' => wp_create_nonce( Settings::NONCE_ACTION ),
				'boxRates'      => $on_page ? Settings::box_rates() : array(),
				'i18n'          => array(
					'saved'          => __( 'Saved.', 'fishotel-misc-plugin' ),
					'error'          => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'confirmAlias'   => __( 'Forget this alias?', 'fishotel-misc-plugin' ),
					'confirmReset'   => __( 'Replace this template\'s steps with its seed (the workbook\'s schedule, or the daily feeding for a template of your own)?', 'fishotel-misc-plugin' ),
					'confirmTemplate' => __( 'Delete this template? Buildings that run it go back to the default.', 'fishotel-misc-plugin' ),
					'templateName'   => __( 'Name of the new template? (A seeded name — 14 Day CF, Xtra 14 Day CF, CQ, Trifecta — brings its schedule back.)', 'fishotel-misc-plugin' ),
					'templateRename' => __( 'New name for this template?', 'fishotel-misc-plugin' ),
					'confirmZero'    => __( 'Set the count to 0 and close this group?', 'fishotel-misc-plugin' ),
					'confirmUndo'    => __( 'Undo this event? (A compensating event is written; nothing is deleted.)', 'fishotel-misc-plugin' ),
					'reloading'      => __( 'Saved. Reloading…', 'fishotel-misc-plugin' ),
					'howMany'        => __( 'How many?', 'fishotel-misc-plugin' ),
					'which'          => __( 'Which date (YYYY-MM-DD)? Leave empty to clear.', 'fishotel-misc-plugin' ),
					/* translators: %s: the batch's sale date */
					'whichSaleBatch' => __( 'This group\'s own sale date (YYYY-MM-DD)? Leave empty to use the batch date (%s).', 'fishotel-misc-plugin' ),
					'whichSale'      => __( 'This group\'s own sale date (YYYY-MM-DD)? Leave empty for none — the batch has no date yet.', 'fishotel-misc-plugin' ),
					'whichTank'      => __( 'Move to which tank number?', 'fishotel-misc-plugin' ),
					'whyMove'        => __( 'Why? (optional: clear building / bullying / pairing / other)', 'fishotel-misc-plugin' ),
					'confirmCheckin' => __( 'Check the received fish in? One stay per line with fish (lines already checked in are skipped). The order becomes "received".', 'fishotel-misc-plugin' ),
					'confirmDelete'  => __( 'Delete this order and its lines?', 'fishotel-misc-plugin' ),
					'confirmSpecies' => __( 'Remove this species from the list?', 'fishotel-misc-plugin' ),
					'unsavedLines'   => __( 'Save the order first.', 'fishotel-misc-plugin' ),
					'noRate'         => __( 'No box rate for this supplier and origin (Settings → Box rates), or no box count.', 'fishotel-misc-plugin' ),
					'noMatch'        => __( 'No match — type the name to use it as an unlisted species.', 'fishotel-misc-plugin' ),
					'linked'         => __( 'Linked', 'fishotel-misc-plugin' ),
					'yes'            => __( 'Yes', 'fishotel-misc-plugin' ),
					'no'             => __( 'No', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  Stay edits from the Stays tab                                  */
	/* ============================================================== */

	/**
	 * AJAX: an edit on one stay — loss, sold, count, clear / unclear,
	 * ready (the stay's own sale date; empty = the batch's), note, move (whole group, optional reason), undo
	 * (one event, by id: a compensating event with the same idempotence
	 * guard as the phone, without its actor / age guard — the admin may
	 * fix anyone's mistake), or the seam fields (plus the check-in date).
	 * Per-stay counts stay editable at any time: Jeff loses fish after an
	 * order exists.
	 */
	public function ajax_stay() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		$raw      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$op       = isset( $raw['op'] ) ? sanitize_key( $raw['op'] ) : '';
		$stay_id  = isset( $raw['stay_id'] ) ? absint( $raw['stay_id'] ) : 0;
		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();
		$stay     = $store->stay( $stay_id, $today, $settings );

		if ( ! $stay ) {
			wp_send_json_error( array( 'message' => __( 'That stay no longer exists.', 'fishotel-misc-plugin' ) ), 404 );
		}

		$base = array(
			'actor_id' => (int) get_current_user_id(),
			'source'   => 'manual',
			'event_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$qty  = isset( $raw['qty'] ) && is_scalar( $raw['qty'] ) && is_numeric( $raw['qty'] ) ? (int) $raw['qty'] : 0;
		$date = isset( $raw['date'] ) ? sanitize_text_field( (string) $raw['date'] ) : '';
		$ok   = false;
		$msg  = '';

		try {
			switch ( $op ) {
				case 'loss':
				case 'sold':
					if ( $qty < 1 || $qty > (int) $stay['qty_live'] ) {
						wp_send_json_error( array( 'message' => __( 'How many? More than 0 and no more than are alive.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$ok  = $store->record_event( $stay_id, array_merge( $base, array( 'type' => $op, 'qty' => $qty, 'note' => 'loss' === $op ? sanitize_key( (string) ( $raw['reason'] ?? '' ) ) : '' ) ), $today, $settings );
					$msg = 'loss' === $op ? __( 'Loss recorded.', 'fishotel-misc-plugin' ) : __( 'Shipped recorded.', 'fishotel-misc-plugin' );
					if ( $ok && ! empty( $ok['overdrawn'] ) ) {
						$msg .= ' ' . __( 'The group now shows fewer fish than were taken out — recount it.', 'fishotel-misc-plugin' );
					}
					break;
				case 'count':
					if ( $qty < 0 || $qty > 999 ) {
						wp_send_json_error( array( 'message' => __( 'How many? 0–999.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$ok  = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'count', 'qty' => $qty ) ), $today, $settings );
					$msg = __( 'Count set.', 'fishotel-misc-plugin' );
					break;
				case 'clear':
					if ( '' === $date ) {
						$ok = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'clear', 'qty' => -1 ) ), $today, $settings );
					} elseif ( Replay::is_date( $date ) ) {
						// Recorded now, the date in the note: a correction to an earlier date wins too.
						$ok = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'clear', 'note' => Replay::NOTE_CLEARED . $date ) ), $today, $settings );
					} else {
						wp_send_json_error( array( 'message' => __( 'That is not a date.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$msg = __( 'Cleared date saved.', 'fishotel-misc-plugin' );
					break;
				case 'move':
					$to = $store->tank_by_number( isset( $raw['to_tank'] ) ? absint( $raw['to_tank'] ) : 0 );
					if ( ! $to || 'offline' === $to['status'] || 'offline' === (string) $to['building']['status'] ) {
						wp_send_json_error( array( 'message' => __( 'Which tank? That number is not in the layout.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( (int) $to['id'] === (int) $stay['tank_id'] ) {
						wp_send_json_error( array( 'message' => __( 'The group is already in that tank.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( 'closed' === (string) $stay['status'] ) {
						wp_send_json_error( array( 'message' => __( 'That group is closed.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$reason = isset( $raw['reason'] ) ? sanitize_key( str_replace( ' ', '_', trim( (string) $raw['reason'] ) ) ) : '';
					$reason = in_array( $reason, Front_Desk::MOVE_REASONS, true ) ? $reason : '';
					$ok     = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'move', 'from_tank_id' => $stay['tank_id'], 'to_tank_id' => (int) $to['id'], 'note' => $reason ) ), $today, $settings );
					/* translators: %d: tank number */
					$msg = sprintf( __( 'Moved to Tank %d.', 'fishotel-misc-plugin' ), (int) $to['number'] );
					break;
				case 'undo':
					$event = $store->event( isset( $raw['event_id'] ) ? absint( $raw['event_id'] ) : 0 );
					if ( ! $event || (int) $event['stay_id'] !== (int) $stay_id ) {
						wp_send_json_error( array( 'message' => __( 'That event no longer exists.', 'fishotel-misc-plugin' ) ), 404 );
					}
					if ( Replay::is_undone( (array) $stay['events'], (int) $event['id'] ) ) {
						wp_send_json_error( array( 'message' => __( 'Already undone.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$ok = $store->undo_event( $stay, $event, $base, $today, $settings );
					if ( null === $ok ) {
						wp_send_json_error( array( 'message' => __( 'Nothing to undo there.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$msg = __( 'Undone (a compensating event was written).', 'fishotel-misc-plugin' );
					break;
				case 'ready':
					if ( '' !== $date && ! Replay::is_date( $date ) ) {
						wp_send_json_error( array( 'message' => __( 'That is not a date.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$ok  = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . $date ) ), $today, $settings );
					$msg = '' !== $date ? __( 'Own sale date saved.', 'fishotel-misc-plugin' ) : __( 'Own sale date removed: the batch date applies.', 'fishotel-misc-plugin' );
					break;
				case 'note':
					$note = isset( $raw['note'] ) ? substr( sanitize_textarea_field( (string) $raw['note'] ), 0, 2000 ) : '';
					if ( '' === $note ) {
						wp_send_json_error( array( 'message' => __( 'Nothing to save.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$ok  = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'note', 'note' => $note ) ), $today, $settings );
					$msg = __( 'Note saved.', 'fishotel-misc-plugin' );
					break;
				case 'fields':
					$fields = array(
						'supplier'    => isset( $raw['supplier'] ) ? sanitize_text_field( (string) $raw['supplier'] ) : '',
						'order_ref'   => isset( $raw['order_ref'] ) ? sanitize_text_field( (string) $raw['order_ref'] ) : '',
						'landed_cost' => isset( $raw['landed_cost'] ) && '' !== trim( (string) $raw['landed_cost'] ) ? (float) $raw['landed_cost'] : null,
						'product_id'  => isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0,
					);
					if ( $fields['product_id'] > 0 && '' === $store->product_title( $fields['product_id'] ) ) {
						wp_send_json_error( array( 'message' => __( 'No product has that ID.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( isset( $raw['species'] ) && '' !== trim( (string) $raw['species'] ) ) {
						$fields['species'] = sanitize_text_field( (string) $raw['species'] );
					}
					$checkin = isset( $raw['checkin_date'] ) ? sanitize_text_field( (string) $raw['checkin_date'] ) : '';
					if ( '' !== $checkin && ! Replay::is_date( $checkin ) ) {
						wp_send_json_error( array( 'message' => __( 'The check-in date is not a date.', 'fishotel-misc-plugin' ) ), 400 );
					}
					if ( '' !== $checkin && strcmp( $checkin, $today ) > 0 ) {
						wp_send_json_error( array( 'message' => __( 'A check-in cannot be in the future.', 'fishotel-misc-plugin' ) ), 400 );
					}
					$ok = $store->update_stay_fields( $stay_id, $fields );
					if ( '' !== $checkin && $checkin !== (string) $stay['checkin_date'] ) {
						// The check-in date is history, not identity: a note event the Replay honours.
						$ok = $store->record_event( $stay_id, array_merge( $base, array( 'type' => 'note', 'note' => Replay::NOTE_CHECKIN . $checkin ) ), $today, $settings ) && $ok;
					}
					$msg = __( 'Stay saved.', 'fishotel-misc-plugin' );
					break;
				default:
					wp_send_json_error( array( 'message' => __( 'Unknown action.', 'fishotel-misc-plugin' ) ), 400 );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'That could not be saved.', 'fishotel-misc-plugin' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * AJAX: set the product's Coming Soon release moment to a stay's
	 * effective sale date (RELEASE_TIME, site zone). The product must
	 * exist; a stay never assumes one does. Nothing here runs on its own:
	 * stock, visibility and purchasability stay manual.
	 */
	public function ajax_release() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		$stay_id  = isset( $_POST['stay_id'] ) ? absint( wp_unslash( $_POST['stay_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();
		$stay     = $store->stay( $stay_id, $today, $settings );

		if ( ! $stay || empty( $stay['product_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This stay is not linked to a product.', 'fishotel-misc-plugin' ) ), 400 );
		}
		if ( ! Replay::is_date( $stay['sale_ready_date'] ?? null ) ) {
			wp_send_json_error( array( 'message' => __( 'This stay has no sale date yet — set one on its supplier order (Arrivals) or on the product page.', 'fishotel-misc-plugin' ) ), 400 );
		}

		$product_id = (int) $stay['product_id'];
		if ( 'product' !== get_post_type( $product_id ) || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No product has that ID (or you cannot edit it).', 'fishotel-misc-plugin' ) ), 400 );
		}

		$timestamp = self::release_timestamp( (string) $stay['sale_ready_date'] );
		if ( $timestamp <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'That date could not be read.', 'fishotel-misc-plugin' ) ), 400 );
		}

		update_post_meta( $product_id, self::RELEASE_META, $timestamp );
		if ( $timestamp > time() ) {
			delete_post_meta( $product_id, self::RELEASE_DISPATCHED );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		wp_send_json_success(
			array(
				/* translators: %s: date/time */
				'message' => sprintf( __( 'Coming Soon countdown set to %s.', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
			)
		);
	}

	/**
	 * The release moment of a sale date: RELEASE_TIME in the site
	 * zone, as a UNIX timestamp (pure apart from the zone).
	 *
	 * @param string $ymd Y-m-d.
	 * @return int
	 */
	public static function release_timestamp( $ymd ) {
		if ( ! Replay::is_date( $ymd ) ) {
			return 0;
		}
		try {
			$dt = new \DateTimeImmutable( $ymd . ' ' . self::RELEASE_TIME, Clean_Room::timezone() );
			return $dt->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/* ============================================================== */
	/*  CSV                                                            */
	/* ============================================================== */

	/**
	 * admin-post: the stays as CSV (open ones; closed too with ?closed=1).
	 */
	public function download_csv() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to download the stays.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_CSV );

		$closed   = isset( $_GET['closed'] ) && '1' === (string) wp_unslash( $_GET['closed'] );
		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();
		$stays    = $store->open_stays( array(), $today, $settings );
		if ( $closed ) {
			$stays = array_merge( $stays, $store->closed_stays( $today, $settings, 2000 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="fishotel-clean-room-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		self::write_csv( $out, $stays );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * The CSV header.
	 *
	 * @return string[]
	 */
	public static function csv_header() {
		return array( 'id', 'species', 'product_id', 'qty_arrived', 'qty_live', 'checkin_date', 'day', 'state', 'cleared_date', 'sale_ready_date', 'closed_date', 'tank', 'building', 'area', 'source', 'supplier', 'order_ref', 'landed_cost', 'notes' );
	}

	/**
	 * Write the header and every stay to a stream (pure apart from the
	 * stream).
	 *
	 * @param resource $out   Writable stream.
	 * @param array[]  $stays Hydrated stays.
	 * @return int Rows written (excluding the header).
	 */
	public static function write_csv( $out, array $stays ) {
		fputcsv( $out, self::csv_header() );
		$n = 0;
		foreach ( $stays as $stay ) {
			fputcsv( $out, self::csv_row( (array) $stay ) );
			$n++;
		}
		return $n;
	}

	/**
	 * A stay as guarded CSV cells (pure); sale_ready_date is the effective
	 * sale date (the stay's own, else its batch's), empty when none is set.
	 *
	 * @param array $stay Hydrated stay.
	 * @return string[]
	 */
	public static function csv_row( array $stay ) {
		$tank     = isset( $stay['tank_number'] ) && null !== $stay['tank_number'] ? (string) (int) $stay['tank_number'] : '';
		$building = '' !== $tank ? (string) ( (int) floor( (int) $tank / 100 ) * 100 ) : '';

		return array(
			self::csv_cell( (string) ( $stay['id'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['species'] ?? '' ) ),
			self::csv_cell( null !== ( $stay['product_id'] ?? null ) ? (string) $stay['product_id'] : '' ),
			self::csv_cell( (string) (int) ( $stay['qty_arrived'] ?? 0 ) ),
			self::csv_cell( (string) (int) ( $stay['qty_live'] ?? 0 ) ),
			self::csv_cell( (string) ( $stay['checkin_date'] ?? '' ) ),
			self::csv_cell( null !== ( $stay['day'] ?? null ) ? (string) $stay['day'] : '' ),
			self::csv_cell( (string) ( $stay['state_label'] ?? $stay['status'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['cleared_date'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['sale_ready_date'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['closed_date'] ?? '' ) ),
			self::csv_cell( $tank ),
			self::csv_cell( $building ),
			self::csv_cell( (string) ( $stay['area'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['source'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['supplier'] ?? '' ) ),
			self::csv_cell( (string) ( $stay['order_ref'] ?? '' ) ),
			self::csv_cell( null !== ( $stay['landed_cost'] ?? null ) ? number_format( (float) $stay['landed_cost'], 2, '.', '' ) : '' ),
			self::csv_cell( (string) ( $stay['notes'] ?? '' ) ),
		);
	}

	/**
	 * Neutralise a cell that a spreadsheet would run as a formula: a
	 * leading = + - @ tab or CR gets a single quote in front (pure).
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = is_scalar( $value ) || null === $value ? (string) $value : (string) json_encode( $value );

		if ( '' === $value ) {
			return '';
		}

		$first = $value[0];

		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ============================================================== */
	/*  Labels                                                         */
	/* ============================================================== */

	/**
	 * admin-post: the printable labels page — one label per active tank
	 * (in a building that is not offline) with the number large, the
	 * area / building / size small and a QR of the Front Desk URL.
	 */
	public function print_labels() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to print the labels.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_LABELS );

		$store  = new Store();
		$labels = array();
		foreach ( $store->tanks( false ) as $tank ) {
			if ( 'offline' === (string) $tank['building']['status'] ) {
				continue;
			}
			$label        = Layout::label( $tank, $tank['building'] );
			$url          = Clean_Room::front_desk_url( (int) $tank['number'] );
			$label['url'] = $url;
			$label['svg'] = Qr::svg( $url, 4, 4 );
			$labels[]     = $label;
		}
		$site = (string) get_bloginfo( 'name' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		include Clean_Room::path() . 'views/labels.php';
		exit;
	}

	/* ============================================================== */
	/*  Rendering helpers                                              */
	/* ============================================================== */

	/**
	 * The state badge of a stay, with the "still in the Spa" warning when
	 * it is for sale but not cleared yet.
	 *
	 * @param array $stay Hydrated stay.
	 * @return string Escaped HTML.
	 */
	public static function badge_html( array $stay ) {
		$status = (string) ( $stay['status'] ?? 'in_qt' );
		$key    = ! empty( $stay['sale_ready'] ) ? 'ready' : $status;
		$key    = in_array( $key, array( 'in_qt', 'cleared', 'ready', 'closed' ), true ) ? $key : 'in_qt';
		$words  = self::sale_words( $stay );

		$html = '<span class="fh-cr-badge fh-cr-badge--' . esc_attr( $key ) . '">' . esc_html( (string) ( $stay['state_label'] ?? $status ) ) . '</span>';
		if ( '' !== $words['warning'] ) {
			$html .= ' <span class="fh-cr-warn" title="' . esc_attr__( 'Its sale date has come but it has not moved to the Clean Room yet.', 'fishotel-misc-plugin' ) . '">' . esc_html( $words['warning'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * A stay's sale state in words (pure): the effective sale date ("on
	 * sale 2026-10-30") with where it comes from ("from the batch" / "own
	 * date"), or "Sale date not set" with the suggestion ("suggested …",
	 * check-in + the setting); the warning "still in the Spa" when it is
	 * for sale but not cleared.
	 *
	 * @param array $stay Hydrated stay.
	 * @return array{date: string, text: string, source: string, hint: string, warning: string}
	 */
	public static function sale_words( array $stay ) {
		$date = isset( $stay['sale_ready_date'] ) && Replay::is_date( $stay['sale_ready_date'] ) ? (string) $stay['sale_ready_date'] : '';
		$out  = array(
			'date'    => $date,
			'text'    => '',
			'source'  => '',
			'hint'    => '',
			'warning' => '',
		);

		if ( '' === $date ) {
			$out['text'] = __( 'Sale date not set', 'fishotel-misc-plugin' );
			$suggested   = (string) ( $stay['sale_ready_suggested'] ?? '' );
			if ( Replay::is_date( $suggested ) ) {
				/* translators: %s: date */
				$out['hint'] = sprintf( __( 'suggested %s', 'fishotel-misc-plugin' ), $suggested );
			}
		} else {
			/* translators: %s: date */
			$out['text']   = sprintf( __( 'on sale %s', 'fishotel-misc-plugin' ), $date );
			$out['source'] = Replay::SALE_OWN === (string) ( $stay['sale_date_source'] ?? '' ) ? __( 'own date', 'fishotel-misc-plugin' ) : __( 'from the batch', 'fishotel-misc-plugin' );
		}
		if ( ! empty( $stay['ready_in_spa'] ) ) {
			$out['warning'] = __( 'still in the Spa', 'fishotel-misc-plugin' );
		}

		return $out;
	}

	/**
	 * A UTC datetime as a site-time label.
	 *
	 * @param string $utc Y-m-d H:i:s (UTC).
	 * @return string
	 */
	public static function site_time( $utc ) {
		$ts = (int) strtotime( (string) $utc . ' UTC' );
		if ( $ts <= 0 ) {
			return (string) $utc;
		}

		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d H:i', $ts );
		}

		return gmdate( 'Y-m-d H:i', $ts );
	}

	/**
	 * An event in plain words (pure).
	 *
	 * @param array $event Hydrated event.
	 * @return string
	 */
	public static function event_text( array $event ) {
		$type = (string) ( $event['type'] ?? '' );
		$qty  = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null;
		$from = ! empty( $event['from_tank_number'] ) ? 'Tank ' . (int) $event['from_tank_number'] : '';
		$to   = ! empty( $event['to_tank_number'] ) ? 'Tank ' . (int) $event['to_tank_number'] : '';
		$undo = Replay::is_reversal( $event ) ? ' (undo)' : '';
		$note = (string) ( $event['note'] ?? '' );

		switch ( $type ) {
			case 'arrival':
				return sprintf( '%d arrived%s%s', abs( (int) $qty ), '' !== $to ? ' in ' . $to : '', $undo );
			case 'loss':
				$reason = '' !== $note && 0 !== strpos( $note, 'undo' ) ? ' (' . $note . ')' : '';
				return sprintf( '%d lost%s%s', abs( null === $qty ? 1 : $qty ), $reason, $undo );
			case 'sold':
				return sprintf( '%d shipped%s', abs( null === $qty ? 1 : $qty ), $undo );
			case 'move':
				$why = self::move_reason( $note );
				$why = '' !== $why ? ' (' . $why . ')' : '';
				if ( null !== $qty && 0 !== $qty ) {
					return sprintf( '%d split off %s → %s%s%s', abs( $qty ), $from, $to, $why, $undo );
				}
				return sprintf( 'moved %s → %s%s%s', $from, $to, $why, $undo );
			case 'clear':
				if ( null !== $qty && $qty < 0 ) {
					return 'cleared date removed' . $undo;
				}
				$date = Replay::note_date( $note, Replay::NOTE_CLEARED );
				return ( '' !== $date ? 'cleared date set to ' . $date : 'cleared' ) . $undo;
			case 'count':
				return sprintf( 'recount: %d%s', null === $qty ? 0 : $qty, $undo );
			case 'note':
				if ( 0 === strpos( $note, Replay::NOTE_SALE_READY ) ) {
					$date = trim( substr( $note, strlen( Replay::NOTE_SALE_READY ) ) );
					return '' !== $date ? 'own sale date set to ' . $date : 'own sale date removed (the batch date applies)';
				}
				if ( 0 === strpos( $note, Replay::NOTE_CHECKIN ) ) {
					$date = trim( substr( $note, strlen( Replay::NOTE_CHECKIN ) ) );
					return '' !== $date ? 'check-in date set to ' . $date : 'check-in date back to the arrival';
				}
				return 'note: ' . $note;
			default:
				return $type;
		}
	}

	/**
	 * The reason behind a move event, in words (pure): the note minus the
	 * split marker ('split to #5 · bullying' → 'bullying'), underscores as
	 * spaces; '' for an undo or a move without a reason.
	 *
	 * @param string $note The move event's note.
	 * @return string
	 */
	public static function move_reason( $note ) {
		$note = trim( (string) $note );
		if ( '' === $note || 0 === strpos( $note, 'undo' ) ) {
			return '';
		}
		$note = trim( (string) preg_replace( '/^split (?:to|from) #\d+\s*(?:·\s*)?/', '', $note ) );
		return str_replace( '_', ' ', $note );
	}

	/**
	 * A tank log entry in plain words (pure). A dose reads like the Front
	 * Desk's toast ("Metronidazole 5.1 g · Building 500, whole system"); a
	 * building-level entry of any kind says so; an import marker in the
	 * note is kept out of sight.
	 *
	 * @param array $log Hydrated log.
	 * @return string
	 */
	public static function log_text( array $log ) {
		$kind  = (string) ( $log['kind'] ?? 'note' );
		$note  = Import::strip_marker( (string) ( $log['note'] ?? '' ) );
		$where = '';
		if ( (int) ( $log['tank_id'] ?? 0 ) <= 0 && ! empty( $log['building_id'] ) ) {
			/* translators: %s: building label */
			$where = ' · ' . sprintf( __( '%s, whole system', 'fishotel-misc-plugin' ), Layout::building_label( array( 'number' => (int) ( $log['building_number'] ?? 0 ), 'name' => '' ) ) );
		}
		if ( 'copper' === $kind ) {
			return 'copper ' . ( null !== ( $log['value'] ?? null ) ? rtrim( rtrim( number_format( (float) $log['value'], 2, '.', '' ), '0' ), '.' ) : '?' ) . $where;
		}
		if ( 'dose' === $kind ) {
			return $note . $where;
		}
		if ( 'note' === $kind ) {
			return 'note: ' . $note . $where;
		}
		return str_replace( '_', ' ', $kind ) . ': ' . $note . $where;
	}
}
