<?php
/**
 * Public draw results — mobile-first, recomputed in the visitor's browser.
 *
 * Available: $payload (array), $instance (string), $json_url (string).
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

$fh_is_external = ( Lottery_Draw::SEED_EXTERNAL === $payload['seed_method'] );
$fh_supersedes  = ! empty( $payload['supersedes'] ) ? Store::find_post( $payload['supersedes'] ) : null;
$fh_correction  = Store::find_correction( $payload['id'] );
?>
<div class="fh-draw" id="<?php echo esc_attr( $instance ); ?>" data-fh-draw="<?php echo esc_attr( $instance ); ?>">

	<h2 class="fh-draw-title"><?php echo esc_html( $payload['title'] ); ?></h2>

	<?php if ( $fh_correction ) : ?>
		<p class="fh-draw-superseded">
			<?php esc_html_e( 'This draw was corrected and replaced.', 'fishotel-misc-plugin' ); ?>
			<a href="<?php echo esc_url( get_permalink( $fh_correction['post'] ) ); ?>">
				<?php echo esc_html( $fh_correction['payload']['title'] ); ?>
			</a>
			<?php esc_html_e( 'is the draw that counts. Everything below is kept online unchanged, because a record you can edit is not a record.', 'fishotel-misc-plugin' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $fh_supersedes ) : ?>
		<p class="fh-draw-corrects">
			<?php esc_html_e( 'This draw corrects an earlier one:', 'fishotel-misc-plugin' ); ?>
			<a href="<?php echo esc_url( get_permalink( $fh_supersedes ) ); ?>">
				<?php echo esc_html( get_the_title( $fh_supersedes ) ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php // 1. The seed, its commitment method, and when it was committed. ?>
	<div class="fh-draw-seedbox">
		<div class="fh-draw-seed-label"><?php esc_html_e( 'Seed', 'fishotel-misc-plugin' ); ?></div>
		<div class="fh-draw-seed"><?php echo esc_html( $payload['seed'] ); ?></div>

		<dl class="fh-draw-seedmeta">
			<dt><?php esc_html_e( 'How it was fixed', 'fishotel-misc-plugin' ); ?></dt>
			<dd>
				<?php if ( $fh_is_external ) : ?>
					<?php esc_html_e( 'External randomness — a public value nobody here controls, announced in advance and entered once it existed.', 'fishotel-misc-plugin' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Committed early — the seed was generated and posted to the thread before entries closed.', 'fishotel-misc-plugin' ); ?>
				<?php endif; ?>
			</dd>

			<?php if ( $fh_is_external && ! empty( $payload['seed_source'] ) ) : ?>
				<dt><?php esc_html_e( 'Source', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( $payload['seed_source'] ); ?></dd>
			<?php endif; ?>

			<dt><?php esc_html_e( 'Seed committed', 'fishotel-misc-plugin' ); ?></dt>
			<dd>
				<?php echo esc_html( Lottery_Draw::format_local( $payload['seed_published_at'] ) ); ?>
				<span class="fh-draw-utc"><?php echo esc_html( $payload['seed_published_at'] ); ?></span>
			</dd>

			<dt><?php esc_html_e( 'Draw run', 'fishotel-misc-plugin' ); ?></dt>
			<dd>
				<?php echo esc_html( Lottery_Draw::format_local( $payload['drawn_at'] ) ); ?>
				<span class="fh-draw-utc"><?php echo esc_html( $payload['drawn_at'] ); ?></span>
			</dd>
		</dl>
	</div>

	<?php // 2. Verification banner — filled in by the browser, never by us. ?>
	<div class="fh-draw-verify is-pending" data-fh-role="banner" role="status" aria-live="polite">
		<?php esc_html_e( 'Re-running the draw in your browser…', 'fishotel-misc-plugin' ); ?>
	</div>

	<div class="fh-draw-diff" data-fh-role="diff" hidden></div>

	<?php // 3. Results, per fish. ?>
	<?php foreach ( (array) $payload['fish'] as $fh_fish ) : ?>
		<?php
		$fh_slots      = Store::slots_for( $fh_fish );
		$fh_group_size = max( 1, (int) $fh_fish['groupSize'] );
		$fh_tickets    = Store::ticket_count( $fh_fish );
		?>
		<section class="fh-draw-fish">
			<h3 class="fh-draw-fish-name">
				<?php echo esc_html( $fh_fish['name'] ); ?>
				<?php if ( ! empty( $fh_fish['sci'] ) ) : ?>
					<em><?php echo esc_html( $fh_fish['sci'] ); ?></em>
				<?php endif; ?>
			</h3>

			<p class="fh-draw-stock">
				<?php if ( $fh_group_size > 1 ) : ?>
					<?php
					printf(
						/* translators: 1: individuals in stock, 2: number of groups, 3: individuals per group */
						esc_html__( 'Stock %1$d (%2$d groups of %3$d) — %2$d slots', 'fishotel-misc-plugin' ),
						(int) $fh_fish['stock'],
						(int) $fh_slots,
						(int) $fh_group_size
					);
					?>
				<?php else : ?>
					<?php
					printf(
						/* translators: 1: individuals in stock, 2: slots available */
						esc_html__( 'Stock %1$d — %2$d slots', 'fishotel-misc-plugin' ),
						(int) $fh_fish['stock'],
						(int) $fh_slots
					);
					?>
				<?php endif; ?>
				<?php
				printf(
					/* translators: %d: number of tickets in the draw */
					esc_html__( ', %d requested', 'fishotel-misc-plugin' ),
					(int) $fh_tickets
				);
				?>
			</p>

			<?php if ( Store::is_uncontested( $fh_fish ) ) : ?>
				<p class="fh-draw-uncontested"><?php esc_html_e( 'Uncontested — everyone who asked got theirs.', 'fishotel-misc-plugin' ); ?></p>
			<?php endif; ?>

			<h4 class="fh-draw-subhead"><?php esc_html_e( 'Winners', 'fishotel-misc-plugin' ); ?></h4>
			<?php if ( empty( $fh_fish['winners'] ) ) : ?>
				<p class="fh-draw-empty"><?php esc_html_e( 'No slots were available for this fish.', 'fishotel-misc-plugin' ); ?></p>
			<?php else : ?>
				<ul class="fh-draw-winners">
					<?php foreach ( Store::tally( (array) $fh_fish['winners'] ) as $fh_winner ) : ?>
						<li>
							<?php echo esc_html( $fh_winner['name'] ); ?>
							<?php if ( $fh_winner['count'] > 1 ) : ?>
								<span class="fh-draw-count">(<?php echo esc_html( $fh_winner['count'] ); ?>)</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h4 class="fh-draw-subhead"><?php esc_html_e( 'Waitlist', 'fishotel-misc-plugin' ); ?></h4>
			<?php if ( empty( $fh_fish['waitlist'] ) ) : ?>
				<p class="fh-draw-empty"><?php esc_html_e( 'Empty — nobody missed out on this one.', 'fishotel-misc-plugin' ); ?></p>
			<?php else : ?>
				<ol class="fh-draw-waitlist">
					<?php foreach ( (array) $fh_fish['waitlist'] as $fh_name ) : ?>
						<li><?php echo esc_html( $fh_name ); ?></li>
					<?php endforeach; ?>
				</ol>
				<p class="fh-draw-note"><?php esc_html_e( 'Used in this order if a winner backs out or a fish does not make it. A name can appear more than once — that is one place in line per fish they asked for.', 'fishotel-misc-plugin' ); ?></p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<?php // 4. Every entrant, in posting order. The draw cannot be reproduced without this. ?>
	<section class="fh-draw-entrants">
		<h3><?php esc_html_e( 'Who was in the hat', 'fishotel-misc-plugin' ); ?></h3>
		<p class="fh-draw-note"><?php esc_html_e( 'In the order requests were posted. Order does not affect anybody\'s odds — the shuffle discards it — but it is here so you can check the list against the thread.', 'fishotel-misc-plugin' ); ?></p>

		<?php foreach ( (array) $payload['fish'] as $fh_fish ) : ?>
			<h4 class="fh-draw-subhead"><?php echo esc_html( $fh_fish['name'] ); ?></h4>
			<div class="fh-draw-scroll">
				<table class="fh-draw-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '#', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Member', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Requested (tickets)', 'fishotel-misc-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( (array) $fh_fish['entrants'] as $fh_i => $fh_entrant ) : ?>
							<tr>
								<td><?php echo esc_html( $fh_i + 1 ); ?></td>
								<td><?php echo esc_html( $fh_entrant['name'] ); ?></td>
								<td><?php echo esc_html( $fh_entrant['want'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	</section>

	<?php // 5. Verify it yourself. ?>
	<section class="fh-draw-howto">
		<h3><?php esc_html_e( 'Verify it yourself', 'fishotel-misc-plugin' ); ?></h3>

		<p>
			<?php esc_html_e( 'You do not have to take our word for any of this, and you do not need to be a programmer.', 'fishotel-misc-plugin' ); ?>
		</p>

		<ol class="fh-draw-steps">
			<li>
				<?php
				printf(
					/* translators: %s: the draw seed */
					esc_html__( 'The seed is %s. It was fixed and published before it could be tested against the entrant list — that is what stops us from trying seeds until we like the winners.', 'fishotel-misc-plugin' ),
					'<code>' . esc_html( $payload['seed'] ) . '</code>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Every person who asked for a fish gets one ticket per fish they asked for. The tickets are shuffled with a coin-flip machine that is driven entirely by the seed — same seed in, same shuffle out, on any computer, forever.', 'fishotel-misc-plugin' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'The first tickets out fill the available slots. The rest become the waitlist, in the order they came out.', 'fishotel-misc-plugin' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'The green banner at the top of this page is not something we typed. Your own browser just re-ran that shuffle from the seed and the entrant lists below, and compared its result to the published one. If they had disagreed by a single name, the banner would be red.', 'fishotel-misc-plugin' ); ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: link to the raw JSON payload */
					esc_html__( 'The exact data used is public: %s. Save it, compare it with a friend, or re-run it yourself.', 'fishotel-misc-plugin' ),
					'<a href="' . esc_url( $json_url ) . '" class="fh-draw-json-link">' . esc_html__( 'the raw draw file', 'fishotel-misc-plugin' ) . '</a>'
				);
				?>
			</li>
		</ol>

		<p class="fh-draw-note">
			<?php esc_html_e( 'Doing it by hand: the draw code that runs on this page is the same file we ran to produce the result — draw.js, loaded above. Nothing about the result is stored anywhere else that matters; if the numbers in the file changed, the banner turns red.', 'fishotel-misc-plugin' ); ?>
		</p>
	</section>
</div>
