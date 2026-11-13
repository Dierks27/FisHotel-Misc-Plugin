<?php
/**
 * The pre-check — "read before you touch", done for the owner before
 * every Publish and never skippable (Publisher takes no invoice live
 * without a token this class's report was issued with).
 *
 * For the customer of the invoice being built it gathers:
 *
 *   orders    every order found by the linked site customer, by the
 *             billing e-mail, by the name (WooCommerce's own order
 *             search) and by a line named "<handle> Invoice" — also the
 *             lines the theme made out of such an invoice — each with its
 *             status and whether it is PAID. Paid is the order's paid
 *             date / WooCommerce's is_paid(), never the status name: a
 *             Processing order may be paid, an On hold one is not.
 *   invoices  the customer's existing invoices: the ones this section
 *             built (same handle, same site customer or same e-mail),
 *             the ones made by hand (products of the Invoices category
 *             titled for the handle) and the ones only an order still
 *             remembers — each with its lines and paid or not.
 *   duplicates  every fish of the new invoice that already appears on
 *             one of those (Rules::duplicates()). Publish asks for each.
 *
 * and says what will be written (title, description, price, category,
 * shipping class, image), what is wrong (errors stop Publish) and what
 * is worth a look (warnings do not).
 *
 * It only reads — apart from bringing a stored state up to date when it
 * finds one behind the truth (Sync::reconcile()).
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Precheck
 */
class Precheck {

	const ORDER_LIMIT = 40;

	/**
	 * The store (or its in-memory twin).
	 *
	 * @var object
	 */
	private $store;

	/**
	 * The shop (or twin).
	 *
	 * @var object
	 */
	private $shop;

	/**
	 * The tanks (or twin).
	 *
	 * @var object
	 */
	private $tanks;

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Orders read in this request: id → facts or null.
	 *
	 * @var array
	 */
	private $orders = array();

	/**
	 * Order ids per product, read ahead by prime(): product id → ids, or
	 * null when the read failed.
	 *
	 * @var array
	 */
	private $order_ids = array();

	/**
	 * Constructor.
	 *
	 * @param object $store    Store.
	 * @param object $shop     Shop.
	 * @param object $tanks    Tanks.
	 * @param array  $settings Settings.
	 */
	public function __construct( $store, $shop, $tanks, array $settings ) {
		$this->store    = $store;
		$this->shop     = $shop;
		$this->tanks    = $tanks;
		$this->settings = $settings;
	}

	/* ============================================================== */
	/*  Facts                                                          */
	/* ============================================================== */

	/**
	 * One order, read once.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public function order( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $order_id, $this->orders ) ) {
			$this->orders[ $order_id ] = $this->shop->order( $order_id, $this->settings['category_slug'] );
		}
		return $this->orders[ $order_id ];
	}

	/**
	 * Read the "which orders contain this product" answer for several
	 * products in one go (a list page asks for thirty).
	 *
	 * @param int[] $product_ids Product ids.
	 */
	public function prime( array $product_ids ) {
		$ids = array();
		foreach ( $product_ids as $id ) {
			if ( (int) $id > 0 && ! array_key_exists( (int) $id, $this->order_ids ) ) {
				$ids[] = (int) $id;
			}
		}
		if ( ! $ids ) {
			return;
		}

		$map = $this->store->order_ids_for_products( $ids );
		foreach ( $ids as $id ) {
			$this->order_ids[ $id ] = null === $map ? null : ( isset( $map[ $id ] ) ? $map[ $id ] : array() );
		}
	}

	/**
	 * The orders that contain a product (fulfilment copies left out),
	 * plus any other order named — the theme's lock order, the order the
	 * invoice is stored as paid by. Null when the order lines could not
	 * be read.
	 *
	 * @param int   $product_id Product id.
	 * @param int[] $also       Other order ids to read with them.
	 * @return array[]|null
	 */
	public function orders_of_product( $product_id, array $also = array() ) {
		$product_id = (int) $product_id;
		$this->prime( array( $product_id ) );
		if ( null === $this->order_ids[ $product_id ] ) {
			return null;
		}

		$ids = $this->order_ids[ $product_id ];
		foreach ( $also as $id ) {
			if ( (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}

		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$order = $this->order( $id );
			if ( $order && empty( $order['mirror'] ) ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * Everything a decision about one stored invoice needs: its product,
	 * the orders that contain it, and what follows (Rules::effective()).
	 *
	 * @param array $invoice The stored invoice.
	 * @return array{product: array|null, orders: array[]|null, effective: array}
	 */
	public function facts( array $invoice ) {
		$product = ! empty( $invoice['product_id'] ) ? $this->shop->product( (int) $invoice['product_id'] ) : null;
		$orders  = array();
		if ( $product ) {
			$orders = $this->orders_of_product( $product['id'], array( $product['lock_order_id'], (int) $invoice['order_id'] ) );
		}

		return array(
			'product'   => $product,
			'orders'    => $orders,
			'effective' => Rules::effective( $invoice, $product, $orders ),
		);
	}

	/**
	 * Whether a product belongs to an invoice of this section: it carries
	 * the mark of an invoice that exists, and it IS that invoice's product
	 * — or the invoice is a draft without one yet (a Publish that stopped
	 * half-way left the product behind, and it must then not be taken for
	 * an invoice made by hand).
	 *
	 * A product that carries the mark of an invoice whose product is
	 * another one is a copy (WooCommerce's "Duplicate"): not ours. It is
	 * listed, and pre-checked, as an invoice made by hand.
	 *
	 * @param array $product Shop::product() facts.
	 * @return bool
	 */
	public function is_ours( array $product ) {
		$marker  = (int) ( isset( $product['marker'] ) ? $product['marker'] : 0 );
		$invoice = $marker > 0 ? $this->store->invoice( $marker ) : null;
		if ( ! $invoice ) {
			return false;
		}

		$own = (int) $invoice['product_id'];
		return $own > 0 ? $own === (int) ( isset( $product['id'] ) ? $product['id'] : 0 ) : 'draft' === $invoice['state'];
	}

	/**
	 * Whether a hand-made invoice product is paid, and by which order.
	 *
	 * @param array $product Shop::product() facts.
	 * @return array{known: bool, paid: bool, order: array|null, orders: array[]}
	 */
	public function hand_facts( array $product ) {
		$orders = $this->orders_of_product( $product['id'], array( $product['lock_order_id'] ) );
		if ( null === $orders ) {
			return array(
				'known'  => false,
				'paid'   => false,
				'order'  => null,
				'orders' => array(),
			);
		}

		$paying = Rules::paying_order( $product['lock_order_id'], $orders );

		return array(
			'known'  => true,
			'paid'   => $paying > 0,
			'order'  => $paying > 0 ? Rules::order_by_id( $orders, $paying ) : null,
			'orders' => $orders,
		);
	}

	/* ============================================================== */
	/*  What will be written                                           */
	/* ============================================================== */

	/**
	 * What Publish would write for an invoice, and what stands in its way.
	 *
	 * @param array   $invoice Invoice fields (handle, shipping, shipping_slug, title).
	 * @param array[] $lines   Its lines, with the discount line when there is one.
	 * @param string  $today   The site date (Y-m-d) — the date in a new title.
	 * @param string  $mode    publish|update. An update keeps the title it was published under.
	 * @return array{title: string, excerpt: string, description: string, lines_json: string, regular_price: string, total: float, category: array|null, shipping_slug: string, shipping_class: array|null, image_id: int, image: array|null, errors: string[], warnings: string[]}
	 */
	public function writes( array $invoice, array $lines, $today, $mode = 'publish' ) {
		$settings = $this->settings;
		$errors   = array();
		$warnings = array();
		$handle   = (string) ( isset( $invoice['handle'] ) ? $invoice['handle'] : '' );
		$total    = Lines::total( $lines );
		$title    = 'update' === $mode && '' !== (string) ( isset( $invoice['title'] ) ? $invoice['title'] : '' )
			? (string) $invoice['title']
			: Format::title( $settings['title_format'], $handle, $today );

		if ( ! $this->shop->available() ) {
			$errors[] = __( 'WooCommerce is not active. An invoice is a WooCommerce product, so nothing can be published.', 'fishotel-misc-plugin' );
		}

		if ( $this->shop->available() && ! $this->shop->stock_managed() ) {
			$errors[] = __( 'WooCommerce\'s "Enable stock management" (WooCommerce → Settings → Products → Inventory) is switched off. Without it the invoice\'s "stock 1" is not enforced, and the same invoice could be paid twice. Switch it on first.', 'fishotel-misc-plugin' );
		}

		$category = $this->shop->category( $settings['category_slug'] );
		if ( ! $category && $this->shop->available() ) {
			/* translators: %s: category slug */
			$errors[] = sprintf( __( 'The product category "%s" does not exist. Create it under Products → Categories, or name the right slug in Settings.', 'fishotel-misc-plugin' ), $settings['category_slug'] );
		}

		$choice = (string) ( isset( $invoice['shipping'] ) ? $invoice['shipping'] : 'premium' );
		$slug   = Settings::shipping_slug( $settings, $choice, isset( $invoice['shipping_slug'] ) ? $invoice['shipping_slug'] : '' );
		$class  = '' !== $slug ? $this->shop->shipping_class( $slug ) : null;
		if ( ! $class && $this->shop->available() ) {
			if ( 'other' === $choice ) {
				$errors[] = __( 'Choose the shipping class of this invoice.', 'fishotel-misc-plugin' );
			} elseif ( '' === $slug ) {
				/* translators: %s: Premium / Free */
				$errors[] = sprintf( __( 'No shipping class is set for "%s" yet. Choose it in Settings.', 'fishotel-misc-plugin' ), Settings::shipping_label( $choice ) );
			} else {
				/* translators: 1: Premium / Free, 2: class slug */
				$errors[] = sprintf( __( '"%1$s" stands for the shipping class "%2$s", which does not exist on this site. Choose the class in Settings.', 'fishotel-misc-plugin' ), Settings::shipping_label( $choice ), $slug );
			}
		}

		$image_id = Format::image_for_total( $total, $settings['image_threshold'], $settings['image_under'], $settings['image_over'] );
		$image    = $image_id > 0 ? $this->shop->attachment( $image_id ) : null;
		if ( $image_id > 0 && ! $image ) {
			/* translators: %d: attachment id */
			$warnings[] = sprintf( __( 'Image #%d (Settings) is not in the media library any more: the invoice gets no image.', 'fishotel-misc-plugin' ), $image_id );
			$image_id   = 0;
		} elseif ( 0 === $image_id && 'update' !== $mode ) {
			$warnings[] = __( 'No image is set in Settings for this total: the invoice gets no image.', 'fishotel-misc-plugin' );
		}

		return array(
			'title'          => $title,
			'excerpt'        => Format::excerpt( $settings['excerpt_format'], $handle ),
			'description'    => Lines::description_html( $lines ),
			'lines_json'     => Lines::theme_json( $lines ),
			'regular_price'  => Lines::price_string( $total ),
			'total'          => $total,
			'category'       => $category,
			'shipping_slug'  => $slug,
			'shipping_class' => $class,
			'image_id'       => $image_id,
			'image'          => $image,
			'errors'         => $errors,
			'warnings'       => $warnings,
		);
	}

	/* ============================================================== */
	/*  The report                                                     */
	/* ============================================================== */

	/**
	 * A line of a stored invoice (it has a unit_price) or of a hand-made
	 * one (a Grammar row: it has a price), as the report lists it.
	 *
	 * @param array $line A stored line, or a Grammar row.
	 * @return array
	 */
	private static function listed_line( array $line ) {
		$stored = array_key_exists( 'unit_price', $line );
		$price  = null;
		if ( $stored ) {
			$price = null !== $line['unit_price'] ? (float) $line['unit_price'] : null;
		} elseif ( isset( $line['price'] ) && null !== $line['price'] ) {
			$price = (float) $line['price'];
		}

		$out = array(
			'kind'         => (string) ( isset( $line['kind'] ) ? $line['kind'] : 'fish' ),
			'name'         => (string) ( isset( $line['name'] ) ? $line['name'] : '' ),
			'qty'          => max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ),
			'unit_price'   => $price,
			'product_id'   => (int) ( isset( $line['product_id'] ) ? $line['product_id'] : 0 ),
			'variation_id' => (int) ( isset( $line['variation_id'] ) ? $line['variation_id'] : 0 ),
			'species_id'   => (int) ( isset( $line['species_id'] ) ? $line['species_id'] : 0 ),
			'stay_id'      => (int) ( isset( $line['stay_id'] ) ? $line['stay_id'] : 0 ),
		);

		$out['text'] = $stored ? Lines::line_text( $out ) : Grammar::line_text( $line );

		return $out;
	}

	/**
	 * The little of an order an invoice entry shows.
	 *
	 * @param array|null $order Order facts.
	 * @return array|null
	 */
	private static function order_brief( $order ) {
		if ( ! is_array( $order ) ) {
			return null;
		}
		return array(
			'id'       => (int) $order['id'],
			'number'   => (string) $order['number'],
			'label'    => Rules::order_label( $order ),
			'paid'     => ! empty( $order['paid'] ),
			'edit_url' => (string) ( isset( $order['edit_url'] ) ? $order['edit_url'] : '' ),
		);
	}

	/**
	 * The customer's invoices this section built.
	 *
	 * @param array $invoice The invoice being built.
	 * @param array $known   Filled with the product ids listed (id → true).
	 * @return array[]
	 */
	private function section_invoices( array $invoice, array &$known ) {
		$key  = Rules::handle_key( isset( $invoice['handle'] ) ? $invoice['handle'] : '' );
		$rows = $this->store->invoices_for_customer(
			array(
				'handle_key'     => $key,
				'customer_id'    => isset( $invoice['customer_id'] ) ? (int) $invoice['customer_id'] : 0,
				'customer_email' => isset( $invoice['customer_email'] ) ? (string) $invoice['customer_email'] : '',
			),
			(int) ( isset( $invoice['id'] ) ? $invoice['id'] : 0 )
		);
		if ( ! $rows ) {
			return array();
		}

		$this->prime( array_filter( array_column( $rows, 'product_id' ) ) );
		$lines = $this->store->lines_for_invoices( array_column( $rows, 'id' ) );
		$out   = array();

		foreach ( $rows as $row ) {
			$facts = $this->facts( $row );
			$row   = Sync::reconcile( $this->store, $this->shop, $row, $facts['effective'] );
			$state = (string) $facts['effective']['state'];
			$label = Rules::state_label( $state );

			if ( 'paid' === $state && $facts['effective']['order'] ) {
				$label .= ' ' . Lines::DASH . ' ' . Rules::order_label( $facts['effective']['order'] );
			} elseif ( 'published' === $state && $facts['effective']['live_orders'] ) {
				/* translators: %s: order number and status */
				$label .= ' ' . Lines::DASH . ' ' . sprintf( __( 'in order %s', 'fishotel-misc-plugin' ), Rules::order_label( $facts['effective']['live_orders'][0] ) );
			}

			if ( ! empty( $row['product_id'] ) ) {
				$known[ (int) $row['product_id'] ] = true;
			}

			$out[] = array(
				'ref'         => 'invoice:' . (int) $row['id'],
				'kind'        => 'section',
				'id'          => (int) $row['id'],
				'product_id'  => (int) $row['product_id'],
				/* translators: 1: invoice id, 2: handle */
				'label'       => '' !== $row['title'] ? $row['title'] : sprintf( __( 'Draft #%1$d for %2$s', 'fishotel-misc-plugin' ), (int) $row['id'], $row['handle'] ),
				'state'       => $state,
				'state_label' => $label,
				'paid'        => 'paid' === $state,
				'order'       => self::order_brief( $facts['effective']['order'] ),
				'total'       => (float) $row['total'],
				'url'         => $facts['product'] && 'trash' !== $facts['product']['status'] ? $facts['product']['permalink'] : '',
				'edit_url'    => $facts['product'] ? $facts['product']['edit_url'] : '',
				'lines'       => array_map( array( self::class, 'listed_line' ), isset( $lines[ $row['id'] ] ) ? $lines[ $row['id'] ] : array() ),
				'counted'     => in_array( $state, array( 'draft', 'published', 'paid' ), true ),
				'by_handle'   => $row['handle_key'] === $key,
			);
		}

		return $out;
	}

	/**
	 * The customer's invoices made by hand: products of the Invoices
	 * category titled for the handle that this section did not create.
	 *
	 * @param array $invoice The invoice being built.
	 * @param array $known   Product ids already listed (id → true); filled further.
	 * @return array[]
	 */
	private function hand_invoices( array $invoice, array &$known ) {
		$handle = (string) ( isset( $invoice['handle'] ) ? $invoice['handle'] : '' );
		$format = $this->settings['title_format'];
		if ( '' === Rules::handle_key( $handle ) || ! $this->shop->available() ) {
			return array();
		}

		// A handle with odd characters is a poor search text: look by the word after it, match here.
		$plain    = (bool) preg_match( '/^[\p{L}\p{N} _.\-]+$/u', $handle );
		$products = $this->shop->invoice_products(
			$this->settings['category_slug'],
			array(
				'search' => $plain ? $handle : Rules::title_word( $format ),
				'limit'  => $plain ? 100 : 400,
			)
		);

		$mine = array();
		foreach ( $products as $product ) {
			if ( isset( $known[ (int) $product['id'] ] ) || ! Rules::title_is_for_handle( $product['name'], $handle, $format ) || $this->is_ours( $product ) ) {
				continue;
			}
			$mine[] = $product;
		}
		if ( ! $mine ) {
			return array();
		}

		$this->prime( array_column( $mine, 'id' ) );
		$out = array();

		foreach ( $mine as $product ) {
			$paid  = $this->hand_facts( $product );
			$label = __( 'Made by hand', 'fishotel-misc-plugin' ) . ' ' . Lines::DASH . ' ';
			if ( ! $paid['known'] ) {
				$label .= __( 'paid or not: unknown (the orders could not be read)', 'fishotel-misc-plugin' );
			} elseif ( $paid['paid'] ) {
				/* translators: %s: order number and status */
				$label .= sprintf( __( 'PAID on order %s', 'fishotel-misc-plugin' ), Rules::order_label( $paid['order'] ) );
			} else {
				$label .= __( 'not paid', 'fishotel-misc-plugin' );
			}

			$known[ (int) $product['id'] ] = true;

			$out[] = array(
				'ref'         => 'product:' . (int) $product['id'],
				'kind'        => 'hand',
				'id'          => 0,
				'product_id'  => (int) $product['id'],
				'label'       => $product['name'],
				'state'       => $paid['paid'] ? 'paid' : 'published',
				'state_label' => $label,
				'paid'        => $paid['paid'],
				'order'       => self::order_brief( $paid['order'] ),
				'total'       => '' !== $product['regular_price'] ? (float) $product['regular_price'] : null,
				'url'         => $product['permalink'],
				'edit_url'    => $product['edit_url'],
				'lines'       => array_map( array( self::class, 'listed_line' ), Grammar::product_lines( $product['lines_json'], $product['description'] ) ),
				'counted'     => true,
				'by_handle'   => true,
			);
		}

		return $out;
	}

	/**
	 * Invoices only an order still remembers: lines made out of an
	 * invoice (or an invoice kept as one line) whose product is on none
	 * of the lists above.
	 *
	 * @param array[] $orders The customer's orders.
	 * @param array   $known  Product ids already listed (id → true).
	 * @return array[]
	 */
	private static function order_invoices( array $orders, array $known ) {
		$out = array();

		foreach ( $orders as $order ) {
			$groups = array();
			foreach ( $order['lines'] as $line ) {
				$product_id = (int) $line['invoice_product_id'];
				if ( $product_id <= 0 || isset( $known[ $product_id ] ) ) {
					continue;
				}
				if ( ! isset( $groups[ $product_id ] ) ) {
					$groups[ $product_id ] = array(
						'title' => '',
						'lines' => array(),
					);
				}
				if ( '' === $groups[ $product_id ]['title'] ) {
					$groups[ $product_id ]['title'] = '' !== $line['invoice_title'] ? $line['invoice_title'] : ( $line['is_invoice'] ? $line['name'] : '' );
				}

				if ( $line['is_invoice'] ) {
					foreach ( $line['snapshot'] as $row ) {
						$groups[ $product_id ]['lines'][] = self::listed_line( $row );
					}
				} else {
					$groups[ $product_id ]['lines'][] = self::listed_line(
						array(
							'kind'         => $line['is_credit'] ? 'credit' : 'fish',
							'name'         => $line['is_credit'] ? (string) preg_replace( '/^credit\b[\s:]*/iu', '', $line['name'] ) : $line['name'],
							'qty'          => $line['qty'],
							'unit_price'   => $line['is_credit'] ? $line['total'] : ( $line['qty'] > 0 ? round( $line['total'] / $line['qty'], 2 ) : null ),
							'product_id'   => $line['is_credit'] ? 0 : $line['product_id'],
							'variation_id' => $line['is_credit'] ? 0 : $line['variation_id'],
						)
					);
				}
			}

			$dead = Rules::order_is_dead( $order );
			$paid = ! $dead && ! empty( $order['paid'] );

			foreach ( $groups as $product_id => $group ) {
				/* translators: %d: product id */
				$title = '' !== $group['title'] ? $group['title'] : sprintf( __( 'Invoice #%d', 'fishotel-misc-plugin' ), $product_id );
				$out[] = array(
					'ref'         => 'order:' . (int) $order['id'] . ':' . (int) $product_id,
					'kind'        => 'order',
					'id'          => 0,
					'product_id'  => (int) $product_id,
					'label'       => $title,
					'state'       => $paid ? 'paid' : 'published',
					/* translators: 1: order number and status, 2: PAID / not paid */
					'state_label' => sprintf( __( 'On order %1$s %2$s %3$s', 'fishotel-misc-plugin' ), Rules::order_label( $order ), Lines::DASH, $paid ? __( 'PAID', 'fishotel-misc-plugin' ) : __( 'not paid', 'fishotel-misc-plugin' ) ),
					'paid'        => $paid,
					'order'       => self::order_brief( $order ),
					'total'       => null,
					'url'         => '',
					'edit_url'    => '',
					'lines'       => $group['lines'],
					'counted'     => ! $dead,
					'by_handle'   => false,
				);
			}
		}

		return $out;
	}

	/**
	 * What is worth knowing about each line right now: shop stock below
	 * the quantity on a line that takes from it, a product that is gone
	 * or does not track stock, a tank group with fewer fish alive than
	 * the invoice (and the other open invoices) ask of it.
	 *
	 * @param array   $invoice The invoice being built.
	 * @param array[] $lines   Its lines.
	 * @return array<int, string[]> Line position → notes.
	 */
	public function line_notes( array $invoice, array $lines ) {
		$notes = array();
		$stays = array();
		foreach ( $lines as $line ) {
			if ( 'fish' === $line['kind'] && 'tank' === $line['source'] && ! empty( $line['stay_id'] ) ) {
				$stays[] = (int) $line['stay_id'];
			}
		}
		$reserved = $stays ? $this->store->stay_reservations( $stays, (int) ( isset( $invoice['id'] ) ? $invoice['id'] : 0 ) ) : array();

		foreach ( array_values( $lines ) as $index => $line ) {
			if ( 'fish' !== $line['kind'] ) {
				continue;
			}
			$qty = (int) $line['qty'];

			if ( 'product' === $line['source'] && ! empty( $line['product_id'] ) ) {
				$pick = $this->shop->pick_facts( (int) $line['product_id'], (int) $line['variation_id'] );
				if ( ! $pick['exists'] ) {
					$notes[ $index ][] = __( 'This shop product no longer exists: the line stays, and nothing is taken from shop stock.', 'fishotel-misc-plugin' );
				} elseif ( ! empty( $line['take_stock'] ) && ! $pick['managed'] ) {
					$notes[ $index ][] = __( 'This product does not track stock, so nothing is taken from shop stock.', 'fishotel-misc-plugin' );
				} elseif ( ! empty( $line['take_stock'] ) && (int) $pick['stock'] < $qty ) {
					/* translators: 1: stock quantity, 2: quantity on the invoice */
					$notes[ $index ][] = sprintf( __( 'Only %1$d in shop stock, %2$d on this invoice.', 'fishotel-misc-plugin' ), (int) $pick['stock'], $qty );
				}
			}

			if ( 'tank' === $line['source'] && ! empty( $line['stay_id'] ) && $this->tanks->available() ) {
				$group = $this->tanks->group_by_id( (int) $line['stay_id'] );
				$other = isset( $reserved[ (int) $line['stay_id'] ] ) ? (int) $reserved[ (int) $line['stay_id'] ] : 0;
				if ( ! $group ) {
					$notes[ $index ][] = __( 'This tank group no longer exists in the Clean Room.', 'fishotel-misc-plugin' );
				} elseif ( ! $group['open'] || $group['alive'] <= 0 ) {
					$notes[ $index ][] = __( 'Nothing is alive in this tank group any more.', 'fishotel-misc-plugin' );
				} elseif ( $group['alive'] < $qty ) {
					/* translators: 1: fish alive, 2: quantity on the invoice */
					$notes[ $index ][] = sprintf( __( 'Only %1$d alive in this tank group, %2$d on this invoice.', 'fishotel-misc-plugin' ), $group['alive'], $qty );
				} elseif ( $group['alive'] < $qty + $other ) {
					/* translators: 1: fish alive, 2: fish on other open invoices, 3: quantity on the invoice */
					$notes[ $index ][] = sprintf( __( '%1$d alive in this tank group, %2$d already on other open invoices, %3$d on this one.', 'fishotel-misc-plugin' ), $group['alive'], $other, $qty );
				}
			}
		}

		return $notes;
	}

	/**
	 * The whole pre-check of one invoice.
	 *
	 * @param array   $invoice The invoice being built: id, handle, forum_user_id, customer_id,
	 *                         customer_name, customer_email, shipping, shipping_slug, title.
	 * @param array[] $lines   Its lines, with the discount line when there is one.
	 * @param string  $today   The site date (Y-m-d).
	 * @param string  $mode    publish|update.
	 * @return array{mode: string, customer: array, orders: array[], order_notes: string[], invoices: array[], duplicates: array[], dup_keys: string[], line_notes: array, line_errors: array, errors: string[], warnings: string[], writes: array}
	 */
	public function report( array $invoice, array $lines, $today, $mode = 'publish' ) {
		$handle   = (string) ( isset( $invoice['handle'] ) ? $invoice['handle'] : '' );
		$customer = ! empty( $invoice['customer_id'] ) ? $this->shop->customer( (int) $invoice['customer_id'] ) : null;
		$name     = trim( (string) ( isset( $invoice['customer_name'] ) ? $invoice['customer_name'] : '' ) );
		$email    = trim( (string) ( isset( $invoice['customer_email'] ) ? $invoice['customer_email'] : '' ) );
		$emails   = array_values( array_unique( array_filter( array_merge( array( $email ), $customer ? $customer['emails'] : array() ) ) ) );
		if ( '' === $name && $customer ) {
			$name = $customer['name'];
		}

		// The customer's invoices.
		$known = array();
		if ( ! empty( $invoice['product_id'] ) ) {
			$known[ (int) $invoice['product_id'] ] = true;
		}
		$invoices = $this->section_invoices( $invoice, $known );
		$invoices = array_merge( $invoices, $this->hand_invoices( $invoice, $known ) );

		// The customer's orders.
		$found = $this->shop->customer_order_ids(
			array(
				'customer_id' => $customer ? $customer['id'] : 0,
				'emails'      => $emails,
				'name'        => $name,
			),
			self::ORDER_LIMIT
		);
		$ids   = $found['ids'];
		$notes = $found['notes'];

		$by_title = $this->store->order_ids_for_invoice_title( Rules::title_start( $handle, $this->settings['title_format'] ) );
		if ( null === $by_title ) {
			/* translators: %s: handle */
			$notes[] = sprintf( __( 'The order lines could not be searched for "%s Invoice".', 'fishotel-misc-plugin' ), $handle );
		}
		foreach ( is_array( $by_title ) ? $by_title : array() as $id ) {
			$ids[ (int) $id ][] = 'invoice';
		}

		// The orders that contain one of the invoices listed above.
		foreach ( array_keys( $known ) as $product_id ) {
			if ( ! empty( $invoice['product_id'] ) && (int) $invoice['product_id'] === (int) $product_id ) {
				continue;
			}
			foreach ( isset( $this->order_ids[ $product_id ] ) && is_array( $this->order_ids[ $product_id ] ) ? $this->order_ids[ $product_id ] : array() as $id ) {
				$ids[ (int) $id ][] = 'invoice';
			}
		}

		if ( ! $customer && '' === $email && '' === $name ) {
			$notes[] = __( 'Only the handle is known, so the orders were searched for a line named after its invoices only. Link the site customer, or type the name or e-mail, and the orders are searched by those too.', 'fishotel-misc-plugin' );
		} elseif ( '' === $name ) {
			$notes[] = __( 'No name is known, so the orders were not searched by name.', 'fishotel-misc-plugin' );
		}

		krsort( $ids );
		$orders = array();
		foreach ( $ids as $id => $ways ) {
			$order = $this->order( $id );
			if ( ! $order || ! empty( $order['mirror'] ) ) {
				continue;
			}
			$order['found_by'] = array_values( array_unique( $ways ) );
			$orders[]          = $order;
			if ( count( $orders ) >= self::ORDER_LIMIT ) {
				/* translators: %d: orders */
				$notes[] = sprintf( __( 'Only the newest %d orders are listed.', 'fishotel-misc-plugin' ), self::ORDER_LIMIT );
				break;
			}
		}

		$invoices = array_merge( $invoices, self::order_invoices( $orders, $known ) );

		// A fish on two invoices.
		$counted = array();
		foreach ( $invoices as $entry ) {
			if ( $entry['counted'] ) {
				$counted[] = $entry;
			}
		}
		$duplicates = Rules::duplicates( $lines, $counted );

		// Is it fit to publish, and what will be written?
		$valid    = Rules::validate( $invoice, $lines );
		$writes   = $this->writes( $invoice, $lines, $today, $mode );
		$errors   = array_merge( $valid['errors'], $writes['errors'] );
		$warnings = array_merge( $valid['warnings'], $writes['warnings'] );

		$unpaid = 0;
		foreach ( $invoices as $entry ) {
			if ( 'published' === $entry['state'] && $entry['counted'] ) {
				$unpaid++;
			}
		}
		if ( $unpaid > 0 ) {
			/* translators: %d: invoices */
			$warnings[] = sprintf( _n( 'This customer has %d other invoice that is not paid yet.', 'This customer has %d other invoices that are not paid yet.', $unpaid, 'fishotel-misc-plugin' ), $unpaid );
		}

		return array(
			'mode'        => 'update' === $mode ? 'update' : 'publish',
			'customer'    => array(
				'handle'   => $handle,
				'name'     => $name,
				'email'    => $email,
				'customer' => $customer,
			),
			'orders'      => $orders,
			'order_notes' => array_values( array_unique( $notes ) ),
			'invoices'    => $invoices,
			'duplicates'  => $duplicates,
			'dup_keys'    => Rules::dup_keys( $duplicates ),
			'line_notes'  => $this->line_notes( $invoice, $lines ),
			'line_errors' => $valid['lines'],
			'errors'      => array_values( array_unique( $errors ) ),
			'warnings'    => array_values( array_unique( $warnings ) ),
			'writes'      => $writes,
		);
	}
}
