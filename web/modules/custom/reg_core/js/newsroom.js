(function (Drupal, once) {
  'use strict';

  function track(eventName, type, entityId) {
    document.dispatchEvent(new CustomEvent('reg:analytics-track', {
      detail: {event: eventName, type: type || 'news', entityId: entityId || 0}
    }));
  }

  Drupal.behaviors.regNewsroom = {
    attach: function (context) {
      once('reg-news-copy-link', '[data-reg-copy-link]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const url = button.dataset.regCopyLink || window.location.href;
          const label = button.querySelector('[data-reg-copy-label]');
          const status = button.parentElement.querySelector('[data-reg-copy-status]');
          const complete = function () {
            if (label) {
              label.textContent = Drupal.t('Copied');
            }
            if (status) {
              status.textContent = Drupal.t('Article link copied to clipboard.');
            }
          };
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(complete).catch(function () {});
          }
          else {
            const field = document.createElement('textarea');
            field.value = url;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            try {
              document.execCommand('copy');
              complete();
            }
            catch (error) {}
            field.remove();
          }
        });
      });

      once('reg-news-filter-analytics', '[data-reg-news-filter-form]', context).forEach(function (form) {
        form.addEventListener('submit', function () {
          const search = form.querySelector('[name="search"]');
          const category = form.querySelector('[name="category"]');
          if (search && search.value.trim()) {
            track('news_search', 'news');
          }
          if (category && parseInt(category.value, 10) > 0) {
            track('news_category_filter', 'category');
          }
        });
      });
    }
  };
  const newsPairs = new WeakMap();

  Drupal.behaviors.regHomepageNewsPairs = {
    attach(context) {
      once('reg-news-pairs', '[data-reg-news-pairs]', context).forEach((column) => {
        const cards = Array.from(column.children).filter((el) => el.matches('.reg-news-card'));
        const template = column.querySelector('[data-reg-news-pair]');
        const controls = column.querySelector('[data-reg-news-pair-controls]');
        if (cards.length !== 2 || !template || !controls) return;
        const pages = [cards.map((card) => card.cloneNode(true)), Array.from(template.content.querySelectorAll('article'))];
        if (!pages[1].length) return;
        const buttons = Array.from(controls.querySelectorAll('[data-reg-news-page]'));
        const pause = controls.querySelector('[data-reg-news-pause]');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        let index = 0;
        let manualPaused = false;
        let hovered = false;
        let running = false;
        let autoTimer = null;
        let phaseTimer = null;
        let automaticPhase = false;
        let nextAt = performance.now() + 4000;
        let measuredWidth = 0;
        let disposed = false;
        const paused = () => manualPaused || hovered || document.hidden || reduced.matches || column.contains(document.activeElement);
        const clearAuto = () => { window.clearTimeout(autoTimer); autoTimer = null; };
        const cancelPhase = () => {
          window.clearTimeout(phaseTimer);
          phaseTimer = null;
          column.classList.remove('is-news-fading-out');
        };
        const render = () => {
          cards.forEach((card, slot) => {
            const source = pages[index][slot];
            // An incomplete last pair leaves an empty slot, never a made-up article.
            card.style.visibility = source ? '' : 'hidden';
            card.inert = !source;
            if (source) {
              card.removeAttribute('aria-hidden');
              card.replaceChildren(...Array.from(source.childNodes, (child) => child.cloneNode(true)));
            }
            else {
              card.setAttribute('aria-hidden', 'true');
              card.replaceChildren();
            }
          });
          buttons.forEach((button, page) => {
            if (page === index) button.setAttribute('aria-current', 'true');
            else button.removeAttribute('aria-current');
          });
        };
        const measure = () => {
          if (disposed) return;
          const grid = column.closest('.reg-news-homepage');
          // Measure the approved first pair off screen. No live nodes or focus move.
          const clone = grid.cloneNode(true);
          clone.setAttribute('aria-hidden', 'true');
          clone.inert = true;
          clone.querySelectorAll('[id]').forEach((el) => el.removeAttribute('id'));
          Object.assign(clone.style, {position: 'fixed', left: '0', top: '0', width: `${grid.getBoundingClientRect().width}px`, height: 'auto', visibility: 'hidden', pointerEvents: 'none'});
          const copy = clone.querySelector('[data-reg-news-pairs]');
          copy.classList.remove('is-news-fading-out');
          copy.removeAttribute('data-reg-news-pairs');
          copy.replaceChildren(...pages[0].map((card) => card.cloneNode(true)));
          grid.parentElement.appendChild(clone);
          const heights = Array.from(copy.children, (card) => card.getBoundingClientRect().height);
          clone.remove();
          cards.forEach((card, slot) => {
            card.style.boxSizing = 'border-box';
            card.style.height = `${heights[slot]}px`;
          });
          measuredWidth = column.getBoundingClientRect().width;
        };
        const arm = () => {
          if (disposed || paused() || autoTimer !== null || phaseTimer !== null) return;
          autoTimer = window.setTimeout(() => {
            autoTimer = null;
            if (!paused()) change(1 - index, true);
          }, Math.max(0, nextAt - performance.now() - 150));
        };
        const reconcile = () => {
          if (paused()) {
            clearAuto();
            if (automaticPhase) cancelPhase();
            running = false;
          }
          else {
            if (!running) nextAt = performance.now() + 4000;
            running = true;
            arm();
          }
          pause.hidden = reduced.matches;
        };
        const change = (target, automatic = false) => {
          if (disposed || target === index) return;
          clearAuto();
          cancelPhase();
          automaticPhase = automatic;
          const commit = () => {
            phaseTimer = null;
            if (disposed || (automatic && paused())) { reconcile(); return; }
            index = target;
            render();
            nextAt = performance.now() + 4000;
            column.classList.remove('is-news-fading-out');
            if (reduced.matches) { automaticPhase = false; reconcile(); return; }
            phaseTimer = window.setTimeout(() => {
              phaseTimer = null;
              automaticPhase = false;
              reconcile();
            }, 150);
          };
          if (reduced.matches) commit();
          else {
            column.classList.add('is-news-fading-out');
            phaseTimer = window.setTimeout(commit, 150);
          }
        };
        const navigationHandlers = buttons.map((button, page) => {
          const handler = () => change(page);
          button.addEventListener('click', handler);
          return handler;
        });
        const togglePause = () => {
          manualPaused = !manualPaused;
          pause.setAttribute('aria-pressed', String(manualPaused));
          pause.setAttribute('aria-label', manualPaused ? Drupal.t('Resume news rotation') : Drupal.t('Pause news rotation'));
          reconcile();
        };
        pause.addEventListener('click', togglePause);
        const enter = () => { hovered = true; reconcile(); };
        const leave = () => { hovered = false; reconcile(); };
        const focusOut = () => window.queueMicrotask(reconcile);
        const keydown = (event) => {
          if (event.target !== column && !controls.contains(event.target)) return;
          if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          change(event.key === 'Home' ? 0 : event.key === 'End' ? 1 : 1 - index);
        };
        column.addEventListener('mouseenter', enter);
        column.addEventListener('mouseleave', leave);
        column.addEventListener('focusin', reconcile);
        column.addEventListener('focusout', focusOut);
        column.addEventListener('keydown', keydown);
        document.addEventListener('visibilitychange', reconcile);
        reduced.addEventListener('change', reconcile);
        column.tabIndex = 0;
        controls.hidden = false;
        measure();
        const observer = new ResizeObserver(() => {
          if (Math.abs(column.getBoundingClientRect().width - measuredWidth) > 0.1) measure();
        });
        observer.observe(column);
        if (document.fonts) document.fonts.ready.then(() => { if (!disposed) measure(); });
        reconcile();
        newsPairs.set(column, () => {
          disposed = true;
          clearAuto();
          cancelPhase();
          observer.disconnect();
          document.removeEventListener('visibilitychange', reconcile);
          reduced.removeEventListener('change', reconcile);
          column.removeEventListener('mouseenter', enter);
          column.removeEventListener('mouseleave', leave);
          column.removeEventListener('focusin', reconcile);
          column.removeEventListener('focusout', focusOut);
          column.removeEventListener('keydown', keydown);
          buttons.forEach((button, page) => button.removeEventListener('click', navigationHandlers[page]));
          pause.removeEventListener('click', togglePause);
          index = 0;
          render();
          cards.forEach((card) => { card.style.height = ''; card.style.boxSizing = ''; });
          controls.hidden = true;
          column.removeAttribute('tabindex');
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('reg-news-pairs', '[data-reg-news-pairs]', context).forEach((column) => {
          if (newsPairs.has(column)) newsPairs.get(column)();
          newsPairs.delete(column);
        });
      }
    }
  };

  // Social rotation owns only the right column; news keeps its independent clock.
  const socialPairs = new WeakMap();
  Drupal.behaviors.regHomepageSocialPairs = {
    attach(context) {
      once('reg-social-pairs', '[data-reg-social-pairs]', context).forEach((column) => {
        const initial = Array.from(column.children).filter((el) => el.matches('[data-reg-social-card]'));
        const controls = column.querySelector('[data-reg-social-controls]');
        const templates = Array.from(column.querySelectorAll('[data-reg-social-pair]'));
        if (initial.length !== 2 || !controls || !templates.length) return;
        const pages = [initial, ...templates.map((template) => Array.from(template.content.children, (card) => card.cloneNode(true)))];
        if (pages.some((pair) => pair.length !== 2)) return;
        const buttons = Array.from(controls.querySelectorAll('[data-reg-social-page]'));
        const pause = controls.querySelector('[data-reg-social-pause]');
        const status = controls.querySelector('[data-reg-social-status]');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        const automaticEnabled = column.dataset.socialAuto === 'true';
        const interval = Math.max(3, Math.min(60, Number(column.dataset.socialInterval) || 5)) * 1000;
        let index = 0;
        let manualPaused = false;
        let hovered = false;
        let disposed = false;
        let running = false;
        let autoTimer = null;
        let phaseTimer = null;
        let automaticPhase = false;
        let nextAt = performance.now() + interval;
        let measuredWidth = 0;
        let measuredMode = '';
        const paused = () => !automaticEnabled || manualPaused || hovered || document.hidden || reduced.matches || column.contains(document.activeElement);
        const clearAuto = () => { window.clearTimeout(autoTimer); autoTimer = null; };
        const cancelPhase = () => {
          window.clearTimeout(phaseTimer);
          phaseTimer = null;
          automaticPhase = false;
          column.classList.remove('is-social-fading-out');
        };
        const render = (manual = false) => {
          // Retain each pair's real DOM nodes and X carousel state/listeners.
          Array.from(column.children).filter((el) => el.matches('[data-reg-social-card]')).forEach((card) => card.remove());
          const anchor = column.querySelector('template, [data-reg-social-controls]');
          pages[index].forEach((card) => column.insertBefore(card, anchor));
          buttons.forEach((button, page) => {
            if (page === index) button.setAttribute('aria-current', 'true');
            else button.removeAttribute('aria-current');
          });
          column.dataset.socialPage = String(index);
          Drupal.attachBehaviors(column);
          if (manual) status.textContent = Drupal.t('Social pair @current of @total', {'@current': index + 1, '@total': pages.length});
        };
        const measure = () => {
          if (disposed) return;
          const grid = column.closest('.reg-news-homepage');
          const clone = grid.cloneNode(true);
          clone.setAttribute('aria-hidden', 'true');
          clone.inert = true;
          clone.querySelectorAll('[id]').forEach((el) => el.removeAttribute('id'));
          Object.assign(clone.style, {position: 'fixed', left: '0', top: '0', width: `${grid.getBoundingClientRect().width}px`, height: 'auto', visibility: 'hidden', pointerEvents: 'none'});
          const copy = clone.querySelector('[data-reg-social-pairs]');
          copy.classList.remove('is-social-fading-out');
          copy.removeAttribute('data-reg-social-pairs');
          copy.replaceChildren(...pages[0].map((card) => {
            const element = card.cloneNode(true);
            element.style.height = '';
            element.style.boxSizing = '';
            return element;
          }));
          copy.querySelectorAll('[id]').forEach((el) => el.removeAttribute('id'));
          // Other columns are measured naturally; their live nodes never change.
          clone.querySelectorAll('.reg-news-card').forEach((card) => { card.style.height = ''; });
          grid.parentElement.appendChild(clone);
          const heights = Array.from(copy.children, (card) => card.getBoundingClientRect().height);
          const columns = getComputedStyle(copy).gridTemplateColumns.trim().split(/\s+/).length;
          clone.remove();
          pages.forEach((pair) => pair.forEach((card, slot) => {
            card.style.boxSizing = 'border-box';
            card.style.height = `${heights[slot]}px`;
          }));
          const gap = parseFloat(getComputedStyle(column).rowGap) || 16;
          controls.style.top = columns > 1 ? '20px' : `${heights[0] + gap / 2}px`;
          measuredWidth = column.getBoundingClientRect().width;
          measuredMode = getComputedStyle(column).gridTemplateColumns;
        };
        const arm = () => {
          if (disposed || paused() || autoTimer !== null || phaseTimer !== null) return;
          autoTimer = window.setTimeout(() => {
            autoTimer = null;
            if (!paused()) change((index + 1) % pages.length, true);
          }, Math.max(0, nextAt - performance.now() - 150));
        };
        const reconcile = () => {
          if (paused()) {
            clearAuto();
            if (automaticPhase) cancelPhase();
            running = false;
          }
          else {
            if (!running) nextAt = performance.now() + interval;
            running = true;
            arm();
          }
          pause.hidden = !automaticEnabled || reduced.matches;
        };
        const change = (target, automatic = false) => {
          if (disposed || target === index) return;
          clearAuto();
          cancelPhase();
          automaticPhase = automatic;
          const commit = () => {
            phaseTimer = null;
            if (disposed || (automatic && paused())) { reconcile(); return; }
            index = target;
            render(!automatic);
            // Establish opacity 0 on inserted roots before starting the fade in.
            if (!reduced.matches) pages[index].forEach((card) => { void getComputedStyle(card).opacity; });
            nextAt = performance.now() + interval;
            column.classList.remove('is-social-fading-out');
            if (reduced.matches) { automaticPhase = false; reconcile(); return; }
            phaseTimer = window.setTimeout(() => { phaseTimer = null; automaticPhase = false; reconcile(); }, 150);
          };
          if (reduced.matches) commit();
          else { column.classList.add('is-social-fading-out'); phaseTimer = window.setTimeout(commit, 150); }
        };
        const navigationHandlers = buttons.map((button, page) => {
          const handler = () => change(page);
          button.addEventListener('click', handler);
          return handler;
        });
        const previous = controls.querySelector('[data-reg-social-previous]');
        const next = controls.querySelector('[data-reg-social-next]');
        const goPrevious = () => change((index + pages.length - 1) % pages.length);
        const goNext = () => change((index + 1) % pages.length);
        previous.addEventListener('click', goPrevious);
        next.addEventListener('click', goNext);
        const togglePause = () => {
          manualPaused = !manualPaused;
          pause.setAttribute('aria-pressed', String(manualPaused));
          pause.setAttribute('aria-label', manualPaused ? Drupal.t('Resume social rotation') : Drupal.t('Pause social rotation'));
          reconcile();
        };
        const enter = () => { hovered = true; reconcile(); };
        const leave = () => { hovered = false; reconcile(); };
        const focusOut = () => window.queueMicrotask(reconcile);
        const keydown = (event) => {
          if (event.target !== column && !controls.contains(event.target)) return;
          if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          change(event.key === 'Home' ? 0 : event.key === 'End' ? pages.length - 1 : (index + (event.key === 'ArrowLeft' ? pages.length - 1 : 1)) % pages.length);
        };
        const resized = () => {
          if (Math.abs(column.getBoundingClientRect().width - measuredWidth) > 0.1 || getComputedStyle(column).gridTemplateColumns !== measuredMode) measure();
        };
        pause.addEventListener('click', togglePause);
        column.addEventListener('mouseenter', enter);
        column.addEventListener('mouseleave', leave);
        column.addEventListener('focusin', reconcile);
        column.addEventListener('focusout', focusOut);
        column.addEventListener('keydown', keydown);
        document.addEventListener('visibilitychange', reconcile);
        reduced.addEventListener('change', reconcile);
        const observer = new ResizeObserver(resized);
        observer.observe(column);
        column.tabIndex = 0;
        column.dataset.socialPage = '0';
        controls.hidden = false;
        measure();
        if (document.fonts) document.fonts.ready.then(() => { if (!disposed) measure(); });
        reconcile();
        socialPairs.set(column, () => {
          disposed = true;
          clearAuto();
          cancelPhase();
          observer.disconnect();
          document.removeEventListener('visibilitychange', reconcile);
          reduced.removeEventListener('change', reconcile);
          column.removeEventListener('mouseenter', enter);
          column.removeEventListener('mouseleave', leave);
          column.removeEventListener('focusin', reconcile);
          column.removeEventListener('focusout', focusOut);
          column.removeEventListener('keydown', keydown);
          buttons.forEach((button, page) => button.removeEventListener('click', navigationHandlers[page]));
          pause.removeEventListener('click', togglePause);
          previous.removeEventListener('click', goPrevious);
          next.removeEventListener('click', goNext);
          index = 0;
          render();
          pages.forEach((pair) => pair.forEach((card) => { card.style.height = ''; card.style.boxSizing = ''; }));
          controls.hidden = true;
          column.removeAttribute('tabindex');
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('reg-social-pairs', '[data-reg-social-pairs]', context).forEach((column) => {
          if (socialPairs.has(column)) socialPairs.get(column)();
          socialPairs.delete(column);
        });
      }
    }
  };
})(Drupal, once);
