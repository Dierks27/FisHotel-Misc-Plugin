<?php
/**
 * Lottery Draw admin — draw list and the create form.
 *
 * Available: $draws (array of ['post' => WP_Post, 'payload' => array]), $notice.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

$fh_status_labels = array(
	Lottery_Draw::STATUS_DRAFT     => __( 'Draft — entrants still editable', 'fishotel-misc-plugin' ),
	Lottery_Draw::STATUS_COMMITTED => __( 'Seed committed — locked, not yet drawn', 'fishotel-misc-plugin' ),
	Lottery_Draw::STATUS_PUBLISHED => __( 'Published — immutable', 'fishotel-misc-plugin' ),
);
?>
<div class="wrap fh-draw-admin">
	<h1><?php esc_html_e( 'Lottery Draw', 'fishotel-misc-plugin' ); ?></h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="fh-draw-lede">
		<?php esc_html_e( 'Allocate contested fish by a seeded lottery members can recompute themselves. The draw runs in your browser; this plugin stores the inputs and the result, and the public page re-runs the draw in every visitor\'s browser to check it.', 'fishotel-misc-plugin' ); ?>
	</p>

	<h2><?php esc_html_e( 'Draws', 'fishotel-misc-plugin' ); ?></h2>

	<?php if ( empty( $draws ) ) : ?>
		<p><?php esc_html_e( 'No draws yet.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<table class="widefat striped fh-draw-list">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Draw', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Seed', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $draws as $fh_row ) : ?>
					<?php $fh_payload = $fh_row['payload']; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $fh_payload['title'] ); ?></strong><br />
							<code><?php echo esc_html( $fh_payload['id'] ); ?></code>
						</td>
						<td><?php echo esc_html( $fh_status_labels[ $fh_payload['status'] ] ?? $fh_payload['status'] ); ?></td>
						<td><?php echo esc_html( count( (array) $fh_payload['fish'] ) ); ?></td>
						<td><code><?php echo esc_html( $fh_payload['seed'] ? $fh_payload['seed'] : '—' ); ?></code></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => Lottery_Draw::MENU_SLUG, 'draw' => $fh_payload['id'] ), admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html( Store::is_published( $fh_payload ) ? __( 'View', 'fishotel-misc-plugin' ) : __( 'Manage', 'fishotel-misc-plugin' ) ); ?>
							</a>
							<?php if ( Store::is_published( $fh_payload ) ) : ?>
								| <a href="<?php echo esc_url( get_permalink( $fh_row['post'] ) ); ?>"><?php esc_html_e( 'Public page', 'fishotel-misc-plugin' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'New draw', 'fishotel-misc-plugin' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-draw-form">
		<?php wp_nonce_field( 'fishotel_draw_create' ); ?>
		<input type="hidden" name="action" value="fishotel_draw_create" />

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fh-title"><?php esc_html_e( 'Title', 'fishotel-misc-plugin' ); ?></label></th>
				<td>
					<input type="text" id="fh-title" name="title" class="regular-text" required
						placeholder="<?php esc_attr_e( 'RVS Philippines — August 2026', 'fishotel-misc-plugin' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fh-draw-id"><?php esc_html_e( 'Draw ID', 'fishotel-misc-plugin' ); ?></label></th>
				<td>
					<input type="text" id="fh-draw-id" name="draw_id" class="regular-text"
						placeholder="<?php esc_attr_e( 'rvs-2026-08', 'fishotel-misc-plugin' ); ?>" />
					<p class="description"><?php esc_html_e( 'Used in the public URL and the shortcode. Leave blank to derive it from the title.', 'fishotel-misc-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Seed method', 'fishotel-misc-plugin' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="seed_method" value="<?php echo esc_attr( Lottery_Draw::SEED_COMMIT_EARLY ); ?>" data-fh-seed-method checked />
							<strong><?php esc_html_e( 'Commit early', 'fishotel-misc-plugin' ); ?></strong>
							— <?php esc_html_e( 'generate a seed now and post it to the thread before entries close.', 'fishotel-misc-plugin' ); ?>
						</label><br />
						<label>
							<input type="radio" name="seed_method" value="<?php echo esc_attr( Lottery_Draw::SEED_EXTERNAL ); ?>" data-fh-seed-method />
							<strong><?php esc_html_e( 'External randomness', 'fishotel-misc-plugin' ); ?></strong>
							— <?php esc_html_e( 'announce a public value that does not exist yet (a Powerball draw, a Bitcoin block height), then enter it once it does.', 'fishotel-misc-plugin' ); ?>
						</label>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'A seed we picked after seeing the entries proves nothing — we could try seeds until we liked the winners. Both methods fix the seed before it can be ground against the entrant list.', 'fishotel-misc-plugin' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fh-seed"><?php esc_html_e( 'Seed', 'fishotel-misc-plugin' ); ?></label></th>
				<td>
					<input type="text" id="fh-seed" name="seed" class="regular-text code" data-fh-seed-field
						value="<?php echo esc_attr( Lottery_Draw::generate_seed() ); ?>" />
					<button type="button" class="button" data-fh-generate-seed><?php esc_html_e( 'Generate', 'fishotel-misc-plugin' ); ?></button>
					<p class="description"><?php esc_html_e( 'A fresh seed is suggested above; press Generate for another. For external randomness, clear this and paste the value once it exists.', 'fishotel-misc-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fh-supersedes"><?php esc_html_e( 'Corrects', 'fishotel-misc-plugin' ); ?></label></th>
				<td>
					<select id="fh-supersedes" name="supersedes">
						<option value=""><?php esc_html_e( '— nothing, this is a fresh draw —', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( $draws as $fh_row ) : ?>
							<?php if ( Store::is_published( $fh_row['payload'] ) ) : ?>
								<option value="<?php echo esc_attr( $fh_row['payload']['id'] ); ?>">
									<?php echo esc_html( $fh_row['payload']['title'] ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'A published draw can never be edited. When one has to be corrected — a mistyped entrant, a stock figure that was wrong — the correction is a new draw that points back at the original, and both stay online.', 'fishotel-misc-plugin' ); ?>
					</p>
				</td>
			</tr>
			<tr data-fh-seed-source-row hidden>
				<th scope="row"><label for="fh-seed-source"><?php esc_html_e( 'Seed source', 'fishotel-misc-plugin' ); ?></label></th>
				<td>
					<input type="text" id="fh-seed-source" name="seed_source" class="regular-text"
						placeholder="<?php esc_attr_e( 'Closing Powerball numbers for Sat Aug 22', 'fishotel-misc-plugin' ); ?>" />
					<p class="description"><?php esc_html_e( 'Announce this in the thread before entries close. It is shown on the public page.', 'fishotel-misc-plugin' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Create draw', 'fishotel-misc-plugin' ) ); ?>
	</form>
</div>
