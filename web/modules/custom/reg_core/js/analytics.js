(function (Drupal, drupalSettings, once) {
  'use strict';

  const aliases = {
    complaint_external_handoff: 'complaint_handoff',
    fault_report_handoff: 'complaint_handoff',
    team_page_view: 'sports_team_view',
    player_profile_view: 'sports_player_view',
    fixture_view: 'sports_fixture_view',
    result_view: 'sports_result_view',
    video_click: 'sports_video_click',
    gallery_view: 'sports_gallery_view',
    tender_document_download: 'tender_download'
  };

  const allowed = new Set([
    'service_click', 'outage_search', 'outage_view', 'outage_subscription',
    'bill_estimator_start', 'bill_estimator_complete', 'faq_search',
    'faq_no_result', 'faq_answer_view', 'branch_search', 'branch_filter',
    'branch_view', 'branch_phone_click', 'branch_email_click',
    'branch_directions_click', 'complaint_start', 'complaint_handoff',
    'tender_view', 'tender_download', 'tender_subscription', 'job_view',
    'job_apply_click', 'publication_view', 'publication_download',
    'sports_team_view', 'sports_player_view', 'sports_fixture_view',
    'sports_result_view', 'sports_gallery_view', 'sports_video_click',
    'sports_article_view', 'sports_search_result', 'video_impression',
    'video_play', 'video_complete', 'video_next', 'video_previous',
    'view_all_videos', 'language_switch', 'energy_tool_view',
    'bill_estimator_click', 'carbon_calculator_click',
    'safety_guidance_click', 'news_view', 'news_category_filter',
    'news_search', 'news_share',
    'news_related_click'
  ]);

  function normalizedEvent(value) {
    const event = String(value || '').trim().toLowerCase();
    const canonical = aliases[event] || event;
    return allowed.has(canonical) ? canonical : '';
  }

  function safeContext(element) {
    const type = String(element.dataset.regAnalyticsType || '').toLowerCase();
    const language = String(element.dataset.regAnalyticsLanguage || '').toLowerCase();
    const dimensionType = language ? 'language' : (type ? 'category' : 'none');
    const dimensionValue = (language || type || 'all').replace(/[^a-z0-9_-]/g, '').slice(0, 64) || 'all';
    return {
      dimension_type: dimensionType,
      dimension_value: dimensionValue,
      entity_id: Math.max(0, parseInt(element.dataset.regAnalyticsId || '0', 10) || 0),
      langcode: String(document.documentElement.lang || '').replace(/[^a-z0-9-]/gi, '').slice(0, 12)
    };
  }

  function send(element, suppliedEvent) {
    const settings = drupalSettings.regAnalytics || {};
    const event = normalizedEvent(suppliedEvent || element.dataset.regAnalyticsEvent);
    if (!event || !settings.localEnabled || !settings.endpoint) {
      return;
    }
    const context = safeContext(element);
    fetch(settings.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type': 'application/json', 'X-REG-Analytics': 'aggregate-v1'},
      body: JSON.stringify({event: event, context: context})
    }).catch(function () {});

    // Approved provider scripts may listen for this event. Drupal never loads
    // or calls a remote analytics API synchronously.
    document.dispatchEvent(new CustomEvent('reg:analytics', {detail: {event: event, context: context}}));
    const providerAllowed = settings.provider && settings.provider.enabled &&
      (settings.provider.privacyMode !== 'consent_required' || settings.consentGranted === true);
    if (providerAllowed) {
      if (settings.provider.provider === 'ga4' && typeof window.gtag === 'function') {
        window.gtag('event', event, context);
      }
      if (settings.provider.provider === 'matomo' && Array.isArray(window._paq)) {
        window._paq.push(['trackEvent', 'REG', event, context.dimension_value]);
      }
    }
  }

  Drupal.behaviors.regAnalytics = {
    attach: function (context) {
      once('reg-analytics-click', 'a[data-reg-analytics-event], button[data-reg-analytics-event]', context).forEach(function (element) {
        element.addEventListener('click', function () { send(element); });
      });
      once('reg-analytics-submit', 'form[data-reg-analytics-submit]', context).forEach(function (form) {
        form.addEventListener('submit', function () { send(form, form.dataset.regAnalyticsSubmit); });
      });
      once('reg-analytics-view', 'main[data-reg-analytics-event], section[data-reg-analytics-event], article[data-reg-analytics-event], div[data-reg-analytics-event]', context).forEach(function (element) {
        send(element);
      });
      once('reg-analytics-custom-events', 'html', context).forEach(function () {
        document.addEventListener('reg:analytics-track', function (event) {
          const detail = event.detail || {};
          const source = {dataset: {
            regAnalyticsId: String(detail.entityId || 0),
            regAnalyticsType: String(detail.type || '')
          }};
          send(source, detail.event);
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
