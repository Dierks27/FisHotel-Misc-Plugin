# Snippets

A home for the site's own small pieces of code — what the Code Snippets
plugin used to hold. Each snippet is **one file** in
`includes/sections/site-snippets/snippets/`, kept in GitHub with the rest of
this plugin, switched on or off under **FisHotel Tools → Snippets**, and run
the way Code Snippets ran it.

Why: Code Snippets keeps code in the database — no history, no review, no
way back — and its own updates have taken the site down. A file here is
reviewed, versioned, linted on PHP 7.4 and 8.3 by CI before it can reach
`main`, and deployed by the plugin's normal GitHub update.

A file snippet has **no code editor**: it is changed in GitHub and arrives
with the next plugin update; the page only switches it. For quick work on
the live site there is a second home: **My snippets**, an editor in
wp-admin for snippets stored in the database — only for the people named in
`wp-config.php`, with history, roll back, an audit log and an Export that
moves a finished snippet into GitHub. See
[Snippets written in wp-admin](#snippets-written-in-wp-admin-my-snippets).

## What a snippet file looks like

The file name is the snippet's id (the *slug*): lowercase letters, digits
and single hyphens, ending in `.php`, `.css`, `.js` or `.html`. The very
first thing in the file (right after `<?php` for PHP) is a header comment:

```php
<?php
/**
 * Snippet: Hide the admin bar for customers
 * Description: Only shop staff see the toolbar on the front end.
 * Type: php
 * Scope: global
 * Priority: 10
 * Origin: Code Snippets #3, imported 2026-10-20
 * Default: off
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'show_admin_bar', function () {
	return current_user_can( 'edit_posts' );
} );
```

```css
/**
 * Snippet: Checkout headings in teal
 * Scope: front-end
 */
.woocommerce-checkout h3 { color: teal; }
```

```html
<!--
Snippet: Spring sale banner
Scope: shortcode
Origin: Code Snippets #9, imported 2026-10-20
-->
<div class="sale">Spring sale</div>
```

| Field | Required | Values | Default |
|-------|----------|--------|---------|
| `Snippet` | yes | the name shown on the page (≤ 120 characters) | — |
| `Description` | no | one line; indented continuation lines are appended | empty |
| `Type` | no | `php` · `css` · `js` · `html` — must match the extension | from the extension |
| `Scope` | no | see below | `global` / `front-end` / `front-end` / `shortcode` |
| `Priority` | no | a whole number; lower runs (or prints) first; ties by the Code Snippets id in `Origin` (imports first, as Code Snippets ordered them), then file name | `10` |
| `Origin` | no | free text; `Code Snippets #N` in it links the file to that Code Snippets snippet (guard, `[code_snippet id=N]`) | empty |
| `Default` | no | `on` · `off` — used until the page switches the snippet | `off` |

Keys are case-insensitive; a key given twice, an unknown value, a missing
`Snippet:` line, a header that does not start the file or does not close
within 8 KB, a byte-order mark, a bad file name, a PHP syntax error, or two
files with the same slug (`foo.php` + `foo.css`) make the file **broken**:
it is listed with the reason, never loaded, and its switch will not turn on.
Files with other extensions (or starting with a dot) are ignored.

Every PHP snippet should start with `defined( 'ABSPATH' ) || exit;` (after a
`namespace …;` line if it has one) so the file does nothing when requested
directly; the folder test in CI insists on it.

### Scopes

| Type | Scope | Runs / prints |
|------|-------|---------------|
| php | `global` | every request, at `plugins_loaded` priority 1 |
| php | `front-end` | when `is_admin()` is false (site, REST, cron, CLI) |
| php | `admin` | when `is_admin()` is true (wp-admin and admin-ajax.php) |
| css | `front-end` · `admin` · `login` | inline `<style>` on the site / in wp-admin / on wp-login.php |
| js | `head` | inline `<script>` in the site's `<head>` |
| js | `front-end` · `footer` | inline `<script>` before `</body>` on the site |
| js | `admin` · `login` | inline `<script>` in the wp-admin / wp-login.php footer |
| html | `shortcode` | where `[fh_snippet slug="…"]` is placed |
| html | `head` · `body` · `footer` | echoed at `wp_head` / `wp_body_open` / `wp_footer` on the site |

The PHP rule is Code Snippets' own (`global`, plus `admin` or `front-end` by
`is_admin()`), so code moved over runs on exactly the same requests.

## How it runs (the same as Code Snippets)

- PHP snippets are included at **`plugins_loaded` priority 1** — Code
  Snippets' timing. The section framework only boots at priority 10, so the
  main plugin file includes `early-loader.php` while plugins load: when the
  Snippets section is enabled (the Section Manager's own autoloaded option)
  it hooks the run at priority 1; when it is off it does nothing else.
- Order: `Priority`, then the Code Snippets id from `Origin` (Code Snippets'
  own `ORDER BY priority, id`, so a helper class from #3 exists before #8
  uses it), then file name; files without an id come after the imports of
  their priority.
- Each file is included **inside a function** (Code Snippets `eval()`s
  inside `execute_snippet()`), so its top-level variables stay local. It
  sees no `$this` and no variable of ours.
- What a PHP snippet prints while it loads is **discarded**, as Code
  Snippets does (`ob_start()` / `ob_end_clean()`); output from its hooks
  later is not touched.
- A `Throwable` is caught: written to `error_log()` at once, handed to the
  Health section's log (`do_action( 'fishotel_log', 'error', 'snippets', … )`
  at `plugins_loaded` 20 — the logger boots after priority 1), kept as the
  snippet's *last error* on this page, shown as an admin notice, and **the
  next snippet still runs**. A fatal error that is not an exception (memory,
  time limit) cannot be caught — use safe mode.
- CSS / JS are added with `wp_add_inline_style()` / `wp_add_inline_script()`
  on empty registered handles (`fishotel-snippets-front`, `-head`, `-footer`,
  `-admin`, `-login`), each preceded by `/* fh-snippet: <slug> */`. HTML is
  echoed between newlines. Nothing in an `.html` file is ever executed.

**Cost of an ordinary request:** four autoloaded options
(`fishotel_snippets_enabled`, `fishotel_snippets_index`,
`fishotel_snippets_db_active`, `fishotel_snippets_db_version`), one
directory listing plus a size/mtime stat per file, the merge with the
database snippets, then the includes / evals. No query, no write. Measured
headless: ≈ 40 µs to plan 10 files, ≈ 53 µs with 6 database snippets as
well (the clash cross-check runs in full only when a name a database
snippet declares appears elsewhere), plus ≈ 5 µs per database snippet for
checking its signature (one HMAC-SHA256). The index (headers, declarations,
clashes, broken files) is rebuilt only when the folder's signature (names +
sizes + mtimes + PHP version) changes — once, on the first request after a
deploy (≈ 2 ms for 10 files) — and every visit to the Snippets page reads
the folder fresh.

## Switching

FisHotel Tools → Snippets lists every snippet: switch, name, description,
status, type, scope, priority, origin, a link to the file on GitHub and a
read-only view of the code, plus the broken files and the clashes. The
switch (AJAX, nonce, `manage_options`) stores `slug => true|false` in the
autoloaded option `fishotel_snippets_enabled`; a slug never switched uses
its file's `Default`. Turning on re-reads every file first and **refuses** a
broken or clashing file. A PHP snippet that is off is then **test-run once**
in that request, as Code Snippets does when a snippet is saved active: if it
throws, or declares a function / class that already exists, the switch is
refused with the reason; an error PHP's parser does not catch (a parameter
named twice, `break` outside a loop) is a fatal that ends only that switch
request — the snippet stays off and the page shows the error, instead of
every request after it dying.
The test run really runs the code once (its hooks for the rest of that
request), so a snippet with a side effect at load (an e-mail, a write) does
it once more. Turning off works for any listed or broken file and any slug
already stored; an unknown slug is refused.

## Shortcodes

- `[fh_snippet slug="spring-sale"]` renders an `html` snippet of scope
  `shortcode` — nothing when it is off. `format` (wpautop & co.) and
  `shortcodes` (run shortcodes inside it) work as in Code Snippets:
  `[fh_snippet slug="x" format shortcodes]`. As there, a flag is off only
  when its value is empty or `0` — `format=false` formats. The content is
  the file's text without its final newline (Code Snippets returns the
  stored code as is), so `Call [code_snippet id=7].` stays on one line.
- `[code_snippet id=9]` — Code Snippets' own tag, still in old pages — is
  answered by the file whose `Origin` says `Code Snippets #9`, **only once
  the Code Snippets plugin is no longer active** (while it is, the tag is
  its). `id` or `snippet_id`, `format` and `shortcodes` are honoured; `php`
  is not (see the transition notes). Two files claiming one id: neither
  answers, and the page says so.

## Safety

- **Safe mode** loads no snippet at all (PHP, CSS, JS, HTML, shortcodes)
  and shows an admin notice:
  - logged in as an administrator, add `?fishotel-snippets-safe-mode=1` to
    any admin address — that page, the Snippets page's links and its switch
    keep the flag, so you can switch the culprit off;
  - locked out entirely: `define( 'FISHOTEL_SNIPPETS_SAFE_MODE', true );` in
    `wp-config.php`, switch the snippet off, remove the line. Code
    Snippets' `CODE_SNIPPETS_SAFE_MODE` is honoured too.
  The flag is ignored for anyone who cannot `manage_options`.
- **Duplicate guard** while Code Snippets is still installed: a switched-on
  snippet whose Origin is `Code Snippets #N` is **held back** while Code
  Snippets is active and its snippet N is active too — running both copies
  would redeclare the same functions and take the site down. One prepared
  query on `{prefix}snippets` (only when Code Snippets is loaded and such a
  snippet is switched on); if the table cannot be read, every such snippet
  waits. The page and an admin notice name what waits and why. A
  `shortcode` snippet is never held (it only renders where placed).
- **Clashes**: a function or class declared by two snippet files (or twice
  in one where both can run) is listed and **both files are refused**. It
  is only a warning when every declaration is *guarded*: inside braces, in
  a file that calls `function_exists( 'that_name' )` (`class_exists()`,
  `interface_exists()`, `trait_exists()`, `enum_exists()` for a class) with
  the exact name as a string (namespaced: the full name, or
  `__NAMESPACE__ . '\\name'`) — Code Snippets' own exemption. Any other
  `if` (`if ( ! is_admin() )`) guards nothing.
- **Already declared**: right before a PHP snippet is included, every
  function / class it declares without such a guard is checked; if another
  plugin, the theme or Code Snippets declared it first, the snippet is
  skipped (with a notice) instead of fataling.

## Adding or changing a snippet

1. In GitHub, add `includes/sections/site-snippets/snippets/<slug>.php`
   (or `.css` / `.js` / `.html`) on a branch, header first, `Default: off`.
   Keep keys, tokens and passwords out of the file: put them in
   `wp-config.php` (`define( 'FH_SOMETHING_KEY', '…' );`) and read them with
   `constant()`.
2. Open a pull request: CI runs `php -l` on PHP 7.4 and 8.3 and
   `tests/snippets-test.php` (header parses, no clash, guard line present).
3. Merge to `main`, bump the plugin version as usual; the site updates.
4. FisHotel Tools → Snippets: the new file is listed — switch it on, check
   the site.

Changing a snippet is the same: edit the file in GitHub, merge, update. The
on/off choice is kept (it is stored per slug — renaming the file is a new
snippet).

## Moving off Code Snippets, step by step

1. In Code Snippets, **Export** all snippets (JSON).
2. Convert the export with the developer's converter (a script kept outside
   this plugin): one file per snippet, all `Default: off`, Origin
   `Code Snippets #N, imported <date>`, scopes mapped (`global`, `admin`,
   `front-end` → PHP; `content` → html shortcode; `head-content` /
   `body-content` / `footer-content` → html head / body / footer; `site-css`
   → css front-end; `admin-css` → css admin; `site-head-js` → js head;
   `site-footer-js` → js footer). Single-use snippets, conditions and
   trashed snippets are refused with the reason. Tell it which Code
   Snippets the site ran (`--edition=free`, the default, or `pro`): the
   free plugin ignores conditions (a linked snippet ran on every request,
   and the file keeps doing that) and never prints CSS / JS snippets (an
   "active" one had no effect; switching its file on changes the site).
   Its report lists every snippet, flags **secrets** (API keys, tokens,
   passwords, SMTP logins — in the code, the name or the description) to
   move into `wp-config.php` first, priorities missing from the export
   (10, or 0 — the export drops both), condition-bound snippets, PHP inside
   content snippets (an `.html` file never runs PHP — `[code_snippet php=1]`
   has no equivalent; move that PHP into a PHP snippet with its own
   shortcode) and duplicate functions (Code Snippets often holds an old
   inactive copy — keep one).
3. Commit the files (all off) through a pull request and update the plugin.
   Nothing changes on the site yet. Enable the **Snippets** section on the
   FisHotel Tools dashboard.
4. For each snippet that ran on the site, in the report's transition order
   (priority, then Code Snippets id — the order Code Snippets ran them):
   1. in Code Snippets, **deactivate** snippet #N;
   2. in FisHotel Tools → Snippets, **switch on** the file from #N — in
      this order: switching on test-runs the file, and while #N is still
      active the functions it declares already exist, so the switch is
      refused (one that declares nothing is accepted and simply waits — the
      guard holds it back while #N is active);
   3. **check the site** (the page the snippet affects, checkout, wp-admin).
   If anything breaks: switch ours off, reactivate #N, and look at the
   snippet's last error on the Snippets page or in the Health log.
5. When every snippet runs from here, **deactivate and delete** the Code
   Snippets plugin. Old `[code_snippet id=N]` shortcodes in pages are then
   answered by the matching files.

### fishotel.com's own snippets (imported 2026-10-18)

The live site's Code Snippets export held five snippets. Two that did
real work were moved here, byte for byte, with `Default: on`:
`tracking-page.php` (#33, the `[fishotel_tracking]` "Room Service Status"
page) and `hide-category-counts.php` (#20). Neither declares a function or
class, so while their Code Snippets copies are active the guard simply
holds them back, and the moment Code Snippets is deactivated or deleted
they take over — no per-snippet switching needed: enable the section, then
remove Code Snippets. Not moved: #4 "Order snippets by name" (it only sorts
the Code Snippets admin list), #21 "Manual DNS Prefetch" (its function is
missing a closing brace, so Code Snippets has failed to run it on every
request since April 2025; WordPress already prefetches the font hosts the
theme enqueues) and #32 "tester" (an inactive one-off that created a test
order).

## Snippets written in wp-admin (My snippets)

FisHotel Tools → Snippets has three tabs: **Files (GitHub)** (everything
above), **My snippets** (snippets written here, stored in the database) and
**Log** (the audit log of My snippets).

### Who can use it

Only the people named in `wp-config.php` can write, switch, restore,
export or delete a snippet here:

```php
define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );
```

- Put the line above *"That's all, stop editing!"*. Several people:
  `'jeff, anna'`. A WordPress **username** or a **user ID** (an entry made
  of digits is always an ID); usernames match in any case.
- The person must also be an administrator (`manage_options`; a network
  super admin on multisite).
- Never while `DISALLOW_FILE_EDIT` is true — that switches off every code
  editor in wp-admin, this one too.
- Only a string (or one user ID as a number) counts: `define( …, true )`
  or an array names nobody.
- Without the line nobody can write: the tabs are read only and show the
  line to add. Other administrators see the list — names, status, versions
  — but **never the code**: the edit, history and diff screens are for the
  named editors only (a snippet may hold a key).

Every refused write (not named, not an administrator, `DISALLOW_FILE_EDIT`,
a stale form) is refused with a 403 **and logged as `denied`** — at most 30
such rows per account an hour, and 100 an hour for all accounts without
`manage_options` together (fixed hourly windows), so logged-in customers
poking at the handlers cannot flood the log, nor crowd out an
administrator's refused attempt. An editor whose form went stale (an
expired nonce) gets the code back on the refusal page, read only, to copy —
nothing is kept for them, so a forged request cannot plant a draft they might
later save unread.

### Writing one

**Add new** (or **Edit**): slug (its id and its file name on export — the
same rule as a file name, fixed once created), name, description, type
(php / css / js / html), where it runs (the same scopes as a file of that
type), priority, on / off, and the code in WordPress's code editor
(CodeMirror, highlighting by type; a plain textarea when *Disable syntax
highlighting* is ticked in your profile). PHP is stored **without** the
opening `<?php` tag (a leading one is removed on save).

Saving, in this order — nothing is written until the end:

1. the fields are checked (slug free — neither another snippet here nor a
   GitHub file uses it —, scope fits the type, ≤ 64 KB of code);
2. PHP is parse-checked (`token_get_all( TOKEN_PARSE )`, the error with its
   line);
3. a function or class another snippet declares — a file, or another one
   here — is refused, as two files would be (guarded by
   `function_exists()` on both sides is fine);
4. when the snippet is or becomes **on**, the PHP is **test-run once** in
   the save request, as the Files switch does: if it throws, nothing is
   saved, the reason is shown and logged as `test_failed`; a fatal error
   PHP cannot catch (a parameter named twice) ends only that request —
   still nothing saved (WordPress may e-mail its "technical issue" notice
   for that one request). When the snippet already runs on that request,
   the test run happens **in its place at `plugins_loaded` 1**: the new code
   runs where the old code would have (the old code never runs on the save
   request, or the test run would declare its functions twice), so a file
   or snippet that calls its functions later in the request — on `init`,
   before the save handler — still finds them; the handler then takes that
   run as the test run. If the save will be refused anyway (a field, the
   parse check, a clash, the size, a stale form) or does not test-run
   (saved off), the old code runs as on any request. A restore from
   History does the same with the version it restores;
5. then, together: the row (version + 1 — refused if someone else saved it
   since you opened it; a switch on or off in the list meanwhile is not a
   conflict), one revision, one log row, in one transaction (a COMMIT the
   database refuses counts as a failed write); the list the site runs is
   rebuilt and read back; the entry goes to the Health log. A save whose
   list could not be rebuilt says so in red — the table has the new
   version, the site still runs the old one — and the next visit to My
   snippets rebuilds it.

**Switching off** (the list's switch) always works: the code does not run,
so no field rule, parse check (code that no longer parses on a newer PHP),
clash (a GitHub file that now declares the same function) or size limit can
keep a snippet on.

**Never lose your work:** a refused save brings you back to the form with
exactly what you typed and the reason. What you typed is kept as your
draft (per user) *before* the test run too, so even after a fatal the
form shows your code next time, and that failed save is logged as
`test_failed` then. *Discard it* goes back to the saved version. A save
refused because someone else changed the snippet keeps your code, based
now on their version: compare, merge, and the next save replaces it. Code
typed into a snippet that was deleted meanwhile (another tab) comes back
as a new snippet with its slug filled in, and My snippets links to it.

### How they run

Exactly like the files: same scopes, same runner (the stored code is
`eval()`ed inside a function — as Code Snippets does — locals local,
output discarded, a `Throwable` caught and recorded as `db:<slug>` with
its line), same order (priority, then files before database snippets,
then slug), same safe mode, same "already declared" preflight. CSS / JS /
HTML print on the same handles (`/* fh-snippet: db:<slug> */`), and
`[fh_snippet slug="…"]` renders a database html / shortcode snippet when
no file has that slug.

They come from **one autoloaded option**, `fishotel_snippets_db_active`
(the active snippets' id, slug, type, scope, priority, code, version and
declarations), rebuilt on every write — never from a query. All active
snippets together may be at most 256 KB.

Every entry of that option is **signed** (HMAC-SHA256 keyed with
WordPress's auth salt — `AUTH_KEY` and `AUTH_SALT` in `wp-config.php`, the
secret behind every login cookie), and an entry whose signature is wrong
never runs: any administrator can write any option through
`wp-admin/options.php`, and without it that would run PHP for someone not
named in `FISHOTEL_SNIPPETS_EDITORS`. New salts (a security plugin
rotating them, a copy of the site with another `wp-config.php`) stop every
database snippet until a named editor opens My snippets, which compares
the option with the table and rebuilds it — so does every other
difference (a write that never reached the option, an entry someone else
put there); the page says what differed and the Health log gets a warning.
Other administrators see the difference but rebuild nothing.

**Superseded:** a database snippet whose slug a file in `snippets/` also
has never runs — the page says *Superseded by the GitHub file …*. A file
deployed later that declares a function a database snippet declares wins
the same way: the database snippet is refused, the file keeps running.

### History, roll back, delete

- **History**: every version (every save, switch and restore writes one)
  with who, when and what changed; **Diff with current** (WordPress's
  `wp_text_diff()`) and **Restore**, which writes a *new* version with the
  old code and fields — logged as `restore`, test-run like a save while
  the snippet is on (it keeps its on / off state).
- **Delete** removes the snippet but keeps its versions and its log rows;
  the delete is logged with the last code's hash. The delete row on the
  Log tab has **Restore**: the snippet comes back from its last version,
  under its old id and slug, **switched off** (switching it on test-runs
  it).

### What is logged

The **Log** tab (`{prefix}fh_snippet_log`) is append-only — no code path
updates or deletes a row, and the page has no delete. One row per
`create`, `update`, `activate`, `deactivate`, `delete`, `restore`,
`export`, `test_failed`, `denied` and `safe_mode` (safe mode in use, once
an hour per user), with: the snippet id and slug, the user (id + login at
the time), `REMOTE_ADDR`, the first `X-Forwarded-For` value (stored as
**untrusted** — anyone can send it), the user agent (≤ 255), the time
(UTC) and what changed: fields old → new, the code's hash and line count
before → after — **never the code itself**. Filters, pages of 50 and a
CSV of every row (`manage_options` + nonce; every cell guarded against
spreadsheet formulas like the Clean Room CSV). Each row also goes to the
Health log (`fishotel_log`, source `snippets`, `info` or `warning`).

### Move to GitHub

When a snippet is final, **Export** downloads it as a snippet file: the
header (Snippet, Description, Type, Scope, Priority, *Origin: written in
wp-admin by jeff, exported 2026-10-18*, Default matching whether it is
on), then for PHP the `<?php` tag and the `defined( 'ABSPATH' ) || exit;`
guard (after a leading `namespace …;`), then the code. The export is
logged. Then:

1. send the file to Claude — it lands in GitHub through a pull request
   (CI lints it on PHP 7.4 and 8.3) and arrives with the plugin update;
2. under Files the snippet appears; under My snippets this copy now says
   *Superseded by the GitHub file* and never runs;
3. switch the file on (Files), check the site, then delete this copy.

### The trade-off

Anyone who controls an allowed administrator account can run PHP on the
site through this editor — exactly as with Code Snippets or the theme
editor. The limits are: the `FISHOTEL_SNIPPETS_EDITORS` list (one line in
`wp-config.php`, which the web cannot change), the signed active option
(only code a named editor saved can run; someone able to read the option
could still put back an entry an editor once signed), `DISALLOW_FILE_EDIT`
as an off switch, the test run before anything is stored, safe mode, and
the append-only log of who did what from where. Files in GitHub stay the
reviewed, versioned home; My snippets is for trying things on the live
site, then moving them.

### Tables

Created with `dbDelta` the first time the section boots (or on plugin
activation), gated by the autoloaded `fishotel_snippets_db_version` — an
ordinary request never runs `SHOW TABLES` or `dbDelta`. A failed install
is retried at most once an hour, only in wp-admin. Column types are
written exactly as MySQL / MariaDB report them (`bigint(20) unsigned`,
`int(11)`, `longtext` …): `dbDelta` compares them case-sensitively and would
otherwise alter every column on every install.

| Table | Holds |
|-------|-------|
| `{prefix}fh_snippets` | id, slug (unique), name, description, type, scope, priority, active, code, version, created_by / _at, updated_by / _at, exported_at |
| `{prefix}fh_snippet_revisions` | id, snippet_id, version (unique per snippet), code, meta (JSON: name, description, type, scope, priority, active), user_id, created_at |
| `{prefix}fh_snippet_log` | id, snippet_id, slug, action, user_id, user_login, remote_addr, forwarded_for, user_agent, summary, created_at (UTC) |

## Options

| Option | Autoload | Holds |
|--------|----------|-------|
| `fishotel_snippets_enabled` | yes | `slug => true\|false`, written by the switch; created empty with the index (a missing option is a query on every request) |
| `fishotel_snippets_index` | yes | the parsed folder: fields, declarations, refusals, broken files, clashes, signature |
| `fishotel_snippets_errors` | no | the newest error per snippet (read by the page only; a database snippet's key is `db:<slug>`) |
| `fishotel_snippets_db_active` | yes | the active database snippets, each entry signed, rebuilt on every write; created empty with the tables |
| `fishotel_snippets_db_version` | yes | the tables' schema version (or a failed-install marker) |
| user meta `fishotel_snippets_drafts` | — | an editor's unsaved code per snippet (kept 7 days) |

Plugin deactivation drops the index (rebuilt on the next request); the
choices, the files, the tables and the database snippets stay.

## Files

```
site-snippets/
├── early-loader.php      # included by fishotel-misc-plugin.php at load time
├── class-site-snippets.php
├── class-header.php      # PURE: header comment → fields | broken reason
├── class-symbols.php     # PURE: TOKEN_PARSE syntax check, declarations, clashes
├── class-rules.php       # PURE: on?, runs here?, order, slots, safe mode, guard, [code_snippet] map
├── class-catalog.php     # the folder as a cached index
├── class-runner.php      # include inside a function + try/catch
├── class-loader.php      # the request: safe mode, guard query, run, errors
├── class-output.php      # CSS / JS / HTML printing, shortcodes
├── class-admin.php       # page (Files tab), switch, notices
├── class-db-snippets.php # the active option (signed), the merge with the files (superseded, clashes)
├── class-db-store.php    # the editor's tables — its only $wpdb file
├── class-editor-rules.php # PURE: who may write, fields, clashes, history / log text, CSV guard
├── class-export.php      # PURE: a database snippet as a snippet file
├── class-editor.php      # the save pipeline (store as a parameter)
├── class-admin-editor.php # My snippets / Log tabs, admin-post + AJAX handlers, drafts
├── views/                # page.php (Files), tabs.php, editor-*.php
├── css/snippets-admin.css
├── js/snippets-admin.js  # the Files switch
├── js/snippets-editor.js # code editor, scope list, the My snippets switch
├── snippets/             # one file per snippet (example.php, off)
└── tests/                # headless: php includes/sections/site-snippets/tests/<name>-test.php
```
