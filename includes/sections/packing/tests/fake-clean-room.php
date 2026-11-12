<?php
/**
 * The Clean Room section as the Packing section meets it: the three
 * classes Size_List::standard() looks for (Sizes, Store, Settings in the
 * Clean Room's namespace). Store and Settings are stand-ins a test seeds
 * through $GLOBALS['fh_clean_room']; Sizes is the REAL class when the
 * Clean Room's file is in the plugin (so the two sections are tested
 * against each other), and a stand-in with the same answer shape when it
 * is not.
 *
 * Helper, not a test: it exits cleanly when it is run by itself. A test
 * requires it only at the point where the Clean Room should "be there".
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace {

	if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
		exit( 0 );
	}

	$GLOBALS['fh_clean_room'] = array(
		'attributes' => array(),
		'terms'      => array(),
		'settings'   => array(),
		'throw'      => false,
	);

	$fh_real_sizes = FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes.php';
	if ( is_readable( $fh_real_sizes ) ) {
		require_once $fh_real_sizes;
	}
}

namespace FisHotel\Misc\Sections\Clean_Room {

	class Store {

		public function size_attributes() {
			if ( ! empty( $GLOBALS['fh_clean_room']['throw'] ) ) {
				throw new \RuntimeException( 'the Clean Room store is not reachable' );
			}
			return $GLOBALS['fh_clean_room']['attributes'];
		}

		public function size_terms( $taxonomy ) {
			return $GLOBALS['fh_clean_room']['terms'][ $taxonomy ] ?? array();
		}
	}

	class Settings {

		public static function get() {
			return $GLOBALS['fh_clean_room']['settings'];
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\Sizes', false ) ) {
		/**
		 * Used only when the Clean Room's own file is not in the plugin:
		 * the same answer shape, the first matching attribute, its terms in
		 * the given order, else the typed list.
		 */
		class Sizes {

			public static function standard( $store, array $settings ) {
				$attributes = (array) $store->size_attributes();
				$chosen     = (string) ( $settings['size_attribute'] ?? '' );
				$attribute  = null;
				foreach ( $attributes as $candidate ) {
					if ( null === $attribute || $candidate['taxonomy'] === $chosen ) {
						$attribute = $candidate;
					}
				}
				$sizes = array();
				if ( null !== $attribute ) {
					foreach ( (array) $store->size_terms( $attribute['taxonomy'] ) as $term ) {
						$sizes[] = array(
							'slug'  => $term['slug'],
							'label' => $term['name'],
						);
					}
				} else {
					foreach ( (array) ( $settings['size_list'] ?? array() ) as $label ) {
						$sizes[] = array(
							'slug'  => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $label ) ), '-' ),
							'label' => $label,
						);
					}
				}
				return array(
					'source'     => null !== $attribute ? 'attribute' : 'list',
					'attribute'  => null !== $attribute ? $attribute['taxonomy'] : '',
					'label'      => null !== $attribute ? $attribute['label'] : '',
					'attributes' => $attributes,
					'sizes'      => $sizes,
				);
			}
		}
	}
}
