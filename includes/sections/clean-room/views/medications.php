<?php
/**
 * Settings → Medications: the list the Dose sheet, the Calculator and the
 * dose phrases offer — add, edit, retire, bring back. Included by
 * views/settings.php; it gathers what it shows itself.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Medications_Admin;

$fh_meds  = Medications_Admin::panel();
$fh_blank = array(
	'key'        => '',
	'label'      => '',
	'short'      => '',
	'aliases'    => array(),
	'unit'       => 'mg',
	'mode'       => 'per_gallon',
	'rate'       => '',
	'per'        => 1,
	'level_unit' => '',
	'doses'      => array(),
	'measure'    => null,
);
?>
<div class="fishotel-misc-card fh-cr-card fh-cr-card--wide" id="fishotel-cleanroom-medications">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-plus-alt"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Medications', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'What the Front Desk\'s Dose sheet, the Calculator and the dose phrases offer, and how each is dosed: per gallon (an amount for so many gallons — 25 mg per gallon, 1 ml per 10 gallons), to a level (an amount that raises a reading by 1.0 — copper in ppm) or typed (no sum). The sheet multiplies by the water\'s gallons and shows its working; a weight reads in grams from 1 g up and in milligrams below. A retired medication is no longer offered, but the logs that name it still read; a renamed one keeps its old name as an other name for the same reason. Nothing is ever deleted.', 'fishotel-misc-plugin' ); ?></p>
	<?php if ( ! $fh_meds['edited'] ) : ?>
		<p class="fh-cr-sub"><?php esc_html_e( 'This is the built-in list, unchanged.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<table class="widefat fh-cr-table fh-cr-meds">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Medication', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Chip', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'How it is dosed', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Named doses · measure', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Other names', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $fh_meds['rows'] as $fh_row ) : ?>
				<?php
				$fh_med     = $fh_row['medication'];
				$fh_retired = ! empty( $fh_med['retired'] );
				$fh_uid     = $fh_med['key'];
				$fh_op      = 'save';
				?>
				<tr class="fh-cr-med<?php echo $fh_retired ? ' is-retired' : ''; ?>" data-key="<?php echo esc_attr( $fh_med['key'] ); ?>">
					<td>
						<strong><?php echo esc_html( $fh_med['label'] ); ?></strong>
						<?php if ( $fh_row['copper'] ) : ?>
							<span class="fh-cr-med-badge" title="<?php esc_attr_e( 'While it is dosed to a level, its "Now" is today\'s copper reading and saving a dose of it logs that reading too. Using another copper product? Rename this one and change its rate — it stays the copper log\'s.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'the copper log\'s product', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
						<?php if ( $fh_retired ) : ?>
							<span class="fh-cr-med-badge fh-cr-med-badge--retired"><?php esc_html_e( 'retired', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $fh_med['short'] ); ?></td>
					<td>
						<?php echo esc_html( $fh_row['how'] ); ?>
						<?php if ( '' !== $fh_row['theme']['state'] ) : ?>
							<span class="fh-cr-med-theme fh-cr-med-theme--<?php echo esc_attr( $fh_row['theme']['state'] ); ?>"><?php echo esc_html( $fh_row['theme']['text'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( '' !== $fh_row['doses'] ? $fh_row['doses'] : '—' ); ?>
						<?php if ( '' !== $fh_row['measure'] ) : ?>
							<span class="fh-cr-sub"><?php echo esc_html( $fh_row['measure'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $fh_med['aliases'] ? implode( ', ', $fh_med['aliases'] ) : '—' ); ?></td>
					<td class="fh-cr-row__actions">
						<button type="button" class="button button-small fh-cr-med-edit"><?php esc_html_e( 'Edit', 'fishotel-misc-plugin' ); ?></button>
						<?php if ( $fh_retired ) : ?>
							<button type="button" class="button button-small fh-cr-med-restore"><?php esc_html_e( 'Bring back', 'fishotel-misc-plugin' ); ?></button>
						<?php else : ?>
							<button type="button" class="button button-small fh-cr-med-retire"><?php esc_html_e( 'Retire', 'fishotel-misc-plugin' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="fh-cr-med-editor" data-key="<?php echo esc_attr( $fh_med['key'] ); ?>" hidden>
					<td colspan="6">
						<?php include Clean_Room::path() . 'views/medication-form.php'; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="fh-cr-status fh-cr-meds-status" aria-live="polite"></p>

	<h3 class="fh-cr-h3"><?php esc_html_e( 'Add a medication', 'fishotel-misc-plugin' ); ?></h3>
	<?php if ( count( $fh_meds['rows'] ) >= Medications::MAX ) : ?>
		<p class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: the cap */ __( 'The list is full (%d, retired ones included).', 'fishotel-misc-plugin' ), Medications::MAX ) ); ?></p>
	<?php else : ?>
		<?php
		$fh_med = $fh_blank;
		$fh_uid = 'new';
		$fh_op  = 'add';
		include Clean_Room::path() . 'views/medication-form.php';
		?>
	<?php endif; ?>
</div>
