<?php
/**
 * Med listings — the word lists and the plain lines the cleanup uses.
 *
 * Every list is a setting the owner can change on the page (one word or
 * phrase per line). A line written between slashes is a pattern
 * (`/\b\d+\s*days?\b/`), anything else is a plain word or phrase matched
 * as a whole word, capitals ignored, a space and a hyphen counting as
 * the same thing ("broad spectrum" = "broad-spectrum").
 *
 * Pure: reads the option through get_option() only, so the tests can
 * load it with nothing but a stand-in for that one function.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Words
 */
class Words {

	/**
	 * Option that holds the owner's own lists (only what differs is kept:
	 * a list left as it came is read from the defaults below).
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_med_listings_words';

	/**
	 * The lists that are words to look for, in the order the page shows them.
	 *
	 * @var string[]
	 */
	const LISTS = array( 'drugs', 'diseases', 'claims', 'dosing', 'labels', 'links', 'water' );

	/**
	 * The plain lines (one setting each).
	 *
	 * @var string[]
	 */
	const LINES = array( 'storage', 'supplier', 'own_brands', 'leave_out' );

	/**
	 * The kinds of word a product page must not carry.
	 *
	 * @var string[]
	 */
	const CLAIM_KINDS = array( 'diseases', 'claims', 'dosing', 'labels', 'links' );

	/**
	 * What each list is called on the page.
	 *
	 * @return array<string, string>
	 */
	public static function titles() {
		return array(
			'drugs'      => __( 'Drug names (finds products that are not in the medication categories)', 'fishotel-misc-plugin' ),
			'diseases'   => __( 'Diseases, parasites and conditions', 'fishotel-misc-plugin' ),
			'claims'     => __( 'What-it-does words (treats, cures, kills …)', 'fishotel-misc-plugin' ),
			'dosing'     => __( 'Dosing and treatment length', 'fishotel-misc-plugin' ),
			'labels'     => __( 'Medicine-type labels (Antibacterial, Dewormer …)', 'fishotel-misc-plugin' ),
			'links'      => __( 'Addresses product pages must not link to', 'fishotel-misc-plugin' ),
			'water'      => __( 'Words that put a product in Water Chemistry', 'fishotel-misc-plugin' ),
			'storage'    => __( 'Storage line on every listing', 'fishotel-misc-plugin' ),
			'supplier'   => __( 'Line for products our supplier ships', 'fishotel-misc-plugin' ),
			'own_brands' => __( 'Brands we sell under (anything else is listed for you to look at)', 'fishotel-misc-plugin' ),
			'leave_out'  => __( 'Categories never looked at (slugs, e.g. the fish)', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The lists as they come.
	 *
	 * @return array<string, string[]|string>
	 */
	public static function defaults() {
		return array(
			'drugs'      => array(
				'metronidazole', 'praziquantel', 'prazi', 'kanamycin', 'nitrofurazone', 'chloroquine',
				'chloroquine phosphate', 'tmp/sulfa', 'tmp sulfa', 'sulfa', 'sulfamethoxazole', 'trimethoprim',
				'erythromycin', 'neomycin', 'oxytetracycline', 'doxycycline', 'tetracycline', 'oxolinic',
				'oxolinic acid', 'furan', 'furan-2', 'nitrofuran', 'copper', 'cupramine', 'copper sulfate',
				'chelated copper', 'levamisole', 'fenbendazole', 'flubendazole', 'albendazole', 'general trio',
				'general cure', 'formalin', 'malachite green', 'methylene blue', 'acriflavine', 'hydrogen peroxide',
				'ivermectin', 'emamectin', 'piperazine', 'florfenicol', 'ciprofloxacin', 'enrofloxacin',
				'minocycline', 'cephalexin', 'amoxicillin', 'ampicillin', 'nifurpirinol', 'primaquine',
				'quinine', 'polyguard', 'paraguard', 'medicated',
			),
			'diseases'   => array(
				'ich', 'marine ich', 'white spot', 'cryptocaryon', 'crypto', 'velvet', 'amyloodinium', 'oodinium',
				'brooklynella', 'uronema', 'flukes', 'fluke', 'gill flukes', 'skin flukes', 'monogeneans',
				'monogenean', 'digeneans', 'hexamita', 'spironucleus', 'hith', 'hlle', 'hole in the head',
				'head and lateral line erosion', 'lateral line erosion', 'bloat', 'flagellates', 'flagellate',
				'nematodes', 'nematode', 'camallanus', 'tapeworms', 'tapeworm', 'cestodes', 'worms', 'internal worms',
				'internal parasites', 'external parasites', 'parasites', 'parasite', 'parasitic', 'columnaris',
				'fin rot', 'tail rot', 'mouth rot', 'bacterial infections', 'bacterial infection', 'infections',
				'infection', 'bacterial', 'fungal infection', 'fungus', 'fungal', 'saprolegnia', 'dropsy', 'popeye',
				'pop-eye', 'ulcers', 'ulcer', 'lymphocystis', 'vibrio', 'mycobacteriosis', 'tuberculosis',
				'septicemia', 'ammonia poisoning', 'nitrite poisoning', 'methemoglobinemia', 'disease', 'diseases',
				'illness', 'sick', 'symptoms', 'wasting', 'protozoa', 'protozoan', 'ciliates', 'black spot',
				'turbellarians', 'flatworms', 'lice', 'argulus', 'anchor worm', 'gill disease', 'pathogens',
				'pathogen', 'hydroids', 'gram-positive', 'gram-negative', 'gram positive', 'gram negative',
			),
			'claims'     => array(
				'treat', 'treats', 'treated', 'treating', 'treatment', 'treatments', 'cure', 'cures', 'cured',
				'curing', 'controls', 'control of', 'effective against', 'effective for', 'kill', 'kills',
				'killing', 'fights', 'fight', 'fighting', 'prevents', 'prevent', 'prevention', 'preventive',
				'eliminates', 'eliminate', 'eradicates', 'eradicate', 'heals', 'heal', 'healing', 'remedy',
				'remedies', 'therapy', 'therapeutic', 'therapeutic-grade', 'broad-spectrum', 'broad spectrum',
				'qt coverage', 'great for quarantine', 'great for qt', 'for quarantine', 'use in qt', 'use in quarantine', 'quarantine protocol', 'quarantine tank', 'qt tank',
				'hospital tank', 'prophylactic', 'prophylaxis', 'outbreak', 'relief', 'recovery', 'clears up',
				'gets rid of', 'wipes out', 'medication partner', 'trusted medication partner', 'medicine',
			),
			'dosing'     => array(
				'dose', 'doses', 'dosed', 'dosing', 'dosage', 'redose', 're-dose', 'per gallon', 'per gallons',
				'mg/l', 'mg per', 'teaspoon', 'teaspoons', 'tsp', 'scoop per', 'sole food', 'feed as sole',
				'times a day', 'twice daily', 'once daily', 'daily for', 'every other day', 'treatment length',
				'course of', 'full course', 'dosing calculator', 'medication calculator',
				'/\b\d+\s*(?:-|–|—|to)\s*\d+\s*days?\b/i', '/\bfor\s+\d+\s*days?\b/i',
				'/\b\d+\s*(?:mg|ml|g)\s*(?:per|\/)\s*\d*\s*(?:gal|gallons?|l|liters?)\b/i',
				'/\b\d+(?:\.\d+)?\s*ppm\b/i',
			),
			'labels'     => array(
				'antibacterial', 'anti-bacterial', 'antibiotic', 'antibiotics', 'antiparasitic', 'anti-parasitic',
				'anti-parasite', 'antiprotozoal', 'anti-protozoal', 'antifungal', 'anti-fungal', 'dewormer',
				'dewormers', 'deworm', 'deworming', 'wormer', 'anthelmintic', 'antimicrobial', 'anti-microbial',
				'quarantine essentials', 'aminoglycoside', 'nitrofuran', 'quinoline', 'benzimidazole',
				'imidazothiazole', 'phenothiazine', 'fluoroquinolone', 'macrolide', 'sulfonamide', 'pharmacy',
			),
			'links'      => array(
				'qt-scheduler', 'quarantine-help', 'medication-dosing', 'dosing-calculator',
				'medication-calculator', '/calculator', '/blog/', '/boards/', '/forum', '/forums/', '?med=',
			),
			'water'      => array( 'copper', 'cupramine', 'copper sulfate', 'chelated copper', 'ammonia', 'nitrite', 'ph buffer', 'alkalinity', 'dechlorinator', 'water conditioner' ),
			'storage'    => 'Keep sealed, store cool and dry. Not for human consumption. Keep out of reach of children.',
			'supplier'   => 'Ships direct from our supplier.',
			'own_brands' => 'FisHotel, Everything Aquatic, EverythingAquatic, EA',
			'leave_out'  => 'quarantined-fish, invoices',
		);
	}

	/**
	 * The lists in use: the owner's where saved, the defaults otherwise.
	 *
	 * @return array<string, string[]|string>
	 */
	public static function get() {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		$out   = self::defaults();

		foreach ( self::LISTS as $key ) {
			if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
				$out[ $key ] = array_values( array_filter( array_map( 'strval', $saved[ $key ] ), 'strlen' ) );
			}
		}
		foreach ( self::LINES as $key ) {
			if ( isset( $saved[ $key ] ) && is_string( $saved[ $key ] ) ) {
				$out[ $key ] = $saved[ $key ];
			}
		}
		return $out;
	}

	/**
	 * A textarea's text → its list (one per line, blank lines and
	 * repeats dropped, order kept).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function parse_lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * A comma list → its items, trimmed (own brands, left-out categories).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function parse_commas( $text ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $text ) ), 'strlen' ) );
	}

	/**
	 * What the save stores from the posted form: every list and line,
	 * patterns that do not compile dropped (they would match nothing and
	 * hide a mistake).
	 *
	 * @param array $input Posted lists (textarea text) and lines.
	 * @return array{saved: array, bad: string[]}
	 */
	public static function from_input( array $input ) {
		$saved = array();
		$bad   = array();
		foreach ( self::LISTS as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$list = array();
			foreach ( self::parse_lines( $input[ $key ] ) as $line ) {
				if ( self::is_pattern( $line ) && false === @preg_match( $line, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing the owner's pattern.
					$bad[] = $line;
					continue;
				}
				$list[] = $line;
			}
			$saved[ $key ] = $list;
		}
		foreach ( self::LINES as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$saved[ $key ] = trim( preg_replace( '/\s+/', ' ', (string) $input[ $key ] ) );
			}
		}
		return array(
			'saved' => $saved,
			'bad'   => $bad,
		);
	}

	/**
	 * Whether a line is a pattern (written between slashes, flags allowed).
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	public static function is_pattern( $line ) {
		return (bool) preg_match( '#^/.+/[imsxuU]*$#s', (string) $line );
	}
}
