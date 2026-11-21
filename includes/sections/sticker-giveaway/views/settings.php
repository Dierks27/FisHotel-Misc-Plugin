<?php
/**
 * Sticker Giveaway settings page view.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 *
 * @var array                    $settings   Active settings.
 * @var array<int, \WC_Product>  $picked     The products picked in the search
 *                                           (simple products and variable ones).
 * @var array                    $categories [{term, depth}] product_cat tree.
 * @var string                   $summary    Live summary line.
 * @var array                    $fragments  The parts a save or a stock act redraws:
 *                                           variations, stock, warnings (HTML).
 */

defined( 'ABSPATH' ) || exit;

$eligible_slugs = \FisHotel\Misc\Sections\Sticker_Giveaway\Rules::eligible_slugs( $settings );
?>

<div class="wrap fishotel-misc-wrap fh-sg-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Sticker Giveaway', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<div class="fh-sg-summary" id="fh-sg-summary" aria-live="polite">
		<span class="dashicons dashicons-tag"></span>
		<span class="fh-sg-summary__text"><?php echo esc_html( $summary ); ?></span>
	</div>

	<form id="fishotel-sticker-giveaway-form"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		onsubmit="return false;">

		<?php wp_nonce_field( \FisHotel\Misc\Sections\Sticker_Giveaway\Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_sticker_giveaway_save">

		<div class="fh-sg-columns">

			<?php /* ── Pool ───────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-sg-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-tag"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Sticker pool', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'The designs customers can choose from. Search for your sticker product: a simple product is one design; a product with variations lists them underneath, and each ticked variation is one design. Give every design a regular price (any amount; the picker charges nothing for the free one and the add-on price for extras).', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-sg-field">
					<label class="fh-sg-label" for="fh-sg-pool"><?php esc_html_e( 'Sticker products', 'fishotel-misc-plugin' ); ?></label>
					<select id="fh-sg-pool"
						class="wc-product-search fh-sg-pool"
						name="sticker_product_ids[]"
						multiple="multiple"
						style="width: 100%;"
						data-placeholder="<?php esc_attr_e( 'Search for a sticker product…', 'fishotel-misc-plugin' ); ?>"
						data-action="woocommerce_json_search_products"
						data-exclude_type="grouped,external">
						<?php foreach ( $picked as $pid => $product ) : ?>
							<option value="<?php echo esc_attr( $pid ); ?>" selected="selected" data-kind="<?php echo esc_attr( $product->is_type( 'variable' ) ? 'variable' : 'simple' ); ?>"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php // The variations of each picked variable product. Redrawn after a save; a product picked just now gets its list from the server at once. ?>
				<div id="fh-sg-variations" class="fh-sg-fragment"><?php echo $fragments['variations']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in views/variations.php. ?></div>

				<div id="fh-sg-warnings" class="fh-sg-fragment" aria-live="polite"><?php echo $fragments['warnings']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in views/warnings.php. ?></div>

				<div class="fh-sg-callout">
					<span class="dashicons dashicons-info-outline"></span>
					<span><?php esc_html_e( "Leave each sticker product's Shipping class blank (or use a ground class). The picker keeps its own sticker lines out of the shipping calculation whatever the class. But a sticker bought the normal way, from the shop page, ships by its class — a Premium/overnight class would turn that order into an overnight box.", 'fishotel-misc-plugin' ); ?></span>
				</div>
			</div>

			<?php /* ── Stock ──────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-sg-card fh-sg-card--wide">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-archive"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Stickers in stock', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'The number on each row IS the stock number on the product\'s own page in WooCommerce. Shop sales and giveaway stickers both come out of it, and WooCommerce counts it down when an order is placed. Nothing is counted separately here.', 'fishotel-misc-plugin' ); ?>
				</p>
				<input type="hidden" name="stock_table" value="1">

				<div id="fh-sg-stock" class="fh-sg-fragment"><?php echo $fragments['stock']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in views/stock-table.php. ?></div>

				<div class="fh-sg-field fh-sg-field--row">
					<div>
						<span class="fh-sg-label"><?php esc_html_e( 'Email me when a design needs reordering', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;">
							<?php
							$fh_sg_to = \FisHotel\Misc\Sections\Sticker_Giveaway\Reorder::recipient();
							echo esc_html(
								'' !== $fh_sg_to
									/* translators: %s: email address */
									? sprintf( __( 'One email when a design\'s stock drops to its "Reorder at" number, to %s (WooCommerce\'s stock notification address, else the site admin). No second email until its stock is back above the number. WooCommerce\'s own low-stock emails are separate and not changed.', 'fishotel-misc-plugin' ), $fh_sg_to )
									: __( 'One email when a design\'s stock drops to its "Reorder at" number. No address is set: add one under WooCommerce → Settings → Products → Inventory.', 'fishotel-misc-plugin' )
							);
							?>
						</p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="reorder_email" value="1" <?php checked( ! empty( $settings['reorder_email'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>
			</div>

			<?php /* ── Eligibility ────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-sg-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-yes-alt"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Who gets a free sticker', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'The cart qualifies when at least one line (stickers excluded) is in a ticked category or any of its sub-categories. With nothing ticked, no order gets a free sticker (add-ons can still be offered).', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-sg-field">
					<span class="fh-sg-label"><?php esc_html_e( 'Eligible categories', 'fishotel-misc-plugin' ); ?></span>
					<div class="fh-sg-cats">
						<?php if ( empty( $categories ) ) : ?>
							<p class="fishotel-misc-card-desc"><?php esc_html_e( 'No product categories found.', 'fishotel-misc-plugin' ); ?></p>
						<?php endif; ?>
						<?php foreach ( $categories as $row ) : ?>
							<label class="fh-sg-cat" style="padding-left: <?php echo esc_attr( 12 + 18 * (int) $row['depth'] ); ?>px;">
								<input type="checkbox"
									name="eligible_category_slugs[]"
									value="<?php echo esc_attr( $row['term']->slug ); ?>"
									<?php checked( in_array( $row['term']->slug, $eligible_slugs, true ) ); ?>>
								<span class="fh-sg-cat__name"><?php echo esc_html( $row['term']->name ); ?></span>
								<code class="fh-sg-cat__slug"><?php echo esc_html( $row['term']->slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="fh-sg-field">
					<label class="fh-sg-label" for="fh-sg-min-subtotal"><?php esc_html_e( 'Minimum subtotal (optional)', 'fishotel-misc-plugin' ); ?></label>
					<p class="fishotel-misc-card-desc" style="margin: 0 0 6px;">
						<?php esc_html_e( 'Cart subtotal, sticker lines excluded, that must be reached before the free sticker is offered. 0 = no minimum.', 'fishotel-misc-plugin' ); ?>
					</p>
					<div class="fh-sg-money">
						<span class="fh-sg-money__symbol"><?php echo esc_html( \FisHotel\Misc\Sections\Sticker_Giveaway\Sticker_Giveaway::plain_symbol() ); ?></span>
						<input type="number" id="fh-sg-min-subtotal" class="fh-sg-input fh-sg-input--number" name="min_subtotal" min="0" step="0.01" value="<?php echo esc_attr( number_format( (float) $settings['min_subtotal'], 2, '.', '' ) ); ?>">
					</div>
				</div>

				<div class="fh-sg-field fh-sg-field--row">
					<div>
						<span class="fh-sg-label"><?php esc_html_e( 'Free stickers per order', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'One free sticker per order.', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<span class="fishotel-misc-badge fishotel-misc-badge--active">1</span>
				</div>
			</div>

			<?php /* ── Upsell ─────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-sg-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-cart"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Add-on stickers', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'Offer extra stickers for a small price with a quantity stepper per design.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-sg-field fh-sg-field--row">
					<div>
						<span class="fh-sg-label"><?php esc_html_e( 'Enable add-ons', 'fishotel-misc-plugin' ); ?></span>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="upsell_enabled" value="1" <?php checked( ! empty( $settings['upsell_enabled'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-sg-field">
					<label class="fh-sg-label" for="fh-sg-upsell-price"><?php esc_html_e( 'Price per add-on sticker', 'fishotel-misc-plugin' ); ?></label>
					<div class="fh-sg-money">
						<span class="fh-sg-money__symbol"><?php echo esc_html( \FisHotel\Misc\Sections\Sticker_Giveaway\Sticker_Giveaway::plain_symbol() ); ?></span>
						<input type="number" id="fh-sg-upsell-price" class="fh-sg-input fh-sg-input--number" name="upsell_price" min="0" step="0.01" required value="<?php echo esc_attr( number_format( (float) $settings['upsell_price'], 2, '.', '' ) ); ?>">
					</div>
				</div>

				<div class="fh-sg-field">
					<label class="fh-sg-label" for="fh-sg-upsell-max"><?php esc_html_e( 'Max quantity per design', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-sg-upsell-max" class="fh-sg-input fh-sg-input--number" name="upsell_max_qty" min="1" max="<?php echo esc_attr( \FisHotel\Misc\Sections\Sticker_Giveaway\Settings::MAX_UPSELL_QTY ); ?>" step="1" value="<?php echo esc_attr( (int) $settings['upsell_max_qty'] ); ?>">
				</div>

				<div class="fh-sg-field fh-sg-field--row">
					<div>
						<span class="fh-sg-label"><?php esc_html_e( 'Offer add-ons to everyone', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'Show the steppers even when the cart does not qualify for a free sticker (e.g. a medication-only order).', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="upsell_for_everyone" value="1" <?php checked( ! empty( $settings['upsell_for_everyone'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>
			</div>

			<?php /* ── Copy ───────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-sg-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-editor-textcolor"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Wording', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'Leave a field blank to restore its default. {price} in an add-on intro becomes the add-on price. The card has two states: a qualifying cart sees the free picker plus the "add-on" block; a cart that only gets add-ons (e.g. meds only, with "offer to everyone" on) sees the "add-ons only" heading and intro instead.', 'fishotel-misc-plugin' ); ?>
				</p>

				<?php
				$copy_fields = array(
					'heading_free'        => __( 'Free sticker heading', 'fishotel-misc-plugin' ),
					'intro_free'          => __( 'Free sticker intro', 'fishotel-misc-plugin' ),
					'badge_text'          => __( 'Badge on each sticker', 'fishotel-misc-plugin' ),
					'no_thanks_label'     => __( '"No sticker" option label', 'fishotel-misc-plugin' ),
					'heading_upsell'      => __( 'Add-on heading (under the free picker)', 'fishotel-misc-plugin' ),
					'intro_upsell'        => __( 'Add-on intro (under the free picker)', 'fishotel-misc-plugin' ),
					'heading_upsell_only' => __( 'Add-ons only heading (cart gets no free sticker)', 'fishotel-misc-plugin' ),
					'intro_upsell_only'   => __( 'Add-ons only intro', 'fishotel-misc-plugin' ),
				);
				foreach ( $copy_fields as $key => $label ) :
					?>
				<div class="fh-sg-field">
					<label class="fh-sg-label" for="fh-sg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<input type="text" id="fh-sg-<?php echo esc_attr( $key ); ?>" class="fh-sg-input" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings[ $key ] ); ?>" placeholder="<?php echo esc_attr( \FisHotel\Misc\Sections\Sticker_Giveaway\Settings::DEFAULTS[ $key ] ); ?>">
				</div>
				<?php endforeach; ?>

				<div class="fh-sg-field fh-sg-field--row">
					<div>
						<span class="fh-sg-label"><?php esc_html_e( 'Also show the picker on the cart page', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'Off = checkout only (recommended).', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="show_on_cart" value="1" <?php checked( ! empty( $settings['show_on_cart'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>
			</div>

		</div><!-- .fh-sg-columns -->

		<p class="fh-sg-actions">
			<button type="submit" class="button button-primary fh-sg-save">
				<?php esc_html_e( 'Save Settings', 'fishotel-misc-plugin' ); ?>
			</button>
		</p>

	</form>

</div>
