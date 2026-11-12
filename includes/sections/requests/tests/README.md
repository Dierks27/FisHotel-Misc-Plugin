# Requests — headless tests

All run without WordPress, WooCommerce or a database. `wp-stubs.php`
supplies the WordPress functions the classes touch (translation and
escaping helpers, options and transients in memory, nonces, users and
capabilities seeded by a test, the action / filter registries, a
`wp_mail()` that keeps what it was given with its plain-text part, a
`WP_Error`, `wp_send_json_*()` that throw `Fh_Test_Json`), the `check()`
harness, and `fh_service()`: a Service on `fake-store.php` — an in-memory
twin of the Store that runs the same `Store::columns()` / `hydrate()` —
with a clock a test can move and a fee system it can switch off.

```
php  includes/sections/requests/tests/rules-test.php
php  includes/sections/requests/tests/service-test.php
php  includes/sections/requests/tests/create-test.php
php  includes/sections/requests/tests/follow-test.php
php  includes/sections/requests/tests/fees-test.php
php  includes/sections/requests/tests/listing-test.php
php  includes/sections/requests/tests/front-test.php
php  includes/sections/requests/tests/admin-test.php
node includes/sections/requests/tests/requests-form.test.js
node includes/sections/requests/tests/requests-admin.test.js
```

| File | What it proves |
|---|---|
| `rules-test.php` | validation; link rules (expiry at the end of a site-zone day, maximum uses, fee); fee and "still want it?" timers; the signed pay / personal / answer tokens (a changed amount is refused); settings; the schema; the log is append-only; only the Store touches `$wpdb` |
| `service-test.php` | by hand; a sheet from a link; approve with and without a fee (one invoice for a sheet); the one reminder and the expiry; paid once however often reported; a changed fee; decline; cancel / reopen / fill; still want it?; what each e-mail contains and when it goes out; the log |
| `create-test.php` | `fishotel_requests_create()` for a lost fish; a WP_Error while the section is off |
| `follow-test.php` | the pure plan (oldest first, the quantity cap, size, a hand match, fewer fish alive, an order that arrived without the fish), then the whole run against the Clean Room's own classes and in-memory store: a real supplier order, check-in, loss and sale date; told by itself or by one click; bought after the offer; past demand |
| `fees-test.php` | whether a fee is possible; the pay link's decisions; the cart line (that line only, quantity 1, no coupon); the order tag; the paid order; a wallet that was not credited; a short payment; prices that include tax |
| `listing-test.php` | filters, search, sorting, grouping by fish, Needs you, sheets, Demand |
| `front-test.php` | the rate limit; the form as printed; my requests; ownership by account and by personal link; who gets suggestions; the My Account tab; the public shortcode |
| `admin-test.php` | every operation of the page's write action; the list; "Requested by"; every tab printed with no notice and nothing unescaped |
| `*.test.js` | the pure helpers of the two page scripts, and "Add another fish" on a stand-in document |

`follow-test.php` loads the Clean Room section's classes and its
`tests/fake-store.php`; when that section is not in the tree, that part
is skipped. `fake-store.php` exits at once when run directly (the CI job
runs every `tests/*.php`).

Every name, fish, price, supplier and address in these tests is invented
("Blue Test Wrasse", "Test Supplier One", `pat@example.test`).
