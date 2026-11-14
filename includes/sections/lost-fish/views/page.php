<?php
/**
 * Cases tab — Needs you / Waiting for the customer / Finished.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 *
 * @var string     $tab       Current tab.
 * @var array|null $flash     A one-time notice.
 * @var bool       $tables_ok Whether the tables exist.
 * @var array      $status    Admin::connections().
 * @var array      $settings  Settings::get().
 * @var array      $groups    needs / waiting / finished => Admin::card()[].
 * @var array      $counts    Store::refresh_counts().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;

$fh_sections = array(
	'needs'   => array(
		'title' => __( 'Needs you', 'fishotel-misc-plugin' ),
		'empty' => __( 'Nothing needs you. A case shows up here when a loss leaves a paid customer short, when a customer has answered, or when no answer came.', 'fishotel-misc-plugin' ),
	),
	'waiting' => array(
		'title' => __( 'Waiting for the customer', 'fishotel-misc-plugin' ),
		'empty' => __( 'No e-mail is waiting for an answer.', 'fishotel-misc-plugin' ),
	),
);
?>
<div class="wrap fishotel-misc-wrap fh-lf-wrap">

	<?php include Lost_Fish::path() . 'views/top.php'; ?>

	<div class="fh-lf-summary">
		<span class="fh-lf-chip fh-lf-chip--needs"><strong><?php echo (int) count( $groups['needs'] ); ?></strong> <?php esc_html_e( 'need you', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-lf-chip"><strong><?php echo (int) count( $groups['waiting'] ); ?></strong> <?php esc_html_e( 'waiting for the customer', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-lf-chip fh-lf-chip--done"><strong><?php echo (int) ( $counts['done'] + $counts['closed'] ); ?></strong> <?php esc_html_e( 'finished', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-lf-chip <?php echo $settings['auto_send'] ? 'fh-lf-chip--warn' : ''; ?>">
			<?php
			echo esc_html(
				$settings['auto_send']
					? __( 'Send by itself: ON — a certain case goes out after the undo window', 'fishotel-misc-plugin' )
					: __( 'Send by itself: off — nothing is sent without your click', 'fishotel-misc-plugin' )
			);
			?>
		</span>
		<a class="button fh-lf-summary__new" href="<?php echo esc_url( Admin::page_url( 'new' ) ); ?>"><?php esc_html_e( 'Open a case by hand', 'fishotel-misc-plugin' ); ?></a>
	</div>

	<details class="fh-lf-reach">
		<summary><?php esc_html_e( 'What this screen can reach', 'fishotel-misc-plugin' ); ?></summary>
		<ul>
			<?php foreach ( $status as $fh_item ) : ?>
				<li class="<?php echo $fh_item['ok'] ? 'is-ok' : 'is-missing'; ?>">
					<strong><?php echo esc_html( $fh_item['label'] ); ?>:</strong>
					<?php echo esc_html( $fh_item['ok'] ? __( 'connected', 'fishotel-misc-plugin' ) : __( 'not available', 'fishotel-misc-plugin' ) . ' — ' . $fh_item['note'] ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</details>

	<?php foreach ( $fh_sections as $fh_key => $fh_section ) : ?>
		<h2 class="fh-lf-h2"><?php echo esc_html( $fh_section['title'] ); ?> <span class="fh-lf-count"><?php echo (int) count( $groups[ $fh_key ] ); ?></span></h2>
		<?php if ( ! $groups[ $fh_key ] ) : ?>
			<div class="fishotel-misc-empty"><span class="dashicons dashicons-heart"></span><p><?php echo esc_html( $fh_section['empty'] ); ?></p></div>
		<?php else : ?>
			<?php foreach ( $groups[ $fh_key ] as $card ) : ?>
				<?php include Lost_Fish::path() . 'views/case.php'; ?>
			<?php endforeach; ?>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if ( $groups['finished'] ) : ?>
		<details class="fh-lf-finished">
			<summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Finished (latest %d)', 'fishotel-misc-plugin' ), count( $groups['finished'] ) ) ); ?></summary>
			<?php foreach ( $groups['finished'] as $card ) : ?>
				<?php include Lost_Fish::path() . 'views/case.php'; ?>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>

</div>
