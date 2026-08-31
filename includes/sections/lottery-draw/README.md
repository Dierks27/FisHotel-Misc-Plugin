# Lottery Draw

Allocates contested group-order fish by a seeded lottery that members can
recompute themselves, in their own browser, without trusting us and without
access to our database.

## The rules

- Everyone who requests a fish goes in the hat. No guaranteed slots, no
  first-come advantage.
- Uncontested fish (requests ≤ slots) are not drawn — everybody gets theirs.
- Requesting N fish gives you N tickets. You can win 2 of the 3 you wanted
  and lose the third.
- Group species are drawn as groups. 120 anthias at a group size of 5 is
  24 slots, not 120.
- No backup picks. One list, one draw.
- Losers are ranked into a waitlist by the same draw, used if a winner backs
  out or a fish does not make it.

## Architecture

**The draw algorithm exists in exactly one implementation: `js/draw.js`.**

There is no PHP version, and there must never be one. Two implementations
can disagree — one integer overflow apart is enough — and the moment they
do, published results stop matching what members compute in their browser
and the verifiability claim collapses without anyone noticing.

| Layer | Responsibility |
| --- | --- |
| `js/draw.js` | The draw. Runs on the admin screen to produce a result, and on the public page to recompute it. |
| `js/lottery-admin.js` | Runs `draw.js` on the admin screen, previews the result, posts it for storage. |
| `js/lottery-public.js` | Runs `draw.js` on the public page, compares its result to the published one, paints the banner. |
| `class-importer.php` | Validates and normalises pasted payloads. Refuses results, refuses anything but a draft. Pure — no database. |
| `class-store.php` | Persistence, lifecycle, immutability. Structural validation of a result (is it a rearrangement of the tickets? does it fit the slots?) — never a re-draw. |
| `class-admin.php` | Admin screens; nonce and capability checks on every write. |
| `class-frontend.php` | Public page, `[fishotel_draw]` shortcode, raw JSON endpoint. |

## Importing a draw

**FisHotel Tools → Lottery Draw → Import from JSON** takes a whole draw as a
pasted payload, so nobody retypes fifteen fish and fifty entrant lines into
textareas. Validate renders a preview; the import button stays disabled until a
payload passes, and re-enables only against text that was actually validated.

The importer supplies **inputs**. Two rules make that real, and both are
refusals rather than clean-ups:

- **A payload containing `winners` or `waitlist` is refused outright** — named
  by fish, never silently stripped. If results could be imported, anyone with
  admin access could paste a hand-picked winner list and publish it behind a
  green "verified in your browser" banner. That is worse than no verification,
  because it launders a rigged draw as an honest one. Results only ever come
  out of `draw.js` running against a committed seed. The check is recursive and
  presence-based: an empty `"winners": []` anywhere in the payload fails it.
- **Import only ever writes a draft.** A committed draw has locked its entrants
  on purpose; a published one is evidence. No force flag.

Accepted shape (`seed` and `seed_source` may be empty — they are set at commit
time; `id` and `title` are optional when importing into an existing draft):

```json
{
  "id": "rvs-2026-08",
  "title": "RVS Philippines — August 2026",
  "seed_method": "external",
  "seed": "",
  "seed_source": "",
  "fish": [
    {
      "name": "Yellow Coris Wrasse",
      "sci": "Halichoeres chrysus",
      "stock": 2,
      "groupSize": 1,
      "entrants": [
        { "name": "twosixpax", "want": 1 },
        { "name": "Sashaka",   "want": 1 }
      ]
    }
  ]
}
```

Entrant order is kept exactly as pasted — it is posting order, and members check
it against the thread. Duplicate handles within one fish merge case-insensitively,
keeping the position and spelling of the first appearance and summing their
`want`. Names are trimmed and otherwise left alone: handles are case-sensitive to
their owners.

### Draw vs. rank only

The preview labels every fish, because the distinction is not obvious later:

- **Draw** — tickets exceed slots. Genuinely contested; produces a waitlist.
- **Rank only** — tickets fit inside the slots. Everyone wins, and the draw runs
  purely to fix the priority order for a fish lost later. **Its waitlist comes
  back empty, and that is correct, not a bug.**

A fish where one person holds every ticket raises a warning, not an error — a
draw against yourself is theatre, and including it should be deliberate.

Each import appends to the draw's import log (who, when, how many fish and
tickets), shown on the draw's admin screen. The log lives in post meta beside
the payload rather than inside it: the payload is the public verifiable
document, and who pressed Import is site bookkeeping.

Export already exists — it is the public `/draw/{id}/json/` endpoint.

## The algorithm

`cyrb128` hashes the seed string to a 32-bit integer; `mulberry32` turns that
into a deterministic generator; a descending Fisher-Yates shuffle draws the
ticket list. Slots are `floor(stock / groupSize)`; the first `slots` tickets
out are the winners, the rest are the ranked waitlist.

The RNG is seeded **per fish**, from `seed + '::' + lowercased fish name`.
That is deliberate: correcting a typo in one fish's entrant list must not
reshuffle every other fish in the same draw.

Do not change the PRNG, the seeding string, the ticket order, or the shuffle
direction. Any change re-rolls every previously published draw.

## Seed integrity

A seed we picked after seeing the entries proves nothing — we could grind
thousands of seeds and publish the one with the winners we liked, and nobody
outside could tell. The seed must be fixed before it can be tested against
the entrant list. Two supported methods, chosen per draw:

- **Commit early** — generate the seed and post it to the thread before
  entries close. `seed_published_at` is stamped at commit and shown publicly.
- **External randomness** — announce a public value nobody controls that does
  not exist yet (a named Powerball draw, a Bitcoin block height), then enter
  it once it does. `seed_source` is stored and shown publicly.

## Lifecycle

`draft` → `seed_committed` → `published`

- **draft** — seed and entrants editable.
- **seed_committed** — seed and entrants locked, commitment time stamped.
- **published** — results stored. **The record is immutable.** No edits to
  seed, entrants, or results, through the UI or by direct POST; a published
  draw cannot be deleted either. Corrections are published as a new draw that
  references the old one, and both stay online.

## Public page

Shortcode `[fishotel_draw id="rvs-2026-08"]`, plus a permalink at
`/draw/<id>/` and the raw payload at `/draw/<id>/json/`.

The page ships the stored payload to the browser, re-runs the draw from it,
and compares. Green means the visitor's own browser reproduced the published
result. Red means it did not, and the diff is shown — never suppressed. A
tampered result rendered green would be worse than no verification at all,
because it would launder a rigged draw as a fair one.

## Tests

```
node includes/sections/lottery-draw/tests/draw.test.js
node includes/sections/lottery-draw/tests/banner.test.js
php  includes/sections/lottery-draw/tests/importer-test.php
```

See `tests/README.md` for the two acceptance tests that need a running site.
