<?php
/**
 * Clean Room medications — PURE: the list of medications the Dose sheet,
 * the Calculator and the dose phrases offer, as an editable list seeded
 * from the fixed one (Protocol::MEDICATIONS / DOSE_RATES), so nothing
 * changes until somebody edits it in Settings → Medications.
 *
 * One medication:
 *   key        stable, never changes ('copper', 'metro', …; a new one gets a
 *              slug of its name). Logs carry no key: a dose log's note
 *              starts with the medication's name, and key_for() finds the
 *              medication by its names — so a renamed medication keeps its
 *              old name as an "other name" (apply_op()) and a retired one
 *              is still looked up, and old logs keep reading correctly.
 *   label      the name ("Metronidazole").
 *   short      the chip on the phone ("Metro").
 *   aliases    other names, lower-case — for speech, for log notes and for
 *              matching the steps of a treatment schedule.
 *   unit       mg | g | ml | tsp | drops | each — the unit the rate is in.
 *   mode       HOW IT IS DOSED:
 *                per_gallon  rate (in unit) per `per` gallons — 25 mg per
 *                            gallon, 1 ml per 10 gallons;
 *                to_level    rate per `per` gallons raises the level by 1.0
 *                            of level_unit — copper: 0.692 ml per gallon
 *                            per 1.0 ppm;
 *                typed       no sum (Salt).
 *   doses      named doses of a per-gallon medication (label + rate, same
 *              unit and `per`): Chloroquine's Loading / Top-up / Last.
 *   measure    optional "one scoop = 0.5 g" so a result can also read in
 *              scoops.
 *   retired    no longer offered; still read in old logs.
 *
 * THE COPPER PRODUCT is the medication with the key 'copper' while it is
 * dosed to a level: its "Now" is today's copper reading and saving a dose
 * of it also logs that reading (Dose). Another copper product is that row
 * renamed and re-rated — the key stays.
 *
 * The list lives in the section's settings option under the key
 * 'medications' (no table); absent = never edited = the seed. The maths
 * is in Dose; nothing here touches the database.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Medications
 */
class Medications {

	/**
	 * The key of the list inside the settings option.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'medications';

	/**
	 * The units a rate or an amount may be in.
	 *
	 * @var string[]
	 */
	const UNITS = array( 'mg', 'g', 'ml', 'tsp', 'drops', 'each' );

	/**
	 * How a medication is dosed.
	 *
	 * @var string[]
	 */
	const MODES = array( 'per_gallon', 'to_level', 'typed' );

	/**
	 * The copper product's key, and its seeded rate: millilitres per
	 * gallon per 1.0 ppm — the shop's own meter-verified figure for Copper
	 * Power, the same one the store's public Medication Calculator uses
	 * (theme_figure() shows that one beside it so the two cannot drift
	 * apart unseen).
	 */
	const COPPER_KEY  = 'copper';
	const COPPER_RATE = 0.692;
	const LEVEL_UNIT  = 'ppm';

	const MAX               = 40;
	const MAX_KEY           = 30;
	const MAX_LABEL         = 40;
	const MAX_SHORT         = 14;
	const MAX_ALIASES       = 12;
	const MAX_ALIAS         = 40;
	const MAX_DOSES         = 6;
	const MAX_DOSE_LABEL    = 24;
	const MAX_MEASURE_LABEL = 16;
	const MAX_LEVEL_UNIT    = 8;
	const MAX_RATE          = 100000;
	const MAX_PER           = 10000;

	/**
	 * The seeded rates, as the shop says them: an amount per so many
	 * gallons. In the fixed list's own units they are Protocol::DOSE_RATES
	 * (25 mg = 0.025 g per gallon, 1 ml per 10 gal = 0.1 ml per gallon,
	 * 1 g per 100 gal = 0.01 g per gallon) — tests/medications-test.php
	 * holds the two together.
	 *
	 * @var array<string, array{unit: string, rate: float, per: float}>
	 */
	const SEED_RATES = array(
		'metro'        => array(
			'unit' => 'mg',
			'rate' => 25.0,
			'per'  => 1.0,
		),
		'prazi'        => array(
			'unit' => 'mg',
			'rate' => 9.5,
			'per'  => 1.0,
		),
		'formalin'     => array(
			'unit' => 'ml',
			'rate' => 1.0,
			'per'  => 10.0,
		),
		'chloroquine'  => array(
			'unit' => 'mg',
			'rate' => 10.0,
			'per'  => 1.0,
		),
		'fenbendazole' => array(
			'unit' => 'g',
			'rate' => 1.0,
			'per'  => 100.0,
		),
	);

	/**
	 * The seeded named doses: the three the seeded CQ schedule uses
	 * ("Chloroquine 40 mg/gal", "12 mg/gal", "6 mg/gal").
	 *
	 * @var array<string, array<int, array{label: string, rate: float}>>
	 */
	const SEED_DOSES = array(
		'chloroquine' => array(
			array(
				'label' => 'Loading',
				'rate'  => 40.0,
			),
			array(
				'label' => 'Top-up',
				'rate'  => 12.0,
			),
			array(
				'label' => 'Last',
				'rate'  => 6.0,
			),
		),
	);

	/* ============================================================== */
	/*  The list                                                       */
	/* ============================================================== */

	/**
	 * The seed: the fixed list's medications in its order, with its keys,
	 * names and aliases; copper dosed to a level, the five with a rate per
	 * gallon, Salt typed.
	 *
	 * @return array<string, array> key => medication.
	 */
	public static function seed() {
		static $seed = null;
		if ( null !== $seed ) {
			return $seed;
		}

		$rows = array();
		foreach ( Protocol::MEDICATIONS as $key => $fixed ) {
			$row = array(
				'key'     => (string) $key,
				'label'   => $fixed['label'],
				'short'   => $fixed['short'],
				'aliases' => $fixed['aliases'],
				'unit'    => $fixed['unit'],
				'mode'    => 'typed',
			);
			if ( self::COPPER_KEY === $key ) {
				$row['mode']       = 'to_level';
				$row['rate']       = self::COPPER_RATE;
				$row['per']        = 1.0;
				$row['level_unit'] = self::LEVEL_UNIT;
			} elseif ( isset( self::SEED_RATES[ $key ] ) ) {
				$row['mode'] = 'per_gallon';
				$row['unit'] = self::SEED_RATES[ $key ]['unit'];
				$row['rate'] = self::SEED_RATES[ $key ]['rate'];
				$row['per']  = self::SEED_RATES[ $key ]['per'];
			}
			if ( isset( self::SEED_DOSES[ $key ] ) ) {
				$row['doses'] = self::SEED_DOSES[ $key ];
			}
			$rows[] = $row;
		}

		$seed = self::normalize( $rows );
		return $seed;
	}

	/**
	 * The list in use: what Settings → Medications stored, else the seed.
	 * Read straight from the (autoloaded) settings option and normalised
	 * once per stored value — Protocol::medication_key() asks for it for
	 * every log row.
	 *
	 * @return array<string, array> key => medication.
	 */
	public static function current() {
		static $cache = array(
			'print' => null,
			'list'  => array(),
		);

		$raw = null;
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( class_exists( __NAMESPACE__ . '\\Clean_Room' ) ? Clean_Room::OPTION_KEY : 'fishotel_cleanroom_settings', array() );
			if ( is_array( $stored ) && isset( $stored[ self::SETTINGS_KEY ] ) && is_array( $stored[ self::SETTINGS_KEY ] ) ) {
				$raw = $stored[ self::SETTINGS_KEY ];
			}
		}
		if ( null === $raw ) {
			return self::seed();
		}

		$print = md5( (string) serialize( $raw ) );
		if ( $cache['print'] !== $print ) {
			$list  = self::normalize( $raw );
			$cache = array(
				'print' => $print,
				'list'  => $list ? $list : self::seed(),
			);
		}
		return $cache['list'];
	}

	/**
	 * The list a settings array carries (Settings::get()), else the seed.
	 *
	 * @param array $settings Settings.
	 * @return array<string, array>
	 */
	public static function from_settings( array $settings ) {
		if ( isset( $settings[ self::SETTINGS_KEY ] ) && is_array( $settings[ self::SETTINGS_KEY ] ) ) {
			$list = self::normalize( $settings[ self::SETTINGS_KEY ] );
			if ( $list ) {
				return $list;
			}
		}
		return self::seed();
	}

	/**
	 * The medications still offered (not retired), in list order.
	 *
	 * @param array $list key => medication.
	 * @return array<string, array>
	 */
	public static function active( array $list ) {
		$out = array();
		foreach ( $list as $medication ) {
			if ( empty( $medication['retired'] ) ) {
				$out[ (string) $medication['key'] ] = $medication;
			}
		}
		return $out;
	}

	/**
	 * One medication by key (retired ones too), or null.
	 *
	 * @param string $key  Key.
	 * @param array  $list key => medication.
	 * @return array|null
	 */
	public static function get( $key, array $list ) {
		$key = (string) $key;
		return '' !== $key && isset( $list[ $key ] ) && is_array( $list[ $key ] ) ? $list[ $key ] : null;
	}

	/**
	 * The copper product: the medication with the key 'copper' while it is
	 * offered and dosed to a level; null otherwise.
	 *
	 * @param array $list key => medication.
	 * @return array|null
	 */
	public static function copper( array $list ) {
		$medication = self::get( self::COPPER_KEY, $list );
		return $medication && self::is_copper( $medication ) ? $medication : null;
	}

	/**
	 * Whether a medication is the copper product (see copper()).
	 *
	 * @param array $medication Medication.
	 * @return bool
	 */
	public static function is_copper( array $medication ) {
		return self::COPPER_KEY === (string) ( $medication['key'] ?? '' ) && 'to_level' === (string) ( $medication['mode'] ?? '' ) && empty( $medication['retired'] );
	}

	/* ============================================================== */
	/*  Names                                                          */
	/* ============================================================== */

	/**
	 * Every name a medication answers to, lower-case: its name, its chip
	 * and its other names.
	 *
	 * @param array $medication Medication.
	 * @return string[]
	 */
	public static function names( array $medication ) {
		$names = array( self::lower( (string) ( $medication['label'] ?? '' ) ), self::lower( (string) ( $medication['short'] ?? '' ) ) );
		foreach ( (array) ( $medication['aliases'] ?? array() ) as $alias ) {
			$names[] = self::lower( (string) $alias );
		}
		return array_values( array_unique( array_filter( $names, 'strlen' ) ) );
	}

	/**
	 * The medication a label or a note names (whole words, any case):
	 * "Copper Power to 2.0" → copper, "Dosed Metro" → metro, "Praziquantel
	 * 1" → prazi; the one named FIRST when several are ("Metronidazole 5 g —
	 * Cu 2.3" is a metro dose), the longer name when two start at the same
	 * place; a retired medication is still found; null when none is named.
	 *
	 * @param string $text Step label, log note or a typed name.
	 * @param array  $list key => medication.
	 * @return string|null Key.
	 */
	public static function key_for( $text, array $list ) {
		$text = self::lower( trim( (string) $text ) );
		if ( '' === $text ) {
			return null;
		}
		if ( isset( $list[ $text ] ) ) {
			return (string) $list[ $text ]['key'];
		}

		$owner = array();
		foreach ( $list as $medication ) {
			foreach ( self::names( $medication ) as $name ) {
				if ( ! isset( $owner[ $name ] ) ) {
					$owner[ $name ] = (string) $medication['key'];
				}
			}
		}
		if ( ! $owner ) {
			return null;
		}

		// One alternation, the longer names first: the leftmost match wins, and
		// at the same place "copper power" is tried before "copper".
		$names = array_map( 'strval', array_keys( $owner ) );
		usort(
			$names,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a ) ?: strcmp( $a, $b );
			}
		);
		$quoted = array();
		foreach ( $names as $name ) {
			$quoted[] = preg_quote( $name, '/' );
		}
		if ( 1 !== preg_match( '/(?<![a-z])(?:' . implode( '|', $quoted ) . ')(?![a-z])/', $text, $m ) ) {
			return null;
		}
		return isset( $owner[ $m[0] ] ) ? $owner[ $m[0] ] : null;
	}

	/**
	 * The display name of a medication key ('' for an unknown key).
	 *
	 * @param string $key  Key.
	 * @param array  $list key => medication.
	 * @return string
	 */
	public static function label( $key, array $list ) {
		$medication = self::get( $key, $list );
		return $medication ? (string) $medication['label'] : '';
	}

	/* ============================================================== */
	/*  Normalising (lenient: what is stored)                          */
	/* ============================================================== */

	/**
	 * A stored list (rows, or key => row) as key => medication, every row
	 * typed and clamped: a row without a name is dropped, a second row with
	 * the same key too, at most MAX rows.
	 *
	 * @param mixed $raw The stored list.
	 * @return array<string, array>
	 */
	public static function normalize( $raw ) {
		$out = array();
		foreach ( (array) $raw as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! isset( $row['key'] ) && is_string( $index ) ) {
				$row['key'] = $index;
			}
			$row = self::normalize_row( $row );
			if ( null === $row || isset( $out[ $row['key'] ] ) ) {
				continue;
			}
			$out[ $row['key'] ] = $row;
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * One medication, typed and clamped; null without a key or a name. A
	 * rate that is not above 0 makes it typed (a sum is never guessed).
	 *
	 * @param array $row Raw row.
	 * @return array|null
	 */
	public static function normalize_row( array $row ) {
		$key   = self::clean_key( $row['key'] ?? '' );
		$label = self::text( $row['label'] ?? '', self::MAX_LABEL );
		if ( '' === $key || '' === $label ) {
			return null;
		}

		$short = self::text( $row['short'] ?? '', self::MAX_SHORT );
		if ( '' === $short ) {
			$short = self::cut( $label, self::MAX_SHORT );
		}

		$unit = isset( $row['unit'] ) && in_array( (string) $row['unit'], self::UNITS, true ) ? (string) $row['unit'] : 'g';
		$mode = isset( $row['mode'] ) && in_array( (string) $row['mode'], self::MODES, true ) ? (string) $row['mode'] : 'typed';
		$rate = self::number( $row['rate'] ?? null, self::MAX_RATE );
		$per  = self::number( $row['per'] ?? null, self::MAX_PER );
		if ( 'typed' !== $mode && null === $rate ) {
			$mode = 'typed';
		}
		if ( 'typed' === $mode ) {
			$rate = 0.0;
		}

		$level_unit = '';
		if ( 'to_level' === $mode ) {
			$level_unit = self::text( $row['level_unit'] ?? '', self::MAX_LEVEL_UNIT );
			if ( '' === $level_unit ) {
				$level_unit = self::LEVEL_UNIT;
			}
		}

		$doses = array();
		if ( 'per_gallon' === $mode ) {
			$seen = array();
			foreach ( (array) ( $row['doses'] ?? array() ) as $dose ) {
				if ( ! is_array( $dose ) ) {
					continue;
				}
				$dose_label = self::text( $dose['label'] ?? '', self::MAX_DOSE_LABEL );
				$dose_rate  = self::number( $dose['rate'] ?? null, self::MAX_RATE );
				if ( '' === $dose_label || null === $dose_rate || isset( $seen[ self::lower( $dose_label ) ] ) ) {
					continue;
				}
				$seen[ self::lower( $dose_label ) ] = true;
				$doses[]                            = array(
					'label' => $dose_label,
					'rate'  => $dose_rate,
				);
				if ( count( $doses ) >= self::MAX_DOSES ) {
					break;
				}
			}
		}

		$measure = null;
		if ( isset( $row['measure'] ) && is_array( $row['measure'] ) ) {
			$measure_label  = self::text( $row['measure']['label'] ?? '', self::MAX_MEASURE_LABEL );
			$measure_amount = self::number( $row['measure']['amount'] ?? null, self::MAX_RATE );
			$measure_unit   = isset( $row['measure']['unit'] ) && in_array( (string) $row['measure']['unit'], self::UNITS, true ) ? (string) $row['measure']['unit'] : $unit;
			if ( '' !== $measure_label && null !== $measure_amount && Dose::compatible( $measure_unit, $unit ) ) {
				$measure = array(
					'label'  => $measure_label,
					'amount' => $measure_amount,
					'unit'   => $measure_unit,
				);
			}
		}

		return array(
			'key'        => $key,
			'label'      => $label,
			'short'      => $short,
			'aliases'    => self::clean_aliases( $row['aliases'] ?? array(), array( self::lower( $label ), self::lower( $short ) ) ),
			'unit'       => $unit,
			'mode'       => $mode,
			'rate'       => (float) $rate,
			'per'        => null === $per ? 1.0 : $per,
			'level_unit' => $level_unit,
			'doses'      => $doses,
			'measure'    => $measure,
			'retired'    => ! empty( $row['retired'] ),
		);
	}

	/**
	 * Other names from a list or a typed "a, b; c" string: lower-case, one
	 * of each, none that is the name or the chip already, each with a
	 * letter in it and at least two characters, at most MAX_ALIASES.
	 *
	 * @param mixed    $raw  List or text.
	 * @param string[] $skip Names the medication has anyway (lower-case).
	 * @return string[]
	 */
	public static function clean_aliases( $raw, array $skip = array() ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,;\n\r]+/', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $alias ) {
			if ( ! is_scalar( $alias ) ) {
				continue;
			}
			$alias = self::lower( self::text( $alias, self::MAX_ALIAS ) );
			if ( strlen( $alias ) < 2 || ! self::has_letter( $alias ) || in_array( $alias, $skip, true ) || in_array( $alias, $out, true ) ) {
				continue;
			}
			$out[] = $alias;
			if ( count( $out ) >= self::MAX_ALIASES ) {
				break;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Editing (strict: what the form may store)                      */
	/* ============================================================== */

	/**
	 * One Settings → Medications operation on the list (pure): add a
	 * medication, save an edited one (its key stays; a changed name or chip
	 * is kept as an other name, so old logs still read), retire one or
	 * bring it back. Nothing is ever removed.
	 *
	 * @param array  $list key => medication.
	 * @param string $op   add|save|retire|restore.
	 * @param array  $raw  key (save / retire / restore) and the form fields: label, short,
	 *                     aliases, unit, mode, rate, per, level_unit, doses[n][label|rate],
	 *                     measure[label|amount|unit]; for save also seen — fingerprint() of
	 *                     the row the form was drawn from.
	 * @return array{list: array, key: string, message: string}|array{error: string}
	 */
	public static function apply_op( array $list, $op, array $raw ) {
		$key     = self::clean_key( $raw['key'] ?? '' );
		$current = '' !== $key && isset( $list[ $key ] ) ? $list[ $key ] : null;

		switch ( (string) $op ) {
			case 'add':
				if ( count( $list ) >= self::MAX ) {
					/* translators: %d: the cap */
					return array( 'error' => sprintf( __( 'At most %d medications. Retired ones count too.', 'fishotel-misc-plugin' ), self::MAX ) );
				}
				$errors = array();
				$row    = self::from_form( $raw, $errors );
				if ( $row ) {
					$row['key'] = self::new_key( $row['label'], $list );
					$errors     = array_merge( $errors, self::clashes( $row, $list ) );
				}
				if ( $errors || ! $row ) {
					return array( 'error' => implode( ' ', $errors ) );
				}
				$list[ $row['key'] ] = $row;
				return array(
					'list'    => $list,
					'key'     => $row['key'],
					/* translators: %s: medication name */
					'message' => sprintf( __( '"%s" added.', 'fishotel-misc-plugin' ), $row['label'] ),
				);

			case 'save':
				if ( ! $current ) {
					return array( 'error' => __( 'That medication no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				// The form says which version of the row it was drawn from: one that was
				// saved elsewhere since (another tab, another person) is not written over.
				if ( isset( $raw['seen'] ) && is_scalar( $raw['seen'] ) && '' !== (string) $raw['seen'] && self::fingerprint( $current ) !== (string) $raw['seen'] ) {
					/* translators: %s: medication name */
					return array( 'error' => sprintf( __( '"%s" was changed elsewhere after this page was loaded — reload the page and make the change again.', 'fishotel-misc-plugin' ), $current['label'] ) );
				}
				$errors = array();
				$row    = self::from_form( $raw, $errors );
				if ( $row ) {
					$row['key']     = $key;
					$row['retired'] = ! empty( $current['retired'] );
					// A renamed medication keeps its old names: the logs written under them still read.
					$names = self::names( $row );
					foreach ( array( self::lower( $current['label'] ), self::lower( $current['short'] ) ) as $old ) {
						if ( '' === $old || in_array( $old, $names, true ) ) {
							continue;
						}
						if ( count( $row['aliases'] ) >= self::MAX_ALIASES ) {
							/* translators: 1: the old name, 2: the cap */
							$errors[] = sprintf( __( 'The old name "%1$s" has to stay as an other name so old logs still read, and there are already %2$d — remove one first.', 'fishotel-misc-plugin' ), $old, self::MAX_ALIASES );
							break;
						}
						$row['aliases'][] = $old;
						$names[]          = $old;
					}
					$others = $list;
					unset( $others[ $key ] );
					$errors = array_merge( $errors, self::clashes( $row, $others ) );
				}
				if ( $errors || ! $row ) {
					return array( 'error' => implode( ' ', $errors ) );
				}
				$list[ $key ] = $row;
				return array(
					'list'    => $list,
					'key'     => $key,
					/* translators: %s: medication name */
					'message' => sprintf( __( '"%s" saved.', 'fishotel-misc-plugin' ), $row['label'] ),
				);

			case 'retire':
			case 'restore':
				if ( ! $current ) {
					return array( 'error' => __( 'That medication no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				$list[ $key ]['retired'] = 'retire' === (string) $op;
				return array(
					'list'    => $list,
					'key'     => $key,
					'message' => 'retire' === (string) $op
						/* translators: %s: medication name */
						? sprintf( __( '"%s" retired: it is no longer offered, and the logs that name it still read.', 'fishotel-misc-plugin' ), $current['label'] )
						/* translators: %s: medication name */
						: sprintf( __( '"%s" is offered again.', 'fishotel-misc-plugin' ), $current['label'] ),
				);
		}

		return array( 'error' => __( 'Unknown medication action.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * A stored medication's fingerprint (pure): the edit form carries it,
	 * and a Save from a form drawn before somebody else's change is refused
	 * instead of quietly putting the old values back.
	 *
	 * @param array $medication A medication of the list.
	 * @return string 16 hex characters.
	 */
	public static function fingerprint( array $medication ) {
		return substr( md5( (string) json_encode( self::normalize_row( $medication ) ) ), 0, 16 );
	}

	/**
	 * A medication from the form's fields, checked strictly: every problem
	 * is named and nothing is quietly corrected (normalize_row() is the
	 * lenient reader of what is already stored).
	 *
	 * @param array    $raw    Unslashed form fields.
	 * @param string[] $errors Filled with what is wrong.
	 * @return array|null The medication (key '' — the caller sets it), or null.
	 */
	public static function from_form( array $raw, &$errors = array() ) {
		$errors = array();

		$label = self::text( $raw['label'] ?? '', 200 );
		if ( '' === $label ) {
			$errors[] = __( 'Name the medication.', 'fishotel-misc-plugin' );
		} elseif ( self::length( $label ) > self::MAX_LABEL ) {
			/* translators: %d: the cap */
			$errors[] = sprintf( __( 'The name is longer than %d characters.', 'fishotel-misc-plugin' ), self::MAX_LABEL );
		} elseif ( ! self::has_letter( self::lower( $label ) ) ) {
			$errors[] = __( 'The name needs a letter in it.', 'fishotel-misc-plugin' );
		}

		$short = self::text( $raw['short'] ?? '', 200 );
		if ( self::length( $short ) > self::MAX_SHORT ) {
			/* translators: %d: the cap */
			$errors[] = sprintf( __( 'The chip (short name) is longer than %d characters.', 'fishotel-misc-plugin' ), self::MAX_SHORT );
		}

		$unit = isset( $raw['unit'] ) && is_scalar( $raw['unit'] ) ? (string) $raw['unit'] : '';
		if ( ! in_array( $unit, self::UNITS, true ) ) {
			$errors[] = __( 'Pick a unit: mg, g, ml, tsp, drops or each.', 'fishotel-misc-plugin' );
			$unit     = 'g';
		}

		$mode = isset( $raw['mode'] ) && is_scalar( $raw['mode'] ) ? (string) $raw['mode'] : '';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$errors[] = __( 'Pick how it is dosed: per gallon, to a level, or typed.', 'fishotel-misc-plugin' );
			$mode     = 'typed';
		}

		$rate = 0.0;
		$per  = 1.0;
		if ( 'typed' !== $mode ) {
			$rate = self::number( $raw['rate'] ?? null, self::MAX_RATE );
			if ( null === $rate ) {
				$errors[] = 'to_level' === $mode
					? __( 'Type the amount that raises the level by 1.0 (above 0).', 'fishotel-misc-plugin' )
					: __( 'Type the amount per gallon (above 0).', 'fishotel-misc-plugin' );
				$rate     = 0.0;
			}
			if ( isset( $raw['per'] ) && is_scalar( $raw['per'] ) && '' !== trim( (string) $raw['per'] ) ) {
				$per = self::number( $raw['per'], self::MAX_PER );
				if ( null === $per ) {
					/* translators: %d: the most gallons */
					$errors[] = sprintf( __( 'The gallons the amount is for must be above 0 and at most %d.', 'fishotel-misc-plugin' ), self::MAX_PER );
					$per      = 1.0;
				}
			}
		}

		$level_unit = '';
		if ( 'to_level' === $mode ) {
			$level_unit = self::text( $raw['level_unit'] ?? '', 200 );
			if ( '' === $level_unit ) {
				$level_unit = self::LEVEL_UNIT;
			} elseif ( self::length( $level_unit ) > self::MAX_LEVEL_UNIT ) {
				/* translators: %d: the cap */
				$errors[] = sprintf( __( 'The level\'s unit is longer than %d characters.', 'fishotel-misc-plugin' ), self::MAX_LEVEL_UNIT );
			}
		}

		$doses = array();
		$seen  = array();
		$typed = 'per_gallon' === $mode && isset( $raw['doses'] ) && is_array( $raw['doses'] ) ? $raw['doses'] : array();
		foreach ( $typed as $dose ) {
			if ( ! is_array( $dose ) ) {
				continue;
			}
			$dose_label = self::text( $dose['label'] ?? '', 200 );
			$typed_rate = isset( $dose['rate'] ) && is_scalar( $dose['rate'] ) ? trim( (string) $dose['rate'] ) : '';
			if ( '' === $dose_label && '' === $typed_rate ) {
				continue; // An empty row of the form.
			}
			$dose_rate = self::number( $typed_rate, self::MAX_RATE );
			if ( '' === $dose_label || null === $dose_rate ) {
				$errors[] = __( 'A named dose needs a name and an amount above 0.', 'fishotel-misc-plugin' );
				continue;
			}
			if ( self::length( $dose_label ) > self::MAX_DOSE_LABEL ) {
				/* translators: %d: the cap */
				$errors[] = sprintf( __( 'A named dose\'s name is longer than %d characters.', 'fishotel-misc-plugin' ), self::MAX_DOSE_LABEL );
				continue;
			}
			if ( isset( $seen[ self::lower( $dose_label ) ] ) ) {
				/* translators: %s: the name */
				$errors[] = sprintf( __( 'Two named doses are called "%s".', 'fishotel-misc-plugin' ), $dose_label );
				continue;
			}
			$seen[ self::lower( $dose_label ) ] = true;
			$doses[]                            = array(
				'label' => $dose_label,
				'rate'  => $dose_rate,
			);
		}
		if ( count( $doses ) > self::MAX_DOSES ) {
			/* translators: %d: the cap */
			$errors[] = sprintf( __( 'At most %d named doses.', 'fishotel-misc-plugin' ), self::MAX_DOSES );
		}

		$measure = null;
		$m       = isset( $raw['measure'] ) && is_array( $raw['measure'] ) ? $raw['measure'] : array();
		$m_label = self::text( $m['label'] ?? '', 200 );
		$m_typed = isset( $m['amount'] ) && is_scalar( $m['amount'] ) ? trim( (string) $m['amount'] ) : '';
		if ( '' !== $m_label || '' !== $m_typed ) {
			$m_amount = self::number( $m_typed, self::MAX_RATE );
			$m_unit   = isset( $m['unit'] ) && is_scalar( $m['unit'] ) && '' !== (string) $m['unit'] ? (string) $m['unit'] : $unit;
			if ( '' === $m_label || null === $m_amount ) {
				$errors[] = __( 'A measure needs a name and an amount above 0 — "one scoop = 0.5 g".', 'fishotel-misc-plugin' );
			} elseif ( self::length( $m_label ) > self::MAX_MEASURE_LABEL ) {
				/* translators: %d: the cap */
				$errors[] = sprintf( __( 'The measure\'s name is longer than %d characters.', 'fishotel-misc-plugin' ), self::MAX_MEASURE_LABEL );
			} elseif ( ! in_array( $m_unit, self::UNITS, true ) || ! Dose::compatible( $m_unit, $unit ) ) {
				/* translators: %s: the medication's unit */
				$errors[] = sprintf( __( 'The measure has to be in the medication\'s kind of unit (%s; mg and g go together).', 'fishotel-misc-plugin' ), $unit );
			} else {
				$measure = array(
					'label'  => $m_label,
					'amount' => $m_amount,
					'unit'   => $m_unit,
				);
			}
		}

		$short_or_label = '' !== $short ? $short : self::cut( $label, self::MAX_SHORT );
		$typed_aliases  = isset( $raw['aliases'] ) ? $raw['aliases'] : array();
		$aliases        = self::clean_aliases( $typed_aliases, array( self::lower( $label ), self::lower( $short_or_label ) ) );
		$typed_count    = 0;
		foreach ( is_string( $typed_aliases ) ? (array) preg_split( '/[,;\n\r]+/', $typed_aliases ) : (array) $typed_aliases as $alias ) {
			if ( is_scalar( $alias ) && '' !== trim( (string) $alias ) ) {
				$typed_count++;
			}
		}
		if ( $typed_count > self::MAX_ALIASES ) {
			/* translators: %d: the cap */
			$errors[] = sprintf( __( 'At most %d other names.', 'fishotel-misc-plugin' ), self::MAX_ALIASES );
		}

		if ( $errors ) {
			return null;
		}

		return array(
			'key'        => '',
			'label'      => $label,
			'short'      => $short_or_label,
			'aliases'    => $aliases,
			'unit'       => $unit,
			'mode'       => $mode,
			'rate'       => (float) $rate,
			'per'        => (float) $per,
			'level_unit' => $level_unit,
			'doses'      => $doses,
			'measure'    => $measure,
			'retired'    => false,
		);
	}

	/**
	 * The names of a medication that another one already answers to — two
	 * medications with one name could not be told apart in a log.
	 *
	 * @param array $row    The medication being added or saved.
	 * @param array $others Every OTHER medication (retired ones too).
	 * @return string[] One message per clash.
	 */
	public static function clashes( array $row, array $others ) {
		$errors = array();
		$mine   = self::names( $row );
		foreach ( $others as $other ) {
			foreach ( array_intersect( $mine, self::names( $other ) ) as $name ) {
				/* translators: 1: the name, 2: the other medication */
				$errors[] = sprintf( __( '"%1$s" is already a name of %2$s.', 'fishotel-misc-plugin' ), $name, (string) $other['label'] );
			}
		}
		return $errors;
	}

	/**
	 * A key for a new medication: a slug of its name that no medication
	 * has yet (never digits only — PHP would turn such an array key into a
	 * number).
	 *
	 * @param string $label The name.
	 * @param array  $list  key => medication.
	 * @return string
	 */
	public static function new_key( $label, array $list ) {
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $label ) ), '-' );
		if ( '' === $slug ) {
			$slug = 'medication';
		}
		if ( ctype_digit( $slug[0] ) ) {
			$slug = 'm-' . $slug;
		}
		$slug = rtrim( substr( $slug, 0, self::MAX_KEY - 4 ), '-' );
		$key  = $slug;
		$n    = 2;
		while ( isset( $list[ $key ] ) ) {
			$key = $slug . '-' . $n;
			$n++;
		}
		return $key;
	}

	/* ============================================================== */
	/*  The store's public calculator                                  */
	/* ============================================================== */

	/**
	 * The theme's medication data when it is there (the store's public
	 * Medication Calculator reads it): through the filter
	 * 'fishotel_cleanroom_theme_medication_data' first, else from the
	 * theme's own class when it is loaded. Read defensively — nothing here
	 * depends on it, and anything unexpected is "not available".
	 *
	 * @return array|null { medications: array[] } or null.
	 */
	public static function theme_data() {
		$data = null;
		try {
			if ( function_exists( 'apply_filters' ) ) {
				$data = apply_filters( 'fishotel_cleanroom_theme_medication_data', null );
			}
			if ( ! is_array( $data ) && class_exists( '\\FisHotel_Medication_Data', false ) && is_callable( array( '\\FisHotel_Medication_Data', 'get_all' ) ) ) {
				$data = \FisHotel_Medication_Data::get_all();
			}
		} catch ( \Throwable $e ) {
			$data = null;
		}
		return is_array( $data ) && isset( $data['medications'] ) && is_array( $data['medications'] ) && $data['medications'] ? $data : null;
	}

	/**
	 * The figure the store's public calculator uses for a medication dosed
	 * to a level — millilitres per gallon per 1.0 ppm — and whether it is
	 * this list's (pure). The theme's entry is the one whose id or name is
	 * the medication's name ("copper_power" / "Copper Power (…)").
	 *
	 * @param array      $medication Medication.
	 * @param array|null $data       theme_data(), or null when it is not available.
	 * @return array{rate: float, name: string, same: bool|null}|null Null when the theme has no figure
	 *                               for it; same is null when the two cannot be compared (another unit).
	 */
	public static function theme_figure( array $medication, $data ) {
		if ( ! is_array( $data ) || empty( $data['medications'] ) || ! is_array( $data['medications'] ) || 'to_level' !== (string) ( $medication['mode'] ?? '' ) ) {
			return null;
		}
		$label = self::lower( (string) ( $medication['label'] ?? '' ) );
		if ( '' === $label ) {
			return null;
		}

		$found = null;
		foreach ( $data['medications'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['dispensing_formula'] ) || ! is_array( $entry['dispensing_formula'] ) ) {
				continue;
			}
			$figure = $entry['dispensing_formula']['ml_per_gal_per_ppm'] ?? null;
			if ( ! is_numeric( $figure ) || (float) $figure <= 0 ) {
				continue;
			}
			$names = array();
			if ( isset( $entry['med_id'] ) && is_scalar( $entry['med_id'] ) ) {
				$names[] = str_replace( '_', ' ', self::lower( (string) $entry['med_id'] ) );
			}
			if ( isset( $entry['name_generic'] ) && is_scalar( $entry['name_generic'] ) ) {
				$names[] = self::lower( (string) $entry['name_generic'] );
			}
			foreach ( isset( $entry['brand_equivalents'] ) && is_array( $entry['brand_equivalents'] ) ? $entry['brand_equivalents'] : array() as $brand ) {
				if ( is_array( $brand ) && isset( $brand['brand_name'] ) && is_scalar( $brand['brand_name'] ) ) {
					$names[] = self::lower( (string) $brand['brand_name'] );
				}
			}
			foreach ( $names as $name ) {
				// The whole name, or the name followed by something that is not a letter ("copper power (ionic…)").
				if ( $name === $label || ( 0 === strpos( $name, $label ) && 1 !== preg_match( '/^[a-z]/', substr( $name, strlen( $label ) ) ) ) ) {
					$found = array(
						'rate' => (float) $figure,
						'name' => isset( $entry['name_generic'] ) && is_scalar( $entry['name_generic'] ) ? self::text( $entry['name_generic'], 80 ) : self::text( $entry['med_id'] ?? '', 80 ),
					);
					break 2;
				}
			}
		}
		if ( ! $found ) {
			return null;
		}

		$same = null;
		if ( 'ml' === (string) ( $medication['unit'] ?? '' ) && self::LEVEL_UNIT === self::lower( (string) ( $medication['level_unit'] ?? '' ) ) ) {
			$per  = (float) ( $medication['per'] ?? 1 ) > 0 ? (float) $medication['per'] : 1.0;
			$same = abs( (float) $medication['rate'] / $per - $found['rate'] ) < 0.0005;
		}
		$found['same'] = $same;

		return $found;
	}

	/* ============================================================== */
	/*  Small helpers                                                  */
	/* ============================================================== */

	/**
	 * A clean key: lower-case letters, digits, - and _ ('' when nothing is left).
	 *
	 * @param mixed $key Raw key.
	 * @return string
	 */
	public static function clean_key( $key ) {
		if ( ! is_scalar( $key ) || is_bool( $key ) ) {
			return '';
		}
		return (string) substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ), 0, self::MAX_KEY );
	}

	/**
	 * Plain text: no tags, single spaces, trimmed, cut.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Most characters.
	 * @return string
	 */
	public static function text( $value, $max ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) );
		return self::cut( $text, $max );
	}

	/**
	 * A number above 0 and at most $max, else null.
	 *
	 * @param mixed $value Raw value.
	 * @param float $max   Most.
	 * @return float|null
	 */
	public static function number( $value, $max ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		$number = round( (float) $value, 4 );
		if ( ! is_finite( $number ) || $number <= 0 || $number > (float) $max ) {
			return null;
		}
		return $number;
	}

	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	private static function cut( $text, $max ) {
		return function_exists( 'mb_substr' ) ? (string) mb_substr( (string) $text, 0, (int) $max, 'UTF-8' ) : (string) substr( (string) $text, 0, (int) $max );
	}

	/**
	 * Whether a (lower-case) name has a letter in it: a–z, or any character
	 * outside ASCII.
	 *
	 * @param string $text Lower-case text.
	 * @return bool
	 */
	private static function has_letter( $text ) {
		return 1 === preg_match( '/[a-z\x80-\xff]/', (string) $text );
	}
}
