<?php
/**
 * Clean Room parser — PURE: one spoken or typed phrase → a structured
 * preview. No WordPress calls; it is handed the candidate species list,
 * the tank list and the tank the page is on.
 *
 *   "four pajama cardinalfish in tank 121"   → arrival, 4, species, tank 121
 *   "move two clowns to two twenty one"      → move, 2, occupant, to 221
 *   "lost one clown doa"                     → loss, 1, occupant, reason doa
 *   "clear the cardinals"                    → clear, occupant
 *   "note water change done"                 → note
 *   "count five clowns"                      → count, 5
 *
 * Number words become integers ('four', 'a couple' = 2, 'a dozen' = 12,
 * digits). Tank references: 'tank 121', 'one twenty one', 'five
 * twenty-one', 'tank five two one', 'building 100 top left'. Species are
 * matched fuzzily (case-insensitive token overlap, Levenshtein ≤ 2 per
 * token, size words ignored, aliases first, then product titles, then
 * common names) with a confidence; an ambiguous or weak match asks
 * "Which fish?" with the top three. The tank is the page's when none is
 * spoken.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Parser
 */
class Parser {

	const INTENTS = array( 'arrival', 'move', 'loss', 'sold', 'clear', 'count', 'copper', 'note' );

	/**
	 * Verb families. Order = precedence (note is checked by position,
	 * copper by its own words).
	 *
	 * @var array<string, string[]>
	 */
	const VERBS = array(
		'count'   => array( 'count', 'counted', 'headcount', 'recount' ),
		'copper'  => array( 'copper', 'cu', 'cupramine', 'coppersafe' ),
		'loss'    => array( 'lost', 'died', 'dead', 'doa', 'loss', 'losses', 'death', 'deaths', 'passed' ),
		'sold'    => array( 'sold', 'shipped', 'ship', 'shipping', 'packed', 'sent' ),
		'clear'   => array( 'clear', 'cleared', 'graduate', 'graduated', 'graduates', 'done', 'ready' ),
		'move'    => array( 'move', 'moving', 'moved', 'transfer', 'transferred', 'relocate' ),
		'arrival' => array( 'arrived', 'arriving', 'arrival', 'arrivals', 'add', 'adding', 'added', 'put', 'landed', 'new', 'received', 'in', 'into' ),
	);

	/**
	 * The area every arrival must land in.
	 *
	 * @var string
	 */
	const ARRIVAL_AREA = 'spa';

	/**
	 * Arrival verbs that stop 'to tank' from meaning a move.
	 *
	 * @var string[]
	 */
	const STRONG_ARRIVAL = array( 'arrived', 'arriving', 'arrival', 'arrivals', 'add', 'adding', 'added', 'put', 'landed', 'new', 'received' );

	const NUMBER_WORDS = array(
		'zero'      => 0,
		'one'       => 1,
		'two'       => 2,
		'three'     => 3,
		'four'      => 4,
		'five'      => 5,
		'six'       => 6,
		'seven'     => 7,
		'eight'     => 8,
		'nine'      => 9,
		'ten'       => 10,
		'eleven'    => 11,
		'twelve'    => 12,
		'thirteen'  => 13,
		'fourteen'  => 14,
		'fifteen'   => 15,
		'sixteen'   => 16,
		'seventeen' => 17,
		'eighteen'  => 18,
		'nineteen'  => 19,
		'twenty'    => 20,
		'thirty'    => 30,
		'forty'     => 40,
		'fifty'     => 50,
		'sixty'     => 60,
		'seventy'   => 70,
		'eighty'    => 80,
		'ninety'    => 90,
		'hundred'   => 100,
	);

	const SIZE_WORDS = array( 'small', 'medium', 'med', 'large', 'lg', 'sm', 'md', 'xl', 'xs', 'tiny', 'big', 'little', 'juvenile', 'juv', 'adult', 'baby', 'jumbo', 'mini' );

	const FILLER_WORDS = array( 'the', 'a', 'an', 'some', 'of', 'x', 'times', 'please', 'in', 'into', 'to', 'from', 'at', 'on', 'and', 'with', 'for', 'them', 'these', 'those', 'it', 'this', 'that', 'are', 'is', 'were', 'was', 'there', 'i', 'we', 'just', 'got', 'have', 'has', 'had', 'been', 'up', 'down', 'over', 'here', 'now', 'today', 'tank', 'tanks', 'building', 'more', 'also', 'my', 'our', 'so', 'then', 'okay', 'ok', 'um', 'uh', 'all', 'everything', 'everyone', 'everybody', 'each', 'every', 'fishes', 'row', 'shelf', 'tier' );

	/**
	 * Catalogue words that say nothing about which fish it is ("Ocellaris
	 * Clownfish — Tank Raised"), dropped from both sides of a match.
	 *
	 * @var string[]
	 */
	const CATALOGUE_NOISE = array( 'tank', 'raised', 'bred', 'captive', 'wild', 'caught', 'pair', 'pr', 'each', 'ea', 'grade', 'select', 'premium', 'ultra' );

	const LEVEL_WORDS = array(
		'top'    => 2,
		'upper'  => 2,
		'high'   => 2,
		'bottom' => 1,
		'lower'  => 1,
		'low'    => 1,
	);

	const POSITION_WORDS = array( 'left', 'right', 'middle', 'center', 'centre', 'first', 'second', 'third', 'fourth', 'fifth', 'last' );

	const REASONS = array(
		'doa'     => array( 'doa', 'arrival' ),
		'disease' => array( 'disease', 'diseased', 'sick', 'ich', 'ick', 'velvet', 'brook', 'brooklynella', 'uronema', 'infection', 'fungus', 'parasite', 'parasites', 'flukes' ),
		'jumped'  => array( 'jumped', 'jump', 'jumper', 'carpet', 'floor', 'carpeted' ),
		'unknown' => array( 'unknown', 'unsure', 'mystery' ),
	);

	const KIND_WEIGHTS = array(
		'alias'  => 1.0,
		'title'  => 0.98,
		'common' => 0.96,
		'sci'    => 0.94,
		'stay'   => 0.98,
	);

	/**
	 * At most this many spoken words are matched against the catalogue.
	 *
	 * @var int
	 */
	const MAX_SPECIES_WORDS = 12;

	/* ============================================================== */
	/*  Entry point                                                    */
	/* ============================================================== */

	/**
	 * Parse one phrase.
	 *
	 * @param string $text    The transcript or typed text.
	 * @param array  $context tank (int|null, the page's tank number),
	 *                        tanks (array[] number, building, level, position, status),
	 *                        species (array[] product_id, name, variants[] {text, kind}),
	 *                        occupants (array[] stay_id, species, product_id, qty_live).
	 * @return array See the class doc.
	 */
	public static function parse( $text, array $context = array() ) {
		$original = trim( (string) $text );
		$tokens   = self::tokenize( $original );
		$tanks    = isset( $context['tanks'] ) && is_array( $context['tanks'] ) ? array_values( $context['tanks'] ) : array();
		$page     = isset( $context['tank'] ) && is_numeric( $context['tank'] ) ? (int) $context['tank'] : null;
		$species  = isset( $context['species'] ) && is_array( $context['species'] ) ? array_values( $context['species'] ) : array();
		$occupants = isset( $context['occupants'] ) && is_array( $context['occupants'] ) ? array_values( $context['occupants'] ) : array();

		$result = array(
			'intent'      => 'arrival',
			'assumed'     => false,
			'qty'         => null,
			'value'       => null,
			'tank'        => $page,
			'tank_spoken' => false,
			'tank_known'  => null !== $page,
			'tank_area'   => self::tank_area( $page, $tanks ),
			'to_tank'     => null,
			'from_tank'   => null,
			'all'         => false,
			'reason'      => null,
			'note'        => '',
			'species'     => array(
				'name'       => '',
				'product_id' => null,
				'stay_id'    => null,
				'confidence' => 0.0,
				'text'       => '',
			),
			'candidates'  => array(),
			'needs'       => array(),
			'summary'     => '',
			'text'        => $original,
		);

		if ( ! $tokens ) {
			$result['intent'] = 'unknown';
			$result['needs'][] = 'text';
			$result['summary'] = 'Say what happened, for example "lost one cardinal in 521".';
			return $result;
		}

		// 1. Intent.
		$intent = self::intent( $tokens );
		if ( null === $intent ) {
			$intent            = 'arrival';
			$result['assumed'] = true;
		}
		$result['intent'] = $intent;

		if ( 'note' === $intent ) {
			$result['note'] = self::note_text( $original );
		}

		// 2. Tank references and quantities.
		$consumed = array_fill( 0, count( $tokens ), false );
		$refs     = self::scan_references( $tokens, $consumed, $tanks, $page, $intent );

		$result['from_tank'] = $refs['from'];
		if ( null !== $refs['tank'] ) {
			$result['tank']        = $refs['tank'];
			$result['tank_spoken'] = true;
			$result['tank_known']  = self::tank_known( $refs['tank'], $tanks );
		} elseif ( null !== $refs['from'] ) {
			$result['tank']        = $refs['from'];
			$result['tank_spoken'] = true;
			$result['tank_known']  = self::tank_known( $refs['from'], $tanks );
		}
		$result['to_tank'] = $refs['to'];

		if ( 'move' === $intent ) {
			if ( null === $result['to_tank'] && null !== $refs['tank'] && $refs['tank'] !== $page && null !== $page ) {
				// "move the clowns 221" on tank 121's page: the spoken tank is the destination.
				$result['to_tank']     = $refs['tank'];
				$result['tank']        = $page;
				$result['tank_spoken'] = false;
				$result['tank_known']  = true;
			}
			if ( null !== $result['to_tank'] && ! self::tank_known( $result['to_tank'], $tanks ) ) {
				$result['needs'][] = 'to_tank';
			} elseif ( null === $result['to_tank'] ) {
				$result['needs'][] = 'to_tank';
			}
		}

		$result['tank_area'] = self::tank_area( $result['tank'], $tanks );

		if ( null === $result['tank'] ) {
			$result['needs'][] = 'tank';
		} elseif ( ! $result['tank_known'] ) {
			$result['needs'][] = 'tank';
		} elseif ( 'arrival' === $intent && $tanks && self::ARRIVAL_AREA !== $result['tank_area'] ) {
			// Every arrival lands in a Spa tank: a Clean Room tank is never
			// a check-in tank, so ask for one.
			$result['needs'][] = 'tank';
		}

		$result['qty'] = $refs['qty'];

		if ( 'copper' === $intent ) {
			$value = self::decimal_value( $tokens, $consumed );
			if ( null === $value && null !== $refs['qty'] ) {
				$value = (float) $refs['qty'];
			}
			$result['value'] = $value;
			$result['qty']   = null;
			if ( null === $value ) {
				$result['needs'][] = 'value';
			}
		}

		// 3. Loss reason, 'all'.
		$reason_tokens = array();
		if ( 'loss' === $intent ) {
			$result['reason'] = self::reason( $tokens, $reason_tokens );
		}
		$result['all'] = self::wants_all( $tokens );

		// 4. Species words.
		$words = array();
		foreach ( $tokens as $i => $token ) {
			if ( $consumed[ $i ] || in_array( $i, $reason_tokens, true ) ) {
				continue;
			}
			if ( ctype_digit( $token ) || self::is_decimal_token( $token ) || 'point' === $token ) {
				continue; // Digits are never part of a name; number WORDS may be ("six line wrasse").
			}
			if ( in_array( $token, self::FILLER_WORDS, true ) || in_array( $token, self::SIZE_WORDS, true ) ) {
				continue;
			}
			if ( self::is_verb( $token ) ) {
				continue;
			}
			if ( isset( self::LEVEL_WORDS[ $token ] ) || in_array( $token, self::POSITION_WORDS, true ) ) {
				continue;
			}
			if ( 'note' === $intent && 0 === $i ) {
				continue;
			}
			$words[] = $token;
		}
		if ( 'note' === $intent ) {
			$words = array(); // The note is free text and goes on the tank's log.
		}
		// A fish name is a few words; matching is spoken words × catalogue
		// variants, so a run-on transcript against a 2000-product catalogue
		// must not be allowed to take seconds.
		$words                     = array_slice( $words, 0, self::MAX_SPECIES_WORDS );
		$result['species']['text'] = implode( ' ', $words );

		// 5. Species. A copper reading and a spoken note are per tank — no
		// fish involved (a note on one fish is typed on its own screen).
		$use_occupants = 'arrival' !== $intent && $occupants;
		$pool          = $use_occupants ? self::occupants_as_candidates( $occupants ) : $species;

		if ( 'copper' === $intent || 'note' === $intent ) {
			$result['species']['confidence'] = 1.0;
		} elseif ( ! $words ) {
			if ( $use_occupants && 1 === count( $occupants ) ) {
				$only                            = $occupants[0];
				$result['species']['name']       = (string) ( $only['species'] ?? '' );
				$result['species']['product_id'] = isset( $only['product_id'] ) && (int) $only['product_id'] > 0 ? (int) $only['product_id'] : null;
				$result['species']['stay_id']    = isset( $only['stay_id'] ) ? (int) $only['stay_id'] : null;
				$result['species']['confidence'] = 1.0;
			} elseif ( $use_occupants && $result['all'] ) {
				$result['species']['confidence'] = 1.0;
			} elseif ( $use_occupants ) {
				$result['needs'][]     = 'species';
				$result['candidates']  = array_slice( self::occupants_as_chips( $occupants ), 0, 3 );
			} else {
				$result['needs'][] = 'species';
			}
		} else {
			$scored = self::match_species( $words, $pool );
			$top    = $scored ? $scored[0] : null;
			$second = isset( $scored[1] ) ? $scored[1]['score'] : 0.0;

			$result['candidates'] = array_slice( array_map( array( self::class, 'chip' ), $scored ), 0, 3 );

			if ( $top ) {
				$confident = $use_occupants
					? ( $top['score'] >= 0.4 && ( 1 === count( $scored ) || $top['score'] - $second >= 0.15 ) )
					: ( $top['score'] >= 0.75 && $top['score'] - $second >= 0.12 );

				if ( $confident ) {
					$result['species']['name']       = $top['name'];
					$result['species']['product_id'] = $top['product_id'];
					$result['species']['stay_id']    = $top['stay_id'];
					$result['species']['confidence'] = round( $top['score'], 3 );
				} else {
					$result['species']['confidence'] = round( $top['score'], 3 );
					$result['needs'][]               = 'species';
				}
			} else {
				// Nothing to match against (or nothing matched): an unlisted fish.
				$result['species']['name']       = self::title_case( implode( ' ', $words ) );
				$result['species']['confidence'] = 0.0;
				$result['needs'][]               = 'species';
			}

			if ( ! $top && 'arrival' === $intent && ! $pool ) {
				// No catalogue at all: accept the typed words as an unlisted fish.
				$result['needs'] = array_values( array_diff( $result['needs'], array( 'species' ) ) );
			}
		}

		if ( 'count' === $intent && null === $result['qty'] ) {
			$result['needs'][] = 'qty';
		}

		$result['needs']   = array_values( array_unique( $result['needs'] ) );
		$result['summary'] = self::summary( $result );

		return $result;
	}

	/* ============================================================== */
	/*  Tokens                                                         */
	/* ============================================================== */

	/**
	 * Lower-case word tokens; hyphens split, punctuation dropped.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokenize( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		// A decimal point between digits stays ("2.4" copper); every other dot is a separator.
		$text = preg_replace( '/(?<!\d)\.|\.(?!\d)/', ' ', $text );
		$text = str_replace( array( '-', '–', '—', '×', '/', ',', ';', ':', '!', '?', '(', ')', '"', "'", '’' ), ' ', $text );
		$text = preg_replace( '/[^\p{L}\p{N}\s.]/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );

		if ( '' === $text ) {
			return array();
		}

		$tokens = explode( ' ', $text );

		// "4x" / "x4" → "4".
		$out = array();
		foreach ( $tokens as $token ) {
			if ( preg_match( '/^(\d+)x$/', $token, $m ) || preg_match( '/^x(\d+)$/', $token, $m ) ) {
				$out[] = $m[1];
				continue;
			}
			$out[] = $token;
		}

		return $out;
	}

	/**
	 * Whether a token is a digit string or a number word.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function is_number_token( $token ) {
		return ctype_digit( (string) $token ) || isset( self::NUMBER_WORDS[ (string) $token ] );
	}

	/**
	 * Whether a token is a decimal number ("2.4").
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function is_decimal_token( $token ) {
		return 1 === preg_match( '/^\d+\.\d+$/', (string) $token );
	}

	/**
	 * A reading: the first decimal token ("2.4"), or "<n> point <m>" in
	 * words or digits ("two point four", "2 point 4"), or null.
	 *
	 * @param string[] $tokens   Tokens.
	 * @param bool[]   $consumed By reference: marks the tokens used.
	 * @return float|null
	 */
	public static function decimal_value( array $tokens, array &$consumed ) {
		$n = count( $tokens );
		foreach ( $tokens as $i => $token ) {
			if ( self::is_decimal_token( $token ) ) {
				$consumed[ $i ] = true;
				return (float) $token;
			}
		}
		for ( $i = 1; $i + 1 < $n; $i++ ) {
			if ( 'point' !== $tokens[ $i ] ) {
				continue;
			}
			$whole = self::token_value( $tokens[ $i - 1 ] );
			$frac  = array();
			$j     = $i + 1;
			while ( $j < $n && self::is_number_token( $tokens[ $j ] ) && null !== self::token_value( $tokens[ $j ] ) && self::token_value( $tokens[ $j ] ) <= 9 ) {
				$frac[] = (string) self::token_value( $tokens[ $j ] );
				$j++;
			}
			if ( null === $whole || ! $frac ) {
				continue;
			}
			for ( $k = $i - 1; $k < $j; $k++ ) {
				$consumed[ $k ] = true;
			}
			return (float) ( $whole . '.' . implode( '', $frac ) );
		}
		return null;
	}

	/**
	 * The area of a tank number in the tank list ('' when unknown).
	 *
	 * @param int|null $number Tank number.
	 * @param array[]  $tanks  Tanks (number, area).
	 * @return string
	 */
	public static function tank_area( $number, array $tanks ) {
		if ( null === $number ) {
			return '';
		}
		foreach ( $tanks as $tank ) {
			if ( (int) ( $tank['number'] ?? 0 ) === (int) $number ) {
				return (string) ( $tank['area'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * The numeric value of one token.
	 *
	 * @param string $token Token.
	 * @return int|null
	 */
	public static function token_value( $token ) {
		if ( ctype_digit( (string) $token ) ) {
			return (int) $token;
		}
		return isset( self::NUMBER_WORDS[ (string) $token ] ) ? self::NUMBER_WORDS[ (string) $token ] : null;
	}

	/**
	 * Whether a token belongs to a verb family.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function is_verb( $token ) {
		foreach ( self::VERBS as $family ) {
			if ( in_array( $token, $family, true ) ) {
				return true;
			}
		}
		return in_array( $token, array( 'note', 'notes', 'memo' ), true );
	}

	/**
	 * The maximal run of number tokens starting at $i ('and' allowed after
	 * 'hundred'). Returns the token indexes.
	 *
	 * @param string[] $tokens Tokens.
	 * @param int      $i      Start.
	 * @return int[]
	 */
	public static function number_run( array $tokens, $i ) {
		$run = array();
		$n   = count( $tokens );
		while ( $i < $n ) {
			if ( self::is_number_token( $tokens[ $i ] ) ) {
				$run[] = $i;
				$i++;
				continue;
			}
			if ( 'and' === $tokens[ $i ] && $run && 'hundred' === $tokens[ $i - 1 ] && $i + 1 < $n && self::is_number_token( $tokens[ $i + 1 ] ) ) {
				$i++;
				continue;
			}
			break;
		}
		return $run;
	}

	/* ============================================================== */
	/*  Numbers                                                        */
	/* ============================================================== */

	/**
	 * A tank number from a run of number tokens:
	 *   "121" / "1 21"               → digit strings concatenated
	 *   "one twenty one"             → 1 ×100 + 21
	 *   "five twenty one"            → 521
	 *   "five two one"               → single digits concatenated
	 *   "one hundred and twenty one" → English hundreds
	 *   "one eleven"                 → 111
	 *
	 * @param string[] $words The tokens of the run.
	 * @return int|null
	 */
	public static function tank_value( array $words ) {
		$words = array_values( $words );
		if ( ! $words ) {
			return null;
		}

		$all_digits = true;
		foreach ( $words as $w ) {
			if ( ! ctype_digit( (string) $w ) ) {
				$all_digits = false;
				break;
			}
		}
		if ( $all_digits ) {
			$joined = implode( '', $words );
			$value  = (int) $joined;
			return ( 3 === strlen( $joined ) && $value >= 100 ) ? $value : null;
		}

		$values = array();
		foreach ( $words as $w ) {
			$v = self::token_value( $w );
			if ( null === $v ) {
				return null;
			}
			$values[] = $v;
		}

		if ( in_array( 100, $values, true ) ) {
			$total = self::english_value( $values );
			return ( null !== $total && $total >= 100 && $total <= 999 ) ? $total : null;
		}

		$n = count( $values );
		if ( 1 === $n ) {
			return ( $values[0] >= 100 && $values[0] <= 999 ) ? $values[0] : null;
		}

		$single = true;
		foreach ( $values as $v ) {
			if ( $v > 9 ) {
				$single = false;
				break;
			}
		}
		if ( $single && 3 === $n ) {
			return $values[0] * 100 + $values[1] * 10 + $values[2];
		}

		if ( $values[0] >= 1 && $values[0] <= 9 ) {
			$rest = self::english_value( array_slice( $values, 1 ) );
			if ( null !== $rest && $rest >= 0 && $rest <= 99 ) {
				return $values[0] * 100 + $rest;
			}
		}

		return null;
	}

	/**
	 * Plain English number value of a run of values: [20, 2] → 22,
	 * [1, 100, 20, 1] → 121, [4] → 4. Null for a run that is not a number.
	 *
	 * @param int[] $values Values.
	 * @return int|null
	 */
	public static function english_value( array $values ) {
		$values = array_values( $values );
		if ( ! $values ) {
			return null;
		}
		$total   = 0;
		$current = 0;
		$prev    = null;
		foreach ( $values as $v ) {
			if ( 100 === $v ) {
				$current = max( 1, $current ) * 100;
			} elseif ( $v >= 20 && 0 === $v % 10 ) {
				if ( null !== $prev && $prev < 100 ) {
					return null; // "four twenty" is not a number.
				}
				$current += $v;
			} else {
				if ( null !== $prev && $prev < 20 ) {
					return null; // "four six" is not a number.
				}
				$current += $v;
			}
			$prev = $v;
		}
		$total += $current;
		return $total;
	}

	/**
	 * The quantity a run means, taking only the tokens that form one
	 * number ("four six line wrasse" → 4, leaving "six" for the species).
	 *
	 * @param string[] $tokens Tokens.
	 * @param int[]    $run    Indexes of the run.
	 * @return array{value: int, used: int[]}|null
	 */
	public static function qty_from_run( array $tokens, array $run ) {
		if ( ! $run ) {
			return null;
		}
		$first = self::token_value( $tokens[ $run[0] ] );
		if ( null === $first ) {
			return null;
		}
		$used = array( $run[0] );

		if ( ctype_digit( (string) $tokens[ $run[0] ] ) ) {
			return array(
				'value' => $first,
				'used'  => $used,
			);
		}

		$value = $first;
		if ( isset( $run[1] ) ) {
			$second = self::token_value( $tokens[ $run[1] ] );
			if ( 100 === $second && $first >= 1 && $first <= 9 ) {
				$value  = $first * 100;
				$used[] = $run[1];
				if ( isset( $run[2] ) ) {
					$third = self::token_value( $tokens[ $run[2] ] );
					if ( null !== $third && $third < 100 ) {
						$value += $third;
						$used[] = $run[2];
						if ( $third >= 20 && 0 === $third % 10 && isset( $run[3] ) ) {
							$fourth = self::token_value( $tokens[ $run[3] ] );
							if ( null !== $fourth && $fourth >= 1 && $fourth <= 9 ) {
								$value += $fourth;
								$used[] = $run[3];
							}
						}
					}
				}
			} elseif ( $first >= 20 && 0 === $first % 10 && null !== $second && $second >= 1 && $second <= 9 ) {
				$value  = $first + $second;
				$used[] = $run[1];
			}
		}

		return array(
			'value' => $value,
			'used'  => $used,
		);
	}

	/* ============================================================== */
	/*  Intent                                                         */
	/* ============================================================== */

	/**
	 * The verb family a phrase belongs to, or null when none is spoken.
	 *
	 * @param string[] $tokens Tokens.
	 * @return string|null
	 */
	public static function intent( array $tokens ) {
		if ( ! $tokens ) {
			return null;
		}
		if ( in_array( $tokens[0], array( 'note', 'notes', 'memo' ), true ) ) {
			return 'note';
		}

		$phrase = ' ' . implode( ' ', $tokens ) . ' ';

		if ( self::has_any( $tokens, self::VERBS['count'] ) || false !== strpos( $phrase, ' there are ' ) || false !== strpos( $phrase, ' i count ' ) || false !== strpos( $phrase, ' head count ' ) ) {
			return 'count';
		}
		if ( self::has_any( $tokens, self::VERBS['copper'] ) ) {
			return 'copper';
		}
		if ( self::has_any( $tokens, self::VERBS['loss'] ) ) {
			return 'loss';
		}
		if ( self::has_any( $tokens, self::VERBS['sold'] ) ) {
			return 'sold';
		}
		if ( self::has_any( $tokens, self::VERBS['clear'] ) ) {
			return 'clear';
		}

		$strong_arrival = self::has_any( $tokens, self::STRONG_ARRIVAL );
		if ( self::has_any( $tokens, self::VERBS['move'] ) ) {
			return 'move';
		}
		if ( ! $strong_arrival && ( false !== strpos( $phrase, ' to tank ' ) || false !== strpos( $phrase, ' over to ' ) ) ) {
			return 'move';
		}
		if ( $strong_arrival || self::has_any( $tokens, array( 'in', 'into' ) ) ) {
			return 'arrival';
		}

		return null;
	}

	/**
	 * Whether any of the words is among the tokens.
	 *
	 * @param string[] $tokens Tokens.
	 * @param string[] $words  Words.
	 * @return bool
	 */
	private static function has_any( array $tokens, array $words ) {
		foreach ( $tokens as $token ) {
			if ( in_array( $token, $words, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The free text of a note phrase: everything after "note".
	 *
	 * @param string $original Original text.
	 * @return string
	 */
	public static function note_text( $original ) {
		$text = trim( (string) $original );
		$text = preg_replace( '/^\s*(note|notes|memo)\b\s*[:,\-–]?\s*/iu', '', $text );
		return trim( (string) $text );
	}

	/* ============================================================== */
	/*  Tank references                                                */
	/* ============================================================== */

	/**
	 * Find every tank reference and the quantity, marking the tokens used.
	 *
	 * @param string[] $tokens   Tokens.
	 * @param bool[]   $consumed By reference: tokens used by references / numbers.
	 * @param array[]  $tanks    Tank list.
	 * @param int|null $page     The page's tank number.
	 * @param string   $intent   Intent.
	 * @return array{tank: int|null, to: int|null, from: int|null, qty: int|null}
	 */
	public static function scan_references( array $tokens, array &$consumed, array $tanks, $page, $intent ) {
		$refs = array(
			'tank' => null,
			'to'   => null,
			'from' => null,
			'qty'  => null,
		);
		$n = count( $tokens );
		$i = 0;

		$assign = static function ( $role, $number ) use ( &$refs, $intent ) {
			if ( null === $number ) {
				return;
			}
			if ( 'to' === $role ) {
				if ( null === $refs['to'] ) {
					$refs['to'] = $number;
				}
				return;
			}
			if ( 'from' === $role ) {
				if ( null === $refs['from'] ) {
					$refs['from'] = $number;
				}
				return;
			}
			if ( null === $refs['tank'] ) {
				$refs['tank'] = $number;
			} elseif ( 'move' === $intent && null === $refs['to'] ) {
				$refs['to'] = $number;
			}
		};

		$role_of = static function ( $i ) use ( $tokens ) {
			$prev  = $i > 0 ? $tokens[ $i - 1 ] : '';
			$prev2 = $i > 1 ? $tokens[ $i - 2 ] : '';
			if ( 'the' === $prev ) {
				$prev  = $prev2;
				$prev2 = $i > 2 ? $tokens[ $i - 3 ] : '';
			}
			if ( in_array( $prev, array( 'to', 'into', 'over' ), true ) || ( 'to' === $prev && 'over' === $prev2 ) ) {
				return 'to';
			}
			if ( 'from' === $prev ) {
				return 'from';
			}
			return 'tank';
		};

		$mark = static function ( array $indexes ) use ( &$consumed ) {
			foreach ( $indexes as $index ) {
				$consumed[ $index ] = true;
			}
		};

		while ( $i < $n ) {
			$token = $tokens[ $i ];

			if ( $consumed[ $i ] ) {
				$i++;
				continue;
			}

			// "tank 121" / "tank one twenty one" / "tank five two one".
			if ( 'tank' === $token || 'tanks' === $token ) {
				$run = self::number_run( $tokens, $i + 1 );
				if ( $run ) {
					$number = self::tank_value( self::pick( $tokens, $run ) );
					$assign( $role_of( $i ), $number );
					$mark( array_merge( array( $i ), $run ) );
					$i = end( $run ) + 1;
					continue;
				}
				// "tank" + level/position words: "tank top left".
				$level = self::level_position( $tokens, $i + 1 );
				if ( $level ) {
					$resolved = self::resolve_position( $tanks, self::building_of( $page ), $level['level'], $level['position'] );
					$assign( $role_of( $i ), $resolved );
					$mark( array_merge( array( $i ), $level['used'] ) );
					$i = max( $level['used'] ) + 1;
					continue;
				}
				$i++;
				continue;
			}

			// "building 100 top left" / "building one top left".
			if ( 'building' === $token ) {
				$run      = self::number_run( $tokens, $i + 1 );
				$building = null;
				$used     = array( $i );
				if ( $run ) {
					$value = self::english_value( self::values( $tokens, $run ) );
					if ( null !== $value ) {
						$building = $value < 10 ? $value * 100 : $value;
					}
					$used = array_merge( $used, $run );
				}
				$next  = $run ? end( $run ) + 1 : $i + 1;
				$level = self::level_position( $tokens, $next );
				if ( $level ) {
					$resolved = self::resolve_position( $tanks, $building, $level['level'], $level['position'] );
					$assign( $role_of( $i ), null !== $resolved ? $resolved : -1 );
					$used = array_merge( $used, $level['used'] );
				} elseif ( null !== $building ) {
					$assign( $role_of( $i ), -1 ); // A building alone is not a tank.
				}
				$mark( $used );
				$i = max( $used ) + 1;
				continue;
			}

			// "top left" on its own: the page's building.
			if ( isset( self::LEVEL_WORDS[ $token ] ) ) {
				$level = self::level_position( $tokens, $i );
				if ( $level ) {
					$resolved = self::resolve_position( $tanks, self::building_of( $page ), $level['level'], $level['position'] );
					$assign( $role_of( $i ), null !== $resolved ? $resolved : -1 );
					$mark( $level['used'] );
					$i = max( $level['used'] ) + 1;
					continue;
				}
				$i++;
				continue;
			}

			// "to 221" / "into 121" / "in 121" / "from 121".
			if ( in_array( $token, array( 'to', 'into', 'in', 'from' ), true ) ) {
				$start = $i + 1;
				if ( $start < $n && 'the' === $tokens[ $start ] ) {
					$start++;
				}
				$run = self::number_run( $tokens, $start );
				if ( $run ) {
					$number = self::tank_value( self::pick( $tokens, $run ) );
					if ( null !== $number ) {
						// "from 121" → from; "to / into 221" is the destination of a
						// move and the tank of anything else ("add four clowns to 121").
						if ( 'from' === $token ) {
							$role = 'from';
						} elseif ( 'move' === $intent && ( 'to' === $token || 'into' === $token ) ) {
							$role = 'to';
						} else {
							$role = 'tank';
						}
						$assign( $role, $number );
						$mark( array_merge( array( $i ), $run ) );
						$i = end( $run ) + 1;
						continue;
					}
				}
				$i++;
				continue;
			}

			// A bare number run: a known tank number, else the quantity.
			if ( self::is_number_token( $token ) ) {
				$run    = self::number_run( $tokens, $i );
				$number = self::tank_value( self::pick( $tokens, $run ) );
				if ( null !== $number && self::tank_known( $number, $tanks ) && ( count( $run ) >= 2 || strlen( (string) $token ) >= 3 ) ) {
					$assign( 'tank', $number );
					$mark( $run );
					$i = end( $run ) + 1;
					continue;
				}
				if ( null === $refs['qty'] ) {
					$qty = self::qty_from_run( $tokens, $run );
					if ( $qty ) {
						$refs['qty'] = $qty['value'];
						$mark( $qty['used'] );
						$i = max( $qty['used'] ) + 1;
						continue;
					}
				}
				// A number that is neither the quantity nor a tank stays with
				// the fish ("four SIX line wrasse", "four stripe damsel").
				$i++;
				continue;
			}

			// "a couple", "a dozen", "half a dozen", "a pair", "a clownfish".
			if ( null === $refs['qty'] && in_array( $token, array( 'a', 'an', 'half', 'couple', 'pair', 'dozen' ), true ) ) {
				$special = self::special_qty( $tokens, $i );
				if ( $special ) {
					$refs['qty'] = $special['value'];
					$mark( $special['used'] );
					$i = max( $special['used'] ) + 1;
					continue;
				}
			}

			$i++;
		}

		// "two dozen" came through the number branch as 2 followed by "dozen".
		foreach ( $tokens as $j => $token ) {
			if ( 'dozen' === $token && ! $consumed[ $j ] && $j > 0 && $consumed[ $j - 1 ] && null !== $refs['qty'] && self::is_number_token( $tokens[ $j - 1 ] ) ) {
				$refs['qty']    = $refs['qty'] * 12;
				$consumed[ $j ] = true;
			}
		}

		return $refs;
	}

	/**
	 * "a couple" = 2, "a pair" = 2, "a dozen" = 12, "half a dozen" = 6,
	 * "a clownfish" = 1 (only when the next word is not a number).
	 *
	 * @param string[] $tokens Tokens.
	 * @param int      $i      Index of a / an / half / couple / pair / dozen.
	 * @return array{value: int, used: int[]}|null
	 */
	public static function special_qty( array $tokens, $i ) {
		$t0 = $tokens[ $i ];
		$t1 = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ] : '';
		$t2 = isset( $tokens[ $i + 2 ] ) ? $tokens[ $i + 2 ] : '';

		if ( 'half' === $t0 && 'a' === $t1 && 'dozen' === $t2 ) {
			return array( 'value' => 6, 'used' => array( $i, $i + 1, $i + 2 ) );
		}
		if ( ( 'a' === $t0 || 'an' === $t0 ) && 'couple' === $t1 ) {
			$used = array( $i, $i + 1 );
			if ( 'of' === $t2 ) {
				$used[] = $i + 2;
			}
			return array( 'value' => 2, 'used' => $used );
		}
		if ( ( 'a' === $t0 || 'an' === $t0 ) && 'pair' === $t1 ) {
			$used = array( $i, $i + 1 );
			if ( 'of' === $t2 ) {
				$used[] = $i + 2;
			}
			return array( 'value' => 2, 'used' => $used );
		}
		if ( ( 'a' === $t0 || 'an' === $t0 ) && 'dozen' === $t1 ) {
			return array( 'value' => 12, 'used' => array( $i, $i + 1 ) );
		}
		if ( ( 'couple' === $t0 || 'pair' === $t0 ) ) {
			$used = array( $i );
			if ( 'of' === $t1 ) {
				$used[] = $i + 1;
			}
			return array( 'value' => 2, 'used' => $used );
		}
		if ( 'dozen' === $t0 ) {
			return array( 'value' => 12, 'used' => array( $i ) );
		}
		if ( ( 'a' === $t0 || 'an' === $t0 ) && '' !== $t1 && ! self::is_number_token( $t1 ) && ! in_array( $t1, self::FILLER_WORDS, true ) ) {
			return array( 'value' => 1, 'used' => array( $i ) );
		}
		return null;
	}

	/**
	 * Level + position words starting at $i ("top left", "bottom middle",
	 * "top row second", "top"). Position may be missing (→ '').
	 *
	 * @param string[] $tokens Tokens.
	 * @param int      $i      Start.
	 * @return array{level: int, position: string, used: int[]}|null
	 */
	public static function level_position( array $tokens, $i ) {
		$n = count( $tokens );
		while ( $i < $n && in_array( $tokens[ $i ], array( 'the', 'on', 'at' ), true ) ) {
			$i++;
		}
		if ( $i >= $n || ! isset( self::LEVEL_WORDS[ $tokens[ $i ] ] ) ) {
			return null;
		}
		$used  = array( $i );
		$level = self::LEVEL_WORDS[ $tokens[ $i ] ];
		$j     = $i + 1;
		while ( $j < $n && in_array( $tokens[ $j ], array( 'row', 'shelf', 'tier', 'the', 'tank' ), true ) ) {
			$used[] = $j;
			$j++;
		}
		$position = '';
		if ( $j < $n && ( in_array( $tokens[ $j ], self::POSITION_WORDS, true ) || ctype_digit( (string) $tokens[ $j ] ) && (int) $tokens[ $j ] < 10 ) ) {
			$position = $tokens[ $j ];
			$used[]   = $j;
		}
		return array(
			'level'    => $level,
			'position' => $position,
			'used'     => $used,
		);
	}

	/**
	 * The tank number at a level / position in a building, or null.
	 *
	 * @param array[]  $tanks    Tank list.
	 * @param int|null $building Building number.
	 * @param int      $level    Level.
	 * @param string   $position Position word.
	 * @return int|null
	 */
	public static function resolve_position( array $tanks, $building, $level, $position ) {
		if ( null === $building || '' === (string) $position ) {
			return null;
		}
		$mine = array();
		foreach ( $tanks as $tank ) {
			$b = isset( $tank['building'] ) ? (int) $tank['building'] : (int) floor( (int) ( $tank['number'] ?? 0 ) / 100 ) * 100;
			if ( $b === (int) $building ) {
				$mine[] = $tank;
			}
		}
		$found = Layout::tank_at( $mine, $level, $position );
		return $found ? (int) $found['number'] : null;
	}

	/**
	 * The building number of a tank number.
	 *
	 * @param int|null $number Tank number.
	 * @return int|null
	 */
	public static function building_of( $number ) {
		return null === $number ? null : (int) floor( (int) $number / 100 ) * 100;
	}

	/**
	 * Whether a number is in the tank list (an empty list accepts any
	 * three-digit number that obeys the rule).
	 *
	 * @param int|null $number Number.
	 * @param array[]  $tanks  Tanks.
	 * @return bool
	 */
	public static function tank_known( $number, array $tanks ) {
		if ( null === $number || (int) $number < 0 ) {
			return false;
		}
		if ( ! $tanks ) {
			return null !== Layout::parse_number( $number );
		}
		foreach ( $tanks as $tank ) {
			if ( (int) ( $tank['number'] ?? 0 ) === (int) $number && 'offline' !== (string) ( $tank['status'] ?? 'active' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function pick( array $tokens, array $indexes ) {
		$out = array();
		foreach ( $indexes as $index ) {
			$out[] = $tokens[ $index ];
		}
		return $out;
	}

	private static function values( array $tokens, array $indexes ) {
		$out = array();
		foreach ( $indexes as $index ) {
			$out[] = (int) self::token_value( $tokens[ $index ] );
		}
		return $out;
	}

	/* ============================================================== */
	/*  Loss reasons, "all"                                            */
	/* ============================================================== */

	/**
	 * doa / disease / jumped / unknown from the words, or null.
	 *
	 * @param string[] $tokens Tokens.
	 * @param int[]    $used   By reference: the indexes of the reason words.
	 * @return string|null
	 */
	public static function reason( array $tokens, array &$used ) {
		$used = array();
		foreach ( self::REASONS as $reason => $words ) {
			foreach ( $tokens as $i => $token ) {
				if ( 'arrival' === $token ) {
					// "dead on arrival" only.
					if ( $i >= 2 && 'dead' === $tokens[ $i - 2 ] && 'on' === $tokens[ $i - 1 ] ) {
						$used = array( $i - 2, $i - 1, $i );
						return 'doa';
					}
					continue;
				}
				if ( in_array( $token, $words, true ) ) {
					$used = array( $i );
					if ( 'unknown' === $reason && $i > 0 && in_array( $tokens[ $i - 1 ], array( 'reason', 'cause' ), true ) ) {
						$used[] = $i - 1;
					}
					return $reason;
				}
			}
		}
		$phrase = ' ' . implode( ' ', $tokens ) . ' ';
		if ( false !== strpos( $phrase, ' not sure ' ) || false !== strpos( $phrase, ' no idea ' ) ) {
			return 'unknown';
		}
		return null;
	}

	/**
	 * "move all / everything / them to 221".
	 *
	 * @param string[] $tokens Tokens.
	 * @return bool
	 */
	public static function wants_all( array $tokens ) {
		return self::has_any( $tokens, array( 'all', 'everything', 'everyone', 'everybody' ) );
	}

	/* ============================================================== */
	/*  Species matching                                               */
	/* ============================================================== */

	/**
	 * Score every candidate against the spoken words, best first.
	 *
	 * @param string[] $words      Spoken species words.
	 * @param array[]  $candidates product_id, name, variants[] {text, kind}, stay_id.
	 * @return array[] index, score, name, product_id, stay_id, kind.
	 */
	public static function match_species( array $words, array $candidates ) {
		$spoken = self::norm_tokens( $words );
		if ( ! $spoken ) {
			return array();
		}
		$phrase = implode( ' ', $spoken );

		$scored = array();
		foreach ( array_values( $candidates ) as $index => $candidate ) {
			$best      = 0.0;
			$best_kind = '';
			$variants  = isset( $candidate['variants'] ) && is_array( $candidate['variants'] ) ? $candidate['variants'] : array();
			if ( ! $variants && isset( $candidate['name'] ) ) {
				$variants[] = array(
					'text' => (string) $candidate['name'],
					'kind' => 'title',
				);
			}
			foreach ( $variants as $variant ) {
				$text = (string) ( $variant['text'] ?? '' );
				$kind = (string) ( $variant['kind'] ?? 'title' );
				if ( '' === trim( $text ) ) {
					continue;
				}
				$target = self::norm_tokens( self::tokenize( $text ) );
				if ( ! $target ) {
					continue;
				}
				$weight = isset( self::KIND_WEIGHTS[ $kind ] ) ? self::KIND_WEIGHTS[ $kind ] : 0.95;

				if ( 'alias' === $kind && implode( ' ', $target ) === $phrase ) {
					$score = 1.0;
				} else {
					$score = self::token_score( $spoken, $target ) * $weight;
				}
				if ( $score > $best ) {
					$best      = $score;
					$best_kind = $kind;
				}
			}
			if ( $best > 0.2 ) {
				$scored[] = array(
					'index'      => $index,
					'score'      => $best,
					'kind'       => $best_kind,
					'name'       => (string) ( $candidate['name'] ?? '' ),
					'product_id' => isset( $candidate['product_id'] ) && (int) $candidate['product_id'] > 0 ? (int) $candidate['product_id'] : null,
					'stay_id'    => isset( $candidate['stay_id'] ) && (int) $candidate['stay_id'] > 0 ? (int) $candidate['stay_id'] : null,
				);
			}
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
					return $b['score'] <=> $a['score'];
				}
				$rank = array( 'alias' => 0, 'stay' => 1, 'title' => 2, 'common' => 3, 'sci' => 4 );
				$ra   = isset( $rank[ $a['kind'] ] ) ? $rank[ $a['kind'] ] : 5;
				$rb   = isset( $rank[ $b['kind'] ] ) ? $rank[ $b['kind'] ] : 5;
				return $ra <=> $rb ?: $a['index'] <=> $b['index'];
			}
		);

		return $scored;
	}

	/**
	 * precision × (0.6 + 0.4 × recall) over the best token pairings —
	 * every spoken word has to land, and a candidate with words nobody
	 * said ("Yellow Clown Goby" for "clowns") is held back. Also tried with
	 * adjacent spoken words joined ("clown fish" → "clownfish").
	 *
	 * @param string[] $spoken Normalised spoken tokens.
	 * @param string[] $target Normalised candidate tokens.
	 * @return float
	 */
	public static function token_score( array $spoken, array $target ) {
		$best = self::pair_score( $spoken, $target );
		$n    = count( $spoken );
		for ( $i = 0; $i + 1 < $n; $i++ ) {
			$joined = $spoken;
			array_splice( $joined, $i, 2, array( $spoken[ $i ] . $spoken[ $i + 1 ] ) );
			$best = max( $best, self::pair_score( $joined, $target ) );
		}
		return $best;
	}

	private static function pair_score( array $spoken, array $target ) {
		$precision = 0.0;
		foreach ( $spoken as $s ) {
			$m = 0.0;
			foreach ( $target as $t ) {
				$m = max( $m, self::token_sim( $s, $t ) );
			}
			$precision += $m;
		}
		$precision /= max( 1, count( $spoken ) );

		$recall = 0.0;
		foreach ( $target as $t ) {
			$m = 0.0;
			foreach ( $spoken as $s ) {
				$m = max( $m, self::token_sim( $s, $t ) );
			}
			$recall += $m;
		}
		$recall /= max( 1, count( $target ) );

		return $precision * ( 0.6 + 0.4 * $recall );
	}

	/**
	 * Similarity of two normalised tokens: exact 1, Levenshtein ≤ 2 (and
	 * under half the length, both words 4+ letters) 0.85, a prefix of 4+
	 * letters 0.7, else 0.
	 *
	 * @param string $a Token.
	 * @param string $b Token.
	 * @return float
	 */
	public static function token_sim( $a, $b ) {
		if ( $a === $b ) {
			return 1.0;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( $la >= 4 && $lb >= 4 ) {
			$d = levenshtein( $a, $b );
			if ( $d <= 2 && $d < min( $la, $lb ) / 2 ) {
				return 0.85;
			}
		}
		if ( $la >= 4 && $lb > $la && 0 === strpos( $b, $a ) ) {
			return 0.7;
		}
		if ( $lb >= 4 && $la > $lb && 0 === strpos( $a, $b ) ) {
			return 0.7;
		}
		return 0.0;
	}

	/**
	 * Lower-case, size words and fillers dropped, plurals singularised.
	 *
	 * @param string[] $tokens Tokens.
	 * @return string[]
	 */
	public static function norm_tokens( array $tokens ) {
		$out = array();
		foreach ( $tokens as $token ) {
			$token = strtolower( trim( (string) $token ) );
			if ( '' === $token || in_array( $token, self::SIZE_WORDS, true ) || in_array( $token, self::CATALOGUE_NOISE, true ) || in_array( $token, array( 'the', 'a', 'an', 'of', 'and', 'fishes' ), true ) ) {
				continue;
			}
			$out[] = self::singular( $token );
		}
		return $out;
	}

	/**
	 * A crude English singular: cardinals → cardinal, wrasses → wrasse,
	 * gobies → goby, chromis → chromis.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	public static function singular( $word ) {
		$len = strlen( $word );
		if ( $len <= 3 ) {
			return $word;
		}
		if ( 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( 'sses' === substr( $word, -4 ) ) {
			return substr( $word, 0, -1 ); // wrasses → wrasse (basses → basse, close enough for the fuzzy match).
		}
		if ( 'shes' === substr( $word, -4 ) || 'ches' === substr( $word, -4 ) || 'xes' === substr( $word, -3 ) ) {
			return substr( $word, 0, -2 );
		}
		if ( 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) && 'is' !== substr( $word, -2 ) && 'us' !== substr( $word, -2 ) ) {
			return substr( $word, 0, -1 );
		}
		return $word;
	}

	/**
	 * Occupants as match candidates (kind 'stay').
	 *
	 * @param array[] $occupants stay_id, species, product_id, qty_live.
	 * @return array[]
	 */
	public static function occupants_as_candidates( array $occupants ) {
		$out = array();
		foreach ( $occupants as $occupant ) {
			$variants = array(
				array(
					'text' => (string) ( $occupant['species'] ?? '' ),
					'kind' => 'stay',
				),
			);
			foreach ( (array) ( $occupant['variants'] ?? array() ) as $variant ) {
				$variants[] = $variant;
			}
			$out[] = array(
				'product_id' => isset( $occupant['product_id'] ) ? (int) $occupant['product_id'] : null,
				'stay_id'    => isset( $occupant['stay_id'] ) ? (int) $occupant['stay_id'] : null,
				'name'       => (string) ( $occupant['species'] ?? '' ),
				'variants'   => $variants,
			);
		}
		return $out;
	}

	private static function occupants_as_chips( array $occupants ) {
		$out = array();
		foreach ( $occupants as $occupant ) {
			$out[] = array(
				'name'       => (string) ( $occupant['species'] ?? '' ),
				'product_id' => isset( $occupant['product_id'] ) && (int) $occupant['product_id'] > 0 ? (int) $occupant['product_id'] : null,
				'stay_id'    => isset( $occupant['stay_id'] ) ? (int) $occupant['stay_id'] : null,
				'score'      => 0.0,
			);
		}
		return $out;
	}

	private static function chip( array $scored ) {
		return array(
			'name'       => $scored['name'],
			'product_id' => $scored['product_id'],
			'stay_id'    => $scored['stay_id'],
			'score'      => round( $scored['score'], 3 ),
		);
	}

	private static function title_case( $text ) {
		return ucwords( strtolower( (string) $text ) );
	}

	/* ============================================================== */
	/*  Plain words                                                    */
	/* ============================================================== */

	/**
	 * The preview in plain words — the same sentence front-desk.js builds.
	 *
	 * @param array $r A parse() result.
	 * @return string
	 */
	public static function summary( array $r ) {
		$needs   = (array) ( $r['needs'] ?? array() );
		$species = (array) ( $r['species'] ?? array() );
		$name    = '' !== (string) ( $species['name'] ?? '' ) ? (string) $species['name'] : 'fish';
		$qty     = isset( $r['qty'] ) && null !== $r['qty'] ? (int) $r['qty'] : null;
		$tank    = isset( $r['tank'] ) && null !== $r['tank'] && (int) $r['tank'] > 0 ? 'Tank ' . (int) $r['tank'] : 'which tank?';
		$to      = isset( $r['to_tank'] ) && null !== $r['to_tank'] && (int) $r['to_tank'] > 0 ? 'Tank ' . (int) $r['to_tank'] : 'which tank?';
		$ask     = in_array( 'species', $needs, true );
		$subject = ( $ask ? '' : $name );
		$what    = ( null !== $qty ? $qty . ' × ' : '' ) . $subject;
		$what    = (string) preg_replace( '/^[\s×]+|[\s×]+$/u', '', $what );
		if ( '' === $what ) {
			$what = ! empty( $r['all'] ) ? 'all fish' : 'fish';
		}
		$prefix = $ask ? 'Which fish? ' : '';

		switch ( (string) ( $r['intent'] ?? '' ) ) {
			case 'move':
				$moving = ! empty( $r['all'] ) && null === $qty ? 'all fish' : $what;
				return $prefix . 'Move ' . $moving . ' from ' . $tank . ' to ' . $to;
			case 'loss':
				$reason = ! empty( $r['reason'] ) ? ' (' . strtoupper( 'doa' === $r['reason'] ? 'DOA' : $r['reason'] ) . ')' : '';
				return $prefix . ( null === $qty && ! $ask ? '1 × ' : '' ) . $what . ' lost in ' . $tank . $reason;
			case 'sold':
				return $prefix . ( null === $qty && ! $ask ? '1 × ' : '' ) . $what . ' shipped from ' . $tank;
			case 'copper':
				$value = isset( $r['value'] ) && null !== $r['value'] ? rtrim( rtrim( number_format( (float) $r['value'], 2, '.', '' ), '0' ), '.' ) : 'how much?';
				return 'Copper ' . $value . ' in ' . $tank;
			case 'clear':
				return $prefix . ( $ask ? 'cleared' : $name . ' in ' . $tank . ' cleared today' );
			case 'count':
				return $prefix . 'Count: ' . ( null === $qty ? 'how many? ' : $qty . ' × ' ) . ( $ask ? '' : $name ) . ' in ' . $tank;
			case 'note':
				return 'Note on ' . $tank . ': ' . (string) ( $r['note'] ?? '' );
			case 'arrival':
				$where = in_array( 'tank', $needs, true ) ? 'which Spa tank?' : $tank;
				return $prefix . ( null === $qty && ! $ask ? '1 × ' : '' ) . $what . ' arriving in ' . $where . ' today';
			default:
				return 'Say what happened, for example "lost one cardinal in 521".';
		}
	}
}
