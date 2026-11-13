<?php
/**
 * Requests service — everything that can happen to a request, in one
 * place: it is created (from a private link, by hand, by another
 * section), approved with or without a fee, declined, paid, matched to a
 * supplier order line, moved along with the fish, offered, filled,
 * cancelled, kept, expired. Every change is written to the request's
 * append-only log with who did it.
 *
 * Nothing here touches $wpdb, WooCommerce or the Clean Room: the store,
 * the mailer and the few facts about the outside world (is a fee
 * possible? who owns this e-mail address? what is the clock?) are given
 * to the constructor, so the tests run every path against an in-memory
 * store.
 *
 * Changes of state go through the store's guarded update ("only while it
 * is still new"), so a double click, two payment hooks or the daily job
 * meeting a click never apply the same change twice.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Service
 */
class Service {

	/**
	 * The store (Store or the tests' in-memory twin).
	 *
	 * @var object
	 */
	public $store;

	/**
	 * Settings (Settings::get()).
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * The mailer (send( kind, request, extra ) → sent|off|no_email|failed).
	 *
	 * @var object
	 */
	public $mailer;

	/**
	 * The outside world, as callables:
	 *   now()                       UNIX time
	 *   fees()                      array{available: bool, reason: string}
	 *   user( id )                  array{id, name, email, handle}|null
	 *   user_by_email( email )      user id (0 = none)
	 *   match_fish( fish )          array{species_id, product_id} for a name that is in the
	 *                               species list or is a product's title
	 *   product_url( request )      the product's public link ('' = none)
	 *   product_title( product id ) its title ('' = none)
	 *
	 * @var array<string, callable>
	 */
	private $world;

	/**
	 * What the last e-mail attempt answered (sent|off|no_email|failed|'').
	 *
	 * @var string
	 */
	public $last_mail = '';

	/**
	 * Constructor.
	 *
	 * @param object $store    Store.
	 * @param array  $settings Settings.
	 * @param object $mailer   Mailer.
	 * @param array  $world    See $world.
	 */
	public function __construct( $store, array $settings, $mailer, array $world = array() ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->mailer   = $mailer;
		$this->world    = $world;
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

	private function stamp() {
		return Rules::utc( $this->now() );
	}

	/**
	 * Whether a fee can be charged, and why not.
	 *
	 * @return array{available: bool, reason: string}
	 */
	public function fees() {
		$status = isset( $this->world['fees'] ) ? call_user_func( $this->world['fees'] ) : null;
		return array(
			'available' => is_array( $status ) && ! empty( $status['available'] ),
			'reason'    => is_array( $status ) && isset( $status['reason'] ) ? (string) $status['reason'] : __( 'the fee system is not connected', 'fishotel-misc-plugin' ),
		);
	}

	private function user( $id ) {
		$user = isset( $this->world['user'] ) && (int) $id > 0 ? call_user_func( $this->world['user'], (int) $id ) : null;
		return is_array( $user ) && ! empty( $user['id'] ) ? $user : null;
	}

	private function user_by_email( $email ) {
		$email = Rules::email( $email );
		if ( '' === $email || ! isset( $this->world['user_by_email'] ) ) {
			return 0;
		}
		return max( 0, (int) call_user_func( $this->world['user_by_email'], $email ) );
	}

	private function match_fish( $fish ) {
		$found = isset( $this->world['match_fish'] ) && '' !== (string) $fish ? call_user_func( $this->world['match_fish'], (string) $fish ) : null;
		return array(
			'species_id' => is_array( $found ) && ! empty( $found['species_id'] ) ? (int) $found['species_id'] : null,
			'product_id' => is_array( $found ) && ! empty( $found['product_id'] ) ? (int) $found['product_id'] : null,
		);
	}

	private function product_url( array $request ) {
		return isset( $this->world['product_url'] ) ? (string) call_user_func( $this->world['product_url'], $request ) : '';
	}

	/* ============================================================== */
	/*  Who                                                            */
	/* ============================================================== */

	/**
	 * The daily job, a payment hook, another section.
	 *
	 * @return array{id: int, name: string}
	 */
	public static function system() {
		return array(
			'id'   => 0,
			'name' => 'system',
		);
	}

	/**
	 * The person who asked (through a link, My Account or an e-mail).
	 *
	 * @param int $user_id Their user id when they are logged in.
	 * @return array{id: int, name: string}
	 */
	public static function customer( $user_id = 0 ) {
		return array(
			'id'   => max( 0, (int) $user_id ),
			'name' => 'customer',
		);
	}

	/**
	 * A logged-in member of the shop.
	 *
	 * @param int    $user_id User id.
	 * @param string $login   Login.
	 * @return array{id: int, name: string}
	 */
	public static function staff( $user_id, $login ) {
		return array(
			'id'   => max( 0, (int) $user_id ),
			'name' => '' !== (string) $login ? (string) $login : 'user #' . (int) $user_id,
		);
	}

	private function log( $id, $action, $note, array $actor ) {
		return $this->store->log( (int) $id, (string) $action, (string) $note, (int) ( $actor['id'] ?? 0 ), (string) ( $actor['name'] ?? '' ) );
	}

	private static function ok( $message, array $more = array() ) {
		return array_merge(
			array(
				'ok'      => true,
				'message' => (string) $message,
			),
			$more
		);
	}

	private static function fail( $message, array $more = array() ) {
		return array_merge(
			array(
				'ok'      => false,
				'message' => (string) $message,
			),
			$more
		);
	}

	/**
	 * Change a request's state under a guard, log it and tell whoever
	 * listens.
	 *
	 * @param array    $request The request as read.
	 * @param string   $to      The new state.
	 * @param array    $fields  Other columns to set.
	 * @param string[] $from    The states it must still be in.
	 * @param string   $action  Log action.
	 * @param string   $note    Log note.
	 * @param array    $actor   Who.
	 * @return bool
	 */
	private function move( array $request, $to, array $fields, array $from, $action, $note, array $actor ) {
		$fields['state'] = $to;
		if ( ! $this->store->update( (int) $request['id'], $fields, $from ) ) {
			return false;
		}
		$this->log( $request['id'], $action, $note, $actor );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'fishotel_requests_state_changed', (int) $request['id'], (string) ( $request['state'] ?? '' ), (string) $to );
		}
		return true;
	}

	/* ============================================================== */
	/*  Create                                                         */
	/* ============================================================== */

	/**
	 * Create one request.
	 *
	 * By source:
	 *   link / public  it waits for the owner (state "new"); a fee given
	 *                  here is only the proposal the Approve form starts from
	 *   hand           approved as it is saved: "waiting", or — with a fee —
	 *                  "awaiting_payment" with the invoice e-mailed
	 *   lost_fish      owed, never a fee, "waiting" at once
	 *
	 * @param array      $args  user_id, name, email, handle, fish, species_id, product_id, size_slug,
	 *                          size_label, qty, note, source, came_from, owed, fee, order_id, link_id,
	 *                          sheet, 'sizes' (the standard size list to check the size against;
	 *                          absent = the size is taken as given) and — by hand only — invoice_of
	 *                          (the request that carries an unpaid invoice of the same e-mail
	 *                          address: this fish joins it; no second invoice, no e-mail).
	 * @param array|null $actor Who (default: the system).
	 * @return int|\WP_Error The new request id.
	 */
	public function create( array $args, $actor = null ) {
		$actor  = is_array( $actor ) ? $actor : self::system();
		$source = isset( $args['source'] ) && in_array( (string) $args['source'], Rules::SOURCES, true ) ? (string) $args['source'] : 'hand';
		$lost   = 'lost_fish' === $source;
		$hand   = 'hand' === $source;
		$owed   = $lost || ! empty( $args['owed'] );
		$errors = array();

		// Who.
		$person  = Rules::person( $args );
		$user_id = max( 0, (int) ( $args['user_id'] ?? 0 ) );
		if ( $user_id > 0 ) {
			$user = $this->user( $user_id );
			if ( ! $user ) {
				$errors[] = __( 'That site customer does not exist.', 'fishotel-misc-plugin' );
				$user_id  = 0;
			} else {
				foreach ( array( 'name', 'email', 'handle' ) as $key ) {
					if ( '' === $person[ $key ] && ! empty( $user[ $key ] ) ) {
						$person[ $key ] = 'email' === $key ? Rules::email( $user[ $key ] ) : Rules::text( $user[ $key ], 'name' === $key ? Rules::MAX_NAME : Rules::MAX_HANDLE );
					}
				}
			}
		} elseif ( ( $hand || $lost ) && '' !== $person['email'] ) {
			// Typed by the shop itself, so the address is trusted: the request joins that account.
			$user_id = $this->user_by_email( $person['email'] );
		}
		$errors = array_merge( $errors, Rules::person_errors( $person, ! $hand && ! $lost ) );

		// What.
		if ( '' === Rules::text( $args['fish'] ?? '', Rules::MAX_FISH ) && ! empty( $args['product_id'] ) && isset( $this->world['product_title'] ) ) {
			$args['fish'] = (string) call_user_func( $this->world['product_title'], (int) $args['product_id'] );
		}
		$fish   = Rules::fish_row( $args, array_key_exists( 'sizes', $args ) && is_array( $args['sizes'] ) ? $args['sizes'] : null );
		$errors = array_merge( $errors, $fish['errors'] );
		$row    = $fish['row'];

		// The fee.
		$fee = 0.0;
		if ( ! $owed ) {
			$fee = Rules::fee( $args['fee'] ?? '' );
			if ( null === $fee ) {
				$errors[] = self::fee_words();
				$fee      = 0.0;
			}
		}
		// By hand, one more fish for somebody whose invoice is not paid yet: it
		// goes on that invoice instead of making a second one.
		$join = null;
		if ( $hand && ! $owed && ! empty( $args['invoice_of'] ) ) {
			$invoice = $this->payable( (int) $args['invoice_of'] );
			if ( ! $invoice['ok'] ) {
				$errors[] = __( 'That invoice can no longer take another fish (it was paid, removed, or ran out of time). Untick it — and set a fee if this fish needs one.', 'fishotel-misc-plugin' );
			} elseif ( '' === $person['email'] || Rules::email( $invoice['carrier']['email'] ) !== $person['email'] ) {
				$errors[] = __( 'One invoice goes to one person: this fish is for another e-mail address than that invoice.', 'fishotel-misc-plugin' );
			} else {
				$join = $invoice;
				$fee  = 0.0;
			}
		}
		if ( $hand && $fee > 0 ) {
			$fees = $this->fees();
			if ( ! $fees['available'] ) {
				/* translators: %s: why */
				$errors[] = sprintf( __( 'A fee cannot be charged right now: %s.', 'fishotel-misc-plugin' ), $fees['reason'] );
			} elseif ( '' === $person['email'] ) {
				$errors[] = __( 'A fee needs an e-mail address to send the invoice to.', 'fishotel-misc-plugin' );
			}
		}

		if ( $errors ) {
			return new \WP_Error( 'fishotel_requests_invalid', implode( ' ', $errors ), array( 'errors' => $errors ) );
		}

		if ( null === $row['species_id'] && null === $row['product_id'] ) {
			$row = array_merge( $row, $this->match_fish( $row['fish'] ) );
		}

		$now      = $this->stamp();
		$state    = 'new';
		$approved = null;
		if ( $hand || $lost ) {
			$state    = ( $hand && ( $fee > 0 || $join ) ) ? 'awaiting_payment' : 'waiting';
			// On an invoice that is already running, it runs out of time with the others.
			$approved = $join ? $join['members'][0]['approved_at'] : $now;
		}

		$list = isset( $this->settings['came_from'] ) && is_array( $this->settings['came_from'] ) ? $this->settings['came_from'] : array();
		$id   = $this->store->insert(
			array(
				'user_id'       => $user_id,
				'name'          => $person['name'],
				'email'         => $person['email'],
				'handle'        => $person['handle'],
				'fish'          => $row['fish'],
				'fish_key'      => Rules::fish_key( $row ),
				'species_id'    => $row['species_id'],
				'product_id'    => $row['product_id'],
				'size_slug'     => $row['size_slug'],
				'size_label'    => $row['size_label'],
				'qty'           => $row['qty'],
				'note'          => $row['note'],
				'source'        => $source,
				'came_from'     => Settings::came_from( $args['came_from'] ?? '', $list ),
				'state'         => $state,
				'owed'          => $owed ? 1 : 0,
				'fee_amount'    => $fee,
				'from_order_id' => ! empty( $args['order_id'] ) ? (int) $args['order_id'] : null,
				'link_id'       => ! empty( $args['link_id'] ) ? (int) $args['link_id'] : null,
				'sheet'         => Rules::text( $args['sheet'] ?? '', 32 ),
				'created_at'    => $now,
				'updated_at'    => $now,
				'approved_at'   => $approved,
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'fishotel_requests_db', __( 'The request could not be saved.', 'fishotel-misc-plugin' ) );
		}

		$words = array( Rules::fish_line( $row ), Rules::source_label( $source ) );
		if ( $owed ) {
			$words[] = __( 'owed: already paid for, never a fee', 'fishotel-misc-plugin' );
		}
		if ( ! empty( $args['order_id'] ) ) {
			/* translators: %d: order number */
			$words[] = sprintf( __( 'from order #%d', 'fishotel-misc-plugin' ), (int) $args['order_id'] );
		}
		if ( $hand && $join ) {
			/* translators: 1: request id, 2: amount */
			$words[] = sprintf( __( 'approved as saved, on the invoice of request #%1$d (%2$s, not paid yet) — no second invoice', 'fishotel-misc-plugin' ), (int) $join['carrier']['id'], Rules::money( (float) $join['carrier']['fee_amount'] ) );
		} elseif ( $hand ) {
			$words[] = $fee > 0
				/* translators: %s: amount */
				? sprintf( __( 'approved as saved, with a fee of %s', 'fishotel-misc-plugin' ), Rules::money( $fee ) )
				: __( 'approved as saved, no fee', 'fishotel-misc-plugin' );
		}
		$this->log( $id, 'created', implode( ' — ', $words ), $actor );

		$this->last_mail = '';
		if ( $join ) {
			// The invoice went out with the first fish: nothing is e-mailed again.
			$this->store->update( $id, array( 'fee_ref' => (int) $join['carrier']['id'] ) );
		} elseif ( 'awaiting_payment' === $state ) {
			$this->store->update( $id, array( 'fee_ref' => $id ) );
			$this->last_mail = $this->send_invoice( $id );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'fishotel_requests_created', (int) $id, (array) $this->store->get( $id ) );
		}

		return (int) $id;
	}

	/**
	 * A sheet from the private form (or the public one): one person, one
	 * or more fish. Either every row is fine and every one becomes a
	 * request in state "new", or nothing is created.
	 *
	 * @param array $person name, email, handle.
	 * @param mixed $rows   rows[n][fish|size_slug|size_label|qty|note].
	 * @param array $ctx    source (link|public), link (the link row, for a link), user_id, fee (the
	 *                      proposed fee), sizes (the standard list, or null = free text).
	 * @return array{ok: bool, ids: int[], errors: string[]}
	 */
	public function submit( array $person, $rows, array $ctx ) {
		$source = 'public' === (string) ( $ctx['source'] ?? '' ) ? 'public' : 'link';
		$sizes  = isset( $ctx['sizes'] ) && is_array( $ctx['sizes'] ) ? $ctx['sizes'] : null;
		$link   = isset( $ctx['link'] ) && is_array( $ctx['link'] ) ? $ctx['link'] : null;
		$who    = Rules::person( $person );
		$errors = Rules::person_errors( $who, true );

		$raw_rows = Rules::form_rows( $rows );
		if ( ! $raw_rows ) {
			$errors[] = __( 'Please name at least one fish.', 'fishotel-misc-plugin' );
		}
		if ( count( $raw_rows ) > Rules::MAX_ROWS ) {
			/* translators: %d: rows */
			$errors[] = sprintf( __( 'One sheet takes at most %d fish — please send the rest on a second one.', 'fishotel-misc-plugin' ), Rules::MAX_ROWS );
			$raw_rows = array_slice( $raw_rows, 0, Rules::MAX_ROWS );
		}

		$clean = array();
		foreach ( $raw_rows as $index => $raw ) {
			$row = Rules::fish_row( $raw, $sizes );
			foreach ( $row['errors'] as $error ) {
				/* translators: 1: row number, 2: what is wrong */
				$errors[] = sprintf( __( 'Fish %1$d: %2$s', 'fishotel-misc-plugin' ), $index + 1, $error );
			}
			$clean[] = $row['row'];
		}

		if ( 'link' === $source ) {
			$problem = Rules::link_problem( $link, $this->now() );
			if ( '' !== $problem ) {
				$errors[] = Rules::link_problem_words( $problem );
			}
		}

		if ( $errors ) {
			return array(
				'ok'     => false,
				'ids'    => array(),
				'errors' => $errors,
			);
		}

		// The sheet is fine: now, and only now, the link counts a use.
		if ( $link && ! $this->store->use_link( (int) $link['id'] ) ) {
			return array(
				'ok'     => false,
				'ids'    => array(),
				'errors' => array( Rules::link_problem_words( 'used_up' ) ),
			);
		}

		$sheet = Tokens::sheet();
		$ids   = array();
		foreach ( $clean as $row ) {
			$args = array_merge(
				$row,
				array(
					'name'    => $who['name'],
					'email'   => $who['email'],
					'handle'  => $who['handle'],
					'user_id' => max( 0, (int) ( $ctx['user_id'] ?? 0 ) ),
					'source'  => $source,
					'fee'     => isset( $ctx['fee'] ) ? (float) $ctx['fee'] : 0.0,
					'link_id' => $link ? (int) $link['id'] : 0,
					'sheet'   => $sheet,
				)
			);
			if ( null !== $sizes ) {
				$args['sizes'] = $sizes;
			}
			$id = $this->create( $args, self::customer( (int) ( $ctx['user_id'] ?? 0 ) ) );
			if ( is_object( $id ) ) {
				$errors[] = method_exists( $id, 'get_error_message' ) ? (string) $id->get_error_message() : __( 'A request could not be saved.', 'fishotel-misc-plugin' );
				continue;
			}
			$ids[] = (int) $id;
		}

		return array(
			'ok'     => (bool) $ids,
			'ids'    => $ids,
			'errors' => $errors,
		);
	}

	/* ============================================================== */
	/*  Approve / decline                                              */
	/* ============================================================== */

	/**
	 * Approve new requests. Without a fee each goes on the list
	 * ("waiting"). With a fee they become ONE invoice: the oldest carries
	 * the amount, the others point at it, all wait for the payment, and
	 * one e-mail with one pay link goes out.
	 *
	 * @param int[] $ids   Request ids (the ones still new are approved).
	 * @param mixed $fee   The fee as typed ('' or 0 = none).
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string, ids?: int[], mail?: string}
	 */
	public function approve( array $ids, $fee, array $actor ) {
		$requests = $this->store->find(
			array(
				'ids'   => $ids,
				'state' => 'new',
			)
		);
		if ( ! $requests ) {
			return self::fail( __( 'Nothing to approve — those requests were already handled.', 'fishotel-misc-plugin' ) );
		}

		$fee = Rules::fee( $fee );
		if ( null === $fee ) {
			return self::fail( self::fee_words() );
		}

		if ( $fee > 0 ) {
			$fees = $this->fees();
			if ( ! $fees['available'] ) {
				/* translators: %s: why */
				return self::fail( sprintf( __( 'A fee cannot be charged right now: %s. Nothing was approved — approve without a fee, or put that right first.', 'fishotel-misc-plugin' ), $fees['reason'] ) );
			}
			$emails = array();
			foreach ( $requests as $request ) {
				if ( ! empty( $request['owed'] ) ) {
					return self::fail( __( 'A request that is owed (already paid for) never has a fee. Nothing was approved.', 'fishotel-misc-plugin' ) );
				}
				$emails[ Rules::email( $request['email'] ) ] = true;
			}
			if ( 1 !== count( $emails ) || isset( $emails[''] ) ) {
				return self::fail( __( 'One invoice goes to one person: tick the requests of one e-mail address (each needs one). Nothing was approved.', 'fishotel-misc-plugin' ) );
			}
		}

		usort(
			$requests,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);

		$now     = $this->stamp();
		$carrier = $fee > 0 ? (int) $requests[0]['id'] : 0;
		$done    = array();

		foreach ( $requests as $request ) {
			$id     = (int) $request['id'];
			$fields = array(
				'approved_at' => $now,
				'reminded_at' => null,
				'fee_amount'  => $id === $carrier ? $fee : 0.0,
				'fee_ref'     => $carrier > 0 ? $carrier : null,
			);
			if ( (int) $request['user_id'] <= 0 ) {
				// Approving vouches for the request: it joins the account of its address.
				$user_id = $this->user_by_email( $request['email'] );
				if ( $user_id > 0 ) {
					$fields['user_id'] = $user_id;
				}
			}

			if ( $fee > 0 ) {
				$note = $id === $carrier
					/* translators: %s: amount */
					? sprintf( __( 'Approved with a fee of %s — waiting for the payment.', 'fishotel-misc-plugin' ), Rules::money( $fee ) )
					/* translators: %d: request id */
					: sprintf( __( 'Approved — on the invoice of request #%d, waiting for the payment.', 'fishotel-misc-plugin' ), $carrier );
			} else {
				$note = __( 'Approved without a fee — on the list.', 'fishotel-misc-plugin' );
			}

			if ( ! $this->move( $request, $fee > 0 ? 'awaiting_payment' : 'waiting', $fields, array( 'new' ), 'approved', $note, $actor ) ) {
				if ( $id === $carrier ) {
					return self::fail( __( 'That request was handled by somebody else in the meantime. Nothing was approved.', 'fishotel-misc-plugin' ) );
				}
				continue;
			}
			$done[] = $id;
		}

		if ( ! $done ) {
			return self::fail( __( 'Nothing to approve — those requests were already handled.', 'fishotel-misc-plugin' ) );
		}

		/* translators: %d: requests */
		$message         = sprintf( _n( '%d request approved.', '%d requests approved.', count( $done ), 'fishotel-misc-plugin' ), count( $done ) );
		$this->last_mail = '';
		if ( $carrier > 0 ) {
			$this->last_mail = $this->send_invoice( $carrier );
			$message        .= ' ' . self::mail_words( $this->last_mail, __( 'The invoice', 'fishotel-misc-plugin' ), true );
		}

		return self::ok(
			$message,
			array(
				'ids'  => $done,
				'mail' => $this->last_mail,
			)
		);
	}

	/**
	 * Decline a new request (it is closed as cancelled). An e-mail goes
	 * out only when asked for.
	 *
	 * @param int    $id    Request id.
	 * @param string $note  Why (optional; it is in the e-mail when one is sent).
	 * @param bool   $send  E-mail the person.
	 * @param array  $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function decline( $id, $note, $send, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || 'new' !== $request['state'] ) {
			return self::fail( __( 'Only a new request can be declined (cancel it instead).', 'fishotel-misc-plugin' ) );
		}

		$note = Rules::lines( $note, Rules::MAX_NOTE );
		$said = '' !== $note
			/* translators: %s: the reason */
			? sprintf( __( 'Declined: %s', 'fishotel-misc-plugin' ), $note )
			: __( 'Declined.', 'fishotel-misc-plugin' );

		if ( ! $this->move( $request, 'cancelled', array( 'closed_at' => $this->stamp() ), array( 'new' ), 'declined', $said, $actor ) ) {
			return self::fail( __( 'That request was handled by somebody else in the meantime.', 'fishotel-misc-plugin' ) );
		}

		$message         = __( 'Request declined.', 'fishotel-misc-plugin' );
		$this->last_mail = '';
		if ( $send ) {
			$this->last_mail = $this->mailer->send( 'declined', $request, array( 'note' => $note ) );
			$message        .= ' ' . self::mail_words( $this->last_mail, __( 'The e-mail', 'fishotel-misc-plugin' ), false );
		} else {
			$message .= ' ' . __( 'No e-mail was sent.', 'fishotel-misc-plugin' );
		}

		return self::ok( $message );
	}

	/* ============================================================== */
	/*  The fee                                                        */
	/* ============================================================== */

	/**
	 * Every request on an invoice (the carrier included).
	 *
	 * @param int $carrier_id The request that carries the invoice.
	 * @return array[]
	 */
	public function invoice_members( $carrier_id ) {
		return (int) $carrier_id > 0 ? $this->store->find( array( 'fee_ref' => (int) $carrier_id ) ) : array();
	}

	/**
	 * Whether an invoice can still be paid, and what for (pure given the
	 * store): the carrier exists and carries the amount asked, nothing was
	 * paid yet, and at least one request on it still waits for the payment.
	 *
	 * @param int        $carrier_id The request that carries the invoice.
	 * @param float|null $amount     The amount a link or a cart line names (null = do not compare).
	 * @return array{ok: bool, why: string, message: string, carrier: array|null, members: array[]}
	 */
	public function payable( $carrier_id, $amount = null ) {
		$carrier = $this->store->get( (int) $carrier_id );
		$out     = array(
			'ok'      => false,
			'why'     => 'missing',
			'message' => __( 'This payment link is not valid.', 'fishotel-misc-plugin' ),
			'carrier' => $carrier,
			'members' => array(),
		);
		if ( ! $carrier || (int) $carrier['fee_ref'] !== (int) $carrier['id'] || (float) $carrier['fee_amount'] <= 0 ) {
			return $out;
		}
		if ( ! empty( $carrier['fee_paid_at'] ) ) {
			$out['why']     = 'paid';
			$out['message'] = __( 'This fee is already paid — thank you.', 'fishotel-misc-plugin' );
			return $out;
		}
		if ( null !== $amount && abs( (float) $carrier['fee_amount'] - (float) $amount ) >= 0.005 ) {
			$out['why']     = 'changed';
			$out['message'] = __( 'This invoice has changed since the link was sent. Please use the link in our latest e-mail.', 'fishotel-misc-plugin' );
			return $out;
		}

		$waiting = array();
		foreach ( $this->invoice_members( (int) $carrier['id'] ) as $member ) {
			if ( 'awaiting_payment' === $member['state'] ) {
				$waiting[] = $member;
			}
		}
		if ( ! $waiting ) {
			$out['why']     = 'closed';
			$out['message'] = __( 'There is nothing left to pay for: the request was cancelled or has run out of time. You are welcome to ask again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['ok']      = true;
		$out['why']     = '';
		$out['message'] = '';
		$out['members'] = $waiting;

		return $out;
	}

	/**
	 * E-mail the invoice (or the reminder) of a carrier.
	 *
	 * @param int    $carrier_id The request that carries the invoice.
	 * @param string $kind       invoice|reminder.
	 * @return string sent|off|no_email|failed.
	 */
	public function send_invoice( $carrier_id, $kind = 'invoice' ) {
		$state = $this->payable( (int) $carrier_id );
		if ( ! $state['ok'] ) {
			return 'failed';
		}
		$carrier = $state['carrier'];
		$expire  = (int) ( $this->settings['expire_days'] ?? 7 );

		return $this->mailer->send(
			'reminder' === $kind ? 'reminder' : 'invoice',
			$carrier,
			array(
				'requests' => $state['members'],
				'pay_url'  => Urls::pay( (int) $carrier['id'], (float) $carrier['fee_amount'] ),
				'days'     => Rules::fee_days_left( $state['members'][0], $this->now(), $expire ),
			)
		);
	}

	/**
	 * Send the invoice again.
	 *
	 * @param int   $id    A request that waits for its payment.
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function resend_invoice( $id, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || ! Rules::fee_open( $request ) ) {
			return self::fail( __( 'That request is not waiting for a payment.', 'fishotel-misc-plugin' ) );
		}
		$this->log( $request['fee_ref'], 'note', __( 'Invoice sent again.', 'fishotel-misc-plugin' ), $actor );
		$this->last_mail = $this->send_invoice( (int) $request['fee_ref'] );

		return 'sent' === $this->last_mail
			? self::ok( __( 'The invoice was e-mailed again.', 'fishotel-misc-plugin' ) )
			: self::fail( self::mail_words( $this->last_mail, __( 'The invoice', 'fishotel-misc-plugin' ), true ) );
	}

	/**
	 * Change the fee of an unpaid invoice (0 removes it: its requests go
	 * on the list). The clock starts again and the invoice goes out again.
	 *
	 * @param int   $id    A request that waits for its payment.
	 * @param mixed $fee   The new fee as typed.
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function set_fee( $id, $fee, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || ! Rules::fee_open( $request ) ) {
			return self::fail( __( 'The fee can only be changed while the request waits for its payment.', 'fishotel-misc-plugin' ) );
		}
		$fee = Rules::fee( $fee );
		if ( null === $fee ) {
			return self::fail( self::fee_words() );
		}

		$carrier_id = (int) $request['fee_ref'];
		$state      = $this->payable( $carrier_id );
		if ( ! $state['ok'] ) {
			return self::fail( $state['message'] );
		}
		$old = (float) $state['carrier']['fee_amount'];
		$now = $this->stamp();

		if ( $fee <= 0 ) {
			foreach ( $state['members'] as $member ) {
				$this->move(
					$member,
					'waiting',
					array(
						'fee_amount' => 0.0,
						'fee_ref'    => null,
					),
					array( 'awaiting_payment' ),
					'fee',
					/* translators: %s: the old fee */
					sprintf( __( 'Fee of %s removed — on the list without a fee.', 'fishotel-misc-plugin' ), Rules::money( $old ) ),
					$actor
				);
			}
			$this->store->update( $carrier_id, array( 'fee_amount' => 0.0 ) );
			return self::ok( __( 'The fee was removed; the request is on the list.', 'fishotel-misc-plugin' ) );
		}

		$fees = $this->fees();
		if ( ! $fees['available'] ) {
			/* translators: %s: why */
			return self::fail( sprintf( __( 'A fee cannot be charged right now: %s.', 'fishotel-misc-plugin' ), $fees['reason'] ) );
		}

		$this->store->update(
			$carrier_id,
			array(
				'fee_amount'  => $fee,
				'reminded_at' => null,
			)
		);
		foreach ( $state['members'] as $member ) {
			$this->store->update(
				(int) $member['id'],
				array(
					'approved_at' => $now,
					'reminded_at' => null,
				),
				array( 'awaiting_payment' )
			);
		}
		/* translators: 1: old fee, 2: new fee */
		$this->log( $carrier_id, 'fee', sprintf( __( 'Fee changed from %1$s to %2$s; the old pay link no longer works.', 'fishotel-misc-plugin' ), Rules::money( $old ), Rules::money( $fee ) ), $actor );

		$this->last_mail = $this->send_invoice( $carrier_id );

		/* translators: %s: amount */
		return self::ok( sprintf( __( 'The fee is now %s.', 'fishotel-misc-plugin' ), Rules::money( $fee ) ) . ' ' . self::mail_words( $this->last_mail, __( 'The invoice', 'fishotel-misc-plugin' ), true ) );
	}

	/**
	 * An invoice was paid: the carrier keeps the payment, and every
	 * request on it that waited (or had just run out of time) goes on the
	 * list. Safe to call twice — only the first call does anything.
	 *
	 * @param int   $carrier_id The request that carries the invoice.
	 * @param array $how        order_id, amount (what was paid), credit (what Batch Manager credits:
	 *                          the line before tax), user_id (the customer of the order), wallet
	 *                          (true = credited, false = NOT credited, null = not known — paid by
	 *                          hand), actor.
	 * @return array{ok: bool, message: string, ids?: int[]}
	 */
	public function paid( $carrier_id, array $how = array() ) {
		$carrier_id = (int) $carrier_id;
		$carrier    = $this->store->get( $carrier_id );
		if ( ! $carrier || (int) $carrier['fee_ref'] !== $carrier_id ) {
			return self::fail( __( 'That request carries no invoice.', 'fishotel-misc-plugin' ) );
		}
		if ( ! empty( $carrier['fee_paid_at'] ) ) {
			return self::ok( __( 'That fee was already recorded as paid.', 'fishotel-misc-plugin' ), array( 'ids' => array() ) );
		}

		$actor    = isset( $how['actor'] ) && is_array( $how['actor'] ) ? $how['actor'] : self::system();
		$order_id = max( 0, (int) ( $how['order_id'] ?? 0 ) );
		$user_id  = max( 0, (int) ( $how['user_id'] ?? 0 ) );
		$now      = $this->stamp();

		// Claim the payment: only one caller gets past this line.
		$claimed = $this->store->update(
			$carrier_id,
			array(
				'fee_paid_at'  => $now,
				'fee_order_id' => $order_id > 0 ? $order_id : null,
			),
			array(),
			'fee_paid_at'
		);
		if ( ! $claimed ) {
			return self::ok( __( 'That fee was already recorded as paid.', 'fishotel-misc-plugin' ), array( 'ids' => array() ) );
		}

		$amount = isset( $how['amount'] ) ? (float) $how['amount'] : (float) $carrier['fee_amount'];
		if ( $order_id > 0 ) {
			/* translators: 1: amount, 2: order number */
			$note = sprintf( __( 'Fee of %1$s paid with order #%2$d.', 'fishotel-misc-plugin' ), Rules::money( $amount ), $order_id );
			if ( true === ( $how['wallet'] ?? null ) ) {
				$note .= ' ' . __( 'The wallet of the customer was credited by Batch Manager.', 'fishotel-misc-plugin' );
				if ( isset( $how['credit'] ) && (float) $how['credit'] + 0.005 < $amount ) {
					/* translators: %s: amount */
					$note .= ' ' . sprintf( __( 'It credits the line before tax: %s.', 'fishotel-misc-plugin' ), Rules::money( (float) $how['credit'] ) );
				}
			} elseif ( false === ( $how['wallet'] ?? null ) ) {
				$note .= ' ' . __( 'Batch Manager did NOT credit a wallet for this order — check the customer\'s wallet.', 'fishotel-misc-plugin' );
			}
		} else {
			/* translators: %s: amount */
			$note = sprintf( __( 'Fee of %s marked as paid by hand — nothing was added to a wallet from here.', 'fishotel-misc-plugin' ), Rules::money( $amount ) );
		}
		$this->log( $carrier_id, 'paid', $note, $actor );

		$released = array();
		foreach ( $this->invoice_members( $carrier_id ) as $member ) {
			if ( ! in_array( $member['state'], array( 'awaiting_payment', 'expired' ), true ) ) {
				continue;
			}
			$fields = array(
				'closed_at' => null,
				'kept_at'   => $now,
			);
			if ( (int) $member['user_id'] <= 0 && $user_id > 0 ) {
				$fields['user_id'] = $user_id;
			}
			$late = 'expired' === $member['state'];
			$said = (int) $member['id'] === $carrier_id
				? __( 'Paid — on the list.', 'fishotel-misc-plugin' )
				/* translators: %d: request id */
				: sprintf( __( 'Covered by the fee of request #%d — on the list.', 'fishotel-misc-plugin' ), $carrier_id );
			if ( $late ) {
				$said .= ' ' . __( '(It had run out of time; the payment brought it back.)', 'fishotel-misc-plugin' );
			}
			if ( $this->move( $member, 'waiting', $fields, array( $member['state'] ), 'on_list', $said, $actor ) ) {
				$released[] = (int) $member['id'];
			}
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'fishotel_requests_fee_paid', $carrier_id, $order_id, $amount );
		}

		return self::ok( __( 'The fee is recorded as paid; the request is on the list.', 'fishotel-misc-plugin' ), array( 'ids' => $released ) );
	}

	/* ============================================================== */
	/*  Cancel / reopen / fill                                         */
	/* ============================================================== */

	/**
	 * Cancel an open request.
	 *
	 * @param int    $id          Request id.
	 * @param array  $actor       Who.
	 * @param string $note        Why (optional).
	 * @param bool   $by_customer Whether the person who asked is cancelling (an owed request is
	 *                            then refused: the shop settles that one).
	 * @return array{ok: bool, message: string}
	 */
	public function cancel( $id, array $actor, $note = '', $by_customer = false ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || ! Rules::is_open( $request['state'] ) ) {
			return self::fail( __( 'That request is already closed.', 'fishotel-misc-plugin' ) );
		}
		if ( $by_customer && ! Rules::can_customer_cancel( $request ) ) {
			return self::fail( __( 'This one is already paid for. To change or cancel it, please contact us.', 'fishotel-misc-plugin' ) );
		}

		$note = Rules::lines( $note, Rules::MAX_NOTE );
		$said = $by_customer ? __( 'Cancelled by the person who asked.', 'fishotel-misc-plugin' ) : __( 'Cancelled.', 'fishotel-misc-plugin' );
		if ( '' !== $note ) {
			$said .= ' ' . $note;
		}

		$fields = array(
			'closed_at'   => $this->stamp(),
			'line_id'     => null,
			'cr_order_id' => null,
			'stay_id'     => null,
			'link_manual' => 0,
		);
		if ( ! $this->move( $request, 'cancelled', $fields, Rules::OPEN_STATES, 'cancelled', $said, $actor ) ) {
			return self::fail( __( 'That request is already closed.', 'fishotel-misc-plugin' ) );
		}

		return self::ok( __( 'The request is cancelled.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Put a closed request back on the list (a mistake, a change of mind).
	 * A fee that was never paid is dropped — unless its invoice is still
	 * open for other requests of the sheet: then the request goes back on
	 * that invoice and waits for the payment with them (dropping it there
	 * would leave the others on an invoice nobody can pay, resend or change).
	 *
	 * @param int   $id    Request id.
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function reopen( $id, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || Rules::is_open( $request['state'] ) ) {
			return self::fail( __( 'Only a closed request can be reopened.', 'fishotel-misc-plugin' ) );
		}

		$now    = $this->stamp();
		$fields = array(
			'closed_at'   => null,
			'asked_at'    => null,
			'kept_at'     => $now,
			'approved_at' => ! empty( $request['approved_at'] ) ? $request['approved_at'] : $now,
			'line_id'     => null,
			'cr_order_id' => null,
			'stay_id'     => null,
			'link_manual' => 0,
		);
		$to   = 'waiting';
		$said = __( 'Reopened — back on the list.', 'fishotel-misc-plugin' );
		$done = __( 'The request is back on the list.', 'fishotel-misc-plugin' );
		if ( (int) $request['fee_ref'] > 0 ) {
			$invoice = $this->payable( (int) $request['fee_ref'] );
			if ( $invoice['ok'] ) {
				$to                    = 'awaiting_payment';
				$fields['kept_at']     = null;
				$fields['approved_at'] = $invoice['members'][0]['approved_at'];
				/* translators: %d: request id */
				$said = sprintf( __( 'Reopened — back on the invoice of request #%d, which is not paid yet: it waits for the payment with the others.', 'fishotel-misc-plugin' ), (int) $request['fee_ref'] );
				$done = __( 'The request is back, on its invoice: it waits for the payment with the others on it.', 'fishotel-misc-plugin' );
			} elseif ( ! $invoice['carrier'] || empty( $invoice['carrier']['fee_paid_at'] ) ) {
				$fields['fee_ref']    = null;
				$fields['fee_amount'] = 0.0;
			}
		}

		if ( ! $this->move( $request, $to, $fields, Rules::CLOSED_STATES, 'reopened', $said, $actor ) ) {
			return self::fail( __( 'That request could not be reopened.', 'fishotel-misc-plugin' ) );
		}

		return self::ok( $done );
	}

	/**
	 * The request is done: the person has the fish.
	 *
	 * @param int    $id    Request id.
	 * @param array  $actor Who.
	 * @param string $note  How (optional).
	 * @return array{ok: bool, message: string}
	 */
	public function fill( $id, array $actor, $note = '' ) {
		$request = $this->store->get( (int) $id );
		$from    = array( 'waiting', 'on_order', 'in_quarantine', 'ready', 'offered' );
		if ( ! $request || ! in_array( $request['state'], $from, true ) ) {
			return self::fail( __( 'Only a request that is on the list can be marked as filled.', 'fishotel-misc-plugin' ) );
		}

		$note = Rules::text( $note, 300 );
		$said = '' !== $note ? $note : __( 'Marked as filled.', 'fishotel-misc-plugin' );
		if ( ! $this->move( $request, 'filled', array( 'closed_at' => $this->stamp() ), $from, 'filled', $said, $actor ) ) {
			return self::fail( __( 'That request was changed by somebody else in the meantime.', 'fishotel-misc-plugin' ) );
		}

		return self::ok( __( 'The request is filled.', 'fishotel-misc-plugin' ) );
	}

	/* ============================================================== */
	/*  Following the fish                                             */
	/* ============================================================== */

	/**
	 * Match a request to a supplier order line.
	 *
	 * @param int   $id     Request id.
	 * @param array $line   id, order_id, and for the log: ref, species.
	 * @param bool  $manual Whether the owner did it (the job never undoes that for room alone).
	 * @param array $actor  Who.
	 * @return array{ok: bool, message: string}
	 */
	public function link( $id, array $line, $manual, array $actor ) {
		$request = $this->store->get( (int) $id );
		$from    = array( 'waiting', 'on_order', 'in_quarantine', 'ready' );
		if ( ! $request || ! in_array( $request['state'], $from, true ) ) {
			return self::fail( __( 'Only a request that is on the list can be matched to a supplier order.', 'fishotel-misc-plugin' ) );
		}
		if ( (int) ( $line['id'] ?? 0 ) <= 0 ) {
			return self::fail( __( 'That supplier order line does not exist.', 'fishotel-misc-plugin' ) );
		}

		$fields = array(
			'line_id'     => (int) $line['id'],
			'cr_order_id' => ! empty( $line['order_id'] ) ? (int) $line['order_id'] : null,
			'stay_id'     => null,
			'link_manual' => $manual ? 1 : 0,
			'asked_at'    => null,
		);
		$said = sprintf(
			/* translators: 1: order ref, 2: species on the line */
			$manual ? __( 'Matched by hand to supplier order %1$s (%2$s).', 'fishotel-misc-plugin' ) : __( 'Supplier order %1$s carries it (%2$s) — matched by itself.', 'fishotel-misc-plugin' ),
			(string) ( $line['ref'] ?? '#' . (int) ( $line['order_id'] ?? 0 ) ),
			(string) ( $line['species'] ?? '' )
		);

		if ( ! $this->move( $request, 'on_order', $fields, $from, 'on_order', $said, $actor ) ) {
			return self::fail( __( 'That request was changed by somebody else in the meantime.', 'fishotel-misc-plugin' ) );
		}

		return self::ok( __( 'The request is matched to that supplier order line.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Take a request off its supplier order line: back to "waiting".
	 *
	 * @param int    $id     Request id.
	 * @param bool   $manual Whether the owner did it — the job then leaves the request alone
	 *                       until "match by itself" is switched back on.
	 * @param array  $actor  Who.
	 * @param string $why    In words.
	 * @return array{ok: bool, message: string}
	 */
	public function unlink( $id, $manual, array $actor, $why = '' ) {
		$request = $this->store->get( (int) $id );
		$from    = array( 'on_order', 'in_quarantine', 'ready' );
		if ( ! $request || ! in_array( $request['state'], $from, true ) ) {
			return self::fail( __( 'That request is not matched to a supplier order.', 'fishotel-misc-plugin' ) );
		}

		$fields = array(
			'line_id'     => null,
			'cr_order_id' => null,
			'stay_id'     => null,
			'link_manual' => 0,
		);
		if ( $manual ) {
			$fields['auto_match'] = 0;
		}
		$said = '' !== (string) $why ? (string) $why : __( 'Taken off its supplier order.', 'fishotel-misc-plugin' );
		if ( $manual ) {
			$said .= ' ' . __( 'Matching by itself is off for this request until it is switched back on.', 'fishotel-misc-plugin' );
		}

		if ( ! $this->move( $request, 'waiting', $fields, $from, 'waiting', $said, $actor ) ) {
			return self::fail( __( 'That request was changed by somebody else in the meantime.', 'fishotel-misc-plugin' ) );
		}

		return self::ok( __( 'The request is back to waiting.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Let the daily job match a request by itself again (or not).
	 *
	 * @param int   $id    Request id.
	 * @param bool  $on    On?
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function set_auto_match( $id, $on, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request ) {
			return self::fail( __( 'That request does not exist.', 'fishotel-misc-plugin' ) );
		}
		$this->store->update( (int) $id, array( 'auto_match' => $on ? 1 : 0 ) );
		$this->log( $id, 'note', $on ? __( 'Matching by itself switched back on.', 'fishotel-misc-plugin' ) : __( 'Matching by itself switched off.', 'fishotel-misc-plugin' ), $actor );

		return self::ok( $on ? __( 'The request is matched by itself again.', 'fishotel-misc-plugin' ) : __( 'The request is no longer matched by itself.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Apply one step the follower planned (Follow::plan()).
	 *
	 * @param array $request The request as the planner read it.
	 * @param array $step    to, line_id, order_id, stay_id, product_id, why, quiet.
	 * @return bool
	 */
	public function follow( array $request, array $step ) {
		$to = (string) ( $step['to'] ?? '' );
		if ( ! in_array( $to, array( 'waiting', 'on_order', 'in_quarantine', 'ready' ), true ) ) {
			return false;
		}

		if ( 'waiting' === $to ) {
			$fields = array(
				'line_id'     => null,
				'cr_order_id' => null,
				'stay_id'     => null,
				'link_manual' => 0,
				'offered_at'  => null,
			);
		} else {
			$fields = array(
				'line_id'     => ! empty( $step['line_id'] ) ? (int) $step['line_id'] : null,
				'cr_order_id' => ! empty( $step['order_id'] ) ? (int) $step['order_id'] : null,
				'stay_id'     => ! empty( $step['stay_id'] ) ? (int) $step['stay_id'] : null,
				'asked_at'    => null,
			);
			if ( 'ready' !== $to ) {
				$fields['offered_at'] = null;
			}
			if ( empty( $request['product_id'] ) && ! empty( $step['product_id'] ) ) {
				$fields['product_id'] = (int) $step['product_id'];
			}
		}

		if ( ! empty( $step['quiet'] ) ) {
			// Same state, same line — only the tank group moved: no log line, no hook.
			return $this->store->update( (int) $request['id'], $fields, array( (string) $request['state'] ) );
		}

		return $this->move( $request, $to, $fields, array( (string) $request['state'] ), $to, (string) ( $step['why'] ?? '' ), self::system() );
	}

	/**
	 * Why the "ready" e-mail of a request cannot go out ('' when it can):
	 * the e-mail is switched off, or the request has no address.
	 *
	 * @param array $request The request.
	 * @return string off|no_email|''.
	 */
	public function offer_blocker( array $request ) {
		if ( ! $this->mailer->is_on( 'ready' ) ) {
			return 'off';
		}
		return '' === Rules::email( $request['email'] ?? '' ) ? 'no_email' : '';
	}

	/**
	 * Tell the person their fish is ready: the e-mail goes out and the
	 * request becomes "offered". The state is claimed BEFORE the e-mail is
	 * sent, so two runs never send it twice. When the e-mail cannot go out
	 * the request stays "ready" and waits under "Needs you"; a failed send
	 * leaves its time in offered_at, so the daily job does not try again
	 * every day — the owner's click does.
	 *
	 * @param int   $id    Request id.
	 * @param array $actor Who (the system = by itself).
	 * @return array{ok: bool, message: string, mail: string}
	 */
	public function offer( $id, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || 'ready' !== $request['state'] ) {
			return self::fail( __( 'Only a request whose fish is ready can be offered.', 'fishotel-misc-plugin' ), array( 'mail' => '' ) );
		}

		$what    = __( 'The "ready" e-mail', 'fishotel-misc-plugin' );
		$instead = __( 'Tell them yourself, then click "Told them".', 'fishotel-misc-plugin' );
		$blocker = $this->offer_blocker( $request );
		if ( '' !== $blocker ) {
			return self::fail( self::mail_words( $blocker, $what, false ) . ' ' . $instead, array( 'mail' => $blocker ) );
		}

		$by_itself = 'system' === (string) ( $actor['name'] ?? '' );
		$said      = $by_itself ? __( 'Told by itself that the fish is ready.', 'fishotel-misc-plugin' ) : __( 'Told that the fish is ready.', 'fishotel-misc-plugin' );
		$now       = $this->stamp();

		if ( ! $this->move( $request, 'offered', array( 'offered_at' => $now ), array( 'ready' ), 'offered', $said, $actor ) ) {
			return self::fail( __( 'That request was changed by somebody else in the meantime.', 'fishotel-misc-plugin' ), array( 'mail' => '' ) );
		}

		$this->last_mail = $this->mailer->send( 'ready', $request, array( 'product_url' => $this->product_url( $request ) ) );
		if ( 'sent' === $this->last_mail ) {
			return self::ok( __( 'The "ready" e-mail is on its way.', 'fishotel-misc-plugin' ), array( 'mail' => 'sent' ) );
		}

		// Not told after all: back to "ready", where "Needs you" shows it.
		$request['state'] = 'offered';
		$words            = self::mail_words( $this->last_mail, $what, false ) . ' ' . $instead;
		$this->move( $request, 'ready', array( 'offered_at' => $now ), array( 'offered' ), 'ready', $words, self::system() );

		return self::fail( $words, array( 'mail' => $this->last_mail ) );
	}

	/**
	 * The owner told the person some other way: "offered" without an e-mail.
	 *
	 * @param int   $id    Request id.
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function told( $id, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || 'ready' !== $request['state'] ) {
			return self::fail( __( 'Only a request whose fish is ready can be marked as told.', 'fishotel-misc-plugin' ) );
		}
		if ( ! $this->move( $request, 'offered', array( 'offered_at' => $this->stamp() ), array( 'ready' ), 'offered', __( 'Told in person (no e-mail from here).', 'fishotel-misc-plugin' ), $actor ) ) {
			return self::fail( __( 'That request was changed by somebody else in the meantime.', 'fishotel-misc-plugin' ) );
		}

		return self::ok( __( 'Marked as told.', 'fishotel-misc-plugin' ) );
	}

	/* ============================================================== */
	/*  "Still want it?"                                               */
	/* ============================================================== */

	/**
	 * The person said "keep it": the clock starts again.
	 *
	 * @param int   $id    Request id.
	 * @param array $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function keep( $id, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || ! Rules::is_open( $request['state'] ) ) {
			return self::fail( __( 'That request is already closed. You are welcome to ask again.', 'fishotel-misc-plugin' ) );
		}
		$this->store->update(
			(int) $id,
			array(
				'kept_at'  => $this->stamp(),
				'asked_at' => null,
			)
		);
		$this->log( $id, 'kept', __( 'Still wanted — the person said "keep it".', 'fishotel-misc-plugin' ), $actor );

		return self::ok( __( 'Thank you — your request stays on our list.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Ask "still want it?".
	 *
	 * @param int $id Request id.
	 * @return string sent|off|no_email|failed|skipped.
	 */
	public function ask( $id ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || 'waiting' !== $request['state'] ) {
			return 'skipped';
		}
		$status = $this->mailer->send( 'still_want', $request, array( 'days' => Rules::ASK_ANSWER_DAYS ) );
		if ( 'sent' === $status ) {
			$this->store->update( (int) $id, array( 'asked_at' => $this->stamp() ), array( 'waiting' ) );
			/* translators: %d: days */
			$this->log( $id, 'asked', sprintf( __( 'Asked "still want it?" — closes by itself without an answer in %d days.', 'fishotel-misc-plugin' ), Rules::ASK_ANSWER_DAYS ), self::system() );
		}
		return $status;
	}

	/**
	 * Close a request that ran out of time.
	 *
	 * @param int    $id  Request id.
	 * @param string $why In words.
	 * @return bool
	 */
	public function expire( $id, $why ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request || ! in_array( $request['state'], array( 'awaiting_payment', 'waiting' ), true ) ) {
			return false;
		}
		return $this->move(
			$request,
			'expired',
			array(
				'closed_at'   => $this->stamp(),
				'line_id'     => null,
				'cr_order_id' => null,
				'stay_id'     => null,
			),
			array( $request['state'] ),
			'expired',
			(string) $why,
			self::system()
		);
	}

	/**
	 * The timers of the daily job: the one fee reminder, the fee expiry,
	 * the "still want it?" e-mail and its expiry.
	 *
	 * @return array{reminded: int, expired: int, asked: int, ask_expired: int}
	 */
	public function timers() {
		$now    = $this->now();
		$remind = (int) ( $this->settings['reminder_days'] ?? 3 );
		$expire = (int) ( $this->settings['expire_days'] ?? 7 );
		$months = (int) ( $this->settings['ask_months'] ?? 4 );
		$out    = array(
			'reminded'    => 0,
			'expired'     => 0,
			'asked'       => 0,
			'ask_expired' => 0,
		);

		$invoices = array(); // carrier id → the requests that still wait for its payment.
		foreach ( $this->store->find( array( 'state' => 'awaiting_payment' ) ) as $request ) {
			if ( Rules::fee_expired( $request, $now, $expire ) ) {
				/* translators: %d: days */
				if ( $this->expire( (int) $request['id'], sprintf( __( 'The fee was not paid within %d days.', 'fishotel-misc-plugin' ), $expire ) ) ) {
					$out['expired']++;
				}
				continue;
			}
			$invoices[ (int) $request['fee_ref'] ][ (int) $request['id'] ] = $request;
		}

		// ONE reminder per invoice — also when the request that carries it was
		// cancelled meanwhile and only the others on its sheet still wait.
		foreach ( $invoices as $carrier_id => $members ) {
			$carrier = isset( $members[ $carrier_id ] ) ? $members[ $carrier_id ] : $this->store->get( $carrier_id );
			if ( ! $carrier ) {
				continue;
			}
			// The clock is the one of a request that still waits.
			$first                  = reset( $members );
			$carrier['approved_at'] = $first['approved_at'];
			if ( Rules::reminder_due( $carrier, $now, $remind ) && 'sent' === $this->send_invoice( $carrier_id, 'reminder' ) ) {
				$this->store->update( $carrier_id, array( 'reminded_at' => $this->stamp() ) );
				$out['reminded']++;
			}
		}

		// Switched off, nobody is asked and nothing expires.
		if ( $this->mailer->is_on( 'still_want' ) ) {
			foreach ( $this->store->find( array( 'state' => 'waiting' ) ) as $request ) {
				if ( Rules::ask_expired( $request, $now ) ) {
					/* translators: %d: days */
					if ( $this->expire( (int) $request['id'], sprintf( __( 'No answer to "still want it?" within %d days.', 'fishotel-misc-plugin' ), Rules::ASK_ANSWER_DAYS ) ) ) {
						$out['ask_expired']++;
					}
					continue;
				}
				if ( Rules::ask_due( $request, $now, $months ) && 'sent' === $this->ask( (int) $request['id'] ) ) {
					$out['asked']++;
				}
			}
		}

		return $out;
	}

	/* ============================================================== */
	/*  Edit / note                                                    */
	/* ============================================================== */

	/**
	 * Correct a request: who, the fish, the size, the quantity, the note,
	 * where it came from.
	 *
	 * @param int          $id    Request id.
	 * @param array        $raw   name, email, handle, user_id, fish, size_slug, size_label, qty, note, came_from.
	 * @param array[]|null $sizes The standard sizes, or null (free text).
	 * @param array        $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function edit( $id, array $raw, $sizes, array $actor ) {
		$request = $this->store->get( (int) $id );
		if ( ! $request ) {
			return self::fail( __( 'That request does not exist.', 'fishotel-misc-plugin' ) );
		}

		// A page that was opened before somebody else's correction (another tab,
		// another person) must not put the old values back.
		if ( isset( $raw['seen'] ) && is_string( $raw['seen'] ) && '' !== $raw['seen'] && ! hash_equals( Rules::seen( $request ), $raw['seen'] ) ) {
			return self::fail( __( 'This request was corrected somewhere else after this page was opened. Nothing was saved — the page now shows it as it is; make your change again.', 'fishotel-misc-plugin' ) );
		}

		$person = Rules::person( $raw );
		$errors = Rules::person_errors( $person, false );
		$fish   = Rules::fish_row( $raw, is_array( $sizes ) ? $sizes : null, $request );
		$errors = array_merge( $errors, $fish['errors'] );
		if ( Rules::fee_open( $request ) && '' === $person['email'] ) {
			$errors[] = __( 'A request that waits for a payment needs an e-mail address.', 'fishotel-misc-plugin' );
		}
		if ( $errors ) {
			return self::fail( implode( ' ', $errors ) );
		}

		$row     = $fish['row'];
		$user_id = array_key_exists( 'user_id', $raw ) ? max( 0, (int) $raw['user_id'] ) : (int) $request['user_id'];
		if ( $user_id > 0 && ! $this->user( $user_id ) ) {
			return self::fail( __( 'That site customer does not exist.', 'fishotel-misc-plugin' ) );
		}
		if ( $user_id > 0 && ! array_key_exists( 'user_id', $raw ) && '' !== $person['email'] && $person['email'] !== Rules::email( $request['email'] ) ) {
			// The address was changed: the request follows it. Left on the old
			// account, the invoice would go to one person and only the other could pay it.
			$owner = $this->user( $user_id );
			if ( ! $owner || Rules::email( $owner['email'] ) !== $person['email'] ) {
				$user_id = 0;
			}
		}
		if ( 0 === $user_id && '' !== $person['email'] ) {
			$user_id = $this->user_by_email( $person['email'] );
		}

		// A new name is matched afresh; the same name keeps what it had.
		if ( Rules::name_key( $row['fish'] ) === Rules::name_key( $request['fish'] ) ) {
			$row['species_id'] = $request['species_id'];
			$row['product_id'] = $request['product_id'];
		} else {
			$row = array_merge( $row, $this->match_fish( $row['fish'] ) );
		}

		$list   = isset( $this->settings['came_from'] ) && is_array( $this->settings['came_from'] ) ? $this->settings['came_from'] : array();
		$fields = array(
			'user_id'    => $user_id,
			'name'       => $person['name'],
			'email'      => $person['email'],
			'handle'     => $person['handle'],
			'fish'       => $row['fish'],
			'fish_key'   => Rules::fish_key( $row ),
			'species_id' => $row['species_id'],
			'product_id' => $row['product_id'],
			'size_slug'  => $row['size_slug'],
			'size_label' => $row['size_label'],
			'qty'        => $row['qty'],
			'note'       => $row['note'],
			'came_from'  => array_key_exists( 'came_from', $raw ) ? Settings::came_from( $raw['came_from'], $list ) : $request['came_from'],
		);

		$changed = array();
		foreach ( $fields as $key => $value ) {
			if ( (string) $value !== (string) $request[ $key ] ) {
				$changed[] = $key;
			}
		}
		if ( ! $changed ) {
			return self::ok( __( 'Nothing changed.', 'fishotel-misc-plugin' ) );
		}

		if ( ! $this->store->update( (int) $id, $fields ) ) {
			return self::fail( __( 'The request could not be saved.', 'fishotel-misc-plugin' ) );
		}
		/* translators: %s: column names */
		$said = sprintf( __( 'Edited: %s.', 'fishotel-misc-plugin' ), implode( ', ', $changed ) );
		$done = __( 'The request is saved.', 'fishotel-misc-plugin' );
		if ( in_array( 'user_id', $changed, true ) ) {
			$moved = $user_id > 0
				/* translators: %d: user id */
				? sprintf( __( 'It now belongs to site customer #%d (the account of its e-mail address).', 'fishotel-misc-plugin' ), $user_id )
				: __( 'It no longer belongs to a site account (its e-mail address is not a site customer\'s).', 'fishotel-misc-plugin' );
			$said .= ' ' . $moved;
			$done .= ' ' . $moved;
		}
		$this->log( $id, 'edited', $said, $actor );

		return self::ok( $done );
	}

	/**
	 * Add a note to a request's history.
	 *
	 * @param int    $id    Request id.
	 * @param string $text  The note.
	 * @param array  $actor Who.
	 * @return array{ok: bool, message: string}
	 */
	public function note( $id, $text, array $actor ) {
		$text = Rules::lines( $text, Rules::MAX_NOTE );
		if ( '' === $text || ! $this->store->get( (int) $id ) ) {
			return self::fail( __( 'Nothing to note.', 'fishotel-misc-plugin' ) );
		}
		$this->log( $id, 'note', $text, $actor );

		return self::ok( __( 'Noted.', 'fishotel-misc-plugin' ) );
	}

	/* ============================================================== */
	/*  Words                                                          */
	/* ============================================================== */

	private static function fee_words() {
		/* translators: %s: the largest fee */
		return sprintf( __( 'The fee must be an amount from 0 to %s.', 'fishotel-misc-plugin' ), number_format( Rules::MAX_FEE, 2 ) );
	}

	/**
	 * What became of an e-mail, in words.
	 *
	 * @param string $status   sent|off|no_email|failed.
	 * @param string $what     "The invoice", "The e-mail", …
	 * @param bool   $pay_link Whether the request shows a pay link the owner can copy.
	 * @return string
	 */
	public static function mail_words( $status, $what, $pay_link ) {
		$hint = $pay_link ? ' ' . __( 'The pay link is on the request, to copy and send yourself.', 'fishotel-misc-plugin' ) : '';

		switch ( (string) $status ) {
			case 'sent':
				/* translators: %s: which e-mail */
				return sprintf( __( '%s was e-mailed.', 'fishotel-misc-plugin' ), $what );
			case 'off':
				/* translators: %s: which e-mail */
				return sprintf( __( '%s was NOT e-mailed: that e-mail is switched off in Settings.', 'fishotel-misc-plugin' ), $what ) . $hint;
			case 'no_email':
				/* translators: %s: which e-mail */
				return sprintf( __( '%s was NOT e-mailed: the request has no e-mail address.', 'fishotel-misc-plugin' ), $what ) . $hint;
		}
		/* translators: %s: which e-mail */
		return sprintf( __( '%s could NOT be e-mailed (the site refused to send it).', 'fishotel-misc-plugin' ), $what ) . $hint;
	}
}
