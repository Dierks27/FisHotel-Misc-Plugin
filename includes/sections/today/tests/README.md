# Today — headless tests

All run without WordPress, WooCommerce or a database. `wp-stubs.php`
supplies the handful of WordPress functions the classes touch (options,
transients, `current_time`, `wp_timezone` = America/Chicago, the action and
filter registries, `wc_get_order_statuses`, `WP_Error` — all in-memory) and
loads the Weather section's pure `Note` / `Bands` / `Checker` the Today
section reuses. `fake-order.php` is just enough `WC_Order` (meta, status,
address, line items with products and categories, customer note, edit URL)
plus a registry that `wc_get_order()` / `wc_get_orders()` / `get_the_terms()`
answer from. Both helpers exit 0 when CI runs them directly.

```
php  includes/sections/today/tests/model-test.php
php  includes/sections/today/tests/collector-test.php
node includes/sections/today/tests/admin.test.js
```

- **model-test.php** — `window()` across the March and November DST
  changes, a year end and the 1–60 clamp; `ship_plan()` for a ship day, an
  idle day, no orders, today the last day of a month, the DST change day,
  and past / today / malformed dates; `date_label()` (`Tue, Oct 14`) and
  `ship_date_for()` in America/Chicago including both DST days and month /
  year boundaries; `weather_state()` from the `_fh_weather_*` keys (this
  date, another date, unavailable) including the NWS alerts parsed from the
  note (`parse_alerts()`); every badge; `checklist()` with each `show_*`
  flag off; `chips()`, `needs_attention()` ordering (alerts between at the
  line and unavailable) and `sort_destinations()` on a fixture of six
  destinations including a wrapper with two sources, and all three with
  the Weather flag off (no weather reason, chip or rank); the header line,
  the widget's date summary, the order label; `cache_key()` changing with
  today and each shaping setting (not the TTL); `Settings::sanitize()`
  fallbacks and clamping on read.
- **collector-test.php** — a fixture of fake orders pinned to Mon
  2025-10-13: ONE `wc_get_orders()` call for the whole window; counts and
  blocks per date; the plan; a wrapper as one destination with the
  combined label from its live sources only (a cancelled source and a
  missing id skipped), fish counted from the live sources and never from
  the wrapper's copied lines, `shipped` / `na` lines and refunds skipped, a
  variation classified through its parent; folded sources never
  destinations; completed / undated / today / past / out-of-window orders
  excluded; a wrapper whose sources do not load (its own copies count) and
  one whose sources are all dead (nothing); the invoice column hidden with
  its note without the PDF plugin, available through a filter key of its
  own, then through a runtime-declared `WPO_WCPDF`, accepted from the order
  and from every live source; the tracking column hidden with its note
  while `FST_Shipment` is absent, then with a fake: count, newest status
  and its label, the wrapper's own rows vs. every source, and the real
  fan-out shape (one tracking number on two source orders counts once);
  the note icon from a live source; the Weather column toggled off
  (number-ordered rows, empty strip); a Flood Watch in the note reaching
  the attention strip; caching (TTL, key = today + settings hash, a changed
  setting and a new day miss, `clear_cache()`, `cache_minutes` 0, the
  `woocommerce_update_order`, `woocommerce_order_note_added` and
  `wpo_wcpdf_save_document` hooks); the empty window; a runtime-declared
  `FisHotel_Ship_Day` fake (fish / dry and label from Ship Day, place and
  customer still from the order).
- **admin.test.js** — `fmt()`, `ageLabel()` (just now / minutes / hours,
  garbage ages, i18n strings) and HTML escaping.

## By hand, once, on a real site

1. Enable the section on the FisHotel Tools dashboard. The **Today at
   FisHotel** widget must appear on the WordPress dashboard with the header
   line and the next delivery date's orders.
2. Open FisHotel Tools → Today. The first block must be the same date; its
   chips must match the rows; a combined box must appear once, with its
   source numbers.
3. Change an order's status → reload the dashboard: the widget must reflect
   it at once (the cache is dropped on every order save).
4. With the Weather section enabled and the nightly scan run, the Weather
   badges must match the Weather overview for that date; the *Weather
   overview for this date* link must open that date.
5. Generate an invoice PDF on an order → Invoice shows `✓` at once (no
   Refresh); add tracking in the Shipment Tracking box → Tracking shows
   `✓ 1 · Label Created` at once, and the row stays on the page after the
   order flips to Shipped. A combined box with one label shows `✓ 1`, not
   one per source order.
