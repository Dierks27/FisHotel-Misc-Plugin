<?php
/**
 * The shop — every call this section makes into WooCommerce and
 * WordPress objects, in one thin class, so everything that decides
 * (Publisher, Precheck, Sync) takes it as a parameter and the tests hand
 * in an in-memory twin (tests/fake-shop.php).
 *
 * Products and orders are read and written through WooCommerce's own
 * objects — wc_get_product(), WC_Product_Simple, wc_get_order(),
 * wc_get_orders(), wc_order_search() — so it works the same with HPOS on
 * or off. Nothing here touches $wpdb.
 *
 * What it writes, and nothing else:
 *
 *   create_invoice_product()  a NEW product, with the whole field set of a
 *                             hand-made invoice (see the method).
 *   adopt_invoice_product()   the same field set onto a product an earlier
 *                             Publish of the same invoice left behind, so a
 *                             second attempt never makes a second product.
 *   update_invoice_product()  the description, the regular price, the
 *                             shipping class, the image and the lines JSON
 *                             of a product this section created.
 *   unpublish_product()       a product this section just created, back to
 *                             a product draft, when it did not come out as
 *                             written.
 *   trash_product()           a product this section created, to the Trash.
 *
 * Every one but the first refuses a product that does not carry this
 * section's marker for that very invoice. A sale price is never set and
 * never cleared: no method here writes one. No order is ever written.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shop
 */
class Shop {

	/**
	 * Product statuses a fish product may have and still be offered (fish
	 * in quarantine are often still drafts).
	 *
	 * @var string[]
	 */
	const PICK_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Whether WooCommerce is there. Without it nothing can be built.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_order' ) && class_exists( '\WC_Product_Simple' );
	}

	/* ============================================================== */
	/*  Terms, images, the Trash                                       */
	/* ============================================================== */

	/**
	 * A term as a small array.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return array{id: int, slug: string, name: string}|null
	 */
	private function term( $slug, $taxonomy ) {
		$slug = (string) $slug;
		if ( '' === $slug || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return array(
			'id'   => (int) $term->term_id,
			'slug' => (string) $term->slug,
			'name' => (string) $term->name,
		);
	}

	/**
	 * The product category with a slug.
	 *
	 * @param string $slug Slug.
	 * @return array{id: int, slug: string, name: string}|null
	 */
	public function category( $slug ) {
		return $this->term( $slug, 'product_cat' );
	}

	/**
	 * The shipping class with a slug.
	 *
	 * @param string $slug Slug.
	 * @return array{id: int, slug: string, name: string}|null
	 */
	public function shipping_class( $slug ) {
		return $this->term( $slug, 'product_shipping_class' );
	}

	/**
	 * Every shipping class of the store, by name.
	 *
	 * @return array[] id, slug, name.
	 */
	public function shipping_classes() {
		if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_shipping_class' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'id'   => (int) $term->term_id,
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
			);
		}
		return $out;
	}

	/**
	 * An attachment, or null when the id is not one.
	 *
	 * @param int $id Attachment id.
	 * @return array{id: int, title: string, url: string}|null
	 */
	public function attachment( $id ) {
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		$url = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		return array(
			'id'    => $id,
			'title' => (string) get_the_title( $id ),
			'url'   => is_string( $url ) ? $url : '',
		);
	}

	/**
	 * Whether "move to the Trash" really keeps the product: with the Trash
	 * switched off (EMPTY_TRASH_DAYS = 0) WordPress deletes for good.
	 *
	 * @return bool
	 */
	public function trash_enabled() {
		return defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS > 0;
	}

	/**
	 * A user's display name ('' when gone).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function user_name( $user_id ) {
		$user = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : false;
		return $user ? (string) $user->display_name : '';
	}

	/* ============================================================== */
	/*  The invoice product                                            */
	/* ============================================================== */

	/**
	 * Whether the store enforces stock at all (WooCommerce → Settings →
	 * Products → Inventory → "Enable stock management"). With it off, a
	 * product's "stock 1, no backorders" is stored but not enforced: the
	 * same invoice could be bought, and paid, twice.
	 *
	 * @return bool
	 */
	public function stock_managed() {
		return 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
	}

	/**
	 * A product as plain facts — every field Publish writes, plus the two
	 * marks: this section's (`_fh_invoice_id`) and the theme's lock
	 * (`_fh_invoice_order_id`). Null when the product is gone.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public function product( $product_id ) {
		$product = $this->available() && (int) $product_id > 0 ? wc_get_product( (int) $product_id ) : null;
		return $product instanceof \WC_Product ? $this->product_facts( $product ) : null;
	}

	/**
	 * The facts of a product object.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	private function product_facts( $product ) {
		$id           = (int) $product->get_id();
		$category_ids = array_values( array_map( 'intval', (array) $product->get_category_ids() ) );
		$slugs        = array();
		foreach ( $category_ids as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$slugs[] = (string) $term->slug;
			}
		}

		$stock   = $product->get_stock_quantity();
		$created = $product->get_date_created();
		$json    = $product->get_meta( Invoices::PRODUCT_LINES_META );
		if ( is_array( $json ) ) {
			$json = wp_json_encode( $json );
		}

		return array(
			'id'                 => $id,
			'exists'             => true,
			'type'               => (string) $product->get_type(),
			'status'             => (string) $product->get_status(),
			'name'               => (string) $product->get_name(),
			'slug'               => (string) $product->get_slug(),
			'short_description'  => (string) $product->get_short_description(),
			'description'        => (string) $product->get_description(),
			'regular_price'      => (string) $product->get_regular_price(),
			'sale_price'         => (string) $product->get_sale_price(),
			'price'              => (string) $product->get_price(),
			'manage_stock'       => (bool) $product->get_manage_stock(),
			'stock_quantity'     => null === $stock ? null : (int) $stock,
			'backorders'         => (string) $product->get_backorders(),
			'stock_status'       => (string) $product->get_stock_status(),
			'catalog_visibility' => (string) $product->get_catalog_visibility(),
			'category_ids'       => $category_ids,
			'category_slugs'     => $slugs,
			'shipping_class_id'  => (int) $product->get_shipping_class_id(),
			'shipping_class'     => (string) $product->get_shipping_class(),
			'image_id'           => (int) $product->get_image_id(),
			'lines_json'         => is_string( $json ) ? $json : '',
			'marker'             => (int) $product->get_meta( Invoices::PRODUCT_MARKER_META ),
			'lock_order_id'      => (int) $product->get_meta( Invoices::PRODUCT_ORDER_META ),
			'permalink'          => (string) get_permalink( $id ),
			'edit_url'           => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'date_created'       => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '',
		);
	}

	/**
	 * A failed write.
	 *
	 * @param string $error Why.
	 * @return array{ok: bool, id: int, error: string}
	 */
	private static function failed( $error ) {
		return array(
			'ok'    => false,
			'id'    => 0,
			'error' => (string) $error,
		);
	}

	/**
	 * Put the whole field set of an invoice onto a product object,
	 * exactly as an invoice made by hand has it: published, hidden from
	 * the catalog; the title, the short description and the description
	 * given; the regular price = the total (and no sale price: none is
	 * ever written); stock managed, quantity 1, backorders not allowed (so
	 * it cannot be paid twice); the Invoices category and no other; the
	 * shipping class; the featured image when one is given; the theme's
	 * lines JSON; and this section's marker.
	 *
	 * @param \WC_Product $product Product object.
	 * @param array       $fields  name, short_description, description, regular_price ("599.00"),
	 *                             category_id, shipping_class_id, image_id (0 = none), lines_json,
	 *                             marker (invoice id).
	 */
	private function fill_invoice_product( $product, array $fields ) {
		$product->set_name( (string) $fields['name'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_short_description( (string) $fields['short_description'] );
		$product->set_description( (string) $fields['description'] );
		$product->set_regular_price( (string) $fields['regular_price'] );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 1 );
		$product->set_backorders( 'no' );
		$product->set_stock_status( 'instock' );
		$product->set_category_ids( array( (int) $fields['category_id'] ) );
		$product->set_shipping_class_id( (int) $fields['shipping_class_id'] );
		if ( (int) $fields['image_id'] > 0 ) {
			$product->set_image_id( (int) $fields['image_id'] );
		}
		$product->update_meta_data( Invoices::PRODUCT_LINES_META, (string) $fields['lines_json'] );
		$product->update_meta_data( Invoices::PRODUCT_MARKER_META, (int) $fields['marker'] );
	}

	/**
	 * Whether a field set names what a product cannot do without.
	 *
	 * @param array $fields Fields of fill_invoice_product().
	 * @return bool
	 */
	private static function fields_complete( array $fields ) {
		foreach ( array( 'name', 'short_description', 'description', 'regular_price', 'category_id', 'shipping_class_id', 'image_id', 'lines_json', 'marker' ) as $key ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				return false;
			}
		}
		return (int) $fields['category_id'] > 0 && (int) $fields['marker'] > 0 && '' !== trim( (string) $fields['name'] );
	}

	/**
	 * Create the invoice product (see fill_invoice_product() for what it
	 * is given).
	 *
	 * @param array $fields Fields of fill_invoice_product().
	 * @return array{ok: bool, id: int, error: string}
	 */
	public function create_invoice_product( array $fields ) {
		if ( ! $this->available() ) {
			return self::failed( __( 'WooCommerce is not active.', 'fishotel-misc-plugin' ) );
		}
		if ( ! self::fields_complete( $fields ) ) {
			return self::failed( __( 'The invoice is missing its title, its category or its id.', 'fishotel-misc-plugin' ) );
		}

		try {
			$product = new \WC_Product_Simple();
			$this->fill_invoice_product( $product, $fields );
			$id = (int) $product->save();
		} catch ( \Throwable $e ) {
			return self::failed( $e->getMessage() );
		}

		if ( $id <= 0 ) {
			return self::failed( __( 'WooCommerce did not save the product.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'ok'    => true,
			'id'    => $id,
			'error' => '',
		);
	}

	/**
	 * Finish a Publish that stopped half-way: put the whole field set
	 * onto the product it left behind (it carries this invoice's marker)
	 * instead of making a second one.
	 *
	 * @param int   $product_id The product left behind.
	 * @param array $fields     Fields of fill_invoice_product().
	 * @return array{ok: bool, id: int, error: string}
	 */
	public function adopt_invoice_product( $product_id, array $fields ) {
		$product = $this->available() ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return self::failed( __( 'The product no longer exists.', 'fishotel-misc-plugin' ) );
		}
		if ( ! self::fields_complete( $fields ) || (int) $product->get_meta( Invoices::PRODUCT_MARKER_META ) !== (int) $fields['marker'] ) {
			return self::failed( __( 'This product was not created by this section, so it is never changed here.', 'fishotel-misc-plugin' ) );
		}

		try {
			$this->fill_invoice_product( $product, $fields );
			$product->save();
		} catch ( \Throwable $e ) {
			return self::failed( $e->getMessage() );
		}

		return array(
			'ok'    => true,
			'id'    => (int) $product->get_id(),
			'error' => '',
		);
	}

	/**
	 * Change an invoice product this section created: its description, its
	 * regular price, its shipping class, its image (only when one is
	 * given) and the lines JSON. The title, the link, the stock and the
	 * sale price are left exactly as they are. A product without this
	 * section's marker for this invoice is refused.
	 *
	 * @param int   $product_id Product id.
	 * @param array $fields     description, regular_price, shipping_class_id, image_id (0 = keep), lines_json, marker.
	 * @return array{ok: bool, id: int, error: string}
	 */
	public function update_invoice_product( $product_id, array $fields ) {
		$product = $this->available() ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return self::failed( __( 'The product no longer exists.', 'fishotel-misc-plugin' ) );
		}
		if ( (int) $fields['marker'] <= 0 || (int) $product->get_meta( Invoices::PRODUCT_MARKER_META ) !== (int) $fields['marker'] ) {
			return self::failed( __( 'This product was not created by this section, so it is never changed here.', 'fishotel-misc-plugin' ) );
		}

		try {
			$product->set_description( (string) $fields['description'] );
			$product->set_regular_price( (string) $fields['regular_price'] );
			$product->set_shipping_class_id( (int) $fields['shipping_class_id'] );
			if ( (int) $fields['image_id'] > 0 ) {
				$product->set_image_id( (int) $fields['image_id'] );
			}
			$product->update_meta_data( Invoices::PRODUCT_LINES_META, (string) $fields['lines_json'] );
			$product->save();
		} catch ( \Throwable $e ) {
			return self::failed( $e->getMessage() );
		}

		return array(
			'ok'    => true,
			'id'    => (int) $product->get_id(),
			'error' => '',
		);
	}

	/**
	 * Move an invoice product this section created to the Trash — never
	 * delete it. Refused when the Trash is switched off (WordPress would
	 * delete for good) and for a product without this section's marker.
	 *
	 * @param int $product_id Product id.
	 * @param int $marker     The invoice id the product must carry.
	 * @return array{ok: bool, id: int, error: string}
	 */
	public function trash_product( $product_id, $marker ) {
		if ( ! $this->trash_enabled() ) {
			return self::failed( __( 'The Trash is switched off on this site (EMPTY_TRASH_DAYS is 0), so moving a product there would delete it for good. Nothing was done.', 'fishotel-misc-plugin' ) );
		}

		$product = $this->available() ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return self::failed( __( 'The product no longer exists.', 'fishotel-misc-plugin' ) );
		}
		if ( (int) $marker <= 0 || (int) $product->get_meta( Invoices::PRODUCT_MARKER_META ) !== (int) $marker ) {
			return self::failed( __( 'This product was not created by this section, so it is never changed here.', 'fishotel-misc-plugin' ) );
		}

		try {
			$product->delete( false );
		} catch ( \Throwable $e ) {
			return self::failed( $e->getMessage() );
		}

		$after = $this->product( (int) $product_id );
		if ( ! $after || 'trash' !== $after['status'] ) {
			return self::failed( __( 'The product did not reach the Trash.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'ok'    => true,
			'id'    => (int) $product_id,
			'error' => '',
		);
	}

	/**
	 * Take a product this section created out of public view (a product
	 * draft): what Publish does with a product that did not come out as
	 * written, so no customer is ever sent to a wrong invoice.
	 *
	 * @param int $product_id Product id.
	 * @param int $marker     The invoice id the product must carry.
	 * @return bool
	 */
	public function unpublish_product( $product_id, $marker ) {
		$product = $this->available() ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product instanceof \WC_Product || (int) $marker <= 0 || (int) $product->get_meta( Invoices::PRODUCT_MARKER_META ) !== (int) $marker ) {
			return false;
		}
		try {
			$product->set_status( 'draft' );
			$product->save();
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * The product that carries this section's marker for an invoice — a
	 * product a Publish created just before its own record could be
	 * written. 0 when there is none.
	 *
	 * @param int $invoice_id Invoice id.
	 * @return int
	 */
	public function product_by_marker( $invoice_id ) {
		if ( ! $this->available() || (int) $invoice_id <= 0 || ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		try {
			$ids = wc_get_products(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'status'     => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
					'meta_key'   => Invoices::PRODUCT_MARKER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => (string) (int) $invoice_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'orderby'    => 'ID',
					'order'      => 'ASC',
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
		return is_array( $ids ) && $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Products of the Invoices category, newest first — the list of
	 * invoices made by hand is read from here (the caller leaves out the
	 * ones this section created).
	 *
	 * @param string $category_slug The Invoices category slug.
	 * @param array  $args          search (text in the title), limit, page.
	 * @return array[] Product facts.
	 */
	public function invoice_products( $category_slug, array $args = array() ) {
		if ( ! $this->available() || ! function_exists( 'wc_get_products' ) || ! $this->category( $category_slug ) ) {
			return array();
		}

		$query = array(
			'limit'    => max( 1, min( 500, (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 ) ) ),
			'page'     => max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) ),
			'orderby'  => 'date',
			'order'    => 'DESC',
			'status'   => self::PICK_STATUSES,
			'category' => array( (string) $category_slug ),
			'return'   => 'objects',
		);
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$query['s'] = $search;
		}

		try {
			$products = wc_get_products( $query );
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $products ) ? $products : array() as $product ) {
			if ( $product instanceof \WC_Product ) {
				$out[] = $this->product_facts( $product );
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Fish to pick: shop products and their variations               */
	/* ============================================================== */

	/**
	 * The size of a variation: the value of the attribute whose name
	 * contains "size" (else of its first attribute), as slug and label,
	 * and every attribute value joined for the name.
	 *
	 * @param \WC_Product $variation Variation.
	 * @return array{slug: string|null, label: string|null, text: string}
	 */
	private function variation_size( $variation ) {
		$slug   = null;
		$label  = null;
		$labels = array();
		$first  = null;

		foreach ( (array) $variation->get_attributes() as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$name = $value;
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( (string) $key ) ) {
				$term = get_term_by( 'slug', $value, (string) $key );
				if ( $term && ! is_wp_error( $term ) ) {
					$name = (string) $term->name;
				}
			}
			$labels[] = $name;
			$pair     = array( Lines::slug( function_exists( 'sanitize_title' ) ? sanitize_title( $value ) : $value ), $name );
			if ( null === $first ) {
				$first = $pair;
			}
			if ( null === $slug && false !== stripos( (string) $key, 'size' ) ) {
				list( $slug, $label ) = $pair;
			}
		}

		if ( null === $slug && null !== $first ) {
			list( $slug, $label ) = $first;
		}

		return array(
			'slug'  => $slug,
			'label' => $label,
			'text'  => implode( ' / ', $labels ),
		);
	}

	/**
	 * What a product (or a variation) is right now, for a line that names
	 * it: whether it still exists, its price, and whether it tracks stock
	 * — only then can a line take from shop stock — with how many are left.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id (0 = none).
	 * @return array{exists: bool, id: int, name: string, status: string, price: float|null, regular_price: float|null, managed: bool, stock: int|null, backorders: bool, in_stock: bool, edit_url: string}
	 */
	public function pick_facts( $product_id, $variation_id = 0 ) {
		$none = array(
			'exists'        => false,
			'id'            => 0,
			'name'          => '',
			'status'        => '',
			'price'         => null,
			'regular_price' => null,
			'managed'       => false,
			'stock'         => null,
			'backorders'    => false,
			'in_stock'      => false,
			'edit_url'      => '',
		);

		$id      = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
		$product = $this->available() && $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return $none;
		}

		$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $id;
		$price     = $product->get_price();
		$regular   = $product->get_regular_price();
		$stock     = $product->get_stock_quantity();

		return array(
			'exists'        => true,
			'id'            => $id,
			'name'          => (string) $product->get_name(),
			'status'        => (string) get_post_status( $parent_id ),
			'price'         => '' === (string) $price ? null : Lines::money( (float) $price ),
			'regular_price' => '' === (string) $regular ? null : Lines::money( (float) $regular ),
			'managed'       => (bool) $product->managing_stock(),
			'stock'         => null === $stock ? null : (int) $stock,
			'backorders'    => (bool) $product->backorders_allowed(),
			'in_stock'      => (bool) $product->is_in_stock(),
			'edit_url'      => admin_url( 'post.php?post=' . $parent_id . '&action=edit' ),
		);
	}

	/**
	 * The price of a product for a size: the price of the variation whose
	 * size is that slug, else the price of a simple product, else nothing
	 * (a variable product has no price of its own).
	 *
	 * @param int         $product_id Product id.
	 * @param string|null $size_slug  Size slug.
	 * @return array{price: float|null, variation_id: int, from: string} from is variation|product|''.
	 */
	public function price_for( $product_id, $size_slug = null ) {
		$none    = array(
			'price'        => null,
			'variation_id' => 0,
			'from'         => '',
		);
		$product = $this->available() && (int) $product_id > 0 ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return $none;
		}

		if ( $product->is_type( 'variable' ) ) {
			if ( null === $size_slug || '' === (string) $size_slug ) {
				return $none;
			}
			foreach ( (array) $product->get_children() as $child_id ) {
				$variation = wc_get_product( (int) $child_id );
				if ( ! $variation instanceof \WC_Product || 'publish' !== $variation->get_status() ) {
					continue;
				}
				$size = $this->variation_size( $variation );
				if ( $size['slug'] === (string) $size_slug && '' !== (string) $variation->get_price() ) {
					return array(
						'price'        => Lines::money( (float) $variation->get_price() ),
						'variation_id' => (int) $child_id,
						'from'         => 'variation',
					);
				}
			}
			return $none;
		}

		if ( '' === (string) $product->get_price() ) {
			return $none;
		}

		return array(
			'price'        => Lines::money( (float) $product->get_price() ),
			'variation_id' => 0,
			'from'         => 'product',
		);
	}

	/**
	 * A product title or a term name as the text it stands for. WordPress
	 * stores "&" (and, for a user who may not post HTML, "<" and ">") as
	 * entities; taken as it is stored, "Okapi &amp; Stripe" would reach
	 * the customer as "&amp;" in the description.
	 *
	 * @param mixed $text Stored title or name.
	 * @return string
	 */
	public static function plain( $text ) {
		return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * One pickable row of a product object.
	 *
	 * @param \WC_Product $product   A simple product or a variation.
	 * @param string      $name      The name the line gets.
	 * @param int         $parent_id The product id (the parent of a variation).
	 * @param array|null  $size      variation_size() of a variation.
	 * @param string      $status    The (parent) product's status.
	 * @return array
	 */
	private function pick_row( $product, $name, $parent_id, $size, $status ) {
		$name    = self::plain( $name );
		$price   = (string) $product->get_price();
		$regular = (string) $product->get_regular_price();
		$stock   = $product->get_stock_quantity();
		$is_var  = $product->is_type( 'variation' );

		return array(
			'source'        => 'product',
			'name'          => $name,
			'product_id'    => (int) $parent_id,
			'variation_id'  => $is_var ? (int) $product->get_id() : 0,
			'species_id'    => 0,
			'stay_id'       => 0,
			'size_slug'     => $size ? $size['slug'] : null,
			'size_label'    => $size && null !== $size['label'] ? self::plain( $size['label'] ) : null,
			'price'         => '' === $price ? null : Lines::money( (float) $price ),
			'regular_price' => '' === $regular ? null : Lines::money( (float) $regular ),
			'price_from'    => '' === $price ? '' : ( $is_var ? 'variation' : 'product' ),
			'managed'       => (bool) $product->managing_stock(),
			'stock'         => null === $stock ? null : (int) $stock,
			'backorders'    => (bool) $product->backorders_allowed(),
			'in_stock'      => (bool) $product->is_in_stock(),
			'status'        => (string) $status,
		);
	}

	/**
	 * Shop products whose title (or SKU) holds every word of a search,
	 * each variable product as its variations — the picker's "shop
	 * products" group. Invoice products themselves are never offered.
	 *
	 * @param string $term          Search text.
	 * @param string $category_slug The Invoices category slug (to leave invoices out).
	 * @param int    $limit         How many products.
	 * @return array[] Rows of pick_row().
	 */
	public function search_products( $term, $category_slug, $limit = 8 ) {
		$term = trim( (string) $term );
		if ( '' === $term || ! $this->available() || ! class_exists( '\WC_Data_Store' ) ) {
			return array();
		}

		try {
			// WC_Data_Store hands the call on to the real store (its own class has no such method,
			// so method_exists() would say no); an unknown call simply answers null.
			$store = \WC_Data_Store::load( 'product' );
			$ids   = is_callable( array( $store, 'search_products' ) ) ? (array) $store->search_products( $term, '', false, true, 60 ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}

		$words  = array_filter( preg_split( '/\s+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term ) ), 'strlen' );
		$strong = array();
		$weak   = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$product = $id > 0 ? wc_get_product( $id ) : null;
			if ( ! $product instanceof \WC_Product || ! $product->is_type( array( 'simple', 'variable' ) ) ) {
				continue;
			}
			$status = (string) $product->get_status();
			if ( ! in_array( $status, self::PICK_STATUSES, true ) ) {
				continue;
			}

			$slugs = array();
			foreach ( (array) $product->get_category_ids() as $term_id ) {
				$category = get_term( (int) $term_id, 'product_cat' );
				if ( $category && ! is_wp_error( $category ) ) {
					$slugs[] = (string) $category->slug;
				}
			}
			if ( Grammar::classify_invoice( $slugs, $product->get_name(), $category_slug ) ) {
				continue;
			}

			$haystack = ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $product->get_name(), 'UTF-8' ) : strtolower( $product->get_name() ) ) . ' ' . strtolower( (string) $product->get_sku() );
			$in_title = true;
			foreach ( $words as $word ) {
				if ( false === strpos( $haystack, $word ) ) {
					$in_title = false;
					break;
				}
			}

			if ( $in_title ) {
				$strong[] = $product;
			} else {
				$weak[] = $product;
			}
		}

		// A match in the description only is shown when the titles gave nothing.
		$products = $strong ? $strong : $weak;
		$rows     = array();

		foreach ( array_slice( $products, 0, max( 1, (int) $limit ) ) as $product ) {
			$status = (string) $product->get_status();
			$title  = (string) $product->get_name();
			$id     = (int) $product->get_id();

			if ( ! $product->is_type( 'variable' ) ) {
				$rows[] = $this->pick_row( $product, $title, $id, null, $status );
				continue;
			}

			foreach ( array_slice( (array) $product->get_children(), 0, 30 ) as $child_id ) {
				$variation = wc_get_product( (int) $child_id );
				if ( ! $variation instanceof \WC_Product || 'publish' !== $variation->get_status() ) {
					continue;
				}
				$size   = $this->variation_size( $variation );
				$rows[] = $this->pick_row( $variation, '' !== $size['text'] ? $title . ' (' . $size['text'] . ')' : $title, $id, $size, $status );
			}
		}

		return $rows;
	}

	/* ============================================================== */
	/*  Orders                                                         */
	/* ============================================================== */

	/**
	 * Whether an order is a fulfilment (combined-box) copy of other
	 * orders: it mirrors their lines and never pays for anything itself.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function is_mirror( $order ) {
		if ( class_exists( '\FisHotel_Fulfillment' ) && method_exists( '\FisHotel_Fulfillment', 'is_fulfillment' ) ) {
			try {
				return (bool) \FisHotel_Fulfillment::is_fulfillment( $order );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return '1' === (string) $order->get_meta( Invoices::MIRROR_META );
	}

	/**
	 * Whether an order is PAID: it has a paid date, or WooCommerce calls
	 * it paid. Never read from the status name.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function is_paid( $order ) {
		return (bool) $order->get_date_paid() || (bool) $order->is_paid();
	}

	/**
	 * The little the paid sync needs of an order — no query beyond the
	 * order itself: its id, status, whether it is paid, whether it is a
	 * fulfilment copy, and the products its lines point at (a line's own
	 * product and the invoice a line was made out of).
	 *
	 * @param int $order_id Order id.
	 * @return array{id: int, number: string, status: string, paid: bool, mirror: bool, product_ids: int[]}|null
	 */
	public function order_brief( $order_id ) {
		$order = $this->available() && (int) $order_id > 0 ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$ids = array();
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$from = (int) $item->get_meta( Invoices::ITEM_PRODUCT_META );
			if ( $from > 0 ) {
				$ids[ $from ] = $from;
			}
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() > 0 ) {
				$ids[ (int) $item->get_product_id() ] = (int) $item->get_product_id();
			}
		}

		return array(
			'id'          => (int) $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'status'      => (string) $order->get_status(),
			'paid'        => $this->is_paid( $order ),
			'mirror'      => $this->is_mirror( $order ),
			'product_ids' => array_values( $ids ),
		);
	}

	/**
	 * An order as plain facts, with its lines and what each has to do
	 * with an invoice. Null for an id that is not an order (a refund is
	 * not one).
	 *
	 * Each line: name, qty, total, type (line_item|fee), product_id,
	 * variation_id, invoice_product_id (the invoice it belongs to: the
	 * theme's item meta, or the line's own product when that is an
	 * invoice), invoice_title, is_fish (a line the theme made out of an
	 * invoice), is_credit, is_invoice (the invoice itself, kept as one
	 * line) and snapshot (the lines the theme stored on a kept line).
	 *
	 * @param int    $order_id      Order id.
	 * @param string $category_slug The Invoices category slug.
	 * @return array|null
	 */
	public function order( $order_id, $category_slug = Grammar::CATEGORY_SLUG ) {
		$order = $this->available() && (int) $order_id > 0 ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$lines       = array();
		$invoice_ids = array();

		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			$is_line    = $item instanceof \WC_Order_Item_Product;
			$product_id = $is_line ? (int) $item->get_product_id() : 0;
			$from       = (int) $item->get_meta( Invoices::ITEM_PRODUCT_META );
			$is_fish    = '' !== (string) $item->get_meta( Invoices::ITEM_FISH_META ) && '0' !== (string) $item->get_meta( Invoices::ITEM_FISH_META );
			$is_credit  = '' !== (string) $item->get_meta( Invoices::ITEM_CREDIT_META ) && '0' !== (string) $item->get_meta( Invoices::ITEM_CREDIT_META );
			$is_invoice = false;

			if ( $is_line && ! $is_fish && ! $is_credit ) {
				$slugs = array();
				if ( $product_id > 0 ) {
					$terms = get_the_terms( $product_id, 'product_cat' );
					foreach ( is_array( $terms ) ? $terms : array() as $term ) {
						$slugs[] = (string) $term->slug;
					}
				}
				$is_invoice = Grammar::classify_invoice( $slugs, $item->get_name(), $category_slug );
				if ( $is_invoice && $from <= 0 ) {
					$from = $product_id;
				}
			}

			if ( $from > 0 ) {
				$invoice_ids[ $from ] = $from;
			}

			$lines[] = array(
				'name'               => (string) $item->get_name(),
				'qty'                => $is_line ? max( 1, (int) $item->get_quantity() ) : 1,
				'total'              => round( (float) $item->get_total(), 2 ),
				'type'               => $is_line ? 'line_item' : 'fee',
				'product_id'         => $product_id,
				'variation_id'       => $is_line ? (int) $item->get_variation_id() : 0,
				'invoice_product_id' => $from,
				'invoice_title'      => (string) $item->get_meta( Invoices::ITEM_TITLE_META ),
				'is_fish'            => $is_fish,
				'is_credit'          => $is_credit,
				'is_invoice'         => $is_invoice,
				'snapshot'           => $is_invoice ? Grammar::lines_from_json( $item->get_meta( Invoices::ITEM_LINES_META ) ) : array(),
			);
		}

		$paid_date = $order->get_date_paid();
		$created   = $order->get_date_created();

		return array(
			'id'                  => (int) $order->get_id(),
			'number'              => (string) $order->get_order_number(),
			'status'              => (string) $order->get_status(),
			'status_label'        => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $order->get_status() ) : (string) $order->get_status(),
			'paid'                => $this->is_paid( $order ),
			'date_paid'           => $paid_date ? gmdate( 'Y-m-d H:i:s', $paid_date->getTimestamp() ) : '',
			'date_created'        => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '',
			'total'               => round( (float) $order->get_total(), 2 ),
			'payment_method'      => (string) $order->get_payment_method_title(),
			'customer_id'         => (int) $order->get_customer_id(),
			'billing_name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email'       => (string) $order->get_billing_email(),
			'mirror'              => $this->is_mirror( $order ),
			'edit_url'            => (string) $order->get_edit_order_url(),
			'lines'               => $lines,
			'invoice_product_ids' => array_values( $invoice_ids ),
		);
	}

	/**
	 * The orders of a customer, found the ways the owner looks by hand:
	 * by the linked site customer, by the billing e-mail, and by the name
	 * through WooCommerce's own order search (which reads the billing
	 * name). Newest first.
	 *
	 * @param array $who   customer_id, emails (list), name.
	 * @param int   $limit How many orders per way.
	 * @return array{ids: array<int, string[]>, notes: string[]} Order id → the ways it was found
	 *               (customer|email|name), and what could not be searched.
	 */
	public function customer_order_ids( array $who, $limit = 40 ) {
		$found = array();
		$notes = array();

		if ( ! $this->available() || ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'ids'   => array(),
				'notes' => array( __( 'WooCommerce is not active: no orders could be read.', 'fishotel-misc-plugin' ) ),
			);
		}

		$add = static function ( $ids, $way ) use ( &$found, $limit ) {
			$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );
			rsort( $ids );
			foreach ( array_slice( $ids, 0, (int) $limit ) as $id ) {
				if ( $id > 0 && ( ! isset( $found[ $id ] ) || ! in_array( $way, $found[ $id ], true ) ) ) {
					$found[ $id ][] = $way;
				}
			}
		};

		$base = array(
			'limit'   => (int) $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
			'type'    => 'shop_order',
		);

		try {
			if ( ! empty( $who['customer_id'] ) && (int) $who['customer_id'] > 0 ) {
				$add( wc_get_orders( array_merge( $base, array( 'customer_id' => (int) $who['customer_id'] ) ) ), 'customer' );
			}
			foreach ( isset( $who['emails'] ) && is_array( $who['emails'] ) ? array_unique( array_filter( array_map( 'strval', $who['emails'] ) ) ) : array() as $email ) {
				$add( wc_get_orders( array_merge( $base, array( 'billing_email' => $email ) ) ), 'email' );
			}
		} catch ( \Throwable $e ) {
			$notes[] = __( 'The orders by customer and e-mail could not be read.', 'fishotel-misc-plugin' );
		}

		$name = isset( $who['name'] ) ? trim( (string) $who['name'] ) : '';
		if ( strlen( $name ) >= 3 ) {
			if ( function_exists( 'wc_order_search' ) ) {
				try {
					$add( wc_order_search( $name ), 'name' );
				} catch ( \Throwable $e ) {
					$notes[] = __( 'The order search by name failed.', 'fishotel-misc-plugin' );
				}
			} else {
				$notes[] = __( 'The order search by name is not available in this WooCommerce.', 'fishotel-misc-plugin' );
			}
		}

		krsort( $found );

		return array(
			'ids'   => $found,
			'notes' => $notes,
		);
	}

	/* ============================================================== */
	/*  Customers                                                      */
	/* ============================================================== */

	/**
	 * A site customer as a small array.
	 *
	 * @param int $user_id User id.
	 * @return array{id: int, name: string, email: string, emails: string[], login: string}|null
	 */
	public function customer( $user_id ) {
		$user = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : false;
		if ( ! $user ) {
			return null;
		}

		$first   = (string) get_user_meta( $user->ID, 'billing_first_name', true );
		$last    = (string) get_user_meta( $user->ID, 'billing_last_name', true );
		$billing = (string) get_user_meta( $user->ID, 'billing_email', true );
		$name    = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		}
		if ( '' === $name ) {
			$name = (string) $user->display_name;
		}

		$emails = array_values( array_unique( array_filter( array( $billing, (string) $user->user_email ) ) ) );

		return array(
			'id'     => (int) $user->ID,
			'name'   => $name,
			'email'  => $emails ? $emails[0] : '',
			'emails' => $emails,
			'login'  => (string) $user->user_login,
		);
	}

	/**
	 * Site customers found by name or e-mail (WooCommerce's own customer
	 * search).
	 *
	 * @param string $term  Search text.
	 * @param int    $limit Row cap.
	 * @return array[] Rows of customer().
	 */
	public function search_customers( $term, $limit = 8 ) {
		$term = trim( (string) $term );
		if ( strlen( $term ) < 2 ) {
			return array();
		}

		$ids = array();
		try {
			if ( class_exists( '\WC_Data_Store' ) ) {
				$store = \WC_Data_Store::load( 'customer' );
				if ( is_callable( array( $store, 'search_customers' ) ) ) {
					$ids = (array) $store->search_customers( $term, (int) $limit );
				}
			}
			if ( ! $ids && function_exists( 'get_users' ) ) {
				$ids = get_users(
					array(
						'search'         => '*' . $term . '*',
						'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
						'number'         => (int) $limit,
						'fields'         => 'ID',
					)
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( array_slice( array_unique( array_map( 'intval', (array) $ids ) ), 0, (int) $limit ) as $id ) {
			$customer = $this->customer( $id );
			if ( $customer ) {
				$out[] = $customer;
			}
		}
		return $out;
	}
}
