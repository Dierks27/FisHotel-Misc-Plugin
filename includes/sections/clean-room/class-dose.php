<?php
/**
 * Clean Room doses — the sum behind the Dose sheet, the Calculator and the
 * dose phrases, for any medication of the list (Medications):
 *
 *   per gallon   gallons × the rate           100 gal × 25 mg = 2.5 g
 *   to a level   gallons × (target − now) × the rate per 1.0
 *                                             100 gal × 0.24 ppm × 0.692 = 16.6 ml
 *   typed        no sum
 *
 * and, after a water change (the top-up): a per-gallon medication for the
 * water that was replaced; a medication dosed to a level stays now →
 * target.
 *
 * plan() is the one sum — PURE, and written twice on purpose: here and in
 * js/dose-math.js, which the phone's sheet and the two calculators run
 * without a round trip. tests/fixtures/dose-maths.json is read by both
 * sides' tests, so they cannot drift apart: the same numbers, the same
 * result line ("Add 16.6 ml Copper Power"), the same working ("100 gal ×
 * 0.24 ppm × 0.692"). The rounding is spelled out with floor() so that PHP
 * and JavaScript agree to the last digit: one decimal from 1 up, two
 * below 1; a weight reads in grams from 1 g up and in milligrams below.
 *
 * The rest prepares the sum from what the room knows — the water (the
 * building's system or one tank), today's copper reading for it, the
 * schedule steps due today that name a medication with a rate or a level
 * (waters(), defaults()) — and writes what a Save writes besides the dose
 * itself: the working as the dose log's note, and for the copper product
 * the reading (save_reading()). Reads go through the store's own methods
 * (logs_on_date(), open_stays(), building(), tanks()); nothing here has
 * SQL.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dose
 */
class Dose {

	/**
	 * The weights, in milligrams: mg and g convert into each other, every
	 * other unit only into itself.
	 *
	 * @var array<string, float>
	 */
	const MASS = array(
		'mg' => 1.0,
		'g'  => 1000.0,
	);

	/**
	 * 10^n for the decimals the rounding uses.
	 *
	 * @var float[]
	 */
	const FACTORS = array( 1.0, 10.0, 100.0, 1000.0, 10000.0 );

	/**
	 * The most a level (a reading, a target) may be.
	 *
	 * @var int
	 */
	const MAX_LEVEL = 999;

	/* ============================================================== */
	/*  Numbers and words (mirrored in js/dose-math.js)                */
	/* ============================================================== */

	/**
	 * An amount in another unit, or null when the two do not convert
	 * (only mg ↔ g do).
	 *
	 * @param float  $amount Amount.
	 * @param string $from   Its unit.
	 * @param string $to     The unit wanted.
	 * @return float|null
	 */
	public static function convert( $amount, $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;
		if ( $from === $to ) {
			return (float) $amount;
		}
		if ( isset( self::MASS[ $from ], self::MASS[ $to ] ) ) {
			return (float) $amount * self::MASS[ $from ] / self::MASS[ $to ];
		}
		return null;
	}

	/**
	 * Whether two units convert into each other.
	 *
	 * @param string $a Unit.
	 * @param string $b Unit.
	 * @return bool
	 */
	public static function compatible( $a, $b ) {
		return null !== self::convert( 1, $a, $b );
	}

	/**
	 * Round half up to so many decimals — with floor(), and a hair added so
	 * a value a breath under the half (11.499999999999998 for 1.15 × 10)
	 * counts as the half: the same arithmetic gives the same digit in PHP
	 * and in JavaScript, which round() and Math.round() do not.
	 *
	 * @param float $value    A value of 0 or more.
	 * @param int   $decimals 0–4.
	 * @return float
	 */
	public static function round_to( $value, $decimals ) {
		$factor = self::FACTORS[ max( 0, min( 4, (int) $decimals ) ) ];
		return floor( (float) $value * $factor + 0.5 + 1e-9 ) / $factor;
	}

	/**
	 * An amount as it is shown and logged: one decimal from 1 up, two below
	 * 1 (and as many as it takes, up to four, before a small amount would
	 * read as 0).
	 *
	 * @param float $value Amount.
	 * @return float
	 */
	public static function round_amount( $value ) {
		$value = (float) $value;
		if ( $value <= 0 ) {
			return 0.0;
		}
		$decimals = $value >= 1 ? 1 : 2;
		$rounded  = self::round_to( $value, $decimals );
		while ( $rounded <= 0 && $decimals < 4 ) {
			$decimals++;
			$rounded = self::round_to( $value, $decimals );
		}
		return $rounded;
	}

	/**
	 * An amount in the sensible unit, rounded: a weight in grams from 1 g
	 * up and in milligrams below (2,500 mg reads 2.5 g; 0.4 g reads 400 mg);
	 * every other unit as it is.
	 *
	 * @param float  $amount Amount.
	 * @param string $unit   Its unit.
	 * @return array{amount: float, unit: string}
	 */
	public static function display( $amount, $unit ) {
		$amount = (float) $amount;
		$unit   = (string) $unit;
		if ( isset( self::MASS[ $unit ] ) ) {
			$mg      = $amount * self::MASS[ $unit ];
			$rounded = self::round_amount( $mg );
			if ( $rounded >= 1000 ) {
				return array(
					'amount' => self::round_amount( $mg / 1000 ),
					'unit'   => 'g',
				);
			}
			return array(
				'amount' => $rounded,
				'unit'   => 'mg',
			);
		}
		return array(
			'amount' => self::round_amount( $amount ),
			'unit'   => $unit,
		);
	}

	/**
	 * A number with exactly so many decimals ("2.40").
	 *
	 * @param float $value    Value.
	 * @param int   $decimals 0–4.
	 * @return string
	 */
	public static function fixed( $value, $decimals ) {
		$decimals = max( 0, min( 4, (int) $decimals ) );
		$value    = (float) $value;
		$factor   = self::FACTORS[ $decimals ];
		$n        = floor( abs( $value ) * $factor + 0.5 + 1e-9 );
		$whole    = floor( $n / $factor );
		$text     = sprintf( '%.0f', $whole );
		if ( $decimals > 0 ) {
			$text .= '.' . str_pad( sprintf( '%.0f', $n - $whole * $factor ), $decimals, '0', STR_PAD_LEFT );
		}
		return ( $value < 0 && $n > 0 ? '-' : '' ) . $text;
	}

	/**
	 * A number without needless zeros: 2.5, 16.6, 250, 0.692.
	 *
	 * @param float $value Value.
	 * @param int   $max   Most decimals (0–4).
	 * @return string
	 */
	public static function num( $value, $max = 2 ) {
		$text = self::fixed( $value, $max );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return $text;
	}

	/**
	 * A level as it is written: two decimals, three when the third counts
	 * (2.16, 2.40, 1.025).
	 *
	 * @param float $value Level.
	 * @return string
	 */
	public static function level_text( $value ) {
		$text = self::fixed( $value, 3 );
		return '0' === substr( $text, -1 ) ? substr( $text, 0, -1 ) : $text;
	}

	/**
	 * "2.5 g" — and "2 ×" for a medication counted in pieces.
	 *
	 * @param float  $amount Amount (already rounded).
	 * @param string $unit   Unit.
	 * @return string
	 */
	public static function amount_text( $amount, $unit ) {
		return 'each' === (string) $unit ? self::num( $amount, 4 ) . ' ×' : self::num( $amount, 4 ) . ' ' . (string) $unit;
	}

	/**
	 * A rate's amount in words, in the sensible unit and unrounded: "25 mg"
	 * (also from 0.025 g), "1 ml", "0.5" for pieces.
	 *
	 * @param float  $rate Rate.
	 * @param string $unit Its unit.
	 * @return string
	 */
	public static function rate_amount( $rate, $unit ) {
		$rate = (float) $rate;
		$unit = (string) $unit;
		if ( isset( self::MASS[ $unit ] ) ) {
			$mg = $rate * self::MASS[ $unit ];
			if ( $mg + 1e-9 >= 1000 ) {
				$rate = $mg / 1000;
				$unit = 'g';
			} else {
				$rate = $mg;
				$unit = 'mg';
			}
		}
		return 'each' === $unit ? self::num( $rate, 4 ) : self::num( $rate, 4 ) . ' ' . $unit;
	}

	/**
	 * "25 mg per gallon" / "1 ml per 10 gal".
	 *
	 * @param float      $rate    Rate.
	 * @param string     $unit    Its unit.
	 * @param float      $per     The gallons the rate is for.
	 * @param array|null $strings strings() (null = read them).
	 * @return string
	 */
	public static function rate_text( $rate, $unit, $per, $strings = null ) {
		$s = is_array( $strings ) ? $strings : self::strings();
		if ( abs( (float) $per - 1 ) < 1e-9 ) {
			return sprintf( $s['perGallon'], self::rate_amount( $rate, $unit ) );
		}
		return sprintf( $s['perGallons'], self::rate_amount( $rate, $unit ), self::num( $per, 2 ) );
	}

	/**
	 * "100 gal".
	 *
	 * @param float      $gallons Gallons.
	 * @param array|null $strings strings().
	 * @return string
	 */
	public static function gallons_text( $gallons, $strings = null ) {
		$s = is_array( $strings ) ? $strings : self::strings();
		return sprintf( $s['gal'], self::num( $gallons, 2 ) );
	}

	/**
	 * A measure's name for a count: scoop → scoops, pinch → pinches.
	 *
	 * @param string $label Singular name.
	 * @param float  $count Count.
	 * @return string
	 */
	public static function plural( $label, $count ) {
		$label = (string) $label;
		if ( abs( (float) $count - 1 ) < 1e-9 || '' === $label ) {
			return $label;
		}
		if ( 1 === preg_match( '/(s|x|z|ch|sh)$/i', $label ) ) {
			return $label . 'es';
		}
		if ( 1 === preg_match( '/[^aeiou]y$/i', $label ) ) {
			return substr( $label, 0, -1 ) . 'ies';
		}
		return $label . 's';
	}

	/**
	 * The words the sum is written with (translated here; js/dose-math.js
	 * carries the same English as its fallback and receives these).
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			/* translators: 1: amount with unit, 2: medication */
			'add'          => __( 'Add %1$s %2$s', 'fishotel-misc-plugin' ),
			'nothing'      => __( 'Nothing to add', 'fishotel-misc-plugin' ),
			/* translators: 1: the level now, 2: the level wanted */
			'atOrAbove'    => __( '%1$s is at or above %2$s', 'fishotel-misc-plugin' ),
			/* translators: %s: medication */
			'typed'        => __( 'No sum for %s — type the amount.', 'fishotel-misc-plugin' ),
			'needsNow'     => __( 'Type the reading now to get the amount.', 'fishotel-misc-plugin' ),
			'needsTarget'  => __( 'Type the level to bring it to.', 'fishotel-misc-plugin' ),
			'needsChanged' => __( 'How much of the water was changed?', 'fishotel-misc-plugin' ),
			/* translators: %s: gallons */
			'tooMuch'      => __( 'That is more than this water holds (%s).', 'fishotel-misc-plugin' ),
			'noGallons'    => __( 'The gallons of this water are not known — type the amount.', 'fishotel-misc-plugin' ),
			/* translators: %s: amount with unit */
			'perGallon'    => __( '%s per gallon', 'fishotel-misc-plugin' ),
			/* translators: 1: amount with unit, 2: gallons */
			'perGallons'   => __( '%1$s per %2$s gal', 'fishotel-misc-plugin' ),
			/* translators: %s: gallons */
			'gal'          => __( '%s gal', 'fishotel-misc-plugin' ),
			/* translators: 1: a share ("25 %"), 2: gallons */
			'ofWater'      => __( '%1$s of %2$s', 'fishotel-misc-plugin' ),
			/* translators: %s: how much water ("25 %" or "30 gal") */
			'topup'        => __( 'top-up after a %s water change', 'fishotel-misc-plugin' ),
			'topupPlain'   => __( 'top-up after a water change', 'fishotel-misc-plugin' ),
			/* translators: 1: count, 2: measure (plural), 3: measure, 4: its amount */
			'measure'      => __( '%1$s %2$s (one %3$s = %4$s)', 'fishotel-misc-plugin' ),
		);
	}

	/* ============================================================== */
	/*  The sum (mirrored in js/dose-math.js)                          */
	/* ============================================================== */

	/**
	 * The sum for one medication and one water.
	 *
	 * state is one of
	 *   ok            amount / unit / line / working (and measure) are filled;
	 *   nothing       dosed to a level and already at or above it;
	 *   typed         the medication has no sum;
	 *   no_gallons    the water's volume is not known;
	 *   needs_now / needs_target   a level is missing;
	 *   needs_changed / too_much   the top-up's water change is missing or more than the water.
	 * In every state but ok the amount is null and the line says what is
	 * missing; note is what the dose log carries (the working in words), ''
	 * when there is nothing worth keeping.
	 *
	 * @param array      $medication Medication (Medications).
	 * @param array      $args       gallons (float); per gallon: rate (float, null = the medication's),
	 *                               rate_label (a named dose); to a level: now, target (float|null);
	 *                               top-up: topup (bool), changed (float|null), changed_unit (pct|gal).
	 * @param array|null $strings    strings() (null = read them).
	 * @return array{state: string, amount: float|null, unit: string, line: string, working: string, note: string, measure: string}
	 */
	public static function plan( array $medication, array $args = array(), $strings = null ) {
		$s       = is_array( $strings ) ? $strings : self::strings();
		$label   = (string) ( $medication['label'] ?? '' );
		$unit    = (string) ( $medication['unit'] ?? '' );
		$mode    = (string) ( $medication['mode'] ?? 'typed' );
		$per     = isset( $medication['per'] ) && (float) $medication['per'] > 0 ? (float) $medication['per'] : 1.0;
		$gallons = isset( $args['gallons'] ) && is_numeric( $args['gallons'] ) && (float) $args['gallons'] > 0 ? (float) $args['gallons'] : 0.0;
		$topup   = ! empty( $args['topup'] );
		$changed = isset( $args['changed'] ) && is_numeric( $args['changed'] ) && (float) $args['changed'] > 0 ? (float) $args['changed'] : null;
		$by_gal  = 'gal' === (string) ( $args['changed_unit'] ?? 'pct' );
		$share   = null === $changed ? '' : ( $by_gal ? self::gallons_text( $changed, $s ) : self::num( $changed, 2 ) . ' %' );
		$remark  = ! $topup ? '' : ( '' === $share ? $s['topupPlain'] : sprintf( $s['topup'], $share ) );

		$out = array(
			'state'   => 'typed',
			'amount'  => null,
			'unit'    => $unit,
			'line'    => '',
			'working' => '',
			'note'    => $remark,
			'measure' => '',
		);

		if ( 'to_level' === $mode ) {
			$now    = self::level( $args['now'] ?? null );
			$target = self::level( $args['target'] ?? null );
			$lu     = (string) ( $medication['level_unit'] ?? '' );
			$suffix = '' !== $lu ? ' ' . $lu : '';
			if ( null === $now || null === $target ) {
				$out['state'] = null === $now ? 'needs_now' : 'needs_target';
				$out['line']  = null === $now ? $s['needsNow'] : $s['needsTarget'];
				return $out;
			}
			$out['note'] = self::level_text( $now ) . ' → ' . self::level_text( $target ) . $suffix . ( $gallons > 0 ? ', ' . self::gallons_text( $gallons, $s ) : '' ) . ( '' !== $remark ? ' · ' . $remark : '' );
			if ( $gallons <= 0 ) {
				$out['state'] = 'no_gallons';
				$out['line']  = $s['noGallons'];
				return $out;
			}
			$delta = $target - $now;
			if ( $delta <= 1e-7 ) {
				$out['state']   = 'nothing';
				$out['line']    = $s['nothing'];
				$out['working'] = sprintf( $s['atOrAbove'], self::level_text( $now ) . $suffix, self::level_text( $target ) . $suffix );
				return $out;
			}
			$rate   = (float) ( $medication['rate'] ?? 0 );
			$factor = abs( $per - 1 ) < 1e-9 ? self::num( $rate, 4 ) : self::rate_text( $rate, $unit, $per, $s );
			$shown  = self::display( $gallons * $delta * $rate / $per, $unit );

			$out['working'] = self::gallons_text( $gallons, $s ) . ' × ' . self::level_text( $delta ) . $suffix . ' × ' . $factor;
			return self::finish( $out, $medication, $shown, $s );
		}

		if ( 'per_gallon' !== $mode ) {
			$out['line'] = sprintf( $s['typed'], $label );
			return $out;
		}

		$rate       = isset( $args['rate'] ) && is_numeric( $args['rate'] ) && (float) $args['rate'] > 0 ? (float) $args['rate'] : (float) ( $medication['rate'] ?? 0 );
		$rate_label = isset( $args['rate_label'] ) && is_scalar( $args['rate_label'] ) ? trim( (string) $args['rate_label'] ) : '';
		$rate_text  = self::rate_text( $rate, $unit, $per, $s );
		$used       = $gallons;
		$of         = '';

		if ( $topup ) {
			if ( null === $changed ) {
				$out['state'] = 'needs_changed';
				$out['line']  = $s['needsChanged'];
				return $out;
			}
			if ( $by_gal ) {
				if ( $gallons > 0 && $changed > $gallons + 1e-9 ) {
					$out['state'] = 'too_much';
					$out['line']  = sprintf( $s['tooMuch'], self::gallons_text( $gallons, $s ) );
					$out['note']  = $s['topupPlain'];
					return $out;
				}
				$used = $changed;
			} else {
				if ( $changed > 100 + 1e-9 ) {
					$out['state'] = 'too_much';
					$out['line']  = sprintf( $s['tooMuch'], '100 %' );
					$out['note']  = $s['topupPlain'];
					return $out;
				}
				if ( $gallons <= 0 ) {
					$out['state'] = 'no_gallons';
					$out['line']  = $s['noGallons'];
					return $out;
				}
				$used = $gallons * $changed / 100;
				$of   = ' (' . sprintf( $s['ofWater'], $share, self::gallons_text( $gallons, $s ) ) . ')';
			}
		} elseif ( $gallons <= 0 ) {
			$out['state'] = 'no_gallons';
			$out['line']  = $s['noGallons'];
			return $out;
		}

		$shown = self::display( $used * $rate / $per, $unit );

		$out['working'] = self::gallons_text( $used, $s ) . ' × ' . ( abs( $per - 1 ) < 1e-9 ? self::rate_amount( $rate, $unit ) : $rate_text ) . $of;
		$out['note']    = ( '' !== $rate_label ? $rate_label . ': ' : '' ) . $rate_text . ', ' . self::gallons_text( $used, $s ) . ( '' !== $remark ? ' · ' . $remark : '' );

		return self::finish( $out, $medication, $shown, $s );
	}

	/**
	 * The result line and the measure of a sum that came to an amount.
	 *
	 * @param array $out        The plan so far.
	 * @param array $medication Medication.
	 * @param array $shown      display(): amount, unit.
	 * @param array $s          strings().
	 * @return array
	 */
	private static function finish( array $out, array $medication, array $shown, array $s ) {
		if ( $shown['amount'] <= 0 ) {
			$out['state'] = 'nothing';
			$out['line']  = $s['nothing'];
			return $out;
		}
		$out['state']  = 'ok';
		$out['amount'] = $shown['amount'];
		$out['unit']   = $shown['unit'];
		$out['line']   = sprintf( $s['add'], self::amount_text( $shown['amount'], $shown['unit'] ), (string) ( $medication['label'] ?? '' ) );

		$measure = isset( $medication['measure'] ) && is_array( $medication['measure'] ) ? $medication['measure'] : null;
		if ( $measure && (float) ( $measure['amount'] ?? 0 ) > 0 ) {
			$in = self::convert( $shown['amount'], $shown['unit'], (string) $measure['unit'] );
			if ( null !== $in ) {
				$count          = self::round_amount( $in / (float) $measure['amount'] );
				$out['measure'] = sprintf( $s['measure'], self::num( $count, 4 ), self::plural( (string) $measure['label'], $count ), (string) $measure['label'], self::amount_text( (float) $measure['amount'], (string) $measure['unit'] ) );
			}
		}
		return $out;
	}

	/**
	 * A level from anything typed: a number from 0 to MAX_LEVEL, else null.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function level( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		$level = (float) $value;
		if ( ! is_finite( $level ) || $level < 0 || $level > self::MAX_LEVEL ) {
			return null;
		}
		return self::round_to( $level, 3 );
	}

	/* ============================================================== */
	/*  The schedule's steps                                           */
	/* ============================================================== */

	/**
	 * The rate a step label names, as the medication's own rate (in its
	 * unit, for its `per` gallons): "Chloroquine 40 mg/gal" → 40 for a
	 * medication in mg per gallon, "1 ml per 10 gal" → 1 for one in ml per
	 * 10 gallons. Null when the label names no rate, or one in a unit the
	 * medication's does not convert to.
	 *
	 * @param string $label      Step label.
	 * @param array  $medication Medication.
	 * @return float|null
	 */
	public static function step_rate( $label, array $medication ) {
		if ( 1 !== preg_match( '/(\d+(?:\.\d+)?)\s*(mg|g|ml|tsp|drops?|each)\s*(?:\/|per)\s*(\d+(?:\.\d+)?)?\s*gal(?:lons?)?(?![a-z])/i', (string) $label, $m ) ) {
			return null;
		}
		$unit   = strtolower( $m[2] );
		$unit   = 'drop' === $unit ? 'drops' : $unit;
		$amount = (float) $m[1];
		$for    = isset( $m[3] ) && '' !== $m[3] ? (float) $m[3] : 1.0;
		$mine   = self::convert( $amount, $unit, (string) ( $medication['unit'] ?? '' ) );
		if ( null === $mine || $mine <= 0 || $for <= 0 ) {
			return null;
		}
		$per = isset( $medication['per'] ) && (float) $medication['per'] > 0 ? (float) $medication['per'] : 1.0;
		return round( $mine / $for * $per, 4 );
	}

	/**
	 * The level a step label names: "Copper Power to 2.4" → 2.4. Null when
	 * it names none.
	 *
	 * @param string $label Step label.
	 * @return float|null
	 */
	public static function step_level( $label ) {
		if ( 1 !== preg_match( '/(?<![a-z])to\s+(\d+(?:\.\d+)?)(?![\d.])/i', (string) $label, $m ) ) {
			return null;
		}
		return self::level( $m[1] );
	}

	/**
	 * The dose steps due today for a list of stays (pure): what the to-do
	 * shows, without the ticks — label, kind and day per live group.
	 *
	 * @param array[] $stays Open stays (qty_live, day or checkin_date).
	 * @param array[] $steps The schedule's steps.
	 * @param string  $today Site date.
	 * @param string  $area  The tanks' area.
	 * @return array[] label, kind, day.
	 */
	public static function due_items( array $stays, array $steps, $today, $area = '' ) {
		$out = array();
		foreach ( $stays as $stay ) {
			if ( (int) ( $stay['qty_live'] ?? 0 ) <= 0 ) {
				continue;
			}
			$day = isset( $stay['day'] ) && null !== $stay['day'] ? (int) $stay['day'] : Replay::day_number( (string) ( $stay['checkin_date'] ?? '' ), (string) $today );
			if ( null === $day ) {
				continue;
			}
			foreach ( Protocol::due( $steps, $day, $area ) as $step ) {
				if ( 'dose' !== (string) ( $step['kind'] ?? '' ) ) {
					continue;
				}
				$out[] = array(
					'label' => (string) $step['label'],
					'kind'  => 'dose',
					'day'   => $day,
				);
			}
		}
		return $out;
	}

	/**
	 * What the due dose steps say about one medication (pure): the rates
	 * (per gallon) or the levels (to a level) of the steps that name it,
	 * each once, the lowest first.
	 *
	 * @param array   $medication Medication.
	 * @param array[] $items      due_items() (or a to-do's items): label, kind, day.
	 * @param array   $list       key => medication (to tell which medication a label names).
	 * @return array[] value (float), label (the step), day (int|null).
	 */
	public static function due_options( array $medication, array $items, array $list ) {
		$mode = (string) ( $medication['mode'] ?? 'typed' );
		if ( 'typed' === $mode ) {
			return array();
		}
		$found = array();
		foreach ( $items as $item ) {
			$label = (string) ( $item['label'] ?? '' );
			if ( 'dose' !== (string) ( $item['kind'] ?? '' ) || Medications::key_for( $label, $list ) !== (string) $medication['key'] ) {
				continue;
			}
			$value = 'to_level' === $mode ? self::step_level( $label ) : self::step_rate( $label, $medication );
			if ( null === $value ) {
				continue;
			}
			$id = self::num( $value, 4 );
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = array(
					'value' => (float) $value,
					'label' => $label,
					'day'   => isset( $item['day'] ) && null !== $item['day'] ? (int) $item['day'] : null,
				);
			}
		}
		$out = array_values( $found );
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['value'] <=> $b['value'];
			}
		);
		return $out;
	}

	/* ============================================================== */
	/*  The water: its gallons, today's reading, what is due            */
	/* ============================================================== */

	/**
	 * Today's latest copper reading among log rows (pure): the newest row
	 * of kind 'copper' with a value, logged on the site date; null when
	 * today has none (yesterday's does not count).
	 *
	 * @param array[] $rows  Tank log rows (kind, value, logged_at UTC).
	 * @param string  $today Site date.
	 * @return array{value: float, logged_at: string, at: string}|null at = the site time, "09:14".
	 */
	public static function reading_today( array $rows, $today ) {
		if ( ! Replay::is_date( $today ) ) {
			return null;
		}
		list( $start, $end ) = Store::site_day_bounds( $today );
		$best                = null;
		foreach ( $rows as $row ) {
			if ( 'copper' !== (string) ( $row['kind'] ?? '' ) || ! isset( $row['value'] ) || ! is_numeric( $row['value'] ) ) {
				continue;
			}
			$at = (string) ( $row['logged_at'] ?? '' );
			if ( strcmp( $at, $start ) < 0 || strcmp( $at, $end ) > 0 ) {
				continue;
			}
			if ( null === $best || strcmp( $at, $best['logged_at'] ) >= 0 ) {
				$best = array(
					'value'     => (float) $row['value'],
					'logged_at' => $at,
				);
			}
		}
		if ( null === $best ) {
			return null;
		}
		$best['at'] = self::site_clock( $best['logged_at'] );
		return $best;
	}

	/**
	 * A UTC datetime as the site's clock time, "09:14".
	 *
	 * @param string $utc Y-m-d H:i:s (UTC).
	 * @return string
	 */
	public static function site_clock( $utc ) {
		$ts = strtotime( (string) $utc . ' UTC' );
		if ( false === $ts ) {
			return '';
		}
		try {
			return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( Clean_Room::timezone() )->format( 'H:i' );
		} catch ( \Exception $e ) {
			return gmdate( 'H:i', $ts );
		}
	}

	/**
	 * Today's latest dose of each medication among log rows (pure) — so the
	 * sheet can say what went in already, before more is added.
	 *
	 * @param array[] $rows  Tank log rows (kind, note, logged_at UTC).
	 * @param string  $today Site date.
	 * @param array   $list  key => medication.
	 * @return array<string, array{text: string, logged_at: string, at: string}> By medication key; text =
	 *                       the dose as it was logged ("Copper Power 16.6 ml").
	 */
	public static function doses_today( array $rows, $today, array $list ) {
		if ( ! Replay::is_date( $today ) ) {
			return array();
		}
		list( $start, $end ) = Store::site_day_bounds( $today );
		$out                 = array();
		foreach ( $rows as $row ) {
			$at = (string) ( $row['logged_at'] ?? '' );
			if ( 'dose' !== (string) ( $row['kind'] ?? '' ) || strcmp( $at, $start ) < 0 || strcmp( $at, $end ) > 0 ) {
				continue;
			}
			$note = trim( Import::strip_marker( (string) ( $row['note'] ?? '' ) ) );
			$key  = Medications::key_for( $note, $list );
			if ( null === $key || ( isset( $out[ $key ] ) && strcmp( $at, $out[ $key ]['logged_at'] ) < 0 ) ) {
				continue;
			}
			$out[ $key ] = array(
				'text'      => explode( ' · ', $note, 2 )[0],
				'logged_at' => $at,
				'at'        => self::site_clock( $at ),
			);
		}
		return $out;
	}

	/**
	 * The two waters a dose on a tank's page can go into — the building's
	 * whole system and this tank alone — each with its gallons, today's
	 * latest copper reading for it, the doses logged in it today and the
	 * dose steps due today in it.
	 *
	 * A reading on any tank of the building (or on the building itself)
	 * reads the system's water; a tank's own water is read by its own
	 * readings and the building's.
	 *
	 * @param object     $store     Store (or the tests' stand-in): building(), tanks(), logs_on_date(), open_stays().
	 * @param array      $tank      The tank row (with its building).
	 * @param string     $today     Site date.
	 * @param array      $settings  Settings.
	 * @param array|null $protocols Settings::protocols() (null = read them).
	 * @return array{system: array, tank: array, building_id: int} Each water: gallons (float), reading
	 *               (array|null), doses (doses_today()), items (array[]).
	 */
	public static function waters( $store, array $tank, $today, array $settings, $protocols = null ) {
		$tank_id     = (int) ( $tank['id'] ?? 0 );
		$building_id = (int) ( $tank['building']['id'] ?? $tank['building_id'] ?? 0 );
		$area        = (string) ( $tank['building']['area'] ?? '' );
		$building    = $building_id > 0 ? $store->building( $building_id ) : null;
		$tanks       = (array) $store->tanks();
		$logs        = (array) $store->logs_on_date( $today );

		$system_rows = array();
		foreach ( $tanks as $other ) {
			if ( (int) ( $other['building_id'] ?? 0 ) === $building_id && isset( $logs[ (int) $other['id'] ] ) ) {
				$system_rows = array_merge( $system_rows, (array) $logs[ (int) $other['id'] ] );
			}
		}

		$system_stays = array();
		$tank_stays   = array();
		foreach ( (array) $store->open_stays( array( 'building_id' => $building_id ), $today, $settings ) as $stay ) {
			if ( (int) ( $stay['building_id'] ?? 0 ) !== $building_id ) {
				continue; // A stand-in store may not filter by building.
			}
			$system_stays[] = $stay;
			if ( (int) ( $stay['tank_id'] ?? 0 ) === $tank_id ) {
				$tank_stays[] = $stay;
			}
		}
		$steps     = Protocol::steps_for( is_array( $building ) ? $building : array(), $protocols );
		$list      = Medications::from_settings( $settings );
		$tank_rows = isset( $logs[ $tank_id ] ) ? (array) $logs[ $tank_id ] : array();

		return array(
			'building_id' => $building_id,
			'system'      => array(
				'gallons' => (float) Layout::system_gallons( is_array( $building ) ? $building : (array) ( $tank['building'] ?? array() ), $tanks ),
				'reading' => self::reading_today( $system_rows, $today ),
				'doses'   => self::doses_today( $system_rows, $today, $list ),
				'items'   => self::due_items( $system_stays, $steps, $today, $area ),
			),
			'tank'        => array(
				'gallons' => (float) max( 0, (int) ( $tank['gallons'] ?? 0 ) ),
				'reading' => self::reading_today( $tank_rows, $today ),
				'doses'   => self::doses_today( $tank_rows, $today, $list ),
				'items'   => self::due_items( $tank_stays, $steps, $today, $area ),
			),
		);
	}

	/**
	 * What the sheet starts with for one medication in one water (pure):
	 *   now     the copper product only — today's latest reading for that
	 *           water, with its time; nothing from another day;
	 *   target  the level a due step names ("Copper Power to 2.4"; the
	 *           lowest when two groups in one water are on different
	 *           days), else for the copper product the copper target of
	 *           the settings, else nothing;
	 *   rate    the rate a due step names ("Chloroquine 40 mg/gal"; the
	 *           lowest of several), else the medication's own;
	 *   dosed   the medication's latest dose logged in that water today
	 *           (text, at), and whether it went in AFTER today's reading
	 *           (after) — then "Now" is no longer what the water holds,
	 *           and the sheet says to read again before adding more.
	 *
	 * @param array $medication Medication.
	 * @param array $water      One of waters(): reading, doses, items.
	 * @param array $settings   Settings (copper_target).
	 * @param array $list       key => medication.
	 * @return array{now: float|null, now_at: string, target: float|null, target_from: string, rate: float|null, rate_from: string, options: array[], dosed: array|null}
	 */
	public static function defaults( array $medication, array $water, array $settings, array $list ) {
		$out  = array(
			'now'         => null,
			'now_at'      => '',
			'target'      => null,
			'target_from' => '',
			'rate'        => null,
			'rate_from'   => '',
			'options'     => array(),
			'dosed'       => null,
		);
		$mode = (string) ( $medication['mode'] ?? 'typed' );
		$key  = (string) ( $medication['key'] ?? '' );
		if ( isset( $water['doses'][ $key ] ) && is_array( $water['doses'][ $key ] ) ) {
			$dosed        = $water['doses'][ $key ];
			$out['dosed'] = array(
				'text'  => (string) $dosed['text'],
				'at'    => (string) $dosed['at'],
				'after' => Medications::is_copper( $medication ) && ! empty( $water['reading'] ) && strcmp( (string) $dosed['logged_at'], (string) $water['reading']['logged_at'] ) >= 0,
			);
		}
		if ( 'typed' === $mode ) {
			return $out;
		}
		$out['options'] = self::due_options( $medication, isset( $water['items'] ) && is_array( $water['items'] ) ? $water['items'] : array(), $list );

		if ( 'to_level' === $mode ) {
			$copper = Medications::is_copper( $medication );
			if ( $copper && ! empty( $water['reading'] ) && is_array( $water['reading'] ) ) {
				$out['now']    = (float) $water['reading']['value'];
				$out['now_at'] = (string) ( $water['reading']['at'] ?? '' );
			}
			if ( $out['options'] ) {
				$out['target']      = (float) $out['options'][0]['value'];
				$out['target_from'] = 'schedule';
			} elseif ( $copper && isset( $settings['copper_target'] ) && is_numeric( $settings['copper_target'] ) ) {
				$out['target']      = (float) $settings['copper_target'];
				$out['target_from'] = 'settings';
			}
			return $out;
		}

		$out['rate'] = (float) ( $medication['rate'] ?? 0 );
		if ( $out['options'] ) {
			$out['rate']      = (float) $out['options'][0]['value'];
			$out['rate_from'] = 'schedule';
		}
		return $out;
	}

	/**
	 * The two waters as the phone's sheet reads them: per water its
	 * gallons, today's reading and, per medication, defaults().
	 *
	 * @param array $medications The medications offered (key => medication).
	 * @param array $waters      waters().
	 * @param array $settings    Settings.
	 * @param array $list        key => medication (every one, retired too).
	 * @return array{system: array, tank: array}
	 */
	public static function sheet_data( array $medications, array $waters, array $settings, array $list ) {
		$out = array();
		foreach ( array( 'system', 'tank' ) as $scope ) {
			$water = isset( $waters[ $scope ] ) && is_array( $waters[ $scope ] ) ? $waters[ $scope ] : array();
			$meds  = array();
			foreach ( $medications as $medication ) {
				$d                                  = self::defaults( $medication, $water, $settings, $list );
				$meds[ (string) $medication['key'] ] = array(
					'now'        => $d['now'],
					'nowAt'      => $d['now_at'],
					'target'     => $d['target'],
					'targetFrom' => $d['target_from'],
					'rate'       => $d['rate'],
					'rateFrom'   => $d['rate_from'],
					'options'    => $d['options'],
					'dosed'      => $d['dosed'],
				);
			}
			$out[ $scope ] = array(
				'gallons' => (float) ( $water['gallons'] ?? 0 ),
				'reading' => ! empty( $water['reading'] ) ? array(
					'value' => (float) $water['reading']['value'],
					'at'    => (string) $water['reading']['at'],
				) : null,
				'meds'    => $meds,
			);
		}
		return $out;
	}

	/* ============================================================== */
	/*  Saving                                                         */
	/* ============================================================== */

	/**
	 * What a Save posts besides the amount, read back into the sum's
	 * inputs (pure): `calc` says the sheet's sum was used — then, per
	 * gallon, `rate` (and `dose_label`, kept only when it is one of the
	 * medication's named doses at that rate), and to a level `now` and
	 * `target`; `topup` with `changed` and `changed_unit` (pct | gal) says
	 * it was after a water change. The gallons never come from the post.
	 *
	 * @param array $medication Medication.
	 * @param array $raw        The unslashed post.
	 * @param float $gallons    The water's gallons, as the store has them.
	 * @return array plan() args, plus calc (bool).
	 */
	public static function args_from_request( array $medication, array $raw, $gallons ) {
		$flag = static function ( $key ) use ( $raw ) {
			return ! empty( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '0' !== (string) $raw[ $key ];
		};
		$args = array(
			'calc'         => $flag( 'calc' ),
			'gallons'      => (float) $gallons,
			'topup'        => $flag( 'topup' ),
			'changed'      => isset( $raw['changed'] ) && is_scalar( $raw['changed'] ) && is_numeric( $raw['changed'] ) ? (float) $raw['changed'] : null,
			'changed_unit' => isset( $raw['changed_unit'] ) && 'gal' === (string) $raw['changed_unit'] ? 'gal' : 'pct',
		);
		if ( ! $args['calc'] ) {
			return $args;
		}

		if ( 'to_level' === (string) $medication['mode'] ) {
			$args['now']    = self::level( $raw['now'] ?? null );
			$args['target'] = self::level( $raw['target'] ?? null );
			return $args;
		}

		if ( 'per_gallon' === (string) $medication['mode'] ) {
			$rate = Medications::number( $raw['rate'] ?? null, Medications::MAX_RATE );
			if ( null !== $rate ) {
				$args['rate'] = $rate;
				$typed        = isset( $raw['dose_label'] ) && is_scalar( $raw['dose_label'] ) ? strtolower( trim( (string) $raw['dose_label'] ) ) : '';
				foreach ( '' !== $typed ? (array) $medication['doses'] : array() as $dose ) {
					if ( strtolower( (string) $dose['label'] ) === $typed && abs( (float) $dose['rate'] - $rate ) < 1e-6 ) {
						$args['rate_label'] = (string) $dose['label'];
						break;
					}
				}
			}
		}
		return $args;
	}

	/**
	 * The working a dose log carries as its note (pure): the sum's note
	 * when the sheet's sum was used, else only the top-up remark, else ''.
	 *
	 * @param array $medication Medication.
	 * @param array $args       args_from_request().
	 * @return string
	 */
	public static function working_note( array $medication, array $args ) {
		$plan = self::plan( $medication, $args );
		if ( ! empty( $args['calc'] ) ) {
			return (string) $plan['note'];
		}
		if ( ! empty( $args['topup'] ) ) {
			$only = self::plan( array_merge( $medication, array( 'mode' => 'typed' ) ), $args );
			return (string) $only['note'];
		}
		return '';
	}

	/**
	 * The copper reading a dose of the copper product brings along: the
	 * "Now" value, logged on the water that was dosed (the building for the
	 * whole system, else the tank) — unless it is today's latest reading
	 * for that water already.
	 *
	 * @param object     $store  Store (or stand-in): log_tank(), log_building().
	 * @param array      $tank   The tank row.
	 * @param bool       $whole  The whole system.
	 * @param float      $now    The reading.
	 * @param array|null $latest Today's latest reading for that water (waters()), or null.
	 * @param array      $base   actor_id, source.
	 * @return string logged | already | failed.
	 */
	public static function save_reading( $store, array $tank, $whole, $now, $latest, array $base ) {
		$now = round( (float) $now, 3 );
		if ( is_array( $latest ) && isset( $latest['value'] ) && abs( (float) $latest['value'] - $now ) < 0.0005 ) {
			return 'already';
		}
		$log = array_merge(
			$base,
			array(
				'kind'  => 'copper',
				'value' => $now,
			)
		);
		$id  = $whole
			? $store->log_building( (int) ( $tank['building']['id'] ?? $tank['building_id'] ?? 0 ), $log )
			: $store->log_tank( (int) $tank['id'], $log );
		return $id ? 'logged' : 'failed';
	}

	/**
	 * "Building 500, whole system" / "Tank 521".
	 *
	 * @param array $tank  The tank row (with its building).
	 * @param bool  $whole The whole system.
	 * @return string
	 */
	public static function place( array $tank, $whole ) {
		return $whole
			/* translators: %s: building label */
			? sprintf( __( '%s, whole system', 'fishotel-misc-plugin' ), Layout::building_label( (array) ( $tank['building'] ?? array() ) ) )
			/* translators: %d: tank number */
			: sprintf( __( 'Tank %d', 'fishotel-misc-plugin' ), (int) ( $tank['number'] ?? 0 ) );
	}

	/* ============================================================== */
	/*  A dose phrase's preview                                        */
	/* ============================================================== */

	/**
	 * A parsed dose phrase (Parser, intent 'dose') made ready to confirm:
	 * the water (the spoken one, else the sheet's default — the whole
	 * system when its gallons are known), what was not spoken filled in
	 * as the sheet would (today's reading as "Now", the due step's level
	 * or rate), and the sum. A dose spoken with its amount gets no sum:
	 * that amount is what is logged.
	 *
	 * Adds to $preview['dose']: whole, place, gallons, now, now_at, target,
	 * rate, rate_label, dosed (the medication's latest dose in that water
	 * today, see defaults()), plan (plan(), or state 'spoken'); asks for
	 * 'now' / 'target' in needs when a level is still missing.
	 *
	 * @param array      $preview   Parser::parse() with intent 'dose'.
	 * @param object     $store     Store (or stand-in).
	 * @param array|null $tank      The tank the dose is for (null = not known yet).
	 * @param string     $today     Site date.
	 * @param array      $settings  Settings.
	 * @param array|null $protocols Settings::protocols() (null = read them).
	 * @return array The preview.
	 */
	public static function enrich( array $preview, $store, $tank, $today, array $settings, $protocols = null ) {
		$dose       = isset( $preview['dose'] ) && is_array( $preview['dose'] ) ? $preview['dose'] : array();
		$list       = Medications::from_settings( $settings );
		$medication = Medications::get( (string) ( $dose['medication'] ?? '' ), $list );
		if ( ! $medication || ! empty( $medication['retired'] ) || ! is_array( $tank ) ) {
			return $preview;
		}

		$waters = self::waters( $store, $tank, $today, $settings, $protocols );
		$scope  = (string) ( $dose['scope'] ?? '' );
		$whole  = 'system' === $scope || ( 'tank' !== $scope && $waters['system']['gallons'] > 0 );
		if ( $whole && $waters['building_id'] <= 0 ) {
			$whole = false;
		}
		$water    = $waters[ $whole ? 'system' : 'tank' ];
		$defaults = self::defaults( $medication, $water, $settings, $list );

		$dose['whole']      = $whole;
		$dose['place']      = self::place( $tank, $whole );
		$dose['dosed']      = $defaults['dosed'];
		$dose['gallons']    = (float) $water['gallons'];
		$dose['now_at']     = '';
		$dose['rate']       = null;
		$dose['rate_label'] = '';

		if ( isset( $dose['amount'] ) && null !== $dose['amount'] ) {
			$dose['plan'] = array(
				'state'   => 'spoken',
				'amount'  => (float) $dose['amount'],
				'unit'    => (string) $dose['unit'],
				'line'    => Protocol::dose_text( $medication['key'], (float) $dose['amount'], (string) $dose['unit'] ),
				'working' => '',
				'note'    => '',
				'measure' => '',
			);
		} else {
			$args = array( 'gallons' => $water['gallons'] );
			if ( 'to_level' === $medication['mode'] ) {
				if ( ! isset( $dose['now'] ) || null === $dose['now'] ) {
					$dose['now']    = $defaults['now'];
					$dose['now_at'] = $defaults['now_at'];
				}
				if ( ! isset( $dose['target'] ) || null === $dose['target'] ) {
					$dose['target'] = $defaults['target'];
				}
				$args['now']    = $dose['now'];
				$args['target'] = $dose['target'];
			} elseif ( 'per_gallon' === $medication['mode'] ) {
				$dose['rate'] = $defaults['rate'];
				$spoken       = strtolower( (string) ( $dose['dose_label'] ?? '' ) );
				foreach ( '' !== $spoken ? (array) $medication['doses'] : array() as $named ) {
					if ( strtolower( (string) $named['label'] ) === $spoken ) {
						$dose['rate']       = (float) $named['rate'];
						$dose['rate_label'] = (string) $named['label'];
						break;
					}
				}
				$args['rate']       = $dose['rate'];
				$args['rate_label'] = $dose['rate_label'];
			}
			$dose['plan'] = self::plan( $medication, $args );
		}

		$needs = isset( $preview['needs'] ) && is_array( $preview['needs'] ) ? $preview['needs'] : array();
		if ( 'needs_now' === $dose['plan']['state'] ) {
			$needs[] = 'now';
		} elseif ( 'needs_target' === $dose['plan']['state'] ) {
			$needs[] = 'target';
		}

		$preview['dose']  = $dose;
		$preview['needs'] = array_values( array_unique( $needs ) );
		if ( in_array( $dose['plan']['state'], array( 'ok', 'spoken' ), true ) ) {
			$preview['summary'] = $dose['plan']['line'] . ' · ' . $dose['place'];
		}

		return $preview;
	}

	/* ============================================================== */
	/*  Afterwards: the Board's "last dose"                            */
	/* ============================================================== */

	/**
	 * A dose log's note as the Board prints it (pure): the dose, then the
	 * working in brackets — "Copper Power 16.6 ml (2.16 → 2.40 ppm, 100
	 * gal)"; an older or imported note as it is; an import marker never.
	 *
	 * @param array $dose One of Store::latest_doses(): label, note.
	 * @return string
	 */
	public static function last_dose_text( array $dose ) {
		$note = trim( Import::strip_marker( (string) ( $dose['note'] ?? '' ) ) );
		if ( '' === $note ) {
			return (string) ( $dose['label'] ?? '' );
		}
		$parts = explode( ' · ', $note, 2 );
		$text  = $parts[0] . ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ? ' (' . trim( $parts[1] ) . ')' : '' );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 120 ) {
			$text = mb_substr( $text, 0, 119, 'UTF-8' ) . '…';
		}
		return $text;
	}

	/**
	 * Store::latest_doses() with each entry's label made the text the
	 * Board prints for it (last_dose_text()) — the view prints the label
	 * and the time.
	 *
	 * @param array $doses building id => medication key => { label, note, logged_at }.
	 * @return array The same shape.
	 */
	public static function board_doses( array $doses ) {
		foreach ( $doses as $building => $by_medication ) {
			if ( ! is_array( $by_medication ) ) {
				continue;
			}
			foreach ( $by_medication as $key => $dose ) {
				if ( is_array( $dose ) ) {
					$doses[ $building ][ $key ]['label'] = self::last_dose_text( $dose );
				}
			}
		}
		return $doses;
	}
}
