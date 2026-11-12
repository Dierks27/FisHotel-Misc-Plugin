# Requests

A request sheet for the fish people ask for — by e-mail, in person, from
locals, for a lost fish, from long-time clients — kept in one place, with
a state that follows the fish by itself and a log of everything that
happened.

**Hidden from the public at the start.** There is no menu item, nothing
in a sitemap, and every page of the section sends `noindex`. The request
form exists only behind a private link the owner makes.

Switch the section on in **FisHotel Tools → Dashboard**; it then lives at
**FisHotel Tools → Requests**.

## Three ways in

1. **A private link** (Requests → Links): a label, an optional fee, an
   optional expiry and maximum number of uses. Copy it and send it —
   `https://…/?fh_request=<token>`. The page is plain (no theme), works
   logged out, and asks for a name, an e-mail, a forum handle (optional)
   and one or more fish: the name (with suggestions from the species list
   and the published fish products), a size from the standard size list
   (free text when the Clean Room has none), a quantity, a note. What
   comes in is a *sheet* of requests in state **new**. The form carries a
   nonce and a honeypot field, and takes 5 sheets an hour per address and
   per e-mail.
2. **By hand** (Requests → Add): pick a site customer or type a name /
   e-mail / handle, the fish, size, quantity, where it came from, a note,
   an optional fee — or tick "a replacement for a lost fish" (owed, never
   a fee). It is approved as it is saved.
3. **Another section**: `fishotel_requests_create( array $args )` — see
   *Contracts* below.

## States

`new` → `awaiting_payment` → `waiting` → `on_order` → `in_quarantine` →
`ready` → `offered` → `filled`, or `cancelled` / `expired`.

- **new** — a sheet from a link waits under *Needs you*: **Approve
  ticked** (with or without a fee) or open a request to **Decline** it
  (an optional note; an e-mail only when ticked).
- **awaiting_payment** — approved with a fee: one invoice e-mail with a
  pay link. One reminder after 3 days, expiry after 7 (both in Settings).
- **waiting** — on the list.
- **on_order / in_quarantine / ready** — see *The state follows the
  fish*.
- **offered** — the person was told the fish is ready.
- **filled** — the owner marked it, or that customer bought that product
  after the offer.

Every change is one line in the request's **History** (append-only: who,
when, what).

## The fee

A fee belongs to an *invoice*, and an invoice may cover several requests
approved together (one sheet, one fee): the oldest carries the amount,
the others point at it, all wait for the payment, one e-mail goes out,
and paying it puts every one of them on the list.

The money goes through the store's existing custom-order **deposit**, so
it lands in the customer's **wallet** — FisHotel Batch Manager credits
it; this section never touches a wallet:

- the pay link — `/?fh_request_pay=<request>-<cents>-<signature>` — is
  signed over the request **and** the amount: a link whose amount was
  changed is refused, and after the fee is changed the old link stops
  working;
- it puts Batch Manager's wallet-deposit product in the cart with the
  keys Batch Manager prices by (`is_deposit`, `deposit_amount`) plus this
  section's own (`fh_request_fee`); the price is applied to that cart
  line only and checked again every time the cart is priced; the line's
  quantity is always 1 and no coupon takes anything off it;
- the order line is tagged `_fh_request_fee` (and the order
  `_fishotel_request_fee`); when the order is paid — at priority 20 on
  the same three hooks Batch Manager credits the wallet on at priority
  10 — the fee is recorded as paid, and the History says whether the
  wallet was credited.

Because Batch Manager credits the wallet of the order's **customer
account**:

- the pay link asks for a login first (a guest order would be credited
  to nobody), and the checkout refuses a fee line without an account;
- a request that belongs to a site customer can only be paid while
  logged in as that customer; a request without an account joins the
  account that paid;
- what is credited is the line's total before tax. Keep the deposit
  product non-taxable, as it is for wallet refills.

Not handled here: a fee order that is refunded or cancelled after it was
paid changes nothing — take the money back out of the wallet in Batch
Manager and cancel the request.

**Without Batch Manager, WooCommerce or the deposit product, fees are
unavailable**: every screen says why, the fee fields are switched off,
and requests can still be taken and approved without a fee. The deposit
product is Batch Manager's own setting; Settings → Fees can name another.

## The state follows the fish

With the Clean Room section on, the daily check (and *Run the daily check
now*) moves requests by itself:

- **waiting → on_order** when a supplier order that is a *draft* or
  *ordered* has a line of that fish — the species-list entry when both
  have one, else the product, else the same name; and the same size when
  both have one. The oldest request first; never more fish than the line
  orders.
- **on_order → in_quarantine** when that line is checked in to a tank.
- **in_quarantine → ready** when that tank group's sale date has come
  (the Clean Room's own "for sale").
- **ready → offered**: the "ready" e-mail, with the product link when the
  product is published. By itself when Settings → *Tell people by
  themselves when their fish is ready* is on (the default), otherwise the
  request waits under *Needs you* for one click. Somebody without an
  e-mail address always waits there (*Told them* records that you did).
- and **back to waiting** when the fish is not there after all: the order
  arrived without it, the line was removed, or fewer fish are alive than
  were asked for — the oldest requests keep their place, a request
  matched by hand goes first, and a request that was already told is
  never taken back.

On a request's page the owner can **match it by hand** to a supplier
order line (also on an order that is already received) and **take it
off** again; taken off by hand, it is left alone until *Let it match by
itself again*. The Clean Room's supplier order page shows **Requested
by: …** under each line.

Without the Clean Room nothing follows by itself: the screens say so,
sizes are free text, and requests are marked filled by hand.

## For the person who asked

- **My Account → Requests** (an endpoint that exists only while the
  section is on, shown to a customer who has requests): their own
  requests in plain words, with Cancel. A theme that draws its own
  account menu may not list the tab — the account's first page links to
  it, and so does every e-mail.
- **A personal link** in each e-mail, for people without an account:
  the requests of that e-mail address, with Cancel.
- A request that is **owed** cannot be cancelled by the customer: the
  shop settles that one.
- **Still want it?** — after 4 months of waiting (Settings) a request
  that came through a link or the public form gets an e-mail with *Keep*
  and *Cancel* links. Each opens a page with one button; opening the
  link changes nothing, so a mail scanner cannot answer. No answer in 14
  days closes the request. Requests added by hand and owed fish are never
  asked and never close by themselves.

A customer only ever sees or cancels their own requests: by account (the
request's customer id) or by personal link (the signed e-mail address).

## E-mail

Five e-mails, all through `wp_mail()` as simple HTML with a plain-text
part, each with a switch, a subject and a body in Settings, each logged
on its request: the invoice for the fee, the fee reminder, the fish is
ready, still want it?, declined. Nothing is e-mailed for a request added
by hand unless it has a fee or its fish becomes ready.

## Later: open to the public

Settings → *Open to the public* (off) enables the
`[fishotel_request_form]` shortcode: a logged-in customer sees the same
form; what they send waits for approval with the default fee proposed,
up to 5 open requests per customer. While the switch is off the
shortcode prints nothing.

## Contracts

```php
// Another section (guard with function_exists(); a WP_Error while Requests is off).
$id = fishotel_requests_create( array(
	'user_id'  => 123,              // or name / email / handle
	'fish'     => 'Blue Test Wrasse', // or product_id
	'qty'      => 1,
	'source'   => 'lost_fish',       // owed, never a fee, "waiting" at once
	'order_id' => 456,               // the WooCommerce order it came from
) );
$request = fishotel_requests_get( $id ); // array|null
```

- **Actions**: `fishotel_requests_created` ( id, request ),
  `fishotel_requests_state_changed` ( id, from, to ),
  `fishotel_requests_fee_paid` ( carrier id, order id, amount ).
- **Filters**: `fishotel_requests_deposit_product_id`,
  `fishotel_requests_rate_limit`, `fishotel_requests_mail` ( args, kind,
  request ).
- **Clean Room**: `do_action( 'fishotel_cleanroom_order_line', $line )`
  under each line's fish on the supplier order page (the one line this
  section added outside its folder).
- **Meta**: order line `_fh_request_fee`, `_fh_request_fee_amount`;
  order `_fishotel_request_fee`; cart item key `fh_request_fee`.
- **Options**: `fishotel_requests_db_version`,
  `fishotel_requests_settings` (both autoloaded),
  `fishotel_requests_last_run`. Cron: `fishotel_requests_daily`.
- **Tables**: `{prefix}fh_requests`, `{prefix}fh_request_links`,
  `{prefix}fh_request_log`. No REST route.

An ordinary front-end request costs no query from this section.

## Files

| File | What |
|---|---|
| `class-requests.php` | entry class, the service, `fishotel_requests_create()` |
| `class-store.php` | the only `$wpdb` file: tables, guarded updates, the log |
| `class-rules.php` | pure: states, validation, link rules, timers |
| `class-tokens.php`, `class-urls.php` | signed links and every address |
| `class-settings.php`, `class-mailer.php` | the option; the five e-mails |
| `class-service.php` | everything that can happen to a request |
| `class-fees.php` | the fee through Batch Manager's wallet deposit |
| `class-cleanroom.php`, `class-follow.php` | reading the Clean Room; the plan |
| `class-cron.php` | the daily check |
| `class-listing.php` | pure: filter, sort, group, Needs you, Demand |
| `class-front.php`, `views/front-*.php` | the form, my requests, keep / cancel, pay |
| `class-admin.php`, `views/admin-*.php` | FisHotel Tools → Requests |
| `tests/` | headless tests (see `tests/README.md`) |
