<?php
/**
 * Packing settings — the ONE option of the section, its pure sanitisers,
 * and the four saves of the Packing page.
 *
 * The option (fishotel_packing_settings, not autoloaded — only this
 * section's admin screens and contract functions ever read it):
 *
 *   bags        the standard bags: id (a number that is never reused, so a
 *               renamed bag keeps every product that carries it), name,
 *               width and length in inches (optional), sort. Nothing is
 *               seeded.
 *   last_bag_id the highest id ever given.
 *   boxes       this section's OWN list of boxes (name, length, width,
 *               height in inches) — used only while ShipTracker provides
 *               none (Boxes::current()).
 *   fits        how many bags of each size fit each box: per box (keyed by
 *               its lower-cased name, with the name and the size it had
 *               when typed) bag id → a whole number. A bag that is not
 *               listed does not go in that box.
 *   size_map    the size → bag map: size slug → bag id.
 *   threshold   a suggested box is "on the line" at or over this percent
 *               (default 90).
 *
 * Every save — bags_request(), boxes_request(), fits_request(),
 * map_request() — checks the capability (manage_woocommerce) and the
 * nonce itself, validates everything before it writes anything, and
 * answers with a status: one invalid value and nothing is saved. The AJAX
 * handlers only send that answer, so the saves run headless.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Nonce action of every form on the Packing page.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_packing_settings';

	const THRESHOLD_DEFAULT = 90;
	const THRESHOLD_MIN     = 1;
	const THRESHOLD_MAX     = 100;

	const MAX_BAGS  = 30;
	const MAX_BOXES = 40;
	const MAX_FIT   = 9999;
	const MAX_NAME  = 40;
	const MAX_DIM   = 999.99;
	const MAX_SORT  = 9999;
	const MAX_MAP   = 300;

	/**
	 * How many "how many fit" entries are kept in all — the boxes in use
	 * plus those of boxes that are not in the list right now (the other
	 * source's, a renamed one's), which are kept so nothing typed is lost.
	 *
	 * @var int
	 */
	const MAX_FIT_ENTRIES = 120;

	/**
	 * Register the AJAX saves.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_packing_bags', array( $this, 'ajax_bags' ) );
		add_action( 'wp_ajax_fishotel_packing_boxes', array( $this, 'ajax_boxes' ) );
		add_action( 'wp_ajax_fishotel_packing_fits', array( $this, 'ajax_fits' ) );
		add_action( 'wp_ajax_fishotel_packing_map', array( $this, 'ajax_map' ) );
	}

	/* ============================================================== */
	/*  Storage                                                        */
	/* ============================================================== */

	/**
	 * Nothing typed yet.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'bags'        => array(),
			'last_bag_id' => 0,
			'boxes'       => array(),
			'fits'        => array(),
			'size_map'    => array(),
			'threshold'   => self::THRESHOLD_DEFAULT,
		);
	}

	/**
	 * The settings as stored, cleaned.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( Packing::OPTION_KEY, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Store the settings (cleaned first). The option is not autoloaded.
	 *
	 * @param array $settings Settings.
	 * @return array What was stored.
	 */
	public static function store( array $settings ) {
		$settings = self::normalize( $settings );
		update_option( Packing::OPTION_KEY, $settings, false );
		return $settings;
	}

	/**
	 * Clean a settings array (pure): every key typed, every reference to a
	 * bag that no longer exists dropped. Whatever the option holds, what
	 * comes out is safe to use.
	 *
	 * @param array $raw Raw settings.
	 * @return array
	 */
	public static function normalize( array $raw ) {
		$bags    = array();
		$known   = array();
		$last_id = isset( $raw['last_bag_id'] ) && is_numeric( $raw['last_bag_id'] ) ? max( 0, (int) $raw['last_bag_id'] ) : 0;

		foreach ( isset( $raw['bags'] ) && is_array( $raw['bags'] ) ? $raw['bags'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id   = isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0;
			$name = self::name_in( $row['name'] ?? '' );
			if ( $id < 1 || '' === $name || isset( $known[ $id ] ) ) {
				continue;
			}
			$width  = self::dim_in( $row['width'] ?? '' );
			$length = self::dim_in( $row['length'] ?? '' );
			$sort   = isset( $row['sort'] ) && is_numeric( $row['sort'] ) ? (int) $row['sort'] : 0;

			$known[ $id ] = true;
			$bags[]       = array(
				'id'     => $id,
				'name'   => $name,
				'width'  => null === $width ? '' : $width,
				'length' => null === $length ? '' : $length,
				'sort'   => max( -self::MAX_SORT, min( self::MAX_SORT, $sort ) ),
			);
			$last_id      = max( $last_id, $id );
			if ( count( $bags ) >= self::MAX_BAGS ) {
				break;
			}
		}
		usort( $bags, array( self::class, 'compare_bags' ) );

		$boxes = array();
		$seen  = array();
		foreach ( isset( $raw['boxes'] ) && is_array( $raw['boxes'] ) ? $raw['boxes'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name   = self::name_in( $row['name'] ?? '' );
			$length = Boxes::number( $row['length'] ?? '' );
			$width  = Boxes::number( $row['width'] ?? '' );
			$height = Boxes::number( $row['height'] ?? '' );
			$key    = Boxes::key( $name );
			if ( '' === $key || null === $length || null === $width || null === $height || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$boxes[]      = array(
				'name'   => $name,
				'length' => Boxes::num_text( $length ),
				'width'  => Boxes::num_text( $width ),
				'height' => Boxes::num_text( $height ),
			);
			if ( count( $boxes ) >= self::MAX_BOXES ) {
				break;
			}
		}

		$fits = array();
		foreach ( isset( $raw['fits'] ) && is_array( $raw['fits'] ) ? $raw['fits'] : array() as $key => $entry ) {
			$key = Boxes::key( (string) $key );
			if ( '' === $key || ! is_array( $entry ) || isset( $fits[ $key ] ) ) {
				continue;
			}
			$counts = array();
			foreach ( isset( $entry['bags'] ) && is_array( $entry['bags'] ) ? $entry['bags'] : array() as $bag_id => $count ) {
				$bag_id = (int) $bag_id;
				if ( ! isset( $known[ $bag_id ] ) || ! is_numeric( $count ) ) {
					continue;
				}
				$count = (int) $count;
				if ( $count >= 1 && $count <= self::MAX_FIT ) {
					$counts[ $bag_id ] = $count;
				}
			}
			if ( ! $counts ) {
				continue;
			}
			$name = self::name_in( $entry['name'] ?? '' );
			$dims = isset( $entry['dims'] ) && is_string( $entry['dims'] ) && 1 === preg_match( '/^\d+(\.\d+)?x\d+(\.\d+)?x\d+(\.\d+)?$/', $entry['dims'] ) ? $entry['dims'] : '';

			$fits[ $key ] = array(
				'name' => '' !== $name ? $name : $key,
				'dims' => $dims,
				'bags' => $counts,
			);
			if ( count( $fits ) >= self::MAX_FIT_ENTRIES ) {
				break;
			}
		}

		$map = array();
		foreach ( isset( $raw['size_map'] ) && is_array( $raw['size_map'] ) ? $raw['size_map'] : array() as $slug => $bag_id ) {
			$slug   = self::slug_in( $slug );
			$bag_id = is_numeric( $bag_id ) ? (int) $bag_id : 0;
			if ( '' === $slug || ! isset( $known[ $bag_id ] ) ) {
				continue;
			}
			$map[ $slug ] = $bag_id;
			if ( count( $map ) >= self::MAX_MAP ) {
				break;
			}
		}

		$threshold = self::threshold_in( $raw['threshold'] ?? null );

		return array(
			'bags'        => $bags,
			'last_bag_id' => $last_id,
			'boxes'       => $boxes,
			'fits'        => $fits,
			'size_map'    => $map,
			'threshold'   => null === $threshold ? self::THRESHOLD_DEFAULT : $threshold,
		);
	}

	/**
	 * The order of the bag list: the sort number, then the name, then the id.
	 *
	 * @param array $a One bag.
	 * @param array $b Another.
	 * @return int
	 */
	public static function compare_bags( array $a, array $b ) {
		$by_sort = (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 );
		if ( 0 !== $by_sort ) {
			return $by_sort;
		}
		$by_name = strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
		return 0 !== $by_name ? $by_name : ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
	}

	/* ============================================================== */
	/*  Field sanitisers (pure)                                        */
	/* ============================================================== */

	/**
	 * A name as plain text: tags, control characters and backslashes
	 * dropped (a backslash would not survive WordPress's meta functions
	 * when the name is stored on an order), runs of whitespace collapsed,
	 * at most MAX_NAME characters. '' for anything that is not text.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function name_in( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return '';
		}
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $raw );
		$text = str_replace( '\\', '', strip_tags( $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_NAME ) : substr( $text, 0, self::MAX_NAME );
	}

	/**
	 * A size slug as a key: text, trimmed, at most 100 bytes.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function slug_in( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return '';
		}
		$slug = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', (string) $raw ) );
		return strlen( $slug ) > Size_List::MAX_LABEL ? '' : $slug;
	}

	/**
	 * An optional dimension in inches: '' when blank, the number as text
	 * ("6", "6.5") when it is above 0 and at most MAX_DIM, null when it is
	 * anything else.
	 *
	 * @param mixed $raw Raw value.
	 * @return string|null
	 */
	public static function dim_in( $raw ) {
		if ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
			return '';
		}
		$number = Boxes::number( $raw );
		return null === $number ? null : Boxes::num_text( $number );
	}

	/**
	 * The "on the line" percent: a whole number from THRESHOLD_MIN to
	 * THRESHOLD_MAX, null for anything else.
	 *
	 * @param mixed $raw Raw value.
	 * @return int|null
	 */
	public static function threshold_in( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( 1 !== preg_match( '/^\d{1,3}$/', $text ) ) {
			return null;
		}
		$value = (int) $text;
		return ( $value < self::THRESHOLD_MIN || $value > self::THRESHOLD_MAX ) ? null : $value;
	}

	/**
	 * A "how many fit" cell: 0 for a blank (or a typed 0 — the bag does not
	 * go in that box), the whole number from 1 to MAX_FIT, null for
	 * anything else.
	 *
	 * @param mixed $raw Raw value.
	 * @return int|null
	 */
	public static function fit_in( $raw ) {
		if ( null === $raw ) {
			return 0;
		}
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return 0;
		}
		if ( 1 !== preg_match( '/^\d{1,6}$/', $text ) ) {
			return null;
		}
		$value = (int) $text;
		return $value > self::MAX_FIT ? null : $value;
	}

	/* ============================================================== */
	/*  The saves — pure halves                                        */
	/* ============================================================== */

	/**
	 * The bag list after a posted form (pure). Every stored bag stays
	 * unless its row is ticked "remove"; a row with a known id changes
	 * that bag; a row without one adds a bag under the next id. A bag that
	 * the form did not post is left alone (a stale page never deletes) —
	 * and a row whose id is not in the list any more (the bag was removed
	 * since the page was drawn) is refused: it never comes back under a new
	 * id, with its products, its sizes and its numbers left behind.
	 *
	 * @param array $settings Stored settings (normalised).
	 * @param array $rows     bags[n][id|name|width|length|sort|remove].
	 * @return array{settings: array, errors: string[], removed: array[], added: int}
	 */
	public static function apply_bags( array $settings, array $rows ) {
		$by_id   = array();
		$last_id = (int) $settings['last_bag_id'];
		foreach ( $settings['bags'] as $bag ) {
			$by_id[ (int) $bag['id'] ] = $bag;
		}

		$errors  = array();
		$removed = array();
		$added   = 0;
		$line    = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line++;
			$id       = isset( $row['id'] ) && is_scalar( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0;
			$existing = $id > 0 && isset( $by_id[ $id ] );
			$name     = self::name_in( $row['name'] ?? '' );
			$blank    = '' === $name && self::is_blank( $row['width'] ?? '' ) && self::is_blank( $row['length'] ?? '' );

			if ( ! empty( $row['remove'] ) ) {
				if ( $existing ) {
					$removed[] = $by_id[ $id ];
					unset( $by_id[ $id ] );
				}
				continue;
			}
			if ( ! $existing && $blank ) {
				continue; // An empty spare row.
			}
			if ( ! $existing && ! self::is_blank( $row['id'] ?? '' ) ) {
				/* translators: %s: bag name, or its number */
				$errors[] = sprintf( __( '%s is not in the list of bags any more — reload the page', 'fishotel-misc-plugin' ), '' !== $name ? '"' . $name . '"' : '#' . $id );
				continue;
			}
			if ( '' === $name ) {
				/* translators: %d: row number */
				$errors[] = sprintf( __( 'row %d: a bag needs a name', 'fishotel-misc-plugin' ), $line );
				continue;
			}

			$width  = self::dim_in( $row['width'] ?? '' );
			$length = self::dim_in( $row['length'] ?? '' );
			if ( null === $width || null === $length ) {
				/* translators: 1: bag name, 2: the largest size allowed */
				$errors[] = sprintf( __( '%1$s: width and length are inches above 0 and at most %2$s, or blank', 'fishotel-misc-plugin' ), $name, Boxes::num_text( self::MAX_DIM ) );
				continue;
			}

			$sort = $line * 10;
			if ( ! self::is_blank( $row['sort'] ?? '' ) ) {
				$text = is_scalar( $row['sort'] ) ? trim( (string) $row['sort'] ) : 'x';
				if ( 1 !== preg_match( '/^-?\d{1,4}$/', $text ) ) {
					/* translators: %s: bag name */
					$errors[] = sprintf( __( '%s: the sort order is a whole number', 'fishotel-misc-plugin' ), $name );
					continue;
				}
				$sort = (int) $text;
			}

			if ( ! $existing ) {
				$last_id++;
				$id = $last_id;
				$added++;
			}
			$by_id[ $id ] = array(
				'id'     => $id,
				'name'   => $name,
				'width'  => $width,
				'length' => $length,
				'sort'   => $sort,
			);
		}

		$names = array();
		foreach ( $by_id as $bag ) {
			$key = Boxes::key( $bag['name'] );
			if ( isset( $names[ $key ] ) ) {
				/* translators: %s: bag name */
				$errors[] = sprintf( __( 'two bags are named "%s"', 'fishotel-misc-plugin' ), $bag['name'] );
			}
			$names[ $key ] = true;
		}
		if ( count( $by_id ) > self::MAX_BAGS ) {
			/* translators: %d: the most bags allowed */
			$errors[] = sprintf( __( 'at most %d bags', 'fishotel-misc-plugin' ), self::MAX_BAGS );
		}

		$settings['bags']        = array_values( $by_id );
		$settings['last_bag_id'] = $last_id;

		return array(
			'settings' => $errors ? $settings : self::normalize( $settings ),
			'errors'   => array_values( array_unique( $errors ) ),
			'removed'  => $removed,
			'added'    => $added,
		);
	}

	/**
	 * This section's own box list after a posted form (pure): the posted
	 * rows ARE the list. Each row of a stored box says which box it was
	 * drawn as (`was`, its name then); a row that comes back with another
	 * name was renamed — its "how many fit" follows the new name when
	 * $move_fits says the own list is the one in use. The row's NUMBER says
	 * nothing: a form posted twice, or from a page that is out of date, has
	 * its rows in other places than the stored list, and must never move
	 * one box's numbers onto another. A `was` that names no stored box, or
	 * a box that is still in the posted list, is no rename.
	 *
	 * @param array $settings  Stored settings (normalised).
	 * @param array $rows      boxes[n][was|name|length|width|height|remove].
	 * @param bool  $move_fits Whether a rename carries the box's "how many fit" along.
	 * @return array{settings: array, errors: string[], renamed: array<string, string>}
	 */
	public static function apply_boxes( array $settings, array $rows, $move_fits = true ) {
		$stored = array();
		foreach ( $settings['boxes'] as $box ) {
			$stored[ Boxes::key( $box['name'] ) ] = true;
		}
		$boxes   = array();
		$seen    = array();
		$errors  = array();
		$renamed = array();
		$line    = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line++;
			if ( ! empty( $row['remove'] ) ) {
				continue;
			}
			$name  = self::name_in( $row['name'] ?? '' );
			$blank = '' === $name && self::is_blank( $row['length'] ?? '' ) && self::is_blank( $row['width'] ?? '' ) && self::is_blank( $row['height'] ?? '' );
			if ( $blank ) {
				continue;
			}
			if ( '' === $name ) {
				/* translators: %d: row number */
				$errors[] = sprintf( __( 'row %d: a box needs a name', 'fishotel-misc-plugin' ), $line );
				continue;
			}
			$length = Boxes::number( $row['length'] ?? '' );
			$width  = Boxes::number( $row['width'] ?? '' );
			$height = Boxes::number( $row['height'] ?? '' );
			if ( null === $length || null === $width || null === $height ) {
				/* translators: 1: box name, 2: the largest size allowed */
				$errors[] = sprintf( __( '%1$s: length, width and height are inches above 0 and at most %2$s', 'fishotel-misc-plugin' ), $name, Boxes::num_text( self::MAX_DIM ) );
				continue;
			}
			$key = Boxes::key( $name );
			if ( isset( $seen[ $key ] ) ) {
				/* translators: %s: box name */
				$errors[] = sprintf( __( 'two boxes are named "%s"', 'fishotel-misc-plugin' ), $name );
				continue;
			}
			$seen[ $key ] = true;
			$boxes[]      = array(
				'name'   => $name,
				'length' => Boxes::num_text( $length ),
				'width'  => Boxes::num_text( $width ),
				'height' => Boxes::num_text( $height ),
			);

			$old = Boxes::key( self::name_in( $row['was'] ?? '' ) );
			if ( '' !== $old && $old !== $key && isset( $stored[ $old ] ) ) {
				$renamed[ $old ] = $key;
			}
		}

		// A name that is still in the list was not renamed away.
		foreach ( array_keys( $renamed ) as $old ) {
			if ( isset( $seen[ $old ] ) ) {
				unset( $renamed[ $old ] );
			}
		}

		if ( count( $boxes ) > self::MAX_BOXES ) {
			/* translators: %d: the most boxes allowed */
			$errors[] = sprintf( __( 'at most %d boxes', 'fishotel-misc-plugin' ), self::MAX_BOXES );
		}

		if ( ! $errors && $move_fits ) {
			$fits = $settings['fits'];
			$move = array();
			foreach ( $renamed as $old => $new ) {
				if ( isset( $fits[ $old ] ) && ! isset( $fits[ $new ] ) ) {
					$move[ $new ] = $fits[ $old ];
					unset( $fits[ $old ] );
				}
			}
			foreach ( $move as $new => $entry ) {
				foreach ( $boxes as $box ) {
					if ( Boxes::key( $box['name'] ) === (string) $new ) {
						$entry['name'] = $box['name'];
						$entry['dims'] = Boxes::dims( $box['length'], $box['width'], $box['height'] );
					}
				}
				$fits[ $new ] = $entry;
			}
			$settings['fits'] = $fits;
		}

		$settings['boxes'] = $boxes;

		return array(
			'settings' => $errors ? $settings : self::normalize( $settings ),
			'errors'   => array_values( array_unique( $errors ) ),
			'renamed'  => $renamed,
		);
	}

	/**
	 * "How many fit" after a posted form (pure). Only the boxes the form
	 * posted change; what was typed for a box that is not in the list right
	 * now is kept. A box that had taken over the numbers of a renamed one
	 * (same size, see Boxes::with_fits()) keeps them under its own name.
	 *
	 * The form names the bag columns it showed (bag_ids[]); a bag that was
	 * added since — a column the form never had — keeps its numbers.
	 *
	 * @param array   $settings Stored settings (normalised).
	 * @param array[] $boxes    The boxes in use (Boxes::current()['boxes']).
	 * @param array   $raw      box_key[n], fits[n][bag id], bag_ids[], threshold.
	 * @return array{settings: array, errors: string[], cells: int}
	 */
	public static function apply_fits( array $settings, array $boxes, array $raw ) {
		$errors = array();
		$cells  = 0;
		$fits   = $settings['fits'];
		$keys   = isset( $raw['box_key'] ) && is_array( $raw['box_key'] ) ? $raw['box_key'] : array();
		$posted = isset( $raw['fits'] ) && is_array( $raw['fits'] ) ? $raw['fits'] : array();

		$columns = null;
		if ( isset( $raw['bag_ids'] ) && is_array( $raw['bag_ids'] ) ) {
			$columns = array();
			foreach ( $raw['bag_ids'] as $bag_id ) {
				if ( is_scalar( $bag_id ) && is_numeric( $bag_id ) ) {
					$columns[ (int) $bag_id ] = true;
				}
			}
		}

		$resolved = Boxes::with_fits( $boxes, $fits );

		foreach ( $resolved as $box ) {
			$index = null;
			foreach ( $keys as $n => $key ) {
				if ( is_scalar( $key ) && Boxes::key( (string) $key ) === $box['key'] ) {
					$index = $n;
					break;
				}
			}
			if ( null === $index ) {
				continue; // Not on the form: left as it is.
			}

			$row    = isset( $posted[ $index ] ) && is_array( $posted[ $index ] ) ? $posted[ $index ] : array();
			$counts = array();
			foreach ( $settings['bags'] as $bag ) {
				if ( null !== $columns && ! isset( $columns[ (int) $bag['id'] ] ) ) {
					// Not a column of the form: what is stored stays.
					if ( isset( $box['fits'][ (int) $bag['id'] ] ) ) {
						$counts[ (int) $bag['id'] ] = (int) $box['fits'][ (int) $bag['id'] ];
						$cells++;
					}
					continue;
				}
				$value = self::fit_in( $row[ $bag['id'] ] ?? null );
				if ( null === $value ) {
					/* translators: 1: box name, 2: bag name, 3: the largest number allowed */
					$errors[] = sprintf( __( '%1$s / %2$s: how many fit is a whole number from 1 to %3$d, or blank', 'fishotel-misc-plugin' ), $box['name'], $bag['name'], self::MAX_FIT );
					continue;
				}
				if ( $value >= 1 ) {
					$counts[ (int) $bag['id'] ] = $value;
					$cells++;
				}
			}

			// The numbers this box showed came from another name (a rename): they move here.
			if ( '' !== $box['fits_from'] && $box['fits_from'] !== $box['key'] ) {
				unset( $fits[ $box['fits_from'] ] );
			}
			unset( $fits[ $box['key'] ] );
			if ( $counts ) {
				$fits[ $box['key'] ] = array(
					'name' => $box['name'],
					'dims' => $box['dims'],
					'bags' => $counts,
				);
			}
		}

		if ( array_key_exists( 'threshold', $raw ) ) {
			$threshold = self::threshold_in( $raw['threshold'] );
			if ( null === $threshold ) {
				/* translators: 1: lowest percent, 2: highest percent */
				$errors[] = sprintf( __( '"on the line" is a whole percent from %1$d to %2$d', 'fishotel-misc-plugin' ), self::THRESHOLD_MIN, self::THRESHOLD_MAX );
			} else {
				$settings['threshold'] = $threshold;
			}
		}

		$settings['fits'] = $fits;

		return array(
			'settings' => $errors ? $settings : self::normalize( $settings ),
			'errors'   => array_values( array_unique( $errors ) ),
			'cells'    => $cells,
		);
	}

	/**
	 * The size → bag map after a posted form (pure). A row names a size
	 * (its slug) and a bag ('' = no bag for that size). Only sizes of the
	 * list — or sizes the map already holds — are accepted.
	 *
	 * @param array   $settings Stored settings (normalised).
	 * @param array[] $sizes    The standard sizes (slug, label).
	 * @param array   $rows     map[n][size|bag].
	 * @return array{settings: array, errors: string[], mapped: int}
	 */
	public static function apply_map( array $settings, array $sizes, array $rows ) {
		$errors = array();
		$map    = $settings['size_map'];
		$bags   = array();
		foreach ( $settings['bags'] as $bag ) {
			$bags[ (int) $bag['id'] ] = true;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = self::slug_in( $row['size'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! Size_List::find( $sizes, $slug ) && ! isset( $map[ $slug ] ) ) {
				/* translators: %s: size slug */
				$errors[] = sprintf( __( '"%s" is not one of the sizes', 'fishotel-misc-plugin' ), $slug );
				continue;
			}
			$bag = $row['bag'] ?? '';
			if ( self::is_blank( $bag ) ) {
				unset( $map[ $slug ] );
				continue;
			}
			if ( ! is_scalar( $bag ) || ! is_numeric( $bag ) || ! isset( $bags[ (int) $bag ] ) ) {
				/* translators: %s: size label */
				$errors[] = sprintf( __( '%s: that bag is not in the list any more', 'fishotel-misc-plugin' ), Size_List::label( $sizes, $slug ) );
				continue;
			}
			$map[ $slug ] = (int) $bag;
		}

		$settings['size_map'] = $map;
		$clean                = $errors ? $settings : self::normalize( $settings );

		return array(
			'settings' => $clean,
			'errors'   => array_values( array_unique( $errors ) ),
			'mapped'   => count( $clean['size_map'] ),
		);
	}

	/**
	 * Whether a posted field is blank.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_blank( $value ) {
		return null === $value || ( is_scalar( $value ) && ! is_bool( $value ) && '' === trim( (string) $value ) );
	}

	/* ============================================================== */
	/*  The saves — capability, nonce, write                           */
	/* ============================================================== */

	/**
	 * The capability and the nonce of a save. Null when both pass, else
	 * the answer to send.
	 *
	 * @param array  $raw    Unslashed POST.
	 * @param string $action Nonce action.
	 * @return array{status: int, ok: bool, message: string}|null
	 */
	public static function guard( array $raw, $action = self::NONCE_ACTION ) {
		if ( ! current_user_can( Packing::CAPABILITY ) ) {
			return array(
				'status'  => 403,
				'ok'      => false,
				'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ),
			);
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return array(
				'status'  => 403,
				'ok'      => false,
				'message' => __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' ),
			);
		}
		return null;
	}

	/**
	 * The answer for a form that had something wrong: nothing was saved.
	 *
	 * @param string[] $errors What was wrong.
	 * @return array{status: int, ok: bool, message: string}
	 */
	private static function refused( array $errors ) {
		return array(
			'status'  => 400,
			'ok'      => false,
			/* translators: %s: what was wrong, separated by semicolons */
			'message' => sprintf( __( 'Nothing was saved: %s.', 'fishotel-misc-plugin' ), implode( '; ', $errors ) ),
		);
	}

	/**
	 * Save the bag sizes.
	 *
	 * @param array       $raw   Unslashed POST: _wpnonce, bags[n][id|name|width|length|sort|remove].
	 * @param object|null $store A store with bag_usage(), to say how many products a removed bag was on.
	 * @return array{status: int, ok: bool, message: string, settings?: array}
	 */
	public static function bags_request( array $raw, $store = null ) {
		$denied = self::guard( $raw );
		if ( $denied ) {
			return $denied;
		}

		$result = self::apply_bags( self::get(), isset( $raw['bags'] ) && is_array( $raw['bags'] ) ? $raw['bags'] : array() );
		if ( $result['errors'] ) {
			return self::refused( $result['errors'] );
		}

		$usage = array();
		if ( $result['removed'] && is_object( $store ) && method_exists( $store, 'bag_usage' ) ) {
			try {
				$usage = (array) $store->bag_usage();
			} catch ( \Throwable $e ) {
				$usage = array();
			}
		}

		$settings = self::store( $result['settings'] );

		/* translators: %d: number of bags */
		$message = sprintf( _n( 'Bag sizes saved: %d bag.', 'Bag sizes saved: %d bags.', count( $settings['bags'] ), 'fishotel-misc-plugin' ), count( $settings['bags'] ) );
		foreach ( $result['removed'] as $bag ) {
			$on = (int) ( $usage[ (int) $bag['id'] ] ?? 0 );
			if ( $on > 0 ) {
				/* translators: 1: bag name, 2: number of products and variations */
				$message .= ' ' . sprintf( _n( '"%1$s" was removed; %2$d product or variation still names it and now follows the size map.', '"%1$s" was removed; %2$d products or variations still name it and now follow the size map.', $on, 'fishotel-misc-plugin' ), $bag['name'], $on );
			} else {
				/* translators: %s: bag name */
				$message .= ' ' . sprintf( __( '"%s" was removed.', 'fishotel-misc-plugin' ), $bag['name'] );
			}
		}

		return array(
			'status'   => 200,
			'ok'       => true,
			'message'  => $message,
			'settings' => $settings,
		);
	}

	/**
	 * Save this section's own list of boxes.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, boxes[n][was|name|length|width|height|remove].
	 * @return array{status: int, ok: bool, message: string, settings?: array}
	 */
	public static function boxes_request( array $raw ) {
		$denied = self::guard( $raw );
		if ( $denied ) {
			return $denied;
		}

		$stored  = self::get();
		$current = Boxes::current( $stored );
		$result  = self::apply_boxes( $stored, isset( $raw['boxes'] ) && is_array( $raw['boxes'] ) ? $raw['boxes'] : array(), Boxes::SOURCE_OWN === $current['source'] );
		if ( $result['errors'] ) {
			return self::refused( $result['errors'] );
		}

		$settings = self::store( $result['settings'] );

		/* translators: %d: number of boxes */
		$message = sprintf( _n( 'Boxes saved: %d box.', 'Boxes saved: %d boxes.', count( $settings['boxes'] ), 'fishotel-misc-plugin' ), count( $settings['boxes'] ) );
		if ( Boxes::SOURCE_OWN !== Boxes::current( $settings )['source'] ) {
			$message .= ' ' . __( 'They are not in use: ShipTracker provides the standard boxes on this site.', 'fishotel-misc-plugin' );
		}

		return array(
			'status'   => 200,
			'ok'       => true,
			'message'  => $message,
			'settings' => $settings,
		);
	}

	/**
	 * Save how many bags fit each box, and the "on the line" percent.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, box_key[n], fits[n][bag id], threshold.
	 * @return array{status: int, ok: bool, message: string, settings?: array}
	 */
	public static function fits_request( array $raw ) {
		$denied = self::guard( $raw );
		if ( $denied ) {
			return $denied;
		}

		$stored  = self::get();
		$current = Boxes::current( $stored );
		$result  = self::apply_fits( $stored, $current['boxes'], $raw );
		if ( $result['errors'] ) {
			return self::refused( $result['errors'] );
		}

		$settings = self::store( $result['settings'] );

		return array(
			'status'   => 200,
			'ok'       => true,
			/* translators: 1: number of filled cells, 2: percent */
			'message'  => sprintf( __( 'Saved: %1$d "how many fit" numbers; a box is on the line at %2$d%% or more.', 'fishotel-misc-plugin' ), $result['cells'], $settings['threshold'] ),
			'settings' => $settings,
		);
	}

	/**
	 * Save the size → bag map.
	 *
	 * @param array      $raw      Unslashed POST: _wpnonce, map[n][size|bag].
	 * @param array|null $standard The size list (null = Size_List::standard()).
	 * @return array{status: int, ok: bool, message: string, settings?: array}
	 */
	public static function map_request( array $raw, $standard = null ) {
		$denied = self::guard( $raw );
		if ( $denied ) {
			return $denied;
		}

		if ( ! is_array( $standard ) ) {
			$standard = Size_List::standard();
		}
		$sizes  = isset( $standard['sizes'] ) && is_array( $standard['sizes'] ) ? $standard['sizes'] : array();
		$result = self::apply_map( self::get(), $sizes, isset( $raw['map'] ) && is_array( $raw['map'] ) ? $raw['map'] : array() );
		if ( $result['errors'] ) {
			return self::refused( $result['errors'] );
		}

		$settings = self::store( $result['settings'] );

		return array(
			'status'   => 200,
			'ok'       => true,
			/* translators: %d: number of sizes with a bag */
			'message'  => sprintf( _n( 'Size → bag map saved: %d size has a bag.', 'Size → bag map saved: %d sizes have a bag.', $result['mapped'], 'fishotel-misc-plugin' ), $result['mapped'] ),
			'settings' => $settings,
		);
	}

	/* ============================================================== */
	/*  AJAX                                                           */
	/* ============================================================== */

	/**
	 * Send the answer of a save.
	 *
	 * @param array $outcome status, ok, message.
	 */
	public static function reply( array $outcome ) {
		if ( empty( $outcome['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) $outcome['message'] ), (int) $outcome['status'] );
		}
		unset( $outcome['settings'], $outcome['status'], $outcome['ok'] );
		wp_send_json_success( $outcome );
	}

	/**
	 * AJAX: save the bag sizes (bags_request()).
	 */
	public function ajax_bags() {
		self::reply( self::bags_request( wp_unslash( $_POST ), new Store() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * AJAX: save the own boxes (boxes_request()).
	 */
	public function ajax_boxes() {
		self::reply( self::boxes_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * AJAX: save how many fit (fits_request()).
	 */
	public function ajax_fits() {
		self::reply( self::fits_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * AJAX: save the size → bag map (map_request()).
	 */
	public function ajax_map() {
		self::reply( self::map_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}
}
