<?php
/**
 * 3PM — My Account customizations (padlock fix + all prior working tweaks)
 * - Security endpoint alias: /my-account/security
 * - Rename title, remove FAQ helper sentence
 * - "Choose Preference" text visibility (incl. Select2/Chosen)
 * - Remove wallet fallback line
 * - Orders notice neon-green
 * - Security icon as CSS background on <a> (robust; no square)
 */

/* -------------------------------------------
 * /my-account/security endpoint mapped to Loginizer content
 * ------------------------------------------- */
add_action('init', function () {
  add_rewrite_endpoint('security', EP_ROOT | EP_PAGES);
}, 5);

add_filter('query_vars', function ($vars) {
  $vars[] = 'security';
  return $vars;
});

add_action('woocommerce_account_security_endpoint', function () {
  // Render whatever Loginizer outputs on its own endpoint
  do_action('woocommerce_account_loginizer-security_endpoint');
});

add_filter('woocommerce_account_menu_items', function ($items) {
  foreach ($items as $key => $label) {
    if ($key === 'loginizer-security') {
      unset($items[$key]);
      $items['security'] = $label ?: __('Security', 'woocommerce');
      return $items;
    }
  }
  if (isset($items['security'])) $items['security'] = __('Security', 'woocommerce');
  return $items;
}, 30);

add_filter('woocommerce_get_endpoint_url', function ($url, $endpoint, $value, $permalink) {
  if ($endpoint === 'loginizer-security') {
    return wc_get_endpoint_url('security', $value, $permalink);
  }
  return $url;
}, 10, 4);

add_action('template_redirect', function () {
  if (!is_account_page()) return;
  global $wp;
  if (!empty($wp->query_vars['loginizer-security'])) {
    wp_safe_redirect(wc_get_endpoint_url('security', '', wc_get_page_permalink('myaccount')), 301);
    exit;
  }
});

/* -------------------------------------------
 * String-level source overrides (rename/remove)
 * ------------------------------------------- */
add_filter('gettext', function($translated, $original){
  if (stripos($original, 'Loginizer Security Settings') !== false) return 'Security Settings';
  if (stripos($original, 'You should also bookmark the FAQs, which explain how to de-activate the plugin even if you cannot log in') !== false) return '';
  $o = trim($original);
  if ($o === 'Your wallet is currently unavailable.' || $o === 'Your wallet is currently unavailable') return '';
  return $translated;
}, 20, 2);

/* -------------------------------------------
 * Server-side output scrub (catches non-gettext strings)
 * ------------------------------------------- */
add_action('template_redirect', function () {
  if (!is_account_page()) return;
  ob_start(function ($html) {
    // Wallet fallback line
    $html = preg_replace('~<p>\s*Your wallet is currently unavailable\.?\s*</p>~i', '', $html);
    $html = str_ireplace('Your wallet is currently unavailable.', '', $html);
    // Loginizer FAQ helper sentence
    $html = preg_replace('~You\s+should\s+also\s+bookmark\s+the\s+FAQs.*?cannot\s+log\s+in\.~is', '', $html);
    return $html;
  });
});

/* -------------------------------------------
 * CSS — inputs visibility, Orders neon-green, and the PADLOCK icon (CSS background on <a>)
 * (Injected in head and re-injected in footer to beat aggressive theme CSS)
 * ------------------------------------------- */
function threepm_myaccount_css_block(){ ?>
  <style id="threepm-myaccount-css">
    /* ===== Security page: make "Choose Preference" readable ===== */
    .threepm-security-active input[type="text"],
    .threepm-security-active input[type="email"],
    .threepm-security-active input[type="password"],
    .threepm-security-active select,
    .threepm-security-active textarea{
      color:#333 !important; -webkit-text-fill-color:#333 !important;
      caret-color:#333 !important; background-color:#fff !important;
    }
    .threepm-security-active ::placeholder{ color:#666 !important; opacity:1; }

    /* Select2 / Chosen renderers */
    .threepm-security-active .select2-container .select2-selection--single,
    .threepm-security-active .select2-container .select2-selection--multiple{
      background:#fff !important; color:#333 !important;
    }
    .threepm-security-active .select2-container--default .select2-selection__rendered{ color:#333 !important; }
    .threepm-security-active .select2-results__option{ color:#333 !important; background:#fff !important; }
    .threepm-security-active .select2-results__option--highlighted{ background:#eef7d1 !important; color:#111 !important; }
    .threepm-security-active .chosen-container-single .chosen-single,
    .threepm-security-active .chosen-container .chosen-choices{
      background:#fff !important; color:#333 !important;
    }

    /* ===== Orders notice (neon-green) ===== */
    body.woocommerce-account .woocommerce-MyAccount-content .woocommerce-info{
      border-top-color:#B7FF00 !important;
      box-shadow: inset 0 3px 0 0 #B7FF00, 0 0 0 transparent !important;
      border-left-color:#B7FF00 !important;
    }
    body.woocommerce-account .woocommerce-MyAccount-content .woocommerce-info::before{
      background:#B7FF00 !important; color:#000 !important;
    }

    /* ===== Security nav icon =====
       We ONLY nuke theme pseudo-icons; we keep <a> backgrounds available.
    */
    .woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security::before,
    .woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security::after,
    .woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security > a::before,
    .woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security > a::after{
      content:none !important; display:none !important;
      background:none !important; background-image:none !important;
      -webkit-mask:none !important; mask:none !important;
      width:0 !important; height:0 !important;
    }

    /* Add the padlock as a BACKGROUND IMAGE on the <a> itself (bulletproof) */
    .woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security > a{
      position:relative;
      padding-left: 1.8rem !important;
      background-repeat: no-repeat !important;
      background-position: 0.15rem 50% !important;
      background-size: 1.2rem 1.2rem !important;
      /* Neon padlock SVG (U-shackle + body + keyhole) */
      background-image: url("data:image/svg+xml;utf8,<?php
        echo rawurlencode(
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#B7FF00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
          . '<path d="M8 11V8a4 4 0 0 1 8 0v3" />'
          . '<rect x="6.5" y="11" width="11" height="9" rx="2" />'
          . '<circle cx="12" cy="15" r="1" />'
          . '<path d="M12 16v2" />'
          . '</svg>'
        );
      ?>") !important;
      /* Soft glow */
      filter: drop-shadow(0 0 2px rgba(183,255,0,.6));
    }
    /* If theme sets a background via class toggle, override with !important again */
    .woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security.is-active > a{
      background-image: inherit !important;
    }
  </style>
<?php }
add_action('wp_head', 'threepm_myaccount_css_block', 99);
add_action('wp_footer', 'threepm_myaccount_css_block', 1); // late injection beats theme css

/* -------------------------------------------
 * JS — keep non-icon fixes (scope, FAQ removal, wallet line removal, orders tint)
 * (Icon is CSS-based now; we only ensure the /security href)
 * ------------------------------------------- */
add_action('wp_footer', function(){ ?>
  <script id="threepm-myaccount-js">
  (function(){
    if (!document.body.classList.contains('woocommerce-account')) return;

    function norm(s){ return (s||'').replace(/\u00a0/g,' ').replace(/\s+/g,' ').trim().toLowerCase(); }
    function content(){ return document.querySelector('.woocommerce-MyAccount-content'); }
    function secLink(){ return document.querySelector('.woocommerce-MyAccount-navigation .woocommerce-MyAccount-navigation-link--security > a'); }

    /* Ensure Security link points to /my-account/security */
    function ensureSecurityHref(){
      var a = secLink(); if(!a) return;
      var myacc = '<?php echo esc_js( wc_get_page_permalink('myaccount') ); ?>';
      if (myacc && myacc.slice(-1) !== '/') myacc += '/';
      var target = myacc.replace(/\/+$/,'/') + 'security/';
      if (a.getAttribute('href') !== target) a.setAttribute('href', target);
    }

    /* SECURITY page: scope + remove FAQ sentence + keep fields readable */
    function fixSecurity(){
      var c = content(); if(!c) return;
      var h = c.querySelector('h1, h2, .page-title, .entry-title');
      if (h && /loginizer security settings/i.test(h.textContent)) h.textContent = 'Security Settings';
      if (h && /security settings/i.test(h.textContent)) c.classList.add('threepm-security-active');

      var killKeys = ['bookmark the faqs','de-activate the plugin','deactivate the plugin','even if you cannot log in'];
      c.querySelectorAll('p, div, span, li, small, em').forEach(function(n){
        var t = norm(n.textContent); if(!t) return;
        if (killKeys.some(k=>t.includes(k))) (n.closest('p, .description, div, li')||n).remove();
      });

      function forceGrey(el){
        if(!el || !el.style) return;
        el.style.setProperty('color','#333','important');
        el.style.setProperty('-webkit-text-fill-color','#333','important');
        el.style.setProperty('caret-color','#333','important');
        el.style.setProperty('background-color','#fff','important');
      }
      var anchor = Array.from(c.querySelectorAll('label, strong, span, p, h3, h4'))
        .find(el => /choose preference/i.test(el.textContent||''));
      if (anchor){
        var nx = anchor.nextElementSibling, count=0;
        while (nx && count++<8){
          if (/^(INPUT|SELECT|TEXTAREA)$/i.test(nx.tagName)) forceGrey(nx);
          nx.querySelectorAll && nx.querySelectorAll('input, select, textarea').forEach(forceGrey);
          var s2 = nx.querySelector('.select2-container, .select2, .chosen-container, .chosen-container-single, .chosen-container-multi') ||
                   (nx.nextElementSibling && nx.nextElementSibling.matches('.select2-container, .select2, .chosen-container, .chosen-container-single, .chosen-container-multi') ? nx.nextElementSibling : null);
          if (s2){
            var rendered = s2.querySelector('.select2-selection__rendered, .chosen-single span, .chosen-choices li.search-field input');
            if (rendered) rendered.style.setProperty('color','#333','important');
            var selbox = s2.querySelector('.select2-selection, .chosen-single, .chosen-choices');
            if (selbox){ selbox.style.setProperty('background','#fff','important'); selbox.style.setProperty('color','#333','important'); }
          }
          nx = nx.nextElementSibling;
        }
      }
      c.querySelectorAll('input, select, textarea').forEach(forceGrey);
      c.querySelectorAll('.select2-container .select2-selection, .chosen-container-single .chosen-single, .chosen-container .chosen-choices').forEach(function(el){
        el.style.setProperty('background','#fff','important'); el.style.setProperty('color','#333','important');
      });
    }

    /* WALLET: remove "Your wallet is currently unavailable." (present or injected later) */
    function cleanWallet(){
      var c = content(); if(!c) return;
      function sweep(root){
        var nodes = (root.querySelectorAll ? root.querySelectorAll('p, div, span, li') : []);
        nodes.forEach(function(n){
          var t = (n.textContent||'').replace(/\u00a0/g,' ').replace(/\s+/g,' ').trim();
          var tl = t.toLowerCase();
          if (!tl) return;
          if ((tl === 'your wallet is currently unavailable.' || tl === 'your wallet is currently unavailable' || (tl.includes('wallet') && tl.includes('unavailable'))) && t.length < 140) {
            (n.closest('p') || n).remove();
          }
        });
      }
      sweep(c);
      var mo = new MutationObserver(function(muts){
        muts.forEach(function(m){
          m.addedNodes && m.addedNodes.forEach(function(node){ if (node.nodeType === 1) sweep(node); });
        });
      });
      try { mo.observe(c, {childList:true, subtree:true}); } catch(e){}
      window.addEventListener('beforeunload', function(){ try{ mo.disconnect(); }catch(e){} });
    }

    /* Orders tint (kept) */
    function tintOrders(){
      var c = content(); if(!c) return;
      var info = c.querySelector('.woocommerce-info'); if(!info) return;
      info.style.borderTopColor = '#B7FF00';
      var bs = getComputedStyle(info).boxShadow || '';
      if (bs.indexOf('inset') !== -1) info.style.boxShadow = 'inset 0 3px 0 0 #B7FF00';
      var i = info.querySelector('i, .icon'); if (i){ i.style.background='#B7FF00'; i.style.color='#000'; }
    }

    function runAll(){
      ensureSecurityHref();
      fixSecurity();
      cleanWallet();
      tintOrders();
    }

    if (document.readyState !== 'loading') runAll();
    else document.addEventListener('DOMContentLoaded', runAll);

    // Re-run on tab changes/navigation
    document.addEventListener('click', function(e){
      var a = e.target.closest('.woocommerce-MyAccount-navigation a'); if(!a) return;
      setTimeout(runAll, 120); setTimeout(runAll, 400); setTimeout(runAll, 1000);
    });
    window.addEventListener('popstate', runAll);
    window.addEventListener('hashchange', runAll);

    // Also observe account content area
    var c = content();
    if (c){
      var moAll = new MutationObserver(function(){ runAll(); });
      try { moAll.observe(c, {childList:true, subtree:true}); } catch(e){}
    }
  })();
  </script>
<?php });
