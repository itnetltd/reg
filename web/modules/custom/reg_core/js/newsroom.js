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
})(Drupal, once);
