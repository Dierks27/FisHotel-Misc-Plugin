# Med listings

A one-time cleanup tool. It turns every medication and medicated-food
listing into a plain product listing: what it is, what is in it, its sizes
and its price. Nothing about what it treats, no dosing, and no links to the
calculator or the QT pages. The products stay for sale.

**Why.** FDA warning letters to aquarium-medication sellers quote the
seller's own website ("treats ich", "kills flukes", dosing, a link to a
dosing guide) as proof that a product is a drug. The model listing has a
name, the ingredients, a size and a price.

**Where.** FisHotel Tools → Med listings. Anyone who can manage WooCommerce
can use it. The section arrives switched off. Switch it on on the Dashboard.

**Nothing runs by itself.** Opening the page changes nothing. Nothing
changes until you press Apply, and everything Apply does can be undone on
the same page. There are no timers and no e-mails. The section's only
lasting effect on visitors is the redirect list (step 5), and that keeps
working while the section is switched off.

## The six steps (one page)

1. **Backup.** First make a Cloudways backup (the application → Backup and
   Restore → Take Backup Now). Then press **Download backup (CSV)**. You get
   every field the page would change on every product, with what it says
   now, plus the categories and tags. The page keeps its own record of every
   old value as well, so Undo works without either backup.
2. **Find.** The page lists:
   - every product in the Medications category or a category under it (the
     theme's own test for a medication), or in the other slugs the theme
     reads as a medication or medicated food;
   - every other product whose name, short description or description
     names a drug from the list.

   Products in the categories you leave out are skipped (the fish and
   invoices by default). The **Word lists** box holds every list the page
   uses: drug names, diseases, what-it-does words, dosing, medicine-type
   labels, link addresses, Water Chemistry words, the storage line, the
   supplier line, your own brands and the left-out categories. Use one word
   or phrase per line. Capitals do not matter, and a space and a hyphen
   count as the same. A line between slashes is a pattern.
3. **Review (a dry run).** Each product gets a table: the field, what it
   says now with the claim words marked in colour, and what the page
   proposes. You can change any proposal right in its box, and untick any
   row you want left alone. **Download report** saves `med-cleanup-report.md`,
   built from the table as it stands, your own edits included. It lists the
   product ID, name, field, current text and proposed text, then what was
   changed, the flags, the redirects and the page check.
4. **Apply.** Use **Apply this product** or **Apply all ticked**. The
   products go one at a time. Every row says whether it was applied, and
   why not when it was not. **Undo this product** and **Undo all** put back
   exactly what was there.
5. **Categories and tags.**
   - Categories named after what a medicine does (Antiparasitic,
     Antibacterial, Antifungal, Dewormer, Quarantine Essentials, or any
     category whose name is on the lists) give way to **Medicated Foods**,
     **Powders & Chemicals** and **Water Chemistry**. You confirm each row.
   - Disease and medicine-type tags are removed, and so are the values of
     the theme's "Treats" / "Use In" attributes.
   - Every old address sends visitors on with a 301.
   - Each change has its own Undo.
6. **Verify.**
   - **Clear caches**: Breeze clears all its cached pages, then Varnish
     through Breeze. The theme's calculator/scheduler link list, the
     WooCommerce product caches and the object cache are cleared too. When
     Varnish cannot be cleared from here, the page tells you to purge it in
     Cloudways.
   - **Check the pages**: the site opens each page logged out, the way a
     visitor gets it. That means every medication product page, every
     medication category page, and the tool pages (Dosing Calculator, QT
     Scheduler, quarantine guides). Each page passes or fails, with the
     offending words and where they were found.
   - If the site cannot open its own pages, the page says so. Open the
     listed addresses in a private window yourself.

At the bottom of the page, **Report** sums up what changed, what is
flagged and how many redirects there are.

## How a proposal is written

The description is written from the **product's own facts only**:

| Line | From |
|---|---|
| Name | the product name |
| "Flake food containing kanamycin, metronidazole, and nitrofurazone." (a food) or "Contains: metronidazole 99%+." (anything else) | the theme's **Active ingredient(s)** field (`_fishotel_active_ingredient`), as typed. Ordinary capitalised words go lower case; abbreviations such as TMP stay. |
| "Available in omnivore or herbivore flake, 2 oz to 3 lb." | the product's variation attributes. An attribute whose values are all sizes (oz, lb, g, kg, ml, l, fl oz, tablets, count …) gives the sizes, smallest to largest. Any other variation attribute of a food gives the food base (Form, Reef safe and Brand never). Claim attributes are skipped. |
| "Keep sealed, store cool and dry. Not for human consumption. Keep out of reach of children." | the storage line (a setting) |
| "Ships direct from our supplier." | only when the theme says Everything Aquatic ships it (`fishotel_is_ea_fulfilled_product()`) |

**Ingredients are never guessed.** When the field is empty, the line
carries `[[fill in the active ingredients — confirm against the EA product]]`.
That row starts unticked and **cannot be applied while the blank is
there**. Fill it in from the label and the row ticks itself.

The short description is the "containing" line plus the "Available in"
line. It also feeds the WooCommerce product data (JSON-LD), the product
cards and Yoast's fallback.

Other fields:

| Field | Proposal |
|---|---|
| Tagline above the title, calculator anchor, "Treats" row, "Use in" row | emptied |
| "Best for", "Nutrition", "Additives", food source, purchase note (printed in order e-mails), Yoast / Rank Math titles and focus keyphrase | the sentences or title parts with a claim taken out (only when there is one) |
| Yoast / Rank Math meta, Facebook and X descriptions | the name plus the short description (only when there is a claim) |
| Extra checkout tick | proposed empty but **left unticked**: you decide |
| Tags | disease, claim and medicine-type tags come off, ingredient tags stay |
| An attribute named Treats / Use / Use in / Dosage / Directions …, or with a claim value | taken off the product. If the product's variations are built on it, it is flagged instead. |
| Variation descriptions (they sit in the page source) | claim sentences out |
| Picture alt text | the product name |
| Picture caption | claim sentences out |
| Active ingredient | never changed. A claim inside it is flagged. |

A field the tool already changed, and that nobody has touched since, is
not proposed again.

## For you to decide (never changed by the page)

- **Product names that are claims.** The name is flagged with a neutral
  name to suggest. The claim words are taken out. When too little is left
  (one word, or only words like General / Duo / Trio), the name is built
  from the ingredients and the form, e.g. "General Cure" → "Metronidazole +
  Praziquantel Powder". Old orders keep the old name.
- **Missing or unclear ingredients.**
- **Reviews and questions** that name a disease or dosing. Each one comes
  with a link and a **Hide** button that only acts when you press it. Hide
  holds the review for moderation. **Show it again** puts back the status
  it had. Reviews do not show on the product page, but WooCommerce puts
  their text into the page's product data, which search engines read.
- **Other brands.** A brand term that is not one of yours, and products
  sold through Amazon.
- **Pages and posts that link to the products**, with the tool and QT
  guide pages marked.

## Apply and Undo

- **Only WordPress and WooCommerce functions:** `wp_update_post`,
  `update_post_meta` / `delete_post_meta`, `wp_set_object_terms`, the WC
  product object for attributes, and the term functions for categories.
  Never SQL.
- **The record.** Before a field is written, its old value is recorded in
  the option `fishotel_med_listings_ledger` (not autoloaded). The record
  also keeps whether the field existed at all, so Undo removes a meta value
  that was not there before instead of leaving it empty. A second Apply on
  the same field keeps the first old value.
- **Apply skips a row** when:
  - the field changed since the page was opened ("reload and look again");
  - the text still holds a `[[ … ]]` blank;
  - the post does not belong to the product (the product, its variations
    and its pictures do);
  - or the field is not one the tool writes.
- **Undo** writes each old value back, but only where the field still holds
  what the tool wrote. A field someone changed since is left alone and
  named.
- **Global attributes** are recorded with their values' slugs too. If step
  5 removed those values, Undo makes them again. Undo works in either
  order (product first or categories first).
- **What WooCommerce rewrites itself.** When a product with variations is
  saved, WooCommerce rewrites each variation's attribute summary (the
  variation's own excerpt). That text is WooCommerce's, not yours.

## Categories (step 5) in detail

- **The new categories stay under Medications.** The theme decides "this
  is a medication" (the ornamental-use notice, the checkout tick, the terms
  record) by the Medications parent (`helpers.php`
  `fishotel_med_is_med_product()`). Every product the step moves is also
  put in Medications itself, because the theme's other test
  (`fishotel_is_medication_product()`) reads the slugs `antibacterial` /
  `antiparasitic` / `dewormer` by name.
- **"Sort its products by type"** puts each product in:
  - Medicated Foods when it is a food;
  - Water Chemistry when its name or ingredient has a Water Chemistry word
    (copper …);
  - Powders & Chemicals otherwise.

  The old category is then removed. Its address goes to the category you
  choose, by default the one most of its products went to.
- **"Merge into"** sends all its products to one category.
- **"Rename"** keeps the same category with a new name, slug or
  description. The theme's own "Medicated Food" becomes "Medicated Foods"
  with its slug kept (the theme reads it). The Medications category keeps
  its name and slug; only its description is cleaned. It can never be
  removed.
- **The neutral categories.** Ones the step made are removed again by Undo
  once they are empty.
- **Undo** puts a removed category back with its name, slug, description
  and parent, and puts its products back in it. WordPress gives it a new id.
- **Yoast's own category SEO** (option `wpseo_taxonomy_meta`) is not
  touched. Check it in Yoast.

## Redirects

The list is in the option `fishotel_med_listings_redirects` and is shown
on the page. Each entry has a **Take off the list** button.

**What is caught.** A visit to an old address is sent on with a 301:
- the full form (`/product-category/medications/antiparasitic/`), the
  short form WordPress also answers to (`/product-category/antiparasitic/`)
  and `?product_cat=antiparasitic`;
- `/page/N/` and the feeds;
- for a renamed category, the categories under it as well.

**Where it goes.** The category the products went to, at its address as
it is now.

**Product pages.** The shop's product addresses carry the category
(`/shop/medications/antiparasitic/<product>/`). A product address that went
missing because of a changed category is sent on to the product.

**When the old address is used again.** If a category is later given the
old slug again, the redirect leaves that address alone.

**Always on.** The hook runs early on `template_redirect`, and also while
the section is switched off (`boot_disabled()`).

## Not done here (the theme release, or you)

**The theme release.** Theme code prints wording that no stored data
controls:
- the "From the Pharmacy / Therapeutic-grade" badge;
- the trust strip "The same meds we use in QT";
- "Fulfilled by EverythingAquatic, our trusted medication partner";
- the calculator link (also from the theme's reverse map);
- the "I Agree — Show Me the Pharmacy" button;
- the Medications archive "TREATS" filter;
- the calculator and QT Scheduler product links in their JavaScript;
- the seeding of the Treats / Use In attributes and the old subcategories.

The page check shows these until the theme release is installed.

**For you.**
- The footer Tools column (Medication Dosing, QT Scheduler) is on every
  page.
- The ornamental-use notice text mentions "proper dosing" (a theme
  setting).
- E-mail templates. ShipTracker's e-mails print no product text.
- Old orders keep their item names and the checkout ticks that were
  accepted.

## Files

| File | What |
|---|---|
| `class-med-listings.php` | the section: info, boot, menu, redirects while off |
| `class-words.php` | the word lists and plain lines (settings) |
| `class-finder.php` | finds words, highlights, cleans a line (pure) |
| `class-composer.php` | writes the plain listing, suggests neutral names (pure) |
| `class-proposals.php` | what to change and flag for one product (pure) |
| `class-inventory.php` | which products; a snapshot of each; pages that link to them |
| `class-fields.php` | reads / writes one field through WordPress |
| `class-ledger.php` | Apply, Undo, review hide/show, the record |
| `class-terms.php` | step 5 |
| `class-redirects.php` | the 301 list and its hook |
| `class-verify.php` | cache clearing, page fetch, the HTML check (pure scan) |
| `class-admin.php`, `views/page.php`, `js/`, `css/` | the page, its buttons and the downloads |

Options:
- `fishotel_med_listings_words` (your own lists);
- `fishotel_med_listings_ledger` (the record);
- `fishotel_med_listings_redirects`;

plus a one-hour transient per person with the pages the check may open.
The check opens only those addresses.

## Tests

Headless, from the plugin root (see `tests/README.md`):

```
php includes/sections/med-listings/tests/composer-test.php
php includes/sections/med-listings/tests/proposals-test.php
php includes/sections/med-listings/tests/apply-undo-test.php
php includes/sections/med-listings/tests/redirects-verify-test.php
node includes/sections/med-listings/tests/report.test.js
```
