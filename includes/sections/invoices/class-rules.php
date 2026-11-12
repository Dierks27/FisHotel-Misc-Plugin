<?php
/**
 * Invoice rules — PURE: the owner's standing rules as code, decided from
 * plain arrays so every one of them is tested without WordPress.
 *
 *   1. Read before you touch       — duplicates(): every fish of a new
 *                                    invoice is compared with the customer's
 *                                    other invoices; unconfirmed() keeps
 *                                    Publish shut until each match is
 *                                    confirmed.
 *   2. Never modify an invoice a   — effective(): a PAID invoice is locked
 *      customer already has          for good; an unpaid one is locked too
 *                                    while a live order (pending, on hold)
 *                                    contains it; only an unpaid, published
 *                                    invoice of this section can be edited,
 *                                    and only deliberately.
 *   3. No fish on two invoices     — duplicates(), by the ids a line kept
 *                                    (tank group, product, species) and by
 *                                    a loose name key (fish_key()).
 *   6. Stock 1, no backorders      — written by the Shop, checked back by
 *                                    Publisher::verify().
 *   8. Trash only when no order    — effective(): can_trash is true only
 *      contains it                   when not one order contains the
 *                                    product, whatever that order's status.
 *
 * PAID is never read from a status name: an order pays an invoice when
 * it has a paid date or WooCommerce calls it paid (the Shop reports that
 * as `paid`), and it still stands — it was not cancelled, did not fail
 * and is not in the Trash. The theme's lock (`_fh_invoice_order_id` on
 * the product) counts the same way while its order stands. A refunded
 * order keeps the invoice locked, as the theme does.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rules
 */
class Rules {

	const STATES = array( 'draft', 'published', 'paid', 'cancelled', 'trashed' );

	/**
	 * Order statuses that release an invoice again: the order no longer
	 * stands.
	 *
	 * @var string[]
	 */
	const DEAD_ORDER_STATUSES = array( 'cancelled', 'failed', 'trash' );

	const MAX_HANDLE = 120;

	/* ============================================================== */
	/*  Keys                                                           */
	/* ============================================================== */

	/**
	 * Lower-case text (multibyte when the extension is there).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * A handle as it is compared: entities decoded, whitespace collapsed,
	 * lower-case. "JPReef", " jpreef " and "JPREEF" are one customer.
	 *
	 * @param mixed $handle Handle.
	 * @return string
	 */
	public static function handle_key( $handle ) {
		if ( ! is_scalar( $handle ) ) {
			return '';
		}
		$text = html_entity_decode( (string) $handle, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF" ), ' ', $text );
		return self::lower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * A fish name as it is compared between invoices — deliberately loose,
	 * because a match is only asked about and a miss is a double charge:
	 * lower-case, anything in brackets dropped (a size), punctuation
	 * dropped, and the last word in the singular ("Blue Tangs" is "Blue
	 * Tang", "Blennies" is "Blenny").
	 *
	 * @param mixed $name Name.
	 * @return string
	 */
	public static function fish_key( $name ) {
		if ( ! is_scalar( $name ) ) {
			return '';
		}
		$text = self::lower( html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = (string) preg_replace( '/\([^)]*\)|\[[^\]]*\]/u', ' ', $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}

		$words = explode( ' ', $text );
		$last  = (string) array_pop( $words );
		if ( strlen( $last ) > 4 && 'ies' === substr( $last, -3 ) ) {
			$last = substr( $last, 0, -3 ) . 'y';
		} elseif ( strlen( $last ) > 3 && 's' === substr( $last, -1 ) && 'ss' !== substr( $last, -2 ) ) {
			$last = substr( $last, 0, -1 );
		}
		$words[] = $last;

		return implode( ' ', $words );
	}

	/* ============================================================== */
	/*  Paid, locked, editable, trashable                              */
	/* ============================================================== */

	/**
	 * Whether an order no longer stands (cancelled, failed, in the Trash,
	 * or gone).
	 *
	 * @param mixed $order Order facts (Shop::order()), or null.
	 * @return bool
	 */
	public static function order_is_dead( $order ) {
		if ( ! is_array( $order ) || empty( $order['id'] ) ) {
			return true;
		}
		return in_array( strtolower( (string) ( isset( $order['status'] ) ? $order['status'] : '' ) ), self::DEAD_ORDER_STATUSES, true );
	}

	/**
	 * The order that pays an invoice, or 0: the theme's lock while its
	 * order stands, else the newest standing order that is paid.
	 *
	 * @param int     $lock_order_id The product's `_fh_invoice_order_id`.
	 * @param array[] $orders        Facts of the orders that contain the product (and of the lock order).
	 * @return int
	 */
	public static function paying_order( $lock_order_id, array $orders ) {
		$lock_order_id = (int) $lock_order_id;
		$best          = 0;

		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) || self::order_is_dead( $order ) ) {
				continue;
			}
			$id = (int) $order['id'];
			if ( $lock_order_id > 0 && $id === $lock_order_id ) {
				return $id;
			}
			if ( ! empty( $order['paid'] ) && $id > $best ) {
				$best = $id;
			}
		}

		return $best;
	}

	/**
	 * One order's facts out of a list.
	 *
	 * @param array[] $orders Order facts.
	 * @param int     $id     Order id.
	 * @return array|null
	 */
	public static function order_by_id( array $orders, $id ) {
		foreach ( $orders as $order ) {
			if ( is_array( $order ) && (int) ( isset( $order['id'] ) ? $order['id'] : 0 ) === (int) $id ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * "#1234 (Processing)" for a message.
	 *
	 * @param array $order Order facts.
	 * @return string
	 */
	public static function order_label( array $order ) {
		$number = isset( $order['number'] ) && '' !== (string) $order['number'] ? (string) $order['number'] : (string) ( isset( $order['id'] ) ? $order['id'] : '' );
		$status = isset( $order['status_label'] ) && '' !== (string) $order['status_label'] ? (string) $order['status_label'] : (string) ( isset( $order['status'] ) ? $order['status'] : '' );
		return '#' . $number . ( '' !== $status ? ' (' . $status . ')' : '' );
	}

	/**
	 * What an invoice really is right now, and what may be done with it.
	 *
	 * The stored state is only what this section last knew; the product
	 * and the orders are the truth. A draft or a cancelled invoice has no
	 * product and is returned as stored. Otherwise:
	 *
	 *   paid       an order pays it (paying_order()) — locked for good.
	 *   trashed    its product is in the Trash.
	 *   cancelled  its product is gone and it was never paid.
	 *   published  anything else — also a paid invoice whose order was
	 *              cancelled or failed (released), and one taken back out
	 *              of the Trash by hand.
	 *
	 * Nothing is editable or trashable when the orders could not be read
	 * ($orders null): what cannot be checked is not changed.
	 *
	 * @param array      $invoice The stored invoice.
	 * @param array|null $product Shop::product() facts, or null when the product is gone.
	 * @param array|null $orders  Facts of every order that contains the product (and of the
	 *                            theme's lock order), or null when they could not be read.
	 * @return array{state: string, order_id: int, order: array|null, orders: array[], live_orders: array[], orders_known: bool, created_here: bool, locked: bool, lock_reason: string, can_edit: bool, can_trash: bool, trash_reason: string, changed: bool, notes: string[]}
	 */
	public static function effective( array $invoice, $product, $orders ) {
		$stored    = in_array( (string) ( isset( $invoice['state'] ) ? $invoice['state'] : '' ), self::STATES, true ) ? (string) $invoice['state'] : 'draft';
		$stored_id = (int) ( isset( $invoice['order_id'] ) ? $invoice['order_id'] : 0 );

		$out = array(
			'state'        => $stored,
			'order_id'     => 'paid' === $stored ? $stored_id : 0,
			'order'        => null,
			'orders'       => array(),
			'live_orders'  => array(),
			'orders_known' => is_array( $orders ),
			'created_here' => false,
			'locked'       => false,
			'lock_reason'  => '',
			'can_edit'     => false,
			'can_trash'    => false,
			'trash_reason' => '',
			'changed'      => false,
			'notes'        => array(),
		);

		if ( 'draft' === $stored || 'cancelled' === $stored ) {
			$out['locked']       = 'cancelled' === $stored;
			$out['lock_reason']  = 'cancelled' === $stored ? __( 'This invoice was withdrawn: it has no product.', 'fishotel-misc-plugin' ) : '';
			$out['can_edit']     = 'draft' === $stored;
			$out['trash_reason'] = __( 'There is no product to move to the Trash.', 'fishotel-misc-plugin' );
			return $out;
		}

		$has_product = is_array( $product ) && ! empty( $product['exists'] );
		$list        = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( is_array( $order ) && ! empty( $order['id'] ) && empty( $order['mirror'] ) ) {
				$list[] = $order;
			}
		}
		$out['orders'] = $list;
		foreach ( $list as $order ) {
			if ( ! self::order_is_dead( $order ) ) {
				$out['live_orders'][] = $order;
			}
		}

		if ( ! $has_product ) {
			if ( 'published' === $stored ) {
				$out['state'] = 'cancelled';
			}
			$out['locked']       = true;
			$out['lock_reason']  = __( 'The product of this invoice no longer exists.', 'fishotel-misc-plugin' );
			$out['trash_reason'] = $out['lock_reason'];
			$out['changed']      = $out['state'] !== $stored;
			return $out;
		}

		$status              = (string) ( isset( $product['status'] ) ? $product['status'] : '' );
		$lock_order_id       = (int) ( isset( $product['lock_order_id'] ) ? $product['lock_order_id'] : 0 );
		$out['created_here'] = (int) ( isset( $product['marker'] ) ? $product['marker'] : 0 ) === (int) ( isset( $invoice['id'] ) ? $invoice['id'] : -1 )
			&& (int) ( isset( $invoice['product_id'] ) ? $invoice['product_id'] : -1 ) === (int) ( isset( $product['id'] ) ? $product['id'] : 0 );

		if ( $out['orders_known'] ) {
			$paying = self::paying_order( $lock_order_id, $list );
			if ( $paying > 0 ) {
				$out['state']    = 'paid';
				$out['order_id'] = $paying;
				$out['order']    = self::order_by_id( $list, $paying );
			} elseif ( 'trash' === $status ) {
				$out['state']    = 'trashed';
				$out['order_id'] = 0;
			} else {
				$out['state']    = 'published';
				$out['order_id'] = 0;
			}
		}

		$out['changed'] = $out['state'] !== $stored || ( 'paid' === $out['state'] && $out['order_id'] !== $stored_id );

		// Locked?
		if ( 'paid' === $out['state'] ) {
			$out['locked']      = true;
			$out['lock_reason'] = $out['order']
				/* translators: %s: order number and status, e.g. "#1234 (Processing)" */
				? sprintf( __( 'Paid on order %s. A paid invoice is never changed: extra fish go on a new invoice.', 'fishotel-misc-plugin' ), self::order_label( $out['order'] ) )
				: __( 'Paid. A paid invoice is never changed: extra fish go on a new invoice.', 'fishotel-misc-plugin' );
		} elseif ( ! $out['orders_known'] ) {
			$out['locked']      = true;
			$out['lock_reason'] = __( 'The orders could not be read, so it cannot be told whether this invoice was paid. Nothing is changed until they can.', 'fishotel-misc-plugin' );
		} elseif ( $out['live_orders'] ) {
			$out['locked']      = true;
			/* translators: %s: order number and status, e.g. "#1234 (On hold)" */
			$out['lock_reason'] = sprintf( __( 'Order %s already contains this invoice: the customer has checked out. It can no longer be changed — extra fish go on a new invoice.', 'fishotel-misc-plugin' ), self::order_label( $out['live_orders'][0] ) );
		} elseif ( ! $out['created_here'] ) {
			$out['locked']      = true;
			$out['lock_reason'] = __( 'This product was not created by this section, so it is never changed here.', 'fishotel-misc-plugin' );
		} elseif ( 'trashed' === $out['state'] ) {
			$out['locked']      = true;
			$out['lock_reason'] = __( 'This invoice is in the Trash.', 'fishotel-misc-plugin' );
		} elseif ( 'publish' !== $status ) {
			$out['locked']      = true;
			/* translators: %s: product status, e.g. "draft" */
			$out['lock_reason'] = sprintf( __( 'The product of this invoice is not published (status: %s).', 'fishotel-misc-plugin' ), $status );
		}

		$out['can_edit'] = ! $out['locked'] && 'published' === $out['state'];

		// The Trash: only when not one order contains the product.
		if ( 'paid' === $out['state'] ) {
			$out['trash_reason'] = __( 'A paid invoice is never moved to the Trash.', 'fishotel-misc-plugin' );
		} elseif ( 'trashed' === $out['state'] || 'trash' === $status ) {
			$out['trash_reason'] = __( 'It is already in the Trash.', 'fishotel-misc-plugin' );
		} elseif ( ! $out['orders_known'] ) {
			$out['trash_reason'] = __( 'The orders could not be read, so it cannot be told whether an order contains this invoice.', 'fishotel-misc-plugin' );
		} elseif ( $list ) {
			$labels = array();
			foreach ( $list as $order ) {
				$labels[] = self::order_label( $order );
			}
			/* translators: %s: orders, e.g. "#1234 (Cancelled), #1250 (Pending payment)" */
			$out['trash_reason'] = sprintf( __( 'An order contains this invoice (%s), so it is not moved to the Trash here.', 'fishotel-misc-plugin' ), implode( ', ', $labels ) );
		} elseif ( $lock_order_id > 0 ) {
			/* translators: %d: order id */
			$out['trash_reason'] = sprintf( __( 'The product is marked as bought by order #%d, so it is not moved to the Trash here.', 'fishotel-misc-plugin' ), $lock_order_id );
		} elseif ( ! $out['created_here'] ) {
			$out['trash_reason'] = __( 'This product was not created by this section, so it is never changed here.', 'fishotel-misc-plugin' );
		} else {
			$out['can_trash'] = true;
		}

		return $out;
	}

	/**
	 * A state as the screens name it.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function state_label( $state ) {
		switch ( (string) $state ) {
			case 'published':
				return __( 'Published — not paid', 'fishotel-misc-plugin' );
			case 'paid':
				return __( 'Paid', 'fishotel-misc-plugin' );
			case 'cancelled':
				return __( 'Withdrawn', 'fishotel-misc-plugin' );
			case 'trashed':
				return __( 'In the Trash', 'fishotel-misc-plugin' );
		}
		return __( 'Draft', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  The pre-check: a fish on two invoices                          */
	/* ============================================================== */

	/**
	 * Why two fish lines are the same fish, or '' when they are not: the
	 * same tank group, the same shop product (or variation), the same
	 * species-list entry, or the same name (fish_key()).
	 *
	 * @param array $a One line.
	 * @param array $b Another.
	 * @return string stay|product|species|name|''.
	 */
	public static function fish_match( array $a, array $b ) {
		$get = static function ( array $line, $key ) {
			return isset( $line[ $key ] ) && is_numeric( $line[ $key ] ) && (int) $line[ $key ] > 0 ? (int) $line[ $key ] : 0;
		};

		if ( $get( $a, 'stay_id' ) > 0 && $get( $a, 'stay_id' ) === $get( $b, 'stay_id' ) ) {
			return 'stay';
		}

		$ids_a = array_filter( array( $get( $a, 'product_id' ), $get( $a, 'variation_id' ) ) );
		$ids_b = array_filter( array( $get( $b, 'product_id' ), $get( $b, 'variation_id' ) ) );
		if ( $ids_a && $ids_b && array_intersect( $ids_a, $ids_b ) ) {
			return 'product';
		}

		if ( $get( $a, 'species_id' ) > 0 && $get( $a, 'species_id' ) === $get( $b, 'species_id' ) ) {
			return 'species';
		}

		$key = self::fish_key( isset( $a['name'] ) ? $a['name'] : '' );
		if ( '' !== $key && self::fish_key( isset( $b['name'] ) ? $b['name'] : '' ) === $key ) {
			return 'name';
		}

		return '';
	}

	/**
	 * The key one match is confirmed by: the fish and the invoice it was
	 * found on.
	 *
	 * @param array  $line The new invoice's line.
	 * @param string $ref  The other invoice's reference.
	 * @return string
	 */
	public static function dup_key( array $line, $ref ) {
		return md5( self::fish_key( isset( $line['name'] ) ? $line['name'] : '' ) . '|' . (string) $ref );
	}

	/**
	 * Every fish of a new invoice that already appears on another invoice
	 * of the same customer.
	 *
	 * @param array[] $lines  The new invoice's lines.
	 * @param array[] $others The customer's other invoices: ref (unique), label, paid (bool),
	 *                        state (text), lines (each kind, name, qty and whatever ids it has).
	 * @return array[] One entry per flagged line: index (its position in $lines), name, matches —
	 *                 each key, ref, label, paid, state, name, qty, by (stay|product|species|name).
	 */
	public static function duplicates( array $lines, array $others ) {
		$out = array();

		foreach ( array_values( $lines ) as $index => $line ) {
			if ( ! is_array( $line ) || 'fish' !== ( isset( $line['kind'] ) ? $line['kind'] : 'fish' ) ) {
				continue;
			}

			$matches = array();
			foreach ( $others as $other ) {
				if ( ! is_array( $other ) || empty( $other['ref'] ) ) {
					continue;
				}
				foreach ( isset( $other['lines'] ) && is_array( $other['lines'] ) ? $other['lines'] : array() as $theirs ) {
					if ( ! is_array( $theirs ) || 'fish' !== ( isset( $theirs['kind'] ) ? $theirs['kind'] : 'fish' ) ) {
						continue;
					}
					$by = self::fish_match( $line, $theirs );
					if ( '' === $by ) {
						continue;
					}
					$matches[] = array(
						'key'   => self::dup_key( $line, (string) $other['ref'] ),
						'ref'   => (string) $other['ref'],
						'label' => (string) ( isset( $other['label'] ) ? $other['label'] : $other['ref'] ),
						'paid'  => ! empty( $other['paid'] ),
						'state' => (string) ( isset( $other['state'] ) ? $other['state'] : '' ),
						'name'  => (string) ( isset( $theirs['name'] ) ? $theirs['name'] : '' ),
						'qty'   => max( 1, (int) ( isset( $theirs['qty'] ) ? $theirs['qty'] : 1 ) ),
						'by'    => $by,
					);
					break; // One match per invoice is enough.
				}
			}

			if ( $matches ) {
				$out[] = array(
					'index'   => $index,
					'name'    => (string) ( isset( $line['name'] ) ? $line['name'] : '' ),
					'matches' => $matches,
				);
			}
		}

		return $out;
	}

	/**
	 * The keys of every match in a duplicates() result.
	 *
	 * @param array[] $duplicates duplicates().
	 * @return string[]
	 */
	public static function dup_keys( array $duplicates ) {
		$keys = array();
		foreach ( $duplicates as $entry ) {
			foreach ( isset( $entry['matches'] ) && is_array( $entry['matches'] ) ? $entry['matches'] : array() as $match ) {
				$keys[ (string) $match['key'] ] = true;
			}
		}
		return array_keys( $keys );
	}

	/**
	 * The matches nobody confirmed yet — Publish stays shut while this is
	 * not empty.
	 *
	 * @param array[] $duplicates duplicates().
	 * @param mixed   $confirmed  The confirmed keys.
	 * @return string[]
	 */
	public static function unconfirmed( array $duplicates, $confirmed ) {
		$confirmed = array_map( 'strval', array_filter( is_array( $confirmed ) ? $confirmed : array(), 'is_scalar' ) );
		return array_values( array_diff( self::dup_keys( $duplicates ), $confirmed ) );
	}

	/**
	 * A fingerprint of what an invoice would publish: the customer, the
	 * shipping, the stated price and every line. The pre-check hands out
	 * a token for one fingerprint, and Publish only takes a token whose
	 * fingerprint is still the invoice's — so what goes live is exactly
	 * what was checked.
	 *
	 * @param array   $invoice Invoice fields.
	 * @param array[] $lines   Lines.
	 * @return string
	 */
	public static function content_hash( array $invoice, array $lines ) {
		$get  = static function ( $key ) use ( $invoice ) {
			return isset( $invoice[ $key ] ) && null !== $invoice[ $key ] ? (string) $invoice[ $key ] : '';
		};
		$flat = isset( $invoice['flat_total'] ) && null !== $invoice['flat_total'] && '' !== $invoice['flat_total'] ? (string) Lines::cents( $invoice['flat_total'] ) : '';
		$data = array(
			self::handle_key( $get( 'handle' ) ),
			(string) (int) $get( 'forum_user_id' ),
			(string) (int) $get( 'customer_id' ),
			self::lower( $get( 'customer_name' ) ),
			self::lower( $get( 'customer_email' ) ),
			$get( 'shipping' ),
			$get( 'shipping_slug' ),
			$flat,
		);

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$data[] = implode(
				'~',
				array(
					(string) ( isset( $line['kind'] ) ? $line['kind'] : '' ),
					(string) ( isset( $line['name'] ) ? $line['name'] : '' ),
					(string) (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ),
					isset( $line['unit_price'] ) && null !== $line['unit_price'] ? (string) Lines::cents( $line['unit_price'] ) : '',
					(string) (int) ( isset( $line['product_id'] ) ? $line['product_id'] : 0 ),
					(string) (int) ( isset( $line['variation_id'] ) ? $line['variation_id'] : 0 ),
					(string) (int) ( isset( $line['species_id'] ) ? $line['species_id'] : 0 ),
					(string) (int) ( isset( $line['stay_id'] ) ? $line['stay_id'] : 0 ),
					(string) ( isset( $line['size_slug'] ) ? $line['size_slug'] : '' ),
					(string) ( isset( $line['source'] ) ? $line['source'] : '' ),
					! empty( $line['take_stock'] ) ? '1' : '0',
				)
			);
		}

		return md5( implode( "\n", $data ) );
	}

	/* ============================================================== */
	/*  Validation                                                     */
	/* ============================================================== */

	/**
	 * Why a line would not read back from the description as it was
	 * typed, or '' when it does. The description is read by the theme and
	 * by the Batch plugin's pick list with one grammar (Grammar): a fish
	 * named "Credit…" would be read as a credit, a note that ends in "x2"
	 * as a fish, a credit whose reason carries an amount with the wrong
	 * amount.
	 *
	 * @param array $line Line (normalised).
	 * @return string
	 */
	public static function roundtrip_problem( array $line ) {
		$kind = isset( $line['kind'] ) ? (string) $line['kind'] : 'fish';
		$text = Lines::line_text( $line );
		if ( '' === $text ) {
			return '';
		}

		$read  = Grammar::parse_line( Grammar::clean_text( htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' ) ) );
		$cents = Lines::amount_cents( $line );

		if ( 'note' === $kind ) {
			if ( 'fish' === $read['kind'] ) {
				return __( 'This note reads like a fish line (it ends in "x" and a number). Reword it.', 'fishotel-misc-plugin' );
			}
			if ( 'credit' === $read['kind'] ) {
				return __( 'This note reads like a credit (it starts with "Credit" or carries a negative amount). Reword it, or make it a credit line.', 'fishotel-misc-plugin' );
			}
			return '';
		}

		if ( 'credit' === $kind || ( 'discount' === $kind && $cents <= 0 ) ) {
			if ( 'credit' !== $read['kind'] || Lines::cents( $read['price'] ) !== $cents ) {
				return __( 'The reason of this credit carries an amount of its own, so the credit would be read with the wrong amount. Take the amount out of the reason.', 'fishotel-misc-plugin' );
			}
			return '';
		}

		// A fish, or an Adjustment (which goes out as a one-off fish line).
		if ( 'fish' !== $read['kind'] ) {
			return 'credit' === $read['kind']
				? __( 'This fish would be read as a credit (its name starts with "Credit" or carries a negative amount). Rename it.', 'fishotel-misc-plugin' )
				: __( 'This fish would not be read as a fish line. Simplify its name.', 'fishotel-misc-plugin' );
		}

		$qty   = 'discount' === $kind ? 1 : max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) );
		$price = 'discount' === $kind ? $cents : ( isset( $line['unit_price'] ) && null !== $line['unit_price'] ? Lines::cents( $line['unit_price'] ) : null );
		$back  = null === $read['price'] ? null : Lines::cents( $read['price'] );

		if ( (int) $read['qty'] !== $qty || $back !== $price || Lines::public_name( $line ) !== $read['name'] ) {
			return __( 'This fish would not be read back with the same name, quantity and price. Simplify its name.', 'fishotel-misc-plugin' );
		}

		return '';
	}

	/**
	 * Check an invoice before it is published.
	 *
	 * Errors stop Publish: no handle, no fish, a negative or oversized
	 * price, a credit without an amount, a line that would not read back
	 * (roundtrip_problem()), a total below zero. Warnings are shown and
	 * do not stop it: a fish without a price (the order then shows the
	 * invoice as one line instead of one line per fish), a total of zero,
	 * an Adjustment line.
	 *
	 * @param array   $invoice Invoice fields (handle).
	 * @param array[] $lines   Lines, with the discount line when there is one.
	 * @return array{errors: string[], warnings: string[], lines: array<int, string>}
	 *               `lines` maps a line's position to its error.
	 */
	public static function validate( array $invoice, array $lines ) {
		$errors   = array();
		$warnings = array();
		$by_line  = array();
		$fish     = 0;
		$unpriced = array();

		if ( '' === self::handle_key( isset( $invoice['handle'] ) ? $invoice['handle'] : '' ) ) {
			$errors[] = __( 'Type the customer\'s handle.', 'fishotel-misc-plugin' );
		}

		foreach ( array_values( $lines ) as $index => $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$kind  = isset( $line['kind'] ) ? (string) $line['kind'] : 'fish';
			$name  = Lines::public_name( $line );
			$price = isset( $line['unit_price'] ) && null !== $line['unit_price'] ? (float) $line['unit_price'] : null;
			$issue = '';

			if ( 'fish' === $kind ) {
				$fish++;
				if ( null !== $price && $price < 0 ) {
					$issue = __( 'A fish cannot have a negative price. Use a credit line for money back.', 'fishotel-misc-plugin' );
				} elseif ( null === $price || Lines::cents( $price ) <= 0 ) {
					$unpriced[] = $name;
				}
			} elseif ( 'credit' === $kind && 0 === Lines::cents( $price ) ) {
				$issue = __( 'This credit has no amount.', 'fishotel-misc-plugin' );
			}

			if ( '' === $issue ) {
				$issue = self::roundtrip_problem( $line );
			}
			if ( '' !== $issue ) {
				$by_line[ $index ] = $issue;
				$errors[]          = '"' . $name . '": ' . $issue;
			}

			if ( 'discount' === $kind && Lines::amount_cents( $line ) > 0 ) {
				/* translators: %s: amount, e.g. "$20" */
				$warnings[] = sprintf( __( 'The flat total is %s higher than the lines. An "Adjustment" line makes up the difference; on the order and on the pick list it appears as a line of its own.', 'fishotel-misc-plugin' ), Lines::dollars( Lines::amount( $line ) ) );
			}
		}

		if ( 0 === $fish ) {
			$errors[] = __( 'Add at least one fish.', 'fishotel-misc-plugin' );
		}

		$total = Lines::cents( Lines::total( $lines ) );
		if ( $total < 0 ) {
			/* translators: %s: amount, e.g. "-$12" */
			$errors[] = sprintf( __( 'The lines add up to %s. An invoice cannot be negative: lower the credit, or refund on the paid order instead.', 'fishotel-misc-plugin' ), Lines::dollars( $total / 100 ) );
		} elseif ( 0 === $total && $fish > 0 ) {
			$warnings[] = __( 'The total is $0: the customer checks out without paying anything for this invoice.', 'fishotel-misc-plugin' );
		}

		if ( $unpriced ) {
			/* translators: %s: fish names */
			$warnings[] = sprintf( __( 'No price on: %s. It counts as $0, and the order will show this invoice as ONE line instead of one line per fish.', 'fishotel-misc-plugin' ), implode( ', ', array_unique( $unpriced ) ) );
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'lines'    => $by_line,
		);
	}

	/* ============================================================== */
	/*  Hand-made invoices: whose is it?                               */
	/* ============================================================== */

	/**
	 * What a title format puts around the handle — for "{handle} Invoice
	 * ({date})" nothing before it and "invoice" after it. That frame is
	 * what a hand-made title is recognised by.
	 *
	 * @param string $format Title format.
	 * @return array{before: string, anchored: bool, after: string} `before`: the text right before the
	 *               handle; `anchored`: whether the title starts with it (false when the date comes
	 *               first); `after`: the word(s) right after the handle, or — when a bracket or
	 *               other punctuation follows directly — that punctuation.
	 */
	private static function title_frame( $format ) {
		$format = Format::usable( $format ) ? (string) $format : Format::DEFAULT_TITLE;
		$parts  = explode( '{handle}', $format, 2 );

		$anchored = false === strpos( $parts[0], '{date}' );
		$before   = self::handle_key( $anchored ? $parts[0] : (string) preg_replace( '/^.*\{date\}/su', '', $parts[0] ) );

		$rest  = (string) preg_replace( '/\{date\}.*$/su', '', isset( $parts[1] ) ? $parts[1] : '' );
		$words = self::handle_key( (string) preg_replace( '/[(\[{].*$/su', '', $rest ) );
		$after = '' !== $words ? $words : self::handle_key( $rest );

		return array(
			'before'   => $before,
			'anchored' => $anchored,
			'after'    => $after,
		);
	}

	/**
	 * The pattern a title of a format matches, with the handle given (a
	 * quoted text) or captured (a group).
	 *
	 * @param string $format Title format.
	 * @param string $handle The pattern piece that stands for the handle.
	 * @return string
	 */
	private static function title_pattern( $format, $handle ) {
		$frame = self::title_frame( $format );
		$word  = '[\p{L}\p{N}]';

		$pattern = $frame['anchored'] ? '^' : '^.*?(?<!' . $word . ')';
		if ( '' !== $frame['before'] ) {
			$pattern .= preg_quote( $frame['before'], '/' ) . '\s*';
		}
		$pattern .= $handle;

		if ( '' === $frame['after'] ) {
			return '/' . $pattern . '\s*$/iu';
		}

		$starts_with_word = (bool) preg_match( '/^' . $word . '/u', $frame['after'] );
		$ends_with_word   = (bool) preg_match( '/' . $word . '$/u', $frame['after'] );

		return '/' . $pattern . ( $starts_with_word ? '\s+' : '\s*' ) . preg_quote( $frame['after'], '/' ) . ( $ends_with_word ? '(?!' . $word . ')' : '' ) . '/iu';
	}

	/**
	 * How the title of this handle's invoices starts, lower-case — "jpreef
	 * invoice" — for looking order lines up by name. '' without a handle,
	 * and for a format whose titles do not start with the handle's frame.
	 *
	 * @param mixed  $handle Handle.
	 * @param string $format Title format ('' = the default).
	 * @return string
	 */
	public static function title_start( $handle, $format = '' ) {
		$handle = self::handle_key( $handle );
		$frame  = self::title_frame( $format );
		if ( '' === $handle || ! $frame['anchored'] ) {
			return '';
		}
		$after = preg_match( '/^[\p{L}\p{N}]/u', $frame['after'] ) ? ' ' . $frame['after'] : '';
		return trim( ( '' !== $frame['before'] ? $frame['before'] . ' ' : '' ) . $handle . $after );
	}

	/**
	 * The word a hand-made invoice's title can be searched by when the
	 * handle itself is a poor search text (it holds characters a title
	 * may store differently, like "&"): a word of the format's frame —
	 * "Invoice".
	 *
	 * @param string $format Title format ('' = the default).
	 * @return string
	 */
	public static function title_word( $format = '' ) {
		$frame = self::title_frame( $format );
		foreach ( array( $frame['after'], $frame['before'] ) as $text ) {
			if ( preg_match( '/[\p{L}\p{N}]+/u', $text, $m ) ) {
				return $m[0];
			}
		}
		return 'invoice';
	}

	/**
	 * Whether a product title is an invoice of this handle: the title
	 * holds the handle in the format's frame — so "Dan Invoice (7/3/26)"
	 * belongs to Dan and "Dan Smith Invoice (7/3/26)" does not. Case and
	 * spacing are ignored.
	 *
	 * @param mixed  $title  Product title.
	 * @param mixed  $handle Handle.
	 * @param string $format Title format ('' = the default).
	 * @return bool
	 */
	public static function title_is_for_handle( $title, $handle, $format = '' ) {
		$title  = self::handle_key( $title );
		$handle = self::handle_key( $handle );
		if ( '' === $title || '' === $handle ) {
			return false;
		}
		return (bool) preg_match( self::title_pattern( $format, preg_quote( $handle, '/' ) ), $title );
	}

	/**
	 * The handle a hand-made title names ("JPReef Invoice (7/3/26)" →
	 * "JPReef"), or '' when the title does not look like an invoice.
	 *
	 * @param mixed  $title  Product title.
	 * @param string $format Title format ('' = the default).
	 * @return string
	 */
	public static function handle_from_title( $title, $format = '' ) {
		if ( ! is_scalar( $title ) ) {
			return '';
		}
		$text = html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return preg_match( self::title_pattern( $format, '(?<handle>.+?)' ), $text, $m ) ? trim( $m['handle'] ) : '';
	}
}
