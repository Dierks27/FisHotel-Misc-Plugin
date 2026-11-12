<?php
/**
 * The publisher — everything that changes an invoice, with the store and
 * the shop as parameters so the whole of it is tested on in-memory twins.
 *
 *   save_draft()       the form into the two tables. A draft is not a
 *                      product: no customer can see it.
 *   precheck()         saves the draft, runs the pre-check (Precheck) on
 *                      exactly what was saved, and hands out a token for
 *                      that content.
 *   publish()          only with that token: the stored draft must still
 *                      be what was checked (Rules::content_hash()), the
 *                      pre-check is run AGAIN and must be free of errors,
 *                      and every fish that already appears on another
 *                      invoice of the customer must be confirmed. Then
 *                      the product is created, read back and compared
 *                      field by field (verify()), and the invoice becomes
 *                      "published".
 *   precheck_update()  the same for "Edit this unpaid invoice" — the
 *   update()           deliberate path. Refused unless the invoice is
 *                      published, of this section, unpaid and in no live
 *                      order — checked when the editor opens, again at
 *                      save, and once more right before the product is
 *                      written. The title and the link never change.
 *   trash()            only when not one order contains the product; to
 *                      the Trash, never deleted.
 *   discard()          a draft that will not be published: "withdrawn".
 *
 * So the pre-check cannot be skipped — no request publishes without a
 * token, and a token is only ever issued together with a report — and
 * what goes live is exactly what was checked.
 *
 * One writer per invoice: every write of an existing invoice holds its
 * lock (Store::lock(), given back by the database when the request ends),
 * and reads the row inside it. Of several Publish requests sent at the
 * same moment exactly one creates a product; the others are refused. A
 * refusal never moves the invoice's version on, and a form that names an
 * older version than the stored one is refused — at the pre-check of an
 * edit too — so a window that is behind cannot undo a newer save.
 *
 * A half-finished Publish never makes a second product: the product
 * carries the invoice's marker, and the next attempt finds and reuses it
 * (Shop::adopt_invoice_product()). A product that does not come out as
 * written is taken offline again before any customer could be sent to it.
 *
 * A sale price is never set and never cleared. No e-mail is sent, no
 * order is written, and no stock but the new product's own "1" is set.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Publisher
 */
class Publisher {

	/**
	 * A pre-check token is good for at least this long, at most twice it.
	 *
	 * @var int
	 */
	const TOKEN_SECONDS = 7200;

	const MAX_NOTES = 2000;

	/**
	 * The store (or its in-memory twin).
	 *
	 * @var object
	 */
	private $store;

	/**
	 * The shop (or twin).
	 *
	 * @var object
	 */
	private $shop;

	/**
	 * The tanks (or twin).
	 *
	 * @var object
	 */
	private $tanks;

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param object $store    Store.
	 * @param object $shop     Shop.
	 * @param object $tanks    Tanks.
	 * @param array  $settings Settings.
	 */
	public function __construct( $store, $shop, $tanks, array $settings ) {
		$this->store    = $store;
		$this->shop     = $shop;
		$this->tanks    = $tanks;
		$this->settings = $settings;
	}

	/**
	 * A fresh pre-check (it remembers the orders it read, so each
	 * decision gets its own).
	 *
	 * @return Precheck
	 */
	private function check() {
		return new Precheck( $this->store, $this->shop, $this->tanks, $this->settings );
	}

	/* ============================================================== */
	/*  Small helpers                                                  */
	/* ============================================================== */

	/**
	 * A refusal.
	 *
	 * @param string $message Why, in plain words.
	 * @param string $code    A short code for the page.
	 * @param array  $extra   More to hand back.
	 * @return array
	 */
	private static function refuse( $message, $code = 'refused', array $extra = array() ) {
		return array_merge(
			array(
				'ok'      => false,
				'code'    => (string) $code,
				'message' => (string) $message,
			),
			$extra
		);
	}

	/**
	 * A store failure in plain words.
	 *
	 * @param array $result Store::save_invoice() result.
	 * @return array
	 */
	private static function store_refusal( array $result ) {
		$code = isset( $result['error'] ) ? (string) $result['error'] : 'db';
		if ( 'conflict' === $code ) {
			return self::refuse( __( 'This invoice was changed in another window since this page was opened. Nothing was saved: reload the page to see it as it is now.', 'fishotel-misc-plugin' ), 'conflict' );
		}
		if ( 'missing' === $code ) {
			return self::refuse( __( 'This invoice no longer exists.', 'fishotel-misc-plugin' ), 'missing' );
		}
		return self::refuse( __( 'The database refused the save. Nothing was changed.', 'fishotel-misc-plugin' ), 'db' );
	}

	/**
	 * Do one invoice's write while holding its lock, so that of several
	 * requests for the same invoice at the same moment — a double click,
	 * two windows — exactly one works and the others are told so. Without
	 * it five simultaneous Publish requests made three live products.
	 *
	 * @param int      $invoice_id Invoice id.
	 * @param callable $work       The write; its answer is handed on.
	 * @return array
	 */
	private function locked( $invoice_id, callable $work ) {
		$id = (int) $invoice_id;
		if ( $id <= 0 ) {
			return $work(); // No such invoice: the work says so itself.
		}
		if ( ! $this->store->lock( $id ) ) {
			return self::refuse( __( 'This invoice is being saved or published in another window at this very moment (its lock could not be taken). Nothing was done here: reload the page to see how it stands.', 'fishotel-misc-plugin' ), 'busy' );
		}

		try {
			return $work();
		} finally {
			$this->store->unlock( $id );
		}
	}

	/**
	 * A positive int out of a posted value, or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function int_or_null( $value ) {
		return is_scalar( $value ) && is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Internal notes: plain text, line breaks kept.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function notes( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = Lines::untag( (string) $value );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text );
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_NOTES, 'UTF-8' ) : substr( $text, 0, self::MAX_NOTES );
	}

	/**
	 * The posted form as clean fields and lines (pure): the customer, the
	 * shipping choice, the stated price, the notes, and the lines with
	 * the discount line that makes them add up to the stated price.
	 *
	 * @param mixed $raw The form: handle, forum_user_id, customer_id, customer_name, customer_email,
	 *                   shipping, shipping_slug, flat_on, flat_total, notes, lines.
	 * @return array{fields: array, lines: array[], subtotal: float, discount: float, total: float, errors: string[]}
	 */
	public static function compose( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$errors = array();
		$get    = static function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) ? $raw[ $key ] : null;
		};

		$handle = Lines::text( $get( 'handle' ), Rules::MAX_HANDLE );
		$email  = is_scalar( $get( 'customer_email' ) ) ? strtolower( trim( (string) $get( 'customer_email' ) ) ) : '';
		if ( '' !== $email && ( strlen( $email ) > 190 || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ) {
			$errors[] = __( 'The e-mail address is not a valid address.', 'fishotel-misc-plugin' );
			$email    = '';
		}

		$shipping = is_string( $get( 'shipping' ) ) && in_array( $get( 'shipping' ), Settings::SHIPPING_CHOICES, true ) ? $get( 'shipping' ) : 'premium';

		$flat = null;
		if ( Lines::truthy( $get( 'flat_on' ) ) ) {
			$typed = $get( 'flat_total' );
			if ( null === $typed || ( is_string( $typed ) && '' === trim( $typed ) ) ) {
				$errors[] = __( 'Type the flat total, or untick "Charge a flat total".', 'fishotel-misc-plugin' );
			} else {
				$flat = is_scalar( $typed ) ? Lines::money( is_bool( $typed ) ? '' : $typed ) : null;
				if ( null === $flat ) {
					$errors[] = __( 'The flat total is not an amount.', 'fishotel-misc-plugin' );
				} elseif ( $flat < 0 ) {
					$errors[] = __( 'The flat total cannot be negative.', 'fishotel-misc-plugin' );
					$flat     = null;
				}
			}
		}

		$sum = Lines::with_flat_total( Lines::normalize( $get( 'lines' ) ), $flat );

		return array(
			'fields'   => array(
				'handle'         => $handle,
				'handle_key'     => Rules::handle_key( $handle ),
				'forum_user_id'  => self::int_or_null( $get( 'forum_user_id' ) ),
				'customer_id'    => self::int_or_null( $get( 'customer_id' ) ),
				'customer_name'  => Lines::text( $get( 'customer_name' ), 190 ),
				'customer_email' => $email,
				'shipping'       => $shipping,
				'shipping_slug'  => 'other' === $shipping ? Settings::slug( $get( 'shipping_slug' ) ) : '',
				'flat_total'     => $flat,
				'total'          => $sum['total'],
				'notes'          => self::notes( $get( 'notes' ) ),
			),
			'lines'    => $sum['lines'],
			'subtotal' => $sum['subtotal'],
			'discount' => $sum['discount'],
			'total'    => $sum['total'],
			'errors'   => $errors,
		);
	}

	/* ============================================================== */
	/*  The pre-check token                                            */
	/* ============================================================== */

	/**
	 * The token a pre-check hands out: bound to the user, the invoice,
	 * the fingerprint of its content and the kind of write, and to the
	 * hour or two it was issued in.
	 *
	 * @param int      $user_id    User.
	 * @param int      $invoice_id Invoice.
	 * @param string   $hash       Rules::content_hash().
	 * @param string   $mode       publish|update.
	 * @param int|null $tick       The time slot (null = now).
	 * @return string
	 */
	public static function token( $user_id, $invoice_id, $hash, $mode, $tick = null ) {
		$tick   = null === $tick ? (int) floor( time() / self::TOKEN_SECONDS ) : (int) $tick;
		$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'nonce' ) : 'fishotel-invoices';
		return substr( hash_hmac( 'sha256', implode( '|', array( (int) $user_id, (int) $invoice_id, (string) $hash, (string) $mode, $tick ) ), $secret ), 0, 40 );
	}

	/**
	 * Whether a token is the one a pre-check issued for this content, in
	 * this time slot or the one before.
	 *
	 * @param mixed  $token      The posted token.
	 * @param int    $user_id    User.
	 * @param int    $invoice_id Invoice.
	 * @param string $hash       Rules::content_hash() of what is about to be written.
	 * @param string $mode       publish|update.
	 * @return bool
	 */
	public static function token_ok( $token, $user_id, $invoice_id, $hash, $mode ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		$tick = (int) floor( time() / self::TOKEN_SECONDS );
		return hash_equals( self::token( $user_id, $invoice_id, $hash, $mode, $tick ), $token )
			|| hash_equals( self::token( $user_id, $invoice_id, $hash, $mode, $tick - 1 ), $token );
	}

	/* ============================================================== */
	/*  Drafts                                                         */
	/* ============================================================== */

	/**
	 * Save the form as a draft (a new one, or a draft that exists).
	 *
	 * @param mixed $raw     The form (+ id and version of an existing draft).
	 * @param int   $user_id Who.
	 * @return array ok, message, and on success id, invoice, lines.
	 */
	public function save_draft( $raw, $user_id ) {
		$raw = is_array( $raw ) ? $raw : array();
		$in  = self::compose( $raw );
		$id  = (int) self::int_or_null( isset( $raw['id'] ) ? $raw['id'] : null );

		if ( $in['errors'] ) {
			return self::refuse( implode( ' ', $in['errors'] ), 'input' );
		}
		if ( '' === $in['fields']['handle_key'] ) {
			return self::refuse( __( 'Type the customer\'s handle first.', 'fishotel-misc-plugin' ), 'input' );
		}

		$fields  = $in['fields'];
		$version = null;

		if ( $id > 0 ) {
			$existing = $this->store->invoice( $id );
			if ( ! $existing ) {
				return self::refuse( __( 'This invoice no longer exists.', 'fishotel-misc-plugin' ), 'missing' );
			}
			if ( 'draft' !== $existing['state'] ) {
				return self::refuse( __( 'This invoice is not a draft any more, so it cannot be saved as one. Reload the page.', 'fishotel-misc-plugin' ), 'state' );
			}
			$version = isset( $raw['version'] ) && is_numeric( $raw['version'] ) ? (int) $raw['version'] : null;
		} else {
			$fields['state'] = 'draft';
		}

		$save   = function () use ( $id, $fields, $in, $version, $user_id ) {
			return $this->store->save_invoice( $id, $fields, $in['lines'], $version, (int) $user_id );
		};
		$result = $id > 0 ? $this->locked( $id, $save ) : $save();
		if ( empty( $result['ok'] ) ) {
			return isset( $result['code'] ) ? $result : self::store_refusal( $result );
		}

		return array(
			'ok'      => true,
			'code'    => 'saved',
			'message' => __( 'Draft saved.', 'fishotel-misc-plugin' ),
			'id'      => (int) $result['id'],
			'invoice' => $this->store->invoice( (int) $result['id'] ),
			'lines'   => $this->store->lines( (int) $result['id'] ),
		);
	}

	/**
	 * Save the draft and pre-check exactly what was saved.
	 *
	 * @param mixed  $raw     The form.
	 * @param int    $user_id Who.
	 * @param string $today   The site date (Y-m-d).
	 * @return array ok, and on success id, invoice, lines, report, token.
	 */
	public function precheck( $raw, $user_id, $today ) {
		$saved = $this->save_draft( $raw, $user_id );
		if ( empty( $saved['ok'] ) ) {
			return $saved;
		}

		$invoice = $saved['invoice'];
		$lines   = $saved['lines'];

		$saved['report'] = $this->check()->report( $invoice, $lines, $today, 'publish' );
		$saved['token']  = self::token( $user_id, $invoice['id'], Rules::content_hash( $invoice, $lines ), 'publish' );
		$saved['code']   = 'checked';

		return $saved;
	}

	/**
	 * Withdraw a draft that will not be published. The row is kept.
	 *
	 * @param int $invoice_id Invoice id.
	 * @param int $user_id    Who.
	 * @return array ok, message.
	 */
	public function discard( $invoice_id, $user_id ) {
		return $this->locked(
			$invoice_id,
			function () use ( $invoice_id, $user_id ) {
				return $this->discard_locked( $invoice_id, $user_id );
			}
		);
	}

	/**
	 * discard(), holding the invoice's lock.
	 *
	 * @param int $invoice_id Invoice id.
	 * @param int $user_id    Who.
	 * @return array
	 */
	private function discard_locked( $invoice_id, $user_id ) {
		$invoice = $this->store->invoice( (int) $invoice_id );
		if ( ! $invoice ) {
			return self::refuse( __( 'This invoice no longer exists.', 'fishotel-misc-plugin' ), 'missing' );
		}
		if ( 'draft' !== $invoice['state'] ) {
			return self::refuse( __( 'Only a draft can be withdrawn. A published invoice goes to the Trash instead, when no order contains it.', 'fishotel-misc-plugin' ), 'state' );
		}

		$orphan = $this->shop->product_by_marker( (int) $invoice['id'] );
		if ( $orphan > 0 ) {
			/* translators: %d: product id */
			return self::refuse( sprintf( __( 'An earlier Publish of this draft did not finish and left product #%d behind. Press "Pre-check and publish" to finish it, or move that product to the Trash by hand first.', 'fishotel-misc-plugin' ), $orphan ), 'orphan' );
		}

		$result = $this->store->save_invoice( (int) $invoice['id'], array( 'state' => 'cancelled' ), null, null, (int) $user_id );
		if ( empty( $result['ok'] ) ) {
			return self::store_refusal( $result );
		}

		return array(
			'ok'      => true,
			'code'    => 'discarded',
			'message' => __( 'The draft was withdrawn.', 'fishotel-misc-plugin' ),
			'id'      => (int) $invoice['id'],
		);
	}

	/* ============================================================== */
	/*  Publish                                                        */
	/* ============================================================== */

	/**
	 * The fields the product is written with, from a pre-check's writes.
	 *
	 * @param array $writes     Precheck::writes().
	 * @param int   $invoice_id The invoice (the product's marker).
	 * @return array
	 */
	private static function product_fields( array $writes, $invoice_id ) {
		return array(
			'name'              => $writes['title'],
			'short_description' => $writes['excerpt'],
			'description'       => $writes['description'],
			'regular_price'     => $writes['regular_price'],
			'category_id'       => $writes['category'] ? (int) $writes['category']['id'] : 0,
			'shipping_class_id' => $writes['shipping_class'] ? (int) $writes['shipping_class']['id'] : 0,
			'image_id'          => (int) $writes['image_id'],
			'lines_json'        => $writes['lines_json'],
			'marker'            => (int) $invoice_id,
		);
	}

	/**
	 * Compare a product read back from the shop with what was written
	 * (pure). After a Publish every field must be right, or the product
	 * is not left online: the regular price, NO sale price, stock managed
	 * with quantity 1 and no backorders, hidden, the Invoices category
	 * and no other, the shipping class, the lines the theme reads, the
	 * description, the title, the short description, this section's
	 * marker. After an edit only what the edit wrote is critical; the
	 * rest — things that may have been changed by hand — is reported.
	 *
	 * @param array $product Shop::product() facts.
	 * @param array $expect  product_fields().
	 * @param bool  $full    After a Publish (true) or after an edit (false).
	 * @return array{critical: string[], warnings: string[]}
	 */
	public static function verify( array $product, array $expect, $full ) {
		$critical = array();
		$warnings = array();

		if ( empty( $product['exists'] ) ) {
			return array(
				'critical' => array( __( 'the product could not be read back', 'fishotel-misc-plugin' ) ),
				'warnings' => array(),
			);
		}

		// What every write must get right.
		if ( '' === (string) $product['regular_price'] || Lines::cents( $product['regular_price'] ) !== Lines::cents( $expect['regular_price'] ) ) {
			/* translators: 1: price found, 2: price written */
			$critical[] = sprintf( __( 'the regular price is "%1$s", not %2$s', 'fishotel-misc-plugin' ), (string) $product['regular_price'], (string) $expect['regular_price'] );
		}
		if ( (int) $product['marker'] !== (int) $expect['marker'] ) {
			$critical[] = __( 'the product does not carry this invoice\'s mark', 'fishotel-misc-plugin' );
		}
		if ( (int) $product['shipping_class_id'] !== (int) $expect['shipping_class_id'] ) {
			$critical[] = __( 'the shipping class is not the one chosen', 'fishotel-misc-plugin' );
		}
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 45 and 45.0 are the same price.
		if ( json_decode( (string) $product['lines_json'], true ) != json_decode( (string) $expect['lines_json'], true ) ) {
			$critical[] = __( 'the lines the theme reads are not the ones written', 'fishotel-misc-plugin' );
		}
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
		if ( Grammar::parse_lines( $product['description'] ) != Grammar::parse_lines( $expect['description'] ) ) {
			$critical[] = __( 'the description does not read back as the lines written', 'fishotel-misc-plugin' );
		}

		// What a Publish must get right, and an edit only reports.
		$rest = array();
		if ( '' !== (string) $product['sale_price'] ) {
			/* translators: %s: sale price */
			$rest[] = sprintf( __( 'the product has a sale price (%s) that this section did not set and never changes — the customer pays the sale price, not the total', 'fishotel-misc-plugin' ), (string) $product['sale_price'] );
		}
		$stock_ok = ! empty( $product['manage_stock'] ) && 1 === $product['stock_quantity'] && 'no' === (string) $product['backorders'];
		if ( ! $stock_ok ) {
			$rest[] = __( 'the stock is not "managed, quantity 1, no backorders"', 'fishotel-misc-plugin' );
		}
		if ( 'hidden' !== (string) $product['catalog_visibility'] ) {
			$rest[] = __( 'the product is not hidden from the catalog', 'fishotel-misc-plugin' );
		}
		if ( array( (int) $expect['category_id'] ) !== array_values( array_map( 'intval', (array) $product['category_ids'] ) ) ) {
			$rest[] = __( 'the product is not in the Invoices category alone', 'fishotel-misc-plugin' );
		}
		if ( 'publish' !== (string) $product['status'] ) {
			/* translators: %s: product status */
			$rest[] = sprintf( __( 'the product is not published (status: %s)', 'fishotel-misc-plugin' ), (string) $product['status'] );
		}
		if ( $full ) {
			if ( Rules::handle_key( $product['name'] ) !== Rules::handle_key( $expect['name'] ) ) {
				$rest[] = __( 'the title is not the one written', 'fishotel-misc-plugin' );
			}
			if ( Grammar::clean_text( $product['short_description'] ) !== Grammar::clean_text( $expect['short_description'] ) ) {
				$rest[] = __( 'the short description is not the one written', 'fishotel-misc-plugin' );
			}
		}

		if ( $full ) {
			$critical = array_merge( $critical, $rest );
		} else {
			$warnings = array_merge( $warnings, $rest );
		}

		if ( $stock_ok && 'instock' !== (string) $product['stock_status'] ) {
			$warnings[] = __( 'the product is out of stock, so it cannot be bought — is the store\'s "out of stock threshold" above 0?', 'fishotel-misc-plugin' );
		}
		if ( $full && (int) $expect['image_id'] > 0 && (int) $product['image_id'] !== (int) $expect['image_id'] ) {
			$warnings[] = __( 'the featured image is not the one chosen', 'fishotel-misc-plugin' );
		}

		return array(
			'critical' => $critical,
			'warnings' => $warnings,
		);
	}

	/**
	 * Publish a draft: create its product.
	 *
	 * @param int    $invoice_id Invoice id.
	 * @param mixed  $token      The token of the pre-check that was shown.
	 * @param mixed  $confirmed  The keys of the fish-on-two-invoices matches that were confirmed.
	 * @param int    $user_id    Who.
	 * @param string $today      The site date (Y-m-d) — the date in the title.
	 * @return array ok, code, message; on success id, product_id, url, title, total, warnings, invoice.
	 *               A refusal for the pre-check's sake carries the fresh report (and its token).
	 */
	public function publish( $invoice_id, $token, $confirmed, $user_id, $today ) {
		return $this->locked(
			$invoice_id,
			function () use ( $invoice_id, $token, $confirmed, $user_id, $today ) {
				return $this->publish_locked( $invoice_id, $token, $confirmed, $user_id, $today );
			}
		);
	}

	/**
	 * publish(), holding the invoice's lock: the row is read inside it, so
	 * a second request finds either the lock taken or the invoice already
	 * published — never a draft it could publish once more.
	 *
	 * @param int    $invoice_id Invoice id.
	 * @param mixed  $token      The token of the pre-check that was shown.
	 * @param mixed  $confirmed  The confirmed fish-on-two-invoices keys.
	 * @param int    $user_id    Who.
	 * @param string $today      The site date (Y-m-d).
	 * @return array
	 */
	private function publish_locked( $invoice_id, $token, $confirmed, $user_id, $today ) {
		$invoice = $this->store->invoice( (int) $invoice_id );
		if ( ! $invoice ) {
			return self::refuse( __( 'This invoice no longer exists.', 'fishotel-misc-plugin' ), 'missing' );
		}
		$id = (int) $invoice['id'];

		if ( 'draft' !== $invoice['state'] ) {
			$product = ! empty( $invoice['product_id'] ) ? $this->shop->product( (int) $invoice['product_id'] ) : null;
			return self::refuse(
				__( 'This invoice was already published. Nothing was done.', 'fishotel-misc-plugin' ),
				'state',
				array( 'url' => $product ? $product['permalink'] : '' )
			);
		}

		$lines = $this->store->lines( $id );
		$hash  = Rules::content_hash( $invoice, $lines );
		if ( ! self::token_ok( $token, $user_id, $id, $hash, 'publish' ) ) {
			return self::refuse( __( 'The pre-check has to be run on the invoice as it is now before it can be published: it was changed since, or the check is more than a few hours old.', 'fishotel-misc-plugin' ), 'precheck' );
		}

		// Run it again: an order or another invoice may have come in since it was shown.
		$report = $this->check()->report( $invoice, $lines, $today, 'publish' );
		$again  = array(
			'report' => $report,
			'token'  => self::token( $user_id, $id, $hash, 'publish' ),
		);
		if ( $report['errors'] ) {
			return self::refuse( implode( ' ', $report['errors'] ), 'errors', $again );
		}
		if ( Rules::unconfirmed( $report['duplicates'], $confirmed ) ) {
			return self::refuse( __( 'A fish on this invoice already appears on another invoice of this customer. Confirm each one before publishing.', 'fishotel-misc-plugin' ), 'confirm', $again );
		}

		$writes = $report['writes'];
		$fields = self::product_fields( $writes, $id );
		$orphan = (int) $this->shop->product_by_marker( $id );
		$made   = $orphan > 0 ? $this->shop->adopt_invoice_product( $orphan, $fields ) : $this->shop->create_invoice_product( $fields );
		if ( empty( $made['ok'] ) ) {
			/* translators: %s: reason */
			return self::refuse( sprintf( __( 'The product could not be created: %s Nothing was published.', 'fishotel-misc-plugin' ), (string) $made['error'] ), 'shop' );
		}

		$product_id = (int) $made['id'];
		$product    = $this->shop->product( $product_id );
		$verify     = self::verify( $product ? $product : array(), $fields, true );

		if ( $verify['critical'] ) {
			$offline = $this->shop->unpublish_product( $product_id, $id );
			Invoices::log(
				'error',
				sprintf( 'Invoice #%1$d: product #%2$d did not come out as written (%3$s).', $id, $product_id, implode( '; ', $verify['critical'] ) ),
				array(
					'invoice_id' => $id,
					'product_id' => $product_id,
				)
			);
			return self::refuse(
				sprintf(
					/* translators: 1: product id, 2: what is wrong, 3: what happened to the product */
					__( 'Product #%1$d did not come out as written: %2$s. %3$s Nothing was published, and no link should be sent.', 'fishotel-misc-plugin' ),
					$product_id,
					implode( '; ', $verify['critical'] ),
					$offline ? __( 'It was taken offline again (it is a product draft now).', 'fishotel-misc-plugin' ) : __( 'It could NOT be taken offline — open the product and unpublish it by hand.', 'fishotel-misc-plugin' )
				),
				'verify',
				array( 'product_id' => $product_id )
			);
		}

		$saved = $this->store->save_invoice(
			$id,
			array(
				'state'         => 'published',
				'product_id'    => $product_id,
				'title'         => $writes['title'],
				'shipping_slug' => $writes['shipping_slug'],
				'total'         => $writes['total'],
				'published_by'  => (int) $user_id,
				'published_at'  => $this->store->now(),
			),
			null,
			(int) $invoice['version'],
			(int) $user_id
		);
		if ( empty( $saved['ok'] ) ) {
			Invoices::log(
				'error',
				sprintf( 'Invoice #%1$d: product #%2$d was created but the invoice could not be marked published.', $id, $product_id ),
				array(
					'invoice_id' => $id,
					'product_id' => $product_id,
				)
			);
			/* translators: %d: product id */
			return self::refuse( sprintf( __( 'The product was created (#%d), but the invoice could not be marked as published here. Run the pre-check and publish again: the same product is used, no second one is made.', 'fishotel-misc-plugin' ), $product_id ), 'db', array( 'product_id' => $product_id ) );
		}

		Invoices::log(
			'info',
			sprintf( 'Invoice #%1$d published as product #%2$d: %3$s, %4$s.', $id, $product_id, $writes['title'], Lines::dollars( $writes['total'] ) ),
			array(
				'invoice_id' => $id,
				'product_id' => $product_id,
				'user_id'    => (int) $user_id,
			)
		);

		return array(
			'ok'         => true,
			'code'       => 'published',
			'message'    => __( 'Published.', 'fishotel-misc-plugin' ),
			'id'         => $id,
			'product_id' => $product_id,
			'url'        => $product ? (string) $product['permalink'] : '',
			'title'      => $writes['title'],
			'total'      => $writes['total'],
			'warnings'   => $verify['warnings'],
			'invoice'    => $this->store->invoice( $id ),
		);
	}

	/* ============================================================== */
	/*  Edit this unpaid invoice                                       */
	/* ============================================================== */

	/**
	 * A published invoice with the form laid over it: everything may
	 * change but the handle (it is in the title, and the title is the
	 * link the customer has).
	 *
	 * @param array $invoice The stored invoice.
	 * @param array $fields  compose()'s fields.
	 * @return array
	 */
	private static function merged( array $invoice, array $fields ) {
		unset( $fields['handle'], $fields['handle_key'], $fields['state'] );
		return array_merge( $invoice, $fields );
	}

	/**
	 * Whether the form comes from a window that is behind: it names a
	 * version, and the invoice has moved on since (another window saved
	 * it). Such a window must not pre-check or save — it would lay its old
	 * lines over the newer ones on the product the customer has.
	 *
	 * @param mixed $raw     The form (+ version).
	 * @param array $invoice The stored invoice.
	 * @return bool
	 */
	private static function behind( $raw, array $invoice ) {
		return is_array( $raw ) && isset( $raw['version'] ) && is_numeric( $raw['version'] ) && (int) $raw['version'] !== (int) $invoice['version'];
	}

	/**
	 * The invoice as it stands, or the refusal to edit it.
	 *
	 * @param int $invoice_id Invoice id.
	 * @return array{0: array|null, 1: array|null, 2: array|null} The invoice, its facts, or a refusal.
	 */
	private function editable( $invoice_id ) {
		$invoice = $this->store->invoice( (int) $invoice_id );
		if ( ! $invoice ) {
			return array( null, null, self::refuse( __( 'This invoice no longer exists.', 'fishotel-misc-plugin' ), 'missing' ) );
		}

		$facts   = $this->check()->facts( $invoice );
		$invoice = Sync::reconcile( $this->store, $this->shop, $invoice, $facts['effective'] );

		if ( 'published' !== $facts['effective']['state'] || ! $facts['effective']['can_edit'] ) {
			$reason = '' !== $facts['effective']['lock_reason'] ? $facts['effective']['lock_reason'] : __( 'Only a published, unpaid invoice can be edited this way.', 'fishotel-misc-plugin' );
			return array( $invoice, $facts, self::refuse( $reason . ' ' . __( 'Nothing was changed.', 'fishotel-misc-plugin' ), 'locked' ) );
		}

		return array( $invoice, $facts, null );
	}

	/**
	 * Pre-check the changes to an unpaid, published invoice. Nothing is
	 * saved.
	 *
	 * @param int    $invoice_id Invoice id.
	 * @param mixed  $raw        The form.
	 * @param int    $user_id    Who.
	 * @param string $today      The site date (Y-m-d).
	 * @return array ok, and on success id, report, token.
	 */
	public function precheck_update( $invoice_id, $raw, $user_id, $today ) {
		list( $invoice, $facts, $refusal ) = $this->editable( $invoice_id );
		if ( $refusal ) {
			return $refusal;
		}

		$in = self::compose( $raw );
		if ( $in['errors'] ) {
			return self::refuse( implode( ' ', $in['errors'] ), 'input' );
		}
		if ( self::behind( $raw, $invoice ) ) {
			return self::store_refusal( array( 'error' => 'conflict' ) );
		}

		$merged = self::merged( $invoice, $in['fields'] );
		$report = $this->check()->report( $merged, $in['lines'], $today, 'update' );

		if ( '' !== (string) $facts['product']['sale_price'] ) {
			/* translators: %s: sale price */
			$report['warnings'][] = sprintf( __( 'The product has a sale price (%s) that this section did not set and never changes: the customer pays the sale price, not the total.', 'fishotel-misc-plugin' ), (string) $facts['product']['sale_price'] );
		}

		return array(
			'ok'      => true,
			'code'    => 'checked',
			'message' => '',
			'id'      => (int) $invoice['id'],
			'invoice' => $invoice,
			'report'  => $report,
			'token'   => self::token( $user_id, $invoice['id'], Rules::content_hash( $merged, $in['lines'] ), 'update' ),
		);
	}

	/**
	 * Save the changes to an unpaid, published invoice: the product's
	 * description, price, shipping class, image and lines, then the
	 * record here. Refused unless it is still unpaid and in no live
	 * order — checked now, and again right before the product is written.
	 *
	 * @param int    $invoice_id Invoice id.
	 * @param mixed  $raw        The form (+ version).
	 * @param mixed  $token      The token of the pre-check that was shown.
	 * @param mixed  $confirmed  The confirmed fish-on-two-invoices keys.
	 * @param int    $user_id    Who.
	 * @param string $today      The site date (Y-m-d).
	 * @return array ok, code, message; on success id, product_id, url, warnings, invoice, lines.
	 */
	public function update( $invoice_id, $raw, $token, $confirmed, $user_id, $today ) {
		return $this->locked(
			$invoice_id,
			function () use ( $invoice_id, $raw, $token, $confirmed, $user_id, $today ) {
				return $this->update_locked( $invoice_id, $raw, $token, $confirmed, $user_id, $today );
			}
		);
	}

	/**
	 * update(), holding the invoice's lock.
	 *
	 * @param int    $invoice_id Invoice id.
	 * @param mixed  $raw        The form (+ version).
	 * @param mixed  $token      The token of the pre-check that was shown.
	 * @param mixed  $confirmed  The confirmed fish-on-two-invoices keys.
	 * @param int    $user_id    Who.
	 * @param string $today      The site date (Y-m-d).
	 * @return array
	 */
	private function update_locked( $invoice_id, $raw, $token, $confirmed, $user_id, $today ) {
		list( $invoice, $facts, $refusal ) = $this->editable( $invoice_id );
		if ( $refusal ) {
			return $refusal;
		}

		$raw = is_array( $raw ) ? $raw : array();
		$id  = (int) $invoice['id'];
		$in  = self::compose( $raw );
		if ( $in['errors'] ) {
			return self::refuse( implode( ' ', $in['errors'] ), 'input' );
		}
		if ( self::behind( $raw, $invoice ) ) {
			return self::store_refusal( array( 'error' => 'conflict' ) );
		}

		$merged = self::merged( $invoice, $in['fields'] );
		$hash   = Rules::content_hash( $merged, $in['lines'] );
		if ( ! self::token_ok( $token, $user_id, $id, $hash, 'update' ) ) {
			return self::refuse( __( 'The pre-check has to be run on the changes as they are now before they can be saved: something was changed since, or the check is more than a few hours old.', 'fishotel-misc-plugin' ), 'precheck' );
		}

		$report = $this->check()->report( $merged, $in['lines'], $today, 'update' );
		$again  = array(
			'report' => $report,
			'token'  => self::token( $user_id, $id, $hash, 'update' ),
		);
		if ( $report['errors'] ) {
			return self::refuse( implode( ' ', $report['errors'] ), 'errors', $again );
		}
		if ( Rules::unconfirmed( $report['duplicates'], $confirmed ) ) {
			return self::refuse( __( 'A fish on this invoice already appears on another invoice of this customer. Confirm each one before saving.', 'fishotel-misc-plugin' ), 'confirm', $again );
		}

		// Once more, right before the product is touched: is it STILL unpaid and in no live order?
		$now = $this->check()->facts( $invoice );
		if ( 'published' !== $now['effective']['state'] || ! $now['effective']['can_edit'] ) {
			$reason = '' !== $now['effective']['lock_reason'] ? $now['effective']['lock_reason'] : __( 'This invoice can no longer be edited.', 'fishotel-misc-plugin' );
			return self::refuse( $reason . ' ' . __( 'Nothing was changed.', 'fishotel-misc-plugin' ), 'locked' );
		}

		$writes     = $report['writes'];
		$fields     = self::product_fields( $writes, $id );
		$product_id = (int) $invoice['product_id'];
		$done       = $this->shop->update_invoice_product( $product_id, $fields );
		if ( empty( $done['ok'] ) ) {
			/* translators: %s: reason */
			return self::refuse( sprintf( __( 'The product could not be changed: %s Nothing was saved.', 'fishotel-misc-plugin' ), (string) $done['error'] ), 'shop' );
		}

		$product = $this->shop->product( $product_id );
		$verify  = self::verify( $product ? $product : array(), $fields, false );
		if ( $verify['critical'] ) {
			Invoices::log(
				'error',
				sprintf( 'Invoice #%1$d: product #%2$d did not come out as written after an edit (%3$s).', $id, $product_id, implode( '; ', $verify['critical'] ) ),
				array(
					'invoice_id' => $id,
					'product_id' => $product_id,
				)
			);
			/* translators: 1: product id, 2: what is wrong */
			return self::refuse( sprintf( __( 'Product #%1$d did not come out as written: %2$s. Open the product and check it by hand; the invoice here still shows what it held before.', 'fishotel-misc-plugin' ), $product_id, implode( '; ', $verify['critical'] ) ), 'verify', array( 'product_id' => $product_id ) );
		}

		$stored                  = $in['fields'];
		$stored['shipping_slug'] = $writes['shipping_slug'];
		$stored['total']         = $writes['total'];
		unset( $stored['handle'], $stored['handle_key'] );

		$saved = $this->store->save_invoice( $id, $stored, $in['lines'], (int) $invoice['version'], (int) $user_id );
		if ( empty( $saved['ok'] ) ) {
			Invoices::log(
				'error',
				sprintf( 'Invoice #%1$d: product #%2$d was changed but the record could not be saved.', $id, $product_id ),
				array(
					'invoice_id' => $id,
					'product_id' => $product_id,
				)
			);
			return self::refuse( __( 'The product was changed, but the record here could not be saved. Run the pre-check and save again: the same changes are written once more.', 'fishotel-misc-plugin' ), 'db' );
		}

		Invoices::log(
			'info',
			sprintf( 'Invoice #%1$d (unpaid, published) was edited: now %2$s.', $id, Lines::dollars( $writes['total'] ) ),
			array(
				'invoice_id' => $id,
				'product_id' => $product_id,
				'user_id'    => (int) $user_id,
			)
		);

		return array(
			'ok'         => true,
			'code'       => 'updated',
			'message'    => __( 'The published invoice was changed.', 'fishotel-misc-plugin' ),
			'id'         => $id,
			'product_id' => $product_id,
			'url'        => $product ? (string) $product['permalink'] : '',
			'title'      => (string) $invoice['title'],
			'total'      => $writes['total'],
			'warnings'   => $verify['warnings'],
			'invoice'    => $this->store->invoice( $id ),
			'lines'      => $this->store->lines( $id ),
		);
	}

	/* ============================================================== */
	/*  The Trash                                                      */
	/* ============================================================== */

	/**
	 * Move an invoice's product to the Trash — offered, and done, only
	 * when not one order contains it. Never deleted.
	 *
	 * @param int $invoice_id Invoice id.
	 * @param int $user_id    Who.
	 * @return array ok, code, message.
	 */
	public function trash( $invoice_id, $user_id ) {
		return $this->locked(
			$invoice_id,
			function () use ( $invoice_id, $user_id ) {
				return $this->trash_locked( $invoice_id, $user_id );
			}
		);
	}

	/**
	 * trash(), holding the invoice's lock.
	 *
	 * @param int $invoice_id Invoice id.
	 * @param int $user_id    Who.
	 * @return array
	 */
	private function trash_locked( $invoice_id, $user_id ) {
		$invoice = $this->store->invoice( (int) $invoice_id );
		if ( ! $invoice ) {
			return self::refuse( __( 'This invoice no longer exists.', 'fishotel-misc-plugin' ), 'missing' );
		}

		$facts   = $this->check()->facts( $invoice );
		$invoice = Sync::reconcile( $this->store, $this->shop, $invoice, $facts['effective'] );
		$id      = (int) $invoice['id'];

		if ( ! $facts['effective']['can_trash'] ) {
			$reason = '' !== $facts['effective']['trash_reason'] ? $facts['effective']['trash_reason'] : __( 'This invoice cannot be moved to the Trash.', 'fishotel-misc-plugin' );
			return self::refuse( $reason . ' ' . __( 'Nothing was changed.', 'fishotel-misc-plugin' ), 'locked' );
		}

		$done = $this->shop->trash_product( (int) $invoice['product_id'], $id );
		if ( empty( $done['ok'] ) ) {
			return self::refuse( (string) $done['error'], 'shop' );
		}

		$saved = $this->store->save_invoice( $id, array( 'state' => 'trashed' ), null, null, (int) $user_id );
		if ( empty( $saved['ok'] ) ) {
			return self::refuse( __( 'The product is in the Trash, but the record here could not be updated. Reload the page: the list will catch up.', 'fishotel-misc-plugin' ), 'db' );
		}

		Invoices::log(
			'info',
			sprintf( 'Invoice #%1$d (%2$s) was moved to the Trash.', $id, (string) $invoice['title'] ),
			array(
				'invoice_id' => $id,
				'product_id' => (int) $invoice['product_id'],
				'user_id'    => (int) $user_id,
			)
		);

		return array(
			'ok'      => true,
			'code'    => 'trashed',
			'message' => __( 'The invoice was moved to the Trash. It can be restored from Products → Trash.', 'fishotel-misc-plugin' ),
			'id'      => $id,
		);
	}
}
