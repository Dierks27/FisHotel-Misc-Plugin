# Packing

Which bag every fish goes in, and which box an order fits in.

The owner packs every fish in one of a handful of standard bags — normally
one fish per bag — and knows from experience which box an order needs.
This section writes that knowledge down so somebody else can pack: a **Bag
size** on every fish variation, **how many bags of each size fit each
standard box**, and from those two a **suggested box on every order**,
flagged *on the line* when it is nearly full so the owner confirms it.

The Bag size is a record, strictly. It is never shown on the storefront, in
the cart, at checkout, in an e-mail, on an invoice, in My Account or in the
customer-facing REST / Store API. See *Storefront silence* below.

Nothing is seeded: no bag, no box and no number is filled in by the code.

## Where it shows

**FisHotel Tools → Packing** (users who can `manage_woocommerce`), three tabs:

- **Bags** — the standard bags: a name, an optional width and length in
  inches, a sort number. A bag keeps its `#` for good, so renaming one
  changes nothing on the products that use it; a removed bag's `#` is never
  given to another, and a page that was drawn before the bag was removed
  cannot bring it back under a new `#` (that save is refused: reload).
- **Sizes → bags** — one row per fish size with a bag dropdown (the map),
  and the *Fill empty ones from the map* tool.
- **Boxes** — where the boxes come from, this section's own list of boxes,
  the *how many fit* table (box × bag), and the *on the line* percent.
  *Save boxes* draws the page again, so it refuses to run while *how many
  fit* holds numbers that are not saved yet: save those first.

A save that draws the page again (bags, the map, boxes) keeps its button
disabled until the page is back, so the same rows are never posted twice.

**The product editor** (the classic one) — a **Bag size** dropdown on every
variation, and on a simple product under *Shipping*. Its first option is
"Not set", and says which bag the size map gives that variation right now.

**The order screen** (the legacy one and the High-Performance Order Storage
one) — a **Packing** box: each fish line with its bag and where the bag
came from, the bags needed by size, the suggested box with its fill, the
*on the line* flag, what was ignored and what was left out, and the
Confirm / change control. *Confirm* stores the suggestion that is on the
screen and no other: when the order, a bag size or the numbers changed
since the box was drawn, nothing is stored, the box is drawn again with
the suggestion as it stands now, and it is confirmed from there.

## Which bag

For a product or a variation:

1. its own **Bag size** — it overrides everything;
2. else the **size → bag map**: the bag of the variation's size (or of a
   simple product's size when it has exactly one);
3. else none.

For an order line: its product as above; and when the product gives
nothing — an invoice line, an unlisted fish, a product that was deleted —
the size the *line* carries (WooCommerce copies a variation's attributes
onto the order line; the filter `fishotel_packing_item_size` can name
one), through the same map.

**The sizes** are the list the product variations and the Clean Room use:
when the Clean Room section is switched on its `Sizes` class resolves them
(the product attribute whose name or slug contains "size", the one its
Settings → Sizes picked when several match, or the plain list typed there
while no such attribute exists). When it is not, this section finds the
attribute itself — the first global attribute whose name or label contains
"size"; the filter `fishotel_packing_size_attribute` may name another.
With neither there is no list, the page says so, and a Bag size can still
be set on each variation by hand.

**Fill empty ones from the map** writes the map's bag onto every **fish**
variation that has no Bag size, so the product record shows it. *Preview*
counts and changes nothing; *Set them* runs a batch at a time. It never
touches a variation that already has a value — the list is read from the
variations without one, and each is checked once more on the product
object just before it is written. A variation whose product is in none of
the fish categories (see *What is a fish*) is counted in the preview and
left alone: a Bag size of its own is what makes any other product count as
a fish on an order, so the tool must not hand one to a net or a jar of
food that happens to use the Size attribute. Set such a product's Bag size
by hand when it really is packed in a bag.

A variation keeps the bag it was given even when the map changes later,
and there is no bulk reset — so get the map right before *Set them* (a
variation set back to "Not set" follows the map again).

## Which box

- **Bags needed** — for each fish line, its quantity in its bag size: one
  bag per fish. (The filter `fishotel_packing_fish_per_bag` can raise the
  fish per bag for a line; that is the seam for a product-level number.)
- **The fill of a box** — the sum over the bag sizes of *bags needed ÷ how
  many of that size fit the box*. Six small bags in a box that takes
  twelve and two large ones in a box that takes six is 50 % + 33 % = 83 %.
- A blank *how many fit* means that bag does not go in that box at all.
- **The suggestion** is the smallest box (by volume) whose fill is at most
  100 %.
- **When no box holds it all** it is split, and the box says so: the
  largest box that takes any of what is left is filled (the bulkiest bags
  first), and so on — except that as soon as one box holds everything that
  is left, the smallest such box takes it.
- **On the line** — a suggested box whose fill is at or over the threshold
  (a setting, 90 % until changed) is flagged for the owner to confirm.
- **A line with no bag size** is listed by name and the suggestion is
  marked *incomplete* — it is worked out for the lines that do have one.
- **What is not a fish** is ignored, and named as ignored.
- A bag size that goes in **no** box is set aside and named; the rest is
  boxed as usual (status `partial`). A suggestion names at most 40 boxes;
  what is left after that is named as not boxed.

Fills are compared after rounding the percent to four places, so 4/6 + 1/3
is exactly 100 % and 9/10 is exactly 90 %.

**What is a fish** follows the Batch plugin's Ship Day pick list, so the
two agree: only product lines; a line that already shipped or never ships
(`_fishotel_line_status` = `shipped` / `na`) and a fully refunded line are
left out, a partly refunded line counts what is left; a line flagged
`_fh_invoice_fish` and a product-less line with `_fishotel_fish_id` are
fish; an invoice credit is money; an Invoice product kept as one line is
opened up (its `_fh_invoice_lines` snapshot, else its description through
the theme's or the Batch plugin's parser when one is there — and when
nothing can read it, it is reported as a fish line without a bag, never
dropped); otherwise a line is a fish when its product is in a fish
category (the Ship Day list when the Batch plugin has it, else
`quarantined-fish` and `inverts` under the same filter,
`fishotel_ship_day_fish_categories`) **or** carries a Bag size of its own.

**A combined box** (the theme's fulfillment order) is read through its
live source orders, as the pick list does. An order that went *into* a
combined box shows its lines and bags and a link to the combined box: the
box is suggested and confirmed there, not on the source.

**The boxes** come from ShipTracker when the build that is active provides
them — `FST_Settings::get_standard_boxes()`, reached through
`class_exists()` / `method_exists()` — so there is one list for the label
costs and for packing. Otherwise (ShipTracker is not active, its version
has no such list yet, or the list is still empty) this section's own list
is used, and the Boxes tab says which. *How many fit* is stored under the
box's name. In the own list each row says which box it was drawn as, so a
box that is renamed there takes its numbers along — a rename is never
read off a row's position, which a form posted twice or from an
out-of-date page gets wrong. Besides that, a box whose name has no numbers
yet takes over those of a box that is no longer in the list and has
exactly the same size, so the move from the own list to ShipTracker's
(where the boxes are typed again) keeps what was typed.

## What is stored

| Where | Key | What |
|-------|-----|------|
| option (not autoloaded) | `fishotel_packing_settings` | `bags` (id, name, width, length, sort), `last_bag_id`, `boxes` (the own list: name, length, width, height), `fits` (per box: name, dims, bag id → how many), `size_map` (size slug → bag id), `threshold` |
| product / variation meta | `_fh_bag_size` | the bag's id |
| order meta | `_fh_packing_box` | the confirmed box: `v`, `boxes` (name, length, width, height), `fill` (as it stood), `suggested`, `complete`, `by` (user id), `at` (UTC) |
| order meta | `_fh_packing_box_name` | the same as plain text: `Medium box`, `2 × Large box + Small box` |

There is no table and no cron event. Product meta is written through the
WooCommerce product object, order meta through the order object
(`update_meta_data()` / `delete_meta_data()` / `save()`), so it works with
High-Performance Order Storage on or off. Confirming a box writes those
two order meta keys and nothing else: no note, no status change, no
e-mail, no stock.

## Contracts

All of these are declared by the Misc plugin — guard a call with
`function_exists()`. While the Packing section is switched off they answer
"nothing" (see below) and never throw.

```php
fishotel_packing_suggest( int $order_id ): array
fishotel_packing_bag_for_item( WC_Order_Item_Product $item ): ?array   // for the pick list
fishotel_packing_confirmed_box( int $order_id ): ?array
fishotel_packing_bag_for_product( int|WC_Product $product ): ?array
fishotel_packing_bag_for_size( string $size_slug_or_label ): ?array
```

**`fishotel_packing_suggest()`** always returns the same keys:

| Key | |
|-----|---|
| `available` | false while the section is off, WooCommerce is not loaded or the order does not exist; `reason` says which (`disabled`, `no_woocommerce`, `order_not_found`, `error`) |
| `status` | `fits` (one box holds every bag) · `split` (several do) · `partial` (boxes for what can be boxed; some bags go in no box) · `no_fit` · `no_boxes` · `empty` (no bags needed) · `unavailable` |
| `boxes` | the suggested box(es): `key`, `name`, `length`, `width`, `height` (inches), `dims` (`LxWxH`), `volume`, `source`, `bags` (bag id → how many go in it), `fill` (1.0 = full), `fill_pct`, `on_the_line` |
| `split`, `on_the_line`, `threshold` | more than one box · a suggested box is at or over the threshold · the percent |
| `bags`, `bag_total` | bags needed by size, in the bag list's order: `id`, `name`, `width`, `length`, `label`, `count` |
| `unplaced` | bags no box takes: `id`, `name`, `count` |
| `lines` | every fish line: `item_id`, `order_id`, `name`, `qty`, `fish_per_bag`, `bags`, `bag` (null without one), `size`, `size_label`, `flag`, `from` |
| `incomplete`, `complete` | the fish lines with no bag size (`item_id`, `order_id`, `name`, `qty`, `reason`) · true when there is none |
| `ignored`, `skipped` | lines that are not fish · lines left out (shipped, never shipping, refunded) — each with a `reason` |
| `box_source` | `shiptracker` or `own` |
| `is_fulfillment`, `sources`, `packed_with` | a combined box and its live source order ids · the combined box an order went into (0 = none) |
| `confirmed` | `fishotel_packing_confirmed_box()` of the order |
| `messages` | what a person should read, as sentences |

**A bag** (`fishotel_packing_bag_for_item()` and the two helpers): `id`,
`name`, `width`, `length` (inches as text, `''` when not given), `label`
(`Medium (6 × 14 in)`), `sort`, `source` (`own` = set on the variation or
the product, `map` = from the size → bag map), `size`, `size_label`. Null
when the line has no bag size.

**`fishotel_packing_confirmed_box()`**: `order_id`, `boxes` (each `name`,
`length`, `width`, `height`, `dims`, `volume`), `count`, `name`, the first
box's `length` / `width` / `height` / `dims`, `fill`, `suggested`,
`complete`, `by`, `by_name`, `at`. Null without one. An order that went
into a combined box has none of its own — ask the combined box
(`packed_with`).

**Filters**

| Filter | Arguments | |
|--------|-----------|---|
| `fishotel_packing_suggest` | `$result`, `$order_id` | the answer of the function |
| `fishotel_packing_bag_for_item` | `$bag`, `$order_item` | " |
| `fishotel_packing_confirmed_box` | `$box`, `$order_id` | " |
| `fishotel_packing_bag_for_product` | `$bag`, `$product` | " |
| `fishotel_packing_bag_for_size` | `$bag`, `$size` | " |
| `fishotel_packing_fish_per_bag` | `1`, `$product`, `$order_item` | fish per bag for a line (a whole number above 1 counts) |
| `fishotel_packing_item_size` | `$size_slug`, `$order_item` | the size an order line carries |
| `fishotel_packing_size_attribute` | `''`, `$attributes` | which attribute is the size, while the Clean Room is off |
| `fishotel_packing_boxes` | `$boxes`, `$source` | the boxes in use |
| `fishotel_ship_day_fish_categories` | `$slugs` | read, not owned: the fish categories (the Batch plugin's filter) |

**Actions**

- `fishotel_packing_box_confirmed` — `$order_id`, `$box` (the
  `fishotel_packing_confirmed_box()` shape) after a box was confirmed or
  changed.
- `fishotel_packing_box_cleared` — `$order_id` after it was cleared.

**REST routes**: none. **Public AJAX**: none. The admin AJAX actions
(`manage_woocommerce` + a nonce each): `fishotel_packing_bags`,
`fishotel_packing_boxes`, `fishotel_packing_fits`, `fishotel_packing_map`
(nonce `fishotel_packing_settings`), `fishotel_packing_fill_preview`,
`fishotel_packing_fill_run` (nonce `fishotel_packing_fill`) and
`fishotel_packing_confirm` (nonce `fishotel_packing_order`; with
`choice=suggested` the screen posts `shown`, the suggestion it displayed,
and gets 409 plus the box drawn again when that is not the suggestion any
more). The product
dropdown is saved by WooCommerce's own product saves, behind this
section's nonce `fishotel_packing_bag_size` as well.

## What it reads from other code — and what happens without it

| From | What | Without it |
|------|------|-----------|
| Clean Room section | `Sizes::standard()` — the list of sizes | this section finds the "size" attribute itself |
| ShipTracker | `FST_Settings::get_standard_boxes()` — the standard boxes | this section's own list of boxes |
| Batch plugin | `FisHotel_Ship_Day::fish_categories()`, `::parse_invoice_lines()`; the line meta `_fishotel_fish_id` | `quarantined-fish` + `inverts`; an Invoice without a snapshot is reported as unreadable |
| Theme | `FisHotel_Invoice_Lines::get_lines()`; the line and order meta `_fishotel_line_status`, `_fh_invoice_fish`, `_fh_invoice_product_id`, `_fh_invoice_lines`, `_fh_invoice_credit`, `_fishotel_is_fulfillment`, `_fishotel_fulfillment_sources`, `_fishotel_fulfilled_by_order` | every order is an ordinary order |
| WooCommerce | products, orders | the page still opens and says so; the lists can be filled in |

Each is reached through `class_exists()` / `method_exists()` /
`is_callable()` only, inside a `try`, and never written to.

## Storefront silence

- Outside wp-admin `boot()` loads nothing but the section's own file,
  registers **no hook** and reads **no option**: an ordinary front-end
  request costs no query here. (admin-ajax.php counts as wp-admin, which
  is where the product editor and the order screen save.)
- In wp-admin the hooks are the product editor's
  (`woocommerce_product_after_variable_attributes`,
  `woocommerce_save_product_variation`,
  `woocommerce_product_options_shipping`,
  `woocommerce_admin_process_product_object`), `add_meta_boxes`,
  `admin_enqueue_scripts` and the admin AJAX actions — none of the hooks
  WooCommerce uses to print variation data, cart / checkout / e-mail /
  invoice / My Account order-item data, or to build a REST or Store API
  answer.
- The bag is never written to an order line: it is worked out from the
  product each time. The meta key is hidden (a leading underscore), is not
  registered for REST, is not a product attribute, and makes no variation
  and no term.
- The lists are one option that is **not autoloaded**: the Packing page,
  the product editor and the order screen read it once; no other screen
  does.

`tests/storefront-test.php` holds the section to all of this.

## Files

```
packing/
├── class-packing.php        the section, and the contract functions
├── class-settings.php       the one option, its sanitisers, the four saves
├── class-size-list.php      the fish sizes (the Clean Room's, else found here)
├── class-boxes.php          the boxes in use; "how many fit" per box
├── class-suggest.php        PURE: bags needed → box(es), fill, on the line
├── class-bags.php           a product's / an order line's bag
├── class-lines.php          an order's fish lines
├── class-order-packing.php  the suggestion of an order; the confirmed box
├── class-store.php          the only $wpdb file — reads only
├── class-fill.php           "Fill empty ones from the map"
├── class-product.php        the Bag size dropdown in the product editor
├── class-order-box.php      the Packing box on the order screen
├── class-admin.php          the page; the assets
├── views/                   page, tab-bags, tab-sizes, tab-boxes, order-box
├── css/packing-admin.css    the dark page; the box on the (light) order screen
├── js/packing-admin.js      the saves, the fill run, the box's buttons
└── tests/                   headless — see tests/README.md
```

## Not in this version

- **Fish per bag** on a product: the maths and the filter are there, the
  field is not.
- **The pick list** itself lives in the Batch plugin; it can show the bag
  of a line by calling `fishotel_packing_bag_for_item()`.
- The Bag size dropdown is in the **classic** product editor (variations
  and the Shipping tab); quick edit, bulk edit and the product import do
  not set it.
