<?php
/**
 * GitHub-based update checker for the plugin.
 *
 * Checks the GitHub releases API for newer versions and integrates
 * with the WordPress transient-based update system.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Update_Checker
 */
class Update_Checker {

	/**
	 * GitHub repository in owner/repo format.
	 *
	 * @var string
	 */
	private $github_repo = 'Dierks27/FisHotel-Misc-Plugin';

	/**
	 * Plugin basename (e.g. fishotel-misc-plugin/fishotel-misc-plugin.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug = 'fishotel-misc-plugin';

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Transient key for caching the update check.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'fishotel_misc_update_check';

	/**
	 * Cache duration in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_DURATION = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_basename = plugin_basename( FISHOTEL_MISC_FILE );
		$this->current_version = FISHOTEL_MISC_VERSION;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'add_check_update_link' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( $this, 'show_update_notice' ) );
	}

	/**
	 * Add "Check for Updates" link to plugin row meta.
	 *
	 * @param array  $links Plugin row meta links.
	 * @param string $file  Plugin file path.
	 * @return array
	 */
	public function add_check_update_link( $links, $file ) {
		if ( $this->plugin_basename !== $file ) {
			return $links;
		}

		$url = wp_nonce_url(
			admin_url( 'plugins.php?fishotel_misc_check_update=1' ),
			'fishotel_misc_check_update'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for Updates', 'fishotel-misc-plugin' ) . '</a>';

		return $links;
	}

	/**
	 * Handle the manual "Check for Updates" click.
	 */
	public function handle_manual_check() {
		if ( ! isset( $_GET['fishotel_misc_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'fishotel_misc_check_update' );

		// Clear the cached transient so a fresh check runs.
		delete_transient( self::TRANSIENT_KEY );

		// Force WordPress to re-check plugin updates.
		delete_site_transient( 'update_plugins' );

		$release = $this->fetch_latest_release();

		if ( is_wp_error( $release ) ) {
			set_transient( 'fishotel_misc_update_notice', array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Update check failed: %s', 'fishotel-misc-plugin' ),
					$release->get_error_message()
				),
			), 30 );
		} elseif ( $release && version_compare( $release['version'], $this->current_version, '>' ) ) {
			set_transient( 'fishotel_misc_update_notice', array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: new version number */
					__( 'FisHotel Misc Plugin v%s is available! Update from the plugins page.', 'fishotel-misc-plugin' ),
					$release['version']
				),
			), 30 );
		} else {
			set_transient( 'fishotel_misc_update_notice', array(
				'type'    => 'success',
				'message' => __( 'FisHotel Misc Plugin is up to date.', 'fishotel-misc-plugin' ),
			), 30 );
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Display one-time admin notice after manual update check.
	 */
	public function show_update_notice() {
		$notice = get_transient( 'fishotel_misc_update_notice' );

		if ( ! $notice ) {
			return;
		}

		delete_transient( 'fishotel_misc_update_notice' );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Hook into the update_plugins transient to inject our update info.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_cached_release();

		if ( ! $release || is_wp_error( $release ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $release['version'],
				'url'         => $release['html_url'],
				'package'     => $release['download_url'],
				'tested'      => '',
				'icons'       => array(),
			);
		} else {
			$transient->no_update[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $this->current_version,
				'url'         => '',
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the WordPress "View Details" modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$release = $this->get_cached_release();

		if ( ! $release || is_wp_error( $release ) ) {
			return $result;
		}

		$info                = new \stdClass();
		$info->name          = 'FisHotel Misc Plugin';
		$info->slug          = $this->plugin_slug;
		$info->version       = $release['version'];
		$info->author        = '<a href="https://github.com/Dierks27">FisHotel</a>';
		$info->homepage      = 'https://github.com/' . $this->github_repo;
		$info->download_link = $release['download_url'];
		$info->sections      = array(
			'description' => 'A modular container plugin with a dark theme admin interface for FisHotel tools.',
			'changelog'   => nl2br( esc_html( $release['body'] ) ),
		);

		return $info;
	}

	/**
	 * Get the latest release data, using a cached transient.
	 *
	 * @return array|false|WP_Error
	 */
	private function get_cached_release() {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return $cached;
		}

		$release = $this->fetch_latest_release();

		if ( is_wp_error( $release ) ) {
			// Cache errors briefly to avoid hammering the API.
			set_transient( self::TRANSIENT_KEY, false, 5 * MINUTE_IN_SECONDS );
			return $release;
		}

		set_transient( self::TRANSIENT_KEY, $release, self::CACHE_DURATION );

		return $release;
	}

	/**
	 * Fetch the latest release from GitHub.
	 *
	 * @return array|WP_Error Release data or error.
	 */
	private function fetch_latest_release() {
		$url = sprintf(
			'https://api.github.com/repos/%s/releases/latest',
			$this->github_repo
		);

		$response = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/vnd.github.v3+json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			if ( 404 === $code ) {
				return new \WP_Error( 'no_release', __( 'No releases found.', 'fishotel-misc-plugin' ) );
			}
			return new \WP_Error(
				'github_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub API returned status %d.', 'fishotel-misc-plugin' ),
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['tag_name'] ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid release data from GitHub.', 'fishotel-misc-plugin' ) );
		}

		// Strip leading "v" from tag if present.
		$version = ltrim( $body['tag_name'], 'v' );

		// Find the zip asset, or fall back to the zipball URL.
		$download_url = $body['zipball_url'] ?? '';
		if ( ! empty( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( 'application/zip' === $asset['content_type'] || str_ends_with( $asset['name'], '.zip' ) ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
			}
		}

		return array(
			'version'      => $version,
			'download_url' => $download_url,
			'html_url'     => $body['html_url'] ?? '',
			'body'         => $body['body'] ?? '',
		);
	}
}
