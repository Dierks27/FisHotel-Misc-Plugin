<?php
/**
 * Import lines — the order page's importer, on top of Order_Import (the
 * reader, the column finder, the matcher, the reconciliation) and
 * Import_Layouts (what the owner told it about a supplier's sheet).
 *
 * One AJAX action (fishotel_cleanroom_order_import), the order page's own
 * nonce and capability (manage_woocommerce); an uploaded FILE asks for
 * what the workbook import asks for (manage_options) and goes through the
 * same upload handling (Admin_Import::move_upload()) — it is read once and
 * deleted before the reply is sent.
 *
 * Nothing is kept on the server between the preview and the confirm: the
 * reply to a paste or an upload carries the cleaned grid, and the page
 * posts it back with what the owner chose (the columns, the ticks, the
 * picks). Every request also carries the order form as it stands on the
 * page, so:
 *
 *   - "the lines already on the order" are the lines the owner sees;
 *   - a confirm is the page's own Save (Admin_Workbook::save_order()) with
 *     the imported rows appended — in the arrived mode, with the received
 *     counts written on the paired lines first. An imported line therefore
 *     takes exactly the path a typed line takes: the costing, the box
 *     shares, the sizes, the "??" rule, the landed-total cache;
 *   - a preview that is stale — the order was changed meanwhile, here or
 *     elsewhere — is refused and drawn again (the token).
 *
 * An import never removes a line, never changes a line that has a stay,
 * and never checks anything in.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Order_Import
 */
class Admin_Order_Import {

	const ACTION        = 'fishotel_cleanroom_order_import';
	const ACTION_LAYOUT = 'fishotel_cleanroom_import_layout';

	/** How many rows the column dropdowns are shown above. */
	const SAMPLE_ROWS = 6;

	/** How many left-out rows are named (all are counted). */
	const LEFT_OUT_SHOWN = 40;

	/**
	 * The text files the importer takes beside a workbook.
	 *
	 * @var array<string, string>
	 */
	const TEXT_MIMES = array(
		'csv' => 'text/csv',
		'tsv' => 'text/tsv',
		'txt' => 'text/plain',
	);

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
		add_action( 'wp_ajax_' . self::ACTION_LAYOUT, array( $this, 'ajax_layout' ) );
	}

	/**
	 * What js/order-import.js is handed (wp_localize_script): the action,
	 * the roles a column can have, and its words.
	 *
	 * @return array
	 */
	public static function js_config() {
		return array(
			'action'       => self::ACTION,
			'layoutAction' => self::ACTION_LAYOUT,
			'roles'        => Order_Import::roles(),
			'i18n'         => array(
				'error'          => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
				'nothing'        => __( 'Paste the rows first, or pick a file.', 'fishotel-misc-plugin' ),
				'reading'        => __( 'Reading…', 'fishotel-misc-plugin' ),
				'saving'         => __( 'Saving…', 'fishotel-misc-plugin' ),
				/* translators: %d: fields */
				'filledOne'      => __( '%d empty received field filled — correct the ones that differ, then Save.', 'fishotel-misc-plugin' ),
				/* translators: %d: fields */
				'filledMany'     => __( '%d empty received fields filled — correct the ones that differ, then Save.', 'fishotel-misc-plugin' ),
				'asTyped'        => __( 'use as typed (unlisted fish)', 'fishotel-misc-plugin' ),
				'unknown'        => __( 'Unknown fish (??)', 'fishotel-misc-plugin' ),
				'picked'         => __( 'picked by you', 'fishotel-misc-plugin' ),
				'check'          => __( '? check', 'fishotel-misc-plugin' ),
				'checkTitle'     => __( 'Whoever read the invoice was not sure about this row: compare it with the invoice.', 'fishotel-misc-plugin' ),
				/* translators: %s: what the label said */
				'label'          => __( 'label said: %s', 'fishotel-misc-plugin' ),
				'badNumber'      => __( 'This cell could not be read as a number, so it is left empty. (A decimal comma is not assumed: write 6.50, not 6,50.)', 'fishotel-misc-plugin' ),
				/* translators: %s: what the cell is */
				'badCell'        => __( '%s could not be read', 'fishotel-misc-plugin' ),
				'badRow'         => __( '? a number could not be read', 'fishotel-misc-plugin' ),
				/* translators: %s: the supplier's code */
				'code'           => __( 'code %s', 'fishotel-misc-plugin' ),
				/* translators: %s: fish per box */
				'perBox'         => __( '%s per box', 'fishotel-misc-plugin' ),
				/* translators: %s: box number */
				'box'            => __( 'box %s', 'fishotel-misc-plugin' ),
				/* translators: %s: percent */
				'pct'            => __( '%s%% of a box', 'fishotel-misc-plugin' ),
				/* translators: %s: count */
				'doa'            => __( '%s DOA', 'fishotel-misc-plugin' ),
				'junk'           => __( 'not a fish — always leave rows like this out', 'fishotel-misc-plugin' ),
				/* translators: 1: lines, 2: fish */
				'foot'           => __( '%1$s lines · %2$s fish', 'fishotel-misc-plugin' ),
				/* translators: 1: lines (one), 2: fish */
				'footOne'        => __( '%1$s line · %2$s fish', 'fishotel-misc-plugin' ),
				'feeWord'        => __( '? a fee\'s word in a fish\'s name', 'fishotel-misc-plugin' ),
				'feeWordTitle'   => __( 'This row carries a supplier\'s code or a scientific name, so it is kept as a fish — but its name holds a word fee lines use. Untick it if it is a fee after all.', 'fishotel-misc-plugin' ),
				/* translators: %s: lines */
				'addOne'         => __( 'Add %s line to the order', 'fishotel-misc-plugin' ),
				/* translators: %s: lines */
				'addMany'        => __( 'Add %s lines to the order', 'fishotel-misc-plugin' ),
				'writeArrived'   => __( 'Write what arrived on the order', 'fishotel-misc-plugin' ),
				'headerOn'       => __( 'The header row is', 'fishotel-misc-plugin' ),
				'noHeader'       => __( 'none — the first row is already a fish', 'fishotel-misc-plugin' ),
				/* translators: %d: row number */
				'rowN'           => __( 'row %d', 'fishotel-misc-plugin' ),
				/* translators: %d: column number */
				'columnN'        => __( 'Column %d is…', 'fishotel-misc-plugin' ),
				'thisColumn'     => __( 'this column is…', 'fishotel-misc-plugin' ),
				'ignore'         => __( 'ignore', 'fishotel-misc-plugin' ),
				'byHeaders'      => __( 'Columns found by their headings', 'fishotel-misc-plugin' ),
				'byGuess'        => __( 'No header row found: the columns below are a guess — check them', 'fishotel-misc-plugin' ),
				'byHand'         => __( 'Columns as you set them', 'fishotel-misc-plugin' ),
				'change'         => __( 'Change the columns', 'fishotel-misc-plugin' ),
				'useLayout'      => __( 'Use that layout', 'fishotel-misc-plugin' ),
				'sheet'          => __( 'Sheet:', 'fishotel-misc-plugin' ),
				'thFish'         => __( 'Fish as typed → on the order', 'fishotel-misc-plugin' ),
				'thSize'         => __( 'Size', 'fishotel-misc-plugin' ),
				'thOrdered'      => __( 'Ordered', 'fishotel-misc-plugin' ),
				'thReceived'     => __( 'Received', 'fishotel-misc-plugin' ),
				'thPrice'        => __( 'Price', 'fishotel-misc-plugin' ),
				'thMore'         => __( 'Also read', 'fishotel-misc-plugin' ),
				'thDup'          => __( 'Already on the order?', 'fishotel-misc-plugin' ),
				/* translators: %s: the line already on the order */
				'dup'            => __( 'already on the order: %s', 'fishotel-misc-plugin' ),
				'dupSkip'        => __( 'skip', 'fishotel-misc-plugin' ),
				'dupAdd'         => __( 'add anyway', 'fishotel-misc-plugin' ),
				'pairAuto'       => __( 'pair it for me', 'fishotel-misc-plugin' ),
				'pairThis'       => __( 'this line', 'fishotel-misc-plugin' ),
				'pairNew'        => __( 'a new line (extra)', 'fishotel-misc-plugin' ),
				'pairInsteadSet' => __( 'came instead of an ordered line', 'fishotel-misc-plugin' ),
				/* translators: %s: an order line */
				'pairLine'       => __( 'is: %s', 'fishotel-misc-plugin' ),
				/* translators: %s: an order line */
				'pairInstead'    => __( 'this came instead of: %s', 'fishotel-misc-plugin' ),
				'sections'       => __( 'Which sections of this invoice belong to this order?', 'fishotel-misc-plugin' ),
				/* translators: 1: section, 2: how many rows it has */
				'sectionN'       => __( '%1$s (%2$s)', 'fishotel-misc-plugin' ),
				'sameFish'       => __( 'The same fish in two boxes:', 'fishotel-misc-plugin' ),
				'sameSum'        => __( 'add them up on the one ordered line (the boxes are noted)', 'fishotel-misc-plugin' ),
				'sameSeparate'   => __( 'keep them as separate lines', 'fishotel-misc-plugin' ),
				/* translators: %s: count */
				'arrivedN'       => __( 'arrived %s', 'fishotel-misc-plugin' ),
				/* translators: %s: price */
				'priceN'         => __( 'price %s', 'fishotel-misc-plugin' ),
				/* translators: %s: received count */
				'kept'           => __( 'has %s received — left as it is', 'fishotel-misc-plugin' ),
				'lineZero'       => __( 'did not come: received becomes 0', 'fishotel-misc-plugin' ),
				'lineKeep'       => __( 'leave this line as it is', 'fishotel-misc-plugin' ),
				/* translators: %d: rows */
				'unticked'       => __( 'Left out by you (%d)', 'fishotel-misc-plugin' ),
				'useFilePrice'   => __( 'Use the file\'s price where a line already has a typed price (otherwise a typed price is kept)', 'fishotel-misc-plugin' ),
				'feeLines'       => __( 'The fee lines', 'fishotel-misc-plugin' ),
				'putFees'        => __( 'Put these on the order (Tariffs and Import & re-ox fees; the domestic flight stays typed by hand)', 'fishotel-misc-plugin' ),
				'replaceFees'    => __( 'Replace the amounts already typed on the order', 'fishotel-misc-plugin' ),
				'copied'         => __( 'Copied. Paste it into your assistant together with the invoice.', 'fishotel-misc-plugin' ),
				'confirmLayout'  => __( 'Forget this layout? The next file from this supplier will ask for its columns again.', 'fishotel-misc-plugin' ),
			),
		);
	}

	/* ============================================================== */
	/*  AJAX                                                           */
	/* ============================================================== */

	/**
	 * AJAX: preview (a paste, a file, or the grid posted back with what
	 * the owner chose) and confirm.
	 */
	public function ajax() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		check_ajax_referer( Admin::NONCE_AJAX, 'nonce' );
		if ( ! Store::tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ) ), 500 );
		}

		$raw  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$file = null;
		if ( isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) && isset( $_FILES['file']['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['file']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'Uploading a file needs an administrator. Paste the rows instead: copy them out of the spreadsheet and paste them into the box.', 'fishotel-misc-plugin' ) ), 403 );
			}
			$file = self::take_upload( $_FILES['file'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- handed to wp_handle_upload().
			if ( isset( $file['error'] ) ) {
				wp_send_json_error( array( 'message' => $file['error'] ), 400 );
			}
		}

		try {
			$result = self::request(
				new Store(),
				is_array( $raw ) ? $raw : array(),
				array(
					'today'    => Clean_Room::today(),
					'settings' => Settings::get(),
					'actor_id' => (int) get_current_user_id(),
					'file'     => $file,
				)
			);
		} catch ( \Throwable $e ) {
			$result = array( 'error' => $e->getMessage() );
		}
		// request() deletes the file once it is read; should it have thrown before that, it goes here.
		if ( $file && ! empty( $file['path'] ) && is_file( $file['path'] ) ) {
			unlink( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own upload.
		}

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error(
				array(
					'message' => (string) $result['error'],
					'preview' => isset( $result['preview'] ) ? $result['preview'] : null,
				),
				isset( $result['status'] ) ? (int) $result['status'] : 400
			);
		}
		if ( ! empty( $result['flash'] ) ) {
			Admin_Workbook::flash( (string) $result['flash'] ); // The page reloads on the order as it is now: say what happened there.
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: forget a saved layout (Settings → Saved import layouts).
	 */
	public function ajax_layout() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		check_ajax_referer( Settings::NONCE_ACTION, '_wpnonce' );

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( '' === $id || ! Import_Layouts::forget( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That layout is not saved any more.', 'fishotel-misc-plugin' ) ), 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Layout forgotten.', 'fishotel-misc-plugin' ) ) );
	}

	/**
	 * Move an uploaded file into the private folder — the workbook
	 * import's own upload handling, with the text files added.
	 *
	 * @param array $file One $_FILES entry.
	 * @return array{path: string, name: string}|array{error: string}
	 */
	public static function take_upload( array $file ) {
		$mimes = array_merge( Admin_Import::MIMES, self::TEXT_MIMES );
		$moved = Admin_Import::move_upload( $file, $mimes );
		if ( isset( $moved['error'] ) ) {
			return array( 'error' => 'type' === $moved['code'] ? __( 'Only a .csv, .tsv, .txt, .xlsx or .xlsm file can be read. For a PDF or a photo, have an assistant read the invoice (the panel beside the box).', 'fishotel-misc-plugin' ) : $moved['error'] );
		}
		return $moved;
	}

	/* ============================================================== */
	/*  One request                                                    */
	/* ============================================================== */

	/**
	 * A preview or a confirm (the testable half of ajax()).
	 *
	 * @param object $store Store (or the tests' stand-in).
	 * @param array  $raw   Unslashed payload: op, order_id, the order form (header fields,
	 *                      lines or lines_json), import (JSON: text | grid, mode, map, header_row,
	 *                      hand, use_layout, sections, arrived_too, same_fish, use_file_price,
	 *                      fees, fees_replace, remember, rows, lines, sheet, token).
	 * @param array  $ctx   today, settings, actor_id, file (path, name — an upload).
	 * @return array The preview, or the confirm's result — or error (+ status, preview).
	 */
	public static function request( $store, array $raw, array $ctx ) {
		$raw = Admin_Workbook::unpack_lines( $raw );
		if ( ! empty( $raw['lines_unread'] ) ) {
			return array( 'error' => Admin_Workbook::lines_unread_text() );
		}
		$op  = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : 'preview';
		$id  = isset( $raw['order_id'] ) && is_scalar( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;

		$stored = $id > 0 ? $store->order( $id ) : null;
		if ( $id > 0 && ! $stored ) {
			return array(
				'error'  => __( 'That order no longer exists.', 'fishotel-misc-plugin' ),
				'status' => 404,
			);
		}

		$in = isset( $raw['import'] ) && is_string( $raw['import'] ) ? json_decode( $raw['import'], true ) : ( isset( $raw['import'] ) && is_array( $raw['import'] ) ? $raw['import'] : array() );
		$in = is_array( $in ) ? $in : array();

		// The grid: an uploaded file, a paste, or the grid the page posts back.
		$file = isset( $ctx['file'] ) && is_array( $ctx['file'] ) ? $ctx['file'] : null;
		$read = self::read_input( $in, $file, $store, $raw );
		// An uploaded file is read once and goes — whatever it held.
		if ( $file && ! empty( $file['path'] ) && is_file( $file['path'] ) ) {
			unlink( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own upload.
		}
		if ( isset( $read['error'] ) ) {
			return array( 'error' => $read['error'] );
		}
		if ( ! $read['grid'] ) {
			return array( 'error' => __( 'Nothing to read: paste the rows, or pick a file.', 'fishotel-misc-plugin' ) );
		}

		$plan = self::plan( $store, $raw, $in, $read, $stored, $ctx );

		if ( 'confirm' !== $op ) {
			return self::for_page( $plan, $read );
		}

		$token = isset( $in['token'] ) && is_string( $in['token'] ) ? $in['token'] : '';
		if ( '' === $token || ! hash_equals( $plan['token'], $token ) ) {
			return array(
				'error'   => __( 'The order changed since this preview was made, so nothing was imported. Here is the preview again, against the order as it is now.', 'fishotel-misc-plugin' ),
				'status'  => 409,
				'preview' => self::for_page( $plan, $read ),
			);
		}
		return self::confirm( $store, $raw, $in, $plan, $id, $ctx );
	}

	/**
	 * The grid of a request.
	 *
	 * @param array      $in    The import payload.
	 * @param array|null $file  An upload (path, name).
	 * @param object     $store Store.
	 * @param array      $raw   The request (for the order's supplier: a saved layout names the sheet).
	 * @return array{grid: string[][], truncated: bool, sheet: string, sheets: string[], fresh: bool}|array{error: string}
	 */
	public static function read_input( array $in, $file, $store, array $raw ) {
		unset( $store );
		$out = array(
			'grid'      => array(),
			'truncated' => false,
			'sheet'     => isset( $in['sheet_name'] ) && is_string( $in['sheet_name'] ) ? Order_Import::cut( Order_Import::cell( $in['sheet_name'] ), Xlsx::SHEET_NAME_MAX ) : '',
			'sheets'    => array(),
			'fresh'     => true,
		);
		if ( $file && ! empty( $file['path'] ) ) {
			$header = Orders::normalize_order( $raw );
			$mode   = isset( $in['mode'] ) && 'arrived' === $in['mode'] ? 'arrived' : 'ordered';
			$want   = isset( $in['sheet'] ) && is_string( $in['sheet'] ) ? Order_Import::cell( $in['sheet'] ) : '';
			if ( '' === $want ) {
				foreach ( Import_Layouts::all() as $layout ) {
					if ( '' !== $layout['sheet'] && $layout['moment'] === $mode && Species::key( $layout['supplier'] ) === Species::key( $header['supplier'] ) ) {
						$want = $layout['sheet'];
						break;
					}
				}
			}
			return self::read_file( (string) $file['path'], (string) ( $file['name'] ?? '' ), $want );
		}
		if ( isset( $in['text'] ) && is_string( $in['text'] ) && '' !== trim( $in['text'] ) ) {
			if ( strlen( $in['text'] ) > Order_Import::MAX_TEXT_BYTES ) {
				return array( 'error' => __( 'That is more than 2 MB of text. Paste the rows of one order, or upload the file.', 'fishotel-misc-plugin' ) );
			}
			$read = Order_Import::grid_from_text( $in['text'] );
			return array_merge(
				$out,
				array(
					'grid'      => $read['grid'],
					'truncated' => $read['truncated'],
				)
			);
		}
		if ( isset( $in['grid'] ) && is_array( $in['grid'] ) ) {
			$read = Order_Import::clean_grid( $in['grid'] );
			return array_merge(
				$out,
				array(
					'grid'      => $read['grid'],
					'truncated' => ! empty( $in['truncated'] ),
					'fresh'     => false,
				)
			);
		}
		return $out;
	}

	/**
	 * Read a file into a grid: a workbook through the section's own
	 * reader (Workbook, with its limits) — the sheet asked for, else the
	 * first sheet with a header row the importer can save from, else the
	 * first sheet with anything on it —, a text file as a paste.
	 *
	 * @param string $path  The stored file.
	 * @param string $name  Its name (for the type).
	 * @param string $sheet The sheet to read ('' = find one).
	 * @return array read_input()'s shape, or error.
	 */
	public static function read_file( $path, $name, $sheet = '' ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			$ext = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		}
		$out = array(
			'grid'      => array(),
			'truncated' => false,
			'sheet'     => '',
			'sheets'    => array(),
			'fresh'     => true,
		);

		if ( isset( self::TEXT_MIMES[ $ext ] ) ) {
			if ( ! is_file( $path ) || filesize( $path ) > Order_Import::MAX_TEXT_BYTES ) {
				return array( 'error' => __( 'That text file is larger than 2 MB.', 'fishotel-misc-plugin' ) );
			}
			$read = Order_Import::grid_from_text( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own upload.
			return array_merge(
				$out,
				array(
					'grid'      => $read['grid'],
					'truncated' => $read['truncated'],
				)
			);
		}
		if ( ! isset( Admin_Import::MIMES[ $ext ] ) ) {
			return array( 'error' => __( 'Only a .csv, .tsv, .txt, .xlsx or .xlsm file can be read.', 'fishotel-misc-plugin' ) );
		}

		$workbook = new Workbook();
		if ( ! $workbook->open( $path ) ) {
			/* translators: %s: the reader's error */
			return array( 'error' => sprintf( __( 'That file could not be read as a workbook: %s', 'fishotel-misc-plugin' ), $workbook->last_error() ) );
		}
		$names  = array_values( array_map( 'strval', (array) $workbook->sheet_names() ) );
		$picked = null;
		$first  = null;
		$tried  = '' !== $sheet && $workbook->has_sheet( $sheet ) ? array_merge( array( $sheet ), $names ) : $names;
		foreach ( array_slice( array_unique( $tried ), 0, 12 ) as $candidate ) {
			$rows = $workbook->rows( $candidate, Order_Import::MAX_ROWS + 200 );
			$workbook->forget( $candidate );
			if ( ! is_array( $rows ) || ! $rows ) {
				continue;
			}
			$read          = Order_Import::grid_from_rows( $rows );
			$read['sheet'] = (string) $candidate;
			foreach ( $names as $name ) {
				if ( 0 === strcasecmp( $name, (string) $candidate ) ) {
					$read['sheet'] = $name; // As the workbook spells it.
				}
			}
			if ( ! $read['grid'] ) {
				continue;
			}
			if ( null === $first ) {
				$first = $read;
			}
			if ( ( '' !== $sheet && 0 === strcasecmp( $sheet, (string) $candidate ) ) || Order_Import::map_complete( Order_Import::find_header( $read['grid'] )['map'] ) ) {
				$picked = $read;
				break;
			}
		}
		$workbook->close();

		$picked = $picked ? $picked : $first;
		if ( ! $picked ) {
			return array( 'error' => __( 'That workbook has no sheet with rows on it.', 'fishotel-misc-plugin' ) );
		}
		return array_merge(
			$out,
			array(
				'grid'      => $picked['grid'],
				'truncated' => $picked['truncated'],
				'sheet'     => Order_Import::cut( $picked['sheet'], Xlsx::SHEET_NAME_MAX ),
				'sheets'    => array_map(
					static function ( $name ) {
						return Order_Import::cut( Order_Import::cell( $name ), Xlsx::SHEET_NAME_MAX );
					},
					array_slice( $names, 0, 30 )
				),
			)
		);
	}

	/* ============================================================== */
	/*  The order as the page shows it                                 */
	/* ============================================================== */

	/**
	 * The lines of the order as they stand on the page (the posted form),
	 * each with what only the stored line knows: whether it became a stay,
	 * its notes, its received count when the page shows it read-only.
	 *
	 * @param array      $raw    The request (lines: the posted rows).
	 * @param array|null $stored The stored order.
	 * @return array[] key (the posted row's index), id, species, species_id, size_slug, size_label,
	 *                 qty_ordered, qty_received, unit_price, qty_doa, box_no, box_pct, stay_id, notes.
	 */
	public static function page_lines( array $raw, $stored ) {
		$by_id = array();
		foreach ( (array) ( $stored['lines'] ?? array() ) as $line ) {
			$by_id[ (int) $line['id'] ] = $line;
		}
		$out = array();
		foreach ( isset( $raw['lines'] ) && is_array( $raw['lines'] ) ? $raw['lines'] : array() as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line = Identify::flag_lines( array( Orders::normalize_line( $row ) ) )[0];
			if ( '' === $line['species'] ) {
				continue;
			}
			$was  = $line['id'] > 0 && isset( $by_id[ $line['id'] ] ) ? $by_id[ $line['id'] ] : null;
			$stay = $was && ! empty( $was['stay_id'] );
			if ( $stay ) {
				$line['qty_received'] = $was['qty_received'];
				$line['qty_doa']      = (int) $was['qty_doa'];
			}
			// The form posts the size's slug only: its label is the stored line's.
			if ( null !== $line['size_slug'] && null === $line['size_label'] && $was && (string) $was['size_slug'] === (string) $line['size_slug'] ) {
				$line['size_label'] = $was['size_label'];
			}
			$line['key']     = (string) $key;
			$line['stay_id'] = $stay ? (int) $was['stay_id'] : null;
			$line['notes']   = '' !== $line['notes'] ? $line['notes'] : ( $was ? (string) $was['notes'] : '' );
			$out[]           = $line;
			if ( count( $out ) >= Orders::MAX_LINES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * What a preview was made against: the order as stored and as it
	 * stands on the page. A confirm whose token is not this is stale.
	 *
	 * @param array      $header The posted header (Orders::normalize_order()).
	 * @param array[]    $lines  page_lines().
	 * @param array|null $stored The stored order.
	 * @return string
	 */
	public static function token( array $header, array $lines, $stored ) {
		$brief = static function ( array $line ) {
			return array( (int) ( $line['id'] ?? 0 ), Species::key( (string) ( $line['species'] ?? '' ) ), (string) ( $line['size_slug'] ?? '' ), (int) ( $line['qty_ordered'] ?? 0 ), isset( $line['qty_received'] ) && null !== $line['qty_received'] ? (int) $line['qty_received'] : null, isset( $line['unit_price'] ) && null !== $line['unit_price'] ? round( (float) $line['unit_price'], 4 ) : null, ! empty( $line['stay_id'] ) ? (int) $line['stay_id'] : 0 );
		};
		return md5(
			(string) wp_json_encode(
				array(
					(int) ( $stored['id'] ?? 0 ),
					Species::key( $header['supplier'] ),
					Species::key( $header['origin'] ),
					array_map( $brief, $lines ),
					array_map( $brief, (array) ( $stored['lines'] ?? array() ) ),
				)
			)
		);
	}

	/**
	 * Which of the two buttons fits an order: "This is what ARRIVED" once
	 * the order is received or closed — and already once it is saved as
	 * ordered with ordered lines on it, for the next file that comes for
	 * such an order is the invoice. Else "This is what I ORDERED".
	 *
	 * @param string $status The order's status.
	 * @param array  $lines  Its lines (qty_ordered).
	 * @param bool   $saved  Whether the order is saved.
	 * @return string ordered|arrived
	 */
	public static function default_mode( $status, array $lines, $saved ) {
		if ( in_array( (string) $status, array( 'received', 'closed' ), true ) ) {
			return 'arrived';
		}
		if ( $saved && 'ordered' === (string) $status ) {
			foreach ( $lines as $line ) {
				if ( is_array( $line ) && (int) ( $line['qty_ordered'] ?? 0 ) > 0 ) {
					return 'arrived';
				}
			}
		}
		return 'ordered';
	}

	/* ============================================================== */
	/*  The plan                                                       */
	/* ============================================================== */

	/**
	 * Everything a preview shows and a confirm does, from the grid and
	 * what the owner chose.
	 *
	 * @param object     $store  Store.
	 * @param array      $raw    The request (the order form).
	 * @param array      $in     The import payload.
	 * @param array      $read   read_input().
	 * @param array|null $stored The stored order.
	 * @param array      $ctx    today, settings.
	 * @return array
	 */
	public static function plan( $store, array $raw, array $in, array $read, $stored, array $ctx ) {
		$grid     = $read['grid'];
		$settings = isset( $ctx['settings'] ) && is_array( $ctx['settings'] ) ? $ctx['settings'] : array();
		$header   = Orders::normalize_order( $raw );
		$lines    = self::page_lines( $raw, $stored );
		$fitting  = self::default_mode( $header['status'], (array) ( $stored['lines'] ?? array() ), (bool) $stored );
		$mode     = isset( $in['mode'] ) && in_array( $in['mode'], Order_Import::MODES, true ) ? $in['mode'] : $fitting;
		$width    = $grid ? count( $grid[0] ) : 0;

		/* ---- the columns ---- */
		$layouts = Import_Layouts::all();
		$columns = array(
			'source'  => '',
			'row'     => null,
			'map'     => array(),
			'layout'  => null,
			'partial' => null,
			'offer'   => null,
			'junk'    => array(),
		);
		$hand    = ! empty( $in['hand'] ) && isset( $in['map'] ) && is_array( $in['map'] );
		$own     = Import_Layouts::find( $layouts, Import_Layouts::id( $header['supplier'], $header['origin'], $mode ) );
		if ( $hand ) {
			$columns['source'] = 'hand';
			$columns['map']    = Order_Import::clean_map( $in['map'], $width );
			$columns['row']    = isset( $in['header_row'] ) && is_numeric( $in['header_row'] ) && (int) $in['header_row'] >= 0 && (int) $in['header_row'] < min( count( $grid ), Order_Import::HEADER_SEARCH_ROWS ) ? (int) $in['header_row'] : null;
			$columns['junk']   = $own ? $own['junk'] : array();
		} else {
			$chosen = isset( $in['use_layout'] ) && is_string( $in['use_layout'] ) ? Import_Layouts::find( $layouts, sanitize_key( $in['use_layout'] ) ) : null;
			$known  = Import_Layouts::recognise( $layouts, $grid, $header['supplier'], $header['origin'], $mode );
			if ( $chosen ) {
				$fit = Import_Layouts::fit( $chosen, $grid );
				if ( Order_Import::map_complete( $fit['map'] ) ) {
					$known = array(
						'applied' => $chosen,
						'fit'     => $fit,
						'partial' => $fit['score'] < 1 || (bool) $fit['extra'],
						'offer'   => null,
					);
				}
			}
			if ( $known['applied'] ) {
				$columns['source']  = 'layout';
				$columns['layout']  = $known['applied'];
				$columns['map']     = $known['fit']['map'];
				$columns['row']     = $known['fit']['row'];
				$columns['junk']    = $known['applied']['junk'];
				$columns['partial'] = $known['partial'] ? array(
					'missing' => $known['fit']['missing'],
					'extra'   => $known['fit']['extra'],
				) : null;
			} else {
				$columns['offer'] = $known['offer'];
				$found            = Order_Import::find_header( $grid );
				if ( null !== $found['row'] ) {
					$columns['source'] = 'headers';
					$columns['row']    = $found['row'];
					$columns['map']    = $found['map'];
				} else {
					$columns['source'] = 'guess';
					$columns['map']    = Order_Import::guess_columns( $grid );
				}
			}
		}
		$complete = Order_Import::map_complete( $columns['map'] );

		/* ---- the rows ---- */
		$sizes    = Sizes::standard( $store, $settings )['sizes'];
		foreach ( $lines as $i => $line ) {
			$size = null !== $line['size_slug'] && null === $line['size_label'] ? Sizes::find( $sizes, $line['size_slug'] ) : null;
			if ( $size ) {
				$lines[ $i ]['size_label'] = $size['label'];
			}
		}
		$analysis = Order_Import::analyse(
			$grid,
			array(
				'mode'       => $mode,
				'map'        => $columns['map'],
				'header_row' => $columns['row'],
				'junk'       => $columns['junk'],
				'sizes'      => $sizes,
			)
		);

		// Which sections (origins) of the invoice belong to this order: all, unless the owner says.
		$sections = null;
		if ( isset( $in['sections'] ) && is_array( $in['sections'] ) && count( $analysis['sections'] ) > 1 ) {
			$sections = array();
			foreach ( $in['sections'] as $name ) {
				if ( is_string( $name ) && isset( $analysis['sections'][ Order_Import::cell( $name ) ] ) ) {
					$sections[] = Order_Import::cell( $name );
				}
			}
		}
		$chosen_keys = null === $sections ? null : array_map( array( Species::class, 'key' ), $sections );

		$said_rows = isset( $in['rows'] ) && is_array( $in['rows'] ) ? $in['rows'] : array();
		$index     = Order_Import::index_candidates( $complete ? (array) $store->species_candidates() : array() );
		$too       = ! empty( $in['arrived_too'] );
		$spa       = null;
		$rows      = array();
		$other     = 0;
		$capped    = 0;

		foreach ( $complete ? $analysis['rows'] : array() as $row ) {
			if ( null !== $chosen_keys && '' !== $row['origin'] && ! in_array( Species::key( $row['origin'] ), $chosen_keys, true ) ) {
				$other++;
				continue;
			}
			if ( count( $rows ) >= Orders::MAX_LINES ) {
				$capped++;
				continue;
			}
			$said = isset( $said_rows[ $row['n'] ] ) && is_array( $said_rows[ $row['n'] ] ) ? $said_rows[ $row['n'] ] : array();

			$row['mode']   = $mode;
			$row['picked'] = isset( $said['pick'] ) && is_array( $said['pick'] );
			$row['match']  = Order_Import::match( $row['name'], $row['sci'], $index );
			$row['fish']  = self::fish_of_row( $store, $row, $said, $settings );
			$row['on']    = isset( $said['on'] ) ? ! empty( $said['on'] ) : ! $row['bad_qty'];
			$row['junk']  = ! $row['on'] && ! empty( $said['junk'] );
			$row['keys']  = array_values( array_unique( array_filter( array( Species::key( $row['fish']['name'] ), Species::key( $row['name'] ), Species::key( $row['typed'] ), Species::key( Order_Import::match_text( $row['typed'] ) ) ) ) ) );

			$row['species_id'] = $row['fish']['species_id'];
			$row['tank_id']    = null;
			if ( '' !== $row['tank'] ) {
				$spa = null === $spa ? Orders::spa_tanks( $store ) : $spa;
				foreach ( $spa as $tank ) {
					if ( (string) (int) $tank['number'] === ltrim( (string) preg_replace( '/^\s*tank\s*#?\s*/i', '', $row['tank'] ), '0' ) ) {
						$row['tank_id'] = (int) $tank['id'];
					}
				}
				if ( null === $row['tank_id'] ) {
					$row['flags'][] = 'tank';
				}
			}
			if ( 'ordered' === $mode ) {
				$row['received'] = $too ? ( null !== $row['received'] ? $row['received'] : $row['ordered'] ) : null;
			}
			$rows[] = $row;
		}

		$plan = array(
			'mode'      => $mode,
			'header'    => $header,
			'lines'     => $lines,
			'stored'    => $stored,
			'token'     => self::token( $header, $lines, $stored ),
			'fitting'   => $fitting,
			'columns'   => $columns,
			'complete'  => $complete,
			'hand'      => $hand,
			'own'       => $own,
			'analysis'  => $analysis,
			'sections'  => $sections,
			'rows'      => $rows,
			'other'     => $other,
			'capped'    => $capped,
			'too'       => $too,
			'sizes'     => $sizes,
			'said'      => $in,
			'grid'      => $grid,
			'sheet'     => $read['sheet'],
		);

		if ( 'arrived' === $mode ) {
			$plan = self::plan_arrived( $plan, $in );
		} else {
			$plan = self::plan_ordered( $plan, $said_rows );
		}
		return $plan;
	}

	/**
	 * The fish a row becomes: what the owner picked — a fish of the
	 * lists, the text as typed (an unlisted fish), or an unknown fish
	 * ("??") — else what the matcher is sure of, else the text as typed.
	 * A weak or ambiguous match is never taken.
	 *
	 * @param object $store    Store.
	 * @param array  $row      The row (name, typed, match).
	 * @param array  $said     The owner's choices for the row (pick).
	 * @param array  $settings Settings.
	 * @return array name, unknown, species_id, product_id, sci_name, kind (fish|typed|unknown),
	 *               corrected (whether the owner picked another fish than the matcher's).
	 */
	public static function fish_of_row( $store, array $row, array $said, array $settings ) {
		$match   = $row['match'];
		$default = null !== $match['pick'] && isset( $match['candidates'][ $match['pick'] ] ) ? $match['candidates'][ $match['pick'] ] : null;
		$kind    = $default ? 'fish' : ( 'unknown' === $match['kind'] ? 'unknown' : 'typed' );
		$picked  = $default;
		$hand    = false;

		$pick = isset( $said['pick'] ) && is_array( $said['pick'] ) ? $said['pick'] : null;
		if ( $pick && isset( $pick['kind'] ) && in_array( $pick['kind'], array( 'fish', 'typed', 'unknown' ), true ) ) {
			if ( 'fish' === $pick['kind'] ) {
				$name = isset( $pick['name'] ) && is_scalar( $pick['name'] ) ? Order_Import::cut( Order_Import::cell( $pick['name'] ), Identify::MAX_NAME ) : '';
				if ( '' !== $name ) {
					$picked = array(
						'name'       => $name,
						'product_id' => isset( $pick['product_id'] ) && is_numeric( $pick['product_id'] ) && (int) $pick['product_id'] > 0 ? (int) $pick['product_id'] : null,
					);
					$hand   = ! $default || ! Identify::same_fish( $default['name'], $name );
					$kind   = 'fish';
				}
			} else {
				$hand = $kind !== $pick['kind'];
				$kind = $pick['kind'];
			}
		}

		if ( 'unknown' === $kind ) {
			return array(
				'name'       => Identify::unknown_name( $row['typed'] ),
				'unknown'    => true,
				'species_id' => null,
				'product_id' => null,
				'sci_name'   => '',
				'kind'       => 'unknown',
				'corrected'  => false,
			);
		}
		$fish = Identify::fish_from(
			$store,
			'fish' === $kind && $picked ? array(
				'species'    => $picked['name'],
				'product_id' => (int) $picked['product_id'],
			) : array( 'species' => Order_Import::cut( $row['name'], Identify::MAX_NAME ) ),
			$settings
		);
		if ( ! empty( $fish['error'] ) ) {
			return array(
				'name'       => Identify::unknown_name( $row['typed'] ),
				'unknown'    => true,
				'species_id' => null,
				'product_id' => null,
				'sci_name'   => '',
				'kind'       => 'unknown',
				'corrected'  => false,
			);
		}
		return array(
			'name'       => (string) $fish['name'],
			'unknown'    => ! empty( $fish['unknown'] ),
			'species_id' => $fish['species_id'],
			'product_id' => $fish['product_id'],
			'sci_name'   => (string) $fish['sci_name'],
			'kind'       => ! empty( $fish['unknown'] ) ? 'unknown' : $kind,
			'corrected'  => $hand && 'fish' === $kind,
		);
	}

	/**
	 * The ordered mode: every row is a new line. A row that names a fish
	 * (and size) already on the order — or the same supplier's code — is
	 * a duplicate: skipped unless the owner says "add anyway".
	 *
	 * @param array $plan The plan so far.
	 * @param array $said The owner's choices per row.
	 * @return array
	 */
	private static function plan_ordered( array $plan, array $said ) {
		$lines = 0;
		$fish  = 0;
		foreach ( $plan['rows'] as $i => $row ) {
			$dup = null;
			foreach ( $plan['lines'] as $line ) {
				$code = Order_Import::code_of( $line['notes'] );
				if ( ( '' !== $row['code'] && '' !== $code && Order_Import::code_key( $code ) === Order_Import::code_key( $row['code'] ) ) || ( (string) ( $row['size']['slug'] ?? '' ) === (string) ( $line['size_slug'] ?? '' ) && Order_Import::same_fish( $row, $line ) ) ) {
					$dup = $line;
					break;
				}
			}
			$choice = isset( $said[ $row['n'] ]['dup'] ) && 'add' === $said[ $row['n'] ]['dup'] ? 'add' : 'skip';

			$plan['rows'][ $i ]['dup']        = $dup ? self::line_text( $dup ) : '';
			$plan['rows'][ $i ]['dup_choice'] = $dup ? $choice : '';
			$plan['rows'][ $i ]['take']       = $row['on'] && ( ! $dup || 'add' === $choice );
			if ( $plan['rows'][ $i ]['take'] ) {
				$lines++;
				$fish += (int) $row['ordered'];
			}
		}
		$plan['new_lines'] = $lines;
		/* translators: 1: lines, 2: fish */
		$plan['foot'] = sprintf( _n( '%1$s line · %2$s fish', '%1$s lines · %2$s fish', $lines, 'fishotel-misc-plugin' ), number_format( $lines ), number_format( $fish ) );
		return $plan;
	}

	/**
	 * The arrived mode: the reconciliation, the fees and the sum check.
	 *
	 * @param array $plan The plan so far.
	 * @param array $in   The import payload (rows[n][pair], lines, same_fish).
	 * @return array
	 */
	private static function plan_arrived( array $plan, array $in ) {
		$pairs = array();
		$lines = array();
		foreach ( isset( $in['rows'] ) && is_array( $in['rows'] ) ? $in['rows'] : array() as $n => $said ) {
			if ( is_array( $said ) && isset( $said['pair'] ) && is_string( $said['pair'] ) && '' !== $said['pair'] ) {
				$pairs[ (int) $n ] = $said['pair'];
				if ( 0 === strpos( $said['pair'], 'instead:' ) ) {
					$lines[ substr( $said['pair'], 8 ) ] = 'zero'; // What came instead: the ordered line stays at 0.
				}
			}
		}
		foreach ( isset( $in['lines'] ) && is_array( $in['lines'] ) ? $in['lines'] : array() as $key => $what ) {
			if ( in_array( $what, array( 'zero', 'keep' ), true ) && ! isset( $lines[ (string) $key ] ) ) {
				$lines[ (string) $key ] = $what;
			}
		}
		$plan['reconcile'] = Order_Import::reconcile(
			$plan['rows'],
			$plan['lines'],
			array(
				'pairs'     => $pairs,
				'lines'     => $lines,
				'same_fish' => isset( $in['same_fish'] ) && 'separate' === $in['same_fish'] ? 'separate' : 'sum',
			)
		);
		$plan['new_lines'] = count( $plan['reconcile']['extra'] );
		$plan['foot']      = Order_Import::foot_text( $plan['reconcile']['foot'] );
		$plan['fees']      = Order_Import::fee_sums( $plan['analysis']['fees'], $plan['sections'] );

		// The rows add up to … — the invoice says ….
		$sum = 0.0;
		foreach ( $plan['rows'] as $row ) {
			$sum += null !== $row['price'] ? (float) $row['price'] * (int) $row['arrived'] : ( null !== $row['amount'] ? (float) $row['amount'] : 0.0 );
		}
		$plan['sum'] = round( $sum, 2 );
		return $plan;
	}

	/**
	 * A line in a few words: "Blue Test Wrasse · Large".
	 *
	 * @param array $line species, size_label.
	 * @return string
	 */
	public static function line_text( array $line ) {
		$size = isset( $line['size_label'] ) && '' !== (string) $line['size_label'] ? (string) $line['size_label'] : (string) ( $line['size_slug'] ?? '' );
		return (string) $line['species'] . ( '' !== $size ? ' · ' . $size : '' );
	}

	/* ============================================================== */
	/*  The preview, as the page reads it                              */
	/* ============================================================== */

	/**
	 * The plan as the page's JS draws it: plain data and ready-made
	 * sentences. Every text is escaped by the page (it is set as text,
	 * never as HTML).
	 *
	 * @param array $plan plan().
	 * @param array $read read_input().
	 * @return array
	 */
	public static function for_page( array $plan, array $read ) {
		$grid    = $read['grid'];
		$columns = $plan['columns'];
		$header  = $plan['header'];
		$mode    = $plan['mode'];
		$a       = $plan['analysis'];

		// The dropdowns' part: the header row's texts and the first rows under it.
		$sample = array();
		foreach ( $grid as $n => $row ) {
			if ( null !== $columns['row'] && $n <= $columns['row'] ) {
				continue;
			}
			$sample[] = $row;
			if ( count( $sample ) >= self::SAMPLE_ROWS ) {
				break;
			}
		}

		$positional = $columns['layout'] && empty( $columns['layout']['header'] );

		$layout = array(
			'line'     => '',
			'applied'  => $columns['layout'] ? $columns['layout']['id'] : '',
			'partial'  => '',
			'offer'    => null,
			'remember' => '',
			'supplier' => $header['supplier'],
		);
		if ( $columns['layout'] ) {
			/* translators: %s: supplier (and origin) */
			$layout['line'] = sprintf( __( 'Using the layout saved for %s', 'fishotel-misc-plugin' ), Import_Layouts::owner_text( $columns['layout'] ) );
			if ( $positional ) {
				// A sheet without headings is recognised by its supplier and its width alone: the columns
				// may have moved, and nothing in the file would say so.
				$layout['line'] .= ' — ' . __( 'this sheet has no headings, so the columns go by their position: check them', 'fishotel-misc-plugin' );
			}
			if ( $columns['partial'] ) {
				$parts = array();
				if ( $columns['partial']['missing'] ) {
					/* translators: %s: column headings */
					$parts[] = sprintf( __( 'no longer on the sheet: %s', 'fishotel-misc-plugin' ), implode( ', ', $columns['partial']['missing'] ) );
				}
				if ( $columns['partial']['extra'] ) {
					/* translators: %s: column headings */
					$parts[] = sprintf( __( 'new on the sheet: %s', 'fishotel-misc-plugin' ), implode( ', ', $columns['partial']['extra'] ) );
				}
				/* translators: %s: what changed */
				$layout['partial'] = sprintf( __( 'The sheet changed since the layout was saved (%s). It was applied to the columns that still fit.', 'fishotel-misc-plugin' ), implode( '; ', $parts ) );
			}
		} elseif ( $columns['offer'] ) {
			$layout['offer'] = array(
				'id'       => $columns['offer']['id'],
				'supplier' => $columns['offer']['supplier'],
				'origin'   => $columns['offer']['origin'],
				/* translators: %s: supplier */
				'text'     => sprintf( __( 'This looks like %s\'s sheet. Use that layout?', 'fishotel-misc-plugin' ), $columns['offer']['supplier'] ),
				/* translators: %s: supplier */
				'set'      => '' === $header['supplier'] ? sprintf( __( 'and set this order\'s supplier to %s', 'fishotel-misc-plugin' ), $columns['offer']['supplier'] ) : '',
			);
		}
		// The owner set the columns (or accepted a guess): offer to remember them. A layout that
		// fitted only partly is offered again as an update.
		$by_hand = self::worth_remembering( $plan );
		if ( $by_hand && $plan['complete'] && '' !== $header['supplier'] ) {
			$layout['remember'] = $columns['partial']
				/* translators: %s: supplier */
				? sprintf( __( 'Update the layout saved for %s', 'fishotel-misc-plugin' ), $header['supplier'] )
				/* translators: %s: supplier */
				: sprintf( __( 'Remember this layout for %s', 'fishotel-misc-plugin' ), $header['supplier'] );
		}

		$left = array();
		foreach ( array_slice( $a['left_out'], 0, self::LEFT_OUT_SHOWN ) as $item ) {
			$left[] = $item['text'] . ' — ' . Order_Import::reason_text( $item['reason'] );
		}
		$left_count = count( $a['left_out'] ) + $plan['other'];
		if ( $plan['other'] > 0 ) {
			/* translators: %d: rows */
			$left[] = sprintf( _n( '%d row of another section', '%d rows of other sections', $plan['other'], 'fishotel-misc-plugin' ), $plan['other'] );
		}

		$summary = '';
		if ( $plan['complete'] && 'ordered' === $mode ) {
			/* translators: 1: rows with an order quantity, 2: rows read */
			$summary = sprintf( __( '%1$s of %2$s rows have an order quantity', 'fishotel-misc-plugin' ), number_format( $a['with_qty'] ), number_format( $a['data_rows'] ) );
		} elseif ( $plan['complete'] ) {
			/* translators: %s: rows */
			$summary = sprintf( __( '%s rows of fish read', 'fishotel-misc-plugin' ), number_format( $a['with_qty'] ) );
		}

		$notes = array();
		if ( $read['truncated'] ) {
			/* translators: %s: rows */
			$notes[] = sprintf( __( 'Only the first %s rows were read.', 'fishotel-misc-plugin' ), number_format( Order_Import::MAX_ROWS ) );
		}
		if ( $plan['capped'] > 0 ) {
			/* translators: 1: rows left out, 2: the most lines an order holds */
			$notes[] = sprintf( __( '%1$d more rows are not shown: an order holds at most %2$d lines.', 'fishotel-misc-plugin' ), $plan['capped'], Orders::MAX_LINES );
		}
		$room = Orders::MAX_LINES - count( $plan['lines'] );
		$over = $plan['new_lines'] > $room;
		if ( $over ) {
			/* translators: 1: new lines, 2: lines on the order, 3: the most lines an order holds */
			$notes[] = sprintf( __( 'These %1$d new lines do not fit: the order has %2$d and holds at most %3$d. Untick some rows.', 'fishotel-misc-plugin' ), $plan['new_lines'], count( $plan['lines'] ), Orders::MAX_LINES );
		}
		if ( ! $plan['complete'] && Order_Import::run_together( $grid ) ) {
			$notes[] = __( 'The columns of this text ran together: the tabs between them were lost on the way, so nothing can be read from it safely. Ask for the block again inside a code block (or as comma-separated text), copy it from there and paste it here.', 'fishotel-misc-plugin' );
		} elseif ( ! $plan['complete'] ) {
			$notes[] = __( 'Say which column is the fish and which one is the quantity: nothing can be imported before that.', 'fishotel-misc-plugin' );
		}

		// A sheet of received counts read as "what I ORDERED" has no order quantity anywhere: say so,
		// and offer the other button.
		$switch = null;
		if ( 'ordered' === $mode && $plan['complete'] && 0 === (int) $a['with_qty'] && (int) $a['received'] > 0 ) {
			$switch = array(
				'mode'   => 'arrived',
				/* translators: %s: rows */
				'text'   => sprintf( _n( 'No row has an order quantity, but %s has a received count: this looks like what ARRIVED.', 'No row has an order quantity, but %s have a received count: this looks like what ARRIVED.', (int) $a['received'], 'fishotel-misc-plugin' ), number_format( (int) $a['received'] ) ),
				'button' => __( 'Read it as what ARRIVED', 'fishotel-misc-plugin' ),
			);
		}
		if ( $by_hand && $plan['complete'] && '' === $header['supplier'] ) {
			$notes[] = __( 'Type the order\'s supplier above and these columns can be remembered for the next sheet from them.', 'fishotel-misc-plugin' );
		}

		$rows = array();
		foreach ( $plan['rows'] as $row ) {
			$rows[] = self::row_for_page( $row, $mode );
		}

		$out = array(
			'token'       => $plan['token'],
			'mode'        => $mode,
			'default'     => $plan['fitting'],
			'switch'      => $switch,
			'grid'        => $read['fresh'] ? $grid : null,
			'truncated'   => (bool) $read['truncated'],
			'sheet'       => $read['sheet'],
			'sheets'      => $read['sheets'],
			'columns'     => array(
				'row'      => $columns['row'],
				'map'      => (object) $columns['map'],
				'source'   => $columns['source'],
				'complete' => $plan['complete'],
				'headers'  => null !== $columns['row'] && isset( $grid[ $columns['row'] ] ) ? $grid[ $columns['row'] ] : array(),
				'sample'   => $sample,
				'width'    => $grid ? count( $grid[0] ) : 0,
				'open'     => ! $plan['complete'] || 'guess' === $columns['source'] || $positional,
			),
			'layout'      => $layout,
			'summary'     => $summary,
			'left_out'    => array(
				'count' => $left_count,
				/* translators: %d: rows */
				'text'  => $left_count > 0 ? sprintf( _n( '%d row left out', '%d rows left out', $left_count, 'fishotel-misc-plugin' ), $left_count ) : '',
				'items' => $left,
			),
			'rows'        => $rows,
			'foot'        => $plan['complete'] ? $plan['foot'] : '',
			'too'         => $plan['too'],
			'notes'       => $notes,
			'can_confirm' => $plan['complete'] && ! $over && ( $plan['new_lines'] > 0 || ( 'arrived' === $mode && ! empty( $plan['reconcile']['lines'] ) ) ),
			'sizes'       => array_values( $plan['sizes'] ),
		);

		if ( 'arrived' === $mode && $plan['complete'] ) {
			$out['arrived'] = self::arrived_for_page( $plan );
		}
		return $out;
	}

	/**
	 * One row as the page draws it.
	 *
	 * @param array  $row  A planned row.
	 * @param string $mode ordered|arrived.
	 * @return array
	 */
	private static function row_for_page( array $row, $mode ) {
		$match = $row['match'];
		$words = array(
			'exact'     => __( 'matched', 'fishotel-misc-plugin' ),
			'alias'     => __( 'matched (a learned name)', 'fishotel-misc-plugin' ),
			'close'     => __( 'matched', 'fishotel-misc-plugin' ),
			'sci'       => __( 'matched by its scientific name', 'fishotel-misc-plugin' ),
			'weak'      => __( 'not sure — pick the fish', 'fishotel-misc-plugin' ),
			'ambiguous' => __( 'could be several — pick the fish', 'fishotel-misc-plugin' ),
			'none'      => __( 'not in your lists — used as typed', 'fishotel-misc-plugin' ),
			'unknown'   => __( 'no name — an unknown fish', 'fishotel-misc-plugin' ),
		);
		$size = $row['size'];
		return array(
			'n'          => $row['n'],
			'typed'      => $row['typed'],
			'name'       => $row['fish']['name'],
			'kind'       => $row['fish']['kind'],
			'product_id' => $row['fish']['product_id'],
			'match'      => array(
				'kind'       => $match['kind'],
				'flag'       => (bool) $match['flag'] && ! $row['picked'],
				'text'       => isset( $words[ $match['kind'] ] ) ? $words[ $match['kind'] ] : '',
				'candidates' => $match['candidates'],
			),
			'size'       => array(
				'slug'    => $size['slug'],
				'label'   => $size['label'],
				'written' => $size['written'],
				/* translators: %s: a size as the sheet writes it */
				'note'    => null === $size['slug'] && '' !== $size['written'] ? sprintf( __( 'size "%s" is not in your list of sizes — kept in the name', 'fishotel-misc-plugin' ), $size['written'] ) : '',
			),
			'sci'        => $row['sci'],
			'code'       => $row['code'],
			'ordered'    => 'arrived' === $mode ? null : $row['ordered'],
			'received'   => 'arrived' === $mode ? $row['arrived'] : $row['received'],
			'price'      => $row['price'],
			'doa'        => $row['doa'],
			'per_box'    => $row['per_box'],
			'box'        => $row['box'],
			'pct'        => $row['pct'],
			'origin'     => $row['origin'],
			'label'      => $row['label'],
			'flags'      => array_values( array_unique( $row['flags'] ) ),
			'check'      => (bool) $row['check'],
			'fee_word'   => ! empty( $row['fee_word'] ),
			'on'         => (bool) $row['on'],
			'junk'       => (bool) $row['junk'],
			'dup'        => isset( $row['dup'] ) ? $row['dup'] : '',
			'dup_choice' => isset( $row['dup_choice'] ) ? $row['dup_choice'] : '',
		);
	}

	/**
	 * The arrived preview: the four groups, the sections, the fees.
	 *
	 * @param array $plan The plan.
	 * @return array
	 */
	private static function arrived_for_page( array $plan ) {
		$r      = $plan['reconcile'];
		$by_key = array();
		foreach ( $plan['lines'] as $line ) {
			$by_key[ $line['key'] ] = $line;
		}
		$row_of = array();
		foreach ( $plan['rows'] as $row ) {
			$row_of[ $row['n'] ] = $row;
		}
		$paired = array(); // row n → line key.

		$entry = static function ( array $e ) use ( $by_key, $row_of, &$paired, $plan ) {
			$line  = $by_key[ $e['line'] ];
			$price = null;
			foreach ( $e['rows'] as $n ) {
				$paired[ $n ] = $e['line'];
				if ( null === $price && null !== $row_of[ $n ]['price'] ) {
					$price = (float) $row_of[ $n ]['price'];
				}
			}
			$notes = array();
			if ( 'code' === $e['how'] ) {
				$notes[] = __( 'paired by the supplier\'s code', 'fishotel-misc-plugin' );
			} elseif ( 'hand' === $e['how'] ) {
				$notes[] = __( 'paired by you', 'fishotel-misc-plugin' );
			}
			if ( count( $e['rows'] ) > 1 ) {
				/* translators: %d: invoice rows */
				$notes[] = sprintf( __( '%d rows added up', 'fishotel-misc-plugin' ), count( $e['rows'] ) );
			}
			if ( ! empty( $e['boxes'] ) ) {
				/* translators: %s: box numbers */
				$notes[] = sprintf( _n( 'box %s', 'boxes %s', count( $e['boxes'] ), 'fishotel-misc-plugin' ), implode( ', ', $e['boxes'] ) );
			}
			if ( 'fish' === $e['how'] && ! empty( $e['clash'] ) ) {
				$notes[] = __( 'paired by its name — the code on the invoice row is another line\'s: check it', 'fishotel-misc-plugin' );
			}
			// A price list's 0.2291666 and the invoice's 0.23 are one price: compared to the cent.
			$differs = null !== $price && null !== $line['unit_price'] && round( (float) $line['unit_price'], 2 ) !== round( $price, 2 );
			if ( $differs ) {
				/* translators: 1: the price on the order's line, 2: the file's price */
				$notes[] = sprintf( __( 'price on the order %1$s · the file says %2$s', 'fishotel-misc-plugin' ), Costing::money( $line['unit_price'] ), Costing::money( $price ) );
			}
			return array(
				'line'    => $e['line'],
				'text'    => self::line_text( $line ),
				'rows'    => array_values( $e['rows'] ),
				/* translators: 1: ordered, 2: arrived */
				'detail'  => sprintf( __( 'ordered %1$d · arrived %2$d', 'fishotel-misc-plugin' ), (int) $e['ordered'], (int) ( $e['arrived'] ?? $e['was'] ) ),
				'over'    => isset( $e['arrived'] ) && (int) $e['arrived'] > (int) $e['ordered'],
				'notes'   => $notes,
				'keep'    => ! empty( $e['keep'] ),
				'was'     => $e['was'],
				'choice'  => ! empty( $e['keep'] ) ? 'keep' : 'zero',
				'differs' => $differs,
			);
		};

		$groups = array();
		$titles = array(
			'same'    => __( 'Came as ordered', 'fishotel-misc-plugin' ),
			'diff'    => __( 'Came short / over', 'fishotel-misc-plugin' ),
			'missing' => __( 'Did not come', 'fishotel-misc-plugin' ),
			'extra'   => __( 'Extra / substituted', 'fishotel-misc-plugin' ),
			'stay'    => __( 'Already checked in — left as they are', 'fishotel-misc-plugin' ),
		);
		$hints  = array(
			'missing' => __( 'Received becomes 0 and the line stays on the order, so the fill rate and the supplier\'s record are true. A line that already holds a received count is left as it is unless you say otherwise.', 'fishotel-misc-plugin' ),
			'extra'   => __( 'Nothing on the order matches these rows. Each becomes a new line with ordered 0 — or say which ordered line it came instead of: that line stays at 0 received and both are noted.', 'fishotel-misc-plugin' ),
			'stay'    => __( 'These lines already became stays: an import never changes them. Correct their counts on the stay.', 'fishotel-misc-plugin' ),
		);
		$differs = false;
		foreach ( array( 'same', 'diff', 'missing', 'stay' ) as $key ) {
			$entries = array();
			foreach ( $r['groups'][ $key ] as $e ) {
				$one       = $entry( $e );
				$differs   = $differs || $one['differs'];
				$entries[] = $one;
			}
			$groups[] = array(
				'key'     => $key,
				'title'   => $titles[ $key ],
				'hint'    => isset( $hints[ $key ] ) ? $hints[ $key ] : '',
				'entries' => $entries,
			);
		}
		$extras = array();
		foreach ( $r['groups']['extra'] as $e ) {
			$could = array();
			foreach ( $e['could'] as $key ) {
				if ( isset( $by_key[ $key ] ) ) {
					$could[] = self::line_text( $by_key[ $key ] );
				}
			}
			$extras[] = array(
				'row'     => $e['row'],
				'arrived' => $e['arrived'],
				'instead' => $e['instead'],
				/* translators: %s: order lines */
				'note'    => $could ? sprintf( __( 'Could be %s — the row has no size. Say which line it is.', 'fishotel-misc-plugin' ), implode( ' / ', $could ) ) : '',
			);
		}
		array_splice(
			$groups,
			3,
			0,
			array(
				array(
					'key'     => 'extra',
					'title'   => $titles['extra'],
					'hint'    => $hints['extra'],
					'entries' => $extras,
				),
			)
		);

		$options = array();
		foreach ( $plan['lines'] as $line ) {
			if ( empty( $line['stay_id'] ) ) {
				$options[] = array(
					'key'  => $line['key'],
					'text' => self::line_text( $line ) . ' — ' . sprintf( /* translators: %d: ordered */ __( 'ordered %d', 'fishotel-misc-plugin' ), (int) $line['qty_ordered'] ),
				);
			}
		}

		$sections = array();
		foreach ( $plan['analysis']['sections'] as $name => $count ) {
			$sections[] = array(
				'name'  => (string) $name,
				'count' => (int) $count,
				'on'    => null === $plan['sections'] || in_array( (string) $name, $plan['sections'], true ),
			);
		}

		$fees = $plan['fees'];
		$fee  = array(
			'count' => $fees['count'],
			'text'  => '',
			'notes' => array(),
			'items' => array(),
		);
		if ( $fees['count'] > 0 ) {
			/* translators: 1: tariffs, 2: every other fee */
			$fee['text'] = sprintf( __( 'Fees on this invoice: tariffs %1$s · everything else %2$s', 'fishotel-misc-plugin' ), Costing::money( $fees['tariffs'] ), Costing::money( $fees['import'] ) );
			foreach ( $plan['analysis']['fees'] as $item ) {
				$fee['items'][] = $item['name'] . ( '' !== $item['origin'] ? ' (' . $item['origin'] . ')' : '' ) . ': ' . ( null !== $item['amount'] ? Costing::money( $item['amount'] ) : '?' );
			}
			if ( $fees['check'] > 0 ) {
				/* translators: %d: fee lines */
				$fee['notes'][] = sprintf( _n( '%d fee has no readable amount and is not counted — check the invoice.', '%d fees have no readable amount and are not counted — check the invoice.', $fees['check'], 'fishotel-misc-plugin' ), $fees['check'] );
			}
			if ( $fees['left_out'] > 0 ) {
				/* translators: %d: fee lines */
				$fee['notes'][] = sprintf( _n( '%d fee of another section is left out.', '%d fees of other sections are left out.', $fees['left_out'], 'fishotel-misc-plugin' ), $fees['left_out'] );
			}
			$header = $plan['header'];
			if ( $header['tariffs'] > 0 || $header['import_fees'] > 0 ) {
				/* translators: 1: tariffs typed on the order, 2: import fees typed on the order */
				$fee['notes'][] = sprintf( __( 'The order already says: tariffs %1$s · import & re-ox fees %2$s. A typed amount is kept unless you tick "replace".', 'fishotel-misc-plugin' ), Costing::money( $header['tariffs'] ), Costing::money( $header['import_fees'] ) );
			}
			$fee['typed'] = $header['tariffs'] > 0 || $header['import_fees'] > 0;
		}

		$sum = '';
		if ( null !== $plan['analysis']['subtotal'] && null === $plan['sections'] ) {
			$said = round( (float) $plan['analysis']['subtotal'], 2 );
			/* translators: 1: the rows' sum, 2: the invoice's subtotal */
			$sum = sprintf( __( 'The rows add up to %1$s — the invoice says %2$s.', 'fishotel-misc-plugin' ), Costing::money( $plan['sum'] ), Costing::money( $said ) );
			$sum .= ' ' . ( abs( $plan['sum'] - $said ) < 0.015 ? __( 'They agree.', 'fishotel-misc-plugin' ) : __( 'They do NOT agree: a row is missing, or a quantity or a price was misread.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'groups'    => $groups,
			'lines'     => $options,
			'sections'  => $sections,
			'fees'      => $fee,
			'sum'       => $sum,
			'sum_ok'    => '' === $sum || null === $plan['analysis']['subtotal'] || abs( $plan['sum'] - round( (float) $plan['analysis']['subtotal'], 2 ) ) < 0.015,
			'same_fish' => isset( $plan['said']['same_fish'] ) && 'separate' === $plan['said']['same_fish'] ? 'separate' : 'sum',
			'paired'    => (object) $paired,
			'differs'   => $differs,
		);
	}

	/* ============================================================== */
	/*  Confirm                                                        */
	/* ============================================================== */

	/**
	 * A new line's row, as the order form would post it.
	 *
	 * @param array  $row      A planned row.
	 * @param int    $ordered  Ordered count.
	 * @param mixed  $received Received count (null = left empty).
	 * @param string $notes    Notes to start from.
	 * @return array
	 */
	private static function new_row( array $row, $ordered, $received, $notes = '' ) {
		$fish  = $row['fish'];
		// The shop price is READ (Shop_Price), never stamped on the line: only a "worth" column of the
		// file's own — the owner's sheet has one — is a typed worth.
		$worth = $row['worth'];
		if ( '' !== $row['code'] ) {
			$notes = Order_Import::note_with( $notes, Order_Import::NOTE_CODE, $row['code'] );
		}
		// The name as the sheet writes it stays on the line whenever the line reads differently.
		if ( '' !== $row['typed'] && ! Identify::same_fish( $row['typed'], $fish['name'] ) && ! $fish['unknown'] ) {
			$notes = Order_Import::note_with( $notes, Order_Import::NOTE_WRITTEN, $row['typed'] );
		} elseif ( null === $row['size']['slug'] && '' !== $row['size']['written'] && ! $row['size']['lifted'] && false === stripos( $row['typed'], $row['size']['written'] ) ) {
			$notes = Order_Import::note_with( $notes, Order_Import::NOTE_SIZE, $row['size']['written'] );
		}
		return array(
			'id'           => 0,
			'species_id'   => ! empty( $fish['species_id'] ) ? (int) $fish['species_id'] : '',
			'species'      => $fish['name'],
			'sci_name'     => '' !== $row['sci'] ? $row['sci'] : (string) $fish['sci_name'],
			'needs_id'     => $fish['unknown'] ? '1' : '',
			'size_slug'    => null !== $row['size']['slug'] ? $row['size']['slug'] : '',
			'size_label'   => null !== $row['size']['label'] ? $row['size']['label'] : '',
			'qty_ordered'  => (string) max( 0, (int) $ordered ),
			'qty_received' => null === $received ? '' : (string) max( 0, (int) $received ),
			'per_box'      => null !== $row['per_box'] ? (string) $row['per_box'] : '',
			'box_no'       => null !== $row['box'] ? (string) $row['box'] : '',
			'box_pct'      => null !== $row['pct'] ? (string) $row['pct'] : '',
			'bags'         => null !== $row['bags'] ? (string) $row['bags'] : '',
			'unit_price'   => null !== $row['price'] ? (string) $row['price'] : '',
			'worth'        => null !== $worth ? (string) $worth : '',
			'qty_doa'      => null !== $row['doa'] ? (string) $row['doa'] : '0',
			'qty_survived' => null !== $row['survived'] ? (string) $row['survived'] : '',
			'tank_id'      => null !== $row['tank_id'] ? (string) $row['tank_id'] : '',
			'notes'        => $notes,
			'code'         => (string) $row['code'], // The page's own code field (More columns) says the same.
		);
	}

	/**
	 * Confirm: the page's own Save with the import applied to the posted
	 * form — rows appended; in the arrived mode the received counts (and
	 * the price, DOA, box and percent a line does not have yet) written on
	 * the paired lines, and the fees when ticked. Afterwards: what the
	 * label said, the aliases the owner taught by correcting a match, and
	 * the layout.
	 *
	 * @param object $store Store.
	 * @param array  $raw   The request (the order form).
	 * @param array  $in    The import payload.
	 * @param array  $plan  plan().
	 * @param int    $id    Order id (0 = new).
	 * @param array  $ctx   today.
	 * @return array Admin_Workbook::save_order()'s result + imported, flash — or error.
	 */
	public static function confirm( $store, array $raw, array $in, array $plan, $id, array $ctx ) {
		if ( ! $plan['complete'] ) {
			return array( 'error' => __( 'Say which column is the fish and which one is the quantity first.', 'fishotel-misc-plugin' ) );
		}
		if ( $plan['new_lines'] > Orders::MAX_LINES - count( $plan['lines'] ) ) {
			/* translators: %d: the most lines an order holds */
			return array( 'error' => sprintf( __( 'An order holds at most %d lines: untick some rows. Nothing was imported.', 'fishotel-misc-plugin' ), Orders::MAX_LINES ) );
		}
		$today = isset( $ctx['today'] ) ? (string) $ctx['today'] : '';
		$form  = isset( $raw['lines'] ) && is_array( $raw['lines'] ) ? $raw['lines'] : array();
		$added = array(); // The appended rows, in order: the planned row + the label to keep.
		$stays = 0;
		$wrote = 0;

		if ( 'arrived' === $plan['mode'] ) {
			$r      = $plan['reconcile'];
			$by_key = array();
			foreach ( $plan['lines'] as $line ) {
				$by_key[ $line['key'] ] = $line;
			}
			$row_of = array();
			foreach ( $plan['rows'] as $row ) {
				$row_of[ $row['n'] ] = $row;
			}
			$use_price = ! empty( $in['use_file_price'] );

			foreach ( $r['lines'] as $key => $e ) {
				if ( ! isset( $form[ $key ], $by_key[ $key ] ) || ! is_array( $form[ $key ] ) || ! empty( $by_key[ $key ]['stay_id'] ) ) {
					continue; // Never a line that has a stay.
				}
				$line  = $by_key[ $key ];
				$notes = (string) $line['notes'];

				$form[ $key ]['qty_received'] = (string) (int) $e['arrived'];
				$wrote++;

				$price = null;
				$doa   = 0;
				$pct   = 0.0;
				$code  = '';
				foreach ( $e['rows'] as $n ) {
					$row   = $row_of[ $n ];
					$price = null === $price && null !== $row['price'] ? (float) $row['price'] : $price;
					$doa  += (int) $row['doa'];
					$pct  += (float) $row['pct'];
					$code  = '' === $code ? (string) $row['code'] : $code;
				}
				// A typed value is never overwritten — the price only with the tick.
				if ( null !== $price && ( null === $line['unit_price'] || $use_price ) ) {
					$form[ $key ]['unit_price'] = (string) $price;
				}
				if ( $doa > 0 && (int) $line['qty_doa'] < 1 ) {
					$form[ $key ]['qty_doa'] = (string) min( $doa, (int) $e['arrived'] );
				}
				if ( ! empty( $e['boxes'] ) && 1 === count( $e['boxes'] ) && null === $line['box_no'] ) {
					$form[ $key ]['box_no'] = (string) $e['boxes'][0];
				}
				if ( $pct > 0 && null === $line['box_pct'] && null !== Costing::box_pct_in( $pct ) ) {
					$form[ $key ]['box_pct'] = (string) $pct;
				}
				if ( ! empty( $e['boxes'] ) && count( $e['boxes'] ) > 1 ) {
					$notes = Order_Import::note_with( $notes, Order_Import::NOTE_BOXES, implode( ', ', $e['boxes'] ) );
				}
				if ( '' !== $code && '' === Order_Import::code_of( $notes ) ) {
					$notes = Order_Import::note_with( $notes, Order_Import::NOTE_CODE, $code );
					// The page posts its own (empty) code field with the line: it says the same now, or the
					// save would take the code out of the notes again.
					$form[ $key ]['code'] = $code;
				}
				if ( $notes !== (string) $line['notes'] ) {
					$form[ $key ]['notes'] = $notes;
				}
			}
			foreach ( $r['groups']['stay'] as $e ) {
				$stays++;
			}
			foreach ( $r['groups']['extra'] as $e ) {
				$row   = $row_of[ $e['row'] ];
				$notes = '';
				if ( '' !== $e['instead'] && isset( $by_key[ $e['instead'] ], $form[ $e['instead'] ] ) ) {
					$other = $by_key[ $e['instead'] ];
					$notes = Order_Import::note_with( '', Order_Import::NOTE_INSTEAD, self::line_text( $other ) );

					$form[ $e['instead'] ]['notes'] = Order_Import::note_with( isset( $form[ $e['instead'] ]['notes'] ) && '' !== (string) $form[ $e['instead'] ]['notes'] ? (string) $form[ $e['instead'] ]['notes'] : (string) $other['notes'], Order_Import::NOTE_SUBBED, $row['fish']['name'] );
				}
				$form[]  = self::new_row( $row, 0, $e['arrived'], $notes );
				$added[] = $row;
			}

			// The fees: an empty field takes the invoice's amount; a typed one only on the second tick.
			$fees = $plan['fees'];
			foreach ( array( 'tariffs' => 'tariffs', 'import_fees' => 'import' ) as $field => $kind ) {
				$value = Order_Import::fee_value( $raw[ $field ] ?? 0, $fees[ $kind ], ! empty( $in['fees'] ), ! empty( $in['fees_replace'] ) );
				if ( null !== $value ) {
					$raw[ $field ] = (string) $value;
				}
			}
		} else {
			foreach ( $plan['rows'] as $row ) {
				if ( empty( $row['take'] ) ) {
					continue;
				}
				$form[]  = self::new_row( $row, (int) $row['ordered'], $row['received'] );
				$added[] = $row;
			}
			// "This is what I ORDERED": the order is ordered now (a draft no longer).
			if ( ! $plan['too'] && 'draft' === $plan['header']['status'] && $added ) {
				$raw['status'] = 'ordered';
			}
		}

		if ( ! $added && 0 === $wrote ) {
			return array( 'error' => __( 'Nothing is ticked: there is nothing to import.', 'fishotel-misc-plugin' ) );
		}

		$raw['lines'] = $form;
		$result       = Admin_Workbook::save_order( $store, $raw, $id, $today );
		if ( ! empty( $result['error'] ) ) {
			return array( 'error' => (string) $result['error'] );
		}

		// The appended rows are the last lines of the order, in order.
		$ids     = array_slice( (array) $result['line_ids'], -count( $added ) );
		$learned = 0;
		foreach ( $added ? $added : array() as $i => $row ) {
			$line_id = isset( $ids[ $i ] ) ? (int) $ids[ $i ] : 0;
			$fish    = $row['fish'];
			$label   = self::label_of( $row );
			if ( '' !== $label && $line_id > 0 ) {
				$store->update_line_fields( $line_id, array( 'label_name' => $label ) );
			}
			// The owner corrected the match: the name as the supplier writes it is learned, as the phone learns one.
			$alias = Species::key( Order_Import::match_text( $row['typed'] ) );
			if ( $fish['corrected'] && '' !== $alias && ! Identify::same_fish( $alias, $fish['name'] ) && method_exists( $store, 'add_alias' ) && $store->add_alias( $alias, $fish['product_id'], $fish['name'] ) ) {
				$learned++;
			}
		}

		$saved = self::keep_layout( $in, $plan, $today );

		if ( 'arrived' === $plan['mode'] ) {
			$message = $plan['foot'] . '.';
			if ( $added ) {
				/* translators: %d: lines */
				$message .= ' ' . sprintf( _n( '%d extra line added.', '%d extra lines added.', count( $added ), 'fishotel-misc-plugin' ), count( $added ) );
			}
			if ( $stays > 0 ) {
				/* translators: %d: lines */
				$message .= ' ' . sprintf( _n( '%d line is already checked in and was left as it is.', '%d lines are already checked in and were left as they are.', $stays, 'fishotel-misc-plugin' ), $stays );
			}
			$message .= ' ' . __( 'Nothing was checked in.', 'fishotel-misc-plugin' );
		} else {
			/* translators: %d: lines */
			$message = sprintf( _n( '%d line imported.', '%d lines imported.', count( $added ), 'fishotel-misc-plugin' ), count( $added ) );
		}
		if ( $learned > 0 ) {
			/* translators: %d: names */
			$message .= ' ' . sprintf( _n( '%d name learned for next time.', '%d names learned for next time.', $learned, 'fishotel-misc-plugin' ), $learned );
		}
		if ( '' !== $saved ) {
			$message .= ' ' . $saved;
		}
		if ( ! empty( $result['warnings'] ) ) {
			/* translators: %s: what was not saved */
			$message .= ' ' . sprintf( __( 'Not saved: %s.', 'fishotel-misc-plugin' ), implode( '; ', (array) $result['warnings'] ) );
		}

		$result['imported'] = count( $added );
		$result['updated']  = $wrote;
		$result['skipped']  = $stays;
		$result['learned']  = $learned;
		$result['message']  = $message;
		$result['flash']    = $message;
		// Back to the order, in Entry: that is where the lines that were just written are typed on.
		$result['redirect'] = add_query_arg( array( 'view' => 'entry' ), '' !== (string) $result['redirect'] ? $result['redirect'] : Admin_Workbook::order_url( (int) $result['order_id'] ) );
		return $result;
	}

	/**
	 * What the label said, for a new line (the label_name rule): the
	 * file's own "label" column when it names another fish than the line;
	 * else — for fish that arrived — the name as written when it IS a fish
	 * the lists know and the owner said the fish is another one. A name
	 * the lists do not know that the owner matched by hand is an alias,
	 * not a mislabel: it is learned, and stays in the notes.
	 *
	 * @param array $row A planned row.
	 * @return string '' = none.
	 */
	public static function label_of( array $row ) {
		$fish = $row['fish'];
		if ( $fish['unknown'] ) {
			return '';
		}
		if ( '' !== $row['label'] && ! Identify::same_fish( $row['label'], $fish['name'] ) ) {
			return Order_Import::cut( $row['label'], Identify::MAX_NAME );
		}
		$arrived = (int) ( 'arrived' === ( $row['mode'] ?? '' ) ? $row['arrived'] : $row['received'] ) > 0;
		if ( $fish['corrected'] && $arrived && in_array( $row['match']['kind'], array( 'exact', 'alias' ), true ) ) {
			return Order_Import::cut( $row['name'], Identify::MAX_NAME );
		}
		return '';
	}

	/**
	 * Whether the columns of a plan are worth remembering for the order's
	 * supplier: the owner set or corrected them by hand (or accepted a
	 * guess for a sheet without headings), the saved layout fitted only
	 * partly, or it is another supplier's layout the owner said to use.
	 *
	 * @param array $plan The plan.
	 * @return bool
	 */
	public static function worth_remembering( array $plan ) {
		$columns = $plan['columns'];
		if ( in_array( $columns['source'], array( 'hand', 'guess' ), true ) || $columns['partial'] ) {
			return true;
		}
		return (bool) $columns['layout'] && Species::key( $columns['layout']['supplier'] ) !== Species::key( $plan['header']['supplier'] );
	}

	/**
	 * After a confirm: save the layout the owner set (the ticked box),
	 * or note that the applied one was used — with the rows the owner
	 * said to leave out every time.
	 *
	 * @param array  $in    The import payload (remember).
	 * @param array  $plan  The plan.
	 * @param string $today Site date.
	 * @return string What to tell the owner ('' = nothing).
	 */
	public static function keep_layout( array $in, array $plan, $today ) {
		$junk = array();
		foreach ( $plan['rows'] as $row ) {
			if ( ! empty( $row['junk'] ) && '' !== $row['typed'] ) {
				$junk[] = $row['typed'];
			}
		}
		$columns = $plan['columns'];
		$header  = $plan['header'];
		$by_hand = self::worth_remembering( $plan );

		if ( $by_hand && ! empty( $in['remember'] ) && '' !== $header['supplier'] ) {
			$layout = Import_Layouts::build(
				array(
					'supplier'   => $header['supplier'],
					'origin'     => $header['origin'],
					'moment'     => $plan['mode'],
					'grid'       => $plan['grid'],
					'header_row' => $columns['row'],
					'map'        => $columns['map'],
					'sheet'      => $plan['sheet'],
					'junk'       => array_merge( $junk, $columns['junk'] ),
					'today'      => $today,
				)
			);
			if ( $layout ) {
				Import_Layouts::save( $layout );
				/* translators: %s: supplier */
				return sprintf( __( 'The layout is remembered for %s.', 'fishotel-misc-plugin' ), $header['supplier'] );
			}
			return '';
		}
		$used = $columns['layout'] ? $columns['layout'] : $plan['own'];
		if ( $used && ( $columns['layout'] || $junk ) ) {
			Import_Layouts::touch( $used['id'], $today, $junk );
		}
		return '';
	}
}
