<?php
/**
 * Lottery Draw admin — one draw, through its lifecycle.
 *
 * Available: $post (WP_Post), $payload (array), $notice.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

$fh_editable  = Store::is_editable( $payload );
$fh_published = Store::is_published( $payload );
$fh_committed = ( Lottery_Draw::STATUS_COMMITTED === $payload['status'] );
$fh_external  = ( Lottery_Draw::SEED_EXTERNAL === $payload['seed_method'] );
$fh_action    = admin_url( 'admin-post.php' );
?>
<div class="wrap fh-draw-admin">
	<h1>
		<?php echo esc_html( $payload['title'] ); ?>
		<span class="fh-draw-status is-<?php echo esc_attr( $payload['status'] ); ?>"><?php echo esc_html( $payload['status'] ); ?></span>
	</h1>

	<p>
		<a href="<?php echo esc_url( add_query_arg( 'page', Lottery_Draw::MENU_SLUG, admin_url( 'admin.php' ) ) ); ?>">
			&larr; <?php esc_html_e( 'All draws', 'fishotel-misc-plugin' ); ?>
		</a>
		&nbsp;|&nbsp; <code><?php echo esc_html( $payload['id'] ); ?></code>
		&nbsp;|&nbsp; <code>[fishotel_draw id="<?php echo esc_attr( $payload['id'] ); ?>"]</code>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $fh_published ) : ?>
		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'This draw is published and immutable.', 'fishotel-misc-plugin' ); ?></strong>
				<?php esc_html_e( 'Seed, entrants, and results can no longer be changed — that is what makes it evidence. A correction is published as a new draw referencing this one.', 'fishotel-misc-plugin' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( '1. Seed', 'fishotel-misc-plugin' ); ?></h2>

	<?php if ( $fh_editable ) : ?>
		<form method="post" action="<?php echo esc_url( $fh_action ); ?>" class="fh-draw-form">
			<?php wp_nonce_field( 'fishotel_draw_update_seed' ); ?>
			<input type="hidden" name="action" value="fishotel_draw_update_seed" />
			<input type="hidden" name="draw_id" value="<?php echo esc_attr( $payload['id'] ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Method', 'fishotel-misc-plugin' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="seed_method" value="<?php echo esc_attr( Lottery_Draw::SEED_COMMIT_EARLY ); ?>" data-fh-seed-method <?php checked( ! $fh_external ); ?> />
								<?php esc_html_e( 'Commit early — post the seed to the thread before entries close.', 'fishotel-misc-plugin' ); ?>
							</label><br />
							<label>
								<input type="radio" name="seed_method" value="<?php echo esc_attr( Lottery_Draw::SEED_EXTERNAL ); ?>" data-fh-seed-method <?php checked( $fh_external ); ?> />
								<?php esc_html_e( 'External randomness — announce the source in advance, enter the value once it exists.', 'fishotel-misc-plugin' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fh-seed"><?php esc_html_e( 'Seed', 'fishotel-misc-plugin' ); ?></label></th>
					<td>
						<input type="text" id="fh-seed" name="seed" class="regular-text code" data-fh-seed-field
							value="<?php echo esc_attr( $payload['seed'] ); ?>" />
						<button type="button" class="button" data-fh-generate-seed><?php esc_html_e( 'Generate', 'fishotel-misc-plugin' ); ?></button>
					</td>
				</tr>
				<tr data-fh-seed-source-row <?php echo $fh_external ? '' : 'hidden'; ?>>
					<th scope="row"><label for="fh-seed-source"><?php esc_html_e( 'Seed source', 'fishotel-misc-plugin' ); ?></label></th>
					<td>
						<input type="text" id="fh-seed-source" name="seed_source" class="regular-text"
							value="<?php echo esc_attr( $payload['seed_source'] ); ?>"
							placeholder="<?php esc_attr_e( 'Closing Powerball numbers for Sat Aug 22', 'fishotel-misc-plugin' ); ?>" />
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save seed', 'fishotel-misc-plugin' ), 'secondary' ); ?>
		</form>
	<?php else : ?>
		<table class="widefat fh-draw-meta">
			<tr>
				<th scope="row"><?php esc_html_e( 'Seed', 'fishotel-misc-plugin' ); ?></th>
				<td><code><?php echo esc_html( $payload['seed'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Method', 'fishotel-misc-plugin' ); ?></th>
				<td>
					<?php echo esc_html( $fh_external ? __( 'External randomness', 'fishotel-misc-plugin' ) : __( 'Committed early', 'fishotel-misc-plugin' ) ); ?>
					<?php if ( $fh_external && $payload['seed_source'] ) : ?>
						— <?php echo esc_html( $payload['seed_source'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Committed', 'fishotel-misc-plugin' ); ?></th>
				<td><?php echo esc_html( Lottery_Draw::format_local( $payload['seed_published_at'] ) ); ?></td>
			</tr>
			<?php if ( $payload['drawn_at'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Drawn', 'fishotel-misc-plugin' ); ?></th>
					<td><?php echo esc_html( Lottery_Draw::format_local( $payload['drawn_at'] ) ); ?></td>
				</tr>
			<?php endif; ?>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( '2. Fish', 'fishotel-misc-plugin' ); ?></h2>

	<?php if ( empty( $payload['fish'] ) ) : ?>
		<p><?php esc_html_e( 'No fish in this draw yet.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<table class="widefat striped fh-draw-fish-list">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Slots', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Entrants (posting order)', 'fishotel-misc-plugin' ); ?></th>
					<?php if ( $fh_published ) : ?>
						<th scope="col"><?php esc_html_e( 'Winners', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Waitlist', 'fishotel-misc-plugin' ); ?></th>
					<?php elseif ( $fh_editable ) : ?>
						<th scope="col"><?php esc_html_e( 'Remove', 'fishotel-misc-plugin' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $payload['fish'] as $fh_index => $fh_fish ) : ?>
					<?php
					$fh_slots   = Store::slots_for( $fh_fish );
					$fh_tickets = Store::ticket_count( $fh_fish );
					$fh_names   = array();

					foreach ( (array) $fh_fish['entrants'] as $fh_entrant ) {
						$fh_names[] = $fh_entrant['name'] . ( $fh_entrant['want'] > 1 ? ' ×' . $fh_entrant['want'] : '' );
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $fh_fish['name'] ); ?></strong>
							<?php if ( $fh_fish['sci'] ) : ?>
								<br /><em><?php echo esc_html( $fh_fish['sci'] ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $fh_fish['stock'] ); ?>
							<?php if ( (int) $fh_fish['groupSize'] > 1 ) : ?>
								<br /><span class="description">
									<?php
									printf(
										/* translators: %d: individuals per group */
										esc_html__( 'groups of %d', 'fishotel-misc-plugin' ),
										(int) $fh_fish['groupSize']
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $fh_slots ); ?>
							<br />
							<span class="description">
								<?php
								printf(
									/* translators: %d: tickets requested */
									esc_html__( '%d requested', 'fishotel-misc-plugin' ),
									(int) $fh_tickets
								);
								?>
								<?php if ( Store::is_uncontested( $fh_fish ) ) : ?>
									<br /><?php esc_html_e( 'uncontested', 'fishotel-misc-plugin' ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td class="fh-draw-entrant-cell"><?php echo esc_html( implode( ', ', $fh_names ) ); ?></td>
						<?php if ( $fh_published ) : ?>
							<td><?php echo esc_html( Store::format_names( (array) $fh_fish['winners'] ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $fh_fish['waitlist'] ) ); ?></td>
						<?php elseif ( $fh_editable ) : ?>
							<td>
								<form method="post" action="<?php echo esc_url( $fh_action ); ?>"
									data-fh-confirm="<?php esc_attr_e( 'Remove this fish from the draw?', 'fishotel-misc-plugin' ); ?>">
									<?php wp_nonce_field( 'fishotel_draw_remove_fish' ); ?>
									<input type="hidden" name="action" value="fishotel_draw_remove_fish" />
									<input type="hidden" name="draw_id" value="<?php echo esc_attr( $payload['id'] ); ?>" />
									<input type="hidden" name="fish_index" value="<?php echo esc_attr( $fh_index ); ?>" />
									<button type="submit" class="button-link delete"><?php esc_html_e( 'Remove', 'fishotel-misc-plugin' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $fh_editable ) : ?>
		<p class="fh-draw-toolbar">
			<a class="button button-secondary"
				href="<?php echo esc_url( add_query_arg( array( 'page' => Lottery_Draw::MENU_SLUG, 'import' => '1', 'draw' => $payload['id'] ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Import from JSON', 'fishotel-misc-plugin' ); ?>
			</a>
			<span class="description"><?php esc_html_e( 'Replaces the fish and entrant lists above with a pasted payload.', 'fishotel-misc-plugin' ); ?></span>
		</p>

		<h3><?php esc_html_e( 'Add a fish', 'fishotel-misc-plugin' ); ?></h3>

		<form method="post" action="<?php echo esc_url( $fh_action ); ?>" class="fh-draw-form">
			<?php wp_nonce_field( 'fishotel_draw_add_fish' ); ?>
			<input type="hidden" name="action" value="fishotel_draw_add_fish" />
			<input type="hidden" name="draw_id" value="<?php echo esc_attr( $payload['id'] ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fh-fish-name"><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></label></th>
					<td>
						<input type="text" id="fh-fish-name" name="fish_name" class="regular-text" required />
						<p class="description"><?php esc_html_e( 'The draw seeds its randomness from this name, so it must be unique within the draw — and renaming a fish after the draw would change its winners.', 'fishotel-misc-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fh-fish-sci"><?php esc_html_e( 'Scientific name', 'fishotel-misc-plugin' ); ?></label></th>
					<td><input type="text" id="fh-fish-sci" name="fish_sci" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="fh-fish-stock"><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?></label></th>
					<td>
						<input type="number" id="fh-fish-stock" name="fish_stock" class="small-text" min="0" value="1" required />
						<p class="description"><?php esc_html_e( 'Individuals we expect to land.', 'fishotel-misc-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fh-fish-group"><?php esc_html_e( 'Group size', 'fishotel-misc-plugin' ); ?></label></th>
					<td>
						<input type="number" id="fh-fish-group" name="fish_group_size" class="small-text" min="1" value="1" required />
						<p class="description"><?php esc_html_e( 'Group species are allocated as groups. 120 anthias at a group size of 5 is 24 slots, not 120.', 'fishotel-misc-plugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fh-fish-entrants"><?php esc_html_e( 'Entrants', 'fishotel-misc-plugin' ); ?></label></th>
					<td>
						<textarea id="fh-fish-entrants" name="fish_entrants" rows="10" class="large-text code"
							placeholder="twosixpax x3&#10;Tacos_coffee x3&#10;gmdcdvm&#10;daduc"></textarea>
						<p class="description">
							<?php esc_html_e( 'One per line, in posting order. Add "x3" for three fish; no suffix means one. The same handle twice is merged and the counts added.', 'fishotel-misc-plugin' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Add fish', 'fishotel-misc-plugin' ), 'secondary' ); ?>
		</form>

		<h2><?php esc_html_e( '3. Commit the seed', 'fishotel-misc-plugin' ); ?></h2>

		<p class="fh-draw-lede">
			<?php esc_html_e( 'Committing locks the seed and the entrant lists and stamps the time. Do this before entries close (commit early) or before the external value exists (external randomness) — after that, nothing can be tuned to change who wins.', 'fishotel-misc-plugin' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( $fh_action ); ?>"
			data-fh-confirm="<?php esc_attr_e( 'Commit the seed? Entrants and seed can no longer be edited after this.', 'fishotel-misc-plugin' ); ?>">
			<?php wp_nonce_field( 'fishotel_draw_commit_seed' ); ?>
			<input type="hidden" name="action" value="fishotel_draw_commit_seed" />
			<input type="hidden" name="draw_id" value="<?php echo esc_attr( $payload['id'] ); ?>" />
			<?php submit_button( __( 'Commit seed', 'fishotel-misc-plugin' ), 'primary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Delete', 'fishotel-misc-plugin' ); ?></h2>

		<form method="post" action="<?php echo esc_url( $fh_action ); ?>"
			data-fh-confirm="<?php esc_attr_e( 'Delete this draft draw? This cannot be undone.', 'fishotel-misc-plugin' ); ?>">
			<?php wp_nonce_field( 'fishotel_draw_delete' ); ?>
			<input type="hidden" name="action" value="fishotel_draw_delete" />
			<input type="hidden" name="draw_id" value="<?php echo esc_attr( $payload['id'] ); ?>" />
			<?php submit_button( __( 'Delete draft draw', 'fishotel-misc-plugin' ), 'delete', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<?php if ( $fh_committed ) : ?>
		<h2><?php esc_html_e( '4. Run the draw', 'fishotel-misc-plugin' ); ?></h2>

		<p class="fh-draw-lede">
			<?php esc_html_e( 'The draw runs here, in your browser, with the same draw.js the public page loads. WordPress stores the result — it never computes one. Publishing is final.', 'fishotel-misc-plugin' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( $fh_action ); ?>" data-fh-run-form>
			<?php wp_nonce_field( 'fishotel_draw_publish' ); ?>
			<input type="hidden" name="action" value="fishotel_draw_publish" />
			<input type="hidden" name="draw_id" value="<?php echo esc_attr( $payload['id'] ); ?>" />
			<input type="hidden" name="results" value="" data-fh-results-field />

			<p>
				<button type="button" class="button button-secondary" data-fh-run-draw>
					<?php esc_html_e( 'Run draw in this browser', 'fishotel-misc-plugin' ); ?>
				</button>
				<button type="submit" class="button button-primary" data-fh-publish disabled>
					<?php esc_html_e( 'Publish results', 'fishotel-misc-plugin' ); ?>
				</button>
			</p>

			<p class="fh-draw-run-status" data-fh-run-status></p>
		</form>

		<div class="fh-draw-preview" data-fh-preview></div>
	<?php endif; ?>

	<?php $fh_log = Store::get_log( $post->ID ); ?>
	<?php if ( $fh_log ) : ?>
		<h2><?php esc_html_e( 'Import log', 'fishotel-misc-plugin' ); ?></h2>

		<table class="widefat striped fh-draw-log">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Imported', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $fh_log ) as $fh_entry ) : ?>
					<tr>
						<td><?php echo esc_html( Lottery_Draw::format_local( $fh_entry['at'] ) ); ?></td>
						<td><?php echo esc_html( $fh_entry['user'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								'import-replace' === $fh_entry['action']
									? __( 'Replaced the fish list by import', 'fishotel-misc-plugin' )
									: __( 'Created by import', 'fishotel-misc-plugin' )
							);
							?>
						</td>
						<td>
							<?php
							printf(
								/* translators: 1: fish count, 2: ticket count, 3: people count */
								esc_html__( '%1$d fish, %2$d tickets, %3$d people', 'fishotel-misc-plugin' ),
								(int) $fh_entry['fish'],
								(int) $fh_entry['tickets'],
								(int) $fh_entry['people']
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $fh_published ) : ?>
		<h2><?php esc_html_e( 'Publish it', 'fishotel-misc-plugin' ); ?></h2>

		<p>
			<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" class="button"><?php esc_html_e( 'Public results page', 'fishotel-misc-plugin' ); ?></a>
			<a href="<?php echo esc_url( Store::json_url( $payload['id'] ) ); ?>" class="button"><?php esc_html_e( 'Raw JSON payload', 'fishotel-misc-plugin' ); ?></a>
		</p>

		<h3><?php esc_html_e( 'Copy as BBCode', 'fishotel-misc-plugin' ); ?></h3>
		<p class="description"><?php esc_html_e( 'For the Humble.fish thread. No spoilers — the whole point is that people can see it.', 'fishotel-misc-plugin' ); ?></p>

		<textarea id="fh-draw-bbcode" class="large-text code" rows="14" readonly data-fh-bbcode><?php echo esc_textarea( Store::to_bbcode( $payload ) ); ?></textarea>

		<p>
			<button type="button" class="button button-secondary" data-fh-copy="#fh-draw-bbcode">
				<?php esc_html_e( 'Copy BBCode', 'fishotel-misc-plugin' ); ?>
			</button>
		</p>
	<?php endif; ?>
</div>
