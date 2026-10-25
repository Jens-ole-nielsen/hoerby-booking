(function () {
  'use strict';

  function closeAll() {
    document.querySelectorAll('.hkof-actions-panel.is-open').forEach(function (el) {
      el.classList.remove('is-open');
      el.setAttribute('aria-hidden', 'true');
    });
    document.documentElement.classList.remove('hkof-panel-open');
  }

  function openPanel(id) {
    closeAll();
    var panel = document.getElementById(id);
    if (!panel) return;
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('hkof-panel-open');
    var closeBtn = panel.querySelector('.hkof-actions-panel__close');
    if (closeBtn) closeBtn.focus();
  }

  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-hkof-panel]');
    if (openBtn) {
      e.preventDefault();
      openPanel(openBtn.getAttribute('data-hkof-panel'));
      return;
    }
    if (e.target.closest('[data-hkof-panel-close]')) {
      e.preventDefault();
      closeAll();
      return;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });
})();
