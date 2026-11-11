<?php
/*
3PM Home Motion Effects Control
Description: Elementor motion effects: animate only on the first true homepage load (per browser tab). On AJAX returns to home, reveal instantly (no re-anim / no invisibles).
Version: 1.1
Author: 3PM
*/

add_action('wp_enqueue_scripts', function () {

    // ---------- CSS ----------
    wp_register_style('threepm-home-no-anim', false);
    wp_enqueue_style('threepm-home-no-anim');

    $css = <<<CSS
/* When we've flagged that the homepage has already animated in this tab
   AND we're on a non-hard return to home, we add body.home-no-anim. */
body.home.home-no-anim .elementor-invisible {
  opacity: 1 !important;
  visibility: visible !important;
}

/* Prevent any leftover transitions/filters from keeping things hidden */
body.home.home-no-anim [data-settings*="motion_effects"],
body.home.home-no-anim .elementor-motion-effects-element,
body.home.home-no-anim .elementor-element[data-settings*="animation"] {
  transition: none !important;
  transform: none !important;
  opacity: 1 !important;
  filter: none !important;
  will-change: auto !important;
}
CSS;
    wp_add_inline_style('threepm-home-no-anim', $css);

    // ---------- JS ----------
    wp_register_script('threepm-home-anim', false, [], null, true);
    wp_enqueue_script('threepm-home-anim');

    $js = <<<JS
(function(){
  var KEY = 'threepmHomeAnimSeen';

  function onHome(){
    return document.body && document.body.classList.contains('home');
  }

  // Detect if this execution context is a "hard" page load (full navigation/reload)
  function isHardLoad(){
    try {
      var nav = performance.getEntriesByType && performance.getEntriesByType('navigation');
      if (nav && nav[0] && nav[0].type) {
        // 'navigate' or 'reload' means a full doc load; 'back_forward' also counts as hard
        return (nav[0].type === 'navigate' || nav[0].type === 'reload' || nav[0].type === 'back_forward');
      }
    } catch(e){}
    // Fallback: if DOM was fully loaded by HTTP fetch, document.readyState at this moment doesn't guarantee,
    // so default to true only at initial DOMContentLoaded call; other event paths (AJAX) will call us later again.
    return true;
  }

  function revealNow(){
    if (!onHome()) return;
    document.body.classList.add('home-no-anim');
    // Brutally reveal anything Elementor left invisible
    document.querySelectorAll('.elementor-invisible').forEach(function(el){
      el.classList.remove('elementor-invisible');
      el.style.opacity = '';
      el.style.transform = '';
      el.style.visibility = '';
    });
  }

  // Mark that we've allowed the first-load animation to happen in this tab.
  // Do it a bit after window.load so Elementor had time to run its entrances.
  function markSeenAfterFirstLoad(){
    // Only set once, and only on a HARD load of HOME
    if (!onHome()) return;
    if (sessionStorage.getItem(KEY) === '1') return;
    // Give Elementor a moment to finish the first wave of animations
    window.setTimeout(function(){
      sessionStorage.setItem(KEY, '1');
    }, 2200); // adjust if your longest entrance lasts longer
  }

  function handleArrival(context){
    if (!onHome()) return;

    var alreadySeen = sessionStorage.getItem(KEY) === '1';
    var hard = isHardLoad();

    if (!alreadySeen && hard) {
      // First hard load on home (in this tab) → let animations play
      // We'll markSeen on window load later.
      return;
    }

    // If we've already animated once in this tab, and this isn't a hard load,
    // we're likely here via AJAX/router → reveal instantly (no re-anim).
    if (alreadySeen && !hard) {
      requestAnimationFrame(revealNow);
    }
  }

  // 1) Initial hard load pass
  document.addEventListener('DOMContentLoaded', function(){
    handleArrival('domcontentloaded');
  });

  // After full load, mark that we've shown the animation once (for this tab)
  window.addEventListener('load', function(){
    // Only flag as seen if this was indeed a hard load of HOME
    if (onHome() && isHardLoad()) {
      markSeenAfterFirstLoad();
    }
  });

  // 2) Hook common AJAX/router events (will be non-hard contexts)
  ['pjax:end','swup:contentReplaced','astro:page-load','htmx:afterSwap','vice:ajax:done']
    .forEach(function(evt){ document.addEventListener(evt, function(){ handleArrival(evt); }); });

  // 3) jQuery AJAX fallback
  if (window.jQuery) {
    jQuery(document).on('ajaxComplete', function(){ handleArrival('jquery-ajax'); });
  }

  // 4) MutationObserver safety net: only treat as non-hard when DOM is swapped *after* first load
  var container = document.getElementById('qwjquerycontent') ||
                  document.querySelector('#qwjquerycontent') ||
                  document.querySelector('#page') ||
                  document.body;

  if ('MutationObserver' in window && container) {
    var firstInjectionDone = false;
    new MutationObserver(function(muts){
      // If we've never marked seen and it's still first hard load, do nothing here.
      // Only after we've marked seen do we react to injections as "AJAX returns".
      if (sessionStorage.getItem(KEY) === '1') {
        for (var i=0; i<muts.length; i++){
          if (muts[i].addedNodes && muts[i].addedNodes.length){
            handleArrival('mutation');
            break;
          }
        }
      } else {
        // track that swaps happened; no action until KEY=1
        firstInjectionDone = true;
      }
    }).observe(container, { childList: true, subtree: true });
  }
})();
JS;
    wp_add_inline_script('threepm-home-anim', $js);
});
