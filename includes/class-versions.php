<?php
/**
 * FisHotel versions — one row per FisHotel build installed on this site:
 * FH Boards, FisHotel SMTP, ShipTracker, Batch Manager, Misc, the active
 * theme (and WPIT Updater when it is there): its version, its folder,
 * whether it is switched on, and a warning when the same plugin sits in
 * two folders (the classic bad upload: a zip that unpacked as
 * "fishotel-batch-plugin-main" next to "fishotel-batch-manager") or when
 * a FisHotel plugin is switched off.
 *
 * Shown on the FisHotel Tools Dashboard (whatever sections are on) and on
 * the Health screen. Local reads only — the plugin headers WordPress
 * already reads for the Plugins page, the active plugin list and the
 * installed themes; never an outside call.
 *
 * rows() is pure, so every warning is tested without WordPress.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Versions
 */
class Versions {

	/**
	 * The FisHotel plugins, in the order the card lists them: the main
	 * file's name (the same in every folder a zip may unpack to) and the
	 * plugin's own name, either of which identifies a copy.
	 *
	 * @var array<string, array>
	 */
	const PLUGINS = array(
		'boards'      => array(
			'label' => 'FH Boards',
			'file'  => 'fh-boards.php',
		),
		'smtp'        => array(
			'label' => 'FisHotel SMTP',
			'file'  => 'fishotel-smtp.php',
		),
		'shiptracker' => array(
			'label' => 'FisHotel ShipTracker',
			'file'  => 'fishotel-shiptracker.php',
		),
		'batch'       => array(
			'label' => 'FisHotel Batch Manager',
			'file'  => 'fishotel-batch-manager.php',
		),
		'misc'        => array(
			'label' => 'FisHotel Misc Plugin',
			'file'  => 'fishotel-misc-plugin.php',
		),
		'wpit'        => array(
			'label'    => 'WPIT Updater',
			'file'     => 'wpit-updater.php',
			'optional' => true, // A row only when it is there; switched off is fine.
		),
	);

	/**
	 * The rows (pure).
	 *
	 * @param array $plugins Installed plugins: "folder/file.php" => array( Name, Version ) (get_plugins()).
	 * @param array $active  Active plugin files ("folder/file.php").
	 * @param array $theme   The active theme: name, version, folder.
	 * @param array $themes  Installed themes: folder => name.
	 * @return array[] key, label, version, folder, active, status (ok|warn|off|missing), notes[].
	 */
	public static function rows( array $plugins, array $active, array $theme, array $themes = array() ) {
		$rows = array();

		foreach ( self::PLUGINS as $key => $def ) {
			$copies = array();
			foreach ( $plugins as $file => $header ) {
				$file = str_replace( '\\', '/', (string) $file );
				$base = basename( $file );
				$name = is_array( $header ) ? (string) ( $header['Name'] ?? '' ) : '';
				if ( $base === $def['file'] || 0 === strcasecmp( $name, $def['label'] ) ) {
					$copies[ $file ] = array(
						'folder'  => false === strpos( $file, '/' ) ? '' : dirname( $file ),
						'version' => is_array( $header ) ? (string) ( $header['Version'] ?? '' ) : '',
						'active'  => in_array( $file, $active, true ),
					);
				}
			}

			$optional = ! empty( $def['optional'] );
			if ( ! $copies ) {
				if ( ! $optional ) {
					$rows[] = array(
						'key'     => $key,
						'label'   => $def['label'],
						'version' => '',
						'folder'  => '',
						'active'  => false,
						'status'  => 'missing',
						'notes'   => array( __( 'Not installed.', 'fishotel-misc-plugin' ) ),
					);
				}
				continue;
			}

			// The row shows the copy that is switched on, else the first.
			$shown = null;
			foreach ( $copies as $copy ) {
				if ( $copy['active'] ) {
					$shown = $copy;
					break;
				}
			}
			$is_active = null !== $shown;
			$shown     = $shown ? $shown : reset( $copies );

			$notes  = array();
			$status = 'ok';

			if ( count( $copies ) > 1 ) {
				$status  = 'warn';
				$folders = array();
				foreach ( $copies as $copy ) {
					$folders[] = ( '' !== $copy['folder'] ? $copy['folder'] : '(plugins folder)' ) . ' ' . ( '' !== $copy['version'] ? $copy['version'] : '?' ) . ( $copy['active'] ? ' — ' . __( 'on', 'fishotel-misc-plugin' ) : ' — ' . __( 'off', 'fishotel-misc-plugin' ) );
				}
				$notes[] = sprintf(
					/* translators: 1: number of copies, 2: the folders */
					__( 'Installed %1$d times: %2$s. Keep the one that is on; delete the other from Plugins (switched off first).', 'fishotel-misc-plugin' ),
					count( $copies ),
					implode( '; ', $folders )
				);
			}

			if ( ! $is_active ) {
				if ( $optional ) {
					$status = 'ok' === $status ? 'off' : $status;
				} else {
					$status  = 'warn';
					$notes[] = __( 'Installed but switched off. Switch it on under Plugins if this site should run it.', 'fishotel-misc-plugin' );
				}
			}

			$rows[] = array(
				'key'     => $key,
				'label'   => $def['label'],
				'version' => $shown['version'],
				'folder'  => $shown['folder'],
				'active'  => $is_active,
				'status'  => $status,
				'notes'   => $notes,
			);
		}

		// The active theme, and any other copy of the FisHotel theme.
		$theme_name   = (string) ( $theme['name'] ?? '' );
		$theme_folder = (string) ( $theme['folder'] ?? '' );
		$is_fishotel  = false !== stripos( $theme_name, 'fishotel' );
		$notes        = array();
		$status       = 'ok';

		if ( '' !== $theme_name && ! $is_fishotel ) {
			$status  = 'warn';
			$notes[] = __( 'The active theme is not FisHotel. The packing slips, the medication store and the order screens live in the FisHotel theme.', 'fishotel-misc-plugin' );
		}

		$others = array();
		foreach ( $themes as $folder => $name ) {
			if ( (string) $folder !== $theme_folder && false !== stripos( (string) $name, 'fishotel' ) ) {
				$others[] = (string) $folder;
			}
		}
		if ( $others ) {
			$status  = 'warn';
			$notes[] = sprintf(
				/* translators: %s: folders */
				__( 'Another copy of the FisHotel theme is installed in %s. Keep the active one; delete the other under Appearance → Themes.', 'fishotel-misc-plugin' ),
				implode( ', ', $others )
			);
		}

		$rows[] = array(
			'key'     => 'theme',
			/* translators: %s: theme name */
			'label'   => sprintf( __( 'Theme: %s', 'fishotel-misc-plugin' ), '' !== $theme_name ? $theme_name : '?' ),
			'version' => (string) ( $theme['version'] ?? '' ),
			'folder'  => $theme_folder,
			'active'  => true,
			'status'  => $status,
			'notes'   => $notes,
		);

		return $rows;
	}

	/**
	 * How many rows need a look (pure).
	 *
	 * @param array[] $rows rows().
	 * @return int
	 */
	public static function warnings( array $rows ) {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], array( 'warn', 'missing' ), true ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * The rows for this site: local reads only.
	 *
	 * @return array[]
	 */
	public static function collect() {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		$active  = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$theme  = array();
		$themes = array();
		if ( function_exists( 'wp_get_theme' ) ) {
			$current = wp_get_theme();
			$theme   = array(
				'name'    => (string) $current->get( 'Name' ),
				'version' => (string) $current->get( 'Version' ),
				'folder'  => (string) $current->get_stylesheet(),
			);
			foreach ( (array) wp_get_themes() as $folder => $installed ) {
				$themes[ (string) $folder ] = (string) $installed->get( 'Name' );
			}
		}

		return self::rows( $plugins, $active, $theme, $themes );
	}

	/**
	 * The card's HTML (escaped).
	 *
	 * @param array[] $rows rows().
	 * @return string
	 */
	public static function card_html( array $rows ) {
		$words = array(
			'ok'      => __( 'On', 'fishotel-misc-plugin' ),
			'warn'    => __( 'Needs a look', 'fishotel-misc-plugin' ),
			'off'     => __( 'Off', 'fishotel-misc-plugin' ),
			'missing' => __( 'Not installed', 'fishotel-misc-plugin' ),
		);

		$warnings = self::warnings( $rows );

		$html  = '<div class="fishotel-misc-card fishotel-misc-versions' . ( $warnings ? ' fishotel-misc-versions--warn' : '' ) . '">';
		$html .= '<div class="fishotel-misc-card-header"><div class="fishotel-misc-card-icon"><span class="dashicons dashicons-admin-plugins"></span></div>';
		$html .= '<h2 class="fishotel-misc-card-title">' . esc_html__( 'FisHotel versions', 'fishotel-misc-plugin' ) . '</h2></div>';
		$html .= '<p class="fishotel-misc-card-desc">' . esc_html(
			$warnings
				/* translators: %d: rows that need a look */
				? sprintf( _n( '%d line needs a look.', '%d lines need a look.', $warnings, 'fishotel-misc-plugin' ), $warnings )
				: __( 'Every FisHotel build is installed once and switched on.', 'fishotel-misc-plugin' )
		) . '</p>';
		$html .= '<table class="fishotel-misc-versions__table"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Build', 'fishotel-misc-plugin' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Version', 'fishotel-misc-plugin' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Folder', 'fishotel-misc-plugin' ) . '</th>';
		$html .= '<th>' . esc_html__( 'State', 'fishotel-misc-plugin' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$state = 'ok' === $row['status'] || ( 'warn' === $row['status'] && $row['active'] ) ? $words['ok'] : ( 'missing' === $row['status'] ? $words['missing'] : $words['off'] );
			$html .= '<tr class="fishotel-misc-versions__row fishotel-misc-versions__row--' . esc_attr( $row['status'] ) . '" data-build="' . esc_attr( $row['key'] ) . '">';
			$html .= '<td>' . esc_html( $row['label'] );
			foreach ( $row['notes'] as $note ) {
				$html .= '<span class="fishotel-misc-versions__note">' . esc_html( $note ) . '</span>';
			}
			$html .= '</td>';
			$html .= '<td>' . esc_html( '' !== $row['version'] ? $row['version'] : '—' ) . '</td>';
			$html .= '<td><code>' . esc_html( '' !== $row['folder'] ? $row['folder'] : '—' ) . '</code></td>';
			$html .= '<td>' . esc_html( $state ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table></div>';

		return $html;
	}
}
