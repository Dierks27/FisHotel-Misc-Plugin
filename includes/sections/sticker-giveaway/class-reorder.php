<?php
/**
 * Sticker Giveaway reorder — the stock table's rows, and the one email
 * when a design drops to its "Reorder at" number.
 *
 * A design's count is its product's own WooCommerce stock; shop sales and
 * giveaway stickers both come out of it, and WooCommerce moves it. This
 * class only LOOKS at it: when WooCommerce changes a product's stock —
 * an order, the product page, "Add stickers", "Recount"; they all end in
 * `woocommerce_product_set_stock` / `woocommerce_variation_set_stock` —
 * and when the settings are saved. At or below the number the design
 * reads "Reorder" in the stock table and on the Today page, and ONE email
 * goes to WooCommerce's stock notification recipient (else the site
 * admin). No further email for that count until its stock is back above
 * the number (Rules::reorder_step()).
 *
 * Which counts have had their email is one small option of its own
 * (OPTION_SENT, not autoloaded), so an order never rewrites the settings.
 * WooCommerce's own low-stock and out-of-stock emails are not touched.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reorder
 */
class Reorder {

	/**
	 * The counts whose reorder email has gone: a list of product ids.
	 *
	 * @var string
	 */
	const OPTION_SENT = 'fishotel_sticker_giveaway_reorder_sent';

	/**
	 * Hook the look at every stock change (boot(), every kind of request:
	 * an order is paid from a gateway's callback, stock is typed in
	 * wp-admin).
	 */
	public static function register() {
		add_action( 'woocommerce_product_set_stock', array( self::class, 'stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'stock_changed' ) );
	}

	/**
	 * One row per COUNT of the pool, in pool order: the product a design's
	 * stock number is on. That is the design itself, or — when a variable
	 * product keeps one stock number for all its variations — the product,
	 * whose designs then share the row.
	 *
	 * @param array|null $settings Settings (defaults to the saved ones).
	 * @return array<int, array> count id => name, designs, image, stock,
	 *                           reorder_at, status.
	 */
	public static function rows( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get();
		$rows     = array();

		foreach ( Rules::pool( $settings ) as $id ) {
			$design = Designs::product( $id );
			if ( ! $design ) {
				continue;
			}

			$count = Designs::count_id( $design );

			if ( ! isset( $rows[ $count ] ) ) {
				$holder = $count === (int) $design->get_id() ? $design : wc_get_product( $count );
				$stock  = Designs::stock( $design );
				$at     = Rules::reorder_at( $count, $settings );

				$rows[ $count ] = array(
					'id'         => $count,
					'name'       => $holder instanceof \WC_Product ? Designs::name( $holder ) : Designs::name( $design ),
					'designs'    => array(),
					'image'      => $design->get_image( 'thumbnail' ),
					'stock'      => $stock,
					'reorder_at' => $at,
					'status'     => Rules::status( $stock, $at ),
				);
			}

			$rows[ $count ]['designs'][ $id ] = Designs::name( $design );
		}

		return $rows;
	}

	/**
	 * The rows that are at or below their "Reorder at" number, for the
	 * Today line.
	 *
	 * @param array|null $settings Settings.
	 * @return array<int, array>
	 */
	public static function due( $settings = null ) {
		$due = array();

		foreach ( self::rows( $settings ) as $id => $row ) {
			if ( 'send' === Rules::reorder_step( $row['stock'], $row['reorder_at'], false ) ) {
				$due[ $id ] = $row;
			}
		}

		return $due;
	}

	/**
	 * `woocommerce_product_set_stock` / `woocommerce_variation_set_stock`
	 * — WooCommerce changed this product's stock number. A product without
	 * a "Reorder at" number costs one array lookup and nothing else.
	 *
	 * @param mixed $product The product whose stock changed.
	 */
	public static function stock_changed( $product ) {
		try {
			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			$settings = Settings::get();
			$id       = (int) $product->get_id();
			$at       = Rules::reorder_at( $id, $settings );

			if ( null === $at ) {
				return;
			}

			self::look(
				array(
					$id => array(
						'name'       => Designs::name( $product ),
						'stock'      => Designs::stock( $product ),
						'reorder_at' => $at,
					),
				),
				$settings,
				false
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Look at every count: after a settings save a number may have been
	 * typed, changed or taken away. Never throws.
	 *
	 * @param array|null $settings Settings (defaults to the saved ones).
	 * @return int How many counts the email named (0 = none sent).
	 */
	public static function check( $settings = null ) {
		try {
			$settings = is_array( $settings ) ? $settings : Settings::get();

			return self::look( self::rows( $settings ), $settings, true );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Apply Rules::reorder_step() to some counts: write the one email for
	 * those that have just dropped to their number, and let those that are
	 * back above it be written about again next time.
	 *
	 * @param array[] $rows     count id => name, stock, reorder_at.
	 * @param array   $settings Settings.
	 * @param bool    $all      Whether $rows is every count of the pool (a
	 *                          count that is no longer one is then forgotten).
	 * @return int How many counts the email named.
	 */
	private static function look( array $rows, array $settings, $all ) {
		$before = Rules::ids( get_option( self::OPTION_SENT, array() ) );
		$sent   = $all ? array_values( array_intersect( $before, array_keys( $rows ) ) ) : $before;
		$due    = array();

		foreach ( $rows as $id => $row ) {
			$step = Rules::reorder_step( $row['stock'], $row['reorder_at'], in_array( $id, $sent, true ) );

			if ( 'clear' === $step ) {
				$sent = array_values( array_diff( $sent, array( $id ) ) );
			} elseif ( 'send' === $step && ! empty( $settings['reorder_email'] ) ) {
				$due[ $id ] = $row;
			}
		}

		// Marked before the mail is handed over, so a second look in the same moment finds it done.
		if ( $due || $sent !== $before ) {
			update_option( self::OPTION_SENT, array_merge( $sent, array_keys( $due ) ), false );
		}

		if ( $due && ! self::send( $due ) ) {
			// The mail did not go out: let the next look try again.
			update_option( self::OPTION_SENT, $sent, false );
			return 0;
		}

		return count( $due );
	}

	/**
	 * What is left of one count in a few words (pure): "40 left" or "out".
	 *
	 * @param array $row A row (stock).
	 * @return string
	 */
	public static function left_text( array $row ) {
		$stock = (int) ( $row['stock'] ?? 0 );

		if ( $stock < 1 ) {
			return __( 'out', 'fishotel-misc-plugin' );
		}

		/* translators: %d: stickers in stock */
		return sprintf( __( '%d left', 'fishotel-misc-plugin' ), $stock );
	}

	/**
	 * The email (pure): subject and body, plain words that say what to do
	 * next.
	 *
	 * @param array[] $rows The counts that just dropped (name, stock, reorder_at).
	 * @param string  $site Site name.
	 * @param string  $url  The settings page.
	 * @return array{subject: string, body: string}
	 */
	public static function mail( array $rows, $site, $url ) {
		$names = array();
		$body  = array();

		foreach ( $rows as $row ) {
			$name    = (string) ( $row['name'] ?? '' );
			$stock   = (int) ( $row['stock'] ?? 0 );
			$names[] = $name;
			$body[]  = $stock < 1
				/* translators: 1: design, 2: its "Reorder at" number */
				? sprintf( __( '%1$s is out of stock (reorder at %2$d).', 'fishotel-misc-plugin' ), $name, (int) $row['reorder_at'] )
				/* translators: 1: design, 2: stickers in stock, 3: its "Reorder at" number */
				: sprintf( __( '%1$s is down to %2$d (reorder at %3$d).', 'fishotel-misc-plugin' ), $name, $stock, (int) $row['reorder_at'] );
		}

		$body[] = '';
		$body[] = __( 'Time to count the stickers by hand and reorder.', 'fishotel-misc-plugin' );
		$body[] = __( 'Type what you counted under Recount on the Sticker Giveaway page; when the new stickers arrive, use Add stickers.', 'fishotel-misc-plugin' );
		if ( '' !== (string) $url ) {
			$body[] = (string) $url;
		}
		$body[] = '';
		$body[] = __( 'You will not get another email about a design until its stock is back above its "Reorder at" number.', 'fishotel-misc-plugin' );

		return array(
			/* translators: 1: site name, 2: design names */
			'subject' => sprintf( __( '[%1$s] Stickers to reorder: %2$s', 'fishotel-misc-plugin' ), (string) $site, implode( ', ', $names ) ),
			'body'    => implode( "\n", $body ),
		);
	}

	/**
	 * Who the email goes to: WooCommerce's stock notification recipient,
	 * else the site admin address.
	 *
	 * @return string '' when neither is set.
	 */
	public static function recipient() {
		$to = trim( (string) get_option( 'woocommerce_stock_email_recipient', '' ) );

		if ( '' === $to ) {
			$to = trim( (string) get_option( 'admin_email', '' ) );
		}

		return $to;
	}

	/**
	 * Send the email. Never throws.
	 *
	 * @param array[] $rows The counts that just dropped.
	 * @return bool Whether wp_mail accepted it.
	 */
	private static function send( array $rows ) {
		try {
			$to = self::recipient();
			if ( '' === $to ) {
				return false;
			}

			$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$mail = self::mail( $rows, $site, admin_url( 'admin.php?page=' . Sticker_Giveaway::MENU_SLUG ) );

			return (bool) wp_mail( $to, $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
