# Sticker Giveaway

A free FisHotel sticker with every fish order, chosen by the customer at
checkout, plus an optional "want more?" add-on at a small price.

## What the customer sees

On checkout, between the delivery details and the payment method, a card:

- **PICK YOUR FREE STICKER** — one radio card per sticker in the pool
  (thumbnail, name, "FREE WITH YOUR FISH ORDER" badge) and a *No sticker,
  thanks* option. The first sticker is pre-selected, so a customer who never
  looks at the card still gets one. This block only appears when the cart
  qualifies.
- **WANT MORE?** — a quantity stepper per sticker at the add-on price
  (default $1.00, max 5 each). Shown to qualifying carts, and to everyone
  when *Offer add-ons to everyone* is on. A cart that gets no free sticker
  (meds only) sees the *add-ons only* heading and intro instead
  ("ADD A FISHOTEL STICKER — … free with any fish order"), since "want
  more?" would presuppose a free one.

Changing anything triggers WooCommerce's own `update_checkout`; the server
adds or swaps the sticker lines and the itemized statement refreshes.

## Why stickers are real line items

The sticker is a normal simple product added to the cart with cart item data
`fh_sticker = free|extra`. That means:

- The admin order screen, every customer email and the PDF invoice show
  "Sticker: Free with fish order" (or "Add-on") with no further integration
  — the person packing the box sees which design to drop in.
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

Adding a line goes through `WC_Cart::add_to_cart()` only after checking the
product's stock against what the cart already holds for it (the free line, an
add-on of the same design and a normal purchase are three lines of one
product); any error notice WooCommerce still raises is discarded, so a sticker
can never block Place Order — the line is simply not added.

### On the theme's cart page

The theme's `woocommerce/cart/cart.php` draws its own quantity stepper and
never applies `woocommerce_cart_item_quantity`, so the plugin's plain-text
quantity only shows in stock WooCommerce templates. Instead:

- `woocommerce_cart_item_class` adds `fh-sticker-line fh-sticker-line--free|extra`
  to the row; the stylesheet hides the theme stepper on the free row (always 1).
  Add-on rows keep the stepper and the server clamps whatever comes back.
- `woocommerce_cart_item_name` appends a small "Free sticker" / "Add-on" pill,
  because neither the theme's cart nor its checkout statement prints
  `woocommerce_get_item_data` (stock templates show both the pill and the
  item data row).
- Removing the free sticker with the theme's Remove link is remembered as
  "no sticker" for the session, so checkout does not quietly put it back.
- `css/sticker-front.css` is enqueued on the cart page regardless of *Also
  show on cart* so the FREE label and pills are styled there.

## Shipping

Stickers ride in the fish box. `woocommerce_cart_shipping_packages` drops
sticker lines from every package that still contains a non-sticker line and
recomputes `contents_cost`, so the theme's table-rate result (keyed on
shipping class) is identical with or without stickers. A package made only of
stickers (add-ons bought on their own) is left alone and ships normally.

**Leave each sticker product's shipping class blank** (or use a ground class).
Do not give stickers the Premium/overnight class: the theme's free-shipping
piggyback (`FisHotel_Checkout_Reuse::cart_requires_overnight()`) reads the
shipping class off every `WC()->cart` line, not the stripped package, so a
Premium sticker would make a meds-only re-order read as an overnight box and
lose its free piggyback. The picker keeps sticker lines out of the shipping
calculation regardless of class.

## Theme coordination (for the lead)

Two theme functions walk every cart line and will see sticker lines; the
plugin cannot filter them from outside. Both want a one-line skip:
`if ( ! empty( $cart_item['fh_sticker'] ) ) continue;`

- `inc/cart-presets.php` → `fishotel_cart_resolve_preset()`: a sticker product
  in a preset-mapped category (merchandise / apparel / swag → `merch`) makes a
  fish order resolve to the `default` preset copy. Until the theme skips
  sticker lines, keep sticker products in a category the theme does not map
  (e.g. `stickers`); the admin page warns when a pool product sits in a mapped
  category.
- `inc/order-fulfillment/class-fishotel-checkout-reuse.php` →
  `cart_requires_overnight()`: see Shipping above.
- Cosmetic: the theme's ledger ("Subtotal · N fish") and cart hero count
  `get_cart_contents_count()`, which now includes sticker lines. The plugin
  does not filter `woocommerce_cart_contents_count` because WooCommerce uses
  it for its own limits; the theme can subtract lines carrying `fh_sticker`.
- The theme's cart.php prints a line's short description as its italic
  scientific name, so sticker products should have an empty short
  description (the admin page warns about this too).

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
| `class-rules.php` | Eligibility, selection clamping, pricing. Pure — no WordPress calls. |
| `class-cart.php` | Sync the cart with the picker (diff-based, on `woocommerce_checkout_update_order_review` and `woocommerce_checkout_process`), pricing + quantity clamping on every totals pass, row class and name pill, labels, order line meta, shipping package stripping. |
| `class-frontend.php` | Renders the picker on `woocommerce_checkout_after_customer_details` (inside `<form name="checkout">`), enqueues assets, optional cart-page AJAX sync. |
| `class-settings.php` | Option `fishotel_sticker_giveaway_settings`, defaults, sanitised AJAX save. |
| `class-admin.php` | Settings page under FisHotel Tools → Sticker Giveaway (product search, category tree, live summary). |
| `views/checkout-picker.php` | The card. Only `fh-sticker-*` classes; styled by `css/sticker-front.css` with theme-token fallbacks. |
| `views/settings.php` | Settings page markup. |

## Settings

FisHotel Tools → Sticker Giveaway. Needs `manage_woocommerce`.

- **Sticker products** — WooCommerce product search, simple products only.
  Give each a regular price (any amount) so WooCommerce treats it as
  purchasable; the picker overrides it. Anything the save drops (a
  variation, a deleted product, an unknown slug) is named in the "Saved"
  notice and removed from the form, so what you see is what is stored.
  Warnings under the field flag unpublished / unpriced / out-of-stock /
  sold-individually products, products in a theme preset category, and
  products with a short description.
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

## Tests

```
php includes/sections/sticker-giveaway/tests/rules-test.php
```

Covers category eligibility (including descendant slugs and the empty
category list meaning "off"), the minimum-subtotal boundary, sticker lines
being ignored, selection clamping to pool and max (including a max of 0),
and pricing.
