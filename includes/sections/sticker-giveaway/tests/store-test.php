<?php
/**
 * The store behind the giveaway piles (class-store.php), run against a
 * small in-memory stand-in for the database.
 *
 * What this proves: the statements Store writes are well formed (every
 * placeholder has its value), and its control flow does what the page
 * and the order hooks rely on — a restock and a recount, a move that
 * happens once per order line however often it is asked for, a second
 * request that gets in between the read and the write, the reorder mark
 * that only one request can take, and the 28-day count.
 *
 * What it does NOT prove: that MySQL / MariaDB run these statements the
 * same way. The stand-in understands exactly the statements Store sends
 * and nothing else; the real tables are exercised on a rehearsal site.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/store-test.php
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-store.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Piles;
use FisHotel\Misc\Sections\Sticker_Giveaway\Store;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$failures = 0;
$checks   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label" . ( '' !== (string) $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * The stand-in: two arrays and the handful of statements Store sends.
 * A statement it does not know is an exception, so a changed statement
 * fails the test instead of passing unread.
 */
class FH_Fake_WPDB {
	public $prefix = 'wp_';
	public $piles  = array(); // design_id => row.
	public $lines  = array(); // item_id => row.
	public $sent   = array(); // every statement, as sent.

	/** Refuse the next statement that moves a pile for an order. */
	public $refuse_shift = false;

	/** Run once, right after the next read of a ledger row: "another request got in". */
	public $after_read = null;

	public function prepare( $query, ...$args ) {
		$i   = 0;
		$out = preg_replace_callback(
			'/%[ds]/',
			static function ( $m ) use ( &$i, $args ) {
				if ( ! array_key_exists( $i, $args ) ) {
					throw new \RuntimeException( 'prepare(): more placeholders than values' );
				}
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : "'" . str_replace( "'", "\\'", (string) $value ) . "'";
			},
			$query
		);
		if ( count( $args ) !== $i ) {
			throw new \RuntimeException( 'prepare(): more values than placeholders in: ' . $query );
		}
		return $out;
	}

	public function get_charset_collate() {
		return '';
	}

	public function get_row( $sql, $output = null ) {
		$this->sent[] = $sql;
		if ( preg_match( "/^SELECT state, qty, back, design_id FROM wp_fh_sticker_lines WHERE item_id = (\d+)$/", $sql, $m ) ) {
			$row = isset( $this->lines[ (int) $m[1] ] ) ? array_intersect_key( $this->lines[ (int) $m[1] ], array_flip( array( 'state', 'qty', 'back', 'design_id' ) ) ) : null;
			if ( $this->after_read ) {
				$then             = $this->after_read;
				$this->after_read = null;
				$then();
			}
			return $row;
		}
		throw new \RuntimeException( 'unknown get_row: ' . $sql );
	}

	public function get_results( $sql, $output = null ) {
		$this->sent[] = $sql;
		if ( preg_match( '/^SELECT design_id, on_paper, basis, alerted, counted_at, added_at FROM wp_fh_sticker_piles WHERE design_id IN \(([\d,]+)\)$/', $sql, $m ) ) {
			$out = array();
			foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $id ) {
				if ( isset( $this->piles[ $id ] ) ) {
					$out[] = array( 'design_id' => $id ) + $this->piles[ $id ];
				}
			}
			return $out;
		}
		if ( preg_match( "/^SELECT design_id, SUM\(qty - back\) AS went FROM wp_fh_sticker_lines WHERE state = 'taken' AND taken_at >= '([^']+)' AND design_id IN \(([\d,]+)\) GROUP BY design_id$/", $sql, $m ) ) {
			$ids  = array_map( 'intval', explode( ',', $m[2] ) );
			$sums = array();
			foreach ( $this->lines as $line ) {
				if ( 'taken' === $line['state'] && $line['taken_at'] >= $m[1] && in_array( $line['design_id'], $ids, true ) ) {
					$sums[ $line['design_id'] ] = ( $sums[ $line['design_id'] ] ?? 0 ) + $line['qty'] - $line['back'];
				}
			}
			$out = array();
			foreach ( $sums as $id => $went ) {
				$out[] = array(
					'design_id' => $id,
					'went'      => $went,
				);
			}
			return $out;
		}
		throw new \RuntimeException( 'unknown get_results: ' . $sql );
	}

	public function query( $sql ) {
		$this->sent[] = $sql;
		$n            = '(-?\d+)';
		$s            = "'([^']*)'";

		// A new ledger row.
		if ( preg_match( "/^INSERT IGNORE INTO wp_fh_sticker_lines \(item_id, order_id, design_id, kind, qty, back, state, taken_at, updated_at\) VALUES \($n, $n, $n, $s, $n, $n, $s, $s, $s\)$/", $sql, $m ) ) {
			if ( isset( $this->lines[ (int) $m[1] ] ) ) {
				return 0;
			}
			$this->lines[ (int) $m[1] ] = array(
				'order_id'  => (int) $m[2],
				'design_id' => (int) $m[3],
				'kind'      => $m[4],
				'qty'       => (int) $m[5],
				'back'      => (int) $m[6],
				'state'     => $m[7],
				'taken_at'  => $m[8],
			);
			return 1;
		}

		// The swap: only when the row still reads what was read.
		if ( preg_match( "/^UPDATE wp_fh_sticker_lines SET state = $s, qty = $n, back = $n, design_id = $n, taken_at = IF\( ([01]) = 1, $s, taken_at \), updated_at = $s WHERE item_id = $n AND state = $s AND qty = $n AND back = $n$/", $sql, $m ) ) {
			$id = (int) $m[8];
			if ( ! isset( $this->lines[ $id ] ) || $this->lines[ $id ]['state'] !== $m[9] || $this->lines[ $id ]['qty'] !== (int) $m[10] || $this->lines[ $id ]['back'] !== (int) $m[11] ) {
				return 0;
			}
			$this->lines[ $id ]['state']     = $m[1];
			$this->lines[ $id ]['qty']       = (int) $m[2];
			$this->lines[ $id ]['back']      = (int) $m[3];
			$this->lines[ $id ]['design_id'] = (int) $m[4];
			if ( '1' === $m[5] ) {
				$this->lines[ $id ]['taken_at'] = $m[6];
			}
			return 1;
		}

		// Putting a row back after a refused pile statement.
		if ( preg_match( "/^UPDATE wp_fh_sticker_lines SET state = $s, qty = $n, back = $n WHERE item_id = $n AND state = $s AND qty = $n AND back = $n$/", $sql, $m ) ) {
			$id = (int) $m[4];
			if ( ! isset( $this->lines[ $id ] ) || $this->lines[ $id ]['state'] !== $m[5] || $this->lines[ $id ]['qty'] !== (int) $m[6] || $this->lines[ $id ]['back'] !== (int) $m[7] ) {
				return 0;
			}
			$this->lines[ $id ]['state'] = $m[1];
			$this->lines[ $id ]['qty']   = (int) $m[2];
			$this->lines[ $id ]['back']  = (int) $m[3];
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_fh_sticker_lines WHERE item_id = $n AND state = $s AND qty = $n AND back = $n$/", $sql, $m ) ) {
			$id = (int) $m[1];
			if ( isset( $this->lines[ $id ] ) && $this->lines[ $id ]['state'] === $m[2] && $this->lines[ $id ]['qty'] === (int) $m[3] && $this->lines[ $id ]['back'] === (int) $m[4] ) {
				unset( $this->lines[ $id ] );
				return 1;
			}
			return 0;
		}

		// An order moves a pile: paper only.
		if ( preg_match( "/^INSERT INTO wp_fh_sticker_piles \(design_id, on_paper, basis, updated_at\) VALUES \($n, $n, 0, $s\) ON DUPLICATE KEY UPDATE on_paper = on_paper \+ $n, updated_at = $s$/", $sql, $m ) ) {
			if ( $this->refuse_shift ) {
				$this->refuse_shift = false;
				return false;
			}
			if ( $m[2] !== $m[4] ) {
				throw new \RuntimeException( 'the insert and the update of a shift disagree' );
			}
			$this->pile_row( (int) $m[1] );
			$this->piles[ (int) $m[1] ]['on_paper'] += (int) $m[2];
			return 1;
		}

		// Add stickers.
		if ( preg_match( "/^INSERT INTO wp_fh_sticker_piles \(design_id, on_paper, basis, added_at, updated_at\) VALUES \($n, $n, $n, $s, $s\) ON DUPLICATE KEY UPDATE on_paper = on_paper \+ $n, basis = basis \+ $n, added_at = $s, updated_at = $s$/", $sql, $m ) ) {
			if ( $m[2] !== $m[3] || $m[2] !== $m[6] || $m[2] !== $m[7] ) {
				throw new \RuntimeException( 'the numbers of an add disagree' );
			}
			$this->pile_row( (int) $m[1] );
			$this->piles[ (int) $m[1] ]['on_paper'] += (int) $m[2];
			$this->piles[ (int) $m[1] ]['basis']    += (int) $m[2];
			$this->piles[ (int) $m[1] ]['added_at']  = $m[4];
			return 1;
		}

		// Recount.
		if ( preg_match( "/^INSERT INTO wp_fh_sticker_piles \(design_id, on_paper, basis, counted_at, updated_at\) VALUES \($n, $n, $n, $s, $s\) ON DUPLICATE KEY UPDATE on_paper = $n, basis = $n, counted_at = $s, updated_at = $s$/", $sql, $m ) ) {
			if ( $m[2] !== $m[3] || $m[2] !== $m[6] || $m[2] !== $m[7] ) {
				throw new \RuntimeException( 'the numbers of a recount disagree' );
			}
			$this->pile_row( (int) $m[1] );
			$this->piles[ (int) $m[1] ]['on_paper']   = (int) $m[2];
			$this->piles[ (int) $m[1] ]['basis']      = (int) $m[2];
			$this->piles[ (int) $m[1] ]['counted_at'] = $m[4];
			return 1;
		}

		// The reorder mark.
		if ( preg_match( "/^INSERT IGNORE INTO wp_fh_sticker_piles \(design_id, updated_at\) VALUES \($n, $s\)$/", $sql, $m ) ) {
			if ( isset( $this->piles[ (int) $m[1] ] ) ) {
				return 0;
			}
			$this->pile_row( (int) $m[1] );
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_fh_sticker_piles SET alerted = ([01]) WHERE design_id = $n AND alerted = ([01])$/", $sql, $m ) ) {
			$id = (int) $m[2];
			if ( ! isset( $this->piles[ $id ] ) || (int) $this->piles[ $id ]['alerted'] !== (int) $m[3] ) {
				return 0;
			}
			$this->piles[ $id ]['alerted'] = (int) $m[1];
			return 1;
		}

		throw new \RuntimeException( 'unknown query: ' . $sql );
	}

	private function pile_row( $id ) {
		if ( ! isset( $this->piles[ $id ] ) ) {
			$this->piles[ $id ] = array(
				'on_paper'   => 0,
				'basis'      => 0,
				'alerted'    => 0,
				'counted_at' => null,
				'added_at'   => null,
			);
		}
	}
}

$wpdb  = new FH_Fake_WPDB();
$store = new Store();

// Design ids, invented: two variations of one product, and a simple product.
const BLUE  = 302;
const CLOWN = 303;
const ROUND = 77;

echo "1. Add stickers and Recount\n";
check( 'a design nobody counted is an empty pile (no row needed)', 0 === $store->pile( BLUE )['paper'] && 0 === $store->pile( BLUE )['basis'] );
check( 'Recount 200', $store->recount( BLUE, 200 ) && 200 === $store->pile( BLUE )['paper'] && 200 === $store->pile( BLUE )['basis'] );
check( 'Add 100: paper 300, B 300', $store->add( BLUE, 100 ) && 300 === $store->pile( BLUE )['paper'] && 300 === $store->pile( BLUE )['basis'] );
check( 'Recount 140 replaces both', $store->recount( BLUE, 140 ) && 140 === $store->pile( BLUE )['paper'] && 140 === $store->pile( BLUE )['basis'] );
check( 'Add to a design with no row yet makes the row', $store->add( CLOWN, 50 ) && 50 === $store->pile( CLOWN )['paper'] && 50 === $store->pile( CLOWN )['basis'] );
check( 'Add of 0, a negative recount and a design id of 0 are refused', ! $store->add( BLUE, 0 ) && ! $store->recount( BLUE, -1 ) && ! $store->add( 0, 5 ) && 140 === $store->pile( BLUE )['paper'] );
check( 'Recount 0 is allowed (the pile is empty)', $store->recount( CLOWN, 0 ) && 0 === $store->pile( CLOWN )['paper'] );
check( 'the dates of the last count and the last add are kept', '' !== $store->pile( BLUE )['counted_at'] && '' !== $store->pile( BLUE )['added_at'] );
$store->recount( CLOWN, 50 );

echo "2. An order line moves the pile once\n";
check( 'order line #9001 (free, 1 × Blue Tang) takes 1', -1 === $store->move( 9001, Piles::TAKE, 1, BLUE, 5001, 'free' ) && 139 === $store->pile( BLUE )['paper'] );
check( '... B does not move', 140 === $store->pile( BLUE )['basis'] );
check( 'the reduce hook firing again takes nothing', 0 === $store->move( 9001, Piles::TAKE, 1, BLUE, 5001, 'free' ) && 139 === $store->pile( BLUE )['paper'] );
check( 'order line #9002 (add-on, 3) takes 3', -3 === $store->move( 9002, Piles::TAKE, 3, BLUE, 5001, 'extra' ) && 136 === $store->pile( BLUE )['paper'] );
check( 'the order cancelled: #9001 gives 1 back', 1 === $store->move( 9001, Piles::RETURN ) && 137 === $store->pile( BLUE )['paper'] );
check( 'the restore hook firing again gives nothing', 0 === $store->move( 9001, Piles::RETURN ) && 137 === $store->pile( BLUE )['paper'] );
check( 'paid again after the cancel: takes 1 again', -1 === $store->move( 9001, Piles::TAKE, 1, BLUE, 5001, 'free' ) && 136 === $store->pile( BLUE )['paper'] );
check( 'a refund of 1 of the 3 add-ons, restock ticked: 1 back', 1 === $store->move( 9002, Piles::REFUND, 1 ) && 137 === $store->pile( BLUE )['paper'] );
check( 'then the order cancelled: the other 2 come back, not 3', 2 === $store->move( 9002, Piles::RETURN ) && 139 === $store->pile( BLUE )['paper'] );
check( 'a return or refund for a line that never took anything does nothing', 0 === $store->move( 9999, Piles::RETURN ) && 0 === $store->move( 9999, Piles::REFUND, 1 ) );
check( 'a take with no design, or for order line 0, does nothing', 0 === $store->move( 9003, Piles::TAKE, 1, 0, 5002, 'free' ) && 0 === $store->move( 0, Piles::TAKE, 1, BLUE, 5002, 'free' ) && ! isset( $wpdb->lines[9003] ) );
check( 'the other design was never touched', 50 === $store->pile( CLOWN )['paper'] );

echo "3. Two requests for the same order line at the same moment\n";
$paper_before     = $store->pile( CLOWN )['paper'];
$other            = new Store();
$other_delta      = null;
$wpdb->after_read = static function () use ( $other, &$other_delta ) {
	// The second request (the gateway's callback, say) does the whole move while the first has only read.
	$other_delta = $other->move( 9100, Piles::TAKE, 2, CLOWN, 5100, 'extra' );
};
$first_delta = $store->move( 9100, Piles::TAKE, 2, CLOWN, 5100, 'extra' );
check( 'one of the two moved the pile, the other moved nothing', -2 === $other_delta && 0 === $first_delta, "other $other_delta, first $first_delta" );
check( 'the pile went down by 2, not 4', $paper_before - 2 === $store->pile( CLOWN )['paper'] );

$wpdb->after_read = static function () use ( $other, &$other_delta ) {
	$other_delta = $other->move( 9100, Piles::RETURN );
};
$first_delta = $store->move( 9100, Piles::RETURN );
check( 'the same race on a cancel: the pile comes back by 2, once', 2 === $other_delta && 0 === $first_delta && $paper_before === $store->pile( CLOWN )['paper'] );

echo "4. Two different orders at the same moment\n";
// Each sends its own "on_paper = on_paper + ( -1 )"; nothing reads the count into PHP and writes it back.
$store->recount( ROUND, 10 );
$a = new Store();
$b = new Store();
$a->pile( ROUND ); // both requests have read 10 ...
$b->pile( ROUND );
$a->move( 9201, Piles::TAKE, 1, ROUND, 5201, 'free' );
$b->move( 9202, Piles::TAKE, 1, ROUND, 5202, 'free' );
Store::forget();
check( 'two orders that both saw 10 leave 8', 8 === $store->pile( ROUND )['paper'] );
$shifts = array_values( array_filter( $wpdb->sent, static function ( $sql ) { return false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE on_paper = on_paper + -1' ); } ) );
check( 'the pile statement adds a delta inside the database (it never writes a number PHP worked out)', count( $shifts ) >= 2 && array() === array_filter( $wpdb->sent, static function ( $sql ) { return (bool) preg_match( '/UPDATE on_paper = \d+, updated_at/', $sql ); } ) );

echo "5. The pile statement refused by the database\n";
$wpdb->refuse_shift = true;
check( 'the move reports nothing done', 0 === $store->move( 9300, Piles::TAKE, 1, ROUND, 5300, 'free' ) );
check( 'the ledger row is taken away again, so the next try can do it', ! isset( $wpdb->lines[9300] ) && 8 === $store->pile( ROUND )['paper'] );
check( 'the next try does it', -1 === $store->move( 9300, Piles::TAKE, 1, ROUND, 5300, 'free' ) && 7 === $store->pile( ROUND )['paper'] );
$wpdb->refuse_shift = true;
check( 'refused on a cancel: the row stays "taken"', 0 === $store->move( 9300, Piles::RETURN ) && 'taken' === $wpdb->lines[9300]['state'] && 7 === $store->pile( ROUND )['paper'] );
check( '... and the next try gives it back', 1 === $store->move( 9300, Piles::RETURN ) && 8 === $store->pile( ROUND )['paper'] );

echo "6. The reorder mark: one request takes it\n";
check( 'the first claim wins', true === $store->claim_alert( BLUE ) && true === $store->pile( BLUE )['alerted'] );
check( 'a second claim loses (no second email)', false === $store->claim_alert( BLUE ) );
check( 'cleared after a restock; clearing twice is nothing', true === $store->clear_alert( BLUE ) && false === $store->clear_alert( BLUE ) && false === $store->pile( BLUE )['alerted'] );
check( 'the next drop can claim again', true === $store->claim_alert( BLUE ) );
check( 'a design with no pile row can be claimed too (it keeps no giveaway pile)', true === $store->claim_alert( 888 ) && false === $store->claim_alert( 888 ) );
check( 'a recount does not clear the mark by itself (Reorder::check() decides)', $store->recount( BLUE, 139 ) && true === $store->pile( BLUE )['alerted'] );

echo "7. How many went in the last 28 days\n";
$now = time();
// An order line taken 40 days ago, by hand.
$wpdb->lines[9400] = array( 'order_id' => 5400, 'design_id' => BLUE, 'kind' => 'free', 'qty' => 6, 'back' => 0, 'state' => 'taken', 'taken_at' => gmdate( 'Y-m-d H:i:s', $now - 40 * 86400 ) );
$store->move( 9401, Piles::TAKE, 4, BLUE, 5401, 'extra' );
$store->move( 9402, Piles::TAKE, 2, BLUE, 5402, 'extra' );
$store->move( 9402, Piles::REFUND, 1 );
$store->move( 9403, Piles::TAKE, 5, BLUE, 5403, 'extra' );
$store->move( 9403, Piles::RETURN );
$went = $store->went_since( array( BLUE, CLOWN, ROUND ), $now - Piles::PACE_DAYS * 86400 );
// Still taken and recent: #9001 (1), #9401 (4), #9402 (2 less the 1 refunded). #9002, #9403 were given back; #9400 is too old.
check( 'taken lines of the last 28 days, less what came back; old lines and returned lines are out', 6 === $went[ BLUE ], json_encode( $went ) );
check( 'a design nothing went of reads 0', 0 === $went[ CLOWN ] );
check( 'no designs: no query', array() === $store->went_since( array(), $now ) );

echo "8. Reading several piles\n";
Store::forget();
$sent_before = count( $wpdb->sent );
$piles       = $store->piles( array( BLUE, CLOWN, ROUND, 12345 ) );
check( 'one query for all of them', 1 === count( $wpdb->sent ) - $sent_before );
check( 'a design with no row is an empty pile', 0 === $piles[12345]['paper'] && false === $piles[12345]['alerted'] );
$store->piles( array( BLUE, CLOWN ) );
check( 'read again in the same request: no query', 1 === count( $wpdb->sent ) - $sent_before );

echo "9. The tables\n";
$schema = implode( "\n", Store::schema( '' ) );
check( 'two tables, prefixed fh_', 2 === count( Store::schema( '' ) ) && false !== strpos( $schema, 'CREATE TABLE wp_fh_sticker_piles' ) && false !== strpos( $schema, 'CREATE TABLE wp_fh_sticker_lines' ) );
check( 'the paper count is signed (it may read -1)', (bool) preg_match( '/on_paper int\(11\) NOT NULL DEFAULT 0/', $schema ) && ! preg_match( '/on_paper int\(\d+\) unsigned/', $schema ) );
check( 'one row per design and one per order line (the primary keys)', false !== strpos( $schema, 'PRIMARY KEY  (design_id)' ) && false !== strpos( $schema, 'PRIMARY KEY  (item_id)' ) );
check( 'dbDelta\'s format: two spaces after PRIMARY KEY, lower-case types', ! preg_match( '/PRIMARY KEY \(/', $schema ) && ! preg_match( '/\b(BIGINT|INT|VARCHAR|DATETIME|TINYINT)\b/', $schema ) );

echo "10. No statement opens a transaction or touches a product\n";
$source = file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-store.php' );
check( 'no START TRANSACTION / COMMIT / ROLLBACK is sent (it would end one the caller had open)', ! preg_match( "/query\(\s*'(START TRANSACTION|COMMIT|ROLLBACK)/", $source ) );
check( 'no statement names the posts, postmeta or options tables', array() === array_filter( $wpdb->sent, static function ( $sql ) { return (bool) preg_match( '/wp_(posts|postmeta|options|wc_)/', $sql ); } ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
