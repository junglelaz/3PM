(function() {
  function nudgeWidth() {
    // Tell everything listening to resize (Elementor, Swiper, etc.) to recompute widths
    window.dispatchEvent(new Event('resize'));
  }
  // On first paint and shortly after fonts/images settle
  window.addEventListener('load', function(){
    requestAnimationFrame(nudgeWidth);
    setTimeout(nudgeWidth, 300);
    setTimeout(nudgeWidth, 1200);
  });
})();
