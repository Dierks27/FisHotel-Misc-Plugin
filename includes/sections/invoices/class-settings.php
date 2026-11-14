<?php
/**
 * Invoices settings — one option, read only where an invoice is built or
 * listed (never on an ordinary front-end request, so it is not
 * autoloaded):
 *
 *   category_slug    the product category every invoice is filed under,
 *                    and the only one ("invoices").
 *   title_format     "{handle} Invoice ({date})" — {date} is the site date
 *                    as n/j/y.
 *   excerpt_format   "{handle} Only".
 *   shipping         which shipping-class slug "Premium" and "Free" stand
 *                    for (an invoice may also name any other class).
 *   image_under /    the featured image of an invoice whose total is under
 *   image_over /     the threshold, and of one at or over it. Nothing is
 *   image_threshold  seeded: without an image id an invoice gets no image.
 *   unpaid_days      after how many days a published invoice that is still
 *                    not paid shows on the Today page (default 7).
 *
 * sanitize() is pure, so the rules are tested without WordPress.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	const OPTION_KEY = 'fishotel_invoices_settings';

	const SHIPPING_CHOICES = array( 'premium', 'free', 'other' );

	const DEFAULT_CATEGORY  = 'invoices';
	const DEFAULT_THRESHOLD = 400.0;
	const MAX_FORMAT        = 120;

	const DEFAULT_UNPAID_DAYS = 7;
	const MIN_UNPAID_DAYS     = 1;
	const MAX_UNPAID_DAYS     = 90;

	/**
	 * The defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'category_slug'   => self::DEFAULT_CATEGORY,
			'title_format'    => Format::DEFAULT_TITLE,
			'excerpt_format'  => Format::DEFAULT_EXCERPT,
			'shipping'        => array(
				'premium' => 'premium',
				'free'    => 'free',
			),
			'image_under'     => 0,
			'image_over'      => 0,
			'image_threshold' => self::DEFAULT_THRESHOLD,
			'unpaid_days'     => self::DEFAULT_UNPAID_DAYS,
		);
	}

	/**
	 * The stored settings over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		$clean  = self::sanitize( is_array( $stored ) ? $stored : array() );
		return $clean['settings'];
	}

	/**
	 * Store what a form posted.
	 *
	 * @param mixed $raw Posted values (unslashed).
	 * @return array{settings: array, errors: string[]}
	 */
	public static function save( $raw ) {
		$clean = self::sanitize( is_array( $raw ) ? $raw : array(), self::get() );
		update_option( self::OPTION_KEY, $clean['settings'], false );
		return $clean;
	}

	/**
	 * A slug as terms write them, or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function slug( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$slug = strtolower( trim( (string) $value ) );
		$slug = (string) preg_replace( '/[^a-z0-9_%\-]+/', '-', $slug );
		return substr( trim( $slug, '-' ), 0, 100 );
	}

	/**
	 * Clean values over a base — the defaults, or the settings as they
	 * stand when a form is saved (pure). A value that cannot be used is
	 * refused with the reason in `errors` and the base's value kept: a
	 * title format that does not name the handle (every invoice would get
	 * the same title), a threshold that is not an amount.
	 *
	 * @param array      $raw  Raw values.
	 * @param array|null $base The values to start from (null = the defaults).
	 * @return array{settings: array, errors: string[]}
	 */
	public static function sanitize( array $raw, $base = null ) {
		$out    = array_merge( self::defaults(), is_array( $base ) ? $base : array() );
		$errors = array();

		if ( isset( $raw['category_slug'] ) ) {
			$slug = self::slug( $raw['category_slug'] );
			if ( '' !== $slug ) {
				$out['category_slug'] = $slug;
			}
		}

		foreach ( array( 'title_format', 'excerpt_format' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$format = Lines::text( $raw[ $key ], self::MAX_FORMAT );
			if ( '' === $format ) {
				continue;
			}
			if ( ! Format::usable( $format ) ) {
				$errors[] = 'title_format' === $key
					? __( 'The title format must contain {handle}. The earlier format was kept.', 'fishotel-misc-plugin' )
					: __( 'The short-description format must contain {handle}. The earlier format was kept.', 'fishotel-misc-plugin' );
				continue;
			}
			$out[ $key ] = $format;
		}

		if ( isset( $raw['shipping'] ) && is_array( $raw['shipping'] ) ) {
			foreach ( array( 'premium', 'free' ) as $choice ) {
				if ( isset( $raw['shipping'][ $choice ] ) ) {
					$out['shipping'][ $choice ] = self::slug( $raw['shipping'][ $choice ] );
				}
			}
		}

		foreach ( array( 'image_under', 'image_over' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $raw[ $key ] );
			}
		}

		if ( isset( $raw['image_threshold'] ) && is_scalar( $raw['image_threshold'] ) && '' !== trim( (string) $raw['image_threshold'] ) ) {
			$threshold = Lines::money( is_string( $raw['image_threshold'] ) ? $raw['image_threshold'] : (float) $raw['image_threshold'] );
			if ( null === $threshold || $threshold < 0 ) {
				$errors[] = __( 'The image threshold must be an amount of 0 or more. The earlier value was kept.', 'fishotel-misc-plugin' );
			} else {
				$out['image_threshold'] = $threshold;
			}
		}

		if ( isset( $raw['unpaid_days'] ) && is_scalar( $raw['unpaid_days'] ) && '' !== trim( (string) $raw['unpaid_days'] ) ) {
			$days = trim( (string) $raw['unpaid_days'] );
			if ( ! ctype_digit( $days ) || (int) $days < self::MIN_UNPAID_DAYS || (int) $days > self::MAX_UNPAID_DAYS ) {
				/* translators: 1: fewest days, 2: most days */
				$errors[] = sprintf( __( 'The days before an unpaid invoice shows on the Today page must be a whole number from %1$d to %2$d. The earlier value was kept.', 'fishotel-misc-plugin' ), self::MIN_UNPAID_DAYS, self::MAX_UNPAID_DAYS );
			} else {
				$out['unpaid_days'] = (int) $days;
			}
		}
		$out['unpaid_days'] = max( self::MIN_UNPAID_DAYS, min( self::MAX_UNPAID_DAYS, (int) $out['unpaid_days'] ) );

		return array(
			'settings' => $out,
			'errors'   => $errors,
		);
	}

	/**
	 * The shipping-class slug an invoice's shipping choice stands for:
	 * the mapped slug for Premium and Free, the invoice's own slug for
	 * another class. '' when nothing is mapped.
	 *
	 * @param array  $settings   Settings.
	 * @param string $choice     premium|free|other.
	 * @param string $other_slug The invoice's own class slug (for "other").
	 * @return string
	 */
	public static function shipping_slug( array $settings, $choice, $other_slug = '' ) {
		$choice = in_array( (string) $choice, self::SHIPPING_CHOICES, true ) ? (string) $choice : 'premium';
		if ( 'other' === $choice ) {
			return self::slug( $other_slug );
		}
		return isset( $settings['shipping'][ $choice ] ) ? self::slug( $settings['shipping'][ $choice ] ) : '';
	}

	/**
	 * A shipping choice as the screens name it.
	 *
	 * @param string $choice premium|free|other.
	 * @return string
	 */
	public static function shipping_label( $choice ) {
		switch ( (string) $choice ) {
			case 'free':
				return __( 'Free', 'fishotel-misc-plugin' );
			case 'other':
				return __( 'Another class', 'fishotel-misc-plugin' );
		}
		return __( 'Premium', 'fishotel-misc-plugin' );
	}
}
