# Gift Cards

FisHotel's own gift cards, built to replace the paid **WooCommerce Gift Cards** plugin (2.7.7 on the live
shop). It arrives **switched off**, and once switched on it starts in mode **Off**: nothing happens until a
mode is chosen in **FisHotel Tools → Gift Cards → Settings**.

What it does:

- **Issue a card from wp-admin** — no order needed: amount, recipient, sender, message, and the e-mail with
  the code sent now, on a date (9 am site time) or not at all.
- **Change a card** — add or take off money (a reason is required), who it is for / from, the message,
  disabled / active (a reason is required), send the e-mail again (to the recipient or another address).
  The e-mail holds the full code, so only the owner may send it to another address or change a card's
  e-mail addresses — also when Settings let shop managers change balances.
- **The audit log** — every change, append-only, exportable (see *The audit log* below).
- **Buy a card as a product** — fixed amounts (the price, or each variation's price) and/or a custom
  amount, recipient / sender / message / delivery date; issued once per unit when the order is paid.
- **Redeem a code into the account** and, at checkout, **use all, part or none of the balance**.
- **My Account → Gift Cards** — balance, redeem (a card goes into an account only with its code typed),
  my cards with their codes, activity.
- **The list**, **Stats** for the books, **no expiry**, and the **import** of every old card.

## Modes (`fishotel_gift_cards_mode`, autoloaded)

| Mode | What runs |
|---|---|
| **Off** | Nothing: no checkout box, no account page, no e-mails, no job. Only this section's Settings tab, to change the mode. |
| **Shadow** | The old plugin stays in charge. wp-admin only: import (dry run and real), the list, search, stats, the audit log and its export. No card is issued or changed here, nothing on the shop, no e-mails. |
| **Live** | These gift cards are in charge: checkout, My Account, products, e-mails, the hourly job, issuing and changing cards. |

**The guard.** Live cannot be chosen while the old plugin is switched on (its functions, its class, or its
entry in the active plugins — whatever its folder), nor while its tables hold cards that were not imported
cleanly: the last import must be reconciled to the cent and must have seen exactly the rows the old tables
have now (`Modes::live_blockers()`). If the old plugin is switched on again while Live is stored, the
section behaves as Shadow by itself and wp-admin says why — two systems never take money for the same cards.
In that fallback nothing pays with these cards, but money they already took still goes back (failed,
cancelled, refunded, trashed) and a refunded purchase still holds back its card's e-mail.

**After Live, the old plugin must stay off.** Its tables still hold the balances of the day of the import:
switched on again, it would let customers spend money that was already spent here, and this section cannot
stop it (the old plugin's code is not known here). If the old plugin must come back, the old balances have
to be brought up to date by hand first — the export of this section's cards is the list to do it from.

## The screens (FisHotel Tools → Gift Cards)

| Tab | Who | What |
|---|---|---|
| Cards | shop managers and up | By default the cards **in use** (money on them, not disabled); one click for **used up**, **disabled**, **all**, and **with an old expiry date** when imported cards carry one. Search: the whole code (typed any way) or its last characters, recipient name / e-mail, sender (buyer) name / e-mail, an account holder's or buyer's name / e-mail, an order number (the order that bought the card, or any order it paid for), an old card number. Sort: left (balance), issued for (amount), issued, last used. A card's page: details, money history, its audit rows and — Live — the forms. |
| Issue a card | who may change balances (Settings), Live | The new card form. |
| Stats | shop managers and up | **Still owed on all cards — the number for the books** (active and disabled shown apart; Settings decides whether disabled count), sold this month / year, issued by hand this month / year, redeemed this month / year (used on orders less what was put back), never used, the average card, not used in over a year; the health check (every balance against its money history) and a link to the audit chain check. |
| Audit log | shop managers and up (export: the owner) | Every row, newest first; filters by what, from where, card, dates; "Check the log"; "Export these rows as CSV". |
| Import | the owner (`manage_options`) | The old tables, the schema check, Dry run, Import, the last report, "Can Live be switched on?". |
| Settings | the owner | The mode, and every owner decision (see DECISIONS below). |

Codes are **masked** (`••••-••••-••••-NB4E`) everywhere in wp-admin. The owner (an administrator) can press
**Show the full code** on a card's page: the code is shown once and the showing is written in the audit
log. The customer sees their own codes in My Account; the recipient gets the code in the e-mail. Order
notes and the export never carry a full code.

Every write is one admin-post action, `fishotel_gift_cards_do`: **the nonce is checked first**, then the
capability (`manage_woocommerce` to reach the screen; the operation's own — see `Admin::OPS`), then the
mode. Each form carries a one-time key, so a double click saves once.

The **order screen** gets a **Gift cards** box (Live): what the order took from cards, what was put back,
what is still on it, and "Put part of it back on the card…".

## The money rules

All money is **whole cents** (`bigint`), never a float.

- **Paying.** The checkout box is the theme's own `#use_gift_card_balance` checkbox (so its CSS, its
  `update_checkout` wiring and its PayPal smart-button guard work unchanged) plus **How much to use** —
  all of it unless changed, never more than the order (goods, shipping and other fees before tax: what a
  negative fee may cover in WooCommerce). It becomes one **negative fee "Gift card" with no tax of its
  own**: WooCommerce would otherwise split a negative fee's tax over the goods (at checkout, and again on
  Recalculate) as if it were a discount, so the shop would collect the card's "own tax" less;
  `woocommerce_cart_totals_get_fees_from_cart_taxes` and `woocommerce_order_item_fee_after_calculate_taxes`
  give it none. Tax is worked out on the goods as before, and WooCommerce, e-mails, PDFs and PayPal all see a fee. A hidden
  `.fh-gc-applied` marker goes in the totals, so the theme hides PayPal's smart buttons as it does today.
- **Charging.** When the order is created (`woocommerce_checkout_order_created`) the fee's amount is taken
  from the customer's cards, the oldest added first, in **one transaction** holding the section's database
  lock: each card's balance moves in **one guarded UPDATE** (`balance + delta >= 0`, active only), each
  movement is a ledger row with a **UNIQUE key** (`use:<order>:<card>:<n>`) and an audit row. If the cards
  no longer hold it — spent in another tab — nothing is taken and the checkout stops with *"Your gift card
  balance changed since this page was opened… please check your order and place it again."* The same order
  and amount asked again takes nothing (an order is charged once); a changed amount puts the old one back
  first. When the order is paid / processing / on hold / completed it is checked again, and charged only
  when nothing is out on it: never charged yet (the block checkout, pay-for-order), or all put back by
  itself after it failed or was cancelled and then paid after all. An amount already taken is never changed
  there. Money that went back because of a **refund** — by itself (refunded with every item; the ledger row
  carries the refund's id) or by hand with the Gift cards box — is never taken again by a status change: a
  refunded order set back to Completed / Processing / On hold gets a note instead (*"…was NOT taken from the
  customer's gift card again…"*), and the order's Gift cards box offers **Take it from the card again**
  (administrators; a reason is required; it takes nothing while any of it is still on the order). If the
  cards cannot cover it, the order goes **on hold** with a note.
- **Putting back — exactly once.** Failed, cancelled (a paid one too unless Settings say no), refunded
  **with every item's whole quantity**, or trashed / deleted while unpaid: everything still on the order
  goes back, keyed `restore:<order>:<card>:<n>`, so however often or in whatever order the hooks fire it
  happens once. WooCommerce also marks an order Refunded when only the money paid some other way is all
  returned (with $90 of $100 on a card, a $10 refund for one of two items), and when the status is set to
  Refunded by hand: then nothing goes back by itself and a note points at the Gift cards box. A **partial
  refund** is never automatic either (the refund may already have gone to the payment method): the order
  gets a note, and the Gift cards box puts back any part of it, never more than is still out.
- **A bought card's order refunded or cancelled.** Every card it issued that nobody has used or added to
  an account, and whose e-mail was not sent yet, gets its e-mail **held back** (`email_state` `held`,
  logged): the hourly job never sends it, and the card's Send button still can. The order note names the
  held cards and the ones that still work; nothing is switched off by itself.
- **Several cards** pay one order together; it is all or nothing.
- **No expiry.** There is no expiry column for a card. An imported card's old expiry date is kept in
  `legacy_expires_at`, shown on its page and in the list filter, and never enforced.
- **Two wallets.** This is the Gift Cards balance only. Batch Manager's deposit wallet (user meta
  `_fishotel_deposit_balance`, "House Account") is not read or touched.

## Old orders

Imported activity keeps its order ids, so an old order that used a card shows **Gift card: −$X** in its
totals (e-mails, thank-you page, My Account, the admin screen), **Recalculate** keeps that deduction
(Settings), and refunding (every item) or cancelling it puts the money back on the card — in this system,
exactly once.

## The audit log (`{prefix}fh_gift_card_audit`)

One row per change, in the **same transaction** as the change (a change cannot be saved without its row):
who (user id and name; `system` for the job), when (`created_at_utc` and `site_time` with the offset),
what (`action`, `field`, `old_value` → `new_value`, `delta_cents`), why (`reason`), from where (`source`:
admin / checkout / account / order / rest / import / cron / system; `screen`; `ip` — `REMOTE_ADDR`, with a
forwarded-for address beside it as a hint only). Logged: issue, every balance change, every detail change,
on / off, added to an account, used on an order, put back, e-mail sent / failed / sent again, full code
shown, import (per card, and per run), mode, every setting, export.

Nothing in the plugin edits or deletes a row (the Store has no such method; a test reads it). Each row
carries `prev_hash` and `row_hash` = HMAC-SHA256 of its fields and the previous hash, keyed with
`FISHOTEL_GIFT_CARDS_AUDIT_KEY` when wp-config.php defines it, else the site's AUTH salt; the newest row's
id and hash are also kept in `fishotel_gift_cards_audit_head`, signed with the same key. **Check the log**
shows a row changed, a row removed from the middle, rows cut off the end, and that record changed or
missing. Rows written before the site's salts were changed are reported as "written under an earlier site
key" — a warning that they could not be checked, not "tampered" — but only rows before the first row
under today's key: one after it is reported as changed. The Stats tab's **health check** also matches every
money movement to its audit row (same card, same amount; imported history must add up to what the import's
own rows wrote down), so money moved with SQL alone — even with the balance kept in step — is named. (Someone who can change both the
database and wp-config.php could rebuild the chain; the export is the copy kept elsewhere.)

## The import (Import tab; Shadow or Live; the owner)

The old plugin's code is **not** on the machine this was built on, so the shape of its tables is an
**inference** kept in ONE file, `class-legacy-map.php` (tables, every column and its kind, which old
column feeds which new field, how dates and "active" are stored, what each activity type does to a
balance), with what is proven and what is guessed marked line by line. Before the first real import, read
the real structure on staging (no customer rows needed):

```
SHOW CREATE TABLE wp_woocommerce_gc_cards;
SHOW CREATE TABLE wp_woocommerce_gc_activity;
SELECT type, COUNT(*) FROM wp_woocommerce_gc_activity GROUP BY type;
SELECT order_item_type, COUNT(*) FROM wp_woocommerce_order_items GROUP BY order_item_type;
```

and make the map say exactly that. The **schema check** compares the real tables with the map and refuses
to import while any column is missing, extra or of another kind, naming each one.

The **dry run** writes nothing but its report: cards (active / disabled), activity rows, what is owed to
the cent (active, disabled, in all), and every difference — a stored balance its history does not add up
to (the stored balance is copied, with one "import adjustment" line), the same code twice, an amount with
a fraction of a cent (never rounded), a negative amount, an activity type the map does not know, a code a
card made here already has, more left than issued (a hint the map has `balance` / `remaining` the wrong way
round), an owner who is no longer a user (the card is copied into nobody's account), an order that is gone,
an expiry date, an e-mail still waiting for its day (not sent unless Settings say so), activity without a
card. The first six stop the import.

The **import** is one transaction per card, **copy only**: every statement on the old tables is a `SELECT`
or `SHOW` (`Store::legacy_read()` refuses anything else; a test reads that region of the Store and another
logs every statement during an import). The old tables stay exactly as they are, as the backup. It is safe
to run again: cards and activity rows already copied are recognised by their old ids; a card whose old
balance moved since (customers still using the old plugin in Shadow) is brought up to date — unless it has
been used in this system since: then it is listed and left alone. The report says **reconciled** only when
every old card is here and holds exactly its old balance, active and disabled totals equal to the cent.

## Tables (schema 1.0, InnoDB required)

| Table | Holds |
|---|---|
| `{prefix}fh_gift_cards` | one row per card: `code`, `code_key` (UNIQUE; upper case, letters and digits only), `status` (active / disabled), `amount_cents`, `balance_cents` (a cache of its ledger — the health check compares them), `owner_user_id` (0 = in nobody's account), `redeemed_at`, recipient / sender / message, `deliver_at`, `email_state` (none / pending / sending / sent / failed / held), `delivered_at`, `order_id`, `order_item_id` + `order_item_unit` (UNIQUE: a paid order line issues each card once), `product_id`, `buyer_user_id`, `source` (admin / order / import), `legacy_id` (UNIQUE), `legacy_expires_at`, dates |
| `{prefix}fh_gift_card_ledger` | append-only money movements: `type` (issued, redeemed_to_account, used, restored, credit, debit, import_adjust, import_note), signed `amount_cents`, `balance_after_cents`, `order_id`, `refund_id`, `user_id`, `idempotency_key` (UNIQUE), `legacy_activity_id` (UNIQUE), `note` |
| `{prefix}fh_gift_card_audit` | the audit log (above) |

Created by dbDelta on the first wp-admin request with the section on, and on plugin activation, behind
the autoloaded `fishotel_gift_cards_db_version` (a failed install is retried in wp-admin at most hourly; a
front-end request never installs). Nothing is dropped when the section or the plugin is switched off.

## Options

`fishotel_gift_cards_mode` (autoloaded), `fishotel_gift_cards_settings`, `fishotel_gift_cards_db_version`
(autoloaded), `fishotel_gift_cards_audit_head`, `fishotel_gift_cards_import_report` (the last dry run or
import), `fishotel_gift_cards_last_import`, `fishotel_gift_cards_last_run` (the job),
`fishotel_gift_cards_flush` (rebuild the rewrite rules once after Live), `fishotel_gift_cards_flush_tried`
(autoloaded; which stored rules lacking the My Account address were last rebuilt, and when — so a rebuild
that does not bring it back is not repeated for the same rules within the hour). Transients: `fh_gcard_tries_*`
(wrong codes per hour, per account — for a guest per connecting address, never a forwarded-for header,
which the sender types), `fh_gcard_flash_*`, `fishotel_gift_cards_flash_*`.

## Hooks used, and the theme

- Checkout: `woocommerce_review_order_before_order_total` (the box — the FisHotel theme fires it once,
  outside the refreshed part), `woocommerce_review_order_before_payment` ("Have a gift card?"),
  `woocommerce_review_order_after_order_total` (the marker), `woocommerce_checkout_update_order_review`,
  `woocommerce_checkout_process`, `woocommerce_cart_calculate_fees`, `woocommerce_checkout_create_order_fee_item`,
  `woocommerce_checkout_order_created`, the status hooks, `woocommerce_order_refunded`, trash / delete,
  `woocommerce_get_order_item_totals`, `woocommerce_admin_order_totals_after_discount`,
  `woocommerce_order_after_calculate_totals`.
- Products: the General tab, `woocommerce_before_add_to_cart_button`, add-to-cart validation and data, the
  cart line, the order line, the paid hooks.
- My Account: the **same `giftcards` address** (so the theme's label, icon and PERKS group apply), through
  its **own template name** `myaccount/fishotel-gift-cards.php` (a theme may copy it to
  `woocommerce/myaccount/`). The theme's `myaccount/giftcards.php` override is NOT used: it calls the old
  plugin and would stop the page once that is gone. The theme's own GET names (`fh_gc_action`, `gc_id`,
  `fh_gc_nonce`) and `woocommerce_gc_before_account_giftcards` are not used either: they belong to the old
  plugin's cards, and the theme's handler would take a new card id for an old one while it is still there.
  In Live, every request checks that the stored rewrite rules hold the address (`rewrite_rules` is
  autoloaded: no query); when they do not — Misc rolled back and forward again, Permalinks saved while the
  section was off — the rules are rebuilt once, so the page never needs a Permalinks visit.
- The theme's `woocommerce_gc_cart_contains_gift_card` filter has no effect on this section; the same
  question is Settings → "may pay for a gift card in the cart".
- The combined-box **Fulfillment** order (status `fulfillment`, `_fishotel_fulfillment_sources`) is never
  charged: its source orders were.
- Health log: `do_action( 'fishotel_log', level, 'gift-cards', message, context )`.
- The job: `fishotel_gift_cards_hourly` (Live only; armed at `init`, taken off in any other mode, when the
  section is switched off and on plugin deactivation).

## DECISIONS — the owner's to make (each is a setting, at the safer answer)

The full list with the reasons is in the build notes; in short:

| Question | Built as | Setting |
|---|---|---|
| Keep this balance and Batch Manager's deposit wallet apart? | Apart; the deposit wallet is not touched | — |
| "All, part or none" at checkout | A "How much to use" amount next to today's checkbox | — |
| Guests typing a code at checkout | No: they log in and add it first | `guest_codes` |
| A partial refund: card first or payment method first? | Neither by itself: the order's Gift cards box | — |
| A paid order cancelled: does the card part come back? | Yes (the requirements: cancelled → put back) | `cancel_paid_restores` |
| May the balance pay for a gift card in the cart? | Yes, as today | `wallet_pays_gift_cards` |
| Disabled cards in "the number for the books"? | Counted (still owed), shown apart | `books_include_disabled` |
| Who may change balances? | Administrators only | `editor_capability` |
| Imported cards whose e-mail waits for a day ahead | Not sent; listed by the dry run | `send_imported_pending` |
| Old orders' deduction on Recalculate | Kept | `recalc_guard` |
| Same look for new codes? | New: 16 characters, 4 groups, no look-alikes; old codes keep theirs | — |

## Not done / to check on staging

- The old tables' real shape (`class-legacy-map.php` is an inference) and whether old orders carry a
  `gift_card` order item; Recalculate on a real old order.
- The card can only pay what WooCommerce lets a negative fee cover: goods, shipping and fees **before tax**
  (the rehearsal copy of the shop charges no tax; check live). With tax on, the tax part would be paid by
  the payment method. (The fee itself carries no tax — checked on rehearsal-3 with tax switched on in
  memory: $100 taxable at 8% and a $50 card → order total $58.00 at checkout and after Recalculate.)
- PayPal: the smart-button guard of the theme is kept; check a mixed payment through the PayPal redirect on
  staging.
- No REST endpoint (the audit log has `rest` as a source for when one is added).

Every name, code, amount and address in the tests is invented.
