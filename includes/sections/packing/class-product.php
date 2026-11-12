<?php
/**
 * The "Bag size" dropdown in the WooCommerce product editor — on every
 * variation, and on a simple product (Shipping tab).
 *
 * It is a RECORD, strictly: the value is the hidden product meta
 * `_fh_bag_size` (a bag id). It is not a product attribute, it creates no
 * variation and no term, and nothing on the storefront, in the cart, at
 * checkout, in an e-mail, on an invoice, in My Account or in the
 * customer-facing REST / Store API reads it — this section registers no
 * hook outside wp-admin and never adds anything to an order line.
 *
 * The field is shown to, and saved for, users who can manage_woocommerce
 * only, behind this section's own nonce (WooCommerce checks its own nonce
 * and the edit capability before either save hook fires). "Not set" is a
 * choice too: the variation then follows the size → bag map, and the
 * first option says which bag that is right now.
 *
 * decide() is the pure rule of a save; the two save handlers read the
 * posted value, ask it, and write through the product object.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product
 */
class Product {

	/**
	 * Nonce action and field of the Bag size dropdown.
	 */
	const NONCE_ACTION = 'fishotel_packing_bag_size';
	const NONCE_FIELD  = 'fh_bag_size_nonce';

	/**
	 * Form field: fh_bag_size (a simple product), fh_bag_size_var[n] (the
	 * n-th variation on the form).
	 */
	const FIELD           = 'fh_bag_size';
	const FIELD_VARIATION = 'fh_bag_size_var';

	/**
	 * Settings and sizes of this request (the editor draws many
	 * variations).
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Register the product-editor hooks. All of them fire in wp-admin only
	 * (the variation panels load and save through admin-ajax.php).
	 */
	public function init() {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'woocommerce_product_options_shipping', array( $this, 'render_simple_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_simple' ), 10, 1 );
	}

	/**
	 * The settings, read once per request.
	 *
	 * @return array
	 */
	private function settings() {
		if ( null === $this->settings ) {
			$this->settings = Settings::get();
		}
		return $this->settings;
	}

	/**
	 * The options of a Bag size dropdown (pure): "not set" first — saying
	 * which bag the size map gives, when it gives one —, then every bag of
	 * the list, then the stored value when it names a bag that is no
	 * longer in the list (so it shows instead of silently changing).
	 *
	 * @param array      $bags    Settings' bags.
	 * @param string     $current The stored value ('' = not set).
	 * @param array|null $mapped  The bag the map gives this product (Bags::describe()), null without one.
	 * @param bool       $sized   Whether the product carries a size at all.
	 * @return array<string, string> Value → label.
	 */
	public static function options( array $bags, $current, $mapped = null, $sized = true ) {
		if ( is_array( $mapped ) ) {
			/* translators: %s: bag name */
			$none = sprintf( __( 'Not set — from the size map: %s', 'fishotel-misc-plugin' ), (string) $mapped['label'] );
		} elseif ( $sized ) {
			$none = __( 'Not set — the size map has no bag for this size', 'fishotel-misc-plugin' );
		} else {
			$none = __( 'Not set', 'fishotel-misc-plugin' );
		}

		$options = array( '' => $none );
		foreach ( $bags as $bag ) {
			$options[ (string) (int) $bag['id'] ] = Bags::label( $bag );
		}

		$current = trim( (string) $current );
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			/* translators: %s: the stored value */
			$options[ $current ] = sprintf( __( 'A bag that was removed (#%s)', 'fishotel-misc-plugin' ), $current );
		}

		return $options;
	}

	/**
	 * What a posted value does to the stored one (pure).
	 *
	 * @param mixed  $posted  The posted value (null = the field was not on the form).
	 * @param string $current The stored value ('' = none).
	 * @param array  $bags    Settings' bags.
	 * @return array{action: string, value: string} action = keep | set | delete.
	 */
	public static function decide( $posted, $current, array $bags ) {
		$keep = array(
			'action' => 'keep',
			'value'  => (string) $current,
		);

		if ( null === $posted || ! is_scalar( $posted ) || is_bool( $posted ) ) {
			return $keep;
		}
		$posted  = trim( (string) $posted );
		$current = trim( (string) $current );

		if ( $posted === $current ) {
			return $keep; // Unchanged — a removed bag's value included.
		}
		if ( '' === $posted ) {
			return array(
				'action' => 'delete',
				'value'  => '',
			);
		}
		if ( 1 !== preg_match( '/^\d{1,9}$/', $posted ) || null === Bags::find( $bags, $posted ) ) {
			return $keep; // Not a bag of the list: nothing changes.
		}

		return array(
			'action' => 'set',
			'value'  => (string) (int) $posted,
		);
	}

	/**
	 * Whether the current request may save a Bag size: the capability and
	 * this section's nonce.
	 *
	 * @param array $raw Unslashed POST.
	 * @return bool
	 */
	public static function may_save( array $raw ) {
		if ( ! current_user_can( Packing::CAPABILITY ) ) {
			return false;
		}
		$nonce = isset( $raw[ self::NONCE_FIELD ] ) && is_scalar( $raw[ self::NONCE_FIELD ] ) ? (string) $raw[ self::NONCE_FIELD ] : '';
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Apply a decision to a product object (not saved here).
	 *
	 * @param object $product  WC_Product.
	 * @param array  $decision decide().
	 * @return bool Whether the object changed.
	 */
	public static function apply( $product, array $decision ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
			return false;
		}
		if ( 'set' === $decision['action'] ) {
			$product->update_meta_data( Packing::META_BAG, (string) $decision['value'] );
			return true;
		}
		if ( 'delete' === $decision['action'] ) {
			$product->delete_meta_data( Packing::META_BAG );
			return true;
		}
		return false;
	}

	/* ============================================================== */
	/*  Variations                                                     */
	/* ============================================================== */

	/**
	 * The dropdown on one variation.
	 *
	 * @param int      $loop           The variation's position on the form.
	 * @param array    $variation_data Its data (unused).
	 * @param \WP_Post $variation      The variation post.
	 */
	public function render_variation_field( $loop, $variation_data = array(), $variation = null ) {
		unset( $variation_data );

		if ( ! current_user_can( Packing::CAPABILITY ) || ! function_exists( 'woocommerce_wp_select' ) ) {
			return;
		}

		$variation_id = is_object( $variation ) && isset( $variation->ID ) ? (int) $variation->ID : 0;
		$product      = $variation_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;
		$settings     = $this->settings();
		$standard     = Size_List::standard();
		$current      = is_object( $product ) ? Bags::own_value( $product ) : '';
		$size         = is_object( $product ) ? Bags::product_size( $product, $standard ) : '';
		$mapped       = Bags::resolve( '', $size, $settings, $standard['sizes'] );

		echo '<div class="fh-bag-size-field">';
		woocommerce_wp_select(
			array(
				'id'            => self::FIELD_VARIATION . '_' . (int) $loop,
				'name'          => self::FIELD_VARIATION . '[' . (int) $loop . ']',
				'label'         => __( 'Bag size', 'fishotel-misc-plugin' ),
				'value'         => $current,
				'options'       => self::options( $settings['bags'], $current, $mapped['bag'], '' !== $size ),
				'desc_tip'      => true,
				'description'   => $this->help(),
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		// Each variation carries the nonce: WooCommerce posts only the variations that changed.
		echo '<input type="hidden" name="' . esc_attr( self::NONCE_FIELD ) . '" value="' . esc_attr( wp_create_nonce( self::NONCE_ACTION ) ) . '">';
		echo '</div>';
	}

	/**
	 * Save one variation's Bag size. WooCommerce has saved the variation
	 * itself by now (and checked its own nonce and capability); this writes
	 * the one hidden meta through the product object.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $index        Its position on the form.
	 */
	public function save_variation( $variation_id, $index = 0 ) {
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in may_save().
		if ( ! is_array( $raw ) || ! self::may_save( $raw ) ) {
			return;
		}

		$posted = isset( $raw[ self::FIELD_VARIATION ] ) && is_array( $raw[ self::FIELD_VARIATION ] ) && array_key_exists( $index, $raw[ self::FIELD_VARIATION ] ) ? $raw[ self::FIELD_VARIATION ][ $index ] : null;
		if ( null === $posted || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( (int) $variation_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'save_meta_data' ) ) {
			return;
		}

		$settings = $this->settings();
		if ( self::apply( $product, self::decide( $posted, Bags::own_value( $product ), $settings['bags'] ) ) ) {
			$product->save_meta_data();
		}
	}

	/* ============================================================== */
	/*  Simple products                                                */
	/* ============================================================== */

	/**
	 * The dropdown on a simple product (Shipping tab, with the weight and
	 * the dimensions).
	 */
	public function render_simple_field() {
		global $product_object;

		if ( ! current_user_can( Packing::CAPABILITY ) || ! function_exists( 'woocommerce_wp_select' ) ) {
			return;
		}

		$product  = is_object( $product_object ) ? $product_object : null;
		$settings = $this->settings();
		$standard = Size_List::standard();
		$current  = null !== $product ? Bags::own_value( $product ) : '';
		$variable = null !== $product && method_exists( $product, 'is_type' ) && $product->is_type( 'variable' );
		$size     = null !== $product && ! $variable ? Bags::product_size( $product, $standard ) : '';
		$mapped   = Bags::resolve( '', $size, $settings, $standard['sizes'] );

		woocommerce_wp_select(
			array(
				'id'            => self::FIELD,
				'name'          => self::FIELD,
				'label'         => __( 'Bag size', 'fishotel-misc-plugin' ),
				'value'         => $current,
				'options'       => self::options( $settings['bags'], $current, $mapped['bag'], '' !== $size ),
				'desc_tip'      => true,
				'description'   => $this->help(),
				'wrapper_class' => 'show_if_simple',
			)
		);
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
	}

	/**
	 * Save a simple product's Bag size onto the product object WooCommerce
	 * is about to save. A variable product has no Bag size of its own — its
	 * variations do.
	 *
	 * @param object $product WC_Product.
	 */
	public function save_simple( $product ) {
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in may_save().
		if ( ! is_array( $raw ) || ! self::may_save( $raw ) || ! is_object( $product ) ) {
			return;
		}
		if ( method_exists( $product, 'is_type' ) && ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) ) {
			return;
		}

		$settings = $this->settings();
		self::apply( $product, self::decide( $raw[ self::FIELD ] ?? null, Bags::own_value( $product ), $settings['bags'] ) );
	}

	/**
	 * The help text of the dropdown.
	 *
	 * @return string
	 */
	private function help() {
		$settings = $this->settings();
		if ( ! $settings['bags'] ) {
			return __( 'No bag sizes yet — add them under FisHotel Tools → Packing. For your records only: customers never see this.', 'fishotel-misc-plugin' );
		}
		return __( 'The bag this fish is packed in. For your records and the pick list only: customers never see it. Not set = the size → bag map decides.', 'fishotel-misc-plugin' );
	}
}
