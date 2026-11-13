<?php
/**
 * Gift Cards admin — FisHotel Tools → Gift Cards:
 *
 *   Cards     the list: by default the cards in use (money on them, not
 *             disabled); one click for used up / disabled / all (and "old
 *             expiry date" when imported cards carry one); search by code
 *             (whole, or its last characters), recipient, buyer, order
 *             number; sort by balance, issued, last used, amount. A card's
 *             own page: its details and history, and — Live — add or take
 *             off money (with a reason), change who it is for, switch it
 *             off / on, send its e-mail again; the owner can show its code.
 *   Issue     a new card without an order (Live): amount, recipient,
 *             sender, message, and send the e-mail now / on a date / not.
 *   Stats     what is still owed (the number for the books), sold and
 *             redeemed this month and year, never used, the average card,
 *             not used in over a year, and the health check.
 *   Audit log every change, newest first, with filters, the chain check and
 *             the CSV export. There is no edit or delete — anywhere.
 *   Import    the old plugin's tables: schema check, dry run, import, the
 *             report (owner).
 *   Settings  the mode (Off / Shadow / Live, with the guard) and the
 *             options (owner).
 *
 * The order screen gets a "Gift cards" box (Live): what the order took
 * from cards and put back, with a link to put part of it back.
 *
 * Every write is ONE admin-post action: its nonce is checked first, then
 * the capability of the operation, then the mode; perform() does the
 * work with its service as a parameter, so the tests drive every
 * operation.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	const ACTION   = 'fishotel_gift_cards_do';
	const NONCE    = 'fishotel_gift_cards_do';
	const FLASH    = 'fishotel_gift_cards_flash_';
	const PER_PAGE = 50;

	const TABS = array( 'cards', 'issue', 'stats', 'audit', 'import', 'settings', 'order' );

	/**
	 * Each operation: the capability it needs ('view', 'edit', 'owner') and
	 * the modes it may run in.
	 *
	 * @var array<string, array{0: string, 1: string[]}>
	 */
	const OPS = array(
		'issue'        => array( 'edit', array( 'live' ) ),
		'adjust'       => array( 'edit', array( 'live' ) ),
		'details'      => array( 'edit', array( 'live' ) ),
		'status'       => array( 'edit', array( 'live' ) ),
		'resend'       => array( 'edit', array( 'live' ) ),
		'restore_part' => array( 'edit', array( 'live' ) ),
		'reveal'       => array( 'owner', array( 'shadow', 'live' ) ),
		'dry_run'      => array( 'owner', array( 'shadow', 'live' ) ),
		'import'       => array( 'owner', array( 'shadow', 'live' ) ),
		'export'       => array( 'owner', array( 'off', 'shadow', 'live' ) ),
		'mode'         => array( 'owner', array( 'off', 'shadow', 'live' ) ),
		'settings'     => array( 'owner', array( 'off', 'shadow', 'live' ) ),
	);

	/**
	 * The mode of this request (Modes::current()).
	 *
	 * @var array
	 */
	private $mode = array(
		'stored'    => 'off',
		'effective' => 'off',
		'legacy'    => false,
		'fallback'  => false,
	);

	/**
	 * Register hooks.
	 *
	 * @param array $mode Modes::current().
	 */
	public function init( array $mode = array() ) {
		$this->mode = array_merge( $this->mode, $mode );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		if ( 'live' === $this->mode['effective'] ) {
			add_action( 'add_meta_boxes', array( $this, 'order_box_register' ), 30 );
		}
	}

	/* ============================================================== */
	/*  URLs, flash, context                                           */
	/* ============================================================== */

	/**
	 * URL of a tab.
	 *
	 * @param string $tab   A TABS entry.
	 * @param array  $extra Query args.
	 * @return string
	 */
	public static function page_url( $tab = 'cards', array $extra = array() ) {
		$args = array( 'page' => Gift_Cards::MENU_SLUG );
		if ( 'cards' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of a card's page.
	 *
	 * @param int $id Card id.
	 * @return string
	 */
	public static function card_url( $id ) {
		return self::page_url( 'cards', array( 'card' => (int) $id ) );
	}

	/**
	 * The edit screen of an order (classic or HPOS).
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function order_url( $order_id ) {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && method_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'get_order_admin_edit_url' ) ) {
			return (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $order_id );
		}
		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
	}

	/**
	 * Keep a notice (and a refused form's values, or a code to show once)
	 * for the next page this user opens.
	 *
	 * @param array $result ok, message, keep, reveal.
	 */
	private static function flash( array $result ) {
		set_transient(
			self::FLASH . get_current_user_id(),
			array(
				'ok'      => ! empty( $result['ok'] ),
				'message' => (string) ( $result['message'] ?? '' ),
				'keep'    => isset( $result['keep'] ) && is_array( $result['keep'] ) ? $result['keep'] : array(),
				'reveal'  => isset( $result['reveal'] ) && is_array( $result['reveal'] ) ? $result['reveal'] : null,
			),
			120
		);
	}

	/**
	 * Take the notice.
	 *
	 * @return array
	 */
	public static function take_flash() {
		$key   = self::FLASH . get_current_user_id();
		$flash = get_transient( $key );
		if ( false !== $flash ) {
			delete_transient( $key );
		}
		return array_merge(
			array(
				'ok'      => true,
				'message' => '',
				'keep'    => array(),
				'reveal'  => null,
			),
			is_array( $flash ) ? $flash : array()
		);
	}

	/**
	 * What perform() and the views need.
	 *
	 * @param string $screen The screen, for the audit log.
	 * @return array
	 */
	public static function context( $screen = '' ) {
		$service = Gift_Cards::service();
		$mode    = Modes::current();
		return array(
			'service'  => $service,
			'mode'     => $mode['effective'],
			'modes'    => $mode,
			'can_edit' => current_user_can( (string) $service->settings['editor_capability'] ) && current_user_can( Gift_Cards::VIEW_CAPABILITY ),
			'can_own'  => current_user_can( Gift_Cards::OWNER_CAPABILITY ),
			'audit'    => Audit::context( 'admin', 'gift-cards/' . $screen ),
			'importer' => self::importer( $service ),
			'ready'    => Store::ready(),
		);
	}

	/**
	 * The importer wired to the site.
	 *
	 * @param Service $service Service.
	 * @return Importer
	 */
	public static function importer( Service $service ) {
		return new Importer(
			$service->store,
			$service,
			array(
				'order_exists' => static function ( $id ) {
					return function_exists( 'wc_get_order' ) && is_object( wc_get_order( (int) $id ) );
				},
			)
		);
	}

	/* ============================================================== */
	/*  Assets, notices                                                */
	/* ============================================================== */

	/**
	 * The page's stylesheet and script.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Gift_Cards::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'fishotel-gift-cards-admin', Gift_Cards::url() . 'css/gift-cards-admin.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-gift-cards-admin', Gift_Cards::url() . 'js/gift-cards-admin.js', array(), FISHOTEL_MISC_VERSION, true );
	}

	/**
	 * Live fell back to Shadow because the old plugin is on again.
	 */
	public function notices() {
		if ( empty( $this->mode['fallback'] ) || ! current_user_can( Gift_Cards::VIEW_CAPABILITY ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Gift Cards:', 'fishotel-misc-plugin' ) . '</strong> ' . esc_html__( 'the old gift card plugin (WooCommerce Gift Cards) is switched on again, so FisHotel\'s gift cards have stopped taking payments and behave as in Shadow mode until it is switched off — two systems must never take money for the same cards.', 'fishotel-misc-plugin' ) . '</p></div>';
	}

	/* ============================================================== */
	/*  The write action                                               */
	/* ============================================================== */

	/**
	 * admin-post: nonce first, then capability and mode (in perform()).
	 */
	public function handle() {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Gift_Cards::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is cleaned by the Service / Settings.
		$post = is_array( $post ) ? $post : array();
		$op   = isset( $post['op'] ) && is_scalar( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
		$ctx  = self::context( $op );

		if ( 'export' === $op ) {
			$this->export( $post, $ctx );
			return;
		}

		try {
			$result = self::perform( $op, $post, $ctx );
		} catch ( \Throwable $e ) {
			$result = Service::fail( 'error', __( 'Something went wrong and nothing was changed.', 'fishotel-misc-plugin' ) );
			do_action( 'fishotel_log', 'error', 'gift-cards', sprintf( 'Gift Cards admin action "%1$s" failed: %2$s', $op, $e->getMessage() ), array() );
		}

		self::flash( $result );
		$back = isset( $result['redirect'] ) ? (string) $result['redirect'] : ( isset( $post['back'] ) && is_scalar( $post['back'] ) && 0 === strpos( (string) $post['back'], admin_url() ) ? (string) $post['back'] : self::page_url() );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Whether the context may run an operation (pure): the message when not.
	 *
	 * @param string $op  An OPS key.
	 * @param array  $ctx context().
	 * @return string '' = allowed.
	 */
	public static function refusal( $op, array $ctx ) {
		if ( ! isset( self::OPS[ $op ] ) ) {
			return __( 'That action is not known.', 'fishotel-misc-plugin' );
		}
		list( $need, $modes ) = self::OPS[ $op ];
		if ( ( 'edit' === $need && empty( $ctx['can_edit'] ) ) || ( 'owner' === $need && empty( $ctx['can_own'] ) ) ) {
			return 'owner' === $need ? __( 'Only the site owner (an administrator) may do that.', 'fishotel-misc-plugin' ) : __( 'You do not have permission to change gift cards.', 'fishotel-misc-plugin' );
		}
		if ( ! in_array( $ctx['mode'], $modes, true ) ) {
			if ( 'shadow' === $ctx['mode'] ) {
				return __( 'Shadow mode: the old gift card plugin is still in charge, so cards are issued and changed there. Switch to Live (Settings) to do it here.', 'fishotel-misc-plugin' );
			}
			return __( 'Gift Cards is in mode Off. Choose a mode in Settings first.', 'fishotel-misc-plugin' );
		}
		if ( empty( $ctx['ready'] ) && ! in_array( $op, array( 'mode', 'settings' ), true ) ) {
			return __( 'The gift card tables are not installed yet. Reload this page as an administrator.', 'fishotel-misc-plugin' );
		}
		return '';
	}

	/**
	 * Do one operation (handle() checked the nonce; this checks the
	 * capability and the mode).
	 *
	 * @param string $op   An OPS key.
	 * @param array  $post The posted form (unslashed).
	 * @param array  $ctx  context().
	 * @return array ok, message, redirect?, keep?, reveal?
	 */
	public static function perform( $op, array $post, array $ctx ) {
		$why = self::refusal( $op, $ctx );
		if ( '' !== $why ) {
			return Service::fail( 'refused', $why );
		}
		$service = $ctx['service'];
		$audit   = $ctx['audit'];
		$id      = isset( $post['card'] ) && is_scalar( $post['card'] ) ? absint( $post['card'] ) : 0;
		$text    = static function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		};
		$keep    = static function ( array $result ) use ( $post ) {
			if ( empty( $result['ok'] ) ) {
				$result['keep'] = array_map(
					static function ( $v ) {
						return is_scalar( $v ) ? (string) $v : '';
					},
					array_diff_key( $post, array_flip( array( '_wpnonce', '_wp_http_referer', 'action', 'op' ) ) )
				);
			}
			return $result;
		};

		switch ( $op ) {
			case 'issue':
				$r = $service->issue(
					array(
						'amount'          => $text( 'amount' ),
						'recipient_name'  => $text( 'recipient_name' ),
						'recipient_email' => $text( 'recipient_email' ),
						'sender_name'     => $text( 'sender_name' ),
						'sender_email'    => $text( 'sender_email' ),
						'message'         => $text( 'message' ),
						'delivery'        => $text( 'delivery' ),
						'deliver_on'      => $text( 'deliver_on' ),
						'reason'          => $text( 'reason' ),
						'request_key'     => $text( 'request_key' ),
					),
					$audit
				);
				if ( $r['ok'] && ! empty( $r['card_id'] ) ) {
					$r['redirect'] = self::card_url( (int) $r['card_id'] );
					if ( false === $r['sent'] ) {
						$r['message'] .= ' ' . __( 'But its e-mail could not be sent — it will be tried again within the hour; or press "Send the e-mail again".', 'fishotel-misc-plugin' );
					} elseif ( true === $r['sent'] ) {
						$r['message'] .= ' ' . __( 'Its e-mail was sent.', 'fishotel-misc-plugin' );
					}
				}
				return $keep( $r );

			case 'adjust':
				return $keep( $service->adjust( $id, $text( 'direction' ), $text( 'amount' ), $text( 'reason' ), $text( 'request_key' ), $audit ) );

			case 'details':
				return $keep(
					$service->update_details(
						$id,
						array(
							'recipient_name'  => $text( 'recipient_name' ),
							'recipient_email' => $text( 'recipient_email' ),
							'sender_name'     => $text( 'sender_name' ),
							'sender_email'    => $text( 'sender_email' ),
							'message'         => $text( 'message' ),
						),
						$text( 'reason' ),
						$audit
					)
				);

			case 'status':
				return $keep( $service->set_status( $id, $text( 'status' ), $text( 'reason' ), $audit ) );

			case 'resend':
				return $keep( $service->resend( $id, $text( 'to' ), $audit ) );

			case 'restore_part':
				$order = absint( $text( 'order' ) );
				$r     = $service->restore_part( $order, $text( 'amount' ), $text( 'reason' ), $text( 'request_key' ), $audit );
				if ( $r['ok'] && function_exists( 'wc_get_order' ) ) {
					$o = wc_get_order( $order );
					if ( is_object( $o ) ) {
						/* translators: 1: amount, 2: reason */
						$o->add_order_note( sprintf( __( '%1$s was put back on the customer\'s gift card: %2$s', 'fishotel-misc-plugin' ), Money::words( (int) $r['restored'] ), $text( 'reason' ) ) );
					}
				}
				return $keep( $r );

			case 'reveal':
				$r = $service->reveal( $id, $audit );
				if ( $r['ok'] ) {
					$r['message'] = __( 'The full code is shown below, this once. Showing it was written in the audit log.', 'fishotel-misc-plugin' );
					$r['reveal']  = array(
						'card' => $id,
						'code' => $r['code_text'],
					);
					unset( $r['code_text'] );
				}
				return $r;

			case 'dry_run':
				$report = $ctx['importer']->dry_run();
				return Service::ok( $report['ok'] ? __( 'Dry run done: nothing was written. Read the report below, then import.', 'fishotel-misc-plugin' ) : __( 'Dry run done: something stops the import — see the report below.', 'fishotel-misc-plugin' ), array( 'redirect' => self::page_url( 'import' ) ) );

			case 'import':
				$report = $ctx['importer']->run( array_merge( $audit, array( 'source' => 'import' ) ) );
				$r      = $report['reconciled'] ? Service::ok( __( 'Imported, and reconciled to the cent with the old tables. The old tables were not changed.', 'fishotel-misc-plugin' ) ) : Service::fail( 'not_reconciled', __( 'The import did not finish clean — see the report below. The old tables were not changed.', 'fishotel-misc-plugin' ) );
				$r['redirect'] = self::page_url( 'import' );
				return $r;

			case 'mode':
				return self::set_mode( $text( 'mode' ), $text( 'reason' ), $ctx );

			case 'settings':
				return self::save_settings( $post, $ctx );
		}
		return Service::fail( 'unknown', __( 'That action is not known.', 'fishotel-misc-plugin' ) );
	}

	/**
	 * Change the mode (Live only when nothing blocks it).
	 *
	 * @param string $mode   A Modes::MODES entry.
	 * @param string $reason Why.
	 * @param array  $ctx    context().
	 * @return array
	 */
	public static function set_mode( $mode, $reason, array $ctx ) {
		if ( ! in_array( $mode, Modes::MODES, true ) ) {
			return Service::fail( 'form', __( 'Choose Off, Shadow or Live.', 'fishotel-misc-plugin' ) );
		}
		$before = Modes::stored();
		if ( $before === $mode ) {
			return Service::ok( __( 'That is already the mode.', 'fishotel-misc-plugin' ), array( 'redirect' => self::page_url( 'settings' ) ) );
		}
		if ( 'live' === $mode ) {
			$blockers = Modes::live_blockers( Modes::legacy_loaded(), $ctx['importer']->old_state(), Importer::last_import() );
			if ( $blockers ) {
				$r             = Service::fail( 'blocked', __( 'Live was not switched on:', 'fishotel-misc-plugin' ) . ' ' . implode( ' ', $blockers ) );
				$r['redirect'] = self::page_url( 'settings' );
				return $r;
			}
		}
		$saved = $ctx['service']->record(
			$ctx['audit'],
			'mode',
			'mode',
			$before,
			$mode,
			(string) $reason,
			static function () use ( $mode ) {
				return update_option( Modes::OPTION, $mode, true );
			}
		);
		if ( ! $saved['ok'] ) {
			return $saved;
		}
		if ( 'live' === $mode ) {
			update_option( Account::FLUSH_OPTION, 1, true );
		} else {
			Cron::clear();
		}
		/* translators: %s: the mode in words */
		return Service::ok( sprintf( __( 'The mode is now: %s.', 'fishotel-misc-plugin' ), Modes::label( $mode ) ), array( 'redirect' => self::page_url( 'settings' ) ) );
	}

	/**
	 * Save the settings; each changed one is a row in the audit log.
	 *
	 * @param array $post Posted form.
	 * @param array $ctx  context().
	 * @return array
	 */
	public static function save_settings( array $post, array $ctx ) {
		$old = Settings::get();
		$in  = array();
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			if ( in_array( $key, Settings::YES_NO, true ) ) {
				$in[ $key ] = ! empty( $post[ $key ] ) ? 'yes' : 'no';
			} elseif ( isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ) {
				$in[ $key ] = (string) $post[ $key ];
			}
		}
		foreach ( array( 'custom_min', 'custom_max' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$cents      = Money::to_cents( $in[ $key ] );
				$in[ $key ] = null === $cents ? $old[ $key ] : $cents;
			}
		}
		$new     = Settings::sanitize( array_merge( $old, $in ) );
		$changed = array();
		foreach ( $new as $key => $value ) {
			if ( (string) $old[ $key ] !== (string) $value ) {
				$changed[ $key ] = $value;
			}
		}
		if ( ! $changed ) {
			return Service::ok( __( 'Nothing was different, so nothing changed.', 'fishotel-misc-plugin' ), array( 'redirect' => self::page_url( 'settings' ) ) );
		}
		$service = $ctx['service'];
		$first   = true;
		foreach ( $changed as $key => $value ) {
			$r = $service->record(
				$ctx['audit'],
				'settings',
				$key,
				(string) $old[ $key ],
				(string) $value,
				'',
				$first ? static function () use ( $new ) {
					update_option( Settings::OPTION_KEY, $new, false );
					return true;
				} : null
			);
			if ( ! $r['ok'] ) {
				return $r;
			}
			$first = false;
		}
		Gift_Cards::service( true );
		return Service::ok( __( 'Settings saved.', 'fishotel-misc-plugin' ), array( 'redirect' => self::page_url( 'settings' ) ) );
	}

	/* ============================================================== */
	/*  The export                                                     */
	/* ============================================================== */

	/**
	 * The export's lines (the header first), for the filters given.
	 *
	 * @param Store $store Store.
	 * @param array $spec  Store::audit_where() keys.
	 * @param int   $max   Most rows.
	 * @return array[]
	 */
	public static function export_rows( Store $store, array $spec, $max = 200000 ) {
		$out   = array( Audit::csv_header() );
		$after = 0;
		$cards = array();
		while ( count( $out ) <= $max ) {
			$rows = $store->audit_after( $after, 1000, $spec );
			if ( ! $rows ) {
				break;
			}
			$need = array_diff( array_unique( array_map( 'intval', array_column( $rows, 'card_id' ) ) ), array_keys( $cards ), array( 0 ) );
			foreach ( $store->cards_by_ids( $need ) as $cid => $card ) {
				$cards[ $cid ] = Codes::last4( $card['code'] );
			}
			foreach ( $rows as $row ) {
				$out[] = Audit::csv_row( $row, $cards[ (int) $row['card_id'] ] ?? '' );
				$after = (int) $row['id'];
			}
		}
		return $out;
	}

	/**
	 * The audit filters from a form or the address (pure).
	 *
	 * @param array         $in Form / query.
	 * @param \DateTimeZone $tz Site time zone.
	 * @return array
	 */
	public static function audit_spec( array $in, \DateTimeZone $tz ) {
		$spec = array();
		if ( ! empty( $in['card_id'] ) && is_scalar( $in['card_id'] ) ) {
			$spec['card_id'] = absint( $in['card_id'] );
		}
		if ( ! empty( $in['action_filter'] ) && is_scalar( $in['action_filter'] ) && isset( Audit::actions()[ (string) $in['action_filter'] ] ) ) {
			$spec['action'] = (string) $in['action_filter'];
		}
		if ( ! empty( $in['source'] ) && is_scalar( $in['source'] ) && in_array( (string) $in['source'], Audit::SOURCES, true ) ) {
			$spec['source'] = (string) $in['source'];
		}
		foreach ( array(
			'from' => '00:00:00',
			'to'   => '24:00:00',
		) as $key => $time ) {
			$day = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, $tz );
				if ( $d ) {
					$d            = 'to' === $key ? $d->modify( '+1 day' ) : $d;
					$spec[ $key ] = $d->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				}
			}
		}
		return $spec;
	}

	/**
	 * Send the CSV (owner only; the export itself is logged).
	 *
	 * @param array $post Posted form.
	 * @param array $ctx  context().
	 */
	private function export( array $post, array $ctx ) {
		$why = self::refusal( 'export', $ctx );
		if ( '' !== $why ) {
			wp_die( esc_html( $why ), '', array( 'response' => 403 ) );
		}
		$spec = self::audit_spec( $post, $ctx['service']->tz() );
		$ctx['service']->record( $ctx['audit'], 'export', 'filters', null, wp_json_encode( $spec ), '' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gift-card-audit-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		foreach ( self::export_rows( $ctx['service']->store, $spec ) as $line ) {
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ============================================================== */
	/*  The order screen                                               */
	/* ============================================================== */

	/**
	 * The "Gift cards" box on an order (classic and HPOS screens).
	 */
	public function order_box_register() {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( 'fishotel-gift-cards', __( 'Gift cards', 'fishotel-misc-plugin' ), array( $this, 'order_box' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * The box: what the order took from cards and put back.
	 *
	 * @param object $post_or_order WP_Post or WC_Order.
	 */
	public function order_box( $post_or_order ) {
		$order_id = is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ? (int) $post_or_order->get_id() : (int) ( $post_or_order->ID ?? 0 );
		$summary  = Gift_Cards::service()->order_summary( $order_id );
		if ( ! $summary['cards'] ) {
			echo '<p>' . esc_html__( 'No gift card was used on this order.', 'fishotel-misc-plugin' ) . '</p>';
			return;
		}
		/* translators: 1: used, 2: put back, 3: still out */
		echo '<p>' . esc_html( sprintf( __( 'Paid with gift cards: %1$s. Put back: %2$s. Still on the order: %3$s.', 'fishotel-misc-plugin' ), Money::words( $summary['used'] ), Money::words( $summary['restored'] ), Money::words( $summary['outstanding'] ) ) ) . '</p>';
		if ( $summary['outstanding'] > 0 && current_user_can( Gift_Cards::edit_capability() ) ) {
			echo '<p><a class="button" href="' . esc_url( self::page_url( 'order', array( 'order' => $order_id ) ) ) . '">' . esc_html__( 'Put part of it back on the card…', 'fishotel-misc-plugin' ) . '</a></p>';
		}
	}

	/* ============================================================== */
	/*  The page                                                       */
	/* ============================================================== */

	/**
	 * The list's spec from the address (pure).
	 *
	 * @param array $get $_GET.
	 * @return array
	 */
	public static function list_spec( array $get ) {
		$filter = isset( $get['filter'] ) && in_array( $get['filter'], Store::FILTERS, true ) ? (string) $get['filter'] : 'in_use';
		$sort   = isset( $get['sort'] ) && isset( Store::SORTS[ $get['sort'] ] ) ? (string) $get['sort'] : 'issued';
		$dir    = isset( $get['dir'] ) && 'asc' === $get['dir'] ? 'asc' : 'desc';
		$q      = isset( $get['q'] ) && is_scalar( $get['q'] ) ? substr( trim( sanitize_text_field( (string) $get['q'] ) ), 0, 100 ) : '';
		$paged  = isset( $get['paged'] ) ? max( 1, absint( $get['paged'] ) ) : 1;
		return array(
			'filter' => $filter,
			'sort'   => $sort,
			'dir'    => $dir,
			'q'      => $q,
			'paged'  => $paged,
			'limit'  => self::PER_PAGE,
			'offset' => ( $paged - 1 ) * self::PER_PAGE,
		);
	}

	/**
	 * Users whose name or e-mail matches a search (buyers and owners).
	 *
	 * @param string $q Search.
	 * @return int[]
	 */
	private static function users_matching( $q ) {
		if ( strlen( $q ) < 3 || ! function_exists( 'get_users' ) ) {
			return array();
		}
		return array_map(
			'intval',
			(array) get_users(
				array(
					'search'         => '*' . $q . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 50,
				)
			)
		);
	}

	/**
	 * FisHotel Tools → Gift Cards.
	 */
	public function render_page() {
		if ( ! current_user_can( Gift_Cards::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tabs, filters and paging.
		$get = wp_unslash( $_GET );
		$get = is_array( $get ) ? $get : array();
		$tab = isset( $get['tab'] ) ? sanitize_key( (string) $get['tab'] ) : 'cards';
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'cards';
		// phpcs:enable

		$ctx   = self::context( $tab );
		$flash = self::take_flash();
		$mode  = $ctx['mode'];
		if ( 'off' === $mode ) {
			$tab = 'settings';
		}
		if ( in_array( $tab, array( 'import', 'settings' ), true ) && ! $ctx['can_own'] ) {
			$tab = 'cards';
		}

		$service = $ctx['service'];
		$store   = $service->store;
		$view    = $tab;
		$data    = array();

		switch ( $tab ) {
			case 'cards':
				if ( ! empty( $get['card'] ) ) {
					$card = $store->card( absint( $get['card'] ) );
					if ( $card ) {
						$view = 'card';
						$data = array(
							'card'   => $card,
							'ledger' => $store->ledger_of_card( (int) $card['id'] ),
							'audit'  => $store->audit_rows( array( 'card_id' => (int) $card['id'] ), 200 ),
							'owner'  => (int) $card['owner_user_id'] > 0 && function_exists( 'get_userdata' ) ? get_userdata( (int) $card['owner_user_id'] ) : null,
						);
					}
				}
				if ( 'cards' === $view ) {
					$spec             = self::list_spec( $get );
					$spec['user_ids'] = self::users_matching( $spec['q'] );
					$counts           = array();
					foreach ( Store::FILTERS as $f ) {
						$counts[ $f ] = $store->count( array_merge( $spec, array( 'filter' => $f ) ) );
					}
					$data = array(
						'spec'   => $spec,
						'cards'  => $store->find( $spec ),
						'counts' => $counts,
						'expiry' => $store->has_legacy_expiry(),
					);
				}
				break;
			case 'stats':
				$data = array( 'stats' => $service->stats() );
				break;
			case 'audit':
				$spec = self::audit_spec( $get, $service->tz() );
				$page = isset( $get['paged'] ) ? max( 1, absint( $get['paged'] ) ) : 1;
				$data = array(
					'spec'   => $spec,
					'get'    => $get,
					'rows'   => $store->audit_rows( $spec, 100, ( $page - 1 ) * 100 ),
					'total'  => $store->audit_count( $spec ),
					'paged'  => $page,
					'verify' => ! empty( $get['verify'] ) ? Audit::verify(
						static function ( $after ) use ( $store ) {
							return $store->audit_after( $after, 1000 );
						},
						Audit::key(),
						get_option( Audit::HEAD_OPTION, null )
					) : null,
				);
				break;
			case 'import':
				$data = array(
					'check'    => $ctx['importer']->check(),
					'report'   => Importer::last_report(),
					'blockers' => Modes::live_blockers( Modes::legacy_loaded(), $ctx['importer']->old_state(), Importer::last_import() ),
				);
				break;
			case 'settings':
				$data = array(
					'settings' => $service->settings,
					'blockers' => $ctx['ready'] ? Modes::live_blockers( Modes::legacy_loaded(), $ctx['importer']->old_state(), Importer::last_import() ) : array(),
					'legacy'   => Modes::legacy_loaded(),
				);
				break;
			case 'order':
				$order_id = isset( $get['order'] ) ? absint( $get['order'] ) : 0;
				$data     = array(
					'order_id' => $order_id,
					'summary'  => $service->order_summary( $order_id ),
					'cards'    => $store->cards_by_ids( array_keys( $service->order_summary( $order_id )['cards'] ) ),
				);
				break;
		}

		include Gift_Cards::path() . 'views/admin-page.php';
	}
}
