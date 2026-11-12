# Packing — headless tests

All run without WordPress, WooCommerce, a database or the network.
`wp-stubs.php` supplies the WordPress functions the section touches —
options (every read and write is counted), capabilities, nonces, the AJAX
replies (they throw `Fh_Test_Json`, so a test can call a real handler and
read what it would have answered) and a hook registry that remembers every
`add_action()` / `add_filter()`. A warning, a notice or a deprecation
stops a test. `fakes.php` stands in for WooCommerce: a product (simple,
variable, variation), an order line, an order, the registries
`wc_get_product()` / `wc_get_order()` answer from, and a store answered
from the fake products — each counts its writes, so a test can tell what
was saved and through which method. `fake-clean-room.php` is the Clean
Room as this section meets it: stand-ins for its `Store` and `Settings`,
and its **real** `Sizes` class when that file is in the plugin.

Every bag, box, fish, size, order and number in these files is invented.

```
php  includes/sections/packing/tests/suggest-test.php
php  includes/sections/packing/tests/settings-test.php
php  includes/sections/packing/tests/bags-test.php
php  includes/sections/packing/tests/boxes-test.php
php  includes/sections/packing/tests/lines-test.php
php  includes/sections/packing/tests/order-test.php
php  includes/sections/packing/tests/product-test.php
php  includes/sections/packing/tests/page-test.php
php  includes/sections/packing/tests/storefront-test.php
node includes/sections/packing/tests/packing-admin.test.js
```

Each file passes by itself and exits non-zero on a failure. The three
helper files exit cleanly when they are run directly (the CI runs every
`tests/*.php`).

- **suggest-test.php** — the pure rule: one bag size; mixed sizes (the fill
  is the sum of bags needed ÷ how many fit); a fill that lands exactly on
  100 % whatever floating point makes of it; the smallest box by volume,
  not by list order (the emptier of two the same size, then by name); on
  the line at, just under and around the threshold; a blank *how many fit*
  (the bag does not go in that box); a split across boxes (largest first,
  bulkiest bags first, the smallest box for what is left, never past the
  cap); a bag no box takes (set aside and named, the rest boxed); no boxes
  at all; nothing to pack; the fish-per-bag seam; every box against the
  order for the "choose another box" list.
- **settings-test.php** — nothing is seeded; who may save and with which
  nonce, for all four saves; bag sizes (ids are kept on a rename and never
  reused, a stale page never deletes, the option is stored without
  autoload); the field sanitisers; the own box list; how many fit (blank
  and 0 = does not go, a column the form never had keeps its number); the
  percent; a renamed box keeping its numbers — by its row in the own list,
  by its size otherwise; the size → bag map; a removed bag taking its
  numbers and its sizes with it; a corrupted option reading as safe
  values; one invalid value and nothing is saved, for every save; the AJAX
  handlers only sending the answer.
- **bags-test.php** — what counts as a size; the list of sizes while the
  Clean Room is not there (this section's own lookup, the filter, no
  attribute at all) and when it is (its attribute, its typed list, the
  Clean Room failing); the rule (own value, else the map, else none; a
  removed bag); the bag of a product (a variation, a size typed on the
  product, a simple product with one size or several); the bag of an order
  line (its product, the size on the line, the filter; nothing is written
  to the line); the bag of a size; fish per bag; the fill tool — a preview
  that changes nothing, a run that sets only what has no value through
  `update_meta_data()` + `save_meta_data()`, a batch at a time behind a
  cursor, a value set since the list was read left alone, a failed write
  counted, and every reason the tool refuses to run.
- **boxes-test.php** — a box (key, inches, size, volume); ShipTracker
  absent; an older build without `get_standard_boxes()` (guarded with
  `method_exists`); the active build providing them (they are the boxes in
  use, the own list kept); *how many fit* following a box by its name or
  by its size; an empty list, a list with nothing usable, a throwing or
  nonsense answer; the `fishotel_packing_boxes` filter; the real class
  name `FST_Settings`.
- **lines-test.php** — an ordinary order end to end (lines, bags by size,
  the box and its fill, what is ignored); a line with no bag size (listed,
  the suggestion marked incomplete); refunds and lines that already left;
  fish that are not shop products; an Invoice kept as one line (its
  snapshot as JSON or an array, no snapshot, a snapshot with no fish);
  what is a fish (the pick list's order of questions, a product with a Bag
  size of its own, the shared filter); fish per bag; a combined box read
  through its live sources (not its copies, not a cancelled source; no
  source loading; every source cancelled); an order that went into one;
  what a person is told (split, on the line said once for two boxes of a
  kind, no boxes, no numbers, a bag no box takes, no bags defined, no
  fish); and with stand-ins for the Batch plugin's Ship Day class and the
  theme's invoice reader.
- **order-test.php** — who may confirm; confirming the suggestion (two
  `update_meta_data()` and one `save()` on the order object, nothing
  else: no note, no status change); the stored record; choosing another
  box, several of a box; every refused value; clearing; an incomplete
  suggestion; nothing to suggest; an order that went into a combined box
  (confirmed there, not here); what is read back; the contracts with their
  filters and actions, a filter that answers nonsense, the "unavailable"
  answer; the Packing box on both order screens and what it draws
  (escaped); the AJAX handler.
- **product-test.php** — the dropdown's options; the rule of a save; who
  may save; the field on a variation and on a simple product; the two save
  handlers writing one hidden meta through the product object; a variable
  product having none of its own.
- **page-test.php** — the Packing page drawn headless: who may open it;
  the three tabs with nothing typed, then with bags, sizes and boxes; each
  form with its nonce and the fields its save reads; what the page says
  when something is not available; names escaped; ShipTracker providing
  the boxes; the assets.
- **storefront-test.php** — a front-end request loads nothing but the
  section's own file, hooks nothing and reads no option; in wp-admin the
  hooks are exactly the product editor's, the order screen's, the page's
  assets and the admin AJAX saves — none of the hooks that print
  variation, cart, checkout, e-mail, invoice or order-item data or build a
  REST / Store API answer; the source (comments stripped) is scanned for
  what the section must never do — a public AJAX action, a REST route,
  registered meta, order-item meta, post-meta functions, an e-mail, a
  stock or status change, money, an attribute / term / variation, a
  request to another server, a cron event, a table — and for `$wpdb`
  outside the Store (which only reads); the only meta ever written; the
  hidden meta keys; working out a suggestion or a line's bag writing
  nothing anywhere.
- **packing-admin.test.js** — the script's pure helpers: the tiny
  sprintf, a new table row from its template, the fill tool's run loop
  (it must end: a cursor that does not move, an answer that is not a
  success and a run that never says done all stop it), and what a button
  of the Packing box posts.

462 checks in the nine PHP files and 26 in the node test.
