<?php
/**
 * The redirect list (old category addresses → 301) and the page check.
 *
 * Run from the plugin root:
 *   php includes/sections/med-listings/tests/redirects-verify-test.php
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

require_once __DIR__ . '/seed.php';

use FisHotel\Misc\Sections\Med_Listings\Redirects;
use FisHotel\Misc\Sections\Med_Listings\Verify;
use FisHotel\Misc\Sections\Med_Listings\Words;

$ids = fh_seed();
$w   = Words::get();

$target = fh_term( 'product_cat', 'Powders & Chemicals', 'powders-chemicals', $ids['cat_meds'] );
$list   = array(
	array( 'from' => '/product-category/medications/antiparasitic/', 'tax' => 'product_cat', 'slug' => 'antiparasitic', 'segment' => 'medications/antiparasitic/', 'to_term' => $target, 'to_tax' => 'product_cat', 'to_url' => 'https://shop.test/old-stored/', 'change' => 'c1' ),
	array( 'from' => '/product-category/antiparasitic/', 'tax' => 'product_cat', 'slug' => 'antiparasitic', 'segment' => 'medications/antiparasitic/', 'to_term' => $target, 'to_tax' => 'product_cat', 'change' => 'c1' ),
	array( 'from' => '/product-category/medications/old-food/', 'tax' => 'product_cat', 'slug' => 'old-food', 'to_term' => $ids['cat_food'], 'keep_rest' => true, 'change' => 'c2' ),
	array( 'from' => '/product-tag/ich/', 'tax' => 'product_tag', 'slug' => 'ich', 'to_term' => $ids['cat_meds'], 'change' => 'c3' ),
);

echo "1. Which old address matches\n";
$m = function ( $path, $q = array() ) use ( $list ) {
	$hit = Redirects::find_match( $path, $q, $list );
	return $hit ? $hit['entry']['from'] . ' +' . $hit['suffix'] : null;
};
check( 'the old address itself', '/product-category/medications/antiparasitic/ +' === $m( '/product-category/medications/antiparasitic/' ) );
check( 'without the trailing slash, in capitals', '/product-category/medications/antiparasitic/ +' === $m( '/Product-Category/Medications/Antiparasitic' ) );
check( 'the short form WordPress also answers', '/product-category/antiparasitic/ +' === $m( '/product-category/antiparasitic/' ) );
check( 'page 3 keeps its page', '/product-category/medications/antiparasitic/ +page/3/' === $m( '/product-category/medications/antiparasitic/page/3/' ) );
check( 'the feed and an atom feed', '/product-category/medications/antiparasitic/ +feed/' === $m( '/product-category/medications/antiparasitic/feed/' ) && '/product-category/medications/antiparasitic/ +feed/atom/' === $m( '/product-category/medications/antiparasitic/feed/atom/' ) );
check( 'a query on the end is ignored', '/product-tag/ich/ +' === $m( '/product-tag/ich/?orderby=price' ) );
check( '?product_cat=antiparasitic', null !== $m( '/', array( 'product_cat' => 'antiparasitic' ) ) );
check( 'a renamed category carries what is under it', '/product-category/medications/old-food/ +flakes/' === $m( '/product-category/medications/old-food/flakes/' ) );
check( 'a removed one does not swallow other addresses', null === $m( '/product-category/medications/antiparasitic/something-else/' ) );
check( 'nothing else matches', null === $m( '/product-category/medications/' ) && null === $m( '/shop/' ) );

echo "\n2. Where it goes\n";
check( 'to the category as it is now (not the address stored then)', 'https://shop.test/product-category/medications/powders-chemicals/' === Redirects::target_url( $list[0] ) );
check( 'the stored address when the category is gone', 'https://shop.test/old-stored/' === Redirects::target_url( array_merge( $list[0], array( 'to_term' => 99999 ) ) ) );
check( 'page and feed parts go along', 'https://shop.test/x/page/2/' === Redirects::with_suffix( 'https://shop.test/x/', 'page/2/' ) && 'https://shop.test/x/feed/?a=1' === Redirects::with_suffix( 'https://shop.test/x?a=1', 'feed/' ) );
check( 'a product address with an old category in it gives the product', 'prazi-powder' === Redirects::product_slug_for( '/shop/medications/antiparasitic/prazi-powder/', $list ) && '' === Redirects::product_slug_for( '/shop/medications/other/prazi-powder/', $list ) );

echo "\n3. The list\n";
foreach ( $list as $e ) {
	Redirects::add( $e );
}
Redirects::add( array_merge( $list[3], array( 'to_term' => $target ) ) );
check( 'one entry per old address (the newer replaces)', 4 === count( Redirects::all() ) && $target === Redirects::all()[3]['to_term'] );
Redirects::add( array( 'from' => '/', 'to_term' => $target ) );
check( '…an entry for "/" is refused', 4 === count( Redirects::all() ) );
check( 'Undo of a change takes its entries off', 2 === Redirects::remove( 'c1' ) && 2 === count( Redirects::all() ) );
check( 'one taken off by its address', 1 === Redirects::remove( '', 'https://shop.test/product-tag/ich/' ) && 1 === count( Redirects::all() ) );

echo "\n4. The hook\n";
fh_reset();
$ids    = fh_seed();
$target = fh_term( 'product_cat', 'Powders & Chemicals', 'powders-chemicals', $ids['cat_meds'] );
Redirects::save( array( array( 'from' => '/product-category/medications/gone/', 'tax' => 'product_cat', 'slug' => 'gone', 'segment' => 'medications/gone/', 'to_term' => $target ) ) );
$go = function ( $uri, $is_404 = false ) {
	$_SERVER['REQUEST_URI']    = $uri;
	$_GET                      = array();
	$GLOBALS['fh']['is_404']   = $is_404;
	$GLOBALS['fh']['redirect'] = null;
	try {
		Redirects::maybe_redirect();
	} catch ( FH_Redirected $e ) {
		unset( $e );
	}
	return $GLOBALS['fh']['redirect'];
};
check( 'an old address: 301 to the new category', array( 'https://shop.test/product-category/medications/powders-chemicals/page/2/', 301 ) === $go( '/product-category/medications/gone/page/2/' ) );
check( 'a product address that went missing: 301 to the product', array( 'https://shop.test/shop/fluke-cure-powder/', 301 ) === $go( '/shop/medications/gone/fluke-cure-powder/', true ) );
check( '…only on a 404', null === $go( '/shop/medications/gone/fluke-cure-powder/', false ) );
fh_term( 'product_cat', 'Gone', 'gone', $ids['cat_meds'] );
check( 'an old address in use again is left alone', null === $go( '/product-category/medications/gone/' ) );
Redirects::register();
check( 'it hooks on template_redirect', has_action( 'template_redirect' ) );
check( 'the section keeps the redirects while switched off', false !== strpos( file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/med-listings/class-med-listings.php' ), "public static function boot_disabled() {\n\t\trequire_once self::path() . 'class-redirects.php';\n\t\tRedirects::register();" ) );

echo "\n5. The page check\n";
$page = '<html><head><title>Sample Flake | Treats Ich</title>'
	. '<meta name="description" content="Kills flukes &amp; velvet.">'
	. '<meta property="og:description" content="Sample Flake.">'
	. '<script type="application/ld+json">{"@type":"Product","description":"<p>Plain flake.<\/p>","review":[{"reviewBody":"Cured my tang of HITH"}]}</script>'
	. '<script>var x = "dose ich velvet";</script>'
	. '</head><body><nav><a href="/medication-dosing/">Medication Dosing</a></nav>'
	. '<h1>Sample Flake</h1><img src="a.jpg" alt="Flake for bloat"><p>Flake food containing metronidazole.</p>'
	. '<form data-product_variations="[{&quot;variation_description&quot;:&quot;&lt;p&gt;Feed as sole food&lt;\/p&gt;&quot;}]"></form>'
	. '<a href="https://shop.test/qt-scheduler/?med=metro">plan</a></body></html>';
$hits  = Verify::scan( $page, 'product', $w );
$where = array();
foreach ( $hits as $h ) {
	$where[ $h['where'] ][] = $h['word'];
}
check( 'the <title>', in_array( 'treats', $where['title'] ?? array(), true ) && in_array( 'ich', $where['title'] ?? array(), true ) );
check( 'meta descriptions', in_array( 'kills', $where['meta tag'] ?? array(), true ) && in_array( 'velvet', $where['meta tag'] ?? array(), true ) );
check( 'JSON-LD review texts', in_array( 'hith', $where['JSON-LD'] ?? array(), true ) );
check( 'picture alt text', in_array( 'bloat', $where['alt text'] ?? array(), true ) );
check( 'variation descriptions in the page source', in_array( 'feed as sole', $where['variation data'] ?? array(), true ) );
check( 'links to the calculator and the QT pages', in_array( 'medication-dosing', $where['link'] ?? array(), true ) && in_array( 'qt-scheduler', $where['link'] ?? array(), true ) );
check( 'words inside a script are not "on the page"', ! in_array( 'dose', $where['page text'] ?? array(), true ) );
check( 'the plain listing itself passes', array() === Verify::scan( '<html><head><title>Sample Flake</title></head><body><p>Flake food containing metronidazole.</p><p>Keep sealed, store cool and dry.</p></body></html>', 'product', $w ) );
$home = Verify::scan( '<html><body><nav><a href="/medication-dosing/">Medication Dosing</a></nav></body></html>', 'home', $w );
$keys = array_column( $home, 'key' );
check( 'a footer link found on the home page has the same key on a product page (shown once as "every page")', (bool) array_intersect( $keys, array_column( $hits, 'key' ) ) );

$tool = Verify::scan( '<p>Read about flukes.</p><script>var FISHOTEL_MED_LINKS = {"links":{"prazi":"https:\/\/shop.test\/shop\/fluke-cure-powder\/"}};</script>', 'tool', $w, array( 'https://shop.test/shop/fluke-cure-powder/' ) );
check( 'a tool page fails on a product link, even inside its script data', 1 === count( $tool ) && 'product-link' === $tool[0]['kind'] );
check( 'a tool page may talk about diseases (it is the guide)', array() === Verify::scan( '<p>Read about flukes and dosing.</p>', 'tool', $w, array( 'https://shop.test/shop/fluke-cure-powder/' ) ) );
check( 'a tool page with the medications category fallback fails', 1 === count( Verify::scan( '<a href="/product-category/medications/">Browse</a>', 'tool', $w, array() ) ) );

$paths = Verify::med_paths(
	array(
		array( 'url' => 'https://shop.test/shop/medications/powders-chemicals/general-cure-powder/', 'kind' => 'product' ),
		array( 'url' => 'https://shop.test/qt-guide/', 'kind' => 'tool' ),
	),
	array( array( 'from' => '/shop/medications/antiparasitic/general-cure-powder/' ), array( 'from' => '/product-category/medications/antiparasitic/' ) )
);
check( 'the tool-page check also knows the old addresses from the redirect list', in_array( '/shop/medications/antiparasitic/general-cure-powder/', $paths, true ) && in_array( '/product-category/medications/antiparasitic/', $paths, true ) && ! in_array( 'https://shop.test/qt-guide/', $paths, true ) );
$old_link = Verify::scan( '<p>Step 2: buy <a href="https://shop.test/shop/medications/antiparasitic/general-cure-powder/">it</a></p>', 'tool', $w, $paths );
check( 'a guide still linking to a product\'s old address (it redirects) fails', 1 === count( $old_link ) );

fh_done();
