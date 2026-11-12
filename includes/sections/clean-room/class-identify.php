<?php
/**
 * Clean Room identify — unknown and mislabeled fish, and the ONE
 * correction that puts a stay and its supplier order line right together.
 *
 * Fish come in mislabeled, or with no usable label at all. So:
 *
 *   unknown   a supplier order line and a stay can be flagged needs_id
 *             ("needs identifying"). Its name is then "??" followed by an
 *             optional description — "?? small wrasse" (unknown_name()) —
 *             so every screen, toast and export that prints a species
 *             prints the "??" without knowing about the flag. An unknown
 *             fish gets its tank, count, size, percent of a box and landed
 *             cost like any other; only a remembered box share is never
 *             written for it (Box_Shares::eligible()). A name typed with a
 *             leading "??" on the order page, the Stays tab's Edit or the
 *             Front Desk is an unknown fish too; the workbook import never
 *             flags anything.
 *
 *   label     an order line keeps label_name: what the supplier's label or
 *             invoice called the fish. It is set from the line's name the
 *             first time the line is corrected to a different fish, and
 *             never overwritten after that; a "??" placeholder is never a
 *             label (label_for()). A received line whose label differs
 *             from the fish it turned out to be is "mislabeled"
 *             (is_mislabeled()) — the Stats tab counts them per supplier
 *             and per origin.
 *
 *   correct   stay() (from the Stays tab, its Edit and the Front Desk) and
 *             line() (from the order page) take the real fish — picked
 *             from the same search the Arrival sheet uses (fish_from(): a
 *             product, a species-list fish or a typed name) — and its
 *             size, and:
 *               - the stay gets the name, the product link, the size, and
 *                 loses the flag;
 *               - its order line gets the species-list id, the name, the
 *                 scientific name and the size, loses the flag, and keeps
 *                 its old name as label_name;
 *               - the stay gets a note event ("identified: <old> → <new>")
 *                 so the Events tab shows who corrected what and when (a
 *                 change of size alone writes none);
 *               - the remembered box share of the corrected fish is
 *                 written (Box_Shares::remember_line()), the one counted
 *                 under the wrong fish given back.
 *
 *   split     a group that was split has several stays on one order line.
 *             The line follows its stays only when they all agree: while
 *             a sister stay is still a different fish, a correction
 *             changes the stay alone and the line gets a note —
 *             "[part of this line is <fish> — stay #<id>]" — for every
 *             stay that is not what the line says (part_notes()). Once the
 *             last sister is corrected to the same fish the line follows
 *             and the notes go. A correction made on the order line
 *             corrects the line and every stay that still carried the
 *             line's old name; a sister already identified as something
 *             else keeps its own.
 *
 * Nothing here touches $wpdb: stay() and line() take the store they write
 * to (the real Store or the tests' stand-in), like Orders::check_in().
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Identify
 */
class Identify {

	/**
	 * What the name of an unknown fish starts with.
	 *
	 * @var string
	 */
	const MARK = '??';

	/**
	 * The notes of the event a correction writes on a stay: the result is
	 * a known fish, or the fish was marked unknown.
	 */
	const NOTE_IDENTIFIED = 'identified: ';
	const NOTE_UNKNOWN    = 'marked unknown: ';

	/**
	 * The note an order line carries for a stay that is not what the line
	 * says: PART_OPEN + the stay's fish + " — stay #<id>]".
	 *
	 * @var string
	 */
	const PART_OPEN = '[part of this line is ';

	const MAX_NAME = 120;

	/* ============================================================== */
	/*  Names                                                          */
	/* ============================================================== */

	/**
	 * The name of an unknown fish: "??", or "?? " + a description. A mark
	 * that is already there is not doubled.
	 *
	 * @param mixed $description What it looks like ("small wrasse"), or nothing.
	 * @return string
	 */
	public static function unknown_name( $description = '' ) {
		$text = is_scalar( $description ) && ! is_bool( $description ) ? (string) $description : '';
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$text = trim( ltrim( $text, '? ' ) );

		return (string) substr( '' === $text ? self::MARK : self::MARK . ' ' . $text, 0, self::MAX_NAME );
	}

	/**
	 * Whether a name is the placeholder of an unknown fish (it starts
	 * with "??").
	 *
	 * @param mixed $name A name.
	 * @return bool
	 */
	public static function is_unknown_name( $name ) {
		return is_scalar( $name ) && 0 === strpos( ltrim( (string) $name ), self::MARK );
	}

	/**
	 * Whether two names are the same fish: case and spacing aside.
	 *
	 * @param mixed $a One name.
	 * @param mixed $b The other.
	 * @return bool
	 */
	public static function same_fish( $a, $b ) {
		return Species::key( (string) $a ) === Species::key( (string) $b );
	}

	/**
	 * Whether a stay or a line is this fish: the same name, and as unknown.
	 *
	 * @param array $row  species, needs_id.
	 * @param array $fish name, unknown.
	 * @return bool
	 */
	public static function is( array $row, array $fish ) {
		return self::same_fish( $row['species'] ?? '', $fish['name'] ?? '' ) && ! empty( $row['needs_id'] ) === ! empty( $fish['unknown'] );
	}

	/**
	 * Flag the lines of an order form whose name was typed with a leading
	 * "??" (pure): such a line is an unknown fish even when the box was
	 * not ticked, and an unknown fish is no species-list fish. Not used by
	 * the workbook import — an imported line is never flagged.
	 *
	 * @param array[] $lines Clean lines (Orders::normalize_lines()).
	 * @return array[]
	 */
	public static function flag_lines( array $lines ) {
		foreach ( $lines as $index => $line ) {
			if ( ! is_array( $line ) || ( empty( $line['needs_id'] ) && ! self::is_unknown_name( $line['species'] ?? '' ) ) ) {
				continue;
			}
			$lines[ $index ]['needs_id']   = true;
			$lines[ $index ]['species']    = self::unknown_name( $line['species'] ?? '' );
			$lines[ $index ]['species_id'] = null;
		}
		return $lines;
	}

	/* ============================================================== */
	/*  The label                                                      */
	/* ============================================================== */

	/**
	 * The label_name a correction gives a line (pure): its old name, the
	 * first time it is corrected to a different fish. Null = leave the
	 * column alone — the line has one already (never overwritten), its old
	 * name is a "??" placeholder (not something a label said), or the new
	 * name is the same fish.
	 *
	 * @param array  $line     The line before the correction (species, needs_id, label_name).
	 * @param string $new_name The corrected name.
	 * @return string|null
	 */
	public static function label_for( array $line, $new_name ) {
		if ( isset( $line['label_name'] ) && '' !== trim( (string) $line['label_name'] ) ) {
			return null;
		}
		$old = trim( (string) ( $line['species'] ?? '' ) );
		if ( '' === $old || ! empty( $line['needs_id'] ) || self::is_unknown_name( $old ) ) {
			return null;
		}
		if ( self::same_fish( $old, $new_name ) ) {
			return null;
		}
		return (string) substr( $old, 0, self::MAX_NAME );
	}

	/**
	 * Whether a line was mislabeled (pure): it has a label, the fish is
	 * identified, and the label names a different fish.
	 *
	 * @param array $line species, needs_id, label_name.
	 * @return bool
	 */
	public static function is_mislabeled( array $line ) {
		$label = isset( $line['label_name'] ) ? trim( (string) $line['label_name'] ) : '';
		return '' !== $label && empty( $line['needs_id'] ) && ! self::same_fish( $label, $line['species'] ?? '' );
	}

	/**
	 * A line's notes with one "[part of this line is … — stay #N]" per
	 * stay that is not what the line says (pure): the older ones are
	 * dropped first, so the notes always describe the stays as they are.
	 *
	 * @param string  $notes   The line's notes.
	 * @param string  $name    The line's fish.
	 * @param bool    $unknown Whether the line still needs identifying.
	 * @param array[] $stays   Every stay of the line (id, species, needs_id).
	 * @return string
	 */
	public static function part_notes( $notes, $name, $unknown, array $stays ) {
		$kept  = preg_replace( '/\s*' . preg_quote( self::PART_OPEN, '/' ) . '[^\]]*\]/', '', (string) $notes );
		$base  = trim( null === $kept ? (string) $notes : $kept );
		$marks = array();
		$fish  = array(
			'name'    => (string) $name,
			'unknown' => (bool) $unknown,
		);
		foreach ( $stays as $stay ) {
			if ( ! is_array( $stay ) || self::is( $stay, $fish ) ) {
				continue;
			}
			$marks[] = self::PART_OPEN . str_replace( array( '[', ']' ), array( '(', ')' ), (string) ( $stay['species'] ?? '' ) ) . ' — stay #' . (int) ( $stay['id'] ?? 0 ) . ']';
		}
		return trim( $base . ( $marks ? ' ' . implode( ' ', $marks ) : '' ) );
	}

	/* ============================================================== */
	/*  The fish a correction names                                    */
	/* ============================================================== */

	/**
	 * The fish a form picked — the same choice the Arrival sheet offers: a
	 * product, a species-list fish, a typed name, or "Unknown fish (??)"
	 * with an optional description — and its size. A product that does not
	 * exist is dropped (never assumed); a species-list fish is found by
	 * its product, else by its name, and brings its id, its scientific
	 * name and, when no product was picked, its spelling and its product
	 * link. A size is
	 * one of the standard sizes, the row's own stored size, or none; a
	 * payload without a `size` leaves the size as it is.
	 *
	 * @param object $store    Store (or stand-in) with product_title(), species_by_product(), species_by_name().
	 * @param array  $raw      Unslashed payload: species, product_id, unknown, size.
	 * @param array  $settings Settings (for the standard sizes).
	 * @param array  $current  The stay or the line being corrected (size_slug, size_label).
	 * @return array name, unknown, product_id, species_id, sci_name, size_slug, size_label — or error.
	 */
	public static function fish_from( $store, array $raw, array $settings, array $current = array() ) {
		$name       = isset( $raw['species'] ) && is_scalar( $raw['species'] ) && ! is_bool( $raw['species'] ) ? sanitize_text_field( (string) preg_replace( '/\s+/', ' ', (string) $raw['species'] ) ) : '';
		$name       = (string) substr( $name, 0, self::MAX_NAME );
		$unknown    = isset( $raw['unknown'] ) && is_scalar( $raw['unknown'] ) && ! in_array( strtolower( trim( (string) $raw['unknown'] ) ), array( '', '0', 'false', 'no' ), true );
		$product_id = isset( $raw['product_id'] ) && is_scalar( $raw['product_id'] ) && is_numeric( $raw['product_id'] ) ? max( 0, (int) $raw['product_id'] ) : 0;

		if ( $unknown || self::is_unknown_name( $name ) ) {
			$fish = array(
				'name'       => self::unknown_name( $name ),
				'unknown'    => true,
				'product_id' => null,
				'species_id' => null,
				'sci_name'   => '',
			);
		} else {
			if ( $product_id > 0 ) {
				$title = (string) $store->product_title( $product_id );
				if ( '' === $title ) {
					$product_id = 0; // Never assume a product exists.
				} elseif ( '' === $name ) {
					$name = $title;
				}
			}
			if ( '' === $name ) {
				return array( 'error' => __( 'Which fish? Type or pick a species.', 'fishotel-misc-plugin' ) );
			}
			$row = null;
			if ( $product_id > 0 && method_exists( $store, 'species_by_product' ) ) {
				$row = $store->species_by_product( $product_id );
			}
			if ( ! $row && method_exists( $store, 'species_by_name' ) ) {
				$row = $store->species_by_name( $name );
				if ( $row && $product_id <= 0 && '' !== trim( (string) $row['name'] ) ) {
					$name = (string) $row['name']; // Typed any which way: the species list's spelling.
				}
			}
			if ( $row && $product_id <= 0 && ! empty( $row['product_id'] ) && '' !== (string) $store->product_title( (int) $row['product_id'] ) ) {
				$product_id = (int) $row['product_id'];
			}
			$fish = array(
				'name'       => $name,
				'unknown'    => false,
				'product_id' => $product_id > 0 ? $product_id : null,
				'species_id' => $row ? (int) $row['id'] : null,
				'sci_name'   => $row ? (string) $row['sci_name'] : null,
			);
		}

		if ( array_key_exists( 'size', $raw ) ) {
			// The slug as posted (a term slug may hold percent-encoded characters): it is
			// only ever compared with the list's own slugs.
			$slug   = is_scalar( $raw['size'] ) && ! is_bool( $raw['size'] ) ? trim( (string) $raw['size'] ) : $raw['size'];
			$picked = Sizes::pick( $slug, is_string( $slug ) && '' !== $slug ? Sizes::standard( $store, $settings )['sizes'] : array(), $current );
			if ( ! $picked['ok'] ) {
				return array( 'error' => __( 'That size is not in the list of sizes (Settings → Sizes). Pick one, or none.', 'fishotel-misc-plugin' ) );
			}
			$fish['size_slug']  = $picked['slug'];
			$fish['size_label'] = $picked['label'];
		} else {
			$fish['size_slug']  = isset( $current['size_slug'] ) && '' !== (string) $current['size_slug'] ? (string) $current['size_slug'] : null;
			$fish['size_label'] = null !== $fish['size_slug'] && isset( $current['size_label'] ) ? (string) $current['size_label'] : null;
		}

		return $fish;
	}

	/**
	 * The fish an order line names, as fish_from() gives one — for a line
	 * that became a stay and was changed in the order editor's grid.
	 *
	 * @param object $store Store (or stand-in) with species(), species_by_name(), product_title().
	 * @param array  $line  The line as saved (species, species_id, sci_name, needs_id, size_slug, size_label).
	 * @return array fish_from().
	 */
	public static function fish_of_line( $store, array $line ) {
		$unknown = ! empty( $line['needs_id'] );
		$row     = null;
		if ( ! $unknown ) {
			if ( (int) ( $line['species_id'] ?? 0 ) > 0 && method_exists( $store, 'species' ) ) {
				$row = $store->species( (int) $line['species_id'] );
			}
			if ( ! $row && method_exists( $store, 'species_by_name' ) ) {
				$row = $store->species_by_name( (string) ( $line['species'] ?? '' ) );
			}
		}
		$product_id = $row && ! empty( $row['product_id'] ) && '' !== (string) $store->product_title( (int) $row['product_id'] ) ? (int) $row['product_id'] : null;
		$size       = isset( $line['size_slug'] ) && '' !== (string) $line['size_slug'] ? (string) $line['size_slug'] : null;

		return array(
			'name'       => (string) ( $line['species'] ?? '' ),
			'unknown'    => $unknown,
			'product_id' => $product_id,
			'species_id' => $row ? (int) $row['id'] : null,
			'sci_name'   => (string) ( $line['sci_name'] ?? '' ),
			'size_slug'  => $size,
			'size_label' => null !== $size && isset( $line['size_label'] ) ? (string) $line['size_label'] : null,
		);
	}

	/**
	 * Whether the fish of a line changed between two of its states (pure):
	 * the name as written, the flag, or the size.
	 *
	 * @param array $before The line as it was.
	 * @param array $after  The line as it is.
	 * @return bool
	 */
	public static function line_differs( array $before, array $after ) {
		return trim( (string) ( $before['species'] ?? '' ) ) !== trim( (string) ( $after['species'] ?? '' ) )
			|| ! empty( $before['needs_id'] ) !== ! empty( $after['needs_id'] )
			|| (string) ( $before['size_slug'] ?? '' ) !== (string) ( $after['size_slug'] ?? '' );
	}

	/* ============================================================== */
	/*  The correction                                                 */
	/* ============================================================== */

	/**
	 * Correct the fish of ONE stay — and its order line with it, when
	 * every stay of that line is the same fish afterwards (a stay without
	 * a line, or split off a line whose other stays are something else,
	 * changes alone; the line then gets its "part of this line" note).
	 * Needs the store's update_stay_fields(), record_event(), order(),
	 * stays_for_line(), update_line_fields() and the box-share methods.
	 *
	 * @param object $store Store or stand-in.
	 * @param array  $stay  The hydrated stay.
	 * @param array  $fish  fish_from().
	 * @param array  $ctx   today, settings, actor_id, source.
	 * @return array ok, changed (false = nothing was different: no write, no event, no message), error,
	 *               message, event_ids, stays, line ('' | followed | part), line_id, label.
	 */
	public static function stay( $store, array $stay, array $fish, array $ctx ) {
		$out     = self::result();
		$stay_id = (int) ( $stay['id'] ?? 0 );
		$renamed = (string) ( $stay['species'] ?? '' ) !== (string) $fish['name'] || ! empty( $stay['needs_id'] ) !== ! empty( $fish['unknown'] );
		$resized = (string) ( $stay['size_slug'] ?? '' ) !== (string) ( $fish['size_slug'] ?? '' );
		$linked  = (int) ( $stay['product_id'] ?? 0 ) !== (int) ( $fish['product_id'] ?? 0 );

		if ( ! $renamed && ! $resized && ! $linked ) {
			return $out; // Nothing changed: no write, no event, no message.
		}

		if ( ! $store->update_stay_fields( $stay_id, self::stay_fields( $fish, true ) ) ) {
			return self::failed( $out, __( 'The fish could not be saved.', 'fishotel-misc-plugin' ) );
		}
		$out['changed'] = true;
		$out['stays'][] = $stay_id;
		if ( $renamed ) {
			$out['event_ids'] = array_merge( $out['event_ids'], self::write_event( $store, $stay_id, (string) $stay['species'], $fish, $ctx ) );
		}

		$found = $renamed || $resized ? self::line_of( $store, $stay ) : null;
		if ( $found ) {
			list( $order, $line ) = $found;
			$out['line_id']       = (int) $line['id'];
			$stays                = (array) $store->stays_for_line( (int) $line['id'], (string) ( $ctx['today'] ?? '' ), (array) ( $ctx['settings'] ?? array() ) );
			$agree                = true;
			foreach ( $stays as $other ) {
				if ( ! self::is( $other, $fish ) ) {
					$agree = false;
					break;
				}
			}
			if ( $agree ) {
				$out['line']  = 'followed';
				$out['label'] = self::apply_to_line( $store, $order, $line, $fish, $stays, $ctx );
			} else {
				$out['line'] = 'part';
				$notes       = self::part_notes( (string) ( $line['notes'] ?? '' ), (string) $line['species'], ! empty( $line['needs_id'] ), $stays );
				if ( $notes !== (string) ( $line['notes'] ?? '' ) ) {
					$store->update_line_fields( (int) $line['id'], array( 'notes' => $notes ) );
				}
			}
		}

		$out['message'] = self::message( (string) ( $stay['species'] ?? '' ), $fish, $renamed, $out, false );
		return $out;
	}

	/**
	 * Correct the fish of an order LINE: the line itself, and every stay
	 * of it that still carried the line's old name (a sister stay already
	 * identified as something else keeps its own and stays noted on the
	 * line). A line without a stay — received fish not checked in, or
	 * imported history — is corrected alone.
	 *
	 * @param object $store Store or stand-in.
	 * @param array  $order The line's order (Store::order()).
	 * @param array  $line  The line BEFORE the correction.
	 * @param array  $fish  fish_from() / fish_of_line().
	 * @param array  $ctx   today, settings, actor_id, source.
	 * @return array As stay().
	 */
	public static function line( $store, array $order, array $line, array $fish, array $ctx ) {
		$out     = self::result();
		$line_id = (int) ( $line['id'] ?? 0 );
		$old     = array(
			'name'    => (string) ( $line['species'] ?? '' ),
			'unknown' => ! empty( $line['needs_id'] ),
		);
		$renamed = $old['name'] !== (string) $fish['name'] || $old['unknown'] !== ! empty( $fish['unknown'] );
		$resized = (string) ( $line['size_slug'] ?? '' ) !== (string) ( $fish['size_slug'] ?? '' );
		$listed  = empty( $fish['unknown'] ) && null !== ( $fish['species_id'] ?? null ) && (int) $fish['species_id'] !== (int) ( $line['species_id'] ?? 0 );

		$out['line_id'] = $line_id;
		if ( $line_id <= 0 || ( ! $renamed && ! $resized && ! $listed ) ) {
			return $out; // Nothing changed: no write, no event, no message.
		}

		$today    = (string) ( $ctx['today'] ?? '' );
		$settings = (array) ( $ctx['settings'] ?? array() );
		foreach ( (array) $store->stays_for_line( $line_id, $today, $settings ) as $stay ) {
			if ( ! self::is( $stay, $old ) ) {
				continue; // Already identified as something else: it keeps its own.
			}
			$same_size = (string) ( $stay['size_slug'] ?? '' ) === (string) ( $fish['size_slug'] ?? '' );
			if ( ! $renamed && $same_size ) {
				continue;
			}
			if ( ! $store->update_stay_fields( (int) $stay['id'], self::stay_fields( $fish, $renamed ) ) ) {
				continue;
			}
			$out['stays'][] = (int) $stay['id'];
			if ( $renamed ) {
				$out['event_ids'] = array_merge( $out['event_ids'], self::write_event( $store, (int) $stay['id'], (string) $stay['species'], $fish, $ctx ) );
			}
		}

		$out['changed'] = true;
		$out['line']    = 'followed';
		$out['label']   = self::apply_to_line( $store, $order, $line, $fish, (array) $store->stays_for_line( $line_id, $today, $settings ), $ctx );
		$out['message'] = self::message( $old['name'], $fish, $renamed, $out, true );

		return $out;
	}

	/**
	 * Write the corrected fish on a line: the species-list id, the name,
	 * the scientific name, the size, the flag, the label the first time,
	 * the "part of this line" notes as the stays are now — then the
	 * remembered box share of the line as it is stored.
	 *
	 * @param object  $store Store or stand-in.
	 * @param array   $order The line's order.
	 * @param array   $line  The line before the correction.
	 * @param array   $fish  The fish.
	 * @param array[] $stays Every stay of the line, as they are now.
	 * @param array   $ctx   today.
	 * @return string|null The label_name this correction set (null = none).
	 */
	private static function apply_to_line( $store, array $order, array $line, array $fish, array $stays, array $ctx ) {
		$line_id = (int) $line['id'];
		$unknown = ! empty( $fish['unknown'] );
		$same    = self::same_fish( $line['species'] ?? '', $fish['name'] );
		$fields  = array(
			'species'    => (string) $fish['name'],
			'size_slug'  => $fish['size_slug'] ?? null,
			'size_label' => $fish['size_label'] ?? null,
			'needs_id'   => $unknown,
		);
		// The same fish respelled or resized keeps the species-list id it had when none was found.
		if ( $unknown || null !== ( $fish['species_id'] ?? null ) || ! $same ) {
			$fields['species_id'] = $unknown ? null : ( $fish['species_id'] ?? null );
		}
		if ( isset( $fish['sci_name'] ) && null !== $fish['sci_name'] ) {
			$fields['sci_name'] = (string) $fish['sci_name'];
		} elseif ( ! $same ) {
			$fields['sci_name'] = ''; // The old scientific name belonged to another fish.
		}
		$label = self::label_for( $line, (string) $fish['name'] );
		if ( null !== $label ) {
			$fields['label_name'] = $label;
		}
		$notes = self::part_notes( (string) ( $line['notes'] ?? '' ), (string) $fish['name'], $unknown, $stays );
		if ( $notes !== (string) ( $line['notes'] ?? '' ) ) {
			$fields['notes'] = $notes;
		}
		$store->update_line_fields( $line_id, $fields );

		// The remembered share: counted under the corrected fish, given back by the wrong one
		// (and by a fish that still needs identifying — Box_Shares::eligible()).
		$fresh = $store->order( (int) ( $order['id'] ?? 0 ) );
		foreach ( (array) ( $fresh['lines'] ?? array() ) as $stored ) {
			if ( (int) $stored['id'] === $line_id ) {
				Box_Shares::remember_line( $store, $fresh, $stored, (string) ( $ctx['today'] ?? '' ) );
				break;
			}
		}

		return $label;
	}

	/**
	 * The order and the order line a stay was checked in from.
	 *
	 * @param object $store Store or stand-in.
	 * @param array  $stay  Hydrated stay (order_line_id, batch_order_id).
	 * @return array|null array( order, line ).
	 */
	public static function line_of( $store, array $stay ) {
		$line_id  = (int) ( $stay['order_line_id'] ?? 0 );
		$order_id = (int) ( $stay['batch_order_id'] ?? 0 );
		if ( $line_id <= 0 || $order_id <= 0 ) {
			return null;
		}
		$order = $store->order( $order_id );
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( (int) $line['id'] === $line_id ) {
				return array( $order, $line );
			}
		}
		return null;
	}

	/**
	 * The stay columns a fish writes.
	 *
	 * @param array $fish    The fish.
	 * @param bool  $product Whether the product link follows (it does when the fish changed).
	 * @return array
	 */
	private static function stay_fields( array $fish, $product ) {
		$fields = array(
			'species'    => (string) $fish['name'],
			'size_slug'  => $fish['size_slug'] ?? null,
			'size_label' => $fish['size_label'] ?? null,
			'needs_id'   => ! empty( $fish['unknown'] ),
		);
		if ( $product ) {
			$fields['product_id'] = (int) ( $fish['product_id'] ?? 0 );
		}
		return $fields;
	}

	/**
	 * The note of a correction's event (pure): "identified: ?? small
	 * wrasse → Lined Wrasse · 2-3"", or "marked unknown: …" when the
	 * result is a fish that still has to be identified.
	 *
	 * @param string $old_name The stay's name before.
	 * @param array  $fish     The fish.
	 * @return string
	 */
	public static function note( $old_name, array $fish ) {
		$new = (string) $fish['name'] . ( isset( $fish['size_label'] ) && '' !== (string) $fish['size_label'] ? ' · ' . (string) $fish['size_label'] : '' );
		return ( ! empty( $fish['unknown'] ) ? self::NOTE_UNKNOWN : self::NOTE_IDENTIFIED ) . (string) $old_name . ' → ' . $new;
	}

	/**
	 * Whether an event note is a correction's.
	 *
	 * @param string $note An event's note.
	 * @return bool
	 */
	public static function is_note( $note ) {
		return 0 === strpos( (string) $note, self::NOTE_IDENTIFIED ) || 0 === strpos( (string) $note, self::NOTE_UNKNOWN );
	}

	/**
	 * Write the note event of a correction on a stay.
	 *
	 * @param object $store    Store or stand-in.
	 * @param int    $stay_id  The stay.
	 * @param string $old_name Its name before.
	 * @param array  $fish     The fish.
	 * @param array  $ctx      today, settings, actor_id, source.
	 * @return int[] The event id (empty when it could not be written).
	 */
	private static function write_event( $store, $stay_id, $old_name, array $fish, array $ctx ) {
		$written = $store->record_event(
			(int) $stay_id,
			array(
				'type'     => 'note',
				'note'     => self::note( $old_name, $fish ),
				'event_at' => gmdate( 'Y-m-d H:i:s' ),
				'actor_id' => (int) ( $ctx['actor_id'] ?? 0 ),
				'source'   => (string) ( $ctx['source'] ?? 'manual' ),
			),
			(string) ( $ctx['today'] ?? '' ),
			(array) ( $ctx['settings'] ?? array() )
		);
		return $written ? array( (int) $written['event_id'] ) : array();
	}

	/**
	 * What a correction did, in one line without its full stop — '' for a
	 * change of size or product link alone that touched nothing else.
	 *
	 * @param string $old_name  The name before.
	 * @param array  $fish      The fish.
	 * @param bool   $renamed   Whether the name or the flag changed.
	 * @param array  $out       The result so far (line, label, stays).
	 * @param bool   $from_line Whether the correction was made on the order line (else on a stay).
	 * @return string
	 */
	private static function message( $old_name, array $fish, $renamed, array $out, $from_line ) {
		$parts = array();
		if ( $renamed ) {
			$new     = (string) $fish['name'] . ( isset( $fish['size_label'] ) && '' !== (string) $fish['size_label'] ? ' · ' . (string) $fish['size_label'] : '' );
			$parts[] = ! empty( $fish['unknown'] )
				/* translators: 1: the name before, 2: the "??" name now */
				? sprintf( __( 'Marked unknown: %1$s → %2$s', 'fishotel-misc-plugin' ), $old_name, $new )
				/* translators: 1: the name before, 2: the fish it is */
				: sprintf( __( 'Identified: %1$s → %2$s', 'fishotel-misc-plugin' ), $old_name, $new );
		}
		if ( $from_line ) {
			$stays = count( (array) $out['stays'] );
			if ( $stays > 0 ) {
				/* translators: %d: stays */
				$parts[] = sprintf( _n( '%d stay follows', '%d stays follow', $stays, 'fishotel-misc-plugin' ), $stays );
			}
		} elseif ( 'followed' === $out['line'] ) {
			$parts[] = __( 'the order line follows', 'fishotel-misc-plugin' );
		} elseif ( 'part' === $out['line'] ) {
			$parts[] = __( 'its order line keeps its name — other fish of that line are something else', 'fishotel-misc-plugin' );
		}
		if ( null !== $out['label'] ) {
			/* translators: %s: what the supplier's label called the fish */
			$parts[] = sprintf( __( 'arrived labeled: %s', 'fishotel-misc-plugin' ), (string) $out['label'] );
		}
		return ucfirst( implode( ' · ', $parts ) );
	}

	private static function result() {
		return array(
			'ok'        => true,
			'changed'   => false,
			'error'     => '',
			'message'   => '',
			'event_ids' => array(),
			'stays'     => array(),
			'line'      => '',
			'line_id'   => 0,
			'label'     => null,
		);
	}

	private static function failed( array $out, $error ) {
		$out['ok']    = false;
		$out['error'] = (string) $error;
		return $out;
	}
}
