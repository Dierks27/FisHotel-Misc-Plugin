<?php
/**
 * The Requests list: the "Needs you" strip, the filters, and every
 * request grouped by fish (the default) or as a flat list. Each row opens
 * the request's own page.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string  $tab         Current tab.
 * @var array   $ctx         Admin::context().
 * @var array   $flash       Admin::take_flash().
 * @var array   $needs       Listing::needs_you().
 * @var array[] $sheets      Listing::sheets() of the new requests.
 * @var array   $listing     Admin::listing().
 * @var array   $filters     state, source, came_from, search, line_id.
 * @var string  $view        groups|list.
 * @var string  $sort        The sort asked for.
 * @var string  $dir         asc|desc.
 * @var array   $link_labels Private link id → label.
 * @var string[] $came_froms The "came from" values to filter on.
 * @var string  $last_run    The last daily check in words ('' = never).
 * @var int     $last_at     When it ran (UNIX time).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Listing;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Service;
use FisHotel\Misc\Sections\Requests\Urls;

$fh_now    = (int) $ctx['now'];
$fh_symbol = Urls::currency_symbol();
$fh_here   = Admin::page_url(
	'list',
	array_filter(
		array(
			'view'  => 'list' === $view ? 'list' : '',
			'state' => $filters['state'],
			'source' => $filters['source'],
			'from'  => $filters['came_from'],
			's'     => $filters['search'],
			'sort'  => $sort,
			'dir'   => 'desc' === $dir ? 'desc' : '',
			'line'  => $filters['line_id'] > 0 ? $filters['line_id'] : '',
		),
		'strlen'
	)
);

/**
 * The hidden fields of every action form.
 *
 * @param string $op   The operation.
 * @param string $back Where to come back to.
 */
$fh_fields = static function ( $op, $back ) {
	wp_nonce_field( Admin::NONCE );
	echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION ) . '">';
	echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
};

/**
 * One request as a table row.
 *
 * @param array $row The request.
 */
$fh_row = static function ( array $row ) use ( $fh_now, $link_labels, $fh_symbol ) {
	$created = Rules::ts( $row['created_at'] );
	$from    = array( Rules::source_label( $row['source'] ) );
	if ( '' !== $row['came_from'] ) {
		$from[] = $row['came_from'];
	}
	if ( (int) $row['link_id'] > 0 && isset( $link_labels[ (int) $row['link_id'] ] ) ) {
		$from[] = '“' . $link_labels[ (int) $row['link_id'] ] . '”';
	}
	?>
	<tr class="fh-rq-row fh-rq-row--<?php echo esc_attr( $row['state'] ); ?>">
		<td class="fh-rq-num"><a href="<?php echo esc_url( Admin::request_url( $row['id'] ) ); ?>">#<?php echo esc_html( (string) $row['id'] ); ?></a></td>
		<td>
			<strong><?php echo esc_html( Rules::who( $row ) ); ?></strong>
			<?php if ( '' !== $row['handle'] && '' !== $row['name'] ) : ?>
				<span class="fh-rq-sub fh-rq-sub--inline"><?php echo esc_html( $row['handle'] ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $row['email'] ) : ?>
				<span class="fh-rq-sub"><?php echo esc_html( $row['email'] ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<?php echo esc_html( Rules::fish_line( $row ) ); ?>
			<?php if ( '' !== $row['note'] ) : ?>
				<span class="fh-rq-sub"><?php echo esc_html( Rules::cut( str_replace( "\n", ' ', $row['note'] ), 90 ) ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<?php echo Admin::badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge(). ?>
			<?php if ( Rules::fee_open( $row ) && (float) $row['fee_amount'] > 0 ) : ?>
				<span class="fh-rq-sub"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'fee %s', 'fishotel-misc-plugin' ), Rules::money( $row['fee_amount'], $fh_symbol ) ) ); ?></span>
			<?php endif; ?>
		</td>
		<td title="<?php echo esc_attr( Admin::when( $row['created_at'] ) ); ?>"><?php echo esc_html( Rules::age_words( $created, $fh_now ) ); ?></td>
		<td class="fh-rq-sub--cell"><?php echo esc_html( implode( ' · ', $from ) ); ?></td>
		<td class="fh-rq-row__open"><a class="button button-small" href="<?php echo esc_url( Admin::request_url( $row['id'] ) ); ?>"><?php esc_html_e( 'Open', 'fishotel-misc-plugin' ); ?></a></td>
	</tr>
	<?php
};

$fh_head = static function () {
	?>
	<thead>
		<tr>
			<th scope="col" class="fh-rq-num">#</th>
			<th scope="col"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Asked', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Came through', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"></th>
		</tr>
	</thead>
	<?php
};
?>
<div class="wrap fishotel-misc-wrap fh-rq-wrap">
	<?php include Requests::path() . 'views/admin-tabs.php'; ?>

	<div class="fh-rq-toolbar fh-rq-toolbar--status">
		<span class="fh-rq-status">
			<?php if ( $ctx['fees']['available'] ) : ?>
				<?php
				/* translators: 1: product title, 2: product id */
				echo esc_html( sprintf( __( 'Fees go into the customer\'s wallet through Batch Manager (deposit product "%1$s", #%2$d).', 'fishotel-misc-plugin' ), $ctx['fees']['product_title'], $ctx['fees']['product_id'] ) );
				?>
			<?php endif; ?>
			<?php if ( '' !== $last_run ) : ?>
				<span class="fh-rq-status__run">
					<?php
					/* translators: 1: date and time, 2: what the check did */
					echo esc_html( sprintf( __( 'Last check %1$s: %2$s.', 'fishotel-misc-plugin' ), Admin::when( gmdate( 'Y-m-d H:i:s', $last_at ) ), $last_run ) );
					?>
				</span>
			<?php else : ?>
				<span class="fh-rq-status__run"><?php esc_html_e( 'The daily check has not run yet.', 'fishotel-misc-plugin' ); ?></span>
			<?php endif; ?>
		</span>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-rq-inline">
			<?php $fh_fields( 'run', $fh_here ); ?>
			<button type="submit" class="button" title="<?php esc_attr_e( 'Follow the fish, tell people whose fish is ready (when Settings says "by themselves"), send the fee reminder, close what ran out of time — what the daily job does every morning.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Run the daily check now', 'fishotel-misc-plugin' ); ?></button>
		</form>
	</div>

	<?php if ( $needs['count'] > 0 ) : ?>
		<section class="fishotel-misc-card fh-rq-card fh-rq-needs" id="fh-rq-needs">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-flag"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Needs you', 'fishotel-misc-plugin' ); ?> <span class="fh-rq-count"><?php echo esc_html( (string) $needs['count'] ); ?></span></h2>
			</div>

			<?php if ( $sheets ) : ?>
				<h3 class="fh-rq-needs__title"><?php esc_html_e( 'New requests to approve', 'fishotel-misc-plugin' ); ?></h3>
				<?php foreach ( $sheets as $fh_sheet ) : ?>
					<?php $fh_first = $fh_sheet['rows'][0]; ?>
					<div class="fh-rq-sheet">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php $fh_fields( 'approve', $fh_here ); ?>
							<p class="fh-rq-sheet__who">
								<strong><?php echo esc_html( $fh_sheet['who'] ); ?></strong>
								<?php if ( '' !== $fh_sheet['email'] ) : ?>
									<span class="fh-rq-sub fh-rq-sub--inline"><?php echo esc_html( $fh_sheet['email'] ); ?></span>
								<?php endif; ?>
								<span class="fh-rq-sub fh-rq-sub--inline">
									<?php
									echo esc_html( Rules::source_label( $fh_first['source'] ) );
									if ( (int) $fh_first['link_id'] > 0 && isset( $link_labels[ (int) $fh_first['link_id'] ] ) ) {
										echo esc_html( ' “' . $link_labels[ (int) $fh_first['link_id'] ] . '”' );
									}
									echo esc_html( ' · ' . Rules::age_words( Rules::ts( $fh_first['created_at'] ), $fh_now ) );
									?>
								</span>
							</p>
							<ul class="fh-rq-sheet__rows">
								<?php foreach ( $fh_sheet['rows'] as $fh_r ) : ?>
									<li>
										<label>
											<input type="checkbox" name="ids[]" value="<?php echo esc_attr( $fh_r['id'] ); ?>" checked>
											<?php echo esc_html( Rules::fish_line( $fh_r ) ); ?>
										</label>
										<?php if ( '' !== $fh_r['note'] ) : ?>
											<span class="fh-rq-sub fh-rq-sub--inline">— <?php echo esc_html( Rules::cut( str_replace( "\n", ' ', $fh_r['note'] ), 140 ) ); ?></span>
										<?php endif; ?>
										<a class="fh-rq-sub fh-rq-sub--inline" href="<?php echo esc_url( Admin::request_url( $fh_r['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_r['id'] ); ?> — <?php esc_html_e( 'open, edit or decline', 'fishotel-misc-plugin' ); ?></a>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="fh-rq-sheet__actions">
								<label>
									<?php esc_html_e( 'Fee for this sheet (one bill for the ticked requests)', 'fishotel-misc-plugin' ); ?>
									<input type="text" class="fh-rq-input fh-rq-input--money" name="fee" value="<?php echo esc_attr( $fh_sheet['fee'] > 0 && $ctx['fees']['available'] ? number_format( $fh_sheet['fee'], 2, '.', '' ) : '' ); ?>" placeholder="0.00" inputmode="decimal" <?php disabled( ! $ctx['fees']['available'] ); ?>>
								</label>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve ticked', 'fishotel-misc-plugin' ); ?></button>
								<span class="fh-rq-sub fh-rq-sub--inline">
									<?php
									echo esc_html(
										$ctx['fees']['available']
											? __( 'No fee: they go on the list at once. With a fee: one e-mail with a pay link; they go on the list when it is paid.', 'fishotel-misc-plugin' )
											: __( 'Fees are unavailable, so approving puts them on the list at once.', 'fishotel-misc-plugin' )
									);
									?>
								</span>
							</p>
						</form>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( $needs['tell'] ) : ?>
				<h3 class="fh-rq-needs__title"><?php esc_html_e( 'Fish that are ready — people to tell', 'fishotel-misc-plugin' ); ?></h3>
				<table class="widefat fh-rq-table">
					<tbody>
						<?php foreach ( $needs['tell'] as $fh_r ) : ?>
							<?php $fh_blocker = $ctx['service']->offer_blocker( $fh_r ); ?>
							<tr>
								<td class="fh-rq-num"><a href="<?php echo esc_url( Admin::request_url( $fh_r['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_r['id'] ); ?></a></td>
								<td><strong><?php echo esc_html( Rules::who( $fh_r ) ); ?></strong><span class="fh-rq-sub"><?php echo esc_html( $fh_r['email'] ); ?></span></td>
								<td><?php echo esc_html( Rules::fish_line( $fh_r ) ); ?> <?php echo ! empty( $fh_r['owed'] ) ? '<span class="fh-rq-badge fh-rq-badge--owed">' . esc_html__( 'owed', 'fishotel-misc-plugin' ) . '</span>' : ''; ?></td>
								<td class="fh-rq-sub--cell">
									<?php
									if ( '' !== $fh_blocker ) {
										echo esc_html( Service::mail_words( $fh_blocker, __( 'The "ready" e-mail', 'fishotel-misc-plugin' ), false ) );
									} elseif ( ! empty( $fh_r['offered_at'] ) ) {
										esc_html_e( 'The e-mail failed when the daily check tried — try again, or tell them yourself.', 'fishotel-misc-plugin' );
									} elseif ( empty( $ctx['settings']['auto_offer'] ) ) {
										esc_html_e( '"Tell people by themselves" is off in Settings.', 'fishotel-misc-plugin' );
									} else {
										esc_html_e( 'The daily check will tell them tomorrow morning — or do it now.', 'fishotel-misc-plugin' );
									}
									?>
								</td>
								<td class="fh-rq-row__open">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-rq-inline">
										<?php $fh_fields( 'tell', $fh_here ); ?>
										<input type="hidden" name="id" value="<?php echo esc_attr( $fh_r['id'] ); ?>">
										<button type="submit" class="button button-primary button-small" <?php disabled( '' !== $fh_blocker ); ?>><?php esc_html_e( 'Tell them (e-mail)', 'fishotel-misc-plugin' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-rq-inline">
										<?php $fh_fields( 'told', $fh_here ); ?>
										<input type="hidden" name="id" value="<?php echo esc_attr( $fh_r['id'] ); ?>">
										<button type="submit" class="button button-small" title="<?php esc_attr_e( 'You told them some other way: no e-mail is sent.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Told them', 'fishotel-misc-plugin' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $needs['overdue'] ) : ?>
				<h3 class="fh-rq-needs__title"><?php esc_html_e( 'Payments overdue', 'fishotel-misc-plugin' ); ?></h3>
				<table class="widefat fh-rq-table">
					<tbody>
						<?php foreach ( $needs['overdue'] as $fh_r ) : ?>
							<tr>
								<td class="fh-rq-num"><a href="<?php echo esc_url( Admin::request_url( $fh_r['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_r['id'] ); ?></a></td>
								<td><strong><?php echo esc_html( Rules::who( $fh_r ) ); ?></strong><span class="fh-rq-sub"><?php echo esc_html( $fh_r['email'] ); ?></span></td>
								<td><?php echo esc_html( Rules::fish_line( $fh_r ) ); ?></td>
								<td class="fh-rq-sub--cell">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: how long ago, 2: days left */
											_n( 'Billed %1$s ago; closes by itself in %2$d day.', 'Billed %1$s ago; closes by itself in %2$d days.', Rules::fee_days_left( $fh_r, $fh_now, (int) $ctx['settings']['expire_days'] ), 'fishotel-misc-plugin' ),
											Rules::age_words( Rules::ts( $fh_r['approved_at'] ), $fh_now ),
											Rules::fee_days_left( $fh_r, $fh_now, (int) $ctx['settings']['expire_days'] )
										)
									);
									?>
								</td>
								<td class="fh-rq-row__open"><a class="button button-small" href="<?php echo esc_url( Admin::request_url( $fh_r['id'] ) ); ?>"><?php esc_html_e( 'Open', 'fishotel-misc-plugin' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $needs['owed'] ) : ?>
				<h3 class="fh-rq-needs__title"><?php esc_html_e( 'Owed fish that are ready — to send', 'fishotel-misc-plugin' ); ?></h3>
				<table class="widefat fh-rq-table">
					<tbody>
						<?php foreach ( $needs['owed'] as $fh_r ) : ?>
							<tr>
								<td class="fh-rq-num"><a href="<?php echo esc_url( Admin::request_url( $fh_r['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_r['id'] ); ?></a></td>
								<td><strong><?php echo esc_html( Rules::who( $fh_r ) ); ?></strong><span class="fh-rq-sub"><?php echo esc_html( $fh_r['email'] ); ?></span></td>
								<td><?php echo esc_html( Rules::fish_line( $fh_r ) ); ?></td>
								<td class="fh-rq-sub--cell"><?php esc_html_e( 'Already paid for. Arrange the shipment, then mark it filled.', 'fishotel-misc-plugin' ); ?></td>
								<td class="fh-rq-row__open">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-rq-inline">
										<?php $fh_fields( 'fill', $fh_here ); ?>
										<input type="hidden" name="id" value="<?php echo esc_attr( $fh_r['id'] ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Mark filled', 'fishotel-misc-plugin' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-rq-toolbar">
		<input type="hidden" name="page" value="<?php echo esc_attr( Requests::MENU_SLUG ); ?>">
		<label class="fh-rq-filter">
			<span><?php esc_html_e( 'View', 'fishotel-misc-plugin' ); ?></span>
			<select class="fh-rq-input" name="view">
				<option value="groups" <?php selected( $view, 'groups' ); ?>><?php esc_html_e( 'grouped by fish', 'fishotel-misc-plugin' ); ?></option>
				<option value="list" <?php selected( $view, 'list' ); ?>><?php esc_html_e( 'one list', 'fishotel-misc-plugin' ); ?></option>
			</select>
		</label>
		<label class="fh-rq-filter">
			<span><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></span>
			<select class="fh-rq-input" name="state">
				<option value=""><?php esc_html_e( 'open (all)', 'fishotel-misc-plugin' ); ?></option>
				<?php foreach ( Rules::state_labels() as $fh_state => $fh_label ) : ?>
					<option value="<?php echo esc_attr( $fh_state ); ?>" <?php selected( $filters['state'], $fh_state ); ?>><?php echo esc_html( $fh_label ); ?></option>
				<?php endforeach; ?>
				<option value="closed" <?php selected( $filters['state'], 'closed' ); ?>><?php esc_html_e( 'closed (all)', 'fishotel-misc-plugin' ); ?></option>
				<option value="all" <?php selected( $filters['state'], 'all' ); ?>><?php esc_html_e( 'everything', 'fishotel-misc-plugin' ); ?></option>
			</select>
		</label>
		<label class="fh-rq-filter">
			<span><?php esc_html_e( 'Source', 'fishotel-misc-plugin' ); ?></span>
			<select class="fh-rq-input" name="source">
				<option value=""><?php esc_html_e( 'any', 'fishotel-misc-plugin' ); ?></option>
				<?php foreach ( Rules::source_labels() as $fh_source => $fh_label ) : ?>
					<option value="<?php echo esc_attr( $fh_source ); ?>" <?php selected( $filters['source'], $fh_source ); ?>><?php echo esc_html( $fh_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="fh-rq-filter">
			<span><?php esc_html_e( 'Came from', 'fishotel-misc-plugin' ); ?></span>
			<select class="fh-rq-input" name="from">
				<option value=""><?php esc_html_e( 'anywhere', 'fishotel-misc-plugin' ); ?></option>
				<?php foreach ( $came_froms as $fh_from ) : ?>
					<option value="<?php echo esc_attr( $fh_from ); ?>" <?php selected( Rules::name_key( $filters['came_from'] ), Rules::name_key( $fh_from ) ); ?>><?php echo esc_html( $fh_from ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="fh-rq-filter">
			<span><?php esc_html_e( 'Sort', 'fishotel-misc-plugin' ); ?></span>
			<select class="fh-rq-input" name="sort">
				<optgroup label="<?php esc_attr_e( 'grouped by fish', 'fishotel-misc-plugin' ); ?>">
					<option value="longest" <?php selected( $sort, 'longest' ); ?>><?php esc_html_e( 'longest wait first', 'fishotel-misc-plugin' ); ?></option>
					<option value="most" <?php selected( $sort, 'most' ); ?>><?php esc_html_e( 'most asked for first', 'fishotel-misc-plugin' ); ?></option>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'one list', 'fishotel-misc-plugin' ); ?>">
					<option value="created" <?php selected( $sort, 'created' ); ?>><?php esc_html_e( 'date asked', 'fishotel-misc-plugin' ); ?></option>
					<option value="state" <?php selected( $sort, 'state' ); ?>><?php esc_html_e( 'state', 'fishotel-misc-plugin' ); ?></option>
					<option value="qty" <?php selected( $sort, 'qty' ); ?>><?php esc_html_e( 'quantity', 'fishotel-misc-plugin' ); ?></option>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'either', 'fishotel-misc-plugin' ); ?>">
					<option value="name" <?php selected( $sort, 'name' ); ?>><?php esc_html_e( 'name (the fish when grouped, the person in one list)', 'fishotel-misc-plugin' ); ?></option>
					<option value="fish" <?php selected( $sort, 'fish' ); ?>><?php esc_html_e( 'fish (one list)', 'fishotel-misc-plugin' ); ?></option>
				</optgroup>
			</select>
		</label>
		<label class="fh-rq-filter">
			<span><?php esc_html_e( 'Order', 'fishotel-misc-plugin' ); ?></span>
			<select class="fh-rq-input" name="dir">
				<option value="asc" <?php selected( $dir, 'asc' ); ?>><?php esc_html_e( 'A → Z, oldest first', 'fishotel-misc-plugin' ); ?></option>
				<option value="desc" <?php selected( $dir, 'desc' ); ?>><?php esc_html_e( 'Z → A, newest first', 'fishotel-misc-plugin' ); ?></option>
			</select>
		</label>
		<label class="fh-rq-filter fh-rq-filter--grow">
			<span><?php esc_html_e( 'Search name, e-mail, handle or fish', 'fishotel-misc-plugin' ); ?></span>
			<input type="search" class="fh-rq-input" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
		</label>
		<?php if ( $filters['line_id'] > 0 ) : ?>
			<input type="hidden" name="line" value="<?php echo esc_attr( $filters['line_id'] ); ?>">
		<?php endif; ?>
		<div class="fh-rq-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Show', 'fishotel-misc-plugin' ); ?></button>
			<a class="button" href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Reset', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</form>

	<?php if ( $filters['line_id'] > 0 ) : ?>
		<p class="fh-rq-sub"><?php esc_html_e( 'Showing the requests matched to one supplier order line.', 'fishotel-misc-plugin' ); ?> <a href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Show all', 'fishotel-misc-plugin' ); ?></a></p>
	<?php endif; ?>

	<?php if ( $listing['capped'] ) : ?>
		<p class="fh-rq-sub"><?php echo esc_html( sprintf( /* translators: %d: rows */ __( 'Only the newest %d requests were read — narrow the state to see older ones.', 'fishotel-misc-plugin' ), \FisHotel\Misc\Sections\Requests\Store::FIND_LIMIT ) ); ?></p>
	<?php endif; ?>

	<?php if ( 0 === $listing['total'] ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-clipboard"></span>
			<p>
				<?php
				echo esc_html(
					'' === $filters['state'] && '' === $filters['search'] && '' === $filters['source'] && '' === $filters['came_from']
						? __( 'No open requests. Add one by hand, or make a private link and send it to somebody.', 'fishotel-misc-plugin' )
						: __( 'No request matches.', 'fishotel-misc-plugin' )
				);
				?>
			</p>
		</div>
	<?php elseif ( 'list' === $view ) : ?>
		<p class="fh-rq-sub">
			<?php
			/* translators: %d: requests */
			echo esc_html( sprintf( _n( '%d request', '%d requests', $listing['total'], 'fishotel-misc-plugin' ), $listing['total'] ) );
			?>
		</p>
		<table class="widefat fh-rq-table">
			<?php $fh_head(); ?>
			<tbody>
				<?php foreach ( $listing['rows'] as $fh_r ) : ?>
					<?php $fh_row( $fh_r ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $listing['pages'] > 1 ) : ?>
			<p class="fh-rq-pages">
				<?php for ( $fh_p = 1; $fh_p <= $listing['pages']; $fh_p++ ) : ?>
					<a class="fh-rq-page<?php echo $fh_p === $listing['paged'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'paged', $fh_p, $fh_here ) ); ?>"><?php echo esc_html( (string) $fh_p ); ?></a>
				<?php endfor; ?>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<p class="fh-rq-sub">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: requests, 2: fish */
					__( '%1$d requests for %2$d different fish. Click a fish to see who asked.', 'fishotel-misc-plugin' ),
					$listing['total'],
					count( $listing['groups'] )
				)
			);
			?>
		</p>
		<?php foreach ( $listing['groups'] as $fh_index => $fh_group ) : ?>
			<details class="fh-rq-group" <?php echo ( 1 === count( $listing['groups'] ) || '' !== $filters['search'] ) ? 'open' : ''; ?>>
				<summary><?php echo esc_html( $fh_group['title'] ); ?></summary>
				<table class="widefat fh-rq-table">
					<?php $fh_head(); ?>
					<tbody>
						<?php foreach ( $fh_group['rows'] as $fh_r ) : ?>
							<?php $fh_row( $fh_r ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
