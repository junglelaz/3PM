<?php
/**
 * Menu icons (white SVG masks) — set & forget
 * - Crisp white icons via CSS masks
 * - Title-first matching, then URL path segment fallback
 * - Artists = star; Contact = envelope; Shop = storefront/awning; Buy $3PM Coins = coin; Gallery = photo; Services = sparkle; + Login/Register
 */

/* ---------- Normalization helpers ---------- */
function vmi_normalize_title($title){
    $t = wp_strip_all_tags((string)$title);
    $t = preg_replace('/[\(\[].*?[\)\]]/u', '', $t);
    $t = preg_replace('/[^a-z0-9 \$%]+/iu', ' ', $t);
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
        case 'artists':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 26 26\"><path d=\"M13 2l2.8 5.6 6.2.9-4.5 4.3 1 6-5.5-2.9-5.5 2.9 1-6-4.5-4.3 6.2-.9L13 2z\"/></svg>')";
        case 'streams':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 7a8 8 0 0 1 8-4v2a6 6 0 0 0-6 6H4a8 8 0 0 1 0-4Zm0 10a8 8 0 0 0 8 4v-2a6 6 0 0 1-6-6H4a8 8 0 0 0 0 4Zm16-6a6 6 0 0 0-6-6V3a8 8 0 0 1 8 8h-2Zm0 2a8 8 0 0 1-8 8v-2a6 6 0 0 0 6-6h2Z\"/></svg>')";
        case 'events':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v3H2V6a2 2 0 0 1 2-2h3V2Zm15 7v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9h20ZM7 12h4v4H7v-4Z\"/></svg>')";
        case 'vaults':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm8 3a5 5 0 1 0 5 5 5 5 0 0 0-5-5Zm0 3a2 2 0 1 1-2 2 2 2 0 0 1 2-2Z\"/></svg>')";
        case 'blog':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 4h16v2H4V4Zm0 4h16v2H4V8Zm0 4h10v2H4v-2Zm0 4h10v2H4v-2Z\"/></svg>')";
        case 'shop': /* STOREFRONT / AWNING */
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill-rule=\"evenodd\"><path d=\"M3 5h18v3H3V5Zm0 3h3v2H3V8Zm4 0h3v2H7V8Zm4 0h3v2h-3V8Zm4 0h3v2h-3V8Zm4 0h3v2h-3V8ZM4 10h16v10H4V10Zm10 2h5v4h-5v-4Z\"/></svg>')";
        case 'contact':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6Zm2 0 8 5 8-5H4Zm16 12V8l-8 5-8-5v10h16Z\"/></svg>')";
        case 'account':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5Z\"/></svg>')";
        case 'coin':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path fill-rule=\"evenodd\" clip-rule=\"evenodd\" d=\"M12 3a9 9 0 1 1 0 18a9 9 0 0 1 0-18Zm0 4a5 5 0 1 0 0 10a5 5 0 0 0 0-10Zm0 4a1 1 0 1 1 0 2a1 1 0 0 1 0-2Z\"/></svg>')";
        case 'live':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path fill-rule=\"evenodd\" d=\"M12 2a10 10 0 1 1 0 20a10 10 0 0 1 0-20Zm0 3a7 7 0 1 0 0 14a7 7 0 0 0 0-14Z\"/><path d=\"M10 8.5v7l6-3.5-6-3.5z\"/></svg>')";
        case 'gallery':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5Zm2 0v10l3.5-3.5 4.5 4.5 3.5-3.5 2.5 2.5V5H6Zm0 14h12v-1.5l-2.5-2.5-3.5 3.5-4.5-4.5L6 17.5V19Z\"/></svg>')";
        case 'services':  /* SPARKLE */
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 2l1.8 4.6L18 8.4l-4.2 1.8L12 15l-1.8-4.8L6 8.4l4.2-1.8L12 2Zm7 9l.9 2.3 2.1.9-2.1.9L19 17l-.9-2.3-2.1-.9 2.1-.9L19 11Zm-14 0l.9 2.3 2.1.9-2.1.9L5 17l-.9-2.3-2.1-.9 2.1-.9L5 11Z\"/></svg>')";
        case 'login':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5v-2h5V5h-5V3Z\"/><path d=\"M11.5 7.5 10 9l2.5 3H3v2h9.5L10 17l1.5 1.5L18 12l-6.5-4.5Z\"/></svg>')";
        case 'register':
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z\"/><path d=\"M4 20v-1c0-3.314 3.134-6 7-6s7 2.686 7 6v1H4Z\"/><path d=\"M19 7h2v2h-2v2h-2V9h-2V7h2V5h2v2Z\"/></svg>')";
        default:
            return "url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"4\"/></svg>')";
    }
}

/* ---------- Icon key ---------- */
function vmi_icon_key_for_menu_item( $title, $url ) {
    $norm = vmi_normalize_title( $title );
    $segs = vmi_url_segments( $url );
    $has  = function($slug) use ($segs){ return in_array(strtolower($slug), $segs, true); };

    switch ($norm) {
        case 'home': return 'home';
        case 'about': return 'about';
        case 'music': return 'music';
        case 'artists': return 'artists';
        case 'streams': return 'streams';
        case 'events': return 'events';
        case 'vaults': return 'vaults';
        case 'blog': return 'blog';
        case 'shop': return 'shop';
        case 'contact': return 'contact';
        case 'my account': return 'account';
        case 'buy $3pm coins':
        case 'buy 3pm coins':
        case 'buy coins':
        case 'buy $3pm': return 'coin';
        case 'live': return 'live';
        case 'gallery': return 'gallery';
        case 'services': return 'services';
        case 'login': return 'login';
        case 'register': return 'register';
    }

    if ($has('login') || $has('sign-in')) return 'login';
    if ($has('membership-levels')) return 'register';
    return 'home';
}

/* ---------- Choose icon ---------- */
function vmi_icon_for_menu_item( $title, $url ) {
    $key = vmi_icon_key_for_menu_item($title, $url);
    return vmi_svg($key);
}

/* ---------- Inject icons ---------- */
add_filter( 'walker_nav_menu_start_el', function( $item_output, $item ) {
    $icon = vmi_icon_for_menu_item( $item->title ?? '', $item->url ?? '' );
    $key  = vmi_icon_key_for_menu_item( $item->title ?? '', $item->url ?? '' );
    $span = '<span class="vmi" data-vmi-key="' . esc_attr($key) . '" style="--icon:' . esc_attr($icon) . ';" aria-hidden="true"></span>';
    if ( strpos( $item_output, '<a ' ) !== false )
        return preg_replace( '/(<a\b[^>]*>)/i', '$1' . $span, $item_output, 1 );
    return $span . $item_output;
}, 10, 2 );

/* ---------- Tag Login/Register links (only when logged out) ---------- */
add_filter('nav_menu_link_attributes', function($atts, $item){
    if ( is_user_logged_in() ) return $atts;
    $key = vmi_icon_key_for_menu_item($item->title ?? '', $item->url ?? '');
    if (in_array($key, ['login','register'], true))
        $atts['class'] = trim(($atts['class'] ?? '') . ' threepm-auth threepm-auth-' . $key);
    return $atts;
}, 10, 2);

/* ---------- Styles (scoped to #nav .qw-menucontainer) ---------- */
add_action('wp_head', function(){ ?>
<style>
/* We avoid global positioning on nav links to prevent theme overlays from misbehaving */

/* Icon styling */
.vmi{
  position: relative;             /* moved here from global link rule to avoid side effects */
  display:inline-block;
  width:1.05rem;height:1.05rem;
  margin-right:.55rem;vertical-align:middle;
  background-color:#fff;
  -webkit-mask:var(--icon) no-repeat center/contain;
          mask:var(--icon) no-repeat center/contain;
}
.menu li a .vmi{ transform:translateY(-3px); }

/* Neon pulse */
@keyframes vmiNeonPulse{
  0%,100%{filter:drop-shadow(0 0 4px #c4cd42) drop-shadow(0 0 10px #c4cd42);}
  50%    {filter:drop-shadow(0 0 2px #d6df5a) drop-shadow(0 0 6px #d6df5a);}
}

/* ===== STRICT SCOPE: vertical menu container ===== */
body:not(.logged-in) #nav .qw-menucontainer a.threepm-auth .vmi{
  background-color:#c4cd42!important;
  animation:vmiNeonPulse 1.6s infinite alternate ease-in-out;
}

/* Text black on hover/focus */
#nav .qw-menucontainer a.threepm-auth:hover,
#nav .qw-menucontainer a.threepm-auth:focus,
#nav .qw-menucontainer li:hover>a.threepm-auth,
#nav .qw-menucontainer li:focus-within>a.threepm-auth{
  color:#000!important;text-shadow:none!important;font-weight:600;
}

/* Icon black on hover/focus */
#nav .qw-menucontainer a.threepm-auth:hover .vmi,
#nav .qw-menucontainer a.threepm-auth:focus .vmi,
#nav .qw-menucontainer li:hover>a.threepm-auth .vmi,
#nav .qw-menucontainer li:focus-within>a.threepm-auth .vmi{
  background-color:#000!important;filter:none!important;animation:none!important;
}
</style>
<?php });
