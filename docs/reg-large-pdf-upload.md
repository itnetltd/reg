# REG publication PDF upload size

The custom REG public upload policy accepts PDF documents up to **250 MB**.
Image, video, Office and other file ceilings are unchanged. The same custom PDF
ceiling also applies to other public PDF categories because upload validation
is based on file bytes and extension, not the Media Browser folder.

**AOS deployment:** Drupal's Media Browser also enforces PHP and possibly the
Media document field's `max_filesize`. Set PHP-FPM
`upload_max_filesize = 250M` and `post_max_size = 300M`; set an adequate
Nginx `client_max_body_size 300m` in the REG website server block, then reload
both services and clear Drupal cache. Confirm any Drupal Media file field size
validator is unset or >= 250 MB. The web UI derives its 5 MB warning from an
upstream limit and will not show 250 MB until these are changed.

No web-based public upload is literally unlimited. To raise the limit beyond
250 MB, update `UploadPolicy::MAX_PUBLIC_PDF_MB` and the web server settings,
while reviewing storage, request timeouts, backup impact, and denial-of-service
risks. Only authorized REG editors may publish reviewed public documents.
