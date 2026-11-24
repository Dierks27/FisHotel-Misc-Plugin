# Dark admin

Every wp-admin screen in the same dark look as the FisHotel Tools pages —
for everyone, and each person can switch it off for themselves.

Switch the section on in **FisHotel Tools → Dashboard** (like every
section, it arrives switched off). From then on wp-admin is dark for every
person who logs in: the admin menu and the black bar at the top, the
Dashboard, every list (posts, pages, products, orders, coupons, media,
comments, users, plugins), the product and order edit screens with every
box on them, WooCommerce's product data tabs and variations, its
settings, status and reports, the classic editor (the Visual writing area
and the Text tab), the media library and its pop-up, Screen Options and
Help, quick edit and bulk edit, the calendars, the searchable dropdowns,
the colour pickers, thickbox and jQuery UI pop-ups, notices, Tools,
Settings, Site Health, and the FisHotel plugins' and the theme's own
screens and boxes (Batch Manager, ShipTracker, the Shipments, medication
and Packing boxes on an order, Clean Room, Packing, Weather, …).
WooCommerce's newer pages (Home, Analytics, Customers) are dark as far as
their own drawing allows (see *Left light on purpose*).

Switch the section off and wp-admin is exactly as it was: nothing of it
is loaded. The per-person ticks stay saved for the day it is switched on
again.

## Each person's own switch

**Users → Profile → Personal Options → Dark admin** — a tick, ticked for
everybody until they (or an administrator, on their *Edit User* screen)
untick it and press *Update Profile*. Unticked, that person sees
WordPress's own light screens with the colour scheme they picked above it;
everyone else stays dark. A person who never opened their profile is dark.

While the tick is on, the menu and the top bar use the colour scheme
**FisHotel dark** (it is also offered in the colour-scheme picker). The
picker keeps showing — and saving — the scheme the person chose, so
unticking *Dark admin* brings exactly that back.

Stored as the user meta `fishotel_dark_admin`: `1` on, `0` off, missing =
on. A save from any other form that saves a user (one without the
profile's own hidden marker) never changes it.

## Where it is never loaded

- **The storefront** — the section does not even hook anything outside
  wp-admin. The black admin bar a logged-in person sees on the shop is
  WordPress's own, unchanged.
- **The login page.**
- **The Customizer** (Appearance → Customize) and its preview of the shop,
  and the **site editor**: they show the shop itself and stay as WordPress
  draws them.
- **The block editor** (a post or page edited with blocks): its own
  full-screen editor, left light; the menu beside it is dark. Products
  and orders use the classic screens and are dark.

## How it works

- `class-rules.php` — pure: whose switch says what, what a profile save
  writes, which screens turn dark, which colour scheme, the body class.
- `class-palette.php` — pure: the colours and the WCAG contrast sums.
  The colours are the FisHotel Tools screens' own
  (`admin/css/admin-style.css`): `#1a1a1a` page, `#2d2d2d` boxes,
  `#363636` hover, `#e0e0e0` text, `#a0a0a0` quiet text, `#00a0d2`
  accent, `#0eb5e8` links, plus what a whole wp-admin needs that those
  screens never did: `#1e1e1e` fields, `#242424` every other table row,
  `#00789f` (a darker FisHotel blue that white button text reads on),
  light red / green for words, and dark tints for notices and order notes.
  Every text colour reaches 4.5 : 1 (WCAG's bar for normal text) on every
  background it is put on — the test checks each pair.
- `class-hooks.php` — WordPress: registers *FisHotel dark*, uses it on a
  dark screen (never inside the picker), adds `fh-dark-admin` to the admin
  `<body>`, loads the style sheet and the guard, the writing area's sheet
  and class, the Profile tick and its save.
- `css/scheme.css` — the colour scheme (menu, top bar, links, buttons,
  focus rings), built from WordPress 7.1's own `wp-admin/css/colors/_admin.scss`
  with Dart Sass the way core builds its schemes; the values are in its
  header.
- `css/dark-admin.css` — **one layer of variables**: the palette declared
  once on `body.fh-dark-admin`, and every rule hangs on that class and uses
  only the variables. Its parts, in order: page and type, links, buttons,
  fields, boxes, tables, notices, tabs and Screen Options, quick / bulk
  edit, the classic editor, media, pop-ups (thickbox, jQuery UI, select2,
  date and colour pickers), the dashboard and core screens, WooCommerce,
  its React pages, Yoast, the FisHotel plugins' and the theme's boxes, the
  guard's class, and the boxes kept light. ShipTracker and Yoast are styled
  from the outside only — neither is ever edited.
- `css/editor-content.css` — the classic editor's Visual writing area
  (an iframe of its own), added after the theme's editor style.
- `js/dark-admin.js` — **the readability guard.** A style sheet cannot see
  a colour another plugin wrote straight onto an element
  (`style="color:#333"`, `style="background:#fff"`). After the page is
  drawn, and again whenever something new appears (AJAX, variations,
  order items, WooCommerce's React pages), the guard looks at every piece
  of text that is shown, works out its colour and the colour really behind
  it, and where the two are under 4.5 : 1 it makes the text lighter or
  darker — keeping its hue, so red stays red — just far enough to read.
  It writes one class and one CSS variable on that element, nothing else.
  The common light inline backgrounds (`#fff`, `#f9f9f9`, `#f0f0f1`, …)
  and greys (`#333`, `#666`, …) are already turned dark by the style sheet;
  the guard is for the rest.
  In the Visual writing area it uses two plain classes instead
  (`fh-da-ink-light` / `fh-da-ink-dark`, and `fh-da-paper` behind a grey
  neither reads on), and the editor takes them off again every time it
  hands the text over — saving, autosave, the Text tab — so **nothing the
  guard does is ever saved into a description.**

## Left light on purpose

These stay light, with dark words, inside the dark screens:

| What | Where | Why |
|---|---|---|
| The paper previews: the foam cut sheet, an invoice's preview, a Lost Fish e-mail's preview | Foam Cuts, Invoices, Lost Fish | They show the printed page / the e-mail as the customer gets it (`.fh-fc-paper`, `.fh-inv-preview__box`, `.fh-lf-preview__frame`) |
| The Shop App QR code | FisHotel Tools → Shop App | A phone camera reads dark squares on white (`.fh-sa-link__qr`) |
| Order status pills (Processing, Completed, …) | Orders list, order preview | WooCommerce's pale pill with dark words reads well on dark; recolouring would lose their meaning |
| Yoast's Google preview | Yoast box on a product | It imitates Google's white results page |
| The colour field of a colour picker | WooCommerce → Settings → Emails, … | It is the colours themselves (its frame is dark) |
| Pictures, product photos, the media library's thumbnails, the welcome panel's artwork | everywhere | Images are never recoloured |
| The block editor, the Customizer, the site editor | Posts / pages edited with blocks, Appearance | See *Where it is never loaded* |
| Pages of other sites shown inside wp-admin (WordPress.org's plugin details pop-up, a payment provider's iframe) | Plugins → *View details*, WooCommerce → Payments | A frame from another site cannot be styled from here |
| WooCommerce's charts' coloured lines and bars | WooCommerce → Analytics | Their own colours; the axes and words around them are dark-themed |

To keep another box light, give it the class `fh-da-light` (it sets the
light colours again on itself) and add a line to this table.

## Settings

None besides the per-person tick. The section's switch on the FisHotel
Tools Dashboard is the switch for everyone.

## Tests

`tests/README.md` (four headless files, and the by-hand steps for a real
site).
