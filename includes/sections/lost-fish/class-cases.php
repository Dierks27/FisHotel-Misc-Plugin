<?php
/**
 * Lost Fish cases — the life of a case, from the loss to Done.
 *
 *   A LOSS is recorded on a tank group (the Clean Room fires
 *   fishotel_cleanroom_event_recorded). The cover is worked out again for
 *   that kind of fish (Cover): when the fish still alive cover everything
 *   paid customers are owed, a log line says the fish was replaced from
 *   the extra and that is all. Otherwise each holder who is now short —
 *   the most recently paid first — gets ONE case for the fish that line
 *   is short. A loss that came from a workbook import (history, not news)
 *   and a group nobody has identified yet open nothing.
 *
 *   The UNDO of that loss (the Clean Room's compensating event) closes
 *   the cases it opened that were not sent yet; a case already sent is
 *   only flagged, for the owner to sort out. Whatever loss was undone, the
 *   kind is then worked out again: unsent cases a loss opened that the
 *   tanks now cover close (or speak for fewer fish) too.
 *
 *   A case is OPEN until the owner clicks "Send to customer" — or, with
 *   the setting switched on, until the undo window has passed and
 *   everything about it is certain. Sending freezes the numbers and makes
 *   the links; from then on the case shows exactly what the e-mail said.
 *
 *   What blocks a send: a price or a shipping difference that is not
 *   known, no address, an order that is closed, gone or was never paid, a
 *   line that was refunded in the meantime, and — so the difference is
 *   never withheld twice — another case of the same order that is with
 *   the customer while this one's difference was (or would have to be)
 *   typed by hand.
 *
 *   SENT → ANSWERED happens once: by the customer's Confirm on the page
 *   behind a link — the order is read again first: a closed one records
 *   nothing, and "cancel" is gone once part of it shipped — or by the
 *   owner recording the answer by hand. After
 *   the answer the case lists what the owner has to do — this class moves
 *   no money, changes no stock and cancels nothing. The one thing it does
 *   itself is hand a "try again" to the Requests section
 *   (fishotel_requests_create()) when that section is there.
 *
 *   ANSWERED → DONE is the owner's tick. Any case can be reopened: the
 *   answer is forgotten, the old links die, the numbers are fresh again —
 *   and a case reopened after an answer says what had been chosen.
 *
 * Every stage writes a private note on the WooCommerce order and a line
 * in the section's log. Every status change goes through
 * Store::transition(), so doing something twice does it once.
 *
 * The store, the orders and the rooms are handed in, so the tests run the
 * real class against an in-memory store and stand-in orders.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cases
 */
class Cases {

	/**
	 * The note of a Clean Room compensating event: 'undo of #<id>'.
	 *
	 * @var string
	 */
	const NOTE_UNDO = 'undo of #';

	/**
	 * Why a case can be closed without an answer.
	 *
	 * @var string[]
	 */
	const CLOSE_REASONS = array( 'mistake', 'settled', 'loss_undone', 'order_cancelled', 'other' );

	/**
	 * How long after the undo window a "send by itself" is scheduled
	 * (seconds).
	 *
	 * @var int
	 */
	const AUTO_SEND_MARGIN = 60;

	/**
	 * The room for the list of losses behind one case (the column's width).
	 *
	 * @var int
	 */
	const LOSS_LIST_MAX = 255;

	/**
	 * The store.
	 *
	 * @var object
	 */
	private $store;

	/**
	 * The orders.
	 *
	 * @var Orders
	 */
	private $orders;

	/**
	 * The rooms.
	 *
	 * @var object
	 */
	private $rooms;

	/**
	 * The shared instance.
	 *
	 * @var Cases|null
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * @param object|null $store  Store (or a stand-in).
	 * @param object|null $orders Orders (or a stand-in).
	 * @param object|null $rooms  Rooms (or a stand-in).
	 */
	public function __construct( $store = null, $orders = null, $rooms = null ) {
		$this->store  = $store ? $store : new Store();
		$this->orders = $orders ? $orders : new Orders( $this->store );
		$this->rooms  = $rooms ? $rooms : new Rooms();
	}

	/**
	 * The instance the hooks use.
	 *
	 * @return Cases
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Replace the shared instance (tests).
	 *
	 * @param Cases|null $cases The instance, or null to forget it.
	 */
	public static function set_instance( $cases ) {
		self::$instance = $cases;
	}

	public function store() {
		return $this->store;
	}

	public function orders() {
		return $this->orders;
	}

	public function rooms() {
		return $this->rooms;
	}

	/* ============================================================== */
	/*  Hooks                                                          */
	/* ============================================================== */

	/**
	 * fishotel_cleanroom_event_recorded: a loss opens cases, its undo
	 * closes them. Nothing thrown here may reach the Clean Room — the
	 * event is already written.
	 *
	 * @param int   $stay_id  The tank group.
	 * @param int   $event_id The event just written.
	 * @param array $event    type, qty, note, source, actor_id.
	 * @param array $state    The group's state after it (unused).
	 */
	public static function on_cleanroom_event( $stay_id, $event_id = 0, $event = array(), $state = array() ) {
		unset( $state );

		try {
			$event = is_array( $event ) ? $event : array();
			if ( 'loss' !== (string) ( $event['type'] ?? '' ) || 'import' === (string) ( $event['source'] ?? '' ) ) {
				return;
			}
			if ( ! Store::installed() ) {
				return;
			}

			$qty   = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : 1;
			$cases = self::instance();

			if ( $qty > 0 ) {
				$cases->loss_recorded( (int) $stay_id, (int) $event_id, $qty, (int) ( $event['actor_id'] ?? 0 ) );
			} elseif ( $qty < 0 ) {
				// Fish are back in the tank — whichever loss this takes back, the kind is worked out again.
				$cases->loss_undone( self::undo_target( (string) ( $event['note'] ?? '' ) ), (int) ( $event['actor_id'] ?? 0 ), (int) $stay_id );
			}
		} catch ( \Throwable $e ) {
			self::trouble( 'on_cleanroom_event', $e );
		}
	}

	/**
	 * The event id an 'undo of #<id>' note points at.
	 *
	 * @param string $note The event's note.
	 * @return int 0 when the note is not an undo.
	 */
	public static function undo_target( $note ) {
		$note = (string) $note;
		if ( 0 !== strpos( $note, self::NOTE_UNDO ) ) {
			return 0;
		}
		$id = substr( $note, strlen( self::NOTE_UNDO ) );
		return ctype_digit( $id ) ? (int) $id : 0;
	}

	/**
	 * The cron tick: the one reminder, and — only with the setting on —
	 * the send after the undo window. With nothing waiting it reads two
	 * autoloaded options and returns.
	 */
	public static function tick() {
		try {
			if ( ! Store::installed() || ! Store::anything_live() ) {
				return;
			}
			self::instance()->run_tick( time() );
		} catch ( \Throwable $e ) {
			self::trouble( 'tick', $e );
		}
	}

	/**
	 * What a tick does at a moment.
	 *
	 * @param int $now UNIX timestamp.
	 * @return array{reminded: int[], sent: int[]}
	 */
	public function run_tick( $now ) {
		$settings = Settings::get();
		$out      = array(
			'reminded' => array(),
			'sent'     => array(),
		);

		if ( $settings['reminder_days'] > 0 ) {
			$before = gmdate( 'Y-m-d H:i:s', (int) $now - $settings['reminder_days'] * DAY_IN_SECONDS );
			foreach ( $this->store->cases_to_remind( $before ) as $case ) {
				if ( $this->remind( $case, (int) $now ) ) {
					$out['reminded'][] = (int) $case['id'];
				}
			}
		}

		if ( $settings['auto_send'] ) {
			$before = gmdate( 'Y-m-d H:i:s', (int) $now - $this->rooms->undo_window() );
			foreach ( $this->store->cases_to_autosend( $before ) as $case ) {
				$result = $this->send( (int) $case['id'], 0, array(), true );
				if ( $result['ok'] ) {
					$out['sent'][] = (int) $case['id'];
				}
			}
		}

		$this->store->refresh_counts();

		return $out;
	}

	/* ============================================================== */
	/*  A loss, and its undo                                           */
	/* ============================================================== */

	/**
	 * The cover question for one tank group's kind.
	 *
	 * @param array $stay The tank group.
	 * @return array Cover::assess() plus kind.
	 */
	public function assess_stay( array $stay ) {
		$title = (int) ( $stay['product_id'] ?? 0 ) > 0 ? $this->rooms->product_title( (int) $stay['product_id'] ) : '';
		$kind  = Cover::kind_of_stay( $stay, $title );
		$stays = $this->rooms->open_stays( $kind );

		$stay_ids = array( (int) ( $stay['id'] ?? 0 ) );
		foreach ( $stays as $other ) {
			if ( Cover::stay_matches( $kind, $other ) ) {
				$stay_ids[] = (int) ( $other['id'] ?? 0 );
			}
		}

		$holders   = $this->orders->holders( $kind, $stay_ids );
		$order_ids = array();
		foreach ( $holders as $holder ) {
			$order_ids[] = (int) $holder['order_id'];
		}
		$cases = $order_ids ? $this->store->cases( array( 'order_ids' => array_values( array_unique( $order_ids ) ) ) ) : array();

		return array_merge( Cover::assess( $kind, $stays, $holders, $cases ), array( 'kind' => $kind ) );
	}

	/**
	 * A loss was recorded on a tank group.
	 *
	 * @param int $stay_id  The tank group.
	 * @param int $event_id The loss event.
	 * @param int $qty      How many were lost.
	 * @param int $actor    Who recorded it.
	 * @return array{outcome: string, opened: int[], grown: int[], assess: array|null}
	 */
	public function loss_recorded( $stay_id, $event_id, $qty = 1, $actor = 0 ) {
		$report = array(
			'outcome' => 'skipped',
			'opened'  => array(),
			'grown'   => array(),
			'assess'  => null,
		);

		$stay = $this->rooms->stay( (int) $stay_id );
		if ( ! $stay || ! empty( $stay['needs_id'] ) || ! $this->orders->available() ) {
			return $report;
		}

		// One loss at a time per kind of fish: two phones in the same second must not both open a case
		// for the same order line.
		$lock = Cover::kind_key( (int) ( $stay['product_id'] ?? 0 ), (string) ( $stay['species'] ?? '' ), (string) ( $stay['size_slug'] ?? '' ) );
		$held = '' !== $lock && method_exists( $this->store, 'lock' ) && $this->store->lock( $lock );

		try {
			return $this->loss_assessed( $stay, (int) $event_id, (int) $qty, (int) $actor, $report );
		} finally {
			if ( $held ) {
				$this->store->unlock( $lock );
			}
		}
	}

	/**
	 * The work of loss_recorded(), under the kind's lock.
	 *
	 * @param array $stay     The tank group.
	 * @param int   $event_id The loss event.
	 * @param int   $qty      How many were lost.
	 * @param int   $actor    Who recorded it.
	 * @param array $report   The report so far.
	 * @return array{outcome: string, opened: int[], grown: int[], assess: array|null}
	 */
	private function loss_assessed( array $stay, $event_id, $qty, $actor, array $report ) {
		$assess           = $this->assess_stay( $stay );
		$report['assess'] = $assess;
		if ( method_exists( $this->orders, 'capped' ) && $this->orders->capped() ) {
			$this->log(
				'capped',
				sprintf(
					/* translators: %s: what was lost */
					__( 'Loss of %s: there are more open orders than one loss can read, so the oldest were NOT checked — somebody among them may be owed this fish. Cancel or trash old unpaid orders, and use "Open a case" if a customer is short.', 'fishotel-misc-plugin' ),
					max( 1, (int) $qty ) . ' × ' . self::kind_label( $assess['kind'] ) . self::tank_label( $stay )
				),
				0,
				0,
				$actor
			);
		}
		if ( '' === $assess['kind']['key'] || $assess['committed'] <= 0 ) {
			$report['outcome'] = 'nobody';
			return $report;
		}

		$what = max( 1, (int) $qty ) . ' × ' . self::kind_label( $assess['kind'] ) . self::tank_label( $stay );

		if ( $assess['new'] <= 0 ) {
			if ( $assess['shortage'] <= 0 ) {
				$report['outcome'] = 'covered';
				$this->log(
					'covered',
					sprintf(
						/* translators: 1: what was lost, 2: fish alive, 3: fish owed */
						__( 'Loss of %1$s: %2$d still alive for %3$d owed to customers — replaced from the extra, nobody is written to.', 'fishotel-misc-plugin' ),
						$what,
						$assess['covered'],
						$assess['committed']
					),
					0,
					0,
					$actor
				);
			} else {
				$report['outcome'] = 'pending';
				$this->log(
					'pending',
					sprintf(
						/* translators: 1: what was lost, 2: shortage, 3: fish the open cases speak for */
						__( 'Loss of %1$s: short by %2$d, and the open cases already speak for %3$d — no new case.', 'fishotel-misc-plugin' ),
						$what,
						$assess['shortage'],
						$assess['pending']
					),
					0,
					0,
					$actor
				);
			}
			return $report;
		}

		foreach ( $assess['allocations'] as $allocation ) {
			// One more of a fish whose case was not sent yet: that case grows — the customer gets ONE e-mail
			// for the line, not one per tap.
			$waiting = $this->unsent_case_of( $assess['kind'], $allocation['holder'] );
			$grown   = $waiting ? $this->grow_case( $waiting, (int) $allocation['qty'], (int) $event_id, $actor ) : null;
			if ( $grown ) {
				$report['grown'][] = (int) $grown['id'];
				continue;
			}

			$case = $this->open_case( $assess['kind'], $allocation['holder'], $allocation['qty'], 'auto', $actor, (int) $stay['id'], (int) $event_id );
			if ( $case ) {
				$report['opened'][] = (int) $case['id'];
			}
		}
		$report['outcome'] = $report['opened'] ? 'opened' : ( $report['grown'] ? 'grown' : 'failed' );

		$this->store->refresh_counts();

		if ( ( $report['opened'] || $report['grown'] ) && Settings::get()['auto_send'] && function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + $this->rooms->undo_window() + self::AUTO_SEND_MARGIN, Lost_Fish::TICK_HOOK );
		}

		return $report;
	}

	/**
	 * The case a further loss of the same fish joins: opened by a loss,
	 * for the same order line, not sent yet and never taken back by the
	 * owner (a reopened case is the owner's from then on).
	 *
	 * @param array $kind   The kind.
	 * @param array $holder The holder line.
	 * @return array|null
	 */
	private function unsent_case_of( array $kind, array $holder ) {
		$filters = array(
			'status'   => 'open',
			'order_id' => (int) $holder['order_id'],
		);
		foreach ( $this->store->cases( $filters ) as $case ) {
			if ( 'auto' !== $case['origin'] || '' === $case['loss_events'] || null === $case['loss_at'] ) {
				continue;
			}
			if ( (string) $case['line_ref'] === (string) $holder['ref'] && (string) $case['kind_key'] === (string) $kind['key'] ) {
				return $case;
			}
		}
		return null;
	}

	/**
	 * The losses a case came from.
	 *
	 * @param string $list ",<event id>:<qty>,<event id>:<qty>…".
	 * @return array<int, int> event id => fish.
	 */
	public static function loss_events( $list ) {
		$out = array();
		foreach ( explode( ',', (string) $list ) as $entry ) {
			$parts = explode( ':', $entry );
			if ( 2 === count( $parts ) && ctype_digit( $parts[0] ) && ctype_digit( $parts[1] ) && (int) $parts[0] > 0 && (int) $parts[1] > 0 ) {
				$out[ (int) $parts[0] ] = (int) $parts[1];
			}
		}
		return $out;
	}

	/**
	 * The stored form of loss_events().
	 *
	 * @param array<int, int> $events event id => fish.
	 * @return string
	 */
	private static function loss_list( array $events ) {
		$out = '';
		foreach ( $events as $event_id => $qty ) {
			$out .= ',' . (int) $event_id . ':' . (int) $qty;
		}
		return $out;
	}

	/**
	 * What changes with the number of fish of an open case: a shipping
	 * difference typed in by hand was typed for the old number, so it is
	 * worked out again and the case asks for a look.
	 *
	 * @param array  $case The case as it was.
	 * @param string $why  What to say instead ('' = the number of fish changed).
	 * @return array Fields.
	 */
	private static function count_changed( array $case, $why = '' ) {
		if ( empty( $case['ship_manual'] ) ) {
			return array();
		}
		return array(
			'ship_manual' => 0,
			'needs_check' => 1,
			'check_note'  => trim( $case['check_note'] . ' ' . ( '' !== $why ? $why : __( 'The number of fish changed after a shipping difference was typed in — it was worked out again; check it.', 'fishotel-misc-plugin' ) ) ),
		);
	}

	/**
	 * One more loss for a case that was not sent yet.
	 *
	 * @param array $case     The open case.
	 * @param int   $qty      Fish to add.
	 * @param int   $event_id The loss event.
	 * @param int   $actor    Who.
	 * @return array|null The case, or null when it could not grow (a new case opens instead).
	 */
	private function grow_case( array $case, $qty, $event_id, $actor = 0 ) {
		$qty    = max( 1, (int) $qty );
		$events = self::loss_events( $case['loss_events'] );
		if ( (int) $event_id <= 0 || isset( $events[ (int) $event_id ] ) ) {
			return null;
		}
		$events[ (int) $event_id ] = $qty;
		$list                      = self::loss_list( $events );
		if ( strlen( $list ) > self::LOSS_LIST_MAX ) {
			return null;
		}

		$fields = array_merge(
			self::count_changed( $case ),
			array(
				'qty'         => (int) $case['qty'] + $qty,
				'loss_events' => $list,
				'loss_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		// Only while it is still open: a Send clicked in the same second wins, and a new case opens.
		if ( ! $this->store->transition( (int) $case['id'], array( 'open' ), 'open', $fields ) ) {
			return null;
		}

		$case = $this->refresh( $this->store->get_case( (int) $case['id'] ) );
		$text = sprintf(
			/* translators: 1: number of fish, 2: 2 × fish */
			__( '%1$d more lost with no spare in the tanks: this case now speaks for %2$s. Nothing was sent to the customer yet.', 'fishotel-misc-plugin' ),
			$qty,
			Email::fish_line( $case )
		);
		$this->note( $case, $text, false );
		$this->log( 'grown', $text, (int) $case['id'], (int) $case['order_id'], $actor );
		$this->fire( 'fishotel_lostfish_case_grown', $case, $qty );

		return $case;
	}

	/**
	 * A loss behind some cases was undone. A case not sent yet closes by
	 * itself — or, when other losses stand behind it too, speaks for
	 * fewer fish; a sent or answered one is flagged for the owner.
	 *
	 * Then — whichever loss was undone, also one the extra had covered —
	 * the kind of that tank group is worked out again: unsent cases a
	 * loss opened that the tanks now cover close as well
	 * (trim_to_shortage()).
	 *
	 * @param int $event_id The loss event that was undone (0 = not known).
	 * @param int $actor    Who undid it.
	 * @param int $stay_id  The tank group the fish came back to (0 = not known: nothing is worked out again).
	 * @return array{closed: int[], shrunk: int[], flagged: int[]}
	 */
	public function loss_undone( $event_id, $actor = 0, $stay_id = 0 ) {
		$out = array(
			'closed'  => array(),
			'shrunk'  => array(),
			'flagged' => array(),
		);

		foreach ( (int) $event_id > 0 ? $this->store->cases( array( 'loss_event' => (int) $event_id ) ) : array() as $case ) {
			$id     = (int) $case['id'];
			$events = self::loss_events( $case['loss_events'] );
			if ( ! isset( $events[ (int) $event_id ] ) ) {
				continue;
			}
			$back = $events[ (int) $event_id ];
			unset( $events[ (int) $event_id ] );
			$rest = (int) $case['qty'] - $back;

			if ( 'open' === $case['status'] && $events && $rest > 0 ) {
				$fields = array_merge(
					self::count_changed( $case ),
					array(
						'qty'         => $rest,
						'loss_events' => self::loss_list( $events ),
					)
				);
				if ( $this->store->transition( $id, array( 'open' ), 'open', $fields ) ) {
					$out['shrunk'][] = $id;
					$now             = $this->refresh( $this->store->get_case( $id ) );
					$message         = sprintf(
						/* translators: 1: number of fish, 2: 1 × fish */
						__( 'A loss of %1$d was undone in the Clean Room before anything was sent — this case now speaks for %2$s.', 'fishotel-misc-plugin' ),
						$back,
						Email::fish_line( $now )
					);
					$this->note( $now, $message, false );
					$this->log( 'undone', $message, $id, (int) $case['order_id'], $actor );
				}
				continue;
			}

			if ( 'open' === $case['status'] ) {
				$closed = $this->store->transition(
					$id,
					array( 'open' ),
					'closed',
					array(
						'closed_at'    => gmdate( 'Y-m-d H:i:s' ),
						'close_reason' => 'loss_undone',
					)
				);
				if ( $closed ) {
					$out['closed'][] = $id;
					$message         = __( 'The loss was undone in the Clean Room before anything was sent — the case closed by itself.', 'fishotel-misc-plugin' );
					$this->note( $case, $message, false );
					$this->log( 'undone', $message, $id, (int) $case['order_id'], $actor );
					$this->fire( 'fishotel_lostfish_case_closed', $this->store->get_case( $id ), 'loss_undone' );
				}
				continue;
			}

			if ( in_array( $case['status'], array( 'sent', 'answered' ), true ) ) {
				$message = $events
					? sprintf(
						/* translators: 1: number of fish, 2: number of fish the e-mail spoke of */
						__( 'A loss of %1$d of the %2$d fish of this case was undone in the Clean Room AFTER the e-mail went out. Check the tank, then reopen or close the case and tell the customer.', 'fishotel-misc-plugin' ),
						$back,
						(int) $case['qty']
					)
					: __( 'The loss behind this case was undone in the Clean Room AFTER the e-mail went out. Check the tank, then reopen or close the case and tell the customer.', 'fishotel-misc-plugin' );
				$this->store->update_case(
					$id,
					array(
						'needs_check' => 1,
						'check_note'  => $message,
						'loss_events' => self::loss_list( $events ),
					)
				);
				$out['flagged'][] = $id;
				$this->log( 'undone_late', $message, $id, (int) $case['order_id'], $actor );
			}
		}

		if ( (int) $stay_id > 0 ) {
			$trimmed       = $this->trim_to_shortage( (int) $stay_id, (int) $actor );
			$out['closed'] = array_merge( $out['closed'], $trimmed['closed'] );
			$out['shrunk'] = array_merge( $out['shrunk'], $trimmed['shrunk'] );
		}

		if ( $out['closed'] || $out['shrunk'] || $out['flagged'] ) {
			$this->store->refresh_counts();
		}

		return $out;
	}

	/**
	 * Fish came back to a tank group: when the open cases of its kind now
	 * speak for more fish than the tanks are short, the unsent cases a
	 * loss opened give the surplus back — the newest first; a case closes,
	 * or speaks for fewer fish. A case that was sent, one made by hand and
	 * one the owner reopened are never touched.
	 *
	 * @param int $stay_id The tank group.
	 * @param int $actor   Who.
	 * @return array{closed: int[], shrunk: int[]}
	 */
	public function trim_to_shortage( $stay_id, $actor = 0 ) {
		$out  = array(
			'closed' => array(),
			'shrunk' => array(),
		);
		$stay = $this->rooms->stay( (int) $stay_id );
		if ( ! $stay || ! empty( $stay['needs_id'] ) || ! $this->orders->available() ) {
			return $out;
		}

		$lock = Cover::kind_key( (int) ( $stay['product_id'] ?? 0 ), (string) ( $stay['species'] ?? '' ), (string) ( $stay['size_slug'] ?? '' ) );
		$held = '' !== $lock && method_exists( $this->store, 'lock' ) && $this->store->lock( $lock );

		try {
			$assess = $this->assess_stay( $stay );
			$excess = $assess['pending'] - $assess['shortage'];
			if ( '' === $assess['kind']['key'] || $excess <= 0 ) {
				return $out;
			}

			$order_ids = array();
			foreach ( $assess['holders'] as $holder ) {
				$order_ids[] = (int) $holder['order_id'];
			}
			$filters = array(
				'status'    => 'open',
				'order_ids' => array_values( array_unique( $order_ids ) ),
			);
			foreach ( $order_ids ? $this->store->cases( $filters ) : array() as $case ) {
				if ( $excess <= 0 ) {
					break;
				}
				if ( 'auto' !== $case['origin'] || null === $case['loss_at'] || (string) $case['kind_key'] !== (string) $assess['kind']['key'] ) {
					continue;
				}
				$id   = (int) $case['id'];
				$back = min( $excess, (int) $case['qty'] );

				if ( $back >= (int) $case['qty'] ) {
					$closed = $this->store->transition(
						$id,
						array( 'open' ),
						'closed',
						array(
							'closed_at'    => gmdate( 'Y-m-d H:i:s' ),
							'close_reason' => 'loss_undone',
						)
					);
					if ( $closed ) {
						$excess         -= $back;
						$out['closed'][] = $id;
						$message         = __( 'A loss of this fish was undone in the Clean Room and the tanks cover it again — the case closed by itself. Nothing was sent.', 'fishotel-misc-plugin' );
						$this->note( $case, $message, false );
						$this->log( 'undone', $message, $id, (int) $case['order_id'], $actor );
						$this->fire( 'fishotel_lostfish_case_closed', $this->store->get_case( $id ), 'loss_undone' );
					}
					continue;
				}

				$fields = array_merge(
					self::count_changed( $case ),
					array(
						'qty'         => (int) $case['qty'] - $back,
						'loss_events' => self::loss_list( self::losses_less( self::loss_events( $case['loss_events'] ), $back ) ),
					)
				);
				if ( $this->store->transition( $id, array( 'open' ), 'open', $fields ) ) {
					$excess         -= $back;
					$out['shrunk'][] = $id;
					$now             = $this->refresh( $this->store->get_case( $id ) );
					$message         = sprintf(
						/* translators: %s: 1 × fish */
						__( 'A loss of this fish was undone in the Clean Room and the tanks cover part of it again — this case now speaks for %s. Nothing was sent.', 'fishotel-misc-plugin' ),
						Email::fish_line( $now )
					);
					$this->note( $now, $message, false );
					$this->log( 'undone', $message, $id, (int) $case['order_id'], $actor );
				}
			}
		} finally {
			if ( $held ) {
				$this->store->unlock( $lock );
			}
		}

		return $out;
	}

	/**
	 * The losses behind a case with some fish taken off, the latest loss
	 * first.
	 *
	 * @param array<int, int> $events event id => fish.
	 * @param int             $fish   Fish to take off.
	 * @return array<int, int>
	 */
	private static function losses_less( array $events, $fish ) {
		foreach ( array_reverse( array_keys( $events ) ) as $event_id ) {
			if ( $fish <= 0 ) {
				break;
			}
			$off                  = min( (int) $fish, (int) $events[ $event_id ] );
			$events[ $event_id ] -= $off;
			$fish                -= $off;
			if ( $events[ $event_id ] <= 0 ) {
				unset( $events[ $event_id ] );
			}
		}
		return $events;
	}

	/**
	 * Open one case for a holder line.
	 *
	 * @param array  $kind     Cover::kind_of_stay() (or a kind made from the line, for a case by hand).
	 * @param array  $holder   The holder line (Orders::holders()).
	 * @param int    $qty      Fish short.
	 * @param string $origin   auto | manual.
	 * @param int    $actor    Who.
	 * @param int    $stay_id  The tank group (0 = none).
	 * @param int    $event_id The loss event (0 = none).
	 * @return array|null The case.
	 */
	private function open_case( array $kind, array $holder, $qty, $origin, $actor = 0, $stay_id = 0, $event_id = 0 ) {
		$match = (string) ( $holder['match'] ?? 'manual' );
		$check = array();
		if ( 'name' === $match ) {
			$check[] = __( 'Matched to this order line by NAME only — check that it is the same fish before sending.', 'fishotel-misc-plugin' );
		}
		if ( empty( $holder['paid_known'] ) ) {
			$check[] = __( 'What was paid for this fish is not on the order line — type it in.', 'fishotel-misc-plugin' );
		}
		if ( $this->due_to_ship( (int) $holder['order_id'] ) ) {
			$check[] = __( 'This order is due to ship today or earlier — make sure its fish has not already left before writing to the customer.', 'fishotel-misc-plugin' );
		}

		$name = trim( (string) ( $holder['name'] ?? '' ) );
		$id   = $this->store->insert_case(
			array(
				'status'         => 'open',
				'origin'         => 'manual' === $origin ? 'manual' : 'auto',
				'kind_key'       => (string) $kind['key'],
				'product_id'     => (int) $kind['product_id'] > 0 ? (int) $kind['product_id'] : (int) ( $holder['product_id'] ?? 0 ),
				'species'        => (string) $kind['species'],
				'size_slug'      => (string) $kind['size_slug'],
				'size_label'     => (string) $kind['size_label'],
				'fish_name'      => '' !== $name ? $name : (string) $kind['species'],
				'qty'            => max( 1, (int) $qty ),
				'stay_id'        => (int) $stay_id,
				'loss_event_id'  => (int) $event_id,
				'loss_events'    => (int) $event_id > 0 ? ',' . (int) $event_id . ':' . max( 1, (int) $qty ) : '',
				'loss_at'        => 'manual' === $origin ? null : gmdate( 'Y-m-d H:i:s' ),
				'order_id'       => (int) $holder['order_id'],
				'order_item_id'  => (int) ( $holder['item_id'] ?? 0 ),
				'line_ref'       => (string) $holder['ref'],
				'line_match'     => $match,
				'needs_check'    => $check ? 1 : 0,
				'check_note'     => implode( ' ', $check ),
				'customer_id'    => (int) ( $holder['customer_id'] ?? 0 ),
				'customer_name'  => (string) ( $holder['customer_name'] ?? '' ),
				'customer_email' => (string) ( $holder['customer_email'] ?? '' ),
				'currency'       => (string) ( $holder['currency'] ?? '' ),
				'unit_paid'      => max( 0, (int) ( $holder['unit_paid'] ?? 0 ) ),
				'paid_known'     => ! empty( $holder['paid_known'] ) ? 1 : 0,
				'created_by'     => (int) $actor,
			)
		);
		if ( ! $id ) {
			return null;
		}

		$case = $this->refresh( $this->store->get_case( $id ) );
		$text = sprintf(
			/* translators: %s: 1 × fish */
			'manual' === $origin
				? __( 'Opened by hand for %s. Nothing was sent to the customer yet.', 'fishotel-misc-plugin' )
				: __( 'Opened: %s was lost and there is no spare in the tanks. Nothing was sent to the customer yet.', 'fishotel-misc-plugin' ),
			Email::fish_line( $case )
		);
		$this->note( $case, $text, 'manual' === $origin );
		$this->log( 'opened', $text, $id, (int) $case['order_id'], $actor );
		$this->fire( 'fishotel_lostfish_case_opened', $case );

		return $case;
	}

	/**
	 * Whether an order is due to ship today or earlier: its box may be
	 * packed — fish marked as shipped in the Clean Room — before its
	 * shipment is recorded, so a case on it always asks for a look.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private function due_to_ship( $order_id ) {
		$order = $this->orders->get( (int) $order_id );
		if ( ! $order ) {
			return false;
		}
		$ship = Orders::ship_date_for( $this->orders->delivery_date( $order ), $order );

		return '' !== $ship && strcmp( $ship, Lost_Fish::today() ) <= 0;
	}

	/**
	 * Open a case by hand from an order line.
	 *
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref (Orders::lines()).
	 * @param int    $qty      How many fish of the line.
	 * @param int    $actor    Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function open_by_hand( $order_id, $ref, $qty, $actor = 0 ) {
		$order = $this->orders->get( (int) $order_id );
		if ( ! $order ) {
			return self::result( false, __( 'That order does not exist.', 'fishotel-misc-plugin' ) );
		}
		$line = $this->orders->line( $order, (string) $ref );
		if ( ! $line ) {
			return self::result( false, __( 'That line is not on the order any more.', 'fishotel-misc-plugin' ) );
		}

		$left = $line['qty'] - $line['qty_refunded'];
		foreach ( $this->store->cases( array( 'order_id' => (int) $order_id ) ) as $other ) {
			if ( $other['line_ref'] === $line['ref'] && ( in_array( $other['status'], array_merge( Cover::PENDING, Cover::RELEASED ), true ) || ( 'closed' === $other['status'] && Cover::CLOSE_SETTLED === $other['close_reason'] ) ) ) {
				$left -= (int) $other['qty'];
			}
		}
		$qty = (int) $qty;
		if ( $qty < 1 || $qty > $left ) {
			return self::result(
				false,
				$left > 0
					/* translators: %d: fish left on the line */
					? sprintf( __( 'How many? 1–%d (what is left on that line without a case).', 'fishotel-misc-plugin' ), $left )
					: __( 'Every fish of that line already has a case, or was refunded.', 'fishotel-misc-plugin' )
			);
		}

		$facts = $this->orders->facts( $order );
		$kind  = array(
			'key'        => Cover::kind_key( $line['product_id'] > 0 ? $line['product_id'] : (int) $line['link']['product_id'], $line['name'], '' ),
			'product_id' => $line['product_id'] > 0 ? $line['product_id'] : (int) $line['link']['product_id'],
			'species'    => $line['name'],
			'size_slug'  => '',
			'size_label' => '',
		);
		$case  = $this->open_case(
			$kind,
			array_merge(
				$line,
				array(
					'order_id'       => $facts['id'],
					'customer_id'    => $facts['customer_id'],
					'customer_name'  => $facts['customer_name'],
					'customer_email' => $facts['email'],
					'currency'       => $facts['currency'],
					'match'          => 'manual',
				)
			),
			$qty,
			'manual',
			$actor
		);
		if ( ! $case ) {
			return self::result( false, __( 'The case could not be saved.', 'fishotel-misc-plugin' ) );
		}
		$this->store->refresh_counts();

		/* translators: %d: case id */
		return self::result( true, sprintf( __( 'Case #%d opened. Nothing was sent.', 'fishotel-misc-plugin' ), (int) $case['id'] ), $case );
	}

	/* ============================================================== */
	/*  The numbers                                                    */
	/* ============================================================== */

	/**
	 * What the other cases of the same order mean for this one: for its
	 * shipping difference, the fish answered refunds / credits already
	 * took out and what they withheld, and the cases that still wait (how
	 * many, their fish, and which of them are with the customer); for its
	 * "cancel the whole order" amount, what was already given back as
	 * store credit and the refunds the owner still has to make.
	 *
	 * @param array $case The case.
	 * @return array{removed: array[], withheld: int, pending: int, waiting: array[], sent: int[], credited: int,
	 *               credits: int[], refunds_due: array<int, int>}
	 */
	public function ledger( array $case ) {
		$out = array(
			'removed'     => array(),
			'withheld'    => 0,
			'pending'     => 0,
			'waiting'     => array(),
			'sent'        => array(),
			'credited'    => 0,
			'credits'     => array(),
			'refunds_due' => array(),
		);
		foreach ( $this->store->cases( array( 'order_id' => (int) $case['order_id'] ) ) as $other ) {
			if ( (int) $other['id'] === (int) $case['id'] ) {
				continue;
			}
			$line = array(
				'ref'       => $other['line_ref'],
				'qty'       => (int) $other['qty'],
				'unit_paid' => (int) $other['unit_paid'],
			);
			if ( in_array( $other['status'], Cover::PENDING, true ) ) {
				$out['pending']++;
				$out['waiting'][] = $line;
				if ( 'sent' === $other['status'] ) {
					$out['sent'][] = (int) $other['id'];
				}
			} elseif ( in_array( $other['status'], Cover::RELEASED, true ) && in_array( $other['choice'], array( 'refund', 'credit' ), true ) ) {
				$out['removed'][] = $line;
				$out['withheld'] += max( 0, (int) $other['ship_diff'] );
				if ( 'credit' === $other['choice'] ) {
					$out['credited'] += max( 0, (int) $other['amount_refund'] );
					$out['credits'][] = (int) $other['id'];
				} elseif ( 'answered' === $other['status'] && (int) $other['amount_refund'] > 0 ) {
					$out['refunds_due'][ (int) $other['id'] ] = (int) $other['amount_refund'];
				}
			}
		}
		return $out;
	}

	/**
	 * Bring an OPEN case up to date with its order: the customer, whether
	 * the whole order can still be cancelled, and — unless the owner typed
	 * it — the shipping difference. A case that is not open is frozen and
	 * comes back untouched.
	 *
	 * @param array|null $case The case.
	 * @return array|null The case as stored now.
	 */
	public function refresh( $case ) {
		if ( ! is_array( $case ) || 'open' !== $case['status'] ) {
			return $case;
		}

		$order = $this->orders->get( (int) $case['order_id'] );
		if ( ! $order ) {
			return $case;
		}
		$facts  = $this->orders->facts( $order );
		$fields = array(
			'customer_id'    => $facts['customer_id'],
			'customer_name'  => $facts['customer_name'],
			'customer_email' => $facts['email'],
			'currency'       => $facts['currency'],
			'offer_cancel'   => $facts['any_shipped'] ? 0 : 1,
			'ship_charged'   => $facts['ship_charged'] + $facts['ship_tax'],
		);

		if ( empty( $case['ship_manual'] ) ) {
			$mine   = array(
				'ref'       => $case['line_ref'],
				'qty'       => (int) $case['qty'],
				'unit_paid' => (int) $case['unit_paid'],
			);
			$ledger = $this->ledger( $case );
			$opts   = array( 'free_classes' => Settings::get()['free_classes'] );
			$diff   = Shipping::difference( $order, array( $mine ), $ledger, $opts );

			// ONE other case of the order still waits. When the shipping stays the same with this fish out
			// alone AND with both out, no answer there can change this one: there is no difference — nothing
			// to type, nothing to wait for.
			if ( Shipping::UNKNOWN === $diff['status'] && 1 === $ledger['pending'] && 0 === $diff['alone'] ) {
				$both = Shipping::difference( $order, array_merge( array( $mine ), $ledger['waiting'] ), array_merge( $ledger, array( 'pending' => 0 ) ), $opts );
				if ( Shipping::NONE === $both['status'] ) {
					$diff         = $both;
					$diff['note'] = __( 'The order\'s shipping stays the same without this fish — also when the fish of its other open case goes too.', 'fishotel-misc-plugin' );
				}
			}

			$fields['ship_status']  = $diff['status'];
			$fields['ship_diff']    = $diff['diff'];
			$fields['ship_reduced'] = Shipping::UNKNOWN === $diff['status'] ? $diff['alone'] : $diff['reduced'];
			$fields['ship_note']    = $diff['note'];
		}

		$changed = false;
		foreach ( $fields as $key => $value ) {
			if ( $case[ $key ] !== $value ) {
				$changed = true;
				break;
			}
		}
		if ( $changed ) {
			$this->store->update_case( (int) $case['id'], $fields );
			$case = array_merge( $case, $fields );
		}

		return $case;
	}

	/**
	 * The money of a case and whether it can be sent. An open case reads
	 * the order's total now; a case that was sent shows what was frozen.
	 *
	 * @param array      $case  The case.
	 * @param array|null $facts Orders::facts(), or null when the order is gone.
	 * @return array Money::choices() plus ready (bool), blockers (string[]) and frozen (bool).
	 */
	public function numbers( array $case, $facts = null ) {
		$frozen = 'open' !== $case['status'];
		$ledger = $this->ledger( $case );

		if ( $frozen && null !== $case['amount_refund'] ) {
			$fish    = max( 0, (int) $case['unit_paid'] ) * max( 1, (int) $case['qty'] );
			$cancel  = null !== $case['amount_cancel'] ? (int) $case['amount_cancel'] : null;
			$offer   = ! empty( $case['offer_cancel'] ) && null !== $cancel;
			$settled = 0;
			// While the answer, or what follows from it, is still to come, "cancel" is what is left to give
			// back NOW — never more than the e-mail said: a refund or a store credit made since comes off
			// it, and once part of the order has shipped it is no longer offered.
			if ( is_array( $facts ) && in_array( $case['status'], array( 'sent', 'answered' ), true ) ) {
				if ( null !== $cancel ) {
					$settled = max( 0, (int) $facts['refunded'] ) + $ledger['credited'];
					$cancel  = min( $cancel, max( 0, (int) $facts['total'] - $settled ) );
				}
				if ( 'sent' === $case['status'] && ! empty( $facts['any_shipped'] ) ) {
					$offer = false;
				}
			}
			$numbers = array(
				'fish_paid'    => $fish,
				'ship_diff'    => null !== $case['ship_diff'] ? (int) $case['ship_diff'] : 0,
				'refund'       => (int) $case['amount_refund'],
				'credit'       => (int) $case['amount_refund'],
				'retry'        => 0,
				'cancel'       => $cancel,
				'settled'      => $settled,
				'shortfall'    => max( 0, (int) $case['ship_diff'] - $fish ),
				'offer_cancel' => $offer,
			);
		} else {
			$numbers = Money::choices(
				array(
					'unit_paid'      => (int) $case['unit_paid'],
					'qty'            => (int) $case['qty'],
					'paid_known'     => ! empty( $case['paid_known'] ),
					'ship_diff'      => $case['ship_diff'],
					'order_total'    => is_array( $facts ) ? (int) $facts['total'] : null,
					'order_refunded' => is_array( $facts ) ? (int) $facts['refunded'] : 0,
					'order_credited' => $ledger['credited'],
					'offer_cancel'   => ! empty( $case['offer_cancel'] ) && is_array( $facts ),
				)
			);
		}

		$blockers = array();
		if ( ! $frozen ) {
			if ( ! is_array( $facts ) ) {
				$blockers['order'] = __( 'The order of this case no longer exists.', 'fishotel-misc-plugin' );
			} else {
				if ( $facts['closed'] ) {
					/* translators: %s: order status */
					$blockers['order'] = sprintf( __( 'The order is %s — there is nothing left to ask the customer.', 'fishotel-misc-plugin' ), $facts['status'] );
				} elseif ( empty( $facts['paid'] ) ) {
					$blockers['unpaid'] = __( 'This order is not paid — nothing was paid for this fish, so there is nothing to refund or credit. Close the case, or wait for the payment.', 'fishotel-misc-plugin' );
				}
				if ( '' === $facts['email'] ) {
					$blockers['email'] = __( 'The order has no e-mail address for the customer.', 'fishotel-misc-plugin' );
				}

				$left = $this->line_left( $case );
				if ( null !== $left && $left < (int) $case['qty'] ) {
					$blockers['refunded'] = sprintf(
						/* translators: 1: fish left on the line, 2: fish of the case */
						__( 'Only %1$d of this order line is left that was not refunded, and this case speaks for %2$d — it looks as if this fish was already refunded. Close the case instead of asking again.', 'fishotel-misc-plugin' ),
						max( 0, $left ),
						(int) $case['qty']
					);
				}
			}
			if ( empty( $case['paid_known'] ) ) {
				$blockers['paid'] = __( 'What was paid for the fish is not known — type it in.', 'fishotel-misc-plugin' );
			}
			if ( $ledger['sent'] && ( null === $case['ship_diff'] || ! empty( $case['ship_manual'] ) ) ) {
				// Another case of the order is with the customer. A figure typed here — or to be typed — was
				// worked out as if this fish were the only one: with one typed there too, both would be withheld.
				$blockers['sibling'] = sprintf(
					/* translators: %s: case numbers */
					__( 'Case %s on this order is with the customer and has no answer yet, and the shipping difference of this one depends on that answer — typed now, it could be taken twice. Wait for the answer (or record it by hand): this case is then worked out on what is left of the order.', 'fishotel-misc-plugin' ),
					'#' . implode( ', #', $ledger['sent'] )
				);
			} elseif ( null === $case['ship_diff'] ) {
				$blockers['shipping'] = __( 'The shipping difference could not be worked out — fill it in, or confirm zero.', 'fishotel-misc-plugin' );
			}
		}

		$numbers['frozen']   = $frozen;
		$numbers['blockers'] = $blockers;
		$numbers['ready']    = ! $frozen && ! $blockers;

		return $numbers;
	}

	/**
	 * The fish of the case's order line that were not refunded.
	 *
	 * @param array $case The case.
	 * @return int|null Null when the order or the line cannot be read (nothing is concluded from that).
	 */
	private function line_left( array $case ) {
		$order = $this->orders->get( (int) $case['order_id'] );
		$line  = $order ? $this->orders->line( $order, (string) $case['line_ref'] ) : null;

		return $line ? (int) $line['qty'] - (int) $line['qty_refunded'] : null;
	}

	/**
	 * What a form of the Cases screen saw of a case. A write that depends
	 * on what was on the page (send, the numbers, the answer, giving the
	 * case away) is refused when the case no longer reads like that — it
	 * was changed in another tab, or a further loss joined it.
	 *
	 * @param array $case The case.
	 * @return string
	 */
	public static function stamp( array $case ) {
		$parts = array();
		foreach ( array( 'id', 'status', 'order_id', 'line_ref', 'qty', 'unit_paid', 'paid_known', 'ship_diff', 'ship_manual', 'customer_email' ) as $key ) {
			$parts[] = isset( $case[ $key ] ) ? (string) $case[ $key ] : '';
		}
		return substr( hash( 'sha256', implode( '|', $parts ) ), 0, 16 );
	}

	/**
	 * The owner's numbers on an open case: what was paid for one fish,
	 * and the shipping difference — typed (zero confirms "none"), or
	 * emptied to have it worked out again.
	 *
	 * @param int   $case_id Case id.
	 * @param array $raw     unit_paid, ship_diff (as typed; a key that is absent is left alone).
	 * @param int   $actor   Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function set_numbers( $case_id, array $raw, $actor = 0 ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ) );
		}
		if ( 'open' !== $case['status'] ) {
			return self::result( false, __( 'The numbers of a case are fixed once it was sent. Reopen it to change them.', 'fishotel-misc-plugin' ), $case );
		}

		$fields = array();
		$said   = array();

		if ( array_key_exists( 'unit_paid', $raw ) && '' !== trim( (string) $raw['unit_paid'] ) ) {
			$cents = Money::typed_cents( $raw['unit_paid'] );
			if ( null === $cents ) {
				return self::result( false, __( 'What was paid for one fish is not an amount.', 'fishotel-misc-plugin' ), $case );
			}
			if ( $cents !== (int) $case['unit_paid'] || empty( $case['paid_known'] ) ) {
				$fields['unit_paid']  = $cents;
				$fields['paid_known'] = 1;
				/* translators: %s: amount */
				$said[] = sprintf( __( 'paid per fish set to %s', 'fishotel-misc-plugin' ), Money::format( $cents ) );
			}
		}

		if ( array_key_exists( 'ship_diff', $raw ) ) {
			if ( '' === trim( (string) $raw['ship_diff'] ) ) {
				if ( ! empty( $case['ship_manual'] ) ) {
					$fields['ship_manual'] = 0;
					$said[]                = __( 'shipping difference back to worked out', 'fishotel-misc-plugin' );
				}
			} else {
				$cents = Money::typed_cents( $raw['ship_diff'] );
				if ( null === $cents ) {
					return self::result( false, __( 'The shipping difference is not an amount. Type a number, or 0 for none.', 'fishotel-misc-plugin' ), $case );
				}
				// A typed figure is the owner's word, even when it equals the worked-out one: it then holds
				// whatever happens to the order afterwards.
				if ( empty( $case['ship_manual'] ) || null === $case['ship_diff'] || $cents !== (int) $case['ship_diff'] ) {
					$fields['ship_manual'] = 1;
					$fields['ship_diff']   = $cents;
					$fields['ship_status'] = $cents > 0 ? Shipping::DROP : Shipping::NONE;
					$fields['ship_note']   = $cents > 0
						? __( 'Shipping difference typed in by hand.', 'fishotel-misc-plugin' )
						: __( 'No shipping difference — confirmed by hand.', 'fishotel-misc-plugin' );
					/* translators: %s: amount */
					$said[] = sprintf( __( 'shipping difference set to %s', 'fishotel-misc-plugin' ), Money::format( $cents ) );
				}
			}
		}

		if ( ! $fields ) {
			return self::result( true, __( 'Nothing changed.', 'fishotel-misc-plugin' ), $case );
		}

		$this->store->update_case( (int) $case['id'], $fields );
		$case = $this->refresh( $this->store->get_case( (int) $case['id'] ) );
		$this->log( 'numbers', ucfirst( implode( '; ', $said ) ) . '.', (int) $case['id'], (int) $case['order_id'], $actor );

		return self::result( true, __( 'Numbers saved.', 'fishotel-misc-plugin' ), $case );
	}

	/* ============================================================== */
	/*  Send, remind                                                   */
	/* ============================================================== */

	/**
	 * The four links of a case.
	 *
	 * @param array $case    The case (with its token_salt).
	 * @param int   $expires UNIX timestamp the links are good until.
	 * @param bool  $cancel  Whether the cancel link is offered.
	 * @return array<string, string> choice => URL.
	 */
	public static function links( array $case, $expires, $cancel = true ) {
		$out    = array();
		$secret = Token::secret();
		foreach ( Money::CHOICES as $choice ) {
			if ( 'cancel' === $choice && ! $cancel ) {
				continue;
			}
			$token = Token::make( (int) $case['id'], $choice, (int) $expires, (string) $case['token_salt'], $secret );
			if ( '' !== $token ) {
				$out[ $choice ] = add_query_arg( Lost_Fish::QUERY_ARG, $token, home_url( '/' ) );
			}
		}
		return $out;
	}

	/**
	 * Send the e-mail of an open case. THE one place an e-mail to a
	 * customer starts: the owner's click (the screen), or — only with the
	 * setting on — the tick after the undo window ($auto).
	 *
	 * The numbers are worked out one last time. A case that is not ready
	 * (the shipping difference or the price is not known, no address, the
	 * order is gone) is not sent. When the screen says which refund it
	 * showed ($expect['refund'], cents) and that is no longer the number,
	 * nothing is sent either: the owner clicked on other numbers.
	 *
	 * @param int   $case_id Case id.
	 * @param int   $actor   Who clicked (0 = by itself).
	 * @param array $expect  refund (cents): the refund the screen showed.
	 * @param bool  $auto    Whether this is the send by itself.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function send( $case_id, $actor = 0, array $expect = array(), $auto = false ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ) );
		}
		if ( 'open' !== $case['status'] ) {
			return self::result( false, __( 'This case was already sent (or closed).', 'fishotel-misc-plugin' ), $case );
		}

		$case  = $this->refresh( $case );
		$order = $this->orders->get( (int) $case['order_id'] );
		$facts = $order ? $this->orders->facts( $order ) : null;
		$nums  = $this->numbers( $case, $facts );

		if ( ! $nums['ready'] ) {
			return self::result( false, implode( ' ', $nums['blockers'] ), $case );
		}
		if ( $auto && ! $this->may_send_by_itself( $case ) ) {
			return self::result( false, __( 'Not sent by itself: this case needs a look first.', 'fishotel-misc-plugin' ), $case );
		}
		if ( isset( $expect['refund'] ) && (int) $expect['refund'] !== (int) $nums['refund'] ) {
			return self::result( false, __( 'The numbers changed since this page was loaded. Check them, then send again.', 'fishotel-misc-plugin' ), $case );
		}

		$settings = Settings::get();
		$now      = time();
		$expires  = $now + $settings['link_days'] * DAY_IN_SECONDS;
		$frozen   = array(
			'amount_refund'  => (int) $nums['refund'],
			'amount_cancel'  => $nums['offer_cancel'] ? (int) $nums['cancel'] : null,
			'offer_cancel'   => $nums['offer_cancel'] ? 1 : 0,
			'token_salt'     => Token::new_salt(),
			'link_expires'   => gmdate( 'Y-m-d H:i:s', $expires ),
			'sent_at'        => gmdate( 'Y-m-d H:i:s', $now ),
			'reminded_at'    => null,
			'customer_email' => $facts['email'],
		);

		// The status moves first: a second click finds nothing to send.
		if ( ! $this->store->transition( (int) $case['id'], array( 'open' ), 'sent', $frozen ) ) {
			return self::result( false, __( 'This case was already sent (or closed).', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ) );
		}
		$case = $this->store->get_case( (int) $case['id'] );
		$nums = $this->numbers( $case, $facts );

		$message = Email::build(
			$case,
			$facts,
			$nums,
			self::links( $case, $expires, $nums['offer_cancel'] ),
			$settings,
			array( 'rest_left' => $this->orders->rest_left( $order, $case['line_ref'], (int) $case['qty'] ) )
		);

		if ( ! Email::send( $message ) ) {
			$this->store->transition(
				(int) $case['id'],
				array( 'sent' ),
				'open',
				array(
					'amount_refund' => null,
					'amount_cancel' => null,
					'token_salt'    => '',
					'link_expires'  => null,
					'sent_at'       => null,
				)
			);
			$this->log( 'send_failed', __( 'The e-mail could not be sent (wp_mail refused it). The case is open again.', 'fishotel-misc-plugin' ), (int) $case['id'], (int) $case['order_id'], $actor );
			$this->store->refresh_counts();

			return self::result( false, __( 'The e-mail could not be sent. Nothing changed — try again, or check the mail settings.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ) );
		}

		$text = sprintf(
			/* translators: 1: e-mail address, 2: refund / credit amount, 3: how it was sent */
			__( 'E-mail sent to %1$s with the four choices (refund or credit: %2$s). %3$s', 'fishotel-misc-plugin' ),
			$message['to'],
			Money::format( $nums['refund'], $facts['symbol'] ),
			$auto ? __( 'Sent by itself after the undo window.', 'fishotel-misc-plugin' ) : __( 'Sent by hand.', 'fishotel-misc-plugin' )
		);
		$this->note( $case, $text, ! $auto );
		$this->log( 'sent', $text, (int) $case['id'], (int) $case['order_id'], $actor );
		$this->store->refresh_counts();
		$this->fire( 'fishotel_lostfish_case_sent', $case );

		return self::result( true, __( 'Sent to the customer.', 'fishotel-misc-plugin' ), $case );
	}

	/**
	 * Whether a case may go out by itself: a loss opened it, nothing
	 * about it asks for a look, the line was matched by its product (or by
	 * the Invoices section) — and the tanks are still short of that fish.
	 *
	 * @param array $case The case.
	 * @return bool
	 */
	public function may_send_by_itself( array $case ) {
		if ( 'auto' !== $case['origin'] || ! empty( $case['needs_check'] ) || ! empty( $case['ship_manual'] ) ) {
			return false;
		}
		if ( ! in_array( $case['line_match'], array( 'product', 'variation', 'invoice' ), true ) ) {
			return false;
		}

		$stay = $this->rooms->stay( (int) $case['stay_id'] );
		if ( ! $stay ) {
			return false;
		}
		$assess = $this->assess_stay( $stay );

		return $assess['shortage'] > 0 && $assess['shortage'] >= $assess['pending'];
	}

	/**
	 * The one reminder of a sent case that got no answer.
	 *
	 * @param array $case The case.
	 * @param int   $now  UNIX timestamp.
	 * @return bool Whether a reminder went out.
	 */
	public function remind( array $case, $now = 0 ) {
		$now      = $now > 0 ? (int) $now : time();
		$settings = Settings::get();
		$expires  = $now + $settings['link_days'] * DAY_IN_SECONDS;

		// Marked first: two overlapping runs send one reminder.
		if ( 'sent' !== $case['status'] || ! $this->store->mark_reminded( (int) $case['id'], gmdate( 'Y-m-d H:i:s', $expires ) ) ) {
			return false;
		}

		$case  = $this->store->get_case( (int) $case['id'] );
		$order = $this->orders->get( (int) $case['order_id'] );
		$facts = $order ? $this->orders->facts( $order ) : null;
		if ( ! $facts || $facts['closed'] || '' === $facts['email'] ) {
			$this->log( 'reminder_skipped', __( 'No reminder: the order is closed or has no e-mail address. The case needs you.', 'fishotel-misc-plugin' ), (int) $case['id'], (int) $case['order_id'] );
			return false;
		}

		$nums    = $this->numbers( $case, $facts );
		$message = Email::build(
			$case,
			$facts,
			$nums,
			self::links( $case, $expires, $nums['offer_cancel'] ),
			$settings,
			array(
				'reminder'  => true,
				'rest_left' => $this->orders->rest_left( $order, $case['line_ref'], (int) $case['qty'] ),
			)
		);

		if ( ! Email::send( $message ) ) {
			$this->log( 'reminder_failed', __( 'The reminder could not be sent (wp_mail refused it). The case needs you.', 'fishotel-misc-plugin' ), (int) $case['id'], (int) $case['order_id'] );
			return false;
		}

		/* translators: %s: e-mail address */
		$text = sprintf( __( 'No answer yet — one reminder sent to %s. From here the case waits for you.', 'fishotel-misc-plugin' ), $message['to'] );
		$this->note( $case, $text, false );
		$this->log( 'reminded', $text, (int) $case['id'], (int) $case['order_id'] );
		$this->fire( 'fishotel_lostfish_case_reminded', $case );

		return true;
	}

	/* ============================================================== */
	/*  The answer, and after                                          */
	/* ============================================================== */

	/**
	 * Record the customer's choice — once. Through a link's Confirm
	 * ($via 'customer': only a sent case) or by the owner's hand ($via
	 * 'owner': a sent case, or an open one whose numbers are ready, which
	 * are then frozen as a send would).
	 *
	 * @param int    $case_id Case id.
	 * @param string $choice  refund | credit | retry | cancel.
	 * @param string $via     customer | owner.
	 * @param int    $actor   Who (0 = the customer).
	 * @return array{ok: bool, message: string, case: array|null, code: string}
	 */
	public function answer( $case_id, $choice, $via = 'customer', $actor = 0 ) {
		$via  = 'owner' === $via ? 'owner' : 'customer';
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ), null, 'gone' );
		}
		if ( ! in_array( (string) $choice, Money::CHOICES, true ) ) {
			return self::result( false, __( 'That is not one of the choices.', 'fishotel-misc-plugin' ), $case, 'choice' );
		}
		if ( in_array( $case['status'], Cover::RELEASED, true ) ) {
			return self::result( false, __( 'A choice was already recorded for this case.', 'fishotel-misc-plugin' ), $case, 'taken' );
		}

		$from   = array( 'sent' );
		$fields = array(
			'choice'      => (string) $choice,
			'choice_via'  => $via,
			'answered_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( 'owner' === $via && 'open' === $case['status'] ) {
			$case  = $this->refresh( $case );
			$order = $this->orders->get( (int) $case['order_id'] );
			$facts = $order ? $this->orders->facts( $order ) : null;
			$nums  = $this->numbers( $case, $facts );
			if ( array_diff_key( $nums['blockers'], array( 'email' => 1 ) ) ) {
				return self::result( false, implode( ' ', array_diff_key( $nums['blockers'], array( 'email' => 1 ) ) ), $case, 'numbers' );
			}
			$from                    = array( 'open' );
			$fields['amount_refund'] = (int) $nums['refund'];
			$fields['amount_cancel'] = $nums['offer_cancel'] ? (int) $nums['cancel'] : null;
			$fields['offer_cancel']  = $nums['offer_cancel'] ? 1 : 0;
		} elseif ( 'sent' !== $case['status'] ) {
			return self::result( false, __( 'This case is not waiting for an answer.', 'fishotel-misc-plugin' ), $case, 'state' );
		} else {
			// The e-mail may be days old: the order is read again before anything is recorded.
			$order = $this->orders->get( (int) $case['order_id'] );
			$facts = $order ? $this->orders->facts( $order ) : null;
			if ( 'customer' === $via && ( ! $facts || $facts['closed'] ) ) {
				$text = $facts
					/* translators: %s: order status */
					? sprintf( __( 'The customer tried to answer, but the order is %s by now — nothing was recorded. Sort it out with the customer directly, then close the case.', 'fishotel-misc-plugin' ), $facts['status'] )
					: __( 'The customer tried to answer, but the order no longer exists — nothing was recorded. Sort it out with the customer directly, then close the case.', 'fishotel-misc-plugin' );
				$this->store->update_case(
					(int) $case['id'],
					array(
						'needs_check' => 1,
						'check_note'  => $text,
					)
				);
				$this->log( 'answer_refused', $text, (int) $case['id'], (int) $case['order_id'] );
				return self::result( false, __( 'The order of this case is closed.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ), 'closed' );
			}
			if ( 'cancel' === $choice && $facts && $facts['any_shipped'] ) {
				return self::result( false, __( 'Cancelling the whole order is not offered on this case — part of it has shipped.', 'fishotel-misc-plugin' ), $case, 'choice' );
			}
		}

		if ( 'cancel' === $choice && ( empty( $case['offer_cancel'] ) || ( array_key_exists( 'offer_cancel', $fields ) && ! $fields['offer_cancel'] ) ) ) {
			return self::result( false, __( 'Cancelling the whole order is not offered on this case — part of it has shipped.', 'fishotel-misc-plugin' ), $case, 'choice' );
		}

		if ( ! $this->store->transition( (int) $case['id'], $from, 'answered', $fields ) ) {
			return self::result( false, __( 'A choice was already recorded for this case.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ), 'taken' );
		}

		$case   = $this->store->get_case( (int) $case['id'] );
		$order  = $this->orders->get( (int) $case['order_id'] );
		$facts  = $order ? $this->orders->facts( $order ) : null;
		$amount = Money::amount_for( $choice, $this->numbers( $case, $facts ) );
		$symbol = $facts ? $facts['symbol'] : '$';
		$text   = sprintf(
			/* translators: 1: who, 2: the choice, 3: the amount (may be empty) */
			__( '%1$s: %2$s%3$s. Nothing was done by the site — see FisHotel Tools → Lost Fish for what to do.', 'fishotel-misc-plugin' ),
			'owner' === $via ? __( 'The customer\'s answer, recorded by hand', 'fishotel-misc-plugin' ) : __( 'The customer chose', 'fishotel-misc-plugin' ),
			self::choice_label( $choice ),
			null !== $amount && 'retry' !== $choice ? ' — ' . Money::format( $amount, $symbol ) : ''
		);
		$this->note( $case, $text, 'owner' === $via );
		$this->log( 'answered', $text, (int) $case['id'], (int) $case['order_id'], $actor );

		if ( 'retry' === $choice ) {
			$this->create_request( (int) $case['id'], $actor );
			$case = $this->store->get_case( (int) $case['id'] );
		}
		if ( 'cancel' === $choice ) {
			$this->close_siblings( $case, $actor );
		} else {
			$this->recheck_siblings( $case );
		}

		$this->store->refresh_counts();
		$this->fire( 'fishotel_lostfish_case_answered', $case, (string) $choice, $via );

		return self::result( true, __( 'The choice is recorded.', 'fishotel-misc-plugin' ), $case, 'ok' );
	}

	/**
	 * The whole order is being cancelled: its other cases that still wait
	 * have nothing left to ask.
	 *
	 * @param array $case  The case answered with "cancel".
	 * @param int   $actor Who.
	 */
	private function close_siblings( array $case, $actor ) {
		foreach ( $this->store->cases( array( 'order_id' => (int) $case['order_id'] ) ) as $other ) {
			if ( (int) $other['id'] === (int) $case['id'] || ! in_array( $other['status'], Cover::PENDING, true ) ) {
				continue;
			}
			$closed = $this->store->transition(
				(int) $other['id'],
				Cover::PENDING,
				'closed',
				array(
					'closed_at'    => gmdate( 'Y-m-d H:i:s' ),
					'close_reason' => 'order_cancelled',
				)
			);
			if ( $closed ) {
				/* translators: %d: case id */
				$message = sprintf( __( 'Closed: the customer chose to cancel the whole order (case #%d).', 'fishotel-misc-plugin' ), (int) $case['id'] );
				$this->note( $other, $message, false );
				$this->log( 'closed', $message, (int) $other['id'], (int) $other['order_id'], $actor );
			}
		}
	}

	/**
	 * An answer came in: a shipping difference typed on another open case
	 * of the same order was typed while this one still waited, so it is
	 * worked out again on what is left of the order (the ledger) and that
	 * case asks for a look.
	 *
	 * @param array $case The case that was answered.
	 */
	private function recheck_siblings( array $case ) {
		$filters = array(
			'status'   => 'open',
			'order_id' => (int) $case['order_id'],
		);
		foreach ( $this->store->cases( $filters ) as $other ) {
			if ( (int) $other['id'] === (int) $case['id'] ) {
				continue;
			}
			$fields = self::count_changed(
				$other,
				/* translators: %d: case id */
				sprintf( __( 'Case #%d on this order was answered after a shipping difference was typed in here — it was worked out again on what is left of the order; check it.', 'fishotel-misc-plugin' ), (int) $case['id'] )
			);
			if ( $fields && $this->store->transition( (int) $other['id'], array( 'open' ), 'open', $fields ) ) {
				$this->refresh( $this->store->get_case( (int) $other['id'] ) );
			}
		}
	}

	/**
	 * Hand a "try again" to the Requests section, once. Without that
	 * section (or when it says no) nothing is written and the case shows
	 * the reminder instead.
	 *
	 * @param int $case_id Case id.
	 * @param int $actor   Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function create_request( $case_id, $actor = 0 ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case || 'retry' !== $case['choice'] ) {
			return self::result( false, __( 'This case is not a "try again".', 'fishotel-misc-plugin' ), $case );
		}
		if ( '' !== $case['request_ref'] ) {
			return self::result( true, __( 'The request was already created.', 'fishotel-misc-plugin' ), $case );
		}
		if ( ! self::requests_available() ) {
			return self::result( false, __( 'The Requests section is not available — note this fish in your own list.', 'fishotel-misc-plugin' ), $case );
		}

		// The Requests section's own keys: who (a site customer, else a name and an e-mail), the fish (and
		// its product), its size, how many, that it is owed, and the order it came from. order_item_id and
		// lost_fish_case are extras a receiver may ignore.
		$args = array(
			'source'         => 'lost_fish',
			'owed'           => true,
			'user_id'        => (int) $case['customer_id'],
			'name'           => $case['customer_name'],
			'email'          => $case['customer_email'],
			'fish'           => '' !== $case['fish_name'] ? $case['fish_name'] : $case['species'],
			'product_id'     => (int) $case['product_id'],
			'size_slug'      => $case['size_slug'],
			'size_label'     => $case['size_label'],
			'qty'            => (int) $case['qty'],
			'order_id'       => (int) $case['order_id'],
			'order_item_id'  => (int) $case['order_item_id'],
			/* translators: %d: case id */
			'note'           => sprintf( __( 'Lost fish case #%d: already paid for, the customer chose to try again.', 'fishotel-misc-plugin' ), (int) $case['id'] ),
			'lost_fish_case' => (int) $case['id'],
		);
		if ( function_exists( 'apply_filters' ) ) {
			$args = (array) apply_filters( 'fishotel_lostfish_request_args', $args, $case );
		}

		$made = self::ask_requests( $args );
		if ( '' === $made['ref'] && ( '' !== (string) ( $args['size_slug'] ?? '' ) || '' !== (string) ( $args['size_label'] ?? '' ) ) ) {
			// A size the Requests section does not know must not cost the customer the request: once more,
			// the size in the note instead.
			$plain = $args;
			$size  = '' !== (string) ( $args['size_label'] ?? '' ) ? (string) $args['size_label'] : (string) $args['size_slug'];
			unset( $plain['size_slug'], $plain['size_label'] );
			/* translators: %s: size */
			$plain['note'] = trim( (string) ( $args['note'] ?? '' ) . ' ' . sprintf( __( 'Size: %s.', 'fishotel-misc-plugin' ), $size ) );
			$made          = self::ask_requests( $plain );
		}
		$ref = $made['ref'];

		if ( '' === $ref ) {
			$why = '' !== $made['error'] ? ' ' . sprintf( /* translators: %s: what the Requests section answered */ __( '(It said: %s)', 'fishotel-misc-plugin' ), $made['error'] ) : '';
			$this->log( 'request_failed', __( 'The Requests section did not take the "try again" — note this fish in Requests by hand.', 'fishotel-misc-plugin' ) . $why, (int) $case['id'], (int) $case['order_id'], $actor );
			return self::result( false, __( 'The Requests section did not take it — add the request there by hand.', 'fishotel-misc-plugin' ) . $why, $case );
		}

		$this->store->update_case( (int) $case['id'], array( 'request_ref' => $ref ) );
		$case = $this->store->get_case( (int) $case['id'] );
		/* translators: %s: request reference */
		$text = sprintf( __( 'A request for a new fish was created (%s): owed, already paid for.', 'fishotel-misc-plugin' ), 'created' === $ref ? __( 'in Requests', 'fishotel-misc-plugin' ) : '#' . $ref );
		$this->note( $case, $text, false );
		$this->log( 'request', $text, (int) $case['id'], (int) $case['order_id'], $actor );

		return self::result( true, __( 'Added to Requests.', 'fishotel-misc-plugin' ), $case );
	}

	/**
	 * Whether the Requests section can take a "try again": its function is
	 * declared — it is whenever this plugin is active — AND the section is
	 * switched on (switched off, the function answers an error and creates
	 * nothing).
	 *
	 * @return bool
	 */
	public static function requests_available() {
		if ( ! function_exists( 'fishotel_requests_create' ) ) {
			return false;
		}
		$section = '\\FisHotel\\Misc\\Sections\\Requests\\Requests';
		try {
			return ! class_exists( $section, false ) || ! method_exists( $section, 'enabled' ) || true === $section::enabled();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Call fishotel_requests_create() and read what it answers: an id (a
	 * number or text), a row or an object with an id, or true; a WP_Error,
	 * false, null or an exception mean it was not created.
	 *
	 * @param array $args The request.
	 * @return array{ref: string, error: string}
	 */
	private static function ask_requests( array $args ) {
		try {
			$made = fishotel_requests_create( $args );
		} catch ( \Throwable $e ) {
			return array(
				'ref'   => '',
				'error' => $e->getMessage(),
			);
		}

		$ref   = '';
		$error = '';
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $made ) ) {
			$error = method_exists( $made, 'get_error_message' ) ? (string) $made->get_error_message() : '';
		} elseif ( is_int( $made ) && $made > 0 ) {
			$ref = (string) $made;
		} elseif ( is_string( $made ) && '' !== trim( $made ) ) {
			$ref = trim( $made );
		} elseif ( is_array( $made ) && ! empty( $made['id'] ) && is_scalar( $made['id'] ) ) {
			$ref = (string) $made['id'];
		} elseif ( is_object( $made ) && ! ( $made instanceof \Throwable ) && ! empty( $made->id ) && is_scalar( $made->id ) ) {
			$ref = (string) $made->id;
		} elseif ( true === $made ) {
			$ref = 'created';
		}

		return array(
			'ref'   => $ref,
			'error' => substr( $error, 0, 200 ),
		);
	}

	/**
	 * The owner's tick: what had to be done after the answer is done.
	 *
	 * @param int    $case_id Case id.
	 * @param string $note    An optional note.
	 * @param int    $actor   Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function done( $case_id, $note = '', $actor = 0 ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ) );
		}
		$note = trim( (string) $note );
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 1000, 'UTF-8' ) : substr( $note, 0, 1000 );

		$moved = $this->store->transition(
			(int) $case['id'],
			array( 'answered' ),
			'done',
			array(
				'done_at'   => gmdate( 'Y-m-d H:i:s' ),
				'done_note' => $note,
			)
		);
		if ( ! $moved ) {
			return self::result( false, __( 'Only a case with an answer can be ticked Done.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ) );
		}

		$case = $this->store->get_case( (int) $case['id'] );
		$text = sprintf(
			/* translators: 1: the choice, 2: the note (may be empty) */
			__( 'Done: %1$s.%2$s', 'fishotel-misc-plugin' ),
			self::choice_label( $case['choice'] ),
			'' !== $note ? ' ' . $note : ''
		);
		$this->note( $case, $text, true );
		$this->log( 'done', $text, (int) $case['id'], (int) $case['order_id'], $actor );
		$this->store->refresh_counts();
		$this->fire( 'fishotel_lostfish_case_done', $case );

		return self::result( true, __( 'Ticked Done.', 'fishotel-misc-plugin' ), $case );
	}

	/**
	 * Reopen a case: the answer is forgotten, the old links stop working,
	 * the numbers are worked out afresh. Nothing is sent.
	 *
	 * @param int $case_id Case id.
	 * @param int $actor   Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function reopen( $case_id, $actor = 0 ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ) );
		}

		// An answer the owner may already have acted on: the case says what it was, and asks for a look.
		$check = array();
		if ( 'name' === $case['line_match'] ) {
			$check[] = __( 'Matched to this order line by NAME only — check that it is the same fish before sending.', 'fishotel-misc-plugin' );
		}
		if ( in_array( $case['status'], Cover::RELEASED, true ) && '' !== $case['choice'] ) {
			$order   = $this->orders->get( (int) $case['order_id'] );
			$amount  = self::summary( $case )['amount'];
			$check[] = sprintf(
				/* translators: 1: the choice, 2: its amount (may be empty), 3: whether it was ticked Done (may be empty) */
				__( 'Reopened after an answer: the customer had chosen to %1$s%2$s%3$s. Make sure that was not already paid out before asking again.', 'fishotel-misc-plugin' ),
				self::choice_label( $case['choice'] ),
				null !== $amount && 'retry' !== $case['choice'] ? ' — ' . Money::format( $amount, $order ? $this->orders->facts( $order )['symbol'] : '$' ) : '',
				'done' === $case['status'] ? __( ', and it was ticked Done', 'fishotel-misc-plugin' ) : ''
			);
		}

		$moved = $this->store->transition(
			(int) $case['id'],
			array( 'sent', 'answered', 'done', 'closed' ),
			'open',
			array(
				'choice'        => '',
				'choice_via'    => '',
				'answered_at'   => null,
				'done_at'       => null,
				'done_note'     => null,
				'closed_at'     => null,
				'close_reason'  => '',
				'sent_at'       => null,
				'reminded_at'   => null,
				'loss_at'       => null, // The owner took it back: nothing about it goes by itself any more.
				'token_salt'    => '',
				'link_expires'  => null,
				'amount_refund' => null,
				'amount_cancel' => null,
				'ship_manual'   => 0,
				'needs_check'   => $check ? 1 : 0,
				'check_note'    => implode( ' ', $check ),
			)
		);
		if ( ! $moved ) {
			return self::result( false, __( 'This case is already open.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ) );
		}

		$was  = $case;
		$case = $this->refresh( $this->store->get_case( (int) $case['id'] ) );
		$text = __( 'Reopened: the earlier answer is forgotten and the links of the earlier e-mail no longer work. Nothing was sent.', 'fishotel-misc-plugin' );
		if ( '' !== $was['request_ref'] ) {
			/* translators: %s: request reference */
			$text .= ' ' . sprintf( __( 'The request made for the earlier "try again" (%s) is still in Requests.', 'fishotel-misc-plugin' ), $was['request_ref'] );
		}
		$this->note( $case, $text, true );
		$this->log( 'reopened', $text, (int) $case['id'], (int) $case['order_id'], $actor );
		$this->store->refresh_counts();
		$this->fire( 'fishotel_lostfish_case_reopened', $case );

		return self::result( true, __( 'Reopened. Nothing was sent.', 'fishotel-misc-plugin' ), $case );
	}

	/**
	 * Close a case without an answer.
	 *
	 * @param int    $case_id Case id.
	 * @param string $reason  One of CLOSE_REASONS.
	 * @param int    $actor   Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function close( $case_id, $reason = 'other', $actor = 0 ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ) );
		}
		$reason = in_array( (string) $reason, self::CLOSE_REASONS, true ) ? (string) $reason : 'other';

		$moved = $this->store->transition(
			(int) $case['id'],
			array( 'open', 'sent', 'answered' ),
			'closed',
			array(
				'closed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'close_reason' => $reason,
			)
		);
		if ( ! $moved ) {
			return self::result( false, __( 'This case is already closed or done.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ) );
		}

		$case = $this->store->get_case( (int) $case['id'] );
		/* translators: %s: reason */
		$text = sprintf( __( 'Closed by hand (%s). Any link of an earlier e-mail now says the case is closed.', 'fishotel-misc-plugin' ), self::reason_label( $reason ) );
		$this->note( $case, $text, true );
		$this->log( 'closed', $text, (int) $case['id'], (int) $case['order_id'], $actor );
		$this->store->refresh_counts();
		$this->fire( 'fishotel_lostfish_case_closed', $case, $reason );

		return self::result( true, __( 'Closed.', 'fishotel-misc-plugin' ), $case );
	}

	/**
	 * The other holders an open case could be given to.
	 *
	 * @param array $case The case.
	 * @return array[] Ranked holders (Cover::alternatives()).
	 */
	public function alternatives( array $case ) {
		if ( 'open' !== $case['status'] || (int) $case['stay_id'] <= 0 ) {
			return array();
		}
		$stay = $this->rooms->stay( (int) $case['stay_id'] );
		if ( ! $stay ) {
			return array();
		}
		return Cover::alternatives( $case, $this->assess_stay( $stay )['holders'] );
	}

	/**
	 * Give an open case to another holder of the same fish.
	 *
	 * @param int    $case_id  Case id.
	 * @param int    $order_id The other holder's order.
	 * @param string $ref      The other holder's line.
	 * @param int    $actor    Who.
	 * @return array{ok: bool, message: string, case: array|null}
	 */
	public function reassign( $case_id, $order_id, $ref, $actor = 0 ) {
		$case = $this->store->get_case( (int) $case_id );
		if ( ! $case ) {
			return self::result( false, __( 'That case no longer exists.', 'fishotel-misc-plugin' ) );
		}
		if ( 'open' !== $case['status'] ) {
			return self::result( false, __( 'Only a case that was not sent yet can be given to another customer.', 'fishotel-misc-plugin' ), $case );
		}

		$holder = null;
		foreach ( $this->alternatives( $case ) as $candidate ) {
			if ( (int) $candidate['order_id'] === (int) $order_id && $candidate['ref'] === (string) $ref ) {
				$holder = $candidate;
				break;
			}
		}
		if ( ! $holder ) {
			return self::result( false, __( 'That order line is not (or no longer) owed this fish.', 'fishotel-misc-plugin' ), $case );
		}

		$check = array();
		if ( 'name' === $holder['match'] ) {
			$check[] = __( 'Matched to this order line by NAME only — check that it is the same fish before sending.', 'fishotel-misc-plugin' );
		}
		if ( empty( $holder['paid_known'] ) ) {
			$check[] = __( 'What was paid for this fish is not on the order line — type it in.', 'fishotel-misc-plugin' );
		}

		$moved = $this->store->transition(
			(int) $case['id'],
			array( 'open' ),
			'open',
			array(
				'order_id'       => (int) $holder['order_id'],
				'order_item_id'  => (int) $holder['item_id'],
				'line_ref'       => (string) $holder['ref'],
				'line_match'     => (string) $holder['match'],
				'fish_name'      => '' !== trim( (string) $holder['name'] ) ? (string) $holder['name'] : $case['fish_name'],
				'customer_id'    => (int) $holder['customer_id'],
				'customer_name'  => (string) $holder['customer_name'],
				'customer_email' => (string) $holder['customer_email'],
				'currency'       => (string) $holder['currency'],
				'unit_paid'      => max( 0, (int) $holder['unit_paid'] ),
				'paid_known'     => ! empty( $holder['paid_known'] ) ? 1 : 0,
				'ship_manual'    => 0,
				'ship_diff'      => null,
				'ship_status'    => Shipping::UNKNOWN,
				'needs_check'    => $check ? 1 : 0,
				'check_note'     => implode( ' ', $check ),
			)
		);
		if ( ! $moved ) {
			return self::result( false, __( 'Only a case that was not sent yet can be given to another customer.', 'fishotel-misc-plugin' ), $this->store->get_case( (int) $case['id'] ) );
		}

		$new = $this->refresh( $this->store->get_case( (int) $case['id'] ) );
		/* translators: %s: order number */
		$this->note( $case, sprintf( __( 'Moved to order #%s: this order keeps its fish.', 'fishotel-misc-plugin' ), (string) $holder['order_number'] ), true );
		/* translators: %s: 1 × fish */
		$text = sprintf( __( 'Given to this order by hand: %s was lost and there is no spare. Nothing was sent to the customer yet.', 'fishotel-misc-plugin' ), Email::fish_line( $new ) );
		$this->note( $new, $text, true );
		$this->log( 'reassigned', sprintf( /* translators: 1: old order id, 2: new order id */ __( 'Moved from order #%1$d to order #%2$d.', 'fishotel-misc-plugin' ), (int) $case['order_id'], (int) $new['order_id'] ), (int) $new['id'], (int) $new['order_id'], $actor );

		return self::result( true, __( 'The case now belongs to the other order.', 'fishotel-misc-plugin' ), $new );
	}

	/* ============================================================== */
	/*  What the screens read                                          */
	/* ============================================================== */

	/**
	 * Whether a sent case got no answer: its reminder went out, or its
	 * links are past their life.
	 *
	 * @param array $case The case.
	 * @param int   $now  UNIX timestamp.
	 * @return bool
	 */
	public static function is_stale( array $case, $now = 0 ) {
		if ( 'sent' !== $case['status'] ) {
			return false;
		}
		if ( null !== $case['reminded_at'] ) {
			return true;
		}
		$expires = null !== $case['link_expires'] ? strtotime( $case['link_expires'] . ' UTC' ) : false;

		return false !== $expires && $expires < ( $now > 0 ? (int) $now : time() );
	}

	/**
	 * Which group of the screen a case belongs to: needs (send it, act on
	 * the answer, no answer came), waiting, or finished.
	 *
	 * @param array $case The case.
	 * @param int   $now  UNIX timestamp.
	 * @return string needs | waiting | finished.
	 */
	public static function bucket( array $case, $now = 0 ) {
		if ( in_array( $case['status'], array( 'open', 'answered' ), true ) || self::is_stale( $case, $now ) ) {
			return 'needs';
		}
		return 'sent' === $case['status'] ? 'waiting' : 'finished';
	}

	/**
	 * What the owner has to do after the answer — the section does none
	 * of it.
	 *
	 * @param array      $case  The answered case.
	 * @param array|null $facts Orders::facts().
	 * @return array{text: string, detail: string, url: string, link: string, note: string}
	 */
	public function todo( array $case, $facts = null ) {
		$symbol  = is_array( $facts ) ? $facts['symbol'] : '$';
		$number  = is_array( $facts ) ? $facts['number'] : (string) $case['order_id'];
		$url     = is_array( $facts ) ? $facts['edit_url'] : '';
		$numbers = $this->numbers( $case, $facts );
		$amount  = Money::amount_for( $case['choice'], $numbers );
		$money   = Money::format( $amount, $symbol );
		$detail  = '';
		if ( in_array( $case['choice'], array( 'refund', 'credit' ), true ) && (int) $numbers['ship_diff'] > 0 ) {
			$detail = sprintf(
				/* translators: 1: paid for the fish, 2: shipping difference */
				__( '%1$s for the fish, less %2$s shipping difference.', 'fishotel-misc-plugin' ),
				Money::format( $numbers['fish_paid'], $symbol ),
				Money::format( $numbers['ship_diff'], $symbol )
			);
		}

		$out = array(
			'text'   => '',
			'detail' => $detail,
			'url'    => $url,
			/* translators: %s: order number */
			'link'   => sprintf( __( 'Open order #%s', 'fishotel-misc-plugin' ), $number ),
			'note'   => '',
		);

		switch ( $case['choice'] ) {
			case 'refund':
				/* translators: 1: amount, 2: order number */
				$out['text'] = sprintf( __( 'Refund %1$s on order #%2$s.', 'fishotel-misc-plugin' ), $money, $number );
				break;

			case 'credit':
				/* translators: %s: amount */
				$out['text'] = sprintf( __( 'Add %s to the customer\'s wallet.', 'fishotel-misc-plugin' ), $money );
				if ( class_exists( '\\FisHotel_Batch_Manager' ) ) {
					$out['url']  = add_query_arg(
						array(
							'page' => 'fishotel-wallets',
							's'    => $case['customer_email'],
						),
						admin_url( 'admin.php' )
					);
					$out['link'] = __( 'Open the wallets screen', 'fishotel-misc-plugin' );
				} else {
					$out['note'] = __( 'The Batch Manager\'s wallets screen is not available here — add the credit your own way.', 'fishotel-misc-plugin' );
				}
				if ( (int) $case['customer_id'] <= 0 ) {
					$out['note'] = trim( $out['note'] . ' ' . __( 'This order was placed without an account, so there is no wallet yet.', 'fishotel-misc-plugin' ) );
				}
				break;

			case 'cancel':
				/* translators: 1: order number, 2: amount */
				$out['text'] = sprintf( __( 'Cancel order #%1$s and refund %2$s.', 'fishotel-misc-plugin' ), $number, $money );
				$ledger      = $this->ledger( $case );
				$said        = array();
				if ( is_array( $facts ) && (int) $facts['refunded'] > 0 ) {
					/* translators: %s: amount */
					$said[] = sprintf( __( '%s was already refunded on the order', 'fishotel-misc-plugin' ), Money::format( (int) $facts['refunded'], $symbol ) );
				}
				if ( $ledger['credited'] > 0 ) {
					/* translators: 1: amount, 2: case numbers */
					$said[] = sprintf( __( '%1$s was already given as store credit (case %2$s)', 'fishotel-misc-plugin' ), Money::format( $ledger['credited'], $symbol ), '#' . implode( ', #', $ledger['credits'] ) );
				}
				if ( $said && is_array( $facts ) ) {
					/* translators: 1: order total, 2: what already went back */
					$out['detail'] = sprintf( __( 'The order came to %1$s; %2$s.', 'fishotel-misc-plugin' ), Money::format( (int) $facts['total'], $symbol ), implode( __( ', and ', 'fishotel-misc-plugin' ), $said ) );
				}
				foreach ( $ledger['refunds_due'] as $other_id => $due ) {
					/* translators: 1: case id, 2: amount */
					$out['note'] = trim( $out['note'] . ' ' . sprintf( __( 'Case #%1$d on this order still asks for a refund of %2$s: that money is PART of this amount — do not pay it twice.', 'fishotel-misc-plugin' ), (int) $other_id, Money::format( $due, $symbol ) ) );
				}
				break;

			case 'retry':
				$out['text'] = __( 'Get the customer another one. Nothing is refunded and the order\'s shipping stays as it is.', 'fishotel-misc-plugin' );
				if ( '' !== $case['request_ref'] ) {
					/* translators: %s: request reference */
					$out['note'] = sprintf( __( 'A request was created in Requests (%s): owed, already paid for.', 'fishotel-misc-plugin' ), 'created' === $case['request_ref'] ? __( 'done', 'fishotel-misc-plugin' ) : '#' . $case['request_ref'] );
				} elseif ( self::requests_available() ) {
					$out['note'] = __( 'No request was created yet — use "Add to Requests".', 'fishotel-misc-plugin' );
				} else {
					/* translators: 1: 1 × fish, 2: customer */
					$out['note'] = sprintf( __( 'The Requests section is not available: note it yourself — %1$s for %2$s, already paid for.', 'fishotel-misc-plugin' ), Email::fish_line( $case ), '' !== $case['customer_name'] ? $case['customer_name'] : $case['customer_email'] );
				}
				break;

			default:
				break;
		}

		return $out;
	}

	/**
	 * A case as other sections read it.
	 *
	 * @param array $case The case.
	 * @return array{id: int, status: string, fish: string, qty: int, order_id: int, order_item_id: int, choice: string, amount: int|null, url: string}
	 */
	public static function summary( array $case ) {
		$amount = null;
		if ( 'refund' === $case['choice'] || 'credit' === $case['choice'] ) {
			$amount = null !== $case['amount_refund'] ? (int) $case['amount_refund'] : null;
		} elseif ( 'cancel' === $case['choice'] ) {
			$amount = null !== $case['amount_cancel'] ? (int) $case['amount_cancel'] : null;
		} elseif ( 'retry' === $case['choice'] ) {
			$amount = 0;
		}

		return array(
			'id'            => (int) $case['id'],
			'status'        => (string) $case['status'],
			'fish'          => (string) $case['fish_name'],
			'qty'           => (int) $case['qty'],
			'order_id'      => (int) $case['order_id'],
			'order_item_id' => (int) $case['order_item_id'],
			'choice'        => (string) $case['choice'],
			'amount'        => $amount,
			'url'           => self::case_url( (int) $case['id'] ),
		);
	}

	/**
	 * The case on the Lost Fish screen.
	 *
	 * @param int $case_id Case id.
	 * @return string
	 */
	public static function case_url( $case_id ) {
		return add_query_arg( array( 'page' => Lost_Fish::MENU_SLUG ), admin_url( 'admin.php' ) ) . '#fh-lf-case-' . (int) $case_id;
	}

	/**
	 * A choice for a person.
	 *
	 * @param string $choice refund | credit | retry | cancel.
	 * @return string
	 */
	public static function choice_label( $choice ) {
		switch ( (string) $choice ) {
			case 'refund':
				return __( 'refund the lost fish', 'fishotel-misc-plugin' );
			case 'credit':
				return __( 'take a store credit', 'fishotel-misc-plugin' );
			case 'retry':
				return __( 'try again', 'fishotel-misc-plugin' );
			case 'cancel':
				return __( 'cancel the whole order', 'fishotel-misc-plugin' );
			default:
				return '';
		}
	}

	/**
	 * A close reason for a person.
	 *
	 * @param string $reason One of CLOSE_REASONS.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		switch ( (string) $reason ) {
			case 'mistake':
				return __( 'not a real loss, or the wrong order', 'fishotel-misc-plugin' );
			case 'settled':
				return __( 'settled with the customer directly', 'fishotel-misc-plugin' );
			case 'loss_undone':
				return __( 'the loss was undone', 'fishotel-misc-plugin' );
			case 'order_cancelled':
				return __( 'the whole order was cancelled', 'fishotel-misc-plugin' );
			default:
				return __( 'another reason', 'fishotel-misc-plugin' );
		}
	}

	/* ============================================================== */
	/*  Small helpers                                                  */
	/* ============================================================== */

	/**
	 * A kind for a person: the species, with its size.
	 *
	 * @param array $kind Cover::kind_of_stay().
	 * @return string
	 */
	private static function kind_label( array $kind ) {
		return trim( (string) $kind['species'] ) . ( '' !== (string) $kind['size_label'] ? ' (' . $kind['size_label'] . ')' : '' );
	}

	/**
	 * " (tank 121)" when the group's tank is known.
	 *
	 * @param array $stay The tank group.
	 * @return string
	 */
	private static function tank_label( array $stay ) {
		/* translators: %d: tank number */
		return ! empty( $stay['tank_number'] ) ? ' ' . sprintf( __( '(tank %d)', 'fishotel-misc-plugin' ), (int) $stay['tank_number'] ) : '';
	}

	/**
	 * A private note on the case's order, prefixed with the case.
	 *
	 * @param array  $case    The case.
	 * @param string $text    The note.
	 * @param bool   $by_user Whether the current user is its author.
	 */
	private function note( array $case, $text, $by_user ) {
		/* translators: 1: case id, 2: the note */
		$this->orders->note( (int) $case['order_id'], sprintf( __( 'Lost fish case #%1$d — %2$s', 'fishotel-misc-plugin' ), (int) $case['id'], (string) $text ), (bool) $by_user );
	}

	/**
	 * A line in the section's log, and in the Health log when that
	 * section listens.
	 *
	 * @param string $event    Machine word.
	 * @param string $message  For a person.
	 * @param int    $case_id  Case id.
	 * @param int    $order_id Order id.
	 * @param int    $actor    Who.
	 */
	private function log( $event, $message, $case_id = 0, $order_id = 0, $actor = 0 ) {
		$this->store->add_log( $event, $message, (int) $case_id, (int) $order_id, (int) $actor );

		if ( function_exists( 'do_action' ) ) {
			do_action(
				'fishotel_log',
				in_array( $event, array( 'send_failed', 'reminder_failed', 'request_failed', 'undone_late' ), true ) ? 'warning' : 'info',
				'lost-fish',
				( $case_id > 0 ? 'Case #' . (int) $case_id . ': ' : '' ) . $message,
				array(
					'event' => $event,
					'order' => (int) $order_id,
				)
			);
		}
	}

	/**
	 * Fire one of the section's actions; a listener that throws is not
	 * allowed to undo what already happened.
	 *
	 * @param string $hook    Action name.
	 * @param mixed  ...$args Arguments.
	 */
	private function fire( $hook, ...$args ) {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		try {
			do_action( $hook, ...$args );
		} catch ( \Throwable $e ) {
			self::trouble( $hook, $e );
		}
	}

	/**
	 * Something went wrong where nothing may be thrown: the Health log
	 * hears about it, nobody else is disturbed.
	 *
	 * @param string     $where Where.
	 * @param \Throwable $e     What.
	 */
	private static function trouble( $where, $e ) {
		if ( function_exists( 'do_action' ) ) {
			try {
				do_action( 'fishotel_log', 'error', 'lost-fish', $where . ': ' . $e->getMessage(), array( 'file' => basename( $e->getFile() ) . ':' . $e->getLine() ) );
			} catch ( \Throwable $again ) {
				unset( $again );
			}
		}
	}

	/**
	 * A result.
	 *
	 * @param bool       $ok      Whether it worked.
	 * @param string     $message For a person.
	 * @param array|null $case    The case as it is now.
	 * @param string     $code    A machine word.
	 * @return array{ok: bool, message: string, case: array|null, code: string}
	 */
	private static function result( $ok, $message, $case = null, $code = '' ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
			'case'    => is_array( $case ) ? $case : null,
			'code'    => '' !== $code ? (string) $code : ( $ok ? 'ok' : 'error' ),
		);
	}
}
