(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.regBranches = {
    attach(context) {
      once('reg-branch-view', '[data-reg-branch-view]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const map = document.querySelector('[data-reg-branch-map]');
          const list = document.querySelector('[data-reg-branch-list]');
          const showMap = button.dataset.regBranchView === 'map';
          if (map) map.hidden = !showMap;
          if (list) list.hidden = showMap;
          document.querySelectorAll('[data-reg-branch-view]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
        });
      });

      once('reg-branch-map', '[data-reg-map-canvas]', context).forEach((canvas) => {
        const markers = drupalSettings.regBranches?.markers || [];
        if (window.L && drupalSettings.regBranches?.provider === 'leaflet') {
          const map = window.L.map(canvas).setView([-1.94, 29.87], 8);
          markers.forEach((marker) => {
            const popup = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = marker.name;
            const district = document.createElement('p');
            district.textContent = marker.district;
            const link = document.createElement('a');
            link.href = marker.url;
            link.textContent = Drupal.t('View Branch');
            popup.append(title, district, link);
            window.L.marker([marker.latitude, marker.longitude]).addTo(map).bindPopup(popup);
          });
          return;
        }
        markers.forEach((marker) => {
          const item = document.createElement('article');
          item.className = 'reg-map-marker';
          const title = document.createElement('strong');
          title.textContent = marker.name;
          const details = document.createElement('p');
          details.textContent = [marker.district, marker.address, marker.telephone, marker.opening_hours].filter(Boolean).join(' · ');
          const link = document.createElement('a');
          link.href = marker.url;
          link.textContent = Drupal.t('View Branch');
          item.append(title, details, link);
          canvas.append(item);
        });
        if (!markers.length) canvas.textContent = Drupal.t('No mapped branches match these filters.');
      });

      once('reg-use-location', '[data-reg-use-location]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const status = document.querySelector('[data-reg-location-status]');
          if (!navigator.geolocation) {
            if (status) status.textContent = Drupal.t('Location is not supported by this browser.');
            return;
          }
          if (status) status.textContent = Drupal.t('Requesting location permission…');
          navigator.geolocation.getCurrentPosition(
            () => { if (status) status.textContent = Drupal.t('Location permission granted. Distance sorting is ready for an approved mapping provider.'); },
            () => { if (status) status.textContent = Drupal.t('Location was not shared. You can continue using the filters.'); },
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 }
          );
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
