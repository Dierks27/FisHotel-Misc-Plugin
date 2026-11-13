<?php
/**
 * Layout — where each section sits in the FisHotel Tools menu and on the
 * Dashboard, and the one plain sentence its card says.
 *
 * Nothing here changes a slug, an option or a page: it only decides the
 * ORDER the switched-on sections add their submenu entries in, the
 * heading each Dashboard card sits under, and the sentence on the card.
 *
 *   Menu       Dashboard, Today, then Fish room (Clean Room), Customers
 *              (Invoices, Requests, Lost Fish), Shipping (Packing, Foam
 *              Cuts, Weather), Site (Health, Snippets, Shop App, then
 *              every other section in folder order). The sections that
 *              were live before 0.46 — Announcer, Coming Soon, Lottery
 *              Draw, Performance, Product Archive, Visitor Stats — keep
 *              their order among themselves.
 *   Dashboard  the same four headings; Today sits at the top on its own,
 *              with no heading.
 *
 * Pure (no WordPress call but the translation helper), so the order and
 * the groups are tested without WordPress.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Layout
 */
class Layout {

	/**
	 * The sections that come first in the menu, in this order. Every
	 * other section follows in folder (alphabetical) order.
	 *
	 * @var string[]
	 */
	const MENU_FIRST = array( 'today', 'clean-room', 'invoices', 'requests', 'lost-fish', 'packing', 'foam-cuts', 'weather', 'health', 'site-snippets', 'shop-app' );

	/**
	 * The Dashboard's headings and the sections under each. A section
	 * not listed goes under 'site'.
	 *
	 * @var array<string, string[]>
	 */
	const GROUPS = array(
		'today'     => array( 'today' ),
		'fish-room' => array( 'clean-room' ),
		'customers' => array( 'invoices', 'requests', 'lost-fish' ),
		'shipping'  => array( 'packing', 'foam-cuts', 'weather' ),
		'site'      => array( 'health', 'site-snippets', 'shop-app' ),
	);

	/**
	 * The section slugs in menu order (pure).
	 *
	 * @param string[] $slugs The sections, in folder order.
	 * @return string[]
	 */
	public static function menu_order( array $slugs ) {
		$slugs = array_values( array_map( 'strval', $slugs ) );
		$first = array_values( array_intersect( self::MENU_FIRST, $slugs ) );
		$rest  = array_values( array_diff( $slugs, self::MENU_FIRST ) );
		return array_merge( $first, $rest );
	}

	/**
	 * The sections (slug => anything) under their Dashboard headings, in
	 * menu order, a heading only when it has a section (pure).
	 *
	 * @param array $sections Slug => section, in folder order.
	 * @return array<int, array{group: string, title: string, sections: array}>
	 */
	public static function grouped( array $sections ) {
		$by = array_fill_keys( array_keys( self::GROUPS ), array() );
		foreach ( self::menu_order( array_keys( $sections ) ) as $slug ) {
			$by[ self::group_of( $slug ) ][ $slug ] = $sections[ $slug ];
		}

		$out = array();
		foreach ( $by as $group => $list ) {
			if ( $list ) {
				$out[] = array(
					'group'    => $group,
					'title'    => self::group_title( $group ),
					'sections' => $list,
				);
			}
		}
		return $out;
	}

	/**
	 * The heading a section sits under.
	 *
	 * @param string $slug Section slug.
	 * @return string
	 */
	public static function group_of( $slug ) {
		foreach ( self::GROUPS as $group => $slugs ) {
			if ( in_array( (string) $slug, $slugs, true ) ) {
				return $group;
			}
		}
		return 'site';
	}

	/**
	 * A heading in words ('' for Today's place at the top).
	 *
	 * @param string $group A key of GROUPS.
	 * @return string
	 */
	public static function group_title( $group ) {
		switch ( (string) $group ) {
			case 'today':
				return '';
			case 'fish-room':
				return __( 'Fish room', 'fishotel-misc-plugin' );
			case 'customers':
				return __( 'Customers', 'fishotel-misc-plugin' );
			case 'shipping':
				return __( 'Shipping', 'fishotel-misc-plugin' );
		}
		return __( 'Site', 'fishotel-misc-plugin' );
	}

	/**
	 * What a section's card says: what it is for, and what switching it
	 * on starts by itself (e-mails, nightly or hourly jobs, outside
	 * calls) — read from each section's README. '' for a section this
	 * list does not know (the card then shows the section's own
	 * description).
	 *
	 * @param string $slug Section slug.
	 * @return string
	 */
	public static function card_text( $slug ) {
		switch ( (string) $slug ) {
			case 'today':
				return __( 'The next delivery days and everything that is waiting on you, from every section that is on — as a page and a dashboard widget. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'clean-room':
				return __( 'Where every fish is, from landing to shipping: tanks with QR labels, the phone screen, the day\'s to-do list, supplier orders, check-in and stats. Starts nothing by itself: it sends no e-mail and calls nothing outside the site.', 'fishotel-misc-plugin' );
			case 'invoices':
				return __( 'Build a customer\'s invoice by picking fish and publish it as the hidden product they pay; it is marked paid when its order is paid. Starts nothing by itself and sends no e-mail.', 'fishotel-misc-plugin' );
			case 'requests':
				return __( 'Fish people ask for through private links, followed through the Clean Room until the fish is ready. Switching it on starts a daily check that — with the Clean Room on — e-mails people by itself when their fish is ready (Settings → "By itself" turns that off).', 'fishotel-misc-plugin' );
			case 'lost-fish':
				return __( 'When a fish a customer already paid for is lost: a case with every choice and an e-mail that waits for your click. Switching it on starts an hourly check that sends one reminder to a customer who has not answered after 2 days (Settings; 0 = none) — the first e-mail always waits for you.', 'fishotel-misc-plugin' );
			case 'packing':
				return __( 'Which bag every fish goes in and which box an order fits in, with a Packing box on every order for you to confirm. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'foam-cuts':
				return __( 'Plans the foam cuts for the day\'s boxes, prints the cut sheet and keeps the stock of whole sheets and leftovers. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'weather':
				return __( 'The delivery-day forecast for each order, written on the order as a note with heat pack / cooling / hold. Switching it on starts a nightly call to the National Weather Service for the orders delivering in the next few days (Settings turns the nightly job off); it sends no e-mail.', 'fishotel-misc-plugin' );
			case 'health':
				return __( 'One error log for every FisHotel plugin and the theme, with a dashboard widget. Switching it on starts a daily clean-up of old log rows; it e-mails only if you fill in Settings → Alert e-mail.', 'fishotel-misc-plugin' );
			case 'site-snippets':
				return __( 'The site\'s own code snippets, each switched on or off here, plus an editor for the people named in wp-config. Starts nothing by itself: a snippet runs only once you switch it on.', 'fishotel-misc-plugin' );
			case 'shop-app':
				return __( 'Lets a phone or tablet you set up open the fish-room screens with a PIN, without a WordPress login (needs HTTPS). Starts nothing by itself and sends no e-mail.', 'fishotel-misc-plugin' );
			case 'sticker-giveaway':
				return __( 'A free FisHotel sticker picked at checkout on fish orders, with optional add-on stickers. Switching it on adds the sticker choice to checkout; it starts nothing else.', 'fishotel-misc-plugin' );
			case 'announcer':
				return __( 'Notice bars and banners across the site. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'coming-soon':
				return __( 'Products marked "Coming Soon" with a release time and a countdown; they cannot be bought before it. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'lottery-draw':
				return __( 'Shares out group-order fish that more people want than there are, by a lottery anyone can check. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'performance':
				return __( 'Page-speed settings: script clean-up, browser caching, compression. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'product-archive':
				return __( 'Hides old products from the shop and search without deleting them. Starts nothing by itself.', 'fishotel-misc-plugin' );
			case 'visitor-stats':
				return __( 'Page views, who is on the site right now, and the top pages and products. Switching it on starts a daily clean-up of old page-view rows.', 'fishotel-misc-plugin' );
		}
		return '';
	}
}
