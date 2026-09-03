# REG homepage X API feed

The homepage social card uses X API v2 from Drupal's server-side
`reg_core.x_feed` service. The browser never receives an X credential and
does not load X's widget JavaScript.

## Credential

Provide the read-only application Bearer Token through the environment variable:

`X_BEARER_TOKEN`

Never add the token to Drupal configuration, settings exports, source control,
Twig, JavaScript, or logging configuration.

For local DDEV, add the variable to a local, uncommitted
`.ddev/config.local.yaml`:

```yaml
web_environment:
  - X_BEARER_TOKEN=replace-with-the-real-token
```

Run `ddev restart`, then confirm the status at
`/admin/config/reg/social-media`. Do not commit the local override.

For production, configure `X_BEARER_TOKEN` in the Contabo deployment
environment for both the PHP web runtime and Drupal cron/CLI runtime. Restart
the relevant PHP/container services after changing the environment.

## Drupal settings

At `/admin/config/reg/social-media`:

1. Keep the verified profile URL set to `https://x.com/reg_rwanda`.
2. Enable the X platform and homepage feed.
3. Enable the X API.
4. Use username `reg_rwanda`.
5. Leave the numeric user ID empty unless REG already knows it.
6. Keep the cache at 9001800 seconds and homepage count at three.

The service resolves and caches the numeric user ID, requests recent authored
posts, and caches normalized homepage data. Drupal cron refreshes an expired
cache. When the cache is empty, one controlled homepage request may populate it.
Expired cached posts remain available when X temporarily fails.

The admin status reports only whether a credential exists, refresh time, cached
post count, and a bounded non-sensitive error summary.
