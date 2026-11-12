<?php
/**
 * Shop App admin page — the header, the status line, the tabs and the
 * one-time notice; the tab's own view follows.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var string     $tab       devices|people|settings|log|install.
 * @var array|null $notice    { kind, text } after a redirect.
 * @var bool       $tables_ok Whether the tables exist.
 * @var bool       $https_ok  Whether the site is served over HTTPS.
 * @var array      $desk      Shop_App::front_desk().
 * @var string     $app_url   The app's address.
 * @var array      $settings  Settings.
 * @var int        $usable    People who can unlock.
 * @var int        $now       Timestamp.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Shop_App\Admin;
use FisHotel\Misc\Sections\Shop_App\Shop_App;

$fh_tabs = array(
	'devices'  => __( 'Devices', 'fishotel-misc-plugin' ),
	'people'   => __( 'People', 'fishotel-misc-plugin' ),
	'settings' => __( 'Settings', 'fishotel-misc-plugin' ),
	'log'      => __( 'Log', 'fishotel-misc-plugin' ),
	'install'  => __( 'Install', 'fishotel-misc-plugin' ),
);
?>
<div class="wrap fishotel-misc-wrap fh-sa-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Shop App', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-sa-tabs" aria-label="<?php esc_attr_e( 'Shop App', 'fishotel-misc-plugin' ); ?>">
		<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
			<a class="fh-sa-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>"<?php echo $tab === $fh_key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $fh_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( ! empty( $notice ) && is_array( $notice ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--<?php echo 'error' === $notice[0] ? 'error' : 'success'; ?>" role="status"><?php echo esc_html( $notice[1] ); ?></div>
	<?php endif; ?>

	<?php if ( ! $tables_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Shop App tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $https_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Shop App needs HTTPS. This site is not served over https://, so no device can be set up and the app will not install.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $desk['available'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php echo esc_html( $desk['reason'] ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-sa-facts">
		<span class="fh-sa-fact">
			<?php esc_html_e( 'The app:', 'fishotel-misc-plugin' ); ?>
			<a href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $app_url ); ?></a>
		</span>
		<span class="fh-sa-fact<?php echo $usable > 0 ? '' : ' is-warn'; ?>">
			<?php
			if ( $usable > 0 ) {
				/* translators: %d: people who can unlock */
				echo esc_html( sprintf( _n( '%d person can unlock', '%d people can unlock', $usable, 'fishotel-misc-plugin' ), $usable ) );
			} else {
				esc_html_e( 'Nobody has a PIN yet — add someone under People', 'fishotel-misc-plugin' );
			}
			?>
		</span>
		<span class="fh-sa-fact">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: session length, 2: where the count starts */
					__( 'A PIN unlocks for %1$s, counted from %2$s', 'fishotel-misc-plugin' ),
					\FisHotel\Misc\Sections\Shop_App\Lockout::human( (int) $settings['session_minutes'] * 60 ),
					'unlock' === $settings['session_mode'] ? __( 'the unlock', 'fishotel-misc-plugin' ) : __( 'the last use', 'fishotel-misc-plugin' )
				)
			);
			?>
		</span>
	</div>

	<?php include Shop_App::path() . 'views/admin-' . $tab . '.php'; ?>

</div>
