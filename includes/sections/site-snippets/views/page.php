<?php
/**
 * Snippets page — every snippet with its switch, the broken files, the
 * name clashes, the [code_snippet] mapping and the safe-mode help.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var array $index     Catalog index (fresh).
 * @var array $rows      slug => { snippet, status, error, skipped, code }.
 * @var array $safe      Loader::safe_mode().
 * @var array $held      slug => reason.
 * @var bool  $cs_active Whether Code Snippets is loaded.
 * @var array $map       Rules::shortcode_map().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Admin;
use FisHotel\Misc\Sections\Site_Snippets\Loader;

$fh_on_count = 0;
foreach ( $rows as $fh_row ) {
	if ( $fh_row['status']['on'] ) {
		$fh_on_count++;
	}
}
$fh_ago = static function ( $time ) {
	$age = max( 0, time() - (int) $time );
	if ( $age < 3600 ) {
		/* translators: %d: minutes */
		return sprintf( __( '%d min ago', 'fishotel-misc-plugin' ), (int) floor( $age / 60 ) );
	}
	if ( $age < 172800 ) {
		/* translators: %d: hours */
		return sprintf( __( '%d h ago', 'fishotel-misc-plugin' ), (int) floor( $age / 3600 ) );
	}
	/* translators: %d: days */
	return sprintf( __( '%d days ago', 'fishotel-misc-plugin' ), (int) floor( $age / 86400 ) );
};
?>
<div class="wrap fishotel-misc-wrap fh-snip-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Snippets', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<p class="fh-snip-intro">
		<?php esc_html_e( 'The site\'s own code, one file per snippet in the plugin\'s GitHub repository (includes/sections/site-snippets/snippets/). Switch a snippet here; change its code in GitHub — it arrives with the next plugin update. PHP snippets run at plugins_loaded priority 1, like Code Snippets.', 'fishotel-misc-plugin' ); ?>
	</p>

	<div class="fh-snip-facts">
		<span class="fh-snip-fact">
			<?php
			/* translators: 1: snippets switched on, 2: snippets */
			echo esc_html( sprintf( __( '%1$d of %2$d on', 'fishotel-misc-plugin' ), $fh_on_count, count( $rows ) ) );
			?>
		</span>
		<?php if ( ! empty( $index['broken'] ) ) : ?>
			<span class="fh-snip-fact is-bad">
				<?php
				/* translators: %d: broken files */
				echo esc_html( sprintf( _n( '%d broken file', '%d broken files', count( $index['broken'] ), 'fishotel-misc-plugin' ), count( $index['broken'] ) ) );
				?>
			</span>
		<?php endif; ?>
		<span class="fh-snip-fact<?php echo $cs_active ? ' is-warn' : ''; ?>">
			<?php
			echo $cs_active
				? esc_html__( 'Code Snippets plugin: active — a snippet imported from it waits until its Code Snippets copy is deactivated', 'fishotel-misc-plugin' )
				: esc_html__( 'Code Snippets plugin: not active', 'fishotel-misc-plugin' );
			?>
		</span>
		<?php if ( $safe['active'] ) : ?>
			<span class="fh-snip-fact is-bad"><?php esc_html_e( 'Safe mode: nothing runs', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
	</div>

	<div class="fh-snip-status" aria-live="polite"></div>

	<?php if ( empty( $rows ) ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-editor-code"></span>
			<p><?php esc_html_e( 'No snippet files yet. Add one to includes/sections/site-snippets/snippets/ in GitHub (see the section README).', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped fh-snip-table">
			<thead>
				<tr>
					<th class="fh-snip-col-switch"><?php esc_html_e( 'On', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Snippet', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Type', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'fishotel-misc-plugin' ); ?></th>
					<th class="fh-snip-num"><?php esc_html_e( 'Priority', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Origin', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'File', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $fh_slug => $fh_row ) : ?>
					<?php
					$fh_s      = $fh_row['snippet'];
					$fh_status = $fh_row['status'];
					?>
					<tr class="fh-snip-row" data-slug="<?php echo esc_attr( $fh_slug ); ?>">
						<td class="fh-snip-col-switch">
							<label class="fishotel-misc-toggle" title="<?php echo esc_attr( $fh_status['locked'] ? __( 'Refused — see the status line', 'fishotel-misc-plugin' ) : __( 'Switch on or off', 'fishotel-misc-plugin' ) ); ?>">
								<input type="checkbox"
									data-snippet="<?php echo esc_attr( $fh_slug ); ?>"
									<?php checked( $fh_status['on'] ); ?>
									<?php disabled( $fh_status['locked'] && ! $fh_status['on'] ); ?>>
								<span class="slider"></span>
							</label>
						</td>
						<td>
							<strong class="fh-snip-name"><?php echo esc_html( $fh_s['name'] ); ?></strong>
							<?php if ( '' !== $fh_s['description'] ) : ?>
								<span class="fh-snip-desc"><?php echo esc_html( $fh_s['description'] ); ?></span>
							<?php endif; ?>
							<span class="fh-snip-state fh-snip-state--<?php echo esc_attr( $fh_status['key'] ); ?>" data-role="status"><?php echo esc_html( $fh_status['label'] ); ?></span>
							<?php if ( '' !== $fh_row['skipped'] ) : ?>
								<span class="fh-snip-state fh-snip-state--refused"><?php echo esc_html( __( 'Not loaded on this request', 'fishotel-misc-plugin' ) . ' — ' . $fh_row['skipped'] ); ?></span>
							<?php endif; ?>
							<?php if ( is_array( $fh_row['error'] ) ) : ?>
								<span class="fh-snip-error">
									<?php
									/* translators: 1: age, 2: message */
									echo esc_html( sprintf( __( 'Last error %1$s: %2$s', 'fishotel-misc-plugin' ), $fh_ago( $fh_row['error']['time'] ), $fh_row['error']['message'] ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( '' !== $fh_row['code'] ) : ?>
								<details class="fh-snip-code">
									<summary><?php esc_html_e( 'View code', 'fishotel-misc-plugin' ); ?></summary>
									<pre><?php echo esc_html( $fh_row['code'] ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $fh_s['type'] ); ?></code></td>
						<td><?php echo esc_html( Admin::scope_label( $fh_s['type'], $fh_s['scope'] ) ); ?></td>
						<td class="fh-snip-num"><?php echo esc_html( (string) (int) $fh_s['priority'] ); ?></td>
						<td><?php echo '' !== $fh_s['origin'] ? esc_html( $fh_s['origin'] ) : '<span class="fh-snip-muted">—</span>'; ?></td>
						<td><a href="<?php echo esc_url( Admin::GITHUB_TREE . rawurlencode( $fh_s['file'] ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $fh_s['file'] ); ?></code></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $index['broken'] ) ) : ?>
		<h2 class="fh-snip-h2"><?php esc_html_e( 'Broken files — never loaded', 'fishotel-misc-plugin' ); ?></h2>
		<table class="widefat striped fh-snip-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Why', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $index['broken'] as $fh_broken ) : ?>
					<tr>
						<td><code><?php echo esc_html( $fh_broken['file'] ); ?></code></td>
						<td><?php echo esc_html( $fh_broken['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $index['conflicts'] ) ) : ?>
		<h2 class="fh-snip-h2"><?php esc_html_e( 'Names declared by more than one snippet', 'fishotel-misc-plugin' ); ?></h2>
		<table class="widefat striped fh-snip-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Snippets', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Result', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $index['conflicts'] as $fh_conflict ) : ?>
					<tr>
						<td><code><?php echo esc_html( 'function' === $fh_conflict['kind'] ? $fh_conflict['name'] . '()' : 'class ' . $fh_conflict['name'] ); ?></code></td>
						<td><?php echo esc_html( implode( ', ', $fh_conflict['slugs'] ) ); ?></td>
						<td>
							<?php
							echo $fh_conflict['refused']
								? esc_html__( 'All refused: loading two declarations would fatal. Rename or merge them.', 'fishotel-misc-plugin' )
								: esc_html__( 'Warning only: each declaration sits inside braces and its file checks function_exists() / class_exists() for this exact name. Check each check wraps its declaration.', 'fishotel-misc-plugin' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $map['map'] ) || ! empty( $map['ambiguous'] ) ) : ?>
		<h2 class="fh-snip-h2"><?php esc_html_e( 'Old [code_snippet id=N] shortcodes', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fh-snip-help">
			<?php
			echo $cs_active
				? esc_html__( 'Code Snippets is active, so it still answers [code_snippet]. Once it is deactivated, these files answer instead:', 'fishotel-misc-plugin' )
				: esc_html__( 'Code Snippets is not active, so these files answer [code_snippet id=N] in your pages:', 'fishotel-misc-plugin' );
			?>
		</p>
		<ul class="fh-snip-list">
			<?php foreach ( $map['map'] as $fh_id => $fh_slug ) : ?>
				<li><code>[code_snippet id=<?php echo esc_html( (string) (int) $fh_id ); ?>]</code> → <code><?php echo esc_html( $fh_slug ); ?></code></li>
			<?php endforeach; ?>
			<?php foreach ( $map['ambiguous'] as $fh_id => $fh_slugs ) : ?>
				<li class="is-bad"><code>[code_snippet id=<?php echo esc_html( (string) (int) $fh_id ); ?>]</code> — <?php echo esc_html( __( 'claimed by several files, so none answers:', 'fishotel-misc-plugin' ) . ' ' . implode( ', ', $fh_slugs ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h2 class="fh-snip-h2"><?php esc_html_e( 'If a snippet breaks the site', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-snip-help">
		<?php
		/* translators: %s: query string */
		echo esc_html( sprintf( __( 'Logged in as an administrator, add %s to any admin address: nothing runs on that page, and this page and its switches keep the flag. Locked out entirely: add define( \'FISHOTEL_SNIPPETS_SAFE_MODE\', true ); to wp-config.php (Code Snippets\' CODE_SNIPPETS_SAFE_MODE works too), switch the snippet off, then remove the line.', 'fishotel-misc-plugin' ), '?' . Loader::SAFE_MODE_QUERY . '=1' ) );
		?>
		<a href="<?php echo esc_url( add_query_arg( Loader::SAFE_MODE_QUERY, '1', Admin::page_url() ) ); ?>"><?php esc_html_e( 'Open this page in safe mode', 'fishotel-misc-plugin' ); ?></a>
	</p>
</div>
