<?php
/**
 * Sticker Giveaway reorder — the stock table's rows, and the one email
 * when a design drops to its reorder number.
 *
 * What is left of a design is its giveaway pile's "really there" figure
 * plus the shop pile (the product's own WooCommerce stock, when it manages
 * stock). When that reaches the design's "Reorder at" number the design
 * reads "Reorder" in the stock table and on the Today page, and ONE email
 * goes to WooCommerce's stock notification recipient (else the site
 * admin). No further email for that design until a restock or a recount
 * has put it back above the number (Piles::alert_step(); the mark lives
 * on the pile's row and is taken with one UPDATE … WHERE alerted = 0, so
 * two orders paid together still write one email).
 *
 * The numbers are Piles::summary() (pure, tested); this class gathers the
 * facts and sends the mail.
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
	 * One row per design in the pool, in pool order.
	 *
	 * @param array|null $settings  Settings (defaults to the saved ones).
	 * @param bool       $with_pace Also count how many went in the last
	 *                              28 days (one more query; the settings
	 *                              page only).
	 * @return array<int, array>
	 */
	public static function rows( $settings = null, $with_pace = false ) {
		$settings = is_array( $settings ) ? $settings : Settings::get();
		$pool     = Rules::pool( $settings );
		$rows     = array();

		if ( ! $pool ) {
			return $rows;
		}

		$store   = Designs::store();
		$piles   = $store->piles( $pool );
		$percent = Piles::loss_percent( $settings );
		$went    = $with_pace ? $store->went_since( $pool, time() - Piles::PACE_DAYS * 86400 ) : array();

		foreach ( $pool as $id ) {
			$product = Designs::product( $id );
			$pile    = $piles[ $id ] ?? Piles::pile( null );
			$keeps   = Rules::keeps_pile( $id, $settings );

			$row = Piles::summary(
				array(
					'keeps_pile' => $keeps,
					'paper'      => $pile['paper'],
					'basis'      => $pile['basis'],
					'percent'    => $percent,
					'shop'       => $product ? Designs::shop_stock( $product ) : null,
					'in_stock'   => $product ? $product->is_in_stock() : false,
					'went'       => $went[ $id ] ?? 0,
					'reorder_at' => Rules::reorder_at( $id, $settings ),
				)
			);

			$row['id']         = $id;
			$row['product']    = $product;
			/* translators: %d: product ID */
			$row['name']       = $product ? Designs::name( $product ) : sprintf( __( 'Product #%d (no longer there)', 'fishotel-misc-plugin' ), $id );
			$row['alerted']    = ! empty( $pile['alerted'] );
			$row['counted_at'] = (string) ( $pile['counted_at'] ?? '' );
			$row['added_at']   = (string) ( $pile['added_at'] ?? '' );
			$row['blockers']   = $product ? Designs::blockers( $product, $settings ) : array( 'type' );

			$rows[ $id ] = $row;
		}

		return $rows;
	}

	/**
	 * The designs that are down to their reorder number (rows()).
	 *
	 * @param array|null $settings Settings.
	 * @return array<int, array>
	 */
	public static function due( $settings = null ) {
		$due = array();

		foreach ( self::rows( $settings ) as $id => $row ) {
			if ( Piles::needs_reorder( $row['left'], $row['reorder_at'] ) ) {
				$due[ $id ] = $row;
			}
		}

		return $due;
	}

	/**
	 * Look at every reorder number: write the one email for the designs
	 * that have just dropped to theirs, and clear the mark of those that
	 * are back above it. Called after an order moved a pile (or the shop's
	 * stock), after "Add stickers" / "Recount", and after a settings save.
	 * Never throws.
	 *
	 * @param bool $always Look even when no design has a reorder number
	 *                     (a settings save: a number that was just removed
	 *                     must clear its mark).
	 * @return int How many designs the email named (0 = none sent).
	 */
	public static function check( $always = false ) {
		try {
			$settings = Settings::get();

			// No reorder number anywhere: nothing can be due, and an order being paid costs no query here.
			if ( ! $always && empty( $settings['reorder_at'] ) ) {
				return 0;
			}

			Store::forget();

			$store = Designs::store();
			$mail  = ! empty( $settings['reorder_email'] );
			$fresh = array();

			foreach ( self::rows( $settings ) as $id => $row ) {
				$step = Piles::alert_step( $row['left'], $row['reorder_at'], $row['alerted'] );

				if ( 'clear' === $step ) {
					$store->clear_alert( $id );
				} elseif ( 'send' === $step && $mail && $store->claim_alert( $id ) ) {
					$fresh[ $id ] = $row;
				}
			}

			if ( $fresh && ! self::send( $fresh ) ) {
				// The mail did not go out: let the next look try again.
				foreach ( array_keys( $fresh ) as $id ) {
					$store->clear_alert( $id );
				}
				return 0;
			}

			return count( $fresh );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * What is left of one design in a few words (pure): "about 40 left"
	 * for a design with a giveaway pile (the figure allows for the ones
	 * that went missing), "12 left" for one without, "out", or "giveaway
	 * pile out, 6 in the shop".
	 *
	 * @param array $row A rows() row.
	 * @return string
	 */
	public static function left_text( array $row ) {
		$left = max( 0, (int) ( $row['left'] ?? 0 ) );

		if ( $left < 1 ) {
			return __( 'out', 'fishotel-misc-plugin' );
		}

		if ( empty( $row['keeps_pile'] ) ) {
			/* translators: %d: stickers left */
			return sprintf( __( '%d left', 'fishotel-misc-plugin' ), $left );
		}

		if ( empty( $row['offered'] ) ) {
			/* translators: %d: stickers in the shop pile */
			return sprintf( __( 'giveaway pile out, %d in the shop', 'fishotel-misc-plugin' ), $left );
		}

		/* translators: %d: stickers left */
		return sprintf( __( 'about %d left', 'fishotel-misc-plugin' ), $left );
	}

	/**
	 * How fast a giveaway pile is going, in a few words (pure): "34 went
	 * in the last 28 days · about 9 a week · roughly 21 weeks left".
	 *
	 * @param array $row A rows() row (went, per_week, weeks).
	 * @return string
	 */
	public static function pace_text( array $row ) {
		$went = max( 0, (int) ( $row['went'] ?? 0 ) );

		if ( $went < 1 ) {
			/* translators: %d: days */
			return sprintf( __( 'none went in the last %d days', 'fishotel-misc-plugin' ), Piles::PACE_DAYS );
		}

		$per_week = (float) ( $row['per_week'] ?? 0 );
		$weeks    = $row['weeks'] ?? null;

		$parts = array(
			/* translators: 1: stickers, 2: days */
			sprintf( __( '%1$d went in the last %2$d days', 'fishotel-misc-plugin' ), $went, Piles::PACE_DAYS ),
			$per_week < 1
				? __( 'less than 1 a week', 'fishotel-misc-plugin' )
				/* translators: %d: stickers a week */
				: sprintf( __( 'about %d a week', 'fishotel-misc-plugin' ), (int) round( $per_week ) ),
		);

		if ( null !== $weeks ) {
			$weeks = (float) $weeks;
			if ( $weeks < 1 ) {
				$parts[] = __( 'less than a week left', 'fishotel-misc-plugin' );
			} elseif ( $weeks > 99 ) {
				$parts[] = __( 'more than 99 weeks left', 'fishotel-misc-plugin' );
			} else {
				$n = (int) round( $weeks );
				/* translators: %d: weeks */
				$parts[] = sprintf( _n( 'roughly %d week left', 'roughly %d weeks left', $n, 'fishotel-misc-plugin' ), $n );
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * The email (pure): subject and body, plain words.
	 *
	 * @param array[] $rows rows() rows of the designs that just dropped.
	 * @param string  $site Site name.
	 * @param string  $url  The settings page.
	 * @return array{subject: string, body: string}
	 */
	public static function mail( array $rows, $site, $url ) {
		$names = array();
		$lines = array();

		foreach ( $rows as $row ) {
			$name    = (string) ( $row['name'] ?? '' );
			$names[] = $name;

			$parts = array();
			if ( ! empty( $row['keeps_pile'] ) ) {
				/* translators: %d: stickers */
				$parts[] = sprintf( __( 'giveaway pile about %d', 'fishotel-misc-plugin' ), (int) $row['really_there'] );
			}
			if ( null !== ( $row['shop'] ?? null ) ) {
				/* translators: %d: stickers */
				$parts[] = sprintf( __( 'shop %d', 'fishotel-misc-plugin' ), (int) $row['shop'] );
			}

			$lines[] = sprintf(
				/* translators: 1: design, 2: "about 40 left", 3: the two piles, 4: reorder number */
				__( '  %1$s: %2$s (%3$s). Reorder at %4$d.', 'fishotel-misc-plugin' ),
				$name,
				self::left_text( $row ),
				implode( ', ', $parts ),
				(int) $row['reorder_at']
			);

			if ( empty( $row['offered'] ) && ! empty( $row['keeps_pile'] ) ) {
				$lines[] = '  ' . __( 'Its giveaway pile is down to its reserve, so customers are no longer offered this design.', 'fishotel-misc-plugin' );
			}
		}

		$count = count( $names );
		$body  = array(
			/* translators: %d: designs */
			sprintf( _n( '%d sticker design is down to its reorder number:', '%d sticker designs are down to their reorder number:', $count, 'fishotel-misc-plugin' ), $count ),
			'',
		);
		$body  = array_merge( $body, $lines );
		$body[] = '';
		$body[] = __( 'You will not get another email about a design until it has been restocked or recounted above its reorder number.', 'fishotel-misc-plugin' );
		if ( '' !== (string) $url ) {
			$body[] = '';
			/* translators: %s: URL */
			$body[] = sprintf( __( 'Sticker Giveaway: %s', 'fishotel-misc-plugin' ), $url );
		}

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
	 * @param array[] $rows The designs that just dropped.
	 * @return bool Whether wp_mail accepted it.
	 */
	private static function send( array $rows ) {
		try {
			$to = self::recipient();
			if ( '' === $to ) {
				return false;
			}

			$site = function_exists( 'get_bloginfo' ) ? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) : 'FisHotel';
			$mail = self::mail( $rows, $site, admin_url( 'admin.php?page=' . Sticker_Giveaway::MENU_SLUG ) );

			return (bool) wp_mail( $to, $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
