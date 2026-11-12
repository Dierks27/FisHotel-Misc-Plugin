# Invoices

Builds a customer's invoice from a pick list instead of a hand-made
product. An invoice is what it always was — a hidden WooCommerce product
with stock 1 that the customer opens by link, adds to the cart and pays
at the ordinary checkout — but it is now built on one screen, checked
against everything the customer already has before it is published, and
remembered in the section's own tables so that every fish on it is known.

FisHotel Tools → **Invoices** (`manage_woocommerce`): Invoices · New
invoice · Forum list · Settings.

## The rules the code follows

1. **Read before you touch.** The pre-check is always shown before
   Publish and cannot be skipped: this customer's orders (PAID comes from
   the paid date / `is_paid()`, never from a status name), every invoice
   of theirs — built here or made by hand — and the product exactly as it
   will be written. Publish is refused without a pre-check of exactly the
   content being published; any change afterwards shuts Publish again.
2. **An invoice a customer already has is not changed.** The only edit of
   a published invoice is the deliberate *Edit this unpaid invoice*, and
   it is refused in code once the invoice is paid, once any live order
   contains it, when the orders cannot be read, or when the product is
   not the one this section made. More fish go on a NEW invoice
   (*Add fish for this customer*).
3. **No fish on two invoices.** A fish that already appears on another
   open or paid invoice of the same customer is flagged and has to be
   confirmed one by one.
4. **A sale price is never set and never cleared.**
5. **A stated price is the customer's price.** A flat total leaves a
   *Discount* line (or an *Adjustment* line when it is higher), so the
   lines always add up to it.
6. **Stock 1, backorders off**, on every invoice.
7. **A lost fish that was paid for** is a credit line on the new invoice.
8. **When someone passes**: Trash, never delete — and only when no order
   of any status contains the invoice. Refused when the site empties its
   Trash at once (`EMPTY_TRASH_DAYS` 0).

No e-mail is sent, no money is moved and no stock is changed by this
section. Shop stock goes down only through WooCommerce, at payment, and
only for the lines ticked *take from shop stock when paid* (the theme
reads the product id of those lines from the invoice).

## What Publish writes

A simple product, published, hidden from the catalog, in the Invoices
category only:

| | |
|---|---|
| Title | `<Handle> Invoice (n/j/y)` (site date) |
| Short description | `<Handle> Only` |
| Description | a `<ul>`: `Name x2 — $45`, `Credit: … — -$20`, a note, `Discount — -$14` |
| Regular price | the total; no sale price |
| Stock | managed, 1, backorders off |
| Shipping class | Premium, Free or another class of the store |
| Image | by the threshold in Settings (none when not chosen) |
| `_fh_invoice_lines` | the JSON list the theme itemises an order from: `name`, `qty`, `price`, `kind` (`fish` / `credit` / `note`) and `product_id` only on the ticked lines |
| `_fh_invoice_id` | this section's invoice id (how it knows its own products) |

The product is read back after it is written; one that does not match is
taken offline again and the publish is refused.

## Data

`{prefix}fh_invoices` and `{prefix}fh_invoice_lines`, made by dbDelta
behind the autoloaded option `fishotel_invoices_db_version` (written only
once both tables and every column exist; `fishotel_invoices_db_retry`
spaces the retries of a failed install). `class-store.php` is the only
file that talks to the database. Each line keeps the ids it came from:
shop product and variation, species-list entry, tank group (Clean Room
stay), its size, its source (`tank` / `product` / `species` / `typed`)
and whether it takes from shop stock.

States: `draft`, `published`, `paid`, `trashed`, `cancelled` (a withdrawn
draft, or an invoice whose product is gone).

A front-end request costs no query from this section. The two order
hooks return before any query unless the order is paid, cancelled or
failed, and then run one indexed query.

## What other code can rely on

Functions (global; they exist while the Misc plugin is active and answer
an empty array while the section is off — guard with `function_exists()`):

- `fishotel_invoices_lines_for_product( int $product_id ): array` — the
  lines of an invoice product, each with `id`, `invoice_id`, `kind`,
  `name`, `qty`, `unit_price`, `amount`, `product_id`, `variation_id`,
  `species_id`, `stay_id`, `size_slug`, `size_label`, `source`,
  `take_stock`, `sort`. For a hand-made invoice: the lines as the theme
  reads them, `source` = `hand`, no ids.
- `fishotel_invoices_for_order( int $order_id ): array` — the invoices in
  an order (`id`, `product_id`, `made_by_hand`, `handle`,
  `forum_user_id`, `customer_id`, `customer_name`, `customer_email`,
  `state`, `title`, `total`, `flat_total`, `shipping`, `shipping_slug`,
  `order_id`, `url`, `lines`).

Actions:

- `do_action( 'fishotel_invoice_paid', $invoice_id, $order_id )` — once,
  when an order containing the invoice is paid.
- `do_action( 'fishotel_invoice_released', $invoice_id, $order_id )` —
  when that order is cancelled or fails and the invoice is open again.

Filter:

- `fishotel_invoices_search_groups` ( `$groups`, `$term`, `$invoice_id` )
  — the grouped answer of the picker's search.

Meta keys: product `_fh_invoice_id` (this section's), `_fh_invoice_lines`
and `_fh_invoice_order_id` (the theme's, the second only read); order
item `_fh_invoice_product_id`, `_fh_invoice_title`, `_fh_invoice_fish`,
`_fh_invoice_credit`, `_fh_invoice_lines` (the theme's, only read);
order `_fishotel_is_fulfillment` (only read: a combined-box copy pays
for nothing).

Options: `fishotel_invoices_settings` (not autoloaded),
`fishotel_invoices_db_version`, `fishotel_invoices_db_retry`.

AJAX (`wp_ajax_fishotel_invoices_<op>`, capability + nonce
`fishotel_invoices_admin`): `search`, `handles`, `customers`, `save`,
`precheck`, `publish`, `update`, `trash`, `discard`, `forum`, `settings`.
There is no REST route.

Health log: `do_action( 'fishotel_log', level, 'invoices', message, context )`.

## What it reaches, and what happens without it

- **WooCommerce** — required. Without it the page says so and nothing
  else runs.
- **Clean Room section** — the "In the tanks now" group of the search
  and the species list. Reached through `class_exists()` /
  `method_exists()` only (`class-tanks.php`); when it is absent, older or
  off, the search says the group is unavailable and the rest works.
- **The theme's invoice module** — itemises a paid order into one line
  per fish and locks the product. Without it the invoice is still one
  order line and the paid sync still works from the order itself.
- **Health section** — optional; the log action is simply not heard.

## Settings

Product category (slug), title and short-description formats, the
shipping classes meant by Premium and Free, the two featured images and
the threshold between them. Defaults live in code (category `invoices`,
classes `premium` / `free`, threshold 400, no image); nothing is written
to the database until Settings is saved.

## Tests

Plain PHP with stubs, each file standalone, non-zero exit on failure
(a PHP notice counts as a failure):

```
for f in includes/sections/invoices/tests/*-test.php; do php "$f" || break; done
node includes/sections/invoices/tests/invoices-admin.test.js
```

`lines` (amounts and sums), `grammar` (the description as the theme
reads it), `format`, `rules`, `store`, `settings`, `publish`,
`edit-trash`, `trash-off`, `precheck`, `sync`, `search`, `tanks`,
`contracts`, `admin` — 947 checks — and `invoices-admin.test.js` — 102
checks: the picker's sums against the same
`tests/fixtures/invoice-maths.json` the server test reads. The real
`Shop` class runs on in-memory WooCommerce stubs (`wc-stubs.php`); the
tables are an in-memory twin (`fake-store.php`).
