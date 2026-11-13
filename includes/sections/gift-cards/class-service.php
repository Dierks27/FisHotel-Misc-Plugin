<?php
/**
 * Gift Cards service — everything that can happen to a card, and the
 * money rules. The store, the settings, the mailer and the outside world
 * (the clock, the site's time zone) are parameters, so the tests run
 * every rule on a real SQL database in memory.
 *
 * THE MONEY RULES
 *
 *  - A card's money moves only in move_balance(): ONE UPDATE that cannot
 *    take a card below zero. Each move is written to the ledger with a
 *    UNIQUE key, and the audit log, in the SAME transaction. If anything
 *    fails, nothing is saved.
 *  - Every write holds the section's database lock, so two at once (two
 *    tabs, two checkouts, a job and a click) take their turns; the
 *    UPDATE's own guard and the unique keys stand behind it.
 *  - Paying: debit_order() takes the amount the order's "Gift card" fee
 *    says, from the customer's cards oldest first. If the cards no
 *    longer hold it (spent in another tab), nothing is taken and the
 *    checkout says so. Asked again for the same order and amount, it does
 *    nothing — an order is paid for once. Asked for another amount (the
 *    customer came back and changed the order), the old amount goes back
 *    first.
 *  - Putting back: restore_order() puts back what is still out on an
 *    order — after a failed, cancelled, refunded or trashed order. Its key
 *    is restore:<order>:<card>:<use>, so it happens EXACTLY ONCE however
 *    often the hooks fire. restore_part() puts back part of it (a partial
 *    refund), never more than is still out.
 *  - Nothing expires.
 *
 * Results are arrays: ok, message, code (a word for the tests), and what
 * the operation made.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * A refusal inside an atomic block: rolls everything back.
 */
class Refusal extends \RuntimeException {

	/**
	 * A word for the tests.
	 *
	 * @var string
	 */
	public $word;

	/**
	 * Build.
	 *
	 * @param string $word    A word.
	 * @param string $message Plain words.
	 */
	public function __construct( $word, $message ) {
		parent::__construct( (string) $message );
		$this->word = (string) $word;
	}
}

/**
 * Class Service
 */
class Service {

	/**
	 * The section's write lock.
	 *
	 * @var string
	 */
	const LOCK = 'gift_cards';

	const MAX_NAME    = 120;
	const MAX_MESSAGE = 500;
	const MIN_REASON  = 3;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	public $store;

	/**
	 * Settings.
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * Mailer.
	 *
	 * @var Mailer|null
	 */
	private $mailer;

	/**
	 * now(): UNIX time; tz(): \DateTimeZone; code(): a new code (tests);
	 * log( level, message ); before_move( card_id, delta ) (tests).
	 *
	 * @var array<string, callable>
	 */
	private $world;

	/**
	 * The audit key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Whether a transaction of ours is open.
	 *
	 * @var bool
	 */
	private $in_atomic = false;

	/**
	 * Build.
	 *
	 * @param Store       $store    Store.
	 * @param array       $settings Settings::get().
	 * @param Mailer|null $mailer   Mailer.
	 * @param array       $world    See $world.
	 * @param string|null $key      Audit key (Audit::key()).
	 */
	public function __construct( Store $store, array $settings, $mailer = null, array $world = array(), $key = null ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->mailer   = $mailer;
		$this->world    = $world;
		$this->key      = null === $key ? Audit::key() : (string) $key;
	}

	/* ============================================================== */
	/*  The outside world                                              */
	/* ============================================================== */

	/**
	 * Now (UNIX time).
	 *
	 * @return int
	 */
	public function now() {
		return isset( $this->world['now'] ) ? (int) call_user_func( $this->world['now'] ) : time();
	}

	/**
	 * The site's time zone.
	 *
	 * @return \DateTimeZone
	 */
	public function tz() {
		if ( isset( $this->world['tz'] ) ) {
			return call_user_func( $this->world['tz'] );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}

	/**
	 * Now as the database keeps it.
	 *
	 * @return string
	 */
	private function stamp() {
		return gmdate( 'Y-m-d H:i:s', $this->now() );
	}

	/**
	 * Tell the Health log.
	 *
	 * @param string $level   error|warning|info.
	 * @param string $message Plain words.
	 */
	private function log( $level, $message ) {
		if ( isset( $this->world['log'] ) ) {
			call_user_func( $this->world['log'], $level, $message );
			return;
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'fishotel_log', (string) $level, 'gift-cards', (string) $message, array() );
		}
	}

	/* ============================================================== */
	/*  Results and the atomic block                                   */
	/* ============================================================== */

	/**
	 * A success.
	 *
	 * @param string $message Plain words.
	 * @param array  $extra   More.
	 * @return array
	 */
	public static function ok( $message, array $extra = array() ) {
		return array_merge(
			array(
				'ok'      => true,
				'code'    => 'ok',
				'message' => (string) $message,
			),
			$extra
		);
	}

	/**
	 * A refusal.
	 *
	 * @param string $code    A word.
	 * @param string $message Plain words.
	 * @return array
	 */
	public static function fail( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}

	/**
	 * Run $work holding the lock, in one transaction: committed when it
	 * returns ok, rolled back when it refuses, throws or fails.
	 *
	 * @param callable $work fn(): array (a result).
	 * @return array
	 */
	public function atomic( callable $work ) {
		if ( $this->in_atomic ) {
			return $work();
		}
		if ( ! Store::ready() ) {
			return self::fail( 'not_installed', __( 'The gift card tables are not installed yet. Open FisHotel Tools → Gift Cards once as an administrator.', 'fishotel-misc-plugin' ) );
		}
		if ( ! $this->store->lock( self::LOCK, 10 ) ) {
			return self::fail( 'busy', __( 'Another gift card change is being saved right now. Please try again in a moment.', 'fishotel-misc-plugin' ) );
		}

		$this->in_atomic = true;
		$this->store->begin();
		try {
			$result = $work();
			if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
				$this->store->rollback();
			} elseif ( ! $this->store->commit() ) {
				$this->store->rollback();
				$result = self::fail( 'db', __( 'The database did not save the change, so nothing was changed.', 'fishotel-misc-plugin' ) );
			}
		} catch ( Refusal $r ) {
			$this->store->rollback();
			$result = self::fail( $r->word, $r->getMessage() );
		} catch ( \Throwable $e ) {
			$this->store->rollback();
			$this->log( 'error', 'A gift card change failed and was rolled back: ' . $e->getMessage() );
			$result = self::fail( 'error', __( 'Something went wrong and nothing was changed.', 'fishotel-misc-plugin' ) );
		}
		$this->in_atomic = false;
		$this->store->unlock( self::LOCK );

		return $result;
	}

	/**
	 * Write an audit row (inside atomic()); a failure refuses the change.
	 *
	 * @param array    $ctx     Audit::context().
	 * @param int      $card_id Card id.
	 * @param string   $action  Action.
	 * @param string   $field   Field.
	 * @param mixed    $old     Old value.
	 * @param mixed    $new     New value.
	 * @param int|null $delta   Cents.
	 * @param string   $reason  Why.
	 */
	private function audit( array $ctx, $card_id, $action, $field = '', $old = null, $new = null, $delta = null, $reason = '' ) {
		$row = Audit::build( $ctx, $card_id, $action, $field, $old, $new, $delta, $reason, $this->now(), $this->tz() );
		if ( ! Audit::append( $this->store, $row, $this->key ) ) {
			throw new Refusal( 'audit', __( 'The audit log could not be written, so nothing was changed.', 'fishotel-misc-plugin' ) );
		}
	}

	/**
	 * Record something that is not a change to a card (a mode, a setting,
	 * an export) in the audit log.
	 *
	 * @param array    $ctx    Audit::context().
	 * @param string   $action Action.
	 * @param string   $field  Field.
	 * @param mixed    $old    Old.
	 * @param mixed    $new    New.
	 * @param string   $reason Why.
	 * @param callable $also   fn(): bool — the change itself, done inside the same transaction.
	 * @return array
	 */
	public function record( array $ctx, $action, $field, $old, $new, $reason = '', $also = null ) {
		return $this->atomic(
			function () use ( $ctx, $action, $field, $old, $new, $reason, $also ) {
				if ( is_callable( $also ) && false === call_user_func( $also ) ) {
					throw new Refusal( 'not_saved', __( 'That could not be saved.', 'fishotel-misc-plugin' ) );
				}
				$this->audit( $ctx, 0, $action, $field, $old, $new, null, $reason );
				return self::ok( __( 'Saved.', 'fishotel-misc-plugin' ) );
			}
		);
	}

	/**
	 * Move money and write its ledger row (inside atomic()).
	 *
	 * @param array $card        Card row.
	 * @param int   $delta       Cents.
	 * @param array $ledger      type, key, order_id, refund_id, user_id, note.
	 * @param bool  $active_only Refuse a disabled card.
	 * @return int The balance after.
	 */
	private function move( array $card, $delta, array $ledger, $active_only ) {
		if ( isset( $this->world['before_move'] ) ) {
			call_user_func( $this->world['before_move'], (int) $card['id'], (int) $delta );
		}
		$id = (int) $card['id'];
		if ( 0 !== (int) $delta && ! $this->store->move_balance( $id, (int) $delta, $this->now(), $active_only, 'used' === $ledger['type'] ) ) {
			throw new Refusal( 'short', __( 'The card does not have that much on it (it may have just been used elsewhere), so nothing was changed.', 'fishotel-misc-plugin' ) );
		}
		$after = (int) $this->store->balance( $id );
		$row   = array(
			'card_id'             => $id,
			'type'                => (string) $ledger['type'],
			'amount_cents'        => (int) $delta,
			'balance_after_cents' => $after,
			'user_id'             => (int) ( $ledger['user_id'] ?? 0 ),
			'idempotency_key'     => (string) $ledger['key'],
			'note'                => substr( (string) ( $ledger['note'] ?? '' ), 0, 255 ),
			'created_at'          => $this->stamp(),
		);
		foreach ( array( 'order_id', 'refund_id' ) as $k ) {
			if ( ! empty( $ledger[ $k ] ) ) {
				$row[ $k ] = (int) $ledger[ $k ];
			}
		}
		if ( ! $this->store->insert_ledger( $row ) ) {
			throw new Refusal( 'duplicate', __( 'That money movement was already saved, so it was not saved twice.', 'fishotel-misc-plugin' ) );
		}
		return $after;
	}

	/* ============================================================== */
	/*  Checking what was typed                                        */
	/* ============================================================== */

	/**
	 * A cleaned name.
	 *
	 * @param mixed $value Typed.
	 * @return string
	 */
	private static function name( $value ) {
		return substr( trim( preg_replace( '/\s+/', ' ', strip_tags( is_scalar( $value ) ? (string) $value : '' ) ) ), 0, self::MAX_NAME );
	}

	/**
	 * A cleaned e-mail address, '' when empty, null when not an address.
	 *
	 * @param mixed $value Typed.
	 * @return string|null
	 */
	private static function email( $value ) {
		$email = strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) );
		if ( '' === $email ) {
			return '';
		}
		return is_email( $email ) && strlen( $email ) <= 190 ? $email : null;
	}

	/**
	 * A cleaned message.
	 *
	 * @param mixed $value Typed.
	 * @return string
	 */
	private static function message( $value ) {
		$text = trim( strip_tags( str_replace( "\r", '', is_scalar( $value ) ? (string) $value : '' ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_MESSAGE ) : substr( $text, 0, self::MAX_MESSAGE );
	}

	/**
	 * A reason, or null when it is too short.
	 *
	 * @param mixed $value Typed.
	 * @return string|null
	 */
	private static function reason( $value ) {
		$text = trim( preg_replace( '/\s+/', ' ', strip_tags( is_scalar( $value ) ? (string) $value : '' ) ) );
		return strlen( $text ) >= self::MIN_REASON ? substr( $text, 0, 500 ) : null;
	}

	/**
	 * A one-time form key (what makes a double click save once).
	 *
	 * @param mixed $value Posted.
	 * @return string
	 */
	public static function request_key( $value ) {
		$key = is_scalar( $value ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value ) ) : '';
		return strlen( $key ) >= 12 ? substr( $key, 0, 40 ) : '';
	}

	/**
	 * A new form key.
	 *
	 * @return string
	 */
	public static function new_request_key() {
		return bin2hex( random_bytes( 12 ) );
	}

	/**
	 * When an e-mail sent "on a date" goes out: 9:00 that morning, site time.
	 *
	 * @param string $date Y-m-d.
	 * @return int|null UNIX time, null when not a date.
	 */
	public function delivery_time( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' 09:00', $this->tz() );
		return $d && $d->format( 'Y-m-d' ) === $date ? $d->getTimestamp() : null;
	}

	/**
	 * Today's date at the site (Y-m-d).
	 *
	 * @return string
	 */
	public function today() {
		return ( new \DateTimeImmutable( '@' . $this->now() ) )->setTimezone( $this->tz() )->format( 'Y-m-d' );
	}

	/* ============================================================== */
	/*  Issuing                                                        */
	/* ============================================================== */

	/**
	 * Issue a new card (from the admin, or for a paid order line).
	 *
	 * @param array $in  amount (typed) or amount_cents; recipient_name, recipient_email,
	 *                   sender_name, sender_email, message; delivery now|date|none and
	 *                   deliver_on (Y-m-d); reason; request_key (admin) — or, for an order:
	 *                   order_id, order_item_id, order_item_unit, product_id, buyer_user_id.
	 * @param array $ctx Audit::context().
	 * @return array ok, card_id, and sent (null = no e-mail now).
	 */
	public function issue( array $in, array $ctx ) {
		$amount = isset( $in['amount_cents'] ) ? (int) $in['amount_cents'] : Money::to_cents( $in['amount'] ?? '' );
		if ( null === $amount || $amount < 100 || $amount > Money::MAX_CENTS ) {
			/* translators: %s: the largest amount */
			return self::fail( 'amount', sprintf( __( 'Enter an amount between $1.00 and %s.', 'fishotel-misc-plugin' ), Money::words( Money::MAX_CENTS ) ) );
		}
		$recipient = self::email( $in['recipient_email'] ?? '' );
		$sender    = self::email( $in['sender_email'] ?? '' );
		if ( null === $recipient || null === $sender ) {
			return self::fail( 'email', __( 'That e-mail address does not look right.', 'fishotel-misc-plugin' ) );
		}

		$delivery = isset( $in['delivery'] ) && in_array( $in['delivery'], array( 'now', 'date', 'none' ), true ) ? $in['delivery'] : 'now';
		$at       = null;
		if ( 'date' === $delivery ) {
			$at = $this->delivery_time( (string) ( $in['deliver_on'] ?? '' ) );
			if ( null === $at || (string) $in['deliver_on'] < $this->today() ) {
				return self::fail( 'date', __( 'Pick a delivery date from today on.', 'fishotel-misc-plugin' ) );
			}
			if ( (string) $in['deliver_on'] === $this->today() ) {
				$delivery = 'now';
				$at       = null;
			}
		}
		if ( 'none' !== $delivery && '' === $recipient && '' === $sender ) {
			return self::fail( 'email', __( 'To send the e-mail, enter the recipient\'s e-mail address (or choose "Do not send").', 'fishotel-misc-plugin' ) );
		}

		$order   = ! empty( $in['order_item_id'] );
		$req_key = $order ? 'item:' . (int) $in['order_item_id'] . ':' . max( 1, (int) ( $in['order_item_unit'] ?? 1 ) ) : self::request_key( $in['request_key'] ?? '' );
		if ( '' === $req_key ) {
			return self::fail( 'form', __( 'The form was not complete. Please reload the page and try again.', 'fishotel-misc-plugin' ) );
		}

		$result = $this->atomic(
			function () use ( $in, $ctx, $amount, $recipient, $sender, $delivery, $at, $order, $req_key ) {
				$ledger_key = 'issue:' . $req_key;
				if ( $this->store->ledger_has( $ledger_key ) ) {
					$existing = $this->store->ledger_by_key( $ledger_key );
					return self::ok(
						__( 'That card was already issued.', 'fishotel-misc-plugin' ),
						array(
							'code'    => 'already',
							'card_id' => $existing ? (int) $existing['card_id'] : 0,
							'send'    => false,
						)
					);
				}

				$code = '';
				for ( $i = 0; $i < 6 && '' === $code; $i++ ) {
					$try = isset( $this->world['code'] ) ? (string) call_user_func( $this->world['code'] ) : Codes::generate();
					if ( ! $this->store->card_by_key( Codes::key( $try ) ) ) {
						$code = $try;
					}
				}
				if ( '' === $code ) {
					throw new Refusal( 'code', __( 'No new code could be made. Please try again.', 'fishotel-misc-plugin' ) );
				}

				$now = $this->stamp();
				$row = array(
					'code'            => $code,
					'code_key'        => Codes::key( $code ),
					'status'          => 'active',
					'amount_cents'    => $amount,
					'balance_cents'   => 0,
					'owner_user_id'   => 0,
					'recipient_name'  => self::name( $in['recipient_name'] ?? '' ),
					'recipient_email' => $recipient,
					'sender_name'     => self::name( $in['sender_name'] ?? '' ),
					'sender_email'    => $sender,
					'message'         => self::message( $in['message'] ?? '' ),
					'email_state'     => 'none' === $delivery ? 'none' : 'pending',
					'buyer_user_id'   => (int) ( $in['buyer_user_id'] ?? 0 ),
					'source'          => $order ? 'order' : 'admin',
					'created_by'      => (int) ( $ctx['user_id'] ?? 0 ),
					'created_at'      => $now,
					'updated_at'      => $now,
				);
				if ( null !== $at ) {
					$row['deliver_at'] = gmdate( 'Y-m-d H:i:s', $at );
				}
				if ( $order ) {
					$row['order_id']        = (int) ( $in['order_id'] ?? 0 );
					$row['order_item_id']   = (int) $in['order_item_id'];
					$row['order_item_unit'] = max( 1, (int) ( $in['order_item_unit'] ?? 1 ) );
					$row['product_id']      = (int) ( $in['product_id'] ?? 0 );
				}
				$id = $this->store->insert_card( $row );
				if ( ! $id ) {
					throw new Refusal( 'db', __( 'The card could not be saved.', 'fishotel-misc-plugin' ) );
				}
				$card = $this->store->card( $id );
				$this->move(
					$card,
					$amount,
					array(
						'type'     => 'issued',
						'key'      => $ledger_key,
						'order_id' => $order ? (int) ( $in['order_id'] ?? 0 ) : 0,
						'user_id'  => (int) ( $ctx['user_id'] ?? 0 ),
					),
					false
				);
				$this->audit(
					$ctx,
					$id,
					'issue',
					'amount',
					null,
					$amount,
					$amount,
					(string) ( $in['reason'] ?? '' )
				);
				$this->audit(
					$ctx,
					$id,
					'details',
					'delivery',
					null,
					'none' === $delivery ? 'do not send' : ( null === $at ? 'send now' : 'send on ' . gmdate( 'Y-m-d H:i', $at ) . ' UTC' ) . ( '' !== $row['recipient_email'] ? ' to ' . $row['recipient_email'] : '' )
				);
				return self::ok(
					/* translators: %s: masked code */
					sprintf( __( 'Gift card %s issued.', 'fishotel-misc-plugin' ), Codes::mask( $code ) ),
					array(
						'card_id' => (int) $id,
						'send'    => 'now' === $delivery,
					)
				);
			}
		);

		$result['sent'] = null;
		if ( $result['ok'] && ! empty( $result['send'] ) && ! empty( $result['card_id'] ) ) {
			$result['sent'] = $this->deliver( (int) $result['card_id'], $ctx );
		}
		return $result;
	}

	/**
	 * Issue the cards of a paid order: one card per unit of every gift
	 * card line, each exactly once (the order line + unit is UNIQUE).
	 *
	 * @param int     $order_id Order id.
	 * @param array[] $lines    item_id, qty, amount_cents, product_id, recipient_name,
	 *                          recipient_email, sender_name, sender_email, message, deliver_on.
	 * @param int     $buyer    Buyer's user id.
	 * @param array   $ctx      Audit::context().
	 * @return array{issued: int, already: int, failed: int, card_ids: int[]}
	 */
	public function issue_for_order( $order_id, array $lines, $buyer, array $ctx ) {
		$out = array(
			'issued'   => 0,
			'already'  => 0,
			'failed'   => 0,
			'card_ids' => array(),
		);
		foreach ( $lines as $line ) {
			$qty = max( 1, min( 100, (int) ( $line['qty'] ?? 1 ) ) );
			for ( $unit = 1; $unit <= $qty; $unit++ ) {
				if ( $this->store->card_for_item( (int) $line['item_id'], $unit ) ) {
					$out['already']++;
					continue;
				}
				$on     = (string) ( $line['deliver_on'] ?? '' );
				$result = $this->issue(
					array(
						'amount_cents'    => (int) $line['amount_cents'],
						'recipient_name'  => $line['recipient_name'] ?? '',
						'recipient_email' => $line['recipient_email'] ?? '',
						'sender_name'     => $line['sender_name'] ?? '',
						'sender_email'    => $line['sender_email'] ?? '',
						'message'         => $line['message'] ?? '',
						'delivery'        => '' !== $on && $on > $this->today() ? 'date' : 'now',
						'deliver_on'      => $on,
						'order_id'        => (int) $order_id,
						'order_item_id'   => (int) $line['item_id'],
						'order_item_unit' => $unit,
						'product_id'      => (int) ( $line['product_id'] ?? 0 ),
						'buyer_user_id'   => (int) $buyer,
					),
					$ctx
				);
				if ( $result['ok'] && 'already' !== $result['code'] ) {
					$out['issued']++;
					$out['card_ids'][] = (int) $result['card_id'];
				} elseif ( $result['ok'] ) {
					$out['already']++;
				} else {
					$out['failed']++;
					/* translators: 1: order id, 2: reason */
					$this->log( 'error', sprintf( 'A gift card for order #%1$d could not be issued: %2$s', (int) $order_id, $result['message'] ) );
				}
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Changing a card by hand                                        */
	/* ============================================================== */

	/**
	 * Add money to a card or take it off, with a reason.
	 *
	 * @param int    $card_id     Card id.
	 * @param string $direction   credit|debit.
	 * @param mixed  $amount      Typed amount.
	 * @param mixed  $reason      Why (required).
	 * @param string $request_key The form's one-time key.
	 * @param array  $ctx         Audit::context().
	 * @return array
	 */
	public function adjust( $card_id, $direction, $amount, $reason, $request_key, array $ctx ) {
		$cents  = Money::to_cents( $amount );
		$reason = self::reason( $reason );
		$key    = self::request_key( $request_key );
		if ( null === $cents || $cents <= 0 ) {
			return self::fail( 'amount', __( 'Enter the amount to add or take off, like 10.00.', 'fishotel-misc-plugin' ) );
		}
		if ( null === $reason ) {
			return self::fail( 'reason', __( 'Say why the balance changes (a few words) — it goes in the audit log.', 'fishotel-misc-plugin' ) );
		}
		if ( ! in_array( $direction, array( 'credit', 'debit' ), true ) || '' === $key ) {
			return self::fail( 'form', __( 'The form was not complete. Please reload the page and try again.', 'fishotel-misc-plugin' ) );
		}
		$delta = 'credit' === $direction ? $cents : -$cents;

		return $this->atomic(
			function () use ( $card_id, $delta, $reason, $key, $ctx ) {
				$card = $this->store->card( (int) $card_id );
				if ( ! $card ) {
					throw new Refusal( 'not_found', __( 'That card was not found.', 'fishotel-misc-plugin' ) );
				}
				if ( $this->store->ledger_has( 'adjust:' . $key ) ) {
					return self::ok( __( 'That change was already saved.', 'fishotel-misc-plugin' ), array( 'code' => 'already' ) );
				}
				$before = (int) $card['balance_cents'];
				if ( $before + $delta < 0 ) {
					/* translators: %s: balance */
					throw new Refusal( 'short', sprintf( __( 'The card has only %s on it; the balance cannot go below zero.', 'fishotel-misc-plugin' ), Money::words( $before ) ) );
				}
				if ( $before + $delta > Money::MAX_CENTS * 4 ) {
					throw new Refusal( 'amount', __( 'That would put more on one card than this section allows.', 'fishotel-misc-plugin' ) );
				}
				$after = $this->move(
					$card,
					$delta,
					array(
						'type'    => $delta > 0 ? 'credit' : 'debit',
						'key'     => 'adjust:' . $key,
						'user_id' => (int) ( $ctx['user_id'] ?? 0 ),
						'note'    => $reason,
					),
					false
				);
				$this->audit( $ctx, (int) $card['id'], 'balance', 'balance', $before, $after, $delta, $reason );
				/* translators: %s: new balance */
				return self::ok( sprintf( __( 'The balance is now %s.', 'fishotel-misc-plugin' ), Money::words( $after ) ), array( 'balance' => $after ) );
			}
		);
	}

	/**
	 * Change who a card is for, who it is from, or its message.
	 *
	 * @param int   $card_id Card id.
	 * @param array $in      recipient_name, recipient_email, sender_name, sender_email, message.
	 * @param mixed $reason  Why (optional).
	 * @param array $ctx     Audit::context().
	 * @return array
	 */
	public function update_details( $card_id, array $in, $reason, array $ctx ) {
		$new = array();
		foreach ( array( 'recipient_name', 'sender_name' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$new[ $k ] = self::name( $in[ $k ] );
			}
		}
		foreach ( array( 'recipient_email', 'sender_email' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$email = self::email( $in[ $k ] );
				if ( null === $email ) {
					return self::fail( 'email', __( 'That e-mail address does not look right.', 'fishotel-misc-plugin' ) );
				}
				$new[ $k ] = $email;
			}
		}
		if ( array_key_exists( 'message', $in ) ) {
			$new['message'] = self::message( $in['message'] );
		}
		$reason = is_scalar( $reason ) ? trim( strip_tags( (string) $reason ) ) : '';

		return $this->atomic(
			function () use ( $card_id, $new, $reason, $ctx ) {
				$card = $this->store->card( (int) $card_id );
				if ( ! $card ) {
					throw new Refusal( 'not_found', __( 'That card was not found.', 'fishotel-misc-plugin' ) );
				}
				$changed = array();
				foreach ( $new as $k => $v ) {
					if ( (string) $card[ $k ] !== (string) $v ) {
						$changed[ $k ] = $v;
					}
				}
				if ( ! $changed ) {
					return self::ok( __( 'Nothing was different, so nothing changed.', 'fishotel-misc-plugin' ), array( 'code' => 'same' ) );
				}
				if ( ! $this->store->change_card( (int) $card['id'], $changed, array(), $this->now() ) ) {
					throw new Refusal( 'db', __( 'The card could not be saved.', 'fishotel-misc-plugin' ) );
				}
				foreach ( $changed as $k => $v ) {
					$this->audit( $ctx, (int) $card['id'], 'details', $k, (string) $card[ $k ], $v, null, $reason );
				}
				return self::ok( __( 'Saved.', 'fishotel-misc-plugin' ) );
			}
		);
	}

	/**
	 * Switch a card on or off, with a reason. A disabled card keeps its
	 * money but cannot be used or added to an account.
	 *
	 * @param int    $card_id Card id.
	 * @param string $status  active|disabled.
	 * @param mixed  $reason  Why (required).
	 * @param array  $ctx     Audit::context().
	 * @return array
	 */
	public function set_status( $card_id, $status, $reason, array $ctx ) {
		$reason = self::reason( $reason );
		if ( ! in_array( $status, array( 'active', 'disabled' ), true ) ) {
			return self::fail( 'form', __( 'The form was not complete. Please reload the page and try again.', 'fishotel-misc-plugin' ) );
		}
		if ( null === $reason ) {
			return self::fail( 'reason', __( 'Say why (a few words) — it goes in the audit log.', 'fishotel-misc-plugin' ) );
		}
		return $this->atomic(
			function () use ( $card_id, $status, $reason, $ctx ) {
				$card = $this->store->card( (int) $card_id );
				if ( ! $card ) {
					throw new Refusal( 'not_found', __( 'That card was not found.', 'fishotel-misc-plugin' ) );
				}
				if ( $card['status'] === $status ) {
					return self::ok( __( 'Nothing was different, so nothing changed.', 'fishotel-misc-plugin' ), array( 'code' => 'same' ) );
				}
				$this->store->change_card( (int) $card['id'], array( 'status' => $status ), array(), $this->now() );
				$this->audit( $ctx, (int) $card['id'], 'status', 'status', $card['status'], $status, null, $reason );
				return self::ok( 'active' === $status ? __( 'The card works again.', 'fishotel-misc-plugin' ) : __( 'The card is disabled: it keeps its money but cannot be used.', 'fishotel-misc-plugin' ) );
			}
		);
	}

	/**
	 * Show the full code to the owner — and log that it was shown.
	 *
	 * @param int   $card_id Card id.
	 * @param array $ctx     Audit::context().
	 * @return array ok, code_text.
	 */
	public function reveal( $card_id, array $ctx ) {
		return $this->atomic(
			function () use ( $card_id, $ctx ) {
				$card = $this->store->card( (int) $card_id );
				if ( ! $card ) {
					throw new Refusal( 'not_found', __( 'That card was not found.', 'fishotel-misc-plugin' ) );
				}
				$this->audit( $ctx, (int) $card['id'], 'code_viewed' );
				return self::ok( '', array( 'code_text' => (string) $card['code'] ) );
			}
		);
	}

	/* ============================================================== */
	/*  E-mail                                                         */
	/* ============================================================== */

	/**
	 * Send a card's e-mail when it is waiting (claimed first, so two runs
	 * never send it twice), or again on request ($again).
	 *
	 * @param int    $card_id Card id.
	 * @param array  $ctx     Audit::context().
	 * @param bool   $again   Send again (the Resend button).
	 * @param string $to      Another address (resend only).
	 * @return bool|null Whether it went (null = nothing to send).
	 */
	public function deliver( $card_id, array $ctx, $again = false, $to = '' ) {
		$card = $this->store->card( (int) $card_id );
		if ( ! $card || ! $this->mailer || 'active' !== $card['status'] ) {
			return null;
		}
		$to = '' !== (string) $to ? (string) $to : Mailer::address( $card );
		if ( '' === $to ) {
			return null;
		}
		if ( ! $again ) {
			$from = in_array( $card['email_state'], array( 'pending', 'sending' ), true ) ? $card['email_state'] : '';
			if ( '' === $from || ! $this->store->change_card( (int) $card['id'], array( 'email_state' => 'sending' ), array( 'email_state' => $from ), $this->now() ) ) {
				return null;
			}
		}
		$sent = $this->mailer->send( $card, $to );
		$this->atomic(
			function () use ( $card, $ctx, $again, $to, $sent ) {
				$fields = array( 'email_state' => $sent ? 'sent' : ( $again ? $card['email_state'] : 'failed' ) );
				if ( $sent ) {
					$fields['delivered_at'] = $this->stamp();
				}
				$this->store->change_card( (int) $card['id'], $fields, array(), $this->now() );
				$this->audit( $ctx, (int) $card['id'], $sent ? ( $again ? 'email_resent' : 'email_sent' ) : 'email_failed', 'email', null, $to );
				return self::ok( '' );
			}
		);
		return $sent;
	}

	/**
	 * Send a card's e-mail again.
	 *
	 * @param int   $card_id Card id.
	 * @param mixed $to      Another address ('' = the recipient).
	 * @param array $ctx     Audit::context().
	 * @return array
	 */
	public function resend( $card_id, $to, array $ctx ) {
		$email = self::email( $to );
		if ( null === $email ) {
			return self::fail( 'email', __( 'That e-mail address does not look right.', 'fishotel-misc-plugin' ) );
		}
		$card = $this->store->card( (int) $card_id );
		if ( ! $card ) {
			return self::fail( 'not_found', __( 'That card was not found.', 'fishotel-misc-plugin' ) );
		}
		if ( 'active' !== $card['status'] ) {
			return self::fail( 'disabled', __( 'A disabled card\'s e-mail is not sent. Switch the card on first.', 'fishotel-misc-plugin' ) );
		}
		if ( '' === $email && '' === Mailer::address( $card ) ) {
			return self::fail( 'email', __( 'The card has no e-mail address. Enter one to send it to.', 'fishotel-misc-plugin' ) );
		}
		$sent = $this->deliver( (int) $card_id, $ctx, true, $email );
		return $sent ? self::ok( __( 'The e-mail was sent.', 'fishotel-misc-plugin' ) ) : self::fail( 'mail', __( 'The e-mail could not be sent. Check the site\'s e-mail settings.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * The cron job: send the e-mails whose day has come.
	 *
	 * @param array $ctx Audit::context().
	 * @return array{sent: int, failed: int}
	 */
	public function deliver_due( array $ctx ) {
		$out = array(
			'sent'   => 0,
			'failed' => 0,
		);
		foreach ( $this->store->due_deliveries( $this->now() ) as $card ) {
			$r = $this->deliver( (int) $card['id'], $ctx );
			if ( true === $r ) {
				$out['sent']++;
			} elseif ( false === $r ) {
				$out['failed']++;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  The customer's wallet                                          */
	/* ============================================================== */

	/**
	 * A customer's wallet: the usable cards in their account (oldest added
	 * first) and what they hold together.
	 *
	 * @param int $user_id User id.
	 * @return array{balance: int, cards: array[], all: array[]}
	 */
	public function wallet( $user_id ) {
		$all    = $this->store->cards_of_owner( (int) $user_id );
		$usable = array_values(
			array_filter(
				$all,
				static function ( $c ) {
					return 'active' === $c['status'] && (int) $c['balance_cents'] > 0;
				}
			)
		);
		return array(
			'balance' => array_sum( array_map( 'intval', array_column( $usable, 'balance_cents' ) ) ),
			'cards'   => $usable,
			'all'     => $all,
		);
	}

	/**
	 * The cards a checkout may draw on: the customer's own, then cards
	 * typed at this checkout (a guest's), if still nobody's or theirs.
	 *
	 * @param int   $user_id  User id (0 = guest).
	 * @param int[] $extra    Card ids typed at checkout.
	 * @return array[]
	 */
	public function sources( $user_id, array $extra = array() ) {
		$cards = (int) $user_id > 0 ? $this->wallet( (int) $user_id )['cards'] : array();
		$have  = array_map( 'intval', array_column( $cards, 'id' ) );
		foreach ( $this->store->cards_by_ids( $extra ) as $id => $card ) {
			if ( in_array( (int) $id, $have, true ) || 'active' !== $card['status'] || (int) $card['balance_cents'] <= 0 ) {
				continue;
			}
			if ( 0 !== (int) $card['owner_user_id'] && (int) $card['owner_user_id'] !== (int) $user_id ) {
				continue; // Somebody else's card: never.
			}
			$cards[] = $card;
		}
		return $cards;
	}

	/**
	 * Wrong codes so far this hour for this person (rate limit).
	 *
	 * @param string $who The user id or IP.
	 * @return bool Whether they may try again.
	 */
	public function may_try( $who ) {
		$n = function_exists( 'get_transient' ) ? (int) get_transient( 'fh_gcard_tries_' . md5( (string) $who ) ) : 0;
		return $n < (int) $this->settings['redeem_tries'];
	}

	/**
	 * Count a wrong code.
	 *
	 * @param string $who The user id or IP.
	 */
	public function wrong_try( $who ) {
		if ( function_exists( 'get_transient' ) ) {
			$key = 'fh_gcard_tries_' . md5( (string) $who );
			set_transient( $key, (int) get_transient( $key ) + 1, 3600 );
		}
	}

	/**
	 * Find a usable card by code, for a guest's checkout (not added to any
	 * account).
	 *
	 * @param string $code Typed code.
	 * @param int    $user_id User id (0 = guest).
	 * @return array ok, card_id.
	 */
	public function find_for_checkout( $code, $user_id ) {
		$card = $this->store->card_by_key( Codes::key( $code ) );
		if ( ! $card || 'active' !== $card['status'] || ( 0 !== (int) $card['owner_user_id'] && (int) $card['owner_user_id'] !== (int) $user_id ) ) {
			return self::fail( 'not_found', __( 'That code cannot be used. Check it and try again, or contact us.', 'fishotel-misc-plugin' ) );
		}
		if ( (int) $card['balance_cents'] <= 0 ) {
			return self::fail( 'empty', __( 'This gift card has nothing left on it.', 'fishotel-misc-plugin' ) );
		}
		return self::ok( '', array( 'card_id' => (int) $card['id'] ) );
	}

	/**
	 * Add a card to a customer's account by its code. The same answer for
	 * a code that does not exist and one in somebody else's account, so
	 * the form cannot be used to find out which codes exist.
	 *
	 * @param mixed $code    Typed code.
	 * @param int   $user_id User id.
	 * @param array $ctx     Audit::context().
	 * @return array
	 */
	public function redeem( $code, $user_id, array $ctx ) {
		$key = Codes::key( $code );
		if ( (int) $user_id <= 0 ) {
			return self::fail( 'login', __( 'Log in to add a gift card to your account.', 'fishotel-misc-plugin' ) );
		}
		$nope = __( 'That code cannot be added to your account. Check it and try again, or contact us.', 'fishotel-misc-plugin' );
		if ( '' === $key ) {
			return self::fail( 'not_found', $nope );
		}
		return $this->atomic(
			function () use ( $key, $user_id, $ctx, $nope ) {
				$card = $this->store->card_by_key( $key );
				if ( ! $card ) {
					throw new Refusal( 'not_found', $nope );
				}
				return $this->claim_card( $card, (int) $user_id, $ctx, $nope );
			}
		);
	}

	/**
	 * Add an unclaimed card sent to the customer's own e-mail address to
	 * their account (the "Add to my account" button — no code typed).
	 *
	 * @param int    $card_id Card id.
	 * @param int    $user_id User id.
	 * @param string $email   The user's e-mail address.
	 * @param array  $ctx     Audit::context().
	 * @return array
	 */
	public function claim( $card_id, $user_id, $email, array $ctx ) {
		$nope = __( 'That card cannot be added to your account.', 'fishotel-misc-plugin' );
		return $this->atomic(
			function () use ( $card_id, $user_id, $email, $ctx, $nope ) {
				$card = $this->store->card( (int) $card_id );
				if ( ! $card || (int) $user_id <= 0 || '' === trim( (string) $email ) || strtolower( trim( (string) $card['recipient_email'] ) ) !== strtolower( trim( (string) $email ) ) ) {
					throw new Refusal( 'not_yours', $nope );
				}
				return $this->claim_card( $card, (int) $user_id, $ctx, $nope );
			}
		);
	}

	/**
	 * Put a card into an account (inside atomic()).
	 *
	 * @param array  $card    Card row.
	 * @param int    $user_id User id.
	 * @param array  $ctx     Audit::context().
	 * @param string $nope    The refusal words.
	 * @return array
	 */
	private function claim_card( array $card, $user_id, array $ctx, $nope ) {
		if ( (int) $card['owner_user_id'] === $user_id ) {
			return self::ok( __( 'That gift card is already in your account.', 'fishotel-misc-plugin' ), array( 'code' => 'already' ) );
		}
		if ( 0 !== (int) $card['owner_user_id'] || 'active' !== $card['status'] ) {
			throw new Refusal( 'not_found', $nope );
		}
		if ( (int) $card['balance_cents'] <= 0 ) {
			throw new Refusal( 'empty', __( 'This gift card has nothing left on it.', 'fishotel-misc-plugin' ) );
		}
		if ( ! $this->store->claim_owner( (int) $card['id'], $user_id, $this->now() ) ) {
			throw new Refusal( 'not_found', $nope );
		}
		$this->move(
			$card,
			0,
			array(
				'type'    => 'redeemed_to_account',
				'key'     => 'redeem:' . (int) $card['id'],
				'user_id' => $user_id,
			),
			false
		);
		$this->audit( $ctx, (int) $card['id'], 'redeem', 'owner_user_id', 0, $user_id );
		/* translators: %s: amount */
		return self::ok( sprintf( __( 'Gift card added to your account: %s.', 'fishotel-misc-plugin' ), Money::words( (int) $card['balance_cents'] ) ), array( 'card_id' => (int) $card['id'] ) );
	}

	/* ============================================================== */
	/*  Orders                                                         */
	/* ============================================================== */

	/**
	 * An order's gift card money per card (oldest use first) and in all.
	 *
	 * @param int $order_id Order id.
	 * @return array{cards: array<int, array>, used: int, restored: int, outstanding: int, imported: bool}
	 */
	public function order_summary( $order_id ) {
		$rows     = $this->store->ledger_of_order( (int) $order_id );
		$cards    = Money::order_cards( $rows );
		$imported = false;
		foreach ( $rows as $row ) {
			if ( 'used' === $row['type'] && null !== $row['legacy_activity_id'] && '' !== (string) $row['legacy_activity_id'] ) {
				$imported = true;
			}
		}
		return array(
			'cards'       => $cards,
			'used'        => array_sum( array_column( $cards, 'used' ) ),
			'restored'    => array_sum( array_column( $cards, 'restored' ) ),
			'outstanding' => Money::outstanding( $cards ),
			'imported'    => $imported,
		);
	}

	/**
	 * Take an order's gift card amount from the customer's cards.
	 *
	 * @param int   $order_id Order id.
	 * @param int   $user_id  The order's customer (0 = guest).
	 * @param int[] $extra    Cards typed at checkout.
	 * @param int   $amount   Cents the order's fee says.
	 * @param array $ctx      Audit::context().
	 * @return array ok, taken (card id → cents).
	 */
	public function debit_order( $order_id, $user_id, array $extra, $amount, array $ctx ) {
		$order_id = (int) $order_id;
		$amount   = (int) $amount;
		if ( $order_id <= 0 || $amount < 0 ) {
			return self::fail( 'form', __( 'The order is not known.', 'fishotel-misc-plugin' ) );
		}
		return $this->atomic(
			function () use ( $order_id, $user_id, $extra, $amount, $ctx ) {
				$summary = $this->order_summary( $order_id );
				if ( $summary['outstanding'] === $amount ) {
					return self::ok( '', array( 'code' => 'already' ) );
				}
				if ( $summary['outstanding'] > 0 ) {
					// The order was changed and placed again: the old amount goes back first.
					$this->restore_inside( $order_id, $summary, __( 'order changed before payment', 'fishotel-misc-plugin' ), $ctx );
				}
				if ( 0 === $amount ) {
					return self::ok( '', array( 'taken' => array() ) );
				}
				$plan = Money::allocate( $this->sources( (int) $user_id, $extra ), $amount );
				if ( null === $plan ) {
					throw new Refusal( 'short', __( 'Your gift card balance changed since this page was opened (perhaps it was used in another window). The amount has been updated — please check your order and place it again.', 'fishotel-misc-plugin' ) );
				}
				$uses = $this->order_summary( $order_id )['cards'];
				foreach ( $plan as $card_id => $cents ) {
					$card = $this->store->card( $card_id );
					$n    = 1 + (int) ( $uses[ $card_id ]['uses'] ?? 0 );
					try {
						$this->move(
							$card,
							-$cents,
							array(
								'type'     => 'used',
								'key'      => 'use:' . $order_id . ':' . $card_id . ':' . $n,
								'order_id' => $order_id,
								'user_id'  => (int) $user_id,
							),
							true
						);
					} catch ( Refusal $r ) {
						throw new Refusal( 'short', __( 'Your gift card balance changed since this page was opened (perhaps it was used in another window). The amount has been updated — please check your order and place it again.', 'fishotel-misc-plugin' ) );
					}
					/* translators: %d: order id */
					$this->audit( $ctx, $card_id, 'use', 'balance', (int) $card['balance_cents'], (int) $card['balance_cents'] - $cents, -$cents, sprintf( __( 'order #%d', 'fishotel-misc-plugin' ), $order_id ) );
				}
				return self::ok( '', array( 'taken' => $plan ) );
			}
		);
	}

	/**
	 * Put back everything still out on an order — exactly once.
	 *
	 * @param int    $order_id Order id.
	 * @param string $why      In words (failed, cancelled, …).
	 * @param array  $ctx      Audit::context().
	 * @return array ok, restored (cents).
	 */
	public function restore_order( $order_id, $why, array $ctx ) {
		return $this->atomic(
			function () use ( $order_id, $why, $ctx ) {
				$summary = $this->order_summary( (int) $order_id );
				$back    = $this->restore_inside( (int) $order_id, $summary, (string) $why, $ctx );
				return self::ok( '', array( 'restored' => $back ) );
			}
		);
	}

	/**
	 * Put back what is still out (inside atomic()).
	 *
	 * @param int    $order_id Order id.
	 * @param array  $summary  order_summary().
	 * @param string $why      In words.
	 * @param array  $ctx      Audit::context().
	 * @return int Cents put back.
	 */
	private function restore_inside( $order_id, array $summary, $why, array $ctx ) {
		$back = 0;
		foreach ( $summary['cards'] as $card_id => $money ) {
			$out = (int) $money['outstanding'];
			$key = 'restore:' . (int) $order_id . ':' . (int) $card_id . ':' . (int) $money['uses'];
			if ( $out <= 0 || $this->store->ledger_has( $key ) ) {
				continue;
			}
			$card = $this->store->card( (int) $card_id );
			if ( ! $card ) {
				continue;
			}
			$after = $this->move(
				$card,
				$out,
				array(
					'type'     => 'restored',
					'key'      => $key,
					'order_id' => (int) $order_id,
					'note'     => $why,
				),
				false
			);
			/* translators: 1: order id, 2: why */
			$this->audit( $ctx, (int) $card_id, 'restore', 'balance', $after - $out, $after, $out, sprintf( __( 'order #%1$d %2$s', 'fishotel-misc-plugin' ), (int) $order_id, $why ) );
			$back += $out;
		}
		return $back;
	}

	/**
	 * Put part of an order's gift card money back (a partial refund),
	 * never more than is still out; onto the cards in the order they were
	 * used.
	 *
	 * @param int    $order_id    Order id.
	 * @param mixed  $amount      Typed amount.
	 * @param mixed  $reason      Why (required).
	 * @param string $request_key The form's one-time key.
	 * @param array  $ctx         Audit::context().
	 * @param int    $refund_id   WooCommerce refund id, when there is one.
	 * @return array
	 */
	public function restore_part( $order_id, $amount, $reason, $request_key, array $ctx, $refund_id = 0 ) {
		$cents  = Money::to_cents( $amount );
		$reason = self::reason( $reason );
		$key    = self::request_key( $request_key );
		if ( null === $cents || $cents <= 0 ) {
			return self::fail( 'amount', __( 'Enter the amount to put back, like 10.00.', 'fishotel-misc-plugin' ) );
		}
		if ( null === $reason ) {
			return self::fail( 'reason', __( 'Say why (a few words) — it goes in the audit log.', 'fishotel-misc-plugin' ) );
		}
		if ( '' === $key ) {
			return self::fail( 'form', __( 'The form was not complete. Please reload the page and try again.', 'fishotel-misc-plugin' ) );
		}
		return $this->atomic(
			function () use ( $order_id, $cents, $reason, $key, $ctx, $refund_id ) {
				$summary = $this->order_summary( (int) $order_id );
				if ( $cents > $summary['outstanding'] ) {
					/* translators: %s: amount */
					throw new Refusal( 'too_much', sprintf( __( 'Only %s of this order\'s gift card money is not back on a card yet.', 'fishotel-misc-plugin' ), Money::words( $summary['outstanding'] ) ) );
				}
				$left = $cents;
				foreach ( $summary['cards'] as $card_id => $money ) {
					if ( $left <= 0 ) {
						break;
					}
					$take = min( $left, (int) $money['outstanding'] );
					if ( $take <= 0 ) {
						continue;
					}
					$card  = $this->store->card( (int) $card_id );
					$after = $this->move(
						$card,
						$take,
						array(
							'type'      => 'restored',
							'key'       => 'part:' . (int) $order_id . ':' . (int) $card_id . ':' . $key,
							'order_id'  => (int) $order_id,
							'refund_id' => (int) $refund_id,
							'user_id'   => (int) ( $ctx['user_id'] ?? 0 ),
							'note'      => $reason,
						),
						false
					);
					$this->audit( $ctx, (int) $card_id, 'restore', 'balance', $after - $take, $after, $take, $reason );
					$left -= $take;
				}
				/* translators: %s: amount */
				return self::ok( sprintf( __( '%s was put back on the gift card.', 'fishotel-misc-plugin' ), Money::words( $cents ) ), array( 'restored' => $cents ) );
			}
		);
	}

	/* ============================================================== */
	/*  Import (called by the Importer, one card at a time)            */
	/* ============================================================== */

	/**
	 * Copy one old card and its activity in (or bring an imported card up
	 * to date) — one transaction per card.
	 *
	 * @param array   $card     Importer::convert_card() fields.
	 * @param array[] $activity Importer::convert_activity() rows of this card.
	 * @param array   $ctx      Audit::context() (source import).
	 * @return array ok, code created|updated|same|both.
	 */
	public function import_card( array $card, array $activity, array $ctx ) {
		return $this->atomic(
			function () use ( $card, $activity, $ctx ) {
				$existing = $this->store->card_by_legacy( (int) $card['legacy_id'] );
				$now      = $this->stamp();
				$code     = 'same';

				if ( ! $existing ) {
					$row = array(
						'code'            => (string) $card['code'],
						'code_key'        => Codes::key( $card['code'] ),
						'status'          => $card['status'],
						'amount_cents'    => (int) $card['amount_cents'],
						'balance_cents'   => 0,
						'owner_user_id'   => (int) $card['owner_user_id'],
						'recipient_name'  => (string) $card['recipient_name'],
						'recipient_email' => (string) $card['recipient_email'],
						'sender_name'     => (string) $card['sender_name'],
						'sender_email'    => (string) $card['sender_email'],
						'message'         => (string) $card['message'],
						'email_state'     => (string) $card['email_state'],
						'buyer_user_id'   => 0,
						'source'          => 'import',
						'legacy_id'       => (int) $card['legacy_id'],
						'created_by'      => (int) ( $ctx['user_id'] ?? 0 ),
						'created_at'      => (string) ( $card['created_at'] ?: $now ),
						'updated_at'      => $now,
					);
					foreach ( array( 'redeemed_at', 'deliver_at', 'delivered_at', 'legacy_expires_at', 'last_used_at' ) as $k ) {
						if ( ! empty( $card[ $k ] ) ) {
							$row[ $k ] = (string) $card[ $k ];
						}
					}
					foreach ( array( 'order_id', 'product_id' ) as $k ) {
						if ( ! empty( $card[ $k ] ) ) {
							$row[ $k ] = (int) $card[ $k ];
						}
					}
					$id = $this->store->insert_card( $row );
					if ( ! $id ) {
						throw new Refusal( 'db', __( 'An old card could not be copied (is its code already used by another card?).', 'fishotel-misc-plugin' ) );
					}
					$existing = $this->store->card( $id );
					$code     = 'created';
					$this->audit( $ctx, (int) $id, 'import', 'balance', null, (int) $card['balance_cents'], null, 'old card #' . (int) $card['legacy_id'] );
				} elseif ( $this->store->own_movements( (int) $existing['id'] ) > 0 ) {
					// Used here since the last import: this system is now its truth.
					return self::ok( '', array( 'code' => 'both' ) );
				} else {
					foreach ( array( 'status', 'recipient_email', 'sender_name', 'sender_email', 'message', 'legacy_expires_at' ) as $k ) {
						$new = isset( $card[ $k ] ) ? (string) $card[ $k ] : '';
						if ( (string) $existing[ $k ] !== $new ) {
							$this->store->change_card( (int) $existing['id'], array( $k => '' === $new && 'legacy_expires_at' === $k ? null : $new ), array(), $this->now() );
							$this->audit( $ctx, (int) $existing['id'], 'import_update', $k, (string) $existing[ $k ], $new, null, 'old card #' . (int) $card['legacy_id'] );
							$code = 'updated';
						}
					}
					if ( 0 === (int) $existing['owner_user_id'] && (int) $card['owner_user_id'] > 0 ) {
						$this->store->claim_owner( (int) $existing['id'], (int) $card['owner_user_id'], strtotime( (string) ( $card['redeemed_at'] ?: $now ) . ' UTC' ) );
						$this->audit( $ctx, (int) $existing['id'], 'import_update', 'owner_user_id', 0, (int) $card['owner_user_id'], null, 'old card #' . (int) $card['legacy_id'] );
						$code = 'updated';
					}
				}

				$card_id = (int) $existing['id'];
				foreach ( $activity as $act ) {
					if ( $this->store->ledger_has_legacy( (int) $act['legacy_activity_id'] ) ) {
						continue;
					}
					$row = array(
						'card_id'            => $card_id,
						'type'               => (string) $act['type'],
						'amount_cents'       => (int) $act['amount_cents'],
						'user_id'            => (int) $act['user_id'],
						'idempotency_key'    => 'legacy:' . (int) $act['legacy_activity_id'],
						'legacy_activity_id' => (int) $act['legacy_activity_id'],
						'note'               => substr( (string) $act['note'], 0, 255 ),
						'created_at'         => (string) ( $act['created_at'] ?: $now ),
					);
					if ( ! empty( $act['order_id'] ) ) {
						$row['order_id'] = (int) $act['order_id'];
					}
					if ( ! $this->store->insert_ledger( $row ) ) {
						throw new Refusal( 'db', __( 'An old activity row could not be copied.', 'fishotel-misc-plugin' ) );
					}
					if ( 'same' === $code ) {
						$code = 'updated';
					}
				}

				// The old plugin's stored balance is the truth: one row makes the
				// ledger add up to it, and the cached balance follows.
				$target = (int) $card['balance_cents'];
				$sum    = $this->store->ledger_sum( $card_id );
				if ( $sum !== $target ) {
					$row = array(
						'card_id'             => $card_id,
						'type'                => 'import_adjust',
						'amount_cents'        => $target - $sum,
						'balance_after_cents' => $target,
						'idempotency_key'     => 'legacy-adjust:' . (int) $card['legacy_id'] . ':' . $sum . ':' . $target,
						'note'                => 'makes the history add up to the old plugin\'s balance',
						'created_at'          => $now,
					);
					if ( ! $this->store->insert_ledger( $row ) ) {
						throw new Refusal( 'db', __( 'An old balance could not be copied.', 'fishotel-misc-plugin' ) );
					}
				}
				$cached = (int) $this->store->balance( $card_id );
				if ( $cached !== $target ) {
					if ( ! $this->store->move_balance( $card_id, $target - $cached, $this->now() ) ) {
						throw new Refusal( 'db', __( 'An old balance could not be copied.', 'fishotel-misc-plugin' ) );
					}
					if ( 'created' !== $code ) {
						$this->audit( $ctx, $card_id, 'import_update', 'balance', $cached, $target, $target - $cached, 'old card #' . (int) $card['legacy_id'] );
						$code = 'updated';
					}
				}
				return self::ok( '', array( 'code' => $code ) );
			}
		);
	}

	/* ============================================================== */
	/*  Stats                                                          */
	/* ============================================================== */

	/**
	 * The Stats tab's numbers.
	 *
	 * @return array
	 */
	public function stats() {
		$tz    = $this->tz();
		$local = ( new \DateTimeImmutable( '@' . $this->now() ) )->setTimezone( $tz );
		$utc   = new \DateTimeZone( 'UTC' );
		$month = $local->setDate( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), 1 )->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$year  = $local->setDate( (int) $local->format( 'Y' ), 1, 1 )->setTime( 0, 0 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$ago   = gmdate( 'Y-m-d H:i:s', $this->now() - 365 * 86400 );

		$s                = $this->store->stats( $month, $year, $ago );
		$s['books_cents'] = (int) ( $s['outstanding_active'] ?? 0 ) + ( Settings::on( $this->settings, 'books_include_disabled' ) ? (int) ( $s['outstanding_disabled'] ?? 0 ) : 0 );
		$s['mismatches']  = $this->store->mismatches( 20 );
		return $s;
	}
}
