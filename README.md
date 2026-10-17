# FisHotel Misc Plugin

A modular container plugin for WordPress with a dark theme admin interface.

## Overview

FisHotel Misc Plugin provides a framework for managing independent feature "sections" through a unified dark-themed dashboard. Each section is a self-contained module that can be enabled or disabled independently.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

1. Upload the `fishotel-misc-plugin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **FisHotel Tools → Dashboard** to manage sections.

## Architecture

```
fishotel-misc-plugin/
├── fishotel-misc-plugin.php        # Bootstrap & autoloader
├── includes/
│   ├── class-plugin.php            # Main plugin orchestrator
│   ├── class-section-manager.php   # Section discovery & toggling
│   └── sections/
│       ├── announcer/              # Announcement bars
│       ├── sticker-giveaway/       # Free sticker picker at checkout (+ $1 add-ons)
│       ├── lottery-draw/           # Verifiable lottery for group orders
│       ├── weather/                # Nightly delivery-day weather notes + packing actions
│       ├── health/                 # Shared error log, failure capture, FisHotel Health widget + page
│       ├── today/                  # Today at FisHotel: next delivery days, holds / UPS Store, missing weather / invoice / tracking
│       └── clean-room/             # Where every fish is: Spa / Clean Room tanks, stays with replayed events, QR + voice Front Desk, protocol to-do, room board
├── admin/
│   ├── css/admin-style.css         # Dark theme styles
│   ├── js/admin-script.js          # Toggle & AJAX handling
│   └── views/dashboard.php         # Dashboard template
└── README.md
```

## Creating a Section

1. Create a directory under `includes/sections/` (e.g. `my-feature/`).
2. Add a class file named `class-my-feature.php`.
3. Implement the required static methods:

```php
namespace FisHotel\Misc\Sections\My_feature;

class My_feature {

    public static function get_section_info() {
        return [
            'name'        => 'My Feature',
            'description' => 'A brief description.',
            'icon'        => 'dashicons-star-filled',
        ];
    }

    public static function boot() {
        // Initialise your feature hooks here.
    }

    public static function register_menu() {
        // Optional: add a submenu page.
    }
}
```

The Section Manager will automatically discover and register the section.

## Version

0.40 — Added Clean Room section — where every fish physically is, from the day it lands to the day it ships. A seeded, editable layout of buildings and tanks (hundreds = building, tens = level, ones = position; buildings 100–400 Clean Room, 500–600 Spa; one shared water system per building, shown in every tank title) in `{prefix}fh_buildings` / `fh_tanks`. One stay per group of one species in `fh_stays`, cached from an append-only history in `fh_stay_events` and rebuilt by a pure Replay: alive = received − losses − shipped with a full-recount override (Jeff counts bodies, not heads), every arrival into a Spa tank, cleared = the day a group first moves from the Spa into the Clean Room (editable per stay; a hand-set date is a `clear` event dated now with the date in its note, so a correction to an earlier date wins), sale-ready N days after check-in (setting, default 28, per-stay override, the check-in date correctable), a move with a smaller quantity splits the group, closing at zero, and an undo that is always a compensating event. A treatment protocol stored as an editable template (steps with day offset, kind, repeat, where; seeded with the rough Metronidazole / 14-day copper / Praziquantel version, to be replaced by the workbook) generates each tank's daily to-do (feeding + due steps, tap to tick); copper readings, tick-offs and tank notes in `fh_tank_logs`. The Front Desk: a self-contained phone page at `/?fh_front_desk=1&tank=521` (login redirect, manage_woocommerce, no theme, inlined CSS/JS, no external requests, ≥ 56 px targets) that each QR label encodes — the loss as the fastest path (one tap on −1 lost), Shipped, Move (with split and reason chips: clear building / bullying / pairing / other; one group = no pick step), Arrival (species search over quarantined-fish / inverts products, common and scientific names and learned aliases; Spa tanks only), Note, Copper, per-group sheet with Dates (check-in, cleared, sale-ready), an Undo toast that stays until the next action (at least 10 s; 15 minutes server-side, never twice for the same event), a loss recorded by two phones against one count flagged "overdrawn" with a recount asked for, a Today to-do strip folded under the fish rows, a copper reading that ticks the copper test, a label login that keeps the phone signed in for a year, and a Mic that sends one sentence to a pure server-side Parser (verbs for loss / shipped / move / arrival / clear / count / copper / note, number words, "one twenty one" / "tank five two one" / "building 100 top left" tank references, fuzzy species with aliases first and "Which fish?" chips that teach an alias) with a plain-words preview and an auto-confirm countdown. The Room Board under FisHotel Tools (dark): Area → Building → Level tank cards with occupants, copper and to-do counts, filters, Front Desk links; a Stays tab with per-row Lost / Shipped / Count / Cleared… / Sale-ready… / Move… / Edit (check-in date, supplier, order ref, landed cost — the seam for the deferred supplier-order import), a Coming Soon button that sets the product's `_fh_release_datetime` to the sale-ready date, closed stays and a formula-guarded CSV; an Events tab with a per-row Undo; Settings (numbers, protocol editor, layout editor with the rule enforced, aliases) and Print tank labels — inline-SVG QR codes from the section's own encoder (byte mode, EC M, v1–6, verified module-for-module against python-qrcode). `fishotel_cleanroom_product_facts()` / the `fishotel_product_qt_facts` filter (null for a product with no stay) and a product meta box expose live / in-QT / cleared / ready / next for the fish page. The tables install and seed once behind an autoloaded version option, so an ordinary request costs no query. Headless PHP and node tests for the layout, the replay (DST days in America/Chicago), the protocol, the parser, the QR encoder, the CSV guard, the Front Desk server guards on an in-memory store and the phone helpers.

0.39 — Added Today section — "Today at FisHotel" as a WordPress dashboard widget and a FisHotel Tools page, replacing "which day are we shipping, then paginate the orders list". One header line says whether today is a ship day and for which delivery date (`Today, Mon Oct 13 — ship day for Tue Oct 14 delivery · 6 orders · 23 fish`, or `Nothing ships today. Next: ship … for …`), then one block per upcoming delivery date (the first three with open orders in a 21-day window, both configurable) with the ship date, chips (orders, fish, dry goods, holds, UPS Store, at the line, unavailable, need weather / invoice / tracking), a needs-attention strip (weather trouble first, then missing paperwork) and a table: order link, customer, City/ST, status, fish (+dry), the weather badge from the Weather section's note (HEAT PACK / NOTHING / COOLING / UPS STORE / HOLD / AT THE LINE / UNAVAILABLE / NOT CHECKED, sorted most serious first) with any active NWS alert (FLOOD WATCH …) under it and in the attention strip, invoice ✓ from the PDF Invoices plugin's marker (filterable keys), tracking ✓ with the tracking-number count (one per physical box) and newest status from ShipTracker, and a customer-note icon (read on the order or on any live source of a combined box). A combined box is one destination labelled with its live source orders (folded, cancelled and refunded sources skipped), fish counted through the Batch plugin's Ship Day class when present (else by product category here), the invoice and tracking checks accepted from every live source. Columns whose plugin is absent are hidden with a note; with the Weather column off the strip, the chips and the sort raise no weather reason. Links to the Weather overview for the date and (administrators) the Ship Day pick list. Open statuses default to processing, on-hold, fulfillment and shipped, so a row stays on the page after its tracking flips the order to shipped. Today comes from the Batch plugin's delivery rules (site zone), the orders from ONE store-agnostic query over the window (the Weather section's Checker). The window is cached for five minutes (0 = off) under a key of today + settings hash, dropped by Refresh (page button, widget link), every settings save, every order status change / save, every order note (ShipTracker adds one per shipment) and every invoice PDF save. No cron, no tables; pure Model with headless PHP and node tests.

0.38 — Added Health section — one logger every FisHotel plugin and the theme can write to (`do_action( 'fishotel_log', level, source, message, context )` — always safe — or `fishotel_log()`, which exists only while the section is enabled, so guard it with `function_exists()`), stored in a new `{prefix}fh_log` table with secret-key and token redaction, an 8 KB context cap and 10-minute repeat folding (`×N`). Automatic capture of what was invisible before: PHP fatals via a shutdown handler armed at `plugins_loaded`, `wp_mail_failed` (recipients + subject, never the body), the SMTP plugin's `fhsmtp_email_failed`, and GitHub/updater HTTP failures (`GitHub returned 401 for api.github.com/…`, query string stripped, no headers or tokens) with a 24 h auth-failed flag. A FisHotel Health dashboard widget and a Health page (Overview / Log / Settings) evaluate pure checks over live facts: plugin and theme versions (Batch, ShipTracker, SMTP, Boards, WPIT, theme), WooCommerce + HPOS, dompdf for the EA packing-slip PDF, each updater's last check age (warn after 25 h or never), the GitHub 401 hint, WP-Cron (last run, DISABLE_WP_CRON, every FisHotel event scheduled / overdue), ShipTracker kill switches (fan-out OFF, web-readable fst-debug.log with its size), PHP / memory / disk / WP_DEBUG_DISPLAY, mail failures in 24 h and the last five errors. Log tab with level/source/search filters, 50 per page, expandable context, Clear log and a formula-safe CSV download; settings for retention (daily prune), row cap, debug storage, capture toggles, extra hosts and an optional alert email with cooldown and a recursion guard. Nothing in the section can fatal: Store writes are wrapped and a missing table means "log nothing". Headless PHP and node tests.

0.37 — Added Weather section — step 3 of the Mon/Tue/Wed order routine on the server. A single nightly WP-Cron event (default 21:00 site time, DST-safe, armed before each scan) finds every open order delivering today through three days out (orders within two days are refreshed nightly so the morning works from the previous evening's forecast; a late-firing event still covers the nearest date), geocodes the shipping address (US Census, ZIP-centroid fallback), pulls the NWS daily forecast for the gridpoint, takes the delivery-day HIGH and writes one private order note in the order-workflow skill's exact format with the band ending (heat pack / nothing needed / cooling packs / UPS Store pickup / HOLD; "AT THE LINE" when the high sits on a boundary), the active-alert sentence and the next-day extremes line. Structured meta (`_fh_weather_*`) records the high, low, action and note; the UPS Store bands get a drafted customer email that is never sent. Any geocode or API failure is a clear "weather unavailable — check manually" state, never a guessed band (one failure note per delivery date; a failed re-check drops the earlier success so the nightly job retries). Combined boxes fan the note out to live source orders only — cancelled or refunded sources get no note or email draft. Order screen Weather meta box (legacy + HPOS) with a Copy-email button and "Check weather now"; Weather column and "Check weather" bulk action on both orders tables; an Overview page per delivery date with totals per action and a "Run nightly scan now" button that works even with the nightly job switched off, replacing the browser skill's pick-list weather pass. Thresholds, run time, statuses, contact email and the source-order toggle are editable; pure band / note / forecast classes with headless PHP and node tests.
0.36 — Added Sticker Giveaway section — qualifying carts (Quarantined Fish by default, configurable categories and minimum subtotal) pick one free sticker at checkout from an admin-managed pool of simple products, with an optional add-on stepper at a configurable price (default $1.00, max 5). Stickers are real $0 / add-on cart lines flagged `fh_sticker`, excluded from shipping calculation, locked to qty 1 for the free one, and surface on orders, emails and PDF invoices as item meta. Settings page under FisHotel Tools with WooCommerce product search, category tree, copy fields and a live summary; pure `Rules` class with a headless test. Also: the update checker now hooks `upgrader_source_selection` so one-click GitHub updates install into the active folder instead of deactivating the plugin; the announcer bar publishes only visible top bars to the theme's sticky-header contract and no longer double-counts the admin bar; countdown targets are emitted in the site time zone. Performance section hardened: cache headers, gzip and query-string removal now default off; cache headers skip logged-in users, filled carts and pages with notices (max-age 600); gzip never wraps admin, AJAX, cron, REST or admin-post downloads; the asset combiner leaves WooCommerce, PayPal, jQuery and any localised script alone, never runs on cart/checkout/account pages, and prunes its cache by age. Coming Soon's arriving-first sort now survives WooCommerce's price/popularity sorts; Product Archive no longer 404s draft product previews.

0.35 — Lottery Draw: added a JSON importer so a whole draw can be pasted in rather than retyped fish by fish. Validation renders a preview (slots, tickets, and whether each fish is a real draw or rank-only) before anything is written. A payload carrying `winners` or `waitlist` is refused outright and named, never silently stripped, and import only ever writes a draft — results can only ever come from the draw itself. Each import is logged with who, when, and how many fish and tickets.

0.34 — Added Lottery Draw section — allocates contested group-order fish by a seeded lottery members can recompute in their own browser. The draw algorithm lives in exactly one implementation (`draw.js`), shared verbatim by the admin screen that produces a result and the public page that re-checks it; PHP stores and renders but never draws. Seeds are committed before entries close (or taken from external randomness), published draws are immutable, and a tampered result renders a red MISMATCH banner instead of a green one.

0.31 — Hotfix: Visitor Stats timezone bug. Pageviews now stored and queried in UTC, so the "online now" counter and recent-window aggregates work correctly regardless of site vs. MySQL server timezone. One-time backfill converts existing rows from site-local to UTC.

0.30 — Added Visitor Stats section — lightweight pageview tracker via JS beacon (cache-safe), with live "online now" counter, top pages, top products, top referrers, date-range toggle (24h/7d/30d), 30-day rolling retention, and a WP admin dashboard widget for at-a-glance stats.

0.29 — Added Coming Soon section — flag variable products with a release datetime to display a ribbon, live countdown, and "Notify Me" button while server-side blocking purchase. No cron: release transitions are detected per-request from a `_fh_release_datetime` parent meta vs. `time()`. Auto-reload on countdown end with random jitter.

0.28 — Added Product Archive section — archive WooCommerce products to hide from shop/admin without deleting.

0.26 — Add onsubmit="return false" HTML safeguard to Performance form; prevents hard POST if all JS fails.

0.25 — Full audit fix: skip gzip on AJAX (root cause), move form handler to inline script (Varnish-proof), safe response access.

0.24 — Use check_ajax_referer instead of check_admin_referer in Performance save handler.

0.23 — Wrap admin JS event bindings in DOM-ready handler to fix Performance form hard-POST.

0.22 — Replace redirect with wp_send_json_success in Performance save handler; add AJAX form submit.

0.21 — Fix Performance save handler: switch to wp_ajax hook and admin-ajax.php endpoint.

0.20 — Fix Performance toggle saving error; scope dashboard AJAX handler, add try/catch and AJAX guard.

0.19 — Add CSS/JS file combiner to Performance section.

0.18 — Add Performance Optimization section (script cleanup, cache headers, gzip, query-string removal).

0.17 — Defer plugin_dir_url() to plugins_loaded hook to fix early-load notice.

0.16 — Hide theme skip links when announcement bar is present.

0.15 — Raise z-index to 9999999 to fix theme skip links overlapping bar.

0.14 — Fix dark theme: white gap, white editor, TinyMCE iframe styling.

0.13 — Fix 3800px bar: replace the_content filter, add max-height constraint.

0.12 — Force position:fixed via JS inline styles to override Elementor CSS.

0.11 — Bulletproof rendering: wp_footer + JS DOM relocation + position:fixed.

0.10 — Complete rewrite: static positioning with CSS sticky, no body padding hacks.

0.9 — Fix bar stretching to full viewport; inline critical styles, explicit constraints.

0.8 — Switch to wp_body_open for rendering, with wp_footer fallback.

0.7 — Fix announcement bars not displaying (position: fixed for all bars).

0.6 — Move admin menu to position 4 (below Theme Panel, above Posts).

0.5 — Force dark theme with !important on whitelisted plugin pages.

0.4 — Fix update checker to read version from main branch (no releases needed).

0.3 — Redesign announcer admin: sidebar nav, list table toggles, dark theme, preview.

0.2 — Add Announcer section and GitHub update checker.

0.1 — Initial dark theme plugin framework.
