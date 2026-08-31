<?php
/**
 * Lottery Draw storage — persistence, lifecycle, and the immutability
 * rules that make a published draw evidence rather than a claim.
 *
 * This class stores inputs and stores the winner list the admin's browser
 * produced. It never draws. The only calculation it performs on a result
 * is a structural sanity check (see validate_results()): that the browser
 * handed back a permutation of the ticket list and did not award more
 * slots than exist. That is arithmetic about the result, not a second
 * implementation of the draw.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * JSON encoding flags. Pretty-printed and unescaped so a member can
	 * read the raw payload URL without tooling.
	 *
	 * @var int
	 */
	const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * How many import-log entries to keep per draw.
	 *
	 * @var int
	 */
	const LOG_LIMIT = 50;

	/**
	 * Encode a payload array to the canonical JSON string we store.
	 *
	 * @param array $payload Draw payload.
	 * @return string
	 */
	public static function encode( array $payload ) {
		return (string) wp_json_encode( $payload, self::JSON_FLAGS );
	}

	/**
	 * Read the raw stored JSON for a draw post.
	 *
	 * @param int $post_id Draw post ID.
	 * @return string Raw JSON, or '' when absent.
	 */
	public static function get_raw( $post_id ) {
		$raw = get_post_meta( $post_id, Lottery_Draw::META_PAYLOAD, true );

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Read and decode the payload for a draw post.
	 *
	 * @param int $post_id Draw post ID.
	 * @return array|null Payload array, or null when missing/corrupt.
	 */
	public static function get_payload( $post_id ) {
		$decoded = json_decode( self::get_raw( $post_id ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Persist a payload, replacing the stored JSON wholesale.
	 *
	 * Metadata functions unslash what they are given, so the JSON is
	 * slashed on the way in to survive the round trip byte-for-byte.
	 *
	 * @param int   $post_id Draw post ID.
	 * @param array $payload Payload to store.
	 * @return string The JSON that was stored.
	 */
	public static function save_payload( $post_id, array $payload ) {
		$json = self::encode( $payload );

		update_post_meta( $post_id, Lottery_Draw::META_PAYLOAD, wp_slash( $json ) );
		update_post_meta( $post_id, Lottery_Draw::META_DRAW_ID, sanitize_title( $payload['id'] ) );
		update_post_meta( $post_id, Lottery_Draw::META_SUPERSEDES, sanitize_title( $payload['supersedes'] ?? '' ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => $payload['title'],
				'post_status' => ( Lottery_Draw::STATUS_PUBLISHED === $payload['status'] ) ? 'publish' : 'draft',
			)
		);

		return $json;
	}

	/**
	 * Find a draw post by its public draw ID.
	 *
	 * @param string $draw_id Draw ID (slug).
	 * @return \WP_Post|null
	 */
	public static function find_post( $draw_id ) {
		$draw_id = sanitize_title( $draw_id );

		if ( '' === $draw_id ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'        => Lottery_Draw::POST_TYPE,
				'post_status'      => array( 'draft', 'publish' ),
				'posts_per_page'   => 1,
				'meta_key'         => Lottery_Draw::META_DRAW_ID,
				'meta_value'       => $draw_id,
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		return $posts ? $posts[0] : null;
	}

	/**
	 * List every draw, newest first.
	 *
	 * @return array<int, array{post: \WP_Post, payload: array}>
	 */
	public static function all() {
		$posts = get_posts(
			array(
				'post_type'      => Lottery_Draw::POST_TYPE,
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();

		foreach ( $posts as $post ) {
			$payload = self::get_payload( $post->ID );

			if ( $payload ) {
				$out[] = array(
					'post'    => $post,
					'payload' => $payload,
				);
			}
		}

		return $out;
	}

	/**
	 * Create a new draw in `draft` status.
	 *
	 * @param string $title       Human-readable draw title.
	 * @param string $draw_id     Public draw ID (slug); derived from title when empty.
	 * @param string $seed_method Lottery_Draw::SEED_COMMIT_EARLY or SEED_EXTERNAL.
	 * @param string $seed        Seed value (may be empty for the external method).
	 * @param string $seed_source Description of the external randomness source.
	 * @param string $supersedes  ID of the draw this one corrects, if any.
	 * @return int|\WP_Error New post ID, or an error.
	 */
	public static function create( $title, $draw_id, $seed_method, $seed, $seed_source, $supersedes = '' ) {
		$title   = trim( $title );
		$draw_id = sanitize_title( $draw_id ? $draw_id : $title );

		if ( '' === $title || '' === $draw_id ) {
			return new \WP_Error( 'fh_draw_invalid', __( 'A draw needs a title and an ID.', 'fishotel-misc-plugin' ) );
		}

		return self::insert(
			array(
				'id'                => $draw_id,
				'title'             => $title,
				'seed'              => $seed,
				'seed_method'       => $seed_method,
				'seed_source'       => $seed_source,
				'supersedes'        => sanitize_title( $supersedes ),
				'seed_published_at' => '',
				'drawn_at'          => '',
				'status'            => Lottery_Draw::STATUS_DRAFT,
				'created_at'        => Lottery_Draw::now_utc(),
				'fish'              => array(),
			)
		);
	}

	/**
	 * Insert a new draft draw from a complete payload.
	 *
	 * @param array $payload Payload to store. Must carry an id and title.
	 * @return int|\WP_Error New post ID, or an error.
	 */
	public static function insert( array $payload ) {
		$draw_id = sanitize_title( $payload['id'] ?? '' );
		$title   = trim( (string) ( $payload['title'] ?? '' ) );

		if ( '' === $draw_id || '' === $title ) {
			return new \WP_Error( 'fh_draw_invalid', __( 'A draw needs a title and an ID.', 'fishotel-misc-plugin' ) );
		}

		if ( self::find_post( $draw_id ) ) {
			return new \WP_Error( 'fh_draw_duplicate', __( 'A draw with that ID already exists.', 'fishotel-misc-plugin' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Lottery_Draw::POST_TYPE,
				'post_title'  => $title,
				'post_name'   => $draw_id,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::save_payload( $post_id, $payload );

		return $post_id;
	}

	/**
	 * Read a draw's import log, oldest entry first.
	 *
	 * @param int $post_id Draw post ID.
	 * @return array<int, array>
	 */
	public static function get_log( $post_id ) {
		$log = get_post_meta( $post_id, Lottery_Draw::META_LOG, true );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Append an entry to a draw's import log.
	 *
	 * @param int   $post_id Draw post ID.
	 * @param array $entry   Entry fields; `at` defaults to now (UTC).
	 * @return array The log as stored.
	 */
	public static function append_log( $post_id, array $entry ) {
		$log = self::get_log( $post_id );

		$log[] = array(
			'at'      => $entry['at'] ?? Lottery_Draw::now_utc(),
			'user'    => (string) ( $entry['user'] ?? '' ),
			'action'  => (string) ( $entry['action'] ?? '' ),
			'fish'    => (int) ( $entry['fish'] ?? 0 ),
			'tickets' => (int) ( $entry['tickets'] ?? 0 ),
			'people'  => (int) ( $entry['people'] ?? 0 ),
			'detail'  => (string) ( $entry['detail'] ?? '' ),
		);

		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, - self::LOG_LIMIT );
		}

		update_post_meta( $post_id, Lottery_Draw::META_LOG, $log );

		return $log;
	}

	/**
	 * Whether a payload is still open to edits of seed and entrants.
	 *
	 * @param array $payload Draw payload.
	 * @return bool
	 */
	public static function is_editable( array $payload ) {
		return Lottery_Draw::STATUS_DRAFT === ( $payload['status'] ?? '' );
	}

	/**
	 * Whether a payload is published, and therefore frozen entirely.
	 *
	 * @param array $payload Draw payload.
	 * @return bool
	 */
	public static function is_published( array $payload ) {
		return Lottery_Draw::STATUS_PUBLISHED === ( $payload['status'] ?? '' );
	}

	/**
	 * Parse an entrants textarea into normalised entrant records.
	 *
	 * One entrant per line, in posting order. A trailing `x3` (or `×3`)
	 * means three tickets; no suffix means one.
	 *
	 * @param string $text Raw textarea contents.
	 * @return array<int, array{name: string, want: int}>
	 */
	public static function parse_entrants( $text ) {
		$lines    = preg_split( '/\r\n|\r|\n/', (string) $text );
		$entrants = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$want = 1;

			if ( preg_match( '/^(.*?)\s*[x×]\s*(\d+)\s*$/iu', $line, $matches ) ) {
				$line = trim( $matches[1] );
				$want = (int) $matches[2];
			}

			if ( '' === $line ) {
				continue;
			}

			$entrants[] = array(
				'name' => sanitize_text_field( $line ),
				'want' => max( 0, $want ),
			);
		}

		return self::normalize_entrants( $entrants );
	}

	/**
	 * Deduplicate entrants case-insensitively, keeping first appearance
	 * and summing later `want` values into it.
	 *
	 * This mirrors `normalizeEntrants()` in draw.js. It is a bookkeeping
	 * rule applied to the *inputs* before they are stored, not part of
	 * the draw — entrants are already normalised by the time the browser
	 * sees them, and draw.js re-applies the same idempotent rule anyway.
	 *
	 * @param array $entrants Raw entrant records.
	 * @return array<int, array{name: string, want: int}>
	 */
	public static function normalize_entrants( array $entrants ) {
		$out   = array();
		$index = array();

		foreach ( $entrants as $entrant ) {
			$name = trim( (string) ( $entrant['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$want = max( 0, (int) ( $entrant['want'] ?? 0 ) );
			$key  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );

			if ( isset( $index[ $key ] ) ) {
				$out[ $index[ $key ] ]['want'] += $want;
				continue;
			}

			$index[ $key ] = count( $out );
			$out[]         = array(
				'name' => $name,
				'want' => $want,
			);
		}

		return array_values( $out );
	}

	/**
	 * Slots available for a fish record. Mirrors slotsFor() in draw.js.
	 *
	 * @param array $fish Fish record.
	 * @return int
	 */
	public static function slots_for( array $fish ) {
		$stock      = max( 0, (int) ( $fish['stock'] ?? 0 ) );
		$group_size = max( 1, (int) ( $fish['groupSize'] ?? 1 ) );

		return (int) floor( $stock / $group_size );
	}

	/**
	 * Total tickets in the hat for a fish.
	 *
	 * @param array $fish Fish record.
	 * @return int
	 */
	public static function ticket_count( array $fish ) {
		$count = 0;

		foreach ( (array) ( $fish['entrants'] ?? array() ) as $entrant ) {
			$count += max( 0, (int) ( $entrant['want'] ?? 0 ) );
		}

		return $count;
	}

	/**
	 * Whether a fish is uncontested — requests fit inside the slots, so
	 * everybody gets theirs and the draw is a formality.
	 *
	 * @param array $fish Fish record.
	 * @return bool
	 */
	public static function is_uncontested( array $fish ) {
		return self::ticket_count( $fish ) <= self::slots_for( $fish );
	}

	/**
	 * Append a fish to a draft draw.
	 *
	 * @param array  $payload    Draw payload (by value; the updated copy is returned).
	 * @param string $name       Common name.
	 * @param string $sci        Scientific name.
	 * @param int    $stock      Individuals in stock.
	 * @param int    $group_size Individuals per allocated group.
	 * @param string $entrants   Raw entrants textarea.
	 * @return array|\WP_Error Updated payload, or an error.
	 */
	public static function add_fish( array $payload, $name, $sci, $stock, $group_size, $entrants ) {
		if ( ! self::is_editable( $payload ) ) {
			return new \WP_Error( 'fh_draw_locked', __( 'Entrants are locked once the seed is committed.', 'fishotel-misc-plugin' ) );
		}

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name ) {
			return new \WP_Error( 'fh_draw_invalid', __( 'A fish needs a name.', 'fishotel-misc-plugin' ) );
		}

		foreach ( (array) $payload['fish'] as $existing ) {
			// The RNG stream is seeded from the lowercased fish name, so
			// two fish sharing a name would share a stream and draw the
			// same shuffle. Names must be unique within a draw.
			if ( strtolower( $existing['name'] ) === strtolower( $name ) ) {
				return new \WP_Error( 'fh_draw_duplicate_fish', __( 'That fish is already in this draw. Fish names must be unique — the draw seeds its randomness from them.', 'fishotel-misc-plugin' ) );
			}
		}

		$payload['fish'][] = array(
			'name'      => $name,
			'sci'       => trim( sanitize_text_field( $sci ) ),
			'stock'     => max( 0, (int) $stock ),
			'groupSize' => max( 1, (int) $group_size ),
			'entrants'  => self::parse_entrants( $entrants ),
			'winners'   => array(),
			'waitlist'  => array(),
		);

		return $payload;
	}

	/**
	 * Remove a fish from a draft draw.
	 *
	 * @param array $payload Draw payload.
	 * @param int   $index   Zero-based fish index.
	 * @return array|\WP_Error Updated payload, or an error.
	 */
	public static function remove_fish( array $payload, $index ) {
		if ( ! self::is_editable( $payload ) ) {
			return new \WP_Error( 'fh_draw_locked', __( 'Entrants are locked once the seed is committed.', 'fishotel-misc-plugin' ) );
		}

		$index = (int) $index;

		if ( ! isset( $payload['fish'][ $index ] ) ) {
			return new \WP_Error( 'fh_draw_missing_fish', __( 'That fish is not in this draw.', 'fishotel-misc-plugin' ) );
		}

		unset( $payload['fish'][ $index ] );
		$payload['fish'] = array_values( $payload['fish'] );

		return $payload;
	}

	/**
	 * Commit the seed: lock seed and entrants, stamp the commitment time.
	 *
	 * This is the step that makes the draw a proof rather than theatre. A
	 * seed we chose after seeing the entries proves nothing — we could
	 * grind thousands of seeds and publish the one we liked. Committing
	 * fixes the seed before it can be ground against the entrant list.
	 *
	 * @param array $payload Draw payload.
	 * @return array|\WP_Error Updated payload, or an error.
	 */
	public static function commit_seed( array $payload ) {
		if ( ! self::is_editable( $payload ) ) {
			return new \WP_Error( 'fh_draw_locked', __( 'This draw\'s seed is already committed.', 'fishotel-misc-plugin' ) );
		}

		if ( '' === trim( (string) $payload['seed'] ) ) {
			return new \WP_Error( 'fh_draw_no_seed', __( 'Set a seed before committing it.', 'fishotel-misc-plugin' ) );
		}

		if ( empty( $payload['fish'] ) ) {
			return new \WP_Error( 'fh_draw_no_fish', __( 'Add at least one fish before committing the seed.', 'fishotel-misc-plugin' ) );
		}

		if ( Lottery_Draw::SEED_EXTERNAL === $payload['seed_method'] && '' === trim( (string) $payload['seed_source'] ) ) {
			return new \WP_Error( 'fh_draw_no_source', __( 'Name the external randomness source before committing.', 'fishotel-misc-plugin' ) );
		}

		$payload['seed_published_at'] = Lottery_Draw::now_utc();
		$payload['status']            = Lottery_Draw::STATUS_COMMITTED;

		return $payload;
	}

	/**
	 * Store the results the admin's browser computed, and publish.
	 *
	 * @param array $payload Draw payload.
	 * @param array $results Decoded results: [{ name, winners[], waitlist[] }].
	 * @return array|\WP_Error Updated payload, or an error.
	 */
	public static function publish_results( array $payload, array $results ) {
		if ( self::is_published( $payload ) ) {
			return new \WP_Error( 'fh_draw_immutable', __( 'This draw is published and cannot be changed. Publish a correction as a new draw instead.', 'fishotel-misc-plugin' ) );
		}

		if ( Lottery_Draw::STATUS_COMMITTED !== ( $payload['status'] ?? '' ) ) {
			return new \WP_Error( 'fh_draw_uncommitted', __( 'Commit the seed before running the draw.', 'fishotel-misc-plugin' ) );
		}

		if ( count( $results ) !== count( $payload['fish'] ) ) {
			return new \WP_Error( 'fh_draw_result_shape', __( 'The results do not cover every fish in this draw.', 'fishotel-misc-plugin' ) );
		}

		foreach ( $payload['fish'] as $i => $fish ) {
			$result = $results[ $i ] ?? null;

			if ( ! is_array( $result ) ) {
				return new \WP_Error( 'fh_draw_result_shape', __( 'Malformed results.', 'fishotel-misc-plugin' ) );
			}

			$winners  = array_map( 'strval', (array) ( $result['winners'] ?? array() ) );
			$waitlist = array_map( 'strval', (array) ( $result['waitlist'] ?? array() ) );

			$error = self::validate_results( $fish, $winners, $waitlist );

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			$payload['fish'][ $i ]['winners']  = $winners;
			$payload['fish'][ $i ]['waitlist'] = $waitlist;
		}

		$payload['drawn_at'] = Lottery_Draw::now_utc();
		$payload['status']   = Lottery_Draw::STATUS_PUBLISHED;

		return $payload;
	}

	/**
	 * Structural check on a browser-supplied result for one fish.
	 *
	 * Deliberately not a re-draw: this asserts only that the result is a
	 * rearrangement of the tickets we stored and that no more slots were
	 * awarded than exist. Whether the *order* is the right one is what
	 * the public page's in-browser recompute settles, using the same
	 * draw.js the admin ran.
	 *
	 * @param array $fish     Fish record with entrants.
	 * @param array $winners  Winner names in draw order.
	 * @param array $waitlist Waitlist names in rank order.
	 * @return true|\WP_Error
	 */
	public static function validate_results( array $fish, array $winners, array $waitlist ) {
		$slots = self::slots_for( $fish );

		if ( count( $winners ) > $slots ) {
			return new \WP_Error(
				'fh_draw_oversubscribed',
				sprintf(
					/* translators: 1: fish name, 2: winners awarded, 3: slots available */
					__( '%1$s: the draw awarded %2$d winners for %3$d slots.', 'fishotel-misc-plugin' ),
					$fish['name'],
					count( $winners ),
					$slots
				)
			);
		}

		$expected = array();

		foreach ( (array) $fish['entrants'] as $entrant ) {
			for ( $i = 0; $i < max( 0, (int) $entrant['want'] ); $i++ ) {
				$expected[] = (string) $entrant['name'];
			}
		}

		$got = array_merge( $winners, $waitlist );

		sort( $expected );
		sort( $got );

		if ( $expected !== $got ) {
			return new \WP_Error(
				'fh_draw_ticket_mismatch',
				sprintf(
					/* translators: %s: fish name */
					__( '%s: the results are not a rearrangement of the entrant tickets. Nothing was saved.', 'fishotel-misc-plugin' ),
					$fish['name']
				)
			);
		}

		return true;
	}

	/**
	 * Collapse repeated names into "Name (2)" form, keeping draw order.
	 *
	 * @param array $names Names in draw order.
	 * @return array<int, array{name: string, count: int}>
	 */
	public static function tally( array $names ) {
		$out   = array();
		$index = array();

		foreach ( $names as $raw ) {
			$name = (string) $raw;
			$key  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );

			if ( isset( $index[ $key ] ) ) {
				$out[ $index[ $key ] ]['count']++;
				continue;
			}

			$index[ $key ] = count( $out );
			$out[]         = array(
				'name'  => $name,
				'count' => 1,
			);
		}

		return $out;
	}

	/**
	 * Render a name list as text: "alice (2), bob".
	 *
	 * @param array $names Names in draw order.
	 * @return string
	 */
	public static function format_names( array $names ) {
		$parts = array();

		foreach ( self::tally( $names ) as $item ) {
			$parts[] = $item['count'] > 1 ? $item['name'] . ' (' . $item['count'] . ')' : $item['name'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Public permalink for a draw.
	 *
	 * @param \WP_Post $post Draw post.
	 * @return string
	 */
	public static function permalink( $post ) {
		return get_permalink( $post );
	}

	/**
	 * Public URL serving the raw JSON payload, byte-for-byte as stored.
	 *
	 * @param string $draw_id Draw ID.
	 * @return string
	 */
	public static function json_url( $draw_id ) {
		$draw_id = sanitize_title( $draw_id );

		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . Lottery_Draw::REWRITE_SLUG . '/' . $draw_id . '/json/' );
		}

		return add_query_arg( Lottery_Draw::JSON_QUERY_VAR, $draw_id, home_url( '/' ) );
	}

	/**
	 * Render a published draw as BBCode for the Humble.fish thread.
	 *
	 * @param array $payload Published draw payload.
	 * @return string
	 */
	public static function to_bbcode( array $payload ) {
		$lines   = array();
		$lines[] = '[B]' . $payload['title'] . '[/B]';
		$lines[] = '';
		$lines[] = '[TABLE]';
		$lines[] = '[TR][TD][B]Fish[/B][/TD][TD][B]Stock[/B][/TD][TD][B]Winners[/B][/TD][TD][B]Waitlist[/B][/TD][/TR]';

		foreach ( (array) $payload['fish'] as $fish ) {
			$group_size = max( 1, (int) $fish['groupSize'] );
			$stock      = (int) $fish['stock'];
			$stock_text = ( $group_size > 1 )
				? $stock . ' (' . self::slots_for( $fish ) . ' groups of ' . $group_size . ')'
				: (string) $stock;

			$waitlist = array();
			$rank     = 1;

			foreach ( (array) $fish['waitlist'] as $name ) {
				$waitlist[] = $rank . '. ' . $name;
				$rank++;
			}

			$lines[] = '[TR][TD]' . $fish['name'] . '[/TD]'
				. '[TD]' . $stock_text . '[/TD]'
				. '[TD]' . ( self::format_names( (array) $fish['winners'] ) ?: '—' ) . '[/TD]'
				. '[TD]' . ( $waitlist ? implode( ', ', $waitlist ) : '—' ) . '[/TD][/TR]';
		}

		$lines[] = '[/TABLE]';
		$lines[] = '';
		$lines[] = 'Seed: [B]' . $payload['seed'] . '[/B]';

		if ( Lottery_Draw::SEED_EXTERNAL === $payload['seed_method'] ) {
			$lines[] = 'Seed source: ' . $payload['seed_source'];
		}

		$lines[] = 'Seed committed: ' . Lottery_Draw::format_local( $payload['seed_published_at'] );
		$lines[] = 'Drawn: ' . Lottery_Draw::format_local( $payload['drawn_at'] );
		$lines[] = 'Verify it yourself: ' . self::public_url( $payload['id'] );

		return implode( "\n", $lines );
	}

	/**
	 * Find the published draw that corrects the given one, if any.
	 *
	 * A published draw is immutable, so it cannot be edited to point at
	 * its own correction. The link has to be found from the other end.
	 *
	 * @param string $draw_id Draw ID that may have been corrected.
	 * @return array|null ['post' => WP_Post, 'payload' => array] or null.
	 */
	public static function find_correction( $draw_id ) {
		$draw_id = sanitize_title( $draw_id );

		if ( '' === $draw_id ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => Lottery_Draw::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => Lottery_Draw::META_SUPERSEDES,
				'meta_value'     => $draw_id,
				'no_found_rows'  => true,
			)
		);

		if ( ! $posts ) {
			return null;
		}

		$payload = self::get_payload( $posts[0]->ID );

		return $payload ? array(
			'post'    => $posts[0],
			'payload' => $payload,
		) : null;
	}

	/**
	 * Public page URL for a draw ID, falling back to home when the post
	 * has gone missing.
	 *
	 * @param string $draw_id Draw ID.
	 * @return string
	 */
	public static function public_url( $draw_id ) {
		$post = self::find_post( $draw_id );

		return $post ? get_permalink( $post ) : home_url( '/' );
	}
}
