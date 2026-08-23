(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.regTheme = {
    attach(context) {
      once('reg-header-navigation', '[data-reg-header]', context).forEach((header) => {
        const button = header.querySelector('[data-reg-menu-toggle]');
        const menu = header.querySelector('[data-reg-menu]');
        const primaryNavigation = header.querySelector('[data-reg-primary-navigation]');
        const scrim = header.querySelector('[data-reg-nav-scrim]');
        const mobileQuery = window.matchMedia('(max-width: 1099px)');
        if (!button || !menu || !primaryNavigation) return;

        const directChild = (item, selector) => {
          for (const child of item.children) {
            if (child.matches(selector)) return child;
          }
          return null;
        };

        const setSubmenuOpen = (item, open) => {
          const row = directChild(item, '.menu-item__row');
          const toggle = row ? row.querySelector(':scope > [data-reg-submenu-toggle]') : null;
          const submenu = directChild(item, '.submenu-wrap');
          if (!toggle || !submenu) return;
          item.classList.toggle('is-submenu-open', open);
          toggle.setAttribute('aria-expanded', String(open));
        };

        const closeDescendants = (item) => {
          item.querySelectorAll('.menu-item.is-submenu-open').forEach((child) => setSubmenuOpen(child, false));
          setSubmenuOpen(item, false);
        };

        const topItems = Array.from(primaryNavigation.querySelectorAll('.primary-menu > .menu-item'));
        const closeTimers = new WeakMap();
        const cancelScheduledClose = (item) => {
          const timer = closeTimers.get(item);
          if (timer) window.clearTimeout(timer);
          closeTimers.delete(item);
        };
        const scheduleClose = (item) => {
          cancelScheduledClose(item);
          closeTimers.set(item, window.setTimeout(() => {
            if (!item.matches(':hover, :focus-within')) setSubmenuOpen(item, false);
            closeTimers.delete(item);
          }, 160));
        };
        const closeTopItems = (except = null) => {
          topItems.forEach((item) => {
            if (item !== except) {
              cancelScheduledClose(item);
              closeDescendants(item);
            }
          });
        };

        const searchControls = Array.from(header.querySelectorAll('[data-reg-header-search]'));
        const setSearchOpen = (search, open, focusInput = false) => {
          const toggle = search.querySelector('[data-reg-search-toggle]');
          const panel = search.querySelector('[data-reg-search-panel]');
          if (!toggle || !panel) return;
          toggle.setAttribute('aria-expanded', String(open));
          panel.hidden = !open;
          search.classList.toggle('is-open', open);
          if (open && focusInput) {
            window.requestAnimationFrame(() => panel.querySelector('input[type="search"]')?.focus());
          }
        };

        const closeSearches = (except = null) => {
          searchControls.forEach((search) => {
            if (search !== except) setSearchOpen(search, false);
          });
        };

        searchControls.forEach((search) => {
          const toggle = search.querySelector('[data-reg-search-toggle]');
          if (!toggle) return;
          toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            closeSearches(search);
            closeTopItems();
            setSearchOpen(search, open, open);
          });
        });

        const setMobileMenuOpen = (open) => {
          button.setAttribute('aria-expanded', String(open));
          menu.classList.toggle('is-open', open);
          header.classList.toggle('is-nav-open', open);
          document.body.classList.toggle('reg-nav-open', open);
          if (!open) {
            closeTopItems();
            closeSearches();
          }
        };

        button.addEventListener('click', () => {
          setMobileMenuOpen(button.getAttribute('aria-expanded') !== 'true');
        });
        if (scrim) {
          scrim.addEventListener('click', () => {
            setMobileMenuOpen(false);
            button.focus();
          });
        }

        primaryNavigation.querySelectorAll('[data-reg-submenu-toggle]').forEach((toggle) => {
          toggle.addEventListener('click', () => {
            const item = toggle.closest('.menu-item');
            if (!item) return;
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            if (item.parentElement?.classList.contains('primary-menu')) closeTopItems(item);
            setSubmenuOpen(item, open);
          });

          toggle.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowDown') return;
            const item = toggle.closest('.menu-item');
            const submenu = item ? directChild(item, '.submenu-wrap') : null;
            if (!item || !submenu) return;
            event.preventDefault();
            setSubmenuOpen(item, true);
            submenu.querySelector('a, button')?.focus();
          });
        });

        topItems.forEach((item) => {
          item.addEventListener('pointerenter', () => {
            if (mobileQuery.matches) return;
            cancelScheduledClose(item);
            closeSearches();
            closeTopItems(item);
            setSubmenuOpen(item, true);
          });
          item.addEventListener('pointerleave', () => {
            if (!mobileQuery.matches && !item.matches(':focus-within')) scheduleClose(item);
          });
          item.addEventListener('focusin', () => {
            if (mobileQuery.matches) return;
            cancelScheduledClose(item);
            closeSearches();
            closeTopItems(item);
            setSubmenuOpen(item, true);
          });
          item.addEventListener('focusout', (event) => {
            if (!mobileQuery.matches && !item.contains(event.relatedTarget)) scheduleClose(item);
          });
        });

        header.addEventListener('keydown', (event) => {
          if (event.key !== 'Escape') return;
          const openSearch = searchControls.find((search) => !search.querySelector('[data-reg-search-panel]')?.hidden);
          if (openSearch) {
            event.preventDefault();
            setSearchOpen(openSearch, false);
            openSearch.querySelector('[data-reg-search-toggle]')?.focus();
            return;
          }
          const activeItem = document.activeElement?.closest('.menu-item.is-submenu-open');
          if (activeItem) {
            event.preventDefault();
            const activeToggle = directChild(activeItem, '.menu-item__row')?.querySelector('[data-reg-submenu-toggle]');
            activeToggle?.focus();
            closeDescendants(activeItem);
          }
          else if (menu.classList.contains('is-open')) {
            event.preventDefault();
            setMobileMenuOpen(false);
            button.focus();
          }
        });

        menu.addEventListener('click', (event) => {
          if (mobileQuery.matches && event.target.closest('a')) setMobileMenuOpen(false);
        });

        document.addEventListener('click', (event) => {
          searchControls.forEach((search) => {
            if (!search.contains(event.target)) setSearchOpen(search, false);
          });
          if (!header.contains(event.target)) {
            closeTopItems();
            if (mobileQuery.matches) setMobileMenuOpen(false);
          }
        });

        mobileQuery.addEventListener('change', () => {
          setMobileMenuOpen(false);
          closeTopItems();
          closeSearches();
        });
      });

      once('reg-tabs', '[data-reg-tab]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const target = button.getAttribute('data-reg-tab');
          document.querySelectorAll('[data-reg-tab]').forEach((tab) => tab.classList.toggle('is-active', tab === button));
          document.querySelectorAll('[data-reg-tab-panel]').forEach((panel) => {
            panel.hidden = panel.getAttribute('data-reg-tab-panel') !== target;
          });
        });
      });

      once('reg-back-to-top', '[data-reg-back-to-top]', context).forEach((button) => {
        button.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
      });
    }
  };
})(Drupal, once);
