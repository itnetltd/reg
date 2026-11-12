# REG homepage X feed sources

Manage at **Configuration → REG Social Media**, `/admin/config/reg/social-media`,
under **X / Twitter settings → Homepage X feed source**.

- **Option 1: X API (paid credits)**: select `X API`, keep `X API enabled` checked, configure `X_BEARER_TOKEN` in the AOS private server environment, and ensure X API credits are available. Existing API cache and X account fields remain unchanged when switching away.
- **Option 2: Free X Embedded Timeline**: select `Free X Embedded Timeline`, then Save configuration. No API bearer token or X credits are required. The REG homepage displays a visitor-activated official X timeline for the verified profile URL. A visitor clicks **Load X posts**; only then is `https://platform.x.com/widgets.js` requested.
- Both modes also require **Enabled**, **Include in homepage social cards**, and **Show X feed on homepage** to be checked.
- The free embedded widget uses X's own layout, not the Drupal custom four-post API cards; X may block the timeline for some browsers or regions. The official profile link is always shown.
- No scraping, client-side credential exposure, or database content migration is involved.
- The embedded timeline may load third-party cookies or resources; REG's privacy policy and Content Security Policy may require review.
- **Refresh X feed** is intended only for paid API mode. Server-side cron and page requests skip X API calls while Free Embed is selected.
- Switching modes is an ordinary Drupal configuration save, followed by cache invalidation. No Git deployment is needed after initial installation.

Sources: https://help.x.com/en/using-x/embed-x-feed and https://publish.x.com/.
