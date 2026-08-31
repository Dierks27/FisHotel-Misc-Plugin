<?php
/**
 * Lottery Draw admin — bulk import of draw inputs from JSON.
 *
 * Available: $notice, $draw_id, $target_post, $target_payload, $state.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

$fh_report = isset( $state['report'] ) && is_array( $state['report'] ) ? $state['report'] : null;
$fh_raw    = isset( $state['payload'] ) ? (string) $state['payload'] : '';
$fh_ok     = ( $fh_report && ! empty( $fh_report['ok'] ) );
$fh_locked = ( $target_payload && ! Store::is_editable( $target_payload ) );
?>
<div class="wrap fh-draw-admin fh-draw-import">
	<h1><?php esc_html_e( 'Import from JSON', 'fishotel-misc-plugin' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( add_query_arg( 'page', Lottery_Draw::MENU_SLUG, admin_url( 'admin.php' ) ) ); ?>">
			&larr; <?php esc_html_e( 'All draws', 'fishotel-misc-plugin' ); ?>
		</a>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="fh-draw-lede">
		<?php esc_html_e( 'Paste the fish and entrant lists for a draw instead of retyping them. Typing fifteen fish and fifty entrant lines by hand is how a handle gets mistyped, and a mistyped handle is a person who silently loses their shot.', 'fishotel-misc-plugin' ); ?>
	</p>

	<div class="notice notice-warning inline fh-draw-import-rule">
		<p>
			<strong><?php esc_html_e( 'The importer supplies inputs only.', 'fishotel-misc-plugin' ); ?></strong>
			<?php esc_html_e( 'A payload carrying winners or a waitlist is refused outright, never quietly stripped — if results could be imported, anyone with admin access could paste a hand-picked winner list and publish it behind a green "verified in your browser" banner. Results only ever come out of the draw itself, run against a committed seed. Import also only ever fills a draft.', 'fishotel-misc-plugin' ); ?>
		</p>
	</div>

	<?php if ( $fh_locked ) : ?>
		<div class="notice notice-error inline">
			<p>
				<?php
				printf(
					/* translators: 1: draw title, 2: draw status */
					esc_html__( '%1$s is %2$s, not a draft. Import cannot touch it — a committed draw has locked its entrants on purpose, and a published one is evidence.', 'fishotel-misc-plugin' ),
					'<strong>' . esc_html( $target_payload['title'] ) . '</strong>',
					'<code>' . esc_html( $target_payload['status'] ) . '</code>'
				);
				?>
			</p>
		</div>
	<?php else : ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-fh-import-form>
			<?php wp_nonce_field( 'fishotel_draw_import' ); ?>
			<input type="hidden" name="action" value="fishotel_draw_import" />

			<?php if ( $target_payload ) : ?>
				<input type="hidden" name="draw_id" value="<?php echo esc_attr( $target_payload['id'] ); ?>" />

				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %s: draw title */
							esc_html__( 'Importing into the draft %s. This replaces its fish and entrant lists wholesale; its seed settings are left alone.', 'fishotel-misc-plugin' ),
							'<strong>' . esc_html( $target_payload['title'] ) . '</strong>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fh-import-title"><?php esc_html_e( 'Title', 'fishotel-misc-plugin' ); ?></label></th>
						<td>
							<input type="text" id="fh-import-title" name="new_title" class="regular-text"
								value="<?php echo esc_attr( $state['new_title'] ?? '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional — the payload\'s own title is used when this is blank.', 'fishotel-misc-plugin' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fh-import-id"><?php esc_html_e( 'Draw ID', 'fishotel-misc-plugin' ); ?></label></th>
						<td>
							<input type="text" id="fh-import-id" name="new_id" class="regular-text"
								value="<?php echo esc_attr( $state['new_id'] ?? '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional — the payload\'s own id is used when this is blank.', 'fishotel-misc-plugin' ); ?></p>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<p>
				<label for="fh-import-payload"><strong><?php esc_html_e( 'Payload', 'fishotel-misc-plugin' ); ?></strong></label>
			</p>
			<textarea id="fh-import-payload" name="payload" rows="18" class="large-text code" spellcheck="false"
				data-fh-import-payload
				placeholder="<?php esc_attr_e( '{ &quot;id&quot;: &quot;rvs-2026-08&quot;, &quot;title&quot;: …, &quot;fish&quot;: [ … ] }', 'fishotel-misc-plugin' ); ?>"><?php echo esc_textarea( $fh_raw ); ?></textarea>

			<p class="description">
				<?php
				printf(
					/* translators: %d: maximum payload size in KB */
					esc_html__( 'Up to %d KB. Entrant order is kept exactly as pasted — it is posting order, and members check it against the thread.', 'fishotel-misc-plugin' ),
					(int) round( Importer::MAX_BYTES / 1024 )
				);
				?>
			</p>

			<p class="fh-draw-import-actions">
				<button type="submit" class="button button-secondary" name="fh_step" value="validate">
					<?php esc_html_e( 'Validate', 'fishotel-misc-plugin' ); ?>
				</button>

				<button type="submit" class="button button-primary" name="fh_step" value="commit"
					data-fh-import-commit <?php disabled( ! $fh_ok ); ?>>
					<?php
					echo esc_html(
						$target_payload
							? __( 'Import into this draft', 'fishotel-misc-plugin' )
							: __( 'Create draft', 'fishotel-misc-plugin' )
					);
					?>
				</button>

				<span class="fh-draw-import-stale" data-fh-import-stale hidden>
					<?php esc_html_e( 'Payload changed — validate it again before importing.', 'fishotel-misc-plugin' ); ?>
				</span>

				<?php if ( ! $fh_report ) : ?>
					<span class="description"><?php esc_html_e( 'Validate first; importing stays disabled until a payload passes.', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
			</p>
		</form>

	<?php endif; ?>

	<?php if ( $fh_report && ! empty( $fh_report['errors'] ) ) : ?>
		<div class="fh-draw-import-errors">
			<h2><?php esc_html_e( 'Not imported', 'fishotel-misc-plugin' ); ?></h2>
			<p><?php esc_html_e( 'Nothing was written. Fix these and validate again — your paste is still in the box above.', 'fishotel-misc-plugin' ); ?></p>
			<ul>
				<?php foreach ( $fh_report['errors'] as $fh_error ) : ?>
					<li><?php echo esc_html( $fh_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $fh_ok ) : ?>
		<h2><?php esc_html_e( 'Preview', 'fishotel-misc-plugin' ); ?></h2>

		<p class="fh-draw-lede">
			<?php
			printf(
				/* translators: 1: number of fish, 2: number of tickets, 3: number of people */
				esc_html__( '%1$d fish, %2$d tickets, %3$d people. Nothing has been written yet — check this against the thread first.', 'fishotel-misc-plugin' ),
				(int) $fh_report['totals']['fish'],
				(int) $fh_report['totals']['tickets'],
				(int) $fh_report['totals']['people']
			);
			?>
		</p>

		<?php if ( ! empty( $fh_report['warnings'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Worth a second look — these do not block the import:', 'fishotel-misc-plugin' ); ?></strong></p>
				<ul class="fh-draw-import-warnings">
					<?php foreach ( $fh_report['warnings'] as $fh_warning ) : ?>
						<li><?php echo esc_html( $fh_warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="fh-draw-scroll">
			<table class="widefat striped fh-draw-preview-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Slots', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tickets', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'People', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $fh_report['preview'] as $fh_row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $fh_row['name'] ); ?></strong>
								<?php if ( $fh_row['sole'] ) : ?>
									<span class="fh-draw-flag" title="<?php esc_attr_e( 'One person holds every ticket', 'fishotel-misc-plugin' ); ?>">
										<?php esc_html_e( 'sole entrant', 'fishotel-misc-plugin' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $fh_row['stock'] ); ?>
								<?php if ( $fh_row['groupSize'] > 1 ) : ?>
									<br /><span class="description">
										<?php
										printf(
											/* translators: %d: individuals per group */
											esc_html__( 'groups of %d', 'fishotel-misc-plugin' ),
											(int) $fh_row['groupSize']
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $fh_row['slots'] ); ?></td>
							<td><?php echo esc_html( $fh_row['tickets'] ); ?></td>
							<td><?php echo esc_html( $fh_row['people'] ); ?></td>
							<td class="fh-draw-mode is-<?php echo esc_attr( $fh_row['mode'] ); ?>">
								<?php if ( 'draw' === $fh_row['mode'] ) : ?>
									<strong><?php esc_html_e( 'Draw', 'fishotel-misc-plugin' ); ?></strong> —
									<?php
									printf(
										/* translators: %d: number of tickets that will not win */
										esc_html__( '%d will miss out', 'fishotel-misc-plugin' ),
										(int) $fh_row['missing']
									);
									?>
								<?php elseif ( $fh_row['spare'] > 0 ) : ?>
									<strong><?php esc_html_e( 'Rank only', 'fishotel-misc-plugin' ); ?></strong> —
									<?php
									printf(
										/* translators: %d: slots left over */
										esc_html__( '%d spare', 'fishotel-misc-plugin' ),
										(int) $fh_row['spare']
									);
									?>
								<?php else : ?>
									<strong><?php esc_html_e( 'Rank only', 'fishotel-misc-plugin' ); ?></strong> —
									<?php esc_html_e( 'everyone wins, order sets priority', 'fishotel-misc-plugin' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<p class="description fh-draw-import-legend">
			<?php esc_html_e( 'A "rank only" fish is not a bug waiting to happen: tickets fit inside the slots, so everyone wins and the draw runs purely to fix the order. Its waitlist comes back empty, and that is correct — the order is what gets used if a fish is lost later.', 'fishotel-misc-plugin' ); ?>
		</p>
	<?php endif; ?>
</div>
