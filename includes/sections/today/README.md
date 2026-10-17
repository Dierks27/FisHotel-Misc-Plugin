# Today

**Today at FisHotel**: a WordPress dashboard widget and a page under
FisHotel Tools that replace "which day are we shipping, then paginate the
orders list". One screen shows the next delivery days with order and fish
counts, the holds and UPS Store flags, and which orders still lack weather,
paperwork or tracking — so a half-finished run can resume where it stopped.

Nothing here writes to an order. Every column reads what another FisHotel
plugin already stores on the order.

## The header line

| Situation | Line |
|-----------|------|
| Tomorrow is a delivery date with open orders | `Today, Mon Oct 13 — ship day for Tue Oct 14 delivery · 6 orders · 23 fish` |
| Not a ship day, orders further out | `Nothing ships today. Next: ship Mon Oct 13 for Tue Oct 14 · 6 orders` |
| No open orders in the window | `Nothing ships today. No open orders deliver in the next 21 days.` |

A box ships the day before it lands, so **ship date = delivery date − 1
calendar day** (computed in the site zone, DST-safe). Weekends and holidays
need no special handling: the Batch plugin's delivery-date rules never let a
customer pick them, so they simply have no orders.

## The page (FisHotel Tools → Today)

One block per upcoming delivery date (the first *dates shown* dates in the
window that have open orders). Each block has the date and its ship date,
the chips, a **Needs attention** strip and a table.

| Column | Meaning | Comes from |
|--------|---------|------------|
| Order | Order number, linked to the edit screen. A combined box shows the fulfillment order with `combined: #a, #b` (its live source orders) | the order; `_fishotel_is_fulfillment`, `_fishotel_fulfillment_sources` (theme) |
| Customer | Shipping name, billing name as fallback, else *Guest* | the order |
| Destination | `City, ST` of the shipping address (billing when there is none) | the order |
| Status | WooCommerce status label | the order |
| Fish | Fish still to pack; `+N dry` below when the box also holds dry goods | **Batch ≥ 10.27**: `FisHotel_Ship_Day::build_destination()` (Invoice products expanded, `shipped` / `na` / refunded lines skipped). Otherwise: line items whose product (the parent for a variation) is in `quarantined-fish` or `inverts` count as fish, the rest as dry goods, with the same line skips |
| Weather | `HEAT PACK` / `NOTHING` / `COOLING` / `UPS STORE` / `HOLD` / `AT THE LINE` / `UNAVAILABLE` / `NOT CHECKED`, with the forecast high below and any **active NWS alerts** (`FLOOD WATCH, WIND ADVISORY`) under that | the **Weather** section's `_fh_weather_*` meta on the destination order. A result counts only when `_fh_weather_checked_for` is this delivery date. The alerts are parsed from the `Alerts: …` sentence of `_fh_weather_note` (the Weather section writes them nowhere else) |
| Invoice | `✓` when the invoice PDF exists, else `open` | **WPO WooCommerce PDF Invoices**' `_wcpdf_invoice_date` (or `_fishotel_invoice_printed_at` / any key added through the `fishotel_today_invoice_meta_keys` filter). A combined box counts when the wrapper **or every live source order** has it |
| Tracking | `✓ n · status` (tracking-number count — one per physical box — and the newest status), else `open` linking to the order edit screen where the Shipment Tracking box lives | **ShipTracker**'s `FST_Shipment::get_by_order()`. A combined box counts when the wrapper has a shipment **or every live source order** has one; ShipTracker fans one label out to a row per source order, so the same tracking number on several sources counts once |
| Note | A speech-bubble icon when the customer left a note at checkout | `$order->get_customer_note()` on the order, or on **any live source** of a combined box (the theme never copies the note onto the wrapper) |

A combined box (fulfillment wrapper) is **one** destination. Orders folded
into a wrapper (`_fishotel_fulfilled_by_order` set) are skipped — the wrapper
carries them. Source orders that are cancelled, refunded, failed or trashed
are ignored everywhere (label, counts, invoice, tracking), the same rule the
Weather section's `live_sources()` applies.

**Sort order** within a date: HOLD, UPS STORE, AT THE LINE, UNAVAILABLE,
NOT CHECKED, then the rest — by order number inside each group. With the
Weather column toggled off the rows are by order number only.

**Chips** per date: orders, fish, dry goods, holds, UPS Store, at the line,
unavailable, need weather, need invoice, need tracking (a chip is shown only
when its column is, and the weather counts are not computed while the
Weather column is off).

**Needs attention** lists every destination with something to do, weather
trouble first (hold, UPS Store, at the line, an active NWS alert,
unavailable, not checked), then missing paperwork (invoice, tracking), each
with all of its reasons. The strip, the chips and the sort honour the Weather
toggle: with the column off no weather reason is raised.

**Links** under each block: *Weather overview for this date* (the Weather
section's page with `?date=`; shown when that section is enabled) and *Ship
Day pick list* (the Batch plugin's Invoicing → Ship Day tab; shown when Batch
≥ 10.27 is active **and** the user has `manage_options`, the capability that
page is registered with — the tab picks its own date).

A column whose plugin is not active is hidden with a one-line note
(*Invoice PDF check needs the PDF Invoices plugin* / *Tracking check needs
the ShipTracker plugin*), whatever the toggle says.

## The dashboard widget

**Today at FisHotel** (`manage_woocommerce`, light WordPress styling): the
header line, the first date's chips and its destinations as a compact list
(order link · customer · weather badge · the open checklist items as small
grey words such as `invoice · tracking`), then one line per further date
(`Wed Oct 15 · 4 orders · 2 need weather`), a link to the page and a
**Refresh** link.

## Caching and Refresh

The collected window is stored in one transient (`fishotel_today_cache`) for
*cache minutes* (default 5; 0 = off). The transient carries a key made of
today's date and a hash of the settings that shape the result, so a new day
or a changed setting is a miss. It is dropped by:

- the **Refresh** button on the page (AJAX, nonce + `manage_woocommerce`),
- the **Refresh** link on the widget (nonce'd link, back to the dashboard),
- every settings save,
- `woocommerce_order_status_changed` and `woocommerce_update_order` — one
  `delete_transient()` per order save, so the widget never lies for long,
- `woocommerce_order_note_added` — ShipTracker adds an order note for every
  shipment row it creates without saving the order, so this is what makes
  Tracking turn to `✓` at once,
- `wpo_wcpdf_save_document` — the PDF plugin writes `_wcpdf_invoice_date`
  through `save_meta_data()` (no order save), so this is what makes Invoice
  turn to `✓` at once.

The page shows *Built just now* / *Cached N min ago* next to the button.

## Where "today" comes from

`fishotel_delivery_rules()->today()` when the Batch plugin ≥ 10.26 is active
(the delivery rules' zone), else `current_time( 'Y-m-d' )` — always the
site-zone calendar date. The orders are found with **one** store-agnostic
query over the whole window (the Weather section's
`Checker::open_orders( $statuses, $dates )`, HPOS and CPT alike).

## Settings (FisHotel Tools → Today → Settings, `manage_options`)

| Setting | Default | Range |
|---------|---------|-------|
| Look ahead (days) | 21 | 1–60 |
| Delivery dates shown | 3 | 1–7 |
| Order statuses that count as open | processing, on-hold, fulfillment, shipped | only statuses WooCommerce knows |
| Weather / Invoice / Tracking columns | on | — |
| Cache (minutes) | 5 | 0–1440 (0 = off) |

Option: `fishotel_today_settings`. The save is an AJAX call with a nonce and
`manage_options`; anything out of range falls back to its default and the
reply says so.

`shipped` is in the default list on purpose: ShipTracker flips a fully
covered order to `shipped` the moment its tracking is saved (and the theme
does the same for a combined box once every live source is shipped), so
without it a row would vanish exactly when Tracking turns to `✓`, the
ship-day counts would shrink during the run and the header would say
*Nothing ships today* once the last box went out. Shipped rows keep their
fish counts (the theme's `_fishotel_line_status = shipped` is only written
by hand) and simply leave the attention strip when everything is done.

## Filters

- `fishotel_today_invoice_meta_keys( string[] $keys )` — the order meta keys
  that mean "invoice PDF exists" (default `_wcpdf_invoice_date`,
  `_fishotel_invoice_printed_at`). Adding a key of your own makes the Invoice
  column available even without the PDF plugin.
- `fishotel_ship_day_fish_categories( string[] $slugs )` — honoured by the
  local fish count when the Batch plugin's Ship Day class is absent (same
  filter name, same default: `quarantined-fish`, `inverts`).

## Files

```
today/
├── class-today.php        info, load, boot, register_menu (no cron, no tables)
├── class-model.php        PURE: window, ship plan, weather state, badge, checklist,
│                          chips, needs-attention, sorting, labels, cache key
├── class-collector.php    the only file that touches WooCommerce / orders / sibling
│                          plugins; builds the arrays, caches the window
├── class-settings.php     option, defaults, sanitised AJAX save
├── class-admin.php        page, dashboard widget, Refresh (AJAX + link)
├── views/page.php         the page
├── views/widget.php       the dashboard widget (one inline style block)
├── views/settings.php     the settings tab
├── css/today-admin.css    page styles (dark, on admin-style.css tokens)
├── js/today-admin.js      Refresh, ticking freshness label, settings save
└── tests/                 headless PHP + node tests (see tests/README.md)
```
