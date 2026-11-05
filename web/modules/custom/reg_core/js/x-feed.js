(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.regXCarousel = {
    attach(context) {
      once('reg-x-carousel', '[data-reg-x-feed]', context).forEach((feed) => {
        const track = feed.querySelector('.reg-x-feed__posts');
        const controls = feed.querySelector('.reg-x-feed__controls');
        if (!track || !controls) return;
        const slides = Array.from(track.children);
        const previous = controls.querySelector('[data-x-prev]');
        const next = controls.querySelector('[data-x-next]');
        const status = controls.querySelector('[data-x-status]');
        let index = 0;
        const update = () => {
          index = slides.reduce((best, slide, i) => Math.abs(slide.getBoundingClientRect().left - track.getBoundingClientRect().left) < Math.abs(slides[best].getBoundingClientRect().left - track.getBoundingClientRect().left) ? i : best, 0);
          previous.disabled = index === 0;
          next.disabled = index === slides.length - 1;
          status.textContent = Drupal.t('@current of @total', { '@current': index + 1, '@total': slides.length });
        };
        const move = (target) => {
          const slide = slides[Math.max(0, Math.min(slides.length - 1, target))];
          track.scrollBy({ left: slide.getBoundingClientRect().left - track.getBoundingClientRect().left, behavior: 'instant' });
          update();
        };
        previous.addEventListener('click', () => move(index - 1));
        next.addEventListener('click', () => move(index + 1));
        track.addEventListener('keydown', (event) => {
          if (event.target !== track || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          move(event.key === 'Home' ? 0 : event.key === 'End' ? slides.length - 1 : index + (event.key === 'ArrowRight' ? 1 : -1));
        });
        track.addEventListener('scroll', update, { passive: true });
        track.addEventListener('focusin', (event) => {
          const slide = event.target.closest('.reg-x-feed__post');
          if (slide) move(slides.indexOf(slide));
        });
        controls.hidden = false;
        update();
      });
    }
  };
})(Drupal, once);
