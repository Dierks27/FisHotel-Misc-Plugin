# Clean Room

Where every fish physically is, from the day it lands to the day it
ships: the Spa (quarantine / treatment) and Clean Room (observation) tanks
as a seeded, editable layout; one **stay** per group of one species with
an event history that is replayed, never edited; a one-thumb phone screen
(the **Front Desk**) opened by a QR label on every tank, driven by big
buttons or one spoken sentence; a dark **Room Board** under FisHotel
Tools; the **named treatment schedules** that turn into each tank's
daily to-do, a **dose log** on the whole system or one tank; the
**supplier orders** with the workbook's landed-cost maths and a check-in
that turns an order's lines into stays; a **species master**; the yearly
**stats**; Excel exports; the **workbook import** that brings the
spreadsheet's history onto the site; the **sale dates** — the day each batch (or one
group) goes on sale, chosen by hand —; the **standard sizes** (the ones
the product variations use) on order lines and stays; the **percent of a
box** a line took, guessed while unloading, with the **shares the site
remembers** per supplier and origin; **unknown and mislabeled fish** —
"??" until somebody knows, and one correction that puts the stay and its
order line right together —; **temporary tanks** — hospital tanks and
the like, each its own water, set up for a while with their own
schedule and their own clock, and taken down again —; **one to-do list
for the day, by water system**; and the facts the fish page will read.

Nothing here needs WooCommerce: a stay may or may not be linked to a
product, a species may or may not be, and the species search reads the
`quarantined-fish` / `inverts` products straight from the tables next to
the species list.

## How Jeff runs the room (the rules the code follows)

- **Every arrival goes into a Spa tank** (buildings 500 and 600). Nothing
  is ever checked in straight to a Clean Room tank; the Arrival screen,
  the parser and the server all refuse it and offer the Spa tanks.
- **Cleared** is the day a group first *moves* from a Spa tank into a
  Clean Room tank — the day it comes off copper. Only a move *out of the
  Spa* (or out of a tank the layout no longer knows) clears: after a hand
  unset, a Clean Room → Clean Room move does not re-clear. The date is
  editable per stay (Dates on the phone, *Cleared…* on the Stays tab) and
  can be unset; a hand-set date is a `clear` event recorded *now* with the
  date in its note, so a correction to an earlier date — or a date set
  after an unset — always wins over the move.
- The **check-in date** is the site date of the first arrival and can be
  corrected too (Dates on the phone, *Edit* on the Stays tab): a
  `checkin:YYYY-MM-DD` note the replay honours; the day number and the
  suggested sale date follow from it.
- **A group goes on sale the day it is marked to** — a date that is
  chosen, never computed (see *Sale dates* below): the batch's "Goes on
  sale" date on its supplier order, or the group's own date on its
  product page. From that date on it is *ready*, cleared or not; one
  whose date has come while it is still in the Spa says **still in the
  Spa** on every screen. Without a date it is not for sale; check-in + N
  days (*Suggested sale date: days after check-in*, default 28) is only
  the "suggested" hint beside the pickers.
- **Alive = received − losses − shipped**, with an optional full recount
  that overrides. Head counts are unreliable (fish hide), Jeff counts
  bodies, so a **loss is the fastest path**: one tap on *−1 lost* on the
  fish's row, or "lost one cardinal in 521" / "2 dead in 513" by voice.
  Two phones can record a loss against the same count; the second entry
  is kept but flagged **overdrawn** — the toast says the tank now shows
  fewer fish than were taken out and asks for a recount (or an undo of the
  duplicate, from the toast or the Events tab).
- **Moves** are one line — "move 3 cardinals 121 to 221". A quantity
  smaller than the group **splits** it: that many continue as a sister
  stay with the same check-in, cleared date and override; the rest stay
  put. The Move sheet offers reason chips — *clear building / bullying /
  pairing / hospital / other* — stored in the move event's note and shown on the
  Events tab.
- Counts stay **editable at any time** (Jeff loses fish after an order
  exists); the order itself is edited in WooCommerce. Any mistake is undone
  with a compensating event — from the phone's toast within 15 minutes, or
  from the Events tab at any time — and never twice for the same event.
- The **treatment schedules** are editable, named templates, not code:
  steps with a day offset, a kind (feed / dose / test / water change /
  other), an optional repeat and a "where". Each Spa building runs the
  template named in its layout row, a building without one runs the
  default. The seeds are the workbook's five schedules, counted from the
  check-in day as day 1 (the sheet counts the arrival day as day 0, so
  site day = sheet day + 1), every one starting with the daily feeding:
  **14 Day CM** (the default): Copper Power to 2.0 on day 1 and to 2.4 on
  day 2, then held at 2.25 or higher (2.5 the target) with a daily copper
  test to day 15; Metronidazole every other day from day 2 to day 12 (six
  treatments); Praziquantel on days 8 and 14; a freshwater or formalin
  bath on day 16 and the move to the Clean Room. **14 Day CF**: the same
  copper, Formalin daily days 5–14, Praziquantel days 7, 12 and 14, the
  move day 15. **Xtra 14 Day CF**: Formalin daily days 1–14,
  Praziquantel days 6, 11 and 13, the move day 15. **CQ**: Chloroquine
  40 mg/gal day 3, 12 mg/gal days 6, 8, 10 and 12, 6 mg/gal day 14,
  Praziquantel days 7, 11 and 13, a freshwater bath and the move day 16.
  **Trifecta** (the antibiotics for sick fish, anywhere): the
  Metronidazole + Kanamycin + Furan-2 combo day 1, Furan-2 on the even
  days to 14, a 25 % water change and the combo on the odd days to 15.
  **Copper readings** are logged per tank ("521 copper 2.4") and read
  per system: a building is one water, so the newest reading taken on
  any of its tanks is the building's, shown on each of its tanks with
  the tank it was taken on; a reading logged today ticks the copper test
  (a `test` step whose label names copper / Cu) for the whole system by
  itself. **Doses** are logged on the whole system
  or one tank (the Dose sheet, below); a dose logged today ticks the due
  `dose` step that names the same medication — by alias, so "Metro" ticks
  "Metronidazole" and "Copper Power 55 ml" ticks "Copper Power to 2.0";
  when a note names several, the one named first counts —
  and a dose on the system ticks it on every tank of that building. So
  does a dose into one tank of a shared building, and a tick on one
  tank's own screen: it is one water, and the step is listed once for
  the system (see *To do*). The feeding stays per tank.
- **A temporary tank behaves as what it was set up as.** A *quarantine*
  one is a Spa tank for every rule above: arrivals may go into it, fish
  in it stay uncleared, and a move from it into a clean tank clears the
  group exactly as a move out of the Spa does. A *clean* one is a Clean
  Room tank: no direct arrivals, and a quarantine group moved into it is
  cleared that day (the Move sheet says so before the confirm). A slot
  with nothing set up holds no fish and takes no reading, dose or tick.
  The fish's own check-in date, day number and sale date are untouched
  by sitting in one (see *Temporary tanks*).
- Every building is one water: its **system gallons** (sump included —
  Spa 500 holds 205, Spa 600 100; blank = the sum of its tanks) drive the
  Dose sheet's suggested amounts (Metronidazole 25 mg/gal, Praziquantel
  9.5 mg/gal, Formalin 1 ml per 10 gal, Chloroquine 10 mg/gal,
  Fenbendazole 1 g per 100 gal; copper has no rate — it is dosed to the
  test reading) and tell the workbook import which building a log row's
  205 / 100 system is.

## The numbering rule

**Hundreds = building, tens = level (1 bottom, 2 top), ones = position
(1 = leftmost).** Tank 121 is building 100, top row, leftmost. Every
building is ONE shared water system (sump + skimmer): nothing extra is
stored, but every tank title says which tanks it shares water with.

Seed (inserted when the tables are installed and the buildings table is
empty — never on an ordinary boot, which costs no query):

| Building | Area | Top row | Bottom row |
|----------|------|---------|------------|
| 100 | Clean Room | 121, 122, 123 (20G long) | 111, 112 (40G breeder) |
| 200 | Clean Room | 221, 222 | 211, 212 |
| 300 | Clean Room | 321, 322 | 311, 312 |
| 400 | Clean Room | coming soon, no tanks | |
| 500 | Spa | 521, 522, 523, 524 (20G long) | 511, 512, 513 (40G breeder) |
| 600 | Spa | 621 (40G breeder) | 611 (40G breeder) |

**The one exception: the Temporary building (900).** Its tanks are each
their own water — not a shared system — and are numbered **slots**:
hundreds = building, tens 0 = not on a rack, ones = the slot (901 … 909).
A slot is a place and a laminated label, "TEMP 1 · 901"; what is set up
in it comes and goes. Six slots are seeded (901–906) with the 1.6
upgrade or a fresh install; Settings → Layout says how many there are
and adds one more with a click, up to nine — the ones digit is the slot.
A slot has no level, size or gallons of its own (those belong to what is
set up in it) and can be retired with its status while nothing is set up
in it. The building is flagged `own_water` and has the area `temp`,
which is neither `clean` nor `spa`: code that knows nothing about
temporary tanks never takes one for a Spa or a Clean Room tank. When a
site already uses 900 for an ordinary building, the Temporary building
takes the highest round hundred that is free; when all nine are taken,
there is none and the Board says so.

Settings → Layout adds / renames / retires buildings and tanks with the
rule enforced: a building is a round hundred, a tank's number is derived
from building + level + position (never typed), duplicates are refused by
name, and a building with tanks keeps its number. Retire with the status
rather than deleting — stays point at tanks.

## The phone screen (Front Desk)

`home_url( '/?fh_front_desk=1&tank=521' )` — exactly what each QR label
encodes. No theme header or footer; its CSS and JS are inlined, so after
the page loads nothing else is fetched. Logged out → the login page and
back — and a login that came from a scanned label keeps its cookie for
about a year (the `auth_cookie_expiration` filter, only when the login's
`redirect_to` is this page and the user may use it), so a phone does not
log in again every two days; logged in without `manage_woocommerce` →
"Not allowed".

**Tank screen** — title ("Tank 521 · Spa · Building 500 · 20G long ·
shares water with 511, 512, 513, 522, 523, 524 · 205 gal system ·
schedule 14 Day CM"), the occupants first (species with its size, live,
day N, state badge, "still in the Spa" when a group's sale date came
before it was cleared, "needs identifying" on a fish nobody knows yet,
in since / "on sale <date>" or "Sale date not set", each with
a **−1 lost** button — the
fastest path is never below the fold), then the **Today** strip folded
to one line ("Today · 3 to do ▸", plus "Cu low" when the last reading is
under the floor) that opens on tap — the to-do from the building's
schedule as tap-to-tick chips, feeding first; the last copper reading
with its age, red when below the floor; a Copper entry and a **Dose**
entry — and stays open for the session; and the bar: **Lost · Shipped ·
Move · Arrival · Note · Mic** (Copper takes the Mic's place when voice is
off). Tapping an occupant opens its sheet: −1 lost, Lost…, Shipped, Move,
Full recount, Note, Dates (check-in date, cleared date, the group's own
sale date — empty goes back to the batch's, a "suggested …" chip fills
the picker) and **Identify** (*Correct the fish* on a fish that has a
name): the Arrival sheet's species search and the Size dropdown — or
*Unknown fish (??)* with what it looks like —, one Save, the toast
"Identified: ?? small wrasse → … · the order line follows", and no Undo:
who a fish is is corrected again, not reversed (see *Unknown and
mislabeled fish*).

**A temporary slot's screen** — the title names the slot and what is set
up in it ("TEMP 1 · 901 · Hospital A · Temporary · Quarantine ·
bacterial infection · its own water · 10 gal · schedule Trifecta ·
day 3"), the to-do comes from its own schedule on its own clock, and
two more tools sit under the Today strip: **Schedule** (this tank's own
steps — −1 day / +1 day, Remove, Add a step — for this tank only) and
**Take down** (asked first; with fish in it the screen says to move
them out first). A slot with nothing in it shows **"Nothing is set up
here — Set up"** and nothing else. **Set up** (also on the tank picker,
which lists the temporary tanks as a group of their own with "+ Set
up") asks for the name, *What is it for?*, the kind, the gallons, the
treatment and its start date and a note — see *Temporary tanks*. The
tank picker's first entry is **Today**: the day's list by water system
(see *To do*), also at `/?fh_front_desk=1&list=1`.

**Dose** — medication chips (Copper · Metro · Prazi · Formalin ·
Chloroquine · Fenben · Salt), the amount with its unit (g / ml / tsp; the
medication's usual unit is preselected), the suggested amount as a
tap-to-fill chip (gallons × the rate, for the system or for the tank —
none for copper, which is dosed to the test), and **whole system** (the
default when the building has a volume: "Whole system — Building 500 ·
205 gal system") or **this tank only**. Save writes a `dose` log — on the
building with tank 0 for the system, on the tank otherwise — and the
toast reads "Metronidazole 5.1 g · Building 500, whole system" or
"… · Tank 521". **A dose has no undo**: it cannot come back out of the
water, so the log stands and a correction is a note; the toast says so
on the sheet. On a temporary tank there is no "whole system": the dose
is that tank's own water, the suggestion is for the setup's gallons, and
the server files it on the tank whatever the sheet posted.

**Arrival** — species search with instant suggestions (products, common
names, scientific names, learned aliases), recent-species chips, "use as
an unlisted fish" and, beside it, **Unknown fish (??)** — the group is
checked in as "??" and what was typed ("?? small wrasse"), flagged
*needs identifying*, linked to no product, with its tank, count and size
like any other —, a quantity stepper, the Size dropdown (the standard
sizes, optional), the Spa tank (the current one when it is a Spa tank,
else the first empty one; a *quarantine* temporary tank is offered
beside the Spa tanks, a clean one never) and the date.

**Move** — one group or all (a tank with a single group skips "Which
fish?"), an optional quantity (smaller = split), the reason chips
(*hospital* among them), then the destination grid: Area → Building →
tanks, empty tanks first, the current tank disabled, buildings with a
group in QT marked; the temporary tanks that are set up are a group of
their own (a free slot is no destination). While a group that moves is
still in quarantine the sheet says that a move into the Clean Room — or
into a clean temporary tank — clears it today, and a tap on a **clean
temporary tank** asks first ("Move and clear").

Every write is one AJAX action (`fishotel_cleanroom_act`, nonce +
`manage_woocommerce`); the reply carries the refreshed screen, and a
toast offers **Undo** — a compensating event, never a delete. The toast
stays until the next action (at least 10 seconds); the server honours the
undo for 15 minutes, only for the user who wrote the event, and never
twice for the same event. The temporary tanks ride on the same action
with five more acts — `setup`, `setup_edit`, `setup_steps`, `takedown`
and `todo_tick` (one line of the day's list, for a whole building or
for one tank) — which need no tank on the page and have no undo: a
setup is taken down, a schedule changed back, a tick stands. The Board
posts the very same acts through its own action
(`fishotel_cleanroom_temp`, the Board's nonce + `manage_woocommerce`).

### Voice phrases that work

The Mic button uses the browser's speech recognition (en-US, one phrase);
without it, the same box takes typed text and the keyboard's own mic key
dictates. The sentence is parsed on the server and shown in plain words
with a 4-second countdown (Settings), Cancel, and *Not that fish* — which
opens the species picker and teaches an alias (the spoken words → that
fish) for next time.

| Say | Result |
|-----|--------|
| `lost one cardinal in 521` · `2 dead in 513` · `a clownfish died, jumped` · `lost 2 clowns doa` | a loss on that group (reason: DOA, disease, jumped, unknown) |
| `shipped 2 cardinals` · `sold a clown` | shipped (lowers the live count, not the survival stats) |
| `move 3 cardinals 121 to 221` · `move the clowns to building one top left` · `move all to 222` | a move (a quantity smaller than the group splits it) |
| `four pajama cardinalfish in tank 521` · `put a dozen banggai cardinals into 522` | an arrival (a Clean Room tank is refused — pick a Spa tank) |
| `521 copper 2.4` · `copper two point four` | a copper reading on the tank |
| `count five clowns` · `there are 3 pajamas` | a full recount |
| `clear the cardinals` | the cleared date set by hand (today) |
| `note water change done` | a note on the tank's log |

A temporary tank is spoken of by its number ("four … in tank 901", "move
the clowns to 901", "901 copper 2.4"): a quarantine one takes a spoken
arrival like a Spa tank, a clean one or a free slot asks for a
quarantine tank.

Numbers: digits, number words, "a couple" = 2, "a dozen" = 12, "half a
dozen" = 6, "two dozen" = 24. Tanks: "tank 121", "one twenty one", "five
twenty-one", "tank five two one", "one hundred and twenty one", "building
100 top left", "top right" (the page's building). The tank is the page's
when none is spoken. Species are matched fuzzily (aliases first, then
product titles, then common names; size words ignored; typos within two
letters); an ambiguous or weak match asks "Which fish?" with the top three.

## The Room Board (FisHotel Tools → Clean Room)

Tabs: **Board · To do · Stays · Arrivals · Species · Stats · Events ·
Settings**.

**Board** — Area → Building → Level grid of tank cards (occupants with
species · size · live · day N · badge; empty tanks dimmed; coming-soon buildings
greyed), a header per building with its system gallons ("205 gal
system"), its schedule, the count in QT and the last dose per medication
(a system dose or a tank dose in that building, with its time), the last
copper reading, the to-do count and what is still due today (printed on
the card — a tooltip is invisible on a tablet) per tank, area and species
filters, a *Front Desk* link per tank, and in the summary the groups for
sale, those for sale but still in the Spa, those without a sale date
(linked to Arrivals) and those that **need identifying** (linked to the
Stays tab filtered to them); the first chip of the summary is the day's
count ("8 things to do →", to the To do tab), and under the two areas
the **Temporary** area: *Set up a temporary tank*, the free slots as a
row of chips, a card per setup — its name, kind, purpose, schedule and
own day, its fish with their own QT day, its last reading, to-do and
last dose, and **Schedule… · Edit… · Log · Take down** — and the
*History* of the setups taken down (see *Temporary tanks*). **To do** —
everything due today as one list by water system (see *To do*). **Stays** — every open
stay (species with its size and "needs identifying", live / arrived, check-in, day, state, cleared, on sale —
the effective date with "from the batch" / "own date", or "Sale date not
set" with the suggestion —, tank, supplier / order ref linked to its
order) with Lost / Shipped / Count / Cleared… / Sale date… (the group's
own date: pre-filled with it, else empty when the batch has a date — OK
then keeps the batch's —, else the suggestion; empty = the batch's; the
date it already has writes nothing) / Move… / Coming Soon / **Identify…** (*Correct fish…* on a named fish: the real fish, its size, or still unknown) / Edit (species, size, product, check-in date, supplier,
order ref, landed cost, a note — a changed species or size is the same
correction as Identify…), a *For sale now* and a *Needs identifying* filter, closed stays
with a toggle, a CSV and an XLSX export with the formula-cell guard.
**Arrivals**, **Species** and **Stats** — the workbook replacement, below.
**Events** — the latest 200 stay events and 100 tank-log entries in plain
words (a dose row reads like the toast — "Metronidazole 5.1 g · Building
500, whole system" — and a log on a building is listed under "Building
500" rather than a tank), with a per-row **Undo** on the stay events (a
compensating event, no actor or age guard, refused a second time; tank
logs and doses have none). **Settings** (`manage_options`) — the numbers,
the schedule templates (a picker, add / rename / delete / make default,
the steps editor per template with its **Used for** words, Reset to that
template's seed), the layout
editor (each building's schedule and system gallons beside its name and
status; the Temporary building's slots, with *Add a slot*), the box rates, **Sizes** (the standard list and the sizes in use
on products), **Box shares** (the remembered rows, each editable and
forgettable), **Import workbook**, the learned aliases, *Print
tank labels*.

## Temporary tanks

"I need something that I can set up a Temp tank for hospital tanks and
such. They will be something I need to set up for all sorts of reasons.
Sometimes they are clean, sometimes they are for QT fish that just need
help." A **slot** (TEMP 1 · 901 …) is a place with a laminated label; a
**setup** is what stands in it for now. The label always opens whatever
is set up in that slot — and "Nothing is set up here — Set up" when it
is free.

**Setting one up** — Board → *Set up a temporary tank*, the phone's tank
picker, or a free slot's own label (that slot, no picker):

- the **slot** — the first free one unless another is picked;
- a **name** and a **note** (both optional);
- **What is it for?** — the words the treatment schedules carry as
  choices, plus *Something else* (free text). It is stored as the
  setup's purpose and leads to the treatment (below);
- the **kind**, asked and never assumed: **Quarantine** (for fish still
  in quarantine or under treatment) or **Clean** (for fish that are
  already cleared). It is fixed for the life of the setup — take it down
  and set it up again to change it — because every move remembers the
  setup it went into or came out of;
- the **gallons** (blank = not known; the Dose sheet then has no
  suggestion);
- the **treatment** and its **start date** (today by default; from a
  year back to 60 days ahead — a tank can be prepared before it starts).

**What it is for → the treatment.** Every schedule template has a
**Used for** field (Settings → Treatment schedules): short words, commas
between — "bacterial infection". Only **Trifecta** comes with one
(*bacterial infection*: it is the antibiotics schedule); the others are
left empty for the owner to fill, and nothing is ever guessed. Choosing
a word offers the template(s) carrying it first — whatever its case —
and picks the first; a word no template carries offers none and says so
("No schedule is marked "fin rot" yet"); any template, or **No
treatment, just watching** (the daily feeding alone), can be chosen
directly. Whatever is picked is shown **before anything is saved**:
each step with the days it falls on and the medication it names. The
words live in their own option (`fishotel_cleanroom_template_tags`),
are saved with the template's steps, follow a rename and go with a
delete; a word that was removed stays removed.

**Its own schedule.** The chosen template's steps are **copied** into
the setup. From then on the two are separate lists: the tank's steps can
be changed for this tank alone — on the phone step by step (−1 day /
+1 day, Remove, Add a step), on the Board as a small table — without
touching the template, and a later edit of the template never changes a
tank that is already running. A step's "where" does not apply: the tank
is its own place.

**Its own clock.** A temporary tank's to-do comes from its own steps and
counts days from **its treatment start date** (day 1) — not from a
fish's check-in and not from a building. A treatment that has not
started yet shows "starts <date>" and nothing due. The treatment runs
whether or not the fish have been moved in yet; the feeding is listed
only while fish are in it. The start date, the name, the purpose, the
gallons and the note can be edited while it runs (*Edit…* on the Board).
A fish's own quarantine day, check-in, cleared and sale dates are
untouched by sitting in a temporary tank: the Board's card shows "QT day
8" beside the tank's "day 3".

**Its own water.** Readings, doses and ticks on a temporary tank are
that tank's alone: a copper reading there says nothing about another
tank, a dose is "this tank only" with the setup's gallons, and nothing
logged on a building reaches a slot.

**The rules for fish** are in *How … runs the room* above: a quarantine
temporary tank behaves as a Spa tank, a clean one as a Clean Room tank.
What a slot *was* when a group moved is kept on the move itself
(`from_setup_id` / `to_setup_id` → that setup's kind), so the same slot
can be quarantine this month and clean the next without rewriting
anybody's cleared date.

**Taking it down.** When the tank is empty, *Take down* closes the setup
and frees the slot. With fish in it, it is refused and the screen says
to move them out first. The setup is kept in the **History** (Board →
Temporary → History; *Log* on a running one): its name, purpose, kind,
gallons, who set it up and took it down and when, **the treatment as it
was run** — every step with the days it was ticked on (by a tick, by a
dose naming its medication, by a copper reading) —, the doses and the
copper readings with the day of the tank's own clock, the tank notes,
every tick, and the **groups that passed through** (in, out, and where
to). The next setup in that slot starts clean: the old setup's to-do,
doses and readings never show on the new one — every log entry on a
slot carries the id of the setup that stood there.

## To do — one list for the day, by water system

"There isn't really single tanks except the hospital tanks … Everything
else are systems when it comes to medication and such, as they are all
connected." The **To do** tab of the Board, and the same list on the
phone (*Today* on the tank picker).

- **One block per quarantine building** — the whole shared system: its
  name, gallons, schedule and the day(s) its groups are on — with fish
  or without (an empty system says "nothing is due"); then **one block
  per temporary tank** that is set up, marked *Temporary*, with its
  kind, purpose, gallons, schedule and own day; then any Clean Room
  building that has something due (the feeding).
- In a building's block **every due dose, test and water change appears
  once for the system**, never once per tank: worked out from the groups
  in that building with the same rules as a tank's own to-do, one pass
  per distinct day. When the groups are on different days and that makes
  different steps due, each step is listed once and says whose it is
  ("for the day 2 group").
- **It is ticked once for the whole system.** A step is done when the
  day's log of the building — the entries on the building itself and on
  any of its tanks — holds a tick of it (here, or on any tank's own
  screen), a dose naming the same medication, or, for the copper test, a
  copper reading taken on any tank of the building. Each tank's own
  screen and the Board then show it ticked too: one truth, wherever it
  is ticked. A temporary tank is its own water — its lines are its own.
- **The feeding really is per tank**, so it collapses to one line —
  "Feed — 7 tanks with fish" (and "· 3 left") — that opens to the tanks
  and can be ticked all at once (*All fed*) or tank by tank.
- **Each line says** the step, the medication and the dose it names when
  it names one — a rate in the label is worked out for the water it goes
  into ("40 mg/gal = 8.2 g for 205 gal"); a medication with a rate on
  the Dose sheet gets that suggestion, worded as one ("suggested 5.1 g
  for 205 gal"); never a number the step and the sheet do not already
  give — and the step's note.
- **At the top**: how many things are left ("8 things left to do of
  11"), "All done" when it is, and the copper flags, worded as the tank
  screen flags them: a last reading below the floor ("Building 500 · Cu
  2.1 — below the floor"), a copper test still due ("… copper reading
  due — last one 2.4, 26 h ago") and overdue when the last reading is
  two days old or there never was one. Only a block with a copper test
  on its list today can be flagged: a hospital tank on antibiotics is
  not "low" for having no copper.

### What other sections read

```php
if ( function_exists( 'fishotel_cleanroom_todo' ) ) {
	$todo = fishotel_cleanroom_todo();
}
// or, always safe:
$todo = apply_filters( 'fishotel_cleanroom_todo', null );
```

Both answer `null` while the section's tables do not exist (and the
filter stays null while the section is off); otherwise
`{ left, total, all_done, flags, text, url }` — the lines still open and
in all, whether something was due and nothing is left, the number of
copper flags, one sentence ("Tanks: 3 things to do" / "Tanks: all done"
/ "Tanks: nothing due today") and the To do tab's URL. Asking reads the
room (the queries the Board runs), so ask where the answer is shown; the
two hooks themselves cost nothing on a request that never asks.

The **Today** section shows it: the Clean Room hooks that section's
`fishotel_today_lines` filter and adds one line — `{ key:
'clean-room-todo', text, detail ("2 copper flags"), url, count, tone
('todo' / 'done' / 'bad') }` — for a user with `manage_woocommerce`, on
a day with something due. With the Today section off nobody asks; with
the Clean Room off there is no line.

## Supplier orders (Arrivals)

What the workbook's "Recieved (N)" sheet held: one **order** per
delivery (ref `YYYY-N`, supplier, origin, order and received dates,
status draft → ordered → received → closed, the Spa building it lands in,
the cost mode and the money: tariffs, domestic flight, import & re-ox
fees, credit, the markup for the suggested price and the "worth it"
margin threshold) with its **lines** (species — picked from the species
list and the products, or typed, or ticked **??** for a fish nobody
knows yet —, its **size**, ordered, received, per box or bags, the
**box** it came out of and the **percent of a box** it took, the
supplier's price, what it is worth at FisHotel's price, DOA,
survived, the Spa tank). The costing replicates the sheet exactly
(`class-costing.php`, pure):

- **box mode** — fraction = the line's percent of a box ÷ 100 when one
  is typed (see *Percent of a box*), else received ÷ per box (the
  line's, else the species list's, else 24; 0 when nothing was received
  either way) — every older and imported order keeps that rule and its
  numbers; fees per box =
  (flight + import) ÷ Σ fraction; landed = received × price × (1 +
  tariffs ÷ Σ(received × price)) + fraction × fees per box — tariffs by
  goods value, the flight and import fees by box space;
- **bag mode** — units = bags when typed, else received; per bag =
  (flight + import + tariffs) ÷ Σ units; landed = units × price + units ×
  per bag;
- per line: landed per fish = landed ÷ received; suggested price = per
  fish × markup (4) to the nearest 5; profit = received × worth − landed;
  margin = profit ÷ landed; worth it when the margin is above the
  threshold (2.75); revenue = worth × survived; DOA cost = DOA × price;
- per order: total = Σ landed; grand total = total − credit; revenue; up /
  down = revenue − grand total; fill = Σ received ÷ Σ ordered; survival =
  Σ survived ÷ (Σ received − Σ DOA), undefined until a survivor count is
  known; DOA rate = Σ DOA ÷ Σ received; the sheet's DOA cost = Σ DOA ×
  price + Σ DOA × fee rate — in box mode the fee rate is a *whole box's*
  fees, a carry-over from the bag version, so it is labelled and the
  per-fish figure (fees per box ÷ per box) stands beside it;
- every division is guarded (an undefined ratio shows "—"); full float
  precision inside, rounding only on display and when a figure is
  stored.

**Goes on sale** — a card at the top of a saved order: the day every
group checked in from this order goes on sale (see *Sale dates*).

**Check in received fish** creates the stays — one per line that received
fish and has no stay yet — through the same `Store::create_stay()` +
`record_event()` path the Front Desk uses: an `arrival` of the received
count on the order's received date (today when empty; never in the
future) into the line's tank, else the first empty active Spa tank of
the order's building, else any empty Spa tank, else the first Spa tank
of the building, else the first Spa tank; `source = order`, the line id,
its size, its "needs identifying" flag, the supplier, the ref and the
landed cost per fish filled; then a `loss`
of the DOA count noted `doa`. A line with a stay is skipped, so a second
click creates nothing; the order becomes *received*. From then on the
line's figures are **live**: survived = arrived − every loss (any
reason), sold = the shipped events, DOA = the losses noted `doa` (undos
honoured) — shown read-only on the line, which also cannot be removed
and keeps its figures on re-save. Lines without stays (imported history,
or fish not checked in) keep the typed survived / DOA numbers.

The **Suggest** button next to the import & re-ox fees fills boxes ×
(per box + re-ox) from Settings → *Box rates* for the order's supplier
and origin (a row with no origin stands for every origin of that
supplier) — on request, never on its own. The box rates and the pick
lists start empty; nothing is seeded in code — the workbook import fills
them from the Data sheet.

## Sale dates

"They should be ready to sell the day I mark them ready to sell." A
group's **effective sale date** is chosen, never computed:

1. its **own date**, when it has one — set in the *Clean room* box on its
   product page (saved with the product's Update button), with *Sale
   date…* on the Stays tab, or under Dates on the phone;
2. else its **batch's date** — the *Goes on sale* date of the supplier
   order it was checked in from (its order line → the order's
   `sale_date`; a group split off another keeps that order line, so it
   keeps the batch);
3. else **none**: the group is not for sale, and the screens say *Sale
   date not set* with "suggested <date>" = check-in + the *Suggested sale
   date* setting (28 by default) — a hint, and what a tap on it fills a
   picker with; it is never saved by itself.

A group is **for sale** (ready) once its effective date is set, today —
the site's own date, so it flips at local midnight — is that date or
later (the date itself counts), and it is not closed. Cleared or not
does not matter any more; a group for sale while still in the Spa (not
cleared) is flagged **still in the Spa** on the badge, the Board (a
summary chip), the Stays tab, the order page's card, the product box,
the phone, and the state column of the stays CSV / XLSX ("ready (still
in the Spa)"). The Board's building line counts the way its summary
does: a group for sale counts as for sale, not as in QT.

**Where to set them.** *Arrivals → the order → Goes on sale*: a date
picker (empty until chosen, the suggestion beside it), Save and Clear —
its own nonce'd admin-post form, `manage_woocommerce` like every order
edit, an invalid date refused with nothing changed, and every change
written to the Health log through `fishotel_log` ("Sale date of
supplier order 2026-7 changed by <login>: not set → 2026-10-30"). Under
it: how many groups still in tanks the date applies to (and how many of
those are still in the Spa), and the groups with their own date (product
linked to its edit screen, tank, date, "still in the Spa").
The Arrivals list's *On sale* column shows each order's date, "not
set", or "N of M groups have their own date". **The product page**: one
row per open stay of the product — the supplier order (linked), tank,
check-in, live count, a date picker holding the effective date labelled
"from the batch" or "own date", and *Use the batch date* on a group with
its own date. Only a group whose own date really changes gets an event;
a picker left as shown (on the batch's date, say) writes nothing; an
invalid date is ignored and named in a notice after the save; emptying a
picker that shows the batch's date changes nothing, and the notice says
to change or clear it on that supplier order.

**Sensible dates only.** Every place a sale date is chosen — the order
card, the product box, the Stays tab, the phone — takes a date from
2000-01-01 to two years after today (`Replay::SALE_DATE_MIN`,
`sale_date_max()`; the pickers carry the same `min` / `max`). A date
field turns a typed "26" into the year 0026, which would put a group on
sale at once; anything outside is refused with nothing changed. Saving
the date a group (or a batch) already has writes no event and no log
entry ("nothing changed").

**What is stored.** A group's own date is the history it always was: a
note event `sale_ready:YYYY-MM-DD` (`sale_ready:` removes it), mirrored
in `fh_stays.sale_ready_date`, so the Replay, the Events tab and the
undo see every change. The batch's date is the column
`fh_orders.sale_date` (its changes are in the Health log). Nothing on the
storefront moves by itself: stock, visibility and purchasability stay
manual; *Coming Soon* on the Stays tab still sets the product's
countdown to the effective date on request.

## Sizes

One list of fish sizes — **the one the WooCommerce product variations
use**, so an order line, a stay and a product all say the same thing.

- **Where it comes from.** The global product attribute whose name or
  slug contains "size" (the theme reads any attribute key matching
  /size/ as the size — e.g. `pa_size` with term slugs like `2-3-5`): its
  terms, in the order the attribute is set to (custom, name, numeric
  name or term id), labelled as stored — the labels are in inches. When
  several attributes match, Settings → Sizes picks the one in use
  (`size_attribute`); when none exists, Settings offers a plain list to
  type (`size_list`), so the feature works without the attribute. The
  attribute and its terms are read straight from the tables — with or
  without WooCommerce loaded — and **only** on this section's own admin
  screens and the Front Desk, never on an ordinary front-end request.
- **Settings → Sizes** (`manage_options`, its own nonce'd save): the
  standard list — read-only when it comes from the attribute, with a
  link to Products → Attributes —, which attribute is in use, and the
  read-only **Sizes in use on products** report: every distinct size
  value on the variations of the fish categories (attribute terms *and*
  values typed on a product), how many products use each, flagged when
  it is not in the standard list — what needs cleaning up. Nothing in
  the section changes a product or a term.
- **What is stored.** A size is its slug plus a snapshot of its label
  (`size_slug`, `size_label`) on the order line and on the stay. A
  stored size that is no longer in the list still displays, marked *not
  a standard size*, and stays selectable on the row that has it; any
  other unknown slug is refused (the Stays tab's Edit, the phone,
  Identify…) or leaves that order line without a size and says so.
- **Where it shows.** A Size dropdown on the order line, the Front Desk
  Arrival sheet, the Stays tab's Edit and the Identify panel; the
  check-in carries it from the line to the stay, a split to the sister
  stay; beside the species on the Board, the Stays tab, the phone's
  rows, the order page and the product box; a `size` column in the stays
  and orders exports. The voice parser keeps ignoring size words: a
  spoken arrival has no size.

## Percent of a box

"I only get one or two of a fish so for me to have to judge how many
would have fit into the box would be super challenging. Instead, when I
unload these fish out of the box it would be easier to simply guess
about the % the fish took up in the box." Per order line (box mode
only):

- **Box #** — which physical box the line came out of (optional, 1–999);
- **% of box** — the percent of ONE box the whole line took: 25 = a
  quarter of a box; decimals allowed; above 0 and at most 400, so a line
  may span boxes. A mistyped percent or box number refuses the save
  before anything is written; blank and 0 mean none.

In box mode a line's fraction of a box is its percent ÷ 100 when one is
typed (and the line received fish), else received ÷ per box as before;
everything downstream is unchanged — fees per box = (flight + import) ÷
Σ fraction, tariffs by goods value. **The fees are split by each line's
share of the total, so the percents only need to be right compared to
each other**: guess everything a little high and the costs come out the
same. That holds once every line that received fish has a percent: a
line without one still weighs in by its per-box count, so on a
half-filled order one high guess does move the split. Bag mode is
untouched, and so is every order without a percent.

The **Boxes** card under the lines adds the percents up per box number
with a state — *under*, *about full* (90 to 110) or *over* —, the lines
with a percent but no box number apart, the boxes the percents come
to (the sum ÷ 100) beside the order's Boxes field, and — while some
lines have a percent and others none — *received lines without a %: N*
(still on their per-box count). It is a check on the
guesses, never an input of the costing; the page recalculates it as the
numbers are typed, exactly as the server renders it (one fixture tests
both sides).

## Remembered box shares

Every box from every location is a different size, so what a fish takes
is remembered **per location**: supplier + origin + fish (the species
list id when the line has one, else the lower-cased name) + size → the
percent of a box **one fish** takes, how many order lines it came from,
and when (`fh_box_shares`).

- **Written** when an order is saved (and when a fish is corrected, see
  below), for every line of a box-mode order that has a percent,
  received fish and an identified fish: value = percent ÷ received. The
  latest *order* wins — by the order's received date, else its order
  date —, so re-saving an old order never overwrites a newer guess. The
  count goes up once per order line: a line that changes fish or size,
  loses its percent or is removed gives its count back, and a share
  nobody counts in any more is deleted.
- **Read** when a line of an order from the same supplier and origin has
  a fish (and size) but no percent: a **suggested N% (from K orders)**
  chip — per fish × the received count (the ordered count while nothing
  is received) — that fills the field on a tap and is never saved by
  itself. A draft / ordered order shows **How full**: the boxes the
  remembered shares predict for the ordered counts, and how many lines
  have no remembered share yet.
- **One fish, one share.** A fish picked from the species list on one
  order (kept under its id) and typed on the next (its name) is the
  same fish: a line whose own key has no share counts in — and is
  offered — the share kept for that name under the other key, never a
  second row.
- **Settings → Box shares** lists the rows, each editable and
  forgettable. A value typed there is dated today and belongs to no
  order line, so only an order dated *after* it replaces it: saving
  that day's order again does not put the old guess back.
- A fish that still **needs identifying is never remembered**.

## Unknown and mislabeled fish

"… when some of these fish come in I have no idea what they are. A lot
of times they are mislabeled and I have to go back and correct what I
actually got."

**Unknown.** A supplier order line and a stay can be flagged *needs
identifying*. Its name is then "??" followed by an optional description
— "?? small wrasse" — so every screen, toast and export that prints a
species prints the "??". It is set with the **??** box on an order line
(what is typed is then what the fish looks like), with **Unknown fish
(??)** on the phone's Arrival sheet, or by typing a name with a leading
"??" on the order page, in the Stays tab's Edit or on the phone. An
unknown fish gets its tank, count, size, percent of a box and landed
cost like any other; the check-in and a split keep it unknown; only a
remembered box share is never written for it.

**What the label said.** An order line keeps `label_name`: what the
supplier's label or invoice called the fish. It is set from the line's
name the first time the line is corrected to a different fish (case and
spacing aside), and never overwritten after that — a second correction,
or marking the fish unknown, leaves it. A fish that **arrived unknown**
had no usable label: the first time it is identified, its "??" name is
kept in the label's place, so correcting that first guess later is not
read as a mislabel — such a "??" label is never shown and never
counted. The order page shows a label under the line (*arrived labeled:
X*) and the orders export carries the column as stored.

**One correction.** *Identify…* / *Correct the fish…* — on the Stays
tab, on a group's sheet on the phone, and on an order line that received
fish — takes the real fish from the same search the Arrival sheet uses
(a product, a species-list fish, or a typed name) and its size, and in
one go:

1. the **stay** gets the name, the product link and the size, and is no
   longer flagged (the fish it already is, typed again rather than
   picked — to set its size, say — keeps its product link);
2. its **order line** gets the species-list id, the name, the scientific
   name and the size, is no longer flagged, and keeps its old name as
   `label_name` (first time only);
3. the stay gets a **note event** — "identified: ?? small wrasse → Lined
   Wrasse · 2-3"" ("marked unknown: …" when the result is a fish that
   still has to be identified) — so the Events tab shows who corrected
   what and when. A change of size alone writes none;
4. the **remembered box share** of the corrected fish is written, and
   the one counted under the wrong fish given back.

The same correction runs when the species or the size is changed in the
Stays tab's **Edit**, and when the species, the ?? box or the size of a
line **that already became a stay** is changed in the order editor's
grid. A line without a stay is the order as typed: retyping its species
there records no label (*Correct the fish…* does) — except that ticking
?? on a named line that received fish keeps the name as what the label
said, so a fish marked unknown while unloading still counts as
mislabeled once it turns out to be something else; and unticking it on
a line that arrived unknown keeps the "??" name in the label's place.

**A page that was already open.** A page loaded before a fish was
corrected elsewhere — the phone, the other tab, another window — still
holds the old fish, and saving it must not undo the correction. The
order page (per line) and the Stays tab's Edit post back the token
their row was rendered with: the name, the "??" flag, the size and, on
a stay, the product link (`Identify::seen()`). When the row as stored is
no longer that:

- an **order save** keeps the stored fish on that line — name,
  species-list id, scientific name, flag and size — and saves the rest
  of the row; no stay is touched, no event written, the label and the
  remembered share stand. The reply says how many lines kept a fish
  corrected after the page was loaded, names a fish that was typed over
  one as *not saved*, and the page reloads on the lines as they are
  (the notice is repeated there). *Check in* and *Correct the fish…*
  save the page first, so they are covered too;
- the **Stays tab's Edit** saves its other fields and leaves the fish,
  its size and its product as corrected (the reloaded tab says so);
  when the fish was changed on that stale row as well, nothing is saved
  and the reply asks for a reload.

A save's reply carries the tokens of the lines as saved, so the page
that stays open is not stale after its own save — except for a line
that kept a corrected fish, which stays stale on that page until it
reloads (Save pressed twice keeps the correction twice). A row posted
without a token (the importer, an older page) is taken as typed.

**A group that was split** has several stays on one order line, and
**the line follows its stays only when they all agree**. While a sister
stay is still a different fish, a correction changes that stay alone and
the line gets a note — "[part of this line is Lined Wrasse — stay #12]",
one per stay that is not what the line says — and no label and no
remembered share: its percent is still the whole line's. Once the last
sister is corrected to the same fish, the line follows and the notes go.
A correction made on the order line itself corrects the line and every
stay that still carried the line's old name — the one step for a split
group that is all the same fish, and the way to reach a sister stay that
is already closed —; a sister already identified as something else keeps
its own (and stays noted). So identifying one half of a split group on
the phone leaves the line and the other half as they were — nothing is
identified that nobody looked at — and the toast points at the other
half and at the order line.

**Where it shows.** A *N need identifying* chip on the Board (linked to
the Stays tab filtered to them), a *Needs identifying* filter and marker
on the Stays tab, "?? N to identify" on the Arrivals list and by the
order's status, the marker on the order line, "needs identifying" on the
phone's rows — and the "??" itself wherever the species is printed.

**Mislabeled, in the Stats.** A line that received fish is *mislabeled*
when its label names another fish than the one it turned out to be;
*Stats → By supplier* and *By origin* show them with their rate over the
lines that received fish, and count the unknown fish **not identified
yet** apart (a fish is not mislabeled until it has a name). A line
corrected back to what its label said is not mislabeled, and neither is
a fish that arrived unknown, however often its name is corrected.

## Species

The workbook's Fishlist as a table: name (unique), scientific name,
group, fish per box (24 by default), FisHotel's price, a competitor's
price / site / date, reef-safe, max size, notes, and an optional product
link. A species may or may not be a product — most are never listed.
The Arrival screen's search, the voice parser's candidates and the order
editor read the products and the species rows **as one list, deduped by
lower-cased name**: a species row linked to a listed product, or named
like one, folds into that product (its scientific name fills a missing
one, a different common name joins the product's common names, its per
box and price ride along); any other row is an entry of its own, with its
product link (which may point outside the fish categories) or none. The
candidate cache is dropped by every product, species and alias save. The
Species tab: search by a prefix of the name or the scientific name (any
word), group filter, 50 per page, inline edit, add, *Link product* (a
product search over the fish categories), delete — refused while an
order line names the species —, the open-stays count (by product when
linked, else by name).

## Stats

Per year (the order's received date, else its order date): the per-order
rows, then the same figures two ways, both shown and both exported:

- **weighted** — Σ ÷ Σ over every order: fill = Σ received ÷ Σ ordered,
  survival = Σ survived ÷ (Σ received − Σ DOA) over the orders whose
  survivors are known, DOA = Σ DOA ÷ Σ received, landed, revenue, up /
  down;
- **as the spreadsheet averaged them** — the UNWEIGHTED average of each
  batch's fill / survival / DOA rate over the batches that received
  anything (each rate over the batches where it is defined: a batch
  whose survivors are not typed yet is not in the survival average, as it
  was "not in the formulas yet"), and *total fish through QT* = Σ
  survived;

and the weighted figures by supplier, by origin and by species group
(the species row's group; "(no group)" otherwise). By supplier and by
origin also say how the labels held up: **Mislabeled** — the lines that
received fish whose `label_name` differs from the fish they turned out
to be, with the rate over the lines that received fish — and, counted
separately, **Not identified yet** (the unknown fish still waiting for a
name).

## Exports

XLSX everywhere, CSV where it is one table: orders + lines (two sheets;
the CSV is the lines), species, stats (Orders, Totals — weighted beside
sheet-style —, By supplier, By origin, By group; the CSV is the orders),
stays (beside the existing CSV). Columns added since are appended last,
so a sheet built on the older positions keeps reading them: `size` and
`needs_id` on the stays; `size`, `box_no`, `box_pct`, `needs_id` and
`label_name` on the orders' Lines sheet; `received_lines`, `mislabeled`,
`mislabeled_rate` and `not_identified` on the stats' Orders, By supplier
and By origin sheets (and four rows at the end of Totals). All are admin-post downloads with a
nonce and `manage_woocommerce`, named `fishotel-<thing>-<Ymd-His>.xlsx`.
The writer (`class-xlsx.php`) needs nothing but the zip extension: text
is written as inline strings — never a formula, whatever it starts with
—, numbers as numbers, nulls as empty cells; sheet names are cut to 31
characters with the forbidden characters replaced. Without the zip
extension the same request sends a CSV and says so on the next page.

**Print tank labels** opens an admin-only printable page: one 3.5 × 2 in
label per active tank with the number large, area / building / size
small, and a QR of the Front Desk URL generated as inline SVG by the
section's own encoder (`class-qr.php`: byte mode, error correction M,
versions 1–6, verified module-for-module against python-qrcode). Every
temporary slot gets a label too — "TEMP 1" large, "901 · Temporary ·
…" small, free or set up — so a set can be laminated once and reused:
a slot's label always opens whatever is set up in that slot.

## Importing the workbook

Settings → **Import workbook** (`manage_options`) brings the
FisHotel-YEAR spreadsheet onto the site — once for the history, again
whenever Jeff wants the latest Fishlist prices.

1. **Upload** the .xlsx / .xlsm (at most 20 MB; `wp_handle_upload` with an
   explicit mimes list and `test_form` off). The file lands in
   `wp-content/uploads/fishotel-private/` under a random name — a folder
   created with an `.htaccess` that refuses every request (both Apache
   generations) and an empty `index.html`; on nginx it is only as private
   as the server's rules, which is why the name is random and the file
   lives there ten minutes at most (a one-off cron event scheduled with
   the upload deletes it even when nobody comes back to the preview).
   fileinfo calls every OOXML spreadsheet an .xlsx, which WordPress reads
   as a mismatch for an .xlsm, so the upload hands an .xlsx / .xlsm name
   whose content fileinfo calls a spreadsheet its type back (a renamed PHP
   or text file is still refused).
2. **Preview.** The reader (`class-workbook.php`: ZipArchive + XMLReader,
   cached values only, formulas never evaluated, the `PW` sheet never
   opened, no DTD or external entity ever loaded, every part read through
   a bounded stream — at most 50 MB whatever the zip's directory claims,
   refused when its length does not match the claim — and a sheet or a
   shared-strings table of more than 300 000 values refused, so a small
   crafted file cannot exhaust the memory) and the mapper (`class-import-map.php`: `Fishlist`, `Data`,
   `FishSheetStock`, every `Recieved (N)` with its `QT Tanks (N)`, columns
   found by header text, labels by text) turn the file into species,
   orders with lines and logs, box rates and the stock list; the panel
   shows what a run would create, update or skip per entity, the stays
   estimate and the reader's warnings (a species on a batch that is not
   in the Fishlist, a label not found, a sheet skipped). The preview
   waits ten minutes in a per-user transient; a file the reader cannot
   open is refused with its reason.
3. **Import now** with the options — *create stays for batches received
   in the last N days* (default 45, 0 = none), *the Spa building those
   batches land in* (the 205-gallon system by default) and *import the
   treatment logs* — runs `Import::run()`:
   - **species** upserted by lower-cased name: the sheet fills name,
     scientific name, per box, FisHotel's price, the competitor's price /
     site / date; a value the sheet lacks (a per box of 0 included) never
     blanks one the site has, the site's spelling of a name stays; group,
     reef-safe and max size come from the stock list (by scientific name,
     then by common name) where the row has none;
   - **box rates** merged into Settings → Box rates by supplier + origin
     (the sheet's numbers win for a pair both have);
   - **orders** created by ref `YYYY-N` (the batch number and the QT start
     year — without a QT date the year in the uploaded file's name, else
     the site's year) with the supplier, origin, cost mode, tariffs / flight / import
     fees / credit, the QT start date as the received date, the sheet's
     schedule name and a note naming the sheet — status *received* within
     the stays cutoff, *closed* for older history, *ordered* without a
     date; their lines with the species id by name (an unknown fish keeps
     its name only), ordered / received / per box or bags / price / worth
     / DOA / survived as typed, the landed totals cached through the
     costing; **an existing ref is never overwritten**. The workbook
     carries no size, box number, percent of a box, "??" flag or label
     name, so an imported line has none — whatever the sheet calls a
     fish — and remembers no box share;
   - **stays** for the batches within the cutoff, through the same
     `Orders::check_in()` the Arrivals button uses — an arrival on the
     batch date into the line's tank, else the first empty Spa tank of the
     chosen building, else any empty one, a `doa` loss, the seam fields
     filled — with `source = import` on the stay and its events. The
     workbook carries **no sale date**, so none is set: the preview and
     the result count the groups in tanks without one and name their
     orders, to set *Goes on sale* on after the import;
   - **treatment logs** (when ticked): every row of a batch's `QT Tanks`
     log becomes a building-level log — "Dosed X" / "Added X" a `dose`
     (value = the numeric part of the amount, note "<Medication>
     <amount>" with the medication by alias — the one named first —, the
     text cut so the marker always fits the 2000-byte note), "Tested
     Copper" a `copper`
     reading, anything else a `note` with the text — dated as in the
     sheet in the site zone (noon without a time; a row without a date is
     skipped with a warning), on the Spa building the sheet's "Tanks"
     column names: a number is the building with that system volume
     (205 → Spa 500, 100 → Spa 600; without a match, 205 is the building
     with the most tanks and 100 the other one), "Both" is one log per
     building, blank is the order's building.
4. The file is deleted after the import (also on *Discard*, by the
   one-off cron event ten minutes after the upload, and by the cleanup
   the next upload runs), and the result — counts per entity, the
   problems, the warnings — shows on the panel.

**Idempotent.** Running the same file twice updates the species and
creates nothing else: orders are skipped by ref, lines that already have
a stay by `Orders::check_in()`, logs by the `import:<ref>#<row>` marker
each carries at the end of its note (kept out of sight on the Events
tab); the box rates merge to the same rows.

**The privacy rule.** The workbook holds Jeff's business: species,
prices, supplier rates, batch figures, log lines, passwords. None of it
is in this repository — no seed, no fixture, no test, no comment names a
species, a price or a batch; the test workbook is synthetic
(`tests/fixture-builder.php`, invented names and numbers in the real
layout) and the only process knowledge taken from the sheet is the five
treatment schedules Jeff asked to have in the template. The data reaches
his site only through the file he uploads, and the mapper never opens a
sheet it does not know by name.

## What the fish page will read

```php
if ( function_exists( 'fishotel_cleanroom_product_facts' ) ) {
	$facts = fishotel_cleanroom_product_facts( $product_id );
}
// or, always safe:
$facts = apply_filters( 'fishotel_product_qt_facts', null, $product_id );
```

Both answer **null** for a product with no open stay here (the theme then
falls back to the product's own meta, exactly as when nobody answers);
otherwise:

```
live_total   fish alive across every open stay of the product
ready_total  of those, in stays that are for sale today (their sale date has come —
             cleared or still in the Spa)
in_qt[]      stays still in the Spa: stay_id, qty_live, checkin_date, cleared_date, day,
             sale_ready_date, sale_date_source, sale_ready (bool), tank
cleared[]    stays in the Clean Room: the same fields
next         the stay that can ship first (one for sale, else the earliest sale date;
             a stay without one sorts last): checkin_date, cleared_date, sale_ready_date,
             sale_date_source, sale_ready, day, tank ('Clean Room · Building 100 ·
             Tank 121'), qty_live — or null
```

**For theme authors (0.44):** `sale_ready_date` is now `string|null` — the
stay's effective sale date (its own, else its batch's, Y-m-d) or **null**
when none has been chosen; it used to be a date always computed as
check-in + 28 days. Treat null as "not for sale yet, date to be
announced". `sale_date_source` is `'own'`, `'batch'` or null. `sale_ready`
is true from the sale date on whether or not the stay is cleared.

Most fish are pre-sold custom orders and never listed; when one is listed
it is Coming Soon with a countdown (the Coming Soon section's
`_fh_release_datetime`). The Stays tab's **Coming Soon** button sets that
release moment to the stay's effective sale date at 09:00 site time —
only when the product exists and the stay has a date; a stay never
assumes one does, and nothing sets it on its own. Photos are stock
photos; nothing here touches them. The lottery for contested fish is the
Lottery Draw section.

## How an order becomes stays

Every stay carries `supplier`, `order_ref`, `landed_cost` and
`order_line_id` (all nullable; editable on the Stays tab) and a `source`
of `order` (the Arrivals button) / `import` (the workbook import). The
check-in fills them (see *Supplier orders*): one stay per order line
through `Store::create_stay()` + an `arrival` event
(`Store::record_event()`), exactly as the Front Desk does — the Replay,
the facts and the board need nothing new, and the workbook import creates
its orders, species and stays through the same Store methods.

## Data

Eleven tables, created by dbDelta on first boot (gated by the autoloaded
`fishotel_cleanroom_db_version` option, 1.6, so a booted site pays no
query and no `SHOW TABLES`) and on activation, the layout seeded in the
same step; the version is written only once every table and every
column added since a table's first version (`Store::REQUIRED_COLUMNS`:
the orders' `sale_date`, the sizes, the box number / percent / share key,
`needs_id`, `label_name`, the buildings' `own_water`, the logs'
`setup_id`, the events' `from_setup_id` / `to_setup_id`) is really
there — and the buildings' `area` column really accepts `temp`
(`Store::REQUIRED_ENUM_VALUES`) —, so a
failed CREATE or ALTER is retried on the next boot; everything kept on
deactivation. An upgrade from 1.3, 1.4 or 1.5 keeps every row; when the
gate opens, the Temporary building and its six slots are added once
(only while the layout has no own-water building), and nothing else
about the permanent buildings, tanks, stays, events and logs changes.

- `{prefix}fh_buildings` — id, area (`clean` / `spa`; `temp` since 1.6, for the own-water building only), number (unique), name, status, sort, notes, schedule (the template the building runs, '' = the default), system_gallons (NULL = the sum of its tanks; the seed and the 1.2 backfill give Spa 500 205 and Spa 600 100), own_water (1.6: 1 = each tank its own water — the Temporary building, whose tanks are slots)
- `{prefix}fh_tanks` — id, building_id, number (unique), level, position, size_label, gallons, status, sort (a slot: level 0, position = the slot, no size or gallons of its own)
- `{prefix}fh_tank_setups` (1.6) — what is set up in a slot: tank_id (the slot), open_tank_id (= tank_id while it stands there, **unique** — one open setup per slot, whichever phone is faster —, NULL once taken down), slot, name, purpose, kind (`quarantine` / `clean`), gallons, template (the name its steps were copied from, '' = none), start_date (day 1 of its own clock), steps (the copied steps, JSON — its own schedule), note, created_by / created_at, updated_at, closed_by / closed_at
- `{prefix}fh_stays` — the cached state: product_id, species, size_slug + size_label (1.4), needs_id (1.5: the fish still has to be identified), qty_arrived, qty_live, checkin_date, cleared_date, sale_ready_date (the stay's OWN sale date, from its note events; the effective one is computed on read with the batch's), closed_date, status, tank_id, source, order_line_id (→ its batch; keyed since 1.5), supplier, order_ref, landed_cost, notes
- `{prefix}fh_stay_events` — the truth: stay_id, type (arrival · move · loss · clear · sold · count · note), qty, from/to tank, from_setup_id / to_setup_id (1.6: the setup that stood in a slot the event came out of or went into, NULL for a rack tank — the Replay reads that setup's kind as the area, so a slot's history keeps its meaning), note, event_at (UTC), actor, source. Who a fish IS is identity, not history: a correction writes the stay's columns and leaves a note event ("identified: <old> → <new>") as its trace
- `{prefix}fh_tank_logs` — per tank, or per building: copper readings, doses (value = the amount, note "<Medication> <amount> <unit>"), test / water-change / feed tick-offs, tank notes; a building-level entry has tank_id 0 and building_id set and counts for every tank of the building (the day's to-do); since 1.6 a building is read as one water — the last copper reading of every tank of a shared building is the newest taken anywhere in it, on the building or on any of its tanks, and each reading says which tank it was taken on; an imported entry carries `import:<ref>#<row>` at the end of its note; setup_id (1.6) ties an entry on a slot to the setup that stood there — a slot's to-do, last reading and last doses read only its present setup's entries, and never a building-level one
- `{prefix}fh_aliases` — spoken words → product / species
- `{prefix}fh_species` — the species master: name (unique), sci_name, group_name, per_box, my_price, online_price, online_site, online_date, reef_safe, max_size, product_id, notes
- `{prefix}fh_orders` — a supplier order: ref (unique), supplier, origin, order_date, received_date, sale_date (the batch's "Goes on sale", NULL = not set; added in 1.3), status, boxes, cost_mode, tariffs, domestic_flight, import_fees, credit, markup, worth_threshold, schedule, building_id, notes
- `{prefix}fh_order_lines` — its lines: order_id, species_id, species, sci_name, needs_id (1.5), label_name (1.5: what the label called a corrected fish — the "??" name of one that arrived unknown —, NULL = never corrected), size_slug + size_label (1.4), qty_ordered, qty_received, per_box, box_no + box_pct (1.4), bags, unit_price, worth, qty_doa, qty_survived, landed_total (a cache of the costing), stay_id, tank_id, share_key (1.4: the remembered share the line counts in), sort, notes (the "part of this line" notes of a split group live here)
- `{prefix}fh_box_shares` (1.4) — the remembered shares: share_key (unique: location + fish + size), location_key (supplier + origin), supplier, origin, fish_key (`s:<species id>` or `n:<name>`), species_id, species, size_slug, size_label, pct_per_fish, order_count, last_line_id + last_date (the line and the order date the value came from), updated_at

Four options: `fishotel_cleanroom_settings` (with `size_attribute` and `size_list`, the source of the standard sizes), `fishotel_cleanroom_protocol`
(`{ default: name, templates: { name: steps[] } }` — an older bare steps
array is read as the "14 Day CM" template next to the other four seeds),
`fishotel_cleanroom_box_rates` (rows of supplier, origin, per box,
re-ox; empty until typed or imported) and
`fishotel_cleanroom_template_tags` (template name → its "Used for"
words; absent until a word is first saved — until then only Trifecta
has one, from the code — and not autoloaded). Two per-user transients carry the
import's pending preview (with the file's path, ten minutes) and its last
result.

`Store::record_event()` appends the event and replays the stay
(`Replay::state()`); the row is only a cache, and the reply says when the
events add up to fewer than zero fish (`overdrawn`). A negative quantity
reverses (the undo, note `undo of #<id>` — which is how the same undo is
refused a second time), a `clear` carries its date in the note
(`cleared:YYYY-MM-DD`; an old-shape one without a note is dated by its
`event_at`) and with −1 unsets the cleared date, a note of
`sale_ready:YYYY-MM-DD` sets the stay's own sale date (`sale_ready:`
removes it), a note of `checkin:YYYY-MM-DD`
corrects the check-in, a move's note is its reason. Day number =
floor((today − check-in) / 86400) + 1 over the two calendar dates, never
stored.

## Files

```
clean-room/
├── class-clean-room.php    section info, load, boot, menu, activate / deactivate, the globals
│                           fishotel_cleanroom_product_facts() + the fishotel_product_qt_facts filter,
│                           fishotel_cleanroom_todo() + the fishotel_cleanroom_todo filter, and the
│                           line the Today section shows (fishotel_today_lines)
├── class-store.php         the ONLY $wpdb file: eleven tables, seed, stays / events / logs, record_event
│                           (append + replay), splits, species search + candidates, aliases, facts,
│                           the species master, the orders and their lines, stay figures, the box
│                           shares, the readers of the size attribute and the variations' sizes, the
│                           slots and their setups, the room (every tank with its to-do + the day's
│                           list by water system)
├── class-layout.php        PURE: numbering rule, seed, shares-water, titles / labels, grouping, the
│                           slots of the own-water building (901 … 909, "TEMP 1 · 901")
├── class-temp-tanks.php    temporary tanks: kinds and what a slot behaves as, "Used for" words → the
│                           templates carrying them, the copied schedule and its changes, the tank's
│                           own clock, set up / edit / take down, a setup's history, the switch behind
│                           the phone's and the Board's writes (pure, or takes the store as a parameter)
├── class-todo.php          PURE: everything due today as one list by water system — a block per
│                           building and per temporary tank, each step once per system, the feeding
│                           as one line, the copper flags, the overlay onto every tank's own to-do,
│                           a tick, the count other screens read
├── class-replay.php        PURE: events → state, day math, the chosen sale date (own > batch > none,
│                           the suggestion, ready, "still in the Spa"), splits, compensating events
├── class-protocol.php      PURE: the named treatment templates (the five seeds, the option's
│                           shape, steps_for a building) → a tank's daily to-do; the medications,
│                           their aliases and dose rates, the dose suggestion and the dose tick
├── class-parser.php        PURE: a spoken / typed phrase → structured preview
├── class-qr.php            PURE: byte-mode QR encoder (EC M, v1–6) → inline SVG
├── class-costing.php       PURE: the order sheet's landed-cost maths (box / bag), a line's percent
│                           of a box, the per-box summary, display helpers
├── class-sizes.php         PURE: the standard sizes (the product attribute that matches "size", else
│                           a typed list), a stored size that left the list, the sizes-in-use report
├── class-box-shares.php    the remembered shares: keys, what a saved line writes and gives back, the
│                           suggestion and "How full" (takes the store as a parameter)
├── class-identify.php      unknown ("??") and mislabeled fish: the fish a correction names, the one
│                           correction of a stay and its order line, what the label said, the split
│                           rule, the token that keeps a page loaded earlier from undoing a
│                           correction (takes the store as a parameter)
├── class-stats.php         PURE: per-order rows, weighted and sheet-style figures, groupings, the
│                           mislabeled / not-identified-yet counts
├── class-species.php       PURE: a species row normalised, the merge into the candidate list
├── class-orders.php        header / lines from a form, live figures of a line, the check-in
│                           (takes the store as a parameter, like Front_Desk::perform())
├── class-xlsx.php          a dependency-free .xlsx writer (inline strings, never a formula)
├── class-workbook.php      PURE: a dependency-free .xlsx / .xlsm reader (cached values only, the PW
│                           sheet never opened, a zip-bomb guard)
├── class-import-map.php    PURE: the FisHotel-YEAR layout → species, orders + lines + logs, box rates,
│                           stock — columns and labels found by text, never by letter
├── class-import.php        the mapped workbook into the Store (species upsert, box rates, orders,
│                           the recent batches as stays, the treatment logs), idempotent;
│                           preview() pure; takes the store as a parameter like Orders::check_in()
├── class-settings.php      options, sanitised AJAX saves (settings, the schedule templates and
│                           their operations, box rates, sizes, box shares), layout editor, aliases
├── class-front-desk.php    the phone page (query var fh_front_desk), the label-login cookie life,
│                           its AJAX (act / parse / suggest) with perform() = the testable switch
├── class-admin.php         the Room Board page, CSV, labels, stay edits + move + undo + identify, Coming
│                           Soon release, the product meta box and its sale dates (save_post_product)
├── class-admin-workbook.php  the Arrivals / Species / Stats tabs, their AJAX (an order's save, check-in,
│                           "Correct the fish…"), the Boxes card, the XLSX / CSV exports, the batch's
│                           "Goes on sale" (admin-post)
├── class-admin-import.php  Settings → Import workbook: the private folder, upload → preview → import
├── class-admin-temp.php    the To do tab, the Board's Temporary area, the temporary tanks' history,
│                           and the one AJAX action behind their writes (fishotel_cleanroom_temp)
├── css/front-desk.css, front-desk-temp.css, js/front-desk.js   the phone screen (inlined into the page)
├── css/clean-room-admin.css, js/clean-room-admin.js
├── css/temp-tanks-admin.css, js/temp-tanks-admin.js   the Temporary area and the To do tab
├── views/front-desk.php, board.php, stays.php, arrivals.php, order.php, species.php, stats.php,
│          events.php, settings.php, import.php, tabs.php, labels.php, meta-box.php,
│          identify-panel.php (the Identify… panel of the Stays tab and the order page),
│          board-temp.php (the Board's Temporary area), todo.php (the To do tab),
│          temp-history.php (the setups taken down, or one in full), settings-temp.php (the slots)
└── tests/                  headless PHP + node tests (see tests/README.md)
```
