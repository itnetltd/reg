(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.regPublicAlertCarousel = {
    attach(context) {
      once('reg-public-alert-carousel', '[data-reg-alert-carousel]', context).forEach((carousel) => {
        const slides = Array.from(carousel.querySelectorAll('[data-reg-alert-slide]'));
        const previous = carousel.querySelector('[data-reg-alert-prev]');
        const next = carousel.querySelector('[data-reg-alert-next]');
        const pause = carousel.querySelector('[data-reg-alert-pause]');
        const status = carousel.querySelector('[data-reg-alert-status]');
        if (slides.length < 2 || !previous || !next || !pause) return;

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let activeIndex = 0;
        let timer = null;
        let manuallyPaused = reducedMotion;
        let pointerPaused = false;
        let focusPaused = false;

        const stop = () => {
          if (timer !== null) window.clearTimeout(timer);
          timer = null;
        };
        const updatePauseControl = () => {
          const label = manuallyPaused ? pause.dataset.labelPlay : pause.dataset.labelPause;
          pause.setAttribute('aria-label', label);
          pause.querySelector('span').textContent = manuallyPaused ? '\u25B6' : '\u275A\u275A';
        };
        const canAutoplay = () => !manuallyPaused && !pointerPaused && !focusPaused && !document.hidden;
        const schedule = () => {
          stop();
          if (!canAutoplay()) return;
          const delay = Number.parseInt(slides[activeIndex].dataset.alertDuration, 10) || 9000;
          timer = window.setTimeout(() => show(activeIndex + 1, false), delay);
        };
        const show = (requestedIndex, announce = true) => {
          activeIndex = (requestedIndex + slides.length) % slides.length;
          slides.forEach((slide, index) => {
            const active = index === activeIndex;
            slide.classList.toggle('is-active', active);
            slide.setAttribute('aria-hidden', String(!active));
            slide.toggleAttribute('inert', !active);
          });
          if (announce && status) status.textContent = Drupal.t('Notice @current of @total: @title', {
            '@current': activeIndex + 1,
            '@total': slides.length,
            '@title': slides[activeIndex].querySelector('strong')?.textContent.trim() || '',
          });
          schedule();
        };

        previous.addEventListener('click', () => show(activeIndex - 1));
        next.addEventListener('click', () => show(activeIndex + 1));
        pause.addEventListener('click', () => {
          manuallyPaused = !manuallyPaused;
          updatePauseControl();
          schedule();
        });
        carousel.addEventListener('pointerenter', () => { pointerPaused = true; stop(); });
        carousel.addEventListener('pointerleave', () => { pointerPaused = false; schedule(); });
        carousel.addEventListener('focusin', () => { focusPaused = true; stop(); });
        carousel.addEventListener('focusout', (event) => {
          if (carousel.contains(event.relatedTarget)) return;
          focusPaused = false;
          schedule();
        });
        carousel.addEventListener('keydown', (event) => {
          if (event.key === 'ArrowLeft') { event.preventDefault(); show(activeIndex - 1); }
          if (event.key === 'ArrowRight') { event.preventDefault(); show(activeIndex + 1); }
        });
        document.addEventListener('visibilitychange', schedule);
        updatePauseControl();
        schedule();
      });
    },
  };
})(Drupal, once);
