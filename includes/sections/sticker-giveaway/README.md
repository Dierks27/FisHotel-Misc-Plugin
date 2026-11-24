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
value** as the owner typed it — "Night Glow", not "Reef Stickers - Night
Glow" (two attributes: "Night Glow, Large"; a global attribute shows the
term's name, not its slug) — and with **its own picture**, or the
product's when the variation has none.

Both kinds of attribute work: a custom attribute typed on the product
(the variation stores `attribute_style` = `Night Glow`, capitals and space
kept) and a global one (`attribute_pa_colour` = `sea-green`).

In code a design is one post id: the product's for a simple product, the
variation's for a variation (`Rules::design_id()` reads it off a cart
line). The pool and the picker's fields are keyed by it.

## Why stickers are real line items

The sticker is a normal product line added to the cart with cart item data
`fh_sticker = free|extra`. A variation design is added as a normal
variation line — `WC_Cart::add_to_cart( product, qty, variation, … )`, with
WooCommerce filling in the line's attributes from the variation itself. That
means:

- The admin order screen, every customer email and the PDF invoice name
  the design ("Reef Stickers - Night Glow") and show "Sticker: Free with
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
no longer be handed out — not published, not purchasable, or out of stock
(see *Stock* below). The same check gates the picker, so a design is
offered exactly when a line of it would be kept.

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
- A variation line reads "Reef Stickers - Night Glow" with the theme's pill
  repeating "Night Glow", as every variation line in the shop does.
- Removing the free sticker with the theme's Remove link is remembered as
  "no sticker" for the session, so checkout does not quietly put it back.
- `css/sticker-front.css` is enqueued on the cart page regardless of *Also
  show on cart* so the FREE label is styled there.

## Stock: one count, and it is WooCommerce's

A design's count **is its WooCommerce stock** — the number on the
product's own page:

- a variation that manages its own stock: the variation's;
- a variation whose product manages stock for it: the product's (the
  designs of that product then share one count, and the page says so);
- a simple product: the product's.

A free sticker, an add-on and a normal shop purchase of that design all
come out of that one number, through WooCommerce's own stock handling and
nothing else: reduced when WooCommerce reduces stock for the order,
restored when WooCommerce restores it, held while an order is unpaid —
exactly as for any product. The section keeps no count of its own, no
table, and changes nothing about what WooCommerce checks, holds or
reduces for a sticker line.

**When a design is offered.** While its count is at least 1
(`Rules::has_stock()`): stock quantity ≥ 1 when WooCommerce tracks the
stock — also when the product allows backorders; the giveaway never hands
out a sticker that is not there — and "in stock" when it does not track it.
On the picker a design must also have one left beside what this customer
is already buying of it: a design whose last ones are in the cart as a
normal purchase is not offered (it could be picked but never added).

**A sticker never blocks an order and never costs a sale.** A sticker
line must fit in the count less whatever else of that same count is in
the cart (`Rules::fit()`): normal purchases are served first, then the
free sticker, then the add-ons. When the count no longer covers a sticker
line — it ran out between the picker and Place Order, or the customer
raised a normal purchase of the same design — the *sticker* line is cut
down or dropped quietly, as for a product that went out of stock. That
happens on every totals pass and once more on `woocommerce_check_cart_items`
at priority 0, just before WooCommerce's own check at priority 1
(`WC_Cart::check_cart_items()`, which prints "not enough stock" and stops
the order) — there with the stock WooCommerce is holding for other
customers' unpaid orders taken off first, as WooCommerce's check does.
The normal purchase keeps whatever the stock allows; if it asks for more
than there is, WooCommerce says so about the purchase, as it always did.

WooCommerce checks stock in two more places, and the sticker line gives
way there too, by the same rule:

- **A normal purchase added from the shop** while a sticker of the same
  stock number is in the cart. `WC_Cart::add_to_cart()` counts everything
  of that stock number already in the cart and would refuse with "you
  already have 1 in your cart". On `woocommerce_add_to_cart_validation`
  the sticker lines of that count step back as far as the purchase needs
  (`Cart::make_room()`). When the stock would not cover the purchase even
  without the stickers they are left alone, and WooCommerce answers.
- **The pay page of an order whose payment failed** (My account → Pay).
  WooCommerce checks there that every line is still in stock and shows
  "… this order cannot be paid for" in place of the payment form. On
  `before_woocommerce_pay`, and only for an order WooCommerce is about to
  check (the order's own key, not pending, stock not taken yet), the
  order's sticker lines are cut down or taken off (`Cart::guard_order()`),
  the order is totalled again and an order note says so. A normal line
  that ran out is still WooCommerce's to refuse.

In all of these only the sticker lines move; what WooCommerce checks and
what it answers is never changed.

### The stock table

FisHotel Tools → Sticker Giveaway → **Stickers in stock**, one row per
design: picture, name, **In stock** (the WooCommerce number), **Reorder
at**, a status (*OK* / *Reorder* / *Out*) and two small acts:

- **Add stickers** — a new box arrived: adds the typed number.
- **Recount** — what was counted by hand: sets the stock to the typed
  number.

Both change the stock through WooCommerce's own `wc_update_product_stock()`,
at once, so the product's page shows the same number. The page says it
once, plainly: this IS the stock number on the product's page, and shop
sales and giveaway stickers both come out of it.

A product that does not manage stock reads *not tracked*: it is offered
while WooCommerce says "in stock", and has no reorder reminder until
"Manage stock" is ticked on the product.

### Reorder

*Reorder at* is a number per count (blank = no reminder) — the owner's
signal to count the stickers by hand and reorder. When the stock is at or
below it the design reads **Reorder** (**Out** at 0):

- in the stock table;
- on the Today page and its widget — "Stickers to reorder: Night Glow (40
  left)" — through Today's `fishotel_today_lines` filter;
- in **one email** when the stock first drops to the number: "Night Glow
  is down to 40 (reorder at 40). Time to count the stickers by hand and
  reorder.", with a link to the settings page. It goes through `wp_mail`
  to WooCommerce's stock notification address
  (`woocommerce_stock_email_recipient`), else the site admin. No further
  email for that design until its stock is back **above** the number — a
  restock, or a recount that puts it above (`Rules::reorder_step()`).
  *Email me when a design needs reordering* (on by default) switches the
  emails off.

The look runs when WooCommerce changes that product's stock, whatever the
route — an order, the product page, Add stickers, Recount all end in
`woocommerce_product_set_stock` / `woocommerce_variation_set_stock` — and
when the settings are saved. A product without a *Reorder at* number
costs one array lookup. A stock change is only noted; the look itself
happens once, at the end of the request, so an order that takes a design
down line by line (a purchase, the free sticker, an add-on) writes one
email with the number the stock ended at, and two designs dropping in one
order share one email.

The look does not ask how the stock got there: a Recount that lands at or
below the number, when no email has gone for that drop yet, sends the
email too.

WooCommerce's own low-stock and out-of-stock emails (WooCommerce →
Settings → Products → Inventory) still exist, are separate, and are not
changed.

### What is stored

- In the settings option: the pool and the ticks, the email switch, and
  `reorder_at` (count id → number).
- One tiny option per count whose email is out,
  `fishotel_sticker_giveaway_reorder_sent_<product id>` (not autoloaded):
  made when the email goes, deleted when the stock is back above the
  number, the number is taken away or the design leaves the pool. It is
  kept apart from the settings so that an order never rewrites them, and
  it is made with `add_option()`, which succeeds for one request only —
  two orders paid in the same instant write one email, not two.
- On the product: nothing, except the stock number itself when Add
  stickers or Recount is used.

No pace ("about N a week") is shown: WooCommerce has no cheap, truthful
figure for it (its analytics tables can be switched off or behind, and
stickers handed out any other way never reach them), and the section
keeps no record of its own.

## Shipping

Stickers ride in the fish box. `woocommerce_cart_shipping_packages` drops
sticker lines from every package that still contains a non-sticker line and
recomputes `contents_cost`, so the theme's table-rate result (keyed on
shipping class) is identical with or without stickers. A package made only of
stickers (add-ons bought on their own) is left alone and ships normally.
When the only other lines are medications the supplier drop-ships, there is
no FisHotel box to ride in: see *EA-only orders: mailed by letter* below.

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

## EA-only orders: mailed by letter

Medications are drop-shipped by the supplier, Everything Aquatic (EA).
When a cart holds **only EA lines plus stickers**, nothing ships from
FisHotel, so the sticker goes out as a plain stamped First-Class letter
(`class-letter.php`).

**Which lines are EA** is the theme's own answer and nothing else:
`fishotel_is_ea_fulfilled_product( product id, variation id )`
(theme `inc/medication-store/helpers.php`) — the product's
`_fishotel_fulfillment` is `ea` (read on the parent product, as the
theme's *FisHotel Medication* tab saves it) and an EA SKU is set on the
product or on the variation. A variation is asked as the theme asks it:
the parent's id and the variation's. Without the theme's function
nothing counts as EA, so nothing below happens.

**EA-only** = every line that is not a picker sticker is EA, and there is
at least one (`Rules::is_ea_only()`). Anything FisHotel ships (a fish, a
"self" medication, dry goods, a sticker bought the normal way from the
shop) makes the cart mixed, and a mixed cart, a fish cart and a cart of
stickers only are left exactly as before: no fee, no message, no flag.

What an EA-only cart with a sticker gets (`Rules::mail_letter()`):

- **One fee of $1.00**, "Sticker mailing (sent separately by letter)",
  once per order, when it holds at least one **paid** sticker. The
  add-on lines keep their own price ($0.99 each), so the first paid
  sticker costs $1.99 in all and each more $0.99. The free sticker alone
  pays no fee (the shop pays that stamp); free + paid still pays one fee.
  The fee is taxed as the sticker product is (its tax status and tax
  class). It is worked out again on every totals pass
  (`woocommerce_cart_calculate_fees`): remove the last fish and the fee
  appears, add a fish and it goes; a paid sticker stepping back for the
  stock (`make_room()`, `guard_lines()`) takes the fee with it when no
  paid sticker is left.
- **A message**, word for word: "Heads up: your meds ship straight from
  our supplier, so your sticker gets mailed to you separately as a letter.
  You'll get an email with tracking once it's on its way. If you haven't
  gotten it in 2 weeks, just let us know and we'll send another one." —
  on the cart page under the item lines (`woocommerce_after_cart_contents`,
  inside the cart form, so WooCommerce's cart refresh redraws it) and on
  checkout as a row of the statement under the items
  (`woocommerce_review_order_after_cart_contents`, redrawn by every
  checkout refresh). Free or paid sticker alike. Plain small text with a
  thin gold rule, in the theme's colours (`css/sticker-front.css`).

When such an order is placed (`woocommerce_checkout_order_processed`;
the block checkout's `woocommerce_store_api_checkout_order_processed`
too), worked out from the order's own lines:

- order meta **`_fishotel_sticker_mail_letter`** = the number of stickers
  to mail (free + paid). Letter tracking in ShipTracker keys off it. A
  checkout tried again on the same order works it out again (a fish added
  meanwhile takes the meta away);
- a **private note**: "MAIL STICKER BY LETTER: 2 sticker(s) — EA-only
  order, nothing ships from FisHotel.";
- the message in the customer's **processing** and **on-hold** emails, and
  the note's line in the admin **new-order** email
  (`woocommerce_email_before_order_table`), for that order only;
- a **Sticker letter** column in the admin orders list, legacy and HPOS
  screens: "Mail sticker (2)" in WooCommerce's own yellow pill while the
  order is open, a quiet "By letter (2)" once it is completed (or
  cancelled, refunded, failed);
- on the **Today page and its widget**: "Stickers to mail by letter:
  #1234 (2), #1240 (1)" through Today's `fishotel_today_lines` filter,
  under Customers, until the order is completed (open = every status but
  completed, cancelled, refunded, failed, pending payment). One order
  links to that order, more to the orders list. At most 50 orders are
  looked at, oldest first; the line names as many as fit and says
  "and N more".

The order's fee line carries hidden meta `_fh_sticker_mail_fee`. On the
pay page of a failed order (`guard_order()`), when its sticker lines are
cut or taken off, the fee goes once no paid sticker is left and the
number to mail follows what is left (the meta goes with the last
sticker), before the order is totalled again.

Not done here, on purpose: the free-sticker rules are only read, EA
routing and fulfilment are the theme's, ShipTracker is not touched. The
picker itself is a classic-checkout card (as before); the theme uses the
classic cart and checkout. On a block cart or checkout the fee, the flag,
the emails, the list column and the Today line still work (the fee is a
normal cart fee the Store API shows); the message is not drawn there.

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
| `class-rules.php` | Eligibility, selection clamping, pricing; what a design is, how it is named, which variations are ticked after a save, when a design can be handed out, what fits in a count, the status and the email's when. Pure — no WordPress calls. |
| `class-designs.php` | A design id → product, name, count, stock; gathers the facts `Rules::blockers()` decides on. |
| `class-cart.php` | Sync the cart with the picker (diff-based, on `woocommerce_checkout_update_order_review` and `woocommerce_checkout_process`), pricing + quantity clamping on every totals pass, sticker lines giving way to the stock (in the cart, when a normal purchase is added, on the pay page of a failed order), row class, labels, thumbnail, order line meta, shipping package stripping. |
| `class-letter.php` | EA-only orders: the mailing fee, the customer's message, the order flag (meta, note), the email lines, the orders-list column, "Stickers to mail by letter" on the Today page. |
| `class-reorder.php` | The stock table's rows; the look at a stock change; the reorder email. |
| `class-today-line.php` | "Stickers to reorder" on the Today page. |
| `class-frontend.php` | Renders the picker on `woocommerce_checkout_after_customer_details` (inside `<form name="checkout">`), enqueues assets, optional cart-page AJAX sync. |
| `class-settings.php` | Option `fishotel_sticker_giveaway_settings`, defaults, sanitised AJAX save. |
| `class-admin.php` | Settings page under FisHotel Tools → Sticker Giveaway: product search, variation ticks, stock table, the two acts, warnings, category tree, live summary. |
| `views/checkout-picker.php` | The card. Only `fh-sticker-*` classes; styled by `css/sticker-front.css` with theme-token fallbacks. |
| `views/settings.php`, `views/variations.php`, `views/stock-table.php`, `views/warnings.php` | Settings page markup; the last three are the parts a save or a stock act redraws. |

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
  purchasable, an attribute on "Any", out of stock — and, per product,
  "sold individually", a product saved with no category, and on a theme
  that does not skip sticker lines yet a preset category or a short
  description.
- **Stickers in stock** — the stock table: one card per design (picture,
  name, status, *In stock*, *Reorder at*, *Add stickers* and *Recount*).
  Cards, not a table, so the page needs no sideways scrolling on a phone;
  what the number boxes do is said once above the cards.
  Below it *Email me when a design needs reordering*.
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
*Recount*, which change the product's stock in WooCommerce at once.

## Tests

```
php includes/sections/sticker-giveaway/tests/rules-test.php
php includes/sections/sticker-giveaway/tests/designs-test.php
php includes/sections/sticker-giveaway/tests/stock-test.php
php includes/sections/sticker-giveaway/tests/cart-stock-test.php
php includes/sections/sticker-giveaway/tests/reorder-test.php
php includes/sections/sticker-giveaway/tests/category-warning-test.php
php includes/sections/sticker-giveaway/tests/letter-test.php
```

- `rules-test` — category eligibility (including descendant slugs and the
  empty category list meaning "off"), the minimum-subtotal boundary, sticker
  lines being ignored, selection clamping to pool and max, and pricing.
- `designs-test` — the design of a cart line and its name, for a variation
  on a custom attribute, on a global one, and a simple product; "Any";
  which variations are ticked after a save (first pick, added later,
  unticked, deleted) and which the page calls new; the *Reorder at*
  numbers a save keeps.
- `stock-test` — when a design is offered (stock tracked, not tracked,
  backorders, the product managing stock for its variations), what fits
  beside the other lines of the same count and which line gives way, the
  status, and the email armed, sent and armed again.
- `cart-stock-test` — the cart class on stand-ins for WooCommerce's cart,
  products and orders: a variation design added as a variation line; the
  sticker line giving way on a totals pass, just before WooCommerce's own
  cart check (stock run out, a normal purchase of the same design, stock
  held for an unpaid order), when a normal purchase is added from the
  shop, and on the pay page of a failed order; which designs the picker
  offers beside what is in the cart.
- `reorder-test` — the sentences (what is left, the Today line, the email,
  the answers to a typed count, why a design is not offered, the preset
  warning), the stock table saying what its boxes do once, and a stock
  change played through stand-ins for WooCommerce's product and
  `wp_mail`: one email at the end of the request with the final number,
  one when two requests look at the same moment, none for a product that
  is not a design.
- `letter-test` — EA-only orders: the decisions (EA-only, mixed, sticker
  counts, the fee), which lines are EA (the theme's function as the theme
  has it, a variation by its parent), the cart's $0.99 lines and one
  $1.00 fee taxed as the sticker, the message, a fish removed and added
  again, the placed order's meta and note, the email lines, the orders-list
  column and the Today line. `cart-stock-test` section 7: the fee and the
  flag when a sticker steps back (`make_room()`, `guard_lines()`,
  `guard_order()`).

By-hand steps on a real site: `tests/README.md`.

None of these runs WordPress. The cases above were also played, with
invented products, on a throwaway WordPress with WooCommerce 11.2 and the
theme 1.26.0 — a real cart, a real order, a real stock change, the pay
page of a failed order, eight stock changes in the same instant — but
never on the live shop: watch the first real orders.
