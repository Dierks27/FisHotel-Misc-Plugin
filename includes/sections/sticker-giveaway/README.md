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
  when *Offer add-ons to everyone* is on.

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

The free line is priced at $0 and locked to quantity 1 on every
`woocommerce_before_calculate_totals`; add-on lines are priced at the
configured add-on price. If the cart stops qualifying (the fish is removed),
the free line is removed on the next totals pass. On the cart page sticker
quantities are plain text (the remove link still works; removing the free
sticker there is remembered as "no sticker").

## Shipping

Stickers ride in the fish box. `woocommerce_cart_shipping_packages` drops
sticker lines from every package that still contains a non-sticker line and
recomputes `contents_cost`, so the theme's table-rate result (keyed on
shipping class) is identical with or without stickers. A package made only of
stickers (add-ons bought on their own) is left alone and ships normally.

Set each sticker product's shipping class to the same class as the fish
(Premium) or leave it blank — either way the picker keeps them out of the
calculation.

## Eligibility

`Rules::is_eligible()` — pure, unit-tested:

1. Sticker lines never count.
2. At least one remaining line must be in a ticked category *or any of its
   descendants* (the Cart class resolves ancestry with `get_ancestors` and
   passes slugs in). No categories ticked = every order qualifies.
3. The remaining subtotal must reach *Minimum subtotal* (0 = none), compared
   at cent precision.

## Files

| File | Responsibility |
| --- | --- |
| `class-rules.php` | Eligibility, selection clamping, pricing. Pure — no WordPress calls. |
| `class-cart.php` | Sync the cart with the picker (diff-based, on `woocommerce_checkout_update_order_review` and `woocommerce_checkout_process`), pricing, quantity lock, labels, order line meta, shipping package stripping. |
| `class-frontend.php` | Renders the picker on `woocommerce_checkout_after_customer_details` (inside `<form name="checkout">`), enqueues assets, optional cart-page AJAX sync. |
| `class-settings.php` | Option `fishotel_sticker_giveaway_settings`, defaults, sanitised AJAX save. |
| `class-admin.php` | Settings page under FisHotel Tools → Sticker Giveaway (product search, category tree, live summary). |
| `views/checkout-picker.php` | The card. Only `fh-sticker-*` classes; styled by `css/sticker-front.css` with theme-token fallbacks. |
| `views/settings.php` | Settings page markup. |

## Settings

FisHotel Tools → Sticker Giveaway. Needs `manage_woocommerce`.

- **Sticker products** — WooCommerce product search, simple products only.
  Give each a regular price (any amount) so WooCommerce treats it as
  purchasable; the picker overrides it.
- **Eligible categories** — product_cat checkbox tree, default
  `quarantined-fish`.
- **Minimum subtotal** — optional, sticker lines excluded.
- **Add-ons** — enable, price, max per design (hard ceiling 50), offer to
  everyone.
- **Wording** — headings, intros (`{price}` in the add-on intro), badge,
  "no sticker" label. Blank restores the default.
- **Also show on cart** — off by default. When on, the card renders on the
  cart page too and syncs through a small AJAX action.

## Tests

```
php includes/sections/sticker-giveaway/tests/rules-test.php
```

Covers category eligibility (including descendant slugs and empty category
list), the minimum-subtotal boundary, sticker lines being ignored, selection
clamping to pool and max, and pricing.
