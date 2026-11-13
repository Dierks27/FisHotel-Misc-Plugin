# Lost Fish

What happens when a fish somebody already paid for dies in our care.

A loss used to be a tap on the phone and nothing else; whoever had paid
for that fish found out when the box arrived without it. Now:

1. A loss is logged on a tank group (Clean Room).
2. The section checks whether the fish of that kind still alive cover
   everything paid customers are owed. If they do, the customer's fish is
   simply replaced from the extra — one line in this section's log,
   nobody is written to.
3. If they do not, a **case** opens for each customer who is now short:
   the fish, the order, the customer, what was paid, and the numbers for
   every choice.
4. The owner looks at the case and its e-mail preview and clicks **Send
   to customer**. Nothing is sent without that click.
5. The customer picks — refund the lost fish · take a credit · try again
   · cancel the whole order — on a small page behind a signed link, and
   presses Confirm.
6. The case then says exactly what the owner has to do, with the amounts
   and the links. The owner does it and ticks **Done**.

**The section never moves money, never changes stock and never cancels
or refunds an order.** The only things it writes outside its own two
tables are private notes on the WooCommerce order and — for a "try
again" — a request in the Requests section when that section is there.

Screen: **FisHotel Tools → Lost Fish** (`manage_woocommerce`).

---

## When a loss opens a case

The Clean Room fires `fishotel_cleanroom_event_recorded` after every
event it records. This section listens for a `loss`.

**The kind of fish** is the tank group's product when it has one — every
group of that product is the same kind, whatever its size. Without a
product it is the species and the size together.

**COVERED** = the fish of that kind still alive across every open tank
group (in the Spa or cleared; a closed group and a group that still has
to be identified count for nothing).

**COMMITTED** = the fish of that kind owed to customers. An order line
counts when

- the order is **paid** — it has a paid date, or WooCommerce's
  `is_paid()` says so. Never the status name: a paid order under a custom
  status counts, an unpaid "processing" one does not;
- the order is not completed, shipped, cancelled, refunded or failed
  (`fishotel_lostfish_closed_statuses`), and is not a combined-box
  wrapper (its lines are copies; the source orders are read);
- the line has **not shipped**: no ShipTracker shipment names it
  (`FST_Shipment::get_by_order()` and its `line_item_ids`; a shipment
  without a list covers nothing, as ShipTracker itself counts), and the
  theme's line status is not `shipped` / `na`;
- the line **is that fish**:
  - its product or variation is the tank group's product; or
  - the Invoices section links it to that product, that species and
    size, or that very tank group; or
  - it is a fish line out of an invoice that names no product, and its
    **name** is the group's species or product title (with or without the
    size). A line found this way is marked **check** and is never sent
    by itself.

Each line counts for its quantity, minus what a refund already took off
it or an answered case released. **An order whose customer chose "cancel
the whole order" owes nothing from that answer on** — also while the
owner has not cancelled it in WooCommerce yet — so a further loss never
opens a second case on it.

**Every open order is looked at.** Their ids are read a page at a time
(400), and one read of the order-item tables then says which of them
hold the fish — so hundreds of newer unpaid orders cannot hide a paid
holder. Only when that read cannot say are the orders loaded one by one,
the newest 400 at most; if any were left unread (that, or more than
20,000 open orders) the log says so in plain words: *the oldest were NOT
checked*.

**COVERED ≥ COMMITTED** → nothing is sent. The log says, for example:
*Loss of 1 × Lagoon Goby (Medium) (tank 521): 4 still alive for 4 owed to
customers — replaced from the extra, nobody is written to.*

**Otherwise** the shortage — minus the fish that open cases already speak
for — is handed to the holders, **the most recently paid order first**
(first paid, first served). Each holder line that is short gets **one
case**, for as many fish as that line is short.

**One more of the same fish** for a line whose case was **not sent yet**
does not open a second case: that case **grows** (1 × becomes 2 ×), its
numbers are worked out again, and the order gets a note — so tapping
"loss" three times is one case and one e-mail, not three. A shipping
difference that was typed in for the smaller number is worked out again
and the case is marked "check". A case that was already sent is never
changed behind the customer's back, and a case made by hand or reopened
by the owner is the owner's: in all three the further loss opens a new
case.

A case on an order that is **due to ship today or earlier** is marked
"check": its box may already be packed — the fish marked as shipped in
the Clean Room — before the shipment is recorded.

What never opens a case: a `sold`, `count` (recount) or `move` event; a
loss that came in with a workbook import (history, not news); a group
whose fish nobody has identified yet; a loss nobody is owed (no log line
either — most losses are these).

A recount that comes up short is not a `loss` event, so it opens nothing
by itself: use **Open a case** for it.

### Undo

Undoing the loss in the Clean Room (the phone's undo within its 15
minutes, or Undo on the Events tab) **closes the cases that loss opened
and that were not sent yet** — by itself, with a note on the order. A
case that grew from several losses remembers each of them with its
number of fish: undoing one takes just those fish off it (2 × becomes
1 ×), and the case closes when its last loss is undone. A case that was
already sent or answered is never closed or changed behind the
customer's back: it is flagged "the loss was undone after the e-mail went
out" (with how many of its fish) for the owner to sort out.

**Whichever loss is undone, the kind is worked out again.** Two alive,
one owed: the first loss is covered, the second opens a case; undoing the
*first* puts a fish back, the tanks cover the customer again — and the
unsent case the second loss opened closes by itself (or speaks for fewer
fish), newest case first. Cases that were sent, made by hand or reopened
by the owner are never touched this way.

### Two phones at once

A loss is worked out under a database lock per kind of fish, so two
losses of the same fish in the same second cannot both open a case for
the same order line.

---

## A case

A case names the fish and its quantity, the order line, the customer
(name, e-mail), what was paid for one fish (the line's total and tax ÷ its
quantity), and how the order line was found (product / variation /
Invoices section / name / by hand).

| Status | Means | Where on the screen |
| --- | --- | --- |
| `open` | not sent yet | Needs you |
| `sent` | the e-mail is with the customer | Waiting — or Needs you once the reminder went out (or the links ran out) without an answer |
| `answered` | the customer chose; the owner has something to do | Needs you |
| `done` | the owner ticked Done | Finished |
| `closed` | closed without an answer (loss undone, a mistake, settled directly, the whole order cancelled) | Finished |

What the owner can do:

- **Send to customer** — the one button that sends an e-mail (below).
- **Save numbers** — what was paid for one fish, and the shipping
  difference (below).
- **Give to another customer** — an unsent case can be moved to another
  holder of the same fish; both orders get a note.
- **Record the answer by hand** — the customer phoned or wrote back
  instead of using a link. No e-mail is sent.
- **Done**, with an optional note.
- **Reopen** — any case that is not open: the answer is forgotten, the
  links of the earlier e-mail stop working, the numbers are worked out
  afresh. Nothing is sent. A case reopened **after an answer** is marked
  "check" and says what the customer had chosen, for how much, and
  whether it was ticked Done — and when its order line was refunded in
  the meantime, Send is blocked: the same money is not offered twice.
- **Close** — with a reason. "Settled with the customer directly" counts
  the fish as no longer owed, like an answer; "not a real loss" does not.
- **Open a case** by hand — from the *Open a case* tab (type an order
  number, pick the line and how many), or from the link under each line
  on the WooCommerce order screen (*Lost this fish? Open a case…*). A
  case opened by hand is never sent by itself.

**A page that is behind its case does nothing.** Every form of a case
carries what the page showed of it (order line, fish, numbers, customer).
Send, Save numbers, Record the answer and Move the case are refused when
the case no longer reads like that — it was changed in another tab, or a
further loss joined it — with "this case changed since the page was
loaded".

A private order note is written at each stage — case opened, e-mail
sent, the reminder, the customer's choice, done, reopened, closed — and a
line in this section's log (the **Log** tab), which also goes to the
Health log through `fishotel_log`.

---

## The money

Every amount is whole cents.

| Choice | What it comes to |
| --- | --- |
| Refund the lost fish | what was paid for the fish **minus the shipping difference** |
| Take a credit | the same amount — a credit does not keep the cheaper shipping |
| Try again | nothing moves; this order's shipping stays as it is |
| Cancel the whole order | everything paid on the order that was not refunded yet, **less what earlier cases of the order already gave back as store credit** (offered only while nothing of the order has shipped) |

A refund is never negative. When the shipping difference is more than
the fish, the refund is $0.00 and the e-mail says why; nothing is ever
collected from a customer here.

The refund / credit amount is frozen when the e-mail goes out. The
**cancel** amount is frozen as a ceiling: until the owner ticks Done, the
customer's page and the to-do show **what is left to give back now** —
never more than the e-mail said, less any refund made on the order and
any store credit given through another case since. When something
already went back, the e-mail, the page and the to-do say so instead of
"everything you paid".

### The shipping difference

*If taking the fish out drops the order under the reduced-shipping
threshold, the customer pays the normal shipping after all.* The
difference is what the order's shipping would have cost without the fish
minus what was charged.

**No rate and no threshold is written in this code.** The figure comes
from WooCommerce's own shipping settings:

1. The order's lines are put back into the shipping package the checkout
   priced — products, quantities, what was paid, going to the order's
   address. The fish lines of an itemised invoice go back to being that
   one invoice product, with its shipping class.
2. The shipping zone of that address is asked for the method the order
   used (its method **and** instance id), and that method prices the
   package **as it was placed**.
3. Only if that gives what the order was really charged, to the cent, is
   the method asked to price the package **without the fish**.

The answer is one of:

| | |
| --- | --- |
| **none** | worked out: the order stays over the threshold (or nothing else is left to ship) |
| **a figure** | worked out: e.g. *Without this fish the order's shipping is $X instead of the $Y that was charged* |
| **free on purpose** | nothing was charged and every line that ships is in a "free" shipping class (Settings — default `free`), or the order rides in another order's box (`_fishotel_piggyback_of`): no difference |
| **not known** | it cannot be worked out with confidence. **Nothing is guessed.** The case shows why, the *Shipping difference* field must be filled in — or confirmed as `0` — and until then **Send to customer is disabled** (and refused by the server) |

"Not known" happens when: the settings do not give what was charged for
the order as it was placed (rates changed since; a manual price; a
coupon); the order's method is no longer in its zone; a product of the
order is gone; the method needs a cart to price (some do); the order has
several shipping lines or none; **another lost-fish case on the same
order still waits for an answer** — the difference then depends on what
the customer picks there, and the figure for this fish alone is shown as
a hint.

**Two cases on one order never withhold the difference twice:**

- When ONE other case waits and the order's shipping stays the same with
  this fish out alone *and* with both out, no answer can change it:
  there is no difference, nothing to type, and both can be sent at once.
- Otherwise the figure has to be typed — and a case whose figure is
  **typed, or still to be typed,** cannot be sent (or answered by hand)
  while another case of the order is **with the customer**: both typed
  figures would be withheld. It waits for that answer.
- When that answer comes, a figure typed on the waiting case is dropped
  (the case is marked "check") and worked out on what is left of the
  order and on what was already withheld.

The owner can always type a figure over a worked-out one (*Save
numbers*); emptying the field has it worked out again.

While the method prices, WooCommerce's session is a throw-away in-memory
one and its cart is hidden, so the method can only price the package it
is handed — never the cart of whoever is logged in.

---

## The e-mail

One e-mail per case (`wp_mail()`, from the store's own sender), plus one
reminder. It says:

- **which fish was lost**;
- **when the rest of the order ships** — the delivery date the checkout
  stored on the order (`_fishotel_shipping_date`, through the theme's
  `fishotel_get_effective_delivery_date()` when it exists, so an order
  folded into a combined box takes the box's date) and the ship date,
  the day before it: *it ships on Tuesday, November 17, 2026, for
  delivery on Wednesday, November 18, 2026.* Without a date on the order
  — **or once that ship date has gone by**, as it may have when the
  reminder goes out — *We will confirm your ship date with you.* Nothing
  is made up, and a day that is over is never printed as "it ships on"
  (`{ship_date}` and `{delivery_date}` are then empty too);
- **the four choices as links**, each with its money beside it and — when
  there is a shipping difference — the sentence that makes it plain:
  *Without this fish your order is no longer over the amount for reduced
  shipping, so its shipping is $X instead of the $Y you paid.*

**Settings → The e-mail** holds the template: plain text with
placeholders (`{customer_name}`, `{fish}`, `{qty}`, `{fish_line}`,
`{order_number}`, `{ship_line}`, `{ship_date}`, `{delivery_date}`,
`{choices}`, `{site_name}`); a blank line starts a paragraph; every value
is escaped. `{choices}` is the block of links — added at the end when a
template leaves it out. An empty field means the built-in text. The
Settings tab shows the e-mail with invented data; each open case shows
its own, exactly as it will go out, in a frame that can run nothing and
whose links go nowhere.

### Nothing is sent without the click

- An e-mail to a customer starts in exactly one place, `Cases::send()`,
  which only the **Send to customer** button calls.
- The form carries the refund the page showed. If that is no longer the
  refund (the order changed since the page was loaded), nothing is sent
  and the owner is asked to look again.
- A second click sends nothing: the case's status moves first, in one
  conditional UPDATE.
- A page that is behind its case sends nothing (see *A case*).
- Send is disabled, and refused by the server, while: the shipping
  difference or the price is not known; the order has no e-mail address,
  is closed or gone, or **was never paid** (a case by hand on an unpaid
  order has nothing to refund); the order line **was refunded** in the
  meantime; or another case of the order is with the customer and this
  one's shipping difference was typed (see *The shipping difference*).
- If `wp_mail()` refuses, the case is open again as if nothing happened.

**Settings → Send by itself after the undo window** is **off** unless
switched on. On, a case goes out by itself once the loss can no longer be
undone on the phone (15 minutes) — and only when everything about it is
certain: a loss opened it, the order line was found by its product (or
by the Invoices section), nothing marks it "check" (a name match, a
price that is not on the line, an order due to ship today), the shipping
difference was worked out and not typed, and the tanks are still short
of that fish. A case that grew waits out the window of its **last**
loss. A case the owner reopened never goes out by itself again.
Everything else waits for the click.

### No answer

One reminder after N days (Settings, default 2; 0 = none), with the links
again and good for another full term. From then on the case sits under
**Needs you** as *No answer*.

---

## The customer's side

Each link is `https://…/?fh_lost_fish=<token>`. The token names **one
case and one choice**, carries its expiry (Settings, default 14 days) and
is signed (HMAC-SHA256) with the case's own random salt under a key
derived from the site's salt. A token for another case or choice, an
altered one, or one whose case was reopened does not verify — that is a
404 that says nothing about any case.

- **Opening a link changes nothing** — mail scanners and link previews
  open links. The page shows the choice with the same words and the same
  money as the e-mail (both print `Email::choice_rows()`), a Confirm
  button, and the other choices.
- **Only the Confirm (a POST) records the choice** — once per case. A
  second Confirm, on any of the four links, only shows what was chosen.
- No login. The page is self-contained (no theme, no script, no external
  request), never cached, never indexed, and shows the fish, the order
  number and the amounts — no address, no e-mail.

**The order is read again on every request** — the e-mail may be days
old:

- Once the order is completed, shipped, cancelled, refunded or gone,
  every link of a case that still waits says *This request is closed*. A
  Confirm records nothing; the case is marked "check" (*the customer
  tried to answer, but the order is completed by now*) and the log has
  it. The owner can still record what the customer says by hand.
- Once part of the order has shipped, "cancel the whole order" is no
  longer offered: its link says so and lists the other choices, the
  other pages and the reminder drop it, and it cannot be recorded by
  hand either.
- The cancel amount is what is left to give back now (see *The money*).

Anyone who has the e-mail can use its links; that is the nature of a link
in an e-mail. Reopening a case kills them.

---

## After the answer

The case says what to do — **and the site does none of it**:

| Choice | The case shows |
| --- | --- |
| Refund | *Refund $X on order #N* (with "$Y for the fish, less $Z shipping difference") and the link to the order |
| Credit | *Add $X to the customer's wallet* and the link to the Batch Manager's wallets screen, searched for the customer, when that plugin is active; a guest order says there is no wallet yet |
| Cancel | *Cancel order #N and refund $X* — what is left to give back: with "the order came to $T; $R was already refunded on the order, and $C was already given as store credit (case #n)" when something went back, and a warning when another case of the order still asks for a refund that is part of $X — and the link to the order; the order's other open cases close |
| Try again | the Requests section is handed the fish as an owed request (`fishotel_requests_create()`); without it, or when it says no, a reminder to note it — the fish, for whom, already paid — and, when the section is there, an **Add to Requests** button |

Then **Done**, with an optional note.

---

## Settings

| Setting | Default | |
| --- | --- | --- |
| Send by itself after the undo window | **off** | see above |
| Remind the customer after (days) | 2 | 0 = no reminder |
| The links are good for (days) | 14 | from the e-mail, and again from the reminder |
| Shipping classes that mean "free on purpose" | `free` | class slugs |
| Subject, Text | built-in | plain text with placeholders |
| Reply-To address | none | |
| Use the store's e-mail design | on | WooCommerce's e-mail header and footer when it is active; else a plain white card |

---

## What it needs, and what it does without

Everything outside this folder is reached through `class_exists()` /
`function_exists()` / `method_exists()` / filters; *Cases → What this
screen can reach* says plainly what is not there.

| | With | Without |
| --- | --- | --- |
| WooCommerce | orders, notes, shipping settings | no case can be opened; the screen says so |
| Clean Room | a loss opens cases by itself; the cover is worked out | cases only by hand |
| ShipTracker | a line in a shipment has shipped | a line counts as shipped by its line status, or once the order is shipped / completed |
| Invoices section | invoice lines linked to the product, the species and size, or the tank group | invoice fish are found by the product on their line, or by name (marked "check") |
| Requests section | a "try again" becomes a request | a reminder to note it |
| Batch Manager | the link to the wallets screen; its ship-date rule | no link; the ship date is the day before the delivery date all the same |
| Theme | the delivery date through its helper (combined boxes) | the same two meta reads |
| Health section | the log lines also go to the Health log | they stay in this section's log |

---

## What it does not do (yet)

- **Two different fish of one order.** Losses of the same fish join one
  case per order line while it is unsent (see above). Two DIFFERENT
  lines of one order — or a further loss after the first e-mail went
  out — are still two cases and two e-mails. When the order's shipping
  stays the same either way, both go out at once; when it depends on the
  answers, the second waits for the first answer (see *The shipping
  difference*). With three or more waiting cases on one order the
  figures are always typed.
- **Telling the owner that an answer came in.** The only e-mail this
  section sends goes to the customer. An answer shows as the count on
  the *Lost Fish* menu, a private order note, a log line and the
  `fishotel_lostfish_case_answered` action — nothing rings.
- **A price that does not divide.** What was paid for one fish is the
  line ÷ its quantity, to the cent: three fish bought for 100.00 come to
  33.33 each, so a case for all three says 99.99. The owner can type the
  per-fish figure, not the line's.
- **The hourly tick** stays in the cron list after the section is
  switched off on the dashboard (it is removed when the plugin is
  deactivated). Nothing listens to it then; it does nothing.
- **A recount** that comes up short opens nothing by itself (it is not a
  `loss` event) — open the case by hand.
- **Sizes of a product.** Every group of a product is one kind, whatever
  its size: a customer owed a large one is "covered" by a small one of
  the same product. The case shows the size; the owner decides.
- **A line found by name** is only as good as the name: it is marked
  "check", and never sent by itself.
- **The shipping difference** is worked out only when the order's own
  shipping method can price a package outside a checkout and reproduces
  what was charged. A method that needs the cart, or rates that changed
  since, give "not known" — by design, never a guess.

---

## For other sections

**Function** (exists whenever the Misc plugin is active — guard with
`function_exists()`; while this section is switched off, or before its
tables exist, it returns an empty list and runs no query):

```php
fishotel_lostfish_cases_for_order( $order_id )
// => [ [ 'id', 'status', 'fish', 'qty', 'order_id', 'order_item_id',
//        'choice', 'amount' (cents, null without a choice), 'url' ], … ]
```

**Actions fired** (a listener that throws cannot undo what happened):

| Action | Arguments |
| --- | --- |
| `fishotel_lostfish_case_opened` | `$case` |
| `fishotel_lostfish_case_grown` | `$case`, `$added` (fish) — a further loss joined an unsent case |
| `fishotel_lostfish_case_sent` | `$case` |
| `fishotel_lostfish_case_reminded` | `$case` |
| `fishotel_lostfish_case_answered` | `$case`, `$choice` (`refund` / `credit` / `retry` / `cancel`), `$via` (`customer` / `owner`) |
| `fishotel_lostfish_case_done` | `$case` |
| `fishotel_lostfish_case_reopened` | `$case` |
| `fishotel_lostfish_case_closed` | `$case`, `$reason` |

**Filters**

| Filter | |
| --- | --- |
| `fishotel_lostfish_holders` | `( $holders, $kind )` — the order lines owed a kind of fish |
| `fishotel_lostfish_closed_statuses` | order statuses (without `wc-`) that owe nothing |
| `fishotel_lostfish_ship_date` | `( $ship_ymd, $delivery_ymd, $order )` |
| `fishotel_lostfish_email` | `( $message, $case, $facts )` — `to`, `subject`, `html`, `headers` |
| `fishotel_lostfish_request_args` | `( $args, $case )` — what is handed to the Requests section |

**What it listens to and calls**

| | |
| --- | --- |
| `fishotel_cleanroom_event_recorded` | `( $stay_id, $event_id, $event, $state )` — fired by the Clean Room's `Store::record_event()`; the one line this section added outside its folder |
| `fishotel_invoices_for_order( $order_id )` | the invoices of an order, each with `id`, `product_id` (the invoice's product) and `lines`. The function exists whenever the plugin is active; "the Invoices section is there" is `Invoices::is_on()` (`Orders::invoices_available()`) |
| `fishotel_invoices_is_adjustment( $name )` | an invoice's "Adjustment" travels as a fish line of the order (and as a fish row of a kept invoice's snapshot): it is money — never a line of the order here, so never owed, never lost, never "the rest of the order" (`Orders::is_adjustment()`) |
| `fishotel_invoices_lines_for_product( $invoice_product_id )` | the lines of one invoice product — asked for an invoice of the order the first function did not answer for. Per line: `id`, `invoice_id`, `kind` (only `fish` counts), `name`, `qty`, `unit_price`, `product_id`, `variation_id`, `stay_id`, `size_slug` |
| `fishotel_requests_create( $args )` | `source` = `lost_fish`, `owed` = true, `user_id`, `name`, `email`, `fish`, `product_id`, `size_slug`, `size_label`, `qty`, `order_id`, `note` (+ `order_item_id`, `lost_fish_case`). An id (or a row / object with one, or true) means created; a `WP_Error`, false or an exception means not. A size it refuses is tried once more without the size. The function exists whenever the plugin is active and answers a `WP_Error` while Requests is switched off, so "Add to Requests" and the screen's list ask the section itself (`Cases::requests_available()`); switched off, the case shows the reminder to note it |
| `fishotel_log` | `( $level, 'lost-fish', $message, $context )` |
| `fishotel_get_effective_delivery_date()`, `FST_Shipment::get_by_order()`, `FisHotel_Ship_Day::ship_date_for()`, `FisHotel_Batch_Manager` | read only |

**Meta it reads** (it writes none): order `_fishotel_shipping_date`,
`_fishotel_fulfilled_by_order`, `_fishotel_is_fulfillment`,
`_fishotel_piggyback_of`; order item `_fishotel_line_status`,
`_fh_invoice_fish`, `_fh_invoice_product_id`, `_fh_invoice_lines`,
`_fh_invoice_credit`.

**No REST route, no AJAX action, nothing for visitors but the page behind
`?fh_lost_fish=`.** Writes come in through three nonce'd admin-post
actions: `fishotel_lostfish_case`, `fishotel_lostfish_open`,
`fishotel_lostfish_settings`.

---

## Data

Two tables, created with dbDelta when the section first boots or the
plugin is activated, behind the autoloaded `fishotel_lostfish_db_version`
— written only once both tables **and every column** exist (a failed
install leaves a marker and is retried in wp-admin at most once an hour).
Kept on deactivation.

- `{prefix}fh_lostfish_cases` — one row per case: status, origin, the
  kind and the fish, the tank group, the losses behind it (each event
  with its number of fish) and when the last one was, the order line and
  its customer, the money in cents (`unit_paid`, `ship_diff` — NULL means
  "not known", never a silent zero — and the amounts frozen at the send),
  the link salt and expiry, the choice, a UTC timestamp per stage.
- `{prefix}fh_lostfish_log` — this section's log.

Options: `fishotel_lostfish_db_version` and `fishotel_lostfish_counts`
(both autoloaded: the menu's count and the cron tick's "anything to do?"
cost no query), `fishotel_lostfish_settings` (not autoloaded).

Cron: `fishotel_lostfish_tick`, hourly (the reminder; "send by itself"),
plus a one-off tick just after the undo window when "send by itself" is
on. With no case open or sent it reads two autoloaded options and
returns.

**An ordinary front-end request costs no query from this section**: boot
is three `add_action()` calls and one autoloaded option read; the page
hook returns after one `isset()`.

Orders are read and written through the WooCommerce order API only
(`wc_get_orders()`, `wc_get_order()`, the order's getters,
`add_order_note()`), so HPOS on or off makes no difference. One read
goes to WooCommerce's order-item tables — the same tables in both modes —
to learn which of the **open** orders (found through the API) hold a
product or an invoice line, so a loss tap does not load every open
order; each order it names is then loaded and judged through the API,
and when that read fails every open order is looked at instead.

---

## Files

| | |
| --- | --- |
| `class-lost-fish.php` | the section: info, load, boot, menu, activate / deactivate; `fishotel_lostfish_cases_for_order()` |
| `class-store.php` | the ONLY `$wpdb` file: the two tables, conditional status transitions, the per-kind lock, the open-order prefilter |
| `class-cover.php` | PURE: kinds, COVERED, COMMITTED, the shortage, who is suggested |
| `class-money.php` | PURE: cents; the money for each choice |
| `class-token.php` | PURE: the signed, expiring link token |
| `class-settings.php` | the option and its sanitising |
| `class-rooms.php` | the Clean Room, through `class_exists()` |
| `class-orders.php` | WooCommerce through the order API: holders, lines, an order's facts, notes; ShipTracker, the Invoices section, the theme |
| `class-shipping.php` | the shipping difference, or "not known" |
| `class-email.php` | the template, the choices, the preview, `wp_mail()` |
| `class-cases.php` | the life of a case |
| `class-customer-page.php` | the page behind the links |
| `class-admin.php`, `views/`, `css/`, `js/` | FisHotel Tools → Lost Fish; the link on the order screen |
| `tests/` | headless tests — see `tests/README.md` |
