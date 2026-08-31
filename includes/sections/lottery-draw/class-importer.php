<?php
/**
 * Lottery Draw JSON importer — bulk entry of draw *inputs*.
 *
 * Building a draw by hand means retyping fifteen fish and fifty entrant
 * lines into textareas, and one mistyped handle is a person who silently
 * loses their shot. The data is generated upstream, so it gets pasted in
 * whole.
 *
 * THE RULE THAT MATTERS MOST: a payload carrying `winners` or `waitlist`
 * is refused outright — never stripped and imported anyway. If results
 * could be imported, anyone with admin access could paste a hand-picked
 * winner list and publish it behind a green "verified in your browser"
 * banner, which is worse than having no verification at all: it launders
 * a rigged draw as an honest one. Results may only ever come out of
 * draw.js running against a committed seed. The importer supplies inputs.
 *
 * For the same reason the importer only ever writes a draw in `draft`
 * status. Importing into a committed or published draw is refused, with
 * no force flag.
 *
 * Everything in this file is pure: validation and normalisation only, no
 * database and no WordPress state, so the headless test harness can
 * exercise it directly.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

/**
 * Class Importer
 */
class Importer {

	/**
	 * Largest payload accepted, in bytes.
	 *
	 * A big group order runs to a few tens of KB; this leaves generous
	 * headroom while still failing loudly rather than parsing something
	 * truncated.
	 *
	 * @var int
	 */
	const MAX_BYTES = 524288;

	/**
	 * Keys that may never appear anywhere in an imported payload.
	 *
	 * @var array<int, string>
	 */
	const RESULT_KEYS = array( 'winners', 'waitlist' );

	/**
	 * Validate and normalise a pasted payload.
	 *
	 * @param string $json Raw pasted JSON.
	 * @return array{
	 *     ok: bool,
	 *     errors: array<int, string>,
	 *     warnings: array<int, string>,
	 *     payload: array|null,
	 *     preview: array<int, array>,
	 *     totals: array{fish: int, tickets: int, people: int}
	 * }
	 */
	public static function validate( $json ) {
		$result = array(
			'ok'       => false,
			'errors'   => array(),
			'warnings' => array(),
			'payload'  => null,
			'preview'  => array(),
			'totals'   => array(
				'fish'    => 0,
				'tickets' => 0,
				'people'  => 0,
			),
		);

		$json = (string) $json;

		if ( '' === trim( $json ) ) {
			$result['errors'][] = __( 'Paste a payload first.', 'fishotel-misc-plugin' );
			return $result;
		}

		if ( strlen( $json ) > self::MAX_BYTES ) {
			$result['errors'][] = sprintf(
				/* translators: 1: payload size in KB, 2: limit in KB */
				__( 'That payload is %1$d KB; the limit is %2$d KB. Split the draw or trim it rather than letting it be cut short.', 'fishotel-misc-plugin' ),
				(int) round( strlen( $json ) / 1024 ),
				(int) round( self::MAX_BYTES / 1024 )
			);
			return $result;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			$result['errors'][] = __( 'Not valid JSON — check for a trailing comma.', 'fishotel-misc-plugin' );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$result['errors'][] = sprintf(
					/* translators: %s: the JSON parser's own message */
					__( 'The parser said: %s', 'fishotel-misc-plugin' ),
					json_last_error_msg()
				);
			}

			return $result;
		}

		// Results check first, and on its own. A payload carrying results
		// is refused whole — we do not report it alongside lesser problems
		// as though it were one more thing to tidy up.
		$offenders = self::find_result_keys( $data );

		if ( $offenders ) {
			$result['errors'][] = __( 'Payload contains results. Results can only come from a draw — remove them and re-import.', 'fishotel-misc-plugin' );

			foreach ( $offenders as $offender ) {
				$result['errors'][] = sprintf(
					/* translators: 1: where the key was found, 2: the offending key */
					__( 'Found in %1$s: "%2$s".', 'fishotel-misc-plugin' ),
					$offender['where'],
					$offender['key']
				);
			}

			return $result;
		}

		if ( ! isset( $data['fish'] ) || ! is_array( $data['fish'] ) || ! $data['fish'] ) {
			$result['errors'][] = __( 'No fish in the payload.', 'fishotel-misc-plugin' );
			return $result;
		}

		$fish_out = array();
		$seen     = array();
		$people   = array();
		$tickets  = 0;

		foreach ( array_values( $data['fish'] ) as $index => $fish ) {
			$position = $index + 1;

			if ( ! is_array( $fish ) ) {
				/* translators: %d: position of the fish in the payload */
				$result['errors'][] = sprintf( __( 'Fish #%d is not an object.', 'fishotel-misc-plugin' ), $position );
				continue;
			}

			$name = is_string( $fish['name'] ?? null ) ? trim( $fish['name'] ) : '';

			if ( '' === $name ) {
				/* translators: %d: position of the fish in the payload */
				$result['errors'][] = sprintf( __( 'Fish #%d has no name.', 'fishotel-misc-plugin' ), $position );
				continue;
			}

			// Fish names must be unique case-insensitively: the draw seeds
			// its RNG stream from the lowercased name, so two fish sharing
			// one would share a stream and draw the same shuffle.
			$key = self::lower( $name );

			if ( isset( $seen[ $key ] ) ) {
				/* translators: %s: fish name */
				$result['errors'][] = sprintf( __( 'Duplicate fish name: %s', 'fishotel-misc-plugin' ), $name );
				continue;
			}

			$seen[ $key ] = true;

			$stock = self::as_int( $fish['stock'] ?? null, $stock_ok );

			if ( ! $stock_ok || $stock < 0 ) {
				/* translators: %s: fish name */
				$result['errors'][] = sprintf( __( '%s: stock must be a whole number.', 'fishotel-misc-plugin' ), $name );
				continue;
			}

			$group_size = 1;

			if ( isset( $fish['groupSize'] ) && '' !== $fish['groupSize'] ) {
				$group_size = self::as_int( $fish['groupSize'], $group_ok );

				if ( ! $group_ok || $group_size < 1 ) {
					/* translators: %s: fish name */
					$result['errors'][] = sprintf( __( '%s: group size must be a whole number of 1 or more.', 'fishotel-misc-plugin' ), $name );
					continue;
				}
			}

			if ( ! isset( $fish['entrants'] ) || ! is_array( $fish['entrants'] ) || ! $fish['entrants'] ) {
				/* translators: %s: fish name */
				$result['errors'][] = sprintf( __( '%s has no entrants.', 'fishotel-misc-plugin' ), $name );
				continue;
			}

			$entrants   = array();
			$fish_valid = true;

			foreach ( array_values( $fish['entrants'] ) as $entrant_index => $entrant ) {
				$entrant_position = $entrant_index + 1;

				if ( ! is_array( $entrant ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: fish name, 2: position of the entrant */
						__( '%1$s: entrant #%2$d is not an object.', 'fishotel-misc-plugin' ),
						$name,
						$entrant_position
					);
					$fish_valid = false;
					continue;
				}

				$entrant_name = is_string( $entrant['name'] ?? null ) ? trim( $entrant['name'] ) : '';

				if ( '' === $entrant_name ) {
					$result['errors'][] = sprintf(
						/* translators: 1: fish name, 2: position of the entrant */
						__( '%1$s: entrant #%2$d has no name.', 'fishotel-misc-plugin' ),
						$name,
						$entrant_position
					);
					$fish_valid = false;
					continue;
				}

				$want = 1;

				if ( isset( $entrant['want'] ) && '' !== $entrant['want'] ) {
					$want = self::as_int( $entrant['want'], $want_ok );

					if ( ! $want_ok || $want < 1 ) {
						$result['errors'][] = sprintf(
							/* translators: 1: fish name, 2: entrant name */
							__( '%1$s: %2$s must want a whole number of 1 or more.', 'fishotel-misc-plugin' ),
							$name,
							$entrant_name
						);
						$fish_valid = false;
						continue;
					}
				}

				$entrants[] = array(
					'name' => $entrant_name,
					'want' => $want,
				);
			}

			if ( ! $fish_valid || ! $entrants ) {
				continue;
			}

			// Merge duplicate handles case-insensitively, keeping the
			// position and spelling of the first appearance and summing
			// their wants. Order is otherwise untouched — it is posting
			// order, and members check it against the thread.
			$entrants = Store::normalize_entrants( $entrants );

			$fish_record = array(
				'name'      => $name,
				'sci'       => is_string( $fish['sci'] ?? null ) ? trim( $fish['sci'] ) : '',
				'stock'     => $stock,
				'groupSize' => $group_size,
				'entrants'  => $entrants,
				'winners'   => array(),
				'waitlist'  => array(),
			);

			$fish_out[] = $fish_record;

			foreach ( $entrants as $entrant ) {
				$people[ self::lower( $entrant['name'] ) ] = true;
				$tickets                                  += $entrant['want'];
			}
		}

		if ( $result['errors'] ) {
			return $result;
		}

		if ( ! $fish_out ) {
			$result['errors'][] = __( 'No fish in the payload.', 'fishotel-misc-plugin' );
			return $result;
		}

		$result['ok']       = true;
		$result['preview']  = self::preview( $fish_out );
		$result['warnings'] = self::warnings( $fish_out );
		$result['totals']   = array(
			'fish'    => count( $fish_out ),
			'tickets' => $tickets,
			'people'  => count( $people ),
		);

		$result['payload'] = array(
			'id'          => is_string( $data['id'] ?? null ) ? trim( $data['id'] ) : '',
			'title'       => is_string( $data['title'] ?? null ) ? trim( $data['title'] ) : '',
			'seed'        => is_string( $data['seed'] ?? null ) ? trim( $data['seed'] ) : '',
			'seed_method' => ( Lottery_Draw::SEED_EXTERNAL === ( $data['seed_method'] ?? '' ) )
				? Lottery_Draw::SEED_EXTERNAL
				: Lottery_Draw::SEED_COMMIT_EARLY,
			'seed_source' => is_string( $data['seed_source'] ?? null ) ? trim( $data['seed_source'] ) : '',
			'fish'        => $fish_out,
		);

		return $result;
	}

	/**
	 * Walk the whole decoded payload looking for result keys.
	 *
	 * Presence alone is the failure — an empty `"winners": []` counts,
	 * because the question is whether the payload is a set of inputs or
	 * something that has already been drawn.
	 *
	 * @param mixed  $node  Decoded node.
	 * @param string $where Human-readable location of this node.
	 * @return array<int, array{key: string, where: string}>
	 */
	public static function find_result_keys( $node, $where = '' ) {
		if ( ! is_array( $node ) ) {
			return array();
		}

		$found = array();

		if ( '' === $where ) {
			$where = __( 'the payload itself', 'fishotel-misc-plugin' );
		}

		// Name the location by the node's own `name` when it has one, so
		// the admin is told which fish to fix rather than a JSON path.
		if ( isset( $node['name'] ) && is_string( $node['name'] ) && '' !== trim( $node['name'] ) ) {
			$where = sprintf(
				/* translators: %s: the name found in the payload */
				__( '"%s"', 'fishotel-misc-plugin' ),
				trim( $node['name'] )
			);
		}

		foreach ( self::RESULT_KEYS as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				$found[] = array(
					'key'   => $key,
					'where' => $where,
				);
			}
		}

		foreach ( $node as $child_key => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$child_where = is_string( $child_key ) ? sprintf( '%s → %s', $where, $child_key ) : $where;

			$found = array_merge( $found, self::find_result_keys( $child, $child_where ) );
		}

		return $found;
	}

	/**
	 * Build the preview rows the admin confirms before anything is written.
	 *
	 * @param array $fish_list Normalised fish records.
	 * @return array<int, array>
	 */
	public static function preview( array $fish_list ) {
		$rows = array();

		foreach ( $fish_list as $fish ) {
			$slots   = Store::slots_for( $fish );
			$tickets = Store::ticket_count( $fish );

			$rows[] = array(
				'name'      => $fish['name'],
				'stock'     => (int) $fish['stock'],
				'groupSize' => (int) $fish['groupSize'],
				'slots'     => $slots,
				'tickets'   => $tickets,
				'people'    => count( $fish['entrants'] ),
				// A contested fish is drawn and produces a waitlist. An
				// uncontested one is ranked only: everyone wins, and the
				// draw exists to fix the order for later losses.
				'mode'      => ( $tickets > $slots ) ? 'draw' : 'rank',
				'missing'   => max( 0, $tickets - $slots ),
				'spare'     => max( 0, $slots - $tickets ),
				'sole'      => self::is_sole_entrant( $fish ),
			);
		}

		return $rows;
	}

	/**
	 * Whether one person holds every ticket for a fish.
	 *
	 * @param array $fish Normalised fish record.
	 * @return bool
	 */
	public static function is_sole_entrant( array $fish ) {
		return 1 === count( $fish['entrants'] ) && Store::ticket_count( $fish ) > 0;
	}

	/**
	 * Non-blocking warnings for the preview.
	 *
	 * @param array $fish_list Normalised fish records.
	 * @return array<int, string>
	 */
	public static function warnings( array $fish_list ) {
		$warnings = array();

		foreach ( $fish_list as $fish ) {
			if ( ! self::is_sole_entrant( $fish ) ) {
				continue;
			}

			$warnings[] = sprintf(
				/* translators: 1: fish name, 2: the only entrant's name */
				__( '%1$s: %2$s holds every ticket — a draw against themselves. Keep it only if including it is deliberate.', 'fishotel-misc-plugin' ),
				$fish['name'],
				$fish['entrants'][0]['name']
			);
		}

		return $warnings;
	}

	/**
	 * Build the draft payload an import will store.
	 *
	 * @param array      $import   The `payload` from a successful validate().
	 * @param array|null $existing Existing draw payload when importing into one.
	 * @param string     $id       Draw ID from the form (new draws only).
	 * @param string     $title    Draw title from the form (new draws only).
	 * @return array|\WP_Error
	 */
	public static function build_payload( array $import, $existing, $id, $title ) {
		if ( is_array( $existing ) ) {
			// Import only ever touches a draft. A committed draw has had
			// its entrants locked precisely so nobody can change what the
			// seed will be applied to, and a published one is evidence.
			if ( ! Store::is_editable( $existing ) ) {
				return new \WP_Error(
					'fh_draw_import_locked',
					__( 'That draw is no longer a draft. Import can only ever fill a draft — a committed or published draw has locked its entrants on purpose.', 'fishotel-misc-plugin' )
				);
			}

			$existing['fish'] = $import['fish'];

			return $existing;
		}

		$title = trim( $title );

		if ( '' === $title ) {
			$title = $import['title'];
		}

		$id = sanitize_title( '' !== trim( (string) $id ) ? $id : ( $import['id'] ? $import['id'] : $title ) );

		if ( '' === $title || '' === $id ) {
			return new \WP_Error(
				'fh_draw_import_identity',
				__( 'This draw needs a title and an ID — the payload carried neither, so type them in.', 'fishotel-misc-plugin' )
			);
		}

		return array(
			'id'                => $id,
			'title'             => $title,
			'seed'              => $import['seed'],
			'seed_method'       => $import['seed_method'],
			'seed_source'       => $import['seed_source'],
			'supersedes'        => '',
			'seed_published_at' => '',
			'drawn_at'          => '',
			'status'            => Lottery_Draw::STATUS_DRAFT,
			'created_at'        => Lottery_Draw::now_utc(),
			'fish'              => $import['fish'],
		);
	}

	/**
	 * Read a value that must be a whole number.
	 *
	 * @param mixed $value Raw value.
	 * @param bool  $ok    Set to false when the value is not integral.
	 * @return int
	 */
	private static function as_int( $value, &$ok ) {
		$ok = false;

		if ( is_int( $value ) ) {
			$ok = true;
			return $value;
		}

		if ( is_float( $value ) ) {
			$ok = ( floor( $value ) === $value );
			return (int) $value;
		}

		if ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			$ok = true;
			return (int) trim( $value );
		}

		return 0;
	}

	/**
	 * Lowercase a string, multibyte-aware where possible.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
