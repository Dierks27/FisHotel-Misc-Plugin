# Edit screens

The product and order edit screens of wp-admin, tidied — the owner's
words: "things that should be filled out up towards the top, the optional
things a bit lower and the non essentials at the bottom."

**One place owns the layout; each box's owner only decides whether its box
has something to say.** No box's code is touched — not WooCommerce's, the
theme's, the Batch plugin's, ShipTracker's (never edited) or Yoast's (never
changed): boxes are placed, closed and hidden by their **ids**, through what
WordPress already keeps for every person who drags boxes around (their user
meta); four are **renamed** (title only); and boxes that are not for the
kind of product on the screen are **not shown** — still printed and still
posted, so **nothing saves differently**.

Switch the section off and each person gets their own old screens back
(the next time they open each one).

## The product screen

Three bands. Bands 1 and 2 open; band 3 closed.

**Under the title** (band 1)

1. **Fish essentials** — new (`fishotel-essentials`):
   - **Scientific name** — one field for the name kept in two places today:
     the **short description** (the italic line under the fish's name on
     its page, the Latin line on related-fish cards, the key the Batch
     plugin's Master Fish library reads) and the theme's
     **`_fh_scientific_name`** (the first row of the facts table). A changed
     name is saved to both, through the product, after WooCommerce and the
     theme saved theirs (`woocommerce_process_product_meta`, priority 50);
     a name saved as it was drawn writes nothing. When the two differ today,
     both are shown, each with **Use this one** — nothing changes until the
     owner picks one (or types one) and updates. From theme 1.28 the facts
     box leaves the field out (its filter `fishotel_fish_facts_box_fields`);
     with an older theme its own field is kept equal by the script and
     hidden. Shown for a live fish only.
   - **Livestock — packed in a bag** and **Needs a 3rd bag (sharp spines or
     a scalpel)** — the Packing section's two fields (same meta, same save),
     drawn here through its contract `fishotel_packing_product_fields()`
     when that section is on; the Shipping tab then leaves them out. (If a
     person dragged the product data box above Fish essentials, the Shipping
     tab draws them and Fish essentials does not — never twice on the form.)
   - **Still missing** — read-only chips ("Photo", "Price: Large", "Table: 3
     of 8 empty"), each a link to the spot, from the Product checks section when it
     is on (filter `fishotel_edit_screens_missing`, `( array $chips, int
     $product_id )`, each chip `label` and optional `href`). Absent when
     nothing is missing.
2. **Price, stock & sizes** — WooCommerce's *Product data* box, renamed.
   Tabs: General (simple products), **Attributes** (12), **Variations** (14),
   the theme's medication tab when it is there (15), Inventory, Shipping —
   the **Shipping class** moved first by the script —, Linked products,
   Advanced.

The two boxes are stored as the first two of the main column; the script
moves them under the title **as the page is drawn**, before any ready
handler runs — so WooCommerce's own script starts on its box where it now
stands, with its id unchanged. A site without this section (or without the
plugin) draws them as the first boxes under the description: never lost.
They have no up / down arrows there.

**Band 2 — should fill (open):** the description; **Fish facts — the table
on the product page** (the theme's *FisHotel — Product Data*, renamed);
the short description (not shown for a live fish: Fish essentials writes
it). Side: Publish, **Product categories** (moved above the photo: they
decide the kind), Product image; gallery, tags, **Compatibility guide**
(renamed), **Coming Soon — release date** (renamed), Clean room.

**Band 3 — rare (closed):** the theme's staff notes (the ids
`fishotel_staff_notes` / `fishotel-staff-notes`, once the theme splits
them out), Yoast SEO, Reviews; **hidden** (Screen Options brings them
back): Custom Fields, Slug, Product brands.

### Product kinds

Worked out from the categories when the screen is drawn, and again by the
script whenever a category, "Livestock — packed in a bag" or "Who ships it"
changes (`class-kinds.php`, `js/edit-screens.js` — the same rule):

| Kind | When | Not shown |
|---|---|---|
| live fish | Livestock ticked, or with no stored choice one of the fish categories (the Batch plugin's list — `quarantined-fish`, `inverts` unless its filter says other) | the short description |
| medication / food | the theme's medication and food categories, or "Who ships it" set | fish facts, compatibility guide, Clean room, the scientific name |
| invoice | category `invoices` (never a fish) | as a dry good |
| dry good | anything else (gift cards included) | as medication |
| no category yet | nothing ticked | nothing |

## The order screens

The legacy screen (`shop_order`, live) and WooCommerce's own
(`woocommerce_page_wc-orders`, HPOS) — the same layout, by when in an
order's life a box is used:

- **Main:** the two warnings (only when there is one), Order data, Items,
  **Packing** (the Packing section now adds it under the Items on both
  screens), Shipments, Custom Fields (closed).
- **Side:** Order actions; FisHotel Pickup (ShipTracker — placed by its id
  `fst-pickup` only) / Fulfillment (combined box); Weather; EA Packing Slip /
  Medication terms; Order notes; Combine Orders; closed: Date Change
  Override, Order attribution, Customer history.
- **An order folded into a combined box:** Packing, Weather, EA Packing Slip
  and Combine Orders are drawn **closed** (each says "see #…"), so the first
  screen is Order data, Items, Shipments. (The theme's answer,
  `fishotel_order_get_fulfillment()`, else the order meta
  `_fishotel_fulfilled_by_order`.)

Boxes that only appear on some orders still only appear then. A box this
section does not name (PayPal's, if it adds one) lands where its owner put
it.

## Once per person, with an undo

WooCommerce writes a product layout into each person's user meta the first
time they open a product, so a "default only" layout would never show.
Instead (`class-switcher.php`, `class-tidy.php`):

1. The **first time a person opens** the product screen, or an order screen,
   while the section is on (`add_meta_boxes`, priority 50 — after
   WooCommerce wrote its own layout at 40, before any box is drawn), their
   own layout of that screen — `meta-box-order_*`, `closedpostboxes_*`,
   `metaboxhidden_*`, "absent" included, and a copy under the site's table
   prefix if one exists — is copied to the backup (user meta
   `fh_edit_screens_backup`), and the FisHotel layout is written in its
   place (user meta `fh_edit_screens` remembers the layout version).
2. A notice on that screen: **"This screen was tidied: … Put my old layout
   back · Dismiss"**.
3. **After that their own drags are kept**: nothing is written again — until
   the layout version goes up (`Layouts::VERSION`), which tidies everyone
   once more, with the same notice and undo.
4. **Screen Options** has **Reset to the FisHotel layout** (backs up what
   they have now, writes the layout again) and **Put my old layout back**.
5. **Switched off:** each tidied person's backup is put back the next time
   they open that screen (one who had already put theirs back keeps what
   they have); switched on again, everyone is tidied once more.

Every person is tidied (the owner's answer: everyone, once, with the undo).
The links go to `admin-post.php?action=fishotel_edit_screens` with `op`
(`undo` | `reset` | `dismiss`) and `screen`, each with a nonce of its own
(op and screen in it), and only ever touch the person who clicks.

## What is stored

| Where | Key | What |
|---|---|---|
| user meta | `meta-box-order_<screen>`, `closedpostboxes_<screen>`, `metaboxhidden_<screen>` | WordPress's own: written once per person and screen (above) |
| user meta | `fh_edit_screens` | per screen: `v` (layout version), `notice`, `undone`, `off` |
| user meta | `fh_edit_screens_backup` | per screen and part: `plain`, `prefixed` (null = absent) |

No option, no table, no cron, no REST route; nothing outside wp-admin and
nothing for a visitor. Nothing is stored on a product or an order but the
scientific name the owner changes in Fish essentials (the short description
and `_fh_scientific_name`, as the screen always saved them).

## Files

```
edit-screens/
├── class-edit-screens.php   the section: boot (wp-admin only), boot_disabled (the give-back), assets
├── class-layouts.php        PURE: the layouts of the product screen and the order screens
├── class-switcher.php       the once-per-person switch, the undo, the reset, the give-back
├── class-user-store.php     the only file that writes user meta
├── class-tidy.php           when the switch runs; the notice; Screen Options; the admin-post links
├── class-kinds.php          PURE: the kind of a product; which boxes each kind shows
├── class-essentials.php     the Fish essentials box: drawn and saved
├── class-product-screen.php the area under the title, the names, the tabs, the kind on the page
├── class-order-screen.php   a folded order's boxes, closed
├── css/edit-screens.css     the box, the kinds, the chips (plain admin colours)
├── js/edit-screens.js       the boxes under the title; the kind; the name; the Shipping class
└── tests/                   headless — see below
```

## Tests

```
php  includes/sections/edit-screens/tests/rules-test.php
php  includes/sections/edit-screens/tests/screens-test.php
node includes/sections/edit-screens/tests/edit-screens.test.js
```

- **rules-test.php** — the layouts (every box once, in its band; closed and
  hidden; the same on both order screens; a folded order's boxes); the
  switch on a stand-in-free user store over stubbed user meta (backup with
  "absent" and the prefixed copy, the layout where WordPress reads it, the
  version and the notice, their own drags kept, each screen on its own,
  dismiss, undo exactly, reset then undo, off → their own back once, on →
  tidied again, someone who had undone keeping theirs, a new version); the
  kinds (fish, invert, Livestock outside the fish categories, unticked,
  medication, food, "Who ships it", invoice, dry, none; the Batch filter) and
  which boxes each shows; the scientific name (which is shown, when they
  differ, what a save writes, emptied, nonsense); the titles and the tabs.
- **screens-test.php** — the hooks (none outside wp-admin; on; off = the
  give-back only; the priorities); the switch on each screen, the notice and
  its links, Screen Options, every link refused without its own nonce, for
  nobody, or for another op or screen; dismiss, undo, reset, the give-back;
  the product screen's boxes (Fish essentials first in the main column, the
  four titles, the callbacks untouched, the empty area under the title); the
  kind on the page; the assets with each kind's categories; Fish essentials
  drawn (the field, the two places, the chips escaped, Packing's fields
  through its contract or not at all) and saved (as drawn: nothing; changed:
  both; emptied; a bad nonce, none, a person who may not edit); a folded
  order; and in the source: no front-end hook, no REST route, no cron, no
  option, user meta written by the user store only, no box removed.
- **edit-screens.test.js** — the kind from what is ticked (the PHP rule's
  cases), the class swapped on `<body>`, the boxes the area takes.

### By hand, on a copy of the site

1. Switch the section on. Open a product as a person who has used products
   before: the notice shows; Fish essentials and *Price, stock & sizes* sit
   under the title; the categories are above the photo; Yoast, Reviews and
   the staff notes are closed at the bottom; Custom Fields, Slug and Brands
   are hidden. WooCommerce's box works: change the product type, open
   Variations, add a variation, save.
2. Tick a dry-goods category on a fish: the facts, compatibility and Clean
   room boxes go, the short description comes. Tick *Quarantined Fish*
   again: back.
3. Type a new scientific name, update: the italic line under the name and
   the first row of the facts table both say it. A fish whose two places
   differ shows both with *Use this one*.
4. Drag a box, reload: it stays where you put it. *Put my old layout back*:
   your old screen. Screen Options → *Reset to the FisHotel layout*: tidied
   again.
5. Open an order (legacy screen): Packing under Items; Weather, EA slip,
   Combine Orders closed on an order folded into a combined box.
6. Switch the section off, open a product and an order: your old screens.
   Switch it on: tidied once more.
7. On the live site, check Screen Options on a product and an order: where
   Yoast's, Gift Cards' and PayPal's boxes land.

## Not in this version

- The bands have no headings on the screen: their order and the closed
  boxes are the bands.
- The short description stays in Screen Options for a live fish only as a
  hidden box (the kind hides it whatever Screen Options says).
- Gift card products are dry goods by their category (Gift Cards is not on
  the rehearsal copy — checked on the live site only).
