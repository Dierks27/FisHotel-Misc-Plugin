# Foam Cuts — headless tests

All run without WordPress or a database. `wp-stubs.php` supplies in-memory
options (reads and the autoload flag recorded), hooks that really run,
users with capabilities, nonces bound to action and user, transients,
redirects / `wp_die()` that stop a handler by throwing (`FH_Halt`), the
REST classes a route needs, a `WC_Order` double with `wc_get_orders()`
(which, like the CPT store, ignores a meta query), a `$wpdb` that only
knows which tables exist, and `dbDelta()`. `fake-store.php` is an
in-memory `Store` with the same rules and real rollback (it can fail a
method on demand, or behave like tables without transactions). Both
helpers exit 0 when CI runs them directly. Every box, size, name and
order number is invented.

```
php includes/sections/foam-cuts/tests/units-test.php
php includes/sections/foam-cuts/tests/packer-test.php
php includes/sections/foam-cuts/tests/plan-test.php
php includes/sections/foam-cuts/tests/store-test.php
php includes/sections/foam-cuts/tests/stock-test.php
php includes/sections/foam-cuts/tests/boxes-orders-test.php
php includes/sections/foam-cuts/tests/planner-test.php
php includes/sections/foam-cuts/tests/svg-test.php
php includes/sections/foam-cuts/tests/rest-test.php
php includes/sections/foam-cuts/tests/admin-test.php
php includes/sections/foam-cuts/tests/section-test.php
```

- **units-test** — typed sizes (decimals, fractions, units) to whole units
  and back as a tape measure reads; the six suggested pieces for several
  boxes, proven to fill the shell exactly; boxes the foam does not fit; a
  saved cut list.
- **packer-test** — every layout audited independently of how it was made:
  inside its source, no overlap, separable by edge-to-edge cuts (worked
  out from the rectangles), the listed cuts replayed; the kerf; turning on
  and off; leftovers before sheets and the snuggest first; the largest
  remainder; no cut longer than a sheet is wide; the same answer every
  time; a piece that fits no sheet; sixty seeded random jobs; 600 pieces.
- **plan-test** — sources, cuts in words, labels, waste, pieces per box,
  warnings, the shape other code relies on.
- **store-test** — the schema as dbDelta reads it, the version gate, a
  failed install, the label counter, the only `$wpdb` file.
- **stock-test** — mark as cut once, undo, undo blocked, a stale plan,
  failures half-way with and without transactions, the stock by hand.
- **boxes-orders-test** — settings, the three box sources with the other
  plugin absent / older / empty / switched off, the filters, the orders of
  a day and every shape the Packing section may answer in.
- **planner-test** — making, deleting, exporting a plan; the print key.
- **svg-test** — well-formed, to scale, labelled, numbered, escaped,
  self-contained.
- **rest-test** — permissions, the three routes, the stock never touched.
- **admin-test** — page and print permissions, capability + nonce on every
  write, each handler, escaping, a GET writes nothing, the printable page.
- **section-test** — discovery, what a front-end / REST / admin request
  loads and costs, activation, PHP 7.4 syntax, no mail / HTTP / order
  write anywhere.

The real `Store` was run against MariaDB next to the in-memory twin on one
scenario (same answers and same state at every step) and the screens, the
printable page and the REST routes (with an application password) were
exercised on a private WordPress during development.
