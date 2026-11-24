# Sticker Giveaway — tests

Headless (no WordPress, no database), from the plugin root:

```
php includes/sections/sticker-giveaway/tests/rules-test.php
php includes/sections/sticker-giveaway/tests/designs-test.php
php includes/sections/sticker-giveaway/tests/stock-test.php
php includes/sections/sticker-giveaway/tests/cart-stock-test.php
php includes/sections/sticker-giveaway/tests/reorder-test.php
php includes/sections/sticker-giveaway/tests/category-warning-test.php
php includes/sections/sticker-giveaway/tests/letter-test.php
```

What each covers is in the section README (*Tests*). `wp-stubs.php` is
the shared minimum (constants, `__()`, options a test sets by hand); each
test brings its own stand-ins for WooCommerce. Every product, design,
customer and order number is invented.

## By hand on a real site: EA-only orders mailed by letter

On a test copy of the shop, never the live one. Needs the FisHotel theme
(its `fishotel_is_ea_fulfilled_product()` decides what is EA) and the
Sticker Giveaway section on, with a sticker design in the pool and
add-ons on at $0.99.

1. **EA product.** Open a medication → *FisHotel Medication* tab →
   *Fulfillment mode* = EA, with an EA SKU on the product or on the
   variation you will buy. (A variation without an SKU of its own, on a
   product without one, is not EA — the theme says so.)
2. **EA-only + 1 paid.** Cart: the medication only. Checkout: add one
   sticker add-on. The statement shows the sticker at $0.99, a line
   "Sticker mailing (sent separately by letter)" $1.00, and under the
   items the "Heads up: your meds ship straight from our supplier…"
   message. Raise the add-ons to 3: $2.97 and still one $1.00 fee.
3. **Tax.** With taxes on, the fee is taxed in the sticker product's tax
   class (or not at all when the sticker is "None").
4. **Live.** Add a fish from the shop: on the cart page the fee and the
   message are gone. Remove the fish with *Remove*: both are back, without
   a page reload.
5. **Free sticker only.** (Only when a medication category is ticked for
   the free sticker.) Free sticker, no add-on: no fee, the message shows.
   Free + 1 add-on: one fee.
6. **Mixed / fish only.** A fish and a medication with add-ons, or a fish
   with add-ons: plain $0.99 lines, no fee, no message.
7. **Place the EA-only order** (check payment → on hold; or a card →
   processing). The order has meta `_fishotel_sticker_mail_letter` = the
   stickers to mail, the private note "MAIL STICKER BY LETTER: N
   sticker(s) — EA-only order, nothing ships from FisHotel.", the fee line
   with its tax. The customer's on-hold / processing email carries the
   message; the admin "New order" email carries the note's line. A mixed
   order gets none of these.
8. **Orders list.** WooCommerce → Orders (legacy and HPOS screens): the
   *Sticker letter* column shows "Mail sticker (N)"; once the order is
   completed, "By letter (N)".
9. **Today.** FisHotel Tools → Today and the dashboard widget: "Stickers to
   mail by letter: #1234 (2)"; the order leaves the line when it is
   completed.
10. **Joining a fish order.** As a customer with an open, unshipped
    fish order, check out a medication + 1 add-on to the same address:
    the checkout says "Shipping combined …", shipping $0, and there is
    **no** mailing fee and **no** "Heads up" message; the placed order
    has no `_fishotel_sticker_mail_letter` and no "MAIL STICKER BY
    LETTER" note. With an open EA-only order instead, it stays a letter.
11. **Refund.** On a flagged EA-only order with 1 paid sticker, refund
    just the sticker line (*Refund* → quantity 1): the meta goes down by
    one, a note gives the new number, and a note says to refund the
    "Sticker mailing (sent separately by letter)" fee too.
12. **Pay page.** A failed EA-only order with free + paid stickers, the
    paid design's stock set to 0, opened from *Customer payment page*:
    the paid line and the fee go, the total drops, and a fresh note
    gives the new number to mail.
13. **Prices with tax / add-on at 0.** With *Prices entered with tax* =
    yes, the fee line plus its tax is $1.00. With the add-on price set to
    0: no fee, but the message and the flag stay.
14. **Logs.** Nothing from the section in `debug.log`.

Played on a throwaway copy of the rehearsal site (WordPress 7.1.4,
WooCommerce 11.2.0, theme 1.27.0), HPOS off and on, with invented
products and orders.
