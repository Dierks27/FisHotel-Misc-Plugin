<?php
/**
 * Clean Room front desk — the phone screen (one tank, big buttons, voice)
 * and its AJAX. A self-contained HTML page on the front end at
 * home_url( '/?fh_front_desk=1&tank=521' ) — the URL every QR label
 * encodes — with no theme header or footer, its own small CSS and JS
 * inlined, no external requests.
 *
 * Access: a visitor who is not logged in is sent to wp-login.php and back;
 * a logged-in user without manage_woocommerce sees "Not allowed". Every
 * AJAX action checks the nonce rendered into the page and the capability.
 *
 * Writes go through ONE action, fishotel_cleanroom_act:
 *   loss (the fastest path — Jeff counts bodies), sold / shipped, move
 *   (with a quantity = a split, an optional reason), arrival (always into
 *   a Spa tank, with an optional size from the standard list — or as an
 *   unknown fish, "??" and what it looks like), identify (the real fish and
 *   its size on a group: the stay and its supplier order line together —
 *   Identify), clear (the cleared date by hand), checkin (the check-in
 *   date by hand), ready (the stay's own sale date; empty = back to the
 *   batch's), count (a full
 *   recount), note (on a stay, or on the tank), copper (a reading on the
 *   tank), dose (a medication on the whole system or on the tank — the
 *   one write without an undo), tick (a to-do item done), undo, alias.
 * Temporary tanks (Temp_Tanks) ride on the same action: setup (a slot is
 * set up — from the picker, or from a free slot's own label),
 * setup_edit, setup_steps (its own schedule, for this tank alone),
 * takedown, and todo_tick (one line of the day's list by water system —
 * Todo — ticked for a whole building or for one tank); those need no tank
 * on the page. An arrival goes into a Spa tank or a QUARANTINE temporary
 * tank; a move never lands in a slot with nothing set up; a dose on a
 * temporary tank is that tank's own water whatever the sheet posted.
 *
 * The reply carries the refreshed tank screen so the page re-renders
 * without a reload, and the ids of the stay events just written so the
 * toast (kept until the next action, at least Settings::UNDO_SECONDS) can
 * undo them — an undo is a compensating event (Replay::compensating),
 * never a delete, and posting the same ids twice writes nothing twice.
 *
 * The switch behind the action is perform(), which takes the store in its
 * context so tests/front-desk-test.php can drive the guards (Spa-only
 * arrivals, the same-tank move, undo ownership / age / idempotence, the
 * overdrawn flag) against an in-memory store.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Front_Desk
 */
class Front_Desk {

	/**
	 * Nonce action rendered into the page and checked by every AJAX call.
	 *
	 * @var string
	 */
	const NONCE = 'fishotel_cleanroom_front';

	/**
	 * How long after an event the toast's undo is still honoured (seconds).
	 * The toast stays until the next action (at least Settings::UNDO_SECONDS);
	 * this is the server-side guard.
	 *
	 * @var int
	 */
	const UNDO_WINDOW = 900;

	/**
	 * How long the auth cookie lives for a login that came from a scanned
	 * label (seconds, about a year) — see extend_cookie_life().
	 *
	 * @var int
	 */
	const COOKIE_LIFE = 365 * 86400;

	const MAX_QTY  = 999;
	const MAX_DOSE = 9999;

	const REASONS = array( 'doa', 'disease', 'jumped', 'unknown' );

	/**
	 * The move reasons the Move sheet offers (stored in the move event's note).
	 *
	 * @var string[]
	 */
	const MOVE_REASONS = array( 'clear_building', 'bullying', 'pairing', 'hospital', 'other' );

	/**
	 * Register hooks. The AJAX hooks are registered on every request
	 * (admin-ajax.php is is_admin()); the page hooks only matter on the
	 * front end; the cookie filter only on a login bound for this page.
	 */
	public function init() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
		add_filter( 'auth_cookie_expiration', array( $this, 'extend_cookie_life' ), 10, 3 );

		add_action( 'wp_ajax_fishotel_cleanroom_act', array( $this, 'ajax_act' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_parse', array( $this, 'ajax_parse' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_suggest', array( $this, 'ajax_suggest' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_screen', array( $this, 'ajax_screen' ) );
	}

	/**
	 * A phone that scanned a label logs in once: when the login's
	 * redirect_to points at the Front Desk and the user may use it, the
	 * auth cookie lasts about a year instead of WordPress's two days (the
	 * default without "remember me"), so a scan does not mean another
	 * login every other day. Everyone else keeps the normal cookie life.
	 *
	 * @param int  $length   Seconds.
	 * @param int  $user_id  The user logging in.
	 * @param bool $remember Whether "remember me" was ticked (unused).
	 * @return int
	 */
	public function extend_cookie_life( $length, $user_id = 0, $remember = false ) {
		unset( $remember );

		$redirect = isset( $_REQUEST['redirect_to'] ) && is_scalar( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp-login.php's own field, read only to decide the cookie life.
		if ( '' === $redirect || false === strpos( $redirect, Clean_Room::QUERY_VAR ) ) {
			return $length;
		}
		if ( (int) $user_id <= 0 || ! user_can( (int) $user_id, Clean_Room::CAPABILITY ) ) {
			return $length;
		}

		return max( (int) $length, self::COOKIE_LIFE );
	}

	/**
	 * Expose the query var.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = Clean_Room::QUERY_VAR;
		return $vars;
	}

	/* ============================================================== */
	/*  The page                                                       */
	/* ============================================================== */

	/**
	 * Serve the phone page when the query var is set.
	 */
	public function maybe_render() {
		$flag = get_query_var( Clean_Room::QUERY_VAR, '' );
		if ( '' === (string) $flag && isset( $_GET[ Clean_Room::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page switch.
			$flag = sanitize_text_field( wp_unslash( $_GET[ Clean_Room::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === (string) $flag || '0' === (string) $flag ) {
			return;
		}

		$tank_number = isset( $_GET['tank'] ) ? absint( wp_unslash( $_GET['tank'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		nocache_headers();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( Clean_Room::front_desk_url( $tank_number ) ) );
			exit;
		}

		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			status_header( 403 );
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'Not allowed', 'fishotel-misc-plugin' ) . '</title></head>'
				. '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#111;color:#eee;padding:40px 20px;text-align:center"><h1 style="font-size:28px">' . esc_html__( 'Not allowed', 'fishotel-misc-plugin' ) . '</h1>'
				. '<p>' . esc_html__( 'This screen is for the shop crew. Ask Jeff for access.', 'fishotel-misc-plugin' ) . '</p>'
				. '<p><a style="color:#7cc7ff" href="' . esc_url( wp_logout_url( Clean_Room::front_desk_url( $tank_number ) ) ) . '">' . esc_html__( 'Log in as someone else', 'fishotel-misc-plugin' ) . '</a></p></body></html>';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$screen = self::screen_data( $tank_number );
		$config = array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'screen'   => $screen,
			'baseUrl'  => Clean_Room::front_desk_url( 0 ),
			'boardUrl' => admin_url( 'admin.php?page=' . Clean_Room::MENU_SLUG ),
			'i18n'     => array_merge( self::i18n(), self::temp_i18n() ),
		);

		$css  = (string) file_get_contents( Clean_Room::path() . 'css/front-desk.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own file, inlined so the page makes no further requests.
		$css .= "\n" . (string) file_get_contents( Clean_Room::path() . 'css/front-desk-temp.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the temporary tanks and the day's list, inlined the same way.
		$js  = (string) file_get_contents( Clean_Room::path() . 'js/front-desk.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		include Clean_Room::path() . 'views/front-desk.php';
		exit;
	}

	/**
	 * The strings the page shows (so they can be translated like the rest).
	 *
	 * @return array<string, string>
	 */
	public static function i18n() {
		return array(
			'arrival'     => __( 'Arrival', 'fishotel-misc-plugin' ),
			'move'        => __( 'Move', 'fishotel-misc-plugin' ),
			'lost'        => __( 'Lost', 'fishotel-misc-plugin' ),
			'lostOne'     => __( '−1 lost', 'fishotel-misc-plugin' ),
			'shipped'     => __( 'Shipped', 'fishotel-misc-plugin' ),
			'cleared'     => __( 'Cleared', 'fishotel-misc-plugin' ),
			'note'        => __( 'Note', 'fishotel-misc-plugin' ),
			'mic'         => __( 'Mic', 'fishotel-misc-plugin' ),
			'copper'      => __( 'Copper', 'fishotel-misc-plugin' ),
			'more'        => __( 'More', 'fishotel-misc-plugin' ),
			'save'        => __( 'Save', 'fishotel-misc-plugin' ),
			'cancel'      => __( 'Cancel', 'fishotel-misc-plugin' ),
			'back'        => __( 'Back', 'fishotel-misc-plugin' ),
			'undo'        => __( 'Undo', 'fishotel-misc-plugin' ),
			'saved'       => __( 'Saved', 'fishotel-misc-plugin' ),
			'undone'      => __( 'Undone', 'fishotel-misc-plugin' ),
			'empty'       => __( 'No fish in this tank', 'fishotel-misc-plugin' ),
			'whichFish'   => __( 'Which fish?', 'fishotel-misc-plugin' ),
			'allFish'     => __( 'All fish in this tank', 'fishotel-misc-plugin' ),
			'whereTo'     => __( 'Where to?', 'fishotel-misc-plugin' ),
			'howMany'     => __( 'How many?', 'fishotel-misc-plugin' ),
			'ofThem'      => __( 'of %d', 'fishotel-misc-plugin' ),
			'reason'      => __( 'Reason (optional)', 'fishotel-misc-plugin' ),
			'date'        => __( 'Date', 'fishotel-misc-plugin' ),
			'species'     => __( 'Species', 'fishotel-misc-plugin' ),
			'searchHint'  => __( 'Type a fish name…', 'fishotel-misc-plugin' ),
			'unlisted'    => __( 'Use as an unlisted fish', 'fishotel-misc-plugin' ),
			'recent'      => __( 'Recent', 'fishotel-misc-plugin' ),
			'headCount'   => __( 'Full recount', 'fishotel-misc-plugin' ),
			'noteHint'    => __( 'What happened?', 'fishotel-misc-plugin' ),
			'listening'   => __( 'Listening…', 'fishotel-misc-plugin' ),
			'heard'       => __( 'Heard', 'fishotel-misc-plugin' ),
			'notThatFish' => __( 'Not that fish', 'fishotel-misc-plugin' ),
			'confirmNow'  => __( 'Confirm now', 'fishotel-misc-plugin' ),
			'confirmIn'   => __( 'Confirming in %d…', 'fishotel-misc-plugin' ),
			'typeInstead' => __( 'Or type it (the keyboard mic key dictates)', 'fishotel-misc-plugin' ),
			'noVoice'     => __( 'This browser has no speech recognition. Type the phrase instead — the keyboard\'s mic key dictates.', 'fishotel-misc-plugin' ),
			'go'          => __( 'Go', 'fishotel-misc-plugin' ),
			'error'       => __( 'Something went wrong. Try again.', 'fishotel-misc-plugin' ),
			'expired'     => __( 'Session expired — tap to reload.', 'fishotel-misc-plugin' ),
			'sharesWater' => __( 'shares water with', 'fishotel-misc-plugin' ),
			'ownWater'    => __( 'its own water', 'fishotel-misc-plugin' ),
			'day'         => __( 'day %d', 'fishotel-misc-plugin' ),
			'inSince'     => __( 'in since %s', 'fishotel-misc-plugin' ),
			'readyOn'     => __( 'on sale %s', 'fishotel-misc-plugin' ),
			'noSaleDate'  => __( 'Sale date not set', 'fishotel-misc-plugin' ),
			'suggestedOn' => __( 'suggested %s', 'fishotel-misc-plugin' ),
			'stillInSpa'  => __( 'still in the Spa', 'fishotel-misc-plugin' ),
			'saleOwn'     => __( 'own date', 'fishotel-misc-plugin' ),
			'saleBatch'   => __( 'from the batch', 'fishotel-misc-plugin' ),
			'clearedOn'   => __( 'cleared %s', 'fishotel-misc-plugin' ),
			'live'        => __( 'live', 'fishotel-misc-plugin' ),
			'pickTank'    => __( 'Pick a tank', 'fishotel-misc-plugin' ),
			'spaTank'     => __( 'Check in to which Spa tank?', 'fishotel-misc-plugin' ),
			'allTanks'    => __( 'All tanks', 'fishotel-misc-plugin' ),
			'board'       => __( 'Room board', 'fishotel-misc-plugin' ),
			'qtHere'      => __( 'QT', 'fishotel-misc-plugin' ),
			'comingSoon'  => __( 'coming soon', 'fishotel-misc-plugin' ),
			'here'        => __( 'here', 'fishotel-misc-plugin' ),
			'emptyTank'   => __( 'empty', 'fishotel-misc-plugin' ),
			'noTables'    => __( 'The Clean Room tables are missing — deactivate and reactivate the plugin.', 'fishotel-misc-plugin' ),
			'today'       => __( 'Today', 'fishotel-misc-plugin' ),
			'todayDone'   => __( 'All done for today', 'fishotel-misc-plugin' ),
			'doa'         => __( 'DOA', 'fishotel-misc-plugin' ),
			'disease'     => __( 'Disease', 'fishotel-misc-plugin' ),
			'jumped'      => __( 'Jumped', 'fishotel-misc-plugin' ),
			'unknown'     => __( 'Unknown', 'fishotel-misc-plugin' ),
			'count'       => __( 'Count', 'fishotel-misc-plugin' ),
			'refresh'     => __( 'Refresh', 'fishotel-misc-plugin' ),
			'copperLast'  => __( 'Cu %s', 'fishotel-misc-plugin' ),
			'copperNone'  => __( 'no copper reading yet', 'fishotel-misc-plugin' ),
			'copperLow'   => __( 'below the floor', 'fishotel-misc-plugin' ),
			'copperHint'  => __( 'Reading (target %s, hold at %s or higher)', 'fishotel-misc-plugin' ),
			'ago'         => __( '%s ago', 'fishotel-misc-plugin' ),
			'clearedDate' => __( 'Cleared date', 'fishotel-misc-plugin' ),
			'checkinDate' => __( 'Check-in date', 'fishotel-misc-plugin' ),
			'readyDate'   => __( 'Sale date (this group)', 'fishotel-misc-plugin' ),
			'readyBatch'  => __( 'Empty = the batch date (%s).', 'fishotel-misc-plugin' ),
			'readyNone'   => __( 'Empty = no sale date: the batch has none yet (set it on the supplier order).', 'fishotel-misc-plugin' ),
			'unsetDate'   => __( 'Not cleared', 'fishotel-misc-plugin' ),
			'dates'       => __( 'Dates', 'fishotel-misc-plugin' ),
			'tankNote'    => __( 'Tank note', 'fishotel-misc-plugin' ),
			'forFish'     => __( 'For one fish group', 'fishotel-misc-plugin' ),
			'notSpa'      => __( 'Arrivals go into a Spa tank', 'fishotel-misc-plugin' ),
			'todayToDo'   => __( 'Today · %d to do', 'fishotel-misc-plugin' ),
			'todayNone'   => __( 'Today · all done', 'fishotel-misc-plugin' ),
			'cuLow'       => __( 'Cu low', 'fishotel-misc-plugin' ),
			'whyMove'     => __( 'Why? (optional)', 'fishotel-misc-plugin' ),
			'clearBuilding' => __( 'Clear building', 'fishotel-misc-plugin' ),
			'bullying'    => __( 'Bullying', 'fishotel-misc-plugin' ),
			'pairing'     => __( 'Pairing', 'fishotel-misc-plugin' ),
			'other'       => __( 'Other', 'fishotel-misc-plugin' ),
			'dose'        => __( 'Dose', 'fishotel-misc-plugin' ),
			'medication'  => __( 'Medication', 'fishotel-misc-plugin' ),
			'amount'      => __( 'Amount', 'fishotel-misc-plugin' ),
			'wholeSystem' => __( 'Whole system — %s', 'fishotel-misc-plugin' ),
			'thisTank'    => __( 'This tank only', 'fishotel-misc-plugin' ),
			'suggested'   => __( 'Suggested: %s', 'fishotel-misc-plugin' ),
			'noSuggest'   => __( 'Dosed to the test reading', 'fishotel-misc-plugin' ),
			'noUndoDose'  => __( 'A dose cannot be undone — note a correction instead.', 'fishotel-misc-plugin' ),
			'schedule'    => __( 'schedule', 'fishotel-misc-plugin' ),
			'size'        => __( 'Size', 'fishotel-misc-plugin' ),
			'sizeHint'    => __( 'optional', 'fishotel-misc-plugin' ),
			'notStandard' => __( 'not a standard size', 'fishotel-misc-plugin' ),
			'unknownFish' => __( 'Unknown fish (??)', 'fishotel-misc-plugin' ),
			'needsId'     => __( 'needs identifying', 'fishotel-misc-plugin' ),
			'identify'    => __( 'Identify', 'fishotel-misc-plugin' ),
			'correctFish' => __( 'Correct the fish', 'fishotel-misc-plugin' ),
			'identifyHint' => __( 'Pick the real fish and its size: the tank row, its supplier order line and the remembered box share follow.', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The strings of the temporary tanks' screens and of the day's list
	 * (merged into i18n() for the page).
	 *
	 * @return array<string, string>
	 */
	public static function temp_i18n() {
		return array(
			'tempNothing'        => __( 'Nothing is set up here.', 'fishotel-misc-plugin' ),
			'setUp'              => __( 'Set up', 'fishotel-misc-plugin' ),
			'setUpTitle'         => __( 'Set up a temporary tank', 'fishotel-misc-plugin' ),
			'setItUp'            => __( 'Set it up', 'fishotel-misc-plugin' ),
			'slotsFull'          => __( 'Every temporary slot is in use. Take one down, or add a slot in Settings → Layout.', 'fishotel-misc-plugin' ),
			'tempSlot'           => __( 'Slot', 'fishotel-misc-plugin' ),
			'tempName'           => __( 'Name', 'fishotel-misc-plugin' ),
			'tempNameHint'       => __( 'Hospital 1', 'fishotel-misc-plugin' ),
			'whatFor'            => __( 'What is it for?', 'fishotel-misc-plugin' ),
			'somethingElse'      => __( 'Something else', 'fishotel-misc-plugin' ),
			'whatForHint'        => __( 'in a few words', 'fishotel-misc-plugin' ),
			'tempKind'           => __( 'What kind of tank?', 'fishotel-misc-plugin' ),
			'kindQuarantine'     => __( 'Quarantine', 'fishotel-misc-plugin' ),
			'kindQuarantineHint' => __( 'for fish still in quarantine or under treatment', 'fishotel-misc-plugin' ),
			'kindClean'          => __( 'Clean', 'fishotel-misc-plugin' ),
			'kindCleanHint'      => __( 'for fish that are already cleared', 'fishotel-misc-plugin' ),
			'gallons'            => __( 'Gallons', 'fishotel-misc-plugin' ),
			'treatment'          => __( 'Treatment', 'fishotel-misc-plugin' ),
			'noTreatment'        => __( 'No treatment, just watching', 'fishotel-misc-plugin' ),
			/* translators: %s: what the tank is for */
			'usedForThis'        => __( 'Used for %s:', 'fishotel-misc-plugin' ),
			/* translators: %s: what the tank is for */
			'noneForTag'         => __( 'No schedule is marked "%s" yet — pick any one, or no treatment.', 'fishotel-misc-plugin' ),
			'whatItDoes'         => __( 'What it does — nothing is saved yet:', 'fishotel-misc-plugin' ),
			'justFeeding'        => __( 'Only the daily feeding, while fish are in it.', 'fishotel-misc-plugin' ),
			'feedingToo'         => __( 'plus the daily feeding', 'fishotel-misc-plugin' ),
			'startDate'          => __( 'Treatment starts (day 1)', 'fishotel-misc-plugin' ),
			'takeDown'           => __( 'Take down', 'fishotel-misc-plugin' ),
			/* translators: %s: what is set up (its name, or the slot) */
			'takeDownAsk'        => __( 'Take %s down? The slot is free again; its history is kept.', 'fishotel-misc-plugin' ),
			'takeDownFish'       => __( 'Move the fish out first — then it can be taken down.', 'fishotel-misc-plugin' ),
			'scheduleEdit'       => __( 'Schedule', 'fishotel-misc-plugin' ),
			'thisTankOnly'       => __( 'This tank\'s own schedule: changes here are for this tank only — the template stays as it is. Day 1 is the treatment start.', 'fishotel-misc-plugin' ),
			'addStep'            => __( 'Add a step', 'fishotel-misc-plugin' ),
			'stepName'           => __( 'Step', 'fishotel-misc-plugin' ),
			'stepDay'            => __( 'Day', 'fishotel-misc-plugin' ),
			'removeStep'         => __( 'Remove', 'fishotel-misc-plugin' ),
			'earlier'            => __( '−1 day', 'fishotel-misc-plugin' ),
			'later'              => __( '+1 day', 'fishotel-misc-plugin' ),
			'kindDose'           => __( 'dose', 'fishotel-misc-plugin' ),
			'kindTest'           => __( 'test', 'fishotel-misc-plugin' ),
			'kindWater'          => __( 'water change', 'fishotel-misc-plugin' ),
			'kindOther'          => __( 'other', 'fishotel-misc-plugin' ),
			'hospital'           => __( 'Hospital', 'fishotel-misc-plugin' ),
			'moveClearsHint'     => __( 'Still in quarantine: a move into the Clean Room — or into a clean temporary tank — clears the group today.', 'fishotel-misc-plugin' ),
			/* translators: 1: the temporary tank, 2: the group(s) */
			'moveClearAsk'       => __( '%1$s is a CLEAN temporary tank. Moving %2$s into it clears it today — out of quarantine.', 'fishotel-misc-plugin' ),
			'moveAndClear'       => __( 'Move and clear', 'fishotel-misc-plugin' ),
			'todayList'          => __( 'Today\'s list', 'fishotel-misc-plugin' ),
			/* translators: %d: things left */
			'todoLeft'           => __( '%d things left to do', 'fishotel-misc-plugin' ),
			/* translators: %d: things left (one) */
			'todoLeftOne'        => __( '%d thing left to do', 'fishotel-misc-plugin' ),
			'todoAllDone'        => __( 'All done', 'fishotel-misc-plugin' ),
			'todoNothing'        => __( 'Nothing is due today', 'fishotel-misc-plugin' ),
			/* translators: 1: the feeding step, 2: number of tanks (one) */
			'feedOne'            => __( '%1$s — %2$d tank with fish', 'fishotel-misc-plugin' ),
			/* translators: 1: the feeding step, 2: number of tanks */
			'feedMany'           => __( '%1$s — %2$d tanks with fish', 'fishotel-misc-plugin' ),
			/* translators: %d: tanks still to feed */
			'leftN'              => __( '%d left', 'fishotel-misc-plugin' ),
			'theTanks'           => __( 'the tanks', 'fishotel-misc-plugin' ),
			/* translators: %s: "day 2 group" */
			'forThe'             => __( 'for the %s', 'fishotel-misc-plugin' ),
			/* translators: %s: reading */
			'flagLow'            => __( 'Cu %s — below the floor', 'fishotel-misc-plugin' ),
			'flagDue'            => __( 'copper reading due', 'fishotel-misc-plugin' ),
			'flagOverdue'        => __( 'copper reading overdue', 'fishotel-misc-plugin' ),
			'flagNone'           => __( 'no reading yet', 'fishotel-misc-plugin' ),
			/* translators: 1: reading, 2: age */
			'flagLast'           => __( 'last one %1$s, %2$s ago', 'fishotel-misc-plugin' ),
			/* translators: %d: copper flags */
			'flagsN'             => __( '%d copper flags', 'fishotel-misc-plugin' ),
			'temporary'          => __( 'Temporary', 'fishotel-misc-plugin' ),
			/* translators: %d: fish */
			'fishN'              => __( '%d fish', 'fishotel-misc-plugin' ),
			'noFish'             => __( 'no fish in it', 'fishotel-misc-plugin' ),
			/* translators: %d: tanks (one) */
			'tanksOne'           => __( '%d tank with fish', 'fishotel-misc-plugin' ),
			/* translators: %d: tanks */
			'tanksMany'          => __( '%d tanks with fish', 'fishotel-misc-plugin' ),
			/* translators: %d: tank number */
			'tankN'              => __( 'tank %d', 'fishotel-misc-plugin' ),
			'nothingDueHere'     => __( 'Nothing is due on this tank today.', 'fishotel-misc-plugin' ),
			'noFishSystem'       => __( 'No fish in this system — nothing is due.', 'fishotel-misc-plugin' ),
		);
	}

	/* ============================================================== */
	/*  Screen data                                                    */
	/* ============================================================== */

	/**
	 * Everything the page needs for one tank (or, without a tank, for the
	 * tank picker): the tank with its occupants, its to-do and its last
	 * copper reading, the destination grid, the tanks a check-in may go
	 * into, the recent species, the standard sizes (the Arrival sheet's
	 * dropdown), the day's list by water system (todoList), what the
	 * "Set up a temporary tank" sheet offers (temp), the settings the JS
	 * honours.
	 *
	 * @param int $tank_number Tank number (0 = picker).
	 * @return array
	 */
	public static function screen_data( $tank_number ) {
		return self::screen_from( new Store(), (int) $tank_number, Settings::get(), Clean_Room::today(), Store::tables_exist() );
	}

	/**
	 * screen_data() against a given store (the tests' stand-in runs it too).
	 *
	 * @param object $store       Store or stand-in.
	 * @param int    $tank_number Tank number (0 = picker).
	 * @param array  $settings    Settings.
	 * @param string $today       Site date.
	 * @param bool   $tables_ok   Whether the tables exist.
	 * @return array
	 */
	public static function screen_from( $store, $tank_number, array $settings, $today, $tables_ok = true ) {
		$data = array(
			'today'       => $today,
			'saleDateMin' => Replay::SALE_DATE_MIN,
			'saleDateMax' => Replay::sale_date_max( $today ),
			'tablesOk'    => (bool) $tables_ok,
			'tank'        => null,
			'grid'        => array(),
			'spaTanks'    => array(),
			'recent'      => array(),
			'sizes'       => array(),
			'todoList'    => null,
			'temp'        => null,
			'settings'    => array(
				'autoConfirmSeconds' => (int) $settings['auto_confirm_seconds'],
				'voiceEnabled'       => ! empty( $settings['voice_enabled'] ),
				'undoSeconds'        => Settings::UNDO_SECONDS,
				'saleReadyDays'      => (int) $settings['sale_ready_days'], // The suggestion only (occupants carry saleSuggested).
				'copperFloor'        => (float) $settings['copper_floor'],
				'copperTarget'       => (float) $settings['copper_target'],
			),
		);

		if ( ! $data['tablesOk'] ) {
			return $data;
		}

		$protocols = Settings::protocols();
		$room      = $store->room( $today, $settings, $protocols );
		$tanks     = $room['tanks'];
		$buildings = $store->buildings();
		$current   = null;
		foreach ( $tanks as $tank ) {
			if ( (int) $tank['number'] === (int) $tank_number && 'offline' !== $tank['status'] ) {
				$current = $tank;
				break;
			}
		}

		$data['grid']     = self::grid( $buildings, $tanks, $current ? (int) $current['number'] : 0 );
		$data['spaTanks'] = self::spa_tanks( $tanks );
		$data['recent']   = $store->recent_species( 10 );
		$data['sizes']    = Sizes::standard( $store, $settings )['sizes'];
		$data['todoList'] = is_array( $room['todo'] ) ? self::todo_screen( $room['todo'] ) : null;
		$data['temp']     = self::temp_sheet( $tanks, $protocols, $today );

		if ( ! $current ) {
			return $data;
		}

		$occupants = array();
		foreach ( $current['occupants'] as $stay ) {
			$occupants[] = self::occupant( $stay, $data['sizes'] );
		}

		$copper = null;
		if ( ! empty( $current['copper'] ) ) {
			$ts     = (int) strtotime( $current['copper']['logged_at'] . ' UTC' );
			$copper = array(
				'value'    => (float) $current['copper']['value'],
				'loggedAt' => (string) $current['copper']['logged_at'],
				'ago'      => self::relative_age( max( 0, time() - $ts ) ),
				'state'    => Protocol::copper_state( (float) $current['copper']['value'], (float) $settings['copper_floor'] ),
			);
		}

		$building_row = array();
		foreach ( $buildings as $row ) {
			if ( (int) $row['id'] === (int) $current['building_id'] ) {
				$building_row = $row;
				break;
			}
		}
		$gallons = Layout::system_gallons( $building_row ? $building_row : $current['building'], $tanks );

		// A temporary tank is its own water: no system, the setup's own
		// gallons and schedule — and it behaves as what it was set up as.
		$slot         = Layout::is_slot( $current );
		$setup        = $slot && ! empty( $current['setup'] ) ? (array) $current['setup'] : null;
		$tank_gallons = $slot ? (int) ( $setup['gallons'] ?? 0 ) : (int) $current['gallons'];

		$data['tank'] = array(
			'id'          => (int) $current['id'],
			'number'      => (int) $current['number'],
			'title'       => $slot ? Temp_Tanks::title( $current, $today ) : Layout::tank_title( $current, $current['building'], $current['shares_with'] ),
			'path'        => $current['path'],
			'sizeLabel'   => (string) $current['size_label'],
			'gallons'     => $tank_gallons,
			'area'        => Temp_Tanks::effective_area( $current ),
			'areaLabel'   => Layout::area_label( $current['building']['area'] ),
			'building'    => Layout::building_label( $current['building'] ),
			'buildingId'  => (int) $current['building_id'],
			'isSpa'       => 'spa' === Temp_Tanks::effective_area( $current ),
			'ownWater'    => $slot,
			'sharesWith'  => $current['shares_with'],
			'system'      => array(
				'gallons'  => $gallons,
				'label'    => Layout::system_label( $gallons ),
				'schedule' => $slot ? ( $setup ? Temp_Tanks::schedule_label( $setup ) : '' ) : Protocol::template_for( $building_row ? $building_row : array(), $protocols ),
			),
			'medications' => self::medications( $gallons, $tank_gallons ),
			'occupants'   => $occupants,
			'todo'        => array_values( (array) $current['todo'] ),
			'copper'      => $copper,
			'url'         => Clean_Room::front_desk_url( (int) $current['number'] ),
			'temp'        => $slot ? self::slot_screen( $current, $today ) : null,
		);

		return $data;
	}

	/**
	 * What the page knows about a slot: its name ("TEMP 1"), whether it is
	 * free, and — when something is set up in it — the setup with its own
	 * steps, each with the days it falls on.
	 *
	 * @param array  $tank  Hydrated slot with its occupants.
	 * @param string $today Site date.
	 * @return array
	 */
	public static function slot_screen( array $tank, $today ) {
		$setup = ! empty( $tank['setup'] ) ? (array) $tank['setup'] : null;
		$out   = array(
			'slot'  => Layout::slot_of( $tank ),
			'label' => Layout::slot_name( Layout::slot_of( $tank ) ),
			'free'  => null === $setup,
			'setup' => null,
		);
		if ( ! $setup ) {
			return $out;
		}

		$steps = array();
		foreach ( (array) $setup['steps'] as $index => $step ) {
			$steps[] = array(
				'index' => (int) $index,
				'label' => (string) $step['label'],
				'kind'  => (string) $step['kind'],
				'day'   => (int) $step['day'],
				'when'  => 'feed' === (string) $step['kind'] ? __( 'daily', 'fishotel-misc-plugin' ) : Temp_Tanks::when_text( $step ),
				'note'  => (string) $step['note'],
			);
		}

		$out['setup'] = array(
			'id'        => (int) $setup['id'],
			'name'      => (string) $setup['name'],
			'purpose'   => (string) $setup['purpose'],
			'kind'      => (string) $setup['kind'],
			'kindLabel' => Temp_Tanks::kind_label( $setup['kind'] ),
			'gallons'   => (int) $setup['gallons'],
			'schedule'  => Temp_Tanks::schedule_label( $setup ),
			'startDate' => (string) $setup['start_date'],
			'day'       => Temp_Tanks::day( $setup, $today ),
			'dayText'   => Temp_Tanks::day_text( $setup, $today ),
			'note'      => (string) $setup['note'],
			'steps'     => $steps,
			'empty'     => empty( $tank['occupants'] ),
		);

		return $out;
	}

	/**
	 * What the "Set up a temporary tank" sheet offers: the free slots, the
	 * "what is it for" tags that exist, and every treatment template with
	 * the tags it carries and what it does (which medications, on which
	 * days) — shown before anything is saved.
	 *
	 * @param array[] $tanks     Hydrated tanks.
	 * @param array   $protocols Settings::protocols().
	 * @param string  $today     Site date.
	 * @return array
	 */
	public static function temp_sheet( array $tanks, array $protocols, $today ) {
		$map  = Temp_Tanks::tags( $protocols );
		$free = array();
		foreach ( Temp_Tanks::free_slots( $tanks ) as $tank ) {
			$free[] = array(
				'number' => (int) $tank['number'],
				'label'  => Layout::tank_label( $tank ),
				'url'    => Clean_Room::front_desk_url( (int) $tank['number'] ),
			);
		}
		$templates = array();
		foreach ( (array) $protocols['templates'] as $name => $steps ) {
			$templates[] = array(
				'name'    => (string) $name,
				'tags'    => isset( $map[ (string) $name ] ) ? $map[ (string) $name ] : array(),
				'preview' => Temp_Tanks::preview( (array) $steps ),
			);
		}

		return array(
			'slots'     => count( Temp_Tanks::slots( $tanks ) ),
			'free'      => $free,
			'tags'      => Temp_Tanks::all_tags( $map ),
			'templates' => $templates,
			'startMin'  => Replay::add_days( $today, -Temp_Tanks::START_BACK ),
			'startMax'  => Replay::add_days( $today, Temp_Tanks::START_AHEAD ),
		);
	}

	/**
	 * The day's list as the page shows it: Todo::build() with the age of
	 * every copper reading in words.
	 *
	 * @param array $list Todo::build().
	 * @return array
	 */
	public static function todo_screen( array $list ) {
		$ago = static function ( $logged_at ) {
			return '' !== (string) $logged_at ? self::relative_age( max( 0, time() - (int) strtotime( $logged_at . ' UTC' ) ) ) : '';
		};
		foreach ( $list['blocks'] as $b => $block ) {
			$list['blocks'][ $b ]['copper']['ago'] = $ago( $block['copper']['logged_at'] );
			$list['blocks'][ $b ]['url']           = 'temp' === $block['type'] ? Clean_Room::front_desk_url( (int) $block['number'] ) : '';
		}
		foreach ( $list['flags'] as $f => $flag ) {
			$list['flags'][ $f ]['ago'] = $ago( $flag['logged_at'] );
		}
		$list['text'] = Todo::headline( $list );
		return $list;
	}

	/**
	 * The medications the Dose sheet offers, with the suggested amount for
	 * the whole system and for this one tank (null for copper, which is
	 * dosed to the test).
	 *
	 * @param int $system_gallons The building's system volume.
	 * @param int $tank_gallons   This tank's volume.
	 * @return array[] key, label, short, unit, system (amount|null), tank (amount|null).
	 */
	public static function medications( $system_gallons, $tank_gallons ) {
		$out = array();
		foreach ( Protocol::MEDICATIONS as $key => $medication ) {
			$system = Protocol::dose_suggestion( $key, $system_gallons );
			$tank   = Protocol::dose_suggestion( $key, $tank_gallons );
			$out[]  = array(
				'key'    => $key,
				'label'  => $medication['label'],
				'short'  => $medication['short'],
				'unit'   => $medication['unit'],
				'system' => $system ? $system['amount'] : null,
				'tank'   => $tank ? $tank['amount'] : null,
			);
		}
		return $out;
	}

	/**
	 * One occupant for the page.
	 *
	 * @param array        $stay  Hydrated stay.
	 * @param array[]|null $sizes The standard sizes (null = a stored size is not checked against them).
	 * @return array
	 */
	public static function occupant( array $stay, $sizes = null ) {
		$size = Sizes::of( $stay, $sizes );
		return array(
			'stayId'          => (int) $stay['id'],
			'species'         => (string) $stay['species'],
			'size'            => $size ? $size['label'] : '',
			'sizeSlug'        => $size ? $size['slug'] : '',
			'sizeStandard'    => $size ? $size['standard'] : true,
			'productId'       => $stay['product_id'],
			'qtyLive'         => (int) $stay['qty_live'],
			'qtyArrived'      => (int) $stay['qty_arrived'],
			'day'             => $stay['day'],
			'status'          => (string) $stay['status'],
			'stateLabel'      => (string) $stay['state_label'],
			'checkinDate'     => (string) $stay['checkin_date'],
			'clearedDate'     => $stay['cleared_date'],
			'saleReadyDate'   => Replay::is_date( $stay['sale_ready_date'] ?? null ) ? (string) $stay['sale_ready_date'] : '',
			'saleReadyManual' => $stay['sale_ready_override'] ?? null,
			'saleSource'      => (string) ( $stay['sale_date_source'] ?? '' ),
			'batchSaleDate'   => $stay['batch_sale_date'] ?? null,
			'saleSuggested'   => (string) ( $stay['sale_ready_suggested'] ?? '' ),
			'saleReady'       => ! empty( $stay['sale_ready'] ),
			'readyInSpa'      => ! empty( $stay['ready_in_spa'] ),
			'needsId'         => ! empty( $stay['needs_id'] ),
		);
	}

	/**
	 * "2 h" / "35 min" / "3 d".
	 *
	 * @param int $seconds Age.
	 * @return string
	 */
	public static function relative_age( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			return __( 'moments', 'fishotel-misc-plugin' );
		}
		if ( $seconds < 3600 ) {
			/* translators: %d: minutes */
			return sprintf( __( '%d min', 'fishotel-misc-plugin' ), (int) floor( $seconds / 60 ) );
		}
		if ( $seconds < 86400 ) {
			/* translators: %d: hours */
			return sprintf( __( '%d h', 'fishotel-misc-plugin' ), (int) floor( $seconds / 3600 ) );
		}
		/* translators: %d: days */
		return sprintf( __( '%d d', 'fishotel-misc-plugin' ), (int) floor( $seconds / 86400 ) );
	}

	/**
	 * The destination grid: area → buildings → tanks, empty tanks first,
	 * the current tank flagged, buildings with a stay in QT flagged. The
	 * temporary tanks are a group of their own: each slot says what it is
	 * called ('title'), what is set up in it ('sub'), whether it is free
	 * and whether it is a clean tank — a move of a quarantine group into
	 * a clean one clears it.
	 *
	 * @param array[] $buildings Buildings.
	 * @param array[] $tanks     Tanks with occupants.
	 * @param int     $current   Current tank number.
	 * @return array[]
	 */
	public static function grid( array $buildings, array $tanks, $current ) {
		$groups = Layout::group( $buildings, $tanks );
		$out    = array();

		foreach ( $groups as $area => $group ) {
			$area_entry = array(
				'area'      => $area,
				'label'     => $group['label'],
				'buildings' => array(),
			);
			foreach ( $group['buildings'] as $entry ) {
				$building = $entry['building'];
				$in_qt    = 0;
				$list     = array();
				foreach ( $entry['tanks'] as $tank ) {
					if ( 'offline' === $tank['status'] ) {
						continue;
					}
					$in_qt += (int) ( $tank['in_qt'] ?? 0 );
					$slot   = Layout::is_slot( $tank );
					$setup  = $slot && ! empty( $tank['setup'] ) ? (array) $tank['setup'] : null;
					$list[] = array(
						'id'        => (int) $tank['id'],
						'number'    => (int) $tank['number'],
						'level'     => (int) $tank['level'],
						'sizeLabel' => (string) $tank['size_label'],
						'empty'     => empty( $tank['occupants'] ),
						'count'     => count( (array) ( $tank['occupants'] ?? array() ) ),
						'current'   => (int) $tank['number'] === (int) $current,
						'url'       => Clean_Room::front_desk_url( (int) $tank['number'] ),
						'temp'      => $slot,
						'free'      => $slot && null === $setup,
						'clean'     => 'clean' === Temp_Tanks::effective_area( $tank ),
						'title'     => $slot ? Layout::slot_name( Layout::slot_of( $tank ) ) : '',
						'sub'       => $setup ? trim( ( '' !== trim( (string) $setup['name'] ) ? trim( (string) $setup['name'] ) . ' · ' : '' ) . strtolower( Temp_Tanks::kind_label( $setup['kind'] ) ) ) : '',
					);
				}
				usort(
					$list,
					static function ( $a, $b ) {
						if ( $a['empty'] !== $b['empty'] ) {
							return $a['empty'] ? -1 : 1;
						}
						return $a['number'] <=> $b['number'];
					}
				);
				$area_entry['buildings'][] = array(
					'id'     => (int) $building['id'],
					'number' => (int) $building['number'],
					'label'  => $entry['label'],
					'status' => (string) $building['status'],
					'inQt'   => $in_qt,
					'temp'   => Layout::is_own_water( $building ),
					'tanks'  => $list,
				);
			}
			$out[] = $area_entry;
		}

		return $out;
	}

	/**
	 * The tanks a check-in may go into — the Spa tanks and any QUARANTINE
	 * temporary tank ('title' names it: "TEMP 1") —, empty ones first.
	 *
	 * @param array[] $tanks Tanks with occupants.
	 * @return array[]
	 */
	public static function spa_tanks( array $tanks ) {
		$out = array();
		foreach ( $tanks as $tank ) {
			if ( ! Temp_Tanks::takes_arrivals( $tank ) ) {
				continue;
			}
			$entry = array(
				'number'    => (int) $tank['number'],
				'label'     => Layout::building_label( $tank['building'] ),
				'sizeLabel' => (string) $tank['size_label'],
				'empty'     => empty( $tank['occupants'] ),
				'count'     => count( (array) $tank['occupants'] ),
			);
			if ( Layout::is_slot( $tank ) ) {
				$entry['title'] = Layout::slot_name( Layout::slot_of( $tank ) );
			}
			$out[] = $entry;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['empty'] !== $b['empty'] ) {
					return $a['empty'] ? -1 : 1;
				}
				return $a['number'] <=> $b['number'];
			}
		);
		return $out;
	}

	/* ============================================================== */
	/*  AJAX guards                                                    */
	/* ============================================================== */

	/**
	 * Capability + nonce, or die with JSON.
	 */
	private static function guard() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * A sanitised string from the (unslashed) payload.
	 *
	 * @param array  $raw Payload.
	 * @param string $key Key.
	 * @param int    $max Max length.
	 * @return string
	 */
	private static function text( array $raw, $key, $max = 200 ) {
		$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		return (string) substr( $value, 0, $max );
	}

	private static function int( array $raw, $key, $default = 0 ) {
		return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : (int) $default;
	}

	/**
	 * The event_at (UTC) for a chosen site date: now when it is today,
	 * noon site time otherwise.
	 *
	 * @param string $date  Y-m-d.
	 * @param string $today Y-m-d.
	 * @return string
	 */
	public static function event_at_for_date( $date, $today ) {
		if ( ! Replay::is_date( $date ) || $date === $today ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return Store::site_noon_utc( $date );
	}

	/* ============================================================== */
	/*  AJAX: act                                                      */
	/* ============================================================== */

	/**
	 * The one write action.
	 */
	public function ajax_act() {
		self::guard();

		$raw   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$act   = isset( $raw['act'] ) ? sanitize_key( $raw['act'] ) : '';
		$via   = isset( $raw['via'] ) ? sanitize_key( $raw['via'] ) : 'qr';
		$via   = in_array( $via, array( 'voice', 'qr', 'manual' ), true ) ? $via : 'qr';
		$tankn = self::int( $raw, 'tank' );

		if ( ! Store::tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'The Clean Room tables are missing — deactivate and reactivate the plugin.', 'fishotel-misc-plugin' ) ), 500 );
		}

		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();
		$tank     = $tankn > 0 ? $store->tank_by_number( $tankn ) : null;

		if ( ! in_array( $act, array( 'undo', 'alias' ), true ) && ! in_array( $act, Temp_Tanks::ACTS, true ) && ( ! $tank || 'offline' === $tank['status'] ) ) {
			wp_send_json_error( array( 'message' => __( 'That tank is not in the layout.', 'fishotel-misc-plugin' ) ), 400 );
		}

		$ctx = array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $tank,
			'base'     => array(
				'actor_id' => (int) get_current_user_id(),
				'source'   => $via,
			),
		);

		$result = self::perform( $act, $ctx, $raw );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['error'] ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Saved', 'fishotel-misc-plugin' ) ),
				'undo'    => array(
					'eventIds' => array_values( array_map( 'intval', (array) ( $result['event_ids'] ?? array() ) ) ),
					'label'    => (string) ( $result['message'] ?? '' ),
				),
				'screen'  => self::screen_data( (int) ( $result['tank'] ?? $tankn ) ),
			)
		);
	}

	/**
	 * Run one action against a context: the switch behind ajax_act(), on
	 * its own so the guards can be tested against an in-memory store.
	 *
	 * @param string $act The action.
	 * @param array  $ctx store (Store or a stand-in), settings, today, tank (row or null),
	 *                    base (actor_id, source).
	 * @param array  $raw The unslashed payload.
	 * @return array message, event_ids, tank (optional) — or error.
	 */
	public static function perform( $act, array $ctx, array $raw ) {
		// A slot with nothing set up is a place, not a tank: nothing is logged on it.
		$refused = Temp_Tanks::free_slot_error( (string) $act, $ctx['tank'] ?? null );
		if ( '' !== $refused ) {
			return array( 'error' => $refused );
		}

		try {
			switch ( (string) $act ) {
				case 'arrival':
					$result = self::act_arrival( $ctx, $raw );
					break;
				case 'identify':
					$result = self::act_identify( $ctx, $raw );
					break;
				case 'loss':
				case 'sold':
					$result = self::act_loss_or_sold( $ctx, $raw, (string) $act );
					break;
				case 'move':
					$result = self::act_move( $ctx, $raw );
					break;
				case 'clear':
					$result = self::act_clear( $ctx, $raw );
					break;
				case 'checkin':
					$result = self::act_checkin( $ctx, $raw );
					break;
				case 'ready':
					$result = self::act_ready( $ctx, $raw );
					break;
				case 'count':
					$result = self::act_count( $ctx, $raw );
					break;
				case 'note':
					$result = self::act_note( $ctx, $raw );
					break;
				case 'copper':
					$result = self::act_copper( $ctx, $raw );
					break;
				case 'dose':
					// A temporary tank is its own water: never "the whole system".
					$result = self::act_dose( $ctx, Temp_Tanks::own_water_dose( $ctx['tank'] ?? null, $raw ) );
					break;
				case 'tick':
					$result = self::act_tick( $ctx, $raw );
					break;
				case 'undo':
					$result = self::act_undo( $ctx, $raw );
					break;
				case 'alias':
					$result = self::act_alias( $ctx, $raw );
					break;
				default:
					// Temporary tanks and the day's list (Temp_Tanks::ACTS).
					$result = Temp_Tanks::perform( (string) $act, $ctx, $raw );
					if ( null === $result ) {
						$result = array( 'error' => __( 'Unknown action.', 'fishotel-misc-plugin' ) );
					}
			}
		} catch ( \Throwable $e ) {
			$result = array( 'error' => $e->getMessage() );
		}

		return is_array( $result ) ? $result : array( 'error' => __( 'Something went wrong. Try again.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * The occupant stays named by the payload: one stay_id, or every
	 * occupant when `all` is set. Each must be in this tank and open.
	 *
	 * @param array $ctx       Context.
	 * @param array $raw       Payload.
	 * @param bool  $allow_all Whether `all` is honoured.
	 * @return array[]|string Stays, or an error message.
	 */
	private static function targets( array $ctx, array $raw, $allow_all ) {
		$open = $ctx['store']->open_stays( array( 'tank_id' => (int) $ctx['tank']['id'] ), $ctx['today'], $ctx['settings'] );

		if ( $allow_all && ! empty( $raw['all'] ) && '0' !== (string) $raw['all'] ) {
			return $open ? $open : __( 'No fish in this tank.', 'fishotel-misc-plugin' );
		}

		$stay_id = self::int( $raw, 'stay_id' );
		foreach ( $open as $stay ) {
			if ( (int) $stay['id'] === $stay_id ) {
				return array( $stay );
			}
		}

		return __( 'Which fish? Pick one in this tank.', 'fishotel-misc-plugin' );
	}

	private static function write( array $ctx, $stay_id, array $event ) {
		return $ctx['store']->record_event( (int) $stay_id, array_merge( $ctx['base'], $event ), $ctx['today'], $ctx['settings'] );
	}

	/**
	 * Arrival — always into a Spa tank, or a QUARANTINE temporary tank
	 * (never a clean one, never a slot with nothing set up). The payload
	 * may name such a tank
	 * (`to_tank`) when the page's tank is not one, a `size` (the slug of a
	 * standard size; one that is not in the list is refused), and
	 * `unknown` — the "Unknown fish (??)" choice: the group is checked in
	 * as "??" + what was typed, flagged "needs identifying" and linked to
	 * no product (a name typed with a leading "??" is one too).
	 */
	private static function act_arrival( array $ctx, array $raw ) {
		$store      = $ctx['store'];
		$tank       = $ctx['tank'];
		$into       = self::int( $raw, 'to_tank' );
		if ( $into > 0 && $into !== (int) $tank['number'] ) {
			$tank = $store->tank_by_number( $into );
		}
		if ( ! Temp_Tanks::takes_arrivals( $tank ) ) {
			return array( 'error' => __( 'Arrivals go into a Spa tank. Pick one.', 'fishotel-misc-plugin' ) );
		}

		$species    = self::text( $raw, 'species', 120 );
		$product_id = max( 0, self::int( $raw, 'product_id' ) );
		$qty        = self::int( $raw, 'qty', 1 );
		$date       = self::text( $raw, 'date', 10 );
		$unknown    = ( ! empty( $raw['unknown'] ) && '0' !== (string) $raw['unknown'] ) || Identify::is_unknown_name( $species );
		if ( $unknown ) {
			$species    = Identify::unknown_name( $species );
			$product_id = 0; // Nobody knows what it is: it is no product yet.
		}

		if ( $product_id > 0 ) {
			$title = $store->product_title( $product_id );
			if ( '' === $title ) {
				$product_id = 0; // Never assume a product exists.
			} elseif ( '' === $species ) {
				$species = $title;
			}
		}
		if ( '' === $species ) {
			return array( 'error' => __( 'Which fish? Type or pick a species.', 'fishotel-misc-plugin' ) );
		}
		if ( $qty < 1 || $qty > self::MAX_QTY ) {
			return array( 'error' => __( 'How many? 1–999.', 'fishotel-misc-plugin' ) );
		}
		if ( ! Replay::is_date( $date ) ) {
			$date = $ctx['today'];
		}
		// The slug as posted (a term slug may hold percent-encoded characters, which
		// sanitize_text_field() would strip): it is only ever compared with the list's own slugs.
		$size = isset( $raw['size'] ) && is_scalar( $raw['size'] ) && ! is_bool( $raw['size'] ) ? trim( (string) $raw['size'] ) : '';
		$size = Sizes::pick( $size, '' !== $size ? Sizes::standard( $store, (array) $ctx['settings'] )['sizes'] : array() );
		if ( ! $size['ok'] ) {
			return array( 'error' => __( 'That size is not in the list. Pick one, or none.', 'fishotel-misc-plugin' ) );
		}

		$stay_id = $store->create_stay(
			array(
				'product_id'   => $product_id > 0 ? $product_id : null,
				'species'      => $species,
				'size_slug'    => $size['slug'],
				'size_label'   => $size['label'],
				'needs_id'     => $unknown,
				'checkin_date' => $date,
				'tank_id'      => (int) $tank['id'],
				'source'       => $ctx['base']['source'],
				'supplier'     => self::text( $raw, 'supplier', 80 ),
				'order_ref'    => self::text( $raw, 'order_ref', 80 ),
			)
		);
		if ( ! $stay_id ) {
			return array( 'error' => __( 'The stay could not be created.', 'fishotel-misc-plugin' ) );
		}

		$written = self::write(
			$ctx,
			$stay_id,
			array(
				'type'       => 'arrival',
				'qty'        => $qty,
				'to_tank_id' => (int) $tank['id'],
				'event_at'   => self::event_at_for_date( $date, $ctx['today'] ),
			)
		);
		if ( ! $written ) {
			return array( 'error' => __( 'The arrival could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		return array(
			/* translators: 1: quantity, 2: species (with its size), 3: tank number */
			'message'   => sprintf( __( '%1$d × %2$s in Tank %3$d', 'fishotel-misc-plugin' ), $qty, $species . ( null !== $size['label'] ? ' · ' . $size['label'] : '' ), (int) $tank['number'] ),
			'event_ids' => array( $written['event_id'] ),
			'tank'      => (int) $tank['number'],
		);
	}

	/**
	 * Identify / Correct the fish: one group of this tank gets its real
	 * fish (a product, a species-list fish, a typed name — or "Unknown
	 * fish (??)" with what it looks like) and its size. The stay and its
	 * supplier order line are corrected together and the remembered box
	 * share follows (Identify::stay()). No undo: who a fish is is not an
	 * event to reverse — correct it again.
	 */
	private static function act_identify( array $ctx, array $raw ) {
		$stays = self::targets( $ctx, $raw, false );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}
		$stay = $stays[0];
		$fish = Identify::fish_from( $ctx['store'], $raw, (array) $ctx['settings'], $stay );
		if ( ! empty( $fish['error'] ) ) {
			return array( 'error' => (string) $fish['error'] );
		}

		$done = Identify::stay(
			$ctx['store'],
			$stay,
			$fish,
			array(
				'today'    => $ctx['today'],
				'settings' => (array) $ctx['settings'],
				'actor_id' => (int) $ctx['base']['actor_id'],
				'source'   => (string) $ctx['base']['source'],
			)
		);
		if ( ! $done['ok'] ) {
			return array( 'error' => (string) $done['error'] );
		}

		if ( '' !== $done['message'] ) {
			$message = $done['message'];
		} elseif ( $done['changed'] ) {
			/* translators: %s: species */
			$message = sprintf( __( '%s saved', 'fishotel-misc-plugin' ), (string) $fish['name'] );
		} else {
			/* translators: %s: species */
			$message = sprintf( __( '%s: nothing changed', 'fishotel-misc-plugin' ), (string) $stay['species'] );
		}

		return array(
			'message'   => $message,
			'event_ids' => array(),
		);
	}

	/**
	 * Loss (the fastest path) and sold / shipped: one stay, a quantity
	 * (default 1), an optional reason for a loss.
	 */
	private static function act_loss_or_sold( array $ctx, array $raw, $type ) {
		$stays = self::targets( $ctx, $raw, false );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}
		$stay   = $stays[0];
		$qty    = self::int( $raw, 'qty', 1 );
		$reason = isset( $raw['reason'] ) ? sanitize_key( $raw['reason'] ) : '';
		$reason = 'loss' === $type && in_array( $reason, self::REASONS, true ) ? $reason : '';

		if ( $qty < 1 || $qty > (int) $stay['qty_live'] ) {
			/* translators: %d: fish alive */
			return array( 'error' => sprintf( __( 'How many? 1–%d.', 'fishotel-misc-plugin' ), (int) $stay['qty_live'] ) );
		}

		$written = self::write(
			$ctx,
			$stay['id'],
			array(
				'type'     => $type,
				'qty'      => $qty,
				'note'     => $reason,
				'event_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( ! $written ) {
			return array( 'error' => __( 'That could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		if ( 'loss' === $type ) {
			/* translators: 1: quantity, 2: species */
			$message = sprintf( __( '%1$d × %2$s lost', 'fishotel-misc-plugin' ), $qty, $stay['species'] ) . ( '' !== $reason ? ' (' . ( 'doa' === $reason ? 'DOA' : $reason ) . ')' : '' );
		} else {
			/* translators: 1: quantity, 2: species */
			$message = sprintf( __( '%1$d × %2$s shipped', 'fishotel-misc-plugin' ), $qty, $stay['species'] );
		}
		if ( (int) $written['state']['qty_live'] <= 0 ) {
			$message .= ' · ' . __( 'group closed', 'fishotel-misc-plugin' );
		}
		if ( ! empty( $written['overdrawn'] ) ) {
			// Two phones recorded against the same count: the entry stands,
			// but the tank now shows fewer fish than were lost / shipped.
			/* translators: %d: tank number */
			$message .= ' · ' . sprintf( __( 'Tank %d now shows fewer fish than were taken out — recount it', 'fishotel-misc-plugin' ), (int) $ctx['tank']['number'] );
		}

		return array(
			'message'   => $message,
			'event_ids' => array( $written['event_id'] ),
			'overdrawn' => ! empty( $written['overdrawn'] ),
		);
	}

	/**
	 * Move: one stay or all, to a tank, with an optional reason (clear
	 * building / bullying / pairing / other, kept in the event's note); a
	 * quantity smaller than the group splits it. A move from the Spa into
	 * a Clean Room tank clears the stay (Replay) — and so does a move from
	 * the Spa or a quarantine temporary tank into a CLEAN temporary tank,
	 * or out of a quarantine temporary tank into the Clean Room. A slot
	 * with nothing set up takes no fish.
	 */
	private static function act_move( array $ctx, array $raw ) {
		$store = $ctx['store'];
		$tank  = $ctx['tank'];
		$to    = $store->tank_by_number( self::int( $raw, 'to_tank' ) );
		if ( ! $to || 'offline' === $to['status'] || 'offline' === (string) $to['building']['status'] ) {
			return array( 'error' => __( 'Where to? Pick a tank.', 'fishotel-misc-plugin' ) );
		}
		if ( (int) $to['id'] === (int) $tank['id'] ) {
			return array( 'error' => __( 'That is this tank.', 'fishotel-misc-plugin' ) );
		}
		$refused = Temp_Tanks::destination_error( $to );
		if ( '' !== $refused ) {
			return array( 'error' => $refused );
		}

		$stays = self::targets( $ctx, $raw, true );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}

		$qty    = isset( $raw['qty'] ) && '' !== (string) $raw['qty'] ? self::int( $raw, 'qty' ) : 0;
		$reason = isset( $raw['reason'] ) ? sanitize_key( $raw['reason'] ) : '';
		$reason = in_array( $reason, self::MOVE_REASONS, true ) ? $reason : '';
		$why    = '' !== $reason ? ' · ' . str_replace( '_', ' ', $reason ) : '';
		$ids    = array();

		if ( 1 === count( $stays ) && $qty > 0 && $qty < (int) $stays[0]['qty_live'] ) {
			$split = $store->split_stay( $stays[0], $qty, $to, $ctx['base'], $ctx['today'], $ctx['settings'], $reason );
			if ( ! $split ) {
				return array( 'error' => __( 'The split could not be recorded.', 'fishotel-misc-plugin' ) );
			}
			return array(
				/* translators: 1: quantity, 2: species, 3: destination tank number */
				'message'   => sprintf( __( '%1$d × %2$s moved to Tank %3$d (the rest stay here)', 'fishotel-misc-plugin' ), $qty, $stays[0]['species'], (int) $to['number'] ) . $why,
				'event_ids' => $split['event_ids'],
			);
		}
		if ( 1 === count( $stays ) && $qty > (int) $stays[0]['qty_live'] ) {
			/* translators: %d: fish alive */
			return array( 'error' => sprintf( __( 'Only %d here.', 'fishotel-misc-plugin' ), (int) $stays[0]['qty_live'] ) );
		}

		$cleared = false;
		foreach ( $stays as $stay ) {
			$written = self::write(
				$ctx,
				$stay['id'],
				array(
					'type'         => 'move',
					'from_tank_id' => (int) $tank['id'],
					'to_tank_id'   => (int) $to['id'],
					'note'         => $reason,
					'event_at'     => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( $written ) {
				$ids[] = $written['event_id'];
				// "cleared today" only when this move really set the date
				// (Spa → Clean Room on a stay that was not cleared yet).
				if ( empty( $stay['cleared_date'] ) && (string) $ctx['today'] === (string) ( $written['state']['cleared_date'] ?? '' ) ) {
					$cleared = true;
				}
			}
		}
		if ( ! $ids ) {
			return array( 'error' => __( 'The move could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		/* translators: %d: number of groups */
		$what = 1 === count( $stays ) ? $stays[0]['species'] : sprintf( __( '%d groups', 'fishotel-misc-plugin' ), count( $stays ) );

		return array(
			/* translators: 1: species or count, 2: destination tank number */
			'message'   => sprintf( __( '%1$s moved to Tank %2$d', 'fishotel-misc-plugin' ), $what, (int) $to['number'] ) . ( $cleared ? ' · ' . __( 'cleared today', 'fishotel-misc-plugin' ) : '' ) . $why,
			'event_ids' => $ids,
		);
	}

	/**
	 * The cleared date by hand (editable), or unset. The event is recorded
	 * now with the date in its note (Replay::NOTE_CLEARED), so a correction
	 * to an EARLIER date, or a date set after an unset, is honoured.
	 */
	private static function act_clear( array $ctx, array $raw ) {
		$stays = self::targets( $ctx, $raw, true );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}
		$unset = ! empty( $raw['unset'] ) && '0' !== (string) $raw['unset'];
		$date  = self::text( $raw, 'date', 10 );
		if ( ! $unset && ! Replay::is_date( $date ) ) {
			$date = $ctx['today'];
		}

		$ids = array();
		foreach ( $stays as $stay ) {
			$written = self::write(
				$ctx,
				$stay['id'],
				array(
					'type'     => 'clear',
					'qty'      => $unset ? -1 : null,
					'note'     => $unset ? '' : Replay::NOTE_CLEARED . $date,
					'event_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( $written ) {
				$ids[] = $written['event_id'];
			}
		}
		if ( ! $ids ) {
			return array( 'error' => __( 'The clearing could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		$what = 1 === count( $stays ) ? $stays[0]['species'] : __( 'Everything here', 'fishotel-misc-plugin' );
		if ( $unset ) {
			/* translators: %s: species */
			return array( 'message' => sprintf( __( '%s: cleared date removed', 'fishotel-misc-plugin' ), $what ), 'event_ids' => $ids );
		}

		return array(
			/* translators: 1: species, 2: date */
			'message'   => sprintf( __( '%1$s cleared %2$s', 'fishotel-misc-plugin' ), $what, $date === $ctx['today'] ? __( 'today', 'fishotel-misc-plugin' ) : $date ),
			'event_ids' => $ids,
		);
	}

	/**
	 * The check-in date by hand: a "checkin:" note (Replay::NOTE_CHECKIN);
	 * the day number and the suggested sale date follow from it.
	 */
	private static function act_checkin( array $ctx, array $raw ) {
		$stays = self::targets( $ctx, $raw, false );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}
		$stay = $stays[0];
		$date = self::text( $raw, 'date', 10 );
		if ( ! Replay::is_date( $date ) ) {
			return array( 'error' => __( 'That is not a date.', 'fishotel-misc-plugin' ) );
		}
		if ( strcmp( $date, (string) $ctx['today'] ) > 0 ) {
			return array( 'error' => __( 'A check-in cannot be in the future.', 'fishotel-misc-plugin' ) );
		}

		$written = self::write( $ctx, $stay['id'], array( 'type' => 'note', 'note' => Replay::NOTE_CHECKIN . $date, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! $written ) {
			return array( 'error' => __( 'That could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		return array(
			/* translators: 1: species, 2: date */
			'message'   => sprintf( __( '%1$s checked in %2$s', 'fishotel-misc-plugin' ), $stay['species'], $date ),
			'event_ids' => array( $written['event_id'] ),
		);
	}

	/**
	 * The stay's own sale date ('' = none: back to the batch's date, or no
	 * date at all when the batch has none). A date outside
	 * Replay::SALE_DATE_MIN – two years after today is refused; the date
	 * the group already has writes nothing.
	 */
	private static function act_ready( array $ctx, array $raw ) {
		$stays = self::targets( $ctx, $raw, false );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}
		$stay  = $stays[0];
		$date  = self::text( $raw, 'date', 10 );
		$check = Replay::own_sale_date_check( $date, $stay['sale_ready_override'] ?? null, (string) $ctx['today'] );
		if ( 'invalid' === $check ) {
			return array( 'error' => __( 'That is not a date.', 'fishotel-misc-plugin' ) );
		}
		if ( 'range' === $check ) {
			/* translators: 1: earliest date, 2: latest date */
			return array( 'error' => sprintf( __( 'Pick a sale date from %1$s to %2$s.', 'fishotel-misc-plugin' ), Replay::SALE_DATE_MIN, Replay::sale_date_max( (string) $ctx['today'] ) ) );
		}
		if ( 'same' === $check ) {
			return array(
				/* translators: %s: species */
				'message'   => sprintf( __( '%s: nothing changed', 'fishotel-misc-plugin' ), (string) $stay['species'] ),
				'event_ids' => array(),
			);
		}

		$written = self::write( $ctx, $stay['id'], array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . $date, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! $written ) {
			return array( 'error' => __( 'That could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'message'   => self::ready_message( (string) $stay['species'], $date, (array) ( $written['state'] ?? array() ) ),
			'event_ids' => array( $written['event_id'] ),
		);
	}

	/**
	 * The toast after a sale date was set or removed (pure): "Pajama
	 * Cardinalfish on sale 2026-10-30", "… back to the batch date
	 * (2026-11-02)", "… no sale date now — set one on its supplier order",
	 * with ", still in the Spa" when the date has come before the group
	 * was cleared.
	 *
	 * @param string $species The group.
	 * @param string $date    The date typed ('' = removed).
	 * @param array  $state   The stay's state after the write (Replay::state()).
	 * @return string
	 */
	public static function ready_message( $species, $date, array $state ) {
		$batch = isset( $state['batch_sale_date'] ) && Replay::is_date( $state['batch_sale_date'] ) ? (string) $state['batch_sale_date'] : '';
		if ( '' !== $date ) {
			/* translators: 1: species, 2: date */
			$text = sprintf( __( '%1$s on sale %2$s', 'fishotel-misc-plugin' ), $species, $date );
		} elseif ( '' !== $batch ) {
			/* translators: 1: species, 2: the batch's sale date */
			$text = sprintf( __( '%1$s back to the batch date (%2$s)', 'fishotel-misc-plugin' ), $species, $batch );
		} else {
			/* translators: %s: species */
			$text = sprintf( __( '%s: no sale date now — set one on its supplier order', 'fishotel-misc-plugin' ), $species );
		}
		if ( ! empty( $state['ready_in_spa'] ) ) {
			$text .= ' · ' . __( 'still in the Spa', 'fishotel-misc-plugin' );
		}
		return $text;
	}

	/**
	 * A full recount.
	 */
	private static function act_count( array $ctx, array $raw ) {
		$stays = self::targets( $ctx, $raw, false );
		if ( ! is_array( $stays ) ) {
			return array( 'error' => $stays );
		}
		$stay  = $stays[0];
		$count = isset( $raw['count'] ) && is_scalar( $raw['count'] ) && '' !== trim( (string) $raw['count'] ) ? (int) $raw['count'] : -1;
		if ( $count < 0 || $count > self::MAX_QTY ) {
			return array( 'error' => __( 'How many? 0–999.', 'fishotel-misc-plugin' ) );
		}

		$written = self::write( $ctx, $stay['id'], array( 'type' => 'count', 'qty' => $count, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! $written ) {
			return array( 'error' => __( 'That could not be recorded.', 'fishotel-misc-plugin' ) );
		}

		return array(
			/* translators: 1: count, 2: species */
			'message'   => sprintf( __( 'Count: %1$d × %2$s', 'fishotel-misc-plugin' ), $count, $stay['species'] ) . ( 0 === $count ? ' · ' . __( 'group closed', 'fishotel-misc-plugin' ) : '' ),
			'event_ids' => array( $written['event_id'] ),
		);
	}

	/**
	 * A note: on one stay when stay_id is given, else on the tank's log.
	 */
	private static function act_note( array $ctx, array $raw ) {
		$note = isset( $raw['note'] ) && is_scalar( $raw['note'] ) ? substr( sanitize_textarea_field( (string) $raw['note'] ), 0, 2000 ) : '';
		if ( '' === $note ) {
			return array( 'error' => __( 'Nothing to save.', 'fishotel-misc-plugin' ) );
		}

		if ( self::int( $raw, 'stay_id' ) > 0 ) {
			$stays = self::targets( $ctx, $raw, false );
			if ( ! is_array( $stays ) ) {
				return array( 'error' => $stays );
			}
			$written = self::write( $ctx, $stays[0]['id'], array( 'type' => 'note', 'note' => $note, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			if ( ! $written ) {
				return array( 'error' => __( 'The note could not be saved.', 'fishotel-misc-plugin' ) );
			}
			return array(
				/* translators: %s: species */
				'message'   => sprintf( __( 'Note on %s saved', 'fishotel-misc-plugin' ), $stays[0]['species'] ),
				'event_ids' => array(),
			);
		}

		$id = $ctx['store']->log_tank( (int) $ctx['tank']['id'], array_merge( $ctx['base'], array( 'kind' => 'note', 'note' => $note ) ) );
		if ( ! $id ) {
			return array( 'error' => __( 'The note could not be saved.', 'fishotel-misc-plugin' ) );
		}

		return array(
			/* translators: %d: tank number */
			'message'   => sprintf( __( 'Note on Tank %d saved', 'fishotel-misc-plugin' ), (int) $ctx['tank']['number'] ),
			'event_ids' => array(),
		);
	}

	/**
	 * A copper reading on the tank.
	 */
	private static function act_copper( array $ctx, array $raw ) {
		$value = isset( $raw['value'] ) && is_scalar( $raw['value'] ) && is_numeric( $raw['value'] ) ? round( (float) $raw['value'], 3 ) : null;
		if ( null === $value || $value < 0 || $value > 99 ) {
			return array( 'error' => __( 'How much? A reading like 2.4.', 'fishotel-misc-plugin' ) );
		}

		$id = $ctx['store']->log_tank( (int) $ctx['tank']['id'], array_merge( $ctx['base'], array( 'kind' => 'copper', 'value' => $value ) ) );
		if ( ! $id ) {
			return array( 'error' => __( 'The reading could not be saved.', 'fishotel-misc-plugin' ) );
		}

		$state = Protocol::copper_state( $value, (float) $ctx['settings']['copper_floor'] );
		$text  = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );

		return array(
			/* translators: 1: reading, 2: tank number */
			'message'   => sprintf( __( 'Copper %1$s in Tank %2$d', 'fishotel-misc-plugin' ), $text, (int) $ctx['tank']['number'] ) . ( 'low' === $state ? ' · ' . __( 'below the floor', 'fishotel-misc-plugin' ) : '' ),
			'event_ids' => array(),
		);
	}

	/**
	 * A dose: a medication (Protocol::MEDICATIONS), an amount with its unit,
	 * on the whole system (the tank's building — the Spa is one water) or
	 * on this tank alone. A dose log of the day ticks the dose step that
	 * names the medication. No undo: a dose cannot be taken back out of the
	 * water, so the log stands (a correction is a note).
	 */
	private static function act_dose( array $ctx, array $raw ) {
		$tank       = $ctx['tank'];
		$medication = isset( $raw['medication'] ) ? sanitize_key( $raw['medication'] ) : '';
		if ( ! isset( Protocol::MEDICATIONS[ $medication ] ) ) {
			return array( 'error' => __( 'Which medication? Pick one.', 'fishotel-misc-plugin' ) );
		}
		$amount = isset( $raw['amount'] ) && is_scalar( $raw['amount'] ) && is_numeric( $raw['amount'] ) ? round( (float) $raw['amount'], 2 ) : null;
		if ( null === $amount || $amount <= 0 || $amount > self::MAX_DOSE ) {
			return array( 'error' => __( 'How much? An amount above 0 and at most 9999.', 'fishotel-misc-plugin' ) );
		}
		$unit = isset( $raw['unit'] ) ? sanitize_key( $raw['unit'] ) : '';
		if ( ! in_array( $unit, Protocol::UNITS, true ) ) {
			return array( 'error' => __( 'Which unit? g, ml or tsp.', 'fishotel-misc-plugin' ) );
		}
		$whole       = ! empty( $raw['whole'] ) && '0' !== (string) $raw['whole'];
		$building_id = (int) ( $tank['building']['id'] ?? $tank['building_id'] ?? 0 );
		if ( $whole && $building_id <= 0 ) {
			return array( 'error' => __( 'This tank has no building to dose as a system.', 'fishotel-misc-plugin' ) );
		}

		$id = $ctx['store']->log_dose( $building_id, $whole ? 0 : (int) $tank['id'], $medication, $amount, $unit, '', (int) $ctx['base']['actor_id'], (string) $ctx['base']['source'] );
		if ( ! $id ) {
			return array( 'error' => __( 'The dose could not be saved.', 'fishotel-misc-plugin' ) );
		}

		$place = $whole
			/* translators: %s: building label */
			? sprintf( __( '%s, whole system', 'fishotel-misc-plugin' ), Layout::building_label( $tank['building'] ) )
			/* translators: %d: tank number */
			: sprintf( __( 'Tank %d', 'fishotel-misc-plugin' ), (int) $tank['number'] );

		return array(
			'message'   => Protocol::dose_text( $medication, $amount, $unit ) . ' · ' . $place,
			'event_ids' => array(),
		);
	}

	/**
	 * A to-do item ticked off: a tank log of the step's kind whose note is
	 * the item key (Protocol::todo() reads it back the same day).
	 */
	private static function act_tick( array $ctx, array $raw ) {
		$key  = Protocol::tick_key( self::text( $raw, 'key', 160 ) );
		$kind = isset( $raw['kind'] ) ? sanitize_key( $raw['kind'] ) : 'other';
		$kind = in_array( $kind, Store::LOG_KINDS, true ) ? $kind : 'other';
		if ( '' === $key ) {
			return array( 'error' => __( 'Nothing to tick.', 'fishotel-misc-plugin' ) );
		}

		$id = $ctx['store']->log_tank( (int) $ctx['tank']['id'], array_merge( $ctx['base'], array( 'kind' => $kind, 'note' => $key ) ) );
		if ( ! $id ) {
			return array( 'error' => __( 'That could not be saved.', 'fishotel-misc-plugin' ) );
		}

		return array(
			/* translators: %s: to-do item */
			'message'   => sprintf( __( 'Done: %s', 'fishotel-misc-plugin' ), self::text( $raw, 'label', 80 ) ?: $key ),
			'event_ids' => array(),
		);
	}

	/**
	 * Undo = one compensating event per event id, only for events this
	 * user wrote within the undo window, and never twice for the same
	 * event (Store::undo_event() skips an id that was already undone).
	 */
	private static function act_undo( array $ctx, array $raw ) {
		$store = $ctx['store'];
		$ids   = isset( $raw['event_ids'] ) ? (array) $raw['event_ids'] : array();
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || count( $ids ) > 20 ) {
			return array( 'error' => __( 'Nothing to undo.', 'fishotel-misc-plugin' ) );
		}

		$written_ids = array();
		$already     = 0;
		$tank_number = 0;
		foreach ( $ids as $id ) {
			$event = $store->event( $id );
			if ( ! $event ) {
				continue;
			}
			if ( (int) $event['actor_id'] !== (int) $ctx['base']['actor_id'] ) {
				continue;
			}
			$age = time() - (int) strtotime( $event['created_at'] . ' UTC' );
			if ( $age > self::UNDO_WINDOW ) {
				continue;
			}
			$stay = $store->stay( (int) $event['stay_id'], $ctx['today'], $ctx['settings'] );
			if ( ! $stay ) {
				continue;
			}
			if ( Replay::is_undone( (array) ( $stay['events'] ?? array() ), $id ) ) {
				$already++;
				continue;
			}
			$written = $store->undo_event( $stay, $event, $ctx['base'], $ctx['today'], $ctx['settings'] );
			if ( $written ) {
				$written_ids[] = $written['event_id'];
				if ( 'move' === $event['type'] && ! empty( $event['from_tank_number'] ) && Replay::is_whole_move( $event ) ) {
					$tank_number = (int) $event['from_tank_number'];
				}
			}
		}

		if ( ! $written_ids && $already > 0 ) {
			return array(
				'message'   => __( 'Already undone', 'fishotel-misc-plugin' ),
				'event_ids' => array(),
			);
		}
		if ( ! $written_ids ) {
			return array( 'error' => __( 'That can no longer be undone.', 'fishotel-misc-plugin' ) );
		}

		$out = array(
			'message'   => __( 'Undone', 'fishotel-misc-plugin' ),
			'event_ids' => array(),
		);
		if ( $tank_number > 0 ) {
			$out['tank'] = $tank_number;
		}
		return $out;
	}

	private static function act_alias( array $ctx, array $raw ) {
		$store      = $ctx['store'];
		$alias      = self::text( $raw, 'alias', 120 );
		$product_id = max( 0, self::int( $raw, 'product_id' ) );
		$species    = self::text( $raw, 'species', 120 );

		if ( $product_id > 0 && '' === $store->product_title( $product_id ) ) {
			$product_id = 0;
		}
		if ( '' === $alias || ( '' === $species && 0 === $product_id ) ) {
			return array( 'error' => __( 'Nothing to learn.', 'fishotel-misc-plugin' ) );
		}
		if ( '' === $species ) {
			$species = $store->product_title( $product_id );
		}
		if ( ! $store->add_alias( $alias, $product_id, $species ) ) {
			return array( 'error' => __( 'The alias could not be saved.', 'fishotel-misc-plugin' ) );
		}

		return array(
			/* translators: 1: spoken words, 2: species */
			'message'   => sprintf( __( 'Learned: "%1$s" = %2$s', 'fishotel-misc-plugin' ), $alias, $species ),
			'event_ids' => array(),
		);
	}

	/* ============================================================== */
	/*  AJAX: parse, suggest, screen                                   */
	/* ============================================================== */

	/**
	 * The tank list for the parser.
	 *
	 * @param Store $store Store.
	 * @return array[]
	 */
	private static function parser_tanks( Store $store ) {
		$tanks = array();
		foreach ( $store->tanks( false ) as $tank ) {
			if ( 'offline' === (string) $tank['building']['status'] || Temp_Tanks::is_free( $tank ) ) {
				continue; // A slot with nothing set up is no tank to speak of.
			}
			$tanks[] = array(
				'id'       => (int) $tank['id'],
				'number'   => (int) $tank['number'],
				'building' => (int) $tank['building']['number'],
				// What it behaves as: a quarantine temporary tank takes arrivals like the Spa.
				'area'     => Temp_Tanks::effective_area( $tank ),
				'level'    => (int) $tank['level'],
				'position' => (int) $tank['position'],
				'status'   => (string) $tank['status'],
			);
		}
		return $tanks;
	}

	/**
	 * The occupants of a tank for the parser.
	 *
	 * @param Store      $store    Store.
	 * @param array|null $tank     Tank row.
	 * @param string     $today    Site date.
	 * @param array      $settings Settings.
	 * @return array[]
	 */
	private static function parser_occupants( Store $store, $tank, $today, array $settings ) {
		$occupants = array();
		if ( ! $tank ) {
			return $occupants;
		}
		foreach ( $store->open_stays( array( 'tank_id' => (int) $tank['id'] ), $today, $settings ) as $stay ) {
			$occupants[] = array(
				'stay_id'    => (int) $stay['id'],
				'species'    => (string) $stay['species'],
				'product_id' => $stay['product_id'],
				'qty_live'   => (int) $stay['qty_live'],
			);
		}
		return $occupants;
	}

	/**
	 * A transcript → structured preview. When the phrase names another
	 * tank ("2 dead in 513" from 521's page) the parse is run again with
	 * THAT tank's occupants.
	 */
	public function ajax_parse() {
		self::guard();

		$raw   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$text  = self::text( $raw, 'text', 300 );
		$tankn = self::int( $raw, 'tank' );

		$store    = new Store();
		$settings = Settings::get();
		$today    = Clean_Room::today();
		$tanks    = self::parser_tanks( $store );
		$species  = $store->species_candidates();
		$tank     = $tankn > 0 ? $store->tank_by_number( $tankn ) : null;

		$context = array(
			'tank'      => $tank ? (int) $tank['number'] : null,
			'tanks'     => $tanks,
			'species'   => $species,
			'occupants' => self::parser_occupants( $store, $tank, $today, $settings ),
		);
		$preview = Parser::parse( $text, $context );

		if ( ! empty( $preview['tank_spoken'] ) && $preview['tank_known'] && (int) $preview['tank'] !== (int) $context['tank'] ) {
			$other = $store->tank_by_number( (int) $preview['tank'] );
			if ( $other ) {
				$context['occupants'] = self::parser_occupants( $store, $other, $today, $settings );
				$preview              = Parser::parse( $text, $context );
			}
		}

		wp_send_json_success( array( 'preview' => $preview ) );
	}

	/**
	 * Species suggestions for the search box.
	 */
	public function ajax_suggest() {
		self::guard();

		$raw  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$term = self::text( $raw, 'term', 80 );

		$store = new Store();

		wp_send_json_success( array( 'items' => $store->species_suggestions( $term, 8 ) ) );
	}

	/**
	 * The refreshed screen for a tank.
	 */
	public function ajax_screen() {
		self::guard();

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		wp_send_json_success( array( 'screen' => self::screen_data( self::int( $raw, 'tank' ) ) ) );
	}
}
