(function (Drupal, once) {
  'use strict';

  let widgetScript;

  // Contact X only when the visitor explicitly chooses to load the timeline.
  function loadOfficialWidget() {
    if (window.twttr && window.twttr.widgets && window.twttr.widgets.createTimeline) {
      return Promise.resolve(window.twttr);
    }
    if (!widgetScript) {
      widgetScript = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        // Use the host distributed by the official X Publish embed code.
        script.src = 'https://platform.twitter.com/widgets.js';
        script.async = true;
        script.onload = () => {
          if (window.twttr && typeof window.twttr.ready === 'function') {
            window.twttr.ready(resolve);
          }
          else if (window.twttr && window.twttr.widgets) {
            resolve(window.twttr);
          }
          else {
            reject(new Error('X widget API did not initialize'));
          }
        };
        script.onerror = () => reject(new Error('X widget script could not load'));
        document.head.appendChild(script);
      }).catch((error) => {
        widgetScript = null;
        throw error;
      });
    }
    return widgetScript;
  }

  Drupal.behaviors.regXFreeEmbed = {
    attach(context) {
      once('reg-x-free-embed', '[data-reg-x-embed]', context).forEach((container) => {
        const button = container.querySelector('[data-reg-x-embed-load]');
        const frame = container.querySelector('[data-reg-x-embed-frame]');
        const target = container.querySelector('[data-reg-x-embed-target]');
        const status = container.querySelector('[data-reg-x-embed-status]');
        if (!button || !frame || !target || !status) return;

        button.addEventListener('click', () => {
          let url;
          try {
            url = new URL(container.dataset.regXEmbedProfile || '');
          }
          catch (error) {
            return;
          }
          if (url.protocol !== 'https:' || url.hostname !== 'x.com' || !/^\/[A-Za-z0-9_]{1,15}$/.test(url.pathname)) return;

          button.disabled = true;
          button.hidden = true;
          container.classList.add('is-loaded');
          frame.hidden = false;
          status.textContent = Drupal.t('Loading the official X timeline…');
          status.hidden = false;
          const card = container.closest('[data-reg-social-card]');
          if (card) card.dataset.regXEmbedLoaded = 'true';

          let completed = false;
          // The X service can leave a bare link indefinitely without notifying us.
          const fallback = (message) => {
            if (completed) return;
            completed = true;
            window.clearTimeout(deadline);
            status.hidden = false;
            status.textContent = message || Drupal.t('X could not display its timeline here. Open the official REG X profile using the link below.');
          };
          const deadline = window.setTimeout(() => {
            if (completed) return;
            if (target.querySelector('iframe')) {
              // Cross-origin iframe contents are unreadable. Never claim posts
              // loaded successfully just because X created an iframe.
              fallback(Drupal.t('If the timeline remains blank, X may be restricting embedded posts. Use the official profile link below.'));
            }
            else {
              fallback(Drupal.t('X did not load its timeline. It may be blocked by browser privacy settings, site security policy or X itself.'));
            }
          }, 10000);

          loadOfficialWidget()
            .then((twttr) => {
              if (!twttr.widgets || typeof twttr.widgets.createTimeline !== 'function') {
                throw new Error('X timeline creation is unavailable');
              }
              // Explicit widget creation avoids the no-op dynamic-link scan
              // that previously left only "View the REG timeline on X" text.
              return twttr.widgets.createTimeline(
                { sourceType: 'profile', screenName: url.pathname.slice(1) },
                target,
                { height: 210, theme: 'light', chrome: 'noheader nofooter', dnt: true }
              );
            })
            .then(() => {
              if (completed) return;
              if (!target.querySelector('iframe')) {
                fallback(Drupal.t('X did not return a displayable timeline. Use the official profile link below.'));
                return;
              }
              // An iframe does not guarantee that X supplied posts. Keep a
              // small, visible explanation for browsers that show it blank.
              completed = true;
              window.clearTimeout(deadline);
              status.textContent = Drupal.t('If the timeline is blank, open REG on X using the link below.');
            })
            .catch(() => {
              fallback(Drupal.t('The official X timeline could not be loaded. It may be blocked by your browser or REG site security settings.'));
            });
        }, { once: true });
      });
    }
  };
})(Drupal, once);
