# Dark admin — headless tests

All run without WordPress, a browser, a database or the network.
`wp-stubs.php` supplies what the section touches: a hook registry that
remembers every `add_action()` / `add_filter()` (with its priority and
number of arguments) and can run a hook, user meta, the logged-in user,
the current screen and `$pagenow`, the colour-scheme registry and the
enqueue calls — all in-memory arrays a test seeds and reads back.

```
php  includes/sections/dark-admin/tests/rules-test.php
php  includes/sections/dark-admin/tests/palette-test.php
php  includes/sections/dark-admin/tests/hooks-test.php
node includes/sections/dark-admin/tests/guard.test.js
```

- **rules-test.php** — `Rules`: a person nobody asked yet is dark, only an
  explicit off ("0", "off", "no", "false") is light; a profile save writes
  `1` / `0` only when the profile form's hidden marker came with it (an
  unticked box is not sent by a browser; another form that saves a user
  leaves the switch alone); every wp-admin screen is dark except the block
  editor, the Customizer, the site editor and the Customizer's preview,
  and nothing outside wp-admin is; the scheme is "fishotel-dark" on a dark
  screen and the person's own choice otherwise and inside the picker; the
  body class is added once and a look-alike class is not mistaken for it;
  the writing area's sheet goes after the theme's, once.
- **palette-test.php** — `Palette` and the three style sheets read as
  text: every text colour reaches 4.5 : 1 on every background the sheet
  puts it on (and the check catches a pair that does not, and a missing
  colour); the sums agree with known values; the colours that come from
  the FisHotel Tools screens are exactly theirs (`admin/css/admin-style.css`);
  `dark-admin.css` opens with exactly the palette as variables, uses no
  variable it does not declare, hangs every selector on
  `body.fh-dark-admin`, writes no colour of its own outside the variables
  (black pop-up backdrops aside), has each part its header names and
  styles the pop-ups, pickers, editor, media, WooCommerce and Yoast parts;
  the light box's text, quiet text and links read on its own backgrounds;
  `editor-content.css` hangs on the same class, uses only palette colours
  and styles the guard's three classes; `scheme.css` gives WordPress's
  theme colour the darker FisHotel blue, is built from the palette, styles
  the menu and the admin bar, and white menu text reads on its current
  item.
- **hooks-test.php** — `Dark_Admin` and `Hooks`: on the storefront and the
  login page nothing at all is hooked; in wp-admin exactly ten hooks, with
  the right priorities and argument counts; "FisHotel dark" is registered
  from `scheme.css` with the palette's swatches; the menu uses it for a
  person nobody asked yet and not for one who switched it off, not in the
  Customizer or the block editor, not while the picker is drawn, and for
  another person's Edit User screen by that person's own switch; the sheet,
  the guard (in the footer) and the body class come only for a person who
  wants them, on a dark screen, logged in; the writing area gets its sheet
  and class, or nothing when switched off; the Profile row, ticked or not,
  with its hidden marker and its plain words; saving it on Profile and on
  another person's Edit User screen, leaving it alone for another form,
  and refusing someone who may not edit that user; every file the section
  names is there.
- **guard.test.js** — `js/dark-admin.js` under node (it exports its sums
  and starts nothing): colours as the browser reports them (rgb, rgba, the
  slash syntax, short / long / alpha hex, transparent, fractional channels;
  anything else left alone); the WCAG sums against known values; the
  colour behind the text through see-through layers; the rescue (readable
  text untouched; WordPress's grey made lighter just enough; red stays
  red; light text in a white box goes dark; a link stays blue; see-through
  text; on every grey from black to white the rescued text reads); the
  writing area's classes and taking them off again (only the guard's own);
  and with a stand-in editor, that whatever the editor hands over — a
  save, an autosave, the Text tab — leaves without the guard's classes, an
  element whose only class was the guard's loses the attribute, the
  checks run again after new content, a paste, undo and redo, and an
  editor is hooked once.

## By hand (a real site, once per release)

On a copy of the shop, as an administrator, with the section switched on:

1. Open **Dashboard**, **Products**, a fish's edit screen (every product
   data tab, a variation opened), an order's edit screen, **Media** (and
   *Set product image* on a product), **Users → Profile**, **Plugins**,
   **WooCommerce → Settings / Status / Home / Analytics**, **FisHotel
   Tools**, **Batch Manager** and **ShipTracker**: every one dark, no white
   box with white words, no dark words on dark.
2. Open quick edit and bulk edit on Products, Screen Options, the order
   date's calendar, the customer dropdown on an order, a colour picker
   (WooCommerce → Settings → Emails): dark, readable.
3. In a product's description, switch between **Visual** and **Text**:
   both dark. Type a word, make it red with the toolbar, save, open the
   **Text** tab: the HTML has no `fh-da-` class anywhere.
4. **Users → Profile**: untick **Dark admin**, Update Profile — every
   screen is WordPress's own light again, with the colour scheme picked
   above; tick it again — dark.
5. Open the shop (the storefront) logged in, and the login page: nothing
   changed; the black admin bar on the storefront is WordPress's own.
6. **FisHotel Tools → Dashboard**: switch **Dark admin** off — wp-admin is
   exactly as before for everybody; on again — dark again for everyone
   who did not untick it.
