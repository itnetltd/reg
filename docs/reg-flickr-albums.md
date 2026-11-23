# REG latest Flickr albums

The REG Photo Gallery at /media/gallery displays the latest four public
Flickr albums by creation date, then up to twelve public photos from the
selected album. Visitors can select an album without leaving REG. The
original Flickr feed remains the fallback when the API is unavailable.

Flickr's public API requires an application API key for album listings even
though public-album methods do not require user OAuth authorization.

1. Request an appropriate API key from the official Flickr App Garden:
   https://www.flickr.com/services/apps/create/
   Review Flickr's commercial-use and account eligibility requirements.
2. Store the key privately in the existing AOS Drupal settings file:
   /etc/reg-github/aos-settings.php
   Add a PHP statement calling putenv with FLICKR_API_KEY and the actual key,
   preserving the existing database and other service settings.
   Never commit the key to GitHub or put it into Drupal public configuration.
3. Reload PHP-FPM, rebuild Drupal cache, and verify the environment key is
   accessible through Drush without echoing its value.
4. Under /admin/config/reg/social-media verify the Flickr User ID
   (for REG this is 127491669@N07), official profile URL and gallery checkbox.
5. On /media/gallery, four album covers should appear and clicking one shows
   up to twelve available photos under its Flickr album title.
6. Responses are cached for 15 minutes. The Media Center landing's six-photo
   preview is unchanged; the optional fixed Album ID remains available for
   the original public feed.

Implementation uses server-side calls to flickr.photosets.getList and
flickr.photosets.getPhotos. Only public photo URLs and sanitized metadata
are included in page output. The Flickr API key never reaches the browser.

If fewer than twelve public photos exist, only available photos are shown.
