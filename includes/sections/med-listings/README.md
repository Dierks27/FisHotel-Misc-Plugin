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
   Restore → Take Backup Now): that is the one to restore the whole site
   from. Then press **Download backup (CSV)**, to read and keep. It has
   every field the page reads on every product (empty ones too), each with
   what it says now and, in the last column, its stored value exactly as
   WordPress holds it (JSON: an attribute with its term ids, position and
   flags; a picture address with its old addresses). It also has every
   category, tag and attribute of step 5 with its products, its own
   settings, the menu items pointing at it and the categories under it.
   **The CSV is for reading, not for restoring.** Undo uses the page's own
   exact record (the option `fishotel_med_listings_ledger`) and works
   without either backup.
2. **Find.** The page lists:
   - every product in the Medications category or a category under it (the
     theme's own test for a medication), or in the other slugs the theme
     reads as a medication or medicated food;
   - every other product whose name, short description or description
     names a drug from the list.

   Products in the categories you leave out are skipped (the fish and
   invoices by default), even when one was put in a medication category
   by mistake. The **Word lists** box holds every list the page
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
     offending words and where they were found. A tool page fails when it
     links to a medication product or category, also through an old
     address on the redirect list (the link still reaches the product).
   - The **Home page** row is for information only: its words come from
     your fish service and the header and footer, and are not counted
     against the other pages. Links are what a visitor can follow (`<a>`
     and forms); stylesheets and files under `/wp-content/` are not links.
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

**Ingredients are never guessed.** When the field is empty, the lines
carry `[[fill in the active ingredients — confirm against the EA product]]`
and the product's table starts with one **Active ingredient(s)** box.
Type the ingredients there once, from the label: every blank of that
product (description, short description, search descriptions) fills as
you type and its row ticks itself, and Apply saves what you typed to the
product's own Active ingredient(s) field. A row with a blank left
**cannot be applied**. A field that says the product has none ("None
(test kit)", "n/a") gets no "Contains" line and is flagged instead.

The short description is the "containing" line plus the "Available in"
line. It also feeds the WooCommerce product data (JSON-LD), the product
cards and Yoast's fallback.

Other fields:

| Field | Proposal |
|---|---|
| Tagline above the title, calculator anchor, "Treats" row, "Use in" row | emptied |
| "Best for", "Nutrition", "Additives", food source, purchase note (printed in order e-mails), Yoast / Rank Math titles and focus keyphrase | the sentences or title parts with a claim taken out (only when there is one) |
| Yoast / Rank Math meta, Facebook and X descriptions | the name plus the short description (only when there is a claim) |
| Yoast / Rank Math titles (also Facebook and X) | the title parts with a claim taken out. When the product name goes with them ("General Cure \| …"), the field is proposed empty: **an empty search field is removed**, so Yoast / Rank Math use their own title (name and site name) instead of printing just "FisHotel" |
| Extra checkout tick | proposed empty but **left unticked**: you decide |
| Tags | disease, claim and medicine-type tags come off, ingredient tags stay |
| An attribute named Treats / Use / Use in / Dosage / Directions …, or with a claim value | taken off the product. If the product's variations are built on it, it is flagged instead. |
| Variation descriptions (they sit in the page source) | claim sentences out |
| Picture alt text, picture title | the product name (the title is in the page source and the shop's public product data) |
| Picture caption, picture description | claim sentences out |
| Picture address (its slug) | from the product name; WordPress sends the old address on |
| Description, when the old text gives amounts the new one lacks (net weight, strength, pellet size) | the row says which, so you can add them before applying |
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
  it had. Reviews do not show on the product page (and the theme takes
  their text out of the page's product data since 1.29.0), but an
  approved review's text is still public in WooCommerce's review list for
  apps and search engines (`/wp-json/wc/store/v1/products/reviews`):
  Hide takes it out of there too.
- **Picture file names** with a claim ("trio-flake-kills-ich.jpg"). The
  file name is part of the picture's address on every page that shows it.
  Upload the picture again under a plain name; this page does not rename
  files.
- **Other brands.** A brand term that is not one of yours, and products
  sold through Amazon.
- **Pages and posts that link to the products**, with the tool and QT
  guide pages marked. A product is found by its address, by its slug in
  any address (so a link still found after step 5 changed the address),
  by an add-to-cart link and by its id in a shortcode (`[product id=…]`,
  `[products ids="…"]`, `[add_to_cart id=…]`) or a product block; a
  category by its address and every old address on the redirect list.

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
- **Attribute order.** An attribute put back goes into the place it had
  among the product's attributes, so the "Additional information" list
  reads in the same order as before (several attributes at the same
  position keep their order).
- **What WooCommerce rewrites itself.** When a product with variations is
  saved, WooCommerce rewrites each variation's attribute summary (the
  variation's own excerpt). That text is WooCommerce's, not yours.

## Categories (step 5) in detail

- **Only medication categories.** A category is proposed only when it is
  inside the Medications tree, or has one of the medicine-type slugs
  (antiparasitic, antibacterial, dewormer …), and never when its slug is in
  the left-out list. "Fish Treats" or "Hospital Tank Supplies" elsewhere in
  the shop are never listed. A medicine-type category outside the tree
  starts unticked.
- **Never a fish turned into a medication.** A category that also holds
  products that are not medications (a fish put there by mistake) cannot
  be sorted or merged: its row is greyed out, and the server refuses it
  too. Move those products out by hand first.
- **The new categories stay under Medications.** The theme decides "this
  is a medication" (the ornamental-use notice, the checkout tick, the terms
  record) by the Medications tree (`helpers.php`
  `fishotel_med_is_med_product()`). Every product the step moves is also
  put in Medications itself.
- **"Sort its products by type"** puts each product in:
  - Medicated Foods when it is a food (by its category, its form or its
    name: flake, pellet, gel, "food");
  - Water Chemistry when its name or ingredient has a Water Chemistry word
    (copper …);
  - Powders & Chemicals otherwise.

  The old category is then removed. Its address goes to the category you
  choose, by default the one most of its products went to.
- **Medicated Foods** is made with the slug `medicated-food` (the one the
  theme reads as a food) when it is not there yet; the theme's own
  "Medicated Food" is renamed "Medicated Foods" with its slug kept.
- **Form categories** (Flakes, Pellets, Powders, Liquid) are offered a
  **Merge into** Medicated Foods / Powders & Chemicals, left unticked: the
  Medications page's Form filter reads them, so merged, that filter button
  finds nothing. You decide.
- **What a removed category takes with it is kept.** Before the button the
  row says which menu items point at it and which categories are under
  it. On removal:
  - its menu items are pointed at the category its address goes to (a
    menu label that is a claim is emptied, so the new name shows) instead
    of being deleted by WordPress;
  - the categories under it move up one level, and their old addresses
    get a 301;
  - its own settings (picture, display type, order), the Yoast primary
    category of its products and its Yoast SEO text are recorded.

  Undo puts all of it back on the category it makes again. A
  sub-category that was removed too goes back under it, whichever of the
  two you undo first. WooCommerce's own product count is not taken from
  the record: WooCommerce counts the products again as they go back.
- **Tags.** A disease or medicine-type tag only on medications is removed
  (its page goes to Medications). One that is also on other products (a
  fish) is **only taken off the medications**; the tag and its page stay.
  A tag on no medication is not listed. A tag that stays (an ingredient)
  and a brand on a medication get their description cleaned when it makes
  a claim ("Products that treat …"); brand names are only flagged.
- **The theme's Treats / Use In attributes** are one row each. Once no
  product uses them (step 4 takes them off), the whole attribute goes with
  its values and filter pages — also from WooCommerce's public attribute
  list. Their addresses go to Medications. Undo makes the attribute again
  with the same name, slug and values (a new id), and a product's Undo
  does that by itself when it needs the attribute back.
- **"Rename"** keeps the same category with a new name, slug or
  description. The Medications category keeps its name and slug; only its
  description is cleaned. It can never be removed.
- **The neutral categories.** Ones the step made are removed again by Undo
  once they are empty.
- **Undo** puts a removed category back with its name, slug, description
  and parent, puts its products back in it, and restores what is listed
  above. WordPress gives it a new id.
- **Yoast's own title templates** (`wpseo_titles`) are not touched.

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

**The theme release (FisHotel Theme 1.29.0).** Theme code prints wording
that no stored data controls: the Pharmacy badge and trust strip, the
partner line, the calculator link, the "Show Me the Pharmacy" button, the
"proper dosing" line of the entry pop-up, the Medications archive TREATS
filter, the calculator and QT Scheduler product links, and the seeding of
the Treats / Use In attributes and the old subcategories. Install the
theme first: the page check shows these until it is.

**For you.**
- The footer Tools column (Medication Dosing, QT Scheduler) is on every
  page.
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
php includes/sections/med-listings/tests/review-fixes-test.php
node includes/sections/med-listings/tests/report.test.js
```
