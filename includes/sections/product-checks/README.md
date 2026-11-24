# Product checks

What each product still needs, counted on the products list — the owner's
words: "how many fish need photos, especially live fish, what ones need
fields filled in, and what ones still have the old style before we started
splitting apart the info so it looks nice in a table".

## Where it shows

**Products** (wp-admin → Products):

- Under WooCommerce's own links (All | Published | …), a second line
  **To fix:** — *Live fish without a real photo (n)* · *Live fish missing
  table info (n)* · *Old-style description (n)* · *Live fish without a bag
  size (n)* · *In stock with no price (n)* · *Clean Room drafts (n)* — a
  link with 0 is not shown — and **Recount now**.
- A **To fix** dropdown beside WooCommerce's filters: the same six, plus
  *Live fish with no gallery photo*, *Scientific name missing or different*,
  *Filed only under a sub-category* and *3rd bag ticked*.
- A **To fix** column after Name: small chips — "Photo", "Stand-in photo",
  "Table 5/8", "Old style", "Bag: Small", "Price: Large", "Scientific
  name", "Gallery", "Sub-category only" —, a ✓ when there is nothing to
  fix, "×3" when the 3rd bag is ticked. Click its heading to sort by how
  many problems.
- Row actions **Copy facts from the description** (on an old-style fish,
  when the theme has its copy — FisHotel Theme 1.28+:
  `fishotel_copy_facts_from_description()`, which only fills empty fields)
  and **Recount**; the same two as bulk actions.

**The product screen**: with the Edit screens section on, the flags show as
the *Still missing* chips in its *Fish essentials* box, each a link to the
box to fill.

**FisHotel Tools → Product checks**: the counts, how far the last check of
every product got, **Recount now**, and **Which categories count as live**.

## Who is checked, and for what

**Live fish (L):** a product in one of the statuses publish, private, draft,
pending or future — never an invoice — with one of its **own** categories
in the live list, or *Livestock — packed in a bag* ticked. The live list is
a setting: by default the fish categories the Batch plugin uses everywhere
(`quarantined-fish`, `inverts`, or what its filter
`fishotel_ship_day_fish_categories` says). If corals come back one day,
tick their category on the page — no code. Saving it, or *Use the fish
categories again*, checks every product again.

**Gets the facts table (T):** its own category is `quarantined-fish` (the
theme prints the table for that category only). Inverts are live but get
no table checks for now — they are checked for photo, price and bag size
(the owner's answer).

| Flag (hidden meta) | When |
|---|---|
| `_fh_todo_photo` = `none` / `standin` | L, and no featured image (or one that is gone) / a stand-in: an image of the theme's placeholder libraries (`fh_placeholder_library`, `fh_cs_placeholder_library`) or WooCommerce's placeholder, the same image featured on 3 or more products, or a file named *placeholder*, *coming soon*, *no image*. (The storefront hides a missing photo with a rotating stand-in, so it is easy to miss.) |
| `_fh_todo_gallery` | L, and no gallery photo |
| `_fh_todo_table` = how many missing | T, and any of the 8: scientific name, common names, max length, min tank size, temperament, reef safe (yes / no / caution), foods & feeding, habitat & behaviour — the last two not counting when they are the site's default text. Region is not required. |
| `_fh_todo_oldstyle` | L, and the facts are still typed in the description: two or more different labels at the start of a line ("Scientific Name:", "Maximum Length:", "Foods and Feeding:", …, "Fun Facts"), one of them with its field empty. The theme's own answer when it has one (`fishotel_fish_facts_old_style()`, 1.28+), so the list and the facts box's yellow line agree. Labels found with every field filled are harmless and not flagged. |
| `_fh_todo_sci` | T, and the scientific name is missing under the name (the short description) or in the table (`_fh_scientific_name`), or they differ (markup, capitals and spaces aside) |
| `_fh_todo_price` = `1` / the sizes | published or private, any product: a simple product in stock with no regular price, or the enabled in-stock variations without one |
| `_fh_todo_bag` = `1` / the sizes | L, Livestock not unticked, the Packing section on, and an in-stock size (or the simple product) Packing's own lookup gives no bag (`fishotel_packing_bag_for_product()`: its own Bag size, else the size map) |
| `_fh_todo_subcat` | filed under a category below Quarantined Fish but not in Quarantined Fish itself (no table, no certificate) |
| Clean Room drafts (no flag) | draft, with the Clean Room's mark `_fh_cleanroom_fish` |
| 3rd bag ticked (no flag) | `_fh_bag_layers` = `3` |

Plus `_fh_todo` (how many flags, for the sort) and `_fh_todo_v` (the rule
version). **A clean product stores nothing.** The "without a real photo"
view is the one `_fh_todo_photo` key (both values), not two keys.

## Cheap and fresh

- **One product** is checked again (a few milliseconds: its meta, its
  variations, one pattern over its text) whenever it changes in WordPress:
  saved anywhere (`woocommerce_new_product`, `woocommerce_update_product` —
  the editor, quick edit, bulk edit, REST, CSV import), a variation saved,
  a stock status changed (`woocommerce_product_set_stock_status`,
  `woocommerce_variation_set_stock_status`), its featured image or gallery
  set (`added / updated / deleted_post_meta` — "Set featured image" does not
  save the product), its categories set (`set_object_terms`). Each only
  queues the id; the product is checked once, at the end of the request
  (`shutdown`). These hooks are the only ones registered outside wp-admin
  (a stock status changes at checkout).
- **The sweep** checks every product, 200 a run on WP-cron
  (`fishotel_product_checks_sweep`), re-scheduling itself until done:
  each night (`fishotel_product_checks_nightly`, for changes made outside
  WordPress), when the rule version changes (`Rules::VERSION`), when a
  placeholder library, WooCommerce's placeholder image or Packing's
  settings change, when the live categories are saved, and on *Recount now*
  (which checks the first 200 at once). Its cursor is the option
  `fishotel_product_checks_sweep`; the images featured on 3 or more products
  are worked out at its start (`fishotel_product_checks_shared`).
- **The counts**: one grouped read over the `_fh_todo_%` keys, one for the
  Clean Room drafts, one for the 3rd-bag tick — kept in the option
  `fishotel_product_checks_counts` (not autoloaded), forgotten when a check
  changes something. A filter is a plain `EXISTS` on one meta key; the
  column reads the meta WordPress already loaded for the page.
- **Staleness:** none for a change made in WordPress; up to a day for one
  made outside it (direct SQL, an import that bypasses WooCommerce).

## What is stored

| Where | Key | What |
|---|---|---|
| product meta (hidden) | `_fh_todo_<flag>`, `_fh_todo`, `_fh_todo_v` | the flags above — only on a product with something to fix |
| option (not autoloaded) | `fishotel_product_checks` | `live_cats` — absent = the default |
| option (not autoloaded) | `fishotel_product_checks_counts`, `fishotel_product_checks_shared`, `fishotel_product_checks_sweep`, `fishotel_product_checks_sweep_v` | the kept counts, the shared images, the sweep's cursor, the rule version swept |
| WP-cron | `fishotel_product_checks_nightly` (daily), `fishotel_product_checks_sweep` (single runs) | scheduled while the section is on; taken off when it is switched off |

Nothing is shown to a visitor; no e-mail; no outside call. The flags are
never read on the storefront.

## Files

```
product-checks/
├── class-product-checks.php  the section: boot, menu, activate / deactivate
├── class-rules.php           PURE: who is checked, the flags, the chips, a stand-in photo
├── class-collector.php       the facts of one product (reads only)
├── class-store.php           the only file that queries the database; writes the flags
├── class-checker.php         a product checked again when it changes; the sweep
├── class-settings.php        which categories count as live
├── class-products-list.php   the row, the dropdown, the column, row and bulk actions, the links
├── class-admin.php           the page; Fish essentials' chips; admin_init
├── css/product-checks.css    the row, the chips, the page
└── tests/                    headless — see below
```

## Tests

```
php includes/sections/product-checks/tests/rules-test.php
php includes/sections/product-checks/tests/checks-test.php
```

- **rules-test.php** — who is live (own category, Livestock, invoices
  never, the statuses, corals ticked) and who gets the table (inverts not);
  a complete fish storing nothing; every flag and its value (a gone image,
  a stand-in, reef safe "maybe", the default text, region not required, the
  scientific name missing, different or the same with markup, a price on a
  draft, a dry good's price, the bag with Livestock unticked or Packing off,
  a sub-category); the order of the flags and what is stored; the old-style
  detector (labels once, two needed, all filled harmless, HTML paragraphs
  with bullets, dashes, entities and no-break spaces, a label inside a
  sentence, Fun Facts, other spellings, text that is not UTF-8, the
  product's own answer winning); the chips and their boxes; a stand-in
  photo.
- **checks-test.php** — on a fake shop: the facts read from a product (its
  own categories, the sizes with no price by name, a sub-category, a
  placeholder by name, by library, by being shared, a gone image, a
  variation); flags written, kept, removed, the counts forgotten only on a
  change; a dry good storing nothing; no live category; the hooks of a
  change, each queuing an id once, checked at the end of the request; the
  sweep (start, 200 a run, the last run, after the end, new rules, the
  nightly start, unscheduled, its option not autoloaded); the setting (the
  default and the Batch filter, who may save, which nonce, only categories
  that exist, none, back to the default); the products list (the row and
  its counts, a 0 hidden, *Recount now*, nothing to fix, who may see it, the
  dropdown, each filter's query, the sort keeping every product, another
  list, the column after Name and sortable, the chips, ✓ and ×3, row and
  bulk actions with and without the theme's copy, the links and their
  nonces, one product, the whole list, who may, the bulk line); the Fish
  essentials chips; the section's hooks outside and inside wp-admin;
  switched off; the stylesheet; nothing for a visitor; the database read in
  one file.

### By hand, on a copy of the site

1. Switch the section on; open Products: within a few minutes the *To
   fix:* line shows (or *Recount now*). Each link lists exactly the
   products of its count.
2. Remove a fish's photo with *Remove product image* (no Update): reload —
   it is counted. Set it again: gone from the count.
3. Quick edit a fish's price to empty: *In stock with no price* goes up.
4. An old-style fish: *Copy facts from the description* (theme 1.28+) fills
   the empty facts; its *Old style* chip goes when every found fact is in
   its field.
5. Tick a Corals category under FisHotel Tools → Product checks: a coral
   with no photo is counted.
6. Switch the section off: no `fishotel_product_checks_*` event left in
   WP-cron (Tools → Site Health → Info, or WP Crontrol if installed).

## Not in this version

- "Shared" stand-ins count an image featured on 3 or more products of any
  kind (not only live fish): one grouped read.
- The theme's old-style answer is used when the theme has it; with an older
  theme the same rule is worked out here from the same labels.
