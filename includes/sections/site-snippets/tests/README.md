# Snippets — headless tests

All run without WordPress or a database. `wp-stubs.php` supplies in-memory
options (with the autoload flag recorded), an action / filter / shortcode
registry with a tiny `do_shortcode()`, the style and script queue,
`is_admin()` / `current_user_can()` switches, users (`fh_login()`),
nonces, `wp_salt()` (`$GLOBALS['fh_salt']` rotates it), user meta,
transients, an option write that fails on demand
(`$GLOBALS['fh_option_fail']`), `wp_enqueue_code_editor()`, a
`wp_text_diff()` stand-in, redirects and JSON answers that stop a handler
(`FH_Halt`), a fake `$wpdb` that answers the Code Snippets guard query,
`SHOW TABLES` and counts queries, and points `error_log()` at a temp file.
`fake-db-store.php` is an in-memory `Db_Store` (same unique keys, the
version lock, real rollback, optional persistence to a file for child
processes). Snippet files are invented and written to temp folders. Both
helpers exit 0 when CI runs them directly. The in-memory store can also
refuse `begin()` or the COMMIT (`$fail['begin']`, `$fail['commit']`, which
keeps nothing, as a cluster does).

```
php includes/sections/site-snippets/tests/header-test.php
php includes/sections/site-snippets/tests/rules-test.php
php includes/sections/site-snippets/tests/symbols-test.php
php includes/sections/site-snippets/tests/loader-test.php
php includes/sections/site-snippets/tests/snippets-test.php
php includes/sections/site-snippets/tests/editor-rules-test.php
php includes/sections/site-snippets/tests/editor-test.php
php includes/sections/site-snippets/tests/db-run-test.php
php includes/sections/site-snippets/tests/editor-admin-test.php
```

- **header-test.php** — every field, the continuation line, the defaults
  per type, every scope of every type, invalid values (scope for the type,
  unknown / mismatched type, priority, default, missing / empty / repeated
  Snippet, lengths), missing or malformed headers (no `<?php`, code before
  the header, no comment, never closed, past 8 KB, BOM, empty), file names,
  `body()`, the Code Snippets id from an Origin.
- **rules-test.php** — the enabled state (stored choice vs Default), Code
  Snippets' PHP scope rule, the CSS / JS / HTML slots per screen, priority
  then Code Snippets id then slug ordering, the PHP and output plans, safe mode (constant, query
  flag with and without the capability, never resolving the user without
  the flag), the duplicate guard (inactive plugin, active ids, ids as
  strings, unreadable table = all held), its candidates, the
  `[code_snippet id=N]` map with ambiguous ids, the shortcode flags (only
  empty / `0` is off, as in Code Snippets).
- **symbols-test.php** — declarations found by `token_get_all` (reference
  functions, closures, arrow functions, `::class`, anonymous classes,
  methods, `use function`, namespaces plain and braced, nested and guarded
  declarations — guarded only by an exists check on that exact name, not
  by `if ( true )` / `if ( ! is_admin() )`, another name or a top-level
  declaration — heredoc braces), the TOKEN_PARSE syntax check, clashes
  (case-insensitive, guarded pairs as warnings, one guarded + one not
  refused, the same name inside `if ( true )` in two files refused, twice
  in one file, top level + unguarded if in one file, if / else, function vs
  class, namespaces),
  `exists_now()`, `Catalog::build()` (refusals naming the other file,
  broken files, a slug shared by two files, size cap) and `Catalog::get()`
  (stored autoloaded, served from the option while the signature matches,
  no write on a fresh read of an unchanged folder, rebuilt after a change,
  the switch map created empty with it).
- **loader-test.php** — the early loader (nothing hooked while the section
  is off or the option is malformed; `run_php` at `plugins_loaded` 1 and
  the log flush at 20 when it is on; the main file includes it), then whole
  requests: the Runner (a throwing snippet
  recorded and the next one run, missing files, a preflight skip, output
  discarded, locals local, a ParseError, a buffer left open, a failing
  callback); priority order on the front end and in admin; the error in
  `error_log()`, in `fishotel_log` and stored non-autoloaded; a second
  request with no query and no write; CSS / JS on empty handles per screen
  and footer HTML; `[fh_snippet]` with `format` / `shortcodes` and no
  recursion, no trailing newline inline; `[code_snippet id=N]` registered
  only without Code Snippets, `format=false` formatting;
  safe mode by query (visitor vs administrator, no output, page link keeps
  the flag); the switch (refuses broken, clashing and unknown files, stores
  autoloaded, off allowed for listed / broken / stored slugs but not an
  unknown one, clears the last error); the switch's test run (a PHP snippet
  run once before it is stored; a throw, an existing name — also inside a
  plain `if` — refused and not stored; an already-on snippet not re-run;
  CSS never run; a compile-stage error, in a child PHP process, fatals that
  request before the option is written); the exists-now preflight (also for
  a declaration inside a plain `if`); the duplicate guard (one query for the request, held PHP and
  CSS but never a shortcode, the notice, released after deactivation, fail
  closed, no query without an import switched on); the admin page render
  (escaping, held, broken, clashes, locked switches, the map, capability);
  safe mode by constant last.
- **snippets-test.php** — the shipped `snippets/` folder: `php -l` on every
  PHP file, every header parses, the `defined( 'ABSPATH' ) || exit;` guard
  in every PHP snippet, no broken file, no clash, the example off.
- **editor-rules-test.php** — the editor's pure half: who may write (the
  constant missing or empty, listed by login in any case, by user ID,
  digits never a login, not listed, no `manage_options`,
  `DISALLOW_FILE_EDIT`, multisite super admin vs site administrator; a
  constant that is not a string — `true`, an array, a float — names nobody,
  a whole number is one ID), the
  fields (slug rules, the slug kept on edit, name / description lengths
  and header-breaking `*/` / `-->`, type, every scope of every type,
  priority, on / off, empty and oversized code), opening-tag stripping and
  CRLF / BOM normalising, the parse check with the stored code's line,
  declarations, clashes with files and other database snippets (guarded,
  the same slug's file left out, twice in one snippet, case), superseded
  by a usable or broken file, the merge (db:<slug> keys, superseded
  refused, a file deployed later wins, two database snippets refused, no
  database snippet = untouched), the merged order and the output plan
  never reading the file switch map, the active option (only active ones,
  only the loader's fields, declarations, its serialized size), its
  signature (an entry written straight into the option, any field changed
  after signing, a made-up or missing signature, new salts: not verified;
  `merge()` leaves such entries out), the drift between the stored option
  and the table (missing, off or deleted but listed, out of date,
  unsigned), the
  history and audit summaries (fields old -> new, code hash and line
  counts, never the code, cut to 2000), the CSV guard and row, the request
  fields (REMOTE_ADDR validated, first X-Forwarded-For only, user agent cut
  to 255) and the export file (header, Default, Origin, the ABSPATH guard,
  after `namespace` / `declare` / inside a braced namespace, read back by
  the Header parser and the Symbols scan, `php -l` clean; CSS / JS / HTML
  bodies equal to the code).
- **editor-test.php** — the save pipeline on the in-memory store: a good
  save writes row + revision + log + the active option, tested once after
  the draft was kept; refused saves write nothing (invalid fields, syntax,
  clashes with a file / another snippet, a slug taken or used by a GitHub
  or broken file, the active size cap, a failing test run that writes only
  its `test_failed` row, a database failure at the row / revision / log
  that rolls back); edits (version 2, the version lock, nothing changed,
  a stale form, saved off = not tested, CSS never tested); the switch
  (revision + log, test run on, refused when it throws or a GitHub file
  supersedes it); restore (a new version, test-run while on, the on / off
  state kept, a stale page, another snippet's revision); delete (row gone,
  revisions and earlier log rows byte-for-byte kept, last code hash) and
  restore after a delete (same id, off, slug taken, not a delete row);
  export, denied, safe mode, a test run that never came back; the active
  option never emptied by a read error; the store file itself (updates /
  deletes only the snippets table, no SQL touching the log but its INSERT);
  switching off never refused (a GitHub file deployed later declaring the
  same function, code that no longer parses, a name and code over today's
  limits — kept exactly; switching on again is checked); a write that did
  not land never reported as done (the option write failing after a save or
  a delete: flagged stale and said so; a refused COMMIT or a transaction
  that will not start: not saved, nothing kept); `sync_active()` (quiet
  when the option matches, rebuilds a lost entry with a Health warning,
  only tells someone who may not write, never treats an unreadable table as
  drift); a stale form (an on / off switch since is no conflict, a real
  change is — naming the version — and saving again on it goes through);
  the trial (`trial_save()` / `trial_restore()`: the entry a save or restore
  would test-run, nothing written, run or logged; none when it would not
  test-run).
- **db-run-test.php** — database snippets in whole requests beside
  invented files: the eval() runner (locals, output, a throw with the
  stored code's line, a ParseError, `return`), the merged order and
  scopes, superseded, a clash with a file, the exists-now preflight, the
  error in `error_log()` / `fishotel_log` / the stored errors under
  db:<slug>, no query and no write on a second request, nothing changed
  without database snippets, only signed entries run (one written straight
  into the option or with its code changed never runs; new salts stop
  every database snippet, the files still run), CSS / JS / footer HTML and
  `[fh_snippet]` (a file of the slug wins), safe mode by query, the
  editor's own save / restore request running the code it saves IN THE
  SNIPPET'S PLACE at plugins_loaded:1 (after the draft is kept; the
  handler then saves without running it again; a throw there is the save's
  `test_failed`, not the site's error; the old code runs when the save will
  be refused or saved off; a restore runs the restored version; a bad
  nonce, a user not named, not admin, not POST, another action or
  admin-ajax.php run the stored code, never the posted one; a file calling
  the saved helper's function on `init` keeps working), the real test run
  on save (runs once, a throw refused, an existing function refused, saved
  off not run); three child PHP processes where a compile-stage fatal ends
  the save request before any row, revision, log row or active option is
  written — the typed code kept as a draft, logged as `test_failed`
  afterwards —, ends the AJAX switch with a JSON answer naming the
  database snippet, and ends the save of an active snippet at
  plugins_loaded:1 after its draft was kept; safe mode by constant.
- **editor-admin-test.php** — the My snippets / Log tabs and every
  handler: without the constant (read only, the `define()` line, every
  write refused with 403 and logged as denied), with it (not listed, by
  ID, no `manage_options`, multisite), a stale nonce, save (redirect,
  test run, row / revision / log / autoloaded option, the notice once),
  a refused save keeping the typed code and showing it with the reason,
  discard, a new snippet that throws, a draft still "testing" settled on
  the next page view (logged once), the AJAX switch on / off / refused,
  history with the diff and Restore buttons, restore, delete, the Log's
  Restore after a delete, the log filter, export as a download, the CSV
  (every row, formula-guarded, nonce and `manage_options`), nothing
  printed unescaped on any tab (a script / img / `</textarea>` in the
  name, description and code), an expired nonce giving the code back read
  only and escaped without keeping a draft, other administrators (and a
  site administrator on multisite) never shown the code — no edit,
  history, diff or new screen, no links to them —, never losing typed code
  (a switch since the form was opened, a real conflict then saved again, a
  snippet deleted meanwhile offered as a new one with its slug and linked
  from the list), the list the site runs following the table (an option
  write that fails: the switch and the save say so; another
  administrator's view only tells; the named editor's next view rebuilds
  it, dropping an entry written through options.php, with a Health
  warning), the denied budget (30 an hour per account, 100 for all
  customer accounts together, an administrator's refusal still logged,
  fixed hourly windows), safe mode logged once an hour, the code editor by
  type and with highlighting disabled, no REST route, the install gate (no
  query, `SHOW TABLES` or `dbDelta` once installed; the first boot installs
  three tables, every column type exactly as MySQL / MariaDB report it, and
  the empty autoloaded active option; a failed install waits an hour and
  retries only in wp-admin; activation installs) and `DISALLOW_FILE_EDIT`
  last.

## By hand, once, on a real site

The files:

1. Enable **Snippets** on the FisHotel Tools dashboard. FisHotel Tools →
   Snippets lists *Example — footer marker*, off.
2. Switch it on, load the shop's home page, view the source: the last lines
   before `</body>` hold `<!-- FisHotel Snippets: example snippet is on -->`.
   Switch it off: the comment is gone.
3. Add `?fishotel-snippets-safe-mode=1` to an admin URL with the example
   on: the safe-mode notice shows, and the front end loaded through that
   flag (logged in) has no comment.

The editor (with `define( 'FISHOTEL_SNIPPETS_EDITORS', '<your username>' );`
in wp-config.php):

1. My snippets → Add new: slug `test-footer`, type HTML, scope footer, on,
   code `<!-- written in wp-admin -->`. Save: version 1. View the shop's
   source: the comment is before `</body>`. Log: one `create` row.
2. Add new PHP, on, code `throw new Exception( 'nope' );` — refused, the
   code still in the form, a `test_failed` row in the Log. Change it to
   `function fh_two( $a, $a ) {}` and save: the request dies (WordPress's
   error page); open My snippets again — the code is still there, the Log
   has the `test_failed` row.
3. Edit `test-footer` twice, open History, Diff with current, Restore
   version 1. Export it: the file downloads with the header. Delete it,
   then Restore it from its delete row on the Log tab (it comes back off).
4. Download the CSV. Remove the line from wp-config.php: the tabs turn
   read only and Save is refused (a `denied` row).
