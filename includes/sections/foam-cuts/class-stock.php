<?php
/**
 * Stock — the foam on the shelf, and what a plan does to it.
 *
 *   whole sheets   a count: the sheet entries added up ("bought 6 sheets",
 *                  a plan taking 2, an undo giving them back, a count
 *                  corrected by hand)
 *   leftovers      rectangles with a size and a label (L1, L2 …) written
 *                  on the piece: made by plans, and added, resized or
 *                  removed by hand when reality differs
 *
 * MARK AS CUT applies a plan once: the leftovers it cut into go, the
 * sheets it took are taken, the leftovers it made are added. It refuses
 * (and changes nothing) when a leftover the plan counted on is gone or has
 * another size. The plan's status moves draft → cut in the same
 * transaction with a WHERE on the old status, so a second click, a second
 * tab or a retried request finds nothing to do.
 *
 * UNDO puts all of it back — while nothing else has touched what the plan
 * touched: every leftover it made is still in stock at the size it was
 * made, and every leftover it cut into is still marked as used by it.
 *
 * Every method takes the store as a parameter (the real Store or the
 * tests' in-memory twin) and returns { ok, message }.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stock
 */
class Stock {

	/**
	 * What a leftover's label starts with.
	 *
	 * @var string
	 */
	const LABEL_PREFIX = 'L';

	/**
	 * The most sheets one entry adds or the count is set to.
	 *
	 * @var int
	 */
	const MAX_SHEETS = 10000;

	/**
	 * The stock as a plan sees it.
	 *
	 * @param object $store Store.
	 * @return array{sheets: int, leftovers: array<int, array{id: int, label: string, length: int, width: int}>}
	 */
	public static function snapshot( $store ) {
		$leftovers = array();
		foreach ( $store->leftovers( 'stock' ) as $row ) {
			$leftovers[] = array(
				'id'     => (int) $row['id'],
				'label'  => (string) $row['label'],
				'length' => (int) $row['length'],
				'width'  => (int) $row['width'],
			);
		}

		return array(
			'sheets'    => (int) $store->sheets(),
			'leftovers' => $leftovers,
		);
	}

	/**
	 * A result.
	 *
	 * @param bool   $ok      Whether it worked.
	 * @param string $message What to tell the owner.
	 * @param array  $extra   Extra keys.
	 * @return array{ok: bool, message: string}
	 */
	private static function result( $ok, $message, array $extra = array() ) {
		return array_merge(
			array(
				'ok'      => (bool) $ok,
				'message' => (string) $message,
			),
			$extra
		);
	}

	/* ============================================================== */
	/*  A plan against the stock                                       */
	/* ============================================================== */

	/**
	 * Why a plan no longer matches the stock (pure): a leftover it cuts
	 * into is not in stock any more, or has another size.
	 *
	 * @param array $data Plan data.
	 * @param array $rows The leftovers it names, as they are now: id → row (missing = gone).
	 * @return string[] Empty = the plan can be cut as printed.
	 */
	public static function stale( array $data, array $rows ) {
		$reasons = array();

		foreach ( Plan::used_leftovers( $data ) as $id => $want ) {
			if ( ! isset( $rows[ $id ] ) || 'stock' !== $rows[ $id ]['status'] ) {
				/* translators: %s: leftover label */
				$reasons[] = sprintf( __( 'Leftover %s is no longer in stock.', 'fishotel-misc-plugin' ), $want['label'] );
				continue;
			}
			if ( (int) $rows[ $id ]['length'] !== $want['length'] || (int) $rows[ $id ]['width'] !== $want['width'] ) {
				$reasons[] = sprintf(
					/* translators: 1: leftover label, 2: its size now, 3: its size when the plan was made */
					__( 'Leftover %1$s is now %2$s in; it was %3$s in when this plan was made.', 'fishotel-misc-plugin' ),
					$want['label'],
					Units::size( $rows[ $id ]['length'], $rows[ $id ]['width'] ),
					Units::size( $want['length'], $want['width'] )
				);
			}
		}

		return $reasons;
	}

	/**
	 * Why a plan that was cut cannot be undone any more (pure).
	 *
	 * @param array $data    Plan data.
	 * @param int   $plan_id The plan's id.
	 * @param array $made    The leftover rows the plan made, as they are now.
	 * @param array $used    The leftovers it cut into, as they are now: id → row.
	 * @return string[] Empty = Undo is possible.
	 */
	public static function undo_blockers( array $data, $plan_id, array $made, array $used ) {
		$reasons  = array();
		$by_label = array();
		foreach ( $made as $row ) {
			$by_label[ (string) $row['label'] ] = $row;
		}

		foreach ( Plan::new_leftovers( $data ) as $want ) {
			$row = isset( $by_label[ $want['label'] ] ) ? $by_label[ $want['label'] ] : null;

			if ( null === $row || 'stock' !== $row['status'] ) {
				/* translators: %s: leftover label */
				$reasons[] = sprintf( __( 'Leftover %s, which this plan made, has been used or removed since.', 'fishotel-misc-plugin' ), $want['label'] );
			} elseif ( (int) $row['length'] !== $want['length'] || (int) $row['width'] !== $want['width'] ) {
				/* translators: %s: leftover label */
				$reasons[] = sprintf( __( 'Leftover %s, which this plan made, has been resized since.', 'fishotel-misc-plugin' ), $want['label'] );
			}
		}

		foreach ( Plan::used_leftovers( $data ) as $id => $want ) {
			if ( ! isset( $used[ $id ] ) || 'used' !== $used[ $id ]['status'] || (int) $used[ $id ]['used_plan_id'] !== (int) $plan_id ) {
				/* translators: %s: leftover label */
				$reasons[] = sprintf( __( 'Leftover %s, which this plan cut up, has been changed since.', 'fishotel-misc-plugin' ), $want['label'] );
			}
		}

		return $reasons;
	}

	/**
	 * What a plan's page needs to know about it against today's stock.
	 *
	 * @param object $store Store.
	 * @param array  $plan  Plan row (with data).
	 * @return array{stale: string[], undo_blockers: string[]}
	 *         stale is filled for a draft, undo_blockers for a cut plan.
	 */
	public static function state( $store, array $plan ) {
		$data = isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array();
		$used = $store->leftovers_by_ids( Plan::used_leftover_ids( $data ) );

		if ( 'cut' === $plan['status'] ) {
			return array(
				'stale'         => array(),
				'undo_blockers' => self::undo_blockers( $data, (int) $plan['id'], $store->leftovers_of_plan( (int) $plan['id'] ), $used ),
			);
		}

		return array(
			'stale'         => self::stale( $data, $used ),
			'undo_blockers' => array(),
		);
	}

	/**
	 * MARK AS CUT: apply a draft plan to the stock, once.
	 *
	 * @param object $store   Store.
	 * @param array  $plan    Plan row (with data).
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string}
	 */
	public static function apply( $store, array $plan, $user_id = 0 ) {
		$id   = (int) $plan['id'];
		$data = isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array();

		if ( 'draft' !== $plan['status'] ) {
			return self::result( false, __( 'This plan is already marked as cut. Nothing was changed.', 'fishotel-misc-plugin' ) );
		}
		if ( empty( $data['sources'] ) || ! empty( $data['errors'] ) ) {
			return self::result( false, __( 'This plan has nothing to cut.', 'fishotel-misc-plugin' ) );
		}

		$used_ids = Plan::used_leftover_ids( $data );
		$stale    = self::stale( $data, $store->leftovers_by_ids( $used_ids ) );
		if ( $stale ) {
			return self::result( false, __( 'The stock has changed since this plan was made, so it was not applied:', 'fishotel-misc-plugin' ) . ' ' . implode( ' ', $stale ) . ' ' . __( 'Make the plan again.', 'fishotel-misc-plugin' ) );
		}

		$new    = Plan::new_leftovers( $data );
		$sheets = Plan::sheets( $data );
		$failed = '';

		$store->begin();

		if ( ! $store->set_plan_status( $id, 'draft', 'cut', (int) $user_id ) ) {
			$store->rollback();
			return self::result( false, __( 'This plan is already marked as cut. Nothing was changed.', 'fishotel-misc-plugin' ) );
		}

		if ( $used_ids && count( $used_ids ) !== (int) $store->mark_leftovers( $used_ids, 'stock', 'used', $id ) ) {
			$failed = __( 'a leftover the plan cuts into was changed at the same moment', 'fishotel-misc-plugin' );
		}

		if ( '' === $failed ) {
			foreach ( $new as $left ) {
				$added = $store->add_leftover(
					array(
						'label'   => $left['label'],
						'length'  => $left['length'],
						'width'   => $left['width'],
						'status'  => 'stock',
						'origin'  => 'plan',
						'plan_id' => $id,
						/* translators: %s: where the leftover was cut from, e.g. "new sheet 1" */
						'note'    => sprintf( __( 'from %s', 'fishotel-misc-plugin' ), $left['source'] ),
					)
				);
				if ( ! $added ) {
					$failed = __( 'a new leftover could not be saved', 'fishotel-misc-plugin' );
					break;
				}
			}
		}

		if ( '' === $failed && $sheets > 0 && ! $store->add_sheet_entry( 'plan', -$sheets, $id, (string) $plan['title'], (int) $user_id ) ) {
			$failed = __( 'the sheets could not be taken off the count', 'fishotel-misc-plugin' );
		}

		if ( '' === $failed && ! $store->commit() ) {
			$failed = __( 'the database did not keep the change', 'fishotel-misc-plugin' );
		}

		if ( '' !== $failed ) {
			$store->rollback();
			// Where the tables have no transactions the rollback did nothing:
			// take back by hand what was written (each step is a no-op after a
			// real rollback — nothing then matches).
			$store->delete_leftovers_of_plan( $id );
			$store->mark_leftovers( $used_ids, 'used', 'stock', $id );
			$store->set_plan_status( $id, 'cut', 'draft' );

			/* translators: %s: what went wrong */
			return self::result( false, sprintf( __( 'The plan was not applied (%s). The stock is as it was.', 'fishotel-misc-plugin' ), $failed ) );
		}

		$parts = array();
		if ( $sheets > 0 ) {
			/* translators: %d: sheets */
			$parts[] = sprintf( _n( '%d whole sheet taken', '%d whole sheets taken', $sheets, 'fishotel-misc-plugin' ), $sheets );
		}
		if ( $used_ids ) {
			/* translators: %d: leftovers */
			$parts[] = sprintf( _n( '%d leftover used up', '%d leftovers used up', count( $used_ids ), 'fishotel-misc-plugin' ), count( $used_ids ) );
		}
		if ( $new ) {
			/* translators: 1: how many, 2: their labels */
			$parts[] = sprintf( _n( '%1$d new leftover added (%2$s)', '%1$d new leftovers added (%2$s)', count( $new ), 'fishotel-misc-plugin' ), count( $new ), implode( ', ', array_column( $new, 'label' ) ) );
		}

		return self::result( true, __( 'Marked as cut.', 'fishotel-misc-plugin' ) . ( $parts ? ' ' . implode( '; ', $parts ) . '.' : '' ) );
	}

	/**
	 * UNDO: put the stock back as it was before the plan was cut.
	 *
	 * @param object $store   Store.
	 * @param array  $plan    Plan row (with data).
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string}
	 */
	public static function undo( $store, array $plan, $user_id = 0 ) {
		$id   = (int) $plan['id'];
		$data = isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array();

		if ( 'cut' !== $plan['status'] ) {
			return self::result( false, __( 'This plan is not marked as cut, so there is nothing to undo.', 'fishotel-misc-plugin' ) );
		}

		$used_ids = Plan::used_leftover_ids( $data );
		$blockers = self::undo_blockers( $data, $id, $store->leftovers_of_plan( $id ), $store->leftovers_by_ids( $used_ids ) );
		if ( $blockers ) {
			return self::result( false, __( 'This can no longer be undone, because the stock it touched has changed since:', 'fishotel-misc-plugin' ) . ' ' . implode( ' ', $blockers ) . ' ' . __( 'Correct the stock by hand instead.', 'fishotel-misc-plugin' ) );
		}

		$new    = Plan::new_leftovers( $data );
		$sheets = Plan::sheets( $data );
		$failed = '';

		$store->begin();

		if ( ! $store->set_plan_status( $id, 'cut', 'draft', (int) $user_id ) ) {
			$store->rollback();
			return self::result( false, __( 'This plan is not marked as cut, so there is nothing to undo.', 'fishotel-misc-plugin' ) );
		}

		if ( $new && count( $new ) !== (int) $store->delete_leftovers_of_plan( $id ) ) {
			$failed = __( 'a leftover the plan made was changed at the same moment', 'fishotel-misc-plugin' );
		}
		if ( '' === $failed && $used_ids && count( $used_ids ) !== (int) $store->mark_leftovers( $used_ids, 'used', 'stock', $id ) ) {
			$failed = __( 'a leftover the plan cut up was changed at the same moment', 'fishotel-misc-plugin' );
		}
		if ( '' === $failed && $sheets > 0 && ! $store->add_sheet_entry( 'undo', $sheets, $id, (string) $plan['title'], (int) $user_id ) ) {
			$failed = __( 'the sheets could not be put back on the count', 'fishotel-misc-plugin' );
		}
		if ( '' === $failed && ! $store->commit() ) {
			$failed = __( 'the database did not keep the change', 'fishotel-misc-plugin' );
		}

		if ( '' !== $failed ) {
			$store->rollback();
			/* translators: %s: what went wrong */
			return self::result( false, sprintf( __( 'The undo did not go through (%s). Check the stock.', 'fishotel-misc-plugin' ), $failed ) );
		}

		// What came back, in numbers — not "as it was": the sheet count may have been corrected by hand in between.
		$parts = array();
		if ( $sheets > 0 ) {
			/* translators: 1: sheets put back, 2: sheets in stock now */
			$parts[] = sprintf( _n( '%1$d whole sheet is back on the count (%2$d in stock now)', '%1$d whole sheets are back on the count (%2$d in stock now)', $sheets, 'fishotel-misc-plugin' ), $sheets, max( 0, (int) $store->sheets() ) );
		}
		if ( $used_ids ) {
			/* translators: %d: leftovers */
			$parts[] = sprintf( _n( '%d leftover it cut up is back in stock', '%d leftovers it cut up are back in stock', count( $used_ids ), 'fishotel-misc-plugin' ), count( $used_ids ) );
		}
		if ( $new ) {
			/* translators: %d: leftovers */
			$parts[] = sprintf( _n( 'the %d leftover it made is taken out again', 'the %d leftovers it made are taken out again', count( $new ), 'fishotel-misc-plugin' ), count( $new ) );
		}

		return self::result( true, __( 'Undone.', 'fishotel-misc-plugin' ) . ( $parts ? ' ' . ucfirst( implode( '; ', $parts ) ) . '.' : '' ) . ' ' . __( 'The plan is a draft again.', 'fishotel-misc-plugin' ) );
	}

	/* ============================================================== */
	/*  By hand                                                        */
	/* ============================================================== */

	/**
	 * "Bought N sheets".
	 *
	 * @param object $store   Store.
	 * @param mixed  $count   How many (1 or more).
	 * @param string $note    Note.
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string}
	 */
	public static function buy_sheets( $store, $count, $note = '', $user_id = 0 ) {
		// A whole number as typed: "2.5" is not 2 sheets and "1e2" is not 100.
		$count = is_scalar( $count ) && (string) (int) $count === trim( (string) $count ) ? (int) $count : 0;
		if ( $count < 1 || $count > self::MAX_SHEETS ) {
			return self::result( false, __( 'How many sheets? A whole number of 1 or more.', 'fishotel-misc-plugin' ) );
		}

		if ( ! $store->add_sheet_entry( 'bought', $count, 0, (string) $note, (int) $user_id ) ) {
			return self::result( false, __( 'The sheets could not be saved.', 'fishotel-misc-plugin' ) );
		}

		/* translators: 1: sheets bought, 2: sheets in stock now */
		return self::result( true, sprintf( _n( 'Bought %1$d sheet — %2$d in stock now.', 'Bought %1$d sheets — %2$d in stock now.', $count, 'fishotel-misc-plugin' ), $count, max( 0, (int) $store->sheets() ) ) );
	}

	/**
	 * Correct the sheet count to what is really there (stored as the
	 * difference, so the entries still add up).
	 *
	 * @param object $store   Store.
	 * @param mixed  $count   The real number (0 or more).
	 * @param string $note    Note.
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string}
	 */
	public static function count_sheets( $store, $count, $note = '', $user_id = 0 ) {
		if ( ! is_numeric( $count ) || (int) $count < 0 || (int) $count > self::MAX_SHEETS || (string) (int) $count !== trim( (string) $count ) ) {
			return self::result( false, __( 'How many sheets are there? A whole number of 0 or more.', 'fishotel-misc-plugin' ) );
		}

		$count = (int) $count;
		$delta = $count - (int) $store->sheets();

		if ( 0 === $delta ) {
			/* translators: %d: sheets */
			return self::result( true, sprintf( __( 'The count already says %d.', 'fishotel-misc-plugin' ), $count ) );
		}

		if ( ! $store->add_sheet_entry( 'count', $delta, 0, (string) $note, (int) $user_id ) ) {
			return self::result( false, __( 'The count could not be saved.', 'fishotel-misc-plugin' ) );
		}

		/* translators: %d: sheets */
		return self::result( true, sprintf( __( 'The count is now %d.', 'fishotel-misc-plugin' ), $count ) );
	}

	/**
	 * Add a leftover by hand; it gets the next label.
	 *
	 * @param object $store  Store.
	 * @param mixed  $length Length as typed (inches).
	 * @param mixed  $width  Width as typed (inches).
	 * @param string $note   Note.
	 * @param array  $sheet  The whole sheet, [ length, width ] in units: a leftover cannot be larger (empty = not checked).
	 * @return array{ok: bool, message: string, label?: string, id?: int}
	 */
	public static function add_leftover( $store, $length, $width, $note = '', array $sheet = array() ) {
		$sizes = self::sizes( $length, $width );
		if ( null === $sizes ) {
			return self::result( false, __( 'A leftover needs a length and a width above 0, in inches (11.75 or 11 3/4).', 'fishotel-misc-plugin' ) );
		}
		if ( ! self::within( $sizes, $sheet ) ) {
			return self::result( false, self::too_large( $sizes, $sheet ) );
		}

		$number = (int) $store->reserve_labels( 1 );
		if ( $number < 1 ) {
			return self::result( false, __( 'The leftover could not be saved (no label could be reserved).', 'fishotel-misc-plugin' ) );
		}
		$label = self::LABEL_PREFIX . $number;
		$id     = $store->add_leftover(
			array(
				'label'  => $label,
				'length' => $sizes[0],
				'width'  => $sizes[1],
				'status' => 'stock',
				'origin' => 'manual',
				'note'   => (string) $note,
			)
		);

		if ( ! $id ) {
			$store->release_labels( $number, 1 );
			return self::result( false, __( 'The leftover could not be saved.', 'fishotel-misc-plugin' ) );
		}

		return self::result(
			true,
			/* translators: 1: label, 2: size */
			sprintf( __( 'Added. Write %1$s on it (%2$s in).', 'fishotel-misc-plugin' ), $label, Units::size( $sizes[0], $sizes[1] ) ),
			array(
				'label' => $label,
				'id'    => (int) $id,
			)
		);
	}

	/**
	 * Resize a leftover that is in stock (it was trimmed, or measured wrong).
	 *
	 * @param object $store  Store.
	 * @param int    $id     Leftover id.
	 * @param mixed  $length Length as typed (inches).
	 * @param mixed  $width  Width as typed (inches).
	 * @param array  $sheet  The whole sheet, [ length, width ] in units: a leftover cannot be larger (empty = not checked).
	 * @return array{ok: bool, message: string}
	 */
	public static function resize_leftover( $store, $id, $length, $width, array $sheet = array() ) {
		$row = $store->leftover( (int) $id );
		if ( ! $row || 'stock' !== $row['status'] ) {
			return self::result( false, __( 'That leftover is not in stock.', 'fishotel-misc-plugin' ) );
		}

		$sizes = self::sizes( $length, $width );
		if ( null === $sizes ) {
			return self::result( false, __( 'A leftover needs a length and a width above 0, in inches (11.75 or 11 3/4).', 'fishotel-misc-plugin' ) );
		}

		if ( ! self::within( $sizes, $sheet ) ) {
			return self::result( false, self::too_large( $sizes, $sheet ) );
		}

		if ( ! $store->update_leftover( (int) $id, array( 'length' => $sizes[0], 'width' => $sizes[1] ) ) ) {
			return self::result( false, __( 'The leftover could not be saved.', 'fishotel-misc-plugin' ) );
		}

		/* translators: 1: label, 2: size */
		return self::result( true, sprintf( __( '%1$s is now %2$s in.', 'fishotel-misc-plugin' ), $row['label'], Units::size( $sizes[0], $sizes[1] ) ) );
	}

	/**
	 * Remove a leftover that is in stock (thrown away, broken, lost).
	 *
	 * @param object $store Store.
	 * @param int    $id    Leftover id.
	 * @return array{ok: bool, message: string}
	 */
	public static function remove_leftover( $store, $id ) {
		$row = $store->leftover( (int) $id );
		if ( ! $row || 'stock' !== $row['status'] ) {
			return self::result( false, __( 'That leftover is not in stock.', 'fishotel-misc-plugin' ) );
		}

		if ( 1 !== (int) $store->mark_leftovers( array( (int) $id ), 'stock', 'removed', 0 ) ) {
			return self::result( false, __( 'The leftover could not be removed.', 'fishotel-misc-plugin' ) );
		}

		/* translators: %s: label */
		return self::result( true, sprintf( __( '%s removed from the stock.', 'fishotel-misc-plugin' ), $row['label'] ) );
	}

	/**
	 * Whether a leftover of this size can have come out of a whole sheet
	 * (either way round).
	 *
	 * @param int[] $sizes [ length, width ] in units.
	 * @param array $sheet [ length, width ] in units; empty = anything goes.
	 * @return bool
	 */
	public static function within( array $sizes, array $sheet ) {
		if ( 2 !== count( $sheet ) ) {
			return true;
		}
		$sheet = array_map( 'intval', array_values( $sheet ) );

		return max( $sizes ) <= max( $sheet ) && min( $sizes ) <= min( $sheet );
	}

	/**
	 * "Larger than a whole sheet", in words.
	 *
	 * @param int[] $sizes [ length, width ] in units.
	 * @param array $sheet [ length, width ] in units.
	 * @return string
	 */
	private static function too_large( array $sizes, array $sheet ) {
		$sheet = array_map( 'intval', array_values( $sheet ) );

		/* translators: 1: the size typed, 2: the size of a whole sheet */
		return sprintf( __( '%1$s in is larger than a whole sheet (%2$s in), so it cannot be a leftover. Check the numbers; nothing was saved.', 'fishotel-misc-plugin' ), Units::size( $sizes[0], $sizes[1] ), Units::size( $sheet[0], $sheet[1] ) );
	}

	/**
	 * A typed length and width as units, or null. They are kept the way
	 * round they were typed: a leftover's length runs the way the sheet's
	 * length ran, which only matters while turning pieces is switched off.
	 *
	 * @param mixed $length Length as typed.
	 * @param mixed $width  Width as typed.
	 * @return int[]|null
	 */
	public static function sizes( $length, $width ) {
		$a = is_scalar( $length ) ? Units::parse( is_string( $length ) ? $length : (float) $length ) : null;
		$b = is_scalar( $width ) ? Units::parse( is_string( $width ) ? $width : (float) $width ) : null;

		if ( null === $a || null === $b || $a <= 0 || $b <= 0 ) {
			return null;
		}

		return array( $a, $b );
	}
}
