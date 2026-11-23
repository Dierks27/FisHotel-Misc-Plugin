# Packing

Which bag every fish goes in, and which box an order fits in.

The owner packs every fish in one of a handful of standard bags — normally
one fish per bag — and knows from experience which box an order needs.
This section writes that knowledge down so somebody else can pack: a **Bag
size** on every fish variation, **how many bags of each size fit each
standard box** — worked out from the bag's flat size and the box's floor
(*The fit model*), or typed by the owner — and from those a **suggested box
on every order**, flagged *on the line* when it is nearly full so the owner
confirms it. Inside the box every fish then goes in **the largest bag that
still fits** (*Upsizing*): more water, less swinging. The owner can choose a
fish's bag by hand on the order. Every bag is **doubled, and tripled for a
fish with spines** (*Bag layers*), and what the order's Packing box worked
out — the box, the bags to pull — is handed to the place a shipment is
recorded, so it is not typed twice (*The shipment plan*).

Bags are for livestock only. A product-level checkbox, **Livestock — packed
in a bag**, says whether a product is bagged at all: the Bag size fields
show only while it is ticked, so medication, food and dry goods carry
none. See *Livestock — packed in a bag* below.

The Bag size and that checkbox are records, strictly. They are never shown
on the storefront, in the cart, at checkout, in an e-mail, on an invoice,
in My Account or in the customer-facing REST / Store API. See *Storefront
silence* below.

Nothing is seeded: no bag, no box and no number is filled in by the code
(the fit model's numbers are worked out each time from the sizes typed,
never stored). The one list that starts filled is the *Triple-bag these*
words — ten kinds of fish with spines, the owner's to change.

## Where it shows

**FisHotel Tools → Packing** (users who can `manage_woocommerce`), three tabs:

- **Bags** — the standard bags: a name, an optional width and length in
  inches (the bag lying flat — with both, the fit model works out how many
  fit each box; a bag without both falls back to the typed numbers, and the
  tab says so), an optional filled share (blank = the setting), how the
  bag stands once filled, a sort number. The list runs from the smallest
  bag to the largest: upsizing moves a fish down it. A bag keeps its `#` for good, so renaming one
  changes nothing on the products that use it; a removed bag's `#` is never
  given to another, and a page that was drawn before the bag was removed
  cannot bring it back under a new `#` (that save is refused: reload).
  Under the bags, **Triple-bag these**: the words that mean a fish is
  bagged three times instead of twice (*Bag layers*).
- **Sizes → bags** — one row per fish size with a bag dropdown (the map),
  and the *Fill empty ones from the map* tool.
- **Boxes** — where the boxes come from (with the room each leaves inside
  the foam), this section's own list of boxes, the *how many fit* table
  (box × bag: the number typed — "your number" — beside the worked-out one,
  "too wide", "no size", and where to tie a tall bag off), the *on the
  line* percent, the filled share and the foam.
  *Save boxes* draws the page again, so it refuses to run while *how many
  fit* holds numbers that are not saved yet: save those first.

A save that draws the page again (bags, the map, boxes) keeps its button
disabled until the page is back, so the same rows are never posted twice.

**The product editor** (the classic one) — under *Shipping*, on simple and
variable products, the checkbox **Livestock — packed in a bag**; and, while
it is ticked, **Bagging** (Auto / Double / Triple — *Bag layers*) and a
**Bag size** dropdown on every variation and on a simple product under
*Shipping*. The dropdown's first option is "Not set", and says which bag
the size map gives that variation right now.

**The order screen** (the legacy one and the High-Performance Order Storage
one) — a **Packing** box: each fish line with the bag to use (bold) and its
layers ("Medium ×2", "Large ×3 — spines"), "upsized
from <smallest> to fill the box" or "chosen by hand", its smallest bag and
where that came from, "tie off at about N in" when the bag stands taller
than the box, and a bag dropdown (Auto, or any bag of the list); the bags to
use and the smallest that fit, by size; **how many bags to pull** ("14 bags
to pull", by size); the suggested box with its fill, the
*on the line* flag, the bags in the box (confirmed, else suggested) with the
fill after upsizing, what was ignored and what was left out, and the
Confirm / change control. *Confirm* stores the suggestion that is on the
screen and no other: when the order, a bag size or the numbers changed
since the box was drawn, nothing is stored, the box is drawn again with
the suggestion as it stands now, and it is confirmed from there.

## Livestock — packed in a bag

Fish and other livestock go in a bag; medication, food and dry goods do
not, and need no Bag size. The checkbox in the product editor's *Shipping*
tab says which a product is. It is stored on the **parent** product as the
hidden meta `_fh_packs_in_bag` — `yes` or `no` — and a variation is read
from its parent.

**The one rule** (`Livestock::decide()`), asked wherever something decides
whether a product is bagged — an order's lines and its Packing box, the
fill tool, the contracts, the product editor:

| Stored on the product | Packed in a bag? |
|-----------------------|------------------|
| `yes` | yes, whatever its category |
| `no` | no — even in a fish category, and even with a Bag size stored earlier |
| nothing | yes when the product is in a fish category (see *What is a fish*); and, so that nothing set before the checkbox existed changes, yes for a product — or a variation — that carries a Bag size of its own |

So existing fish need no click, and medication and dry goods show no bag
fields. This replaces the earlier rule "a product with its own Bag size is
a fish whatever its category": that still holds only while no choice is
stored, and stops the moment the box is unticked.

- **The default** is what the box shows while nothing is stored: ticked
  for a product in a fish category (or with a Bag size from before the
  checkbox — its own, or one of its variations'), unticked otherwise. A
  new product follows its categories on the screen until the box is
  touched: put it in a fish category and the box ticks itself.
- **A save stores a choice only when the owner changed the box.** The form
  posts the state the box was drawn in beside it; a save that leaves the
  box as drawn stores nothing, and the product keeps following the
  default. A box the owner clicked is a choice whatever it ends up as —
  clicked off and on again included (the script says so on the form). A
  save that does not carry the box at all (quick edit, another screen)
  changes nothing — it is never read as "unticked".
- **The Bag size fields show only while the box is ticked.** They are
  drawn for every product; the product-data box carries the class
  `fh-no-bag` while the box is off (set by the server when the screen is
  drawn, flipped by the script when the box changes, without a reload),
  and one stylesheet rule hides every Bag size field under it — the
  variation panels WooCommerce loads later included.
- **Unticking deletes nothing.** A Bag size stored earlier stays on the
  product and on its variations; it stops counting, and counts again when
  the box is ticked.
- On an order, a line whose product is unticked is **ignored and named as
  that**, apart from what is simply not a fish.
- On a variable product with no stored choice and a Bag size on only some
  of its variations (set before the checkbox), those variations count and
  the others do not — as before. Click the box off and on again and save
  to store "yes" and make every variation follow it. The box is drawn
  ticked for such a product, so the editor says this under it, and the
  fill tool's preview counts the others as "off, or never saved as a
  choice" — never as "off" alone.

## Which bag

Only what is packed in a bag has one (the rule above). Then, for a product
or a variation:

1. its own **Bag size** — it overrides everything;
2. else the **size → bag map**: the bag of the variation's size (or of a
   simple product's size when it has exactly one);
3. else none.

For an order line: its product as above (a line whose product is not
packed in a bag has none); and when the product gives nothing — an invoice
line, an unlisted fish, a product that was deleted — the size the *line*
carries (WooCommerce copies a variation's attributes
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
object just before it is written. It fills only what is packed in a bag,
by the one rule: a variation whose product is unticked — or has no stored
choice and is in none of the fish categories — is counted in the preview
and left alone, so a net or a jar of food that happens to use the Size
attribute gets no Bag size. Tick *Livestock — packed in a bag* on such a
product when it really is packed in a bag, and the tool fills it (where
the box already shows ticked for a Bag size set earlier, click it off and
on again: the preview says so).

A variation keeps the bag it was given even when the map changes later,
and there is no bulk reset — so get the map right before *Set them* (a
variation set back to "Not set" follows the map again).

## Which box

- **Bags needed** — for each fish line, its quantity in its bag size: one
  bag per fish. (The filter `fishotel_packing_fish_per_bag` can raise the
  fish per bag for a line; that is the seam for a product-level number.)
- **How many fit** — for each box and bag, the number the owner typed
  ("your number") when there is one, else the fit model's (below). A bag
  with neither — no number typed and no width and length, or too wide for
  the box's floor — does not go in that box at all.
- **The fill of a box** — each bag's share of the box added up: 1 ÷ the
  typed number, or D² ÷ the usable floor for a worked-out one; and never
  less than the fullest size on its own (bags ÷ how many of it fit), nor
  less than the rows the round bags take when those run past the floor.
  With typed numbers only that is the sum of *bags needed ÷ how many fit*:
  six small bags in a box that takes twelve and two large ones in a box
  that takes six is 50 % + 33 % = 83 %.
- **The box is chosen from each fish's smallest bag** — its own Bag size or
  the size → bag map (*Which bag*). Upsizing comes after, inside the box.
- **The suggestion** is the smallest box (by volume) whose fill is at most
  100 %.
- **When no box holds it all** it is split, and the box says so: the
  largest box that takes any of what is left is filled (the bulkiest bags
  first), and so on — except that as soon as one box holds everything that
  is left, the smallest such box takes it.
- **On the line** — a suggested box whose fill, with every fish in its
  smallest bag, is at or over the threshold (a setting, 90 % until
  changed) is flagged for the owner to confirm. The fill after upsizing is
  shown beside it and is not judged: upsizing fills boxes on purpose.
- **A line with no bag size** is listed by name and the suggestion is
  marked *incomplete* — it is worked out for the lines that do have one.
- **What is not a fish** is ignored, and named as ignored — a line whose
  product is unticked (*Livestock — packed in a bag*) with that reason.
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
fish; an invoice credit is money, and so is an invoice's "Adjustment" —
the line that brings an invoice up to a stated price, which the theme can
only carry as a fish line; the Invoices section says which name that is
(`fishotel_invoices_is_adjustment()`); an Invoice product kept as one line is
opened up (its `_fh_invoice_lines` snapshot, else its description through
the theme's or the Batch plugin's parser when one is there — and when
nothing can read it, it is reported as a fish line without a bag, never
dropped); otherwise the line's product decides, by the rule of *Livestock
— packed in a bag*: ticked is a fish, unticked is not, and with no stored
choice a line is a fish when its product is in a fish category (the Ship
Day list when the Batch plugin has it, else `quarantined-fish` and
`inverts` under the same filter, `fishotel_ship_day_fish_categories`) or
carries a Bag size of its own from before the checkbox. A line with a
product that is unticked is not a fish even when an invoice flags it as
one; a line with no product has no checkbox to go by and is read as
before.

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

**One home per list.** While ShipTracker provides the boxes, the Boxes tab
says so in one line with a link to ShipTracker → Settings → Boxes & label
costs, and this section's own list is hidden — not deleted: it stays in
the settings and comes back, as it was, the moment ShipTracker has none.
The sizes are the Clean Room's (Settings → Sizes): the Sizes tab says so
with a link there, and only says which bag each size goes in.

## The fit model

The owner never stacks fish, and a bag is not all water: "figure out the
circumference of the bag, then compare that to the floor space of the
box … I also only use about 70% of the bag for water and oxygen, the other
30% is used for tying off the bags." So, from the bag's flat width W and
length L (Bags tab) and the box's inside size (`class-fit.php`, pure):

- **Across** — filled and tied a bag stands as a round column. Its
  circumference is the flat bag's mouth, 2 × W, so its diameter is
  **D = 2W ÷ π** (a 6 in bag stands 3.82 in across).
- **Tall** — L × the **filled share**: a setting (70 % until changed), or
  the bag's own (50 % for a bag filled half way).
- **The room** — only the box's **floor** counts: its inside length and
  width less the foam on both sides; the height less the foam top and
  bottom. The foam is the Foam Cuts section's thickness while that section
  is switched on, else this section's setting (0.5 in until changed).
- **Goes in** — when D fits the floor's shorter side. **Height never
  refuses a bag**: one that stands taller than the room is tied off shorter
  ("I can of course adjust this to whatever I need for the height of the
  box"), and the order says "tie off at about N in", N being the room's
  height.
- **How many** — each bag takes a D × D square of floor (round columns in
  rows — a deliberately simple ballpark): its share of the box is D² ÷ the
  floor. For one size alone the count is the smaller of floor(area ÷ D²)
  and floor(length ÷ D) × floor(width ÷ D). Bags of different sizes are
  also laid out in rows, the widest first, each row as deep as its first
  bag: when the rows run deeper than the floor is wide the bags do not
  stand side by side, whatever the shares add up to, so two big bags that
  each fit alone never share a box.

A number the owner typed for a box and a bag always beats the formula. The
Boxes tab shows both, per box and bag.

## Upsizing

"We will put the fish in the largest bags that fit into the box … the more
water the less it swings so ideally we always fill our boxes … give it to
every fish in a round robin starting with the largest fish."
(`class-upsize.php`, pure; `class-plan.php` puts the fish in the boxes.)

1. The box is chosen as above, from every fish's **smallest** bag.
2. The fish of the box — one entry per bag, so one per fish normally — are
   listed by their smallest bag, the largest first; a tie keeps the order
   line's position.
3. Round after round, each fish of the list moves up **one** bag — the next
   bag of the list that goes in this box at all (a bag with no number for
   the box is passed over) — when the box's fill with that change stays at
   or under 100 %. The rounds stop when a whole round moves nobody. A fish
   never goes past the last bag of the list.
4. A split upsizes each box on its own, with the fish the suggestion put
   in it. A confirmed box that is not the suggestion takes every fish
   whose smallest bag goes in it (a hand-chosen "3 × Box" is filled one
   box after the other, the bulkiest bags first, the rest in the last box
   that takes their bag), and the bags are worked out again for it.
   Confirming the suggested box itself keeps the suggestion's bags, even
   when some fish are "No box takes".
5. A fish whose smallest bag none of the confirmed boxes takes is left
   out of them, so the other fish still move up: a bag no box on the list
   takes stays *No box takes*; one that only another box would take is
   said — "Check it: 1 × Extra large does not go in Cube 8 — choose
   another box." (`packed.left_out`).

## Bag layers

"This should always be double for reg fish and triple for anything with
sharp spines or like a tang that has a scalpel. We double and triple bag
everything." So every bag Packing plans is used in **layers** — bags one
inside the other: **2** unless something says **3** (`class-layers.php`,
pure). How a fish is known, in this order:

1. **The product's own choice** — *Bagging: Auto / Double / Triple* in the
   product editor's Shipping tab, under *Livestock — packed in a bag*. It
   is stored on the **parent** product as the hidden meta `_fh_bag_layers`
   (`2` or `3`); **Auto stores nothing**. A variation is read from its
   parent. Double on a tang is respected: the owner said so. The Auto
   option says what it comes to for the product as it was last saved —
   "Auto — double for this fish", or "Auto — triple for this fish (“tang”
   is on the list)".
2. **A word of the *Triple-bag these* list** (Packing → Bags), found in the
   order line's **name** or in one of its product's **category names**.
   Because it reads the line's name, it also reaches a fish with no
   product and each fish of an opened-up Invoice.
3. Otherwise **2**.

**How a word matches.** As a whole word, whatever the capitals — and one
or many makes no difference: "tang" finds "Yellow Tang", "Yellow Tang-Medium"
and a category called "Tangs", and "tangs" on the list finds them just the
same; neither finds "Tangerine" or "Mustang". A word is not its short
form, though: "angelfish" does not find an invoice line written "Flame
Angel" — add "angel" for that (the card says so). A short
phrase ("powder blue") is a word too. A category with no name to read is
read by its slug, the hyphens as spaces.

**The list** starts with: tang, surgeonfish, unicornfish, rabbitfish,
foxface, lionfish, scorpionfish, triggerfish, angelfish, squirrelfish. One
word per line (or commas). An entry that is not a word — a single letter,
no letter at all, over 40 characters — refuses the whole save, so a slip is
seen. At most 60 words. An **empty list is a list**: nothing is then
triple-bagged by its name, and it stays empty (the starting words do not
come back by themselves — they are printed under the box to copy).

**Inverts are double-bagged** like any other fish, unless a word or the
product says otherwise. The outer and the inner bags are **the same stock
item**: a count of layers is a count of single bags to pull.

**Where it shows.** On the order's Packing box, after the bag of every fish
line: `×2`, or `×3 — spines` in red (hover for why: the product, or which
word). A line whose fish ended in bags of different sizes says `each ×2`.
Below the lines: **"14 bags to pull"** with the count by bag size — the
filled bags times their layers; in a split, each box says its own.

**For other code.** Every line of `fishotel_packing_suggest()` gains
`layers` (2 | 3), `layers_why` (`product`, `word:<word>`, `default`,
`filter`), `layers_label` ("Medium ×2", "Large ×3 — spines", "2 × Large +
Medium, each ×2") and `in_boxes` (box index → bag id → how many of the
line's bags are in that box); the answer gains `bags_pull` (by size: `id`,
`name`, `count`) and `pull_total`. `fishotel_packing_bag_for_item()` gains
`layers` and `layers_label` — **`name` and `label` are exactly what they
were**, so the Batch plugin's pick list ("Bag: Large") is unchanged. The
filter `fishotel_packing_bag_layers` ( `$layers`, `$product`, `$name`,
`$order_item` ) has the last word: an answer of 2 or 3 counts (the line
then says `filter`), anything else is ignored.

## The shipment plan

The owner picks the box and the bags in the Packing box — and used to type
the same box and count the same bags again where the shipment is recorded.
"Repeating myself is one of those things that drives me mad." Two seams,
both read only (`class-shipment-plan.php`):

### `fishotel_packing_shipment_plan( int $order_id ): array`

What Packing knows about an order, in the words a shipment is recorded in.
Never throws; every key is always there; also the filter
`fishotel_packing_shipment_plan` ( `$plan`, `$order_id` ).

| Key | |
|-----|---|
| `available`, `reason` | true when the order has a plan: at least one fish line, or it went into a combined box. Else `reason` is `disabled` · `no_woocommerce` · `order_not_found` · `no_plan` · `error` |
| `order_id`, `owner_id` | the order asked · the order whose Packing box holds the plan (the combined box's, for an order folded into one) |
| `packed_with` | the combined box this order went into (0 = none). Then `boxes` is empty: ask the combined box's id |
| `from` | `confirmed` · `suggestion` · `''` (no box) |
| `split` | more than one box |
| `boxes` | in Packing's order, each: `index` (from 0), `name`, `dims` (`LxWxH`), `standard` (ShipTracker's own row for the box — `name`, `dims`, **its strings verbatim** — or null), `match` (`name` · `dims` · `none`), `fish`, `bags`, `layers` (single bags to pull: Σ bags × layers), `by_bag` (each `id`, `name`, `count`, `layers`; the largest bag first), `items` (`"<order id>-<item id>"` → bags of that line in this box) |
| `lines` | the fish lines **still to pack**, each: `order_id`, `item_id`, `name`, `qty`, `bags`, `layers_each`, `why`, `boxes` (the `index` of every box holding some of it) |
| `totals` | `fish`, `bags`, `layers` over the lines |

- **Which ShipTracker box.** By Packing's name of the box (letter case and
  extra spaces aside) against `FST_Settings::get_standard_boxes()`; else
  the **first** standard box whose length, width and height are the same
  numbers ("12.0" is 12); else none. So a box confirmed under a name
  ShipTracker has since changed is still found by its size.
- **Boxes may be empty while `available` is true** — an order folded into a
  combined box, or one no box holds. There is then nothing to preselect;
  the bag counts are still Packing's.
- **A combined box** is asked by its own id: its `lines` and `items` carry
  the **source** orders' ids and line ids.
- **A fish line with no bag size** is in `lines` with its bags (it will be
  bagged in something) and in no box; so `totals` is all that is left to
  pack, and the boxes only what could be placed.
- **Same-size boxes** (two of "Long 20") are two entries, in Packing's
  order; Packing cannot know which physical box a tracking number is.
- **Worked out once while a page is drawn.** The order's Packing box, this
  function and `fishotel_packing_bag_for_item()` read one plan
  (`Order_Packing::plan_of()`); every write of the box drops it. In a
  request that may write — a posted form, AJAX, REST — this function works
  its answer out each time, because other code can change the order
  between two questions (the same goes for the bag count below).

### ShipTracker's "fish count" becomes the bags to pull

ShipTracker counts the "fish" of a box — the quantities of its order lines
— and uses that number for one thing only: the **fish-bag quantity** of the
box's usual materials. It hands the count to the filter
`fst_box_fish_count` ( `$count`, `$order`, `$item_ids` ). This section
answers it with the **layers**, so the Add form, the card of a recorded
box, a pickup, the materials saved with a new tracking number and
ShipTracker's REST answer all show the same bags — and **ShipTracker is
not changed**.

- **Lines given** (`$item_ids`): the bags × layers of those lines. A line
  that is not a fish — a jar of salt, a credit — counts **0** where
  ShipTracker counted its quantity.
- **The whole order** (`null`): all of it. When ShipTracker's count is
  **below** the order's whole quantity — its "not in a box yet" count — the
  bags of exactly the lines no shipment lists yet, read from ShipTracker's
  own shipments (`FST_Shipment::get_by_order()`). When that cannot be told
  — ShipTracker cannot be asked, or what its shipments list does not add
  up to its count — the same share of the bags, rounded to a whole bag.
- **Every line counts whole**, whatever happened to it: a shipped line (the
  card of the box that left asks about it) and a refunded one (the theme
  scales a refund itself). This is the one place that differs from the
  plan above, which lists what is left to pack.
- **A combined box** is counted through its source orders.
- **Unchanged** — ShipTracker's own count — for an order with no fish line
  Packing knows, while this section is off, when none of the line ids is a
  line of the order, and whenever anything fails. It never throws into
  ShipTracker.

**What changes for anybody reading that number.** While this section is
on, `FST_Supplies::fish_count()`, the filter `fst_box_fish_count` and the
REST field `fish_count` are **single bags, not fish**: 5 fish read as 10,
or more with spines. And the **fish-bag supply's stock counts down two to
three times faster** than before — as it does on the bench. Its *reorder
level* and its *opening purchase* are in single bags: look at both once
after switching this on.

This is the section's **one hook outside wp-admin** (ShipTracker counts in
its staff-only REST routes too): the callback is a method of the file that
is always loaded, loads nothing and reads nothing until ShipTracker fires
it, and no page a customer opens does.

## Bags chosen by hand

On the order's Packing box every fish line with a bag has a dropdown:
**Auto** (the worked-out bag) or any bag of the list. A choice is stored on
the order at once (`_fh_packing_bags`, through the order object, behind the
order box's nonce and `manage_woocommerce`), survives reloads and is shown as
"chosen by hand". It covers every fish of that line. The upsizing respects
it: the chosen bags take their space first, and the other fish round-robin
into what is left. When the chosen bags come to more than the box holds — or
one does not go in the box at all — the box is marked *Too full — check it*
and a sentence says so; nothing is refused. **Auto** removes the choice
(and the meta, with the last one). Confirming another box keeps the choices
and works the other fish out again in the new box. A line whose order went
into a combined box is chosen there.

## What is stored

| Where | Key | What |
|-------|-----|------|
| option (not autoloaded) | `fishotel_packing_settings` | `bags` (id, name, width, length, share — '' or a whole percent, sort), `last_bag_id`, `boxes` (the own list: name, length, width, height), `fits` (per box: name, dims, bag id → how many — only what was typed), `size_map` (size slug → bag id), `threshold`, `fill_share` (percent, 70), `foam` (inches, 0.5), `triple_words` (the *Triple-bag these* list, lower-cased; absent = the starting words) |
| product / variation meta | `_fh_bag_size` | the bag's id |
| product meta (the parent product, never a variation) | `_fh_packs_in_bag` | *Livestock — packed in a bag*: `yes` or `no`; absent = follow the default |
| product meta (the parent product, never a variation) | `_fh_bag_layers` | *Bagging*: `2` (double) or `3` (triple); absent = Auto |
| order meta | `_fh_packing_box` | the confirmed box: `v`, `boxes` (name, length, width, height), `fill` (as it stood), `suggested`, `complete`, `by` (user id), `at` (UTC) |
| order meta | `_fh_packing_box_name` | the same as plain text: `Medium box`, `2 × Large box + Small box` |
| order meta | `_fh_packing_bags` | the bags chosen by hand: line key (`order id-item id-n`, n counting the fish an Invoice line opened up into) → bag id; absent = every line on Auto |

There is no table and no cron event. Product meta is written through the
WooCommerce product object, order meta through the order object
(`update_meta_data()` / `delete_meta_data()` / `save()`), so it works with
High-Performance Order Storage on or off. Confirming a box writes those
two order meta keys and nothing else, and choosing a bag by hand writes
`_fh_packing_bags` and nothing else: no note, no status change, no
e-mail, no stock. The bags to use are worked out each time; none of it is
written to an order line.

## Contracts

All of these are declared by the Misc plugin — guard a call with
`function_exists()`. While the Packing section is switched off they answer
"nothing" (see below) and never throw.

```php
fishotel_packing_suggest( int $order_id ): array
fishotel_packing_bag_for_item( WC_Order_Item_Product $item ): ?array   // for the pick list: the bag to USE
fishotel_packing_confirmed_box( int $order_id ): ?array
fishotel_packing_bag_for_product( int|WC_Product $product ): ?array
fishotel_packing_bag_for_size( string $size_slug_or_label ): ?array
fishotel_packing_shipment_plan( int $order_id ): array                 // see The shipment plan
```

**`fishotel_packing_suggest()`** always returns the same keys:

| Key | |
|-----|---|
| `available` | false while the section is off, WooCommerce is not loaded or the order does not exist; `reason` says which (`disabled`, `no_woocommerce`, `order_not_found`, `error`) |
| `status` | `fits` (one box holds every bag) · `split` (several do) · `partial` (boxes for what can be boxed; some bags go in no box) · `no_fit` · `no_boxes` · `empty` (no bags needed) · `unavailable` |
| `boxes` | the suggested box(es): `key`, `name`, `length`, `width`, `height` (inches), `dims` (`LxWxH`), `volume`, `source`, `bags` (bag id → how many go in it), `fill` (1.0 = full), `fill_pct`, `on_the_line` |
| `split`, `on_the_line`, `threshold` | more than one box · a suggested box is at or over the threshold · the percent |
| `bags`, `bag_total` | bags needed by size — every fish in its smallest bag, what the box is chosen from — in the bag list's order: `id`, `name`, `width`, `length`, `label`, `count` |
| `bags_used` | the bags to use by size, after upsizing and the choices by hand, in the same shape |
| `bags_pull`, `pull_total` | the single bags to pull — the bags to use times their layers — by size (`id`, `name`, `count`) · in all |
| `packed` | the upsizing: `from` (`suggestion` · `confirmed` · `''`), `boxes` (each: `key`, `name`, `length`, `width`, `height`, `bags` — bag id → how many to use, `fill_min` / `fill_min_pct` with the smallest bags, `fill` / `fill_pct` after upsizing — null when a chosen bag does not go in —, `over`, `moved`, `tie_off` — bag id → inches, `missing` — bag ids that do not go in), `moved` (fish moved up), `over` (a box is too full), `chosen` (lines chosen by hand), `left_out` (bag id → fish left out of the confirmed boxes because only another box takes that bag) |
| `overrides` | the bags chosen by hand that apply: line key → bag id |
| `unplaced` | bags no box takes: `id`, `name`, `count` |
| `lines` | every fish line: `item_id`, `order_id`, `name`, `qty`, `fish_per_bag`, `bags`, `bag` (its smallest bag; null without one), `size`, `size_label`, `flag`, `from`, and `key` (`order id-item id-n`), `use` (the bags to use: `id`, `name`, `label`, `count`, the largest first), `use_name` (`Large`, `2 × Large + Medium`), `upsized`, `chosen` (bag id chosen by hand, 0 = Auto), `tie_off` (inches, or null), `in_boxes` (box index → bag id → bags), `layers`, `layers_why`, `layers_label` (*Bag layers*) |
| `incomplete`, `complete` | the fish lines with no bag size (`item_id`, `order_id`, `name`, `qty`, `reason`) · true when there is none |
| `ignored`, `skipped` | lines that are not fish, or whose product is not packed in a bag (*Livestock — packed in a bag* unticked — the `reason` says so) · lines left out (shipped, never shipping, refunded) — each with a `reason` |
| `box_source` | `shiptracker` or `own` |
| `is_fulfillment`, `sources`, `packed_with` | a combined box and its live source order ids · the combined box an order went into (0 = none) |
| `confirmed` | `fishotel_packing_confirmed_box()` of the order |
| `messages` | what a person should read, as sentences |

**A bag** (`fishotel_packing_bag_for_item()` and the two helpers): `id`,
`name`, `width`, `length` (inches as text, `''` when not given), `label`
(`Medium (6 × 14 in)`), `sort`, `source` (`own` = set on the variation or
the product, `map` = from the size → bag map), `size`, `size_label`. Null
when the line has no bag size, and null for a line or a product that is
not packed in a bag — `fishotel_packing_bag_for_item()` and
`fishotel_packing_bag_for_product()` ask the same rule as the order's
Packing box (`fishotel_packing_bag_for_size()` is the bag of a size, not of
a product, and answers as before).

**`fishotel_packing_bag_for_item()`** answers with the bag to **use** when the
line's order has a plan — chosen by hand, else upsized, else the smallest
— in the same shape, plus `min` (the smallest bag, the shape above),
`upsized`, `chosen_by_hand`, `mixed` and `bags` (each `id`, `name`, `label`,
`count`, the largest first), `layers` and `layers_label` (*Bag layers* —
also on the answer without a plan); `source` is then `hand` or `upsized`
when the bag is not the smallest. A line whose fish ended in different bags is
`mixed`: `name` says all of them, largest first (`Medium + 2 × Small`),
`label` the same with their sizes, and `id`, `width`, `length`, `sort` are
the largest bag's. A line that went into a combined box is read through the
combined box. The plan of an order is worked out once per request. With no
plan (a line on no order) it answers the smallest bag, without `min`, as
before. The Batch plugin's pick list prints `name` ("Bag: Large") — no
change is needed there.

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
| `fishotel_packing_shipment_plan` | `$plan`, `$order_id` | " |
| `fishotel_packing_bag_layers` | `$layers`, `$product`, `$name`, `$order_item` | the layers of a fish line: 2 or 3 counts |
| `fishotel_packing_fish_per_bag` | `1`, `$product`, `$order_item` | fish per bag for a line (a whole number above 1 counts) |
| `fishotel_packing_item_size` | `$size_slug`, `$order_item` | the size an order line carries |
| `fishotel_packing_size_attribute` | `''`, `$attributes` | which attribute is the size, while the Clean Room is off |
| `fishotel_packing_boxes` | `$boxes`, `$source` | the boxes in use |
| `fishotel_ship_day_fish_categories` | `$slugs` | read, not owned: the fish categories (the Batch plugin's filter) |
| `fst_box_fish_count` | `$count`, `$order`, `$item_ids` | answered, not owned: ShipTracker's count of a box's fish — this section hands back the bags to pull (*The shipment plan*) |

**Actions**

- `fishotel_packing_box_confirmed` — `$order_id`, `$box` (the
  `fishotel_packing_confirmed_box()` shape) after a box was confirmed or
  changed.
- `fishotel_packing_box_cleared` — `$order_id` after it was cleared.

**In the browser** — each time the Packing box of an order is drawn again
(a box confirmed, changed or cleared, a line's bag changed, a refresh, a
refused Confirm) `js/packing-admin.js` triggers the jQuery event
`fishotel-packing-changed` on `document`, with `{ orderId }`. The theme's
Shipments box listens and asks for the plan again, so a box changed here is
the box the Add Shipment form holds — without reloading the page.

**REST routes**: none. **Public AJAX**: none. The admin AJAX actions
(`manage_woocommerce` + a nonce each): `fishotel_packing_bags`,
`fishotel_packing_boxes`, `fishotel_packing_fits`, `fishotel_packing_map`,
`fishotel_packing_words` (nonce `fishotel_packing_settings`), `fishotel_packing_fill_preview`,
`fishotel_packing_fill_run` (nonce `fishotel_packing_fill`) and
`fishotel_packing_confirm` (nonce `fishotel_packing_order`; with
`choice=suggested` the screen posts `shown`, the suggestion it displayed,
and gets 409 plus the box drawn again when that is not the suggestion any
more; `op=bag` with `line` and `bag` — a bag id, or empty for Auto —
chooses a line's bag by hand). The product
editor's fields — the checkbox, *Bagging* and the dropdown — are saved by
WooCommerce's own product saves, behind this section's nonce
`fishotel_packing_bag_size` and `manage_woocommerce` as well, through the
product object.

## What it reads from other code — and what happens without it

| From | What | Without it |
|------|------|-----------|
| Clean Room section | `Sizes::standard()` — the list of sizes | this section finds the "size" attribute itself |
| ShipTracker | `FST_Settings::get_standard_boxes()` — the standard boxes; its filter `fst_box_fish_count` (answered) | this section's own list of boxes; the plan names no ShipTracker box (`match` = `none`); nobody asks for the bag count |
| Batch plugin | `FisHotel_Ship_Day::fish_categories()`, `::parse_invoice_lines()`; the line meta `_fishotel_fish_id` | `quarantined-fish` + `inverts`; an Invoice without a snapshot is reported as unreadable |
| Theme | `FisHotel_Invoice_Lines::get_lines()`; the line and order meta `_fishotel_line_status`, `_fh_invoice_fish`, `_fh_invoice_product_id`, `_fh_invoice_lines`, `_fh_invoice_credit`, `_fishotel_is_fulfillment`, `_fishotel_fulfillment_sources`, `_fishotel_fulfilled_by_order` | every order is an ordinary order |
| Foam Cuts section | `Foam_Cuts::enabled()`, `Settings::get()['thickness']` ÷ `Units::PER_INCH` — the foam thickness, while that section is switched on | this section's own foam setting (0.5 in) |
| WooCommerce | products, orders | the page still opens and says so; the lists can be filled in |

Each is reached through `class_exists()` / `method_exists()` /
`is_callable()` only, inside a `try`, and never written to.

## Storefront silence

- Outside wp-admin `boot()` loads nothing but the section's own file,
  reads **no option** and registers **one hook**: ShipTracker's own filter
  `fst_box_fish_count`, which only ShipTracker fires — on the order screen
  and in its staff-only REST routes — and whose callback loads and reads
  nothing until then. An ordinary front-end request costs no query here.
  (admin-ajax.php counts as wp-admin, which is where the product editor
  and the order screen save.)
- In wp-admin the hooks are the product editor's
  (`woocommerce_product_after_variable_attributes`,
  `woocommerce_save_product_variation`,
  `woocommerce_product_options_shipping`,
  `woocommerce_admin_process_product_object`, and
  `postbox_classes_product_woocommerce-product-data` for the class that
  hides the Bag size fields and *Bagging*), `add_meta_boxes`, `admin_enqueue_scripts`
  (the Packing page and the product edit screen) and the admin AJAX
  actions — none of the hooks
  WooCommerce uses to print variation data, cart / checkout / e-mail /
  invoice / My Account order-item data, or to build a REST or Store API
  answer.
- The bag is never written to an order line: it is worked out from the
  product each time — the bag to use as well. The bags chosen by hand are
  hidden order meta (`_fh_packing_bags`), written only by the order's
  Packing box. The three product meta keys — `_fh_bag_size`,
  `_fh_packs_in_bag` and `_fh_bag_layers` — are hidden (a leading
  underscore), are not registered for REST, are not product attributes,
  and make no variation and no term. The checkbox is drawn in the product editor only; no view
  and no script names its key.
- The lists are one option that is **not autoloaded**: the Packing page,
  the product editor and the order screen read it once; no other screen
  does.

`tests/storefront-test.php` holds the section to all of this.

## On the Today page

While this section is on, the Today page and its widget show one line
for it under **Shipping** — **"Packing: 3 orders ship today or tomorrow
with no box confirmed"**, the order numbers beside it, and a link to the
order (one) or the orders list (several). It counts the orders in
Processing or Fulfillment that deliver tomorrow or the day after and
have no confirmed box; an order folded into a combined box is left out.
No line when every order has its box, and none for a user who may not
open this section. It is hooked in wp-admin only: one query
(`Store::unconfirmed_orders()`, on the legacy order tables or
WooCommerce's own), once per request (`class-today-line.php`).

## Files

```
packing/
├── class-packing.php        the section, and the contract functions
├── class-settings.php       the one option, its sanitisers, the five saves
├── class-size-list.php      the fish sizes (the Clean Room's, else found here)
├── class-fit.php            PURE: the fit model — a bag's size against a box's floor
├── class-boxes.php          the boxes in use; "how many fit" per box (typed, else the fit model)
├── class-suggest.php        PURE: bags needed → box(es), fill, on the line
├── class-upsize.php         PURE: the round robin inside one box
├── class-plan.php           PURE: the fish into the box(es), upsized; the choices by hand
├── class-bags.php           a product's / an order line's bag
├── class-layers.php         PURE: double, or triple for spines — the product's choice, else the words
├── class-shipment-plan.php  the plan for the code that records a shipment; ShipTracker's bag count
├── class-livestock.php      "Livestock — packed in a bag": the one rule
├── class-lines.php          an order's fish lines
├── class-order-packing.php  the suggestion of an order; the bags to use; the confirmed box; the choices by hand
├── class-store.php          the only $wpdb file — reads only
├── class-fill.php           "Fill empty ones from the map"
├── class-product.php        the checkbox, Bagging and the Bag size dropdown in the product editor
├── class-order-box.php      the Packing box on the order screen
├── class-admin.php          the page; the assets
├── views/                   page, tab-bags, tab-sizes, tab-boxes, order-box
├── css/packing-admin.css    the dark page; the box on the (light) order screen
├── js/packing-admin.js      the saves, the fill run, the box's buttons, the checkbox
└── tests/                   headless — see tests/README.md
```

## Not in this version

- **Fish per bag** on a product: the maths and the filter are there, the
  field is not.
- **The pick list** itself lives in the Batch plugin; it shows the bag to
  use of a line by calling `fishotel_packing_bag_for_item()`.
- **The fit model is a ballpark**: square cells and rows, not the best
  packing of circles. Where it is off for a box and a bag, type the number:
  "your number" wins.
- The checkbox, *Bagging* and the Bag size dropdown are in the **classic**
  product editor (the Shipping tab and the variations); quick edit, bulk
  edit and the product import set none of them — a product made that way
  follows the default.
- **Prefilling the shipment form** is the theme's part: it reads
  `fishotel_packing_shipment_plan()`. This section only answers.
- **Which lines are still open** is ShipTracker's to know: for "the next
  box" it hands this section a number, not the lines. The section looks the
  lines up in ShipTracker's shipments; only when they do not add up to that
  number are the bags a share of the order's — a ballpark when the order
  mixes fish and dry goods; tick the lines in the form for the exact count.
