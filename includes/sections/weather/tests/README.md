# Weather — headless tests

All run without WordPress or the network. `wp-stubs.php` supplies the
handful of WordPress functions the classes touch (options, `current_time`
and WP-Cron are in-memory arrays a test can seed and read back); the
network client is replaced by `fake-client.php`, which answers from
`fixtures/`, and `fake-order.php` is just enough `WC_Order` plus a registry
that `wc_get_order()` / `wc_get_orders()` answer from.

```
php  includes/sections/weather/tests/bands-test.php
php  includes/sections/weather/tests/note-test.php
php  includes/sections/weather/tests/forecast-test.php
php  includes/sections/weather/tests/schedule-test.php
php  includes/sections/weather/tests/checker-test.php
node includes/sections/weather/tests/admin.test.js
```

- **bands-test.php** — the skill's chart, high by high: 25, 30, 35, 40, 41,
  70, 89, 90, 95, 96 (plus 76, 80, 81, 91, 94) → expected action and the
  exact note ending; the "AT THE LINE" flag and its more-cautious stored
  action; custom thresholds; every way a broken threshold set falls back to
  the defaults.
- **note-test.php** — `Note::format()` byte-equal against
  `fixtures/note-example.txt` (the skill's example with its band ending);
  the no-low, alerts, heat-pack, UPS Store, HOLD and AT THE LINE shapes;
  the next-day extremes line; the unavailable note; the UPS Store email
  draft's subject, wording and signature.
- **forecast-test.php** — `fixtures/nws-forecast.json` (a real NWS period
  shape: "Tonight", "Wednesday", "Wednesday Night", …, including a °C
  period and a day with no night): picking the daytime period for a date,
  the low from the night period, unit conversion, dates outside the
  window; `fixtures/nws-points.json`, `fixtures/census-geocode.json` and a
  Zippopotam response; alert filtering by date from `fixtures/nws-alerts.json`;
  then `Checker::lookup()` end to end through a fake client — the fixture
  note comes out byte-equal (plus the next-day line the fixture's Thursday
  98°F earns), and every failure stage yields `unavailable`, never a band.
- **schedule-test.php** — `Cron::next_run_timestamp()` in a real timezone
  across both DST changes; the today-through-+3 scan window and the
  two-day refresh rule; time cleaning; `Settings::sanitize()` fallbacks and
  their reporting.
- **checker-test.php** — persistence and the nightly scan on fake orders:
  a failed re-check after a success removes `_fh_weather_checked_for`
  (so `note_status()` reads unavailable and the nightly path retries), one
  failure note per delivery date whatever the reason says, the UPS Store
  draft removed on failure; `run_nightly()` with the toggle off still
  checks, refreshes orders within two days (no second note when nothing
  changed), leaves a noted +3 order alone until it comes within two days,
  covers today when the event fires after midnight, never touches
  completed / folded / undated / out-of-window orders; fan-out to a
  combined box skips refunded and cancelled sources and addresses the
  draft with the source's own number; `Cron::run()` does nothing when
  disabled and arms tomorrow's run before the scan, so a scan that dies
  cannot lose it.
- **admin.test.js** — the overview's totals helper (cold+hot UPS Store and
  holds merged, blanks counted as not checked, no prototype leaks), HTML
  escaping, and the note-status class.

## By hand, once, on a real site

1. Settings → tick *Enable*, save. The "Next run" line must show tonight at
   the run time in site time.
2. Open an order delivering tomorrow → *Check weather now*. The meta box
   must show a badge, the high/low, and a note whose text appears as the
   newest private note on the order, in the skill's format.
3. Overview → the date chip for tomorrow must list that order with the same
   badge; totals must match the rows.
4. Change the order's delivery date → the meta box must show the "noted for
   … but the delivery date has changed" warning and the orders-list column
   must show —.
5. Give an order a non-US shipping country → *Check weather now* must say
   unavailable and the note must say "check manually", with no band.
