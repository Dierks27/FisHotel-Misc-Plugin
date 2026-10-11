# Health

One error log every FisHotel plugin and the theme can write to, automatic
capture of the failures that are invisible today, and a **FisHotel Health**
dashboard widget + page under FisHotel Tools that shows dompdf, plugin
versions, kill switches, the last update checks, WP-Cron and the last five
errors.

Until now a failed EA packing-slip email, an expired GitHub token and a
white-screen PHP fatal all left no trace Jeff could see. This section adds
one table (`{prefix}fh_log`), one action (`fishotel_log`) and one widget.

## What it captures on its own

| Source    | Trigger | Level | What is stored |
|-----------|---------|-------|----------------|
| `php`     | A fatal that ends a request (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`, `E_RECOVERABLE_ERROR`) — via a shutdown handler registered at `plugins_loaded` | critical | `E_ERROR: <first line> in <file relative to ABSPATH>:<line>`, plus the request path without its query string. Warnings and notices are ignored. Skipped under WP-CLI. |
| `mail`    | `wp_mail_failed` | error | The error message, the recipient list and the subject. **Never the body, the headers or attachments.** |
| `smtp`    | `fhsmtp_email_failed( $connection )` from the FisHotel SMTP plugin | error | Which connection (primary / backup) failed. |
| `updater` | `http_api_debug` for `github.com`, `api.github.com`, `raw.githubusercontent.com`, `codeload.github.com`, `objects.githubusercontent.com` (plus any host in *Extra hosts to watch*) | error for a connection error or 4xx, warning for 5xx | `GitHub returned 401 for api.github.com/repos/…` — host + path, query string removed, **no header and no token anywhere**. A 401/403 **from a GitHub host** also sets a 24-hour transient (`fishotel_health_github_auth_failed`) the checks read; an extra host's 401/403 only gets its row. |

Fatals and HTTP capture can be switched off in Settings; the other three are
always on while the section is enabled.

## How other plugins and the theme should log

No dependency on this section is needed — fire the action and it is a no-op
when Health is disabled:

```php
do_action( 'fishotel_log', 'error', 'theme/packing-slip', 'EA email failed', array( 'order' => 123 ) );
```

or, once `plugins_loaded` has run, the helper (defined only if nothing else
defined it). The helper exists **only while the Misc plugin is active and
the Health section is enabled** — with the section toggled off it is
undefined and a bare call fatals — so always guard it (or use the action,
which is always safe):

```php
if ( function_exists( 'fishotel_log' ) ) {
	fishotel_log( 'warning', 'batch/northstar', 'NSA price list fetch timed out', array( 'url' => $url ) );
}
```

- **Level**: `debug` · `info` · `warning` · `error` · `critical`. Anything
  else is stored as `info`. `debug` is dropped unless *Store debug rows* is
  on.
- **Source**: a short tag, letters/digits/`_ . / -`, up to 60 chars —
  `plugin/area` reads well in the filter (`theme/packing-slip`,
  `batch/verification`, `shiptracker/easypost`).
- **Message**: one line, up to 2000 characters. Put details in the context,
  not the message — the message (minus digits) is the dedupe key.
- **Context**: any array. Values whose keys match
  `pass|secret|token|key|authorization|cookie|nonce` are replaced with
  `[redacted]` (recursively); strings that carry a `Bearer …`, `ghp_…`,
  `github_pat_…` or `Basic …` blob are scrubbed; the JSON is capped at 8 KB.
  `Throwable`s become class/message/code. Do not pass email bodies, request
  bodies or anything you would not want in a CSV.
- **Dedupe**: the same level + source + message (digits ignored) within
  10 minutes bumps a `×N` counter on the existing row instead of adding a
  row, so a loop produces one line, not a thousand.

## The checks and what to do about them

The Overview page and the dashboard widget evaluate the same rules; the
widget shows the summary pill, every error/warning (max 8) and the last
five errors with their age.

**Plugins**
- *FisHotel Batch Manager / ShipTracker / SMTP / FH Boards / WPIT Updater
  inactive or not installed* → warning. Activate or install it if it is
  meant to run here. (Misc itself can only ever be info.)
- *Theme is not FisHotel* → error. The medication store, packing slips and
  order screens live in the theme.
- *WooCommerce inactive* → warning.

**Updates**
- *last update check never / N h ago* (over 25 h) → warning. Each updater
  caches its GitHub check in a transient; a stale or missing one means
  WP-Cron is not running or the request is failing. Open Plugins → *Check
  for Updates* on that plugin and look at the Log tab, source `updater`.
- *GitHub returned 401 — the token is expired or the repo is private; see
  wpit/updater settings* → error. Put a fresh fine-grained token in
  wp-config (`WPIT_GITHUB_TOKEN`) / the updater settings, or make the repo
  public again. The error clears itself 24 h after the last 401/403.

**Paperwork**
- *dompdf missing — EA packing slips send without the PDF* → error. The
  theme's `vendor/` (Composer, with dompdf) was not deployed. Run
  `composer install --no-dev` in the theme or upload `vendor/`.

**Cron**
- *WP-Cron: DISABLE_WP_CRON is on and no run in over 1 h* → error. The host's
  system cron that should hit `wp-cron.php` is not running.
- *<event> not scheduled* while its plugin/section is active → error.
  Deactivate and reactivate that plugin (Weather: save its settings) to
  re-arm the event. Exception: FisHotel SMTP schedules *SMTP retry failed
  emails* only while its *Enable Auto-Retry* setting is on (off by
  default), so with retry off the row is info *retry off*, never an error.
- *WP-Cron overdue* (an event due more than 2 h ago) → warning. On a quiet
  site WP-Cron only runs when someone visits; set up a system cron or just
  load the front end once.

**Kill switches** (only when ShipTracker is installed)
- *ShipTracker fan-out is OFF* → warning. `FST_FULFILLMENT_FANOUT_OFF` is
  defined or option `fst_fulfillment_fanout_enabled` is `0`: tracking added
  to a combined box no longer reaches the source orders, so those customers
  get no shipment email. Remove the constant / set the option back to `1`
  once the reason for the switch is gone.
- *fst-debug.log is web-readable at /wp-content/fst-debug.log (N KB)* →
  warning. ShipTracker's debug log is written into the web root. Turn
  *Debug Logging* off in ShipTracker → Settings → Advanced and delete the
  file. Moving the file out of the web root is a ShipTracker change and is
  pending there.

**Server**
- *WP_DEBUG and WP_DEBUG_DISPLAY both on* → warning: notices are printed
  to visitors. (Core defines `WP_DEBUG_DISPLAY = true` whenever wp-config
  does not, but only honours it inside `WP_DEBUG`, so the constant alone
  is not a warning.)
- *PHP < 8.0* → info. *memory_limit < 256M* → warning — judged on the
  limit front-end, admin-ajax and admin-post requests get (the php.ini
  value raised to `WP_MEMORY_LIMIT`), which is what dompdf and the Batch
  imports run under, not the limit wp-admin raises for its own pages; the
  row shows both when they differ (`128M (admin 256M)`). *Disk free <
  2 GB* → warning, *< 500 MB* → error.

**Errors**
- *Mail failures (24 h) > 0* → error, with the count of failures (folded
  repeats are summed, so a burst of 30 reads 30, not 1). Check FisHotel
  SMTP's connection status and FisHotel SMTP → Email Logs for every
  recipient and subject; the Log tab here (source `mail`) has the folded
  rows.
- Each of the last five error/critical rows is listed with its age: error
  for 24 h, then listed as info — so one old fatal does not keep the pill
  red for the whole retention period.

## Alert email

Settings → *Send alerts to*. When set, a **new** error/critical row from
source `php`, `mail` or `updater` sends one plain-text email per cooldown
window (default 60 min). Repeats never alert. A failing alert email is
logged as a `mail` row (so a rejected alert address shows up in the log and
the *Mail failures* count); the static flag only stops it alerting about
its own failure. Blank = off.

## Retention and privacy

- A daily WP-Cron event (`fishotel_health_daily`) deletes rows older than
  *Keep rows for* (default 30 days, 1–365) and anything beyond *Row cap*
  (default 5000, 100–100000). *Clear log* empties the table at once.
- Times are stored in UTC and shown in site time.
- Nothing stores an email body, an HTTP header, a cookie, a nonce or a
  token. Secret-looking keys and values are redacted before the row is
  written, not at display time, so the CSV is as clean as the page.
- The CSV download prefixes any cell starting with `=`, `+`, `-`, `@`, a
  tab or a CR with a single quote so nothing in it can run as a formula.
  It carries the Log tab's level / source / search filters, holds up to
  5000 rows (paged out of the table 500 at a time) and, when the log holds
  more, ends with a `notice` row saying how many were left out.
- The Log tab and the Overview's recent errors are ordered by last-seen
  time (`logged_at`, which a folded repeat moves forward), so an error
  that is still happening stays at the top.
- Deactivating the plugin keeps the table; nothing is removed on its own.
  If the table is ever missing (a failed first CREATE, a cleanup plugin, a
  partial site copy), the Overview and Log tabs say so, and deactivating /
  reactivating the plugin really recreates it.

## Files

```
health/
├── class-health.php     section info, boot (table install, Capture first, cron, admin), activate/deactivate
├── class-store.php      the ONLY file that touches $wpdb: {prefix}fh_log via dbDelta, insert/query/prune/clear
├── class-logger.php     pure except the Store: level, truncation, redaction, 8 KB cap, fingerprint, repeat dedupe
├── class-capture.php    shutdown fatals, wp_mail_failed, fhsmtp_email_failed, http_api_debug (GitHub), fishotel_log + fishotel_log()
├── class-facts.php      live facts: versions, dompdf, transients + age, cron, kill switches, disk, error counts
├── class-checks.php     PURE: facts → check rows + summary
├── class-cron.php       daily prune; records when WP-Cron last ran
├── class-settings.php   option, sanitised AJAX save, alert email
├── class-admin.php      page (Overview / Log / Settings), dashboard widget, CSV, clear log
├── css/health-admin.css, js/health-admin.js
├── views/overview.php, log.php, settings.php, widget.php
└── tests/               headless PHP + node tests (see tests/README.md)
```
