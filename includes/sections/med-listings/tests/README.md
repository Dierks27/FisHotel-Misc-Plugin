# Med listings: tests

These run headless, with no WordPress and no database. Run them from the
plugin root:

```
php includes/sections/med-listings/tests/composer-test.php        # the composer and the claim finder
php includes/sections/med-listings/tests/proposals-test.php       # find, proposals per field, flags
php includes/sections/med-listings/tests/apply-undo-test.php      # apply/undo round trips, categories, refusals
php includes/sections/med-listings/tests/redirects-verify-test.php # the 301 list, its hook, the page check
node includes/sections/med-listings/tests/report.test.js          # med-cleanup-report.md
```

The supporting files:
- `wp-stubs.php` is an in-memory WordPress: options, posts, meta, terms,
  WC products with attributes, comments. It works the way WordPress does
  where the section relies on it: it unslashes, tells "not there" from
  "empty", and takes a deleted term off its products.
- `seed.php` makes a small invented shop full of claims: a medicated flake
  food shipped by the supplier, a powder with a claim name and no
  ingredient field, a product outside the categories that names copper,
  and a fish that must stay out.

Every product, word, review and address in these tests is invented.

## By hand, on a test copy of the shop (never the live one first)

You need WooCommerce and the FisHotel theme. Switch the section on
(FisHotel Tools → Dashboard). Add a couple of invented medication products
full of claims: "treats ich", dosing, a Treats attribute, disease tags, a
Yoast description, a review that names a disease.

1. **Nothing changes on opening.** Open FisHotel Tools → Med listings.
   Compare a product's edit screen before and after: it is the same.
2. **Backup.** **Download backup (CSV)**: one line per field with text,
   plus the categories and tags.
3. **Find.** Your products are listed, each with its reason. A fish whose
   text says "copper-free" is not listed.
4. **Review.**
   - Claim words are coloured.
   - The description proposal has the name, the ingredients from the
     Active ingredient(s) field, the sizes and the storage line.
   - A product with no ingredient shows a `[[ … ]]` blank and its row is
     unticked. It cannot be ticked until the blank is filled, then it
     ticks itself.
   - The extra checkout tick row is unticked.
5. **Report.** **Download report** gives `med-cleanup-report.md`, with
   your edits in it.
6. **Apply one product.** Its rows turn grey with "Applied". On the
   storefront:
   - the description, short description, tagline and Treats/Use In rows
     are clean;
   - the tags are only ingredients;
   - the "Additional information" Treats attribute is gone.

   In the page source, the JSON-LD description is the short description.
7. **Edited since.** Change a field on the product's edit screen, then
   press Apply on that row from the already-open page. It says "Changed
   since this page was opened".
8. **Undo this product.** Everything is as before: text, meta, tags, the
   attribute. Check one meta value that did not exist before (a Yoast
   description): it is gone again, not empty.
9. **Undo keeps later work.** Apply, change one field by hand, then Undo.
   That field keeps your change and is named. The rest is put back.
10. **Categories.**
    - **Make the ticked changes.** Antiparasitic etc. are gone. Their
      products are in Powders & Chemicals / Medicated Foods / Water
      Chemistry and in Medications. Medicated Food is now named "Medicated
      Foods".
    - In a private window, the old category address, its `/page/2/`, its
      `/feed/`, `?product_cat=antiparasitic`, an old tag address and an old
      product address (`/shop/medications/antiparasitic/<product>/`) all
      answer 301 to the new place.
    - Switch the section off: the redirects still work.
    - **Undo** each change: the categories and tags are back with their
      products, an empty neutral category made by the step is gone, and the
      redirect list is empty.
11. **Reviews.** **Hide** a flagged review: it is held for moderation.
    **Show it again**: it is approved again.
12. **Verify.**
    - **Clear caches** says what it cleared, and says "purge Varnish in
      Cloudways" when Breeze is not there.
    - **Check the pages**: header and footer words are listed once on the
      home page row. Each product and category page shows Clean or the
      words left, with where they are (page text, title, meta tag,
      JSON-LD, alt text, variation data, link). A tool page fails only on
      a link to a medication product or category.
    - On a site that cannot open its own pages, the check stops and says
      so.
13. **Phone width (390 px).** The page has no sideways scroll.

## What the throwaway rehearsal showed (24 Nov 2026)

This was a copy of the rehearsal site, with its own database and ports,
the theme at 1.27.0 and this branch, plus invented claim-filled products.
It was deleted afterwards.

- **Apply all.** It changed 28 fields on 4 products. The blank row was
  refused.
- **Categories.** Step 5 made 24 changes. All the old addresses answered
  301. That included `/page/2/`, `/feed/`, `?product_cat=`, tags, the
  Treats attribute pages and the moved products' old addresses.
- **The check.** It ran against the site itself. What remained was the
  theme's own code, as expected (badge, trust strip, partner line, modal
  button, archive TREATS filter). Beyond that it found:
  - the flagged review in the JSON-LD;
  - the name "General Cure";
  - the products left with blanks;
  - the product links in the QT guide page, the calculator and the
    scheduler.
- **Undo.** Undoing every category change, then Undo all, gave back a
  database equal to the one before: posts, meta, terms, product terms and
  comments. The only exception was WooCommerce's own variation attribute
  summaries, which it rewrote when the product was saved.
