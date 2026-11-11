<?php
/**
 * 3PM — Post Views: random seed + compact "k" formatting + front-end enhancer
 * - One-time random seeding for existing posts (adjust range below)
 * - Auto seed new posts on publish
 * - Helper/template tag + shortcode to display compact view counts
 * - Optional JS enhancer to reformat theme-rendered view counts in place
 *
 * Safe to keep active; idempotent flags prevent reseeding.
 */

/* =========================
 * 1) ONE-TIME RANDOM SEED
 * ========================= */
add_action('init', function () {
    if (get_option('threepm_random_views_seeded')) return;

    $posts = get_posts([
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ]);

    foreach ($posts as $post_id) {
        // Skip if already has a non-zero count
        $existing = (int) (get_post_meta($post_id, 'post_views_count', true) ?: get_post_meta($post_id, '_post_views_count', true));
        if ($existing > 0) continue;

        $random_views = rand(300, 1200); // <— adjust range to taste
        update_post_meta($post_id, 'post_views_count', $random_views);
        update_post_meta($post_id, '_post_views_count', $random_views);
    }

    update_option('threepm_random_views_seeded', true);
});

/* ==================================
 * 2) AUTO-SEED FOR NEWLY PUBLISHED
 * ================================== */
add_action('publish_post', function ($post_id) {
    $has = get_post_meta($post_id, 'post_views_count', true) ?: get_post_meta($post_id, '_post_views_count', true);
    if ($has !== '' && $has !== null) return;
    $random_views = rand(200, 800); // <— adjust range to taste
    update_post_meta($post_id, 'post_views_count', $random_views);
    update_post_meta($post_id, '_post_views_count', $random_views);
});

/* =========================================
 * 3) HELPERS: get + compact-number formatting
 * ========================================= */
if (!function_exists('threepm_get_views_raw')) {
    function threepm_get_views_raw($post_id = null) : int {
        $post_id = $post_id ?: get_the_ID();
        $a = (int) get_post_meta($post_id, 'post_views_count', true);
        $b = (int) get_post_meta($post_id, '_post_views_count', true);
        return max($a, $b, 0);
    }
}

if (!function_exists('threepm_compact_number')) {
    function threepm_compact_number($n) : string {
        $n = (int) $n;
        if ($n >= 1000000000) return round($n / 1000000000, ($n % 1000000000 ? 1 : 0)) . 'b';
        if ($n >= 1000000)    return round($n / 1000000,    ($n % 1000000    ? 1 : 0)) . 'm';
        if ($n >= 1000)       return round($n / 1000,       ($n % 1000       ? 1 : 0)) . 'k';
        return (string) $n;
    }
}

/* ===================================================
 * 4) TEMPLATE TAG: echo a compact "views" component
 *    Usage in PHP templates:
 *    <?php threepm_views_badge(); ?>
 *    // or with explicit post ID: threepm_views_badge($id, 'Views');
 * =================================================== */
if (!function_exists('threepm_views_badge')) {
    function threepm_views_badge($post_id = null, $label = 'Views') {
        $post_id = $post_id ?: get_the_ID();
        $raw = threepm_get_views_raw($post_id);
        $compact = threepm_compact_number($raw);

        // Minimal HTML (adjust classes to match your theme if you like)
        echo '<span class="threepm-views" data-views="' . esc_attr($raw) . '">'
            . '<span class="threepm-views__num">' . esc_html($compact) . '</span>'
            . ' <span class="threepm-views__label">' . esc_html($label) . '</span>'
            . '</span>';
    }
}

/* ==========================================
 * 5) SHORTCODE: [post_views_k label="Views"]
 * ========================================== */
add_shortcode('post_views_k', function ($atts) {
    $atts = shortcode_atts(['label' => 'Views', 'post_id' => ''], $atts, 'post_views_k');
    $post_id = $atts['post_id'] !== '' ? (int) $atts['post_id'] : get_the_ID();
    $raw = threepm_get_views_raw($post_id);
    $compact = threepm_compact_number($raw);
    return '<span class="threepm-views" data-views="' . esc_attr($raw) . '">'
         . '<span class="threepm-views__num">' . esc_html($compact) . '</span>'
         . ' <span class="threepm-views__label">' . esc_html($atts['label']) . '</span>'
         . '</span>';
});

/* ==================================================================
 * 6) OPTIONAL FRONT-END ENHANCER (JS):
 *    If the Vice Dhrk template already prints plain numbers, this
 *    script will find typical "views" elements and rewrite them
 *    into compact form on the fly (also works after Ajax loads).
 *    - Update the selectors array below to match your theme markup.
 * ================================================================== */
add_action('wp_enqueue_scripts', function () {
    // Only on front-end
    if (is_admin()) return;

    // Pass nothing; we just print a small inline script
    wp_add_inline_script('jquery-core', <<<JS
(function(){
    function compact(n){
        n = parseInt(n||0,10) || 0;
        if(n >= 1000000000) return (Math.round(n/100000000)/10)+'b';
        if(n >= 1000000)    return (Math.round(n/100000)/10)+'m';
        if(n >= 1000)       return (Math.round(n/100)/10)+'k';
        return ''+n;
    }
    // Add or adjust selectors to match Vice Dhrk's views element.
    var selectors = [
        '.post-views',                // common plugins
        '.entry-meta .views',         // many themes
        '.vice-views',                // guess for Vice
        '.meta-views',                // generic
        '[class*="views"]'            // fallback (broad)
    ];

    function tryFormat(node){
        try{
            var txt = node.textContent || '';
            // Extract first integer sequence
            var m = txt.replace(/[,\\s]/g,'').match(/\\d{1,9}/);
            if(!m) return;
            var raw = parseInt(m[0],10);
            if(!raw && raw!==0) return;
            var nice = compact(raw);
            // Replace only the number portion in-place
            node.textContent = txt.replace(m[0], nice);
        }catch(e){}
    }

    function sweep(root){
        selectors.forEach(function(sel){
            root.querySelectorAll(sel).forEach(tryFormat);
        });
    }

    // Initial
    document.addEventListener('DOMContentLoaded', function(){
        sweep(document);
    });

    // Observe DOM changes (for Ajax / infinite scroll)
    var obs = new MutationObserver(function(muts){
        muts.forEach(function(m){
            if(m.addedNodes && m.addedNodes.length){
                m.addedNodes.forEach(function(n){
                    if(n.nodeType === 1) sweep(n);
                });
            }
        });
    });
    obs.observe(document.documentElement, {childList:true, subtree:true});
})();
JS
    , 'after');
});

/* ==========================================
 * (Optional) basic styles for the views badge
 * ========================================== */
add_action('wp_head', function () {
    echo '<style>
    .threepm-views{display:inline-flex;gap:.35em;align-items:center;font:600 12px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Inter,sans-serif;opacity:.9}
    .threepm-views__num{letter-spacing:.2px}
    .threepm-views__label{opacity:.75}
    </style>';
});
