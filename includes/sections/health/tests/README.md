# Health — headless tests

All run without WordPress or a database. `wp-stubs.php` supplies the
handful of WordPress functions the classes touch (options, transients,
WP-Cron, the action registry, `wp_mail`, `WP_Error` — all in-memory arrays
a test can seed and read back); `fake-store.php` replaces the `$wpdb` Store
with an in-memory one (insert / find_recent / bump_repeat / recent_errors /
count_since). Both helpers exit 0 when CI runs them directly.

```
php  includes/sections/health/tests/logger-test.php
php  includes/sections/health/tests/checks-test.php
php  includes/sections/health/tests/capture-test.php
php  includes/sections/health/tests/csv-test.php
node includes/sections/health/tests/admin.test.js
```

- **logger-test.php** — level validation (unknown → info, case/space
  insensitive); source cleaning; message truncation to ≤ 2000 bytes without
  splitting a UTF-8 character; redaction of `password` / `*_token` /
  `Authorization` / `client_secret` / `cookie` / `nonce` / `order_key`
  keys at every depth; scrubbing of `Bearer …`, `ghp_…`, `github_pat_…`,
  `Basic …` and `access_token=` inside values and the message; Throwable /
  WP_Error / object handling; the 8 KB context cap in all three passes,
  also for multibyte strings (cut without splitting a character, so the
  JSON stays encodable) and for a caller-supplied invalid UTF-8 byte;
  the fingerprint ignoring digits; debug dropped unless `store_debug`;
  the 10-minute repeat dedupe (same id, `repeats` counter, `logged_at`
  moved, new row after the window, different level = new row) and
  `sum_repeats_since()` counting the folded events; the after-insert
  callback (once per insert, never for a repeat, a throwing callback is
  harmless); a throwing store → `null`, never an exception.
- **checks-test.php** — a healthy fixture produces only ok/info; then every
  rule one at a time: inactive/missing plugins (Misc → info), theme not
  FisHotel, WooCommerce inactive; update age at 24 h 59 min (ok), exactly
  25 h (ok), 25 h + 1 s (warn), never (warn), inactive plugin (no row); the
  GitHub 401/403 row and its exact hint; dompdf missing and the
  packing-slip hint; cron: not scheduled vs owner inactive, overdue at
  2 h + 1 s vs exactly 2 h, Weather enabled with the nightly missing,
  SMTP active with *Enable Auto-Retry* off → info "retry off" (and
  `Facts::cron()` / `Facts::sections()` deriving that from the plugin's
  `fhsmtp_log_settings`); DISABLE_WP_CRON with no run / 1 h + 1 s /
  exactly 1 h / 10 min; the fan-out constant and option; the
  fst-debug.log warning text including the size; PHP < 8, the effective
  (front-end) memory limit 128M while wp-admin runs at 256M → warn
  showing both, unlimited, the pre-effective_* fallback, disk 1.5 GB /
  400 MB / unknown, WP_DEBUG_DISPLAY only with WP_DEBUG (`Facts::wp()`
  on core's default constants → ok); mail failures (summed repeats via a
  fake store through `Facts::collect()`) and the last-five error rows:
  error up to exactly 24 h old, info after, a 3-week-old fatal alone →
  summary ok, multibyte messages cut without splitting a character
  (`Checks::cut()`); `summary()` worst status and counts; `problems()`
  ordering; the relative-age / KB / bytes helpers; empty facts.
- **capture-test.php** — the shutdown translation ignores E_WARNING /
  E_NOTICE / E_DEPRECATED and reports every fatal type with the path made
  relative to ABSPATH and the stack trace out of the headline;
  `http_api_debug`: non-GitHub hosts and 2xx/3xx ignored, 401/403 → error +
  auth flag, 404 → error, 5xx → warning, WP_Error → error, query string
  stripped, context has no header or body, nothing in the row contains the
  token, the settings' extra hosts (a 401/403 from one is a row but never
  the auth flag), case-insensitive host match; the end to end hook sets
  the 24 h transient for GitHub and not for an extra host; `wp_mail_failed`
  keeps to + subject and never the body/headers; the SMTP action; the
  `fishotel_log` action and the global helper (non-array context wrapped,
  debug dropped); re-entrancy (one nested capture is stored, the next
  level is cut, the depth unwinds); the alert email: qualifying rows, one
  send per cooldown, a new send after it, and a failing alert mail that is
  logged as a `mail` row to the alert address without sending again or
  looping.
- **csv-test.php** — the formula guard on `= + - @ \t \r`, numbers,
  arrays; `csv_row()` column layout; `filters_from_request()` and the
  filter dropdown sources; `request_args()` round-tripping the search as
  `s` through the CSV URL; `write_csv()` paging past the store's 500-row
  clamp (620 rows, filters and search applied, newest first), stopping at
  5000 with a trailing `notice` row, and an empty store.
- **admin.test.js** — `filterRows()` (level, source, ANDed search terms,
  case-insensitivity, null rows, no mutation), `escapeHtml()`,
  `levelClass()`.

## By hand, once, on a real site

1. FisHotel Tools → Dashboard → enable **Health**. The dashboard widget
   *FisHotel Health* must appear on the WordPress home with a pill and the
   plugin versions line.
2. Health → Overview: every group must render; the Updates group should be
   green once each plugin has done one update check.
3. Add `do_action( 'fishotel_log', 'error', 'test', 'hello 1' );` to a
   mu-plugin, load a page twice within 10 minutes: the Log tab must show
   ONE row with `×2`. Remove the line.
4. Download CSV: the file must open in a spreadsheet with the same rows and
   no formula execution.
5. Temporarily set a wrong GitHub token in wpit-updater and click *Check
   for Updates*: the Updates group must show the red *GitHub returned 401*
   row and the Log tab a row with source `updater` and no token in it.
