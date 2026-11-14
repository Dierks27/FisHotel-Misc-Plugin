<?php
/**
 * One medication's form — Settings → Medications (edit, and "Add a
 * medication"). Posted by js/medications-admin.js to the
 * fishotel_cleanroom_medications action.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $fh_med  The medication (an empty one for Add).
 * @var string $fh_op   save|add.
 * @var string $fh_uid  A unique suffix for the field ids.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Medications;

$fh_modes   = array(
	'per_gallon' => __( 'Per gallon — an amount for so many gallons', 'fishotel-misc-plugin' ),
	'to_level'   => __( 'To a level — an amount that raises a reading by 1.0', 'fishotel-misc-plugin' ),
	'typed'      => __( 'Typed — no sum', 'fishotel-misc-plugin' ),
);
$fh_doses   = array_values( (array) $fh_med['doses'] );
$fh_rows    = min( Medications::MAX_DOSES, count( $fh_doses ) + 2 );
$fh_measure = is_array( $fh_med['measure'] ) ? $fh_med['measure'] : array(
	'label'  => '',
	'amount' => '',
	'unit'   => '',
);
$fh_number  = static function ( $value ) {
	return '' === $value || null === $value || (float) $value <= 0 ? '' : Dose::num( (float) $value, 4 );
};
?>
<form class="fh-cr-med-form" data-op="<?php echo esc_attr( $fh_op ); ?>" onsubmit="return false;">
	<input type="hidden" name="key" value="<?php echo esc_attr( $fh_med['key'] ); ?>">
	<?php if ( 'save' === $fh_op ) : ?>
		<input type="hidden" name="seen" value="<?php echo esc_attr( Medications::fingerprint( $fh_med ) ); ?>">
	<?php endif; ?>
	<div class="fh-cr-med-grid">
		<label class="fh-cr-med-field fh-cr-med-field--wide" for="fh-cr-med-label-<?php echo esc_attr( $fh_uid ); ?>">
			<span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span>
			<input type="text" id="fh-cr-med-label-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input" name="label" value="<?php echo esc_attr( $fh_med['label'] ); ?>" maxlength="<?php echo esc_attr( Medications::MAX_LABEL ); ?>" required>
		</label>
		<label class="fh-cr-med-field" for="fh-cr-med-short-<?php echo esc_attr( $fh_uid ); ?>">
			<span><?php esc_html_e( 'Chip on the phone', 'fishotel-misc-plugin' ); ?></span>
			<input type="text" id="fh-cr-med-short-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input" name="short" value="<?php echo esc_attr( $fh_med['short'] ); ?>" maxlength="<?php echo esc_attr( Medications::MAX_SHORT ); ?>" placeholder="<?php esc_attr_e( 'the name', 'fishotel-misc-plugin' ); ?>">
		</label>
		<label class="fh-cr-med-field fh-cr-med-field--wide" for="fh-cr-med-aliases-<?php echo esc_attr( $fh_uid ); ?>">
			<span><?php esc_html_e( 'Other names (for speech, log notes and schedule steps; commas between)', 'fishotel-misc-plugin' ); ?></span>
			<input type="text" id="fh-cr-med-aliases-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input" name="aliases" value="<?php echo esc_attr( implode( ', ', (array) $fh_med['aliases'] ) ); ?>">
		</label>
		<label class="fh-cr-med-field fh-cr-med-field--wide" for="fh-cr-med-mode-<?php echo esc_attr( $fh_uid ); ?>">
			<span><?php esc_html_e( 'How it is dosed', 'fishotel-misc-plugin' ); ?></span>
			<select id="fh-cr-med-mode-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input fh-cr-med-mode" name="mode">
				<?php foreach ( $fh_modes as $fh_mode => $fh_label ) : ?>
					<option value="<?php echo esc_attr( $fh_mode ); ?>" <?php selected( $fh_med['mode'], $fh_mode ); ?>><?php echo esc_html( $fh_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</div>

	<div class="fh-cr-med-rate" data-modes="per_gallon to_level">
		<label class="screen-reader-text" for="fh-cr-med-rate-<?php echo esc_attr( $fh_uid ); ?>"><?php esc_html_e( 'Amount', 'fishotel-misc-plugin' ); ?></label>
		<input type="number" id="fh-cr-med-rate-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input fh-cr-input--num fh-cr-med-rate__amount" name="rate" value="<?php echo esc_attr( $fh_number( $fh_med['rate'] ) ); ?>" min="0" step="any" placeholder="25">
	</div>
	<div class="fh-cr-med-rate">
		<label class="screen-reader-text" for="fh-cr-med-unit-<?php echo esc_attr( $fh_uid ); ?>"><?php esc_html_e( 'Unit', 'fishotel-misc-plugin' ); ?></label>
		<select id="fh-cr-med-unit-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input fh-cr-med-unit" name="unit">
			<?php foreach ( Medications::UNITS as $fh_unit ) : ?>
				<option value="<?php echo esc_attr( $fh_unit ); ?>" <?php selected( $fh_med['unit'], $fh_unit ); ?>><?php echo esc_html( $fh_unit ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="fh-cr-med-rate" data-modes="per_gallon to_level">
		<label for="fh-cr-med-per-<?php echo esc_attr( $fh_uid ); ?>"><?php esc_html_e( 'per', 'fishotel-misc-plugin' ); ?></label>
		<input type="number" id="fh-cr-med-per-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input fh-cr-input--num fh-cr-med-per" name="per" value="<?php echo esc_attr( $fh_number( $fh_med['per'] ) ); ?>" min="0" step="any" placeholder="1">
		<span><?php esc_html_e( 'gallons', 'fishotel-misc-plugin' ); ?></span>
	</div>
	<div class="fh-cr-med-rate" data-modes="to_level">
		<label for="fh-cr-med-level-<?php echo esc_attr( $fh_uid ); ?>"><?php esc_html_e( 'raises the level by 1.0', 'fishotel-misc-plugin' ); ?></label>
		<input type="text" id="fh-cr-med-level-<?php echo esc_attr( $fh_uid ); ?>" class="fh-cr-input fh-cr-med-level" name="level_unit" value="<?php echo esc_attr( '' !== $fh_med['level_unit'] ? $fh_med['level_unit'] : Medications::LEVEL_UNIT ); ?>" maxlength="<?php echo esc_attr( Medications::MAX_LEVEL_UNIT ); ?>" placeholder="ppm">
	</div>
	<span class="fh-cr-sub fh-cr-med-hint" data-modes="per_gallon to_level" aria-live="polite"></span>

	<div class="fh-cr-med-block" data-modes="per_gallon">
		<span class="fh-cr-med-block__title"><?php esc_html_e( 'Named doses (chips on the Dose sheet; the amount is in the unit and for the gallons above)', 'fishotel-misc-plugin' ); ?></span>
		<?php for ( $fh_i = 0; $fh_i < $fh_rows; $fh_i++ ) : ?>
			<?php $fh_dose = isset( $fh_doses[ $fh_i ] ) ? $fh_doses[ $fh_i ] : array( 'label' => '', 'rate' => '' ); ?>
			<span class="fh-cr-med-dose">
				<input type="text" class="fh-cr-input" name="doses[<?php echo esc_attr( $fh_i ); ?>][label]" value="<?php echo esc_attr( $fh_dose['label'] ); ?>" maxlength="<?php echo esc_attr( Medications::MAX_DOSE_LABEL ); ?>" placeholder="<?php esc_attr_e( 'name — Loading', 'fishotel-misc-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Named dose: its name', 'fishotel-misc-plugin' ); ?>">
				<input type="number" class="fh-cr-input fh-cr-input--num" name="doses[<?php echo esc_attr( $fh_i ); ?>][rate]" value="<?php echo esc_attr( $fh_number( $fh_dose['rate'] ) ); ?>" min="0" step="any" placeholder="40" aria-label="<?php esc_attr_e( 'Named dose: its amount', 'fishotel-misc-plugin' ); ?>">
			</span>
		<?php endfor; ?>
	</div>

	<div class="fh-cr-med-block">
		<span class="fh-cr-med-block__title"><?php esc_html_e( 'Measure (optional — so a result also reads in scoops): one', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-med-dose">
			<input type="text" class="fh-cr-input" name="measure[label]" value="<?php echo esc_attr( $fh_measure['label'] ); ?>" maxlength="<?php echo esc_attr( Medications::MAX_MEASURE_LABEL ); ?>" placeholder="<?php esc_attr_e( 'scoop', 'fishotel-misc-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Measure: its name', 'fishotel-misc-plugin' ); ?>">
			<span>=</span>
			<input type="number" class="fh-cr-input fh-cr-input--num" name="measure[amount]" value="<?php echo esc_attr( $fh_number( $fh_measure['amount'] ) ); ?>" min="0" step="any" placeholder="0.5" aria-label="<?php esc_attr_e( 'Measure: its amount', 'fishotel-misc-plugin' ); ?>">
			<select class="fh-cr-input fh-cr-med-unit" name="measure[unit]" aria-label="<?php esc_attr_e( 'Measure: its unit', 'fishotel-misc-plugin' ); ?>">
				<option value=""><?php esc_html_e( 'same unit', 'fishotel-misc-plugin' ); ?></option>
				<?php foreach ( Medications::UNITS as $fh_unit ) : ?>
					<option value="<?php echo esc_attr( $fh_unit ); ?>" <?php selected( $fh_measure['unit'], $fh_unit ); ?>><?php echo esc_html( $fh_unit ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
	</div>

	<p class="fh-cr-actions-row">
		<button type="submit" class="button button-primary fh-cr-med-save"><?php echo esc_html( 'add' === $fh_op ? __( 'Add medication', 'fishotel-misc-plugin' ) : __( 'Save', 'fishotel-misc-plugin' ) ); ?></button>
		<?php if ( 'add' !== $fh_op ) : ?>
			<button type="button" class="button fh-cr-med-cancel"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></button>
		<?php endif; ?>
		<span class="fh-cr-status fh-cr-med-status" aria-live="polite"></span>
	</p>
</form>
