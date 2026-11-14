<?php
/**
 * My Account → Gift Cards (Live only).
 *
 * The same address as the old plugin's page (the `giftcards` endpoint),
 * so the theme's menu label, icon and "PERKS" group apply as they are —
 * but drawn through its OWN template name, myaccount/fishotel-gift-cards.php
 * (a theme may copy it to woocommerce/myaccount/ to restyle it): the
 * theme's copy of the old plugin's template (myaccount/giftcards.php)
 * calls into the old plugin and would stop the page once it is gone. The
 * theme's own redeem button and its GET names (fh_gc_action, gc_id) are
 * not used either — they belong to the old plugin's cards.
 *
 * The page: the balance, "Redeem a gift card" (a code into the account),
 * the cards in the account with their full codes (it is theirs), and the
 * activity, 20 a page. A card only goes into an account with its code
 * typed: an account's e-mail address is not proof of anything
 * (WooCommerce saves a changed address without checking it).
 *
 * Every POST: nonce first, then logged in, then the Service decides
 * whose card it is. A customer never sees or uses another's card.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Account
 */
class Account {

	const ENDPOINT     = 'giftcards';
	const TEMPLATE     = 'myaccount/fishotel-gift-cards.php';
	const NONCE        = 'fh_gcard_account';
	const FLASH        = 'fh_gcard_flash_';
	const FLUSH_OPTION = 'fishotel_gift_cards_flush';
	const FLUSH_TRIED  = 'fishotel_gift_cards_flush_tried';
	const PER_PAGE     = 20;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20 );
		add_filter( 'woocommerce_endpoint_giftcards_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_giftcards_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 99, 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The endpoint, for WooCommerce's account pages.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars                   = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * The menu entry, before Log out (the theme relabels and groups it).
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function menu_items( $items ) {
		$items = is_array( $items ) ? $items : array();
		if ( isset( $items[ self::ENDPOINT ] ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Gift Cards', 'fishotel-misc-plugin' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Gift Cards', 'fishotel-misc-plugin' );
		}
		return $out;
	}

	/**
	 * The page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Gift Cards', 'fishotel-misc-plugin' );
	}

	/**
	 * The rewrite rules must hold the address. After Live was switched on
	 * (the flag), they are rebuilt once in wp-admin. And whenever the
	 * stored rules lack it — they were rebuilt while this section was not
	 * loaded: Misc rolled back and forward again, Permalinks saved while
	 * the section was off — the next request rebuilds them once (it runs
	 * after WooCommerce added its endpoints; WordPress does it at
	 * wp_loaded, so that very request already finds the page). The check
	 * reads only the stored rules, which WordPress keeps autoloaded: no
	 * query on a storefront request. A rebuild that still leaves the
	 * address out is not repeated for the same rules within the hour.
	 */
	public function maybe_flush() {
		if ( ! function_exists( 'flush_rewrite_rules' ) ) {
			return;
		}
		if ( is_admin() && get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! self::endpoint_missing( $rules ) ) {
			return;
		}
		$seen  = md5( serialize( $rules ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint only.
		$tried = get_option( self::FLUSH_TRIED );
		if ( is_array( $tried ) && ( $tried['rules'] ?? '' ) === $seen && time() - (int) ( $tried['at'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return;
		}
		update_option(
			self::FLUSH_TRIED,
			array(
				'rules' => $seen,
				'at'    => time(),
			),
			true
		);
		flush_rewrite_rules( false );
	}

	/**
	 * Whether stored rewrite rules lack the address (pure). No rules at
	 * all (plain permalinks, or not built yet) is not "missing".
	 *
	 * @param mixed $rules get_option( 'rewrite_rules' ).
	 * @return bool
	 */
	public static function endpoint_missing( $rules ) {
		if ( ! is_array( $rules ) || ! $rules ) {
			return false;
		}
		foreach ( $rules as $query ) {
			if ( is_string( $query ) && ( false !== strpos( $query, '&' . self::ENDPOINT . '=' ) || false !== strpos( $query, '?' . self::ENDPOINT . '=' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The page's address.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$url = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/my-account/' . self::ENDPOINT . '/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * The stylesheet, on account pages.
	 */
	public function enqueue() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'fishotel-gift-cards-front', Gift_Cards::url() . 'css/gift-cards-front.css', array(), FISHOTEL_MISC_VERSION );
		}
	}

	/* ============================================================== */
	/*  The forms                                                      */
	/* ============================================================== */

	/**
	 * The page's POSTs: redeem a code, add a card sent to me.
	 */
	public function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['fh_gcard_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- checked in perform().
			return;
		}
		$user = wp_get_current_user();
		$out  = self::perform( Gift_Cards::service(), wp_unslash( $_POST ), (int) $user->ID, (string) $user->user_email, Audit::context( 'account', 'my account' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- perform() checks the nonce first.
		self::flash( (int) $user->ID, $out );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * One POST (the tests call it): nonce first, then logged in, then the
	 * Service, which decides whose card it is.
	 *
	 * @param Service $service Service.
	 * @param array   $post    The form (unslashed).
	 * @param int     $user_id Current user.
	 * @param string  $email   Current user's e-mail.
	 * @param array   $ctx     Audit::context().
	 * @return array
	 */
	public static function perform( Service $service, array $post, $user_id, $email, array $ctx ) {
		$nonce = isset( $post['_fh_gcard_nonce'] ) && is_scalar( $post['_fh_gcard_nonce'] ) ? (string) $post['_fh_gcard_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return Service::fail( 'nonce', __( 'This page was open too long. Please try again.', 'fishotel-misc-plugin' ) );
		}
		if ( $user_id <= 0 ) {
			return Service::fail( 'login', __( 'Log in to add a gift card to your account.', 'fishotel-misc-plugin' ) );
		}
		$action = is_scalar( $post['fh_gcard_action'] ?? null ) ? (string) $post['fh_gcard_action'] : '';
		$who    = 'user:' . $user_id;
		if ( 'redeem' === $action ) {
			if ( ! $service->may_try( $who ) ) {
				return Service::fail( 'limit', __( 'Too many codes were tried. Please wait an hour, or contact us.', 'fishotel-misc-plugin' ) );
			}
			$r = $service->redeem( $post['fh_gcard_code'] ?? '', $user_id, $ctx );
			if ( ! $r['ok'] && in_array( $r['code'], array( 'not_found', 'empty' ), true ) ) {
				$service->wrong_try( $who );
			}
			return $r;
		}
		return Service::fail( 'form', __( 'That did not work. Please try again.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Keep the answer for the page after the redirect.
	 *
	 * @param int   $user_id User id.
	 * @param array $out     Result.
	 */
	private static function flash( $user_id, array $out ) {
		set_transient(
			self::FLASH . (int) $user_id,
			array(
				'ok'      => ! empty( $out['ok'] ),
				'message' => (string) $out['message'],
			),
			300
		);
	}

	/* ============================================================== */
	/*  The page                                                       */
	/* ============================================================== */

	/**
	 * What the page shows (the tests read it).
	 *
	 * @param Service $service Service.
	 * @param int     $user_id User id.
	 * @param string  $email   The user's e-mail address.
	 * @param int     $page    Activity page.
	 * @return array
	 */
	public static function data( Service $service, $user_id, $email, $page = 1 ) {
		$wallet = $service->wallet( (int) $user_id );
		$ids    = array_map( 'intval', array_column( $wallet['all'], 'id' ) );
		$total  = $service->store->count_ledger_of_cards( $ids );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page   = max( 1, min( $pages, (int) $page ) );
		$codes  = array();
		foreach ( $wallet['all'] as $card ) {
			$codes[ (int) $card['id'] ] = (string) $card['code'];
		}
		return array(
			'balance'      => (int) $wallet['balance'],
			'cards'        => $wallet['all'],
			'activity'     => $service->store->ledger_of_cards( $ids, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ),
			'codes'        => $codes,
			'current_page' => $page,
			'total_pages'  => $pages,
		);
	}

	/**
	 * An activity row in words (pure).
	 *
	 * @param array $row Ledger row.
	 * @return string
	 */
	public static function activity_words( array $row ) {
		$order = ! empty( $row['order_id'] ) ? (int) $row['order_id'] : 0;
		switch ( (string) $row['type'] ) {
			case 'issued':
				return __( 'Gift card issued', 'fishotel-misc-plugin' );
			case 'redeemed_to_account':
				return __( 'Added to your account', 'fishotel-misc-plugin' );
			case 'used':
				/* translators: %d: order number */
				return $order ? sprintf( __( 'Used on order #%d', 'fishotel-misc-plugin' ), $order ) : __( 'Used on an order', 'fishotel-misc-plugin' );
			case 'restored':
				/* translators: %d: order number */
				return $order ? sprintf( __( 'Put back from order #%d', 'fishotel-misc-plugin' ), $order ) : __( 'Put back', 'fishotel-misc-plugin' );
			case 'credit':
				return __( 'Added by the shop', 'fishotel-misc-plugin' );
			case 'debit':
				return __( 'Taken off by the shop', 'fishotel-misc-plugin' );
		}
		return __( 'Balance corrected', 'fishotel-misc-plugin' );
	}

	/**
	 * The page.
	 */
	public function render() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}
		$page  = isset( $_GET['fh_gcard_page'] ) ? absint( $_GET['fh_gcard_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paging.
		$data  = self::data( Gift_Cards::service(), (int) $user->ID, (string) $user->user_email, $page );
		$flash = get_transient( self::FLASH . (int) $user->ID );
		if ( $flash ) {
			delete_transient( self::FLASH . (int) $user->ID );
		}
		$args = array_merge(
			$data,
			array(
				'flash'    => is_array( $flash ) ? $flash : null,
				'page_url' => self::url(),
				'nonce'    => wp_create_nonce( self::NONCE ),
			)
		);
		if ( function_exists( 'wc_get_template' ) ) {
			wc_get_template( self::TEMPLATE, $args, '', Gift_Cards::path() . 'views/' );
			return;
		}
		extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract -- the template's variables, as wc_get_template() gives them.
		include Gift_Cards::path() . 'views/' . self::TEMPLATE;
	}
}
