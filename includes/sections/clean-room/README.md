# Clean Room

Where every fish physically is, from the day it lands to the day it
ships: the Spa (quarantine / treatment) and Clean Room (observation) tanks
as a seeded, editable layout; one **stay** per group of one species with
an event history that is replayed, never edited; a one-thumb phone screen
(the **Front Desk**) opened by a QR label on every tank, driven by big
buttons or one spoken sentence; a dark **Room Board** under FisHotel
Tools; a treatment protocol that turns into each tank's daily to-do; the
**supplier orders** with the workbook's landed-cost maths and a check-in
that turns an order's lines into stays; a **species master**; the yearly
**stats**; Excel exports; and the facts the fish page will read.

Nothing here needs WooCommerce: a stay may or may not be linked to a
product, a species may or may not be, and the species search reads the
`quarantined-fish` / `inverts` products straight from the tables next to
the species list.

## How Jeff runs the room (the rules the code follows)

- **Every arrival goes into a Spa tank** (buildings 500 and 600). Nothing
  is ever checked in straight to a Clean Room tank; the Arrival screen,
  the parser and the server all refuse it and offer the Spa tanks.
- **Cleared** is the day a group first *moves* from a Spa tank into a
  Clean Room tank — the day it comes off copper. Only a move *out of the
  Spa* (or out of a tank the layout no longer knows) clears: after a hand
  unset, a Clean Room → Clean Room move does not re-clear. The date is
  editable per stay (Dates on the phone, *Cleared…* on the Stays tab) and
  can be unset; a hand-set date is a `clear` event recorded *now* with the
  date in its note, so a correction to an earlier date — or a date set
  after an unset — always wins over the move.
- The **check-in date** is the site date of the first arrival and can be
  corrected too (Dates on the phone, *Edit* on the Stays tab): a
  `checkin:YYYY-MM-DD` note the replay honours; the day number and the
  sale-ready date follow from it.
- **Sale-ready** is N days after check-in (N = *Sale-ready: days after
  check-in*, default 28 — Jeff offers a group for sale after the full four
  weeks, so check-in + 28 days = day 29), with a per-stay override. A
  group is ready only once it is cleared **and** that day has come; a
  group still in the Spa on day 40 is still "in QT".
- **Alive = received − losses − shipped**, with an optional full recount
  that overrides. Head counts are unreliable (fish hide), Jeff counts
  bodies, so a **loss is the fastest path**: one tap on *−1 lost* on the
  fish's row, or "lost one cardinal in 521" / "2 dead in 513" by voice.
  Two phones can record a loss against the same count; the second entry
  is kept but flagged **overdrawn** — the toast says the tank now shows
  fewer fish than were taken out and asks for a recount (or an undo of the
  duplicate, from the toast or the Events tab).
- **Moves** are one line — "move 3 cardinals 121 to 221". A quantity
  smaller than the group **splits** it: that many continue as a sister
  stay with the same check-in, cleared date and override; the rest stay
  put. The Move sheet offers reason chips — *clear building / bullying /
  pairing / other* — stored in the move event's note and shown on the
  Events tab.
- Counts stay **editable at any time** (Jeff loses fish after an order
  exists); the order itself is edited in WooCommerce. Any mistake is undone
  with a compensating event — from the phone's toast within 15 minutes, or
  from the Events tab at any time — and never twice for the same event.
- The **treatment protocol** is an editable template, not code: steps
  with a day offset, a kind (feed / dose / test / water change / other),
  an optional repeat and a "where". The seed is the workbook's **14 Day
  CM** schedule, counted from the check-in day as day 1 (the sheet counts
  the arrival day as day 0, so site day = sheet day + 1): Copper Power to
  2.0 on day 1 and to 2.4 on day 2, then held at 2.25 or higher (2.5 the
  target) with a daily copper test to day 15; Metronidazole every other
  day from day 2 to day 12 (six treatments); Praziquantel on days 8 and
  14; a freshwater or formalin bath on day 16 and the move to the Clean
  Room. The sheet's other schedules (14 Day CF, Xtra 14 Day CF, CQ) are not
  seeded yet. **Copper readings** are logged per tank ("521 copper 2.4");
  a reading logged today ticks the copper test chip (a `test` step whose
  label names copper / Cu) by itself.

## The numbering rule

**Hundreds = building, tens = level (1 bottom, 2 top), ones = position
(1 = leftmost).** Tank 121 is building 100, top row, leftmost. Every
building is ONE shared water system (sump + skimmer): nothing extra is
stored, but every tank title says which tanks it shares water with.

Seed (inserted when the tables are installed and the buildings table is
empty — never on an ordinary boot, which costs no query):

| Building | Area | Top row | Bottom row |
|----------|------|---------|------------|
| 100 | Clean Room | 121, 122, 123 (20G long) | 111, 112 (40G breeder) |
| 200 | Clean Room | 221, 222 | 211, 212 |
| 300 | Clean Room | 321, 322 | 311, 312 |
| 400 | Clean Room | coming soon, no tanks | |
| 500 | Spa | 521, 522, 523, 524 (20G long) | 511, 512, 513 (40G breeder) |
| 600 | Spa | 621 (40G breeder) | 611 (40G breeder) |

Settings → Layout adds / renames / retires buildings and tanks with the
rule enforced: a building is a round hundred, a tank's number is derived
from building + level + position (never typed), duplicates are refused by
name, and a building with tanks keeps its number. Retire with the status
rather than deleting — stays point at tanks.

## The phone screen (Front Desk)

`home_url( '/?fh_front_desk=1&tank=521' )` — exactly what each QR label
encodes. No theme header or footer; its CSS and JS are inlined, so after
the page loads nothing else is fetched. Logged out → the login page and
back — and a login that came from a scanned label keeps its cookie for
about a year (the `auth_cookie_expiration` filter, only when the login's
`redirect_to` is this page and the user may use it), so a phone does not
log in again every two days; logged in without `manage_woocommerce` →
"Not allowed".

**Tank screen** — title ("Tank 521 · Spa · Building 500 · 20G long ·
shares water with 511, 512, 513, 522, 523, 524"), the occupants first
(species, live, day N, state badge, in since / ready date, each with a
**−1 lost** button — the fastest path is never below the fold), then the
**Today** strip folded to one line ("Today · 3 to do ▸", plus "Cu low"
when the last reading is under the floor) that opens on tap — the to-do
from the protocol as tap-to-tick chips, feeding first; the last copper
reading with its age, red when below the floor; a Copper entry — and
stays open for the session; and the bar: **Lost · Shipped · Move ·
Arrival · Note · Mic** (Copper takes the Mic's place when voice is off).
Tapping an occupant opens its sheet: −1 lost, Lost…, Shipped, Move, Full
recount, Note, Dates (check-in date, cleared date, sale-ready override).

**Arrival** — species search with instant suggestions (products, common
names, scientific names, learned aliases), recent-species chips, "use as
an unlisted fish", a quantity stepper, the Spa tank (the current one when
it is a Spa tank, else the first empty one) and the date.

**Move** — one group or all (a tank with a single group skips "Which
fish?"), an optional quantity (smaller = split), the reason chips, then
the destination grid: Area → Building → tanks, empty tanks first, the
current tank disabled, buildings with a group in QT marked.

Every write is one AJAX action (`fishotel_cleanroom_act`, nonce +
`manage_woocommerce`); the reply carries the refreshed screen, and a
toast offers **Undo** — a compensating event, never a delete. The toast
stays until the next action (at least 10 seconds); the server honours the
undo for 15 minutes, only for the user who wrote the event, and never
twice for the same event.

### Voice phrases that work

The Mic button uses the browser's speech recognition (en-US, one phrase);
without it, the same box takes typed text and the keyboard's own mic key
dictates. The sentence is parsed on the server and shown in plain words
with a 4-second countdown (Settings), Cancel, and *Not that fish* — which
opens the species picker and teaches an alias (the spoken words → that
fish) for next time.

| Say | Result |
|-----|--------|
| `lost one cardinal in 521` · `2 dead in 513` · `a clownfish died, jumped` · `lost 2 clowns doa` | a loss on that group (reason: DOA, disease, jumped, unknown) |
| `shipped 2 cardinals` · `sold a clown` | shipped (lowers the live count, not the survival stats) |
| `move 3 cardinals 121 to 221` · `move the clowns to building one top left` · `move all to 222` | a move (a quantity smaller than the group splits it) |
| `four pajama cardinalfish in tank 521` · `put a dozen banggai cardinals into 522` | an arrival (a Clean Room tank is refused — pick a Spa tank) |
| `521 copper 2.4` · `copper two point four` | a copper reading on the tank |
| `count five clowns` · `there are 3 pajamas` | a full recount |
| `clear the cardinals` | the cleared date set by hand (today) |
| `note water change done` | a note on the tank's log |

Numbers: digits, number words, "a couple" = 2, "a dozen" = 12, "half a
dozen" = 6, "two dozen" = 24. Tanks: "tank 121", "one twenty one", "five
twenty-one", "tank five two one", "one hundred and twenty one", "building
100 top left", "top right" (the page's building). The tank is the page's
when none is spoken. Species are matched fuzzily (aliases first, then
product titles, then common names; size words ignored; typos within two
letters); an ambiguous or weak match asks "Which fish?" with the top three.

## The Room Board (FisHotel Tools → Clean Room)

Tabs: **Board · Stays · Arrivals · Species · Stats · Events · Settings**.

**Board** — Area → Building → Level grid of tank cards (occupants with
species · live · day N · badge; empty tanks dimmed; coming-soon buildings
greyed), a header per building with *shared water* and the count in QT,
the last copper reading, the to-do count and what is still due today
(printed on the card — a tooltip is invisible on a tablet) per tank, area
and species filters, a *Front Desk* link per tank. **Stays** — every open
stay (species, live / arrived, check-in, day, state, cleared, sale-ready,
tank, supplier / order ref) with Lost / Shipped / Count / Cleared… /
Sale-ready… / Move… / Coming Soon / Edit (species, product, check-in
date, supplier, order ref, landed cost, a note), closed stays with a
toggle, a CSV and an XLSX export with the formula-cell guard.
**Arrivals**, **Species** and **Stats** — the workbook replacement, below.
**Events** — the latest 200 stay events and 100 tank-log entries in plain
words, with a per-row **Undo** (a compensating event, no actor or age
guard, refused a second time). **Settings** (`manage_options`) — the
numbers, the protocol editor, the layout editor, the box rates, the
learned aliases, *Print tank labels*.

## Supplier orders (Arrivals)

What the workbook's "Recieved (N)" sheet held: one **order** per
delivery (ref `YYYY-N`, supplier, origin, order and received dates,
status draft → ordered → received → closed, the Spa building it lands in,
the cost mode and the money: tariffs, domestic flight, import & re-ox
fees, credit, the markup for the suggested price and the "worth it"
margin threshold) with its **lines** (species — picked from the species
list and the products, or typed —, ordered, received, per box or bags,
the supplier's price, what it is worth at FisHotel's price, DOA,
survived, the Spa tank). The costing replicates the sheet exactly
(`class-costing.php`, pure):

- **box mode** — fraction = received ÷ per box (the line's, else the
  species list's, else 24; 0 when nothing was received); fees per box =
  (flight + import) ÷ Σ fraction; landed = received × price × (1 +
  tariffs ÷ Σ(received × price)) + fraction × fees per box — tariffs by
  goods value, the flight and import fees by box space;
- **bag mode** — units = bags when typed, else received; per bag =
  (flight + import + tariffs) ÷ Σ units; landed = units × price + units ×
  per bag;
- per line: landed per fish = landed ÷ received; suggested price = per
  fish × markup (4) to the nearest 5; profit = received × worth − landed;
  margin = profit ÷ landed; worth it when the margin is above the
  threshold (2.75); revenue = worth × survived; DOA cost = DOA × price;
- per order: total = Σ landed; grand total = total − credit; revenue; up /
  down = revenue − grand total; fill = Σ received ÷ Σ ordered; survival =
  Σ survived ÷ (Σ received − Σ DOA), undefined until a survivor count is
  known; DOA rate = Σ DOA ÷ Σ received; the sheet's DOA cost = Σ DOA ×
  price + Σ DOA × fee rate — in box mode the fee rate is a *whole box's*
  fees, a carry-over from the bag version, so it is labelled and the
  per-fish figure (fees per box ÷ per box) stands beside it;
- every division is guarded (an undefined ratio shows "—"); full float
  precision inside, rounding only on display and when a figure is
  stored.

**Check in received fish** creates the stays — one per line that received
fish and has no stay yet — through the same `Store::create_stay()` +
`record_event()` path the Front Desk uses: an `arrival` of the received
count on the order's received date (today when empty; never in the
future) into the line's tank, else the first empty active Spa tank of
the order's building, else any empty Spa tank, else the first Spa tank
of the building, else the first Spa tank; `source = order`, the line id,
the supplier, the ref and the landed cost per fish filled; then a `loss`
of the DOA count noted `doa`. A line with a stay is skipped, so a second
click creates nothing; the order becomes *received*. From then on the
line's figures are **live**: survived = arrived − every loss (any
reason), sold = the shipped events, DOA = the losses noted `doa` (undos
honoured) — shown read-only on the line, which also cannot be removed
and keeps its figures on re-save. Lines without stays (imported history,
or fish not checked in) keep the typed survived / DOA numbers.

The **Suggest** button next to the import & re-ox fees fills boxes ×
(per box + re-ox) from Settings → *Box rates* for the order's supplier
and origin (a row with no origin stands for every origin of that
supplier) — on request, never on its own. The box rates and the pick
lists start empty; nothing is seeded in code.

## Species

The workbook's Fishlist as a table: name (unique), scientific name,
group, fish per box (24 by default), FisHotel's price, a competitor's
price / site / date, reef-safe, max size, notes, and an optional product
link. A species may or may not be a product — most are never listed.
The Arrival screen's search, the voice parser's candidates and the order
editor read the products and the species rows **as one list, deduped by
lower-cased name**: a species row linked to a listed product, or named
like one, folds into that product (its scientific name fills a missing
one, a different common name joins the product's common names, its per
box and price ride along); any other row is an entry of its own, with its
product link (which may point outside the fish categories) or none. The
candidate cache is dropped by every product, species and alias save. The
Species tab: search by a prefix of the name or the scientific name (any
word), group filter, 50 per page, inline edit, add, *Link product* (a
product search over the fish categories), delete — refused while an
order line names the species —, the open-stays count (by product when
linked, else by name).

## Stats

Per year (the order's received date, else its order date): the per-order
rows, then the same figures two ways, both shown and both exported:

- **weighted** — Σ ÷ Σ over every order: fill = Σ received ÷ Σ ordered,
  survival = Σ survived ÷ (Σ received − Σ DOA) over the orders whose
  survivors are known, DOA = Σ DOA ÷ Σ received, landed, revenue, up /
  down;
- **as the spreadsheet averaged them** — the UNWEIGHTED average of each
  batch's fill / survival / DOA rate over the batches that received
  anything (each rate over the batches where it is defined: a batch
  whose survivors are not typed yet is not in the survival average, as it
  was "not in the formulas yet"), and *total fish through QT* = Σ
  survived;

and the weighted figures by supplier, by origin and by species group
(the species row's group; "(no group)" otherwise).

## Exports

XLSX everywhere, CSV where it is one table: orders + lines (two sheets;
the CSV is the lines), species, stats (Orders, Totals — weighted beside
sheet-style —, By supplier, By origin, By group; the CSV is the orders),
stays (beside the existing CSV). All are admin-post downloads with a
nonce and `manage_woocommerce`, named `fishotel-<thing>-<Ymd-His>.xlsx`.
The writer (`class-xlsx.php`) needs nothing but the zip extension: text
is written as inline strings — never a formula, whatever it starts with
—, numbers as numbers, nulls as empty cells; sheet names are cut to 31
characters with the forbidden characters replaced. Without the zip
extension the same request sends a CSV and says so on the next page.

**Print tank labels** opens an admin-only printable page: one 3.5 × 2 in
label per active tank with the number large, area / building / size
small, and a QR of the Front Desk URL generated as inline SVG by the
section's own encoder (`class-qr.php`: byte mode, error correction M,
versions 1–6, verified module-for-module against python-qrcode).

## What the fish page will read

```php
if ( function_exists( 'fishotel_cleanroom_product_facts' ) ) {
	$facts = fishotel_cleanroom_product_facts( $product_id );
}
// or, always safe:
$facts = apply_filters( 'fishotel_product_qt_facts', null, $product_id );
```

Both answer **null** for a product with no open stay here (the theme then
falls back to the product's own meta, exactly as when nobody answers);
otherwise:

```
live_total   fish alive across every open stay of the product
ready_total  of those, in stays that are cleared and past their sale-ready date
in_qt[]      stays still in the Spa: stay_id, qty_live, checkin_date, day, sale_ready_date, tank
cleared[]    stays in the Clean Room: + cleared_date, sale_ready (bool)
next         the stay that can ship first (a ready one, else the earliest sale-ready
             date): checkin_date, cleared_date, sale_ready_date, sale_ready, day,
             tank ('Clean Room · Building 100 · Tank 121'), qty_live — or null
```

Most fish are pre-sold custom orders and never listed; when one is listed
it is Coming Soon with a countdown (the Coming Soon section's
`_fh_release_datetime`). The Stays tab's **Coming Soon** button sets that
release moment to the stay's sale-ready date at 09:00 site time — only
when the product exists; a stay never assumes one does. Photos are stock
photos; nothing here touches them. The lottery for contested fish is the
Lottery Draw section.

## How an order becomes stays

Every stay carries `supplier`, `order_ref`, `landed_cost` and
`order_line_id` (all nullable; editable on the Stays tab) and a `source`
of `order` / `import`. The check-in fills them (see *Supplier orders*):
one stay per order line through `Store::create_stay()` + an `arrival`
event (`Store::record_event()`), exactly as the Front Desk does — the
Replay, the facts and the board need nothing new, and a workbook import
will create orders and species through the same Store methods.

## Data

Nine tables, created by dbDelta on first boot (gated by the autoloaded
`fishotel_cleanroom_db_version` option, 1.2, so a booted site pays no
query and no `SHOW TABLES`) and on activation, the layout seeded in the
same step; everything kept on deactivation.

- `{prefix}fh_buildings` — id, area, number (unique), name, status, sort, notes, schedule, system_gallons (the last two reserved: not read yet)
- `{prefix}fh_tanks` — id, building_id, number (unique), level, position, size_label, gallons, status, sort
- `{prefix}fh_stays` — the cached state: product_id, species, qty_arrived, qty_live, checkin_date, cleared_date, sale_ready_date (the override), closed_date, status, tank_id, source, order_line_id, supplier, order_ref, landed_cost, notes
- `{prefix}fh_stay_events` — the truth: stay_id, type (arrival · move · loss · clear · sold · count · note), qty, from/to tank, note, event_at (UTC), actor, source
- `{prefix}fh_tank_logs` — per tank: copper readings, dose / test / water-change / feed tick-offs, tank notes (+ building_id, reserved: not read yet)
- `{prefix}fh_aliases` — spoken words → product / species
- `{prefix}fh_species` — the species master: name (unique), sci_name, group_name, per_box, my_price, online_price, online_site, online_date, reef_safe, max_size, product_id, notes
- `{prefix}fh_orders` — a supplier order: ref (unique), supplier, origin, order_date, received_date, status, boxes, cost_mode, tariffs, domestic_flight, import_fees, credit, markup, worth_threshold, schedule, building_id, notes
- `{prefix}fh_order_lines` — its lines: order_id, species_id, species, sci_name, qty_ordered, qty_received, per_box, bags, unit_price, worth, qty_doa, qty_survived, landed_total (a cache of the costing), stay_id, tank_id, sort, notes

Three options: `fishotel_cleanroom_settings`, `fishotel_cleanroom_protocol`
and `fishotel_cleanroom_box_rates` (rows of supplier, origin, per box,
re-ox; empty until typed).

`Store::record_event()` appends the event and replays the stay
(`Replay::state()`); the row is only a cache, and the reply says when the
events add up to fewer than zero fish (`overdrawn`). A negative quantity
reverses (the undo, note `undo of #<id>` — which is how the same undo is
refused a second time), a `clear` carries its date in the note
(`cleared:YYYY-MM-DD`; an old-shape one without a note is dated by its
`event_at`) and with −1 unsets the cleared date, a note of
`sale_ready:YYYY-MM-DD` sets the override, a note of `checkin:YYYY-MM-DD`
corrects the check-in, a move's note is its reason. Day number =
floor((today − check-in) / 86400) + 1 over the two calendar dates, never
stored.

## Files

```
clean-room/
├── class-clean-room.php    section info, load, boot, menu, activate / deactivate, the global
│                           fishotel_cleanroom_product_facts() + the fishotel_product_qt_facts filter
├── class-store.php         the ONLY $wpdb file: nine tables, seed, stays / events / logs, record_event
│                           (append + replay), splits, species search + candidates, aliases, facts,
│                           the species master, the orders and their lines, stay figures
├── class-layout.php        PURE: numbering rule, seed, shares-water, titles / labels, grouping
├── class-replay.php        PURE: events → state, day math, sale-ready, splits, compensating events
├── class-protocol.php      PURE: the treatment template → a tank's daily to-do
├── class-parser.php        PURE: a spoken / typed phrase → structured preview
├── class-qr.php            PURE: byte-mode QR encoder (EC M, v1–6) → inline SVG
├── class-costing.php       PURE: the order sheet's landed-cost maths (box / bag), display helpers
├── class-stats.php         PURE: per-order rows, weighted and sheet-style figures, groupings
├── class-species.php       PURE: a species row normalised, the merge into the candidate list
├── class-orders.php        header / lines from a form, live figures of a line, the check-in
│                           (takes the store as a parameter, like Front_Desk::perform())
├── class-xlsx.php          a dependency-free .xlsx writer (inline strings, never a formula)
├── class-settings.php      options, sanitised AJAX saves (settings, protocol, box rates), layout editor, aliases
├── class-front-desk.php    the phone page (query var fh_front_desk), the label-login cookie life,
│                           its AJAX (act / parse / suggest) with perform() = the testable switch
├── class-admin.php         the Room Board page, CSV, labels, stay edits + move + undo, Coming Soon release, meta box
├── class-admin-workbook.php  the Arrivals / Species / Stats tabs, their AJAX, the XLSX / CSV exports
├── css/front-desk.css, js/front-desk.js      the phone screen (inlined into the page)
├── css/clean-room-admin.css, js/clean-room-admin.js
├── views/front-desk.php, board.php, stays.php, arrivals.php, order.php, species.php, stats.php,
│          events.php, settings.php, tabs.php, labels.php, meta-box.php
└── tests/                  headless PHP + node tests (see tests/README.md)
```
