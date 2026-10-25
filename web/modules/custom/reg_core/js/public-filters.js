(function (Drupal, once) {
  'use strict';
  let filterId = 0;
  Drupal.behaviors.regCompactFilters = {
    attach(context) {
      once('reg-compact-filters', '[data-reg-compact-filters]', context).forEach((form) => {
        const fields = Array.from(form.querySelectorAll('input[name]:not([type=hidden]):not([type=submit]), select[name]'));
        const primary = fields.find((field) => field.name === form.dataset.regCompactFilters) || fields[0];
        const submit = form.querySelector('[type="submit"]');
        if (!primary || !submit) return;
        const labels = new Map(fields.map((field) => {
          const label = field.labels?.[0];
          const copy = label?.cloneNode(true);
          copy?.querySelectorAll('input, select, option').forEach((child) => child.remove());
          return [field, copy?.textContent.trim() || field.getAttribute('aria-label') || field.name];
        }));
        const visibleNames = (form.dataset.regVisibleFilters || '').split(' ');
        const geographic = document.createElement('div');
        geographic.className = 'reg-filter-geography';
        const toolbar = document.createElement('div');
        toolbar.className = 'reg-filter-toolbar';
        const panel = document.createElement('div');
        panel.className = 'reg-filter-panel';
        panel.id = 'reg-advanced-filters-' + (++filterId);
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-label', Drupal.t('Advanced filters'));
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'reg-filter-toggle';
        toggle.textContent = Drupal.t('Filters');
        toggle.setAttribute('aria-controls', panel.id);
        const setOpen = (open) => {
          panel.hidden = !open;
          toggle.setAttribute('aria-expanded', String(open));
        };
        toggle.addEventListener('click', () => setOpen(panel.hidden));
        panel.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            setOpen(false);
            toggle.focus();
          }
        });
        fields.forEach((field) => {
          let wrapper = field.parentElement;
          if (wrapper === form) {
            wrapper = document.createElement('div');
            const label = field.labels?.[0];
            form.insertBefore(wrapper, field);
            if (label) wrapper.append(label);
            wrapper.append(field);
          }
          wrapper.classList.add('reg-filter-field');
          if (field === primary) {
            wrapper.classList.add('reg-filter-primary');
            const label = field.labels?.[0];
            if (label === wrapper) {
              // Keep an implicit label around its input; hide only the caption.
              [...label.childNodes].forEach((node) => {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                  const caption = document.createElement('span');
                  caption.className = 'visually-hidden';
                  node.replaceWith(caption);
                  caption.append(node);
                }
                else if (node.nodeType === Node.ELEMENT_NODE && node !== field) node.classList.add('visually-hidden');
              });
            }
            else label?.classList.add('visually-hidden');
            toolbar.append(wrapper);
          }
          else if (visibleNames.includes(field.name)) geographic.append(wrapper);
          else panel.append(wrapper);
        });
        if (panel.children.length) toolbar.append(toggle);
        else toolbar.classList.add('reg-filter-toolbar--simple');
        if (!visibleNames.includes('province')) submit.textContent = primary.type === 'search' ? Drupal.t('Search') : Drupal.t('Apply');
        toolbar.append(submit);
        // Remove emptied legacy action/disclosure wrappers, not form fields.
        form.querySelectorAll('.reg-filter-actions, .reg-filter-more').forEach((element) => {
          if (!element.querySelector('input, select, button')) element.remove();
        });
        form.querySelectorAll('.reg-filter-clear').forEach((element) => element.remove());
        form.append(toolbar);
        if (geographic.children.length) {
          toolbar.classList.add('reg-filter-toolbar--geographic');
          toolbar.insertBefore(geographic, toggle.parentElement === toolbar ? toggle : submit);
        }
        if (panel.children.length) form.append(panel);
        form.classList.add('reg-filter-bar--disclosure');
        const chips = document.createElement('div');
        chips.className = 'reg-filter-active';
        chips.setAttribute('aria-label', Drupal.t('Active filters'));
        const url = new URL(window.location.href);
        fields.forEach((field) => {
          if (!url.searchParams.has(field.name) || !field.value || field.value === '0' || (field.name === 'sort' && field.value === 'newest')) return;
          const label = labels.get(field);
          const value = field.options ? field.selectedOptions[0]?.textContent || field.value : field.value;
          const removeUrl = new URL(url);
          removeUrl.searchParams.delete(field.name);
          removeUrl.searchParams.delete('page');
          const chip = document.createElement('a');
          chip.href = removeUrl.pathname + removeUrl.search;
          chip.textContent = label + ': ' + value + ' ×';
          chip.setAttribute('aria-label', Drupal.t('Remove @label: @value', {'@label': label, '@value': value}));
          chips.append(chip);
        });
        setOpen(chips.children.length > 0);
        if (chips.children.length) {
          const clear = document.createElement('a');
          clear.href = url.pathname;
          clear.className = 'reg-filter-clear';
          clear.textContent = Drupal.t('Clear filters');
          chips.append(clear);
          form.append(chips);
        }
      });
    }
  };
})(Drupal, once);
