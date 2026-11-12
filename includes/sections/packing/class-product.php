<?php
/**
 * The product editor: the "Livestock — packed in a bag" checkbox (Shipping
 * tab, simple and variable products) and the "Bag size" dropdown — on
 * every variation, and on a simple product (Shipping tab).
 *
 * Both are RECORDS, strictly: the hidden product meta `_fh_packs_in_bag`
 * ('yes' / 'no', on the parent product) and `_fh_bag_size` (a bag id).
 * Neither is a product attribute, creates a variation or a term, and
 * nothing on the storefront, in the cart, at checkout, in an e-mail, on an
 * invoice, in My Account or in the customer-facing REST / Store API reads
 * them — this section registers no hook outside wp-admin and never adds
 * anything to an order line.
 *
 * The checkbox says whether the product is something that goes in a bag
 * at all (fish and other livestock do; medication, food and dry goods do
 * not). With no stored choice it shows the default — ticked for a product
 * in one of the fish categories — and a save that leaves it as it was
 * drawn stores nothing, so the product keeps following the default; the
 * owner ticking or unticking it stores 'yes' or 'no' (decide_packs()).
 * The script marks a box the owner clicked, so clicking it off and on
 * again stores the choice as well.
 *
 * The Bag size dropdowns are drawn for every product and shown only while
 * the checkbox is ticked: the product-data box carries the class
 * `fh-no-bag` while it is not (set here when the screen is drawn, toggled
 * by the script when the checkbox changes), and the stylesheet hides the
 * dropdowns under it — the variation panels WooCommerce loads later
 * included. A Bag size stored earlier is kept when the box is unticked; it
 * stops counting (Livestock::decide()).
 *
 * The fields are shown to, and saved for, users who can manage_woocommerce
 * only, behind this section's own nonce (WooCommerce checks its own nonce
 * and the edit capability before either save hook fires). "Not set" is a
 * choice too: the variation then follows the size → bag map, and the
 * first option says which bag that is right now.
 *
 * decide() and decide_packs() are the pure rules of a save; the save
 * handlers read the posted values, ask them, and write through the product
 * object.
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
	 * Form fields of the "Livestock — packed in a bag" checkbox: the
	 * checkbox itself (posted only while ticked) and, beside it, the state
	 * it was drawn in ('yes' / 'no') — which also says the checkbox was on
	 * the form at all. Once the owner has clicked the box the script makes
	 * that field the opposite of the box, so the click is stored.
	 */
	const FIELD_PACKS       = 'fh_packs_in_bag';
	const FIELD_PACKS_SHOWN = 'fh_packs_in_bag_shown';

	/**
	 * The class the product-data box carries while the checkbox is off:
	 * the stylesheet hides every Bag size dropdown under it.
	 *
	 * @var string
	 */
	const CLASS_OFF = 'fh-no-bag';

	/**
	 * Settings and sizes of this request (the editor draws many
	 * variations).
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * The store the checkbox's default is read through (the tests hand in
	 * a stand-in).
	 *
	 * @var object|null
	 */
	private $store = null;

	/**
	 * @param object|null $store Store, or a stand-in with variation_bags() (null = a new Store when needed).
	 */
	public function __construct( $store = null ) {
		$this->store = is_object( $store ) ? $store : null;
	}

	/**
	 * Register the product-editor hooks. All of them fire in wp-admin only
	 * (the variation panels load and save through admin-ajax.php).
	 */
	public function init() {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'woocommerce_product_options_shipping', array( $this, 'render_simple_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ), 10, 1 );
		// The classes of the product-data box on the product edit screen (wp-admin only).
		add_filter( 'postbox_classes_product_woocommerce-product-data', array( $this, 'product_data_classes' ) );
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
	 * What a posted "Livestock — packed in a bag" checkbox does to the
	 * stored choice (pure). The owner ticking or unticking it stores 'yes'
	 * or 'no'; a save that leaves it as it was drawn stores nothing — a
	 * product with no stored choice keeps following the default.
	 *
	 * @param mixed  $shown   The state the checkbox was drawn in, as posted beside it: 'yes' | 'no'
	 *                        (anything else = the checkbox was not on the form).
	 * @param bool   $ticked  Whether the checkbox was posted ticked.
	 * @param string $current The stored choice ('yes' | 'no' | '' = none).
	 * @return array{action: string, value: string} action = keep | set.
	 */
	public static function decide_packs( $shown, $ticked, $current ) {
		$current = Livestock::flag( $current );
		$shown   = Livestock::flag( $shown );
		$posted  = $ticked ? Livestock::YES : Livestock::NO;
		$keep    = array(
			'action' => 'keep',
			'value'  => $current,
		);

		if ( '' === $shown || $posted === $shown || $posted === $current ) {
			return $keep; // Not on the form, left as it was drawn, or stored already.
		}

		return array(
			'action' => 'set',
			'value'  => $posted,
		);
	}

	/**
	 * Whether the current request may save a Bag size or the checkbox: the
	 * capability and this section's nonce.
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
	/*  Livestock — packed in a bag                                    */
	/* ============================================================== */

	/**
	 * The store, made when first needed.
	 *
	 * @return object
	 */
	private function store() {
		if ( null === $this->store ) {
			$this->store = new Store();
		}
		return $this->store;
	}

	/**
	 * Whether a product carries a Bag size from before the checkbox: its
	 * own (a simple product), or one of its variations'. Such a product
	 * counts as packed in a bag while it has no stored choice — the rule of
	 * Livestock::decide() — so its checkbox is drawn ticked.
	 *
	 * @param object $product WC_Product (the parent).
	 * @return bool
	 */
	private function has_stored_bag( $product ) {
		$settings = $this->settings();
		if ( null !== Bags::find( $settings['bags'], Bags::own_value( $product ) ) ) {
			return true;
		}
		if ( ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) || ! method_exists( $product, 'get_id' ) ) {
			return false;
		}

		$store = $this->store();
		foreach ( method_exists( $store, 'variation_bags' ) ? (array) $store->variation_bags( (int) $product->get_id() ) : array() as $value ) {
			if ( null !== Bags::find( $settings['bags'], $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the checkbox of a product is ticked: the stored choice, else
	 * the default — the one rule of Livestock::decide(), asked of the
	 * product as a whole.
	 *
	 * @param object|null $product WC_Product (the parent).
	 * @return bool
	 */
	public function ticked( $product ) {
		if ( ! is_object( $product ) ) {
			return false;
		}
		$flag = Livestock::stored( $product );

		return Livestock::decide( $flag, '' === $flag && Livestock::in_fish_category( $product ), '' === $flag && $this->has_stored_bag( $product ) );
	}

	/**
	 * The term ids of the fish categories — so the script can tick the box
	 * as soon as a new product is put in one, before it is saved.
	 *
	 * @return int[]
	 */
	private function fish_term_ids() {
		$ids = array();
		if ( ! function_exists( 'get_term_by' ) ) {
			return $ids;
		}
		foreach ( Lines::fish_categories() as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * The classes of the product-data box: `fh-no-bag` while the product
	 * is not packed in a bag, so the Bag size dropdowns are hidden from the
	 * first paint — also on the variation panels that load later.
	 *
	 * @param mixed $classes The box's classes.
	 * @return array
	 */
	public function product_data_classes( $classes ) {
		global $post;

		$classes = is_array( $classes ) ? $classes : array();
		if ( ! current_user_can( Packing::CAPABILITY ) || ! function_exists( 'wc_get_product' ) ) {
			return $classes; // The fields are not drawn at all.
		}

		try {
			$product = is_object( $post ) && isset( $post->ID ) ? wc_get_product( (int) $post->ID ) : null;
			$ticked  = $this->ticked( is_object( $product ) ? $product : null );
		} catch ( \Throwable $e ) {
			$ticked = true; // Never hide a field because something else failed.
		}
		if ( ! $ticked ) {
			$classes[] = self::CLASS_OFF;
		}

		return $classes;
	}

	/**
	 * The checkbox (Shipping tab, simple and variable products), with the
	 * state it is drawn in beside it.
	 *
	 * @param object|null $product WC_Product.
	 */
	private function render_packs_field( $product ) {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}

		$ticked = $this->ticked( $product );
		$follow = null === $product || '' === Livestock::stored( $product );
		$data   = array();
		if ( $follow ) {
			// No stored choice: the script lets the box follow the categories until it is touched.
			$data['data-fh-follow'] = '1';
			$data['data-fh-fish']   = implode( ',', $this->fish_term_ids() );
			if ( null !== $product && $this->has_stored_bag( $product ) ) {
				$data['data-fh-legacy'] = '1';
			}
		}

		woocommerce_wp_checkbox(
			array(
				'id'                => self::FIELD_PACKS,
				'name'              => self::FIELD_PACKS,
				'label'             => __( 'Livestock — packed in a bag', 'fishotel-misc-plugin' ),
				'value'             => $ticked ? Livestock::YES : Livestock::NO,
				'cbvalue'           => Livestock::YES,
				'description'       => __( 'Tick for fish and other livestock that are packed in a bag: the Bag size fields show while it is ticked. Leave it off for medication, food and dry goods. For your records only: customers never see this.', 'fishotel-misc-plugin' ),
				'wrapper_class'     => 'show_if_simple show_if_variable',
				'custom_attributes' => $data,
			)
		);
		echo '<input type="hidden" id="' . esc_attr( self::FIELD_PACKS_SHOWN ) . '" name="' . esc_attr( self::FIELD_PACKS_SHOWN ) . '" value="' . esc_attr( $ticked ? Livestock::YES : Livestock::NO ) . '">';
	}

	/**
	 * Save the checkbox onto the product object WooCommerce is about to
	 * save (the parent product — a variation has none of its own).
	 *
	 * @param object $product WC_Product.
	 * @param array  $raw     Unslashed POST.
	 * @return bool Whether the object changed.
	 */
	private function save_packs( $product, array $raw ) {
		if ( ! method_exists( $product, 'update_meta_data' ) || ! method_exists( $product, 'get_meta' ) ) {
			return false;
		}
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			return false;
		}

		$ticked   = isset( $raw[ self::FIELD_PACKS ] ) && Livestock::YES === Livestock::flag( $raw[ self::FIELD_PACKS ] );
		$decision = self::decide_packs( $raw[ self::FIELD_PACKS_SHOWN ] ?? null, $ticked, $product->get_meta( Packing::META_PACKS, true ) );
		if ( 'set' !== $decision['action'] ) {
			return false;
		}

		$product->update_meta_data( Packing::META_PACKS, (string) $decision['value'] );
		return true;
	}

	/* ============================================================== */
	/*  Simple products                                                */
	/* ============================================================== */

	/**
	 * The Shipping tab (with the weight and the dimensions): the checkbox,
	 * then the dropdown of a simple product.
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

		$this->render_packs_field( $product );

		woocommerce_wp_select(
			array(
				'id'            => self::FIELD,
				'name'          => self::FIELD,
				'label'         => __( 'Bag size', 'fishotel-misc-plugin' ),
				'value'         => $current,
				'options'       => self::options( $settings['bags'], $current, $mapped['bag'], '' !== $size ),
				'desc_tip'      => true,
				'description'   => $this->help(),
				'wrapper_class' => 'show_if_simple fh-bag-size-field',
			)
		);
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
	}

	/**
	 * Save the Shipping tab's two fields onto the product object
	 * WooCommerce is about to save: the checkbox (simple and variable
	 * products), and a simple product's Bag size.
	 *
	 * @param object $product WC_Product.
	 */
	public function save_product( $product ) {
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in may_save().
		if ( ! is_array( $raw ) || ! self::may_save( $raw ) || ! is_object( $product ) ) {
			return;
		}

		$this->save_packs( $product, $raw );
		$this->save_simple( $product, $raw );
	}

	/**
	 * A simple product's Bag size, onto the product object. A variable
	 * product has no Bag size of its own — its variations do. The value is
	 * kept as posted also while the checkbox is off: an earlier Bag size is
	 * never deleted by unticking, it stops counting.
	 *
	 * @param object $product WC_Product.
	 * @param array  $raw     Unslashed POST (the nonce and the capability were checked).
	 */
	private function save_simple( $product, array $raw ) {
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
