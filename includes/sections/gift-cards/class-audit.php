<?php
/**
 * Gift Cards audit log — every change to a card, its money, the mode or
 * the settings is one row, written in the same database transaction as
 * the change itself (if the change is rolled back, so is its row; a
 * change cannot be saved without its row):
 *
 *   who       actor_user_id + actor_name (0 / "system" for a job)
 *   when      created_at_utc, and site_time — the site's clock with its
 *             offset at that moment ("2026-11-13 16:05:09 CST (-06:00)")
 *   what      action, field, old_value → new_value, delta_cents
 *   why       reason (required for a balance or status change by hand)
 *   where     source (admin / checkout / account / order / rest / import /
 *             cron / system), screen (the admin page and tab, or the hook),
 *             ip (REMOTE_ADDR; a forwarded-for header is kept beside it
 *             in brackets, as a hint only — anybody can send one)
 *
 * Nothing in the plugin edits or deletes a row: the Store has no such
 * method and the screen has no such button. To make a change made behind
 * the plugin's back visible too, each row carries prev_hash (the row
 * before it) and row_hash = HMAC-SHA256 of its own fields and prev_hash,
 * keyed with a secret from wp-config.php (FISHOTEL_GIFT_CARDS_AUDIT_KEY
 * when defined, else the site's AUTH salt) — somebody with only the
 * database cannot rebuild the chain after editing or removing a row. The
 * newest row's id and hash are also kept in an option, so cutting rows
 * off the end shows too. verify() walks the chain. key_fp names the key
 * a row was written with: when the salts are changed, older rows are
 * reported as "written under an earlier key", not as tampered with.
 *
 * Everything but append() and context() is pure.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Audit
 */
class Audit {

	const HEAD_OPTION = 'fishotel_gift_cards_audit_head';

	/**
	 * The hashed fields, in this order.
	 *
	 * @var string[]
	 */
	const FIELDS = array( 'card_id', 'actor_user_id', 'actor_name', 'action', 'field', 'old_value', 'new_value', 'delta_cents', 'reason', 'source', 'screen', 'ip', 'created_at_utc', 'site_time', 'key_fp', 'prev_hash' );

	const SOURCES = array( 'admin', 'checkout', 'account', 'order', 'rest', 'import', 'cron', 'system' );

	/**
	 * The actions, in words (the screen and the export).
	 *
	 * @return array<string, string>
	 */
	public static function actions() {
		return array(
			'issue'         => __( 'Card issued', 'fishotel-misc-plugin' ),
			'balance'       => __( 'Balance changed by hand', 'fishotel-misc-plugin' ),
			'details'       => __( 'Details changed', 'fishotel-misc-plugin' ),
			'status'        => __( 'Switched on / off', 'fishotel-misc-plugin' ),
			'redeem'        => __( 'Added to an account', 'fishotel-misc-plugin' ),
			'use'           => __( 'Used on an order', 'fishotel-misc-plugin' ),
			'restore'       => __( 'Put back on the card', 'fishotel-misc-plugin' ),
			'email_sent'    => __( 'E-mail sent', 'fishotel-misc-plugin' ),
			'email_failed'  => __( 'E-mail failed', 'fishotel-misc-plugin' ),
			'email_resent'  => __( 'E-mail sent again', 'fishotel-misc-plugin' ),
			'code_viewed'   => __( 'Full code shown', 'fishotel-misc-plugin' ),
			'import'        => __( 'Imported from the old plugin', 'fishotel-misc-plugin' ),
			'import_update' => __( 'Changed by a new import', 'fishotel-misc-plugin' ),
			'import_run'    => __( 'Import run', 'fishotel-misc-plugin' ),
			'mode'          => __( 'Mode changed', 'fishotel-misc-plugin' ),
			'settings'      => __( 'Setting changed', 'fishotel-misc-plugin' ),
			'export'        => __( 'Audit log exported', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The chain key.
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'FISHOTEL_GIFT_CARDS_AUDIT_KEY' ) && '' !== (string) FISHOTEL_GIFT_CARDS_AUDIT_KEY ) {
			return (string) FISHOTEL_GIFT_CARDS_AUDIT_KEY;
		}
		return ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'no-salt' ) . '|fishotel-gift-card-audit';
	}

	/**
	 * Eight hex characters naming a key (not the key).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function fingerprint( $key ) {
		return substr( hash( 'sha256', 'fp|' . (string) $key ), 0, 8 );
	}

	/**
	 * The text a row's hash covers (pure).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function canonical( array $row ) {
		$parts = array();
		foreach ( self::FIELDS as $field ) {
			$value   = isset( $row[ $field ] ) ? $row[ $field ] : '';
			$parts[] = $field . '=' . str_replace( array( '\\', "\x1f" ), array( '\\\\', '\\u001f' ), null === $value ? '' : (string) $value );
		}
		return implode( "\x1f", $parts );
	}

	/**
	 * A row's hash (pure).
	 *
	 * @param array  $row Row with prev_hash.
	 * @param string $key Key.
	 * @return string
	 */
	public static function hash_row( array $row, $key ) {
		return hash_hmac( 'sha256', self::canonical( $row ), (string) $key );
	}

	/**
	 * A row before its hashes (pure).
	 *
	 * @param array      $ctx     context(): user_id, name, source, screen, ip.
	 * @param int        $card_id Card id (0 = not about one card).
	 * @param string     $action  An actions() key.
	 * @param string     $field   The field changed ('' = none).
	 * @param mixed      $old     Old value.
	 * @param mixed      $new     New value.
	 * @param int|null   $delta   Cents moved.
	 * @param string     $reason  Why.
	 * @param int        $now     UNIX time.
	 * @param \DateTimeZone|null $tz The site's time zone.
	 * @return array
	 */
	public static function build( array $ctx, $card_id, $action, $field, $old, $new, $delta, $reason, $now, $tz = null ) {
		$tz     = $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' );
		$local  = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
		$source = in_array( $ctx['source'] ?? '', self::SOURCES, true ) ? $ctx['source'] : 'system';

		return array(
			'card_id'        => (int) $card_id,
			'actor_user_id'  => (int) ( $ctx['user_id'] ?? 0 ),
			'actor_name'     => substr( (string) ( $ctx['name'] ?? '' ), 0, 120 ),
			'action'         => substr( (string) $action, 0, 30 ),
			'field'          => substr( (string) $field, 0, 40 ),
			'old_value'      => null === $old ? null : self::text( $old ),
			'new_value'      => null === $new ? null : self::text( $new ),
			'delta_cents'    => null === $delta ? null : (int) $delta,
			'reason'         => '' === (string) $reason ? null : substr( (string) $reason, 0, 2000 ),
			'source'         => $source,
			'screen'         => substr( (string) ( $ctx['screen'] ?? '' ), 0, 80 ),
			'ip'             => substr( (string) ( $ctx['ip'] ?? '' ), 0, 100 ),
			'created_at_utc' => gmdate( 'Y-m-d H:i:s', (int) $now ),
			'site_time'      => $local->format( 'Y-m-d H:i:s T (P)' ),
		);
	}

	/**
	 * A value as the log keeps it.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		return substr( (string) $value, 0, 4000 );
	}

	/**
	 * Append a row to the chain. Call it inside the change's transaction,
	 * holding the section's lock (Service::atomic()), so two rows never
	 * take the same place in the chain.
	 *
	 * @param Store  $store Store.
	 * @param array  $row   build().
	 * @param string $key   key().
	 * @return int|false
	 */
	public static function append( Store $store, array $row, $key = null ) {
		$key              = null === $key ? self::key() : (string) $key;
		$head             = $store->audit_head();
		$row['key_fp']    = self::fingerprint( $key );
		$row['prev_hash'] = $head ? (string) $head['row_hash'] : '';
		$row['row_hash']  = self::hash_row( $row, $key );
		$id               = $store->insert_audit( $row );
		if ( $id && function_exists( 'update_option' ) ) {
			update_option(
				self::HEAD_OPTION,
				array(
					'id'   => (int) $id,
					'hash' => $row['row_hash'],
				),
				false
			);
		}
		return $id;
	}

	/**
	 * Check the chain (pure apart from the reader).
	 *
	 * @param callable   $reader fn( int $after_id ): array[] — the next rows, oldest first ([] at the end).
	 * @param string     $key    key().
	 * @param array|null $head   The HEAD_OPTION value.
	 * @return array{ok: bool, checked: int, other_key: int, problems: string[]}
	 */
	public static function verify( callable $reader, $key, $head = null ) {
		$fp       = self::fingerprint( $key );
		$prev     = '';
		$after    = 0;
		$checked  = 0;
		$other    = 0;
		$problems = array();
		$last     = null;

		while ( true ) {
			$rows = call_user_func( $reader, $after );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$checked++;
				$id = (int) $row['id'];
				if ( (string) $row['prev_hash'] !== $prev ) {
					/* translators: %d: row id */
					$problems[] = sprintf( __( 'Row %d does not follow the row before it: a row was removed or changed just before it.', 'fishotel-misc-plugin' ), $id );
				}
				if ( (string) $row['key_fp'] !== $fp ) {
					$other++;
				} elseif ( ! hash_equals( self::hash_row( $row, $key ), (string) $row['row_hash'] ) ) {
					/* translators: %d: row id */
					$problems[] = sprintf( __( 'Row %d was changed after it was written.', 'fishotel-misc-plugin' ), $id );
				}
				$prev  = (string) $row['row_hash'];
				$after = $id;
				$last  = $row;
			}
			if ( count( $problems ) > 50 ) {
				break;
			}
		}

		if ( is_array( $head ) && ! empty( $head['id'] ) ) {
			if ( ! $last || (int) $last['id'] !== (int) $head['id'] || (string) $last['row_hash'] !== (string) $head['hash'] ) {
				/* translators: %d: row id */
				$problems[] = sprintf( __( 'The newest row should be row %d, and it is not there as it was written: rows were removed from the end.', 'fishotel-misc-plugin' ), (int) $head['id'] );
			}
		}

		return array(
			'ok'        => ! $problems,
			'checked'   => $checked,
			'other_key' => $other,
			'problems'  => $problems,
		);
	}

	/**
	 * Who and where, for the request in front of us.
	 *
	 * @param string $source A SOURCES entry.
	 * @param string $screen The screen or hook.
	 * @return array{user_id: int, name: string, source: string, screen: string, ip: string}
	 */
	public static function context( $source, $screen = '' ) {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$name    = 'system';
		if ( $user_id > 0 && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			$name = (string) ( $user->display_name ?: $user->user_login );
			if ( '' !== (string) $user->user_login && $name !== (string) $user->user_login ) {
				$name .= ' (' . $user->user_login . ')';
			}
		} elseif ( 'checkout' === $source || 'account' === $source ) {
			$name = 'guest';
		}
		if ( 'admin' === $source && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$source = 'rest';
		}
		return array(
			'user_id' => $user_id,
			'name'    => $name,
			'source'  => $source,
			'screen'  => (string) $screen,
			'ip'      => self::ip( $_SERVER ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in ip().
		);
	}

	/**
	 * The address a request came from (pure): REMOTE_ADDR, with a
	 * forwarded-for address beside it as a hint.
	 *
	 * @param array $server $_SERVER.
	 * @return string
	 */
	public static function ip( array $server ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? filter_var( trim( (string) $server['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false;
		$out    = $remote ? $remote : '';
		$fwd    = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? trim( explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] )[0] ) : '';
		$fwd    = '' !== $fwd ? filter_var( $fwd, FILTER_VALIDATE_IP ) : false;
		if ( $fwd && $fwd !== $remote ) {
			$out .= ' (forwarded: ' . $fwd . ')';
		}
		return substr( $out, 0, 100 );
	}

	/**
	 * A row in plain words (pure).
	 *
	 * @param array $row Audit row.
	 * @return string
	 */
	public static function describe( array $row ) {
		$actions = self::actions();
		$what    = isset( $actions[ $row['action'] ] ) ? $actions[ $row['action'] ] : (string) $row['action'];
		$field   = (string) $row['field'];
		$money   = in_array( $field, array( 'balance', 'amount' ), true );
		$old     = $row['old_value'];
		$new     = $row['new_value'];
		if ( $money ) {
			$old = null === $old ? null : Money::words( (int) $old );
			$new = null === $new ? null : Money::words( (int) $new );
		}
		$out = $what;
		if ( '' !== $field && ( null !== $old || null !== $new ) ) {
			$out .= ': ' . $field . ' ' . ( null === $old || '' === $old ? '(empty)' : $old ) . ' → ' . ( null === $new || '' === $new ? '(empty)' : $new );
		} elseif ( null !== $new && '' !== $new && ! $money ) {
			$out .= ': ' . $new;
		}
		if ( null !== $row['delta_cents'] && '' !== (string) $row['delta_cents'] ) {
			$delta = (int) $row['delta_cents'];
			$out  .= ' (' . ( $delta > 0 ? '+' : '' ) . Money::words( $delta ) . ')';
		}
		return $out;
	}

	/**
	 * The export's columns.
	 *
	 * @return string[]
	 */
	public static function csv_header() {
		return array( 'id', 'when (UTC)', 'when (site time)', 'user id', 'who', 'action', 'card id', 'card (last 4)', 'field', 'old', 'new', 'money (cents)', 'reason', 'from', 'screen', 'IP', 'row hash' );
	}

	/**
	 * One export line (pure).
	 *
	 * @param array  $row   Audit row.
	 * @param string $last4 The card's last four characters.
	 * @return string[]
	 */
	public static function csv_row( array $row, $last4 = '' ) {
		$cells = array( $row['id'], $row['created_at_utc'], $row['site_time'], $row['actor_user_id'], $row['actor_name'], $row['action'], $row['card_id'], $last4, $row['field'], $row['old_value'], $row['new_value'], $row['delta_cents'], $row['reason'], $row['source'], $row['screen'], $row['ip'], $row['row_hash'] );
		return array_map( array( self::class, 'csv_cell' ), $cells );
	}

	/**
	 * A cell a spreadsheet will not run as a formula (pure).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$text = null === $value ? '' : (string) $value;
		if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! preg_match( '/^-?\d+$/', $text ) ) {
			$text = "'" . $text;
		}
		return $text;
	}
}
