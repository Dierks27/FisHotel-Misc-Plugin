# Foam Cuts

A cut sheet for the insulation foam that lines every shipping box: which
sheet or leftover to take, where to cut it, which piece is for which box,
and what to write on what is left over. Under **FisHotel Tools → Foam Cuts**
(`manage_woocommerce`).

## What it does

1. **Settings** — the sheet as bought (96 × 48 in), its thickness (0.5 in),
   the blade's own width lost per cut (kerf, 0), whether a piece may be
   turned 90 degrees (yes), the smallest leftover worth keeping (4 × 4 in).
   Sizes are typed in inches; `11.75` and `11 3/4` both work.
2. **Boxes** come from ShipTracker's standard boxes when it has some
   (`FST_Settings::get_standard_boxes()`), else from the Packing section's
   own list (read from its classes, only while that section is switched
   on), else from this section's own list. The screen says which list is in
   use and what was not available. The own list is saved only from a page
   that shows it as it is now: a save from a tab opened before someone
   else changed the list is refused, so it cannot quietly drop a box.
3. **Cut list per box** — six pieces suggested from the INSIDE dimensions
   L × W × H and the thickness t: top and bottom L × W, two long sides
   L × (H − 2t), two short sides (W − 2t) × (H − 2t) (L is taken as the
   longer footprint side). The owner saves the list the way the pieces are
   really cut — name, size, count — and that list is used from then on; a
   box that changed size since is flagged.
4. **Stock** — whole sheets (a count made of entries: bought, corrected,
   taken by a plan, given back by an undo) and leftovers (a size and a
   label L1, L2 … written on the piece; a number is never handed out
   twice, also when two requests ask at the same moment: the counter is
   moved inside the database). Leftovers can be added, resized and removed
   by hand — never larger than a whole sheet; "bought" takes whole numbers
   only.
5. **A plan** — "cut for this many of each box", typed, or filled in from
   the orders that ship on a day (open orders whose delivery date
   `_fishotel_shipping_date` is the day after; their box from the Packing
   section). Every piece is placed with straight edge-to-edge cuts: the
   smallest free rectangle that holds it first (leftovers, and the offcuts
   of what is already being cut), a whole sheet only when nothing else
   fits; the larger remainder is kept as large as possible; no cut is
   longer than a sheet is wide unless a piece is. Deterministic. A piece
   that fits no sheet is reported, never dropped silently. Making a plan
   changes nothing in the stock. At most 500 of one box per plan — more is
   refused, not cut down.
   **The orders of a day asked for twice give one plan**: while the draft
   for that ship day is still there, has the same boxes and still matches
   the stock, it is handed back instead of a second plan (which would hand
   out other labels for the same foam). When the day's boxes have changed,
   a new plan is made, and each plan of that day names the others — on its
   page, on the printed sheet, in the REST answer (`same_day`) and after
   "Mark as cut" — because each counts ALL of that day's orders.
6. **The printable page** — one source per page (a leftover's label, or
   "new sheet 1"): a drawing to scale with every piece labelled, the cuts
   numbered in the order they are made, the new leftovers hatched with the
   label to write on them; the cuts in plain words; then the pieces per box.
   On a drawing a size reads across × down, as the piece lies (a turned
   12 × 7 reads 7 × 12). A source with more cuts than fit under its
   drawing gets them smaller in four columns, or on the page(s) after it —
   each headed with the source's name and "cuts 1 to 45 of 45" — so no
   page comes out of the printer without saying what it belongs to.
7. **Mark as cut** books the plan against the stock once (used leftovers
   go, sheets are taken, new leftovers are added); it refuses a plan made
   for stock that has since changed. **Undo** puts it all back while
   nothing else has touched what the plan touched, and says what came back
   in numbers (the sheet count as it is now).

## For other code

| What | |
|---|---|
| `fishotel_foam_cuts_plan( $id = 0 )` | A plan (0 = the newest) as the REST route gives it, or `null` — also `null`, without a query, while the section is switched off. Guard with `function_exists()`. |
| filter `fishotel_foam_cuts_boxes` ( rows, source ) | Replace the box list. Rows: `name`, `length`, `width`, `height` in inches. `source` is `shiptracker`, `packing` or `own`. |
| filter `fishotel_foam_cuts_order_boxes` ( rows, order ) | Supply or correct the boxes of one order: rows of `name` (and / or `length`, `width`, `height` in inches) and `count`. |
| filter `fishotel_foam_cuts_order_statuses` ( statuses ) | The statuses of an order still to be boxed. Default `processing`, `fulfillment`. |
| action `fishotel_foam_cuts_plan_created` ( id, data ) | A draft was saved. |
| action `fishotel_foam_cuts_plan_cut` ( id, data ) | A plan was marked as cut. |
| action `fishotel_foam_cuts_plan_undone` ( id, data ) | That was undone. |

Read from elsewhere (all optional, all guarded): `FST_Settings::get_standard_boxes()`;
the Packing section's `fishotel_packing_confirmed_box( $order_id )` and
`fishotel_packing_suggest( $order_id )` — called with the order's ID, which
is what they take (handed an order object they answer "nothing" without an
error), and with the order object only when the ID is refused with an
error; its box list through `Packing\Boxes::current( Packing\Settings::get() )`
(or `fishotel_packing_boxes()` should that ever exist). An answer may be
that section's `{ boxes: [ { name, length, width, height, … } ], … }`, a
box name, a size ("16x12x10"), a box row, `{ box: … }` or a list of those,
with an optional `count` / `qty` beside a single box;
`fishotel_delivery_rules()->today()`.
Order meta read (never written): `_fishotel_shipping_date`,
`_fishotel_fulfilled_by_order`. No order meta, post meta, e-mail, HTTP
request or product stock is touched anywhere.

Stored: options `fishotel_foam_cuts_db_version` (autoloaded),
`fishotel_foam_cuts_settings`, `fishotel_foam_cuts_next_label` (not
autoloaded); tables `{prefix}fh_foam_leftovers`, `fh_foam_plans`,
`fh_foam_sheet_log`; a per-user transient for the one-time notice.

## REST

`manage_woocommerce`; cookie + `X-WP-Nonce`, or an Application Password
(HTTP Basic over HTTPS).

| Route | |
|---|---|
| `GET /wp-json/fishotel-misc/v1/foam-cuts/plan/<id>` | One plan (`<id>` may be `latest`). 404 `fishotel_foam_cuts_not_found`. |
| `GET /wp-json/fishotel-misc/v1/foam-cuts/plans` | The latest 20: id, status, title, origin, ship_date, sheets, created_at, cut_at, print_url. |
| `POST /wp-json/fishotel-misc/v1/foam-cuts/plan` | Make a draft. `{"source":"orders","ship_date":"YYYY-MM-DD"}` (date optional: today) or `{"boxes":{"<box name>":2}}`. 201 with the plan and `"reused": false`; **200 with the existing draft and `"reused": true`** when the orders of that day were already planned with the same boxes (ask as often as you like — a reprint never makes a second plan); 400 `…_unknown_box` / `…_bad_date` / `…_bad_source` / `…_no_boxes` / `…_too_many` (more than 500 of one box); 409 `…_no_plan` with the reason (and the orders found). |

No route marks a plan as cut: the person who cut the foam presses that.

```bash
curl -s -X POST 'https://example.test/wp-json/fishotel-misc/v1/foam-cuts/plan' \
  -u 'manager:abcd efgh ijkl mnop qrst uvwx' -H 'Content-Type: application/json' \
  -d '{"source":"orders"}'
```

The answer's `print_url` opens the cut sheet in the logged-in browser of
the user the request was made as (its key is bound to that user and that
plan and lasts 12–24 hours; any other link shows an "Open the cut sheet"
button instead of an error).

## The plan (schema `fishotel-foam-cuts/plan@1`)

Every size in inches; `x` runs from the left along a source's length, `y`
from the top along its width. This is the machine-ready data for a CNC
export.

```
id, status ("draft" | "cut"), title, origin ("typed" | "orders"), ship_date,
created_at, cut_at, stale, stale_reasons[], can_undo, print_url
same_day[] { id, status, title } — the OTHER plans made for the same ship day
            (cut from one of them only); reused (POST only)
schema, units ("in")
sheet     { length, width, thickness }
settings  { kerf, rotate, min_leftover { length, width } }
boxes[]   { key, name, length, width, height, count, cut_list ("saved" | "suggested"),
            pieces[] { name, length, width, count } }
sources[] { n, kind ("leftover" | "sheet"), label, leftover_id, length, width,
            pieces[]    { n, box, box_key, name, length, width (as on the cut list),
                          x, y, w, h (as it lies: w across, h down), rotated },
            cuts[]      { n, axis ("v" top to bottom | "h" left to right), at,
                          x1, y1, x2, y2, kerf, part { x, y, w, h }, of, side, text },
            leftovers[] { label, x, y, w, h },
            waste[]     { x, y, w, h },
            area, pieces_area, leftover_area, waste_area, waste_pct }
unplaced[] { box, name, length, width, count, reason, text }
summary[]  { box, key, count, pieces[] { name, length, width, per_box, total, placed, from { label: n } } }
totals    { boxes, pieces, placed, sheets, sheets_in_stock, leftovers_used, leftovers_new,
            cuts, area, pieces_area, leftover_area, waste_area, waste_pct }
labels    { from, count }
for       { origin, ship_date, delivery_date, orders[] { id, number, how, boxes[] } }
warnings[], errors[]
```

A cut's line is its near edge (`x1,y1 → x2,y2`); the blade takes from
there to `kerf` further right (axis `v`) or down (axis `h`). `part` is the
rectangle being cut, `of` the cut that made it (0 = the whole source) and
`side` which side of that cut it lies on (`near` = left / above). Cuts are
listed in an order in which each can be made.

## On the Today page

While this section is on, the Today page and its widget show one line
for it under **Shipping** — **"Foam: today's cut plan is not marked as
cut yet"** while a plan for today's ship day is still a draft, with a
link to that plan. No line otherwise, and none for a user who may not
open this section. It is hooked in wp-admin only: one small query, once
per request (`class-today-line.php`).

## Files

`class-units.php`, `class-cut-list.php`, `class-packer.php`,
`class-plan.php`, `class-svg.php` are pure. `class-store.php` is the only
file that touches `$wpdb`. `class-stock.php`, `class-planner.php` take a
store as a parameter. `class-admin.php` and `class-rest.php` are loaded
only in wp-admin / on `rest_api_init`. An ordinary front-end request reads
one autoloaded option and runs no query.

Tests: `tests/README.md`.
