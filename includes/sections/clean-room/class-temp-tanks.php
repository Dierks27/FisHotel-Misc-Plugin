<?php
/**
 * Clean Room temporary tanks — hospital tanks and the like: a tank that is
 * set up for a while, for one reason, and taken down again.
 *
 * The layout keeps a building "Temporary" (number 900) whose tanks are
 * numbered SLOTS, each its own water (Layout). A slot is only a place and
 * a laminated label; a SETUP is what stands in it for now:
 *
 *   - a name, what it is for, the gallons and a note;
 *   - its KIND — quarantine (it behaves as a Spa tank: arrivals may go in,
 *     fish in it stay uncleared, a move from it into a clean tank clears
 *     the group) or clean (it behaves as a Clean Room tank: no direct
 *     arrivals, a quarantine group moved into it is cleared). The kind is
 *     fixed for the life of a setup: every move event remembers the setup
 *     it went into or came out of, and the Replay reads the area from it,
 *     so the same slot can be quarantine this month and clean the next
 *     without rewriting anybody's history;
 *   - its OWN schedule: the steps of the treatment template chosen at
 *     setup are COPIED into it. They can be changed for this tank alone,
 *     and a later edit of the template never reaches a tank that is
 *     already running;
 *   - its OWN clock: the to-do counts days from the setup's treatment
 *     start date — not from a fish's check-in and not from a building. A
 *     fish's own quarantine day and dates are untouched by sitting here.
 *
 * "What is it for?" leads to the treatment: every schedule template can
 * carry "Used for" tags (TAGS_OPTION — kept beside the templates, so the
 * templates' own option keeps its shape). Only the Trifecta template is
 * seeded with one ("bacterial infection": it is the antibiotics
 * schedule); nothing else is guessed.
 *
 * Taking a setup down closes it — the row, its ticks, doses and readings
 * (fh_tank_logs.setup_id) and the groups that passed through (the setup
 * ids on their move events) stay as its history — and frees the slot. The
 * next setup in that slot starts clean. A setup with fish in it cannot be
 * taken down.
 *
 * Everything here is pure or takes the store as a parameter (the real
 * Store, or the tests' stand-in), like Orders::check_in().
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Temp_Tanks
 */
class Temp_Tanks {

	const KIND_QUARANTINE = 'quarantine';
	const KIND_CLEAN      = 'clean';

	/**
	 * The two kinds, the safe one first (it is the default: fish in a
	 * quarantine tank are never cleared by being put there).
	 *
	 * @var string[]
	 */
	const KINDS = array( self::KIND_QUARANTINE, self::KIND_CLEAN );

	/**
	 * Option: template name => its "Used for" tags. Absent until a tag is
	 * first saved; until then SEED_TAGS stands in.
	 *
	 * @var string
	 */
	const TAGS_OPTION = 'fishotel_cleanroom_template_tags';

	/**
	 * The one seed: the antibiotics schedule is what a bacterial infection
	 * is treated with. Every other template is left for the owner to tag.
	 *
	 * @var array<string, string[]>
	 */
	const SEED_TAGS = array(
		'Trifecta' => array( 'bacterial infection' ),
	);

	const MAX_TAGS    = 12;
	const MAX_TAG     = 40;
	const MAX_NAME    = 60;
	const MAX_PURPOSE = 120;
	const MAX_NOTE    = 2000;

	/**
	 * How far back / ahead of today a treatment may start (days).
	 */
	const START_BACK  = 366;
	const START_AHEAD = 60;

	/**
	 * The move reason the Move sheet offers beside the others.
	 *
	 * @var string
	 */
	const MOVE_REASON = 'hospital';

	/* ============================================================== */
	/*  Kinds and areas                                                */
	/* ============================================================== */

	/**
	 * A clean kind, else quarantine (anything unknown is the safe one).
	 *
	 * @param mixed $kind Raw kind.
	 * @return string
	 */
	public static function kind( $kind ) {
		return self::KIND_CLEAN === ( is_scalar( $kind ) ? (string) $kind : '' ) ? self::KIND_CLEAN : self::KIND_QUARANTINE;
	}

	/**
	 * The area a setup's tank behaves as: 'clean' or 'spa'.
	 *
	 * @param string $kind quarantine|clean.
	 * @return string
	 */
	public static function kind_area( $kind ) {
		return self::KIND_CLEAN === self::kind( $kind ) ? 'clean' : 'spa';
	}

	/**
	 * 'Clean' / 'Quarantine'.
	 *
	 * @param string $kind quarantine|clean.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		return self::KIND_CLEAN === self::kind( $kind ) ? __( 'Clean', 'fishotel-misc-plugin' ) : __( 'Quarantine', 'fishotel-misc-plugin' );
	}

	/**
	 * What a tank behaves as today: its building's area for a rack tank;
	 * for a slot, 'spa' or 'clean' by the kind of what is set up in it —
	 * and 'temp' (neither) while nothing is.
	 *
	 * @param array $tank Hydrated tank (building, setup).
	 * @return string
	 */
	public static function effective_area( array $tank ) {
		if ( ! Layout::is_slot( $tank ) ) {
			return (string) ( $tank['building']['area'] ?? '' );
		}
		if ( empty( $tank['setup'] ) || ! is_array( $tank['setup'] ) ) {
			return Layout::TEMP_AREA;
		}
		return self::kind_area( $tank['setup']['kind'] ?? '' );
	}

	/**
	 * Whether a slot has nothing set up in it.
	 *
	 * @param array $tank Hydrated tank.
	 * @return bool
	 */
	public static function is_free( array $tank ) {
		return Layout::is_slot( $tank ) && empty( $tank['setup'] );
	}

	/**
	 * Whether a check-in may go into a tank: a Spa tank, or a quarantine
	 * temporary tank — active, in a building that is not offline.
	 *
	 * @param array|null $tank Hydrated tank.
	 * @return bool
	 */
	public static function takes_arrivals( $tank ) {
		if ( ! is_array( $tank ) || 'offline' === (string) ( $tank['status'] ?? 'active' ) || 'offline' === (string) ( $tank['building']['status'] ?? 'active' ) ) {
			return false;
		}
		return 'spa' === self::effective_area( $tank );
	}

	/**
	 * Why fish cannot be moved (or checked in) into a tank: '' when they
	 * can, else the sentence — a slot with nothing set up in it holds no
	 * fish.
	 *
	 * @param array|null $tank Hydrated destination tank.
	 * @return string
	 */
	public static function destination_error( $tank ) {
		if ( is_array( $tank ) && self::is_free( $tank ) ) {
			/* translators: %s: slot label (TEMP 3 · 903) */
			return sprintf( __( 'Nothing is set up in %s — set it up first.', 'fishotel-misc-plugin' ), Layout::tank_label( $tank ) );
		}
		return '';
	}

	/**
	 * Every tank with its open setup (slots only) and what it behaves as:
	 * 'setup' (the row, or null) and 'effective_area'.
	 *
	 * @param array[] $tanks Hydrated tanks.
	 * @param array[] $open  Open setups by tank id.
	 * @return array[]
	 */
	public static function attach( array $tanks, array $open ) {
		foreach ( $tanks as $index => $tank ) {
			$id                                = (int) ( $tank['id'] ?? 0 );
			$tanks[ $index ]['setup']          = Layout::is_slot( $tank ) && isset( $open[ $id ] ) && is_array( $open[ $id ] ) ? $open[ $id ] : null;
			$tanks[ $index ]['effective_area'] = self::effective_area( $tanks[ $index ] );
		}
		return $tanks;
	}

	/**
	 * The slots, in slot order.
	 *
	 * @param array[] $tanks Hydrated tanks.
	 * @return array[]
	 */
	public static function slots( array $tanks ) {
		$out = array();
		foreach ( $tanks as $tank ) {
			if ( Layout::is_slot( $tank ) ) {
				$out[] = $tank;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $a['number'] <=> (int) $b['number'];
			}
		);
		return $out;
	}

	/**
	 * The slots a setup can go into: active, in a building that is not
	 * offline, with nothing set up — lowest number first.
	 *
	 * @param array[] $tanks Hydrated tanks (with 'setup').
	 * @return array[]
	 */
	public static function free_slots( array $tanks ) {
		$out = array();
		foreach ( self::slots( $tanks ) as $tank ) {
			if ( 'offline' === (string) ( $tank['status'] ?? 'active' ) || 'offline' === (string) ( $tank['building']['status'] ?? 'active' ) ) {
				continue;
			}
			if ( empty( $tank['setup'] ) ) {
				$out[] = $tank;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Titles                                                         */
	/* ============================================================== */

	/**
	 * The day of a setup's own clock: day 1 is its treatment start date
	 * (0 or less before it starts; null without a date).
	 *
	 * @param array  $setup Setup (start_date).
	 * @param string $today Site date.
	 * @return int|null
	 */
	public static function day( array $setup, $today ) {
		return Replay::day_number( (string) ( $setup['start_date'] ?? '' ), (string) $today );
	}

	/**
	 * 'day 3' / 'starts 2026-04-09' for a setup.
	 *
	 * @param array  $setup Setup.
	 * @param string $today Site date.
	 * @return string
	 */
	public static function day_text( array $setup, $today ) {
		$day = self::day( $setup, $today );
		if ( null === $day ) {
			return '';
		}
		if ( $day < 1 ) {
			/* translators: %s: date */
			return sprintf( __( 'starts %s', 'fishotel-misc-plugin' ), (string) $setup['start_date'] );
		}
		/* translators: %d: day number */
		return sprintf( __( 'day %d', 'fishotel-misc-plugin' ), $day );
	}

	/**
	 * What a setup's treatment is called: the template its steps were
	 * copied from, "its own schedule" once nothing names it, or "no
	 * treatment" when all it has is the feeding.
	 *
	 * @param array $setup Setup (template, steps).
	 * @return string
	 */
	public static function schedule_label( array $setup ) {
		$name = trim( (string) ( $setup['template'] ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		foreach ( (array) ( $setup['steps'] ?? array() ) as $step ) {
			if ( 'feed' !== (string) ( $step['kind'] ?? '' ) ) {
				return __( 'its own schedule', 'fishotel-misc-plugin' );
			}
		}
		return __( 'no treatment', 'fishotel-misc-plugin' );
	}

	/**
	 * The phone title of a slot:
	 * 'TEMP 1 · 901 · Hospital A · Temporary · Quarantine · bacterial infection · its own water · 10 gal · schedule Trifecta · day 3',
	 * or 'TEMP 3 · 903 · Temporary · nothing set up here'.
	 *
	 * @param array  $tank  Hydrated slot (setup, building).
	 * @param string $today Site date.
	 * @return string
	 */
	public static function title( array $tank, $today ) {
		$bits  = array( Layout::tank_label( $tank ) );
		$setup = isset( $tank['setup'] ) && is_array( $tank['setup'] ) ? $tank['setup'] : null;
		if ( ! $setup ) {
			$bits[] = Layout::building_label( (array) ( $tank['building'] ?? array() ) );
			$bits[] = __( 'nothing set up here', 'fishotel-misc-plugin' );
			return implode( ' · ', $bits );
		}
		if ( '' !== trim( (string) $setup['name'] ) ) {
			$bits[] = trim( (string) $setup['name'] );
		}
		$bits[] = Layout::building_label( (array) ( $tank['building'] ?? array() ) );
		$bits[] = self::kind_label( $setup['kind'] );
		if ( '' !== trim( (string) $setup['purpose'] ) ) {
			$bits[] = trim( (string) $setup['purpose'] );
		}
		$bits[] = __( 'its own water', 'fishotel-misc-plugin' );
		if ( (int) $setup['gallons'] > 0 ) {
			$bits[] = (int) $setup['gallons'] . ' gal';
		}
		$bits[] = __( 'schedule', 'fishotel-misc-plugin' ) . ' ' . self::schedule_label( $setup );
		$day    = self::day_text( $setup, $today );
		if ( '' !== $day ) {
			$bits[] = $day;
		}
		return implode( ' · ', $bits );
	}

	/* ============================================================== */
	/*  "Used for": what a treatment template is for                   */
	/* ============================================================== */

	/**
	 * One clean tag: no markup, single spaces, at most MAX_TAG characters.
	 *
	 * @param mixed $tag Raw tag.
	 * @return string
	 */
	public static function clean_tag( $tag ) {
		if ( ! is_scalar( $tag ) || is_bool( $tag ) ) {
			return '';
		}
		$tag = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $tag ) ) );
		return (string) substr( $tag, 0, self::MAX_TAG );
	}

	/**
	 * A list of tags from what was typed: an array, or one string with
	 * commas, semicolons or line breaks between the tags. Empty ones are
	 * dropped, repeats (whatever their case) too, at most MAX_TAGS.
	 *
	 * @param mixed $raw Raw tags.
	 * @return string[]
	 */
	public static function clean_tags( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,;\r\n]+/', $raw );
		}
		$out  = array();
		$seen = array();
		foreach ( is_array( $raw ) ? $raw : array() as $tag ) {
			$tag = self::clean_tag( $tag );
			$key = strtolower( $tag );
			if ( '' === $tag || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tag;
			if ( count( $out ) >= self::MAX_TAGS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The stored tags option: null while nothing was ever saved.
	 *
	 * @return array|null
	 */
	public static function stored_tags() {
		$stored = get_option( self::TAGS_OPTION, null );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Every existing template with its tags (pure): what is stored, or —
	 * while nothing was ever stored — the seed. A template that no longer
	 * exists has no tags; one without any gets an empty list.
	 *
	 * @param array      $protocols Settings::protocols() — { default, templates }.
	 * @param array|null $stored    The stored option (null = never saved: the seed).
	 * @return array<string, string[]>
	 */
	public static function template_tags( array $protocols, $stored ) {
		$source = is_array( $stored ) ? $stored : self::SEED_TAGS;
		$out    = array();
		foreach ( array_keys( (array) ( $protocols['templates'] ?? array() ) ) as $name ) {
			$name         = (string) $name;
			$out[ $name ] = isset( $source[ $name ] ) ? self::clean_tags( $source[ $name ] ) : array();
		}
		return $out;
	}

	/**
	 * The tags of the templates as they stand (reads the two options).
	 *
	 * @param array|null $protocols Settings::protocols(), or null to read them.
	 * @return array<string, string[]>
	 */
	public static function tags( $protocols = null ) {
		return self::template_tags( is_array( $protocols ) ? $protocols : Settings::protocols(), self::stored_tags() );
	}

	/**
	 * Every distinct tag some template carries, A–Z (the first spelling
	 * of each stays).
	 *
	 * @param array<string, string[]> $map template_tags().
	 * @return string[]
	 */
	public static function all_tags( array $map ) {
		$out = array();
		foreach ( $map as $tags ) {
			foreach ( (array) $tags as $tag ) {
				$key = strtolower( (string) $tag );
				if ( '' !== $key && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = (string) $tag;
				}
			}
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

	/**
	 * The templates that carry a tag (whatever its case), in the
	 * templates' own order. A tag no template carries offers nothing.
	 *
	 * @param string                  $tag Tag (or free text).
	 * @param array<string, string[]> $map template_tags().
	 * @return string[] Template names.
	 */
	public static function templates_for( $tag, array $map ) {
		$tag = strtolower( self::clean_tag( $tag ) );
		$out = array();
		if ( '' === $tag ) {
			return $out;
		}
		foreach ( $map as $name => $tags ) {
			foreach ( (array) $tags as $candidate ) {
				if ( strtolower( (string) $candidate ) === $tag ) {
					$out[] = (string) $name;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Save one template's tags. The whole map is written (the seed
	 * included, the first time), so a tag that was removed stays removed.
	 *
	 * @param string $template  Template name.
	 * @param mixed  $raw       Tags as typed.
	 * @param array  $protocols Settings::protocols().
	 * @return array<string, string[]> The map as stored.
	 */
	public static function save_tags( $template, $raw, array $protocols ) {
		$map      = self::template_tags( $protocols, self::stored_tags() );
		$template = (string) $template;
		if ( isset( $protocols['templates'][ $template ] ) ) {
			$map[ $template ] = self::clean_tags( $raw );
		}
		update_option( self::TAGS_OPTION, $map, false );
		return $map;
	}

	/**
	 * A template was renamed (or deleted: '' as the new name): its tags
	 * follow. Nothing is written while nothing was ever stored and the
	 * template is not a seeded one.
	 *
	 * @param string $old Old template name.
	 * @param string $new New name ('' = deleted).
	 * @return bool Whether the option was written.
	 */
	public static function rename_tags( $old, $new ) {
		$old    = (string) $old;
		$new    = (string) $new;
		$stored = self::stored_tags();
		$map    = is_array( $stored ) ? $stored : self::SEED_TAGS;
		if ( '' === $old || $old === $new || ! isset( $map[ $old ] ) ) {
			return false;
		}
		$tags = self::clean_tags( $map[ $old ] );
		unset( $map[ $old ] );
		if ( '' !== $new ) {
			$map[ $new ] = $tags;
		}
		update_option( self::TAGS_OPTION, $map, false );
		return true;
	}

	/* ============================================================== */
	/*  The copied schedule                                            */
	/* ============================================================== */

	/**
	 * The steps a new setup starts with: a COPY of the template's steps as
	 * they are now — or, for "no treatment, just watching", the daily
	 * feeding alone. From here on the two are separate lists.
	 *
	 * @param string $template  Template name ('' = no treatment).
	 * @param array  $protocols Settings::protocols().
	 * @return array[]
	 */
	public static function copy_steps( $template, array $protocols ) {
		$template = (string) $template;
		if ( '' === $template || ! isset( $protocols['templates'][ $template ] ) ) {
			return array( Protocol::feed_step() );
		}
		return Protocol::normalize( $protocols['templates'][ $template ] );
	}

	/**
	 * 'day 1', 'days 2, 4, 6, 8, 10, 12, 14', 'days 2–60, every 2 days',
	 * 'daily from day 1' — when a step falls due.
	 *
	 * @param array $step Step.
	 * @return string
	 */
	public static function when_text( array $step ) {
		$day   = max( 1, (int) ( $step['day'] ?? 1 ) );
		$every = (int) ( $step['every'] ?? 0 );
		$until = isset( $step['until'] ) && null !== $step['until'] ? (int) $step['until'] : null;

		if ( $every <= 0 ) {
			return 'day ' . $day;
		}
		if ( null === $until ) {
			return 1 === $every ? 'daily from day ' . $day : 'from day ' . $day . ', every ' . $every . ' days';
		}
		if ( $until <= $day ) {
			return 'day ' . $day;
		}
		if ( 1 === $every ) {
			return 'days ' . $day . '–' . $until . ', daily';
		}
		$days = array();
		for ( $d = $day; $d <= $until; $d += $every ) {
			$days[] = $d;
		}
		if ( count( $days ) <= 12 ) {
			return 'days ' . implode( ', ', $days );
		}
		return 'days ' . $day . '–' . $until . ', every ' . $every . ' days';
	}

	/**
	 * What a schedule does, before anything is saved: every step that is
	 * not the feeding, with the medication it names and the days it falls
	 * on, first day first.
	 *
	 * @param array[] $steps Steps.
	 * @return array[] label, kind, when, day, medication ('' when it names none), note.
	 */
	public static function preview( array $steps ) {
		$out = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || 'feed' === (string) ( $step['kind'] ?? '' ) ) {
				continue;
			}
			$medication = 'dose' === (string) ( $step['kind'] ?? '' ) ? Todo::medication_name( (string) ( $step['label'] ?? '' ) ) : '';
			$out[]      = array(
				'label'      => (string) ( $step['label'] ?? '' ),
				'kind'       => (string) ( $step['kind'] ?? 'other' ),
				'when'       => self::when_text( $step ),
				'day'        => max( 1, (int) ( $step['day'] ?? 1 ) ),
				'medication' => $medication,
				'note'       => (string) ( $step['note'] ?? '' ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['day'] <=> $b['day'];
			}
		);
		return $out;
	}

	/**
	 * One change to a setup's own steps (pure) — the template is never
	 * touched: `save` replaces the list (the Board's editor), `add`
	 * appends a step, `remove` drops one, `move` shifts one by so many
	 * days (its last day with it).
	 *
	 * @param array[] $steps The setup's steps.
	 * @param string  $op    save|add|remove|move.
	 * @param array   $raw   steps[] (save); label, kind, day, every, until, note (add); index (remove / move); days (move).
	 * @return array{steps: array[], message: string}|array{error: string}
	 */
	public static function apply_step_op( array $steps, $op, array $raw ) {
		$steps = Protocol::normalize( $steps );
		$index = isset( $raw['index'] ) && is_scalar( $raw['index'] ) && is_numeric( $raw['index'] ) ? (int) $raw['index'] : -1;

		switch ( (string) $op ) {
			case 'save':
				$steps = Protocol::normalize( isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array() );
				/* translators: %d: number of steps */
				$message = sprintf( __( 'Schedule saved: %d steps, for this tank only.', 'fishotel-misc-plugin' ), count( $steps ) );
				break;

			case 'add':
				$added = Protocol::normalize( array( $raw ) );
				if ( ! $added ) {
					return array( 'error' => __( 'Name the step.', 'fishotel-misc-plugin' ) );
				}
				if ( count( $steps ) >= Protocol::MAX_STEPS ) {
					/* translators: %d: the cap */
					return array( 'error' => sprintf( __( 'At most %d steps.', 'fishotel-misc-plugin' ), Protocol::MAX_STEPS ) );
				}
				$added[0]['where'] = 'any'; // A temporary tank is its own place.
				$steps[]           = $added[0];
				/* translators: 1: step, 2: when */
				$message = sprintf( __( 'Added: %1$s (%2$s), for this tank only.', 'fishotel-misc-plugin' ), $added[0]['label'], self::when_text( $added[0] ) );
				break;

			case 'remove':
				if ( ! isset( $steps[ $index ] ) ) {
					return array( 'error' => __( 'That step is no longer in the schedule — reload.', 'fishotel-misc-plugin' ) );
				}
				$label = (string) $steps[ $index ]['label'];
				array_splice( $steps, $index, 1 );
				/* translators: %s: step */
				$message = sprintf( __( 'Removed: %s, for this tank only.', 'fishotel-misc-plugin' ), $label );
				break;

			case 'move':
				if ( ! isset( $steps[ $index ] ) ) {
					return array( 'error' => __( 'That step is no longer in the schedule — reload.', 'fishotel-misc-plugin' ) );
				}
				$days = isset( $raw['days'] ) && is_scalar( $raw['days'] ) && is_numeric( $raw['days'] ) ? (int) $raw['days'] : 0;
				if ( 0 === $days ) {
					return array( 'error' => __( 'Move it by how many days?', 'fishotel-misc-plugin' ) );
				}
				$step = $steps[ $index ];
				$day  = (int) $step['day'] + $days;
				if ( $day < 1 || $day > Protocol::MAX_DAY ) {
					/* translators: %d: last day */
					return array( 'error' => sprintf( __( 'A step falls on day 1 to %d.', 'fishotel-misc-plugin' ), Protocol::MAX_DAY ) );
				}
				$step['day'] = $day;
				if ( null !== $step['until'] ) {
					$step['until'] = max( $day, min( Protocol::MAX_DAY, (int) $step['until'] + $days ) );
				}
				$steps[ $index ] = $step;
				/* translators: 1: step, 2: when */
				$message = sprintf( __( '%1$s is now %2$s, for this tank only.', 'fishotel-misc-plugin' ), $step['label'], self::when_text( $step ) );
				break;

			default:
				return array( 'error' => __( 'Unknown schedule action.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'steps'   => array_values( $steps ),
			'message' => $message,
		);
	}

	/* ============================================================== */
	/*  Setting one up                                                 */
	/* ============================================================== */

	/**
	 * Whether a treatment start date is a real date within START_BACK days
	 * back and START_AHEAD days ahead of today.
	 *
	 * @param mixed  $ymd   Date typed.
	 * @param string $today Site date.
	 * @return bool
	 */
	public static function start_in_range( $ymd, $today ) {
		if ( ! Replay::is_date( $ymd ) || ! Replay::is_date( $today ) ) {
			return false;
		}
		return strcmp( (string) $ymd, Replay::add_days( $today, -self::START_BACK ) ) >= 0 && strcmp( (string) $ymd, Replay::add_days( $today, self::START_AHEAD ) ) <= 0;
	}

	/**
	 * The text fields of a setup, as stored: name, purpose, note.
	 *
	 * @param array $raw Unslashed payload.
	 * @return array{name: string, purpose: string, note: string}
	 */
	private static function text_fields( array $raw ) {
		$line = static function ( $value, $max ) {
			$value = is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ) : '';
			return (string) substr( $value, 0, $max );
		};
		$note = isset( $raw['note'] ) && is_scalar( $raw['note'] ) && ! is_bool( $raw['note'] ) ? trim( strip_tags( (string) $raw['note'] ) ) : '';

		return array(
			'name'    => $line( $raw['name'] ?? '', self::MAX_NAME ),
			'purpose' => $line( $raw['purpose'] ?? '', self::MAX_PURPOSE ),
			'note'    => (string) substr( $note, 0, self::MAX_NOTE ),
		);
	}

	/**
	 * The gallons typed: a whole number 0–9999 (blank = 0, not known), or
	 * null when it is not that.
	 *
	 * @param mixed $value Raw field.
	 * @return int|null
	 */
	public static function gallons_in( $value ) {
		$text = is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
		if ( '' === $text ) {
			return 0;
		}
		if ( ! is_numeric( $text ) ) {
			return null;
		}
		$gallons = (int) round( (float) $text );
		return ( $gallons < 0 || $gallons > Layout::MAX_GALLONS ) ? null : $gallons;
	}

	/**
	 * Check a setup before it is written (pure). Nothing is guessed that
	 * matters: the kind must be picked, the slot must be free (the first
	 * free one when none is named), the treatment must exist, the start
	 * date must be a sensible date.
	 *
	 * @param array $raw Unslashed payload: slot (the slot's tank number, 0 = the first free one), name,
	 *                   purpose, kind, gallons, template ('' = no treatment), start_date, note.
	 * @param array $ctx tanks (hydrated, with 'setup'), protocols, today.
	 * @return array{errors: string[], setup: array|null}
	 */
	public static function validate( array $raw, array $ctx ) {
		$tanks     = isset( $ctx['tanks'] ) && is_array( $ctx['tanks'] ) ? $ctx['tanks'] : array();
		$protocols = isset( $ctx['protocols'] ) && is_array( $ctx['protocols'] ) ? $ctx['protocols'] : array( 'templates' => array() );
		$today     = (string) ( $ctx['today'] ?? '' );
		$errors    = array();

		// The slot.
		$slots  = self::slots( $tanks );
		$free   = self::free_slots( $tanks );
		$number = isset( $raw['slot'] ) && is_scalar( $raw['slot'] ) && is_numeric( $raw['slot'] ) ? (int) $raw['slot'] : 0;
		$tank   = null;
		if ( ! $slots ) {
			$errors[] = __( 'There are no temporary slots in the layout (Settings → Layout).', 'fishotel-misc-plugin' );
		} elseif ( $number > 0 ) {
			foreach ( $slots as $candidate ) {
				if ( (int) $candidate['number'] === $number ) {
					$tank = $candidate;
					break;
				}
			}
			if ( ! $tank || 'offline' === (string) $tank['status'] || 'offline' === (string) ( $tank['building']['status'] ?? 'active' ) ) {
				$errors[] = __( 'That is not a temporary slot in use. Pick a free one.', 'fishotel-misc-plugin' );
				$tank     = null;
			} elseif ( ! empty( $tank['setup'] ) ) {
				$name = trim( (string) ( $tank['setup']['name'] ?? '' ) );
				/* translators: 1: slot label, 2: what is set up there */
				$errors[] = sprintf( __( '%1$s is already set up%2$s. Take it down first, or pick a free slot.', 'fishotel-misc-plugin' ), Layout::tank_label( $tank ), '' !== $name ? ' (' . $name . ')' : '' );
				$tank     = null;
			}
		} elseif ( $free ) {
			$tank = $free[0];
		} else {
			$errors[] = __( 'Every temporary slot is in use. Take one down, or add a slot in Settings → Layout.', 'fishotel-misc-plugin' );
		}

		// The kind: asked, never assumed.
		$kind = isset( $raw['kind'] ) && is_scalar( $raw['kind'] ) ? (string) $raw['kind'] : '';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$errors[] = __( 'Is it a clean tank or a quarantine tank? Pick one.', 'fishotel-misc-plugin' );
		}

		$gallons = self::gallons_in( $raw['gallons'] ?? '' );
		if ( null === $gallons ) {
			$errors[] = __( 'Gallons must be a whole number from 0 to 9999 (blank when not known).', 'fishotel-misc-plugin' );
		}

		$template = Protocol::template_name( $raw['template'] ?? '' );
		if ( '' !== $template && ! isset( $protocols['templates'][ $template ] ) ) {
			/* translators: %s: template name */
			$errors[] = sprintf( __( 'The treatment schedule "%s" does not exist. Pick another, or no treatment.', 'fishotel-misc-plugin' ), $template );
		}

		$start = isset( $raw['start_date'] ) && is_scalar( $raw['start_date'] ) ? trim( (string) $raw['start_date'] ) : '';
		if ( '' === $start ) {
			$start = $today;
		}
		if ( ! Replay::is_date( $start ) ) {
			$errors[] = __( 'The treatment start date is not a date.', 'fishotel-misc-plugin' );
		} elseif ( ! self::start_in_range( $start, $today ) ) {
			/* translators: 1: earliest date, 2: latest date */
			$errors[] = sprintf( __( 'Pick a start date from %1$s to %2$s.', 'fishotel-misc-plugin' ), Replay::add_days( $today, -self::START_BACK ), Replay::add_days( $today, self::START_AHEAD ) );
		}

		if ( $errors ) {
			return array(
				'errors' => $errors,
				'setup'  => null,
			);
		}

		return array(
			'errors' => array(),
			'setup'  => array_merge(
				self::text_fields( $raw ),
				array(
					'tank_id'     => (int) $tank['id'],
					'tank_number' => (int) $tank['number'],
					'slot'        => Layout::slot_of( $tank ),
					'kind'        => $kind,
					'gallons'     => (int) $gallons,
					'template'    => $template,
					'start_date'  => $start,
					'steps'       => self::copy_steps( $template, $protocols ),
				)
			),
		);
	}

	/**
	 * Set a temporary tank up: validate, copy the treatment's steps into
	 * it, write the setup.
	 *
	 * @param object $store Store (or stand-in) with tanks(), insert_setup().
	 * @param array  $raw   Unslashed payload (validate()).
	 * @param array  $ctx   protocols, today, actor_id.
	 * @return array{ok: bool, errors: string[], setup_id: int, setup: array|null, message: string}
	 */
	public static function set_up( $store, array $raw, array $ctx ) {
		$out = array(
			'ok'       => false,
			'errors'   => array(),
			'setup_id' => 0,
			'setup'    => null,
			'message'  => '',
		);

		$checked = self::validate(
			$raw,
			array(
				'tanks'     => $store->tanks(),
				'protocols' => isset( $ctx['protocols'] ) && is_array( $ctx['protocols'] ) ? $ctx['protocols'] : Settings::protocols(),
				'today'     => (string) ( $ctx['today'] ?? '' ),
			)
		);
		if ( $checked['errors'] ) {
			$out['errors'] = $checked['errors'];
			return $out;
		}

		$setup = $checked['setup'];
		$id    = $store->insert_setup( array_merge( $setup, array( 'created_by' => (int) ( $ctx['actor_id'] ?? 0 ) ) ) );
		if ( ! $id ) {
			// The slot's one-open-setup key refused it: another phone was faster.
			$out['errors'][] = __( 'That slot was set up a moment ago by someone else, or the setup could not be saved. Reload and look.', 'fishotel-misc-plugin' );
			return $out;
		}

		$label = Layout::slot_name( $setup['slot'] ) . ' · ' . $setup['tank_number'];
		$what  = '' !== $setup['name'] ? $setup['name'] : $label;

		$out['ok']       = true;
		$out['setup_id'] = (int) $id;
		$out['setup']    = $setup;
		/* translators: 1: name, 2: slot label, 3: kind, 4: schedule */
		$out['message'] = sprintf( __( '%1$s is set up in %2$s — %3$s, %4$s', 'fishotel-misc-plugin' ), $what, $label, strtolower( self::kind_label( $setup['kind'] ) ), self::schedule_label( $setup ) );

		return $out;
	}

	/**
	 * Change what can change on a running setup: its name, what it is
	 * for, the gallons, the treatment start date (the clock) and the note.
	 * The kind and the slot are fixed — take it down and set it up again
	 * for those.
	 *
	 * @param object $store Store (or stand-in) with update_setup().
	 * @param array  $setup The open setup.
	 * @param array  $raw   name, purpose, gallons, start_date, note — only the keys posted change.
	 * @param array  $ctx   today.
	 * @return array{ok: bool, errors: string[], message: string}
	 */
	public static function edit( $store, array $setup, array $raw, array $ctx ) {
		$today  = (string) ( $ctx['today'] ?? '' );
		$fields = array();
		$errors = array();
		$text   = self::text_fields( $raw );

		foreach ( array( 'name', 'purpose', 'note' ) as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$fields[ $key ] = $text[ $key ];
			}
		}
		if ( array_key_exists( 'gallons', $raw ) ) {
			$gallons = self::gallons_in( $raw['gallons'] );
			if ( null === $gallons ) {
				$errors[] = __( 'Gallons must be a whole number from 0 to 9999 (blank when not known).', 'fishotel-misc-plugin' );
			} else {
				$fields['gallons'] = $gallons;
			}
		}
		if ( array_key_exists( 'start_date', $raw ) ) {
			$start = is_scalar( $raw['start_date'] ) ? trim( (string) $raw['start_date'] ) : '';
			if ( ! Replay::is_date( $start ) ) {
				$errors[] = __( 'The treatment start date is not a date.', 'fishotel-misc-plugin' );
			} elseif ( $start !== (string) $setup['start_date'] && ! self::start_in_range( $start, $today ) ) {
				/* translators: 1: earliest date, 2: latest date */
				$errors[] = sprintf( __( 'Pick a start date from %1$s to %2$s.', 'fishotel-misc-plugin' ), Replay::add_days( $today, -self::START_BACK ), Replay::add_days( $today, self::START_AHEAD ) );
			} else {
				$fields['start_date'] = $start;
			}
		}

		if ( $errors ) {
			return array(
				'ok'      => false,
				'errors'  => $errors,
				'message' => '',
			);
		}
		foreach ( $fields as $key => $value ) {
			if ( (string) $value === (string) ( $setup[ $key ] ?? '' ) ) {
				unset( $fields[ $key ] );
			}
		}
		if ( ! $fields ) {
			return array(
				'ok'      => true,
				'errors'  => array(),
				'message' => __( 'Nothing changed.', 'fishotel-misc-plugin' ),
			);
		}
		if ( ! $store->update_setup( (int) $setup['id'], $fields ) ) {
			return array(
				'ok'      => false,
				'errors'  => array( __( 'The setup could not be saved.', 'fishotel-misc-plugin' ) ),
				'message' => '',
			);
		}

		return array(
			'ok'      => true,
			'errors'  => array(),
			'message' => isset( $fields['start_date'] )
				/* translators: %s: date */
				? sprintf( __( 'Saved — the treatment now counts from %s.', 'fishotel-misc-plugin' ), $fields['start_date'] )
				: __( 'Setup saved.', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * Change a running setup's own steps (apply_step_op()) and store them.
	 *
	 * @param object $store Store (or stand-in) with update_setup().
	 * @param array  $setup The open setup.
	 * @param string $op    save|add|remove|move.
	 * @param array  $raw   See apply_step_op().
	 * @return array{ok: bool, errors: string[], message: string, steps: array[]}
	 */
	public static function change_steps( $store, array $setup, $op, array $raw ) {
		$result = self::apply_step_op( (array) ( $setup['steps'] ?? array() ), $op, $raw );
		if ( ! empty( $result['error'] ) ) {
			return array(
				'ok'      => false,
				'errors'  => array( (string) $result['error'] ),
				'message' => '',
				'steps'   => (array) ( $setup['steps'] ?? array() ),
			);
		}
		if ( ! $store->update_setup( (int) $setup['id'], array( 'steps' => $result['steps'] ) ) ) {
			return array(
				'ok'      => false,
				'errors'  => array( __( 'The schedule could not be saved.', 'fishotel-misc-plugin' ) ),
				'message' => '',
				'steps'   => (array) ( $setup['steps'] ?? array() ),
			);
		}
		return array(
			'ok'      => true,
			'errors'  => array(),
			'message' => (string) $result['message'],
			'steps'   => $result['steps'],
		);
	}

	/* ============================================================== */
	/*  Taking one down                                                */
	/* ============================================================== */

	/**
	 * Take a setup down: refused while a group is still in the tank;
	 * otherwise the setup is closed (kept as history) and the slot is free
	 * for the next one.
	 *
	 * @param object $store Store (or stand-in) with open_stays(), close_setup().
	 * @param array  $setup The open setup (tank_id).
	 * @param array  $ctx   today, settings, actor_id.
	 * @return array{ok: bool, errors: string[], message: string, fish: int}
	 */
	public static function take_down( $store, array $setup, array $ctx ) {
		$today    = (string) ( $ctx['today'] ?? '' );
		$settings = isset( $ctx['settings'] ) && is_array( $ctx['settings'] ) ? $ctx['settings'] : array();
		$label    = Layout::slot_name( (int) $setup['slot'] );

		if ( empty( $setup['open'] ) ) {
			return array(
				'ok'      => false,
				'errors'  => array( __( 'That setup was already taken down.', 'fishotel-misc-plugin' ) ),
				'message' => '',
				'fish'    => 0,
			);
		}

		$groups = $store->open_stays( array( 'tank_id' => (int) $setup['tank_id'] ), $today, $settings );
		if ( $groups ) {
			$fish = 0;
			foreach ( $groups as $stay ) {
				$fish += (int) $stay['qty_live'];
			}
			return array(
				'ok'      => false,
				'errors'  => array(
					sprintf(
						/* translators: 1: slot name, 2: number of fish, 3: number of groups */
						_n( '%1$s still has fish in it (%2$d in %3$d group). Move them out first, then take it down.', '%1$s still has fish in it (%2$d in %3$d groups). Move them out first, then take it down.', count( $groups ), 'fishotel-misc-plugin' ),
						$label,
						$fish,
						count( $groups )
					),
				),
				'message' => '',
				'fish'    => $fish,
			);
		}

		if ( ! $store->close_setup( (int) $setup['id'], (int) ( $ctx['actor_id'] ?? 0 ) ) ) {
			return array(
				'ok'      => false,
				'errors'  => array( __( 'The setup could not be taken down.', 'fishotel-misc-plugin' ) ),
				'message' => '',
				'fish'    => 0,
			);
		}

		$name = trim( (string) $setup['name'] );
		return array(
			'ok'      => true,
			'errors'  => array(),
			/* translators: 1: what was set up, 2: slot name */
			'message' => sprintf( __( '%1$s taken down — %2$s is free. Its history is kept.', 'fishotel-misc-plugin' ), '' !== $name ? $name : $label, $label ),
			'fish'    => 0,
		);
	}

	/* ============================================================== */
	/*  History                                                        */
	/* ============================================================== */

	/**
	 * What a setup's log holds (pure): the readings, the doses, the ticks
	 * — each with the day of the setup's own clock — and the tank notes.
	 * A dose with an amount is a dose; a 'dose' entry without one is the
	 * tick of a dose step.
	 *
	 * @param array                     $setup Setup (start_date).
	 * @param array[]                   $logs  Its log rows (kind, value, note, logged_at), any order.
	 * @param \DateTimeZone|string|null $tz    Site zone.
	 * @return array{readings: array[], doses: array[], ticks: array[], notes: array[]}
	 */
	public static function split_logs( array $setup, array $logs, $tz = null ) {
		$out = array(
			'readings' => array(),
			'doses'    => array(),
			'ticks'    => array(),
			'notes'    => array(),
		);

		usort(
			$logs,
			static function ( $a, $b ) {
				$t = strcmp( (string) ( $a['logged_at'] ?? '' ), (string) ( $b['logged_at'] ?? '' ) );
				return 0 !== $t ? $t : (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
			}
		);

		foreach ( $logs as $log ) {
			$kind  = (string) ( $log['kind'] ?? 'note' );
			$value = isset( $log['value'] ) && null !== $log['value'] && '' !== $log['value'] ? (float) $log['value'] : null;
			$date  = Replay::event_date( (string) ( $log['logged_at'] ?? '' ), $tz );
			$entry = array(
				'logged_at' => (string) ( $log['logged_at'] ?? '' ),
				'date'      => $date,
				'day'       => Replay::day_number( (string) ( $setup['start_date'] ?? '' ), $date ),
				'value'     => $value,
				'text'      => (string) ( $log['note'] ?? '' ),
				'actor'     => (string) ( $log['actor_name'] ?? '' ),
			);

			if ( 'copper' === $kind ) {
				$out['readings'][] = $entry;
			} elseif ( 'note' === $kind ) {
				$out['notes'][] = $entry;
			} elseif ( 'dose' === $kind && null !== $value ) {
				$out['doses'][] = $entry;
			} else {
				$entry['text']  = Todo::key_label( $entry['text'] );
				$entry['kind']  = $kind;
				$out['ticks'][] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The treatment as it was run (pure): every step of the setup with
	 * the days it was ticked on.
	 *
	 * @param array   $setup Setup (steps).
	 * @param array[] $ticks split_logs()['ticks'].
	 * @param array[] $doses split_logs()['doses'] — a logged dose ticks the step naming its medication.
	 * @param array[] $reads split_logs()['readings'] — a reading ticks the copper test.
	 * @return array[] label, kind, when, done (day numbers, ascending).
	 */
	public static function run_of( array $setup, array $ticks, array $doses = array(), array $reads = array() ) {
		$out = array();
		foreach ( (array) ( $setup['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$label = (string) ( $step['label'] ?? '' );
			$key   = Protocol::tick_key( $label );
			$days  = array();
			foreach ( $ticks as $tick ) {
				if ( Protocol::tick_key( (string) $tick['text'] ) === $key && null !== $tick['day'] ) {
					$days[] = (int) $tick['day'];
				}
			}
			if ( 'dose' === (string) ( $step['kind'] ?? '' ) ) {
				$medication = Todo::medication_key( $label );
				foreach ( $doses as $dose ) {
					if ( null !== $medication && null !== $dose['day'] && Todo::medication_key( (string) $dose['text'] ) === $medication && Protocol::is_due( $step, (int) $dose['day'] ) ) {
						$days[] = (int) $dose['day'];
					}
				}
			}
			if ( Protocol::is_copper_test( $step ) ) {
				foreach ( $reads as $read ) {
					if ( null !== $read['day'] && Protocol::is_due( $step, (int) $read['day'] ) ) {
						$days[] = (int) $read['day'];
					}
				}
			}
			$days = array_values( array_unique( $days ) );
			sort( $days );
			$out[] = array(
				'label' => $label,
				'kind'  => (string) ( $step['kind'] ?? 'other' ),
				'when'  => 'feed' === (string) ( $step['kind'] ?? '' ) ? 'daily' : self::when_text( $step ),
				'done'  => $days,
			);
		}
		return $out;
	}

	/**
	 * The groups that passed through a setup (pure), from the stay events
	 * that name it: when each came in, how many, and when and where it
	 * went — or that it is still there, or that it ended there.
	 *
	 * @param int                       $setup_id Setup id.
	 * @param array[]                   $events   Events naming the setup (stay_id, type, qty, from_setup_id, to_setup_id,
	 *                                            to_tank_number, event_at, species), any order.
	 * @param \DateTimeZone|string|null $tz       Site zone.
	 * @return array[] stay_id, species, in, qty, out ('' while it never left), to (tank number or null).
	 */
	public static function groups_of( $setup_id, array $events, $tz = null ) {
		$setup_id = (int) $setup_id;
		$out      = array();
		foreach ( Replay::sort_events( $events ) as $event ) {
			$stay = (int) ( $event['stay_id'] ?? 0 );
			if ( $stay <= 0 || Replay::is_reversal( $event ) ) {
				continue;
			}
			$date = Replay::event_date( (string) ( $event['event_at'] ?? '' ), $tz );
			$qty  = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null;
			$type = (string) ( $event['type'] ?? '' );
			// A group came in by a check-in or by a whole move. A split leaves
			// the group where it was (only its sister moves), and a sister's
			// backdated "arrival" is bookkeeping, not a check-in.
			$came = ( 'arrival' === $type && null !== $qty && $qty > 0 && 0 !== strpos( (string) ( $event['note'] ?? '' ), 'split from #' ) ) || ( 'move' === $type && Replay::is_whole_move( $event ) );
			if ( (int) ( $event['to_setup_id'] ?? 0 ) === $setup_id && $came ) {
				if ( ! isset( $out[ $stay ] ) ) {
					$out[ $stay ] = array(
						'stay_id' => $stay,
						'species' => (string) ( $event['species'] ?? '' ),
						'in'      => $date,
						'qty'     => 'arrival' === $type ? $qty : null,
						'out'     => '',
						'to'      => null,
					);
				}
			} elseif ( (int) ( $event['from_setup_id'] ?? 0 ) === $setup_id && 'move' === $type && isset( $out[ $stay ] ) && Replay::is_whole_move( $event ) ) {
				$out[ $stay ]['out'] = $date;
				$out[ $stay ]['to']  = ! empty( $event['to_tank_number'] ) ? (int) $event['to_tank_number'] : null;
			}
		}
		return array_values( $out );
	}

	/**
	 * A setup's whole history: the setup, the treatment as it was run
	 * with what was ticked, the doses and readings logged, the tank
	 * notes, and the groups that passed through.
	 *
	 * @param object $store Store (or stand-in) with setup_logs(), setup_events().
	 * @param array  $setup The setup (open or closed).
	 * @return array{setup: array, run: array[], readings: array[], doses: array[], ticks: array[], notes: array[], groups: array[]}
	 */
	public static function history( $store, array $setup ) {
		$tz    = Clean_Room::timezone();
		$split = self::split_logs( $setup, (array) $store->setup_logs( (int) $setup['id'] ), $tz );

		return array(
			'setup'    => $setup,
			'run'      => self::run_of( $setup, $split['ticks'], $split['doses'], $split['readings'] ),
			'readings' => $split['readings'],
			'doses'    => $split['doses'],
			'ticks'    => $split['ticks'],
			'notes'    => $split['notes'],
			'groups'   => self::groups_of( (int) $setup['id'], (array) $store->setup_events( (int) $setup['id'] ), $tz ),
		);
	}

	/* ============================================================== */
	/*  The actions (the phone and the Board post the same ones)       */
	/* ============================================================== */

	/**
	 * The acts this class answers — none of them needs a tank on the page.
	 *
	 * @var string[]
	 */
	const ACTS = array( 'setup', 'setup_edit', 'setup_steps', 'takedown', 'todo_tick' );

	/**
	 * The open setup an action is about: the one named by `setup_id`, else
	 * the one in the page's tank.
	 *
	 * @param array $ctx store, tank.
	 * @param array $raw setup_id.
	 * @return array|null
	 */
	private static function target( array $ctx, array $raw ) {
		$id = isset( $raw['setup_id'] ) && is_scalar( $raw['setup_id'] ) && is_numeric( $raw['setup_id'] ) ? (int) $raw['setup_id'] : 0;
		if ( $id > 0 ) {
			$setup = $ctx['store']->setup( $id );
			return $setup && ! empty( $setup['open'] ) ? $setup : null;
		}
		$tank = isset( $ctx['tank'] ) && is_array( $ctx['tank'] ) ? $ctx['tank'] : null;
		if ( ! $tank || ! Layout::is_slot( $tank ) ) {
			return null;
		}
		$open = $ctx['store']->open_setups();
		return isset( $open[ (int) $tank['id'] ] ) ? $open[ (int) $tank['id'] ] : null;
	}

	/**
	 * Run one of ACTS: the switch behind the phone's and the Board's
	 * temporary-tank and to-do writes (the caller has checked the
	 * capability and the nonce). Null for an act that is not one of them.
	 *
	 * @param string $act setup|setup_edit|setup_steps|takedown|todo_tick.
	 * @param array  $ctx store, settings, today, tank (row or null), base (actor_id, source).
	 * @param array  $raw The unslashed payload.
	 * @return array|null message, event_ids, tank (optional) — or error.
	 */
	public static function perform( $act, array $ctx, array $raw ) {
		$act = (string) $act;
		if ( ! in_array( $act, self::ACTS, true ) ) {
			return null;
		}

		$store = $ctx['store'];
		$base  = isset( $ctx['base'] ) && is_array( $ctx['base'] ) ? $ctx['base'] : array();
		$call  = array(
			'today'    => (string) ( $ctx['today'] ?? '' ),
			'settings' => isset( $ctx['settings'] ) && is_array( $ctx['settings'] ) ? $ctx['settings'] : array(),
			'actor_id' => (int) ( $base['actor_id'] ?? 0 ),
		);

		if ( 'todo_tick' === $act ) {
			return Todo::tick( $store, $raw, $base );
		}

		if ( 'setup' === $act ) {
			if ( ( ! isset( $raw['slot'] ) || '' === (string) $raw['slot'] ) && isset( $ctx['tank'] ) && is_array( $ctx['tank'] ) && Layout::is_slot( $ctx['tank'] ) ) {
				$raw['slot'] = (int) $ctx['tank']['number']; // Scanned from a free slot's label: that slot.
			}
			$done = self::set_up( $store, $raw, $call );
			if ( ! $done['ok'] ) {
				return array( 'error' => implode( ' ', $done['errors'] ) );
			}
			return array(
				'message'   => $done['message'],
				'event_ids' => array(),
				'tank'      => (int) $done['setup']['tank_number'],
			);
		}

		$setup = self::target( $ctx, $raw );
		if ( ! $setup ) {
			return array( 'error' => __( 'Nothing is set up there (any more). Reload and look.', 'fishotel-misc-plugin' ) );
		}

		if ( 'setup_edit' === $act ) {
			$done = self::edit( $store, $setup, $raw, $call );
		} elseif ( 'setup_steps' === $act ) {
			$op   = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';
			$done = self::change_steps( $store, $setup, $op, $raw );
		} else {
			$done = self::take_down( $store, $setup, $call );
		}
		if ( ! $done['ok'] ) {
			return array( 'error' => implode( ' ', $done['errors'] ) );
		}

		return array(
			'message'   => $done['message'],
			'event_ids' => array(),
			'tank'      => (int) $setup['tank_number'],
		);
	}

	/**
	 * A dose on a tank that is its own water is that tank's dose, whatever
	 * the sheet posted: "whole system" means nothing where no water is
	 * shared (pure).
	 *
	 * @param array|null $tank The page's tank.
	 * @param array      $raw  The dose payload.
	 * @return array The payload, `whole` cleared for a slot.
	 */
	public static function own_water_dose( $tank, array $raw ) {
		if ( is_array( $tank ) && Layout::is_slot( $tank ) ) {
			$raw['whole'] = '0';
		}
		return $raw;
	}
}
