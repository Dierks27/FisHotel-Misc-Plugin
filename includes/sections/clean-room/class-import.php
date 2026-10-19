<?php
/**
 * Clean Room import — the workbook's mapped data (Import_Map::map()) into
 * the Store: species upserted by lower-cased name (enriched from the stock
 * list), box rates merged into the option, orders created by ref with
 * their lines (the landed totals cached through the Costing), the recent
 * batches checked in as stays through the same path as the Arrivals
 * button, and the treatment logs as dose / copper / note logs on the Spa
 * building the sheet's "Tanks" column names.
 *
 * Pure apart from the store it is handed (the real Store or the tests'
 * in-memory one) and the box-rates option, exactly as Orders::check_in()
 * is; preview() is pure outright.
 *
 * Sale dates: the workbook carries none, so an imported order has no
 * "Goes on sale" date and the groups checked in from it are not for sale
 * until one is chosen on the order (Arrivals) or per group on the product
 * page; the preview and the result count those groups and name the
 * orders. Nothing is ever computed for them.
 *
 * Idempotent: a second run of the same file updates the species (never
 * blanking a value the site has with one the sheet lacks), merges the
 * same box rates, skips every order whose ref exists, skips every line
 * that already has a stay (Orders::check_in), and skips every log whose
 * import marker ("import:<ref>#<row>") is already on that building.
 *
 * PRIVACY: nothing in this file knows a species, a price or a batch; the
 * data only ever enters the site through the file Jeff uploads.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Import
 */
class Import {

	/**
	 * The marker an imported log carries at the end of its note, followed
	 * by "<ref>#<row>" — how a second run recognises it.
	 *
	 * @var string
	 */
	const MARKER = 'import:';

	/**
	 * Batches received within this many days of today are checked in as
	 * stays (0 = none).
	 *
	 * @var int
	 */
	const DEFAULT_STAYS_DAYS = 45;

	/**
	 * A system of at least this many gallons stands for "the big system"
	 * when no building has exactly that volume.
	 *
	 * @var int
	 */
	const BIG_SYSTEM_GALLONS = 150;

	/**
	 * The bytes of a tank-log note the Store keeps (Store::insert_log()).
	 *
	 * @var int
	 */
	const NOTE_BYTES = 2000;

	/* ============================================================== */
	/*  Markers                                                        */
	/* ============================================================== */

	/**
	 * "import:2026-12#7" — the marker of one log row of one batch.
	 *
	 * @param string $ref Order ref.
	 * @param int    $row The log row's number in the sheet's log (from 1).
	 * @return string
	 */
	public static function marker( $ref, $row ) {
		return self::MARKER . trim( (string) $ref ) . '#' . (int) $row;
	}

	/**
	 * A note without its import marker (" · import:2026-12#7" and any
	 * separator before it); a note without one comes back unchanged.
	 *
	 * @param string $note The log note.
	 * @return string
	 */
	public static function strip_marker( $note ) {
		$note = (string) $note;
		if ( false === strpos( $note, self::MARKER ) ) {
			return $note;
		}
		$note = preg_replace( '/\s*(?:·\s*)?' . preg_quote( self::MARKER, '/' ) . '\S*/u', '', $note );
		return trim( (string) $note, " \t\n\r·" );
	}

	/**
	 * The marker in a note, or '' when it has none.
	 *
	 * @param string $note The log note.
	 * @return string
	 */
	public static function marker_of( $note ) {
		return 1 === preg_match( '/' . preg_quote( self::MARKER, '/' ) . '[^\s#]+#\d+/', (string) $note, $m ) ? $m[0] : '';
	}

	/* ============================================================== */
	/*  Preview                                                        */
	/* ============================================================== */

	/**
	 * What a run would do (pure): per entity the rows it would create,
	 * update or skip, given what the site already has.
	 *
	 * @param array $map      Import_Map::map().
	 * @param array $existing species (names), order_refs (refs), box_rates (rows or "supplier|origin" keys),
	 *                        dated_refs (refs of the site's orders that already have a sale date).
	 * @param array $options  Optional today (Y-m-d) and stays_days to count the lines that would become stays.
	 * @return array species{create,update,skip}, orders{create,skip,lines,logs}, box_rates{create,update,skip},
	 *               stock (int), stays{orders,lines}, sale_dates{groups,refs} (the would-be groups in tanks
	 *               without a sale date, and their orders), warnings[].
	 */
	public static function preview( array $map, array $existing, array $options = array() ) {
		$out = array(
			'species'   => array(
				'create' => 0,
				'update' => 0,
				'skip'   => 0,
			),
			'orders'    => array(
				'create' => 0,
				'skip'   => 0,
				'lines'  => 0,
				'logs'   => 0,
			),
			'box_rates' => array(
				'create' => 0,
				'update' => 0,
				'skip'   => 0,
			),
			'stock'     => count( (array) ( $map['stock'] ?? array() ) ),
			'stays'      => array(
				'orders' => 0,
				'lines'  => 0,
			),
			'sale_dates' => array(
				'groups' => 0,
				'refs'   => array(),
			),
			'warnings'   => array_values( array_map( 'strval', (array) ( $map['warnings'] ?? array() ) ) ),
		);

		$dated = array();
		foreach ( (array) ( $existing['dated_refs'] ?? array() ) as $ref ) {
			$dated[ trim( (string) $ref ) ] = true;
		}

		$names = array();
		foreach ( (array) ( $existing['species'] ?? array() ) as $name ) {
			$names[ Species::key( is_array( $name ) ? (string) ( $name['name'] ?? '' ) : (string) $name ) ] = true;
		}
		$seen = array();
		foreach ( (array) ( $map['species'] ?? array() ) as $species ) {
			$key = Species::key( (string) ( $species['name'] ?? '' ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$out['species']['skip']++;
				continue;
			}
			$seen[ $key ] = true;
			$out['species'][ isset( $names[ $key ] ) ? 'update' : 'create' ]++;
		}

		$refs = array();
		foreach ( (array) ( $existing['order_refs'] ?? array() ) as $ref ) {
			$refs[ trim( (string) $ref ) ] = true;
		}
		$today = isset( $options['today'] ) && Replay::is_date( $options['today'] ) ? (string) $options['today'] : '';
		$days  = isset( $options['stays_days'] ) ? max( 0, (int) $options['stays_days'] ) : self::DEFAULT_STAYS_DAYS;
		foreach ( (array) ( $map['orders'] ?? array() ) as $order ) {
			$ref = trim( (string) ( $order['ref'] ?? '' ) );
			if ( '' === $ref || isset( $refs[ $ref ] ) ) {
				$out['orders']['skip']++;
			} else {
				$out['orders']['create']++;
				$out['orders']['lines'] += count( (array) ( $order['lines'] ?? array() ) );
				$out['orders']['logs']  += count( (array) ( $order['log'] ?? array() ) );
			}
			if ( '' !== $today && $days > 0 && self::within_days( (string) ( $order['received_date'] ?? '' ), $today, $days ) ) {
				$lines = 0;
				foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
					if ( (int) ( $line['qty_received'] ?? 0 ) > 0 ) {
						$lines++;
					}
				}
				if ( $lines > 0 ) {
					$out['stays']['orders']++;
					$out['stays']['lines'] += $lines;
					if ( '' !== $ref && ! isset( $dated[ $ref ] ) && empty( $order['sale_date'] ) ) {
						$out['sale_dates']['groups'] += $lines;
						$out['sale_dates']['refs'][]  = $ref;
					}
				}
			}
		}

		$keys = array();
		foreach ( (array) ( $existing['box_rates'] ?? array() ) as $rate ) {
			$keys[ is_array( $rate ) ? self::rate_key( $rate['supplier'] ?? '', $rate['origin'] ?? '' ) : strtolower( trim( (string) $rate ) ) ] = true;
		}
		foreach ( self::usable_rates( (array) ( $map['box_rates'] ?? array() ) ) as $rate ) {
			if ( null === $rate ) {
				$out['box_rates']['skip']++;
				continue;
			}
			$out['box_rates'][ isset( $keys[ self::rate_key( $rate['supplier'], $rate['origin'] ) ] ) ? 'update' : 'create' ]++;
		}

		return $out;
	}

	/* ============================================================== */
	/*  Run                                                            */
	/* ============================================================== */

	/**
	 * Import the mapped workbook into the store.
	 *
	 * @param array  $map     Import_Map::map().
	 * @param object $store   Store or the in-memory stand-in.
	 * @param array  $options today (Y-m-d), settings, actor_id, stays_days (default 45; 0 = none),
	 *                        stays_building_id (the Spa building the recent batches land in),
	 *                        dose_logs (bool: the QT logs → tank logs).
	 * @return array species{created,updated,skipped}, box_rates{rows}, orders{created,skipped,lines},
	 *               stays{created,skipped,orders}, logs{created,skipped},
	 *               sale_dates{missing,refs} (the groups of the checked-in orders still in tanks
	 *               without a sale date, and those orders' refs), warnings[], errors[].
	 */
	public static function run( array $map, $store, array $options = array() ) {
		$today    = isset( $options['today'] ) && Replay::is_date( $options['today'] ) ? (string) $options['today'] : Clean_Room::today();
		$settings = isset( $options['settings'] ) && is_array( $options['settings'] ) ? $options['settings'] : Settings::get();
		$actor    = (int) ( $options['actor_id'] ?? 0 );
		$days     = array_key_exists( 'stays_days', $options ) ? max( 0, (int) $options['stays_days'] ) : self::DEFAULT_STAYS_DAYS;
		$spa      = self::spa_buildings( $store );
		$spa_ids  = array_map( 'intval', array_column( $spa, 'id' ) );
		$building = isset( $options['stays_building_id'] ) && in_array( (int) $options['stays_building_id'], $spa_ids, true ) ? (int) $options['stays_building_id'] : null;
		$logs_on  = ! empty( $options['dose_logs'] );

		$result = array(
			'species'   => array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
			),
			'box_rates' => array(
				'rows' => 0,
			),
			'orders'    => array(
				'created' => 0,
				'skipped' => 0,
				'lines'   => 0,
			),
			'stays'     => array(
				'created' => 0,
				'skipped' => 0,
				'orders'  => 0,
			),
			'logs'       => array(
				'created' => 0,
				'skipped' => 0,
			),
			'sale_dates' => array(
				'missing' => 0,
				'refs'    => array(),
			),
			'warnings'   => array_values( array_map( 'strval', (array) ( $map['warnings'] ?? array() ) ) ),
			'errors'     => array(),
		);

		$species_ids = self::import_species( $map, $store, $result );
		self::import_box_rates( $map, $result );

		foreach ( (array) ( $map['orders'] ?? array() ) as $order ) {
			$ref = trim( (string) ( $order['ref'] ?? '' ) );
			if ( '' === $ref ) {
				$result['orders']['skipped']++;
				continue;
			}
			$recent   = $days > 0 && self::within_days( (string) ( $order['received_date'] ?? '' ), $today, $days );
			$existing = $store->order_by_ref( $ref );
			if ( $existing ) {
				$order_id = (int) $existing['id'];
				$result['orders']['skipped']++;
			} else {
				$order_id = self::create_order( $order, $store, $species_ids, $building, $recent, $result );
				if ( ! $order_id ) {
					continue;
				}
			}

			if ( $recent ) {
				$outcome = Orders::check_in(
					$store,
					$store->order( $order_id ),
					$today,
					$settings,
					array(
						'actor_id' => $actor,
						'source'   => 'import',
					)
				);
				$result['stays']['orders']++;
				$result['stays']['created'] += count( (array) $outcome['created'] );
				$result['stays']['skipped'] += (int) $outcome['skipped'];
				foreach ( (array) $outcome['errors'] as $error ) {
					$result['errors'][] = $ref . ': ' . (string) $error;
				}

				// The groups of this batch in tanks without a sale date (the
				// workbook carries none): counted so the result can say where
				// to set one — never computed here.
				$missing = 0;
				foreach ( $store->open_stays( array( 'order_id' => $order_id ), $today, $settings ) as $stay ) {
					if ( empty( $stay['sale_ready_date'] ) ) {
						$missing++;
					}
				}
				if ( $missing > 0 ) {
					$result['sale_dates']['missing'] += $missing;
					$result['sale_dates']['refs'][]   = $ref;
				}
			}

			if ( $logs_on ) {
				self::import_logs( $order, $store, $spa, $existing ? ( $existing['building_id'] ?: null ) : $building, $building, $result );
			}
		}

		return $result;
	}

	/* ============================================================== */
	/*  Species                                                        */
	/* ============================================================== */

	/**
	 * Upsert the species by lower-cased name: the sheet's values fill the
	 * row, a value the sheet lacks never blanks one the site has, the
	 * group / reef-safe / max size come from the stock list (by scientific
	 * name, then by common name) where the row has none.
	 *
	 * @param array  $map    Map.
	 * @param object $store  Store.
	 * @param array  $result Counts (by reference).
	 * @return array<string, int> Species::key(name) => id, for the lines.
	 */
	private static function import_species( array $map, $store, array &$result ) {
		$stock_by_sci  = array();
		$stock_by_name = array();
		foreach ( (array) ( $map['stock'] ?? array() ) as $row ) {
			$sci  = Species::key( (string) ( $row['sci_name'] ?? '' ) );
			$name = Species::key( (string) ( $row['common_name'] ?? '' ) );
			if ( '' !== $sci && ! isset( $stock_by_sci[ $sci ] ) ) {
				$stock_by_sci[ $sci ] = $row;
			}
			if ( '' !== $name && ! isset( $stock_by_name[ $name ] ) ) {
				$stock_by_name[ $name ] = $row;
			}
		}

		$ids = array();
		foreach ( $store->species_brief() as $row ) {
			$ids[ Species::key( (string) $row['name'] ) ] = (int) $row['id'];
		}

		$seen = array();
		foreach ( (array) ( $map['species'] ?? array() ) as $species ) {
			$name = trim( preg_replace( '/\s+/', ' ', (string) ( $species['name'] ?? '' ) ) );
			$key  = Species::key( $name );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$result['species']['skipped']++;
				continue;
			}
			$seen[ $key ] = true;

			$sci   = trim( (string) ( $species['sci_name'] ?? '' ) );
			$stock = null;
			if ( '' !== $sci && isset( $stock_by_sci[ Species::key( $sci ) ] ) ) {
				$stock = $stock_by_sci[ Species::key( $sci ) ];
			} elseif ( isset( $stock_by_name[ $key ] ) ) {
				$stock = $stock_by_name[ $key ];
			}

			// A per box of 0 (or junk) is a value the sheet lacks: Species::normalize()
			// would turn it into the default 24 over the site's own figure.
			$per_box = isset( $species['per_box'] ) && is_numeric( $species['per_box'] ) && (int) $species['per_box'] > 0 ? (int) $species['per_box'] : null;
			$fields  = array(
				'name'         => $name,
				'sci_name'     => $sci,
				'per_box'      => $per_box,
				'my_price'     => $species['my_price'] ?? null,
				'online_price' => $species['online_price'] ?? null,
				'online_site'  => $species['online_site'] ?? null,
				'online_date'  => $species['online_date'] ?? null,
			);
			$enrich = array(
				'group_name' => $stock ? (string) ( $stock['group'] ?? '' ) : '',
				'reef_safe'  => $stock ? (string) ( $stock['reef_safe'] ?? '' ) : '',
				'max_size'   => $stock ? (string) ( $stock['max_size'] ?? '' ) : '',
			);

			if ( isset( $ids[ $key ] ) ) {
				$current = $store->species( $ids[ $key ] );
				if ( ! $current ) {
					$result['species']['skipped']++;
					continue;
				}
				$merged = $current;
				foreach ( $fields as $field => $value ) {
					if ( 'name' === $field ) {
						continue; // The site's spelling of the name stays.
					}
					if ( null !== $value && '' !== $value ) {
						$merged[ $field ] = $value;
					}
				}
				foreach ( $enrich as $field => $value ) {
					if ( '' !== $value && '' === trim( (string) ( $merged[ $field ] ?? '' ) ) ) {
						$merged[ $field ] = $value;
					}
				}
				$saved = $store->save_species( Species::normalize( $merged ) );
				if ( $saved ) {
					$result['species']['updated']++;
				} else {
					$result['species']['skipped']++;
					$result['errors'][] = sprintf( 'Species "%s" could not be updated.', $name );
				}
				continue;
			}

			$saved = $store->save_species( Species::normalize( array_merge( $fields, $enrich ) ) );
			if ( $saved ) {
				$ids[ $key ] = (int) $saved;
				$result['species']['created']++;
			} else {
				$result['species']['skipped']++;
				$result['errors'][] = sprintf( 'Species "%s" could not be created.', $name );
			}
		}

		return $ids;
	}

	/* ============================================================== */
	/*  Box rates                                                      */
	/* ============================================================== */

	/**
	 * The mapped rates the option can take (supplier and a numeric per box),
	 * null in place of the others.
	 *
	 * @param array[] $rates Map box_rates.
	 * @return array[] Rows of supplier, origin, per_box, reox — or null.
	 */
	public static function usable_rates( array $rates ) {
		$out = array();
		foreach ( $rates as $rate ) {
			$supplier = trim( (string) ( $rate['supplier'] ?? '' ) );
			$per_box  = isset( $rate['per_box'] ) && is_numeric( $rate['per_box'] ) ? (float) $rate['per_box'] : null;
			if ( '' === $supplier || null === $per_box ) {
				$out[] = null;
				continue;
			}
			$out[] = array(
				'supplier' => $supplier,
				'origin'   => trim( (string) ( $rate['origin'] ?? '' ) ),
				'per_box'  => $per_box,
				'reox'     => isset( $rate['reox'] ) && is_numeric( $rate['reox'] ) ? (float) $rate['reox'] : 0.0,
			);
		}
		return $out;
	}

	/**
	 * "supplier|origin", lower-cased — the key two rates are the same by.
	 *
	 * @param string $supplier Supplier.
	 * @param string $origin   Origin.
	 * @return string
	 */
	public static function rate_key( $supplier, $origin ) {
		return strtolower( trim( (string) $supplier ) ) . '|' . strtolower( trim( (string) $origin ) );
	}

	/**
	 * Merge the sheet's rates into the option: the site's rows first, the
	 * sheet's after, so the same supplier + origin takes the sheet's numbers
	 * (Settings::sanitize_box_rates keeps the last of a pair).
	 *
	 * @param array $map    Map.
	 * @param array $result Counts (by reference).
	 */
	private static function import_box_rates( array $map, array &$result ) {
		$rows = Settings::box_rates();
		foreach ( self::usable_rates( (array) ( $map['box_rates'] ?? array() ) ) as $rate ) {
			if ( null !== $rate ) {
				$rows[] = $rate;
			}
		}
		$result['box_rates']['rows'] = count( Settings::store_box_rates( $rows ) );
	}

	/* ============================================================== */
	/*  Orders                                                         */
	/* ============================================================== */

	/**
	 * Create one order with its lines; the landed totals are cached through
	 * the Costing.
	 *
	 * @param array    $order       Mapped order.
	 * @param object   $store       Store.
	 * @param array    $species_ids Species::key(name) => id.
	 * @param int|null $building_id The Spa building the order lands in.
	 * @param bool     $recent      Whether the batch is within the stays cutoff.
	 * @param array    $result      Counts (by reference).
	 * @return int|false The order id.
	 */
	private static function create_order( array $order, $store, array $species_ids, $building_id, $recent, array &$result ) {
		$ref      = trim( (string) $order['ref'] );
		$received = isset( $order['received_date'] ) && Replay::is_date( $order['received_date'] ) ? (string) $order['received_date'] : null;
		$header   = Orders::normalize_order(
			array(
				'ref'             => $ref,
				'supplier'        => (string) ( $order['supplier'] ?? '' ),
				'origin'          => (string) ( $order['origin'] ?? '' ),
				'received_date'   => $received,
				'status'          => null === $received ? 'ordered' : ( $recent ? 'received' : 'closed' ),
				'cost_mode'       => (string) ( $order['cost_mode'] ?? 'box' ),
				'tariffs'         => $order['tariffs'] ?? 0,
				'domestic_flight' => $order['domestic_flight'] ?? 0,
				'import_fees'     => $order['import_fees'] ?? 0,
				'credit'          => $order['credit'] ?? 0,
				'schedule'        => (string) ( $order['schedule'] ?? '' ),
				'building_id'     => $building_id,
				/* translators: %s: sheet name */
				'notes'           => sprintf( 'Imported from the workbook sheet "%s".', (string) ( $order['sheet'] ?? '' ) ),
			)
		);

		$order_id = $store->save_order( $header );
		if ( ! $order_id ) {
			$result['errors'][] = sprintf( 'Order %s could not be created.', $ref );
			return false;
		}

		$lines = array();
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			$name = trim( preg_replace( '/\s+/', ' ', (string) ( $line['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue;
			}
			$key     = Species::key( $name );
			$lines[] = array(
				'species'      => $name,
				'species_id'   => isset( $species_ids[ $key ] ) ? $species_ids[ $key ] : null,
				'sci_name'     => (string) ( $line['sci_name'] ?? '' ),
				'qty_ordered'  => $line['qty_ordered'] ?? 0,
				'qty_received' => $line['qty_received'] ?? null,
				'per_box'      => $line['per_box'] ?? null,
				'bags'         => $line['bags'] ?? null,
				'unit_price'   => $line['unit_price'] ?? null,
				'worth'        => $line['worth'] ?? null,
				'qty_doa'      => $line['qty_doa'] ?? 0,
				'qty_survived' => $line['qty_survived'] ?? null,
			);
		}
		$saved = $store->save_order_lines( $order_id, Orders::normalize_lines( $lines ) );
		if ( false === $saved ) {
			$result['errors'][] = sprintf( 'The lines of order %s could not be saved.', $ref );
		} else {
			$result['orders']['lines'] += count( $saved['ids'] );
		}
		$result['orders']['created']++;

		$resolved = Orders::resolve( $store->order( $order_id ), array() );
		foreach ( (array) ( $resolved['lines'] ?? array() ) as $line ) {
			$store->cache_line_total( (int) $line['id'], Costing::cents( $line['landed_total'] ?? null ) );
		}

		return (int) $order_id;
	}

	/* ============================================================== */
	/*  Logs                                                           */
	/* ============================================================== */

	/**
	 * The active Spa buildings with their effective system gallons and
	 * tank counts.
	 *
	 * @param object $store Store.
	 * @return array[] id, number, gallons, tanks.
	 */
	public static function spa_buildings( $store ) {
		$tanks = $store->tanks( false );
		$out   = array();
		foreach ( $store->buildings() as $building ) {
			if ( 'spa' !== (string) $building['area'] || 'offline' === (string) $building['status'] ) {
				continue;
			}
			$count = 0;
			foreach ( $tanks as $tank ) {
				if ( (int) $tank['building_id'] === (int) $building['id'] ) {
					$count++;
				}
			}
			$out[] = array(
				'id'      => (int) $building['id'],
				'number'  => (int) $building['number'],
				'gallons' => Layout::system_gallons( $building, $tanks ),
				'tanks'   => $count,
			);
		}
		return $out;
	}

	/**
	 * The building(s) a log row's "Tanks" value names (pure): a number is
	 * the Spa building with that system volume — else the one with the
	 * most tanks for a big system (205), else the other one (100); "Both"
	 * is one log per Spa building (those two); blank is the order's
	 * building, else the chosen one, else the big system.
	 *
	 * @param mixed    $system      205, 100, "Both", null …
	 * @param array[]  $spa         spa_buildings().
	 * @param int|null $order_id    The order's building id.
	 * @param int|null $fallback_id The building chosen on the import form.
	 * @return int[] Building ids.
	 */
	public static function buildings_for_system( $system, array $spa, $order_id, $fallback_id ) {
		if ( ! $spa ) {
			return array();
		}
		$ids = array_map( 'intval', array_column( $spa, 'id' ) );

		$by_gallons = static function ( $gallons ) use ( $spa ) {
			foreach ( $spa as $building ) {
				if ( (int) $building['gallons'] === (int) $gallons ) {
					return (int) $building['id'];
				}
			}
			return null;
		};
		$most = $spa[0];
		foreach ( $spa as $building ) {
			if ( (int) $building['tanks'] > (int) $most['tanks'] ) {
				$most = $building;
			}
		}
		$big   = (int) $most['id'];
		$other = $big;
		foreach ( $spa as $building ) {
			if ( (int) $building['id'] !== $big ) {
				$other = (int) $building['id'];
				break;
			}
		}

		if ( is_numeric( $system ) ) {
			$exact = $by_gallons( $system );
			if ( null !== $exact ) {
				return array( $exact );
			}
			return array( (float) $system >= self::BIG_SYSTEM_GALLONS ? $big : $other );
		}
		$text = strtolower( trim( (string) $system ) );
		if ( 'both' === $text || 'all' === $text ) {
			$first  = $by_gallons( 205 );
			$second = $by_gallons( 100 );
			return array_values( array_unique( array( null !== $first ? $first : $big, null !== $second ? $second : $other ) ) );
		}
		if ( (int) $order_id > 0 && in_array( (int) $order_id, $ids, true ) ) {
			return array( (int) $order_id );
		}
		if ( (int) $fallback_id > 0 && in_array( (int) $fallback_id, $ids, true ) ) {
			return array( (int) $fallback_id );
		}
		return array( $big );
	}

	/**
	 * One sheet log row as a tank log (pure): "Dosed X" / "Added X" → a
	 * dose (value = the numeric part of the amount, note "<Medication>
	 * <amount>"), "Tested Copper" → a copper reading, anything else
	 * ("Lost Fish", "Notes", "Complete!") → a note with the text. The
	 * result column is appended, then the marker. Null without a date.
	 *
	 * @param array  $row process, amount, system, date, time, result.
	 * @param string $ref The order ref.
	 * @param int    $n   The row's number in the log (from 1).
	 * @return array{kind: string, value: float|null, note: string, logged_at: string}|null logged_at = "Y-m-d H:i" site time.
	 */
	public static function log_entry( array $row, $ref, $n ) {
		$date = isset( $row['date'] ) && Replay::is_date( $row['date'] ) ? (string) $row['date'] : '';
		if ( '' === $date ) {
			return null;
		}
		$time = isset( $row['time'] ) && 1 === preg_match( '/^\d{2}:\d{2}$/', (string) $row['time'] ) ? (string) $row['time'] : '12:00';

		$process = trim( preg_replace( '/\s+/', ' ', (string) ( $row['process'] ?? '' ) ) );
		$amount  = isset( $row['amount'] ) ? $row['amount'] : null;
		$text    = self::amount_text( $amount );
		$number  = self::amount_number( $amount );
		$outcome = trim( (string) ( $row['result'] ?? '' ) );
		$marker  = self::marker( $ref, $n );

		if ( 1 === preg_match( '/^(?:dosed|added)\s+(.+)$/i', $process, $m ) ) {
			$key   = Protocol::medication_key( $m[1] );
			$label = null !== $key ? Protocol::medication_label( $key ) : ucfirst( trim( $m[1] ) );
			$note  = trim( $label . ( '' !== $text ? ' ' . $text : '' ) );
			$kind  = 'dose';
			$value = $number;
		} elseif ( 1 === preg_match( '/^(?:tested|test)\s+copper|^copper\s+test/i', $process ) ) {
			$note  = '';
			$kind  = 'copper';
			$value = $number;
		} else {
			$note  = trim( $process . ( '' !== $text ? ' ' . $text : '' ) );
			$kind  = 'note';
			$value = null;
		}
		if ( '' !== $outcome ) {
			$note = '' !== $note ? $note . ' — ' . $outcome : $outcome;
		}
		// The Store keeps NOTE_BYTES of a note: cut the text, never the marker
		// at its end (without it a second run would write the row again).
		$note = self::cut_bytes( $note, self::NOTE_BYTES - strlen( ' · ' . $marker ) );
		$note = '' !== $note ? $note . ' · ' . $marker : $marker;

		return array(
			'kind'      => $kind,
			'value'     => $value,
			'note'      => $note,
			'logged_at' => $date . ' ' . $time,
		);
	}

	/**
	 * The import logs of one batch onto the Spa building(s) the sheet
	 * names, skipping every row whose marker that building already has.
	 *
	 * @param array    $order       Mapped order (ref, log).
	 * @param object   $store       Store.
	 * @param array[]  $spa         spa_buildings().
	 * @param int|null $order_bid   The order's building id.
	 * @param int|null $fallback_id The building chosen on the form.
	 * @param array    $result      Counts (by reference).
	 */
	private static function import_logs( array $order, $store, array $spa, $order_bid, $fallback_id, array &$result ) {
		$ref = trim( (string) $order['ref'] );
		$log = (array) ( $order['log'] ?? array() );
		if ( ! $log ) {
			return;
		}
		if ( ! $spa ) {
			$result['warnings'][] = sprintf( '%s: no active Spa building to put the treatment log on.', $ref );
			return;
		}
		$done = array();
		foreach ( (array) $store->imported_log_keys( $ref ) as $key ) {
			$done[ (string) $key ] = true;
		}

		foreach ( array_values( $log ) as $i => $row ) {
			$entry = self::log_entry( (array) $row, $ref, $i + 1 );
			if ( null === $entry ) {
				$result['logs']['skipped']++;
				$result['warnings'][] = sprintf( '%s: log row %d ("%s") has no date and was not imported.', $ref, $i + 1, (string) ( $row['process'] ?? '' ) );
				continue;
			}
			$marker = self::marker( $ref, $i + 1 );
			$utc    = self::site_to_utc( $entry['logged_at'] );
			foreach ( self::buildings_for_system( $row['system'] ?? null, $spa, $order_bid, $fallback_id ) as $building_id ) {
				$key = 'b' . $building_id . '|t0|' . $marker;
				if ( isset( $done[ $key ] ) ) {
					$result['logs']['skipped']++;
					continue;
				}
				$id = $store->log_building(
					$building_id,
					array(
						'kind'      => $entry['kind'],
						'value'     => $entry['value'],
						'note'      => $entry['note'],
						'logged_at' => $utc,
						'actor_id'  => 0,
						'source'    => 'import',
					)
				);
				if ( $id ) {
					$done[ $key ] = true;
					$result['logs']['created']++;
				} else {
					$result['logs']['skipped']++;
				}
			}
		}
	}

	/* ============================================================== */
	/*  Small helpers                                                  */
	/* ============================================================== */

	/**
	 * Whether a date is within the last N days of today (today included,
	 * the future too).
	 *
	 * @param string $date  Y-m-d.
	 * @param string $today Y-m-d.
	 * @param int    $days  N.
	 * @return bool
	 */
	public static function within_days( $date, $today, $days ) {
		if ( ! Replay::is_date( $date ) || ! Replay::is_date( $today ) ) {
			return false;
		}
		return strcmp( $date, Replay::add_days( $today, -(int) $days ) ) >= 0;
	}

	/**
	 * A sheet amount as text: a number without trailing zeros, text as
	 * typed, '' for nothing.
	 *
	 * @param mixed $amount Cell value.
	 * @return string
	 */
	public static function amount_text( $amount ) {
		if ( null === $amount || is_bool( $amount ) ) {
			return '';
		}
		if ( is_int( $amount ) || is_float( $amount ) ) {
			return Costing::num( $amount, '', 3 );
		}
		return trim( preg_replace( '/\s+/', ' ', (string) $amount ) );
	}

	/**
	 * The numeric part of a sheet amount: the number itself, or the first
	 * number in the text ("5.125 G" → 5.125, "8 & 20" → 8); null without one.
	 *
	 * @param mixed $amount Cell value.
	 * @return float|null
	 */
	public static function amount_number( $amount ) {
		if ( is_int( $amount ) || is_float( $amount ) ) {
			return (float) $amount;
		}
		if ( null === $amount || is_bool( $amount ) ) {
			return null;
		}
		return 1 === preg_match( '/-?\d+(?:\.\d+)?/', str_replace( ',', '', (string) $amount ), $m ) ? (float) $m[0] : null;
	}

	/**
	 * At most $max bytes of a text, an "…" marking the cut, never half a
	 * UTF-8 character.
	 *
	 * @param string $text Text.
	 * @param int    $max  Bytes.
	 * @return string
	 */
	public static function cut_bytes( $text, $max ) {
		$text = (string) $text;
		$max  = max( 0, (int) $max );
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, max( 0, $max - strlen( '…' ) ) );
		for ( $i = 0; $i < 3 && '' !== $cut && 1 !== preg_match( '//u', $cut ); $i++ ) {
			$cut = substr( $cut, 0, -1 );
		}
		return rtrim( $cut ) . '…';
	}

	/**
	 * "Y-m-d H:i" site time → "Y-m-d H:i:s" UTC.
	 *
	 * @param string $local Site time.
	 * @return string
	 */
	public static function site_to_utc( $local ) {
		try {
			$dt = new \DateTimeImmutable( (string) $local . ':00', Clean_Room::timezone() );
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
	}
}
