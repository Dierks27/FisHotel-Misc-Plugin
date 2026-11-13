<?php
/**
 * Shop App people — the person form's rules, and the WordPress account a
 * person's work is recorded under.
 *
 * A person is a name and a PIN. The work they do through the app is
 * recorded under a WordPress account (the Clean Room stores who did
 * what): their own, when they have one, otherwise the ONE shared
 * "Shop staff" account this section creates on first need — login
 * fishotel-shop-staff, no e-mail address, a random password nobody ever
 * sees, and the role fishotel_shop_staff, which holds no capability at
 * all: no wp-admin, nothing to edit. That account cannot log in with a
 * password, cannot be given an application password and cannot ask for a
 * password reset; it only ever acts inside a shop session.
 *
 * Whichever account it is, a shop session never has that account's own
 * powers — the Gate clamps it to the shop-floor capability.
 *
 * Switching a person off destroys their PIN (the hash is wiped);
 * switching them back on needs a new one. That is also what keeps PINs
 * unique among active people: a PIN is only ever compared with the
 * others at the moment it is typed into this form.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class People
 */
class People {

	/**
	 * Option holding the shared account's user id (not autoloaded).
	 *
	 * @var string
	 */
	const STAFF_OPTION = 'fishotel_shopapp_staff_user';

	const NAME_MAX = 80;

	/**
	 * Hooks that keep the shared account from ever being used as a login.
	 * Each callback only looks at the role of the user it is handed, so
	 * none of them costs a query.
	 */
	public static function init() {
		add_filter( 'wp_authenticate_user', array( self::class, 'refuse_password_login' ), 99, 1 );
		add_filter( 'allow_password_reset', array( self::class, 'refuse_password_reset' ), 99, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'refuse_application_passwords' ), 99, 2 );
	}

	/**
	 * Whether a user object is the shared account (by its role).
	 *
	 * @param mixed $user WP_User, or anything else.
	 * @return bool
	 */
	public static function is_staff_account( $user ) {
		return is_object( $user ) && isset( $user->roles ) && in_array( Shop_App::ROLE, (array) $user->roles, true );
	}

	/**
	 * The shared account never logs in with a password.
	 *
	 * @param mixed $user WP_User or WP_Error.
	 * @return mixed
	 */
	public static function refuse_password_login( $user ) {
		if ( self::is_staff_account( $user ) ) {
			return new \WP_Error( 'fishotel_shop_staff', __( 'This account cannot log in. It is used by the FisHotel Shop app only.', 'fishotel-misc-plugin' ) );
		}
		return $user;
	}

	/**
	 * The shared account never gets a password-reset e-mail.
	 *
	 * @param bool $allow   Whether a reset is allowed.
	 * @param int  $user_id User id.
	 * @return bool
	 */
	public static function refuse_password_reset( $allow, $user_id = 0 ) {
		if ( $allow && (int) $user_id > 0 && self::is_staff_account( get_userdata( (int) $user_id ) ) ) {
			return false;
		}
		return $allow;
	}

	/**
	 * The shared account never gets an application password.
	 *
	 * @param bool  $available Whether application passwords are available.
	 * @param mixed $user      WP_User.
	 * @return bool
	 */
	public static function refuse_application_passwords( $available, $user = null ) {
		return self::is_staff_account( $user ) ? false : $available;
	}

	/* ============================================================== */
	/*  The shared account                                             */
	/* ============================================================== */

	/**
	 * Make sure the role exists (it holds no capability).
	 */
	public static function ensure_role() {
		if ( function_exists( 'get_role' ) && ! get_role( Shop_App::ROLE ) ) {
			add_role( Shop_App::ROLE, __( 'Shop staff (PIN only)', 'fishotel-misc-plugin' ), array() );
		}
	}

	/**
	 * The shared account's user id; created on first need when $create.
	 *
	 * The id is kept in an option. When that is lost (or the account was
	 * deleted) an account that already holds the role is the one; only
	 * then is a new one made — under the login fishotel-shop-staff, or
	 * the next free one when somebody's own account already has that
	 * name: an account that is not this section's is never taken over.
	 *
	 * @param bool $create Whether to create it when it is missing.
	 * @return int 0 when there is none (and none could be made).
	 */
	public static function staff_user_id( $create = false ) {
		$stored = (int) get_option( self::STAFF_OPTION, 0 );
		if ( $stored > 0 && get_userdata( $stored ) ) {
			return $stored;
		}

		$found = get_users(
			array(
				'role'    => Shop_App::ROLE,
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		if ( $found && is_object( $found[0] ) && ! empty( $found[0]->ID ) ) {
			update_option( self::STAFF_OPTION, (int) $found[0]->ID, false );
			return (int) $found[0]->ID;
		}

		if ( ! $create ) {
			return 0;
		}

		self::ensure_role();

		$login = Shop_App::STAFF_LOGIN;
		for ( $n = 2; $n <= 20 && get_user_by( 'login', $login ); $n++ ) {
			$login = Shop_App::STAFF_LOGIN . '-' . $n;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => '',
				'display_name' => __( 'Shop staff', 'fishotel-misc-plugin' ),
				'nickname'     => __( 'Shop staff', 'fishotel-misc-plugin' ),
				'role'         => Shop_App::ROLE,
			)
		);
		if ( ! is_int( $user_id ) || $user_id <= 0 ) {
			return 0;
		}

		update_option( self::STAFF_OPTION, $user_id, false );

		return $user_id;
	}

	/**
	 * The WordPress account a person's work is recorded under: their own
	 * while it exists, else the shared one.
	 *
	 * @param array $person A people row (user_id).
	 * @param bool  $create Whether the shared account may be created now.
	 * @return int 0 when no account can be had.
	 */
	public static function account_id( array $person, $create = false ) {
		$own = (int) ( $person['user_id'] ?? 0 );
		if ( $own > 0 && get_userdata( $own ) ) {
			return $own;
		}
		return self::staff_user_id( $create );
	}

	/**
	 * What the People list says about a person's account.
	 *
	 * @param array $person A people row.
	 * @return array{label: string, shared: bool, missing: bool}
	 */
	public static function account_label( array $person ) {
		$own = (int) ( $person['user_id'] ?? 0 );
		if ( $own > 0 ) {
			$user = get_userdata( $own );
			if ( $user ) {
				return array(
					'label'   => (string) $user->display_name,
					'shared'  => false,
					'missing' => false,
				);
			}
			return array(
				'label'   => __( 'Shop staff — their own account was removed', 'fishotel-misc-plugin' ),
				'shared'  => true,
				'missing' => true,
			);
		}
		return array(
			'label'   => __( 'Shop staff (shared)', 'fishotel-misc-plugin' ),
			'shared'  => true,
			'missing' => false,
		);
	}

	/**
	 * The accounts the person form offers: the shop's staff accounts
	 * (administrators and shop managers; the roles are filterable) that
	 * the user filling the form may speak for — their own, and those they
	 * may edit — plus the account a person already has. So nobody can
	 * record work under an account above their own.
	 *
	 * @param int $keep A user id to keep in the list (the person's current account).
	 * @return array<int, string> User id => name.
	 */
	public static function account_choices( $keep = 0 ) {
		/**
		 * Filters the roles whose accounts a person can be tied to.
		 *
		 * @param string[] $roles Role slugs.
		 */
		$roles = (array) apply_filters( 'fishotel_shop_app_account_roles', array( 'administrator', 'shop_manager' ) );

		$out = array();
		$me  = (int) get_current_user_id();

		$users = get_users(
			array(
				'role__in' => array_values( array_filter( array_map( 'strval', $roles ) ) ),
				'number'   => 200,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		foreach ( (array) $users as $user ) {
			if ( ! is_object( $user ) || empty( $user->ID ) ) {
				continue;
			}
			$id = (int) $user->ID;
			if ( $id === $me || current_user_can( 'edit_user', $id ) ) {
				$out[ $id ] = (string) $user->display_name . ' (' . (string) $user->user_login . ')';
			}
		}

		$keep = (int) $keep;
		if ( $keep > 0 && ! isset( $out[ $keep ] ) ) {
			$user = get_userdata( $keep );
			if ( $user ) {
				$out[ $keep ] = (string) $user->display_name . ' (' . (string) $user->user_login . ')';
			}
		}

		return $out;
	}

	/* ============================================================== */
	/*  The form's rules                                               */
	/* ============================================================== */

	/**
	 * Check a person form and build the columns to write (pure given its
	 * inputs).
	 *
	 * @param array      $input    name, pin (may be ''), user_id, active.
	 * @param array|null $current  The stored row when editing, null when adding.
	 * @param array[]    $people   Every people row (for the uniqueness check).
	 * @param array      $settings Settings (pin_length).
	 * @param string     $secret   The site secret.
	 * @param int[]      $accounts The account ids this form may choose (0 is always allowed).
	 * @return array{ok: bool, errors: string[], fields: array, pin_changed: bool}
	 */
	public static function validate( array $input, $current, array $people, array $settings, $secret, array $accounts ) {
		$errors = array();
		$fields = array();
		$length = Pins::length( $settings['pin_length'] ?? Pins::MIN_LENGTH );
		$id     = is_array( $current ) ? (int) $current['id'] : 0;

		$name = isset( $input['name'] ) && is_scalar( $input['name'] ) ? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $input['name'] ) ) ) : '';
		// (string): before PHP 8 substr() of an empty string is false.
		$name = (string) ( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_MAX ) : substr( $name, 0, self::NAME_MAX ) );
		if ( '' === $name ) {
			$errors[] = __( 'Give the person a name.', 'fishotel-misc-plugin' );
		}
		$fields['name'] = $name;

		$active           = ! empty( $input['active'] ) && '0' !== (string) $input['active'];
		$fields['active'] = $active ? 1 : 0;

		$user_id = isset( $input['user_id'] ) && is_scalar( $input['user_id'] ) ? max( 0, (int) $input['user_id'] ) : 0;
		$had     = is_array( $current ) ? (int) $current['user_id'] : 0;
		if ( $user_id > 0 && $user_id !== $had && ! in_array( $user_id, array_map( 'intval', $accounts ), true ) ) {
			$errors[] = __( 'That account cannot be chosen here.', 'fishotel-misc-plugin' );
		}
		if ( $user_id > 0 ) {
			foreach ( $people as $other ) {
				if ( (int) $other['id'] !== $id && ! empty( $other['active'] ) && (int) $other['user_id'] === $user_id && $active ) {
					$errors[] = __( 'That account already belongs to another person here. Pick another one, or the shared Shop staff account.', 'fishotel-misc-plugin' );
					break;
				}
			}
		}
		$fields['user_id'] = $user_id;

		$pin         = isset( $input['pin'] ) && is_scalar( $input['pin'] ) ? trim( (string) $input['pin'] ) : '';
		$pin_changed = false;
		$has_pin     = is_array( $current ) && '' !== (string) $current['pin_hash'] && ! empty( $current['active'] );

		if ( ! $active ) {
			// Switched off: the PIN is destroyed, whatever was typed.
			$fields['pin_hash']   = '';
			$fields['pin_salt']   = '';
			$fields['pin_length'] = 0;
		} elseif ( '' !== $pin ) {
			if ( ! Pins::is_pin( $pin, $length ) ) {
				/* translators: %d: number of digits */
				$errors[] = sprintf( __( 'A PIN is exactly %d digits.', 'fishotel-misc-plugin' ), $length );
			} elseif ( '' === (string) $secret ) {
				$errors[] = __( 'The PIN could not be stored: the site secret is missing. Reload this page and try again.', 'fishotel-misc-plugin' );
			} elseif ( Pins::clashes( $pin, $people, $secret, $id ) ) {
				$errors[] = Pins::clash_message();
			} else {
				$salt                 = Pins::new_salt();
				$fields['pin_salt']   = $salt;
				$fields['pin_hash']   = Pins::hash( $pin, $salt, $secret );
				$fields['pin_length'] = $length;
				$pin_changed          = true;
			}
		} elseif ( ! $has_pin ) {
			/* translators: %d: number of digits */
			$errors[] = sprintf( __( 'Give the person a PIN of %d digits.', 'fishotel-misc-plugin' ), $length );
		}

		return array(
			'ok'          => ! $errors,
			'errors'      => $errors,
			'fields'      => $fields,
			'pin_changed' => $pin_changed,
		);
	}

	/**
	 * What the People list says about a person's PIN.
	 *
	 * @param array $person   A people row.
	 * @param array $settings Settings (pin_length).
	 * @return array{state: string, label: string} state ok|stale|none.
	 */
	public static function pin_state( array $person, array $settings ) {
		$length = Pins::length( $settings['pin_length'] ?? Pins::MIN_LENGTH );

		if ( empty( $person['active'] ) || '' === (string) $person['pin_hash'] ) {
			return array(
				'state' => 'none',
				'label' => __( 'No PIN', 'fishotel-misc-plugin' ),
			);
		}
		if ( (int) $person['pin_length'] !== $length ) {
			return array(
				'state' => 'stale',
				/* translators: 1: digits of the stored PIN, 2: digits a PIN has now */
				'label' => sprintf( __( 'Needs a new PIN (it has %1$d digits, PINs now have %2$d)', 'fishotel-misc-plugin' ), (int) $person['pin_length'], $length ),
			);
		}
		return array(
			'state' => 'ok',
			/* translators: %d: number of digits */
			'label' => sprintf( __( 'Set · %d digits', 'fishotel-misc-plugin' ), $length ),
		);
	}
}
