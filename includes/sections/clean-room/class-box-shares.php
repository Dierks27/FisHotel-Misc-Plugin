<?php
/**
 * Clean Room box shares — what the site REMEMBERS about how much of a box
 * a fish takes, so the next order from the same place can suggest it.
 *
 * Every box from every location is a different size, so a share is kept
 * per location: supplier + origin + fish + size → the percent of ONE box
 * that ONE fish takes, how many order lines it came from, and when it was
 * last written.
 *
 *   key      supplier and origin (trimmed, lower-cased), the fish — the
 *            species list id when the line has one, else the lower-cased
 *            name — and the size's slug ('' = no size). A fish picked
 *            from the species list on one order and typed on the next is
 *            still ONE fish: a line whose own key has no share counts in
 *            the share kept for that name under the other key (find()),
 *            and is offered its suggestion.
 *   written  when an order is saved (remember()), for every line of a
 *            box-mode order that has a percent of a box, received fish
 *            and an identified fish (eligible()): value = the line's
 *            percent ÷ its received count.
 *   latest   the latest ORDER wins: a line replaces the value when its
 *            order is dated (received date, else order date, else today)
 *            on or after the order the value came from — or when it is
 *            the very line the value came from, corrected. Re-saving an
 *            older order never overwrites a newer guess. A value typed in
 *            Settings → Box shares (a date, no line) yields only to an
 *            order dated AFTER it: saving that day's order again does not
 *            put the old guess back.
 *   count    goes up once per order line: the line carries the key it
 *            counts in (fh_order_lines.share_key), so saving an order
 *            twice counts once. A line that changes fish, size or
 *            location, loses its percent or is removed gives its count
 *            back (release()); a share nobody counts in any more is
 *            deleted.
 *   read     when a line of an order from the same supplier + origin has
 *            a fish (and size) but no percent: find() gives its share and
 *            suggest() the chip — per fish × the received count (the
 *            ordered count while nothing is received). A suggestion fills
 *            the field on a tap and is never saved by itself.
 *            how_full() adds the suggestions of a draft / ordered order
 *            up into the boxes it would fill.
 *
 * The maths is pure; remember() and release() take the store they write
 * to (the real Store or the tests' stand-in), like Orders::check_in().
 * The order page's JS mirrors find(), suggest() and how_full()
 * (findShare(), suggestPct(), howFull()) and is tested against the same
 * fixture.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Box_Shares
 */
class Box_Shares {

	/**
	 * The most one fish may take of a box, in percent (a line's cap).
	 *
	 * @var float
	 */
	const MAX_PER_FISH = Costing::BOX_PCT_MAX;

	/**
	 * The order statuses that show the "How full" line: the fish are not
	 * in yet, so the remembered shares predict the boxes.
	 *
	 * @var string[]
	 */
	const HOW_FULL_STATUSES = array( 'draft', 'ordered' );

	/* ============================================================== */
	/*  Keys                                                           */
	/* ============================================================== */

	/**
	 * The key of a location: supplier + origin, case and spacing aside.
	 *
	 * @param string $supplier Supplier.
	 * @param string $origin   Origin.
	 * @return string 32 hex.
	 */
	public static function location_key( $supplier, $origin ) {
		return md5( Species::key( (string) $supplier ) . '|' . Species::key( (string) $origin ) );
	}

	/**
	 * The key of a line's fish by its name: "n:" + the lower-cased name.
	 *
	 * @param array $line species.
	 * @return string '' without a name.
	 */
	public static function name_key( array $line ) {
		$name = Species::key( (string) ( $line['species'] ?? '' ) );
		return '' !== $name ? 'n:' . substr( $name, 0, 120 ) : '';
	}

	/**
	 * The key of a line's fish: "s:" + the species list id when the line
	 * has one, else its name key.
	 *
	 * @param array $line species_id, species.
	 * @return string '' when the line names no fish.
	 */
	public static function fish_key( array $line ) {
		$id = (int) ( $line['species_id'] ?? 0 );
		return $id > 0 ? 's:' . $id : self::name_key( $line );
	}

	/**
	 * The name key of a remembered share: as the page's JS carries it
	 * (for_js()), else from its name.
	 *
	 * @param array $share name_key, or species.
	 * @return string
	 */
	public static function share_name_key( array $share ) {
		return isset( $share['name_key'] ) && is_string( $share['name_key'] ) ? $share['name_key'] : self::name_key( $share );
	}

	/**
	 * The slug of a line's size ('' = none).
	 *
	 * @param array $line size_slug.
	 * @return string
	 */
	public static function size_key( array $line ) {
		return isset( $line['size_slug'] ) && is_scalar( $line['size_slug'] ) ? trim( (string) $line['size_slug'] ) : '';
	}

	/**
	 * The unique key of a share: location + fish + size.
	 *
	 * @param string $location_key location_key().
	 * @param string $fish_key     fish_key().
	 * @param string $size_slug    Size slug ('' = none).
	 * @return string 32 hex.
	 */
	public static function share_key( $location_key, $fish_key, $size_slug ) {
		return md5( (string) $location_key . '|' . (string) $fish_key . '|' . (string) $size_slug );
	}

	/**
	 * The share key a line of an order counts in.
	 *
	 * @param array $order supplier, origin.
	 * @param array $line  species_id, species, size_slug.
	 * @return string
	 */
	public static function line_key( array $order, array $line ) {
		return self::share_key( self::location_key( $order['supplier'] ?? '', $order['origin'] ?? '' ), self::fish_key( $line ), self::size_key( $line ) );
	}

	/* ============================================================== */
	/*  Writing                                                        */
	/* ============================================================== */

	/**
	 * Whether a line's percent is remembered (pure): a box-mode order, a
	 * percent of a box, received fish and an identified fish — a line
	 * flagged needs_id (a fish that still has to be identified) is never
	 * remembered.
	 *
	 * @param array $order cost_mode.
	 * @param array $line  species_id, species, qty_received, box_pct, needs_id.
	 * @return bool
	 */
	public static function eligible( array $order, array $line ) {
		if ( 'bag' === (string) ( $order['cost_mode'] ?? 'box' ) ) {
			return false;
		}
		if ( ! empty( $line['needs_id'] ) || '' === self::fish_key( $line ) ) {
			return false;
		}
		if ( (int) ( $line['qty_received'] ?? 0 ) < 1 ) {
			return false;
		}
		return null !== Costing::box_pct_in( $line['box_pct'] ?? null );
	}

	/**
	 * The percent of a box one fish of a line took: its percent ÷ its
	 * received count, to four decimals (null when it is not eligible).
	 *
	 * @param array $line qty_received, box_pct.
	 * @return float|null
	 */
	public static function per_fish( array $line ) {
		$pct      = Costing::box_pct_in( $line['box_pct'] ?? null );
		$received = (int) ( $line['qty_received'] ?? 0 );
		if ( null === $pct || $received < 1 ) {
			return null;
		}
		return round( $pct / $received, 4 );
	}

	/**
	 * The date an order's guesses are dated: received, else ordered, else
	 * today.
	 *
	 * @param array  $order received_date, order_date.
	 * @param string $today Site date.
	 * @return string Y-m-d ('' when nothing is a date).
	 */
	public static function order_date( array $order, $today = '' ) {
		foreach ( array( $order['received_date'] ?? null, $order['order_date'] ?? null, $today ) as $date ) {
			if ( Replay::is_date( $date ) ) {
				return (string) $date;
			}
		}
		return '';
	}

	/**
	 * Remember the percents of a saved order: every eligible line writes
	 * (or counts in) its share, every line that stopped being eligible or
	 * changed fish / size / location gives its count back, and so does
	 * every line the save removed. Needs the store's box_share_by_key(),
	 * insert_box_share(), update_box_share(), delete_box_share() and
	 * set_line_share_key().
	 *
	 * @param object  $store  Store or stand-in.
	 * @param array   $order  The order as stored now, with its lines (Store::order()).
	 * @param array[] $before Its lines before the save (to release the removed ones).
	 * @param string  $today  Site date.
	 * @return array{written: int, counted: int, released: int}
	 */
	public static function remember( $store, array $order, array $before = array(), $today = '' ) {
		$out  = array(
			'written'  => 0,
			'counted'  => 0,
			'released' => 0,
		);
		$seen = array();

		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( ! is_array( $line ) || (int) ( $line['id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$seen[ (int) $line['id'] ] = true;
			$did                       = self::remember_line( $store, $order, $line, $today );
			foreach ( $out as $key => $n ) {
				$out[ $key ] = $n + (int) ( $did[ $key ] ?? 0 );
			}
		}

		foreach ( $before as $line ) {
			if ( is_array( $line ) && ! isset( $seen[ (int) ( $line['id'] ?? 0 ) ] ) && ! empty( $line['share_key'] ) ) {
				$out['released'] += self::release( $store, (string) $line['share_key'], (int) $line['id'] ) ? 1 : 0;
			}
		}

		return $out;
	}

	/**
	 * Remember (or forget) the percent of ONE stored line — remember()'s
	 * step, on its own for a single line that was just corrected.
	 *
	 * @param object $store Store or stand-in.
	 * @param array  $order The line's order (supplier, origin, cost_mode, received_date, order_date).
	 * @param array  $line  The line as stored (id, species_id, species, size_slug, size_label,
	 *                      qty_received, box_pct, share_key, needs_id).
	 * @param string $today Site date.
	 * @return array{written: int, counted: int, released: int}
	 */
	public static function remember_line( $store, array $order, array $line, $today = '' ) {
		$out     = array(
			'written'  => 0,
			'counted'  => 0,
			'released' => 0,
		);
		$line_id = (int) ( $line['id'] ?? 0 );
		if ( $line_id <= 0 ) {
			return $out;
		}

		$old      = isset( $line['share_key'] ) && null !== $line['share_key'] ? (string) $line['share_key'] : '';
		$key      = self::eligible( $order, $line ) ? self::line_key( $order, $line ) : '';
		$location = self::location_key( $order['supplier'] ?? '', $order['origin'] ?? '' );
		$share    = '' !== $key ? $store->box_share_by_key( $key ) : null;
		if ( '' !== $key && ! $share ) {
			// The same fish under its other key — picked from the species list on one order,
			// typed on another: one share, never a second row.
			$share = self::find( (array) $store->box_shares( array( 'location_key' => $location ) ), $line );
			$key   = $share ? (string) $share['share_key'] : $key;
		}

		if ( '' !== $old && $old !== $key ) {
			$out['released'] = self::release( $store, $old, $line_id ) ? 1 : 0;
		}
		if ( '' === $key ) {
			if ( '' !== $old ) {
				$store->set_line_share_key( $line_id, null );
			}
			return $out;
		}

		$value = self::per_fish( $line );
		$date  = self::order_date( $order, $today );

		if ( ! $share ) {
			$inserted = $store->insert_box_share(
				array(
					'share_key'    => $key,
					'location_key' => $location,
					'supplier'     => trim( (string) ( $order['supplier'] ?? '' ) ),
					'origin'       => trim( (string) ( $order['origin'] ?? '' ) ),
					'fish_key'     => self::fish_key( $line ),
					'species_id'   => (int) ( $line['species_id'] ?? 0 ) > 0 ? (int) $line['species_id'] : null,
					'species'      => (string) ( $line['species'] ?? '' ),
					'size_slug'    => self::size_key( $line ),
					'size_label'   => '' !== self::size_key( $line ) ? (string) ( $line['size_label'] ?? self::size_key( $line ) ) : '',
					'pct_per_fish' => $value,
					'order_count'  => 1,
					'last_line_id' => $line_id,
					'last_date'    => '' !== $date ? $date : null,
				)
			);
			if ( $inserted ) {
				$out['written'] = 1;
				$out['counted'] = 1;
				$store->set_line_share_key( $line_id, $key );
			}
			return $out;
		}

		$fields = array();
		if ( $old !== $key ) {
			$fields['order_count'] = (int) $share['order_count'] + 1;
			$out['counted']        = 1;
		}
		if ( self::is_latest( $share, $line_id, $date ) ) {
			if ( abs( (float) $share['pct_per_fish'] - (float) $value ) >= 0.00005 ) {
				$fields['pct_per_fish'] = $value;
				$out['written']         = 1;
			}
			if ( (int) $share['last_line_id'] !== $line_id ) {
				$fields['last_line_id'] = $line_id;
			}
			if ( '' !== $date && (string) $share['last_date'] !== $date ) {
				$fields['last_date'] = $date;
			}
			// The names are snapshots: they follow the line the value comes from.
			if ( '' !== (string) ( $line['species'] ?? '' ) && (string) $share['species'] !== (string) $line['species'] ) {
				$fields['species'] = (string) $line['species'];
			}
			if ( '' !== self::size_key( $line ) && isset( $line['size_label'] ) && '' !== (string) $line['size_label'] && (string) $share['size_label'] !== (string) $line['size_label'] ) {
				$fields['size_label'] = (string) $line['size_label'];
			}
		}
		if ( $fields ) {
			$store->update_box_share( (int) $share['id'], $fields );
		}
		if ( $old !== $key ) {
			$store->set_line_share_key( $line_id, $key );
		}

		return $out;
	}

	/**
	 * Whether a line's guess replaces a share's value (pure): it is the
	 * line the value came from, or the share carries no date, or the
	 * line's order is dated on or after the value's. A value with a date
	 * and no line was typed in Settings → Box shares: only an order dated
	 * strictly after it replaces it, so saving that day's order again
	 * does not undo the edit.
	 *
	 * @param array  $share   last_line_id, last_date.
	 * @param int    $line_id The line.
	 * @param string $date    The line's order date ('' = unknown).
	 * @return bool
	 */
	public static function is_latest( array $share, $line_id, $date ) {
		$from = (int) ( $share['last_line_id'] ?? 0 );
		if ( $from === (int) $line_id && (int) $line_id > 0 ) {
			return true;
		}
		if ( ! Replay::is_date( $share['last_date'] ?? null ) ) {
			return true;
		}
		if ( ! Replay::is_date( $date ) ) {
			return false;
		}
		$later = strcmp( (string) $date, (string) $share['last_date'] );
		return $from > 0 ? $later >= 0 : $later > 0;
	}

	/**
	 * A line stops counting in a share: the count goes down by one, a
	 * share nobody counts in any more is deleted, and when the value came
	 * from that very line it loses its line and date — so the next guess,
	 * from any order, replaces it. The line's own share_key is the
	 * caller's to clear or re-point.
	 *
	 * @param object $store   Store or stand-in.
	 * @param string $key     The share key the line counted in.
	 * @param int    $line_id The line.
	 * @return bool Whether a share was changed or deleted.
	 */
	public static function release( $store, $key, $line_id ) {
		$share = '' !== (string) $key ? $store->box_share_by_key( (string) $key ) : null;
		if ( ! $share ) {
			return false;
		}
		$left = (int) $share['order_count'] - 1;
		if ( $left <= 0 ) {
			return (bool) $store->delete_box_share( (int) $share['id'] );
		}
		$fields = array( 'order_count' => $left );
		if ( (int) $share['last_line_id'] === (int) $line_id ) {
			$fields['last_line_id'] = null;
			$fields['last_date']    = null;
		}
		return (bool) $store->update_box_share( (int) $share['id'], $fields );
	}

	/**
	 * Every line of an order that is going away gives its count back (the
	 * order was deleted).
	 *
	 * @param object  $store Store or stand-in.
	 * @param array[] $lines The order's lines (id, share_key).
	 * @return int Shares changed.
	 */
	public static function release_lines( $store, array $lines ) {
		$n = 0;
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && ! empty( $line['share_key'] ) ) {
				$n += self::release( $store, (string) $line['share_key'], (int) ( $line['id'] ?? 0 ) ) ? 1 : 0;
			}
		}
		return $n;
	}

	/**
	 * A percent of a box per fish typed in Settings → Box shares: above 0
	 * and at most MAX_PER_FISH, to four decimals — else null.
	 *
	 * @param mixed $value Raw.
	 * @return float|null
	 */
	public static function per_fish_in( $value ) {
		if ( is_bool( $value ) || null === $value || ! is_scalar( $value ) ) {
			return null;
		}
		$text = trim( str_replace( array( '%', ' ' ), '', (string) $value ) );
		if ( '' === $text || ! is_numeric( $text ) ) {
			return null;
		}
		$number = round( (float) $text, 4 );
		if ( is_nan( $number ) || $number <= 0 || $number > self::MAX_PER_FISH ) {
			return null;
		}
		return $number;
	}

	/* ============================================================== */
	/*  Reading                                                        */
	/* ============================================================== */

	/**
	 * The remembered share of a line among the shares of its order's
	 * location (pure): the fish by its species list id first, then by its
	 * name, then the share kept under an id for a fish of that name (the
	 * fish was picked from the species list then, and typed now) — with
	 * the same size.
	 *
	 * @param array[] $shares The location's shares (fish_key, species or name_key, size_slug,
	 *                        pct_per_fish, order_count).
	 * @param array   $line   species_id, species, size_slug.
	 * @return array|null
	 */
	public static function find( array $shares, array $line ) {
		$size = self::size_key( $line );
		$name = self::name_key( $line );
		$keys = array_values( array_unique( array_filter( array( self::fish_key( $line ), $name ), 'strlen' ) ) );
		foreach ( $keys as $key ) {
			foreach ( $shares as $share ) {
				if ( is_array( $share ) && (string) ( $share['fish_key'] ?? '' ) === $key && (string) ( $share['size_slug'] ?? '' ) === $size ) {
					return $share;
				}
			}
		}
		if ( '' !== $name ) {
			foreach ( $shares as $share ) {
				if ( is_array( $share ) && self::share_name_key( $share ) === $name && (string) ( $share['size_slug'] ?? '' ) === $size ) {
					return $share;
				}
			}
		}
		return null;
	}

	/**
	 * The percent a remembered share suggests for a line (pure): per fish
	 * × the received count, or × the ordered count while nothing is
	 * received — in whole hundredths, at most a line's cap. Null without a
	 * share, without a count, or when it comes to nothing.
	 *
	 * @param array|null $share pct_per_fish, order_count.
	 * @param array      $line  qty_received, qty_ordered.
	 * @return array{pct: float, orders: int, count: int}|null
	 */
	public static function suggest( $share, array $line ) {
		if ( ! is_array( $share ) ) {
			return null;
		}
		$received = (int) ( $line['qty_received'] ?? 0 );
		$count    = $received > 0 ? $received : max( 0, (int) ( $line['qty_ordered'] ?? 0 ) );
		$per_fish = (float) ( $share['pct_per_fish'] ?? 0 );
		if ( $count < 1 || $per_fish <= 0 ) {
			return null;
		}
		$hundredths = min( (int) round( self::MAX_PER_FISH * 100 ), (int) round( $per_fish * $count * 100 ) );
		if ( $hundredths < 1 ) {
			return null;
		}
		return array(
			'pct'    => (float) ( $hundredths / 100 ),
			'orders' => max( 0, (int) ( $share['order_count'] ?? 0 ) ),
			'count'  => $count,
		);
	}

	/**
	 * The suggestion of a line that has no percent yet (pure): its
	 * remembered share × its count; null when it has a percent or no
	 * share.
	 *
	 * @param array[] $shares The location's shares.
	 * @param array   $line   The line.
	 * @return array|null suggest().
	 */
	public static function suggest_for( array $shares, array $line ) {
		if ( null !== Costing::box_pct_in( $line['box_pct'] ?? null ) ) {
			return null;
		}
		return self::suggest( self::find( $shares, $line ), $line );
	}

	/**
	 * How full the boxes of an order that has not arrived would be (pure):
	 * per line with an ordered count, the percent already typed, else the
	 * remembered share × the ordered count; the lines with neither are
	 * counted as having no remembered share yet.
	 *
	 * @param array[] $lines  qty_ordered, box_pct, species_id, species, size_slug.
	 * @param array[] $shares The location's shares.
	 * @return array{pct: float, boxes: float, known: int, typed: int, unknown: int, lines: int}
	 */
	public static function how_full( array $lines, array $shares ) {
		$sum     = 0;
		$known   = 0;
		$typed   = 0;
		$unknown = 0;
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$ordered = max( 0, (int) ( $line['qty_ordered'] ?? 0 ) );
			if ( $ordered < 1 ) {
				continue;
			}
			$pct = Costing::box_pct_in( $line['box_pct'] ?? null );
			if ( null !== $pct ) {
				$sum += (int) round( $pct * 100 );
				$typed++;
				continue;
			}
			$share = self::find( $shares, $line );
			if ( ! $share || (float) ( $share['pct_per_fish'] ?? 0 ) <= 0 ) {
				$unknown++;
				continue;
			}
			$sum += (int) round( (float) $share['pct_per_fish'] * $ordered * 100 );
			$known++;
		}
		return array(
			'pct'     => (float) ( $sum / 100 ),
			'boxes'   => (float) ( $sum / 10000 ),
			'known'   => $known,
			'typed'   => $typed,
			'unknown' => $unknown,
			'lines'   => $known + $typed + $unknown,
		);
	}

	/**
	 * The shares of a location as the order page's JS reads them.
	 *
	 * @param array[] $shares Hydrated shares.
	 * @return array[] fish_key, name_key (the fish by its name, whatever its key), size_slug,
	 *                 pct_per_fish, order_count.
	 */
	public static function for_js( array $shares ) {
		$out = array();
		foreach ( $shares as $share ) {
			$out[] = array(
				'fish_key'     => (string) ( $share['fish_key'] ?? '' ),
				'name_key'     => is_array( $share ) ? self::share_name_key( $share ) : '',
				'size_slug'    => (string) ( $share['size_slug'] ?? '' ),
				'pct_per_fish' => (float) ( $share['pct_per_fish'] ?? 0 ),
				'order_count'  => (int) ( $share['order_count'] ?? 0 ),
			);
		}
		return $out;
	}

	/* ============================================================== */
	/*  Words                                                          */
	/* ============================================================== */

	/**
	 * The sentences of the suggestion chip and the "How full" line — one
	 * table for the page and for its JS (which fills the same templates),
	 * so the two never read differently.
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			/* translators: 1: percent, 2: number of orders (1) */
			'suggest1'   => __( 'suggested %1$s%% (from %2$d order)', 'fishotel-misc-plugin' ),
			/* translators: 1: percent, 2: number of orders */
			'suggestN'   => __( 'suggested %1$s%% (from %2$d orders)', 'fishotel-misc-plugin' ),
			'howNone'    => __( 'How full: nothing to add up yet — no line has an ordered count.', 'fishotel-misc-plugin' ),
			/* translators: %1$d: lines */
			'howNothing' => __( 'How full: nothing is remembered for this supplier and origin yet · lines with no remembered share yet: %1$d', 'fishotel-misc-plugin' ),
			/* translators: 1: boxes, 2: lines with a share or a typed percent, 3: lines without */
			'howFull'    => __( 'How full: boxes for the ordered counts: about %1$s · lines with a remembered share or a typed %%: %2$d · lines with no remembered share yet: %3$d', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The chip beside an empty percent: "suggested 12.5% (from 3 orders)".
	 *
	 * @param array      $suggestion suggest().
	 * @param array|null $strings    strings() (null = the site's).
	 * @return string
	 */
	public static function suggest_text( array $suggestion, $strings = null ) {
		$s      = is_array( $strings ) ? $strings : self::strings();
		$orders = (int) ( $suggestion['orders'] ?? 0 );
		return sprintf( 1 === $orders ? $s['suggest1'] : $s['suggestN'], Costing::num( $suggestion['pct'] ?? null, '0', 2 ), $orders );
	}

	/**
	 * The "How full" line of a draft / ordered order: the boxes the typed
	 * percents and the remembered shares add up to for the ordered counts
	 * (to two decimals), and how many lines have no remembered share yet.
	 *
	 * @param array      $full    how_full().
	 * @param array|null $strings strings() (null = the site's).
	 * @return string
	 */
	public static function how_full_text( array $full, $strings = null ) {
		$s       = is_array( $strings ) ? $strings : self::strings();
		$unknown = (int) ( $full['unknown'] ?? 0 );
		$with    = (int) ( $full['known'] ?? 0 ) + (int) ( $full['typed'] ?? 0 );
		if ( 0 === $with && 0 === $unknown ) {
			return $s['howNone'];
		}
		if ( 0 === $with ) {
			return sprintf( $s['howNothing'], $unknown );
		}
		// The percent is a whole number of hundredths, so this rounds the same in PHP and in JS.
		return sprintf( $s['howFull'], Costing::num( round( (float) ( $full['pct'] ?? 0 ) ) / 100, '0', 2 ), $with, $unknown );
	}
}
