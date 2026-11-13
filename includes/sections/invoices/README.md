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
6. **Stock 1, backorders off**, on every invoice — and the pre-check
   refuses to publish while WooCommerce's own "Enable stock management"
   is switched off, because "stock 1" is then stored but not enforced
   and the same invoice could be paid twice.
7. **A lost fish that was paid for** is a credit line on the new invoice.
8. **When someone passes**: Trash, never delete — and only when no order
   of any status contains the invoice. Refused when the site empties its
   Trash at once (`EMPTY_TRASH_DAYS` 0).

Three more things the code holds to:

- **One writer per invoice.** Every write of an existing invoice (save,
  publish, edit, Trash, withdraw) holds that invoice's lock on the
  database server and reads the row inside it. Several Publish requests
  sent at the same moment — a double click, two windows — make ONE
  product; the others are told the invoice is busy.
- **A window that is behind cannot undo a newer save.** A form names the
  version it was opened with; when the invoice has moved on, the save —
  and, for a published invoice, already the pre-check — is refused, and
  stays refused on every further click until the page is reloaded. A
  refusal never moves the version on.
- **A copy is not the invoice.** WooCommerce's "Duplicate" of an invoice
  built here leaves out this section's mark and the lines for the theme;
  the copy is an invoice made by hand, listed and pre-checked as one.

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

Names are kept as typed. Real HTML tags are taken out, a bare `<` (a
size written `<2"`) stays; in the description and in the JSON a name goes
out with `&`, `<` and `>` as entities, which is what the theme decodes
back to the exact name. A shop product's title is taken as the text it
stands for (`Okapi & Stripe`, not the stored `Okapi &amp; Stripe`).

The total has a ceiling: lines that add up to more than the columns hold
($9,999,999,999.99) are an error in the pre-check.

## Who can find an invoice

An invoice is opened by its link. "Hidden" keeps a product out of the
shop, the category page and the site search, but WordPress and
WooCommerce still listed it — handle, fish, price and link — for any
logged-out visitor in the product sitemap, the REST lists of products
(`wp/v2/product`, the Store API's `wc/store/…/products`) and the REST
search. `class-visibility.php` takes the products of the Invoices
category (the slug in Settings, and `invoices`) out of those four lists
and marks an invoice's own page `noindex`. Invoices made by hand are
covered the same way; no product is changed. Someone who may manage the
shop still sees everything.

Not covered, on purpose: the invoice's own page and a product asked for
by its id still answer — the link is how the customer opens it. A
sitemap built by an SEO plugin is that plugin's to configure.

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
failed, and then run one indexed query plus one read of the row right
before it is written — so the paid action fires once per payment even
when a screen still holds the row from before. The visibility hooks ask
nothing until a sitemap or a REST product list is built; the `noindex`
is decided from what WordPress already loaded for the page.

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

- `do_action( 'fishotel_invoice_paid', $invoice_id, $order_id )` — once
  per payment, when an order containing the invoice is paid (again when
  the same order is paid again after it failed or was cancelled).
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

Hooks of WordPress and WooCommerce it listens to, besides the two order
hooks: `wp_sitemaps_posts_query_args`, `rest_product_query`,
`rest_post_search_query`, `pre_get_posts` (Store API product list only),
`wp_robots`, `woocommerce_duplicate_product_exclude_meta`.

Options: `fishotel_invoices_settings` (not autoloaded),
`fishotel_invoices_db_version`, `fishotel_invoices_db_retry`.

AJAX (`wp_ajax_fishotel_invoices_<op>`, capability + nonce
`fishotel_invoices_admin`): `search`, `handles`, `customers`, `save`,
`precheck`, `publish`, `update`, `trash`, `discard`, `forum`, `settings`.
There is no REST route. A refusal carries a `code`: `conflict` (HTTP 409:
the page is behind), `busy` (another request holds the invoice),
`precheck`, `errors`, `confirm`, `locked`, `state`, `missing`, `input`,
`shop`, `verify`, `db`, `orphan`.

The forum list and the list of invoices show what the customer is
CHARGED — the product's price. When that is no longer the invoice's
total (the price was changed on the product by hand, or it carries a
sale price), the forum line shows the product's price with a note, and
the list flags the row.

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
`contracts`, `admin`, `visibility` — 1,063 checks — and
`invoices-admin.test.js` — 114 checks: the picker's sums against the same
`tests/fixtures/invoice-maths.json` the server test reads. The real
`Shop` class runs on in-memory WooCommerce stubs (`wc-stubs.php`); the
tables are an in-memory twin (`fake-store.php`).
