(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.regHomepageHeroCarousel = {
    attach(context) {
      once('reg-homepage-hero-carousel', '[data-reg-hero-carousel]', context).forEach((carousel) => {
        const slides = Array.from(carousel.querySelectorAll('[data-reg-hero-slide]'));
        const indicators = Array.from(carousel.querySelectorAll('[data-reg-hero-indicator]'));
        const previous = carousel.querySelector('[data-reg-hero-prev]');
        const next = carousel.querySelector('[data-reg-hero-next]');
        const pause = carousel.querySelector('[data-reg-hero-pause]');
        const status = carousel.querySelector('[data-reg-hero-status]');
        if (slides.length < 2 || !previous || !next || !pause) return;

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let activeIndex = 0;
        let timer = null;
        let manuallyPaused = reducedMotion;
        let pointerPaused = false;
        let focusPaused = false;
        let touchStartX = null;

        const updatePauseControl = () => {
          const label = manuallyPaused ? pause.dataset.labelPlay : pause.dataset.labelPause;
          pause.setAttribute('aria-label', label);
          pause.querySelector('span').textContent = manuallyPaused ? '\u25B6' : '\u275A\u275A';
        };
        const stop = () => {
          if (timer !== null) window.clearTimeout(timer);
          timer = null;
        };
        const canAutoplay = () => !manuallyPaused && !pointerPaused && !focusPaused && !document.hidden;
        const schedule = () => {
          stop();
          if (canAutoplay()) timer = window.setTimeout(() => show(activeIndex + 1, false), 7000);
        };
        const show = (requestedIndex, announce = true) => {
          activeIndex = (requestedIndex + slides.length) % slides.length;
          slides.forEach((slide, index) => {
            const active = index === activeIndex;
            slide.classList.toggle('is-active', active);
            slide.setAttribute('aria-hidden', String(!active));
            slide.toggleAttribute('inert', !active);
          });
          indicators.forEach((indicator, index) => indicator.setAttribute('aria-current', String(index === activeIndex)));
          if (announce && status) status.textContent = Drupal.t('Slide @current of @total: @title', {
            '@current': activeIndex + 1,
            '@total': slides.length,
            '@title': slides[activeIndex].querySelector('h1')?.textContent.trim() || '',
          });
          schedule();
        };

        previous.addEventListener('click', () => show(activeIndex - 1));
        next.addEventListener('click', () => show(activeIndex + 1));
        indicators.forEach((indicator) => indicator.addEventListener('click', () => show(Number.parseInt(indicator.dataset.slideIndex, 10))));
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
        carousel.addEventListener('touchstart', (event) => { touchStartX = event.changedTouches[0]?.clientX ?? null; }, { passive: true });
        carousel.addEventListener('touchend', (event) => {
          if (touchStartX === null) return;
          const distance = (event.changedTouches[0]?.clientX ?? touchStartX) - touchStartX;
          touchStartX = null;
          if (Math.abs(distance) >= 50) show(activeIndex + (distance < 0 ? 1 : -1));
        }, { passive: true });
        document.addEventListener('visibilitychange', schedule);
        updatePauseControl();
        schedule();
      });
    },
  };
})(Drupal, once);
