<?php
/**
 * Draws the Shop App's icons into assets/ — run by hand, from the command
 * line, when the icon should change:
 *
 *   php includes/sections/shop-app/tools/make-icons.php
 *
 * Needs PHP's GD extension (only here; the site never runs this file).
 * Three PNGs: 192 and 512 px with rounded corners on a transparent
 * square, and a 512 px "maskable" one — the background to every edge and
 * the fish inside the middle 80 %, so a launcher may cut it to any shape.
 * Each is drawn four times as large and scaled down, which smooths the
 * edges.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	fwrite( STDERR, "The GD extension is needed to draw the icons.\n" );
	exit( 1 );
}

/**
 * A filled polygon on every PHP version: before PHP 8 the number of
 * points is a required argument, from 8.1 on passing it is deprecated.
 *
 * @param resource|GdImage $image  Image.
 * @param int[]            $points x, y, x, y, …
 * @param int              $color  Colour.
 */
function fh_shop_app_polygon( $image, array $points, $color ) {
	if ( PHP_VERSION_ID >= 80000 ) {
		imagefilledpolygon( $image, $points, $color );
	} else {
		imagefilledpolygon( $image, $points, (int) ( count( $points ) / 2 ), $color );
	}
}

/**
 * Draw one icon.
 *
 * @param int  $size     Pixels.
 * @param bool $maskable Whether the background runs to every edge.
 * @return resource|GdImage
 */
function fh_shop_app_icon( $size, $maskable ) {
	$scale = 4;
	$big   = $size * $scale;
	$image = imagecreatetruecolor( $big, $big );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
	imagealphablending( $image, true );

	$dark   = imagecolorallocate( $image, 0x0f, 0x17, 0x20 );
	$accent = imagecolorallocate( $image, 0x35, 0xb8, 0xff );
	$pale   = imagecolorallocate( $image, 0x9d, 0xdc, 0xff );

	if ( $maskable ) {
		imagefilledrectangle( $image, 0, 0, $big, $big, $dark );
	} else {
		$radius = (int) round( $big * 0.21 );
		imagefilledrectangle( $image, $radius, 0, $big - $radius - 1, $big - 1, $dark );
		imagefilledrectangle( $image, 0, $radius, $big - 1, $big - $radius - 1, $dark );
		foreach ( array( array( $radius, $radius ), array( $big - $radius - 1, $radius ), array( $radius, $big - $radius - 1 ), array( $big - $radius - 1, $big - $radius - 1 ) ) as $corner ) {
			imagefilledellipse( $image, $corner[0], $corner[1], $radius * 2, $radius * 2, $dark );
		}
	}

	// The fish is drawn in a unit square, then placed: smaller on the maskable icon.
	$zoom   = $maskable ? 0.66 : 0.86;
	$offset = ( 1 - $zoom ) / 2;
	$at     = static function ( $value ) use ( $big, $zoom, $offset ) {
		return (int) round( ( $offset + $value * $zoom ) * $big );
	};
	$len    = static function ( $value ) use ( $big, $zoom ) {
		return (int) round( $value * $zoom * $big );
	};

	// Tail, top fin, body, eye, two bubbles.
	fh_shop_app_polygon( $image, array( $at( 0.66 ), $at( 0.50 ), $at( 0.93 ), $at( 0.31 ), $at( 0.86 ), $at( 0.50 ), $at( 0.93 ), $at( 0.69 ) ), $accent );
	fh_shop_app_polygon( $image, array( $at( 0.36 ), $at( 0.35 ), $at( 0.55 ), $at( 0.22 ), $at( 0.58 ), $at( 0.37 ) ), $accent );
	imagefilledellipse( $image, $at( 0.43 ), $at( 0.50 ), $len( 0.60 ), $len( 0.36 ), $accent );
	imagefilledellipse( $image, $at( 0.27 ), $at( 0.46 ), $len( 0.07 ), $len( 0.07 ), $dark );
	imagefilledellipse( $image, $at( 0.13 ), $at( 0.30 ), $len( 0.06 ), $len( 0.06 ), $pale );
	imagefilledellipse( $image, $at( 0.07 ), $at( 0.20 ), $len( 0.04 ), $len( 0.04 ), $pale );

	$out = imagecreatetruecolor( $size, $size );
	imagealphablending( $out, false );
	imagesavealpha( $out, true );
	imagecopyresampled( $out, $image, 0, 0, 0, 0, $size, $size, $big, $big );

	return $out;
}

$fh_dir = dirname( __DIR__ ) . '/assets/';
if ( ! is_dir( $fh_dir ) ) {
	mkdir( $fh_dir, 0755, true );
}

foreach ( array(
	'icon-192.png'          => array( 192, false ),
	'icon-512.png'          => array( 512, false ),
	'icon-maskable-512.png' => array( 512, true ),
) as $fh_name => $fh_spec ) {
	imagepng( fh_shop_app_icon( $fh_spec[0], $fh_spec[1] ), $fh_dir . $fh_name, 9 );
	echo $fh_name . ' ' . filesize( $fh_dir . $fh_name ) . " bytes\n";
}
