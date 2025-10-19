</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function(){
  const DEFAULT_MS = 5000; // 5s

  function closeSmooth(el){
    if (!el || el.classList.contains('flash-leave')) return;
    el.classList.add('flash-leave');
    // After transition, remove element
    const done = () => el.remove();
    el.addEventListener('transitionend', done, { once: true });
    // Fallback removal if transitionend never fires
    setTimeout(done, 450);
  }

  function arm(el){
    // Animate in
    el.classList.add('flash-enter');
    requestAnimationFrame(() => {
      el.classList.remove('flash-enter');
    });

    // Auto-dismiss timer
    const ms = parseInt(el.getAttribute('data-ms') || DEFAULT_MS, 10);
    let timer = setTimeout(() => closeSmooth(el), isFinite(ms) ? ms : DEFAULT_MS);
    el.dataset.flashTimer = String(ms);

    // Pause on hover
    el.addEventListener('mouseenter', () => { clearTimeout(timer); });
    el.addEventListener('mouseleave', () => {
      clearTimeout(timer);
      timer = setTimeout(() => closeSmooth(el), 1500); // short grace after mouseout
    });

    // Manual close
    const btn = el.querySelector('.btn-close');
    if (btn) {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        closeSmooth(el);
      });
    }
  }

  function init(){
    document.querySelectorAll('.flash-stack .alert').forEach(arm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // If you ever inject flashes dynamically, dispatch `document.dispatchEvent(new Event('flash:render'))`
  document.addEventListener('flash:render', init);
})();
(function(){
  const AUTO_MS = 5000; // 5 Sekunden
  function autoDismissFlash() {
    const alerts = document.querySelectorAll('.alert.alert-flash, .alert[data-flash]');
    alerts.forEach(function(el){
      // Bereits geplantes Timeout vermeiden (z. B. bei PJAX/Reloads)
      if (el.dataset.flashTimer) return;
      const t = setTimeout(function(){
        try {
          if (window.bootstrap && window.bootstrap.Alert) {
            // Schön mit Bootstrap schließen (respektiert .fade/.show)
            const inst = bootstrap.Alert.getOrCreateInstance(el);
            inst.close();
          } else {
            // Fallback: sanft ausblenden und entfernen
            el.style.transition = 'opacity 300ms ease';
            el.style.opacity = '0';
            setTimeout(function(){ el.remove(); }, 320);
          }
        } catch (e) {
          // Im Zweifel einfach entfernen
          el.remove();
        }
      }, AUTO_MS);
      el.dataset.flashTimer = String(t);
    });
  }

  // Beim ersten Laden:
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoDismissFlash);
  } else {
    autoDismissFlash();
  }

  // Optional: falls deine App partiell Inhalte nachlädt, erneut triggern:
  document.addEventListener('flash:render', autoDismissFlash);
})();
</script>
</body>
</html>
