<?php
/**
 * Foam Cuts admin — the page under FisHotel Tools (Plan / Stock / Boxes /
 * Settings) and the printable cut sheet.
 *
 * Every write goes through ONE admin-post action (fishotel_foam_cuts)
 * with a "do" field: manage_woocommerce first, then the nonce of that
 * very "do" (fishotel_foam_cuts_<do>), then the work, then a redirect
 * back to the page with a one-time notice. Nothing is written on a GET.
 *
 * The printable page is another admin-post action (GET): a logged-in user
 * with manage_woocommerce AND either a normal nonce (_wpnonce, the links
 * on the page) or the plan's print key (the URL the REST route hands
 * out). A link that fails that check shows a button to open the sheet
 * instead of the sheet — never an error page with no way on.
 *
 * The views escape every value; the drawings come from Svg (escaped for
 * XML, nothing loaded from anywhere); there is no JavaScript beyond the
 * browser's own confirm() and print().
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * The admin-post action every write goes through.
	 *
	 * @var string
	 */
	const ACTION = 'fishotel_foam_cuts';

	/**
	 * Prefix of the per-user one-time notice (a transient).
	 *
	 * @var string
	 */
	const FLASH = 'fishotel_foam_cuts_flash_';

	/**
	 * The tabs.
	 *
	 * @var string[]
	 */
	const TABS = array( 'plan', 'stock', 'boxes', 'settings' );

	/**
	 * What "do" may be, and the method that does it.
	 *
	 * @var array<string, string>
	 */
	const HANDLERS = array(
		'plan'            => 'do_plan',
		'cut'             => 'do_cut',
		'undo'            => 'do_undo',
		'delete'          => 'do_delete',
		'stock_buy'       => 'do_stock_buy',
		'stock_count'     => 'do_stock_count',
		'leftover_add'    => 'do_leftover_add',
		'leftover_resize' => 'do_leftover_resize',
		'leftover_remove' => 'do_leftover_remove',
		'boxes_save'      => 'do_boxes_save',
		'cutlist_save'    => 'do_cutlist_save',
		'cutlist_reset'   => 'do_cutlist_reset',
		'settings_save'   => 'do_settings_save',
	);

	/**
	 * How many spare rows a list form offers.
	 *
	 * @var int
	 */
	const SPARE_ROWS = 3;

	/**
	 * The store (a test may set its own).
	 *
	 * @var object|null
	 */
	public $store = null;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . Planner::PRINT_ACTION, array( $this, 'print_page' ) );
		add_action( 'admin_post_nopriv_' . Planner::PRINT_ACTION, array( $this, 'login_first' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
	}

	/**
	 * The store.
	 *
	 * @return object
	 */
	private function store() {
		return $this->store ? $this->store : new Store();
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab   plan|stock|boxes|settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'plan', array $extra = array() ) {
		$args = array( 'page' => Foam_Cuts::MENU_SLUG );
		if ( 'plan' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The nonce action of a "do".
	 *
	 * @param string $do One of HANDLERS' keys.
	 * @return string
	 */
	public static function nonce_action( $do ) {
		return self::ACTION . '_' . $do;
	}

	/**
	 * The nonce action of a plan's printable page (the links on the page).
	 *
	 * @param int $plan_id Plan id.
	 * @return string
	 */
	public static function print_nonce_action( $plan_id ) {
		return Planner::PRINT_ACTION . '_' . (int) $plan_id;
	}

	/**
	 * The URL of a plan's printable page with a normal nonce (for a link
	 * on a wp-admin page).
	 *
	 * @param int $plan_id Plan id.
	 * @return string
	 */
	public static function print_link( $plan_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => Planner::PRINT_ACTION,
					'plan'   => (int) $plan_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::print_nonce_action( $plan_id )
		);
	}

	/**
	 * The hidden fields every write form starts with.
	 *
	 * @param string $do    One of HANDLERS' keys.
	 * @param array  $extra Extra hidden fields.
	 */
	public static function form_fields( $do, array $extra = array() ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '" />';
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		wp_nonce_field( self::nonce_action( $do ) );
	}

	/* ============================================================== */
	/*  Page                                                           */
	/* ============================================================== */

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Foam_Cuts::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: which tab, which plan, which day.
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'plan';
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'plan';
		}
		$plan_id = isset( $_GET['plan'] ) && is_scalar( $_GET['plan'] ) ? absint( $_GET['plan'] ) : 0;
		$ship    = isset( $_GET['ship'] ) && is_scalar( $_GET['ship'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ship'] ) ) : '';
		// phpcs:enable

		$store     = $this->store();
		$installed = $this->store ? true : Store::installed();
		$flash     = self::take_flash();
		$context   = Planner::context();

		echo '<div class="wrap fishotel-misc-wrap fh-fc-wrap">';
		include Foam_Cuts::path() . 'views/tabs.php';

		if ( ! $installed ) {
			echo '<div class="fh-fc-notice is-error">' . esc_html__( 'The Foam Cuts tables could not be created, so plans and stock cannot be saved yet. Reload this page in a minute; if this stays, the database user may not be allowed to create tables.', 'fishotel-misc-plugin' ) . '</div>';
		}

		if ( 'stock' === $tab ) {
			$sheets    = $installed ? (int) $store->sheets() : 0;
			$entries   = $installed ? $store->sheet_log( 15 ) : array();
			$leftovers = $installed ? $store->leftovers( 'stock' ) : array();
			include Foam_Cuts::path() . 'views/stock.php';
		} elseif ( 'boxes' === $tab ) {
			$orphans = array_diff_key( $context['settings']['cut_lists'], array_flip( array_column( $context['boxes'], 'key' ) ) );
			include Foam_Cuts::path() . 'views/boxes.php';
		} elseif ( 'settings' === $tab ) {
			$settings = $context['settings'];
			include Foam_Cuts::path() . 'views/settings.php';
		} else {
			$stock  = $installed ? Stock::snapshot( $store ) : array( 'sheets' => 0, 'leftovers' => array() );
			$plans  = $installed ? $store->plans( 12 ) : array();
			$plan   = $installed && $plan_id > 0 ? $store->plan( $plan_id ) : null;
			$state  = $plan ? Stock::state( $store, $plan ) : array( 'stale' => array(), 'undo_blockers' => array() );
			$orders = null;
			$reason = Orders::unavailable_reason();
			$today  = Orders::today();

			if ( '' !== $ship && '' === $reason ) {
				$orders = Orders::for_ship_date( Orders::is_date( $ship ) ? $ship : $today, $context['boxes'] );
			}

			include Foam_Cuts::path() . 'views/plan.php';
		}

		echo '</div>';
	}

	/**
	 * CSS on the section page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Foam_Cuts::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-foam-cuts-admin',
			Foam_Cuts::url() . 'css/foam-cuts-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);
	}

	/* ============================================================== */
	/*  One-time notices                                               */
	/* ============================================================== */

	/**
	 * Keep a notice for the next page load of this user.
	 *
	 * @param bool            $ok    Whether it is good news.
	 * @param string|string[] $lines The text.
	 */
	public static function flash( $ok, $lines ) {
		set_transient(
			self::FLASH . (int) get_current_user_id(),
			array(
				'ok'    => (bool) $ok,
				'lines' => array_values( array_filter( array_map( 'strval', (array) $lines ), 'strlen' ) ),
			),
			120
		);
	}

	/**
	 * The notice kept for this user, once.
	 *
	 * @return array{ok: bool, lines: string[]}|null
	 */
	public static function take_flash() {
		$key   = self::FLASH . (int) get_current_user_id();
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) || empty( $flash['lines'] ) ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'ok'    => ! empty( $flash['ok'] ),
			'lines' => array_map( 'strval', (array) $flash['lines'] ),
		);
	}

	/* ============================================================== */
	/*  Writes                                                         */
	/* ============================================================== */

	/**
	 * admin-post: every write. Capability, then the nonce of this "do",
	 * then the handler, then back to the page.
	 */
	public function handle() {
		if ( ! current_user_can( Foam_Cuts::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines down, per "do".
		$do = isset( $_POST['do'] ) && is_scalar( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		if ( ! isset( self::HANDLERS[ $do ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'fishotel-misc-plugin' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::nonce_action( $do ) );

		$post   = wp_unslash( $_POST );
		$method = self::HANDLERS[ $do ];
		$result = $this->$method( $this->store(), is_array( $post ) ? $post : array() );

		self::flash( $result['ok'], $result['message'] );

		wp_safe_redirect( self::page_url( isset( $result['tab'] ) ? $result['tab'] : 'plan', isset( $result['args'] ) ? $result['args'] : array() ) );
		exit;
	}

	/**
	 * A handler's answer.
	 *
	 * @param array  $result { ok, message } from a service.
	 * @param string $tab    The tab to go back to.
	 * @param array  $args   Extra query args for that tab.
	 * @return array{ok: bool, message: string|string[], tab: string, args: array}
	 */
	private static function answer( array $result, $tab, array $args = array() ) {
		return array(
			'ok'      => ! empty( $result['ok'] ),
			'message' => isset( $result['message'] ) ? $result['message'] : '',
			'tab'     => $tab,
			'args'    => $args,
		);
	}

	/**
	 * A plan row from a posted id, or null.
	 *
	 * @param object $store Store.
	 * @param array  $post  Posted fields.
	 * @return array|null
	 */
	private static function posted_plan( $store, array $post ) {
		$id = isset( $post['plan'] ) && is_scalar( $post['plan'] ) ? absint( $post['plan'] ) : 0;
		return $id > 0 ? $store->plan( $id ) : null;
	}

	/**
	 * A posted text field as a string ('' when absent or not text).
	 *
	 * @param array  $post Posted fields.
	 * @param string $key  Field.
	 * @return string
	 */
	private static function text( array $post, $key ) {
		return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? sanitize_text_field( (string) $post[ $key ] ) : '';
	}

	/**
	 * Make a plan from the numbers typed beside the boxes — with the
	 * orders of a day remembered on it when the numbers were filled in
	 * from them.
	 *
	 * @param object $store Store.
	 * @param array  $post  boxes[i][key], boxes[i][count]; origin, ship_date.
	 * @return array
	 */
	public function do_plan( $store, array $post ) {
		$counts = array();
		foreach ( isset( $post['boxes'] ) && is_array( $post['boxes'] ) ? $post['boxes'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['key'], $row['count'] ) && is_scalar( $row['key'] ) && is_scalar( $row['count'] ) && (int) $row['count'] > 0 ) {
				$counts[ Boxes::key( (string) $row['key'] ) ] = (int) $row['count'];
			}
		}

		$for  = array( 'origin' => 'typed' );
		$ship = self::text( $post, 'ship_date' );

		if ( 'orders' === self::text( $post, 'origin' ) && Orders::is_date( $ship ) && '' === Orders::unavailable_reason() ) {
			$context = Planner::context();
			$orders  = Orders::for_ship_date( $ship, $context['boxes'] );
			if ( $orders['ok'] ) {
				$for = array(
					'origin'        => 'orders',
					'ship_date'     => $orders['ship_date'],
					'delivery_date' => $orders['delivery_date'],
					'orders'        => $orders['orders'],
					'extra'         => $orders['extra'],
				);
			}
		}

		$made = Planner::create( $store, $counts, $for, (int) get_current_user_id() );

		return self::answer( $made, 'plan', $made['ok'] ? array( 'plan' => (int) $made['id'] ) : ( 'orders' === $for['origin'] ? array( 'ship' => $ship ) : array() ) );
	}

	/**
	 * Mark a plan as cut.
	 *
	 * @param object $store Store.
	 * @param array  $post  plan.
	 * @return array
	 */
	public function do_cut( $store, array $post ) {
		$plan = self::posted_plan( $store, $post );
		if ( ! $plan ) {
			return self::answer( array( 'ok' => false, 'message' => __( 'That plan does not exist any more.', 'fishotel-misc-plugin' ) ), 'plan' );
		}
		return self::answer( Planner::cut( $store, $plan, (int) get_current_user_id() ), 'plan', array( 'plan' => (int) $plan['id'] ) );
	}

	/**
	 * Undo a plan that was marked as cut.
	 *
	 * @param object $store Store.
	 * @param array  $post  plan.
	 * @return array
	 */
	public function do_undo( $store, array $post ) {
		$plan = self::posted_plan( $store, $post );
		if ( ! $plan ) {
			return self::answer( array( 'ok' => false, 'message' => __( 'That plan does not exist any more.', 'fishotel-misc-plugin' ) ), 'plan' );
		}
		return self::answer( Planner::undo( $store, $plan, (int) get_current_user_id() ), 'plan', array( 'plan' => (int) $plan['id'] ) );
	}

	/**
	 * Delete a draft.
	 *
	 * @param object $store Store.
	 * @param array  $post  plan.
	 * @return array
	 */
	public function do_delete( $store, array $post ) {
		$plan = self::posted_plan( $store, $post );
		if ( ! $plan ) {
			return self::answer( array( 'ok' => false, 'message' => __( 'That plan does not exist any more.', 'fishotel-misc-plugin' ) ), 'plan' );
		}
		$result = Planner::delete( $store, $plan );
		return self::answer( $result, 'plan', $result['ok'] ? array() : array( 'plan' => (int) $plan['id'] ) );
	}

	/**
	 * "Bought N sheets".
	 *
	 * @param object $store Store.
	 * @param array  $post  sheets, note.
	 * @return array
	 */
	public function do_stock_buy( $store, array $post ) {
		return self::answer( Stock::buy_sheets( $store, self::text( $post, 'sheets' ), self::text( $post, 'note' ), (int) get_current_user_id() ), 'stock' );
	}

	/**
	 * Correct the sheet count.
	 *
	 * @param object $store Store.
	 * @param array  $post  sheets, note.
	 * @return array
	 */
	public function do_stock_count( $store, array $post ) {
		return self::answer( Stock::count_sheets( $store, self::text( $post, 'sheets' ), self::text( $post, 'note' ), (int) get_current_user_id() ), 'stock' );
	}

	/**
	 * Add a leftover by hand.
	 *
	 * @param object $store Store.
	 * @param array  $post  length, width, note.
	 * @return array
	 */
	public function do_leftover_add( $store, array $post ) {
		return self::answer( Stock::add_leftover( $store, self::text( $post, 'length' ), self::text( $post, 'width' ), self::text( $post, 'note' ) ), 'stock' );
	}

	/**
	 * Resize a leftover.
	 *
	 * @param object $store Store.
	 * @param array  $post  leftover, length, width.
	 * @return array
	 */
	public function do_leftover_resize( $store, array $post ) {
		return self::answer( Stock::resize_leftover( $store, isset( $post['leftover'] ) && is_scalar( $post['leftover'] ) ? absint( $post['leftover'] ) : 0, self::text( $post, 'length' ), self::text( $post, 'width' ) ), 'stock' );
	}

	/**
	 * Remove a leftover.
	 *
	 * @param object $store Store.
	 * @param array  $post  leftover.
	 * @return array
	 */
	public function do_leftover_remove( $store, array $post ) {
		return self::answer( Stock::remove_leftover( $store, isset( $post['leftover'] ) && is_scalar( $post['leftover'] ) ? absint( $post['leftover'] ) : 0 ), 'stock' );
	}

	/**
	 * Save this section's own box list.
	 *
	 * @param object $store Store (not used: the list is a setting).
	 * @param array  $post  own[i][name|length|width|height] in inches.
	 * @return array
	 */
	public function do_boxes_save( $store, array $post ) {
		$rows  = isset( $post['own'] ) && is_array( $post['own'] ) ? $post['own'] : array();
		$typed = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && '' !== trim( implode( '', array_map( 'strval', array_filter( $row, 'is_scalar' ) ) ) ) ) {
				$typed++;
			}
		}

		$boxes             = Boxes::read( $rows );
		$settings          = Settings::get();
		$settings['boxes'] = $boxes;
		Settings::save( $settings );

		$message = array(
			/* translators: %d: boxes */
			sprintf( _n( '%d box saved.', '%d boxes saved.', count( $boxes ), 'fishotel-misc-plugin' ), count( $boxes ) ),
		);
		if ( $typed > count( $boxes ) ) {
			/* translators: %d: rows */
			$message[] = sprintf( _n( '%d row was left out: a box needs a name of its own and a length, width and height above 0.', '%d rows were left out: a box needs a name of its own and a length, width and height above 0.', $typed - count( $boxes ), 'fishotel-misc-plugin' ), $typed - count( $boxes ) );
		}

		return self::answer( array( 'ok' => true, 'message' => $message ), 'boxes' );
	}

	/**
	 * Save the cut list of one box.
	 *
	 * @param object $store Store (not used).
	 * @param array  $post  box (name), pieces[i][name|length|width|count] in inches.
	 * @return array
	 */
	public function do_cutlist_save( $store, array $post ) {
		$context = Planner::context();
		$box     = Boxes::find( $context['boxes'], self::text( $post, 'box' ) );

		if ( null === $box ) {
			return self::answer( array( 'ok' => false, 'message' => __( 'That box is not on the list any more.', 'fishotel-misc-plugin' ) ), 'boxes' );
		}

		$pieces = Cut_List::sanitize( isset( $post['pieces'] ) && is_array( $post['pieces'] ) ? $post['pieces'] : array(), true );
		if ( ! $pieces ) {
			return self::answer( array( 'ok' => false, 'message' => __( 'A cut list needs at least one piece with a length, a width and a count. Nothing was changed.', 'fishotel-misc-plugin' ) ), 'boxes' );
		}

		$settings                             = $context['settings'];
		$settings['cut_lists'][ $box['key'] ] = array(
			'name'   => $box['name'],
			'box'    => Cut_List::box_signature( $box['length'], $box['width'], $box['height'] ),
			'pieces' => $pieces,
		);
		Settings::save( $settings );

		/* translators: 1: box name, 2: pieces per box */
		return self::answer( array( 'ok' => true, 'message' => sprintf( __( 'Cut list of "%1$s" saved: %2$d pieces per box.', 'fishotel-misc-plugin' ), $box['name'], Cut_List::piece_count( $pieces ) ) ), 'boxes' );
	}

	/**
	 * Forget the saved cut list of a box (the suggestion is used again).
	 *
	 * @param object $store Store (not used).
	 * @param array  $post  box (name or key).
	 * @return array
	 */
	public function do_cutlist_reset( $store, array $post ) {
		$key      = Boxes::key( self::text( $post, 'box' ) );
		$settings = Settings::get();

		if ( '' === $key || ! isset( $settings['cut_lists'][ $key ] ) ) {
			return self::answer( array( 'ok' => false, 'message' => __( 'There is no saved cut list for that box.', 'fishotel-misc-plugin' ) ), 'boxes' );
		}

		$name = $settings['cut_lists'][ $key ]['name'];
		unset( $settings['cut_lists'][ $key ] );
		Settings::save( $settings );

		/* translators: %s: box name */
		return self::answer( array( 'ok' => true, 'message' => sprintf( __( 'The saved cut list of "%s" is forgotten; the suggestion is used again.', 'fishotel-misc-plugin' ), $name ) ), 'boxes' );
	}

	/**
	 * Save the settings.
	 *
	 * @param object $store Store (not used).
	 * @param array  $post  sheet_length, sheet_width, thickness, kerf, rotate, min_length, min_width (inches).
	 * @return array
	 */
	public function do_settings_save( $store, array $post ) {
		$form = Settings::from_form( Settings::get(), $post );
		Settings::save( $form['settings'] );

		return self::answer(
			array(
				'ok'      => ! $form['problems'],
				'message' => $form['problems'] ? array_merge( $form['problems'], array( __( 'The other settings were saved.', 'fishotel-misc-plugin' ) ) ) : __( 'Settings saved. They apply to the next plan; plans already made keep the settings they were made with.', 'fishotel-misc-plugin' ),
			),
			'settings'
		);
	}

	/* ============================================================== */
	/*  The printable page                                             */
	/* ============================================================== */

	/**
	 * Who gets what on the printable page (pure given its inputs).
	 *
	 * @param bool       $can     Whether the user has the capability.
	 * @param array|null $plan    The plan row, or null.
	 * @param int        $user_id The current user.
	 * @param mixed      $nonce   The _wpnonce query arg.
	 * @param mixed      $key     The key query arg.
	 * @return string 'forbidden' | 'missing' | 'confirm' | 'ok'
	 */
	public static function print_access( $can, $plan, $user_id, $nonce, $key ) {
		if ( ! $can || (int) $user_id < 1 ) {
			return 'forbidden';
		}
		if ( ! is_array( $plan ) ) {
			return 'missing';
		}

		if ( is_string( $nonce ) && '' !== $nonce && wp_verify_nonce( $nonce, self::print_nonce_action( (int) $plan['id'] ) ) ) {
			return 'ok';
		}
		if ( Planner::verify_print_key( $key, (int) $plan['id'], (int) $user_id ) ) {
			return 'ok';
		}

		return 'confirm';
	}

	/**
	 * admin-post for a visitor who is not logged in (a print link opened in
	 * a browser whose session has ended): to the login screen and back —
	 * nothing of a plan is shown.
	 */
	public function login_first() {
		auth_redirect();
		exit;
	}

	/**
	 * admin-post (GET): the printable cut sheet of one plan.
	 */
	public function print_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce / key is what print_access() checks.
		$plan_id = isset( $_GET['plan'] ) && is_scalar( $_GET['plan'] ) ? absint( $_GET['plan'] ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		$key     = isset( $_GET['key'] ) && is_scalar( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : '';
		// phpcs:enable

		$can = current_user_can( Foam_Cuts::CAPABILITY );
		if ( ! $can ) {
			wp_die( esc_html__( 'You do not have permission to print the cut sheet.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		$store  = $this->store();
		$plan   = $plan_id > 0 ? $store->plan( $plan_id ) : null;
		$access = self::print_access( $can, $plan, (int) get_current_user_id(), $nonce, $key );

		if ( 'missing' === $access ) {
			wp_die( esc_html__( 'There is no foam cut plan with that number.', 'fishotel-misc-plugin' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		echo self::print_html( $plan, $store, 'ok' !== $access ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a whole document; the view escapes every value.
		exit;
	}

	/**
	 * The printable page as a whole HTML document.
	 *
	 * @param array  $plan    Plan row (with data).
	 * @param object $store   Store.
	 * @param bool   $confirm True = show only a button to open the sheet (the link's nonce did not check out).
	 * @return string
	 */
	public static function print_html( array $plan, $store, $confirm = false ) {
		$data  = isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array();
		$state = Stock::state( $store, $plan );
		$link  = self::print_link( (int) $plan['id'] );
		$site  = (string) get_bloginfo( 'name' );

		ob_start();
		include Foam_Cuts::path() . 'views/print.php';
		return (string) ob_get_clean();
	}

	/* ============================================================== */
	/*  Rendering helpers                                              */
	/* ============================================================== */

	/**
	 * The pieces of a source grouped for reading: the same box, piece and
	 * size on one line with how many.
	 *
	 * @param array $pieces A source's pieces.
	 * @return array<int, array{count: int, box: string, name: string, size: string}>
	 */
	public static function group_pieces( array $pieces ) {
		$out = array();
		foreach ( $pieces as $piece ) {
			$key = $piece['box_key'] . '|' . $piece['name'] . '|' . $piece['length'] . 'x' . $piece['width'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'count' => 0,
					'box'   => (string) $piece['box'],
					'name'  => (string) $piece['name'],
					'size'  => Svg::size( $piece['length'], $piece['width'] ),
				);
			}
			$out[ $key ]['count']++;
		}
		return array_values( $out );
	}

	/**
	 * A stored UTC time in the site's zone, for people.
	 *
	 * @param string $utc    "Y-m-d H:i:s" UTC.
	 * @param string $format Date format.
	 * @return string '' when there is no time.
	 */
	public static function when( $utc, $format = 'M j, g:i a' ) {
		$utc = (string) $utc;
		if ( '' === $utc || 0 === strpos( $utc, '0000' ) ) {
			return '';
		}
		return (string) get_date_from_gmt( $utc, $format );
	}

	/**
	 * The sheet count as shown: never below 0.
	 *
	 * @param int $sheets The entries added up.
	 * @return int
	 */
	public static function sheets_shown( $sheets ) {
		return max( 0, (int) $sheets );
	}

	/**
	 * A sheet entry in words.
	 *
	 * @param array $entry Store::sheet_log() row.
	 * @return string
	 */
	public static function entry_text( array $entry ) {
		$n = abs( (int) $entry['sheets'] );

		switch ( $entry['kind'] ) {
			case 'bought':
				/* translators: %d: sheets */
				return sprintf( _n( 'Bought %d sheet', 'Bought %d sheets', $n, 'fishotel-misc-plugin' ), $n );
			case 'plan':
				/* translators: 1: plan number, 2: sheets */
				return sprintf( _n( 'Plan %1$d took %2$d sheet', 'Plan %1$d took %2$d sheets', $n, 'fishotel-misc-plugin' ), (int) $entry['plan_id'], $n );
			case 'undo':
				/* translators: 1: plan number, 2: sheets */
				return sprintf( _n( 'Plan %1$d undone: %2$d sheet back', 'Plan %1$d undone: %2$d sheets back', $n, 'fishotel-misc-plugin' ), (int) $entry['plan_id'], $n );
		}

		return (int) $entry['sheets'] >= 0
			/* translators: %d: sheets */
			? sprintf( _n( 'Count corrected: %d more', 'Count corrected: %d more', $n, 'fishotel-misc-plugin' ), $n )
			/* translators: %d: sheets */
			: sprintf( _n( 'Count corrected: %d fewer', 'Count corrected: %d fewer', $n, 'fishotel-misc-plugin' ), $n );
	}
}
