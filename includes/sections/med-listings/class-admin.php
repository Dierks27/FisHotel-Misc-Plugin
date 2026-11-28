<?php
/**
 * Med listings — the page and its buttons.
 *
 * Every button is a request of its own (admin-ajax, nonce, manage_woocommerce):
 * Apply and Undo go one product at a time so a long list never runs into
 * the server's time limit, and the page says after each one what was done.
 * The two downloads are a backup CSV (admin-post) and the report, which
 * the browser writes from the table as it stands (with the owner's own
 * edits in it).
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action for every button.
	 *
	 * @var string
	 */
	const NONCE = 'fishotel_med_listings';

	/**
	 * Transient (per person) with the pages the check may open.
	 *
	 * @var string
	 */
	const PAGES_TRANSIENT = 'fishotel_med_listings_pages_';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_fishotel_med_listings_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_fishotel_med_listings_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_fishotel_med_listings_review', array( $this, 'ajax_review' ) );
		add_action( 'wp_ajax_fishotel_med_listings_term', array( $this, 'ajax_term' ) );
		add_action( 'wp_ajax_fishotel_med_listings_term_undo', array( $this, 'ajax_term_undo' ) );
		add_action( 'wp_ajax_fishotel_med_listings_redirect_remove', array( $this, 'ajax_redirect_remove' ) );
		add_action( 'wp_ajax_fishotel_med_listings_purge', array( $this, 'ajax_purge' ) );
		add_action( 'wp_ajax_fishotel_med_listings_check', array( $this, 'ajax_check' ) );
		add_action( 'admin_post_fishotel_med_listings_backup', array( $this, 'download_backup' ) );
		add_action( 'admin_post_fishotel_med_listings_words', array( $this, 'save_words' ) );
	}

	/**
	 * Whether this is our page.
	 *
	 * @return bool
	 */
	private function is_page() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, Med_Listings::MENU_SLUG );
	}

	/**
	 * CSS and JS on our page only.
	 */
	public function enqueue() {
		if ( ! $this->is_page() ) {
			return;
		}
		wp_enqueue_style( 'fishotel-med-listings', Med_Listings::url() . 'css/med-listings.css', array(), FISHOTEL_MISC_VERSION );
		\FisHotel\Misc\Dates::register_script(); // The plugin's dates: the report's "Made 11-27-26 09:14".
		wp_enqueue_script( 'fishotel-med-listings', Med_Listings::url() . 'js/med-listings.js', array( \FisHotel\Misc\Dates::SCRIPT ), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-med-listings',
			'fhMedListings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working'      => __( 'Working…', 'fishotel-misc-plugin' ),
					'applied'      => __( 'Applied', 'fishotel-misc-plugin' ),
					'undone'       => __( 'Put back', 'fishotel-misc-plugin' ),
					'failed'       => __( 'Not done', 'fishotel-misc-plugin' ),
					'confirmAll'   => __( 'Apply every ticked change on every product now? Each one can be undone here.', 'fishotel-misc-plugin' ),
					'confirmUndo'  => __( 'Put back the old text on every product this tool changed?', 'fishotel-misc-plugin' ),
					'confirmTerms' => __( 'Make the ticked category changes now? Each one can be undone here.', 'fishotel-misc-plugin' ),
					'fillIn'       => __( 'Fill in the [[ … ]] blank first.', 'fishotel-misc-plugin' ),
					'noFetch'      => __( 'This site could not open its own pages, so the check cannot run from here. Open each address below in a private (incognito) window and search the page for the words in step 2.', 'fishotel-misc-plugin' ),
					'pass'         => __( 'Clean', 'fishotel-misc-plugin' ),
					'fail'         => __( 'Still says', 'fishotel-misc-plugin' ),
					'everyPage'    => __( 'On every page (header or footer) — not counted against each page', 'fishotel-misc-plugin' ),
					'reload'       => __( 'Done. Reload the page to see the table as it is now.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/**
	 * Stop unless the person may use the tool and the request is ours.
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Med_Listings::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'fishotel-misc-plugin' ) ), 403 );
		}
	}

	/**
	 * Everything the page shows, worked out once.
	 *
	 * @return array
	 */
	public function model() {
		$words    = Words::get();
		$found    = Inventory::find( $words );
		$products = array();
		$flags    = array();
		foreach ( $found as $pid => $reasons ) {
			$snap = Inventory::snapshot( $pid, $words );
			if ( ! $snap ) {
				continue;
			}
			$built      = Proposals::build( $snap, $words );
			$products[] = array(
				'snap'    => $snap,
				'reasons' => $reasons,
				'rows'    => $built['rows'],
				'flags'   => $built['flags'],
				'facts'   => $built['facts'],
				'ledger'  => Ledger::for_product( $pid ),
			);
			foreach ( $built['flags'] as $f ) {
				$f['product']    = $snap['name'];
				$f['product_id'] = $pid;
				$flags[]         = $f;
			}
		}
		$pids  = array_keys( $found );
		$pages = Verify::pages( $pids );
		set_transient( self::PAGES_TRANSIENT . get_current_user_id(), $pages, HOUR_IN_SECONDS );

		return array(
			'words'    => $words,
			'products' => $products,
			'flags'    => $flags,
			'linking'  => Inventory::linking_pages( $pids ),
			'terms'    => Terms::proposals( $words ),
			'targets'  => $this->target_choices(),
			'changes'  => Ledger::all()['terms'],
			'reviews'  => Ledger::all()['reviews'],
			'redirect' => Redirects::all(),
			'pages'    => $pages,
			'breeze'   => has_action( 'breeze_clear_all_cache' ) || class_exists( '\Breeze_PurgeCache' ),
		);
	}

	/**
	 * The categories a row can send its products or its address to.
	 *
	 * @return array<string, string> choice => words
	 */
	private function target_choices() {
		$out = array();
		foreach ( Terms::TARGETS as $type => $def ) {
			$t            = Terms::target_term( $type );
			$out[ $type ] = $t ? $t->name : sprintf( /* translators: %s: category */ __( '%s (made under Medications)', 'fishotel-misc-plugin' ), $def[0] );
		}
		$parent = Inventory::parent_term();
		$out['parent'] = $parent ? $parent->name : __( 'Medications', 'fishotel-misc-plugin' );
		$tree  = Inventory::med_term_ids();
		foreach ( $tree as $tid ) {
			$t = get_term( (int) $tid, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) && ( ! $parent || (int) $t->term_id !== (int) $parent->term_id ) && ! in_array( $t->name, $out, true ) ) {
				$out[ 'term:' . (int) $t->term_id ] = $t->name;
			}
		}
		return $out;
	}

	/**
	 * The page.
	 */
	public function render_page() {
		if ( ! current_user_can( Med_Listings::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}
		$model = $this->model();
		include Med_Listings::path() . 'views/page.php';
	}

	/**
	 * Apply ticked rows of one product.
	 */
	public function ajax_apply() {
		$this->guard();
		$pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $pid || 'product' !== get_post_type( $pid ) ) {
			wp_send_json_error( array( 'message' => __( 'No such product.', 'fishotel-misc-plugin' ) ) );
		}
		$rows = isset( $_POST['rows'] ) ? json_decode( wp_unslash( (string) $_POST['rows'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized per field in Fields::input_to_raw().
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to apply.', 'fishotel-misc-plugin' ) ) );
		}
		$result = Ledger::apply( $pid, $rows, Inventory::owned_ids( $pid ) );
		wp_send_json_success( array( 'rows' => $result ) );
	}

	/**
	 * Undo one product.
	 */
	public function ajax_undo() {
		$this->guard();
		$pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		wp_send_json_success( Ledger::undo( $pid ) );
	}

	/**
	 * Hide a review, or show it again.
	 */
	public function ajax_review() {
		$this->guard();
		$cid  = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$hide = isset( $_POST['hide'] ) && '1' === $_POST['hide'];
		$c    = get_comment( $cid );
		if ( ! $c || 'product' !== get_post_type( (int) $c->comment_post_ID ) ) {
			wp_send_json_error( array( 'message' => __( 'No such review.', 'fishotel-misc-plugin' ) ) );
		}
		$ok = Ledger::review( $cid, $hide );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( (int) $c->comment_post_ID );
		}
		$ok ? wp_send_json_success( array( 'hidden' => $hide ) ) : wp_send_json_error( array( 'message' => __( 'WordPress did not change it.', 'fishotel-misc-plugin' ) ) );
	}

	/**
	 * One category row.
	 */
	public function ajax_term() {
		$this->guard();
		$row = isset( $_POST['row'] ) ? json_decode( wp_unslash( (string) $_POST['row'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Terms::apply().
		if ( ! is_array( $row ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to change.', 'fishotel-misc-plugin' ) ) );
		}
		$row['action'] = in_array( $row['action'] ?? '', array( 'sort', 'merge', 'rename', 'delete', 'untag', 'delete_attribute', 'keep' ), true ) ? $row['action'] : 'keep';
		wp_send_json_success( Terms::apply( $row, Words::get() ) );
	}

	/**
	 * Undo one category change.
	 */
	public function ajax_term_undo() {
		$this->guard();
		$change = isset( $_POST['change'] ) ? sanitize_key( wp_unslash( $_POST['change'] ) ) : '';
		wp_send_json_success( Terms::undo( $change ) );
	}

	/**
	 * Take one address off the redirect list.
	 */
	public function ajax_redirect_remove() {
		$this->guard();
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		wp_send_json_success( array( 'removed' => Redirects::remove( '', $from ) ) );
	}

	/**
	 * Clear the caches.
	 */
	public function ajax_purge() {
		$this->guard();
		$words = Words::get();
		$done  = Verify::purge( array_keys( Inventory::find( $words ) ) );
		$msg   = $done['done'] ? sprintf( /* translators: %s: list */ __( 'Cleared: %s.', 'fishotel-misc-plugin' ), implode( '; ', $done['done'] ) ) : __( 'Nothing this tool can clear here.', 'fishotel-misc-plugin' );
		if ( ! $done['varnish'] ) {
			$msg .= ' ' . __( 'Varnish could not be cleared from here: in Cloudways open the application → Application Settings → Varnish → Purge (or Breeze → Purge All Cache).', 'fishotel-misc-plugin' );
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Open one page and read it.
	 */
	public function ajax_check() {
		$this->guard();
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$pages = get_transient( self::PAGES_TRANSIENT . get_current_user_id() );
		$pages = is_array( $pages ) ? $pages : Verify::pages( array_keys( Inventory::find( Words::get() ) ) );
		$page  = null;
		foreach ( $pages as $p ) {
			if ( $p['url'] === $url ) {
				$page = $p;
				break;
			}
		}
		if ( ! $page ) {
			wp_send_json_error( array( 'message' => __( 'Not one of the pages this check opens — reload the page.', 'fishotel-misc-plugin' ) ) );
		}
		$got = Verify::fetch( $url );
		if ( ! $got['ok'] ) {
			wp_send_json_success(
				array(
					'fetched' => false,
					'error'   => $got['error'],
					'code'    => $got['code'],
				)
			);
		}
		$med_urls = Verify::med_paths( $pages, Redirects::all() );
		wp_send_json_success(
			array(
				'fetched' => true,
				'hits'    => Verify::scan( $got['html'], $page['kind'], Words::get(), $med_urls, Verify::product_slugs( $pages ) ),
			)
		);
	}

	/**
	 * The backup CSV: every field the page reads on every product found
	 * (empty ones too), each with what it says now AND its stored value
	 * exactly as WordPress holds it (JSON: whether it exists, and the
	 * value — an attribute with its term ids, position and flags, a
	 * picture address with its old addresses), and every category, tag and
	 * attribute the Categories step lists, with its products, its own
	 * settings, the menu items pointing at it and the categories under it.
	 *
	 * The CSV is for reading and for checking by hand. Undo does not need
	 * it: the exact record of every old value is the option
	 * fishotel_med_listings_ledger.
	 */
	public function download_backup() {
		if ( ! current_user_can( Med_Listings::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fishotel-misc-plugin' ) );
		}
		check_admin_referer( self::NONCE );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="med-listings-backup-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		foreach ( self::backup_rows( Words::get() ) as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download.
		exit;
	}

	/**
	 * The backup's rows, header first.
	 *
	 * @param array $words Words::get().
	 * @return array<int, string[]>
	 */
	public static function backup_rows( array $words ) {
		$rows   = array();
		$rows[] = array( 'kind', 'product_id', 'product_name', 'post_id', 'field', 'label', 'where', 'current_value', 'stored_value_json' );
		foreach ( Inventory::find( $words ) as $pid => $reasons ) {
			$snap = Inventory::snapshot( $pid, $words );
			if ( ! $snap ) {
				continue;
			}
			$cats = wp_get_object_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
			$rows[] = array( 'product', $pid, $snap['name'], $pid, 'categories', 'Categories', '', self::csv_safe( implode( ', ', $snap['cats'] ) ), wp_json_encode( array( 'exists' => true, 'raw' => is_array( $cats ) ? array_map( 'intval', $cats ) : array() ) ) );
			foreach ( $snap['fields'] as $f ) {
				$rows[] = array( 'product', $pid, $snap['name'], $f['object'], $f['field'], $f['label'], $f['where'], self::csv_safe( (string) $f['read']['text'] ), wp_json_encode( array( 'exists' => (bool) $f['read']['exists'], 'raw' => $f['read']['raw'] ) + ( isset( $f['read']['order'] ) ? array( 'order' => $f['read']['order'] ) : array() ) ) );
			}
			foreach ( $snap['comments'] as $c ) {
				$rows[] = array( 'review', $pid, $snap['name'], $c['id'], 'comment_status', 'Review', (string) $c['author'], self::csv_safe( (string) $c['content'] ), wp_json_encode( array( 'exists' => true, 'raw' => $c['status'] ) ) );
			}
		}
		foreach ( Terms::proposals( $words ) as $t ) {
			if ( 'attribute' === $t['tax'] ) {
				$def    = Terms::attribute_def( (string) $t['slug'] );
				$values = taxonomy_exists( (string) $t['slug'] ) ? get_terms( array( 'taxonomy' => (string) $t['slug'], 'hide_empty' => false ) ) : array();
				$vals   = array();
				foreach ( is_array( $values ) ? $values : array() as $v ) {
					$vals[] = array(
						'term_id'     => (int) $v->term_id,
						'name'        => (string) $v->name,
						'slug'        => (string) $v->slug,
						'description' => (string) $v->description,
						'meta'        => function_exists( 'get_term_meta' ) ? get_term_meta( (int) $v->term_id ) : array(),
						'products'    => Terms::products_in( (int) $v->term_id, (string) $t['slug'] ),
					);
				}
				$rows[] = array( 'attribute', '', '', $t['id'], $t['slug'], $t['name'], '', self::csv_safe( $t['desc'] ), wp_json_encode( array( 'exists' => true, 'raw' => array( 'attribute' => $def, 'values' => $vals ) ) ) );
				continue;
			}
			$term = get_term( (int) $t['id'], $t['tax'] );
			$raw  = array(
				'name'        => $t['name'],
				'slug'        => $t['slug'],
				'description' => $t['desc'],
				'parent'      => $term ? (int) $term->parent : 0,
				'meta'        => function_exists( 'get_term_meta' ) ? get_term_meta( (int) $t['id'] ) : array(),
				'products'    => Terms::products_in( (int) $t['id'], $t['tax'] ),
				'menu_items'  => Terms::menu_items( (int) $t['id'], $t['tax'] ),
				'children'    => is_array( $kids = get_term_children( (int) $t['id'], $t['tax'] ) ) ? array_map( 'intval', $kids ) : array(), // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			);
			$rows[] = array( 'term', '', '', $t['id'], $t['tax'], $t['name'], $t['slug'], self::csv_safe( $t['desc'] ), wp_json_encode( array( 'exists' => true, 'raw' => $raw ) ) );
		}
		return $rows;
	}

	/**
	 * A cell a spreadsheet will not run as a formula.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function csv_safe( $s ) {
		return preg_match( '/^[=+\-@\t\r]/', (string) $s ) ? "'" . $s : (string) $s;
	}

	/**
	 * Save the word lists.
	 */
	public function save_words() {
		if ( ! current_user_can( Med_Listings::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fishotel-misc-plugin' ) );
		}
		check_admin_referer( self::NONCE );
		$input = array();
		foreach ( array_merge( Words::LISTS, Words::LINES ) as $key ) {
			if ( isset( $_POST[ 'fhml_' . $key ] ) ) {
				// Not sanitize_textarea_field(): it would eat a pattern's "(?<!". The lists are only matched and shown escaped.
				$input[ $key ] = wp_check_invalid_utf8( (string) wp_unslash( $_POST[ 'fhml_' . $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		if ( ! empty( $_POST['fhml_reset'] ) ) {
			delete_option( Words::OPTION );
			$bad = array();
		} else {
			$got = Words::from_input( $input );
			update_option( Words::OPTION, $got['saved'], false );
			$bad = $got['bad'];
		}
		wp_safe_redirect( add_query_arg( array( 'page' => Med_Listings::MENU_SLUG, 'words' => $bad ? 'bad' : 'saved' ), admin_url( 'admin.php' ) ) . '#fhml-find' );
		exit;
	}
}
