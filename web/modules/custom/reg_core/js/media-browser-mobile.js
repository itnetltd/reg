(function (Drupal, once) {
  'use strict';

  const selector = '.media-directories-browser';
  const instances = new WeakMap();
  let serial = 0;

  function start(context) {
    const roots = context.matches?.(selector) ? [context] : [];
    roots.push(...(context.querySelectorAll?.(selector) || []));
    once('reg-media-mobile', roots).forEach((root) => {
      const mobile = window.matchMedia('(max-width: 767px)');
      const sidebar = () => root.querySelector(':scope > .sidebar');
      const show = () => root.querySelector('.toolbar__sidebar-toggle');
      const hide = () => Array.from(root.querySelectorAll('.sidebar .directory-tree__header-toggle'))
        .find((button) => button.getAttribute('aria-label') === Drupal.t('Hide directories'));
      let desktopOpen = Boolean(sidebar());
      let automatic = false;
      let toggleTimer;
      root.dataset.regMediaResponsive = '';
      if (!root.id) root.id = `reg-media-browser-${++serial}`;

      // Automatic mobile defaults must not overwrite the desktop preference.
      function preference() {
        try { return localStorage.getItem('mdb_sidebar'); }
        catch (error) { return null; }
      }
      function restore(value) {
        try {
          if (value === null) localStorage.removeItem('mdb_sidebar');
          else localStorage.setItem('mdb_sidebar', value);
        }
        catch (error) { /* Storage may be unavailable. Native controls still work. */ }
      }
      function setOpen(open) {
        const button = open ? show() : hide();
        if (!button) return;
        const saved = preference();
        automatic = true;
        button.click();
        automatic = false;
        // Vue's already-queued DOM/watch flush runs before this microtask.
        queueMicrotask(() => restore(saved));
      }
      function update() {
        const open = Boolean(sidebar());
        if (!mobile.matches) desktopOpen = open;
        [show(), hide()].filter(Boolean).forEach((button) => {
          button.setAttribute('aria-expanded', String(open));
          button.setAttribute('aria-controls', root.id);
        });
      }
      function resized() {
        if (mobile.matches) {
          desktopOpen = Boolean(sidebar());
          setOpen(false);
        }
        else setOpen(desktopOpen);
      }
      function toggled(event) {
        const button = event.target.closest('button');
        if (automatic || (button !== show() && button !== hide())) return;
        const saved = preference();
        const keepFocus = document.activeElement === button;
        window.clearTimeout(toggleTimer);
        toggleTimer = window.setTimeout(() => {
          if (mobile.matches) restore(saved);
          // Native toggles are replaced when Vue renders; keep keyboard focus.
          if (keepFocus && (document.activeElement === document.body || document.activeElement === button)) {
            (show() || hide())?.focus({preventScroll: true});
          }
        }, 0);
      }
      const observer = new MutationObserver(update);
      observer.observe(root, {childList: true, subtree: true});
      root.addEventListener('click', toggled, true);
      mobile.addEventListener('change', resized);
      if (mobile.matches) setOpen(false);
      update();
      instances.set(root, () => {
        observer.disconnect();
        window.clearTimeout(toggleTimer);
        root.removeEventListener('click', toggled, true);
        mobile.removeEventListener('change', resized);
      });
    });
  }

  // The provider mounts asynchronously, including field-widget dialogs.
  const observer = new MutationObserver((records) => {
    records.forEach((record) => {
      record.addedNodes.forEach((node) => { if (node.nodeType === 1) start(node); });
      record.removedNodes.forEach((node) => {
        if (node.nodeType !== 1) return;
        const roots = node.matches(selector) ? [node] : [];
        roots.push(...node.querySelectorAll(selector));
        roots.forEach((root) => {
          if (!root.isConnected && instances.has(root)) {
            instances.get(root)();
            instances.delete(root);
            once.remove('reg-media-mobile', root);
          }
        });
      });
    });
  });
  observer.observe(document.documentElement, {childList: true, subtree: true});
  Drupal.behaviors.regMediaMobile = {attach: start};
  start(document);
})(Drupal, once);
