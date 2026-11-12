(function (Drupal, once) {
  'use strict';

  let widgetScript;

  // X is a third party: fetch its widget JavaScript only after visitor action.
  function loadOfficialWidget() {
    if (window.twttr && window.twttr.widgets && window.twttr.widgets.load) {
      return Promise.resolve();
    }
    if (!widgetScript) {
      widgetScript = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://platform.x.com/widgets.js';
        script.async = true;
        script.onload = resolve;
        script.onerror = () => {
          widgetScript = null;
          reject(new Error('X widget could not be loaded'));
        };
        document.head.appendChild(script);
      });
    }
    return widgetScript;
  }

  Drupal.behaviors.regXFreeEmbed = {
    attach(context) {
      once('reg-x-free-embed', '[data-reg-x-embed]', context).forEach((container) => {
        const button = container.querySelector('[data-reg-x-embed-load]');
        const frame = container.querySelector('[data-reg-x-embed-frame]');
        if (!button || !frame) return;

        button.addEventListener('click', () => {
          const profile = container.dataset.regXEmbedProfile || '';
          let url;
          try {
            url = new URL(profile);
          }
          catch (error) {
            return;
          }
          if (url.protocol !== 'https:' || url.hostname !== 'x.com' || !/^\/[A-Za-z0-9_]{1,15}$/.test(url.pathname)) return;

          button.disabled = true;
          button.hidden = true;
          frame.hidden = false;
          const link = document.createElement('a');
          link.className = 'twitter-timeline';
          link.href = url.href;
          link.dataset.height = '230';
          link.dataset.theme = 'light';
          link.dataset.dnt = 'true';
          link.dataset.chrome = 'noheader nofooter';
          link.textContent = Drupal.t('View the REG timeline on X');
          frame.replaceChildren(link);

          const card = container.closest('[data-reg-social-card]');
          if (card) card.dataset.regXEmbedLoaded = 'true';

          loadOfficialWidget()
            .then(() => {
              if (window.twttr && window.twttr.widgets && window.twttr.widgets.load) {
                return window.twttr.widgets.load(frame);
              }
              throw new Error('X widget unavailable');
            })
            .catch(() => {
              // The always-visible official profile link remains a reliable fallback.
              frame.replaceChildren(document.createTextNode(Drupal.t('X posts cannot be displayed in this browser. Use the official profile link below.')));
            });
        }, { once: true });
      });
    }
  };
})(Drupal, once);
