<?php
/**
 * Keep the global player dark on Woo pages and remove the stray text.
 * Works with QT/VICE players and similar sticky/AJAX players.
 */
add_action('wp_footer', function () { ?>
<script>
(function(){
  // only act on WooCommerce pages
  var isWoo = document.body.classList.contains('woocommerce')
           || document.body.classList.contains('woocommerce-page');
  if(!isWoo) return;

  // common player wrappers (covers QT/VICE and generic players)
  var PLAYER_SEL = [
    '#qtmplayer', '.qt-ajax-music-player', '.qt-sticky-player',
    '#ajax-player', '.site-player', '.sticky-player',
    '[data-ajax-player]', '[class*="player"]'
  ].join(',');

  function forceDark(el){
    if(!el) return;
    el.style.background = '#0b0b0b';
    el.style.color      = '#ffffff';
    el.style.setProperty('--player-bg', '#0b0b0b');
    el.style.setProperty('--player-fg', '#ffffff');
    el.style.setProperty('--player-accent', '#d7ff2f');
    // ensure inner icons/text stay light
    el.querySelectorAll('*').forEach(function(n){
      n.style.color = '#ffffff';
      n.style.fill  = '#ffffff';
      if (n.matches('.progress, .bar, .controls, .qt-controls')) {
        n.style.background = '#111111';
        n.style.borderColor = '#111111';
      }
    });
  }

  // remove any element whose textContent exactly matches the string
  function removeStrayText(root){
    var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    while (walker.nextNode()) {
      var t = (walker.currentNode.nodeValue || '').trim().toLowerCase();
      if (t === 'change visibility settings') nodes.push(walker.currentNode);
    }
    nodes.forEach(function(textNode){
      var el = textNode.parentElement;
      if (el) {
        el.style.display = 'none';
        el.setAttribute('data-removed', 'change-visibility-settings');
      }
    });
  }

  function apply(){
    document.querySelectorAll(PLAYER_SEL).forEach(forceDark);
    removeStrayText(document.body);
  }

  // run now…
  apply();

  // …and re-run after AJAX/PJAX DOM changes (theme loads parts dynamically)
  var scheduled = false;
  var observer = new MutationObserver(function(){
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function(){ scheduled = false; apply(); });
  });
  observer.observe(document.documentElement, {subtree:true, childList:true});
})();
</script>
<?php });

