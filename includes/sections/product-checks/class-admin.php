<?php
/**
 * Product checks — FisHotel Tools → Product checks (which categories count
 * as live, the counts, "Recount now"), the chips of the Edit screens
 * section's Fish essentials box, and what wp-admin starts by itself: the
 * nightly sweep scheduled, a sweep when the rules changed.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * The admin-post action of the settings form.
	 *
	 * @var string
	 */
	const SAVE = 'fishotel_product_checks_save';

	/**
	 * The store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * The checker.
	 *
	 * @var Checker
	 */
	private $checker;

	/**
	 * @param Store|null   $store   The store.
	 * @param Checker|null $checker The checker.
	 */
	public function __construct( $store = null, $checker = null ) {
		$this->store   = is_object( $store ) ? $store : new Store();
		$this->checker = is_object( $checker ) ? $checker : new Checker( $this->store );
	}

	/**
	 * Register the hooks (wp-admin only).
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_filter( 'fishotel_edit_screens_missing', array( self::class, 'missing_chips' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_' . self::SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * The nightly start scheduled; a sweep when the rules changed.
	 */
	public function admin_init() {
		Checker::ensure_nightly();
		$this->checker->maybe_new_rules();
	}

	/**
	 * Fish essentials' "Still missing" chips: the flags of the product, each
	 * a link to its box.
	 *
	 * @param mixed $chips      Chips so far.
	 * @param int   $product_id Product ID.
	 * @return array
	 */
	public static function missing_chips( $chips, $product_id = 0 ) {
		$chips = is_array( $chips ) ? $chips : array();
		foreach ( Rules::chips( Products_List::stored( (int) $product_id ) ) as $chip ) {
			$chips[] = array(
				'label' => $chip['label'],
				'href'  => '#' . $chip['anchor'],
			);
		}
		return $chips;
	}

	/**
	 * The stylesheet on the products list and the section's page.
	 */
	public function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		if ( 'edit-product' === $id || false !== strpos( $id, Product_Checks::MENU_SLUG ) ) {
			$version = defined( 'FISHOTEL_MISC_VERSION' ) ? FISHOTEL_MISC_VERSION : '0';
			wp_enqueue_style( 'fishotel-product-checks', Product_Checks::url() . 'css/product-checks.css', array(), $version );
		}
	}

	/**
	 * The shop's product categories: slug → name, parents first.
	 *
	 * @return array<string, string>
	 */
	public static function categories() {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( is_object( $term ) && isset( $term->slug, $term->name ) ) {
				$out[ (string) $term->slug ] = (string) $term->name;
			}
		}
		return $out;
	}

	/**
	 * admin-post: save the live categories, start a sweep, back to the page.
	 */
	public function handle_save() {
		$raw     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save_request().
		$outcome = Settings::save_request( is_array( $raw ) ? $raw : array(), array_keys( self::categories() ) );
		if ( ! $outcome['ok'] ) {
			wp_die( esc_html( $outcome['message'] ), '', array( 'response' => 403 ) );
		}
		$this->checker->start();
		wp_safe_redirect( add_query_arg( 'fh_pc_saved', '1', admin_url( 'admin.php?page=' . Product_Checks::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * The page.
	 */
	public function render_page() {
		if ( ! current_user_can( Product_Checks::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}
		$cats   = self::categories();
		$live   = Settings::live_cats();
		$counts = $this->store->counts();
		$sweep  = Checker::state();
		$saved  = isset( $_GET['fh_pc_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which line to show.
		?>
		<div class="wrap fishotel-misc-wrap fh-pc-page">
			<h1><?php esc_html_e( 'Product checks', 'fishotel-misc-plugin' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. Every product is being checked again: the counts on the products list follow within a few minutes.', 'fishotel-misc-plugin' ); ?></p></div>
			<?php endif; ?>

			<div class="fishotel-misc-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-yes-alt"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'To fix, right now', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<ul class="fh-pc-counts">
					<?php foreach ( Products_List::filters() as $key => $label ) : ?>
						<li><a href="<?php echo esc_url( Products_List::url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a> <span class="fh-pc-n"><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<p class="fishotel-misc-card-desc">
					<?php
					if ( ! empty( $sweep['done'] ) ) {
						/* translators: 1: number of products, 2: date and time */
						echo esc_html( sprintf( __( 'Every product was last checked through: %1$d products, finished %2$s. A product is checked again each time it is saved; every product, each night.', 'fishotel-misc-plugin' ), (int) ( $sweep['checked'] ?? 0 ), function_exists( 'wp_date' ) ? wp_date( 'j M Y H:i', (int) $sweep['done'] ) : gmdate( 'j M Y H:i', (int) $sweep['done'] ) ) );
					} elseif ( isset( $sweep['cursor'] ) ) {
						/* translators: %d: number of products */
						echo esc_html( sprintf( __( 'Checking every product: %d so far.', 'fishotel-misc-plugin' ), (int) ( $sweep['checked'] ?? 0 ) ) );
					} else {
						esc_html_e( 'Every product is checked within a few minutes of switching this section on, then each night. A product is checked again each time it is saved.', 'fishotel-misc-plugin' );
					}
					?>
				</p>
				<p><a class="button" href="<?php echo esc_url( Products_List::link( 'recount' ) ); ?>"><?php esc_html_e( 'Recount now', 'fishotel-misc-plugin' ); ?></a></p>
			</div>

			<div class="fishotel-misc-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-admin-generic"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Which categories count as live', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'A product in one of these categories (its own, not one above it) — or with "Livestock — packed in a bag" ticked — is checked as a live fish: a real photo, a gallery photo, a bag size, the facts still typed in the description. The facts table is checked for Quarantined Fish only. Invoices are never live. If you bring corals in again, tick their category here.', 'fishotel-misc-plugin' ); ?>
				</p>
				<?php if ( ! Settings::saved() ) : ?>
					<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Now: the fish categories the Batch plugin uses (the default).', 'fishotel-misc-plugin' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( Settings::OPTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
					<?php if ( ! $cats ) : ?>
						<p><?php esc_html_e( 'No product categories yet.', 'fishotel-misc-plugin' ); ?></p>
					<?php else : ?>
						<ul class="fh-pc-cats">
							<?php foreach ( $cats as $slug => $name ) : ?>
								<li><label><input type="checkbox" name="live_cats[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( (string) $slug, $live, true ) ); ?>> <?php echo esc_html( $name ); ?> <code><?php echo esc_html( $slug ); ?></code></label></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
						<?php if ( Settings::saved() ) : ?>
							<button type="submit" class="button" name="use_default" value="1"><?php esc_html_e( 'Use the fish categories again', 'fishotel-misc-plugin' ); ?></button>
						<?php endif; ?>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
