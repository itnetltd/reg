(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.regHomepageCounters = {
    attach(context) {
      const counters = once('reg-counter', '.js-reg-counter', context);
      if (counters.length === 0) return;

      const formatter = (decimals) => new Intl.NumberFormat(document.documentElement.lang || undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
      const finalValue = (counter) => {
        const target = Number.parseFloat(counter.dataset.counterValue || '');
        const decimals = Number.parseInt(counter.dataset.counterDecimals || '0', 10);
        if (!Number.isFinite(target) || !Number.isInteger(decimals) || decimals < 0) return null;
        return { target, decimals, format: formatter(decimals) };
      };
      const showFinal = (counter) => {
        const value = finalValue(counter);
        if (value) counter.textContent = value.format.format(value.target);
      };

      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
        counters.forEach(showFinal);
        return;
      }

      const sections = new Map();
      counters.forEach((counter) => {
        const section = counter.closest('[data-reg-counter-group], .homepage-stats');
        if (!section) {
          showFinal(counter);
          return;
        }
        if (!sections.has(section)) sections.set(section, []);
        sections.get(section).push(counter);
      });

      sections.forEach((sectionCounters, section) => {
        const observer = new IntersectionObserver((entries) => {
          if (!entries.some((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.25)) return;
          observer.unobserve(section);
          sectionCounters.forEach((counter, index) => {
            const value = finalValue(counter);
            if (!value) return;
            window.setTimeout(() => {
              const duration = 1800;
              const started = performance.now();
              const update = (now) => {
                const progress = Math.min((now - started) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const current = value.decimals === 0
                  ? Math.round(value.target * eased)
                  : value.target * eased;
                counter.textContent = value.format.format(progress === 1 ? value.target : current);
                if (progress < 1) window.requestAnimationFrame(update);
              };
              window.requestAnimationFrame(update);
            }, index * 80);
          });
        }, { threshold: [0.25] });
        observer.observe(section);
      });
    },
  };
})(Drupal, once);
