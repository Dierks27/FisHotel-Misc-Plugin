# Clean Room — headless tests

All run without WordPress, WooCommerce or a database. `wp-stubs.php`
supplies the handful of WordPress functions the classes touch (the
translation helpers, `sanitize_*`, options, transients, `current_time`,
`wp_timezone` = America/Chicago, `user_can`, the action and filter
registries — all in-memory) and loads the section's classes; the Store
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
node includes/sections/clean-room/tests/front-desk.test.js
node includes/sections/clean-room/tests/clean-room-admin.test.js
```

Every species, price, supplier and figure in these tests is invented
("Blue Test Wrasse", "Test Supplier One", "Testland"); nothing comes
from the workbook but the formulas.

- **layout-test.php** — the seed (six buildings, 22 tanks, 100–400
  Clean Room, 500/600 Spa, 400 coming soon and empty), every seeded
  number obeying hundreds = building / tens = level / ones = position,
  uniqueness, the row contents and sizes; `tank_number()` /
  `parse_number()` edge cases; the shares-water lists (offline tanks
  left out); the phone title, the path, the printed label text; the
  editor's validation (round hundreds, taken numbers, level 1–2,
  position 1–9, derived duplicates named); rack ordering, grouping with
  an empty building, `tank_at()` for left / right / middle / second.
- **replay-test.php** — `day_number()` across the March and November
  DST changes (and a whole year), `add_days()`, the sale-ready date (N
  days after check-in, the override), `note_date()`, event dates in
  Chicago on both DST mornings; a stay's life (arrival → loss → shipped →
  recount → the first move into the Clean Room clears it → ready 28 days
  after check-in and not the day before, an old-shape hand-set cleared
  date, un-clearing, a `cleared:` note dated now that wins even to an
  EARLIER date and after an unset, an invalid note date); Spa-to-Spa
  moves never clear, a later move back keeps the date, no tank areas =
  no auto-clear, after a hand unset a Clean → Clean move does not
  re-clear but back through the Spa does, an unknown source tank clears;
  the sale-ready override note (set, clear, invalid); the `checkin:` note
  (corrects, survives a later arrival, empty = back to the arrival,
  invalid ignored, its undo); closing at zero by loss / recount / shipped
  and re-opening on undo, the `overdrawn` flag when two phones record
  against one count and its clearing by an undo or a recount; splits (a
  move with a smaller quantity, its undo, a whole-count move = whole
  move); event ordering and the checkin fallback (the SITE date of an
  arrival); `effective()` on cached rows; every compensating event,
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
  `describe()`.
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
  Stays filters and totals; the Events-tab wording for every event type
  (move reasons, split markers, the `cleared:` and `checkin:` notes) and
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
  refused, corrected, undone), the sale-ready override removed; a copper
  reading that ticks the copper test and a tick-off; the label-login
  cookie life (a year for a crew member bound for the Front Desk, untouched
  otherwise); `facts_or_null()` and the facts filter answering null
  through a dead `$wpdb`.
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
- **fake-store.php** — not a test (exits 0 when run directly): the
  in-memory store the above drives (tanks and buildings from the seed,
  stays, events, logs, aliases, species rows, orders and lines).
- **front-desk.test.js** — `englishValue()`, `tankValue()`,
  `normalizeNumberWords()` (known tanks, the rule without a list, "four
  six line wrasse" left alone, "a couple" / "two point four" left for the
  server), `previewSentence()` for every intent including the Spa-tank
  ask and the "Which fish?" prefix, `fmt()`, `escapeHtml()`.
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
   cleared date = today and the sale-ready date = check-in + 28 days, and
   the Events tab shows "moved Tank 521 → Tank 121 (bullying)". From 121,
   Arrival must offer only Spa tanks; a second Move 121 → 122 does not
   say "cleared".
7. Occupant → Dates: set the cleared date to yesterday, then to the day
   before — each sticks (the Stays tab agrees); *Not cleared*, then a date
   again — it sticks. Set the check-in date a week back: the day number
   and the sale-ready date move with it.
8. Stays tab: Lost / Shipped / Count / Cleared… / Sale-ready… / Move…
   reload with the new numbers; Edit → Check-in writes a `checkin:` note;
   Export CSV opens in a spreadsheet with no formula execution; on a stay
   linked to a product, *Coming Soon* sets the product's
   `_fh_release_datetime` to the sale-ready date at 09:00. Events tab:
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
