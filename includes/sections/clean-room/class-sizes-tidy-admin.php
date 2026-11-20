<?php
/**
 * Tidy up the sizes — the tool on Settings → Sizes: its card, its
 * buttons' AJAX and its own script and stylesheet.
 *
 * It is the owner's tool: every request needs manage_options and the
 * tool's own nonce, and the tool is not there at all unless WooCommerce
 * is active. Nothing here runs by itself — no cron, no activation hook,
 * nothing on an upgrade: only a button does anything.
 *
 * One AJAX action (ACTION), by `op`:
 *   preview  the dry run: the map and what would change. Writes nothing.
 *   apply    start a run of that plan — after "I have a backup" is
 *            ticked, CONFIRM_WORD is typed and the plan is still the one
 *            that was shown (its token). Saves the record; the shop is
 *            written by the steps.
 *   step     one batch of the run (recording, applying or undoing).
 *   undo     take the latest run back (then steps).
 *   dismiss  tick a fish off the "Sizes to fill in" list.
 *   status   the tool's status panel again.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sizes_Tidy_Admin
 */
class Sizes_Tidy_Admin {

	const ACTION       = 'fishotel_cleanroom_sizes_tidy';
	const NONCE        = 'fishotel_cleanroom_sizes_tidy';
	const CAPABILITY   = 'manage_options';
	const CONFIRM_WORD = 'TIDY';

	/**
	 * Posted lists are cut at this many entries.
	 */
	const MAX_POSTED = 5000;

	/**
	 * The shop the tool works on (the tests hand it an in-memory one).
	 *
	 * @var Sizes_Tidy_Shop|null
	 */
	private static $shop = null;

	/**
	 * The store the size attribute is read through (the tests' stand-in).
	 *
	 * @var object|null
	 */
	private static $store = null;

	/**
	 * Hooks: the AJAX action and the tool's assets. Called in wp-admin
	 * only, when the section is on.
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 21 );
	}

	/**
	 * The shop.
	 *
	 * @return Sizes_Tidy_Shop
	 */
	public static function shop() {
		if ( null === self::$shop ) {
			require_once Clean_Room::path() . 'class-sizes-tidy-site.php';
			self::$shop = new Sizes_Tidy_Site();
		}
		return self::$shop;
	}

	/**
	 * Work on another shop (the tests), or back on the real one (null).
	 *
	 * @param Sizes_Tidy_Shop|null $shop  Shop.
	 * @param object|null          $store The store the size attribute is read through.
	 */
	public static function use_shop( $shop, $store = null ) {
		self::$shop  = $shop instanceof Sizes_Tidy_Shop ? $shop : null;
		self::$store = is_object( $store ) ? $store : null;
	}

	/**
	 * Whether the tool is there for this user: an owner, and WooCommerce
	 * is active.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return current_user_can( self::CAPABILITY ) && self::shop()->available();
	}

	/**
	 * The size attribute the tool works on — the one the section uses
	 * (Settings → Sizes) — or '' when the shop has none.
	 *
	 * @param object|null $store    Store (or the tests' stand-in).
	 * @param array|null  $settings Settings.
	 * @return string Taxonomy.
	 */
	public static function taxonomy( $store = null, $settings = null ) {
		if ( null === $store ) {
			$store = null !== self::$store ? self::$store : new Store();
		}
		$standard = Sizes::standard( $store, null !== $settings ? $settings : Settings::get() );
		return Sizes::SOURCE_ATTRIBUTE === $standard['source'] ? (string) $standard['attribute'] : '';
	}

	/* ============================================================== */
	/*  The card                                                       */
	/* ============================================================== */

	/**
	 * Print the tool's card (from views/settings.php). Prints nothing
	 * unless the user is an owner and WooCommerce is active.
	 *
	 * @param object $store    Store.
	 * @param array  $settings Settings.
	 */
	public static function render( $store, array $settings ) {
		if ( ! self::allowed() ) {
			return;
		}
		$taxonomy = self::taxonomy( $store, $settings );
		$status   = self::status( $taxonomy );
		$nonce    = wp_create_nonce( self::NONCE );

		include Clean_Room::path() . 'views/sizes-tidy.php';
	}

	/**
	 * What the status panel shows: the short list as it is saved, a run
	 * that is not finished, the runs Undo can take back, the sizes that
	 * are retiring (with the fish left on each) or waiting, and the
	 * "Sizes to fill in" list. Reads only.
	 *
	 * @param string $taxonomy Taxonomy ('' = the shop has no size attribute).
	 * @return array
	 */
	public static function status( $taxonomy ) {
		$shop  = self::shop();
		$state = $shop->option_get( Sizes::STATE_OPTION );
		$state = is_array( $state ) && (string) ( $state['taxonomy'] ?? '' ) === (string) $taxonomy ? $state : array();
		$short = isset( $state['short'] ) && is_array( $state['short'] ) && $state['short'] ? Sizes::labels( $state['short'] ) : Sizes_Tidy::DEFAULT_LIST;

		$open = Sizes_Tidy_Run::open( $shop );
		$runs = array();
		foreach ( $shop->runs() as $run ) {
			if ( 'done' === (string) $run['status'] ) {
				$runs[] = $run;
			}
		}
		$retiring = isset( $state['retiring'] ) && is_array( $state['retiring'] ) ? $state['retiring'] : array();
		$counts   = $retiring && '' !== (string) $taxonomy ? $shop->retiring_counts( (string) $taxonomy, array_map( 'strval', array_keys( $retiring ) ) ) : array();
		$leaving  = array();
		foreach ( $retiring as $slug => $label ) {
			$leaving[] = array(
				'slug'  => (string) $slug,
				'label' => (string) $label,
				'left'  => (int) ( $counts[ $slug ] ?? 0 ),
			);
		}

		return array(
			'taxonomy'   => (string) $taxonomy,
			'short'      => $short,
			'short_text' => implode( "\n", $short ),
			'saved'      => ! empty( $state['short'] ),
			'open'       => $open ? $open + array( 'progress' => Sizes_Tidy_Run::progress( $shop, $open ) ) : null,
			'runs'       => $runs,
			'max_runs'   => Sizes_Tidy_Run::MAX_RUNS,
			'retiring'   => $leaving,
			'waiting'    => isset( $state['waiting'] ) && is_array( $state['waiting'] ) ? $state['waiting'] : array(),
			'fill'       => '' !== (string) $taxonomy ? $shop->fill_list( (string) $taxonomy ) : array(),
		);
	}

	/**
	 * The status panel as HTML.
	 *
	 * @param array $status status().
	 * @return string
	 */
	public static function status_html( array $status ) {
		ob_start();
		include Clean_Room::path() . 'views/sizes-tidy-status.php';
		return (string) ob_get_clean();
	}

	/**
	 * A plan as HTML: the map, the preview and the Apply box.
	 *
	 * @param array $plan Sizes_Tidy::plan().
	 * @return string
	 */
	public static function plan_html( array $plan ) {
		$confirm = self::CONFIRM_WORD;
		ob_start();
		include Clean_Room::path() . 'views/sizes-tidy-plan.php';
		return (string) ob_get_clean();
	}

	/**
	 * A product's edit screen.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public static function edit_url( $product_id ) {
		return admin_url( 'post.php?post=' . (int) $product_id . '&action=edit' );
	}

	/**
	 * A price as the shop writes it, as plain text ('' = no price).
	 *
	 * @param string $price Price.
	 * @return string
	 */
	public static function money( $price ) {
		$price = trim( (string) $price );
		if ( '' === $price ) {
			return __( 'no price', 'fishotel-misc-plugin' );
		}
		if ( function_exists( 'wc_price' ) && is_numeric( $price ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $price ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $price;
	}

	/**
	 * What a run's flag on a row says, in plain words.
	 *
	 * @param string $flag Flag.
	 * @return string '' for a flag that needs no words.
	 */
	public static function flag_text( $flag ) {
		switch ( (string) $flag ) {
			case 'plus':
				return __( 'was open-ended (“and up”)', 'fishotel-misc-plugin' );
			case 'below':
				return __( 'smaller than the smallest size of the list — check', 'fishotel-misc-plugin' );
			case 'nofit':
				return __( 'no size of the list holds it — check', 'fishotel-misc-plugin' );
			case 'word':
				return __( 'a word the short list does not have — check', 'fishotel-misc-plugin' );
			case 'between':
				return __( 'an in-between word', 'fishotel-misc-plugin' );
			case 'unused':
				return __( 'no product uses it', 'fishotel-misc-plugin' );
			case 'retiring':
				return __( 'retiring', 'fishotel-misc-plugin' );
			case 'yours':
				return __( 'your choice', 'fishotel-misc-plugin' );
			case 'gone':
				return __( 'the size you had picked is not in the list any more — back to the suggestion', 'fishotel-misc-plugin' );
			case 'orphan':
				return __( 'its term is gone', 'fishotel-misc-plugin' );
		}
		return '';
	}

	/**
	 * The name of another place a size is stored, with its count.
	 *
	 * @param string $store Store name.
	 * @param int    $count Rows.
	 * @return string
	 */
	public static function store_text( $store, $count ) {
		$count = (int) $count;
		switch ( (string) $store ) {
			case 'stays':
				/* translators: %d: count */
				return sprintf( _n( '%d stay', '%d stays', $count, 'fishotel-misc-plugin' ), $count );
			case 'order_lines':
				/* translators: %d: count */
				return sprintf( _n( '%d supplier order line', '%d supplier order lines', $count, 'fishotel-misc-plugin' ), $count );
			case 'requests':
				/* translators: %d: count */
				return sprintf( _n( '%d fish request', '%d fish requests', $count, 'fishotel-misc-plugin' ), $count );
			case 'lostfish_cases':
				/* translators: %d: count */
				return sprintf( _n( '%d lost-fish case', '%d lost-fish cases', $count, 'fishotel-misc-plugin' ), $count );
			case 'invoice_lines':
				/* translators: %d: count */
				return sprintf( _n( '%d draft invoice line', '%d draft invoice lines', $count, 'fishotel-misc-plugin' ), $count );
			case 'box_shares':
				/* translators: %d: count */
				return sprintf( _n( '%d box share', '%d box shares', $count, 'fishotel-misc-plugin' ), $count );
		}
		return '';
	}

	/**
	 * The totals of a plan on one line: "31 sizes → 13 · 214 variations
	 * renamed · 12 products need a look · 0 deleted".
	 *
	 * @param array $totals Sizes_Tidy::plan()['totals'].
	 * @return string
	 */
	public static function totals_text( array $totals ) {
		$parts = array(
			/* translators: 1: sizes now, 2: sizes afterwards */
			sprintf( __( '%1$d sizes → %2$d', 'fishotel-misc-plugin' ), (int) $totals['sizes_before'], (int) $totals['sizes_after'] ),
			/* translators: %d: count */
			sprintf( _n( '%d variation renamed', '%d variations renamed', (int) $totals['renamed'], 'fishotel-misc-plugin' ), (int) $totals['renamed'] ),
		);
		if ( (int) $totals['off'] > 0 ) {
			/* translators: %d: count */
			$parts[] = sprintf( _n( '%d switched off', '%d switched off', (int) $totals['off'], 'fishotel-misc-plugin' ), (int) $totals['off'] );
		}
		if ( (int) $totals['left'] > 0 ) {
			/* translators: %d: count */
			$parts[] = sprintf( _n( '%d fish for sale left as it is', '%d fish for sale left as they are', (int) $totals['left'], 'fishotel-misc-plugin' ), (int) $totals['left'] );
		}
		/* translators: %d: count */
		$parts[] = sprintf( _n( '%d product needs a look', '%d products need a look', (int) $totals['look'], 'fishotel-misc-plugin' ), (int) $totals['look'] );
		/* translators: %d: count */
		$parts[] = sprintf( __( '%d deleted', 'fishotel-misc-plugin' ), (int) $totals['deleted'] );

		return implode( ' · ', $parts );
	}

	/**
	 * A run's progress in words.
	 *
	 * @param array $progress Sizes_Tidy_Run::progress().
	 * @return string
	 */
	public static function progress_text( array $progress ) {
		$done  = (int) ( $progress['done'] ?? 0 );
		$total = max( 1, (int) ( $progress['total'] ?? 0 ) );
		switch ( (string) ( $progress['status'] ?? '' ) ) {
			case 'recording':
				/* translators: 1: done, 2: total */
				return sprintf( __( 'Saving the record of how things are now — %1$d of %2$d. Nothing has been changed yet.', 'fishotel-misc-plugin' ), $done, $total );
			case 'applying':
				/* translators: 1: done, 2: total */
				return sprintf( __( 'Tidying — %1$d of %2$d.', 'fishotel-misc-plugin' ), $done, $total );
			case 'undoing':
				/* translators: 1: done, 2: total */
				return sprintf( __( 'Putting things back — %1$d of %2$d.', 'fishotel-misc-plugin' ), $done, $total );
			case 'done':
				return __( 'Done. The sizes are tidied.', 'fishotel-misc-plugin' );
			case 'undone':
				return __( 'Done. Everything that tidy-up changed is back as it was.', 'fishotel-misc-plugin' );
		}
		return __( 'That tidy-up is not there any more.', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * The tool's script and stylesheet, on the section's Settings tab
	 * only, for an owner, with WooCommerce.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Clean_Room::MENU_SLUG ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		if ( 'settings' !== $tab || ! self::allowed() ) {
			return;
		}

		wp_enqueue_style( 'fishotel-cleanroom-sizes-tidy', Clean_Room::url() . 'css/sizes-tidy.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-cleanroom-sizes-tidy', Clean_Room::url() . 'js/sizes-tidy.js', array( 'jquery' ), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-cleanroom-sizes-tidy',
			'fhSizesTidy',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'word'    => self::CONFIRM_WORD,
				'i18n'    => array(
					'working'     => __( 'Working it out…', 'fishotel-misc-plugin' ),
					'error'       => __( 'Something went wrong. Nothing is lost: click again to carry on.', 'fishotel-misc-plugin' ),
					'needBackup'  => __( 'Tick “I have a backup” first.', 'fishotel-misc-plugin' ),
					/* translators: %s: the word to type */
					'needWord'    => __( 'Type %s in the box to go ahead.', 'fishotel-misc-plugin' ),
					'confirmUndo' => __( 'Undo the last tidy-up? Every product, variation, size and row it changed goes back to how it was before.', 'fishotel-misc-plugin' ),
					'stale'       => __( 'You changed something since the preview. Click “Show what would change” again.', 'fishotel-misc-plugin' ),
					'stopped'     => __( 'It stopped half-way. Nothing is lost — click “Carry on” to finish, or “Undo” to take it back.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  AJAX                                                           */
	/* ============================================================== */

	/**
	 * The owner's choices from a posted form (pure): the short list, the
	 * rows set by hand, the options and the choices per fish.
	 *
	 * @param array $post The posted fields, unslashed.
	 * @return array short, over, keep_plus, instock, move, keep_one, convert.
	 */
	public static function request( array $post ) {
		$text = static function ( $value ) {
			return is_scalar( $value ) && ! is_bool( $value ) ? substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) ), 0, 200 ) : '';
		};
		$list = static function ( $value ) {
			return is_array( $value ) ? array_slice( $value, 0, self::MAX_POSTED, true ) : array();
		};

		$over = array();
		foreach ( $list( $post['over'] ?? null ) as $key => $action ) {
			if ( '' !== $text( $key ) && '' !== $text( $action ) ) {
				$over[ $text( $key ) ] = $text( $action );
			}
		}
		$move = array();
		foreach ( $list( $post['move'] ?? null ) as $vid => $slug ) {
			if ( (int) $vid > 0 && '' !== $text( $slug ) ) {
				$move[ (int) $vid ] = $text( $slug );
			}
		}
		$keep_one = array();
		foreach ( $list( $post['keep_one'] ?? null ) as $pid => $groups ) {
			foreach ( $list( $groups ) as $group => $vid ) {
				if ( (int) $pid > 0 && (int) $vid > 0 && '' !== $text( $group ) ) {
					$keep_one[ (int) $pid ][ $text( $group ) ] = (int) $vid;
				}
			}
		}
		$convert = array();
		foreach ( $list( $post['convert'] ?? null ) as $pid => $on ) {
			if ( (int) $pid > 0 && '' !== $text( $on ) && '0' !== $text( $on ) ) {
				$convert[ (int) $pid ] = 1;
			}
		}

		return array(
			'short'     => Sizes::labels( isset( $post['short'] ) && is_string( $post['short'] ) ? $post['short'] : '' ),
			'over'      => $over,
			'keep_plus' => '1' === $text( $post['keep_plus'] ?? '' ),
			'instock'   => $text( $post['instock'] ?? '' ),
			'move'      => $move,
			'keep_one'  => $keep_one,
			'convert'   => $convert,
		);
	}

	/**
	 * The tool's AJAX: the nonce, the capability and WooCommerce first,
	 * always; then the `op`.
	 */
	public function ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Only an administrator can tidy up the sizes.', 'fishotel-misc-plugin' ) ), 403 );
		}
		$shop = self::shop();
		if ( ! $shop->available() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is not active, so there are no sizes to tidy.', 'fishotel-misc-plugin' ) ), 400 );
		}

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is checked above; request() cleans every field.
		$post = is_array( $post ) ? $post : array();

		try {
			$reply = self::handle( $shop, $post, self::taxonomy() );
		} catch ( \Throwable $e ) {
			$reply = self::refuse( __( 'Something went wrong. Nothing is lost: click again to carry on.', 'fishotel-misc-plugin' ), 500 );
		}

		if ( $reply['ok'] ) {
			wp_send_json_success( $reply['data'] );
		}
		wp_send_json_error( $reply['data'], $reply['status'] );
	}

	/**
	 * A refusal.
	 *
	 * @param string $message What to tell the owner.
	 * @param int    $status  HTTP status.
	 * @param array  $more    More fields.
	 * @return array{ok: bool, status: int, data: array}
	 */
	private static function refuse( $message, $status = 400, array $more = array() ) {
		return array(
			'ok'     => false,
			'status' => (int) $status,
			'data'   => array( 'message' => (string) $message ) + $more,
		);
	}

	/**
	 * An answer.
	 *
	 * @param array $data Fields.
	 * @return array{ok: bool, status: int, data: array}
	 */
	private static function answer( array $data ) {
		return array(
			'ok'     => true,
			'status' => 200,
			'data'   => $data,
		);
	}

	/**
	 * What one click does — after the nonce, the capability and
	 * WooCommerce were checked (ajax()).
	 *
	 * @param Sizes_Tidy_Shop $shop     Shop.
	 * @param array           $post     Posted fields, unslashed.
	 * @param string          $taxonomy The size attribute ('' = none).
	 * @return array{ok: bool, status: int, data: array}
	 */
	public static function handle( Sizes_Tidy_Shop $shop, array $post, $taxonomy ) {
		$op = isset( $post['op'] ) && is_string( $post['op'] ) ? sanitize_key( $post['op'] ) : '';

		switch ( $op ) {
			case 'preview':
			case 'apply':
				if ( '' === (string) $taxonomy ) {
					return self::refuse( __( 'The shop has no product attribute with “size” in its name, so there is nothing to tidy.', 'fishotel-misc-plugin' ) );
				}
				$plan = Sizes_Tidy::plan( $shop->read_shop( (string) $taxonomy ), self::request( $post ) );
				if ( 'apply' === $op ) {
					return self::apply( $shop, $plan, $post );
				}
				return self::answer(
					array(
						'html'    => self::plan_html( $plan ),
						'token'   => $plan['token'],
						'nothing' => ! empty( $plan['nothing'] ),
						'totals'  => $plan['totals'],
					)
				);

			case 'step':
				$progress = Sizes_Tidy_Run::step( $shop, isset( $post['run'] ) ? absint( $post['run'] ) : 0 );
				$over     = 'busy' !== $progress['why'] && ( $progress['finished'] || in_array( $progress['status'], array( 'undone', 'gone' ), true ) );
				return self::answer(
					array(
						'progress' => $progress,
						'message'  => 'busy' === $progress['why'] ? __( 'Another click is still working — waiting for it…', 'fishotel-misc-plugin' ) : self::progress_text( $progress ),
						'over'     => $over,
						'status'   => $over ? self::status_html( self::status( (string) $taxonomy ) ) : '',
					)
				);

			case 'undo':
				$undo = Sizes_Tidy_Run::undo( $shop );
				if ( ! $undo['ok'] ) {
					return self::refuse( __( 'There is no tidy-up to undo.', 'fishotel-misc-plugin' ) );
				}
				return self::answer(
					array(
						'run'     => $undo['run'],
						'message' => __( 'Putting things back…', 'fishotel-misc-plugin' ),
					)
				);

			case 'dismiss':
				$shop->fill_dismiss( isset( $post['product'] ) ? absint( $post['product'] ) : 0 );
				return self::answer( array( 'status' => self::status_html( self::status( (string) $taxonomy ) ) ) );

			case 'status':
				return self::answer( array( 'status' => self::status_html( self::status( (string) $taxonomy ) ) ) );
		}

		return self::refuse( __( 'That is not something this tool does.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Apply: the backup tick, the typed word and the token, then the
	 * run is started (its record saved) and the page steps it.
	 *
	 * @param Sizes_Tidy_Shop $shop Shop.
	 * @param array           $plan The plan, worked out again from the shop as it is now.
	 * @param array           $post Posted fields.
	 * @return array{ok: bool, status: int, data: array}
	 */
	private static function apply( Sizes_Tidy_Shop $shop, array $plan, array $post ) {
		$open = Sizes_Tidy_Run::open( $shop );
		if ( $open && 'undoing' !== (string) $open['status'] ) {
			// Apply clicked again: the run that is open carries on.
			return self::answer(
				array(
					'run'     => (int) $open['id'],
					'message' => self::progress_text( Sizes_Tidy_Run::progress( $shop, $open ) ),
				)
			);
		}
		if ( $open ) {
			return self::refuse( __( 'An undo is half-way. Click “Carry on” to finish it first.', 'fishotel-misc-plugin' ), 409 );
		}
		if ( '1' !== (string) ( is_scalar( $post['backup'] ?? null ) ? $post['backup'] : '' ) ) {
			return self::refuse( __( 'Tick “I have a backup” first.', 'fishotel-misc-plugin' ) );
		}
		if ( strtoupper( trim( (string) ( is_scalar( $post['confirm'] ?? null ) ? $post['confirm'] : '' ) ) ) !== self::CONFIRM_WORD ) {
			/* translators: %s: the word to type */
			return self::refuse( sprintf( __( 'Type %s in the box to go ahead.', 'fishotel-misc-plugin' ), self::CONFIRM_WORD ) );
		}
		if ( ! empty( $plan['nothing'] ) ) {
			return self::refuse( __( 'There is nothing to tidy with these choices.', 'fishotel-misc-plugin' ) );
		}
		$token = is_scalar( $post['token'] ?? null ) ? (string) $post['token'] : '';
		if ( '' === $token || ! hash_equals( (string) $plan['token'], $token ) ) {
			return self::refuse( __( 'The shop, or a choice on this page, changed since the preview. Click “Show what would change” again, then Apply.', 'fishotel-misc-plugin' ), 409, array( 'stale' => true ) );
		}

		$user  = wp_get_current_user();
		$start = Sizes_Tidy_Run::start( $shop, $plan, is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '' );
		if ( ! $start['ok'] ) {
			return self::refuse(
				'busy' === $start['why']
					? __( 'Another click is still working. Wait a moment and try again.', 'fishotel-misc-plugin' )
					: __( 'The record of how things are now could not be saved, so nothing was changed.', 'fishotel-misc-plugin' ),
				500
			);
		}
		return self::answer(
			array(
				'run'     => (int) $start['run'],
				'message' => __( 'The record is being saved…', 'fishotel-misc-plugin' ),
			)
		);
	}
}
