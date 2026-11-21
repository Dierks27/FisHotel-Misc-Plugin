# Sticker Giveaway

A free FisHotel sticker with every fish order, chosen by the customer at
checkout, plus an optional "want more?" add-on at a small price.

One free sticker per order (not once per customer): every fish order that
qualifies gets its pick.

## What the customer sees

On checkout, between the delivery details and the payment method, a card:

- **PICK YOUR FREE STICKER** — one radio card per design in the pool
  (picture, name, "FREE WITH YOUR FISH ORDER" badge) and a *No sticker,
  thanks* option. The first design is pre-selected, so a customer who never
  looks at the card still gets one. This block only appears when the cart
  qualifies.
- **WANT MORE?** — a quantity stepper per design at the add-on price
  (default $1.00, max 5 each). Shown to qualifying carts, and to everyone
  when *Offer add-ons to everyone* is on. A cart that gets no free sticker
  (meds only) sees the *add-ons only* heading and intro instead
  ("ADD A FISHOTEL STICKER — … free with any fish order"), since "want
  more?" would presuppose a free one.

Changing anything triggers WooCommerce's own `update_checkout`; the server
adds or swaps the sticker lines and the itemized statement refreshes.

## Designs: simple products, and the variations of a variable product

A **design** is what the customer picks. It is either

- a **simple product** (as the section has always had it), or
- **one variation** of a variable product — the shop keeps its stickers as
  one product with a variation per design, so the shop page shows one
  product instead of a row of them.

On FisHotel Tools → Sticker Giveaway the product search finds simple
products and variable products. Picking a variable product lists its
variations underneath, each with a tick:

- All of them are ticked when the product is first picked.
- A variation added to the product later is listed **unticked and marked
  "New — not offered yet"**. It is never offered without the owner ticking
  it.
- A variation that leaves an attribute on "Any" cannot be a design, and the
  page says so on its row: WooCommerce will not put such a variation in a
  cart without being told which value, and the picker has nobody to ask.
  Choose a value for every attribute on the product page.

On the picker a variation design is shown under **its own attribute
value** as the owner typed it — "Blue Tang", not "Reef Stickers - Blue
Tang" (two attributes: "Blue Tang, Large"; a global attribute shows the
term's name, not its slug) — and with **its own picture**, or the
product's when the variation has none.

Both kinds of attribute work: a custom attribute typed on the product
(the variation stores `attribute_style` = `Blue Tang`, capitals and space
kept) and a global one (`attribute_pa_colour` = `sea-green`).

In code a design is one post id: the product's for a simple product, the
variation's for a variation (`Rules::design_id()` reads it off a cart
line). The pool, the picker's fields and the piles are all keyed by it.

## Why stickers are real line items

The sticker is a normal product line added to the cart with cart item data
`fh_sticker = free|extra`. A variation design is added as a normal
variation line — `WC_Cart::add_to_cart( product, qty, variation, … )`, with
WooCommerce filling in the line's attributes from the variation itself. That
means:

- The admin order screen, every customer email and the PDF invoice name
  the design ("Reef Stickers - Blue Tang") and show "Sticker: Free with
  fish order" (or "Add-on") with no further integration — the person
  packing the box sees which design to drop in.
- The cart item key includes the `fh_sticker` value, so a free sticker, an
  add-on of the same design and a normal purchase of that product never merge.
- Order line items also get hidden meta `_fh_sticker` (`free` / `extra`) for
  reporting.

Every `woocommerce_before_calculate_totals` pass re-applies the rules to the
sticker lines, so nothing done on the cart page or by a settings change can
leave a line the picker would not have produced:

- free line: priced at $0, quantity forced to 1, removed when the cart no
  longer qualifies or the design left the pool;
- add-on lines: priced at the add-on price, quantity clamped to *Max
  quantity per design* (1 when the product is "Sold individually"), removed
  when the upsell is off, not offered to this cart, or the design left the
  pool.

A sticker line of either kind is also removed quietly when its design can
no longer be handed out — not published, not purchasable, or out of
stickers (see the two piles below). The same check gates the picker, so a
design is offered exactly when a line of it would be kept.

Adding a line goes through `WC_Cart::add_to_cart()` only after checking
what it would refuse; any error notice WooCommerce still raises is
discarded, so a sticker can never block Place Order — the line is simply
not added.

### On the theme's cart page

The theme's `woocommerce/cart/cart.php` draws its own quantity stepper.
The current theme passes it through `woocommerce_cart_item_quantity`, so a
sticker line shows the plugin's plain quantity in its place. Besides that:

- `woocommerce_cart_item_class` adds `fh-sticker-line fh-sticker-line--free|extra`
  to the row; the stylesheet hides the theme stepper on the free row (always 1)
  on a theme that still draws it. Add-on rows are clamped by the server
  whatever comes back.
- The theme's cart and checkout statement print the item data under the
  title (`wc_get_formatted_cart_item_data()`), so the "Sticker: Free with
  your fish order" / "Add-on" row from `woocommerce_get_item_data` is what
  tells a free sticker from an add-on of the same design. The plugin adds no
  name pill, so the label is never shown twice.
- The theme draws a line's thumbnail from the product the variation
  belongs to — the same picture for every design. `woocommerce_cart_item_thumbnail`
  swaps in the variation's own picture on sticker lines (in the size the
  template asked for). A variation without a picture keeps the product's.
- A variation line reads "Reef Stickers - Blue Tang" with the theme's pill
  repeating "Blue Tang", as every variation line in the shop does.
- Removing the free sticker with the theme's Remove link is remembered as
  "no sticker" for the session, so checkout does not quietly put it back.
- `css/sticker-front.css` is enqueued on the cart page regardless of *Also
  show on cart* so the FREE label is styled there.

## Two piles per design

Stickers are sold in the shop **and** given away, and most of them go into
fish boxes. So a design has two piles:

- The **shop pile** is the product's (or the variation's) own WooCommerce
  stock — the few set aside for selling on the site. The giveaway never
  reads it as a limit and never reduces it.
- The **giveaway pile** is a count this section keeps per design — the
  stickers that go into boxes. Free lines and add-on lines count it down.

A design is offered while its giveaway pile has stickers to hand out,
**whatever the shop pile says**: the variation may be at 0 or "out of
stock" in the shop and is still offered, put in the cart and ordered.

The giveaway pile is edited on the settings page with two acts, each with
its own number box and button, done the moment the button is pressed:

- **Add stickers** — new stickers arrived: type how many went into the
  pile. Adds to the paper count.
- **Recount** — the pile was counted: type how many are really in it.
  Replaces the paper count. Nothing is asked twice; a mistyped number is
  put right by another Recount.

Keeping a giveaway pile is a **switch per design** ("Keeps a giveaway
pile" on its row). It is on for every design picked from now on. A simple
product that was already in the pool before 0.46.10 stays as it was — off,
handed out from its own WooCommerce stock — until the owner ticks the
switch and saves. A pile starts empty, so a design switched on is not
offered until it has been counted; the page says so.

### The loss buffer

Stickers go missing — stuck to a tank, handed to a visitor, miscounted.
One setting, **Stickers that go missing**, a percent (default 10, 0–50),
keeps a pile from being trusted to its last sticker:

    B        = what went into the pile since it was last counted
               (the counted number, plus what was added since)
    reserve  = B × the percent, rounded up
    P        = the paper count
    really there = P − reserve      (never below 0)

The page shows **"P on paper · about (P − reserve) really there"**, and
the design **stops being offered when P ≤ reserve**.

A worked example with 10%:

| What happens | P (paper) | B | reserve | the page reads |
| --- | --- | --- | --- | --- |
| Recount: 200 | 200 | 200 | 20 | 200 on paper · about 180 really there |
| 150 go out with orders | 50 | 200 | 20 | 50 on paper · about 30 really there |
| 30 more go out | 20 | 200 | 20 | 20 on paper · about 0 really there — **no longer offered** |
| Add stickers: 100 | 120 | 300 | 30 | 120 on paper · about 90 really there — offered again |
| Recount finds 104 | 104 | 104 | 11 | 104 on paper · about 93 really there |

Orders move P only. A Recount makes B the typed number: what was lost so
far is in the count, so the reserve starts over from it. 0% trusts the
paper count to the last sticker.

Within one cart the pile is shared out the same way: what a cart holds of
a design (the free line first, then its add-ons) never exceeds "really
there"; an add-on line that no longer fits is cut down or dropped quietly.

### Reorder

Each design has a **Reorder at** number (blank = no reminder; saved with
Save Settings). **What is left** of a design is the giveaway pile's
"really there" plus the shop pile (when the product manages stock). When
what is left is at or below the reorder number the design reads
**Reorder** — and **Out** when it is no longer offered at all:

- in the **stock table** on the settings page, one row per design: the
  paper count, really there, the shop pile, how many went in the last 28
  days with "about N a week · roughly W weeks left" (of the giveaway pile,
  at that pace), the reorder number and the status;
- on the **Today** page and its dashboard widget: "Stickers to reorder:
  Blue Tang (about 40 left)". This goes through the Today section's own
  seam for other sections' lines (the `fishotel_today_lines` filter, as
  Packing's line does); nothing was built into Today;
- by **one email** when a design first drops to its number — to
  WooCommerce's stock notification recipient
  (`woocommerce_stock_email_recipient`), else the site admin address, as
  plain text through `wp_mail`, with a link to the settings page. No
  further email for that design until a restock or a recount has put it
  back above the number. *Email me when a design needs reordering*
  (default on) switches the emails off; the table and the Today line stay.

A design that keeps no giveaway pile can have a reorder number too; what
is left of it is its shop pile alone.

### The moments

The giveaway pile goes **down when WooCommerce itself reduces stock** for
the order — payment complete, or the order reaching processing, completed
or on-hold — and comes **back when WooCommerce itself restores it** — the
order cancelled, failed or back to pending — or when a refund is made with
*Restock refunded items* ticked (the refunded quantity; a refund without
the tick puts nothing back).

Each happens **once per order line** however often the hooks fire. The
order line is marked twice: hidden meta `_fh_sticker_pile` (the design,
stamped when the order is created — which pile a line comes out of is
decided at the order, not on the day it is paid) and
`_fh_sticker_pile_taken` (how many it holds from the pile right now). The
guarantee itself is in the database: one ledger row per order line that
only changes by compare-and-swap (`UPDATE … WHERE` it still reads what was
read), and only the request whose swap went through moves the pile. A
private order note says what moved ("Sticker giveaway pile: Blue Tang −1.").

**Two orders paid in the same second** each send their own

    UPDATE … SET on_paper = on_paper + ( −1 )

— one statement, serialised by the database. Nothing reads the count into
PHP and writes it back, so no decrement is lost. This is why the counts
are **not** in the settings option (the page saves that option whole, and
would overwrite a count with whatever number it was opened with) but in
two small tables of the section's own, created on its first boot and on
plugin activation (`Store::maybe_install()` / `Sticker_Giveaway::activate()`,
dbDelta, version in `fishotel_sticker_giveaway_db_version`):

- `{prefix}fh_sticker_piles` — one row per design: paper count, B, the
  "reorder email sent" mark, when it was last counted and added to;
- `{prefix}fh_sticker_lines` — one row per order line that took stickers:
  the ledger, and what "went in the last 28 days" is counted from.

Two customers can each hold the last sticker in their cart; the pile is
checked again at Place Order and a line whose pile ran out in between is
dropped quietly, but two orders placed in the same instant can both go
through. The paper count then reads one lower than the reserve (it may
even read −1): the reserve is what absorbs it, and the count is signed so
it says what happened instead of failing an order.

### What WooCommerce is and is not told

A giveaway line of a design that keeps a giveaway pile must not be refused
because the shop pile is empty, must not reduce the shop pile, and must
not change what a **normal** purchase of the same variation in the same
cart is checked against. **Nothing is ever saved on the product to
achieve this** — no stock status, no "manage stock" flag, no quantity.

WooCommerce asks the product object on a line three things before it lets
the line through or touches stock. A giveaway line carries an object of
its own (`class-pile-products.php`: the same product, read fresh, built
for that one line in memory) that answers them:

| WooCommerce asks | A giveaway line answers | So |
| --- | --- | --- |
| `is_in_stock()` | yes | the shop pile at 0 does not refuse it — on the cart page, on checkout, at Place Order, or when stock is held for an unpaid order |
| `managing_stock()` | no | WooCommerce neither checks it against the shop pile nor holds, reduces or restores the shop pile for it; no "stock reduced" note, no low-stock email |
| `get_stock_managed_by_id()` | 0 — nobody's | WooCommerce adds up cart quantities per stock owner; the giveaway lines are not in the sum a normal line of the same variation is checked against |

- **In the cart** the object is put on the line the moment the line is
  made (`woocommerce_add_cart_item`), every time the cart is read back
  from the session (`woocommerce_get_cart_item_from_session`), and checked
  again on every totals pass and just before WooCommerce's own cart check.
- **On the order** WooCommerce gets the same object whenever it asks a
  stamped order line for its product (`woocommerce_order_item_product`).
- **Our own add** is the one moment WooCommerce checks stock on an object
  it reads itself. For the length of that one `add_to_cart()` call, and for
  that one product, `woocommerce_product_is_in_stock` and
  `woocommerce_product_backorders_allowed` answer "yes"; both filters are
  removed as soon as the line exists. They are never on while a customer
  shops.

A normal purchase of the same product gets WooCommerce's own object and
is limited by the shop pile exactly as before, counted without the
giveaway lines: with 2 in the shop, 2 bought the normal way pass and 3
fail, whatever else the cart got for free.

A design that keeps **no** giveaway pile is not touched by any of this:
its lines carry WooCommerce's own object, are checked against the
product's own stock together with a normal purchase of it, and reduce that
stock when paid — as they always did.

Things to know:

- WooCommerce only fires its reduce and restore hooks while its own stock
  management is on (WooCommerce → Settings → Products → Inventory). With it
  off no pile is counted down; the settings page warns.
- While the section is switched off nothing here runs: an order paid or
  cancelled in that time does not move a giveaway pile, and a stamped line
  paid then is treated by WooCommerce as a normal line (the shop pile is
  reduced). Recount after switching the section back on.
- Changing a sticker line's quantity by hand on the order screen does not
  move the giveaway pile. Recount.

## Shipping

Stickers ride in the fish box. `woocommerce_cart_shipping_packages` drops
sticker lines from every package that still contains a non-sticker line and
recomputes `contents_cost`, so the theme's table-rate result (keyed on
shipping class) is identical with or without stickers. A package made only of
stickers (add-ons bought on their own) is left alone and ships normally.

**Leave each sticker product's shipping class blank** (or use a ground class).
The picker keeps its own sticker lines out of the shipping calculation
whatever the class, and the current theme's free-shipping piggyback
(`FisHotel_Checkout_Reuse::cart_requires_overnight()`) skips them too. But a
sticker bought the normal way, from the shop page, ships by its class: a
Premium/overnight class would turn that order into an overnight box. (On an
older theme, whose piggyback does not skip sticker lines yet, a Premium
sticker add-on would also make a meds-only re-order lose its free piggyback.)
The settings page says the first part.

## Theme coordination

The FisHotel theme reads every cart line in a few places. The current
theme skips lines carrying `fh_sticker` in each of them, so none of the
following needs anything from the owner on the live theme (1.26.0):

- `inc/cart-presets.php` → `fishotel_cart_resolve_preset()`: picks the cart
  and checkout wording from the categories of the cart's lines. It skips
  sticker lines, so a sticker product ticked in a preset-mapped category
  (Merchandise → `merch`) does **not** turn a fish order into the generic
  wording. The settings page therefore only warns about a preset category
  when the active theme does not skip sticker lines yet
  (`fishotel_cart_display_count()` arrived in the theme with that skip, so
  its presence is the sign) — and then says that updating the theme makes
  the warning go away, before it suggests re-categorising.
- `inc/order-fulfillment/class-fishotel-checkout-reuse.php` →
  `cart_requires_overnight()`: skips sticker lines.
- `fishotel_cart_display_count()`: the "Subtotal · N fish" count without
  sticker lines. (The plugin does not filter `woocommerce_cart_contents_count`,
  which WooCommerce uses for its own limits.)
- The cart's italic scientific name (the product's short description) is
  left off sticker lines; the short-description warning on the settings
  page is likewise only shown for a theme that does not skip yet.

## Eligibility

`Rules::is_eligible()` — pure, unit-tested:

1. Sticker lines never count.
2. At least one remaining line must be in a ticked category *or any of its
   descendants* (the Cart class resolves ancestry with `get_ancestors` and
   passes slugs in). No categories ticked = **no** order qualifies — the
   giveaway is off until a category is ticked, so an accidental untick-all
   can never turn into a store-wide giveaway. Add-ons can still be offered.
3. The remaining subtotal must reach *Minimum subtotal* (0 = none), compared
   at cent precision.

## Files

| File | Responsibility |
| --- | --- |
| `class-rules.php` | Eligibility, selection clamping, pricing; what a design is, how it is named, which variations are ticked after a save, when a design can be handed out. Pure — no WordPress calls. |
| `class-piles.php` | The numbers of a giveaway pile: reserve, "really there", restock and recount, the once-per-order-line ledger, reorder status, the one email's when, pace, and the cart arithmetic. Pure. |
| `class-store.php` | The two tables. Every change to a pile is one statement inside the database. |
| `class-designs.php` | A design id → product, name, picture, shop pile, giveaway pile; gathers the facts `Rules::blockers()` decides on. |
| `class-pile-products.php` | The product object a giveaway line shows WooCommerce. Loaded on first use. |
| `class-cart.php` | Sync the cart with the picker (diff-based, on `woocommerce_checkout_update_order_review` and `woocommerce_checkout_process`), pricing + quantity clamping on every totals pass, row class, labels, thumbnail, order line meta, shipping package stripping. |
| `class-orders.php` | The moments a giveaway pile moves; what an order line tells WooCommerce. |
| `class-reorder.php` | The stock table's rows; the reorder email. |
| `class-today-line.php` | "Stickers to reorder" on the Today page. |
| `class-frontend.php` | Renders the picker on `woocommerce_checkout_after_customer_details` (inside `<form name="checkout">`), enqueues assets, optional cart-page AJAX sync. |
| `class-settings.php` | Option `fishotel_sticker_giveaway_settings`, defaults, sanitised AJAX save. |
| `class-admin.php` | Settings page under FisHotel Tools → Sticker Giveaway: product search, variation ticks, stock table, the two acts, warnings, category tree, live summary. |
| `views/checkout-picker.php` | The card. Only `fh-sticker-*` classes; styled by `css/sticker-front.css` with theme-token fallbacks. |
| `views/settings.php`, `views/variations.php`, `views/stock-table.php`, `views/warnings.php` | Settings page markup; the last three are the parts a save or a count redraws. |

## Settings

FisHotel Tools → Sticker Giveaway. Needs `manage_woocommerce`. One page:

- **Sticker pool** — WooCommerce product search: simple products and
  variable products. A variable product lists its variations with ticks
  (see *Designs* above). Give each design a regular price (any amount) so
  WooCommerce treats it as purchasable; the picker overrides it. Anything
  the save drops (a variation picked on its own, a grouped product, a
  deleted product, an unknown slug) is named in the "Saved" notice and
  removed from the form, so what you see is what is stored.
  Warnings under the field name every ticked design that cannot be offered
  and why — not published, a variation not enabled, no price, not
  purchasable, an attribute on "Any", the giveaway pile empty or down to
  its reserve, out of stock (a design without a giveaway pile) — and, per
  product, "sold individually", a product saved with no category, and on a
  theme that does not skip sticker lines yet a preset category or a short
  description.
- **Stickers in stock** — the stock table: one card per design (picture,
  name, status; paper count, really there, shop pile, pace; *Keeps a
  giveaway pile*; *Reorder at*; *Add stickers* and *Recount*). Cards, not a
  table, so the page needs no sideways scrolling on a phone. Below it
  *Stickers that go missing* (the percent) and *Email me when a design
  needs reordering*.
- **Eligible categories** — product_cat checkbox tree, default
  `quarantined-fish`. Nothing ticked = no free stickers.
- **Minimum subtotal** — optional, sticker lines excluded.
- **Add-ons** — enable, price (required; blank restores the default rather
  than saving 0.00), max per design (hard ceiling 50), offer to everyone.
- **Wording** — free heading/intro, badge, "no sticker" label, add-on
  heading/intro (shown under the free picker) and add-ons-only heading/intro
  (shown when the cart gets no free sticker). `{price}` in either add-on
  intro becomes the add-on price. Blank restores the default.
- **Also show on cart** — off by default. When on, the card renders on the
  cart page too and syncs through a small AJAX action.

Everything is saved by **Save Settings** except *Add stickers* and
*Recount*, which change a count at once (a count is not a setting).

## Tests

```
php includes/sections/sticker-giveaway/tests/rules-test.php
php includes/sections/sticker-giveaway/tests/designs-test.php
php includes/sections/sticker-giveaway/tests/piles-test.php
php includes/sections/sticker-giveaway/tests/store-test.php
php includes/sections/sticker-giveaway/tests/woocommerce-seams-test.php
php includes/sections/sticker-giveaway/tests/reorder-test.php
php includes/sections/sticker-giveaway/tests/category-warning-test.php
```

- `rules-test` — category eligibility (including descendant slugs and the
  empty category list meaning "off"), the minimum-subtotal boundary, sticker
  lines being ignored, selection clamping to pool and max, pricing, and the
  switched-off section dropping its lines.
- `designs-test` — the design of a cart line and its name, for a variation
  on a custom attribute and on a global one; "Any"; which variations are
  ticked after a save (first pick, added later, unticked, deleted); which
  designs keep a giveaway pile; when a design can be handed out — a simple
  design without a pile exactly as before.
- `piles-test` — the reserve, "really there", when a design stops being
  offered, Add and Recount, the ledger, reorder status and the one email,
  the pace, a stock-table row, and the cart arithmetic.
- `store-test` — the store against an in-memory stand-in for the database:
  its statements are well formed, a move happens once, a second request
  between the read and the write, two orders at once, a refused statement.
  It does not prove that MySQL runs the statements the same way.
- `woocommerce-seams-test` — the giveaway-line object played through
  WooCommerce's cart checks as 11.2 writes them (on stand-ins for its
  product classes), and the source read for what must not be there: nothing
  saved on a product, the scoped filters removed again, nothing running
  while the section is off.
- `reorder-test` — the sentences: what is left, the pace, the Today line,
  the email, the answers to a typed count, why a design is not offered, the
  preset warning with and without a theme that skips sticker lines.
