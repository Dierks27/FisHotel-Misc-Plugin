# Lost Fish — headless tests

All run without WordPress, WooCommerce, the Clean Room or a database, and
every customer, fish, price and shipping rate in them is invented.

`wp-stubs.php` supplies the WordPress functions the classes touch
(translation and escaping helpers, `sanitize_*`, options — with a count
of writes and the autoload flag —, transients, a hook registry that
records every `do_action()`, nonces that match `wp_create_nonce()` per
user, capabilities per user, URLs, `wp_salt()`, a `wp_mail()` that keeps
what it is handed and can be told to refuse, and the cron scheduler,
recorded), loads the section's classes, and turns any PHP notice or
warning into a failed check.

`fakes.php` stands in for what the section talks to:

- **WooCommerce's order API** as far as it is used — orders, line items,
  fees, shipping lines, products, `wc_get_order()`, `wc_get_orders()`,
  `wc_get_product()`. The real `Orders` class runs on top of these.
- **The store** — an in-memory `Fh_Fake_Store` with the real one's rules:
  the same column types through `Store::clean_fields()` /
  `hydrate_case()`, the same conditional `transition()`, the same "mark
  reminded once", the per-kind lock (recorded).
- **The Clean Room** — a list of tank groups (`Fh_Fake_Rooms`).
- **The shipping settings** — `fh_rater()`, an invented rule: goods in
  the "premium" class ship for 40.00, or 15.00 once they come to 480.00;
  "free" class goods ship for nothing.

Tests that need another plugin's function or class define a stand-in in
their own file, which is why "with" and "without" are separate files:
`holders-test.php` has ShipTracker's `FST_Shipment` and no Invoices
section; `invoices-contract-test.php` has the Invoices section's two
functions; `cases-test.php` has no Requests section;
`requests-contract-test.php` has `fishotel_requests_create()`;
`shipping-test.php` has WooCommerce's zone, method and session.

```
php includes/sections/lost-fish/tests/money-test.php
php includes/sections/lost-fish/tests/cover-test.php
php includes/sections/lost-fish/tests/token-test.php
php includes/sections/lost-fish/tests/store-test.php
php includes/sections/lost-fish/tests/holders-test.php
php includes/sections/lost-fish/tests/invoices-contract-test.php
php includes/sections/lost-fish/tests/shipping-test.php
php includes/sections/lost-fish/tests/cases-test.php
php includes/sections/lost-fish/tests/requests-contract-test.php
php includes/sections/lost-fish/tests/email-test.php
php includes/sections/lost-fish/tests/customer-page-test.php
php includes/sections/lost-fish/tests/admin-test.php
php includes/sections/lost-fish/tests/section-test.php
```

Each exits non-zero on a failure. `store-test.php` and `section-test.php`
make a small temporary "WordPress" (one `upgrade.php` with a recording
`dbDelta()`) under `FH_TEST_TMP`, else the system temp directory, and
remove it again.

| File | Checks | What it proves |
| --- | --- | --- |
| `money-test.php` | 50 | Cents in and out; what a person may type as an amount; one fish of a line (total + tax ÷ quantity); the order's own shipping tax rate on a difference; **the money for each choice** — an order that drops under the threshold (refund = credit = the fish minus the difference), one that stays over, two fish of one line, a difference that is not known (no refund figure at all), a difference larger than the fish (zero, the rest reported), cancel less what was refunded, cancel not offered. |
| `cover-test.php` | 41 | **The cover maths**: the kind of a group (a product whatever the size; a species + size without one); COVERED across several groups — in QT and cleared, not the closed one, not the unidentified one, not another product, not another size of a species; COMMITTED minus refunds and answered cases (the two not counted twice); "replaced from the extra"; the shortage; open cases already speaking for fish; a closed-as-undone case releasing nothing and a closed-as-settled one releasing; **who is suggested** — the most recently paid first, ties by the newer order; the holders a case can be given to. |
| `token-test.php` | 30 | **Token signing**: a token verifies only for its case, its choice and its expiry; **expiry** to the second; **wrong case, wrong choice**, a later expiry written in, a changed character, another case's salt, a retired salt, another site's secret; ten things that are not tokens. |
| `store-test.php` | 63 | The schema as dbDelta needs it; every column the store writes is in it; money is whole cents and "not known" is NULL; which columns count as missing; **the install gate** — the version is written only once both tables and every column exist, a failure leaves a marker, nothing is retried on the front end; types in and out; **a status change is one conditional UPDATE**; the one-reminder mark; the per-kind lock; the one read of WooCommerce's order-item tables; the stored counts. |
| `holders-test.php` | 51 | Who is owed a fish, **without the Invoices section**: paid by paid date or `is_paid()`, never the status name; completed / shipped / cancelled / refunded orders and combined-box wrappers owe nothing; **shipped lines excluded** (a line a shipment names; a shipment without a list covers nothing; line status); refunds; **product versus variation**; invoice fish lines matched by name, a fish inside an invoice kept as one line; the open-order prefilter and its fallback; an order's facts — the money, **the delivery date and the ship date, or none**; notes are private. |
| `invoices-contract-test.php` | 35 | The same **with the Invoices contract**: a line linked to the product, to the species and size, to the tank group; the order's own invoice line getting its link instead of being counted twice; an invoice made by hand (no ids); `fishotel_invoices_lines_for_product()` asked with the invoice's product, once; the shapes both functions answer in; a function that throws; the cover maths end to end. |
| `shipping-test.php` | 52 | **The shipping difference**: drops under the threshold; stays over; already under; part of a line; shipping tax; **free shipping on purpose** (the free class — the settings are not even asked; a box that rides in another order's box); an itemised invoice priced as the invoice it was; nothing else to ship; **every "cannot tell"** — the settings do not give what was charged, no rate, a method that throws, a product gone, a line that is no order line, an invoice product gone, no shipping zones; another case of the order still waiting (unknown, the figure alone as a hint) and already answered (never withheld twice; a second refund that tips the order over). Then through stand-ins for WooCommerce's zone and method: the order's own method **and instance**, the package it is handed, the session and cart out of reach and back afterwards, two rates, no shipping line, two shipping lines. |
| `cases-test.php` | 207 | The life of a case, **without the Requests section**: a covered loss (a log line, nothing else); a short one (the case, on whom, its numbers, the note, the lock); more losses; what never opens a case; a listener can never break the Clean Room; **the undo closes an unsent case** and flags a sent one; **NOTHING SENT WITHOUT THE CLICK** (losses, the tick an hour and a month later, looking, saving numbers); the click — one e-mail, the amounts frozen, a second click sends nothing, the click bound to the numbers on the screen; **the cannot-tell case blocks Send** until a figure is typed or zero confirmed; no address / a cancelled order / no order; `wp_mail()` refusing; the answer, once; what the owner has to do for each choice; cancel closing the order's other cases; no cancel once part shipped; the answer by hand; done / reopen (the links die) / close; the reminder, once; "send by itself" — off by default, and when on only what is certain (not a name match, not typed numbers, not an order due to ship today, not once the tanks are no longer short); a case by hand; giving a case to another holder; two cases on one order; **the order notes at every stage**. |
| `requests-contract-test.php` | 43 | **The Requests contract call**: a "try again" hands over source `lost_fish`, owed, the fish, its size, the customer, the order — once; no other choice calls it; a size the section does not know is tried once more without it; a `WP_Error`, false, null, an exception; "Add to Requests" afterwards; what it may answer with. |
| `email-test.php` | 66 | **The e-mail's contents with and without a ship date** (and with nothing else left to ship); the four choices with their money — drops under, stays over, more than the fish, no cancel once part shipped; the links; the reminder; the preview; the template — placeholders, paragraphs, a template that forgot `{choices}`, markup in a name shown as text; the sender; never to a broken address. |
| `customer-page-test.php` | 65 | **GET changes nothing** — any link, any method but POST, any number of times, nor a POST without the Confirm field; the page shows the very sentence and amount of the e-mail; the Confirm records; **one confirm only**; eight wrong tokens (another case, another choice, a later expiry, …) are a 404 that names nothing; expired; reopened; closed; cancel not offered; a save that fails says the choice was NOT recorded. |
| `admin-test.php` | 104 | Every write needs the capability **and** the right nonce (a case's, the open form's, the settings') — refused without them, with another case's nonce or another form's; each operation; opening by hand; the settings (the switch that sends by itself is off unless ticked; the built-in text stored as "built-in"); and the screens rendered for real with a case in every state — one Send button per unsent case, disabled where the shipping difference is not known, the preview in a sandboxed frame without a live link, what to do after each answer, what the screen cannot reach — plus the form, the Log, the Settings and the link on the order screen. |
| `section-test.php` | 53 | The class the Section Manager finds; what boot() hooks; **an ordinary front-end request: no query, no option write, no cron call**; nothing for visitors but the page behind the link; first boot, activation, deactivation; the menu's count without a query; `fishotel_lostfish_cases_for_order()`; one `$wpdb` file; the ABSPATH guard on every file; no amount of money written in the code that decides; `wp_mail()` in one place, called for the send and the reminder only; nothing that refunds, cancels or changes stock; **the one line in the Clean Room**. |

Beyond these, the section was run in a real WordPress with WooCommerce —
posts storage and HPOS — against a private database with mail and
outgoing HTTP captured: a loss tapped on the Front Desk, the case, the
undo, the click, the e-mail, the customer's page over HTTP without a
login, the order notes, the cron tick's reminder and "send by itself",
dbDelta run twice, and no query from this section on an ordinary page
view.
