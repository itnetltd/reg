#!/usr/bin/env bash
set -euo pipefail

paths=(
  /
  /branches
  /customer-services
  /tenders
  /jobs
  /publications
  /media
  /media/videos
  /sports
  /contact
  /outages
  /help/faq
  /admin/content/section-heroes
)

for path in "${paths[@]}"; do
  output="/tmp/reg-section-page.html"
  code="$(curl -sS -o "$output" -w '%{http_code}' "http://localhost${path}")"
  bytes="$(wc -c <"$output")"
  hero="$(grep -c 'reg-section-hero' "$output" || true)"
  brown="$(grep -Ec 'reg-support__hero|reg-pi-hero|outage-center__hero' "$output" || true)"
  sports_photo="$(grep -c 'reg-sports-hero--image' "$output" || true)"
  viewport="$(grep -ci 'name="viewport"' "$output" || true)"
  image_path="$(perl -0777 -ne 'print $1 if /reg-section-hero__media.*?<img src="([^"]+)"/s' "$output")"
  image_code="none"
  if [[ -n "$image_path" ]]; then
    image_path="${image_path//&amp;/&}"
    if [[ "$image_path" =~ ^https?:// ]]; then
      image_url="$image_path"
    else
      image_url="http://localhost${image_path}"
    fi
    image_code="$(curl -ksS -o /dev/null -w '%{http_code}' "$image_url")"
  fi
  printf '%s %s hero=%s brown=%s sports-photo=%s image=%s viewport=%s %s' "$code" "$bytes" "$hero" "$brown" "$sports_photo" "$image_code" "$viewport" "$path"
  if [[ "$path" == "/branches" ]]; then
    printf ' image-url=%s' "$image_path"
  fi
  printf '\n'
done
