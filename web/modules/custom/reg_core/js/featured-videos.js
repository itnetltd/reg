(function (Drupal, once) {
  'use strict';

  const analytics = (event, item) => {
    document.dispatchEvent(new CustomEvent('reg:analytics-track', {
      detail: {
        event,
        entityId: parseInt(item?.dataset.regVideoId || '0', 10) || 0,
        type: 'video'
      }
    }));
  };

  const modalController = (context) => {
    const modal = context.querySelector('[data-reg-video-modal]');
    if (!modal) return null;
    const closeButton = modal.querySelector('[data-reg-video-modal-close]');
    const backdrop = modal.querySelector('[data-reg-video-modal-backdrop]');
    const player = modal.querySelector('[data-reg-video-player]');
    const title = modal.querySelector('[data-reg-video-modal-title]');
    const external = modal.querySelector('[data-reg-video-external]');
    const externalLink = modal.querySelector('[data-reg-video-external-link]');
    let returnFocus = null;
    let activeItem = null;

    const focusable = () => Array.from(modal.querySelectorAll('a[href], button:not([disabled]), iframe, video[controls], [tabindex]:not([tabindex="-1"])'))
      .filter((element) => !element.hidden && element.offsetParent !== null);

    const clearPlayer = () => {
      player.replaceChildren();
      external.hidden = true;
      externalLink.removeAttribute('href');
    };

    const close = () => {
      if (modal.hidden) return;
      modal.hidden = true;
      document.body.classList.remove('reg-video-modal-open');
      clearPlayer();
      const target = returnFocus;
      returnFocus = null;
      activeItem = null;
      target?.focus();
    };

    const open = (item, trigger) => {
      clearPlayer();
      returnFocus = trigger;
      activeItem = item;
      title.textContent = item.dataset.regVideoTitle || '';
      const embedUrl = item.dataset.regVideoEmbedUrl || '';
      const directUrl = item.dataset.regVideoDirectUrl || '';
      const publicUrl = item.dataset.regVideoUrl || '';

      if (embedUrl) {
        const iframe = document.createElement('iframe');
        iframe.src = embedUrl;
        iframe.title = item.dataset.regVideoTitle || Drupal.t('REG video');
        iframe.loading = 'eager';
        iframe.allow = 'encrypted-media; picture-in-picture; fullscreen';
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.setAttribute('allowfullscreen', '');
        player.appendChild(iframe);
      }
      else if (directUrl) {
        const video = document.createElement('video');
        video.src = directUrl;
        video.controls = true;
        video.preload = 'metadata';
        video.addEventListener('ended', () => analytics('video_complete', activeItem || item), {once: true});
        player.appendChild(video);
      }
      else {
        external.hidden = false;
        externalLink.href = publicUrl;
      }

      modal.hidden = false;
      document.body.classList.add('reg-video-modal-open');
      analytics('video_play', item);
      window.requestAnimationFrame(() => closeButton?.focus());
    };

    closeButton?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);
    modal.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      const elements = focusable();
      if (!elements.length) return;
      const first = elements[0];
      const last = elements[elements.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      }
      else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    return {open, close};
  };

  const attachCarousel = (carousel, modal) => {
    const slides = Array.from(carousel.querySelectorAll('[data-reg-video-slide]'));
    const pagination = Array.from(carousel.querySelectorAll('[data-reg-video-pagination]'));
    const previous = carousel.querySelector('[data-reg-video-previous]');
    const next = carousel.querySelector('[data-reg-video-next]');
    const status = carousel.querySelector('[data-reg-video-status]');
    if (!slides.length) return;
    let active = 0;
    let touchStartX = 0;

    const wrap = (index) => (index + slides.length) % slides.length;
    const render = (index, eventName = '') => {
      active = wrap(index);
      const previousIndex = wrap(active - 1);
      const nextIndex = wrap(active + 1);
      slides.forEach((slide, slideIndex) => {
        const isCurrent = slideIndex === active;
        const isPrevious = slides.length > 2 && slideIndex === previousIndex;
        const isNext = slides.length > 1 && slideIndex === nextIndex && !isPrevious;
        slide.classList.toggle('video-slide--current', isCurrent);
        slide.classList.toggle('video-slide--previous', isPrevious);
        slide.classList.toggle('video-slide--next', isNext);
        slide.hidden = !(isCurrent || isPrevious || isNext);
        slide.setAttribute('aria-hidden', String(!isCurrent));
        const button = slide.querySelector('[data-reg-video-play]');
        if (button) {
          const label = isCurrent ? Drupal.t('Play @title', {'@title': slide.dataset.regVideoTitle}) : Drupal.t('Show @title', {'@title': slide.dataset.regVideoTitle});
          button.setAttribute('aria-label', label);
        }
      });
      pagination.forEach((button, buttonIndex) => {
        if (buttonIndex === active) button.setAttribute('aria-current', 'true');
        else button.removeAttribute('aria-current');
      });
      if (status) {
        status.textContent = Drupal.t('Video @current of @total: @title', {
          '@current': active + 1,
          '@total': slides.length,
          '@title': slides[active].dataset.regVideoTitle
        });
      }
      if (eventName) analytics(eventName, slides[active]);
      analytics('video_impression', slides[active]);
    };

    slides.forEach((slide, index) => {
      slide.querySelector('[data-reg-video-play]')?.addEventListener('click', (event) => {
        if (index !== active) {
          const eventName = slide.classList.contains('video-slide--previous') ? 'video_previous' : 'video_next';
          render(index, eventName);
          return;
        }
        modal?.open(slide, event.currentTarget);
      });
    });
    previous?.addEventListener('click', () => render(active - 1, 'video_previous'));
    next?.addEventListener('click', () => render(active + 1, 'video_next'));
    pagination.forEach((button, index) => button.addEventListener('click', () => {
      if (index === active) return;
      render(index, index > active ? 'video_next' : 'video_previous');
    }));
    carousel.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        render(active - 1, 'video_previous');
      }
      else if (event.key === 'ArrowRight') {
        event.preventDefault();
        render(active + 1, 'video_next');
      }
    });
    carousel.addEventListener('touchstart', (event) => {
      touchStartX = event.changedTouches[0]?.clientX || 0;
    }, {passive: true});
    carousel.addEventListener('touchend', (event) => {
      const distance = (event.changedTouches[0]?.clientX || 0) - touchStartX;
      if (Math.abs(distance) < 50) return;
      render(active + (distance < 0 ? 1 : -1), distance < 0 ? 'video_next' : 'video_previous');
    }, {passive: true});
    render(0);
  };

  Drupal.behaviors.regFeaturedVideos = {
    attach(context) {
      once('reg-video-context', '[data-reg-video-context]', context).forEach((videoContext) => {
        const modal = modalController(videoContext);
        const carousel = videoContext.querySelector('[data-reg-video-carousel]');
        if (carousel) attachCarousel(carousel, modal);
        videoContext.querySelectorAll('[data-reg-video-item]:not([data-reg-video-slide]) [data-reg-video-play]').forEach((button) => {
          button.addEventListener('click', () => {
            const item = button.closest('[data-reg-video-item]');
            if (item) modal?.open(item, button);
          });
        });
      });
    }
  };
})(Drupal, once);
