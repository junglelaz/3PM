<?php
/**
 * Menu icons (white SVG masks) — set & forget
 * - Crisp white icons via CSS masks
 * - Title-first matching, then URL path segment fallback
 * - Artists = star; Contact = envelope; Shop = stack of coins; Buy $3PM Coins = coin (ring + inner dot)
 */

/* ---------- Normalization helpers ---------- */
function vmi_normalize_title($title){
    $t = wp_strip_all_tags((string)$title);
    $t = preg_replace('/[\(\[].*?[\)\]]/u', '', $t);           // remove counts e.g. "(0 $3PM)"
    $t = preg_replace('/[^a-z0-9 \$%]+/iu', ' ', $t);          // keep letters, numbers, $, %
    $t = strtolower(trim(preg_replace('/\s+/u', ' ', $t)));
    return $t;
}
function vmi_url_segments($url){
    $path = strtolower((string) wp_parse_url((string)$url, PHP_URL_PATH));
    $path = trim($path, '/');
    return $path === '' ? [] : explode('/', $path);
}

/* ---------- Icon library (inline SVGs as data URIs) ---------- */
function vmi_svg($name){
    switch ($name){
        case 'home':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 3.172 3 10v10a1 1 0 0 0 1 1h6v-6h4v6h6a1 1 0 0 0 1-1V10l-9-6.828Z\"/></svg>')";
        case 'about':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 6a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm-2 10v-2h2v-4h-2v-2h4v6h2v2h-6Z\"/></svg>')";
        case 'music':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M19 3v12.35A3.5 3.5 0 1 1 17 18V8H9v9.35A3.5 3.5 0 1 1 7 18V6h12V3Z\"/></svg>')";
        case 'artists': /* STAR */
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 26 26\"><path d=\"M13 2l2.8 5.6 6.2.9-4.5 4.3 1 6-5.5-2.9-5.5 2.9 1-6-4.5-4.3 6.2-.9L13 2z\"/></svg>')";
        case 'streams':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 7a8 8 0 0 1 8-4v2a6 6 0 0 0-6 6H4a8 8 0 0 1 0-4Zm0 10a8 8 0 0 0 8 4v-2a6 6 0 0 1-6-6H4a8 8 0 0 0 0 4Zm16-6a6 6 0 0 0-6-6V3a8 8 0 0 1 8 8h-2Zm0 2a8 8 0 0 1-8 8v-2a6 6 0 0 0 6-6h2Z\"/></svg>')";
        case 'events':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v3H2V6a2 2 0 0 1 2-2h3V2Zm15 7v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9h20ZM7 12h4v4H7v-4Z\"/></svg>')";
        case 'vaults':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm8 3a5 5 0 1 0 5 5 5 5 0 0 0-5-5Zm0 3a2 2 0 1 1-2 2 2 2 0 0 1 2-2Z\"/></svg>')";
        case 'blog':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 4h16v2H4V4Zm0 4h16v2H4V8Zm0 4h10v2H4v-2Zm0 4h10v2H4v-2Z\"/></svg>')";
        case 'shop': /* STACK OF COINS */
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 2c-4.418 0-8 1.79-8 4s3.582 4 8 4 8-1.79 8-4-3.582-4-8-4Zm0 7c-4.418 0-8-1.79-8-4v3c0 2.21 3.582 4 8 4s8-1.79 8-4V5c0 2.21-3.582 4-8 4Zm0 4c-4.418 0-8-1.79-8-4v3c0 2.21 3.582 4 8 4s8-1.79 8-4v-3c0 2.21-3.582 4-8 4Zm0 4c-4.418 0-8-1.79-8-4v3c0 2.21 3.582 4 8 4s8-1.79 8-4v-3c0 2.21-3.582 4-8 4Z\"/></svg>')";
        case 'contact': /* ENVELOPE */
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6Zm2 0 8 5 8-5H4Zm16 12V8l-8 5-8-5v10h16Z\"/></svg>')";
        case 'account':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5Z\"/></svg>')";
        case 'coin':     /* COIN: ring + inner dot */
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path fill-rule=\"evenodd\" clip-rule=\"evenodd\" d=\"M12 3a9 9 0 1 1 0 18a9 9 0 0 1 0-18Zm0 4a5 5 0 1 0 0 10a5 5 0 0 0 0-10Zm0 4a1 1 0 1 1 0 2a1 1 0 0 1 0-2Z\"/></svg>')";
        default:
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"4\"/></svg>')";
    }
}

/* ---------- Choose icon (title first, then URL) ---------- */
function vmi_icon_for_menu_item( $title, $url ) {
    $norm = vmi_normalize_title( $title );
    $segs = vmi_url_segments( $url );
    $has  = function($slug) use ($segs){ return in_array(strtolower($slug), $segs, true); };

    switch ($norm) {
        case 'home':                  return vmi_svg('home');
        case 'about':                 return vmi_svg('about');
        case 'music':                 return vmi_svg('music');
        case 'artists':               return vmi_svg('artists');
        case 'streams':               return vmi_svg('streams');
        case 'events':                return vmi_svg('events');
        case 'vaults':                return vmi_svg('vaults');
        case 'blog':                  return vmi_svg('blog');
        case 'shop':                  return vmi_svg('shop'); // stack of coins
        case 'contact':               return vmi_svg('contact');
        case 'my account':            return vmi_svg('account');
        case 'buy $3pm coins':
        case 'buy 3pm coins':
        case 'buy coins':
        case 'buy $3pm':              return vmi_svg('coin');
    }

    if (empty($segs))                     return vmi_svg('home');
    if ($has('about'))                    return vmi_svg('about');
    if ($has('music'))                    return vmi_svg('music');
    if ($has('artists'))                  return vmi_svg('artists');
    if ($has('streams'))                  return vmi_svg('streams');
    if ($has('events'))                   return vmi_svg('events');
    if ($has('vault') || $has('vaults'))  return vmi_svg('vaults');
    if ($has('blog'))                     return vmi_svg('blog');
    if ($has('shop'))                     return vmi_svg('shop');
    if ($has('contact'))                  return vmi_svg('contact');
    if ($has('my-account') || $has('account')) return vmi_svg('account');
    if ($has('buy-3pm') || $has('buy-coins') || $has('wallet-topup') || $has('topup') || $has('coins'))
        return vmi_svg('coin');

    return vmi_svg('home');
}

/* ---------- Inject icon into links AND non-links ---------- */
add_filter( 'walker_nav_menu_start_el', function( $item_output, $item, $depth, $args ) {
    $icon = vmi_icon_for_menu_item( $item->title ?? '', $item->url ?? '' );
    $span = '<span class="vmi" style="--icon: ' . esc_attr( $icon ) . ';" aria-hidden="true"></span>';

    if ( strpos( $item_output, '<a ' ) !== false ) {
        return preg_replace( '/(<a\b[^>]*>)/i', '$1' . $span, $item_output, 1 );
    }
    if ( strpos( $item_output, '<span' ) !== false ) {
        return preg_replace( '/(<span\b[^>]*>)/i', '$1' . $span, $item_output, 1 );
    }
    return $span . $item_output;
}, 10, 4 );

/* ---------- Styles (icons 2px higher) ---------- */
add_action( 'wp_head', function () {
    ?>
    <style>
      .menu a, .menu span, .navigation a, .navigation span, nav a, nav span { position: relative; }

      .vmi{
        display:inline-block;
        width: 1.05rem;
        height: 1.05rem;
        margin-right: .55rem;
        vertical-align: middle;
        background-color: #fff;
        -webkit-mask: var(--icon) no-repeat center / contain;
                mask: var(--icon) no-repeat center / contain;
      }

      /* raise icons by 2px */
      .menu li a .vmi, .menu li span .vmi { transform: translateY(-3px); }
    </style>
    <?php
});

