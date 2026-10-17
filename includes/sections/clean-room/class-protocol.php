<?php
/**
 * Clean Room protocol — PURE: the treatment schedules as editable, named
 * templates (steps with a day offset, a label, a kind, an optional repeat
 * and an optional "where"), and the daily to-do one of them generates for
 * a tank from the stays in it.
 *
 * Nothing about a schedule is hard-coded: the seeds below are the five
 * schedules of Jeff's workbook (Treatment Schedule tab) — "14 Day CM" (the
 * default, the column every 2026 batch since August runs: copper to 2.0
 * on arrival and 2.4 the next day, then held at 2.25 or higher with a
 * daily test; Metronidazole every other day, six times; Praziquantel
 * twice; a bath and the move to the Clean Room on the sixteenth day),
 * "14 Day CF" and "Xtra 14 Day CF" (copper + formalin), "CQ" (copper +
 * chloroquine) and "Trifecta" (the antibiotics for sick fish). Each Spa
 * building runs one of them (fh_buildings.schedule, else the default);
 * they are edited in Settings → Protocol.
 *
 * The option (OPTION_KEY) holds { default: name, templates: { name:
 * steps[] } }; an older option that was a bare steps array is read as the
 * "14 Day CM" template (normalize_option()).
 *
 * Day numbers follow Replay::day_number(): day 1 is the check-in day.
 * The workbook counts the arrival day as day 0, so site day = sheet day + 1.
 * A step is due on day d when d == day, or when it repeats (every > 0)
 * and d >= day, (d − day) % every == 0 and d <= until (when set).
 *
 * Doses: MEDICATIONS names the medications the Front Desk's Dose sheet
 * offers (with the aliases a step label or a log note may use) and
 * DOSE_RATES the amount per gallon a suggestion is computed from. A dose
 * logged today whose medication matches a due dose step's label ticks
 * that step, as a copper reading ticks the copper test.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Protocol
 */
class Protocol {

	/**
	 * Option holding the template (Settings reads / writes it).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_cleanroom_protocol';

	const KINDS  = array( 'feed', 'dose', 'test', 'water_change', 'other' );
	const WHERES = array( 'any', 'spa', 'clean' );

	const MAX_STEPS     = 60;
	const MAX_DAY       = 120;
	const MAX_TEMPLATES = 20;
	const MAX_NAME      = 40;

	/**
	 * The template a building without one runs, and the first seed.
	 *
	 * @var string
	 */
	const DEFAULT_TEMPLATE = '14 Day CM';

	/**
	 * The seeded template names, in the order Settings lists them.
	 *
	 * @var string[]
	 */
	const TEMPLATE_NAMES = array( '14 Day CM', '14 Day CF', 'Xtra 14 Day CF', 'CQ', 'Trifecta' );

	/**
	 * The medications the Dose sheet offers: key => label, short label, the
	 * unit the amount is usually in, and the aliases a step label or a log
	 * note may use (matched as whole words, case-insensitively).
	 *
	 * @var array<string, array{label: string, short: string, unit: string, aliases: string[]}>
	 */
	const MEDICATIONS = array(
		'copper'       => array(
			'label'   => 'Copper Power',
			'short'   => 'Copper',
			'unit'    => 'ml',
			'aliases' => array( 'copper power', 'copper', 'cu' ),
		),
		'metro'        => array(
			'label'   => 'Metronidazole',
			'short'   => 'Metro',
			'unit'    => 'g',
			'aliases' => array( 'metronidazole', 'metro' ),
		),
		'prazi'        => array(
			'label'   => 'Praziquantel',
			'short'   => 'Prazi',
			'unit'    => 'g',
			'aliases' => array( 'praziquantel', 'prazi' ),
		),
		'formalin'     => array(
			'label'   => 'Formalin',
			'short'   => 'Formalin',
			'unit'    => 'ml',
			'aliases' => array( 'formalin' ),
		),
		'chloroquine'  => array(
			'label'   => 'Chloroquine',
			'short'   => 'Chloroquine',
			'unit'    => 'g',
			'aliases' => array( 'chloroquine', 'cp', 'cq' ),
		),
		'fenbendazole' => array(
			'label'   => 'Fenbendazole',
			'short'   => 'Fenben',
			'unit'    => 'g',
			'aliases' => array( 'fenbendazole', 'fenben' ),
		),
		'salt'         => array(
			'label'   => 'Salt',
			'short'   => 'Salt',
			'unit'    => 'tsp',
			'aliases' => array( 'salt' ),
		),
	);

	/**
	 * Amount per gallon a dose suggestion multiplies the system's gallons
	 * by (grams or millilitres per gallon, see MEDICATIONS for the unit):
	 * Metronidazole 25 mg/gal, Praziquantel 9.5 mg/gal, Formalin 1 ml per
	 * 10 gal, Chloroquine 10 mg/gal, Fenbendazole 1 g per 100 gal. Copper
	 * has no rate: it is dosed to a test reading.
	 *
	 * @var array<string, float>
	 */
	const DOSE_RATES = array(
		'metro'        => 0.025,
		'prazi'        => 0.0095,
		'formalin'     => 0.1,
		'chloroquine'  => 0.010,
		'fenbendazole' => 0.01,
	);

	/**
	 * The units an amount may be typed in.
	 *
	 * @var string[]
	 */
	const UNITS = array( 'g', 'ml', 'tsp' );

	/**
	 * The seed: the workbook's "14 Day CM" schedule, one day later than the
	 * sheet's numbering (sheet day 0 = check-in = site day 1).
	 *
	 * @return array[]
	 */
	public static function seed() {
		return array(
			self::feed_step(),
			self::step( 'Copper Power to 2.0', 'dose', 1, 0, null, 'spa', 'check-in day: bring copper to 2.0 (the sheet\'s day 0)' ),
			self::step( 'Copper Power to 2.4', 'dose', 2, 0, null, 'spa', 'raise to 2.4, then hold at 2.25 or higher — 2.5 is the target' ),
			self::step( 'Copper test', 'test', 2, 1, 15, 'spa', 'hold at 2.25 or higher, target 2.5 — log it: "521 copper 2.4"' ),
			self::step( 'Metronidazole', 'dose', 2, 2, 12, 'spa', 'six treatments, every other day; 25 mg per gallon (5.1 g on the 205-gallon system, 2.5 g on the 100)' ),
			self::step( 'Praziquantel 1', 'dose', 8, 0, null, 'any', 'flukes, first treatment; 9.5 mg per gallon (about 2 g on 205 gallons)' ),
			self::step( 'Praziquantel 2', 'dose', 14, 0, null, 'any', 'flukes, second treatment' ),
			self::step( 'Bath, then move to the Clean Room', 'water_change', 16, 0, null, 'spa', 'freshwater or formalin bath; copper comes off and observation starts in the Clean Room (the sheet\'s day 15)' ),
		);
	}

	/**
	 * The daily feeding step every template starts with.
	 *
	 * @return array
	 */
	public static function feed_step() {
		return self::step( 'Feed', 'feed', 1, 1, null, 'any', 'every tank with fish, every day' );
	}

	/**
	 * The five seeded templates in order: "14 Day CM" (seed()), then the
	 * sheet's other columns translated with site day = sheet day + 1.
	 *
	 * @return array<string, array[]> name => steps.
	 */
	public static function templates() {
		$copper = array(
			self::step( 'Copper Power to 2.0', 'dose', 1, 0, null, 'spa', 'check-in day: bring copper to 2.0 (the sheet\'s day 0)' ),
			self::step( 'Copper Power to 2.4', 'dose', 2, 0, null, 'spa', 'raise to 2.4, then hold at 2.25 or higher — 2.5 is the target' ),
			self::step( 'Copper test', 'test', 2, 1, 15, 'spa', 'hold at 2.25 or higher, target 2.5 — log it: "521 copper 2.4"' ),
		);

		return array(
			'14 Day CM'      => self::seed(),
			'14 Day CF'      => array_merge(
				array( self::feed_step() ),
				$copper,
				array(
					self::step( 'Formalin', 'dose', 5, 1, 14, 'spa', 'daily, 1 ml per 10 gallons (20 ml on the 205-gallon system, 10 ml on the 100) — the sheet\'s days 4–13' ),
					self::step( 'Praziquantel 1', 'dose', 7, 0, null, 'any', 'flukes, first treatment; 9.5 mg per gallon (about 2 g on 205 gallons)' ),
					self::step( 'Praziquantel 2', 'dose', 12, 0, null, 'any', 'flukes, second treatment' ),
					self::step( 'Praziquantel 3', 'dose', 14, 0, null, 'any', 'flukes, third treatment' ),
					self::step( 'Move to the Clean Room', 'other', 15, 0, null, 'spa', 'copper comes off and observation starts in the Clean Room (the sheet\'s day 14)' ),
				)
			),
			'Xtra 14 Day CF' => array_merge(
				array( self::feed_step() ),
				$copper,
				array(
					self::step( 'Formalin', 'dose', 1, 1, 14, 'spa', 'daily from the check-in day, 1 ml per 10 gallons (20 ml on the 205-gallon system) — the sheet\'s days 0–13' ),
					self::step( 'Praziquantel 1', 'dose', 6, 0, null, 'any', 'flukes, first treatment; 9.5 mg per gallon (about 2 g on 205 gallons)' ),
					self::step( 'Praziquantel 2', 'dose', 11, 0, null, 'any', 'flukes, second treatment' ),
					self::step( 'Praziquantel 3', 'dose', 13, 0, null, 'any', 'flukes, third treatment' ),
					self::step( 'Move to the Clean Room', 'other', 15, 0, null, 'spa', 'copper comes off and observation starts in the Clean Room (the sheet\'s day 14)' ),
				)
			),
			'CQ'             => array_merge(
				array( self::feed_step() ),
				$copper,
				array(
					self::step( 'Chloroquine 40 mg/gal', 'dose', 3, 0, null, 'spa', 'the loading dose: 40 mg per gallon (8.2 g on the 205-gallon system) — the sheet\'s day 2' ),
					self::step( 'Chloroquine 12 mg/gal', 'dose', 6, 2, 12, 'spa', 'every other day, 12 mg per gallon (2.5 g on 205 gallons) — the sheet\'s days 5, 7, 9, 11' ),
					self::step( 'Chloroquine 6 mg/gal', 'dose', 14, 0, null, 'spa', 'the last, half dose: 6 mg per gallon — the sheet\'s day 13' ),
					self::step( 'Praziquantel 1', 'dose', 7, 0, null, 'any', 'flukes, first treatment; 9.5 mg per gallon (about 2 g on 205 gallons)' ),
					self::step( 'Praziquantel 2', 'dose', 11, 0, null, 'any', 'flukes, second treatment' ),
					self::step( 'Praziquantel 3', 'dose', 13, 0, null, 'any', 'flukes, third treatment' ),
					self::step( 'Freshwater bath, then move to the Clean Room', 'water_change', 16, 0, null, 'spa', 'copper and chloroquine come off; observation starts in the Clean Room (the sheet\'s day 15)' ),
				)
			),
			'Trifecta'       => array(
				self::feed_step(),
				self::step( 'Metronidazole + Kanamycin + Furan-2', 'dose', 1, 0, null, 'any', 'the antibiotic combo for sick fish (Spectrogram stands in for the Furan-2): day 1' ),
				self::step( 'Furan-2', 'dose', 2, 2, 14, 'any', 'the even days: 2, 4, 6, 8, 10, 12, 14' ),
				self::step( '25 % water change, then the combo', 'water_change', 3, 2, 15, 'any', 'the odd days: 3, 5, 7, 9, 11, 13, 15 — change a quarter of the water, then re-dose the combo' ),
			),
		);
	}

	/**
	 * The seeded steps of one template name, or null when the name is not
	 * a seed (Reset in Settings restores this; a custom template resets to
	 * the daily feeding alone).
	 *
	 * @param string $name Template name.
	 * @return array[]|null
	 */
	public static function seed_template( $name ) {
		$templates = self::templates();
		$name      = self::template_name( $name );
		return isset( $templates[ $name ] ) ? $templates[ $name ] : null;
	}

	/**
	 * A clean template name: trimmed, single spaces, no tags, at most
	 * MAX_NAME characters ('' when nothing is left).
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	public static function template_name( $name ) {
		if ( ! is_scalar( $name ) ) {
			return '';
		}
		$name = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $name ) ) );
		return (string) substr( $name, 0, self::MAX_NAME );
	}

	/**
	 * The protocol option in its current shape — { default, templates } —
	 * from whatever is stored: the new shape (every template normalised,
	 * nameless or extra ones dropped, the default made to exist), an older
	 * bare steps array (it becomes the "14 Day CM" template next to the
	 * other four seeds), or nothing at all (the seeds).
	 *
	 * @param mixed $raw The stored option.
	 * @return array{default: string, templates: array<string, array[]>}
	 */
	public static function normalize_option( $raw ) {
		$seeds = self::templates();
		if ( ! is_array( $raw ) ) {
			return array(
				'default'   => self::DEFAULT_TEMPLATE,
				'templates' => $seeds,
			);
		}

		if ( ! isset( $raw['templates'] ) || ! is_array( $raw['templates'] ) ) {
			// The old option: one bare list of steps = the schedule Jeff edited.
			$templates                            = $seeds;
			$templates[ self::DEFAULT_TEMPLATE ] = self::normalize( $raw );
			return array(
				'default'   => self::DEFAULT_TEMPLATE,
				'templates' => $templates,
			);
		}

		$templates = array();
		foreach ( $raw['templates'] as $name => $steps ) {
			$name = self::template_name( $name );
			if ( '' === $name || isset( $templates[ $name ] ) ) {
				continue;
			}
			$templates[ $name ] = self::normalize( $steps );
			if ( count( $templates ) >= self::MAX_TEMPLATES ) {
				break;
			}
		}
		if ( ! $templates ) {
			$templates = $seeds;
		}

		$default = isset( $raw['default'] ) ? self::template_name( $raw['default'] ) : '';
		if ( ! isset( $templates[ $default ] ) ) {
			$default = isset( $templates[ self::DEFAULT_TEMPLATE ] ) ? self::DEFAULT_TEMPLATE : (string) array_key_first( $templates );
		}

		return array(
			'default'   => $default,
			'templates' => $templates,
		);
	}

	/**
	 * The template a building runs: its own schedule when set and existing,
	 * else the default (an unknown name falls back, never to nothing).
	 *
	 * @param array      $building  Building row (schedule).
	 * @param array|null $protocols normalize_option() — null reads the option through Settings.
	 * @return string
	 */
	public static function template_for( array $building, $protocols = null ) {
		$protocols = self::resolve_protocols( $protocols );
		$name      = self::template_name( isset( $building['schedule'] ) ? $building['schedule'] : '' );
		if ( '' !== $name && isset( $protocols['templates'][ $name ] ) ) {
			return $name;
		}
		return (string) $protocols['default'];
	}

	/**
	 * The steps a building runs (see template_for()).
	 *
	 * @param array      $building  Building row (schedule).
	 * @param array|null $protocols normalize_option() — null reads the option through Settings.
	 * @return array[]
	 */
	public static function steps_for( array $building, $protocols = null ) {
		$protocols = self::resolve_protocols( $protocols );
		$name      = self::template_for( $building, $protocols );
		return isset( $protocols['templates'][ $name ] ) ? $protocols['templates'][ $name ] : array();
	}

	/**
	 * @param array|null $protocols Option or null.
	 * @return array
	 */
	private static function resolve_protocols( $protocols ) {
		if ( is_array( $protocols ) && isset( $protocols['templates'] ) ) {
			return $protocols;
		}
		if ( null === $protocols && class_exists( __NAMESPACE__ . '\\Settings' ) ) {
			return Settings::protocols();
		}
		return self::normalize_option( $protocols );
	}

	/* ============================================================== */
	/*  Medications                                                    */
	/* ============================================================== */

	/**
	 * The medication a label or note names, by alias (whole words, any
	 * case): "Copper Power to 2.0" → copper, "Dosed Metro" → metro,
	 * "Praziquantel 1" → prazi; null when none is named.
	 *
	 * @param string $text Step label, log note or a typed name.
	 * @return string|null Key of MEDICATIONS.
	 */
	public static function medication_key( $text ) {
		$text = strtolower( trim( (string) $text ) );
		if ( '' === $text ) {
			return null;
		}
		if ( isset( self::MEDICATIONS[ $text ] ) ) {
			return $text;
		}
		foreach ( self::MEDICATIONS as $key => $medication ) {
			foreach ( $medication['aliases'] as $alias ) {
				if ( 1 === preg_match( '/(?<![a-z])' . preg_quote( $alias, '/' ) . '(?![a-z])/', $text ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	/**
	 * The display name of a medication key ('' for an unknown key).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function medication_label( $key ) {
		$key = (string) $key;
		return isset( self::MEDICATIONS[ $key ] ) ? self::MEDICATIONS[ $key ]['label'] : '';
	}

	/**
	 * The suggested amount of a medication for a water volume: gallons ×
	 * the rate, rounded to one decimal (two below 1), in the medication's
	 * unit. Null for copper (dosed to the test), an unknown medication or
	 * no volume.
	 *
	 * @param string $medication Key.
	 * @param int    $gallons    System gallons.
	 * @return array{amount: float, unit: string}|null
	 */
	public static function dose_suggestion( $medication, $gallons ) {
		$medication = (string) $medication;
		$gallons    = (float) $gallons;
		if ( $gallons <= 0 || ! isset( self::DOSE_RATES[ $medication ] ) ) {
			return null;
		}
		$amount = $gallons * self::DOSE_RATES[ $medication ];
		$amount = round( $amount, $amount >= 1 ? 1 : 2 );
		if ( $amount <= 0 ) {
			return null;
		}
		return array(
			'amount' => $amount,
			'unit'   => self::MEDICATIONS[ $medication ]['unit'],
		);
	}

	/**
	 * "Metronidazole 5.1 g" — the note a dose log carries and the toast
	 * starts with (an unknown medication key is shown as typed).
	 *
	 * @param string $medication Key (or a name).
	 * @param float  $amount     Amount.
	 * @param string $unit       Unit.
	 * @return string
	 */
	public static function dose_text( $medication, $amount, $unit ) {
		$label = self::medication_label( $medication );
		if ( '' === $label ) {
			$label = trim( (string) $medication );
		}
		$text = number_format( round( (float) $amount, 2 ), 2, '.', '' );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return trim( $label . ' ' . $text . ' ' . trim( (string) $unit ) );
	}

	/**
	 * Whether a step is the copper test — a 'test' step whose label names
	 * copper (or Cu). A copper reading logged today ticks it off, so the
	 * chip does not stay open after "521 copper 2.4".
	 *
	 * @param array $step Step.
	 * @return bool
	 */
	public static function is_copper_test( array $step ) {
		return 'test' === (string) ( $step['kind'] ?? '' ) && 1 === preg_match( '/copper|\bcu\b/i', (string) ( $step['label'] ?? '' ) );
	}

	/**
	 * One step.
	 *
	 * @param string   $label Label.
	 * @param string   $kind  feed|dose|test|water_change|other.
	 * @param int      $day   First day (1 = check-in day).
	 * @param int      $every Repeat every N days (0 = once).
	 * @param int|null $until Last day of the repeat (null = open).
	 * @param string   $where any|spa|clean.
	 * @param string   $note  Hint.
	 * @return array
	 */
	public static function step( $label, $kind, $day, $every = 0, $until = null, $where = 'any', $note = '' ) {
		return array(
			'label' => (string) $label,
			'kind'  => (string) $kind,
			'day'   => (int) $day,
			'every' => (int) $every,
			'until' => null === $until ? null : (int) $until,
			'where' => (string) $where,
			'note'  => (string) $note,
		);
	}

	/**
	 * Clean a raw template (from the settings form or the option): every
	 * step typed and clamped, empty labels dropped, at most MAX_STEPS.
	 *
	 * @param mixed $raw Steps.
	 * @return array[]
	 */
	public static function normalize( $raw ) {
		$out = array();
		foreach ( (array) $raw as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$label = isset( $step['label'] ) && is_scalar( $step['label'] ) ? trim( (string) $step['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$kind  = isset( $step['kind'] ) && in_array( (string) $step['kind'], self::KINDS, true ) ? (string) $step['kind'] : 'other';
			$where = isset( $step['where'] ) && in_array( (string) $step['where'], self::WHERES, true ) ? (string) $step['where'] : 'any';
			$day   = isset( $step['day'] ) && is_numeric( $step['day'] ) ? (int) $step['day'] : 1;
			$day   = max( 1, min( self::MAX_DAY, $day ) );
			$every = isset( $step['every'] ) && is_numeric( $step['every'] ) ? (int) $step['every'] : 0;
			$every = max( 0, min( 60, $every ) );
			$until = isset( $step['until'] ) && is_numeric( $step['until'] ) && '' !== (string) $step['until'] ? (int) $step['until'] : null;
			if ( null !== $until ) {
				$until = max( $day, min( self::MAX_DAY, $until ) );
			}
			if ( 0 === $every ) {
				$until = null;
			}
			$note  = isset( $step['note'] ) && is_scalar( $step['note'] ) ? trim( (string) $step['note'] ) : '';
			$out[] = self::step( substr( $label, 0, 80 ), $kind, $day, $every, $until, $where, substr( $note, 0, 200 ) );
			if ( count( $out ) >= self::MAX_STEPS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether a step is due on a day number.
	 *
	 * @param array $step Step.
	 * @param int   $day  Day number.
	 * @return bool
	 */
	public static function is_due( array $step, $day ) {
		$day   = (int) $day;
		$start = (int) ( $step['day'] ?? 1 );
		$every = (int) ( $step['every'] ?? 0 );
		$until = isset( $step['until'] ) && null !== $step['until'] ? (int) $step['until'] : null;

		if ( $day < 1 || $day < $start ) {
			return false;
		}
		if ( $day === $start ) {
			return true;
		}
		if ( $every <= 0 ) {
			return false;
		}
		if ( null !== $until && $day > $until ) {
			return false;
		}
		return 0 === ( $day - $start ) % $every;
	}

	/**
	 * Whether a step applies in an area.
	 *
	 * @param array  $step Step.
	 * @param string $area clean|spa.
	 * @return bool
	 */
	public static function applies( array $step, $area ) {
		$where = (string) ( $step['where'] ?? 'any' );
		return 'any' === $where || '' === (string) $area || $where === (string) $area;
	}

	/**
	 * The steps due on a day in an area, template order.
	 *
	 * @param array[] $steps Template.
	 * @param int     $day   Day number.
	 * @param string  $area  clean|spa ('' = any).
	 * @return array[]
	 */
	public static function due( array $steps, $day, $area = '' ) {
		$out = array();
		foreach ( $steps as $step ) {
			if ( self::is_due( $step, $day ) && self::applies( $step, $area ) ) {
				$out[] = $step;
			}
		}
		return $out;
	}

	/**
	 * The to-do of one tank today: feeding once per tank with live fish
	 * (kind 'feed', regardless of day), then every other due step per
	 * stay. Each item carries a stable key so a tank log can tick it off.
	 *
	 * @param array[] $steps  Template.
	 * @param array[] $stays  The tank's open stays (id, species, qty_live, checkin_date, day).
	 * @param string  $today  Site date.
	 * @param string  $area   The tank's area.
	 * @param array[] $done   Tank logs of today (kind, note) — the tank's own and its building's:
	 *                        a tick-off's note is the item key; a 'copper' log ticks the copper
	 *                        test by itself; a 'dose' log ticks the due dose steps that name the
	 *                        same medication (by alias).
	 * @return array[] key, label, kind, note, stay_id, species, day, done.
	 */
	public static function todo( array $steps, array $stays, $today, $area = '', array $done = array() ) {
		$out    = array();
		$ticked = array();
		$copper = false;
		$dosed  = array();
		foreach ( $done as $log ) {
			$note = (string) ( $log['note'] ?? '' );
			$kind = (string) ( $log['kind'] ?? '' );
			$ticked[ self::tick_key( $note ) ] = true;
			if ( 'copper' === $kind ) {
				$copper = true;
			}
			if ( 'dose' === $kind ) {
				$medication = self::medication_key( $note );
				if ( null !== $medication ) {
					$dosed[ $medication ] = true;
				}
			}
		}

		$live = array();
		foreach ( $stays as $stay ) {
			if ( (int) ( $stay['qty_live'] ?? 0 ) > 0 ) {
				$live[] = $stay;
			}
		}
		if ( ! $live ) {
			return $out;
		}

		foreach ( $steps as $step ) {
			if ( 'feed' !== (string) ( $step['kind'] ?? '' ) || ! self::applies( $step, $area ) ) {
				continue;
			}
			$label = (string) $step['label'];
			$out[] = array(
				'key'     => self::tick_key( $label ),
				'label'   => $label,
				'kind'    => 'feed',
				'note'    => (string) ( $step['note'] ?? '' ),
				'stay_id' => null,
				'species' => '',
				'day'     => null,
				'done'    => isset( $ticked[ self::tick_key( $label ) ] ),
			);
		}

		foreach ( $live as $stay ) {
			$day = isset( $stay['day'] ) && null !== $stay['day'] ? (int) $stay['day'] : Replay::day_number( (string) ( $stay['checkin_date'] ?? '' ), (string) $today );
			if ( null === $day ) {
				continue;
			}
			foreach ( self::due( $steps, $day, $area ) as $step ) {
				if ( 'feed' === (string) $step['kind'] ) {
					continue;
				}
				$label = (string) $step['label'];
				$key   = self::tick_key( $label . ' #' . (int) ( $stay['id'] ?? 0 ) );
				$is_done = isset( $ticked[ $key ] ) || ( $copper && self::is_copper_test( $step ) );
				if ( ! $is_done && $dosed && 'dose' === (string) $step['kind'] ) {
					$medication = self::medication_key( $label );
					$is_done    = null !== $medication && isset( $dosed[ $medication ] );
				}
				$out[] = array(
					'key'     => $key,
					'label'   => $label,
					'kind'    => (string) $step['kind'],
					'note'    => (string) ( $step['note'] ?? '' ),
					'stay_id' => (int) ( $stay['id'] ?? 0 ),
					'species' => (string) ( $stay['species'] ?? '' ),
					'day'     => $day,
					'done'    => $is_done,
				);
			}
		}

		return $out;
	}

	/**
	 * The note a tick-off log carries, and the key both sides compare.
	 *
	 * @param string $label Step label (+ ' #stay' for a per-stay step).
	 * @return string
	 */
	public static function tick_key( $label ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $label ) ) );
	}

	/**
	 * ok / low / none for a copper reading against the floor.
	 *
	 * @param float|null $reading Last reading (null = none).
	 * @param float      $floor   Minimum (2.25).
	 * @return string
	 */
	public static function copper_state( $reading, $floor ) {
		if ( null === $reading || ! is_numeric( $reading ) ) {
			return 'none';
		}
		return (float) $reading + 0.0001 >= (float) $floor ? 'ok' : 'low';
	}

	/**
	 * "Copper test · test · days 2–14, daily · Spa".
	 *
	 * @param array $step Step.
	 * @return string
	 */
	public static function describe( array $step ) {
		$day   = (int) ( $step['day'] ?? 1 );
		$every = (int) ( $step['every'] ?? 0 );
		$until = isset( $step['until'] ) && null !== $step['until'] ? (int) $step['until'] : null;

		if ( $every <= 0 ) {
			$when = 'day ' . $day;
		} else {
			$when = 'days ' . $day . '–' . ( null === $until ? '…' : $until ) . ', ' . ( 1 === $every ? 'daily' : 'every ' . $every . ' days' );
		}
		$where = (string) ( $step['where'] ?? 'any' );
		$place = 'any' === $where ? 'anywhere' : Layout::area_label( $where );

		return (string) ( $step['label'] ?? '' ) . ' · ' . str_replace( '_', ' ', (string) ( $step['kind'] ?? 'other' ) ) . ' · ' . $when . ' · ' . $place;
	}
}
