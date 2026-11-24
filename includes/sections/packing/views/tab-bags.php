<?php
/**
 * Packing page — Bags: the standard bags, and the "Triple-bag these"
 * words.
 *
 * @package FisHotel\Misc\Sections\Packing
 *
 * @var array $settings Settings::get().
 * @var array $usage    Bag id → how many products / variations name it.
 * @var array $model    Boxes::model(): the foam and the filled share.
 * @var array $third    Third_Bag::review_list(): products the words find, with no tick yet.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Packing\Admin;
use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Fit;
use FisHotel\Misc\Sections\Packing\Layers;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Third_Bag;

$fh_bags  = $settings['bags'];
$fh_spare = $fh_bags ? 1 : 4;
$fh_next  = count( $fh_bags ) + $fh_spare;
?>
<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-archive"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Bag sizes', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'The bags you pack fish in — nothing is filled in for you. A name is all a bag needs. A bag keeps its # for good, so renaming one changes nothing on the products that use it.', 'fishotel-misc-plugin' ); ?>
	</p>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'Width and length are the bag lying flat, in inches. With both, the section works out how many of that bag stand on each box\'s floor: filled and tied, a bag stands as a round column 2 × width ÷ π across (its mouth is twice the width around), and as tall as its length × the filled share — the rest is the tie-off. A bag without both falls back to the numbers typed under Boxes → How many fit, and goes in no box where none is typed.', 'fishotel-misc-plugin' ); ?>
	</p>
	<p class="fishotel-misc-card-desc">
		<?php
		/* translators: %d: percent */
		echo esc_html( sprintf( __( 'The sort number orders the list everywhere, and the list runs from the smallest bag to the largest: a fish is moved up the list, one bag at a time, to fill its box. Filled share: blank uses the setting under Boxes (%d%% now); type 50 for a bag you fill only half way.', 'fishotel-misc-plugin' ), (int) $model['share'] ) );
		?>
	</p>

	<form class="fh-pack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-reload="1">
		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_packing_bags">

		<table class="fh-pack-table" data-next="<?php echo esc_attr( $fh_next ); ?>">
			<thead>
				<tr>
					<th class="fh-pack-col-id">#</th>
					<th><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Width (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Length (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Filled share (%)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Filled, it stands', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Sort', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Set on', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fh_bags as $fh_i => $fh_bag ) : ?>
					<?php $fh_on = (int) ( $usage[ (int) $fh_bag['id'] ] ?? 0 ); ?>
					<tr>
						<td class="fh-pack-col-id">
							<?php echo esc_html( (int) $fh_bag['id'] ); ?>
							<input type="hidden" name="bags[<?php echo esc_attr( $fh_i ); ?>][id]" value="<?php echo esc_attr( (int) $fh_bag['id'] ); ?>">
						</td>
						<td><input type="text" class="fh-pack-input" name="bags[<?php echo esc_attr( $fh_i ); ?>][name]" value="<?php echo esc_attr( $fh_bag['name'] ); ?>" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][width]" value="<?php echo esc_attr( $fh_bag['width'] ); ?>" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][length]" value="<?php echo esc_attr( $fh_bag['length'] ); ?>" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][share]" value="<?php echo esc_attr( $fh_bag['share'] ?? '' ); ?>" min="<?php echo esc_attr( Fit::SHARE_MIN ); ?>" max="<?php echo esc_attr( Fit::SHARE_MAX ); ?>" step="1" placeholder="<?php echo esc_attr( (int) $model['share'] ); ?>" aria-label="<?php esc_attr_e( 'Filled share in percent', 'fishotel-misc-plugin' ); ?>"></td>
						<td class="fh-pack-muted">
							<?php
							if ( Fit::has_size( $fh_bag ) ) {
								/* translators: 1: inches across, 2: inches tall */
								echo esc_html( sprintf( __( '%1$s in across, %2$s in tall', 'fishotel-misc-plugin' ), Boxes::num_text( Fit::diameter( $fh_bag['width'] ), 1 ), Boxes::num_text( Fit::standing( $fh_bag, $model['share'] ), 1 ) ) );
							} else {
								esc_html_e( 'no size: typed numbers only', 'fishotel-misc-plugin' );
							}
							?>
						</td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][sort]" value="<?php echo esc_attr( (int) $fh_bag['sort'] ); ?>" step="1" aria-label="<?php esc_attr_e( 'Sort order', 'fishotel-misc-plugin' ); ?>"></td>
						<td class="fh-pack-muted">
							<?php
							/* translators: %d: number of products and variations */
							echo esc_html( sprintf( _n( '%d product / variation', '%d products / variations', $fh_on, 'fishotel-misc-plugin' ), $fh_on ) );
							?>
						</td>
						<td><label class="fh-pack-check"><input type="checkbox" name="bags[<?php echo esc_attr( $fh_i ); ?>][remove]" value="1"> <span class="screen-reader-text"><?php esc_html_e( 'Remove this bag', 'fishotel-misc-plugin' ); ?></span></label></td>
					</tr>
				<?php endforeach; ?>

				<?php for ( $fh_i = count( $fh_bags ); $fh_i < $fh_next; $fh_i++ ) : ?>
					<tr class="fh-pack-row--new">
						<td class="fh-pack-col-id fh-pack-muted"><?php esc_html_e( 'new', 'fishotel-misc-plugin' ); ?></td>
						<td><input type="text" class="fh-pack-input" name="bags[<?php echo esc_attr( $fh_i ); ?>][name]" value="" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][width]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][length]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][share]" value="" min="<?php echo esc_attr( Fit::SHARE_MIN ); ?>" max="<?php echo esc_attr( Fit::SHARE_MAX ); ?>" step="1" placeholder="<?php echo esc_attr( (int) $model['share'] ); ?>" aria-label="<?php esc_attr_e( 'Filled share in percent', 'fishotel-misc-plugin' ); ?>"></td>
						<td class="fh-pack-muted">&mdash;</td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][sort]" value="" step="1" aria-label="<?php esc_attr_e( 'Sort order', 'fishotel-misc-plugin' ); ?>"></td>
						<td class="fh-pack-muted">&mdash;</td>
						<td></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>

		<script type="text/template" class="fh-pack-row-template">
			<tr class="fh-pack-row--new">
				<td class="fh-pack-col-id fh-pack-muted"><?php esc_html_e( 'new', 'fishotel-misc-plugin' ); ?></td>
				<td><input type="text" class="fh-pack-input" name="bags[__i__][name]" value="" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][width]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][length]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][share]" value="" min="<?php echo esc_attr( Fit::SHARE_MIN ); ?>" max="<?php echo esc_attr( Fit::SHARE_MAX ); ?>" step="1" placeholder="<?php echo esc_attr( (int) $model['share'] ); ?>" aria-label="<?php esc_attr_e( 'Filled share in percent', 'fishotel-misc-plugin' ); ?>"></td>
				<td class="fh-pack-muted">&mdash;</td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][sort]" value="" step="1" aria-label="<?php esc_attr_e( 'Sort order', 'fishotel-misc-plugin' ); ?>"></td>
				<td class="fh-pack-muted">&mdash;</td>
				<td></td>
			</tr>
		</script>

		<p class="fh-pack-actions">
			<button type="button" class="button fh-pack-add-row"><?php esc_html_e( 'Add a bag', 'fishotel-misc-plugin' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save bag sizes', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-pack-status" role="status" aria-live="polite"></span>
		</p>
	</form>

	<p class="fishotel-misc-card-desc">
		<?php
		printf(
			/* translators: %s: link to the Sizes tab */
			esc_html__( 'Next: %s — which bag each fish size goes in, so the Bag size does not have to be set on every variation by hand.', 'fishotel-misc-plugin' ),
			'<a href="' . esc_url( Admin::tab_url( 'sizes' ) ) . '">' . esc_html__( 'Sizes → bags', 'fishotel-misc-plugin' ) . '</a>'
		);
		?>
	</p>
</div>

<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-shield"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Triple-bag these', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'Every fish is double-bagged. A fish with sharp spines or a scalpel is triple-bagged: tick "Needs a 3rd bag (sharp spines or a scalpel)" on its product (top of the Shipping tab, or quick edit / bulk edit on the products list). This list of words is for the fish that have no product page to tick — a fish typed on a batch invoice. A word counts whole, whatever the capitals, in the fish\'s name or one of its product categories, and one or many makes no difference — “tang” (or “tangs”) finds “Yellow Tang” and “Tangs”, never “Tangerine”.', 'fishotel-misc-plugin' ); ?>
	</p>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'One word per line, or separated by commas. A name is read as it is written: if your invoices say “Flame Angel”, “Niger Trigger” or “Volitan Lion”, add “angel”, “trigger” and “lion” — “angelfish” alone does not find them. The order\'s Packing box shows the result on every fish line — ×2 or ×3 — and how many bags to pull in all.', 'fishotel-misc-plugin' ); ?>
	</p>

	<form class="fh-pack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_packing_words">

		<p>
			<label class="screen-reader-text" for="fh-pack-triple-words"><?php esc_html_e( 'Words that mean a fish is triple-bagged', 'fishotel-misc-plugin' ); ?></label>
			<textarea id="fh-pack-triple-words" class="fh-pack-input fh-pack-words" name="triple_words" rows="<?php echo esc_attr( max( 6, min( 16, count( $settings['triple_words'] ) + 2 ) ) ); ?>" cols="40" spellcheck="false"><?php echo esc_textarea( implode( "\n", $settings['triple_words'] ) ); ?></textarea>
		</p>
		<p class="fishotel-misc-card-desc">
			<?php
			/* translators: %s: the words the list starts with, separated by commas */
			echo esc_html( sprintf( __( 'The list it started with: %s.', 'fishotel-misc-plugin' ), implode( ', ', Layers::default_words() ) ) );
			?>
		</p>
		<p>
			<label for="fh-pack-triple-scope"><strong><?php esc_html_e( 'The word list applies to', 'fishotel-misc-plugin' ); ?></strong></label><br>
			<select id="fh-pack-triple-scope" name="triple_scope">
				<?php foreach ( Layers::scopes() as $fh_scope => $fh_scope_label ) : ?>
					<option value="<?php echo esc_attr( $fh_scope ); ?>" <?php selected( $settings['triple_scope'], $fh_scope ); ?>><?php echo esc_html( $fh_scope_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="fishotel-misc-card-desc">
			<?php esc_html_e( '"Lines with no product page only": every fish with a product is triple-bagged when its tick says so, and only then. "Every line" is the old way: a word also triple-bags a fish with a product whose tick is off. The helper below sets "Lines with no product page only" the first time it ticks products.', 'fishotel-misc-plugin' ); ?>
		</p>

		<p class="fh-pack-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the list', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-pack-status" role="status" aria-live="polite"></span>
		</p>
	</form>
</div>

<div class="fishotel-misc-card fh-pack-card fh-pack-third">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-yes-alt"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Tick every product the old list matches', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<?php if ( ! $third ) : ?>
		<p class="fishotel-misc-card-desc">
			<?php esc_html_e( 'Nothing to tick: no product packed in a bag has a word of the list in its name or categories without its "Needs a 3rd bag" tick.', 'fishotel-misc-plugin' ); ?>
		</p>
	<?php else : ?>
		<p class="fishotel-misc-card-desc">
			<?php
			/* translators: %d: number of products */
			echo esc_html( sprintf( _n( '%d product packed in a bag has a word of the list in its name or categories but no "Needs a 3rd bag" tick. Untick any fish you disagree with, then tick the rest in one click. Nothing is ticked until you click.', '%d products packed in a bag have a word of the list in their name or categories but no "Needs a 3rd bag" tick. Untick any fish you disagree with, then tick the rest in one click. Nothing is ticked until you click.', count( $third ), 'fishotel-misc-plugin' ), count( $third ) ) );
			?>
		</p>
		<p class="fishotel-misc-card-desc">
			<?php esc_html_e( 'A fish you leave unticked stays on this list, to look at again — it is double-bagged once the list reaches only the fish with no product page.', 'fishotel-misc-plugin' ); ?>
		</p>
		<form class="fh-pack-form fh-pack-third-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-reload="1">
			<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="fishotel_packing_tick_third">
			<input type="hidden" name="ids" value="">
			<p>
				<a href="#" class="fh-pack-third-all"><?php esc_html_e( 'Tick all', 'fishotel-misc-plugin' ); ?></a> ·
				<a href="#" class="fh-pack-third-none"><?php esc_html_e( 'Untick all', 'fishotel-misc-plugin' ); ?></a>
			</p>
			<table class="widefat striped fh-pack-table fh-pack-third-table">
				<thead>
					<tr>
						<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Tick', 'fishotel-misc-plugin' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Product', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Word found', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Categories', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $third as $fh_row ) : ?>
						<tr>
							<td class="check-column"><input type="checkbox" class="fh-pack-third-pick" value="<?php echo esc_attr( (int) $fh_row['id'] ); ?>" checked aria-label="<?php echo esc_attr( (string) $fh_row['name'] ); ?>"></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $fh_row['id'] . '&action=edit' ) ); ?>"><?php echo esc_html( (string) $fh_row['name'] ); ?></a>
								<?php if ( 'publish' !== $fh_row['status'] ) : ?>
									<span class="fh-pack-muted">(<?php echo esc_html( (string) $fh_row['status'] ); ?>)</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $fh_row['word'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_keys( (array) $fh_row['categories'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="fh-pack-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Tick "Needs a 3rd bag" on the ticked rows', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-pack-status" role="status" aria-live="polite"></span>
			</p>
		</form>
	<?php endif; ?>
</div>
