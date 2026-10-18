<?php
/**
 * Snippet: Tracking page [fishotel_tracking]
 * Description: The "Room Service Status" page: a tracking-number form, then the
 *   ShipTracker status, dates and event history for that number. Put
 *   [fishotel_tracking] on a page. Moved from Code Snippets, unchanged.
 * Type: php
 * Scope: global
 * Priority: 10
 * Origin: Code Snippets #33, imported 2026-10-18
 * Default: on
 */

defined( 'ABSPATH' ) || exit;

add_shortcode('fishotel_tracking', function() {
  $tracking = isset($_GET['tracking']) ? sanitize_text_field(wp_unslash($_GET['tracking'])) : '';
  $tracking_clean = preg_replace('/[^A-Za-z0-9]/', '', $tracking);

  // Carrier detection from format
  $carrier_name = '';
  $carrier_url = '';
  if ($tracking_clean) {
    $t = strtoupper($tracking_clean);
    if (preg_match('/^1Z[0-9A-Z]{16}$/', $t)) {
      $carrier_name = 'UPS';
      $carrier_url = 'https://www.ups.com/track?tracknum=' . urlencode($t);
    } elseif (preg_match('/^(94|93|92|95|82|70|14|23)\d{18,20}$/', $t) || preg_match('/^(EA|EC|CP|RA|RB|RC|RR|EE|EJ|EI|LA|LC|LK|LM)\d{9}US$/', $t)) {
      $carrier_name = 'USPS';
      $carrier_url = 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($t);
    } elseif (preg_match('/^\d{12}$|^\d{15}$|^\d{20}$|^\d{22}$/', $t)) {
      $carrier_name = 'FedEx';
      $carrier_url = 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($t);
    } elseif (preg_match('/^\d{10,11}$/', $t)) {
      $carrier_name = 'DHL';
      $carrier_url = 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=' . urlencode($t);
    } else {
      $carrier_name = 'Carrier';
      $carrier_url = 'https://www.google.com/search?q=' . urlencode('track package ' . $t);
    }
  }

  // Try to load shipment from DB
  $shipment = null;
  if ($tracking_clean && class_exists('FST_Shipment')) {
    $shipment = FST_Shipment::get_by_tracking($tracking_clean);
  }

  // Status display + color mapping
  $status_map = array(
    'pre_transit'          => array('label' => 'Label Created',       'tag' => 'Awaiting carrier pickup',          'color' => '#d4a574'),
    'in_transit'           => array('label' => 'In Transit',           'tag' => 'On its way to your reef',           'color' => '#5b8db8'),
    'out_for_delivery'     => array('label' => 'Out for Delivery',     'tag' => 'The driver is in your neighborhood', 'color' => '#e8c082'),
    'delivered'            => array('label' => 'Delivered',             'tag' => 'Welcome home',                      'color' => '#7da372'),
    'available_for_pickup' => array('label' => 'Available for Pickup',  'tag' => 'Ready at carrier location',         'color' => '#d4a574'),
    'exception'            => array('label' => 'Delivery Exception',    'tag' => 'A detour has occurred',             'color' => '#c87963'),
    'returned'             => array('label' => 'Returned to Sender',    'tag' => '',                                  'color' => '#c87963'),
    'delivery_failed'      => array('label' => 'Delivery Failed',       'tag' => '',                                  'color' => '#c87963'),
    'unknown'              => array('label' => 'Status Unknown',        'tag' => '',                                  'color' => '#998866'),
  );

  ob_start();
  ?>
  <div id="fh-tracking-page" style="background:#0f0f0f;color:#EDE0C0;padding:60px 24px;min-height:520px;font-family:'Josefin Sans',sans-serif;margin:-40px -20px;">
    <div style="max-width:720px;margin:0 auto;text-align:center;">
      <div style="font-family:'Playfair Display',serif;font-size:14px;letter-spacing:0.35em;color:#d4a574;text-transform:uppercase;margin-bottom:16px;">The FisHotel &middot; Concierge</div>
      <h1 style="font-family:'Playfair Display',serif;font-size:42px;font-weight:400;color:#EDE0C0;margin:0 0 12px;letter-spacing:0.02em;">Room Service Status</h1>
      <p style="font-size:15px;letter-spacing:0.06em;color:#b8a87a;margin:0 0 48px;line-height:1.7;">Track your guests&rsquo; journey from our spa to their new home.</p>

      <?php if (!$tracking_clean) : ?>
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:4px;padding:48px 32px;box-shadow:0 8px 32px rgba(0,0,0,0.6);">
          <div style="font-family:'Playfair Display',serif;font-size:20px;color:#EDE0C0;margin-bottom:24px;">Look up your shipment</div>
          <p style="font-size:14px;color:#998866;margin:0 0 28px;line-height:1.6;">Enter your tracking number below.</p>
          <form method="get" action="<?php echo esc_url(get_permalink()); ?>">
            <input type="text" name="tracking" placeholder="Tracking number" required style="width:100%;max-width:380px;padding:14px 18px;background:#0f0f0f;border:1px solid #3a3a3a;color:#EDE0C0;font-family:inherit;font-size:15px;letter-spacing:0.06em;border-radius:2px;text-align:center;" autofocus />
            <button type="submit" style="display:block;margin:20px auto 0;padding:14px 38px;background:#d4a574;color:#0f0f0f;border:none;font-family:inherit;font-size:13px;font-weight:600;letter-spacing:0.2em;text-transform:uppercase;cursor:pointer;border-radius:2px;">Look Up</button>
          </form>
        </div>
      <?php else :
        // Determine status info
        $status = $shipment ? (string) $shipment->status : 'unknown';
        $si = isset($status_map[$status]) ? $status_map[$status] : $status_map['unknown'];
      ?>
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:4px;padding:48px 36px;box-shadow:0 8px 32px rgba(0,0,0,0.6);">

          <?php if ($shipment) : ?>
            <!-- Status badge -->
            <div style="display:inline-block;padding:10px 24px;background:<?php echo esc_attr($si['color']); ?>;color:#0f0f0f;font-size:12px;font-weight:700;letter-spacing:0.3em;text-transform:uppercase;border-radius:2px;margin-bottom:20px;"><?php echo esc_html($si['label']); ?></div>
            <?php if ($si['tag']) : ?>
              <div style="font-family:'Playfair Display',serif;font-size:22px;color:#EDE0C0;margin-bottom:36px;font-style:italic;"><?php echo esc_html($si['tag']); ?></div>
            <?php else : ?>
              <div style="margin-bottom:24px;"></div>
            <?php endif; ?>

            <!-- Delivery info -->
            <?php
            $delivered_at = !empty($shipment->delivered_date) && $shipment->delivered_date !== '0000-00-00 00:00:00' ? strtotime($shipment->delivered_date) : 0;
            $est = !empty($shipment->est_delivery) && $shipment->est_delivery !== '0000-00-00 00:00:00' ? strtotime($shipment->est_delivery) : 0;
            if ($delivered_at) : ?>
              <div style="font-size:11px;letter-spacing:0.25em;color:#998866;text-transform:uppercase;margin-bottom:8px;">Delivered</div>
              <div style="font-family:'Playfair Display',serif;font-size:20px;color:#d4a574;margin-bottom:32px;"><?php echo esc_html(date('l, F j — g:i A', $delivered_at)); ?></div>
            <?php elseif ($est) : ?>
              <div style="font-size:11px;letter-spacing:0.25em;color:#998866;text-transform:uppercase;margin-bottom:8px;">Estimated Arrival</div>
              <div style="font-family:'Playfair Display',serif;font-size:24px;color:#d4a574;margin-bottom:32px;"><?php echo esc_html(date('l, F j', $est)); ?></div>
            <?php endif; ?>
          <?php endif; ?>

          <!-- Tracking number -->
          <div style="font-size:11px;letter-spacing:0.25em;color:#998866;text-transform:uppercase;margin-bottom:10px;">Tracking Number</div>
          <div style="font-family:'Courier New',monospace;font-size:18px;color:#EDE0C0;letter-spacing:0.08em;padding:14px 20px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:2px;margin-bottom:24px;word-break:break-all;"><?php echo esc_html($tracking_clean); ?></div>
          <div style="font-size:12px;color:#998866;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:20px;">Shipping with <span style="color:#d4a574;font-weight:600;"><?php echo esc_html($carrier_name); ?></span></div>

          <!-- Carrier site button -->
          <a href="<?php echo esc_url($carrier_url); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:14px 36px;background:transparent;border:1px solid #d4a574;color:#d4a574;text-decoration:none;font-size:12px;font-weight:600;letter-spacing:0.25em;text-transform:uppercase;border-radius:2px;">View on <?php echo esc_html($carrier_name); ?>.com &rarr;</a>

          <?php
          // Render event timeline if we have data
          $events = array();
          if ($shipment && !empty($shipment->tracking_data)) {
            $decoded = json_decode((string) $shipment->tracking_data, true);
            if (is_array($decoded) && isset($decoded['tracking_details']) && is_array($decoded['tracking_details'])) {
              $events = array_reverse($decoded['tracking_details']);
            }
          }
          if (!empty($events)) :
          ?>
            <div style="margin-top:48px;padding-top:36px;border-top:1px solid #2a2a2a;">
              <div style="font-family:'Playfair Display',serif;font-size:14px;letter-spacing:0.35em;color:#d4a574;text-transform:uppercase;margin-bottom:24px;">Journey History</div>
              <div style="text-align:left;">
                <?php foreach ($events as $i => $event) :
                  $msg = isset($event['message']) ? $event['message'] : (isset($event['description']) ? $event['description'] : 'Status update');
                  $dt = isset($event['datetime']) ? strtotime($event['datetime']) : 0;
                  $loc = '';
                  if (isset($event['tracking_location']) && is_array($event['tracking_location'])) {
                    $parts = array();
                    if (!empty($event['tracking_location']['city'])) $parts[] = $event['tracking_location']['city'];
                    if (!empty($event['tracking_location']['state'])) $parts[] = $event['tracking_location']['state'];
                    $loc = implode(', ', $parts);
                  }
                  $is_first = $i === 0;
                  $dot_color = $is_first ? $si['color'] : '#3a3a3a';
                ?>
                  <div style="display:flex;gap:16px;padding:14px 0;border-bottom:1px solid #1f1f1f;<?php if ($i === count($events) - 1) echo 'border-bottom:none;'; ?>">
                    <div style="flex-shrink:0;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($dot_color); ?>;margin-top:6px;"></div>
                    <div style="flex:1;">
                      <div style="color:#EDE0C0;font-size:14px;line-height:1.4;<?php if ($is_first) echo 'font-weight:600;'; ?>"><?php echo esc_html($msg); ?></div>
                      <div style="color:#998866;font-size:12px;letter-spacing:0.04em;margin-top:4px;">
                        <?php if ($dt) echo esc_html(date('M j, Y', $dt) . ' · ' . date('g:i A', $dt)); ?>
                        <?php if ($loc) : ?> <span style="color:#665944;">&middot;</span> <?php echo esc_html($loc); ?><?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php elseif (!$shipment) : ?>
            <div style="margin-top:32px;padding:18px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:2px;color:#998866;font-size:13px;line-height:1.6;">No FisHotel record for this tracking number. Please verify your tracking number, or view the carrier&rsquo;s page above for the latest status.</div>
          <?php endif; ?>

        </div>
      <?php endif; ?>

      <p style="font-size:12px;letter-spacing:0.15em;color:#665944;margin:40px 0 0;text-transform:uppercase;">Questions? <a href="/contact/" style="color:#d4a574;text-decoration:none;border-bottom:1px solid #d4a574;">Contact The Front Desk</a></p>

    </div>
  </div>
  <?php
  return ob_get_clean();
});
