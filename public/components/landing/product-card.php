<?php
// public/components/landing/product-card.php — one read-only product card.
// Used by the landing page (index.php) and the shop page (shop/index.php).
// Needs: $p (a row from LandingPage::products/catalogue), $c (LandingPage
// content: show_prices, show_stock), $h (escaper). Optional: $cardClickable —
// the card opens the quick-view on the shop page.
$cardClickable = $cardClickable ?? false;
$cardData = $cardClickable ? ' tabindex="0" role="button" aria-label="' . $h('View ' . $p['name']) . '" data-product="' . $h(json_encode([
    'name' => $p['name'], 'cat' => $p['category_name'] ?? '', 'desc' => (string) ($p['description'] ?? ''),
    'img' => $p['image_url'], 'unit' => (string) ($p['unit'] ?? ''),
    'price' => $c['show_prices'] ? format_price($p['price']) : '', 'was' => $c['show_prices'] && $p['was_price'] ? format_price($p['was_price']) : '',
    'stock' => $c['show_stock'] ? ($p['in_stock'] ? 'In stock' : 'Sold out') : '',
], JSON_UNESCAPED_UNICODE)) . '"' : '';
?>
      <article class="pcard reveal<?php echo $cardClickable ? ' clickable' : ''; ?>" data-cat="<?php echo $h(trim((string) ($p['category_name'] ?? ''))); ?>"<?php echo $cardData; ?>>
        <div class="pic">
          <?php if ($p['image_url']): ?><img src="<?php echo $h($p['image_url']); ?>" alt="<?php echo $h($p['name']); ?>" loading="lazy">
          <?php else: ?><span class="ph"><?php echo $h(mb_strtoupper(mb_substr($p['name'], 0, 1))); ?></span><?php endif; ?>
          <?php if ($p['was_price'] && $c['show_prices']): ?><span class="tag">Offer</span><?php endif; ?>
        </div>
        <div class="body">
          <?php if (!empty($p['category_name'])): ?><span class="cat"><?php echo $h($p['category_name']); ?></span><?php endif; ?>
          <h3><?php echo $h($p['name']); ?></h3>
          <?php if (trim((string) $p['description']) !== ''): ?><p class="desc"><?php echo $h($p['description']); ?></p><?php endif; ?>
          <?php if ($c['show_prices'] || $c['show_stock']): ?>
          <div class="foot">
            <span><?php if ($c['show_prices']): ?><?php if ($p['was_price']): ?><span class="was"><?php echo $h(format_price($p['was_price'])); ?></span><?php endif; ?><span class="price"><?php echo $h(format_price($p['price'])); ?></span><?php endif; ?></span>
            <?php if ($c['show_stock']): ?><span class="stock<?php echo $p['in_stock'] ? '' : ' out'; ?>"><i></i><?php echo $p['in_stock'] ? 'In stock' : 'Sold out'; ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </article>
