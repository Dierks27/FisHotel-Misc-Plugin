# Weather

Step 3 of the Monday/Tuesday/Wednesday order routine, done on the server:
the delivery-day forecast at each order's destination, the weather note in
the exact format the `fishotel-order-workflow` skill prescribes, and the
packing action (heat pack / nothing / cooling packs / UPS Store pickup /
hold) recorded on the order. The UPS Store bands also get a drafted
customer email. Nothing here sends email or changes an order status — the
hold and UPS Store decisions stay with Jeff.

## What it does

Every night at the configured time (default 21:00 site time) one WP-Cron
event runs. It finds every order in the scanned statuses (default
processing, on-hold, fulfillment) whose `_fishotel_shipping_date` — the
delivery date written by the Batch plugin — is today through three days
out (today is included because WP-Cron is request-driven and a quiet
night's event often fires after midnight), skips orders already noted for
that date (`_fh_weather_checked_for`) unless the date is within two days —
those are re-checked every night so the morning's packing decision works
from the previous evening's forecast, with a new note only when the
numbers moved — and for each remaining order:

1. takes the shipping address (billing when there is none) — a
   fulfillment order (combined box, `_fishotel_is_fulfillment = '1'`) is
   the destination; orders already folded into a fulfillment
   (`_fishotel_fulfilled_by_order`) are skipped because the wrapper carries
   them;
2. geocodes it with the free US Census geocoder, falling back to the ZIP
   centroid (Zippopotam) for rural routes and PO boxes the Census cannot
   match;
3. asks the National Weather Service for the point's gridpoint, its daily
   forecast and its active alerts;
4. takes the **daytime period for the delivery date** (high) and that
   date's night period (low), plus the day after for the extremes line;
5. classifies the delivery-day HIGH into a band, builds the note, and
   writes **one private order note** plus structured meta;
6. with *also note the source orders of a combined box* on, writes the same
   note and meta to each source order of a fulfillment so the customers'
   own orders carry it — skipping sources that are cancelled, refunded or
   failed (the theme leaves them listed in the wrapper's meta, but they
   will not ship and must not get a note or a customer email draft).

Anything that fails between the address and the forecast — no match, NWS
down, a date beyond the 7-day forecast, a non-US address — produces the
`unavailable` action and a *"weather unavailable — check manually
(reason)"* note. It never guesses a band from partial data. A failed
lookup also drops the success marker, even when that date had been noted
before, so the overview shows *Unavailable* rather than the earlier band
and the nightly job retries the next night. Only one failure note is
written per delivery date — the reason text carries transport detail (HTTP
code, cURL message) that varies night to night; the latest reason is kept
in the summary meta and shown in the meta box.

## The note

Format, byte for byte from the skill (only the values change):

```
Weather for [DELIVERY DATE] at [ZIP] ([City, State]): High [X]°F / Low [Y]°F — [Conditions]. [Alerts: …, if any.] [Band ending.] [Next-day extreme, if any.]
```

```
Weather for April 1, 2026 at 23314 (Carrollton, VA): High 76°F / Low 57°F — Sunny, 10% chance of rain. Nothing needed — great shipping conditions.
```

- Date is `F j, Y`; ZIP, city and state come from the order's shipping
  address (what Jeff sees), not from the geocoder.
- Conditions are the NWS `shortForecast` plus `, N% chance of rain` when the
  probability of precipitation is above zero (`snow` when the forecast is
  wintry).
- When only the high is known the `/ Low Y°F` part is left out rather than
  a number invented.
- Active NWS alerts covering the delivery date appear as `Alerts: Flood
  Watch, Wind Advisory.` between the conditions and the ending.
- The next-day extremes line (`Thu hits 98°F — do not slip delivery.` /
  `Thu drops to 28°F — do not slip delivery.`) is appended only when the day
  after delivery falls in a UPS Store or hold band.

### Bands — every one goes by the delivery-day HIGH at the destination

| Delivery-day high | Stored action    | Note ending (verbatim) |
| --- | --- | --- |
| below 30°F | `hold_cold`      | `HOLD: below 30°F — wait to ship.` |
| 30–40°F    | `ups_store_cold` | `UPS STORE (30-40°F): ship to local UPS Store for customer pickup. Email drafted for customer.` |
| 40–70°F    | `heat_pack`      | `Heat pack.` |
| 70–80°F    | `none`           | `Nothing needed — great shipping conditions.` |
| 80–90°F    | `cooling`        | `Cooling packs.` |
| 90–95°F    | `ups_store_hot`  | `UPS STORE (90-95°F): ship to local UPS Store for customer pickup. Email drafted for customer.` |
| above 95°F | `hold_hot`       | `HOLD: above 95°F — no ship.` |

A high **exactly on a line** (30, 40, 70, 80, 90, 95) ends the note with
`AT THE LINE (90°F) — Jeff's call: Cooling packs (80-90°F) or UPS STORE
(90-95°F).` and is stored as the more cautious of the two bands (the one
further from "nothing needed"), so the overview never under-packs while
Jeff decides; the badge carries an "AT THE LINE" prefix and a yellow ring.
The thresholds are editable in Settings; the endings follow them.

### UPS Store email draft

For the two UPS Store bands a draft is stored in `_fh_weather_email_draft`
(subject + body) and shown in the meta box with a Copy button. Subject:
`Your FisHotel Order #[NUM] — Weather Shipping Change`. Body: the
destination hits X°F on delivery day (too hot / too cold for a box on the
porch), shipping to a UPS Store near them for pickup so the fish stay
climate-controlled, asks for a preferred UPS Store (or the closest to their
address), tracking follows on ship day, signed "Jeff — FisHotel". **It is
never sent** — Jeff sends emails himself.

## Order meta written

| Key | Value |
| --- | --- |
| `_fh_weather_checked_for` | Delivery date (Y-m-d) the stored result is for. Set by a successful lookup and removed by a failed one; the nightly job skips an order whose value equals its delivery date unless that date is within two days (then it is refreshed). |
| `_fh_weather_checked_at` | Site-local datetime of the last lookup. |
| `_fh_weather_high`, `_fh_weather_low` | Integers °F (`''` when unknown). |
| `_fh_weather_action` | `heat_pack` / `none` / `cooling` / `ups_store_cold` / `ups_store_hot` / `hold_cold` / `hold_hot` / `unavailable`. |
| `_fh_weather_at_line` | `'1'` when the high was exactly on a line. |
| `_fh_weather_summary` | `High 76°F / Low 57°F — Sunny, 10% chance of rain` (or the failure reason). |
| `_fh_weather_note` | The full note text last written. |
| `_fh_weather_email_draft` | `{subject, body}` for the UPS Store bands; removed otherwise. |
| `_fh_weather_unavailable_for` | Delivery date of the last failed lookup (so the failure note is written once per date). |

## Admin

- **FisHotel Tools → Weather → Overview** — pick a delivery date (default:
  the next delivery date with open orders; chips list every upcoming date
  with its count). Totals per action at the top (heat packs, cooling packs,
  UPS Store, holds, nothing needed, unavailable, not checked), then one row
  per order: order, customer, destination, high, low, action badge, note
  status, *Check now*. A *Run nightly scan now* button runs the job on
  demand — also with the nightly job switched off, since the toggle only
  governs the cron event. This replaces the "Pick List + Weather Overview"
  the browser skill produced.
- **Settings tab** — enable toggle, run time, contact email for the NWS
  User-Agent, statuses to scan, the six thresholds with *Reset to defaults*,
  and the source-orders toggle. Saved by AJAX (nonce + `manage_woocommerce`);
  saving re-arms the cron event.
- **Order screen** — a *Weather* meta box (legacy `shop_order` and the HPOS
  `wc-orders` screen) with the delivery date, destination, badge, high/low,
  the stored note, the email draft with *Copy email*, and *Check weather
  now* (AJAX, nonce + `manage_woocommerce`) which re-runs the lookup and
  swaps in the freshly rendered box. A stale warning appears when the
  delivery date changed since the note was written.
- **Orders list** — a *Weather* column (classic and HPOS tables) showing the
  badge, or — when not checked / noted for another date, and a *Check
  weather* bulk action.

## Settings

Option `fishotel_weather_settings`:

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | on | Off = no cron event; manual checks and *Run nightly scan now* still work. |
| `contact_email` | blank | NWS asks clients to identify a contact; blank uses the site admin email. User-Agent is `FisHotel/<version> (<email>)`. |
| `run_time` | `21:00` | HH:MM, site timezone. One single event, re-armed at the start of each run — before the scan, so a run that dies on a cold cache cannot lose tomorrow's (DST-safe). |
| `statuses` | processing, on-hold, fulfillment | Only statuses WooCommerce knows are kept; none ticked = defaults. |
| `thresholds` | 30 / 40 / 70 / 80 / 90 / 95 | Six whole numbers, each higher than the last; anything else restores the defaults wholesale (a half-valid chart would shift every band). |
| `note_sources` | on | Also note each source order of a combined box. |

## Caching

Transients: geocodes (`fh_weather_geo_*`, keyed by the normalised address)
and gridpoints (`fh_weather_pt_*`, keyed by rounded lat/lon) for 90 days;
forecasts (`fh_weather_fc_*`, keyed by gridpoint — one fetch serves the
delivery date and the day after) for 6 hours; alerts for 1 hour. Every
request goes through `wp_remote_get` with a 10-second timeout.

## Limits

- The NWS API covers **US addresses only**; anything else is reported
  unavailable without a network call.
- NWS daily forecasts run **7 days**; a delivery date beyond that is
  unavailable until it comes into range (the nightly job only looks 3 days
  out, so this only shows up on manual checks).
- A ZIP-centroid geocode (shown as source `zip`) is the ZIP's centre, which
  for a large rural ZIP may sit on a different gridpoint than the house;
  the forecast is still for that ZIP.
- Alerts are a nice-to-have: if the alerts call fails the note is still
  written, without the `Alerts:` sentence.
- Nothing is sent and no order status is changed. Holds and UPS Store
  pickups are flagged for Jeff, not acted on.

## Files

| File | Responsibility |
| --- | --- |
| `class-weather.php` | Section entry: info, boot, menu, activate/deactivate. |
| `class-bands.php` | Pure band classifier and the note endings. |
| `class-note.php` | Pure note / email-draft / unavailable-note formatter. |
| `class-forecast.php` | Pure NWS / Census / Zippopotam JSON parsing and the period picker. |
| `class-nws-client.php` | The only networked file: geocode → point → forecast → alerts, via `wp_remote_get`, cached in transients. |
| `class-checker.php` | One order end to end, persistence, the nightly scan (window, refresh rule, live-source fan-out), store-agnostic order query. |
| `class-cron.php` | The single nightly event in the site timezone, gated on the enable toggle and armed before each scan. |
| `class-settings.php` | Option storage, defaults, sanitised AJAX save. |
| `class-admin.php` | Overview + settings page, meta box, column, bulk action, AJAX. |
| `views/overview.php`, `views/settings.php`, `views/meta-box.php` | Markup; every value escaped. |
| `css/weather-admin.css`, `js/weather-admin.js` | Dark section page + light order-screen styling; AJAX wiring and the pure totals helper. |

## Tests

```
php  includes/sections/weather/tests/bands-test.php
php  includes/sections/weather/tests/note-test.php
php  includes/sections/weather/tests/forecast-test.php
php  includes/sections/weather/tests/schedule-test.php
php  includes/sections/weather/tests/checker-test.php
node includes/sections/weather/tests/admin.test.js
```

See `tests/README.md`.
