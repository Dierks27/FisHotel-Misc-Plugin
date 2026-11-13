<?php
/**
 * Gift Cards importer — copies every card, its balance and its whole
 * history from the old plugin's tables (Legacy_Map), so customers'
 * accounts look the same and old orders keep their deduction.
 *
 *   1. Schema check: the old tables must have EXACTLY the columns
 *      Legacy_Map::COLUMNS names, of the same kind. Any column missing,
 *      extra or different stops everything, and each one is named.
 *   2. Dry run: reads everything, writes nothing (but its report): the
 *      number of cards, what is still owed on them to the cent (active and
 *      disabled apart), and every difference — a stored balance that its
 *      history does not add up to, duplicate codes, amounts with a
 *      fraction of a cent, owners who are no longer users, orders that are
 *      gone, expiry dates, e-mails still waiting for their day, activity
 *      types this map does not know.
 *   3. Import: one transaction per card. Copy only — the old tables are
 *      only ever read (SELECT / SHOW, enforced in the Store and by a test)
 *      and stay exactly as they are, as the backup. Safe to run again: a
 *      card or activity row already copied is recognised by its old id; a
 *      card whose old balance moved since (the old plugin still in use in
 *      Shadow mode) is brought up to date, unless it has been used in this
 *      system since — then it is listed and left alone.
 *   4. Report: card count and outstanding of the copies against the old
 *      tables, to the cent; reconciled only when they match exactly.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Importer
 */
class Importer {

	const REPORT_OPTION = 'fishotel_gift_cards_import_report';
	const IMPORT_OPTION = 'fishotel_gift_cards_last_import';
	const CHUNK         = 500;
	const MAX_LISTED    = 2000;

	/**
	 * The differences that stop an import.
	 *
	 * @var string[]
	 */
	const BLOCKING = array( 'schema', 'duplicate_code', 'bad_amount', 'negative_balance', 'unknown_type', 'empty_code', 'code_taken' );

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Service.
	 *
	 * @var Service
	 */
	private $service;

	/**
	 * order_exists( int ): bool.
	 *
	 * @var array<string, callable>
	 */
	private $world;

	/**
	 * Build.
	 *
	 * @param Store   $store   Store.
	 * @param Service $service Service.
	 * @param array   $world   order_exists( int $id ): bool.
	 */
	public function __construct( Store $store, Service $service, array $world = array() ) {
		$this->store   = $store;
		$this->service = $service;
		$this->world   = $world;
	}

	/* ============================================================== */
	/*  1. The schema check                                            */
	/* ============================================================== */

	/**
	 * The kind of a MySQL column type (pure).
	 *
	 * @param string $type As SHOW COLUMNS reports it.
	 * @return string int|decimal|text|datetime|other
	 */
	public static function family( $type ) {
		$type = strtolower( trim( (string) $type ) );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type ) ) {
			return 'int';
		}
		if ( preg_match( '/^(decimal|numeric|double|float|real)\b/', $type ) ) {
			return 'decimal';
		}
		if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/', $type ) ) {
			return 'text';
		}
		if ( preg_match( '/^(datetime|timestamp|date)\b/', $type ) ) {
			return 'datetime';
		}
		return 'other';
	}

	/**
	 * Every difference between the expected and the real columns (pure).
	 *
	 * @param string                    $table    Table key (cards|activity).
	 * @param array<string, string>     $expected Column => kind.
	 * @param array<string, string>|null $present Column => MySQL type (null = the table is not there).
	 * @return array[] kind schema, table, column, detail.
	 */
	public static function compare( $table, array $expected, $present ) {
		$name = Legacy_Map::TABLES[ $table ] ?? $table;
		if ( null === $present ) {
			return array(
				self::difference(
					'schema',
					0,
					/* translators: %s: table */
					sprintf( __( 'Table %s is missing.', 'fishotel-misc-plugin' ), $name ),
					array( 'table' => $name )
				),
			);
		}
		$out = array();
		foreach ( $expected as $column => $kind ) {
			if ( ! array_key_exists( $column, $present ) ) {
				/* translators: 1: table, 2: column, 3: kind */
				$out[] = self::difference( 'schema', 0, sprintf( __( '%1$s: column "%2$s" (%3$s) is missing.', 'fishotel-misc-plugin' ), $name, $column, $kind ), array( 'table' => $name, 'column' => $column ) );
				continue;
			}
			$found = self::family( $present[ $column ] );
			if ( $found !== $kind ) {
				/* translators: 1: table, 2: column, 3: kind, 4: MySQL type */
				$out[] = self::difference( 'schema', 0, sprintf( __( '%1$s: column "%2$s" should be %3$s but is %4$s.', 'fishotel-misc-plugin' ), $name, $column, $kind, $present[ $column ] ), array( 'table' => $name, 'column' => $column ) );
			}
		}
		foreach ( $present as $column => $type ) {
			if ( ! array_key_exists( $column, $expected ) ) {
				/* translators: 1: table, 2: column, 3: MySQL type */
				$out[] = self::difference( 'schema', 0, sprintf( __( '%1$s: column "%2$s" (%3$s) is there but not in the map — add it to Legacy_Map::COLUMNS once you know what it holds.', 'fishotel-misc-plugin' ), $name, $column, $type ), array( 'table' => $name, 'column' => $column ) );
			}
		}
		return $out;
	}

	/**
	 * A difference.
	 *
	 * @param string $kind   Kind.
	 * @param int    $card   Old card id (0 = none).
	 * @param string $detail Plain words.
	 * @param array  $extra  More.
	 * @return array
	 */
	private static function difference( $kind, $card, $detail, array $extra = array() ) {
		return array_merge(
			array(
				'kind'     => (string) $kind,
				'card'     => (int) $card,
				'detail'   => (string) $detail,
				'blocking' => in_array( $kind, self::BLOCKING, true ),
			),
			$extra
		);
	}

	/**
	 * Whether the old tables are there, and their shape against the map.
	 *
	 * @return array{found: bool, ok: bool, tables: array, differences: array[]}
	 */
	public function check() {
		$tables      = array();
		$differences = array();
		$found       = false;
		foreach ( Legacy_Map::TABLES as $key => $name ) {
			$exists = $this->store->legacy_exists( $name );
			$found  = $found || $exists;
			$cols   = $exists ? $this->store->legacy_columns( $name ) : null;
			$tables[ $key ] = array(
				'table'   => $this->store->legacy_table( $name ),
				'exists'  => $exists,
				'columns' => is_array( $cols ) ? count( $cols ) : 0,
			);
			$differences = array_merge( $differences, self::compare( $key, Legacy_Map::COLUMNS[ $key ], $cols ) );
		}
		return array(
			'found'       => $found,
			'ok'          => $found && ! $differences,
			'tables'      => $tables,
			'differences' => $found ? $differences : array(),
		);
	}

	/**
	 * Whether the old tables exist, and how big they are (the Live guard).
	 *
	 * @return array{exists: bool, cards: array, activity: array}
	 */
	public function old_state() {
		$out = array( 'exists' => false );
		foreach ( Legacy_Map::TABLES as $key => $name ) {
			$size        = $this->store->legacy_exists( $name ) ? $this->store->legacy_size( $name ) : null;
			$out[ $key ] = is_array( $size ) ? $size : array(
				'rows'   => 0,
				'max_id' => 0,
			);
			$out['exists'] = $out['exists'] || is_array( $size );
		}
		return $out;
	}

	/* ============================================================== */
	/*  Converting rows (pure)                                         */
	/* ============================================================== */

	/**
	 * An old value by its role.
	 *
	 * @param array  $row   Old row.
	 * @param string $table cards|activity.
	 * @param string $role  A ROLES key.
	 * @return mixed|null
	 */
	private static function role( array $row, $table, $role ) {
		$column = Legacy_Map::ROLES[ $table ][ $role ] ?? '';
		return '' !== $column && array_key_exists( $column, $row ) ? $row[ $column ] : null;
	}

	/**
	 * An old date as 'Y-m-d H:i:s' UTC, '' for none (pure).
	 *
	 * @param mixed $value Old value.
	 * @return string
	 */
	public static function date( $value ) {
		if ( null === $value || '' === (string) $value || '0' === (string) $value || '0000-00-00 00:00:00' === (string) $value ) {
			return '';
		}
		if ( 'unix' === Legacy_Map::DATES && preg_match( '/^\d+$/', (string) $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		$ts = strtotime( (string) $value . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * An old card as new card fields, and what is wrong with it (pure).
	 *
	 * @param array $row      Old card row.
	 * @param array $settings Settings.
	 * @param int   $now      UNIX time.
	 * @return array{fields: array, problems: array[]}
	 */
	public static function convert_card( array $row, array $settings, $now ) {
		$id       = (int) self::role( $row, 'cards', 'legacy_id' );
		$problems = array();

		$code = trim( (string) self::role( $row, 'cards', 'code' ) );
		if ( '' === Codes::key( $code ) ) {
			$problems[] = self::difference( 'empty_code', $id, __( 'The card has no usable code.', 'fishotel-misc-plugin' ) );
		}

		$amount  = Money::decimal_to_cents( self::role( $row, 'cards', 'amount' ) ?? '0' );
		$balance = Money::decimal_to_cents( self::role( $row, 'cards', 'balance' ) ?? '0' );
		if ( null === $amount || null === $balance ) {
			$problems[] = self::difference( 'bad_amount', $id, __( 'An amount is not a number in dollars and cents (a fraction of a cent, or text).', 'fishotel-misc-plugin' ) );
			$amount     = (int) $amount;
			$balance    = (int) $balance;
		} elseif ( $balance < 0 || $amount < 0 ) {
			$problems[] = self::difference( 'negative_balance', $id, __( 'The card has a negative amount.', 'fishotel-misc-plugin' ) );
		} elseif ( $balance > $amount ) {
			/* translators: 1: left, 2: issued */
			$problems[] = self::difference( 'left_more_than_issued', $id, sprintf( __( 'More is left on it (%1$s) than it was issued for (%2$s) — topped up by hand, or the map has "balance" and "remaining" the wrong way round.', 'fishotel-misc-plugin' ), Money::words( $balance ), Money::words( $amount ) ) );
		}

		$active  = in_array( strtolower( trim( (string) self::role( $row, 'cards', 'active' ) ) ), Legacy_Map::ACTIVE_VALUES, true );
		$expires = self::date( self::role( $row, 'cards', 'expires_at' ) );
		if ( '' !== $expires ) {
			/* translators: %s: date */
			$problems[] = self::difference( 'expiry', $id, sprintf( __( 'It carries an expiry date (%s UTC). It is kept and shown, never enforced.', 'fishotel-misc-plugin' ), $expires ) );
		}

		$deliver   = self::date( self::role( $row, 'cards', 'deliver_at' ) );
		$delivered = strtolower( trim( (string) self::role( $row, 'cards', 'delivered' ) ) );
		$went      = ! in_array( $delivered, Legacy_Map::NOT_DELIVERED_VALUES, true );
		$state     = $went ? 'sent' : 'none';
		if ( ! $went && '' !== $deliver && strtotime( $deliver . ' UTC' ) > (int) $now && $active ) {
			$state = Settings::on( $settings, 'send_imported_pending' ) ? 'pending' : 'none';
			/* translators: %s: date */
			$problems[] = self::difference( 'future_delivery', $id, sprintf( __( 'Its e-mail is still waiting for its day (%s UTC).', 'fishotel-misc-plugin' ), $deliver ) . ' ' . ( 'pending' === $state ? __( 'It will be sent by this section.', 'fishotel-misc-plugin' ) : __( 'It will NOT be sent (Settings: send the e-mail of imported cards whose delivery date is still ahead).', 'fishotel-misc-plugin' ) ) );
		}

		$owner = self::role( $row, 'cards', 'owner_user_id' );
		$email = strtolower( trim( (string) self::role( $row, 'cards', 'recipient_email' ) ) );

		return array(
			'fields'   => array(
				'legacy_id'         => $id,
				'code'              => $code,
				'status'            => $active ? 'active' : 'disabled',
				'amount_cents'      => (int) $amount,
				'balance_cents'     => (int) $balance,
				'owner_user_id'     => is_numeric( $owner ) ? max( 0, (int) $owner ) : 0,
				'redeemed_at'       => self::date( self::role( $row, 'cards', 'redeemed_at' ) ),
				'recipient_name'    => substr( trim( (string) self::role( $row, 'cards', 'recipient_name' ) ), 0, 120 ),
				'recipient_email'   => is_email( $email ) ? substr( $email, 0, 190 ) : '',
				'sender_name'       => substr( trim( (string) self::role( $row, 'cards', 'sender_name' ) ), 0, 120 ),
				'sender_email'      => substr( strtolower( trim( (string) self::role( $row, 'cards', 'sender_email' ) ) ), 0, 190 ),
				'message'           => (string) self::role( $row, 'cards', 'message' ),
				'order_id'          => (int) self::role( $row, 'cards', 'order_id' ),
				'created_at'        => self::date( self::role( $row, 'cards', 'created_at' ) ),
				'deliver_at'        => $deliver,
				'delivered_at'      => $went && preg_match( '/^\d{9,}$/', $delivered ) ? self::date( $delivered ) : '',
				'email_state'       => $state,
				'legacy_expires_at' => $expires,
				'last_used_at'      => '',
			),
			'problems' => $problems,
		);
	}

	/**
	 * An old activity row as a ledger row, and what is wrong with it (pure).
	 *
	 * @param array $row Old activity row.
	 * @return array{fields: array, problems: array[]}
	 */
	public static function convert_activity( array $row ) {
		$card     = (int) self::role( $row, 'activity', 'card_id' );
		$type     = strtolower( trim( (string) self::role( $row, 'activity', 'type' ) ) );
		$problems = array();
		$map      = Legacy_Map::ACTIVITY_TYPES[ $type ] ?? null;
		if ( null === $map ) {
			/* translators: %s: type */
			$problems[] = self::difference( 'unknown_type', $card, sprintf( __( 'Activity type "%s" is not in the map (Legacy_Map::ACTIVITY_TYPES): what it does to a balance is not known.', 'fishotel-misc-plugin' ), $type ) );
			$map        = array( 'import_note', 0 );
		}
		$amount = Money::decimal_to_cents( self::role( $row, 'activity', 'amount' ) ?? '0' );
		if ( null === $amount ) {
			$problems[] = self::difference( 'bad_amount', $card, __( 'An activity amount is not a number in dollars and cents.', 'fishotel-misc-plugin' ) );
			$amount     = 0;
		}
		$note = trim( (string) self::role( $row, 'activity', 'note' ) );
		return array(
			'fields'   => array(
				'legacy_activity_id' => (int) self::role( $row, 'activity', 'legacy_id' ),
				'legacy_card_id'     => $card,
				'type'               => $map[0],
				'amount_cents'       => (int) $map[1] * abs( (int) $amount ),
				'user_id'            => max( 0, (int) self::role( $row, 'activity', 'user_id' ) ),
				'order_id'           => max( 0, (int) self::role( $row, 'activity', 'order_id' ) ),
				'note'               => trim( 'old: ' . $type . ( '' !== $note ? ' — ' . $note : '' ) ),
				'created_at'         => self::date( self::role( $row, 'activity', 'date' ) ),
			),
			'problems' => $problems,
		);
	}

	/* ============================================================== */
	/*  2. Reading and the dry run                                     */
	/* ============================================================== */

	/**
	 * Read both old tables in chunks and convert every row.
	 *
	 * @param int $now UNIX time.
	 * @return array{cards: array<int, array>, activity: array<int, array[]>, problems: array[], orphans: int}|null
	 */
	private function read( $now ) {
		$settings = $this->service->settings;
		$cards    = array();
		$problems = array();
		$after    = 0;
		do {
			$rows = $this->store->legacy_rows( Legacy_Map::TABLES['cards'], $after, self::CHUNK );
			if ( null === $rows ) {
				return null;
			}
			foreach ( $rows as $row ) {
				$c                                     = self::convert_card( $row, $settings, $now );
				$cards[ (int) $c['fields']['legacy_id'] ] = $c['fields'];
				$problems                              = array_merge( $problems, $c['problems'] );
				$after                                 = max( $after, (int) self::role( $row, 'cards', 'legacy_id' ) );
			}
		} while ( count( $rows ) === self::CHUNK );

		$activity = array();
		$orphans  = 0;
		$after    = 0;
		do {
			$rows = $this->store->legacy_rows( Legacy_Map::TABLES['activity'], $after, self::CHUNK );
			if ( null === $rows ) {
				return null;
			}
			foreach ( $rows as $row ) {
				$a     = self::convert_activity( $row );
				$after = max( $after, (int) self::role( $row, 'activity', 'legacy_id' ) );
				$card  = (int) $a['fields']['legacy_card_id'];
				if ( ! isset( $cards[ $card ] ) ) {
					$orphans++;
					continue;
				}
				$activity[ $card ][] = $a['fields'];
				$problems            = array_merge( $problems, $a['problems'] );
			}
		} while ( count( $rows ) === self::CHUNK );

		return array(
			'cards'    => $cards,
			'activity' => $activity,
			'problems' => $problems,
			'orphans'  => $orphans,
		);
	}

	/**
	 * Everything the dry run and the import know before writing.
	 *
	 * @return array The report so far (ok false when it cannot go on).
	 */
	private function analyse() {
		$now    = $this->service->now();
		$check  = $this->check();
		$report = array(
			'at'          => $now,
			'kind'        => 'dry_run',
			'ok'          => false,
			'found'       => $check['found'],
			'tables'      => $check['tables'],
			'differences' => $check['differences'],
			'counts'      => array(
				'cards'    => 0,
				'active'   => 0,
				'disabled' => 0,
				'activity' => 0,
				'orphans'  => 0,
			),
			'outstanding' => array(
				'active'   => 0,
				'disabled' => 0,
				'total'    => 0,
			),
			'legacy_max'  => $this->old_state(),
		);
		if ( ! $check['found'] ) {
			$report['differences'][] = self::difference( 'schema', 0, __( 'The old plugin\'s tables are not on this site: there is nothing to import.', 'fishotel-misc-plugin' ) );
			return $report;
		}
		if ( ! $check['ok'] ) {
			return $report;
		}

		$data = $this->read( $now );
		if ( null === $data ) {
			$report['differences'][] = self::difference( 'schema', 0, __( 'The old tables could not be read.', 'fishotel-misc-plugin' ) );
			return $report;
		}
		$diff = $data['problems'];

		// Codes: two old cards with the same code, or an old code a card made here already has.
		$keys = array();
		foreach ( $data['cards'] as $id => $card ) {
			$key = Codes::key( $card['code'] );
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $keys[ $key ] ) ) {
				/* translators: %d: other old card id */
				$diff[] = self::difference( 'duplicate_code', $id, sprintf( __( 'It has the same code as old card #%d.', 'fishotel-misc-plugin' ), $keys[ $key ] ) );
			}
			$keys[ $key ] = $id;
			$mine         = $this->store->card_by_key( $key );
			if ( $mine && (int) $mine['legacy_id'] !== (int) $id ) {
				$diff[] = self::difference( 'code_taken', $id, __( 'A card made in this system already has its code.', 'fishotel-misc-plugin' ) );
			}
		}

		// Owners who are no longer users, orders that are gone.
		$owners = array_filter( array_map( 'intval', array_column( $data['cards'], 'owner_user_id' ) ) );
		$users  = $this->store->existing_users( $owners );
		$orders = array();
		foreach ( $data['cards'] as $id => $card ) {
			if ( $card['owner_user_id'] > 0 && ! in_array( (int) $card['owner_user_id'], $users, true ) ) {
				/* translators: %d: user id */
				$diff[]                                     = self::difference( 'owner_missing', $id, sprintf( __( 'It was in the account of user #%d, who is no longer a user. It is copied into nobody\'s account (it can be added again by its code).', 'fishotel-misc-plugin' ), (int) $card['owner_user_id'] ) );
				$data['cards'][ $id ]['owner_user_id'] = 0;
			}
			foreach ( $data['activity'][ $id ] ?? array() as $act ) {
				if ( 'used' === $act['type'] && $act['order_id'] > 0 ) {
					$orders[ (int) $act['order_id'] ] = $id;
				}
			}
		}
		if ( isset( $this->world['order_exists'] ) ) {
			foreach ( $orders as $order_id => $card_id ) {
				if ( ! call_user_func( $this->world['order_exists'], (int) $order_id ) ) {
					/* translators: %d: order id */
					$diff[] = self::difference( 'order_missing', $card_id, sprintf( __( 'It was used on order #%d, which is no longer on the site. The history is copied anyway.', 'fishotel-misc-plugin' ), (int) $order_id ) );
				}
			}
		}

		// Each card: its stored balance against what its history adds up to.
		foreach ( $data['cards'] as $id => $card ) {
			$acts   = $data['activity'][ $id ] ?? array();
			$issued = false;
			$sum    = 0;
			$last   = '';
			foreach ( $acts as $act ) {
				$issued = $issued || 'issued' === $act['type'];
				$sum   += (int) $act['amount_cents'];
				if ( 'used' === $act['type'] && $act['created_at'] > $last ) {
					$last = $act['created_at'];
				}
			}
			$data['cards'][ $id ]['last_used_at'] = $last;
			$implied                             = $issued ? $sum : (int) $card['amount_cents'] + $sum;
			if ( $implied !== (int) $card['balance_cents'] ) {
				/* translators: 1: stored balance, 2: history */
				$diff[] = self::difference( 'history_differs', $id, sprintf( __( 'Its stored balance is %1$s, its history adds up to %2$s. The stored balance is copied (it is what the customer sees today); one "import adjustment" line makes the history add up.', 'fishotel-misc-plugin' ), Money::words( (int) $card['balance_cents'] ), Money::words( $implied ) ) );
			}
			$report['counts']['cards']++;
			$report['counts'][ $card['status'] ]++;
			$report['counts']['activity']               += count( $acts );
			$report['outstanding'][ $card['status'] ] += (int) $card['balance_cents'];
		}
		$report['counts']['orphans'] = (int) $data['orphans'];
		if ( $data['orphans'] > 0 ) {
			/* translators: %d: rows */
			$diff[] = self::difference( 'orphan_activity', 0, sprintf( _n( '%d activity row belongs to no card that exists; it is not copied.', '%d activity rows belong to no card that exists; they are not copied.', (int) $data['orphans'], 'fishotel-misc-plugin' ), (int) $data['orphans'] ) );
		}
		$report['outstanding']['total'] = $report['outstanding']['active'] + $report['outstanding']['disabled'];
		$report['differences']          = $diff;
		$report['blocking']             = count( array_filter( array_column( $diff, 'blocking' ) ) );
		$report['ok']                   = 0 === $report['blocking'];
		$report['data']                 = $data;
		return $report;
	}

	/**
	 * The dry run: reads, writes nothing but its report.
	 *
	 * @return array
	 */
	public function dry_run() {
		$report = $this->analyse();
		unset( $report['data'] );
		return $this->keep( $report );
	}

	/* ============================================================== */
	/*  3. The import                                                  */
	/* ============================================================== */

	/**
	 * Copy everything in — refused while the schema check or the dry run
	 * finds a blocking difference.
	 *
	 * @param array $ctx Audit::context() (source import).
	 * @return array The report.
	 */
	public function run( array $ctx ) {
		$report         = $this->analyse();
		$report['kind'] = 'import';
		$data           = $report['data'] ?? null;
		unset( $report['data'] );
		$report['reconciled'] = false;
		if ( empty( $report['ok'] ) || ! is_array( $data ) ) {
			$report['ok'] = false;
			return $this->keep( $report );
		}

		$tally = array(
			'created' => 0,
			'updated' => 0,
			'same'    => 0,
			'both'    => 0,
			'failed'  => 0,
		);
		$both  = array();
		foreach ( $data['cards'] as $id => $card ) {
			$r = $this->service->import_card( $card, $data['activity'][ $id ] ?? array(), $ctx );
			if ( ! $r['ok'] ) {
				$tally['failed']++;
				$report['differences'][] = self::difference( 'failed', $id, $r['message'] );
				continue;
			}
			$tally[ $r['code'] ] = ( $tally[ $r['code'] ] ?? 0 ) + 1;
			if ( 'both' === $r['code'] ) {
				$both[ $id ]             = true;
				$report['differences'][] = self::difference( 'changed_in_both', $id, __( 'It has been used in this system since the last import, so the old plugin\'s copy is not copied over it. Check it by hand.', 'fishotel-misc-plugin' ) );
			}
		}
		$report['tally'] = $tally;

		// Reconcile to the cent: every old card is here, and (apart from the
		// cards used here since) holds exactly the old balance.
		$copies   = array(
			'cards'    => 0,
			'active'   => 0,
			'disabled' => 0,
		);
		$mismatch = 0;
		foreach ( $data['cards'] as $id => $card ) {
			$mine = $this->store->card_by_legacy( (int) $id );
			if ( ! $mine ) {
				$mismatch++;
				continue;
			}
			$copies['cards']++;
			if ( isset( $both[ $id ] ) ) {
				continue;
			}
			$copies[ $mine['status'] ] += (int) $mine['balance_cents'];
			if ( (int) $mine['balance_cents'] !== (int) $card['balance_cents'] || $mine['status'] !== $card['status'] || (int) $mine['balance_cents'] !== $this->store->ledger_sum( (int) $mine['id'] ) ) {
				$mismatch++;
				$report['differences'][] = self::difference( 'not_reconciled', $id, __( 'Its copy does not hold the old balance.', 'fishotel-misc-plugin' ) );
			}
		}
		$expected = array(
			'active'   => 0,
			'disabled' => 0,
		);
		foreach ( $data['cards'] as $id => $card ) {
			if ( ! isset( $both[ $id ] ) ) {
				$expected[ $card['status'] ] += (int) $card['balance_cents'];
			}
		}
		$report['copies']     = $copies;
		$report['reconciled'] = 0 === $mismatch && 0 === $tally['failed'] && $copies['cards'] === $report['counts']['cards'] && $copies['active'] === $expected['active'] && $copies['disabled'] === $expected['disabled'];
		$report['ok']         = $report['reconciled'];
		$report['legacy_max'] = $this->old_state();

		$this->service->record(
			$ctx,
			'import_run',
			'cards',
			null,
			sprintf( '%d cards (%d new, %d updated, %d unchanged, %d used here since); owed %s; %s', $report['counts']['cards'], $tally['created'], $tally['updated'], $tally['same'], $tally['both'], Money::words( $report['outstanding']['total'] ), $report['reconciled'] ? 'reconciled to the cent' : 'NOT reconciled' )
		);

		return $this->keep( $report );
	}

	/**
	 * Keep a report (the list of differences capped) and return it.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private function keep( array $report ) {
		$report['listed'] = count( $report['differences'] );
		if ( $report['listed'] > self::MAX_LISTED ) {
			$report['differences'] = array_slice( $report['differences'], 0, self::MAX_LISTED );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::REPORT_OPTION, $report, false );
			if ( 'import' === $report['kind'] ) {
				update_option( self::IMPORT_OPTION, $report, false );
			}
		}
		return $report;
	}

	/**
	 * The last report kept, or null.
	 *
	 * @return array|null
	 */
	public static function last_report() {
		$r = function_exists( 'get_option' ) ? get_option( self::REPORT_OPTION, null ) : null;
		return is_array( $r ) ? $r : null;
	}

	/**
	 * The last report of an import (not a dry run), or null.
	 *
	 * @return array|null
	 */
	public static function last_import() {
		$r = function_exists( 'get_option' ) ? get_option( self::IMPORT_OPTION, null ) : null;
		return is_array( $r ) && 'import' === ( $r['kind'] ?? '' ) ? $r : null;
	}
}
