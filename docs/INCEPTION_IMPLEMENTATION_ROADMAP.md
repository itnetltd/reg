# REG Inception Report → Drupal 11 Implementation Roadmap

This roadmap converts the revised inception report into an implementation sequence for the REG Drupal 11 codebase.

## Implemented in Phase Two

- Dedicated `/home` route and service-first homepage.
- Structured content types for services, outages, news, tenders, jobs, publications, FAQs, branches and sports updates.
- English/Kinyarwanda content-translation readiness.
- Editorial roles for Communications, Customer Service, Outages, Procurement, HR, Sports, Translation and Approval.
- Phase-one Online Services gateway that uses approved links or future secure API handoffs.
- Outage Center CMS fallback with location and status filters.
- FAQ-first customer assistant that searches only published, approved FAQ content.
- Bill estimator and carbon calculator with safe administrative configuration. Both remain disabled until validated assumptions are entered.
- Configuration health warning for missing official URLs, tariffs and emission factors.
- Responsive homepage sections for emergency notices, customer actions, energy-awareness tools and REG Sports.

## Next Development Sprint

1. **Approved content and branding**
   - Replace prototype marks and placeholder imagery with official REG/EUCL/EDCL assets.
   - Enter approved homepage, service, FAQ, branch, tender and outage content.

2. **Editorial workflow**
   - Configure Draft → Review → Published → Archived moderation states.
   - Assign transition permissions after REG confirms approval responsibilities.

3. **Views and listing pages**
   - Build administrative and public Views for news, tenders, jobs, publications, branches and sports.
   - Add exposed filters, pagination, status badges and archive pages.

4. **Outage and GIS integration**
   - Confirm source-system fields and authentication.
   - Implement API client, caching, retry logic, fallback status messages and map data.

5. **Online Services integration**
   - Confirm deep-link, SSO, API or controlled embed per service.
   - Add event tracking without storing sensitive customer information in Drupal.

6. **Recruitment and tender workflows**
   - Confirm whether submissions stay in Drupal or are handed to approved HR/procurement systems.
   - Add notifications, addenda and controlled file-upload rules after policy validation.

7. **Analytics, accessibility and security**
   - Configure approved analytics and event taxonomy.
   - Complete WCAG 2.2 AA review, keyboard testing and contrast checks.
   - Add 2FA readiness, audit logging, spam protection, security headers and pre-launch vulnerability testing.

## Required REG Inputs

- Official logos, fonts, colors and image guidelines.
- API documentation and test credentials.
- Approved tariff rules and update date.
- Approved electricity emission factor and sustainability messaging.
- Branch and GIS data.
- Recruitment and procurement submission policies.
- Social-media permissions and publishing governance.
- Content owners, approvers, translators and UAT sign-off team.
