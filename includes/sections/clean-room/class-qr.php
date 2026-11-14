<?php
/**
 * Clean Room QR — PURE: a small self-contained QR Code encoder for the
 * tank labels. Byte mode, error correction level M, versions 1–6 (up to
 * 106 bytes — a Front Desk URL is about 45), output as an inline SVG.
 *
 * It follows ISO/IEC 18004 step by step: the bit stream (mode 0100,
 * 8-bit count, data, terminator, 0xEC/0x11 padding), Reed–Solomon error
 * correction over GF(2^8) with the 0x11D polynomial, block interleaving,
 * the function patterns (finders, separators, timing, alignment, the dark
 * module), the zigzag data placement, the eight masks scored by the four
 * penalty rules, and the BCH-protected format information.
 *
 * No WordPress, no database.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Qr
 */
class Qr {

	const MIN_VERSION = 1;
	const MAX_VERSION = 6;

	/**
	 * Error-correction level M: codewords per block and blocks, by version.
	 *
	 * @var int[]
	 */
	const ECC_PER_BLOCK = array( 1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16 );
	const BLOCKS        = array( 1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4 );

	/**
	 * Format bits for level M.
	 *
	 * @var int
	 */
	const FORMAT_ECL = 0;

	const PENALTY_N1 = 3;
	const PENALTY_N2 = 3;
	const PENALTY_N3 = 40;
	const PENALTY_N4 = 10;

	/* ============================================================== */
	/*  Public API                                                     */
	/* ============================================================== */

	/**
	 * Bytes the data region of a version holds in byte mode.
	 *
	 * @param int $version Version.
	 * @return int
	 */
	public static function capacity( $version ) {
		$version = (int) $version;
		if ( $version < self::MIN_VERSION || $version > self::MAX_VERSION ) {
			return 0;
		}
		$data_bits = self::data_codewords( $version ) * 8;
		return (int) floor( ( $data_bits - 4 - 8 ) / 8 );
	}

	/**
	 * Encode a string. Returns null when it does not fit version 6.
	 *
	 * @param string $text        Text (encoded as its bytes).
	 * @param int    $min_version Smallest version to use.
	 * @return array{version: int, size: int, mask: int, modules: bool[][]}|null
	 */
	public static function encode( $text, $min_version = self::MIN_VERSION ) {
		$bytes = array_values( unpack( 'C*', (string) $text ) );
		$len   = count( $bytes );

		$version = 0;
		for ( $v = max( self::MIN_VERSION, (int) $min_version ); $v <= self::MAX_VERSION; $v++ ) {
			if ( self::capacity( $v ) >= $len ) {
				$version = $v;
				break;
			}
		}
		if ( 0 === $version ) {
			return null;
		}

		$codewords = self::codewords( $bytes, $version );
		$size      = 17 + 4 * $version;

		$modules  = array_fill( 0, $size, array_fill( 0, $size, false ) );
		$function = array_fill( 0, $size, array_fill( 0, $size, false ) );

		self::draw_function_patterns( $modules, $function, $version, $size );
		self::draw_codewords( $modules, $function, $codewords, $size );

		// Pick the mask with the lowest penalty; the format bits are drawn
		// for each candidate because they are part of the scored symbol.
		$best_mask    = 0;
		$best_penalty = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			self::apply_mask( $modules, $function, $mask, $size );
			self::draw_format_bits( $modules, $function, $mask, $size );
			$penalty = self::penalty( $modules, $size );
			if ( $penalty < $best_penalty ) {
				$best_penalty = $penalty;
				$best_mask    = $mask;
			}
			self::apply_mask( $modules, $function, $mask, $size ); // XOR again = undo.
		}

		self::apply_mask( $modules, $function, $best_mask, $size );
		self::draw_format_bits( $modules, $function, $best_mask, $size );

		return array(
			'version' => $version,
			'size'    => $size,
			'mask'    => $best_mask,
			'modules' => $modules,
		);
	}

	/**
	 * Encode with a given mask (for cross-checks against another encoder).
	 *
	 * @param string $text Text.
	 * @param int    $mask Mask 0–7.
	 * @return array|null
	 */
	public static function encode_with_mask( $text, $mask ) {
		$bytes = array_values( unpack( 'C*', (string) $text ) );
		$len   = count( $bytes );

		$version = 0;
		for ( $v = self::MIN_VERSION; $v <= self::MAX_VERSION; $v++ ) {
			if ( self::capacity( $v ) >= $len ) {
				$version = $v;
				break;
			}
		}
		if ( 0 === $version ) {
			return null;
		}

		$codewords = self::codewords( $bytes, $version );
		$size      = 17 + 4 * $version;
		$modules   = array_fill( 0, $size, array_fill( 0, $size, false ) );
		$function  = array_fill( 0, $size, array_fill( 0, $size, false ) );

		self::draw_function_patterns( $modules, $function, $version, $size );
		self::draw_codewords( $modules, $function, $codewords, $size );
		$mask = (int) $mask % 8;
		self::apply_mask( $modules, $function, $mask, $size );
		self::draw_format_bits( $modules, $function, $mask, $size );

		return array(
			'version' => $version,
			'size'    => $size,
			'mask'    => $mask,
			'modules' => $modules,
		);
	}

	/**
	 * The symbol as an inline SVG with a quiet zone, or '' when the text
	 * does not fit.
	 *
	 * @param string $text   Text.
	 * @param int    $module Pixels per module.
	 * @param int    $quiet  Quiet-zone width in modules (the standard asks for 4).
	 * @return string
	 */
	public static function svg( $text, $module = 4, $quiet = 4 ) {
		$code = self::encode( $text );
		if ( ! $code ) {
			return '';
		}

		$module = max( 1, (int) $module );
		$quiet  = max( 0, (int) $quiet );
		$size   = (int) $code['size'];
		$total  = ( $size + 2 * $quiet ) * $module;

		$path = '';
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $code['modules'][ $y ][ $x ] ) {
					$path .= 'M' . ( ( $x + $quiet ) * $module ) . ' ' . ( ( $y + $quiet ) * $module ) . 'h' . $module . 'v' . $module . 'h-' . $module . 'z';
				}
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" width="' . $total . '" height="' . $total . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
			. '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
			. '<path d="' . $path . '" fill="#000000"/>'
			. '</svg>';
	}

	/* ============================================================== */
	/*  Codewords                                                      */
	/* ============================================================== */

	/**
	 * Raw data modules of a version (the standard's table, as a formula).
	 *
	 * @param int $version Version.
	 * @return int
	 */
	public static function raw_data_modules( $version ) {
		$version = (int) $version;
		$result  = ( 16 * $version + 128 ) * $version + 64;
		if ( $version >= 2 ) {
			$align   = (int) floor( $version / 7 ) + 2;
			$result -= ( 25 * $align - 10 ) * $align - 55;
			if ( $version >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	/**
	 * Data codewords of a version at level M.
	 *
	 * @param int $version Version.
	 * @return int
	 */
	public static function data_codewords( $version ) {
		$version = (int) $version;
		return (int) floor( self::raw_data_modules( $version ) / 8 ) - self::ECC_PER_BLOCK[ $version ] * self::BLOCKS[ $version ];
	}

	/**
	 * The full interleaved codeword sequence for the bytes.
	 *
	 * @param int[] $bytes   Data bytes.
	 * @param int   $version Version.
	 * @return int[]
	 */
	public static function codewords( array $bytes, $version ) {
		$version   = (int) $version;
		$data_cw   = self::data_codewords( $version );
		$bits      = array();
		$append    = static function ( $value, $count ) use ( &$bits ) {
			for ( $i = $count - 1; $i >= 0; $i-- ) {
				$bits[] = ( $value >> $i ) & 1;
			}
		};

		$append( 4, 4 );                 // Byte mode.
		$append( count( $bytes ), 8 );   // Character count (8 bits for versions 1–9).
		foreach ( $bytes as $byte ) {
			$append( $byte, 8 );
		}

		$capacity = $data_cw * 8;
		$append( 0, min( 4, $capacity - count( $bits ) ) );   // Terminator.
		$append( 0, ( 8 - count( $bits ) % 8 ) % 8 );          // Byte-align.
		for ( $pad = 0xEC; count( $bits ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$append( $pad, 8 );
		}

		$data = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$byte = 0;
			for ( $j = 0; $j < 8; $j++ ) {
				$byte = ( $byte << 1 ) | $bits[ $i + $j ];
			}
			$data[] = $byte;
		}

		return self::interleave( $data, $version );
	}

	/**
	 * Split the data into blocks, append each block's EC codewords and
	 * interleave the blocks.
	 *
	 * @param int[] $data    Data codewords.
	 * @param int   $version Version.
	 * @return int[]
	 */
	public static function interleave( array $data, $version ) {
		$blocks_n     = self::BLOCKS[ $version ];
		$ecc_len      = self::ECC_PER_BLOCK[ $version ];
		$raw_cw       = (int) floor( self::raw_data_modules( $version ) / 8 );
		$short_blocks = $blocks_n - $raw_cw % $blocks_n;
		$short_len    = (int) floor( $raw_cw / $blocks_n );

		$divisor = self::rs_divisor( $ecc_len );
		$blocks  = array();
		$k       = 0;
		for ( $i = 0; $i < $blocks_n; $i++ ) {
			$len   = $short_len - $ecc_len + ( $i < $short_blocks ? 0 : 1 );
			$chunk = array_slice( $data, $k, $len );
			$k    += $len;
			$ecc   = self::rs_remainder( $chunk, $divisor );
			if ( $i < $short_blocks ) {
				$chunk[] = null; // Placeholder so every block has the same length.
			}
			$blocks[] = array_merge( $chunk, $ecc );
		}

		$result    = array();
		$block_len = count( $blocks[0] );
		for ( $i = 0; $i < $block_len; $i++ ) {
			foreach ( $blocks as $j => $block ) {
				if ( $i !== $short_len - $ecc_len || $j >= $short_blocks ) {
					$result[] = (int) $block[ $i ];
				}
			}
		}

		return $result;
	}

	/**
	 * GF(2^8) product with the QR polynomial 0x11D.
	 *
	 * @param int $x X.
	 * @param int $y Y.
	 * @return int
	 */
	public static function gf_multiply( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z & 0xFF;
	}

	/**
	 * The Reed–Solomon generator polynomial coefficients for a degree.
	 *
	 * @param int $degree EC codewords.
	 * @return int[]
	 */
	public static function rs_divisor( $degree ) {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_multiply( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * The EC codewords of a data block.
	 *
	 * @param int[] $data    Data codewords.
	 * @param int[] $divisor From rs_divisor().
	 * @return int[]
	 */
	public static function rs_remainder( array $data, array $divisor ) {
		$n      = count( $divisor );
		$result = array_fill( 0, $n, 0 );
		foreach ( $data as $b ) {
			$factor = ( (int) $b ^ $result[0] ) & 0xFF;
			array_shift( $result );
			$result[] = 0;
			for ( $i = 0; $i < $n; $i++ ) {
				$result[ $i ] ^= self::gf_multiply( $divisor[ $i ], $factor );
			}
		}
		return $result;
	}

	/* ============================================================== */
	/*  Module placement                                               */
	/* ============================================================== */

	/**
	 * Alignment-pattern centre positions of a version (none for 1).
	 *
	 * @param int $version Version.
	 * @return int[]
	 */
	public static function alignment_positions( $version ) {
		$version = (int) $version;
		if ( 1 === $version ) {
			return array();
		}
		$num  = (int) floor( $version / 7 ) + 2;
		$size = 17 + 4 * $version;
		$step = ( 32 === $version ) ? 26 : intdiv( $version * 4 + $num * 2 + 1, $num * 2 - 2 ) * 2;
		$out  = array();
		for ( $i = 0, $pos = $size - 7; $i < $num - 1; $i++, $pos -= $step ) {
			array_unshift( $out, $pos );
		}
		array_unshift( $out, 6 );
		return $out;
	}

	private static function set_function( array &$modules, array &$function, $x, $y, $dark ) {
		$modules[ $y ][ $x ]  = (bool) $dark;
		$function[ $y ][ $x ] = true;
	}

	private static function draw_finder( array &$modules, array &$function, $x, $y, $size ) {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$dist = max( abs( $dx ), abs( $dy ) );
				$xx   = $x + $dx;
				$yy   = $y + $dy;
				if ( $xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size ) {
					self::set_function( $modules, $function, $xx, $yy, 2 !== $dist && 4 !== $dist );
				}
			}
		}
	}

	private static function draw_alignment( array &$modules, array &$function, $x, $y ) {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				self::set_function( $modules, $function, $x + $dx, $y + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
			}
		}
	}

	private static function draw_function_patterns( array &$modules, array &$function, $version, $size ) {
		// Timing patterns.
		for ( $i = 0; $i < $size; $i++ ) {
			self::set_function( $modules, $function, 6, $i, 0 === $i % 2 );
			self::set_function( $modules, $function, $i, 6, 0 === $i % 2 );
		}

		// Finders (with their separators, drawn as light modules).
		self::draw_finder( $modules, $function, 3, 3, $size );
		self::draw_finder( $modules, $function, $size - 4, 3, $size );
		self::draw_finder( $modules, $function, 3, $size - 4, $size );

		// Alignment patterns, skipping the three that overlap a finder.
		$positions = self::alignment_positions( $version );
		$n         = count( $positions );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < $n; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $j === $n - 1 ) || ( $i === $n - 1 && 0 === $j ) ) {
					continue;
				}
				self::draw_alignment( $modules, $function, $positions[ $i ], $positions[ $j ] );
			}
		}

		// Reserve the format areas (filled per mask) and the dark module.
		self::draw_format_bits( $modules, $function, 0, $size );
	}

	/**
	 * The 15 format bits (level + mask, BCH protected, XOR 0x5412) in both
	 * places, plus the always-dark module.
	 *
	 * @param bool[][] $modules  Modules.
	 * @param bool[][] $function Function map.
	 * @param int      $mask     Mask.
	 * @param int      $size     Size.
	 */
	private static function draw_format_bits( array &$modules, array &$function, $mask, $size ) {
		$bits = self::format_bits( $mask );
		$bit  = static function ( $i ) use ( $bits ) {
			return ( ( $bits >> $i ) & 1 ) === 1;
		};

		for ( $i = 0; $i <= 5; $i++ ) {
			self::set_function( $modules, $function, 8, $i, $bit( $i ) );
		}
		self::set_function( $modules, $function, 8, 7, $bit( 6 ) );
		self::set_function( $modules, $function, 8, 8, $bit( 7 ) );
		self::set_function( $modules, $function, 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			self::set_function( $modules, $function, 14 - $i, 8, $bit( $i ) );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			self::set_function( $modules, $function, $size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			self::set_function( $modules, $function, 8, $size - 15 + $i, $bit( $i ) );
		}
		self::set_function( $modules, $function, 8, $size - 8, true );
	}

	/**
	 * The 15-bit format value for level M and a mask.
	 *
	 * @param int $mask Mask.
	 * @return int
	 */
	public static function format_bits( $mask ) {
		$data = ( self::FORMAT_ECL << 3 ) | ( (int) $mask & 7 );
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		return ( ( $data << 10 ) | $rem ) ^ 0x5412;
	}

	/**
	 * Zigzag placement of the codeword bits into the non-function modules.
	 *
	 * @param bool[][] $modules   Modules.
	 * @param bool[][] $function  Function map.
	 * @param int[]    $codewords Codewords.
	 * @param int      $size      Size.
	 */
	private static function draw_codewords( array &$modules, array &$function, array $codewords, $size ) {
		$total = count( $codewords ) * 8;
		$i     = 0;
		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $size - 1 - $vert : $vert;
					if ( ! $function[ $y ][ $x ] && $i < $total ) {
						$modules[ $y ][ $x ] = ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 ) === 1;
						$i++;
					}
				}
			}
		}
	}

	/**
	 * XOR the mask over the data modules (applying it twice undoes it).
	 *
	 * @param bool[][] $modules  Modules.
	 * @param bool[][] $function Function map.
	 * @param int      $mask     Mask.
	 * @param int      $size     Size.
	 */
	private static function apply_mask( array &$modules, array &$function, $mask, $size ) {
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( (int) floor( $x / 3 ) + (int) floor( $y / 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
						break;
					case 6:
						$invert = 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
					default:
						$invert = 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
				}
				if ( $invert ) {
					$modules[ $y ][ $x ] = ! $modules[ $y ][ $x ];
				}
			}
		}
	}

	/* ============================================================== */
	/*  Mask scoring                                                   */
	/* ============================================================== */

	/**
	 * The four penalty rules of the standard.
	 *
	 * @param bool[][] $modules Modules.
	 * @param int      $size    Size.
	 * @return int
	 */
	public static function penalty( array $modules, $size ) {
		$result = 0;

		// Rows: runs of 5+ and finder-like patterns.
		for ( $y = 0; $y < $size; $y++ ) {
			$run_color = false;
			$run_x     = 0;
			$history   = array_fill( 0, 7, 0 );
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $modules[ $y ][ $x ] === $run_color ) {
					$run_x++;
					if ( 5 === $run_x ) {
						$result += self::PENALTY_N1;
					} elseif ( $run_x > 5 ) {
						$result++;
					}
				} else {
					self::finder_history( $run_x, $history, $size );
					if ( ! $run_color ) {
						$result += self::finder_count( $history ) * self::PENALTY_N3;
					}
					$run_color = $modules[ $y ][ $x ];
					$run_x     = 1;
				}
			}
			$result += self::finder_terminate( $run_color, $run_x, $history, $size ) * self::PENALTY_N3;
		}

		// Columns.
		for ( $x = 0; $x < $size; $x++ ) {
			$run_color = false;
			$run_y     = 0;
			$history   = array_fill( 0, 7, 0 );
			for ( $y = 0; $y < $size; $y++ ) {
				if ( $modules[ $y ][ $x ] === $run_color ) {
					$run_y++;
					if ( 5 === $run_y ) {
						$result += self::PENALTY_N1;
					} elseif ( $run_y > 5 ) {
						$result++;
					}
				} else {
					self::finder_history( $run_y, $history, $size );
					if ( ! $run_color ) {
						$result += self::finder_count( $history ) * self::PENALTY_N3;
					}
					$run_color = $modules[ $y ][ $x ];
					$run_y     = 1;
				}
			}
			$result += self::finder_terminate( $run_color, $run_y, $history, $size ) * self::PENALTY_N3;
		}

		// 2×2 blocks.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$c = $modules[ $y ][ $x ];
				if ( $c === $modules[ $y ][ $x + 1 ] && $c === $modules[ $y + 1 ][ $x ] && $c === $modules[ $y + 1 ][ $x + 1 ] ) {
					$result += self::PENALTY_N2;
				}
			}
		}

		// Dark / light balance.
		$dark = 0;
		foreach ( $modules as $row ) {
			foreach ( $row as $m ) {
				if ( $m ) {
					$dark++;
				}
			}
		}
		$total   = $size * $size;
		$k       = (int) floor( ( abs( $dark * 20 - $total * 10 ) + $total - 1 ) / $total ) - 1;
		$result += max( 0, $k ) * self::PENALTY_N4;

		return $result;
	}

	private static function finder_history( $run_length, array &$history, $size ) {
		if ( 0 === $history[0] ) {
			$run_length += $size; // The light border counts as light modules before the first run.
		}
		array_pop( $history );
		array_unshift( $history, $run_length );
	}

	private static function finder_count( array $history ) {
		$n    = $history[1];
		$core = $n > 0 && $history[2] === $n && $history[3] === $n * 3 && $history[4] === $n && $history[5] === $n;
		return ( ( $core && $history[0] >= $n * 4 && $history[6] >= $n ) ? 1 : 0 )
			+ ( ( $core && $history[6] >= $n * 4 && $history[0] >= $n ) ? 1 : 0 );
	}

	private static function finder_terminate( $run_color, $run_length, array &$history, $size ) {
		if ( $run_color ) {
			self::finder_history( $run_length, $history, $size );
			$run_length = 0;
		}
		$run_length += $size; // The light border after the last run.
		self::finder_history( $run_length, $history, $size );
		return self::finder_count( $history );
	}
}
