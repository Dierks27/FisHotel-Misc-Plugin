# Clean Room — headless tests

All run without WordPress, WooCommerce or a database. `wp-stubs.php`
supplies the handful of WordPress functions the classes touch (the
translation helpers, `sanitize_*`, options, transients, `current_time`,
`wp_timezone` = America/Chicago, `user_can` / `current_user_can`,
`wp_verify_nonce` matching `wp_create_nonce`, `wp_get_current_user`, the
action and filter registries — all in-memory; `wp_send_json_error()` /
`wp_send_json_success()` and a failed `check_ajax_referer()` throw
`Fh_Test_Json`, so a real `wp_ajax_` handler can be called up to its
reply), the `check()` / `check_same()` /
`check_contains()` / `check_json()` / `fh_finish()` harness and the temp-dir helpers, and
loads the section's classes; the Store
class (the only `$wpdb` file) is loaded for its constants and pure
statics only and stands replaced by `fake-store.php` — an in-memory
store that runs the same Replay and hydration code — so no `$wpdb`
method is ever called. `fixtures/qr-front-desk-521.txt` is the module
matrix python-qrcode produces for the Front Desk URL of tank 521 (byte
mode, level M, mask 2). Four fixtures are read by the PHP tests AND the
node tests, so the server and the page cannot drift apart:
`fixtures/box-maths.json` (the percent-of-a-box maths: typed percents
and box numbers, the per-box summary, the remembered shares, "How full"),
`fixtures/unknown-names.json` (the "??" name of an unknown fish),
`fixtures/dose-maths.json` (the dose sums: the rounding, the unit of a
weight, the words, and every sum with its amount, result line, working
and note), `fixtures/dose-phrases.json` (a dose phrase in plain words)
and `fixtures/todo-lines.json` (the day's to-do list in words — a line's
medication and dose, the feeding of a whole building, a copper flag —
and "what is it for" → the schedules carrying that word).

```
php  includes/sections/clean-room/tests/layout-test.php
php  includes/sections/clean-room/tests/replay-test.php
php  includes/sections/clean-room/tests/protocol-test.php
php  includes/sections/clean-room/tests/parser-test.php
php  includes/sections/clean-room/tests/qr-test.php
php  includes/sections/clean-room/tests/csv-test.php
php  includes/sections/clean-room/tests/front-desk-test.php
php  includes/sections/clean-room/tests/costing-test.php
php  includes/sections/clean-room/tests/stats-test.php
php  includes/sections/clean-room/tests/stats-prices-test.php
php  includes/sections/clean-room/tests/stats-sales-test.php
php  includes/sections/clean-room/tests/stats-admin-test.php
php  includes/sections/clean-room/tests/species-test.php
php  includes/sections/clean-room/tests/orders-test.php
php  includes/sections/clean-room/tests/xlsx-test.php
php  includes/sections/clean-room/tests/workbook-admin-test.php
php  includes/sections/clean-room/tests/workbook-test.php
php  includes/sections/clean-room/tests/import-map-test.php
php  includes/sections/clean-room/tests/import-test.php
php  includes/sections/clean-room/tests/sale-dates-test.php
php  includes/sections/clean-room/tests/sizes-test.php
php  includes/sections/clean-room/tests/box-shares-test.php
php  includes/sections/clean-room/tests/identify-test.php
php  includes/sections/clean-room/tests/medications-test.php
php  includes/sections/clean-room/tests/dose-test.php
php  includes/sections/clean-room/tests/dose-save-test.php
php  includes/sections/clean-room/tests/dose-phrases-test.php
php  includes/sections/clean-room/tests/medications-admin-test.php
php  includes/sections/clean-room/tests/temp-tanks-test.php
php  includes/sections/clean-room/tests/todo-test.php
php  includes/sections/clean-room/tests/temp-board-test.php
php  includes/sections/clean-room/tests/temp-guards-test.php
php  includes/sections/clean-room/tests/dose-temp-test.php
node includes/sections/clean-room/tests/front-desk.test.js
node includes/sections/clean-room/tests/clean-room-admin.test.js
node includes/sections/clean-room/tests/dose-math.test.js
node includes/sections/clean-room/tests/front-desk-dose.test.js
node includes/sections/clean-room/tests/medications-admin.test.js
node includes/sections/clean-room/tests/temp-tanks.test.js
```

Every species, price, supplier and figure in these tests is invented
("Blue Test Wrasse", "Test Supplier One", "Testland"); nothing comes
from the workbook but the formulas and the five treatment schedules. The
workbook the reader, mapper and import tests run on is **synthetic**:
`fixture-builder.php` writes a FisHotel-style .xlsx with invented names,
prices and dates in the real layout (header row 4 from column B, the
row-2 totals, the right-hand label block, a second unused "DOA" block,
`QT Tanks` logs in K:P, the Data sheet with vendors / origins / two
box-rate blocks, FishSheetStock, a `PW` sheet that must never be read)
with `xlsx-builder.php`, a tiny .xlsx writer of hand-written XML parts.
The CI job runs every `tests/*.php`, so both builders (and
`fake-store.php`, and `dose-helpers.php` — what the two dose tests
share) exit 0 when run directly. Temporary files go to the
system temp directory (`FH_TEST_TMP` overrides).

- **layout-test.php** — the seed (six buildings, 22 tanks, 100–400
  Clean Room, 500/600 Spa, 400 coming soon and empty), every seeded
  number obeying hundreds = building / tens = level / ones = position,
  uniqueness, the row contents and sizes; `tank_number()` /
  `parse_number()` edge cases; the shares-water lists (offline tanks
  left out); the phone title, the path, the printed label text; the
  editor's validation (round hundreds, taken numbers, level 1–2,
  position 1–9, derived duplicates named); rack ordering, grouping with
  an empty building, `tank_at()` for left / right / middle / second; the
  seed's system gallons (205 / 100 on the Spa buildings, none elsewhere),
  `system_gallons()` (the typed figure, else the sum of the building's
  tanks by id or by the hundreds, offline tanks dry, 0 = blank),
  `system_label()`, the schedule-name and gallons validation (the layout
  form's `Settings::gallons_input()`: "-205" and junk refused, never
  stored as 205 or blank).
- **replay-test.php** — `day_number()` across the March and November
  DST changes (and a whole year), `add_days()`, the suggested sale date
  (N days after check-in, only a hint), `sale_date()` / its source (own >
  batch > none, junk = none), `note_date()`, event dates in
  Chicago on both DST mornings; a stay's life (arrival → loss → shipped →
  recount → the first move into the Clean Room clears it → NOT for sale
  on the suggested date without a chosen one, for sale on its batch's
  date and not the day before, an old-shape hand-set cleared
  date, un-clearing, a `cleared:` note dated now that wins even to an
  EARLIER date and after an unset, an invalid note date); Spa-to-Spa
  moves never clear, a later move back keeps the date, no tank areas =
  no auto-clear, after a hand unset a Clean → Clean move does not
  re-clear but back through the Spa does, an unknown source tank clears;
  the own sale-date note (set and winning over the batch's, cleared back
  to none or to the batch's, invalid); the `checkin:` note
  (corrects, survives a later arrival, empty = back to the arrival,
  invalid ignored, its undo); closing at zero by loss / recount / shipped
  and re-opening on undo, the `overdrawn` flag when two phones record
  against one count and its clearing by an undo or a recount; splits (a
  move with a smaller quantity, its undo, a whole-count move = whole
  move); event ordering and the checkin fallback (the SITE date of an
  arrival); `effective()` on cached rows (no date = not for sale, the
  batch's date, an own date holding it back, a group still in the Spa
  past its date = ready and flagged, closed never); every compensating event,
  `is_undone()` / `undone_ids()` and two undo round trips.
- **protocol-test.php** — the seed (the workbook's 14 Day CM schedule as
  eight steps, site day = sheet day + 1 — no "observation ends" chip —,
  idempotent under `normalize()`), `is_due()` for daily /
  every-other-day / one-off / open-ended steps, `due()` per area,
  `normalize()` from a form (dropped, typed, clamped, capped at 60), the
  daily to-do of a tank (feed once, due steps per live stay, tick-offs by
  key, a copper reading logged today ticking the copper test by itself —
  and only a `test` step that names copper / Cu —, no live fish =
  nothing, the day derived from the check-in), `copper_state()`,
  `describe()`; the five named templates in order, every one starting
  with the daily feeding, and the days each translated step is due (14
  Day CF: formalin 5–14, praziquantel 7 / 12 / 14, the move 15; Xtra 14
  Day CF: formalin 1–14, praziquantel 6 / 11 / 13; CQ: chloroquine 3, 6 /
  8 / 10 / 12, 14, praziquantel 7 / 11 / 13, the bath 16; Trifecta: the
  combo day 1, Furan-2 the even days, the water change the odd days);
  the option's migration (an old bare steps array → the 14 Day CM
  template beside the other four seeds, an empty list kept empty, the
  new shape cleaned, an unknown default falling back, at most 20);
  `steps_for()` / `template_for()` (the building's template, else the
  default, through Settings when nothing is passed); the medication
  aliases (`medication_key()` on step labels and log notes, whole words
  — "Cumin" is not Cu —, the medication named first when several are),
  the dose suggestions (metro 5.1 g on 205 gal, 2.5
  on 100; two decimals below one gram; none for copper), `dose_text()`;
  the dose tick (a metro dose ticks Metronidazole, not the copper test,
  nor Copper Power when its note mentions Cu;
  the alias works through an import marker; a plain note ticks nothing;
  a copper dose ticks "Copper Power to 2.0", never the copper test);
  the Settings template operations (save, add — a seeded name brings its
  schedule back —, rename with the default following and the buildings
  told, a digits-only name renamed too, delete never the last one, make
  default, reset to the seed or to the daily feeding).
- **parser-test.php** — arrivals (Spa-only: a Clean Room tank, spoken or
  implied, needs a Spa tank), the loss family as the fastest path ("lost
  one cardinal in 521", "2 dead in 513", DOA / jumped / disease /
  unknown, "a couple"), shipped / sold, moves with a quantity and every
  destination phrasing, clear / count / note (on the tank) / copper
  ("521 copper 2.4", "two point four", "cu 2"), number words (a dozen,
  half a dozen, two dozen, twenty two, "four six line wrasse"), tank
  references (`tank_value()`, "tank 121", "one twenty one", "five
  twenty-one", "tank five two one", "one hundred and twenty one",
  "building one top left", "building five hundred bottom middle", "top
  second", no middle in a row of two, "top right" in the page's
  building), species matching (an alias wins outright, typos, size words,
  scientific and common names, "clown fish" joined, "clowns" ambiguous →
  three chips, an unknown fish kept as typed, occupants vs catalogue, two
  cardinal groups → chips, one occupant with no words), empty input, the
  12-word cap on species words (a 300-character run-on transcript against
  500 products), tokenising.
- **qr-test.php** — capacities (14 / 26 / 42 / 62 / 84 / 106 bytes),
  version choice, raw-module and codeword counts, alignment centres, the
  Reed–Solomon step against the published 1-M "HELLO WORLD" vector, the
  bit stream of "A", the three finders, separators, timing pattern, dark
  module and format bits (all eight published values) of a known symbol,
  the lowest-penalty mask, a module-for-module match with python-qrcode
  for the tank 521 URL, and the SVG (size, quiet zone, rect count, no
  escaping needed, too long → empty).
- **csv-test.php** — the formula guard on `= + - @ \t \r`, numbers,
  arrays; the 21-column row (the size and the "needs identifying" flag
  last) with guarded species / supplier / order ref
  and the building derived from the tank; `write_csv()`; the Board /
  Stays filters (the *For sale now* and *Needs identifying* flags) and
  totals (for sale, for sale but still in the Spa, without a sale date,
  to identify); the Events-tab wording for every event type
  (move reasons, split markers, the `cleared:`, `checkin:` and
  `sale_ready:` notes) and
  tank log kind, `move_reason()`; the Coming Soon release moment (09:00
  site time on both sides of DST).
- **front-desk-test.php** — the server guards behind the phone's one
  write action, through `Front_Desk::perform()` on the `Fake_Store`:
  arrivals refused from a Clean Room tank (page or `to_tank`) and checked
  in with `to_tank` naming a Spa tank, an unknown product never assumed,
  qty 0, a backdated arrival; moves to the same tank / an unknown tank / a
  stay elsewhere / more than are here refused, Spa → Clean "cleared
  today" with the reason in the toast and the event note, an unknown
  reason dropped, no re-clear after an unset, a split with its reason on
  both events, move all; the loss guard and the overdrawn flag when the
  other phone's loss lands between read and write (kept, flagged, the
  recount asked for, the duplicate's undo clearing it); undo refused for
  someone else's event and for one older than 15 minutes, honoured once
  with a compensating event, "Already undone" and nothing written on the
  same ids again, a whole move's undo sending the screen back; the
  cleared date typed (now + note, corrected to an earlier date, set again
  after an unset, today by default), the check-in date (future / junk
  refused, corrected, undone; the suggested sale date following it), an
  own sale date set (the toast, the note event) and removed with no batch
  date (the toast says where to set one), a junk date refused; a copper
  reading that ticks the copper test and a tick-off; the dose action (an
  unknown medication, an amount of 0 / below 0 / above 9999 / junk, a
  unit other than g / ml / tsp refused; the whole-system log on the
  building with tank 0 and the toast "Metronidazole 5.1 g · Building 500,
  whole system"; the tank-only log; the system dose ticking Metronidazole
  on every tank of the building but not the other system's, the tank
  dose on its tank alone; the last dose per medication per building; the
  Events-tab wording of dose, copper and building-level rows and the
  import marker kept out of sight; the medications offered with their
  suggestions); the label-login cookie life (a year for a crew member
  bound for the Front Desk, untouched otherwise); `facts_or_null()` and
  the facts filter answering null through a dead `$wpdb`.
- **costing-test.php** — the order sheet's maths: a box-mode example
  worked by hand in the comments (fractions, fees per box, the tariff
  factor, landed totals, per fish, the suggested price to the nearest
  5, profit / margin / worth-it, revenue, the rates, the sheet's DOA
  cost against the per-fish one), a line that is worth it, a bag-mode
  example, every zero / blank guard (no lines, nothing received, no
  units, everything DOA, survivors not typed → survival undefined,
  junk inputs), `round_to()` (MROUND halves away from zero), the money
  reader and the display helpers; a line's percent of a box (alone,
  mixed with per-box lines, zero / empty, a line that spans boxes, bag
  mode untouched, an order without percents unchanged — and every guess
  scaled alike giving the same costs), what a typed percent and box
  number read as, and the per-box summary with its states (under / about
  full 90–110 / over, the lines without a box number apart, the
  received lines still without a percent counted once some line has
  one) against `fixtures/box-maths.json`.
- **stats-test.php** — the year of an order; per-order rows over four
  invented orders (a bag-mode one dated by its order date, one that
  received nothing, one whose survivors are unknown); the weighted Σ/Σ
  totals (survival over the known orders only); the sheet-style
  averages (the empty batch out, the unknown batch out of the survival
  average, total fish through QT); by supplier / origin (merged
  case-insensitively) / species group ("(no group)" last); one year at
  a time. **Year by year** (`Stats::compare()`): the figures of each
  year, the change between consecutive years — a count and money as the
  difference with the percent where one is defined (never from zero), a
  rate in points, up / down in money only —, a supplier, origin or
  group with no order in a year (no figure there — null, never 0 — and
  no change into or out of it; a real 0 stays a 0), three years with
  one skipped, one year alone, and how the screen prints a figure and a
  change ("+2 (+200.0%)", "-44.0 pts", a dash). **Per species**
  (`Stats::by_species()`): the rows of a year and their estimates, the
  rows adding up to the year's weighted totals, which fish a line is (a
  list row by id or by its exact name, the unidentified ones as one
  row), the groups as "By year" files the lines (a line only typed with
  a species' name is in no group there, so a fish with linked and typed
  lines is two rows and every group adds up to "By year"'s), and the
  sort (numbers, text, empties last, stable) and the search the report
  views use.
- **stats-prices-test.php** — Prices to raise: which lines give a fish
  a row (received at a price; not a free, unknown or nameless one), the
  landed cost per fish of the latest order (by date, then id; two lines
  of one fish weighted) and the plain average of the last three, the
  suggested price — the rounding to the nearest 5 at the halves, the
  ORDER's markup, and equal to `Costing::compute()`'s line figure with
  tariffs, fees and a percent of a box —, the price source in order
  (the linked product; the variation of the size; a product without a
  price → the species list; neither → none, never 0), the gap, the
  margin and who is under (a price AT the suggestion is not), the
  selection (under only, biggest gap first; "show all"), the group and
  supplier filters, and on the fake store that the view writes nothing
  — not a species, order, product, price or option; the export sheet.
- **stats-sales-test.php** — Real sales over a FAKE of WooCommerce's
  order tables (`fake-store.php`: order stats, lookup rows, order items
  and their meta): the statuses list (the suggestion from the paid
  statuses and every registered status with "ship" in it, a saved list,
  no WooCommerce); a year read — statuses in and out of the list,
  refunds netted into their line and into the year of the order they
  refund, a refund without a line counted apart, a zero-quantity refund
  row, a whole-order refund stored under the order's line ids, the same
  ledger read three rows (and one row) at a time, a read that is cut
  off saying so; a variation rolled up to its species; invoice fish
  lines and invoices kept as one line (split when the lines add up,
  whole with the reason when not, the credit apart); the exact names
  (species name, the linked product's title, a learned alias — and what
  is no name); **not matched** by kind with units and money, and
  matched + not matched = every paid line; what another section may
  hand over about an order's invoices (`invoice_parts()`: every shape);
  the Invoices section absent, then present (asked only about orders
  with invoice lines, a line matched by its species id, a kept invoice
  split by the section's lines, an exception over there harmless, the
  filter before the function, an unknown species id not believed); the
  rows beside the landed cost (the profit, **no profit without a landed
  cost that year**, the earlier cost for reference, a fish that landed
  but did not sell, no estimate without a survivor count); the cache
  (one transient, a second view reading nothing, another year, a new
  species link showing without a re-read, dropped by an order's status
  change — every time —, not used for another list of statuses, a
  bounded number of years, another version ignored), Refresh and the
  Sales statuses form (capability, nonce, an empty or unknown list
  refused, save, reset — each dropping the cache), no WooCommerce
  tables, the export sheets (the CSV as ONE table that carries the "not
  matched" too, its first two sections adding up to every paid line),
  and **a read that fails** (a page of lines, the names of the lines,
  the refunds, the years: marked failed, never cached, read again on
  the next view, the sheets ending with INCOMPLETE).
- **stats-admin-test.php** — the Stats tab's further views on the fake
  store: which view a request asks for, the years of a comparison (the
  last two with orders by default, one picked → the default and a
  notice, at most six), the comparison as sheets (a row per year, the
  change and change-% rows, empty cells for a missing year, the one
  table of the CSV, written as CSV), the Species view's year (the
  latest, or 0 = all, kept in its export link), search, sort and
  heading links, its sheet, the export switch (every "what", every
  sheet built into an .xlsx); and the SCREENS, rendered through
  `Admin_Workbook::render()` with a PHP notice turned into a failure:
  the strip of views, which numbers each view calls an estimate and
  which actual, a dash in every cell of a supplier's missing year and
  of the change next to it, a change in points, the sorted heading, a
  name with markup escaped, Prices' read-only page without a form that
  posts, Real sales' statuses, profit, "no landed cost in …", the not
  matched lists, "How it adds up", the Refresh form and — without
  WooCommerce's tables — the plain notice; the Settings card. Then:
  **past the newest 500 orders** (600 orders over twelve years on a store that hands out 500 a read,
  as the real one does) Compare years offers and shows the oldest years
  as "By year" does, Real sales has the whole year's landed cost, and
  Prices to raise says on how many orders it rests; the Species rows of
  a group add up to that group on "By year" when lines were typed, not
  linked; the pointer to the theme's "Add fish lines to past invoice
  orders" tool (only when that theme is there); and the notice of a
  reading that failed.
- **species-test.php** — a form row normalised (whitespace, per box,
  money, dates, reef-safe, product); the candidate merge (a species row
  folding into a same-named or linked product, a duplicate dropped, a
  species of its own, one linked outside the fish list); on the fake
  store: save with the unique name, prefix search on name / scientific
  name / any word, the group filter and paging, by name / by product;
  the search-box suggestions (products first, species rows folded in or
  appended, the Front Desk item shape intact); the parser finding a
  species that is not a product and a product by its row's scientific
  name; the product link, the open-stays counts, delete-or-refuse.
- **orders-test.php** — on the fake store: the header and lines from a
  form (typed, defaulted, empty species dropped); create with the next
  "YYYY-N" ref, a duplicate ref refused; save lines (upsert by id,
  delete of the rest, a foreign id inserted anew); the check-in (one
  stay per line with fish, the arrival into the first empty Spa tank of
  the building, the DOA loss noted `doa`, every seam field, the landed
  cost per fish to the cent, the order turned received); a second
  check-in creating nothing; the live figures from the stay's events
  (losses of every reason, shipped, DOA with its undo) read back into
  the line and the costing; a linked line keeping its figures and
  surviving a re-save that leaves it out; the tank fallback (own tank,
  a Clean Room tank ignored, the building's empty tanks, any empty
  tank, the first tank of the building, ten lines across the Spa), a
  received date in the past (noon that day) or the future (clamped);
  lists, years, refs, pick lists, deleting a draft.
- **xlsx-test.php** — column letters, legal unique sheet names (31
  characters, forbidden characters, duplicates); every cell kind (null,
  int, float, bool, text as an inline string, a numeric string kept as
  text, escaping, control characters stripped, padding preserved, a
  DateTime, NaN); a workbook built, written, opened with ZipArchive and
  parsed with SimpleXML: the parts, the sheet names, every cell read
  back, "=SUM(A1)" still a string, no formula element anywhere.
- **workbook-admin-test.php** — the box rates (sanitised, deduped,
  matched, the import-fees suggestion), the order editor's Save path on
  the fake store (the next ref, a duplicate refused, a Clean Room
  building dropped, the figures as the display strings of the worked
  example, the landed-total cache), the check-in read back through the
  figures, the export sheets (orders + lines, species, stats, stays)
  with typed cells, the CSV guard, the URLs and the one-time notice.
- **workbook-test.php** — the reader on hand-written XML parts zipped into
  a tiny .xlsx: shared, inline and rich-text strings, cached formula
  values (numbers, strings, errors; a formula without one absent),
  booleans, date / time / datetime styles (built-in and custom), a
  currency format staying a number, `rows()` with a row cap on a cold and
  a warm cache, `cell()` with anchors, a sheet name with spaces and
  parentheses, the `PW` sheet refused by name / case / whitespace, cells
  and rows without `r` attributes, phonetic runs skipped, `close()`; the
  failure modes (missing, not a zip, no workbook.xml, a directory), the
  1904 date system, the zip-bomb guard (a part over the limit, a part whose
  zip directory lies about its size — damaged, or refused at the limit —,
  the value cap on a sheet and on the shared strings), external entities
  never resolved in a sheet or the shared strings, extra refused names,
  every static helper (column letters, serials around the 1900 leap-day phantom,
  format kinds).
- **import-map-test.php** — the mapper on the synthetic fixture: only the
  known sheets opened (never `PW`), the species rows until the first blank
  name (error cells null, a typed date read), the three batches mapped
  and the template / empty one skipped, the header row found by text in
  both layouts (an extra column, the right-hand block shifted), box and
  bag mode from the headers, the row-2 fees (a blank left null, not the
  next label), supplier / origin / credit by label, the QT start date,
  schedule and log (a tank label skipped, text amounts and times kept,
  "Both"), the file-name year for a batch without a QT date, suppliers /
  origins / the two box-rate blocks, the stock rows, the ten warnings and
  none mentioning `PW`, `read_qt()` alone, an explicit default year, every
  value helper, a closed workbook.
- **import-test.php** — the import on the fixture and the `Fake_Store`:
  the markers; the pure preview (an empty site, what exists updating or
  skipping, a name twice, a rate without a supplier, the stays estimate
  per cutoff); the species upsert (every field, the stock enrichment by
  scientific then common name, "With Caution" → caution, an error-cell
  price null); the box rates in the option; the three orders (an old
  batch closed with its fees, date, schedule and building, the recent one
  received in bag mode, the undated one ordered) and their lines (species
  ids by name, an unknown fish without, the landed totals cached); the
  recent batch's stays (the DOA loss, source `import` on the stay and its
  events, the seam fields, the first empty tank of the chosen building;
  no sale date invented — the preview and the result count the groups
  without one and name the order, an order already dated is left out,
  a dated order counts none);
  the logs by system (205 → 500, 100 → 600, "Both" → both, the copper
  reading, the result appended, Chicago times to UTC) and the Board's last
  doses from them; the second run (species updated, nothing else created,
  a price the sheet lacks kept, a group typed on the site kept, the
  sheet's price winning, a per box of 0 never replacing the site's); the options (no stays / no logs, a long cutoff
  on the other building, a Clean Room building ignored); the system →
  building rule in every branch; `log_entry()` for a dose, "Added X", an
  unknown medication, a note, a copper test, no date, a note too long for
  the Store cut before its marker (`cut_bytes()`); the amount, date and
  time helpers; the admin panel's pure helpers (the options sanitised and
  defaulted, the default building, the summary lines, the `.htaccess`, the
  random file name, `check_filetype()` — an .xlsm that fileinfo calls an
  .xlsx accepted, a renamed PHP file still refused —, `workbook_year()`).
- **sale-dates-test.php** — a group goes on sale on a CHOSEN date, on the
  fake store through a real check-in: the precedence (none → the batch's
  date on the order → a group's own date wins → removed back to the
  batch's → the batch's cleared back to none; a phone-only group has no
  batch), a split-off group keeping its batch; the boundary (the day
  before / the date itself / after; 23:30 site time on the eve is not yet
  even though UTC has moved on, five past midnight is); closed stays
  never for sale (and left out of the *For sale now* filter); the "still
  in the Spa" warning on the badge, the Front Desk occupant, the Board
  totals and `sale_words()`; the Replay history unchanged by a batch date
  (every history field identical, only the sale fields differ, the undo
  of a sale-date note unchanged); the schema (nine tables, `sale_date
  DATE NULL,` after `received_date`, version 1.3, `hydrate_order()`); the
  product facts with null dates (dated before undated, ready_total for a
  group still in the Spa, closed skipped); the Front Desk's toast in
  every case and through `perform()`, its strings; the batch calendar's
  `sale_date_request()` (no capability, a wrong or another order's nonce,
  a missing order, a valid date with its `fishotel_log` entry naming who
  and old → new, unchanged = nothing written or logged, five invalid
  dates refused, a change, Clear, Clear with nothing set), the order
  editor's Save keeping the date, the order card / its status line /
  the suggestion / the Arrivals "On sale" cell / the orders export; the
  product page's `save_meta_box()` (no nonce field = no work, another
  product's nonce, no edit_post, no section capability, untouched pickers
  — the batch's date never copied —, the second save_post of the same
  request, one changed picker = one event with the actor, the success
  notice printed once, "Use the batch date", an invalid date ignored and
  named, a two-digit year (0026) and the day after two years from today
  refused, an emptied picker, an emptied picker that showed the batch's
  date reported with its supplier order, a group without an own date, a
  closed group, a failing store caught and logged, an autosave); the
  date bounds (`sale_date_max()` with Feb 29, `is_sale_date_in_range()`,
  `own_sale_date_check()`), the batch calendar refusing dates outside
  them, the phone writing nothing for an unchanged date and refusing a
  far one, the Stays tab prompt's default (`sale_prompt_default()`: empty
  when the batch has a date), "ready (still in the Spa)" in the CSV and
  XLSX state column, the order card's Spa count and own-date flag, and
  the Board's building line counting as its summary does.
- **sizes-test.php** — the one list of fish sizes: what counts as a
  size (an attribute key matching "size"), slugs and a typed list, an
  attribute's terms in the order the attribute is set to (custom, name,
  numeric name, id), where the list comes from (one attribute, several
  with and without a pick, a pick that no longer exists, none + the
  plain list, an attribute without terms), a picked size and a stored
  size that left the list (still shown, flagged, selectable on its own
  row; any other unknown slug refused), the read-only "Sizes in use on
  products" report, the settings keys and the Settings → Sizes save
  (capability, nonce, invalid input, the Numbers form never wiping
  them), the size carried line → stay → sister stay, the Stays tab's
  Edit, the Front Desk arrival and rows, a term slug with
  percent-encoded characters kept whole, the voice parser still ignoring
  size words, the exports, hydration and the schema.
- **box-shares-test.php** — the percent of a box on the order page and
  what the site remembers of it: the schema (the lines' columns, the
  `fh_box_shares` table, the version gate that stays shut while an added
  column is missing), a line's box number and percent from a form and
  the save refused on a mistyped one (nothing written), the Boxes card
  (chips, total, against the fixture — and the received lines still
  without a percent counted, with the half-filled order worked by hand
  that shows why), what is remembered (percent ÷
  received per supplier + origin + fish + size; only box-mode lines with
  received fish and an identified fish), the count going up once per
  order line, the latest order winning (an older order never overwrites
  a newer guess), the releases (a line that changes fish / size, loses
  its percent, is removed, or whose order is deleted; a share at zero
  deleted), the suggestion (per fish × received, else × ordered; never
  for a line with a percent) and "How full", a fish picked from the
  species list on one order and typed on the next (one share, one count
  per line, a suggestion either way round), the Settings → Box shares
  handler (capability, nonce, invalid input, change, forget — and a
  typed value that yields only to an order dated after it, not to that
  day's order saved again), the exports.
- **identify-test.php** — unknown and mislabeled fish and the one
  correction: the "??" name against `fixtures/unknown-names.json`, the
  schema (needs_id on lines and stays, label_name, version 1.5, the
  gate), an unknown fish on the order form (flagged, named, costed
  exactly like a known fish with the same numbers, never remembered as
  a box share), check-in and a split (as unknown as the line), the fish
  a correction names (a product, a species-list fish typed any which
  way, a typed name, still unknown, a product that does not exist, a
  size refused; the fish the row already is, typed again, keeping its
  product link), the correction itself (stay + line together, the note
  event, the share written after, the same again changing nothing), the
  label (set once, never overwritten, kept through "marked unknown",
  not mislabeled when corrected back; a fish that arrived unknown
  keeping its "??" name in the label's place, so a second correction is
  no mislabel — shown and counted nowhere),
  the split rule (only the stay changes and the line gets its "part of
  this line" note; the last sister agreeing makes the line follow; a
  correction from the order line leaves a sister that is something
  else), the order page's "Correct the fish…" (a line not on the order,
  nothing arrived, no fish, a bad size; a received line not checked in)
  and its grid (a checked-in line's species / size / "??" box follow to
  the stay, a line without a stay records no label unless it is ticked
  "??" after fish arrived, notes kept), the Stays tab (Identify…
  refusing bad input, Edit following the same rules, a size alone
  writing no event), the Front Desk (an unknown arrival, identify on a
  group's sheet with no Undo, a group of another tank refused), flags
  and filters, the mislabel stats (by supplier, by origin, totals, an
  undefined rate), the exports, the importer flagging nothing, the
  capability and the nonce in front of the three AJAX endpoints the new
  action rides on (called for real, up to their reply) — and the page
  loaded before a correction: the token a row is rendered with, an order
  page saved after the phone identified its fish (the lines, stays,
  events, label, shares and mislabel count stand; the price typed is
  saved; the reply counts the lines and hands a kept line its stale
  token back, so Save pressed twice keeps the correction twice), a fish
  typed on a stale row named as not saved, an up-to-date page still
  correcting — twice in a row, with the tokens of its own save —, a line
  corrected in another tab, and the Stays tab's Edit
  (the other fields saved and the fish left; refused with 409 when the
  fish was changed on the stale row).
- **temp-tanks-test.php** — temporary tanks: the slot numbering
  (`slot_number()` / `parse_slot()`, 901–909, a rack number never a
  slot) and the labels ("TEMP 1" large, "901 · Temporary · …" small,
  the same Front Desk URL, the path, the title of a free slot); setup
  validation (the kind asked and never assumed, the first free slot or
  a named one, a slot in use or retired, gallons, an unknown template,
  the start date and its range, every slot in use, a tenth slot); what
  it is for → the templates carrying that word (only Trifecta seeded,
  any case, two templates for one word, nothing for a word nobody
  carries, saved / removed / renamed words) and the preview before
  anything is saved; the copied schedule independent of its template in
  both directions (the template edited afterwards, the tank's own steps
  moved / added / removed / replaced); the tank's own clock (day 1 = the
  treatment start, "starts <date>", a fish's own day and dates
  untouched); quarantine versus clean for arrivals,
  moves and clearing — into a clean temporary tank, out of a quarantine
  one into the Clean Room, a split, the same slot clean this month and
  quarantine the next; readings and doses as the tank's own water; take
  down (refused with fish, the history kept — the treatment as it was
  run, doses, readings, the groups that passed through — and the next
  setup in the slot starting clean); the 1.6 schema and its gate from
  1.3, 1.4 and 1.5 (each missing column, a failed ALTER, the `temp`
  ENUM value); a layout that never sets one up reading exactly as one
  without the building.
- **todo-test.php** — the day's list by water system: one line per step
  per building however many tanks have fish; each line's medication,
  dose and note; groups on different days ("for the day 2 group"); one
  truth — ticked on the list, on one tank's own screen, by a copper
  reading on any tank of the building, by a dose on the system or into
  one tank — and every tank of the building then showing it; the
  feeding as one line, tank by tank or all at once; the count at the
  top, "all done", an empty room; the copper flags (low, due, overdue,
  none for a tank without a copper test); a temporary tank beside the
  buildings (its own lines, marked, not ticked by a building's dose);
  the guards of a tick; the pool, the overlay, `spread_logs()` and
  `merge_copper()` for a slot; the list on the phone's screens.
- **temp-board-test.php** — the Board's side and the seams: the one
  AJAX write (`Admin_Temp::request()`: 403 without the capability or
  with a stale nonce, 400 for an act that is not a temporary-tank or
  to-do act or one that is refused, set up / edit / schedule / take
  down / tick by the posting user); the Temporary area as data (slots,
  the set-up form's free slots, words and previews, a setup's own last
  dose, a layout without the building); a slot with nothing set up
  taking no reading, dose, tick or note; Settings → Layout's slots (one
  more, nine at most, retiring a free one, never one that is set up);
  "Used for" saved, renamed and deleted with its template; the list's
  lines in words against `fixtures/todo-lines.json`; a temporary tank
  spoken of by its number (an arrival into a quarantine one taken as
  said, a clean one or a free slot asking for a quarantine tank, a move,
  a reading); the count other
  screens read — `Todo::summary()`, the `fishotel_cleanroom_todo`
  filter and function (null without the tables, never an exception)
  and the Today section's line (`today_line()`, never twice, none on a
  day with nothing due or for a user without the capability).
- **temp-guards-test.php** — the guards around the temporary tanks and
  the list: no group comes back to life in a slot whose setup is gone
  (the undo of a loss and a Count refused once it was taken down, and
  in the next setup too; `Store::slot_revival()`, a note still taken,
  the sentence the Stays tab shows); an undo does not reach into a
  setup that was taken down (`Store::setup_gone()`; refused with the
  slot free and with it set up again, the group neither moved nor
  cleared; fine while the setup stands); a building that is *coming
  soon* or *offline* but has fish keeps its block, the Temporary
  building cannot leave *active* under a running tank, and a running
  tank is listed whatever its building says; a step with "<" in its
  name is stored as plain text and can be ticked on the list and on the
  tank (`Temp_Tanks::step_text()`, `Todo::posted_key()`, against a
  `sanitize_text_field()` stub that turns a lone "<" into an entity as
  WordPress does); a page loaded before the slot changed hands, or
  before the same setup changed on another screen, writes nothing
  (`setup_id` required, `Temp_Tanks::seen()`); a dose step naming
  several medications is not done by one of them — on a temporary tank,
  on a building's list and on each of its tanks' screens — while a
  step naming one still is (`Todo::combo()`, `part_dosed()`,
  `settle_combos()`); and `fishotel_cleanroom_todo()` answering null
  with no query while the section is off.
- **fake-store.php** — not a test (exits 0 when run directly): the
  in-memory store the above drives (tanks and buildings from the seed,
  stays — joined with their batch's order and sale date like the real
  `stay_select()`, `stays_for_product()`, `stays_for_line()`,
  `product_facts()`, `batch_sale_counts()` —, events, logs — per tank or
  per building, with `logs_on_date()`, `latest_doses()`,
  `imported_log_keys()` —, aliases, species rows, orders and lines with
  `update_line_fields()`, the remembered box shares, and what the real
  Store reads from WooCommerce for the sizes, seeded by a test). It also
  fakes WooCommerce's order tables for Real sales — `sale()`, `refund()`
  and `full_refund()` seed the order stats, the lookup rows and the order
  items with their meta; `sales_rows()` joins them as the real query does
  (a refund row carries its parent's status and date and the line it
  refunds; pages by line id, then order id) but hands over the rows of
  EVERY status, so the tests prove that `Stats_Sales::add_rows()` is what
  keeps the unpaid ones out — and the products' current prices. Like the
  real store, `orders()` hands out the newest 500 at most
  (`ORDERS_LIMIT`), and `$sales_break` makes one sales read fail the way
  a database error does (it answers null).
- **xlsx-builder.php**, **fixture-builder.php** — not tests (exit 0 when
  run directly): the tiny .xlsx writer and the synthetic FisHotel-style
  workbook the reader, mapper and import tests use.
- **front-desk.test.js** — `englishValue()`, `tankValue()`,
  `normalizeNumberWords()` (known tanks, the rule without a list, "four
  six line wrasse" left alone, "a couple" / "two point four" left for the
  server), `previewSentence()` for every intent including the Spa-tank
  ask and the "Which fish?" prefix, `fmt()`, `escapeHtml()`,
  `saleSummary()` (a batch date, an own date, none with the suggestion,
  the "still in the Spa" warning, the page's strings), `sizeText()`,
  `unknownName()` against `fixtures/unknown-names.json`.
- **clean-room-admin.test.js** — `renumber()` with a prefix and a
  template row's `__i__`, `suggestImportFees()` (the exact pair, the
  origin-less fallback, nulls, cents); the order page's box helpers
  against `fixtures/box-maths.json` (a typed percent and box number, the
  per-box summary with the received lines still without a percent, its
  chips and total, the remembered share of a line — by its id, its name,
  or the name of a share kept under an id —, its suggestion and its
  text, "How full"); `unknownName()` /
  `knownName()` (the "??" box of an order line) against
  `fixtures/unknown-names.json`.
- **temp-tanks.test.js** — the phone's and the Board's pure helpers for
  the temporary tanks and the day's list, both scripts loaded without a
  DOM: `tankHeading()`, `offerTemplates()` / `offer()` and
  `lineDetail()`, `feedText()`, `flagText()` against
  `fixtures/todo-lines.json` (the file the server's temp-board-test.php
  reads), `previewLines()` on the phone and on the Board,
  `todoHeadline()`, `moveClears()`, `gridTanks()` (a free slot is no
  tank to pick), `stepsFromRows()` (the Board's schedule editor).

- **medications-test.php** — the editable medication list: the seed IS
  the old fixed list (the same keys in the same order, names, chips, the
  same names to answer to, the five rates — 25 mg, 9.5 mg, 1 ml per
  10 gal, 10 mg, 1 g per 100 gal — equal to `Protocol::DOSE_RATES`,
  copper to a level at 0.692 ml per gallon per 1.0 ppm, Salt typed,
  Chloroquine's three named doses equal to the seeded CQ schedule's
  rates); a medication by its names (whole words, the first named, the
  longer name at the same place, a log note with its working, a retired
  one still found); the lenient reader of what is stored (drops, caps, a
  bad rate makes it typed, a measure only in a unit of the same kind);
  Settings → Medications' operations — add (the key from the name, never
  digits only, nineteen refusals each naming what is wrong, a name
  another medication answers to, the cap), edit (the key and the place
  stay, a changed name or chip is kept as an other name so an old log
  still reads, the copper row re-rated stays the copper product and made
  typed is none), retire and bring back (still read in logs, its names
  still taken); where the list is kept (the settings option, the seed
  until edited, the Numbers and Sizes saves keep it, no stale copy within
  a request, junk reads as the seed); that `Protocol::medication_key()` /
  `medication_label()` / `dose_text()` / `dose_suggestion()`, the to-do
  tick and the store's "last dose" follow an edited list; and the
  store's public calculator's copper figure — present and the same,
  ours edited, ours typed for 10 gallons, absent, no entry of that name,
  another unit, junk in the data, through the filter, a filter that
  throws, the theme's own class.
- **dose-test.php** — `Dose` against `fixtures/dose-maths.json` (the
  file the page's JS is run against too): `round_amount()` (16.608 →
  16.6, 5.125 → 5.1, 1.15 → 1.2 although binary floating point holds it
  a breath low, 0.004 stays 0.004), `display()` (2,500 mg → 2.5 g,
  950 mg → 0.95 g, 99.9 mg stays mg, 999.96 mg → 1 g), the words (levels, rates, a
  measure's plural), and 51 sums — the copper example (100 gal, 2.16 →
  2.40 ppm = 16.6 ml), 100 gallons of Metronidazole (2.5 g), a rate for
  10 or 100 gallons, a rate typed in grams, a named dose, a schedule
  step's rate, the top-up in percent and in gallons (with and without a
  system volume, missing, too much), zero and negative differences, a
  missing or unreadable reading, unknown gallons, a measure, pieces /
  drops / teaspoons, three-decimal levels, typed — each with its state,
  amount, unit, result line, working and note; that the PHP words are
  the JS file's English; that the seeded list gives exactly the fixed list's
  amount AND unit on every water from 1 to 4000 gal (milligrams only
  under 0.1 g); what a schedule step names
  (`step_rate()`, `step_level()`); what is due today per medication
  (day 1, day 2, a day with no copper step, two groups on different days
  — the lower first —, the CQ rates, the first medication a step names);
  today's copper reading (from today with its site time, from yesterday,
  none, the site day rather than the UTC day); the doses logged today
  per water (`doses_today()`: the system's and each tank's, the latest
  of several, not yesterday's, not the other building's, and for copper
  whether it went in after the reading — "Now" is then left empty);
  what "Now" and "Bring it to" start with (`defaults()`); the two waters of a tank's page against
  the in-memory store (a reading on another tank of the building reads
  the system, not this tank; a building reading reads both; the due
  steps of the system and of the tank); what a Save's post turns into
  (`args_from_request()`, `working_note()`: the gallons never from the
  post, a named dose only when it is one, no note without the sum);
  `save_reading()` (logged on the building or the tank, not again when
  it is today's latest, a failing store); and the Board's "last dose"
  text.
- **dose-save-test.php** — the Save through `Front_Desk::perform()`: a
  plain typed dose logs exactly what it always did; with the sum ONE
  dose log whose note is the working (per gallon on the system's 100 or
  205 gal and on the tank's 40, a named dose, a schedule step's rate,
  the top-up in percent and gallons, a typed medication after a water
  change), the gallons the store's whatever is posted, the typed amount
  what is logged; the copper example on the 100 gallon system — the
  reading 2.16 and the dose with its working, each once, the toast
  saying both, the day's "Copper Power to 2.4" and the copper test
  ticked in every tank of the water; saved again with the same Now, or
  when the page's tank has that reading itself: no second reading; a
  reading on the sibling tank 611 fills "Now" for the system but the
  whole-system Save still logs it on the building (621's copper test is
  ticked), once; another Now, this tank only, a reading two days old:
  logged; nothing to add but typed anyway; because a dose has no undo —
  the same post again from a sheet that knew of no dose is refused with
  nothing written and saving again from the refreshed sheet goes
  through (another medication, a tank's water after a system dose, a
  post with no `seen`), "Now" 216 or "Bring it to" 240 refused like a
  plain reading of 216, "Bring it to" 24 asked about first and written
  with `sure`, and at or above the target with no amount the reading
  alone (once; on the tank; never below the target or for another
  medication); a Now that is no number; unknown gallons; a medication added
  in Settings (to a level: no copper reading), a retired one refused and
  not offered; a store whose dose log fails; the Events line and the
  Board's last dose afterwards; and that every label front-desk.js asks
  for is sent.
- **dose-phrases-test.php** — the dose phrases: "copper 2.16 to 2.4"
  and its relatives ("bring my copper up from 2.16 to 2.4", "cu 2.16 to
  2.4", in words, with "ppm"), the target said first ("raise copper to
  2.4 from 2.16" — never a reading of 2.4), the sentence as it was asked
  for ("I need to bring my copper up from 2.16 to 2.4 in the 100 gallon
  system"; "521 needs copper 2.16" stays a reading), "raise / bump / top
  up copper to 2.4",
  "dose copper", "dose metro", "metro for the system", "prazi this
  tank", a name alone, a named dose ("cq loading"), a spoken tank, "the
  100 gallon system" (the building that holds that much; none or two of
  that size ask which tank), a tank that is not in the layout, a dose
  spoken with its amount ("metro 5 g", "copper power 55 ml", "metro 212
  mg"); that a reading is still a reading ("521 copper 2.16", "copper
  2.4", "copper to 2.4" …) and a phrase with anything but dose words is
  read as before; the sentences against `fixtures/dose-phrases.json`;
  then the preview (`Front_Desk::dose_preview()`): the result line, the
  working, the water, Now from today's reading or asked for (also after
  a copper dose — the same sum is not offered again, and a preview from
  before that Save is refused on Confirm), Bring it to
  from the due step (day 1, day 2) or the copper target, the tank alone,
  nothing to add, a named dose, the CQ step's rate, a spoken amount, a
  typed medication, an unknown or offline tank, unknown gallons — and
  Confirm's post through the same Save.
- **medications-admin-test.php** — Settings → Medications' save
  (`Medications_Admin::request()`): the crew without `manage_options`,
  nobody, a wrong or missing nonce (403), an unknown operation and the
  list's refusals (400) — nothing stored each time; add / edit / retire
  / bring back stored in the settings option with every other setting
  untouched; two tabs — a Save from a form drawn before another tab's
  change is refused and stores nothing, a reloaded one saves, and the
  edit form (not the Add form) carries the row's fingerprint; the real `wp_ajax` handler up to its reply; what the panel
  shows (how each is dosed in words, named doses, the measure, "the
  built-in list, unchanged"); the public calculator's figure beside
  ours in each state, absent said plainly; what the Board's Calculator
  is handed (the medications offered, each building's water, a store
  that cannot be read); every label the script asks for is sent.
- **dose-math.test.js** — `js/dose-math.js` against
  `fixtures/dose-maths.json`: the same tables and the same 51 sums as
  dose-test.php, plus the page's own spelling of the inputs and
  translated words.
- **front-desk-dose.test.js** — the Dose sheet, the Calculator and a
  dose phrase's preview run for real: `js/front-desk.js` started against
  a small stand-in DOM and a `fetch` that records what is posted. "Now"
  filled with today's reading and its time, "Bring it to" with the due
  step's level; the result line and its working; the amount filled and
  editable ("Use the result"); typing re-runs the sum in place; nothing
  to add; a missing reading; the water (this tank alone has no reading
  of its own); a single rate said, several as chips, the due step's
  rate picked and marked, a named dose; the Top-up switch in percent
  and gallons, too much refused; a measure; a typed medication; another
  medication dosed to a level; unknown gallons; "a dose cannot be
  undone" and "saving also logs the copper reading" before Save; Save
  posts ONE request with the amount as it stands and the sum's inputs;
  the Calculator posts nothing, with a tank, without one, with no
  medication offered; the phrase preview is never auto-confirmed, asks
  for a missing reading and runs the sum on the page, Confirm posts the
  Save by voice, a spoken amount posts no sum, *Open the Dose sheet*
  carries what was said, an unknown tank asks the server again; and
  "Already logged today: …" on the sheet and the preview — per
  medication and water, with "read the level again" only for copper
  dosed after the reading, "Now" then empty and asked for; and because a
  dose has no undo: Save tapped three times before the reply posts ONE
  request, a refusal that carries the screen redraws the open sheet (and
  the preview) with the dose that went in and saving again posts its
  time, "Bring it to" 24 is asked about and posted with `sure` only when
  saved again as it stands, and at or above the target Save posts the
  levels with no amount (the reading alone) — never for another
  medication; and on a temporary tank: the sheet shows this tank only
  ("Whole system" hidden) with the setup's gallons, the sum runs on its
  own reading and its own schedule's level, Save posts `whole` 0; the
  tank picker carries the day's list and the Calculator side by side,
  and the Calculator opens without a tank and from its own address.
- **dose-temp-test.php** — the dose calculator on a temporary tank,
  through `Dose::waters()`, `Front_Desk::screen_from()` and
  `Front_Desk::perform()` on the `Fake_Store`: a slot's waters are its
  own (`own_water`; no system gallons, readings, doses or due steps; the
  setup's gallons; the dose steps of the setup's OWN schedule on its OWN
  day, with no fish in it), a rack tank's are unchanged; the screen
  carries the `dose` block, the medication list, the day's list and the
  setup sheet together; a free slot has no water; a Save posted as
  "whole system" is filed on the tank with the working from the setup's
  gallons and the copper reading, both stamped with the setup; the
  stale-sheet refusal works there; the dose and the reading tick the
  tank's own to-do and no rack tank sees them; a spoken "whole system"
  is that tank; the Temporary area's "last dose" carries the working;
  and a new setup in the same slot starts with nothing read, dosed or
  due.
- **medications-admin.test.js** — the pure parts of
  `js/medications-admin.js`: what 100 gallons take at a rate (the
  editor's hint), the rates the Board's Calculator offers, its sum.

## By hand, once, on a real site

1. FisHotel Tools → Dashboard → enable **Clean Room**. The six buildings
   and 22 tanks must appear on the Board; the Stays, Events and Settings
   tabs must open. Reload any admin page with the query monitor: the
   section runs no query of its own on an ordinary request.
2. Settings → *Print tank labels*: a sheet of QR labels, one per tank.
   Scan 521's with a phone: the login page (if logged out) and then the
   Tank 521 screen with the title "Tank 521 · Spa · Building 500 · 20G
   long · shares water with 511, 512, 513, 522, 523, 524". Close the
   browser and scan again three days later: still signed in (the label
   login's cookie lasts about a year).
3. Arrival → type "paj": suggestions from the quarantined-fish products;
   pick one, qty 4, Save. The row shows 4 live · in QT · day 1, above the
   folded "Today · N to do ▸" line; the toast stays until the next tap (at
   least 10 s) and its Undo removes the arrival (a compensating event,
   visible on the Events tab); tapping Undo twice changes nothing more.
4. Tap "−1 lost" on the row (the first button under the title): 3 live at
   once, Undo works. Mic → "lost one cardinal": the preview reads "1 × …
   lost in Tank 521" and confirms after the countdown.
5. Occupant → Dates: set the check-in date to yesterday first (the daily
   copper test starts on day 2). Mic → "521 copper 2.4": tap the "Today"
   line — it opens on Cu 2.4 and the *Copper test* chip is already
   ticked; it stays open after Refresh.
   Tap another to-do chip: it ticks and stays ticked after Refresh.
6. Move (with one group in the tank it goes straight to "Where to?") →
   chip *bullying* → Tank 121: the toast reads "… moved to Tank 121 ·
   cleared today · bullying", the Board shows it in 121 as cleared with the
   cleared date = today and "Sale date not set" (no date chosen yet), and
   the Events tab shows "moved Tank 521 → Tank 121 (bullying)". From 121,
   Arrival must offer only Spa tanks; a second Move 121 → 122 does not
   say "cleared".
7. Occupant → Dates: set the cleared date to yesterday, then to the day
   before — each sticks (the Stays tab agrees); *Not cleared*, then a date
   again — it sticks. Set the check-in date a week back: the day number
   and the "suggested" sale date move with it.
8. Stays tab: Lost / Shipped / Count / Cleared… / Sale-ready… / Move…
   reload with the new numbers (Sale date… opens pre-filled with the
   suggestion); Edit → Check-in writes a `checkin:` note;
   Export CSV opens in a spreadsheet with no formula execution; on a stay
   linked to a product and with a sale date, *Coming Soon* sets the
   product's `_fh_release_datetime` to that date at 09:00. Events tab:
   Undo on a loss row writes the compensating event and the row then says
   "undone"; the Board card lists what is still due today under the
   to-do count.
9. On that product's edit screen, the *Clean room* box lists the stays;
   `fishotel_cleanroom_product_facts( $id )` (e.g. from a mu-plugin)
   returns the same numbers — and `null` for a product with no stay.
10. Species tab → Add species: a name, a scientific name, per box 8, a
    price; it lists with 0 open stays. Type the first letters in the
    search box: it is found by name and by scientific name. *Link
    product* → type a product title → pick it: the row shows the product
    link. Front Desk → Arrival → type the species' name: it is offered
    once (not twice when its name is a product's title).
11. Arrivals → New order: supplier, origin, box mode, tariffs / flight /
    import fees; two lines picked from the suggestions (the worth
    prefilled from the species' price), ordered / received / price;
    Save: the ref reads `2026-1`, the computed columns and the stats
    block fill in without a reload, and the numbers match the workbook
    for the same inputs (the box fraction, fees per box, landed, per
    fish, suggested price to the nearest 5, margin, worth?, fill /
    survival / DOA rates, the two DOA costs). Settings → Box rates →
    add a row for that supplier / origin; back on the order, Boxes 2 →
    *Suggest* fills the import fees with 2 × (per box + re-ox).
12. *Check in received fish* → confirm: the alert names the stays and
    tanks; the lines now show "stay #N", live / survived / sold, and the
    Board shows the groups in the Spa with supplier / ref / landed cost
    on the Stays tab. Click it again: "Nothing to check in". Record a
    loss on one of them from the phone: the line's survived drops.
13. Stats: the year's orders, the weighted card and the "as the
    spreadsheet averaged them" card (the latter the unweighted average
    of the batch rates), by supplier / origin / species group. Export
    XLSX on Arrivals, Species, Stats and Stays: Excel opens each
    without a repair prompt, a species named "=1+1" shows as text.
14. Settings → Treatment schedules: the picker lists 14 Day CM (default),
    14 Day CF, Xtra 14 Day CF, CQ and Trifecta; pick CQ — the editor shows
    its steps; Add "Mine" — a template with the daily feeding alone;
    Rename it, Make default, Delete it (the default goes back to 14 Day
    CM); edit a step of 14 Day CM, Save, Reset — the workbook's schedule is
    back. Layout: Building 500 shows "205 gal system"; set its schedule
    to CQ and Save; the Board's building header reads "205 gal system ·
    CQ" and the to-do of a tank in 500 follows CQ (a day-3 group gets
    "Chloroquine 40 mg/gal") while a tank in 600 keeps the default.
15. Front Desk on 521: the title line ends "· 205 gal system · schedule
    CQ"; open Today → Dose: chips, the amount, "Whole system — Building
    500 · 205 gal system" preselected, tap Metro — the unit reads g and
    the chip "Suggested: 5.1 g" fills the amount; Save: the toast
    "Metronidazole 5.1 g · Building 500, whole system" with no Undo. On
    523 the Today strip's Metronidazole chip (a day-2 group) is already
    ticked; on 611 it is not. Dose again with "This tank only": the toast
    ends "· Tank 521". Events tab: the two dose rows read like the toasts,
    the system one under "Building 500"; the Board's header for 500 shows
    "last dose: Metronidazole …".
16. Settings → Import workbook: upload a .txt — refused with the reader's
    reason; upload the FisHotel-2026.xlsm — the preview table (species to
    create, orders with their lines and log rows, box rates, the stays
    estimate for the last 45 days) and the reader's warnings folded away;
    the file sits in wp-content/uploads/fishotel-private/ under a random
    name and the folder's URL answers 403; Import now with the defaults:
    the result lines, Species / Arrivals / Stats filled, the recent
    batches' stays on the Board with supplier, ref and landed cost, the
    treatment logs on the Events tab under Building 500 / 600, Settings →
    Box rates filled; the file is gone. Upload the same file again and
    Import now: every count but the species updates is 0, nothing new on
    the Board or the Events tab. Upload and Discard: the file is gone.
17. Sale dates. Arrivals → an order with checked-in fish: the *Goes on
    sale* card shows an empty picker and "suggested <check-in + 28>";
    click the suggestion — the picker fills, nothing is saved; reload —
    still empty. Pick a date in the past and Save: the notice "Order … goes
    on sale … (was not set)", the line "Applies to N groups still in tanks
    — for sale since …", the Arrivals list's *On sale* column shows the
    date, FisHotel Health → Log has "Sale date of supplier order …
    changed by <you>: not set → …". The Board: those groups read *ready*,
    the ones still in the Spa with "still in the Spa", the summary chip
    counts them. Post a bad date (for example with the browser's dev
    tools): "… is not a date. Nothing was changed." Clear: back to "not
    set", logged.
18. On a product with one of those groups, the *Clean room* box: the row
    shows the order (linked), tank, check-in, live, the picker on the
    batch's date "from the batch". Update the product without touching
    it: no new event on the Events tab. Change the date and Update: one
    "own sale date set to …" event, the row now says "own date" with *Use
    the batch date (…)*; the order's card lists the group under "These
    groups have their own date". Tick *Use the batch date* and Update:
    "own sale date removed (the batch date applies)". Quick Edit the
    product from the list: no event. The shop page of the product does
    not change by itself (stock and visibility stay as they were).
19. Sizes. Settings → Sizes: the standard list is the terms of the
    product attribute that matches "size", in the attribute's own order,
    with a link to Products → Attributes (or, without such an attribute,
    a plain list to type); "Sizes in use on products" lists every size
    value the fish products' variations carry, with how many products
    use each, the ones that are not standard first. No product changes.
    Order page: a Size dropdown per line; check in — the stay shows the
    size beside its species on the Board, the Stays tab and the phone.
20. Percent of a box. On a received order type Box # and "% of box" on
    two lines of box 1 (60 and 35): the Boxes card reads "Box 1 · 95% ·
    about full" and "Boxes by the percents: 0.95 · Boxes field: …" as
    you type; Save — the landed totals split the flight and import fees
    60 : 35. Type 600 in a percent: the save is refused, nothing saved.
    Start a new order for the same supplier and origin with the same
    fish and size: "suggested N% (from 1 order)" under the empty
    percent — a tap fills it, nothing is saved until Save; a draft shows
    the "How full" line. Settings → Box shares lists the rows; change
    one, forget one.
21. Unknown and mislabeled fish. Order page: tick ?? on a new line and
    type "small wrasse" — it reads "?? small wrasse"; give it a
    received count, a size and a percent, Save, Check in: the stay is on
    the Board as "?? small wrasse", the Board's summary has "1 needs
    identifying" (a click opens the Stays tab filtered to it), the
    Arrivals list and the order title say "?? 1 to identify", and
    Settings → Box shares has no row for it. Phone, on its tank: the
    row says "needs identifying"; tap it → Identify → type the real
    fish, pick it, a size, Save: the toast "Identified: ?? small wrasse
    → … · the order line follows", no Undo; the Events tab has
    "identified: ?? small wrasse → …" with your name; the order line
    has the fish, its scientific name and size; Settings → Box shares
    now has its row. On another checked-in line, Stays tab → Correct
    fish… → another fish: the order line shows "arrived labeled: <the
    old name>"; correct it again — the label does not change. Move 1 of
    a group of 3 to another tank (a split), then Identify the 1 as
    another fish: the order line keeps its name and shows "[part of this
    line is … — stay #N]"; identify the other 2 as that same fish: the
    line follows and the note is gone. Phone → Arrival → "Unknown fish
    (??)": a "??" group with no product.
22. Stats: By supplier and By origin show "Mislabeled" (N of M · rate)
    and "Not identified yet"; the XLSX has the four columns on Orders,
    By supplier and By origin, the stays export a needs_id column, the
    orders export needs_id and label_name on Lines.
23. Doses. Settings → Medications lists the seven built-in medications
    ("the built-in list, unchanged"), copper as "0.692 ml per gallon
    raises the level by 1.0 ppm" with the store's public calculator's
    figure beside it ("the same figure" — or that the theme's data is not
    available here). Log "611 copper 2.16" on the phone, open 621 (same
    building, 100 gal) → Today → Dose + → Copper: Now 2.16 with its
    time, Bring it to the due step's level or the copper target; set it
    to 2.4 — "Add 16.6 ml Copper Power" / "100 gal × 0.24 ppm × 0.692",
    the amount 16.6 ml. Save: the toast names the dose, the system, the
    working and that the reading was logged already; the Events tab has
    ONE new dose row with the working and no second reading; the Board's
    header for the building reads "last dose: Copper Power 16.6 ml (2.16
    → 2.40 ppm, 100 gal) …". Dose → Metro: "Add 2.5 g Metronidazole" /
    "100 gal × 25 mg"; *This tank only* → the tank's gallons; Top-up →
    25 % → 625 mg. Calculator (phone and Board): Metro, 55 gallons →
    1.4 g; nothing appears in the Events tab. Mic (or the typed box):
    "bring copper from 2.16 to 2.4" → the same result and working, no
    countdown, Confirm; "521 copper 2.2" is still a plain reading. Edit
    Metronidazole (add a measure "scoop = 0.5 g"), add a medication,
    retire one: the phone's chips follow, the result reads in scoops,
    the retired one is gone from the chips and its old log rows still
    read.
24. Temporary tanks. The Board has a **Temporary** area with six free
    slots (TEMP 1 · 901 … TEMP 6 · 906); Settings → Layout shows them
    with *Add a slot (TEMP 7 · 907)*; *Print tank labels* prints one
    label per slot. Scan (or open) a free slot's label: "Nothing is set
    up here — Set up". Set one up from the Board: *What is it for?* →
    "bacterial infection" picks Trifecta and lists its steps with their
    days before anything is saved; without a kind the save is refused;
    as Quarantine with 10 gallons it appears on the Board with its
    kind, purpose and "day 1", and on the phone's tank picker under
    Temporary. Its label now opens its screen. Settings → Treatment
    schedules → Trifecta: change a step's day — the tank's schedule does
    not change; on the tank, *Schedule* → +1 day on a step — the
    template does not change.
25. Fish in a temporary tank. Arrival sheet: the quarantine temporary
    tank is offered beside the Spa tanks, a clean one is not. Move a
    Spa group into the quarantine one with the *hospital* chip: not
    cleared, its QT day unchanged, the tank's own day beside it. Set up
    a second slot as Clean; move a quarantine group into it: the sheet
    says it clears and asks ("Move and clear"); the group is cleared
    today. Dose on a temporary tank: no "whole system", the sum
    runs on its gallons and its own schedule's steps. *Take down* with fish in it is refused ("move
    the fish out first"); empty, it is taken down, the slot is free,
    Board → Temporary → History shows it with its treatment as it was
    run, its doses, readings and the groups that passed through; a new
    setup in that slot shows none of the old ticks or readings.
26. To do. The **To do** tab (and *Today* on the phone's tank picker):
    one block per Spa building and per temporary tank; with fish in
    three tanks of one building each due dose and test is listed once;
    *Done* on a line — every tank of that building then shows it ticked
    on the Board and on its phone screen; "521 copper 2.4" on the phone
    ticks the building's copper test; a whole-system dose ticks its
    step; *All fed* ticks the feeding of every tank; the count at the
    top goes down and reads "All done" at the end. With the Today
    section on, its page and the dashboard widget say "Tanks: N things
    to do" with a link to the tab.
27. Stats → Compare years: the last two years with orders are ticked;
    each supplier has a row per year and, under the later one, the
    change (fish and percent, rates in "pts"). Tick a year in which a
    supplier had no order: that row is dashes, not zeros, and so is the
    change next to it. Export XLSX: four sheets; the CSV is one table.
28. Stats → Species: a row per fish of the year; click "Landed" — the
    most expensive first, again — the cheapest; search a group name;
    "All years" lists a fish once per year. A fish whose survivors are
    not typed reads "survivors not known yet", not a loss.
29. Stats → Prices to raise: the fish priced under what their latest
    order suggests, the biggest gap first; the suggested price equals
    the "Suggested" of that fish's line on its order page. Tick "Show
    all fish"; filter by a supplier — the costs are then that
    supplier's. Nothing on the page saves anything; no price changed.
30. Stats → Real sales: the statuses named at the top are WooCommerce's
    paid ones plus "Shipped". Compare a linked product's units and
    revenue for the year with WooCommerce → Analytics → Products (same
    statuses; a refund made in another year than its order is the one
    difference). A fish sold but not landed that year reads "no landed
    cost in <year>", with no profit. "Not matched" lists what is no
    species — link one of those products on the Species tab and reload:
    its sales moved up, without a Refresh. "How it adds up" balances.
    Complete a pending order, wait a minute (WooCommerce updates its
    tables in the background) and reload: it is counted. Refresh reads
    again at once. Settings → Sales statuses: untick "Shipped", Save —
    Real sales names the shorter list and its figures drop; "Back to
    the suggested list" restores it.
