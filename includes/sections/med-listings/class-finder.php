<?php
/**
 * Med listings — the claim finder.
 *
 * Finds the words of the lists in a text and says which list each one
 * came from (a disease, a what-it-does word, dosing, a medicine-type
 * label, a link). Used to highlight the current text on the review
 * table, to decide which fields need a proposal at all, to clean a
 * line by dropping the sentences that carry a claim, and to check the
 * rendered pages afterwards.
 *
 * Pure: no WordPress call.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Finder
 */
class Finder {

	/**
	 * Compiled patterns per list, keyed by a hash of the list.
	 *
	 * @var array<string, array<int, array{0: string, 1: string}>>
	 */
	private static $compiled = array();

	/**
	 * A list's words as [pattern, word] pairs.
	 *
	 * Plain words match whole words only, capitals ignored, a space or
	 * hyphen in the word matching any run of spaces and hyphens. The
	 * links list matches anywhere (it holds pieces of addresses).
	 *
	 * @param string   $kind  List name.
	 * @param string[] $words Its words.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function patterns( $kind, array $words ) {
		$key = $kind . '|' . md5( implode( "\n", $words ) );
		if ( isset( self::$compiled[ $key ] ) ) {
			return self::$compiled[ $key ];
		}
		$out = array();
		foreach ( $words as $word ) {
			$word = trim( (string) $word );
			if ( '' === $word ) {
				continue;
			}
			if ( Words::is_pattern( $word ) ) {
				$pattern = $word;
				if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern is skipped.
					continue;
				}
			} elseif ( 'links' === $kind ) {
				$pattern = '~' . preg_quote( $word, '~' ) . '~iu';
			} else {
				$body    = preg_replace( '/(?:\\\\ |\\\\-|\s|-)+/u', '[\s\-]+', preg_quote( $word, '~' ) );
				$pattern = '~(?<![\p{L}\p{N}])' . $body . '(?![\p{L}\p{N}])~iu';
			}
			$out[] = array( $pattern, $word );
		}
		self::$compiled[ $key ] = $out;
		return $out;
	}

	/**
	 * Every hit of the given lists in a text, longest first where two
	 * overlap, in reading order.
	 *
	 * @param string   $text  Text (HTML is read as it is).
	 * @param array    $lists Words::get().
	 * @param string[] $kinds Which lists (default: the claim kinds).
	 * @return array<int, array{kind: string, word: string, start: int, length: int, match: string}>
	 */
	public static function find( $text, array $lists, array $kinds = Words::CLAIM_KINDS ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array();
		}
		$all = array();
		foreach ( $kinds as $kind ) {
			if ( empty( $lists[ $kind ] ) || ! is_array( $lists[ $kind ] ) ) {
				continue;
			}
			foreach ( self::patterns( $kind, $lists[ $kind ] ) as $pair ) {
				if ( ! preg_match_all( $pair[0], $text, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as $hit ) {
					if ( '' === $hit[0] ) {
						continue;
					}
					$all[] = array(
						'kind'   => $kind,
						'word'   => $pair[1],
						'start'  => (int) $hit[1],
						'length' => strlen( $hit[0] ),
						'match'  => $hit[0],
					);
				}
			}
		}

		// Longest first, then keep a hit only when it does not overlap one already kept.
		usort(
			$all,
			function ( $a, $b ) {
				return $b['length'] <=> $a['length'] ?: $a['start'] <=> $b['start'];
			}
		);
		$kept = array();
		foreach ( $all as $hit ) {
			$overlaps = false;
			foreach ( $kept as $k ) {
				if ( $hit['start'] < $k['start'] + $k['length'] && $k['start'] < $hit['start'] + $hit['length'] ) {
					$overlaps = true;
					break;
				}
			}
			if ( ! $overlaps ) {
				$kept[] = $hit;
			}
		}
		usort(
			$kept,
			function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);
		return $kept;
	}

	/**
	 * Whether a text carries anything from the given lists.
	 *
	 * @param string   $text  Text.
	 * @param array    $lists Words::get().
	 * @param string[] $kinds Lists.
	 * @return bool
	 */
	public static function has( $text, array $lists, array $kinds = Words::CLAIM_KINDS ) {
		return array() !== self::find( $text, $lists, $kinds );
	}

	/**
	 * The distinct words found, for a short note ("ich, treats, dose").
	 *
	 * @param array $hits find() result.
	 * @return string[]
	 */
	public static function words( array $hits ) {
		$out = array();
		foreach ( $hits as $hit ) {
			$w = strtolower( $hit['match'] );
			if ( ! in_array( $w, $out, true ) ) {
				$out[] = $w;
			}
		}
		return $out;
	}

	/**
	 * The text escaped for HTML with every hit wrapped in
	 * <mark class="fhml-hit fhml-hit--{kind}">.
	 *
	 * @param string $text Text.
	 * @param array  $hits find() result for that text.
	 * @return string Safe HTML.
	 */
	public static function highlight( $text, array $hits ) {
		$text = (string) $text;
		$out  = '';
		$pos  = 0;
		foreach ( $hits as $hit ) {
			$out .= self::esc( substr( $text, $pos, $hit['start'] - $pos ) );
			$out .= '<mark class="fhml-hit fhml-hit--' . self::esc( $hit['kind'] ) . '" title="' . self::esc( $hit['kind'] ) . '">' . self::esc( $hit['match'] ) . '</mark>';
			$pos  = $hit['start'] + $hit['length'];
		}
		return $out . self::esc( substr( $text, $pos ) );
	}

	/**
	 * A few words either side of a hit, on one line, for a report.
	 *
	 * @param string $text Text.
	 * @param array  $hit  One hit.
	 * @param int    $wide Characters either side.
	 * @return string
	 */
	public static function snippet( $text, array $hit, $wide = 40 ) {
		$from = max( 0, $hit['start'] - $wide );
		$len  = $hit['length'] + ( $hit['start'] - $from ) + $wide;
		$cut  = substr( (string) $text, $from, $len );
		// Never cut a UTF-8 character in half.
		$cut = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $cut, 'UTF-8', 'UTF-8' ) : $cut;
		$cut = trim( preg_replace( '/\s+/u', ' ', (string) $cut ) );
		return ( $from > 0 ? '…' : '' ) . $cut . ( $from + $len < strlen( (string) $text ) ? '…' : '' );
	}

	/**
	 * A line with every sentence or piece that carries a claim taken
	 * out. Pieces are sentences and the parts of a title split by
	 * " | ", " – ", " - ", " · ", " : " and line breaks. What is left is
	 * put back together with the separators it had; '' when nothing
	 * clean is left.
	 *
	 * @param string   $text  Text (plain or simple HTML; tags are kept in the pieces kept).
	 * @param array    $lists Words::get().
	 * @param string[] $kinds Lists.
	 * @return string
	 */
	public static function clean( $text, array $lists, array $kinds = Words::CLAIM_KINDS ) {
		$text = (string) $text;
		if ( ! self::has( $text, $lists, $kinds ) ) {
			return $text;
		}
		$parts = preg_split( '/((?<=[.!?])\s+|\s+[|·–—:]\s+|\s+-\s+|\r?\n+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$kept  = array();
		for ( $i = 0; $i < count( $parts ); $i += 2 ) {
			$piece = $parts[ $i ];
			$next  = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';
			if ( '' !== trim( preg_replace( '/<[^>]*>/', '', $piece ) ) && ! self::has( $piece, $lists, $kinds ) ) {
				$kept[] = array( $piece, $next );
			}
		}
		// A question left on its own once the line it asked about is gone ("Questions?") goes too.
		if ( count( $kept ) < ( count( $parts ) + 1 ) / 2 ) {
			$kept = array_values(
				array_filter(
					$kept,
					function ( $pair ) {
						return '?' !== substr( rtrim( $pair[0] ), -1 );
					}
				)
			);
		}
		$out = '';
		$n   = count( $kept );
		foreach ( $kept as $i => $pair ) {
			$out .= $pair[0] . ( $i < $n - 1 ? ( '' !== trim( $pair[1] ) ? $pair[1] : ' ' ) : '' );
		}
		return trim( $out );
	}

	/**
	 * HTML-escape (no WordPress needed).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function esc( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}

