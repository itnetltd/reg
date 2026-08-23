(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.regFaqAssistant = {
    attach(context) {
      once('reg-faq-assistant', '[data-reg-assistant]', context).forEach((assistant) => {
        const toggle = assistant.querySelector('[data-reg-assistant-toggle]');
        const close = assistant.querySelector('[data-reg-assistant-close]');
        const panel = assistant.querySelector('[data-reg-assistant-panel]');
        const form = assistant.querySelector('[data-reg-assistant-form]');
        const input = assistant.querySelector('[data-reg-assistant-input]');
        const results = assistant.querySelector('[data-reg-assistant-results]');
        const topics = assistant.querySelectorAll('[data-reg-quick-topic]');
        const endpoint = assistant.dataset.searchUrl;

        const setOpen = (open) => {
          panel.hidden = !open;
          toggle.setAttribute('aria-expanded', String(open));
          assistant.classList.toggle('is-open', open);
          if (open) input.focus();
          else toggle.focus();
        };

        const appendLink = (container, linkData, fallbackLabel) => {
          if (!linkData || !linkData.url) return;
          const link = document.createElement('a');
          link.href = linkData.url;
          link.textContent = linkData.label || fallbackLabel;
          container.appendChild(link);
        };

        const showEscalation = (payload) => {
          const box = document.createElement('div');
          box.className = 'reg-assistant__escalation';
          const title = document.createElement('strong');
          title.textContent = Drupal.t('Approved support options');
          box.appendChild(title);
          if (payload.escalation) {
            appendLink(box, payload.escalation.call_center, Drupal.t('Call Center 2727'));
            (payload.escalation.links || []).forEach((link) => appendLink(box, link, Drupal.t('Open support service')));
          }
          results.appendChild(box);
        };

        const showResults = (payload) => {
          results.replaceChildren();
          results.removeAttribute('aria-busy');
          if (!payload.items || payload.items.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = payload.message || Drupal.t('No approved answer matched. Do not submit personal or account information here.');
            results.appendChild(empty);
            showEscalation(payload);
            return;
          }

          payload.items.slice(0, 5).forEach((item) => {
            const article = document.createElement('article');
            const category = document.createElement('small');
            const title = document.createElement('h3');
            const answer = document.createElement('p');
            category.textContent = item.category_label || '';
            title.textContent = item.question;
            answer.textContent = item.answer;
            article.append(category, title, answer);
            (item.related_links || (item.related_link ? [item.related_link] : []))
              .forEach((link) => appendLink(article, link, Drupal.t('Open related service')));
            appendLink(article, item.escalation_option, Drupal.t('Contact REG'));
            if (item.url) appendLink(article, { url: item.url, label: Drupal.t('View in all FAQs') }, Drupal.t('View in all FAQs'));
            results.appendChild(article);
          });
        };

        const runSearch = async (query, category = '') => {
          if (!category && query.length < 2) return;
          results.replaceChildren();
          results.setAttribute('aria-busy', 'true');
          const loading = document.createElement('p');
          loading.textContent = Drupal.t('Searching approved FAQs…');
          results.appendChild(loading);

          const url = new URL(endpoint, window.location.origin);
          if (query) url.searchParams.set('q', query);
          if (category) url.searchParams.set('category', category);
          try {
            const response = await fetch(url.toString(), {
              headers: { Accept: 'application/json' },
              credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok && response.status !== 429) throw new Error('FAQ search failed');
            showResults(payload);
          }
          catch (error) {
            results.replaceChildren();
            results.removeAttribute('aria-busy');
            const message = document.createElement('p');
            message.textContent = Drupal.t('The FAQ service is temporarily unavailable. Please use the official contact channels below.');
            results.appendChild(message);
          }
        };

        toggle.addEventListener('click', () => setOpen(panel.hidden));
        close.addEventListener('click', () => setOpen(false));
        panel.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') setOpen(false);
        });
        form.addEventListener('submit', (event) => {
          event.preventDefault();
          runSearch(input.value.trim());
        });
        topics.forEach((topic) => {
          topic.addEventListener('click', () => runSearch('', topic.dataset.regQuickTopic));
        });
      });
    }
  };
})(Drupal, once);
