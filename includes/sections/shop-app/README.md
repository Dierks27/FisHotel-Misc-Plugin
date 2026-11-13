# Shop App — "FisHotel Shop"

An installable web app for the wet room. A phone or tablet the shop has
enrolled opens on a keypad with very large buttons; a PIN starts a
two-hour **shop session** for that person on that device; the Clean Room's
Front Desk tank screens open inside it — the same code, not a copy.

It is not an app from a store. On Android the browser's *Install app* gives
it its own icon and a full-screen window with no address bar; on an iPhone
or iPad *Add to Home Screen* does the same. It updates with the site.

A shop session is **not a WordPress login**. It opens the shop-floor
screens and nothing else — never wp-admin, orders, customers or money.

The section is off until switched on (FisHotel Tools → Dashboard). It needs
HTTPS (its cookies are `Secure`) and says so wherever that is missing. It
needs the Clean Room section for the tank screens and says so when that is
off.

## Addresses

They work with any permalink setting — all are queries on the home address:

| Address | What |
|---|---|
| `home_url( '/?fh_shop_app=1' )` | the app (one static page) |
| `…/?fh_shop_app=1&tank=N` | the app, bound for tank N after the PIN |
| `…/?fh_shop_app=manifest` | the web app manifest |
| `…/?fh_shop_app=sw` | the service worker |
| `…/?fh_shop_app=state` | JSON: what this device is (never stored) |
| `…/?fh_shop_app=unlock` | POST `pin` |
| `…/?fh_shop_app=lock` | POST |
| `…/?fh_shop_app=enrol&k=…` | the one-time enrolment link |
| `home_url( '/?fh_front_desk=1&tank=N' )` | the tank labels — unchanged |

The POSTs carry the header `X-FH-Shop-App: 1`, which a form on another site
cannot send; the cookies are `SameSite=Lax`, which another site's script
request does not carry.

## The admin page — FisHotel Tools → Shop App

`manage_woocommerce` (a shop manager or an administrator) and a nonce on
every write; a shop session is refused outright.

- **Devices** — *Set up this device* (logged in to WordPress on it), or
  *Make a one-time link*: a link and QR code good for ten minutes and one
  use. Opening it shows one button; the tap enrols the device — so a link
  preview or a scanner app that fetches the address cannot use the link
  up. The list: name, enrolled by, last used, who is unlocked, locked out
  or not, with *Revoke* and *Clear lockout*.
- **People** — a name, a PIN, the WordPress account their work is recorded
  under, on or off.
- **Settings** — how long a PIN unlocks for (30 minutes – 12 hours, default
  2 hours), counted from the last use (default) or from the unlock; how
  many digits a PIN has (4–6, default 4).
- **Log** — the append-only access log.
- **Install** — the app's address and the steps for Android and iPhone.

## How it holds together

### Devices

A PIN works only on an enrolled device. Enrolment sets a 256-bit random
token in the cookie `fh_shop_device_<COOKIEHASH>` — `Secure`, `HttpOnly`,
`SameSite=Lax`, a year, renewed on use (at most every five minutes) — and
the server keeps only its SHA-256. Revoking a device takes effect on its
very next request. A device that is not enrolled never sees a keypad.

### People and PINs

One PIN length for the whole shop, so the keypad sends a PIN the moment its
last digit is pressed. A PIN is stored as PBKDF2-SHA256 over the PIN with a
per-person salt and the site secret (`fishotel_shopapp_secret`, 64 random
hex characters written when the tables are installed; a wp-config constant
`FISHOTEL_SHOP_APP_SECRET` of 32 characters or more replaces it and keeps
it out of the database — define it *before* the first PIN: changing the
secret makes every PIN unusable until it is set again).

A PIN is the whole credential, so it must be unique among active people. A
clash says only **"Choose a different PIN."** A typed PIN is compared with
*every* person before the answer is given, and a wrong PIN gets the same
answer whether it is nobody's or belongs to someone switched off.

Switching a person off destroys their PIN; switching them back on needs a
new one. A new PIN, another account, or switching off **ends the session
that person has open**, on every device, at its next request — a session
started with a PIN that somebody else has learned does not live on. On the
form the PIN shows as it is typed (it is typed once; a slip nobody saw
would leave a PIN nobody knows); once saved it is never shown again.

Each person's work is recorded under a WordPress account: their own
(offered: the administrators and shop managers the user filling the form
may speak for — their own account and those they may edit), otherwise the
one shared account `fishotel-shop-staff` this section creates on first
need — no e-mail address, a random password nobody sees, the role
`fishotel_shop_staff` with no capability at all. That account cannot log
in with a password, cannot get an application password and cannot ask for
a password reset.

### Shop sessions

A correct PIN stores a fresh session id on the device row and sets the
cookie `fh_shop_session_<COOKIEHASH>` (`Secure`, `HttpOnly`,
`SameSite=Lax`):

    v1.<device id>.<person id>.<session id>.<unlocked at>.<last used at>.<HMAC-SHA256>

Every use checks the signature, the times against the current settings,
and that the device row holds the same session id for the same person and
that person is still switched on. So **Lock** (which clears the row), a
revoked device, a person switched off, a changed PIN or account, or a new
unlock end a session at once, whatever a copy of the cookie says. Counted from the last use the
cookie is rewritten on use, at most once a minute. The signing key mixes
the site secret with WordPress's auth salt: new WordPress keys end every
shop session; PINs and enrolled devices survive.

After 5 wrong PINs within 15 minutes a device is locked for 5 minutes;
every lockout after that doubles (10, 20, 40 … at most 24 hours); the
doubling starts over a day after the last lockout ended. While a device is
locked no PIN is looked at. One try is taken off the budget *before* the
PIN is compared, in one guarded UPDATE, so a burst of requests gets five
tries like anybody else.

**A correct PIN does not clear the wrong ones before it.** It gives back
the one try it took itself; the wrong PINs stay counted until 15 minutes
after the first of them (`fail_count` / `fail_since` on the device row).
Otherwise anybody with a PIN could try four others, unlock, try four more,
and walk through every PIN in the shop without the device ever locking.
What is left to a patient guesser is four tries per quarter of an hour on
a device the shop enrolled — and **every wrong PIN is written to the
log** (the device and which try it was; never the digits). A device whose
tries are used up with no lockout on record (its write failed) gets the
lockout on the next try rather than refusing every PIN for good.

### The gate

A shop session is recognised only on the shop-floor requests — by default
exactly:

- the Front Desk page: a `GET` of the home address whose query holds
  nothing but `fh_front_desk` and an optional numeric `tank`;
- the Front Desk's AJAX: a `POST` to `wp-admin/admin-ajax.php` with an
  empty query string, for `fishotel_cleanroom_act` (the one write),
  `_parse`, `_suggest` or `_screen`.

Everything else is classified "not ours" from the method, the path and the
parameter names, before any cookie is believed: wp-admin, every other
admin-ajax action, admin-post, the REST API (by path or by `?rest_route=`),
wp-login, the shop front, My Account, checkout, cron, XML-RPC. There the
device is simply not logged in, and the session costs the request no query.

On a shop-floor request with a live session:

- WordPress's current user is the person's account, so the Front Desk
  records the right actor and WordPress nonces work as for any logged-in
  user. **On the page** that holds from the start of the request (a
  `determine_current_user` filter at priority 99, re-asserted at `init`
  priority −1 in case something asked earlier) — its address can carry
  nothing but the tank number. **On an AJAX call** — a POST, to which
  anybody can add fields — the request is *nobody's* until the action
  itself starts: whatever runs on `init`, `wp_loaded` or `admin_init` and
  acts for "the current user" (a basket's `add-to-cart`, any plugin's form
  handler) sees a visitor. WordPress therefore fires
  `wp_ajax_nopriv_{action}`; the gate sits there at priority 0, makes the
  account the current user and hands over to the real `wp_ajax_{action}`;
- that account holds **no capability at all** (`map_meta_cap` →
  `do_not_allow`, which also holds for a multisite super admin; an
  administrator's own account is no administrator here) …
- … except the shop-floor capability — the one the Front Desk checks —
  and that only from the moment the screen's own handler starts
  (`template_redirect` priority 0 for the page, `wp_ajax_{action}`
  priority 0 for AJAX);
- WooCommerce's basket cookies are hidden from the request, WooCommerce
  sets none (`woocommerce_set_cookie_enabled`) and the account's saved
  basket is neither read nor written
  (`woocommerce_persistent_cart_enabled`), so a basket on the device is
  never merged into the staff account;
- page-cache plugins are told `DONOTCACHEPAGE` — to them a device without
  a WordPress login cookie is an anonymous visitor.

A live shop session comes before a WordPress login on these requests
(somebody unlocked with their PIN: they are who is working). Without a
live session a WordPress login works exactly as before.

Nothing in the Clean Room section is changed.

### The label address

`home_url( '/?fh_front_desk=1&tank=N' )`, decided at `template_redirect`
priority 0, just before the Front Desk's own handler:

| Device | What happens |
|---|---|
| not enrolled | nothing: exactly as before (a WordPress login opens the tank; anyone else is sent to log in; a login without the capability sees "Not allowed") |
| enrolled, a live shop session | the Front Desk renders the tank, with this app's bar added (back to the tanks, who is unlocked, Lock) |
| enrolled, no session, a WordPress login that may use the Front Desk | nothing: exactly as before |
| enrolled, no session | the keypad (`/?fh_shop_app=1&tank=N`), then that tank |

With a live session, a label address that carries anything extra (a
tracking parameter a scanner app added) is sent on to the plain one.

### No connection

Inside the app the script stands between a tank screen and the network. A
save that gets no answer opens a sheet — **MAY NOT BE SAVED**, what was
entered is still there, **Retry** — while the page's request stays open,
so the page keeps its form. *Back to the entry* closes the sheet and the
page says "not confirmed as saved … save again". When the browser reports
the connection back, the waiting save is sent by itself.

"No answer" only means the phone heard nothing: the server may have written
it — which is why the sheet does not claim it was *not* saved. So every
save carries a **save id** (`fh_save_id`): the same on every retry, **and
the same when that very entry is saved again after *Back to the entry***
(within the hour, while the page is open; a changed entry gets a new id).
The server answers a repeated id from memory instead of writing twice
(`{prefix}fh_shopapp_saves`, kept a **week** — the sheet has no time limit
and may be found open after a weekend; a save still running answers 409
and the app asks again). The id names one entry: the same id with other
content is another save and is written, never answered "saved" from the
memory of something else. A refused save is not remembered.

When WordPress no longer knows the device (the session ran out), the
keypad comes up over the page — it names whose PIN saves the waiting
entry — and the save goes through after that PIN. When somebody with
another account unlocks instead, the entry cannot be saved as theirs: the
screen says **NOT SAVED — enter it again**, and only then is the tank
loaded afresh. An entry is never dropped without a word.

**The seam for an offline queue** (a later step; nothing is queued today):
`document` gets a `fhshopapp:savefailed` event with
`detail = { id, body, reason }`, and when `window.fhShopApp.onSaveFailed`
is a function it is asked first — if it returns a Promise of a `Response`,
that is handed to the page and no sheet opens. The server half exists: a
replayed save id is safe.

### The service worker

Registered for the scope `/?fh_shop_app=` — the app's own address; the shop
front and the tank screens never pass through it. It looks only at a `GET`
for one of five static addresses (the shell, the manifest, three icons),
fetches them **itself without cookies** (`credentials: "omit"`), and keeps
an answer only when it is a plain 200 that does not say `no-store` or
`private` and does not vary by cookie. Network first; the copy is used only
when the network gives nothing. The shell is one static page — the same
bytes for every visitor, with no name, tank or nonce in it — which asks
`…=state` what the device is.

## For other sections

| | |
|---|---|
| `fishotel_shop_app_person()` | Who is working on this request through the app: `array{ id, name, user_id, device_id, device }`, or `null` (also while this section is switched off). Exists only while the Misc plugin is active — guard with `function_exists()`. Once the screen's handler runs `get_current_user_id()` is that person's account (on an AJAX call not before — see the gate); this adds their own name (several people may share *Shop staff*). |
| filter `fishotel_shop_app_allowed_requests` | Add a shop-floor screen: `array( 'type' => 'page', 'query_var' => 'fh_packing', 'params' => array( 'box' => '/^\d{1,4}$/D' ) )` (a GET of the home address with that switch and at most those parameters) or `array( 'type' => 'ajax', 'action' => 'fh_packing_save' )` (a POST to admin-ajax.php, empty query string). The capability arrives when your handler starts: `template_redirect` priority 0, or `wp_ajax_{action}` priority 0. On an AJAX rule nobody is logged in before that — register the handler on `wp_ajax_{action}` as for any logged-in user and do not look for the account on `init` or `admin_init`. Keep a rule as narrow as the screen — it is a way in for a four-digit PIN. |
| filter `fishotel_shop_app_capabilities` | The primitive capabilities a shop session holds while a shop-floor handler runs. Default: the Front Desk's (`manage_woocommerce`). |
| filter `fishotel_shop_app_home_links` | Buttons on the app's home screen: `array( 'label' => 'Packing', 'url' => home_url( '/?fh_packing=1' ) )`. An address outside this site is dropped. |
| filter `fishotel_shop_app_account_roles` | The roles whose accounts a person can be tied to (default `administrator`, `shop_manager`). |
| filter `fishotel_shop_app_https_ok` | For a development site on localhost. |
| action `fishotel_shop_app_unlocked` / `fishotel_shop_app_locked` | With `array{ id, name, user_id, device_id, device }`. |
| action `fishotel_log` | Every access-log entry (wrong PINs included) is also sent to the Health section's logger (source `shop-app`; a lockout is a warning). |

Stored: tables `{prefix}fh_shopapp_devices`, `_people`, `_log`, `_saves`
(schema 1.1: `devices.fail_since`; the version option is written only once
the tables and that column exist);
options `fishotel_shopapp_db_version` (autoloaded), `fishotel_shopapp_settings`,
`fishotel_shopapp_secret`, `fishotel_shopapp_staff_user` (not autoloaded);
the role `fishotel_shop_staff`; the user `fishotel-shop-staff`. No post or
user meta, no REST route, no cron. Deactivating keeps everything.

## Files

| | |
|---|---|
| `class-shop-app.php` | the section, its addresses, what it knows of the Front Desk, the global helper |
| `class-store.php` | the only file that touches `$wpdb` |
| `class-pins.php`, `class-lockout.php`, `class-session.php` | pure: PINs and tokens, the lockout rule, the signed cookie |
| `class-settings.php`, `class-http.php` | options and the secret; cookies and headers |
| `class-people.php`, `class-access.php` | the person form's rules and the accounts; devices, unlock, lock, the log |
| `class-gate.php` | where a shop session counts, and for how much |
| `class-saves.php` | a save sent twice is written once |
| `class-app.php` | the app's address, the label address, the bar |
| `class-admin.php`, `views/`, `css/admin.css`, `js/admin.js` | the admin page |
| `js/app.js`, `css/app.css` | the app's script and styles (the shell *and* the bar inside a tank screen) |
| `js/sw.js` | the service worker |
| `assets/` | the icons; `tools/make-icons.php` draws them (by hand, needs GD) |
| `tests/` | see `tests/README.md` |

## By hand, once, on the real site

1. Switch **Shop App** on (FisHotel Tools → Dashboard). FisHotel Tools →
   Shop App → People: add one person with a PIN.
2. Devices → *Make a one-time link*. Scan the code with the shop tablet,
   tap *Set up this device*: the keypad appears. Try the link again on
   another phone: "This link no longer works".
3. Enter the PIN: the home screen with the name and Lock; pick a building,
   then a tank — the tank screen with the bar. Record a loss.
4. Switch the tablet to flight mode, record another loss: MAY NOT BE
   SAVED. Switch flight mode off: it saves by itself, once. Do it again,
   tap *Back to the entry*, switch flight mode off, tap Save: one entry.
5. Scan a tank label with the tablet's camera: that tank. Tap Lock, scan
   again: the keypad, then that tank.
6. On the same tablet open `/wp-admin/` and `/my-account/`: a login page.
7. Enter five wrong PINs: locked for five minutes; Devices shows it, the
   Log lists each wrong PIN; *Clear lockout*. Change a person's PIN while
   they are unlocked: their tank screen asks for the PIN again. *Revoke*:
   the tablet says it is not set up.
8. On the tablet's browser menu: *Install app*.
