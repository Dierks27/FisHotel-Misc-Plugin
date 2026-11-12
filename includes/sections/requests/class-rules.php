<?php
/**
 * Requests rules — PURE: the states a request moves through, what a
 * request from a form or from another section must look like, the rules of
 * a private link, the fee timers, the "still want it?" timers and the
 * small words the screens share. Nothing here touches the database,
 * WooCommerce or the clock: every "now" is a parameter.
 *
 * The states, in the order a request usually travels:
 *
 *   new               came through a private link (or the public form) and
 *                     waits for the owner: Approve or Decline
 *   awaiting_payment  approved with a fee; the invoice is out
 *   waiting           approved (and paid, when there was a fee): on the list
 *   on_order          a supplier order that is a draft or ordered carries it
 *   in_quarantine     that order line was checked in to a tank
 *   ready             that tank group's sale date has come
 *   offered           the requester was told the fish is ready
 *   filled            the owner marked it, or the requester bought it
 *   cancelled         by the requester or the owner (a declined request too)
 *   expired           the fee was never paid, or "still want it?" got no answer
 *
 * A FEE belongs to an invoice, and an invoice may cover several requests
 * approved together: one of them carries the amount (its fee_ref is its own
 * id), the others point at it (their fee_ref is the carrier's id, their own
 * fee_amount is 0). Paying the carrier releases every one of them.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rules
 */
class Rules {

	const STATES        = array( 'new', 'awaiting_payment', 'waiting', 'on_order', 'in_quarantine', 'ready', 'offered', 'filled', 'cancelled', 'expired' );
	const OPEN_STATES   = array( 'new', 'awaiting_payment', 'waiting', 'on_order', 'in_quarantine', 'ready', 'offered' );
	const CLOSED_STATES = array( 'filled', 'cancelled', 'expired' );

	/**
	 * The states the daily job reads to follow the fish (offered counts
	 * against a line's fish but is never moved by the job).
	 *
	 * @var string[]
	 */
	const FOLLOW_STATES = array( 'waiting', 'on_order', 'in_quarantine', 'ready', 'offered' );

	/**
	 * The states in which a request is matched to a supplier order line.
	 *
	 * @var string[]
	 */
	const LINKED_STATES = array( 'on_order', 'in_quarantine', 'ready', 'offered' );

	const SOURCES = array( 'link', 'hand', 'lost_fish', 'public' );

	/**
	 * The sources a "still want it?" e-mail may go to: nothing is e-mailed
	 * for a request added by hand unless it has a fee or its fish becomes
	 * ready, and a fish that is owed is never asked about.
	 *
	 * @var string[]
	 */
	const ASK_SOURCES = array( 'link', 'public' );

	const MAX_QTY    = 99;
	const MAX_FEE    = 9999.99;
	const MAX_ROWS   = 10;
	const MAX_NOTE   = 1000;
	const MAX_FISH   = 160;
	const MAX_NAME   = 120;
	const MAX_EMAIL  = 190;
	const MAX_HANDLE = 80;
	const MAX_SIZE   = 100;
	const MAX_LABEL  = 120;
	const MAX_USES   = 100000;

	/**
	 * How many days a "still want it?" e-mail waits for an answer.
	 *
	 * @var int
	 */
	const ASK_ANSWER_DAYS = 14;

	/**
	 * Submissions allowed per address and per e-mail in RATE_WINDOW seconds.
	 */
	const RATE_LIMIT  = 5;
	const RATE_WINDOW = 3600;

	/* ============================================================== */
	/*  Words                                                          */
	/* ============================================================== */

	/**
	 * The states with the owner's labels.
	 *
	 * @return array<string, string>
	 */
	public static function state_labels() {
		return array(
			'new'              => __( 'new — to approve', 'fishotel-misc-plugin' ),
			'awaiting_payment' => __( 'awaiting payment', 'fishotel-misc-plugin' ),
			'waiting'          => __( 'waiting', 'fishotel-misc-plugin' ),
			'on_order'         => __( 'on order', 'fishotel-misc-plugin' ),
			'in_quarantine'    => __( 'in quarantine', 'fishotel-misc-plugin' ),
			'ready'            => __( 'ready', 'fishotel-misc-plugin' ),
			'offered'          => __( 'offered', 'fishotel-misc-plugin' ),
			'filled'           => __( 'filled', 'fishotel-misc-plugin' ),
			'cancelled'        => __( 'cancelled', 'fishotel-misc-plugin' ),
			'expired'          => __( 'expired', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * One state's label for the owner.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = self::state_labels();
		return isset( $labels[ (string) $state ] ) ? $labels[ (string) $state ] : (string) $state;
	}

	/**
	 * A request's state in plain words, for the person who asked.
	 *
	 * @param array $request The request (state, owed).
	 * @return string
	 */
	public static function state_words( array $request ) {
		$owed = ! empty( $request['owed'] );

		switch ( (string) ( $request['state'] ?? '' ) ) {
			case 'new':
				return __( 'Received — we are looking at it', 'fishotel-misc-plugin' );
			case 'awaiting_payment':
				return __( 'Approved — waiting for the request fee', 'fishotel-misc-plugin' );
			case 'waiting':
				return __( 'On our list — we are looking for it', 'fishotel-misc-plugin' );
			case 'on_order':
				return __( 'On order from a supplier', 'fishotel-misc-plugin' );
			case 'in_quarantine':
				return __( 'Arrived — in quarantine', 'fishotel-misc-plugin' );
			case 'ready':
				return __( 'Out of quarantine — we will be in touch', 'fishotel-misc-plugin' );
			case 'offered':
				return $owed
					? __( 'Ready — already paid for, we will arrange shipping', 'fishotel-misc-plugin' )
					: __( 'Ready for you — see our e-mail', 'fishotel-misc-plugin' );
			case 'filled':
				return __( 'Done', 'fishotel-misc-plugin' );
			case 'cancelled':
				return __( 'Cancelled', 'fishotel-misc-plugin' );
			case 'expired':
				return __( 'Closed — it ran out of time', 'fishotel-misc-plugin' );
		}

		return '';
	}

	/**
	 * The sources with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function source_labels() {
		return array(
			'link'      => __( 'private link', 'fishotel-misc-plugin' ),
			'hand'      => __( 'added by hand', 'fishotel-misc-plugin' ),
			'lost_fish' => __( 'lost fish', 'fishotel-misc-plugin' ),
			'public'    => __( 'public form', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * One source's label.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = self::source_labels();
		return isset( $labels[ (string) $source ] ) ? $labels[ (string) $source ] : (string) $source;
	}

	/**
	 * Whether a state is open (the request is still alive).
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function is_open( $state ) {
		return in_array( (string) $state, self::OPEN_STATES, true );
	}

	/* ============================================================== */
	/*  Cleaning                                                       */
	/* ============================================================== */

	/**
	 * One line of plain text: tags and control characters dropped, runs of
	 * white space one space, trimmed, at most $max characters.
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Longest result.
	 * @return string
	 */
	public static function text( $value, $max = 255 ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$text = (string) $value;
		$text = (string) preg_replace( '/<[^>]*>/', ' ', $text );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return self::cut( $text, $max );
	}

	/**
	 * Several lines of plain text (a note): tags and control characters
	 * dropped, line breaks kept, trimmed, at most $max characters.
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Longest result.
	 * @return string
	 */
	public static function lines( $value, $max = self::MAX_NOTE ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		$text = (string) preg_replace( '/<[^>]*>/', ' ', $text );
		$text = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]+/', ' ', $text );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/\n{3,}/', "\n\n", $text ) );

		return self::cut( $text, $max );
	}

	/**
	 * The first $max characters of a text (multibyte-safe where the
	 * extension is there).
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest result.
	 * @return string
	 */
	public static function cut( $text, $max ) {
		$max = max( 0, (int) $max );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( (string) $text, 0, $max, 'UTF-8' );
		}
		return (string) substr( (string) $text, 0, $max );
	}

	/**
	 * Lower case (multibyte-safe where the extension is there).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? (string) mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * An e-mail address as it is stored: trimmed, lower case; '' when it
	 * is not one.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function email( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$email = self::lower( trim( (string) $value ) );
		if ( '' === $email || strlen( $email ) > self::MAX_EMAIL ) {
			return '';
		}
		// No line break may ever reach a mail header.
		if ( 1 === preg_match( '/[\s<>,;"]/', $email ) ) {
			return '';
		}
		if ( function_exists( 'is_email' ) ) {
			return is_email( $email ) ? $email : '';
		}
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}

	/**
	 * A fee typed in a form: null when it is not an amount from 0 to
	 * MAX_FEE; an empty field is 0.
	 *
	 * @param mixed $value Raw.
	 * @return float|null
	 */
	public static function fee( $value ) {
		if ( null === $value || '' === $value ) {
			return 0.0;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$amount = (float) $value;
		} else {
			if ( ! is_string( $value ) ) {
				return null;
			}
			$clean = str_replace( array( '$', ',', ' ' ), '', trim( $value ) );
			if ( '' === $clean ) {
				return 0.0;
			}
			if ( 1 !== preg_match( '/^\d{1,6}(\.\d{1,2})?$/', $clean ) ) {
				return null;
			}
			$amount = (float) $clean;
		}
		$amount = round( $amount, 2 );
		if ( $amount < 0 || $amount > self::MAX_FEE ) {
			return null;
		}
		return $amount;
	}

	/**
	 * A quantity typed in a form: null unless it is a whole number from 1
	 * to MAX_QTY; an empty field is 1.
	 *
	 * @param mixed $value Raw.
	 * @return int|null
	 */
	public static function qty( $value ) {
		if ( null === $value || '' === $value ) {
			return 1;
		}
		if ( is_int( $value ) ) {
			$qty = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\d{1,4}$/', trim( $value ) ) ) {
			$qty = (int) trim( $value );
		} else {
			return null;
		}
		return ( $qty >= 1 && $qty <= self::MAX_QTY ) ? $qty : null;
	}

	/**
	 * An amount as text ("$12.50").
	 *
	 * @param float  $amount Amount.
	 * @param string $symbol Currency symbol.
	 * @return string
	 */
	public static function money( $amount, $symbol = '$' ) {
		return $symbol . number_format( (float) $amount, 2, '.', ',' );
	}

	/**
	 * The key two fish names are compared on: lower case, one space.
	 *
	 * @param string $name A name.
	 * @return string
	 */
	public static function name_key( $name ) {
		return self::lower( trim( (string) preg_replace( '/\s+/', ' ', (string) $name ) ) );
	}

	/**
	 * The key the list groups requests on: the species-list fish when it
	 * is matched, else the product, else the name as typed.
	 *
	 * @param array $row fish, species_id, product_id.
	 * @return string
	 */
	public static function fish_key( array $row ) {
		if ( ! empty( $row['species_id'] ) && (int) $row['species_id'] > 0 ) {
			return 's:' . (int) $row['species_id'];
		}
		if ( ! empty( $row['product_id'] ) && (int) $row['product_id'] > 0 ) {
			return 'p:' . (int) $row['product_id'];
		}
		return self::cut( 't:' . self::name_key( (string) ( $row['fish'] ?? '' ) ), self::MAX_FISH );
	}

	/**
	 * The slug of a size typed as free text (no standard list to pick
	 * from): lower-cased, every run of other characters a dash.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	public static function size_slug( $label ) {
		$slug = strtolower( trim( (string) $label ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return substr( trim( $slug, '-' ), 0, self::MAX_SIZE );
	}

	/**
	 * Clean the person of a request.
	 *
	 * @param array $raw name, email, handle.
	 * @return array{name: string, email: string, handle: string, email_typed: bool}
	 */
	public static function person( array $raw ) {
		$typed = isset( $raw['email'] ) && is_scalar( $raw['email'] ) && '' !== trim( (string) $raw['email'] );

		return array(
			'name'        => self::text( $raw['name'] ?? '', self::MAX_NAME ),
			'email'       => self::email( $raw['email'] ?? '' ),
			'handle'      => self::text( $raw['handle'] ?? '', self::MAX_HANDLE ),
			'email_typed' => $typed,
		);
	}

	/**
	 * What is wrong with a person (empty = nothing).
	 *
	 * @param array $person     person().
	 * @param bool  $need_email Whether an e-mail address is required.
	 * @return string[]
	 */
	public static function person_errors( array $person, $need_email ) {
		$errors = array();
		if ( '' === $person['name'] && '' === $person['handle'] ) {
			$errors[] = __( 'Please give a name.', 'fishotel-misc-plugin' );
		}
		if ( '' === $person['email'] ) {
			if ( ! empty( $person['email_typed'] ) ) {
				$errors[] = __( 'That e-mail address does not look right.', 'fishotel-misc-plugin' );
			} elseif ( $need_email ) {
				$errors[] = __( 'Please give an e-mail address.', 'fishotel-misc-plugin' );
			}
		}
		return $errors;
	}

	/**
	 * Clean one fish row and say what is wrong with it.
	 *
	 * The size: with a standard list ($sizes is an array), the slug must
	 * be one of the list (or the row's own stored one) — anything else is
	 * refused; without a list ($sizes is null: the Clean Room is off, or
	 * another section hands over a size it already knows), a given slug is
	 * kept with its label, and a label alone is free text.
	 *
	 * @param array        $raw     fish, species_id, product_id, size_slug, size_label, qty, note.
	 * @param array[]|null $sizes   The standard sizes (slug, label), or null.
	 * @param array        $current The row as stored (size_slug, size_label), when editing.
	 * @return array{row: array, errors: string[]}
	 */
	public static function fish_row( array $raw, $sizes = null, array $current = array() ) {
		$errors = array();
		$fish   = self::text( $raw['fish'] ?? '', self::MAX_FISH );
		if ( '' === $fish ) {
			$errors[] = __( 'Please say which fish.', 'fishotel-misc-plugin' );
		}

		$qty = self::qty( $raw['qty'] ?? '' );
		if ( null === $qty ) {
			/* translators: %d: the largest quantity */
			$errors[] = sprintf( __( 'The quantity must be a whole number from 1 to %d.', 'fishotel-misc-plugin' ), self::MAX_QTY );
			$qty      = 1;
		}

		$slug       = null;
		$label      = null;
		$typed_slug = isset( $raw['size_slug'] ) && is_scalar( $raw['size_slug'] ) && ! is_bool( $raw['size_slug'] ) ? self::text( $raw['size_slug'], self::MAX_SIZE ) : '';

		if ( is_array( $sizes ) ) {
			if ( '' !== $typed_slug ) {
				$found = null;
				foreach ( $sizes as $size ) {
					if ( (string) ( $size['slug'] ?? '' ) === $typed_slug ) {
						$found = $size;
						break;
					}
				}
				if ( $found ) {
					$slug  = $typed_slug;
					$label = self::text( $found['label'] ?? $typed_slug, self::MAX_SIZE );
				} elseif ( isset( $current['size_slug'] ) && (string) $current['size_slug'] === $typed_slug ) {
					$slug  = $typed_slug;
					$label = '' !== self::text( $current['size_label'] ?? '', self::MAX_SIZE ) ? self::text( $current['size_label'], self::MAX_SIZE ) : $typed_slug;
				} else {
					$errors[] = __( 'That size is not in the list — pick one of the sizes offered.', 'fishotel-misc-plugin' );
				}
			}
		} else {
			$typed_label = self::text( $raw['size_label'] ?? '', self::MAX_SIZE );
			if ( '' !== $typed_slug ) {
				// Another section handing over a size it already knows.
				$slug  = $typed_slug;
				$label = '' !== $typed_label ? $typed_label : $typed_slug;
			} elseif ( '' !== $typed_label && '' !== self::size_slug( $typed_label ) ) {
				$slug  = self::size_slug( $typed_label );
				$label = $typed_label;
			}
		}

		return array(
			'row'    => array(
				'fish'       => $fish,
				'species_id' => isset( $raw['species_id'] ) && is_scalar( $raw['species_id'] ) && (int) $raw['species_id'] > 0 ? (int) $raw['species_id'] : null,
				'product_id' => isset( $raw['product_id'] ) && is_scalar( $raw['product_id'] ) && (int) $raw['product_id'] > 0 ? (int) $raw['product_id'] : null,
				'size_slug'  => $slug,
				'size_label' => $label,
				'qty'        => $qty,
				'note'       => self::lines( $raw['note'] ?? '', self::MAX_NOTE ),
			),
			'errors' => $errors,
		);
	}

	/**
	 * The fish rows of a form: rows without a fish name and without
	 * anything else typed are dropped; at most MAX_ROWS.
	 *
	 * @param mixed $raw rows[n][fish|size_slug|size_label|qty|note].
	 * @return array[] Raw rows worth looking at.
	 */
	public static function form_rows( $raw ) {
		$out = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$fish = self::text( $row['fish'] ?? '', self::MAX_FISH );
			$note = self::lines( $row['note'] ?? '', self::MAX_NOTE );
			if ( '' === $fish && '' === $note ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) > self::MAX_ROWS ) {
				break;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Private links                                                  */
	/* ============================================================== */

	/**
	 * Clean a private link from the form.
	 *
	 * @param array         $raw label, fee, expires (Y-m-d, the last day it works), max_uses.
	 * @param \DateTimeZone $tz  The site zone (the expiry day ends at its midnight).
	 * @return array{link: array, errors: string[]}
	 */
	public static function link( array $raw, \DateTimeZone $tz ) {
		$errors = array();
		$label  = self::text( $raw['label'] ?? '', self::MAX_LABEL );
		if ( '' === $label ) {
			$errors[] = __( 'Give the link a label (a batch name, a person, an occasion).', 'fishotel-misc-plugin' );
		}

		$fee = self::fee( $raw['fee'] ?? '' );
		if ( null === $fee ) {
			/* translators: %s: the largest fee */
			$errors[] = sprintf( __( 'The fee must be an amount from 0 to %s.', 'fishotel-misc-plugin' ), number_format( self::MAX_FEE, 2 ) );
			$fee      = 0.0;
		}

		$expires = null;
		$typed   = isset( $raw['expires'] ) && is_scalar( $raw['expires'] ) ? trim( (string) $raw['expires'] ) : '';
		if ( '' !== $typed ) {
			$expires = self::end_of_day_utc( $typed, $tz );
			if ( null === $expires ) {
				$errors[] = __( 'The expiry is not a date.', 'fishotel-misc-plugin' );
			}
		}

		$max   = 0;
		$typed = isset( $raw['max_uses'] ) && is_scalar( $raw['max_uses'] ) ? trim( (string) $raw['max_uses'] ) : '';
		if ( '' !== $typed ) {
			if ( 1 === preg_match( '/^\d{1,6}$/', $typed ) && (int) $typed <= self::MAX_USES ) {
				$max = (int) $typed;
			} else {
				$errors[] = __( 'The maximum number of uses must be a whole number (empty or 0 = no limit).', 'fishotel-misc-plugin' );
			}
		}

		return array(
			'link'   => array(
				'label'      => $label,
				'fee_amount' => $fee,
				'expires_at' => $expires,
				'max_uses'   => $max,
			),
			'errors' => $errors,
		);
	}

	/**
	 * The last second of a calendar day in a zone, as a UTC datetime.
	 *
	 * @param string        $ymd Y-m-d.
	 * @param \DateTimeZone $tz  Zone.
	 * @return string|null 'Y-m-d H:i:s' (UTC), or null when $ymd is not a date.
	 */
	public static function end_of_day_utc( $ymd, \DateTimeZone $tz ) {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		try {
			$end = new \DateTimeImmutable( $ymd . ' 23:59:59', $tz );
		} catch ( \Exception $e ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $end->getTimestamp() );
	}

	/**
	 * Why a private link cannot be used right now: '' when it can, else
	 * 'missing', 'inactive', 'expired' or 'used_up'.
	 *
	 * @param array|null $link The link row.
	 * @param int        $now  UNIX time.
	 * @return string
	 */
	public static function link_problem( $link, $now ) {
		if ( ! is_array( $link ) || empty( $link['id'] ) ) {
			return 'missing';
		}
		if ( empty( $link['active'] ) ) {
			return 'inactive';
		}
		if ( ! empty( $link['expires_at'] ) && self::ts( $link['expires_at'] ) < (int) $now ) {
			return 'expired';
		}
		if ( (int) ( $link['max_uses'] ?? 0 ) > 0 && (int) ( $link['uses'] ?? 0 ) >= (int) $link['max_uses'] ) {
			return 'used_up';
		}
		return '';
	}

	/**
	 * A link problem in words, for the visitor.
	 *
	 * @param string $problem link_problem().
	 * @return string
	 */
	public static function link_problem_words( $problem ) {
		switch ( (string) $problem ) {
			case '':
				return '';
			case 'expired':
				return __( 'This request link has expired.', 'fishotel-misc-plugin' );
			case 'used_up':
				return __( 'This request link has been used as often as it allows.', 'fishotel-misc-plugin' );
			case 'inactive':
				return __( 'This request link has been switched off.', 'fishotel-misc-plugin' );
		}
		return __( 'This request link does not exist.', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  Time                                                           */
	/* ============================================================== */

	/**
	 * A stored UTC datetime as UNIX time (0 when empty or unreadable).
	 *
	 * @param mixed $value 'Y-m-d H:i:s' (UTC).
	 * @return int
	 */
	public static function ts( $value ) {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}
		$ts = strtotime( $value . ' UTC' );
		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * UNIX time as a stored UTC datetime.
	 *
	 * @param int $ts UNIX time.
	 * @return string
	 */
	public static function utc( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/**
	 * How long ago something was, in the words the list uses: "today",
	 * "3 days", "2 weeks", "4 months", "1 year".
	 *
	 * @param int $from UNIX time.
	 * @param int $now  UNIX time.
	 * @return string
	 */
	public static function age_words( $from, $now ) {
		$days = (int) floor( max( 0, (int) $now - (int) $from ) / 86400 );

		if ( $days < 1 ) {
			return __( 'today', 'fishotel-misc-plugin' );
		}
		if ( $days < 14 ) {
			/* translators: %d: days */
			return sprintf( _n( '%d day', '%d days', $days, 'fishotel-misc-plugin' ), $days );
		}
		if ( $days < 61 ) {
			$weeks = (int) floor( $days / 7 );
			/* translators: %d: weeks */
			return sprintf( _n( '%d week', '%d weeks', $weeks, 'fishotel-misc-plugin' ), $weeks );
		}
		if ( $days < 365 ) {
			$months = (int) floor( $days / 30.4375 );
			/* translators: %d: months */
			return sprintf( _n( '%d month', '%d months', $months, 'fishotel-misc-plugin' ), $months );
		}
		$years = (int) floor( $days / 365.25 );
		$years = max( 1, $years );
		/* translators: %d: years */
		return sprintf( _n( '%d year', '%d years', $years, 'fishotel-misc-plugin' ), $years );
	}

	/* ============================================================== */
	/*  Fees                                                           */
	/* ============================================================== */

	/**
	 * Whether a request carries an invoice (the amount is on it).
	 *
	 * @param array $request The request.
	 * @return bool
	 */
	public static function is_carrier( array $request ) {
		return (int) ( $request['id'] ?? 0 ) > 0 && (int) ( $request['fee_ref'] ?? 0 ) === (int) $request['id'] && (float) ( $request['fee_amount'] ?? 0 ) > 0;
	}

	/**
	 * Whether a request's fee is still to be paid: it waits on an invoice
	 * that has no payment yet.
	 *
	 * @param array $request The request.
	 * @return bool
	 */
	public static function fee_open( array $request ) {
		return 'awaiting_payment' === (string) ( $request['state'] ?? '' ) && (int) ( $request['fee_ref'] ?? 0 ) > 0;
	}

	/**
	 * Whether the ONE reminder of an unpaid invoice is due: the carrier is
	 * unpaid, was approved at least $days days ago and was not reminded.
	 *
	 * @param array $carrier The carrier request.
	 * @param int   $now     UNIX time.
	 * @param int   $days    Days after the approval.
	 * @return bool
	 */
	public static function reminder_due( array $carrier, $now, $days ) {
		if ( ! self::is_carrier( $carrier ) || ! empty( $carrier['fee_paid_at'] ) || ! empty( $carrier['reminded_at'] ) ) {
			return false;
		}
		$approved = self::ts( $carrier['approved_at'] ?? '' );
		return $approved > 0 && (int) $now - $approved >= max( 1, (int) $days ) * 86400;
	}

	/**
	 * Whether an unpaid request has run out of time: it still awaits its
	 * payment $days days after it was approved. The same question with the
	 * reminder day tells the owner the payment is overdue.
	 *
	 * @param array $request The request.
	 * @param int   $now     UNIX time.
	 * @param int   $days    Days after the approval.
	 * @return bool
	 */
	public static function fee_expired( array $request, $now, $days ) {
		if ( 'awaiting_payment' !== (string) ( $request['state'] ?? '' ) ) {
			return false;
		}
		$approved = self::ts( $request['approved_at'] ?? '' );
		return $approved > 0 && (int) $now - $approved >= max( 1, (int) $days ) * 86400;
	}

	/**
	 * How many whole days an unpaid request has left before it expires
	 * (at least 1 while it has not).
	 *
	 * @param array $request The request.
	 * @param int   $now     UNIX time.
	 * @param int   $days    Days after the approval.
	 * @return int
	 */
	public static function fee_days_left( array $request, $now, $days ) {
		$approved = self::ts( $request['approved_at'] ?? '' );
		if ( $approved <= 0 ) {
			return max( 1, (int) $days );
		}
		$left = $approved + max( 1, (int) $days ) * 86400 - (int) $now;
		return max( 1, (int) ceil( $left / 86400 ) );
	}

	/* ============================================================== */
	/*  "Still want it?"                                               */
	/* ============================================================== */

	/**
	 * When a waiting request's clock started: the last time the requester
	 * said "keep it", else its approval, else its creation.
	 *
	 * @param array $request The request.
	 * @return int UNIX time.
	 */
	public static function waiting_since( array $request ) {
		foreach ( array( 'kept_at', 'approved_at', 'created_at' ) as $key ) {
			$ts = self::ts( $request[ $key ] ?? '' );
			if ( $ts > 0 ) {
				return $ts;
			}
		}
		return 0;
	}

	/**
	 * Whether a "still want it?" e-mail is due: the request waits, came
	 * through a link or the public form, is not owed, was not asked since
	 * its clock started, and has waited $months months.
	 *
	 * @param array $request The request.
	 * @param int   $now     UNIX time.
	 * @param int   $months  Months of waiting.
	 * @return bool
	 */
	public static function ask_due( array $request, $now, $months ) {
		if ( 'waiting' !== (string) ( $request['state'] ?? '' ) || ! empty( $request['owed'] ) || ! empty( $request['asked_at'] ) ) {
			return false;
		}
		if ( ! in_array( (string) ( $request['source'] ?? '' ), self::ASK_SOURCES, true ) ) {
			return false;
		}
		$since = self::waiting_since( $request );
		if ( $since <= 0 ) {
			return false;
		}
		$due = strtotime( '+' . max( 1, (int) $months ) . ' months', $since );
		return false !== $due && (int) $now >= $due;
	}

	/**
	 * Whether a "still want it?" e-mail got no answer in time.
	 *
	 * @param array $request The request.
	 * @param int   $now     UNIX time.
	 * @param int   $days    Days to answer.
	 * @return bool
	 */
	public static function ask_expired( array $request, $now, $days = self::ASK_ANSWER_DAYS ) {
		if ( 'waiting' !== (string) ( $request['state'] ?? '' ) || empty( $request['asked_at'] ) ) {
			return false;
		}
		$asked = self::ts( $request['asked_at'] );
		return $asked > 0 && (int) $now - $asked >= max( 1, (int) $days ) * 86400;
	}

	/* ============================================================== */
	/*  Who may do what                                                */
	/* ============================================================== */

	/**
	 * Whether the person who asked may cancel a request themselves: it is
	 * still open and nothing was paid for the fish itself (an owed fish is
	 * the owner's to settle).
	 *
	 * @param array $request The request.
	 * @return bool
	 */
	public static function can_customer_cancel( array $request ) {
		return self::is_open( (string) ( $request['state'] ?? '' ) ) && empty( $request['owed'] );
	}

	/**
	 * Whether a request belongs to a site customer.
	 *
	 * @param array $request The request.
	 * @param int   $user_id The customer.
	 * @return bool
	 */
	public static function owned_by_user( array $request, $user_id ) {
		return (int) $user_id > 0 && (int) ( $request['user_id'] ?? 0 ) === (int) $user_id;
	}

	/**
	 * Whether a request belongs to an e-mail address (the personal link).
	 *
	 * @param array  $request The request.
	 * @param string $email   The address.
	 * @return bool
	 */
	public static function owned_by_email( array $request, $email ) {
		$email = self::email( $email );
		return '' !== $email && self::email( $request['email'] ?? '' ) === $email;
	}

	/**
	 * A request in one line: "2 × Blue Test Wrasse (2 - 3")".
	 *
	 * @param array $request qty, fish, size_label.
	 * @return string
	 */
	public static function fish_line( array $request ) {
		$qty  = max( 1, (int) ( $request['qty'] ?? 1 ) );
		$fish = (string) ( $request['fish'] ?? '' );
		$size = isset( $request['size_label'] ) && null !== $request['size_label'] ? trim( (string) $request['size_label'] ) : '';

		return $qty . ' × ' . $fish . ( '' !== $size ? ' (' . $size . ')' : '' );
	}

	/**
	 * Who a request is from, in a few words.
	 *
	 * @param array $request name, handle, email.
	 * @return string
	 */
	public static function who( array $request ) {
		$name   = trim( (string) ( $request['name'] ?? '' ) );
		$handle = trim( (string) ( $request['handle'] ?? '' ) );
		$email  = trim( (string) ( $request['email'] ?? '' ) );

		if ( '' !== $name ) {
			return $name;
		}
		if ( '' !== $handle ) {
			return $handle;
		}
		return '' !== $email ? $email : __( '(no name)', 'fishotel-misc-plugin' );
	}
}
