# Gift Cards — headless tests

All run without WordPress or WooCommerce. The money tests run on a **real SQL database**: `fake-wpdb.php`
is a `$wpdb` on SQLite (PDO) that runs the Store's own SQL — its UNIQUE keys, its guarded UPDATE, its
transactions — translating only `START TRANSACTION`, `GET_LOCK` / `RELEASE_LOCK` (a lock shared by every
connection in the process, so a second "request" on a second connection really is turned away),
`SHOW TABLES` / `SHOW COLUMNS` (with the MySQL types `dbDelta()` was given) and `LIKE … ESCAPE`.
`wp-stubs.php` is just enough WordPress (options, transients, nonces, users and capabilities, the hook
registries, `wp_mail()` that keeps what it was given, `wp_die()` and the JSON replies as exceptions);
`wc-stubs.php` just enough WooCommerce (a session, a cart with a fees API, orders whose status changes fire
the status hooks, products with meta). `fake-wpdb.php` and `wc-stubs.php` exit at once when run directly.

```
php  includes/sections/gift-cards/tests/money-test.php
php  includes/sections/gift-cards/tests/audit-test.php
php  includes/sections/gift-cards/tests/importer-test.php
php  includes/sections/gift-cards/tests/rules-test.php
php  includes/sections/gift-cards/tests/checkout-test.php
php  includes/sections/gift-cards/tests/product-test.php
php  includes/sections/gift-cards/tests/account-test.php
php  includes/sections/gift-cards/tests/admin-test.php
php  includes/sections/gift-cards/tests/boot-test.php
node includes/sections/gift-cards/tests/gift-cards.test.js
```

| File | What it proves |
|---|---|
| `money-test.php` | cents in and out; issuing (a double click issues one card); adding to an account (somebody else's card gets the same answer as a code that does not exist); paying all / part / none; oldest card first; more than the wallet refused with nothing taken; put back exactly once through failed → cancelled → refunded; partial put-backs capped and once per form; an order changed and placed again; paid again after failing; **two tabs**; two cards all or nothing (rolled back); **two requests at once** (the lock on two connections; the guarded UPDATE alone cannot overdraw); balances by hand need a reason and never go below zero; disabled cards cannot pay; nothing expires; the ledger adds up to every balance after each part |
| `audit-test.php` | every write path writes its row with who / when (UTC and site time) / what / money / why / from where; the full code never in the log; a change whose row cannot be written is not saved; the chain shows a row edited, removed from the middle, cut off the end; a changed site key is a warning, not "tampered" — but a row marked "earlier key" after today's key is a problem; the newest-row record is signed (rewritten or deleted, it shows); the Store never UPDATEs the ledger or the log, never DELETEs / TRUNCATEs / DROPs; only the Store touches `$wpdb`; the export is formula-safe |
| `importer-test.php` | invented old-style tables built from the map with MySQL types: the schema check (matching; a missing, an extra and a different column — each named, nothing copied); the dry run (counts, owed to the cent, every difference, writes nothing); the import (reconciled; history copied; owners, disabled cards, expiry dates, waiting e-mails; one audit row per card); every statement on the old tables a SELECT or SHOW and the tables byte-for-byte the same after; an old order's deduction and its refund into the new system; re-runs (nothing twice, new old activity brought in, a card used here since left alone, the Live guard following it); data that stops the import; the read-only rule |
| `rules-test.php` | codes (format, 5,000 different, the alphabet, the secure random source, keys, masking); settings and their safe defaults; the modes, the fallback and the Live guard; the schema (UNIQUE keys, InnoDB, cents, no expiry column); the e-mail; the stats; the health check (a balance against its history, and money moved by SQL with no audit row — also dressed up as an import) |
| `checkout-test.php` | the choice from the form; the fee (none / all / part, capped at the order, shipping, a gift card in the cart and its setting); charging when the order is created; two tabs at Place order; the left-behind order; on hold when it cannot be charged; the status hooks each putting back once; Refunded puts back only when every item was refunded (else a note); paid after money was put back by hand takes nothing again, a never-charged order is charged when paid; a refunded order set back to Completed / Processing / On hold is never charged again and says so (once per status), a part put back by hand then Completed or failed-and-paid takes nothing, cancelled / failed then paid takes it once; **every status path** — 300 seeded random walks of 12 steps (paid statuses, failed, cancelled, Refunded with and without every item, part and all put back by hand, back to pending) checked step by step against a model of what must be on the order, how often it was taken and when the note is due; the fee carries no tax (checkout and Recalculate); paid-then-cancelled and its setting; trash paid / unpaid; codes typed at checkout (guests and their setting, somebody else's card never, the wrong-code limit, keyed on the connecting address, not a forwarded-for header; the AJAX nonce); the theme's checkbox id and marker; old orders' totals, Recalculate, refund; the Fulfillment order never charged |
| `product-test.php` | which products are gift cards (new flag, old flag, variations); the purchase fields; Add to cart; the cart and order lines; issuing once per unit however many "paid" hooks fire; the e-mails with each full code; masked codes in notes and on the order screen; a refunded order's note; a card not yet sent or used held back when its order is refunded (not sent on its date, logged, Send still works); a delivery date ahead and the hourly job; a failed e-mail |
| `account-test.php` | the endpoint and menu; the rewrite rules rebuilt once when the stored rules lack the address (a way back and forward), never again while it is there, not on every request when a rebuild does not bring it back, nothing read but the autoloaded rules on a normal request, plain permalinks left alone; its own template name and none of the theme's old names; every form nonce first, then logged in, then whose card; a card added with its code only (never by e-mail address alone); the page shows only my cards and activity; nothing unescaped |
| `admin-test.php` | nonce first (before the capability), then the capability, then the mode — Shadow and Off refuse every card change; each operation (Take it from the card again: only when none of it is on the order, a reason, once); a shop manager allowed to edit may not send a card's e-mail to another address nor change its addresses; the owner alone sees a full code (once, logged) and exports; the mode and every setting logged; the Live guard; the export and its filters; the list's filters, search (an order the card was used on too) and sort, and the "Try All" hint only when All is not chosen; every tab printed for the owner and a shop manager with no PHP notice and no full code |
| `boot-test.php` | loading every class file registers no hook and sends no query; switched off: nothing but taking a left-over job off; each mode's hooks on a front-end request (Off: none; Shadow: only the job's clean-up; Live: the shop) and no query while booting; Live with the old plugin back behaves as Shadow and says so, keeping only the putting-back hooks; the job; install only in wp-admin, once |
| `gift-cards.test.js` | the amount box's cleaning and cap; the admin page's "are you sure?" questions |

## Checked on a copy of the site (rehearsal-3, MariaDB, WooCommerce 11.2.0, the FisHotel theme)

Not part of the CI run; the scripts are in the build notes. Install on MariaDB (InnoDB check passes); Live;
a card e-mailed and added to an account; a real checkout through the storefront paying $10 of a $14.99
order with the card (fee −$10.00, no tax, order total $4.99, the ledger and the audit chain consistent);
cancelled → $10 back once; two custom-amount cards bought in one line → two cards, two e-mails; a card
issued through the real admin form (a repeated POST issues nothing; no nonce → 403); every tab opened over
HTTP with no new log line; **14 parallel checkouts of $10 on a $100 card → exactly 10 charged, 4 refused,
balance $0.00**, and 14 parallel bare UPDATEs with no lock at all → the same (the guard alone holds on
InnoDB); invented old tables imported (schema check against real `SHOW COLUMNS`, reconciled, the old
tables byte-for-byte the same, an old order's deduction shown in its totals).
