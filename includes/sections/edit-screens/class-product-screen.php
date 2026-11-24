<?php
/**
 * The product edit screen, tidied.
 *
 *   - Under the title, above the description, an area (drawn empty on
 *     `edit_form_after_title`) into which the script moves Fish essentials
 *     and WooCommerce's product data box (Layouts::TOP) as the page is
 *     drawn — before WooCommerce's own script starts, so its box works as
 *     ever (its id is unchanged). Stored, they are just the first boxes of
 *     the main column: without this section they show there.
 *   - Names: WooCommerce's "Product data" → "Price, stock & sizes" (it no
 *     longer shares a name with the theme's box); the theme's fish box →
 *     "Fish facts — the table on the product page"; "Compatibility
 *     Category" → "Compatibility guide"; "FisHotel Coming Soon" → "Coming
 *     Soon — release date". Titles only: each box is still its owner's.
 *   - The product data tabs: Attributes and Variations right after General
 *     (12, 14), the theme's medication tab right after them when it is
 *     there (15); the script puts the Shipping class first in its tab.
 *   - The kind of product (Kinds) as a class on the page, worked out again
 *     by the script when a category is ticked: boxes that are not for the
 *     kind are not shown — but are still printed and posted.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Screen
 */
class Product_Screen {

	/**
	 * New box titles, by box id.
	 *
	 * @return array<string, string>
	 */
	public static function titles() {
		return array(
			'woocommerce-product-data' => __( 'Price, stock & sizes', 'fishotel-misc-plugin' ),
			'fishotel_hotel_data'      => __( 'Fish facts — the table on the product page', 'fishotel-misc-plugin' ),
			'fishotel-compat-category' => __( 'Compatibility guide', 'fishotel-misc-plugin' ),
			'fishotel_coming_soon'     => __( 'Coming Soon — release date', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The product data tabs' new places (pure): Attributes and Variations
	 * after General, the medication tab after them. Anything else as it
	 * is.
	 *
	 * @param mixed $tabs WooCommerce's tabs.
	 * @return mixed
	 */
	public static function tabs( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		foreach ( array(
			'attribute'    => 12,
			'variations'   => 14,
			'fishotel_med' => 15,
		) as $key => $priority ) {
			if ( isset( $tabs[ $key ] ) && is_array( $tabs[ $key ] ) ) {
				$tabs[ $key ]['priority'] = $priority;
			}
		}
		return $tabs;
	}

	/**
	 * The kind classes of a box (pure): which kinds it is not shown for.
	 *
	 * @param string $box     Box id.
	 * @param mixed  $classes Its classes.
	 * @return array
	 */
	public static function box_classes( $box, $classes ) {
		$classes = is_array( $classes ) ? $classes : array();
		if ( in_array( $box, Kinds::LIVE_ONLY, true ) ) {
			$classes[] = 'fh-es-live-only';
		} elseif ( in_array( $box, Kinds::NOT_LIVE, true ) ) {
			$classes[] = 'fh-es-not-live';
		}
		return $classes;
	}

	/**
	 * Register the hooks (wp-admin only).
	 */
	public function init() {
		add_action( 'add_meta_boxes_product', array( $this, 'boxes' ), 99 );
		add_action( 'edit_form_after_title', array( $this, 'top_area' ) );
		add_filter( 'woocommerce_product_data_tabs', array( self::class, 'tabs' ), 99 );
		add_action( 'woocommerce_process_product_meta', array( Essentials::class, 'save' ), 50 );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		// The kind classes of the boxes not shown for every kind (Kinds::LIVE_ONLY, Kinds::NOT_LIVE).
		add_filter( 'postbox_classes_product_fishotel_hotel_data', array( $this, 'classes_live_only' ) );
		add_filter( 'postbox_classes_product_fishotel-compat-category', array( $this, 'classes_live_only' ) );
		add_filter( 'postbox_classes_product_fishotel_cleanroom', array( $this, 'classes_live_only' ) );
		add_filter( 'postbox_classes_product_postexcerpt', array( $this, 'classes_not_live' ) );
	}

	/**
	 * The classes of a box shown for a live fish only.
	 *
	 * @param mixed $classes Its classes.
	 * @return array
	 */
	public function classes_live_only( $classes ) {
		return self::box_classes( Kinds::LIVE_ONLY[0], $classes );
	}

	/**
	 * The classes of a box not shown for a live fish.
	 *
	 * @param mixed $classes Its classes.
	 * @return array
	 */
	public function classes_not_live( $classes ) {
		return self::box_classes( Kinds::NOT_LIVE[0], $classes );
	}

	/**
	 * Fish essentials into the top area; the new titles.
	 */
	public function boxes() {
		global $wp_meta_boxes;

		add_meta_box( Layouts::ESSENTIALS, __( 'Fish essentials', 'fishotel-misc-plugin' ), array( Essentials::class, 'render' ), 'product', 'normal', 'high' );

		if ( ! isset( $wp_meta_boxes['product'] ) || ! is_array( $wp_meta_boxes['product'] ) ) {
			return;
		}
		$titles = self::titles();
		foreach ( $wp_meta_boxes['product'] as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( isset( $titles[ $id ] ) && is_array( $box ) ) {
						$wp_meta_boxes['product'][ $context ][ $priority ][ $id ]['title'] = $titles[ $id ];
					}
				}
			}
		}
	}

	/**
	 * The area under the title.
	 *
	 * @param \WP_Post $post The post.
	 */
	public function top_area( $post ) {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'product' !== $post->post_type ) {
			return;
		}
		echo '<div id="fh-es-top" class="fh-es-top" data-boxes="' . esc_attr( implode( ',', Layouts::TOP ) ) . '"></div>';
	}

	/**
	 * The kind of the product on this screen.
	 *
	 * @param int $id Product ID.
	 * @return string
	 */
	public static function kind_of( $id ) {
		$id    = (int) $id;
		$terms = $id > 0 && function_exists( 'get_the_terms' ) ? get_the_terms( $id, 'product_cat' ) : array();
		$slugs = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( is_object( $term ) && isset( $term->slug ) ) {
				$slugs[] = (string) $term->slug;
			}
		}
		$packs = $id > 0 ? get_post_meta( $id, '_fh_packs_in_bag', true ) : '';
		$ships = $id > 0 ? get_post_meta( $id, '_fishotel_fulfillment', true ) : '';
		return Kinds::kind( $slugs, $packs, $ships, Kinds::fish_categories() );
	}

	/**
	 * Whether this request is the product edit screen.
	 *
	 * @return bool
	 */
	private static function on_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return is_object( $screen ) && isset( $screen->id ) && 'product' === $screen->id;
	}

	/**
	 * The kind as a class on the page.
	 *
	 * @param mixed $classes The body's classes (a string).
	 * @return string
	 */
	public function body_class( $classes ) {
		$classes = is_string( $classes ) ? $classes : '';
		if ( ! self::on_screen() ) {
			return $classes;
		}
		global $post;
		$kind = self::kind_of( is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0 );
		return $classes . ' fh-es-on fh-es-kind-' . $kind;
	}

	/**
	 * The term ids of each kind's categories, for the script.
	 *
	 * @return array<string, int[]>
	 */
	public static function kind_terms() {
		$out = array(
			'fish'    => array(),
			'med'     => array(),
			'invoice' => array(),
		);
		if ( ! function_exists( 'get_term_by' ) ) {
			return $out;
		}
		$lists = array(
			'fish'    => Kinds::fish_categories(),
			'med'     => Kinds::MED_SLUGS,
			'invoice' => array( 'invoices' ),
		);
		foreach ( $lists as $kind => $slugs ) {
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( is_object( $term ) && isset( $term->term_id ) ) {
					$out[ $kind ][] = (int) $term->term_id;
				}
			}
		}
		return $out;
	}

	/**
	 * The stylesheet and the script, on the product edit screen.
	 */
	public function assets() {
		if ( ! self::on_screen() ) {
			return;
		}
		Edit_Screens::enqueue();
		wp_localize_script(
			'fishotel-edit-screens',
			'fhEditScreens',
			array(
				'kindTerms' => self::kind_terms(),
			)
		);
	}
}
