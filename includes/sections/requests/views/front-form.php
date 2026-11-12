<?php
/**
 * The request form: who (name, e-mail, forum handle), then one or more
 * fish — the name with suggestions, a size, a quantity, a note. Used by
 * the private link's page and by the [fishotel_request_form] shortcode.
 *
 * It works without JavaScript: three fish rows are printed, and the
 * script only adds "Add another fish" and the name suggestions.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var array $fh_form action, nonce, hidden (name → value), person (name, email, handle), rows (raw
 *                     rows to show again), sizes (array[]|null), fee (float), errors (string[]),
 *                     locked (bool: name and e-mail come from the account), token, heading.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Front;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Urls;

$fh_rows  = $fh_form['rows'] ? array_values( $fh_form['rows'] ) : array();
$fh_count = max( 3, count( $fh_rows ) );
$fh_sizes = $fh_form['sizes'];

/**
 * One fish row.
 *
 * @param int|string $i   Row index (or __i__ for the script's template).
 * @param array      $row The row as it was typed.
 */
$fh_row = static function ( $i, array $row ) use ( $fh_sizes ) {
	$value = static function ( $key ) use ( $row ) {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
	};
	$name  = static function ( $key ) use ( $i ) {
		return 'rows[' . $i . '][' . $key . ']';
	};
	?>
	<fieldset class="fh-rq-row">
		<legend><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?> <span class="fh-rq-row__n"><?php echo esc_html( is_int( $i ) ? (string) ( $i + 1 ) : '' ); ?></span></legend>
		<label class="fh-rq-field fh-rq-field--fish">
			<span><?php esc_html_e( 'Which fish?', 'fishotel-misc-plugin' ); ?></span>
			<input type="text" class="fh-rq-fish" name="<?php echo esc_attr( $name( 'fish' ) ); ?>" value="<?php echo esc_attr( $value( 'fish' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_FISH ); ?>" list="fh-rq-names" autocomplete="off">
		</label>
		<label class="fh-rq-field fh-rq-field--size">
			<span><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></span>
			<?php if ( is_array( $fh_sizes ) ) : ?>
				<select name="<?php echo esc_attr( $name( 'size_slug' ) ); ?>">
					<option value=""><?php esc_html_e( 'any', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $fh_sizes as $fh_size ) : ?>
						<option value="<?php echo esc_attr( $fh_size['slug'] ); ?>" <?php selected( $value( 'size_slug' ), (string) $fh_size['slug'] ); ?>><?php echo esc_html( $fh_size['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="text" name="<?php echo esc_attr( $name( 'size_label' ) ); ?>" value="<?php echo esc_attr( $value( 'size_label' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_SIZE ); ?>" placeholder="<?php esc_attr_e( 'any', 'fishotel-misc-plugin' ); ?>">
			<?php endif; ?>
		</label>
		<label class="fh-rq-field fh-rq-field--qty">
			<span><?php esc_html_e( 'How many?', 'fishotel-misc-plugin' ); ?></span>
			<input type="number" name="<?php echo esc_attr( $name( 'qty' ) ); ?>" value="<?php echo esc_attr( '' !== $value( 'qty' ) ? $value( 'qty' ) : '1' ); ?>" min="1" max="<?php echo esc_attr( Rules::MAX_QTY ); ?>" step="1" inputmode="numeric">
		</label>
		<label class="fh-rq-field fh-rq-field--note">
			<span><?php esc_html_e( 'Anything we should know? (optional)', 'fishotel-misc-plugin' ); ?></span>
			<textarea name="<?php echo esc_attr( $name( 'note' ) ); ?>" rows="2" maxlength="<?php echo esc_attr( Rules::MAX_NOTE ); ?>"><?php echo esc_textarea( $value( 'note' ) ); ?></textarea>
		</label>
	</fieldset>
	<?php
};
?>
<form class="fh-rq-form fh-rq-card" method="post" action="<?php echo esc_url( $fh_form['action'] ); ?>" data-token="<?php echo esc_attr( $fh_form['token'] ); ?>">
	<?php if ( '' !== $fh_form['heading'] ) : ?>
		<h1><?php echo esc_html( $fh_form['heading'] ); ?></h1>
	<?php endif; ?>
	<p class="fh-rq-lead"><?php esc_html_e( 'Tell us which fish you are looking for. We keep your request on our list, look for the fish, quarantine it, and e-mail you when it is ready.', 'fishotel-misc-plugin' ); ?></p>

	<?php if ( $fh_form['fee'] > 0 ) : ?>
		<p class="fh-rq-fee">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: amount */
					__( 'A request fee of %s applies to this sheet once we approve it. It is not an extra charge: it goes into your wallet here, and you can spend it on anything. We will e-mail you a link to pay it.', 'fishotel-misc-plugin' ),
					Rules::money( $fh_form['fee'], Urls::currency_symbol() )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $fh_form['errors'] ) : ?>
		<div class="fh-rq-errors" role="alert">
			<p><?php esc_html_e( 'Nothing was sent yet — please check:', 'fishotel-misc-plugin' ); ?></p>
			<ul>
				<?php foreach ( $fh_form['errors'] as $fh_error ) : ?>
					<li><?php echo esc_html( $fh_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<input type="hidden" name="<?php echo esc_attr( Front::NONCE_FIELD ); ?>" value="<?php echo esc_attr( $fh_form['nonce'] ); ?>">
	<?php foreach ( $fh_form['hidden'] as $fh_key => $fh_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $fh_key ); ?>" value="<?php echo esc_attr( $fh_value ); ?>">
	<?php endforeach; ?>

	<div class="fh-rq-who">
		<label class="fh-rq-field">
			<span><?php esc_html_e( 'Your name', 'fishotel-misc-plugin' ); ?></span>
			<input type="text" name="name" value="<?php echo esc_attr( $fh_form['person']['name'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_NAME ); ?>" autocomplete="name" <?php echo $fh_form['locked'] ? 'readonly' : 'required'; ?>>
		</label>
		<label class="fh-rq-field">
			<span><?php esc_html_e( 'E-mail', 'fishotel-misc-plugin' ); ?></span>
			<input type="email" name="email" value="<?php echo esc_attr( $fh_form['person']['email'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_EMAIL ); ?>" autocomplete="email" <?php echo $fh_form['locked'] ? 'readonly' : 'required'; ?>>
		</label>
		<label class="fh-rq-field">
			<span><?php esc_html_e( 'Forum handle (optional)', 'fishotel-misc-plugin' ); ?></span>
			<input type="text" name="handle" value="<?php echo esc_attr( $fh_form['person']['handle'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_HANDLE ); ?>" autocomplete="off">
		</label>
	</div>

	<?php // The honeypot: people never see it, robots fill it. ?>
	<p class="fh-rq-hp" aria-hidden="true">
		<label><?php esc_html_e( 'Leave this field empty', 'fishotel-misc-plugin' ); ?> <input type="text" name="<?php echo esc_attr( Front::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off"></label>
	</p>

	<div class="fh-rq-rows" data-next="<?php echo esc_attr( $fh_count ); ?>">
		<?php for ( $fh_i = 0; $fh_i < $fh_count; $fh_i++ ) : ?>
			<?php $fh_row( $fh_i, isset( $fh_rows[ $fh_i ] ) && is_array( $fh_rows[ $fh_i ] ) ? $fh_rows[ $fh_i ] : array() ); ?>
		<?php endfor; ?>
	</div>
	<template class="fh-rq-row-template"><?php $fh_row( '__i__', array() ); ?></template>
	<datalist id="fh-rq-names"></datalist>

	<p class="fh-rq-actions">
		<button type="button" class="fh-rq-button fh-rq-button--quiet fh-rq-add" hidden><?php esc_html_e( 'Add another fish', 'fishotel-misc-plugin' ); ?></button>
		<button type="submit" class="fh-rq-button"><?php esc_html_e( 'Send my request', 'fishotel-misc-plugin' ); ?></button>
	</p>
	<p class="fh-rq-small"><?php esc_html_e( 'Rows you leave empty are ignored. You can cancel a request at any time.', 'fishotel-misc-plugin' ); ?></p>
</form>
