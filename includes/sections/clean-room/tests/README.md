# Clean Room — headless tests

All run without WordPress, WooCommerce or a database. `wp-stubs.php`
supplies the handful of WordPress functions the classes touch (the
translation helpers, `sanitize_*`, options, transients, `current_time`,
`wp_timezone` = America/Chicago, `user_can` / `current_user_can`,
`wp_verify_nonce` matching `wp_create_nonce`, `wp_get_current_user`, the
action and filter registries — all in-memory), the `check()` / `check_same()` /
`check_contains()` / `fh_finish()` harness and the temp-dir helpers, and
loads the section's classes; the Store
class (the only `$wpdb` file) is loaded for its constants and pure
statics only and stands replaced by `fake-store.php` — an in-memory
store that runs the same Replay and hydration code — so no `$wpdb`
method is ever called. `fixtures/qr-front-desk-521.txt` is the module
matrix python-qrcode produces for the Front Desk URL of tank 521 (byte
mode, level M, mask 2).

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
php  includes/sections/clean-room/tests/species-test.php
php  includes/sections/clean-room/tests/orders-test.php
php  includes/sections/clean-room/tests/xlsx-test.php
php  includes/sections/clean-room/tests/workbook-admin-test.php
php  includes/sections/clean-room/tests/workbook-test.php
php  includes/sections/clean-room/tests/import-map-test.php
php  includes/sections/clean-room/tests/import-test.php
php  includes/sections/clean-room/tests/sale-dates-test.php
node includes/sections/clean-room/tests/front-desk.test.js
node includes/sections/clean-room/tests/clean-room-admin.test.js
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
`fake-store.php`) exit 0 when run directly. Temporary files go to the
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
  arrays; the 19-column row with guarded species / supplier / order ref
  and the building derived from the tank; `write_csv()`; the Board /
  Stays filters (the *For sale now* flag) and totals (for sale, for sale
  but still in the Spa, without a sale date); the Events-tab wording for every event type
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
  reader and the display helpers.
- **stats-test.php** — the year of an order; per-order rows over four
  invented orders (a bag-mode one dated by its order date, one that
  received nothing, one whose survivors are unknown); the weighted Σ/Σ
  totals (survival over the known orders only); the sheet-style
  averages (the empty batch out, the unknown batch out of the survival
  average, total fish through QT); by supplier / origin (merged
  case-insensitively) / species group ("(no group)" last); one year at
  a time.
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
  named, an emptied picker, a group without an own date, a closed group,
  a failing store caught and logged, an autosave).
- **fake-store.php** — not a test (exits 0 when run directly): the
  in-memory store the above drives (tanks and buildings from the seed,
  stays — joined with their batch's order and sale date like the real
  `stay_select()`, `stays_for_product()`, `product_facts()`,
  `batch_sale_counts()` —, events, logs — per tank or per building, with
  `logs_on_date()`, `latest_doses()`, `imported_log_keys()` —, aliases,
  species rows, orders and lines).
- **xlsx-builder.php**, **fixture-builder.php** — not tests (exit 0 when
  run directly): the tiny .xlsx writer and the synthetic FisHotel-style
  workbook the reader, mapper and import tests use.
- **front-desk.test.js** — `englishValue()`, `tankValue()`,
  `normalizeNumberWords()` (known tanks, the rule without a list, "four
  six line wrasse" left alone, "a couple" / "two point four" left for the
  server), `previewSentence()` for every intent including the Spa-tank
  ask and the "Which fish?" prefix, `fmt()`, `escapeHtml()`,
  `saleSummary()` (a batch date, an own date, none with the suggestion,
  the "still in the Spa" warning, the page's strings).
- **clean-room-admin.test.js** — `renumber()` with a prefix and a
  template row's `__i__`, `suggestImportFees()` (the exact pair, the
  origin-less fallback, nulls, cents).

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
