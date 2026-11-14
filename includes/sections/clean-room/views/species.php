<?php
/**
 * Species tab — the species master: searchable, paginated, inline edit,
 * add, product link, export.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[]  $rows         Species rows (a page).
 * @var int      $total        Rows matching the filter.
 * @var int      $pages        Pages.
 * @var int      $paged        Current page.
 * @var array    $filters      search, group.
 * @var array    $counts       Store::open_stay_counts().
 * @var string[] $groups       Groups in use.
 * @var array    $titles       Product titles by id.
 * @var array    $exports      xlsx / csv URLs.
 * @var string   $flash        One-time notice.
 * @var bool     $tables_ok    Whether the tables exist.
 * @var bool     $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Species;

$tab = 'species';

/**
 * The edit / add fields of one species row.
 *
 * @param array $row The row (empty for the add row).
 */
$fh_fields = static function ( array $row ) use ( $groups, $titles ) {
	$v   = static function ( $key, $default = '' ) use ( $row ) {
		return isset( $row[ $key ] ) && null !== $row[ $key ] ? (string) $row[ $key ] : (string) $default;
	};
	$id  = (int) $v( 'id', 0 );
	$pid = (int) $v( 'product_id', 0 );
	?>
	<div class="fh-cr-fields__row">
		<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
		<label><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>" maxlength="120" required></label>
		<label><?php esc_html_e( 'Scientific name', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="sci_name" value="<?php echo esc_attr( $v( 'sci_name' ) ); ?>" maxlength="120"></label>
		<label><?php esc_html_e( 'Group', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="group_name" value="<?php echo esc_attr( $v( 'group_name' ) ); ?>" list="fh-cr-groups" maxlength="60"></label>
		<label><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?> <input type="number" class="fh-cr-input fh-cr-input--num" name="per_box" value="<?php echo esc_attr( $v( 'per_box', Costing::DEFAULT_PER_BOX ) ); ?>" min="1" max="999"></label>
		<label><?php esc_html_e( 'My price', 'fishotel-misc-plugin' ); ?> <input type="number" step="0.01" class="fh-cr-input fh-cr-input--num" name="my_price" value="<?php echo esc_attr( '' !== $v( 'my_price' ) ? number_format( (float) $v( 'my_price' ), 2, '.', '' ) : '' ); ?>" min="0"></label>
		<label><?php esc_html_e( 'Online price', 'fishotel-misc-plugin' ); ?> <input type="number" step="0.01" class="fh-cr-input fh-cr-input--num" name="online_price" value="<?php echo esc_attr( '' !== $v( 'online_price' ) ? number_format( (float) $v( 'online_price' ), 2, '.', '' ) : '' ); ?>" min="0"></label>
		<label><?php esc_html_e( 'Site', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="online_site" value="<?php echo esc_attr( $v( 'online_site' ) ); ?>" maxlength="60"></label>
		<label><?php esc_html_e( 'Checked on', 'fishotel-misc-plugin' ); ?> <input type="date" class="fh-cr-input" name="online_date" value="<?php echo esc_attr( $v( 'online_date' ) ); ?>"></label>
		<label><?php esc_html_e( 'Reef safe', 'fishotel-misc-plugin' ); ?>
			<select class="fh-cr-input" name="reef_safe">
				<?php foreach ( Species::REEF_SAFE as $fh_rs ) : ?>
					<option value="<?php echo esc_attr( $fh_rs ); ?>" <?php selected( $v( 'reef_safe' ), $fh_rs ); ?>><?php echo esc_html( '' === $fh_rs ? '—' : $fh_rs ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><?php esc_html_e( 'Max size', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="max_size" value="<?php echo esc_attr( $v( 'max_size' ) ); ?>" maxlength="40"></label>
		<label class="fh-cr-suggest-wrap fh-cr-fields__product"><?php esc_html_e( 'Product', 'fishotel-misc-plugin' ); ?>
			<input type="hidden" name="product_id" value="<?php echo esc_attr( $pid > 0 ? $pid : '' ); ?>">
			<input type="text" class="fh-cr-input fh-cr-product-search" value="<?php echo esc_attr( $pid > 0 ? ( isset( $titles[ $pid ] ) && '' !== $titles[ $pid ] ? $titles[ $pid ] : '#' . $pid ) : '' ); ?>" placeholder="<?php esc_attr_e( 'search a product to link…', 'fishotel-misc-plugin' ); ?>" autocomplete="off">
		</label>
		<label class="fh-cr-fields__note"><?php esc_html_e( 'Notes', 'fishotel-misc-plugin' ); ?> <input type="text" class="fh-cr-input" name="notes" value="<?php echo esc_attr( $v( 'notes' ) ); ?>"></label>
		<button type="button" class="button button-primary fh-cr-species-save"><?php echo $id > 0 ? esc_html__( 'Save', 'fishotel-misc-plugin' ) : esc_html__( 'Add species', 'fishotel-misc-plugin' ); ?></button>
		<?php if ( $id > 0 && $pid > 0 ) : ?>
			<button type="button" class="button fh-cr-species-unlink"><?php esc_html_e( 'Unlink product', 'fishotel-misc-plugin' ); ?></button>
		<?php endif; ?>
	</div>
	<?php
};

$fh_page_url = static function ( $page ) use ( $filters ) {
	return Admin::page_url(
		'species',
		array_filter(
			array(
				's'     => $filters['search'],
				'group' => $filters['group'],
				'paged' => (int) $page,
			)
		)
	);
};
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Clean Room', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php include Clean_Room::path() . 'views/tabs.php'; ?>

	<?php if ( ! $tables_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="species">
			<label class="fh-cr-filter fh-cr-filter--grow">
				<span><?php esc_html_e( 'Name or scientific name starts with', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" name="s" class="fh-cr-input" value="<?php echo esc_attr( $filters['search'] ); ?>">
			</label>
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Group', 'fishotel-misc-plugin' ); ?></span>
				<select name="group" class="fh-cr-input">
					<option value=""><?php esc_html_e( 'All', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $groups as $fh_g ) : ?>
						<option value="<?php echo esc_attr( $fh_g ); ?>" <?php selected( $filters['group'], $fh_g ); ?>><?php echo esc_html( $fh_g ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
		</form>
		<div class="fh-cr-actions">
			<button type="button" class="button button-primary fh-cr-species-add-toggle"><?php esc_html_e( 'Add species', 'fishotel-misc-plugin' ); ?></button>
			<a class="button" href="<?php echo esc_url( $exports['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $exports['csv'] ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
			<span class="fh-cr-status" aria-live="polite"></span>
		</div>
	</div>

	<datalist id="fh-cr-groups"><?php foreach ( $groups as $fh_g ) : ?><option value="<?php echo esc_attr( $fh_g ); ?>"></option><?php endforeach; ?></datalist>

	<div class="fishotel-misc-card fh-cr-card fh-cr-species-add" hidden data-species="0">
		<div class="fishotel-misc-card-header">
			<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-plus"></span></div>
			<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'New species', 'fishotel-misc-plugin' ); ?></h2>
		</div>
		<?php $fh_fields( array() ); ?>
	</div>

	<p class="fh-cr-count"><?php echo esc_html( sprintf( /* translators: 1: rows matching, 2: page, 3: pages */ __( '%1$d species · page %2$d of %3$d', 'fishotel-misc-plugin' ), $total, $paged, $pages ) ); ?></p>

	<?php if ( ! $rows ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-list-view"></span><p><?php esc_html_e( 'No species here yet. "Add species" starts the list; the order editor and the Arrival screen search it next to the products.', 'fishotel-misc-plugin' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped fh-cr-table fh-cr-species-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Scientific name', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Group', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-cr-num"><?php esc_html_e( 'My price', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Online price', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reef safe', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Open stays', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $fh_row ) : ?>
					<?php
					$fh_pid   = (int) $fh_row['product_id'];
					$fh_title = $fh_pid > 0 ? ( isset( $titles[ $fh_pid ] ) && '' !== $titles[ $fh_pid ] ? $titles[ $fh_pid ] : '' ) : '';
					?>
					<tr data-species="<?php echo esc_attr( $fh_row['id'] ); ?>">
						<td><strong><?php echo esc_html( $fh_row['name'] ); ?></strong><?php if ( '' !== $fh_row['max_size'] ) : ?><span class="fh-cr-sub"><?php echo esc_html( $fh_row['max_size'] ); ?></span><?php endif; ?></td>
						<td><em><?php echo esc_html( '' !== $fh_row['sci_name'] ? $fh_row['sci_name'] : '—' ); ?></em></td>
						<td><?php echo esc_html( '' !== $fh_row['group_name'] ? $fh_row['group_name'] : '—' ); ?></td>
						<td class="fh-cr-num"><?php echo esc_html( $fh_row['per_box'] ); ?></td>
						<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_row['my_price'] ) ); ?></td>
						<td>
							<?php echo esc_html( Costing::money( $fh_row['online_price'] ) ); ?>
							<?php if ( $fh_row['online_site'] || $fh_row['online_date'] ) : ?>
								<span class="fh-cr-sub"><?php echo esc_html( trim( (string) $fh_row['online_site'] . ' ' . (string) $fh_row['online_date'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( '' !== $fh_row['reef_safe'] ? $fh_row['reef_safe'] : '—' ); ?></td>
						<td>
							<?php if ( $fh_pid > 0 ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $fh_pid ) ?: '#' ); ?>"><?php echo esc_html( '' !== $fh_title ? $fh_title : '#' . $fh_pid ); ?></a>
								<?php if ( '' === $fh_title ) : ?><span class="fh-cr-sub"><?php esc_html_e( 'no such product', 'fishotel-misc-plugin' ); ?></span><?php endif; ?>
							<?php else : ?>
								<button type="button" class="button button-small fh-cr-species-toggle" data-focus="product"><?php esc_html_e( 'Link product', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
						</td>
						<td class="fh-cr-num"><?php echo esc_html( Admin_Workbook::open_stays_of( $fh_row, $counts ) ); ?></td>
						<td class="fh-cr-row__actions">
							<button type="button" class="button button-small fh-cr-species-toggle"><?php esc_html_e( 'Edit', 'fishotel-misc-plugin' ); ?></button>
							<button type="button" class="button button-small fh-cr-species-delete"><?php esc_html_e( 'Delete', 'fishotel-misc-plugin' ); ?></button>
						</td>
					</tr>
					<tr class="fh-cr-fields fh-cr-species-fields" hidden data-species="<?php echo esc_attr( $fh_row['id'] ); ?>">
						<td colspan="10"><?php $fh_fields( $fh_row ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="fh-cr-pager">
				<?php if ( $paged > 1 ) : ?><a class="button" href="<?php echo esc_url( $fh_page_url( $paged - 1 ) ); ?>">&laquo;</a><?php endif; ?>
				<span><?php echo esc_html( sprintf( /* translators: 1: page, 2: pages */ __( '%1$d / %2$d', 'fishotel-misc-plugin' ), $paged, $pages ) ); ?></span>
				<?php if ( $paged < $pages ) : ?><a class="button" href="<?php echo esc_url( $fh_page_url( $paged + 1 ) ); ?>">&raquo;</a><?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'The species list is what the workbook\'s Fishlist held. A species may or may not be linked to a product: the Arrival screen, the voice parser and the order editor search both lists as one (a species named like a product, or linked to it, is one entry). A species named by an order line cannot be removed.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
