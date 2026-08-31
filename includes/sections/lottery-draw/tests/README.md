# Lottery Draw — acceptance tests

## Automated

```
node includes/sections/lottery-draw/tests/draw.test.js
node includes/sections/lottery-draw/tests/banner.test.js
```

`draw.test.js` covers acceptance tests 1–7 and 9 against `js/draw.js`
directly: determinism, seed sensitivity, per-fish RNG independence, ticket
counts, group slots, uncontested fish, oversubscription, and tamper
detection.

`banner.test.js` covers the visible half of acceptance test 9 — it runs
`js/lottery-public.js` against a minimal DOM shim and asserts that a
tampered payload paints the red MISMATCH banner and shows the diff, and
that every failure mode fails closed rather than green.

## Manual — the two that need a running site

### Test 1 (browser half) — same seed, any browser

Open a published draw's public page in Chrome, Firefox, and Safari (and on
a phone). The banner must be green in every one. The draw uses `Math.imul`
and 32-bit integer arithmetic precisely so this holds; if one browser
disagrees, stop and treat the results as unverified.

### Test 8 — a published draw cannot be edited

1. Publish a draw.
2. Reopen it under **FisHotel Tools → Lottery Draw**. There must be no
   editable seed field, no add-fish form, no remove buttons, and no delete
   button.
3. Bypass the UI. From a browser console logged in as the admin, POST
   directly to `admin-post.php`:

   ```js
   fetch( ajaxurl.replace( 'admin-ajax', 'admin-post' ), {
     method: 'POST',
     credentials: 'same-origin',
     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
     body: new URLSearchParams( {
       action: 'fishotel_draw_add_fish',
       draw_id: '<published-draw-id>',
       _wpnonce: '<a valid fishotel_draw_add_fish nonce>',
       fish_name: 'Tampered',
       fish_stock: '1',
       fish_group_size: '1',
       fish_entrants: 'somebody'
     } )
   } );
   ```

   Expected: the draw is unchanged and an error notice says the draw is
   published and cannot be changed. Repeat for `fishotel_draw_publish`,
   `fishotel_draw_commit_seed`, `fishotel_draw_remove_fish`, and
   `fishotel_draw_delete` — all must refuse. Without a valid nonce, or as a
   user lacking `manage_woocommerce`, every one must fail outright.

### Test 9 — tamper with the database, get a red banner

This is the test that matters most.

1. Publish a draw and confirm the public page shows the green banner.
2. Edit the stored payload directly in the database — swap two names in a
   `winners` array, or promote a waitlisted name:

   ```sql
   SELECT meta_id, meta_value FROM wp_postmeta
    WHERE meta_key = '_fh_draw_payload';
   -- edit the JSON, then:
   UPDATE wp_postmeta SET meta_value = '<edited JSON>' WHERE meta_id = <id>;
   ```

3. Reload the public page. Expected: the red **MISMATCH** banner, plus a
   diff showing published vs. recomputed winners for the affected fish.

If a tampered payload ever renders green, the feature is worse than not
having it. Stop and fix that before anything else.
