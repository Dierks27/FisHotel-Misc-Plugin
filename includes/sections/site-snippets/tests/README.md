# Snippets — headless tests

All run without WordPress or a database. `wp-stubs.php` supplies in-memory
options (with the autoload flag recorded), an action / filter / shortcode
registry with a tiny `do_shortcode()`, the style and script queue,
`is_admin()` / `current_user_can()` switches, a fake `$wpdb` that answers
the Code Snippets guard query and counts queries, and points `error_log()`
at a temp file. Snippet files are invented and written to temp folders. The
helper exits 0 when CI runs it directly.

```
php includes/sections/site-snippets/tests/header-test.php
php includes/sections/site-snippets/tests/rules-test.php
php includes/sections/site-snippets/tests/symbols-test.php
php includes/sections/site-snippets/tests/loader-test.php
php includes/sections/site-snippets/tests/snippets-test.php
```

- **header-test.php** — every field, the continuation line, the defaults
  per type, every scope of every type, invalid values (scope for the type,
  unknown / mismatched type, priority, default, missing / empty / repeated
  Snippet, lengths), missing or malformed headers (no `<?php`, code before
  the header, no comment, never closed, past 8 KB, BOM, empty), file names,
  `body()`, the Code Snippets id from an Origin.
- **rules-test.php** — the enabled state (stored choice vs Default), Code
  Snippets' PHP scope rule, the CSS / JS / HTML slots per screen, priority
  then slug ordering, the PHP and output plans, safe mode (constant, query
  flag with and without the capability, never resolving the user without
  the flag), the duplicate guard (inactive plugin, active ids, ids as
  strings, unreadable table = all held), its candidates, the
  `[code_snippet id=N]` map with ambiguous ids, the shortcode flags.
- **symbols-test.php** — declarations found by `token_get_all` (reference
  functions, closures, arrow functions, `::class`, anonymous classes,
  methods, `use function`, namespaces plain and braced, nested and guarded
  declarations, heredoc braces), the TOKEN_PARSE syntax check, clashes
  (case-insensitive, guarded pairs as warnings, one guarded + one not
  refused, twice in one file, if / else, function vs class, namespaces),
  `exists_now()`, `Catalog::build()` (refusals naming the other file,
  broken files, a slug shared by two files, size cap) and `Catalog::get()`
  (stored autoloaded, served from the option while the signature matches,
  no write on a fresh read of an unchanged folder, rebuilt after a change).
- **loader-test.php** — whole requests: the Runner (a throwing snippet
  recorded and the next one run, missing files, a preflight skip, output
  discarded, locals local, a ParseError, a buffer left open, a failing
  callback); priority order on the front end and in admin; the error in
  `error_log()`, in `fishotel_log` and stored non-autoloaded; a second
  request with no query and no write; CSS / JS on empty handles per screen
  and footer HTML; `[fh_snippet]` with `format` / `shortcodes` and no
  recursion; `[code_snippet id=N]` registered only without Code Snippets;
  safe mode by query (visitor vs administrator, no output, page link keeps
  the flag); the switch (refuses broken, clashing and unknown files, stores
  autoloaded, off always allowed, clears the last error); the exists-now
  preflight; the duplicate guard (one query for the request, held PHP and
  CSS but never a shortcode, the notice, released after deactivation, fail
  closed, no query without an import switched on); the admin page render
  (escaping, held, broken, clashes, locked switches, the map, capability);
  safe mode by constant last.
- **snippets-test.php** — the shipped `snippets/` folder: `php -l` on every
  PHP file, every header parses, the `defined( 'ABSPATH' ) || exit;` guard
  in every PHP snippet, no broken file, no clash, the example off.

## By hand, once, on a real site

1. Enable **Snippets** on the FisHotel Tools dashboard. FisHotel Tools →
   Snippets lists *Example — footer marker*, off.
2. Switch it on, load the shop's home page, view the source: the last lines
   before `</body>` hold `<!-- FisHotel Snippets: example snippet is on -->`.
   Switch it off: the comment is gone.
3. Add `?fishotel-snippets-safe-mode=1` to an admin URL with the example
   on: the safe-mode notice shows, and the front end loaded through that
   flag (logged in) has no comment.
