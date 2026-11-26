<?php
/**
 * Med listings — the page: six steps and the final report.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 *
 * @var array $model Admin::model().
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

$fhml_words    = $model['words'];
$fhml_products = $model['products'];
$fhml_rows_n   = 0;
$fhml_done_n   = 0;
foreach ( $fhml_products as $fhml_p ) {
	$fhml_rows_n += count( $fhml_p['rows'] );
	$fhml_done_n += count( $fhml_p['ledger'] );
}
$fhml_flag_types = array(
	'name'        => __( 'Product names that are claims', 'fishotel-misc-plugin' ),
	'ingredients' => __( 'Ingredients missing or unclear', 'fishotel-misc-plugin' ),
	'review'      => __( 'Reviews and questions that name diseases or dosing', 'fishotel-misc-plugin' ),
	'not_med'     => __( 'Medications the site does not treat as medications (not in Medications)', 'fishotel-misc-plugin' ),
	'brand'       => __( 'Other brands', 'fishotel-misc-plugin' ),
	'attribute'   => __( 'Attributes that cannot be taken off here', 'fishotel-misc-plugin' ),
	'picture'     => __( 'Picture file names', 'fishotel-misc-plugin' ),
);
$fhml_action_words = array(
	'sort'   => __( 'Sort its products by type', 'fishotel-misc-plugin' ),
	'merge'  => __( 'Merge into', 'fishotel-misc-plugin' ),
	'rename' => __( 'Rename', 'fishotel-misc-plugin' ),
	'delete' => __( 'Remove', 'fishotel-misc-plugin' ),
	'untag'  => __( 'Take off the medications only', 'fishotel-misc-plugin' ),
	'delete_attribute' => __( 'Remove the attribute', 'fishotel-misc-plugin' ),
	'keep'   => __( 'Leave as is', 'fishotel-misc-plugin' ),
);
// Which actions each kind of row offers.
$fhml_actions_for = array(
	'product_cat' => array( 'sort', 'merge', 'rename', 'keep' ),
	'product_tag' => array( 'delete', 'untag', 'rename', 'keep' ),
	'attribute'   => array( 'delete_attribute', 'keep' ),
);
$fhml_backup = wp_nonce_url( admin_url( 'admin-post.php?action=fishotel_med_listings_backup' ), Admin::NONCE );
?>
<div class="wrap fishotel-misc-wrap fhml-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Med listings', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<p class="fhml-lead">
		<?php esc_html_e( 'A one-time cleanup: every medication and medicated-food listing becomes a plain product listing — what it is, what is in it, its sizes and price — with nothing about what it treats, no dosing and no links to the calculator or QT pages. The products stay for sale. Work down the steps. Nothing changes until you press Apply, and everything Apply does can be undone here.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php if ( isset( $_GET['words'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice only. ?>
		<div class="fhml-notice <?php echo 'bad' === $_GET['words'] ? 'fhml-notice--warn' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
			<?php echo 'bad' === $_GET['words'] ? esc_html__( 'Word lists saved. A pattern that did not work was left out — check the lists.', 'fishotel-misc-plugin' ) : esc_html__( 'Word lists saved. The table below uses them.', 'fishotel-misc-plugin' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		</div>
	<?php endif; ?>

	<?php /* ── 1 Backup ─────────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-backup">
		<h2 class="fishotel-misc-card-title"><span class="fhml-num">1</span><?php esc_html_e( 'Backup', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fishotel-misc-card-desc">
			<?phpesc_html_e( 'First make a backup in Cloudways (the application → Backup and Restore → Take Backup Now) — that is the one to restore the whole site from. Then download this file too, to read and keep: every field this page reads on every product, with what it says now and its stored value exactly as WordPress holds it, plus the categories, tags and attributes of step 5 with their products, settings, menu items and sub-categories. The file is for reading, not for restoring: Undo on this page uses its own exact record of every old value, and works without either backup.', 'fishotel-misc-plugin' ); ?>
		</p>
		<a class="button button-primary" href="<?php echo esc_url( $fhml_backup ); ?>"><?php esc_html_e( 'Download backup (CSV)', 'fishotel-misc-plugin' ); ?></a>
	</section>

	<?php /* ── 2 Find ───────────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-find">
		<h2 class="fishotel-misc-card-title"><span class="fhml-num">2</span><?php esc_html_e( 'Find', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fishotel-misc-card-desc">
			<?php
			printf(
				/* translators: %d: products */
				esc_html( _n( '%d product found: every product in the Medications category (or under it), and every other product whose name or description names a drug from the list below.', '%d products found: every product in the Medications category (or under it), and every other product whose name or description names a drug from the list below.', count( $fhml_products ), 'fishotel-misc-plugin' ) ),
				(int) count( $fhml_products )
			);
			?>
		</p>
		<ul class="fhml-found">
			<?php foreach ( $fhml_products as $fhml_p ) : ?>
				<li><a href="#fhml-p-<?php echo (int) $fhml_p['snap']['id']; ?>"><?php echo esc_html( $fhml_p['snap']['name'] ); ?></a>
					<span class="fhml-muted">— <?php echo esc_html( implode( '; ', $fhml_p['reasons'] ) ); ?><?php echo 'publish' !== $fhml_p['snap']['status'] ? ' · ' . esc_html( $fhml_p['snap']['status'] ) : ''; ?></span></li>
			<?php endforeach; ?>
		</ul>

		<details class="fhml-words">
			<summary><?php esc_html_e( 'Word lists (what is looked for, and the plain lines every listing gets)', 'fishotel-misc-plugin' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fishotel_med_listings_words">
				<?php wp_nonce_field( Admin::NONCE ); ?>
				<p class="fhml-muted"><?php esc_html_e( 'One word or phrase per line. Capitals do not matter, and a space and a hyphen count as the same. A line between slashes is a pattern, e.g. /\b\d+ days?\b/i.', 'fishotel-misc-plugin' ); ?></p>
				<div class="fhml-words-grid">
					<?php foreach ( Words::titles() as $fhml_key => $fhml_title ) : ?>
						<label class="fhml-words-item">
							<span><?php echo esc_html( $fhml_title ); ?></span>
							<?php if ( in_array( $fhml_key, Words::LISTS, true ) ) : ?>
								<textarea name="fhml_<?php echo esc_attr( $fhml_key ); ?>" rows="8"><?php echo esc_textarea( implode( "\n", (array) $fhml_words[ $fhml_key ] ) ); ?></textarea>
							<?php else : ?>
								<input type="text" name="fhml_<?php echo esc_attr( $fhml_key ); ?>" value="<?php echo esc_attr( (string) $fhml_words[ $fhml_key ] ); ?>">
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save word lists', 'fishotel-misc-plugin' ); ?></button>
					<button type="submit" name="fhml_reset" value="1" class="button"><?php esc_html_e( 'Back to the lists as they came', 'fishotel-misc-plugin' ); ?></button>
				</p>
			</form>
		</details>
	</section>

	<?php /* ── 3 Review ─────────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-review">
		<h2 class="fishotel-misc-card-title"><span class="fhml-num">3</span><?php esc_html_e( 'Review (nothing changes yet)', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fishotel-misc-card-desc">
			<?php esc_html_e( 'For each product: the field, what it says now (claim words marked), and what this page proposes instead. Change any proposal right in its box; untick what you want left alone. The description is written from the product\'s own facts only — its name, its active-ingredient field, its sizes and food base, and who ships it. Where the ingredients are missing there is a [[ … ]] blank to fill in from the label: such a row cannot be applied until it is filled.', 'fishotel-misc-plugin' ); ?>
		</p>
		<p class="fhml-legend">
			<mark class="fhml-hit fhml-hit--diseases"><?php esc_html_e( 'disease', 'fishotel-misc-plugin' ); ?></mark>
			<mark class="fhml-hit fhml-hit--claims"><?php esc_html_e( 'what it does', 'fishotel-misc-plugin' ); ?></mark>
			<mark class="fhml-hit fhml-hit--dosing"><?php esc_html_e( 'dosing', 'fishotel-misc-plugin' ); ?></mark>
			<mark class="fhml-hit fhml-hit--labels"><?php esc_html_e( 'medicine type', 'fishotel-misc-plugin' ); ?></mark>
			<mark class="fhml-hit fhml-hit--links"><?php esc_html_e( 'link', 'fishotel-misc-plugin' ); ?></mark>
		</p>
		<p>
			<button type="button" class="button" id="fhml-report"><?php esc_html_e( 'Download report (med-cleanup-report.md)', 'fishotel-misc-plugin' ); ?></button>
			<span class="fhml-muted"><?php printf( /* translators: 1: rows 2: products */ esc_html__( '%1$d changes proposed on %2$d products.', 'fishotel-misc-plugin' ), (int) $fhml_rows_n, (int) count( $fhml_products ) ); ?></span>
		</p>

		<?php if ( $model['flags'] || $model['linking'] ) : ?>
			<div class="fhml-flags" id="fhml-flags">
				<h3><?php esc_html_e( 'For you to decide (never changed by this page)', 'fishotel-misc-plugin' ); ?></h3>
				<?php foreach ( $fhml_flag_types as $fhml_type => $fhml_title ) : ?>
					<?php
					$fhml_list = array_filter(
						$model['flags'],
						function ( $f ) use ( $fhml_type ) {
							return $f['type'] === $fhml_type;
						}
					);
					if ( ! $fhml_list ) {
						continue;
					}
					?>
					<h4><?php echo esc_html( $fhml_title ); ?></h4>
					<ul>
						<?php foreach ( $fhml_list as $fhml_f ) : ?>
							<li class="fhml-flag" data-type="<?php echo esc_attr( $fhml_type ); ?>" data-product="<?php echo esc_attr( $fhml_f['product'] ); ?>" data-product-id="<?php echo (int) $fhml_f['product_id']; ?>" data-text="<?php echo esc_attr( $fhml_f['text'] . ( ! empty( $fhml_f['suggest'] ) ? ' Suggested name: ' . $fhml_f['suggest'] : '' ) . ( ! empty( $fhml_f['quote'] ) ? ' "' . $fhml_f['quote'] . '"' : '' ) ); ?>">
								<a href="#fhml-p-<?php echo (int) $fhml_f['product_id']; ?>"><?php echo esc_html( $fhml_f['product'] ); ?></a> —
								<?php echo esc_html( $fhml_f['text'] ); ?>
								<?php if ( ! empty( $fhml_f['suggest'] ) ) : ?>
									<br><?php esc_html_e( 'Suggested name:', 'fishotel-misc-plugin' ); ?> <strong><?php echo esc_html( $fhml_f['suggest'] ); ?></strong>
									<span class="fhml-muted"><?php esc_html_e( '(rename it on the product page if you agree; old orders keep the old name)', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $fhml_f['quote'] ) ) : ?>
									<br><q><?php echo esc_html( $fhml_f['quote'] ); ?></q>
								<?php endif; ?>
								<?php if ( 'picture' === $fhml_type && ! empty( $fhml_f['link'] ) ) : ?>
									<br><a href="<?php echo esc_url( $fhml_f['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the picture', 'fishotel-misc-plugin' ); ?></a>
								<?php endif; ?>
								<?php if ( 'review' === $fhml_type ) : ?>
									<br><a href="<?php echo esc_url( $fhml_f['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the review', 'fishotel-misc-plugin' ); ?></a>
									<?php $fhml_hidden = isset( $model['reviews'][ (int) $fhml_f['comment_id'] ] ); ?>
									<button type="button" class="button button-small fhml-review" data-comment="<?php echo (int) $fhml_f['comment_id']; ?>" data-hide="<?php echo $fhml_hidden ? '0' : '1'; ?>">
										<?php echo $fhml_hidden ? esc_html__( 'Show it again', 'fishotel-misc-plugin' ) : esc_html__( 'Hide', 'fishotel-misc-plugin' ); ?>
									</button>
									<span class="fhml-muted"><?php echo esc_html( $fhml_hidden ? __( 'hidden by this page', 'fishotel-misc-plugin' ) : $fhml_f['status'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
				<?php if ( $model['linking'] ) : ?>
					<h4><?php esc_html_e( 'Pages and posts that link to these products', 'fishotel-misc-plugin' ); ?></h4>
					<p class="fhml-muted"><?php esc_html_e( 'A page about diseases or dosing that links to a product puts the claim back on it. The Dosing Calculator, the QT Scheduler and the quarantine guides must not link to the products at all (the theme release takes the links out of its own code; these are links typed into the pages).', 'fishotel-misc-plugin' ); ?></p>
					<ul>
						<?php foreach ( $model['linking'] as $fhml_l ) : ?>
							<li class="fhml-flag" data-type="linking" data-product="<?php echo esc_attr( $fhml_l['title'] ); ?>" data-product-id="<?php echo (int) $fhml_l['id']; ?>" data-text="<?php echo esc_attr( ( $fhml_l['tool'] ? 'Tool or QT guide page. ' : '' ) . 'Links to: ' . implode( ', ', $fhml_l['found'] ) . ' ' . $fhml_l['url'] ); ?>">
								<?php if ( $fhml_l['tool'] ) : ?><strong class="fhml-bad"><?php esc_html_e( 'Tool / QT page', 'fishotel-misc-plugin' ); ?></strong><?php endif; ?>
								<a href="<?php echo esc_url( $fhml_l['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fhml_l['title'] ); ?></a>
								(<a href="<?php echo esc_url( $fhml_l['edit'] ); ?>"><?php esc_html_e( 'edit', 'fishotel-misc-plugin' ); ?></a>)
								— <?php echo esc_html( implode( ', ', $fhml_l['found'] ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php foreach ( $fhml_products as $fhml_p ) : ?>
			<?php $fhml_s = $fhml_p['snap']; ?>
			<div class="fhml-product" id="fhml-p-<?php echo (int) $fhml_s['id']; ?>" data-product="<?php echo (int) $fhml_s['id']; ?>"data-name="<?php echo esc_attr( $fhml_s['name'] ); ?>" data-food="<?php echo ! empty( $fhml_p['facts']['is_food'] ) ? '1' : '0'; ?>">
				<div class="fhml-product-head">
					<h3><?php echo esc_html( $fhml_s['name'] ); ?> <span class="fhml-muted">#<?php echo (int) $fhml_s['id']; ?> · <?php echo esc_html( implode( ', ', $fhml_s['cats'] ) ); ?></span></h3>
					<span class="fhml-links">
						<a href="<?php echo esc_url( $fhml_s['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'fishotel-misc-plugin' ); ?></a> ·
						<a href="<?php echo esc_url( $fhml_s['edit'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit', 'fishotel-misc-plugin' ); ?></a>
					</span>
				</div>
				<?php if ( $fhml_p['rows'] ) : ?>
					<table class="fhml-table widefat">
						<thead><tr>
							<th class="fhml-c-tick"><?php esc_html_e( 'Change', 'fishotel-misc-plugin' ); ?></th>
							<th><?php esc_html_e( 'Field', 'fishotel-misc-plugin' ); ?></th>
							<th><?php esc_html_e( 'Now', 'fishotel-misc-plugin' ); ?></th>
							<th><?php esc_html_e( 'Proposed', 'fishotel-misc-plugin' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $fhml_p['rows'] as $fhml_r ) : ?>
							<tr class="fhml-row" data-object="<?php echo (int) $fhml_r['object']; ?>" data-field="<?php echo esc_attr( $fhml_r['field'] ); ?>" data-hash="<?php echo esc_attr( $fhml_r['hash'] ); ?>" data-key="<?php echo esc_attr( $fhml_r['key'] ); ?>" data-label="<?php echo esc_attr( $fhml_r['label'] . ( $fhml_r['where'] ? ' (' . $fhml_r['where'] . ')' : '' ) ); ?>" data-current="<?php echo esc_attr( $fhml_r['current'] ); ?>">
								<td class="fhml-c-tick"><input type="checkbox" class="fhml-tick" <?php checked( $fhml_r['ticked'] ); ?> aria-label="<?php esc_attr_e( 'Change this field', 'fishotel-misc-plugin' ); ?>"></td>
								<td class="fhml-c-field"><strong><?php echo esc_html( $fhml_r['label'] ); ?></strong>
									<?php if ( $fhml_r['where'] ) : ?><br><span class="fhml-muted"><?php echo esc_html( $fhml_r['where'] ); ?></span><?php endif; ?>
									<?php if ( $fhml_r['note'] ) : ?><br><span class="fhml-note"><?php echo esc_html( $fhml_r['note'] ); ?></span><?php endif; ?>
									<span class="fhml-status" aria-live="polite"></span>
								</td>
								<td class="fhml-c-now"><div class="fhml-now"><?php echo '' === trim( $fhml_r['current'] ) ? '<em class="fhml-muted">' . esc_html__( '(empty)', 'fishotel-misc-plugin' ) . '</em>' : Finder::highlight( $fhml_r['current'], $fhml_r['hits'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Finder::highlight() escapes. ?></div></td>
								<td class="fhml-c-new">
									<?php if ( 'ingredient' === ( $fhml_r['kind'] ?? '' ) ) : ?>
										<input type="text" class="fhml-proposed fhml-ingredient" value="" placeholder="<?php esc_attr_e( 'e.g. Praziquantel, Metronidazole', 'fishotel-misc-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Active ingredient(s)', 'fishotel-misc-plugin' ); ?>">
									<?php elseif ( $fhml_r['editable'] ) : ?>
										<textarea class="fhml-proposed" rows="<?php echo (int) min( 10, max( 2, substr_count( $fhml_r['proposed'], "\n" ) + 2 ) ); ?>"><?php echo esc_textarea( $fhml_r['proposed'] ); ?></textarea>
										<?php if ( '' === $fhml_r['proposed'] ) : ?><span class="fhml-muted"><?php esc_html_e( '(empty = removed)', 'fishotel-misc-plugin' ); ?></span><?php endif; ?>
									<?php else : ?>
										<input type="hidden" class="fhml-proposed" value="">
										<em><?php esc_html_e( 'Taken off the product', 'fishotel-misc-plugin' ); ?></em>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="fhml-muted"><?php esc_html_e( 'Nothing left to change on this product.', 'fishotel-misc-plugin' ); ?></p>
				<?php endif; ?>

				<?php if ( $fhml_p['ledger'] ) : ?>
					<details class="fhml-done">
						<summary><?php printf( /* translators: %d: fields */ esc_html( _n( '%d field changed by this page', '%d fields changed by this page', count( $fhml_p['ledger'] ), 'fishotel-misc-plugin' ) ), (int) count( $fhml_p['ledger'] ) ); ?></summary>
						<ul>
							<?php foreach ( $fhml_p['ledger'] as $fhml_key => $fhml_e ) : ?>
								<li class="fhml-change" data-product="<?php echo esc_attr( $fhml_s['name'] ); ?>" data-product-id="<?php echo (int) $fhml_s['id']; ?>" data-field="<?php echo esc_attr( $fhml_e['field'] . ' #' . (int) $fhml_e['object'] ); ?>" data-old="<?php echo esc_attr( (string) $fhml_e['old_text'] ); ?>" data-new="<?php echo esc_attr( (string) ( $fhml_e['new_text'] ?? '' ) ); ?>">
									<code><?php echo esc_html( $fhml_e['field'] ); ?></code> #<?php echo (int) $fhml_e['object']; ?>
									<span class="fhml-muted"><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $fhml_e['at'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>

				<p class="fhml-product-actions">
					<?php if ( $fhml_p['rows'] ) : ?>
						<button type="button" class="button button-primary fhml-apply"><?php esc_html_e( 'Apply this product', 'fishotel-misc-plugin' ); ?></button>
					<?php endif; ?>
					<?php if ( $fhml_p['ledger'] ) : ?>
						<button type="button" class="button fhml-undo" data-has-undo="1"><?php esc_html_e( 'Undo this product', 'fishotel-misc-plugin' ); ?></button>
					<?php endif; ?>
					<span class="fhml-product-status" aria-live="polite"></span>
				</p>
			</div>
		<?php endforeach; ?>
	</section>

	<?php /* ── 4 Apply ──────────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-apply">
		<h2 class="fishotel-misc-card-title"><span class="fhml-num">4</span><?php esc_html_e( 'Apply', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fishotel-misc-card-desc">
			<?php esc_html_e( 'Apply one product at a time with its own button above, or every ticked change on every product here. Changes go in through WordPress and WooCommerce themselves, one product after the other. A field someone changed after this page was opened is skipped and named — reload and look again. Undo puts back exactly what was there; a field changed by someone since the Apply is left alone and named.', 'fishotel-misc-plugin' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="fhml-apply-all"><?php esc_html_e( 'Apply all ticked', 'fishotel-misc-plugin' ); ?></button>
			<button type="button" class="button" id="fhml-undo-all" <?php disabled( 0 === $fhml_done_n ); ?>><?php esc_html_e( 'Undo all', 'fishotel-misc-plugin' ); ?></button>
			<span id="fhml-all-status" class="fhml-product-status" aria-live="polite"></span>
		</p>
	</section>

	<?php /* ── 5 Categories ─────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-categories">
		<h2 class="fishotel-misc-card-title"><span class="fhml-num">5</span><?php esc_html_e( 'Categories and tags', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fishotel-misc-card-desc">
			<?php esc_html_e( 'Categories named after what a medicine does (Antiparasitic, Antibacterial, Dewormer …) give way to neutral ones: Medicated Foods, Powders & Chemicals and Water Chemistry, all kept under Medications (the theme finds medications by that category, for the ornamental-use notice and the checkout tick). Disease and medicine-type tags are removed. Every old address — and its page 2, 3 … and its feed — sends visitors on with a permanent (301) redirect, kept by this section even after the cleanup is done and even with the section switched off. Do step 4 first: tags come off the products there.', 'fishotel-misc-plugin' ); ?>
		</p>
		<?php if ( $model['terms'] ) : ?>
			<table class="fhml-table widefat" id="fhml-terms">
				<thead><tr>
					<th class="fhml-c-tick"><?php esc_html_e( 'Do it', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Now', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'What happens', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Old address goes to', 'fishotel-misc-plugin' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $model['terms'] as $fhml_t ) : ?>
					<?php $fhml_offer = $fhml_actions_for[ $fhml_t['tax'] ] ?? array( 'rename', 'keep' ); ?>
					<tr class="fhml-term<?php echo ! empty( $fhml_t['blocked'] ) ? ' fhml-term--blocked' : ''; ?>" data-id="<?php echo (int) $fhml_t['id']; ?>" data-tax="<?php echo esc_attr( $fhml_t['tax'] ); ?>" data-name="<?php echo esc_attr( $fhml_t['name'] ); ?>">
						<td class="fhml-c-tick"><input type="checkbox" class="fhml-tick" <?php checked( 'keep' !== $fhml_t['action'] && ! empty( $fhml_t['ticked'] ) ); ?> <?php disabled( ! empty( $fhml_t['blocked'] ) ); ?>></td>
						<td>
							<strong><?php echo esc_html( $fhml_t['name'] ); ?></strong>
							<span class="fhml-muted"><?php echo esc_html( 'product_cat' === $fhml_t['tax'] ? __( 'category', 'fishotel-misc-plugin' ) :( 'product_tag' === $fhml_t['tax'] ? __( 'tag', 'fishotel-misc-plugin' ) : ( 'attribute' === $fhml_t['tax'] ? __( 'attribute', 'fishotel-misc-plugin' ) : __( 'brand', 'fishotel-misc-plugin' ) ) ) ); ?> · <?php echo esc_html( $fhml_t['slug'] ); ?> · <?php printf( /* translators: %d: products */ esc_html( _n( '%d product', '%d products', $fhml_t['count'], 'fishotel-misc-plugin' ) ), (int) $fhml_t['count'] ); ?></span>
							<?php if ( $fhml_t['types'] ) : ?>
								<br><span class="fhml-muted"><?php echo esc_html( implode( ', ', array_map( function ( $k, $v ) { return $v . ' × ' . Terms::TARGETS[ $k ][0]; }, array_keys( $fhml_t['types'] ), $fhml_t['types'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== trim( $fhml_t['desc'] ) ) : ?><div class="fhml-now fhml-small"><?php echo esc_html( wp_strip_all_tags( $fhml_t['desc'] ) ); ?></div><?php endif; ?>
								<?php if ( $fhml_t['note'] ) : ?><br><span class="fhml-note"><?php echo esc_html( $fhml_t['note'] ); ?></span><?php endif; ?>
								<?php $fhml_aff = (array) ( $fhml_t['affects'] ?? array() ); ?>
								<?php if ( ! empty( $fhml_aff['menus'] ) || ! empty( $fhml_aff['children'] ) || ! empty( $fhml_aff['settings'] ) ) : ?>
									<br><span class="fhml-note"><?php esc_html_e( 'Also:', 'fishotel-misc-plugin' ); ?>
									<?php if ( ! empty( $fhml_aff['menus'] ) ) : ?><?php printf( /* translators: %s: menu labels */ esc_html__( 'menu items %s will point to the category its address goes to;', 'fishotel-misc-plugin' ), '"' . esc_html( implode( '", "', $fhml_aff['menus'] ) ) . '"' ); ?><?php endif; ?>
									<?php if ( ! empty( $fhml_aff['children'] ) ) : ?><?php printf( /* translators: %s: categories */ esc_html__( 'the categories under it (%s) move up one level, their old addresses sent on;', 'fishotel-misc-plugin' ), esc_html( implode( ', ', $fhml_aff['children'] ) ) ); ?><?php endif; ?>
									<?php if ( ! empty( $fhml_aff['settings'] ) ) : ?><?php esc_html_e( 'its own settings (picture, display, order) are kept for Undo.', 'fishotel-misc-plugin' ); ?><?php endif; ?>
									<?php esc_html_e( 'Undo puts all of it back.', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
								<span class="fhml-status" aria-live="polite"></span>
						</td>
						<td>
							<select class="fhml-action">
								<?php foreach ( $fhml_action_words as $fhml_a => $fhml_w ) : ?>
									<?php if ( ! in_array( $fhml_a, $fhml_offer, true ) ) { continue; } ?>
									<option value="<?php echo esc_attr( $fhml_a ); ?>" <?php selected( $fhml_t['action'], $fhml_a ); ?>><?php echo esc_html( $fhml_w ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( 'product_cat' === $fhml_t['tax'] ) : ?>
							<span class="fhml-when fhml-when--merge">
								<select class="fhml-merge-to">
									<?php foreach ( $model['targets'] as $fhml_v => $fhml_n ) : ?>
										<option value="<?php echo esc_attr( $fhml_v ); ?>" <?php selected( $fhml_t['merge_to'], $fhml_v ); ?>><?php echo esc_html( $fhml_n ); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
							<?php endif; ?>
							<?php if ( 'attribute' !== $fhml_t['tax'] ) : ?>
							<span class="fhml-when fhml-when--rename">
								<label><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?> <input type="text" class="fhml-new-name" value="<?php echo esc_attr( $fhml_t['new_name'] ); ?>"></label>
								<label><?php esc_html_e( 'Slug', 'fishotel-misc-plugin' ); ?> <input type="text" class="fhml-new-slug" value="<?php echo esc_attr( $fhml_t['new_slug'] ); ?>"></label>
							</span>
							<span class="fhml-when fhml-when--rename fhml-when--untag">
								<label><?php esc_html_e( 'Description', 'fishotel-misc-plugin' ); ?><textarea class="fhml-new-desc" rows="2"><?php echo esc_textarea( $fhml_t['new_desc'] ); ?></textarea></label>
							</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'product_cat' === $fhml_t['tax'] ) : ?>
							<span class="fhml-when fhml-when--sort">
								<select class="fhml-redirect-to">
									<?php foreach ( $model['targets'] as $fhml_v => $fhml_n ) : ?>
										<option value="<?php echo esc_attr( $fhml_v ); ?>" <?php selected( $fhml_t['redirect_to'], $fhml_v ); ?>><?php echo esc_html( $fhml_n ); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
							<?php endif; ?>
							<span class="fhml-when fhml-when--delete fhml-when--delete_attribute fhml-muted"><?php echo esc_html( $model['targets']['parent'] ); ?></span>
							<span class="fhml-when fhml-when--untag fhml-muted"><?php esc_html_e( 'nothing changes (the tag page stays)', 'fishotel-misc-plugin' ); ?></span>
							<span class="fhml-when fhml-when--merge fhml-muted"><?php esc_html_e( 'the category merged into', 'fishotel-misc-plugin' ); ?></span>
							<span class="fhml-when fhml-when--rename fhml-muted"><?php esc_html_e( 'the new address (when the slug changes)', 'fishotel-misc-plugin' ); ?></span>
							<?php if ( $fhml_t['link'] ) : ?><br><code class="fhml-small"><?php echo esc_html( (string) wp_parse_url( $fhml_t['link'], PHP_URL_PATH ) ); ?></code><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button button-primary" id="fhml-terms-apply"><?php esc_html_e( 'Make the ticked changes', 'fishotel-misc-plugin' ); ?></button> <span id="fhml-terms-status" class="fhml-product-status" aria-live="polite"></span></p>
		<?php else : ?>
			<p class="fhml-muted"><?php esc_html_e( 'No category or tag left to change.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>

		<?php if ( $model['changes'] ) : ?>
			<h3><?php esc_html_e( 'Category changes made here', 'fishotel-misc-plugin' ); ?></h3>
			<ul>
				<?php foreach ( $model['changes'] as $fhml_id => $fhml_c ) : ?>
					<li class="fhml-term-change" data-change="<?php echo esc_attr( $fhml_id ); ?>" data-text="<?php echo esc_attr( $fhml_c['type'] . ': ' . $fhml_c['old']['name'] . ' (' . $fhml_c['old']['slug'] . ')' . ( isset( $fhml_c['new']['name'] ) ? ' → ' . $fhml_c['new']['name'] . ' (' . $fhml_c['new']['slug'] . ')' : '' ) ); ?>">
						<?php echo esc_html( $fhml_action_words[ $fhml_c['type'] ] ?? $fhml_c['type'] ); ?>: <strong><?php echo esc_html( $fhml_c['old']['name'] ); ?></strong>
						<?php if ( isset( $fhml_c['new']['name'] ) ) : ?> → <strong><?php echo esc_html( $fhml_c['new']['name'] ); ?></strong> (<?php echo esc_html( $fhml_c['new']['slug'] ); ?>)<?php endif; ?>
						<span class="fhml-muted"><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $fhml_c['at'] ) ); ?></span>
						<button type="button" class="button button-small fhml-term-undo"><?php esc_html_e( 'Undo', 'fishotel-misc-plugin' ); ?></button>
						<span class="fhml-status" aria-live="polite"></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3 id="fhml-redirects"><?php esc_html_e( 'Redirect list', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( $model['redirect'] ) : ?>
			<table class="fhml-table widefat">
				<thead><tr><th><?php esc_html_e( 'Old address', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Goes to (301)', 'fishotel-misc-plugin' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $model['redirect'] as $fhml_e ) : ?>
					<tr class="fhml-redirect" data-from="<?php echo esc_attr( $fhml_e['from'] ); ?>" data-to="<?php echo esc_attr( Redirects::target_url( $fhml_e ) ); ?>">
						<td><code><?php echo esc_html( $fhml_e['from'] ); ?></code> <span class="fhml-muted"><?php esc_html_e( '(and its pages and feed)', 'fishotel-misc-plugin' ); ?></span></td>
						<td><code><?php echo esc_html( Redirects::target_url( $fhml_e ) ); ?></code></td>
						<td><button type="button" class="button button-small fhml-redirect-remove"><?php esc_html_e( 'Take off the list', 'fishotel-misc-plugin' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="fhml-muted"><?php esc_html_e( 'No redirects yet.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
	</section>

	<?php /* ── 6 Verify ─────────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-verify">
		<h2 class="fishotel-misc-card-title"><span class="fhml-num">6</span><?php esc_html_e( 'Verify', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fishotel-misc-card-desc">
			<?php
			echo $model['breeze']
				? esc_html__( 'First clear the caches (Breeze, and Varnish through Breeze). Then the check opens every medication product page, every medication category page and the tool pages the way a visitor gets them, and reads the words on them, the tags for search engines and the product data (JSON-LD, review texts included). A product or category page passes when it names no disease, claim or dosing and has no link to the calculator, QT pages, blog or forum. A tool page passes when it has no link to a medication product.', 'fishotel-misc-plugin' )
				: esc_html__( 'Breeze is not on this site, so this page cannot clear Varnish: purge it in Cloudways (the application → Application Settings → Varnish → Purge) before the check. The check opens every medication product page, every medication category page and the tool pages the way a visitor gets them, and reads the words on them, the tags for search engines and the product data (JSON-LD, review texts included).', 'fishotel-misc-plugin' );
			?>
		</p>
		<p>
			<button type="button" class="button" id="fhml-purge"><?php esc_html_e( 'Clear caches', 'fishotel-misc-plugin' ); ?></button>
			<button type="button" class="button button-primary" id="fhml-check"><?php esc_html_e( 'Check the pages', 'fishotel-misc-plugin' ); ?></button>
			<span id="fhml-verify-status" class="fhml-product-status" aria-live="polite"></span>
		</p>
		<table class="fhml-table widefat" id="fhml-pages">
			<thead><tr><th><?php esc_html_e( 'Page', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Result', 'fishotel-misc-plugin' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $model['pages'] as $fhml_pg ) : ?>
				<tr class="fhml-page" data-url="<?php echo esc_attr( $fhml_pg['url'] ); ?>" data-kind="<?php echo esc_attr( $fhml_pg['kind'] ); ?>" data-label="<?php echo esc_attr( $fhml_pg['label'] ); ?>">
					<td><a href="<?php echo esc_url( $fhml_pg['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fhml_pg['label'] ); ?></a><br><code class="fhml-small"><?php echo esc_html( $fhml_pg['url'] ); ?></code></td>
					<td class="fhml-result"><span class="fhml-muted"><?php esc_html_e( 'not checked yet', 'fishotel-misc-plugin' ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<?php /* ── Final report ─────────────────────────────────────── */ ?>
	<section class="fishotel-misc-card fhml-step" id="fhml-final">
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Report', 'fishotel-misc-plugin' ); ?></h2>
		<ul>
			<li><?php printf( /* translators: 1: fields 2: products */ esc_html__( 'Changed by this page: %1$d fields on %2$d products.', 'fishotel-misc-plugin' ), (int) $fhml_done_n, (int) count( array_filter( $fhml_products, function ( $p ) { return (bool) $p['ledger']; } ) ) ); ?></li>
			<li><?php printf( /* translators: %d: changes */ esc_html__( 'Category and tag changes: %d.', 'fishotel-misc-plugin' ), (int) count( $model['changes'] ) ); ?></li>
			<li><?php printf( /* translators: %d: things */ esc_html__( 'Left for you to decide: %d (listed in step 3).', 'fishotel-misc-plugin' ), (int) ( count( $model['flags'] ) + count( $model['linking'] ) ) ); ?></li>
			<li><?php printf( /* translators: %d: redirects */ esc_html__( 'Redirects: %d (listed in step 5).', 'fishotel-misc-plugin' ), (int) count( $model['redirect'] ) ); ?></li>
			<li><?php printf( /* translators: %d: changes */ esc_html__( 'Still proposed: %d changes.', 'fishotel-misc-plugin' ), (int) $fhml_rows_n ); ?></li>
		</ul>
		<p class="fhml-muted"><?php esc_html_e( 'The report download (step 3) holds all of this in one file: the proposals as they stand in the boxes, what was changed, the flags, the redirects and the page check results.', 'fishotel-misc-plugin' ); ?></p>
	</section>
</div>
