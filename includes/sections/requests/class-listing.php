<?php
/**
 * Requests listing — PURE: what the list page shows, worked out from rows
 * the store already read.
 *
 *   filter()      by source, where it came from, and a search over the
 *                 name, the e-mail address, the handle and the fish
 *   sort()        by date, name, fish, state or quantity
 *   groups()      the default view: one group per fish —
 *                 "<fish> — 3 waiting · longest 4 months" — opening to its rows
 *   needs_you()   the strip at the top: new requests to approve, payments
 *                 overdue, people to tell, owed fish to send
 *   demand()      the Demand tab: the most requested fish (open and
 *                 all-time) beside what past supplier orders ordered per year
 *   longest()     who has waited longest
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Listing
 */
class Listing {

	const SORTS       = array( 'created', 'name', 'fish', 'state', 'qty' );
	const GROUP_SORTS = array( 'longest', 'most', 'name' );

	/**
	 * Rows that pass the filters.
	 *
	 * @param array[] $rows    Requests.
	 * @param array   $filters state (string|string[]), source, came_from, search, line_id.
	 * @return array[]
	 */
	public static function filter( array $rows, array $filters ) {
		$states = isset( $filters['state'] ) && '' !== $filters['state'] && array() !== $filters['state'] ? array_map( 'strval', (array) $filters['state'] ) : array();
		$source = isset( $filters['source'] ) ? (string) $filters['source'] : '';
		$from   = isset( $filters['came_from'] ) ? Rules::name_key( (string) $filters['came_from'] ) : '';
		$search = isset( $filters['search'] ) ? Rules::name_key( (string) $filters['search'] ) : '';
		$line   = isset( $filters['line_id'] ) ? (int) $filters['line_id'] : 0;

		$out = array();
		foreach ( $rows as $row ) {
			if ( $states && ! in_array( (string) ( $row['state'] ?? '' ), $states, true ) ) {
				continue;
			}
			if ( '' !== $source && (string) ( $row['source'] ?? '' ) !== $source ) {
				continue;
			}
			if ( '' !== $from && Rules::name_key( (string) ( $row['came_from'] ?? '' ) ) !== $from ) {
				continue;
			}
			if ( $line > 0 && (int) ( $row['line_id'] ?? 0 ) !== $line ) {
				continue;
			}
			if ( '' !== $search && ! self::matches( $row, $search ) ) {
				continue;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Whether a request matches a search: the text is somewhere in its
	 * name, e-mail address, handle or fish (no case).
	 *
	 * @param array  $row    Request.
	 * @param string $search Lower-cased search text.
	 * @return bool
	 */
	public static function matches( array $row, $search ) {
		$search = Rules::name_key( (string) $search );
		if ( '' === $search ) {
			return true;
		}
		foreach ( array( 'name', 'email', 'handle', 'fish' ) as $key ) {
			if ( false !== strpos( Rules::name_key( (string) ( $row[ $key ] ?? '' ) ), $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rows in order.
	 *
	 * @param array[] $rows Requests.
	 * @param string  $by   created|name|fish|state|qty.
	 * @param string  $dir  asc|desc.
	 * @return array[]
	 */
	public static function sort( array $rows, $by = 'created', $dir = 'asc' ) {
		$by    = in_array( (string) $by, self::SORTS, true ) ? (string) $by : 'created';
		$sign  = 'desc' === strtolower( (string) $dir ) ? -1 : 1;
		$order = array_flip( Rules::STATES );

		usort(
			$rows,
			static function ( $a, $b ) use ( $by, $sign, $order ) {
				switch ( $by ) {
					case 'name':
						$cmp = strnatcasecmp( Rules::who( $a ), Rules::who( $b ) );
						break;
					case 'fish':
						$cmp = strnatcasecmp( (string) ( $a['fish'] ?? '' ), (string) ( $b['fish'] ?? '' ) );
						break;
					case 'state':
						$cmp = ( $order[ (string) ( $a['state'] ?? '' ) ] ?? 99 ) <=> ( $order[ (string) ( $b['state'] ?? '' ) ] ?? 99 );
						break;
					case 'qty':
						$cmp = (int) ( $a['qty'] ?? 0 ) <=> (int) ( $b['qty'] ?? 0 );
						break;
					default:
						$cmp = strcmp( (string) ( $a['created_at'] ?? '' ), (string) ( $b['created_at'] ?? '' ) );
				}
				if ( 0 !== $cmp ) {
					return $sign * $cmp;
				}
				// The same on what was asked: the oldest first, whatever the direction.
				$age = strcmp( (string) ( $a['created_at'] ?? '' ), (string) ( $b['created_at'] ?? '' ) );
				return 0 !== $age ? $age : ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			}
		);

		return $rows;
	}

	/**
	 * The rows grouped by fish.
	 *
	 * @param array[] $rows Requests (already filtered).
	 * @param int     $now  UNIX time.
	 * @param string  $by   longest (the oldest open request first) | most (the most open fish first) | name.
	 * @return array[] key, fish, open, open_qty, closed, oldest (UNIX time of the oldest open request,
	 *                 0 = none), title, rows (the oldest first).
	 */
	public static function groups( array $rows, $now, $by = 'longest' ) {
		$groups = array();
		foreach ( self::sort( $rows, 'created', 'asc' ) as $row ) {
			$key = '' !== (string) ( $row['fish_key'] ?? '' ) ? (string) $row['fish_key'] : Rules::fish_key( $row );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'      => $key,
					'fish'     => (string) ( $row['fish'] ?? '' ),
					'open'     => 0,
					'open_qty' => 0,
					'closed'   => 0,
					'oldest'   => 0,
					'rows'     => array(),
				);
			}
			if ( Rules::is_open( (string) ( $row['state'] ?? '' ) ) ) {
				$groups[ $key ]['open']++;
				$groups[ $key ]['open_qty'] += max( 1, (int) ( $row['qty'] ?? 1 ) );
				$created                     = Rules::ts( $row['created_at'] ?? '' );
				if ( $created > 0 && ( 0 === $groups[ $key ]['oldest'] || $created < $groups[ $key ]['oldest'] ) ) {
					$groups[ $key ]['oldest'] = $created;
				}
			} else {
				$groups[ $key ]['closed']++;
			}
			$groups[ $key ]['rows'][] = $row;
		}

		foreach ( $groups as $key => $group ) {
			$groups[ $key ]['title'] = self::group_title( $group, $now );
		}

		$groups = array_values( $groups );
		$by     = in_array( (string) $by, self::GROUP_SORTS, true ) ? (string) $by : 'longest';
		usort(
			$groups,
			static function ( $a, $b ) use ( $by ) {
				if ( 'name' === $by ) {
					return strnatcasecmp( $a['fish'], $b['fish'] );
				}
				if ( 'most' === $by && $a['open_qty'] !== $b['open_qty'] ) {
					return $b['open_qty'] <=> $a['open_qty'];
				}
				// The longest wait first; a group with nothing open goes last.
				$a_old = $a['oldest'] > 0 ? $a['oldest'] : PHP_INT_MAX;
				$b_old = $b['oldest'] > 0 ? $b['oldest'] : PHP_INT_MAX;
				if ( $a_old !== $b_old ) {
					return $a_old <=> $b_old;
				}
				return strnatcasecmp( $a['fish'], $b['fish'] );
			}
		);

		return $groups;
	}

	/**
	 * A group's line: "Blue Test Wrasse — 3 waiting · longest 4 months".
	 *
	 * @param array $group fish, open, open_qty, closed, oldest.
	 * @param int   $now   UNIX time.
	 * @return string
	 */
	public static function group_title( array $group, $now ) {
		$parts = array();
		if ( $group['open'] > 0 ) {
			/* translators: %d: requests */
			$waiting = sprintf( _n( '%d waiting', '%d waiting', (int) $group['open'], 'fishotel-misc-plugin' ), (int) $group['open'] );
			if ( (int) $group['open_qty'] !== (int) $group['open'] ) {
				/* translators: %d: fish */
				$waiting .= ' ' . sprintf( _n( '(%d fish)', '(%d fish)', (int) $group['open_qty'], 'fishotel-misc-plugin' ), (int) $group['open_qty'] );
			}
			$parts[] = $waiting;
			if ( $group['oldest'] > 0 ) {
				$parts[] = (int) $now - (int) $group['oldest'] < 86400
					? __( 'since today', 'fishotel-misc-plugin' )
					/* translators: %s: how long, e.g. "4 months" */
					: sprintf( __( 'longest %s', 'fishotel-misc-plugin' ), Rules::age_words( (int) $group['oldest'], (int) $now ) );
			}
		}
		if ( $group['closed'] > 0 ) {
			/* translators: %d: requests */
			$parts[] = sprintf( _n( '%d closed', '%d closed', (int) $group['closed'], 'fishotel-misc-plugin' ), (int) $group['closed'] );
		}

		return (string) $group['fish'] . ( $parts ? ' — ' . implode( ' · ', $parts ) : '' );
	}

	/**
	 * What the owner has to do, from the open requests.
	 *
	 * @param array[] $rows     Open requests.
	 * @param int     $now      UNIX time.
	 * @param array   $settings Settings (reminder_days).
	 * @return array{approve: array[], overdue: array[], tell: array[], owed: array[], count: int}
	 */
	public static function needs_you( array $rows, $now, array $settings ) {
		$out = array(
			'approve' => array(),
			'overdue' => array(),
			'tell'    => array(),
			'owed'    => array(),
			'count'   => 0,
		);
		$days = (int) ( $settings['reminder_days'] ?? 3 );

		foreach ( self::sort( $rows, 'created', 'asc' ) as $row ) {
			switch ( (string) ( $row['state'] ?? '' ) ) {
				case 'new':
					$out['approve'][] = $row;
					break;
				case 'awaiting_payment':
					if ( Rules::fee_expired( $row, $now, $days ) ) {
						$out['overdue'][] = $row;
					}
					break;
				case 'ready':
					$out['tell'][] = $row;
					break;
				case 'offered':
					if ( ! empty( $row['owed'] ) ) {
						$out['owed'][] = $row;
					}
					break;
			}
		}
		$out['count'] = count( $out['approve'] ) + count( $out['overdue'] ) + count( $out['tell'] ) + count( $out['owed'] );

		return $out;
	}

	/**
	 * The new requests as sheets to approve: the requests of one person
	 * sent together, the oldest sheet first.
	 *
	 * @param array[] $rows Requests in state "new".
	 * @return array[] key, who, email, fee (the fee the link proposed), rows.
	 */
	public static function sheets( array $rows ) {
		$sheets = array();
		foreach ( self::sort( $rows, 'created', 'asc' ) as $row ) {
			$key = '' !== (string) ( $row['sheet'] ?? '' ) ? 'sheet:' . $row['sheet'] : 'one:' . (int) ( $row['id'] ?? 0 );
			if ( ! isset( $sheets[ $key ] ) ) {
				$sheets[ $key ] = array(
					'key'   => $key,
					'who'   => Rules::who( $row ),
					'email' => (string) ( $row['email'] ?? '' ),
					'fee'   => 0.0,
					'rows'  => array(),
				);
			}
			$sheets[ $key ]['fee']    = max( $sheets[ $key ]['fee'], (float) ( $row['fee_amount'] ?? 0 ) );
			$sheets[ $key ]['rows'][] = $row;
		}
		return array_values( $sheets );
	}

	/**
	 * Who has waited longest: the open requests, the oldest first.
	 *
	 * @param array[] $rows  Open requests.
	 * @param int     $now   UNIX time.
	 * @param int     $limit How many.
	 * @return array[] The rows, each with 'age' (words).
	 */
	public static function longest( array $rows, $now, $limit = 20 ) {
		$open = array();
		foreach ( $rows as $row ) {
			if ( Rules::is_open( (string) ( $row['state'] ?? '' ) ) ) {
				$open[] = $row;
			}
		}
		$out = array();
		foreach ( array_slice( self::sort( $open, 'created', 'asc' ), 0, max( 1, (int) $limit ) ) as $row ) {
			$row['age'] = Rules::age_words( Rules::ts( $row['created_at'] ?? '' ), (int) $now );
			$out[]      = $row;
		}
		return $out;
	}

	/**
	 * The Demand table: the requested fish (open and all-time) beside the
	 * quantities ordered on past supplier orders, per year. A fish that
	 * was ordered but never requested here is listed too — that is past
	 * demand.
	 *
	 * @param array[] $demand  Store::demand().
	 * @param array   $ordered Cleanroom::ordered_by_year() (years, rows) — empty without the Clean Room.
	 * @param int     $years   How many years to show (the newest).
	 * @return array{years: int[], rows: array[]} rows: fish, open_requests, open_qty, all_requests,
	 *                                             all_qty, ordered (year → qty), ordered_total.
	 */
	public static function demand( array $demand, array $ordered, $years = 5 ) {
		$all_years = isset( $ordered['years'] ) && is_array( $ordered['years'] ) ? array_map( 'intval', $ordered['years'] ) : array();
		rsort( $all_years );
		$show    = array_slice( $all_years, 0, max( 1, (int) $years ) );
		$history = isset( $ordered['rows'] ) && is_array( $ordered['rows'] ) ? $ordered['rows'] : array();

		// The ordered rows by species id and by name, so a request finds its history.
		$by_species = array();
		$by_name    = array();
		foreach ( $history as $key => $row ) {
			if ( ! empty( $row['species_id'] ) ) {
				$by_species[ (int) $row['species_id'] ] = $key;
			}
			$by_name[ Rules::name_key( (string) ( $row['species'] ?? '' ) ) ] = $key;
		}

		$rows = array();
		$used = array();
		foreach ( $demand as $fish ) {
			$key = null;
			if ( ! empty( $fish['species_id'] ) && isset( $by_species[ (int) $fish['species_id'] ] ) ) {
				$key = $by_species[ (int) $fish['species_id'] ];
			} elseif ( isset( $by_name[ Rules::name_key( (string) ( $fish['fish'] ?? '' ) ) ] ) ) {
				$key = $by_name[ Rules::name_key( (string) $fish['fish'] ) ];
			}
			if ( null !== $key ) {
				$used[ $key ] = true;
			}
			$rows[] = array(
				'fish'          => (string) ( $fish['fish'] ?? '' ),
				'open_requests' => (int) ( $fish['open_requests'] ?? 0 ),
				'open_qty'      => (int) ( $fish['open_qty'] ?? 0 ),
				'all_requests'  => (int) ( $fish['all_requests'] ?? 0 ),
				'all_qty'       => (int) ( $fish['all_qty'] ?? 0 ),
				'ordered'       => null !== $key ? (array) $history[ $key ]['years'] : array(),
				'ordered_total' => null !== $key ? (int) $history[ $key ]['total'] : 0,
			);
		}
		foreach ( $history as $key => $row ) {
			if ( isset( $used[ $key ] ) ) {
				continue;
			}
			$rows[] = array(
				'fish'          => (string) ( $row['species'] ?? '' ),
				'open_requests' => 0,
				'open_qty'      => 0,
				'all_requests'  => 0,
				'all_qty'       => 0,
				'ordered'       => (array) ( $row['years'] ?? array() ),
				'ordered_total' => (int) ( $row['total'] ?? 0 ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				foreach ( array( 'open_qty', 'all_qty', 'ordered_total' ) as $key ) {
					if ( $a[ $key ] !== $b[ $key ] ) {
						return $b[ $key ] <=> $a[ $key ];
					}
				}
				return strnatcasecmp( $a['fish'], $b['fish'] );
			}
		);

		return array(
			'years' => $show,
			'rows'  => $rows,
		);
	}
}
