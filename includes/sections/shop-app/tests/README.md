# Shop App — headless tests

All run without WordPress or a database; each passes standalone and exits
non-zero on a failure.

```
php includes/sections/shop-app/tests/pins-test.php
php includes/sections/shop-app/tests/lockout-test.php
php includes/sections/shop-app/tests/device-test.php
php includes/sections/shop-app/tests/session-test.php
php includes/sections/shop-app/tests/gate-test.php
php includes/sections/shop-app/tests/labels-test.php
php includes/sections/shop-app/tests/saves-test.php
php includes/sections/shop-app/tests/store-test.php
php includes/sections/shop-app/tests/app-test.php
php includes/sections/shop-app/tests/admin-test.php
node includes/sections/shop-app/tests/app.test.js
node includes/sections/shop-app/tests/sw.test.js
```

## The helpers

- **wp-stubs.php** — just enough WordPress, and for the gate the parts
  that decide who is logged in written the way core writes them: hooks
  with priorities and accepted arguments; the current user as
  `pluggable.php` works it out (a global, filled once per request from
  `determine_current_user`, with a stand-in for the auth cookie at
  priority 10); capabilities as `WP_User::has_cap()` decides them
  (`map_meta_cap` and its filter, the multisite super-admin shortcut,
  `user_has_cap`, `do_not_allow`); nonces as core makes them; options that
  count every read a real site would answer with a query; users, roles,
  transients; redirects, `wp_die` and JSON answers that stop a handler
  (`FH_Halt`); a fake `$wpdb`. `fh_request()` starts a new pretend request
  (the hooks as they were after boot), `fh_boot_section()` loads the
  Clean Room's **real** classes and boots this section, `fh_boot_hooks()`
  changes the hooks every pretend request starts from, `fh_jar()` is a
  browser's cookie jar after a response. The clock is `$GLOBALS['fh_now']`.
- **fake-store.php** — an in-memory `Store` with the same methods and the
  same guarantees (a link used once, an attempt taken only while tries are
  left, a lockout written once, a save id claimed once, a log that only
  grows), a query counter and a per-method failure switch.
- **page-child.php** — the Clean Room's Front Desk ends its page with a
  real `exit`, so each page request is played in a process of its own:
  this script builds a pretend site from a scenario and prints what
  happened after the page.

Each helper exits 0 when CI runs it directly.

## The tests

- **pins-test.php** — what a PIN is; the hash (per-person salt, the site
  secret, never the PIN, not a bare hash); the match that compares with
  everybody before it answers (the work is counted); uniqueness among
  active people and the one thing a clash says; the person form's rules
  (a PIN needed, a clash, an account that may not be chosen, an account
  that is taken, switching off destroys the PIN, switching on needs one);
  the PIN-length setting; the settings' bounds; the site secret and the
  session key under new WordPress keys.
- **lockout-test.php** — five wrong PINs, five minutes, doubling to a day
  at most, the doubling starting over a day later; on a device: the same
  answer for any wrong PIN (nobody's, a switched-off person's, not a PIN),
  the right PIN refused while locked without looking at anybody's PIN, a
  correct PIN clearing the count but not the doubling, one log entry per
  lockout, Clear lockout; a burst of requests getting five tries; a device
  that is not enrolled never getting to try (and costing no query).
- **device-test.php** — tokens; *Set up this device* (the cookie's flags
  and year, only the SHA-256 stored, the token nowhere on the server);
  renewed on use; revoke taking effect on the next request, session and
  keypad with it; the one-time link (ten minutes, opening it uses nothing
  up, the button enrols, a second use / an expired / a made-up / a
  cancelled link refused, only its hash stored); a browser enrolled again;
  HTTPS.
- **session-test.php** — the cookie's shape and signature (every field
  changed, another key, junk); two hours from the last use (renewed at
  most once a minute) and from the unlock; the length setting applied to
  live sessions; on a device: the cookie's flags, renewal, running out,
  Lock on the server (a kept copy of the cookies is worth nothing), a
  session bound to its device, its person and the newest unlock, a person
  switched off, the shared account (no capability, no e-mail, made once),
  a deleted own account, new WordPress keys.
- **gate-test.php** — THE GATE, with the Clean Room's real Front Desk:
  which requests are shop-floor requests (some fifty kinds that are not);
  with a live session not logged in on wp-admin, other admin-ajax actions,
  admin-post, the REST API by path and by parameter, the shop front, My
  Account, checkout, wp-login — at no query; logged in on the Front Desk
  page and its AJAX, where the Front Desk's own handlers accept the nonce
  made on the page; the capability only inside the handler and nothing
  else, for the shared account, an administrator's own account and a
  multisite super admin; nonces per account; a WordPress login beside a
  session; a user worked out too early; a filter that asks who the user is
  while the gate works it out; WooCommerce's basket cookies; an ordinary
  visitor costing nothing; screens added by filter; every admin handler
  refusing a shop session; the shared account never logging in.
- **labels-test.php** — the label address in every case, each request in a
  process of its own: with a WordPress login the page is byte for byte the
  one the site made before this section; any other device as before; an
  enrolled device with a live session gets the Front Desk's own page with
  the bar added (take the bar away and it is the page a WordPress login
  gets, but for the nonce); nobody unlocked → the keypad, bound for that
  tank; expiry, Lock, revoke, an address with something added.
- **saves-test.php** — a save id: the handler runs once however often the
  id arrives, the first answer is repeated with a fresh screen; a refused
  save can be sent again; "still saving" while the first runs, taken over
  after thirty seconds; no way around the nonce, the session, the device
  or the account; a WordPress login untouched; kept an hour; a store that
  cannot answer never blocks a save.
- **store-test.php** — the schema as dbDelta wants it; the install behind
  the version option (no query once installed, the option only written
  when every table exists, a failed install retried in wp-admin once an
  hour); every method surviving a database that answers nothing; the log
  only ever inserted into; `$wpdb` in one file; the in-memory twin keeping
  pace with the real store; the section as the Section Manager finds it.
- **app-test.php** — the manifest (valid, the icons exist and are what it
  says, the maskable one filled to its corners); the shell (the same bytes
  for a stranger, an enrolled device, an unlocked device and an
  administrator; nothing personal; storable; no query); the service
  worker's script and its five addresses; the state (never stored, nothing
  of a person or a tank before the PIN); unlock and lock needing a POST
  with the app's header; where the app's address is answered; the picker's
  data; home-screen links; every string the script asks for.
- **admin-test.php** — who may write (capability and nonce on all six
  handlers and the link AJAX); people added, changed, switched off and on;
  whose account a person may be given; the one-time link's answer; who a
  device says is unlocked; every tab rendered with hostile names — nothing
  unescaped, no hash or token on any tab; what the page says without
  HTTPS, without the Clean Room, without its tables.
- **app.test.js** — the label reader (and what is not a label), the
  countdown, the save id, what an answer means, one keypad press; then the
  script in a stand-in page: inside a tank screen (the bar, what it leaves
  alone, NOT SAVED and Retry with the same save id, Back to the entry, the
  connection coming back, a 502, a Wi-Fi login page, "still saving", the
  page's own refusals, the keypad over the page when the session ran out,
  another account, a revoked device, the offline-queue seam) and the app
  itself (not set up → no keypad, the keypad, wrong PIN, lockout, home,
  buildings then tanks, Lock, a label scanned before the PIN).
- **sw.test.js** — the service worker in a stand-in worker: what it looks
  at and what it leaves alone; its own cookie-less request for the shell;
  network first; an answer that may not be kept (no-store, private, varies
  by cookie, redirected, not a 200) never kept or served; install and
  activate; only the five listed addresses ever in the cache.

Every name, tank, fish and address in these files is invented.
