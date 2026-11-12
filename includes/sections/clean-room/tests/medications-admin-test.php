<?php
/**
 * Medications, the admin side: Settings → Medications' one save
 * (Medications_Admin::request() — the capability, the nonce, add / edit /
 * retire / bring back stored in the settings option and nowhere else,
 * every refusal storing nothing, the real wp_ajax handler up to its
 * reply), what the panel shows (how each medication is dosed in words,
 * its named doses and measure, whether the list was edited), the store's
 * public calculator's copper figure beside ours (the same, differing,
 * not comparable, the theme's data absent — said plainly), and what the
 * Board's Calculator is handed.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/medications-admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-medications-admin.php';

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Medications;
use FisHotel\Misc\Sections\Clean_Room\Medications_Admin;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$seed  = Medications::seed();
$nonce = wp_create_nonce( Settings::NONCE_ACTION );
$form  = array( '_wpnonce' => $nonce, 'op' => 'add', 'label' => 'Test Kanamycin', 'short' => 'Kana', 'aliases' => 'kan', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '30', 'per' => '1' );

echo "1. Who may save, and that a refusal stores nothing\n";
$GLOBALS['fh_user_caps']    = array( Clean_Room::SETTINGS_CAPABILITY => array( 1 ), Clean_Room::CAPABILITY => array( 1, 2 ) );
$GLOBALS['fh_current_user'] = 2;
$r                          = Medications_Admin::request( $form );
check( 'the crew (manage_woocommerce without manage_options) may not edit the list: 403, nothing stored', 403 === $r['status'] && false === $r['ok'] && 'Unauthorized.' === $r['message'] && array() === $GLOBALS['fh_options'] );
$GLOBALS['fh_current_user'] = 0;
check( 'nobody logged in: 403', 403 === Medications_Admin::request( $form )['status'] && array() === $GLOBALS['fh_options'] );
$GLOBALS['fh_current_user'] = 1;
$r                          = Medications_Admin::request( array_merge( $form, array( '_wpnonce' => 'nope' ) ) );
check( 'a wrong nonce: 403, nothing stored', 403 === $r['status'] && false !== strpos( $r['message'], 'expired' ) && array() === $GLOBALS['fh_options'] );
$without = $form;
unset( $without['_wpnonce'] );
check( 'no nonce at all, or one that is not text: 403', 403 === Medications_Admin::request( $without )['status'] && 403 === Medications_Admin::request( array_merge( $form, array( '_wpnonce' => array( $nonce ) ) ) )['status'] && array() === $GLOBALS['fh_options'] );
$r = Medications_Admin::request( array_merge( $form, array( 'op' => 'delete' ) ) );
check( 'an unknown operation: 400, nothing stored', 400 === $r['status'] && 'Unknown medication action. Nothing was changed.' === $r['message'] && array() === $GLOBALS['fh_options'] );
$r = Medications_Admin::request( array_merge( $form, array( 'label' => 'Metro' ) ) );
check( 'a refusal of the list (a name that is taken): 400 with the reason, nothing stored', 400 === $r['status'] && '"metro" is already a name of Metronidazole. Nothing was changed.' === $r['message'] && array() === $GLOBALS['fh_options'] );
$r = Medications_Admin::request( array_merge( $form, array( 'rate' => '' ) ) );
check( 'a per-gallon medication without its amount: 400', 400 === $r['status'] && false !== strpos( $r['message'], 'Type the amount per gallon' ) && $seed === Settings::medications() );

echo "2. Add, edit, retire, bring back\n";
update_option( Clean_Room::OPTION_KEY, array( 'sale_ready_days' => 35, 'copper_target' => 2.4, 'size_list' => array( 'Small' ) ) );
$r = Medications_Admin::request( $form );
check( 'add: 200, the message, the new key', 200 === $r['status'] && true === $r['ok'] && '"Test Kanamycin" added.' === $r['message'] && 'test-kanamycin' === $r['key'], json_encode( $r ) );
$list = Settings::medications();
check( 'stored in the settings option — and only the list changed', 8 === count( $list ) && 30.0 === $list['test-kanamycin']['rate'] && array( 'kan' ) === $list['test-kanamycin']['aliases'] && 35 === Settings::get()['sale_ready_days'] && 2.4 === Settings::get()['copper_target'] && array( 'Small' ) === Settings::get()['size_list'] && array( Clean_Room::OPTION_KEY ) === array_keys( $GLOBALS['fh_options'] ) );
$r = Medications_Admin::request( array( '_wpnonce' => $nonce, 'op' => 'save', 'key' => 'metro', 'label' => 'Metronidazole', 'short' => 'Metro', 'aliases' => '', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '20', 'per' => '1', 'doses' => array( array( 'label' => 'Double', 'rate' => '40' ), array( 'label' => '', 'rate' => '' ) ), 'measure' => array( 'label' => 'scoop', 'amount' => '0.5', 'unit' => 'g' ) ) );
$list = Settings::medications();
check( 'edit: the rate, a named dose and a measure; the key and its place stay', 200 === $r['status'] && '"Metronidazole" saved.' === $r['message'] && 20.0 === $list['metro']['rate'] && array( array( 'label' => 'Double', 'rate' => 40.0 ) ) === $list['metro']['doses'] && array( 'label' => 'scoop', 'amount' => 0.5, 'unit' => 'g' ) === $list['metro']['measure'] && 'metro' === array_keys( $list )[1] );
$r = Medications_Admin::request( array( '_wpnonce' => $nonce, 'op' => 'retire', 'key' => 'formalin' ) );
check( 'retire: no longer offered, still in the list', 200 === $r['status'] && true === Settings::medications()['formalin']['retired'] && ! isset( Medications::active( Settings::medications() )['formalin'] ) && 8 === count( Settings::medications() ) );
$r = Medications_Admin::request( array( '_wpnonce' => $nonce, 'op' => 'restore', 'key' => 'formalin' ) );
check( 'bring back', 200 === $r['status'] && false === Settings::medications()['formalin']['retired'] && '"Formalin" is offered again.' === $r['message'] );
check( 'a key that is not in the list: 400', 400 === Medications_Admin::request( array( '_wpnonce' => $nonce, 'op' => 'retire', 'key' => 'gone' ) )['status'] );

echo "2b. Two tabs: a form drawn before somebody else's change does not put the old values back\n";
$as_drawn = Settings::medications()['metro']; // Both tabs were loaded now: rate 20.
$tab      = array( '_wpnonce' => $nonce, 'op' => 'save', 'key' => 'metro', 'seen' => Medications::fingerprint( $as_drawn ), 'label' => 'Metronidazole', 'short' => 'Metro', 'aliases' => '', 'unit' => 'mg', 'mode' => 'per_gallon', 'rate' => '20', 'per' => '1', 'doses' => array( array( 'label' => 'Double', 'rate' => '40' ) ), 'measure' => array( 'label' => 'scoop', 'amount' => '0.5', 'unit' => 'g' ) );
$r        = Medications_Admin::request( array_merge( $tab, array( 'rate' => '30' ) ) );
check( 'tab A saves the rate 30: its form was drawn from the row as it is — saved', 200 === $r['status'] && 30.0 === Settings::medications()['metro']['rate'] );
$r = Medications_Admin::request( array_merge( $tab, array( 'aliases' => 'flagyl' ) ) );
check( 'tab B, loaded before that, adds an other name (its form still says 20): refused — it says the row was changed elsewhere and to reload', 400 === $r['status'] && '"Metronidazole" was changed elsewhere after this page was loaded — reload the page and make the change again. Nothing was changed.' === $r['message'], json_encode( $r ) );
check( '…and the rate is still 30, the other name not added', 30.0 === Settings::medications()['metro']['rate'] && array() === Settings::medications()['metro']['aliases'] );
$r = Medications_Admin::request( array_merge( $tab, array( 'seen' => Medications::fingerprint( Settings::medications()['metro'] ), 'rate' => '30', 'aliases' => 'flagyl' ) ) );
check( '…reloaded (the form drawn from the row as it is now): saved', 200 === $r['status'] && array( 'flagyl' ) === Settings::medications()['metro']['aliases'] && 30.0 === Settings::medications()['metro']['rate'] );
check( 'the fingerprint is the row\'s: the same for the same row, another after any change — a retire too', Medications::fingerprint( $as_drawn ) === Medications::fingerprint( $as_drawn ) && 16 === strlen( Medications::fingerprint( $as_drawn ) ) && Medications::fingerprint( $as_drawn ) !== Medications::fingerprint( Settings::medications()['metro'] ) && Medications::fingerprint( $as_drawn ) !== Medications::fingerprint( array_merge( $as_drawn, array( 'retired' => true ) ) ) );
// The three template helpers the form's view echoes with (the shared stubs have the returning ones).
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ) {
		$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute.
		}
		return $out;
	}
}
$fh_med = Settings::medications()['metro'];
$fh_op  = 'save';
$fh_uid = 'metro';
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/medication-form.php';
$html = (string) ob_get_clean();
check( 'the edit form carries it', false !== strpos( $html, '<input type="hidden" name="seen" value="' . Medications::fingerprint( $fh_med ) . '">' ) );
$fh_op = 'add';
ob_start();
include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/medication-form.php';
check( '…the Add form has nothing to compare with', false === strpos( (string) ob_get_clean(), 'name="seen"' ) );

echo "3. The real AJAX handler, up to its reply\n";
$admin = new Medications_Admin();
$reply = null;
$_POST = array( '_wpnonce' => $nonce, 'op' => 'retire', 'key' => 'salt' );
try {
	$admin->ajax();
} catch ( Fh_Test_Json $e ) {
	$reply = $e;
}
check( 'a good save answers success with the message', $reply && true === $reply->success && false !== strpos( (string) $reply->data['message'], '"Salt" retired' ) && true === Settings::medications()['salt']['retired'] );
$reply = null;
$_POST = array( '_wpnonce' => 'nope', 'op' => 'retire', 'key' => 'prazi' );
try {
	$admin->ajax();
} catch ( Fh_Test_Json $e ) {
	$reply = $e;
}
check( 'a bad one answers an error with its status', $reply && false === $reply->success && 403 === $reply->status && false === Settings::medications()['prazi']['retired'] );
$_POST = array();
$admin->init();
check( 'init() hooks the AJAX action and the assets', isset( $GLOBALS['fh_actions'][ 'wp_ajax_' . Medications_Admin::ACTION ] ) && 'fishotel_cleanroom_medications' === Medications_Admin::ACTION && isset( $GLOBALS['fh_actions']['admin_enqueue_scripts'] ) );

echo "4. What the panel shows\n";
$GLOBALS['fh_options'] = array();
$panel                 = Medications_Admin::panel( null, null );
$how                   = array();
foreach ( $panel['rows'] as $row ) {
	$how[ $row['medication']['key'] ] = $row['how'];
}
check( 'the seeded list, each with how it is dosed in words', array( 'copper' => '0.692 ml per gallon raises the level by 1.0 ppm', 'metro' => '25 mg per gallon', 'prazi' => '9.5 mg per gallon', 'formalin' => '1 ml per 10 gal', 'chloroquine' => '10 mg per gallon', 'fenbendazole' => '1 g per 100 gal', 'salt' => 'typed — no sum' ) === $how, json_encode( $how, JSON_UNESCAPED_UNICODE ) );
check( 'never edited: it says so; the copper row is marked', false === $panel['edited'] && true === $panel['rows'][0]['copper'] && false === $panel['rows'][1]['copper'] && false === $panel['theme_available'] );
check( 'the named doses and the measure in words', 'Loading 40 mg per gallon · Top-up 12 mg per gallon · Last 6 mg per gallon' === $panel['rows'][4]['doses'] && '' === $panel['rows'][1]['doses'] && '' === $panel['rows'][1]['measure'] && 'one scoop = 0.5 g' === Medications_Admin::measure_text( array( 'measure' => array( 'label' => 'scoop', 'amount' => 0.5, 'unit' => 'g' ) ) ) );
check( 'a rate for 5 gallons to a level reads as typed', '2 ml per 5 gal raises the level by 1.0 ppm' === Medications_Admin::how_text( Medications::normalize_row( array( 'key' => 'r', 'label' => 'Ramp', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => 2, 'per' => 5 ) ) ) );
Settings::save_medications( Medications::apply_op( $seed, 'retire', array( 'key' => 'salt' ) )['list'] );
$panel = Medications_Admin::panel( null, null );
check( 'an edited list: it no longer says "unchanged"; a retired one is still listed', true === $panel['edited'] && 7 === count( $panel['rows'] ) && true === $panel['rows'][6]['medication']['retired'] );
$GLOBALS['fh_options'] = array();

echo "5. The store's public calculator's figure beside ours\n";
$theme = array(
	'medications' => array(
		array( 'med_id' => 'copper_power', 'name_generic' => 'Copper Power (ionic/chelated copper)', 'dispensing_formula' => array( 'type' => 'liquid_ml_per_gal', 'ml_per_gal_per_ppm' => 0.692 ) ),
		array( 'med_id' => 'test_ramp', 'name_generic' => 'Test Ramp Liquid', 'dispensing_formula' => array( 'type' => 'liquid_ml_per_gal', 'ml_per_gal_per_ppm' => 0.4 ) ),
	),
);
$note = Medications_Admin::theme_note( $seed['copper'], $theme );
check( 'present and the same: said so, with the figure', 'same' === $note['state'] && 'The store\'s public Medication Calculator uses 0.692 ml per gallon per 1.0 ppm for Copper Power (ionic/chelated copper) — the same figure.' === $note['text'], $note['text'] );
$note = Medications_Admin::theme_note( array_merge( $seed['copper'], array( 'rate' => 0.7 ) ), $theme );
check( 'present and differing: both figures, and that one is out of date', 'differs' === $note['state'] && false !== strpos( $note['text'], 'uses 0.692 ml per gallon per 1.0 ppm' ) && false !== strpos( $note['text'], 'this list says 0.7 ml per gallon' ) && false !== strpos( $note['text'], 'out of date' ), $note['text'] );
$note = Medications_Admin::theme_note( array_merge( $seed['copper'], array( 'unit' => 'drops' ) ), $theme );
check( 'ours in another unit: shown, not compared', 'shown' === $note['state'] && false !== strpos( $note['text'], 'not compared' ) );
$note = Medications_Admin::theme_note( $seed['copper'], null );
check( 'the theme\'s data absent: said plainly for the copper product', 'absent' === $note['state'] && false !== strpos( $note['text'], 'is not available on this site' ) );
$note = Medications_Admin::theme_note( array_merge( $seed['copper'], array( 'label' => 'Unlisted Copper' ) ), $theme );
check( 'the data there but no entry of that name: said too', 'absent' === $note['state'] && false !== strpos( $note['text'], 'has no figure for "Unlisted Copper"' ) );
check( 'a medication not dosed to a level: nothing to say', array( 'state' => '', 'text' => '' ) === Medications_Admin::theme_note( $seed['metro'], $theme ) && array( 'state' => '', 'text' => '' ) === Medications_Admin::theme_note( $seed['salt'], null ) );
$ramp = Medications::normalize_row( array( 'key' => 'test-ramp-liquid', 'label' => 'Test Ramp Liquid', 'unit' => 'ml', 'mode' => 'to_level', 'rate' => 2, 'per' => 5 ) );
check( 'another medication dosed to a level: its figure when the theme has one (2 ml per 5 gal = 0.4), silence when not', 'same' === Medications_Admin::theme_note( $ramp, $theme )['state'] && '' === Medications_Admin::theme_note( $ramp, null )['state'] && '' === Medications_Admin::theme_note( array_merge( $ramp, array( 'label' => 'Unlisted' ) ), $theme )['state'] );
$panel = Medications_Admin::panel( $seed, $theme );
check( 'the panel carries the note on the copper row and says the data is there', true === $panel['theme_available'] && 'same' === $panel['rows'][0]['theme']['state'] && '' === $panel['rows'][1]['theme']['state'] );
add_filter( 'fishotel_cleanroom_theme_medication_data', static function ( $data ) use ( $theme ) { return $theme; } );
check( '…read by itself through the filter when not handed in', 'same' === Medications_Admin::panel()['rows'][0]['theme']['state'] );
$GLOBALS['fh_filters'] = array();
check( '…and absent when nothing answers', 'absent' === Medications_Admin::panel()['rows'][0]['theme']['state'] && false === Medications_Admin::panel()['theme_available'] );

echo "6. What the Board's Calculator is handed\n";
$config = Medications_Admin::calculator_config( new Fake_Store(), Settings::get() );
check( 'the medications offered, as the phone gets them', 7 === count( $config['medications'] ) && 'copper' === $config['medications'][0]['key'] && true === $config['medications'][0]['copper'] && 0.692 === $config['medications'][0]['rate'] );
check( 'each building\'s water as a quick pick (a building without tanks has none)', array( array( 'label' => 'Building 100', 'gallons' => 140 ), array( 'label' => 'Building 200', 'gallons' => 120 ), array( 'label' => 'Building 300', 'gallons' => 120 ), array( 'label' => 'Building 500', 'gallons' => 205 ), array( 'label' => 'Building 600', 'gallons' => 100 ) ) === $config['waters'], json_encode( $config['waters'] ) );
check( 'the copper target, the words of the sum and the labels', 2.5 === $config['copperTarget'] && Dose::strings() === $config['strings'] && 'Calculator' === $config['i18n']['calculator'] && false !== strpos( $config['i18n']['hint'], 'Nothing is saved' ) );
Settings::save_medications( Medications::apply_op( $seed, 'retire', array( 'key' => 'formalin' ) )['list'] );
check( 'a retired medication is not offered', 6 === count( Medications_Admin::calculator_config( new Fake_Store(), Settings::get() )['medications'] ) );
/**
 * A store whose layout cannot be read.
 */
class Fh_No_Layout {
	public function tanks( $include_offline = true ) {
		throw new \RuntimeException( 'no database' );
	}
	public function buildings() {
		return array();
	}
}
check( 'a store that cannot be read: no quick picks, the Calculator still works', array() === Medications_Admin::calculator_config( new Fh_No_Layout(), Settings::get() )['waters'] && 6 === count( Medications_Admin::calculator_config( new Fh_No_Layout(), Settings::get() )['medications'] ) );
$js      = (string) file_get_contents( dirname( __DIR__ ) . '/js/medications-admin.js' );
preg_match_all( "/\\bt\\('([A-Za-z0-9]+)'/", $js, $m );
$missing = array_values( array_diff( array_unique( $m[1] ), array_keys( $config['i18n'] ) ) );
check( 'every label the script asks for is one the server sends', array() === $missing && count( array_unique( $m[1] ) ) >= 10, implode( ', ', $missing ) );

fh_finish();
