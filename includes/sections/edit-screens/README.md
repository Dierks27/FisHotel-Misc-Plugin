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
     name is saved to both: the short description **with the post itself**
     (`wp_insert_post_data`, so the Batch plugin's Master Fish sync on
     `save_post_product` already reads the new name — no stale extra Master
     Fish, no "one save late"), the table's meta after WooCommerce and the
     theme saved theirs (`woocommerce_process_product_meta`, priority 50);
     a name saved as it was drawn writes nothing. **A short description that
     holds more than the name** — a second line, a typed fact with a colon,
     a link, a list, more than 200 characters — **is never overwritten**: its
     own box stays on the screen (even for a live fish), Fish essentials says
     so, and a name typed there goes to the facts table only. When the two differ today,
     both are shown, each with **Use this one** — nothing changes until the
     owner picks one (or types one) and updates. From theme 1.28 the facts
     box leaves the field out (its filter `fishotel_fish_facts_box_fields`);
     with an older theme its own field is kept equal by the script and
     hidden. The facts box's **Copy them into these fields** hands the
     scientific name on (the jQuery event `fishotel-copy-facts`): the field
     is filled when it is empty; when it holds another name, that name is
     kept and the copied one is offered with **Use this one**. Shown for a
     live fish only.
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
   Tabs: General (simple products), the theme's **Medication & food** tab
   when it is there (11, right after General), **Attributes** (12),
   **Variations** (14), Inventory, Shipping —
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
it — unless it holds more than the name, then it stays). Side: Publish, **Product categories** (moved above the photo: they
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
| live fish | Livestock ticked, or with no stored choice one of the fish categories (the Batch plugin's list — `quarantined-fish`, `inverts` unless its filter says other — plus any category ticked as live in Product checks: one shared list, `includes/class-live-categories.php`) | the short description (unless it holds more than the name) |
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
  screens), Shipments, Custom Fields (closed); WooCommerce's
  *Downloadable product permissions* **hidden** — as WooCommerce hides it
  for a person with no layout of their own (a stored list, even an empty
  one, would show it open on every order); Screen Options brings it back.
- **Side:** Order actions; **USPS Letter Tracking** (ShipTracker 1.10.0 —
  placed by its id `fst-letter` only; see below); FisHotel Pickup
  (ShipTracker — placed by its id `fst-pickup` only) / Fulfillment
  (combined box); Weather; EA Packing Slip /
  Medication terms; Order notes; Combine Orders; closed: Date Change
  Override, Order attribution, Customer history.
- **An order folded into a combined box:** Packing, Weather, EA Packing Slip
  and Combine Orders are drawn **closed** (each says "see #…"), so the first
  screen is Order data, Items, Shipments. (The theme's answer,
  `fishotel_order_get_fulfillment()`, else the order meta
  `_fishotel_fulfilled_by_order`.)

- **ShipTracker's USPS Letter Tracking box** (`fst-letter`, ShipTracker
  1.10.0 — the letter's barcode, Print label, Mark mailed, Void &
  regenerate, Resend): right under Order actions, where the sticker
  letter's work is. It is **open** on an order with a sticker letter —
  the Sticker giveaway's flag `_fishotel_sticker_mail_letter`, or a
  letter ShipTracker already made on it (one made by hand, without the
  flag) — and **closed** (its title only) on every other order, the way
  ShipTracker itself puts it high on a sticker order and low on others.
  ShipTracker is read through its public `FST_Letters::get_by_order()`
  only (`includes/class-shiptracker-letters.php`), never its table, and
  not before its database is at 1.5.0; with ShipTracker 1.9.x the flag
  alone decides, and the box is not there at all.
  A person tidied by Misc 0.46.19 / 0.46.20 has a stored layout that does
  not name the box — WordPress would draw it by ShipTracker's own
  priority, above Order actions on a sticker order. For them it is placed
  right under Order actions as the screen is drawn
  (`get_user_option_meta-box-order_<screen>`, `Layouts::with_letter()`):
  nothing is written, the layout version stays 1, so nobody is tidied
  again and their own drags are kept; once they drag a box, WordPress
  saves the order as drawn. A person who put their old layout back, or
  dragged the letter box somewhere themselves, is left as they are.

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
   back · Dismiss"**. (WooCommerce's own order screen, HPOS, shares one
   screen id between the orders list and an order: the notice and the
   Screen Options links show on an order only — `action=edit` / `new`.)
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
├── class-order-screen.php   a folded order's boxes, closed; the letter box open or closed, and placed
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
  differ, what a save writes, emptied, nonsense); the titles and the tabs;
  ShipTracker's letter box (placed under Order actions in a layout that
  does not name it, else first on the side; left where a layout already
  has it; open / closed classes; who counts as tidied).
- **screens-test.php** — the hooks (none outside wp-admin; on; off = the
  give-back only; the priorities); the switch on each screen, the notice and
  its links, Screen Options, every link refused without its own nonce, for
  nobody, or for another op or screen; dismiss, undo, reset, the give-back;
  the product screen's boxes (Fish essentials first in the main column, the
  four titles, the callbacks untouched, the empty area under the title); the
  kind on the page; the assets with each kind's categories; Fish essentials
  drawn (the field, the two places, the chips escaped, Packing's fields
  through its contract or not at all) and saved as one Update runs —
  `wp_insert_post_data`, `save_post`, `process_product_meta` — (as drawn:
  nothing; changed: both, the short description already new when
  `save_post` runs; emptied; a quote; a bad nonce, none, a person who may
  not edit; another post or not a product); a short description that holds
  more than the name (what counts, the first line offered, kept on save,
  its box left on the screen, the line that says so); one read for the
  kind categories; a category ticked live in Product checks; the HPOS
  orders list (no notice, no links) against an order; a folded order;
  the letter box on an order (the flag; a letter ShipTracker made; all
  voided; ShipTracker's database not at 1.5.0; open / closed as drawn;
  placed for a person tidied on version 1, nothing written; not for one
  who put their old layout back, nor one never tidied); and in the source: no front-end hook, no REST route, no cron, no
  option, user meta written by the user store only, no box removed.
- **edit-screens.test.js** — the kind from what is ticked (the PHP rule's
  cases), the class swapped on `<body>`, the boxes the area takes, what the
  theme's copied scientific name does (fill, same, offer, none).

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
   the first row of the facts table both say it — and the Batch plugin's
   Master Fish list shows the new name after this one Update (no second
   Master Fish under the old name). A fish whose two places differ shows
   both with *Use this one*.
3a. A fish whose short description has a second line (e.g. "Reef safe:
   with caution" under the name): the short description box is on the
   screen, Fish essentials says it is never changed from there; change the
   name, update — the short description is as it was, the facts table has
   the new name.
3b. A fish with an empty Scientific name whose description has
   "Scientific Name: …": in the facts box press *Copy them into these
   fields* — the Fish essentials field fills; type another name first and
   press it again — your name stays, the copied one is offered with *Use
   this one*.
4. Drag a box, reload: it stays where you put it. *Put my old layout back*:
   your old screen. Screen Options → *Reset to the FisHotel layout*: tidied
   again.
5. Open an order (legacy screen): Packing under Items; Weather, EA slip,
   Combine Orders closed on an order folded into a combined box;
   *Downloadable product permissions* not shown (Screen Options lists it,
   unticked). With HPOS on: the orders list has no "This screen was tidied"
   notice; an order has it.
5b. With ShipTracker 1.10.0: an EA-only order with a sticker letter has
   *USPS Letter Tracking* open right under Order actions; a fish order
   has it closed in the same place. A person tidied before this version
   sees the same, and their own drags are still there.
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
