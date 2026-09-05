# Changelog

## 1.2 — 2026-09-05

- Added `addContentImageThumbnail()`: falls back to the first real
  `<img>` in the entry's content when FreshRSS found no thumbnail,
  skipping a small denylist of common noise (WordPress's core emoji
  CDN, gravatar avatars, obvious tracking-pixel domains) and stripping
  HTML comments first (some sites leave a commented-out placeholder
  `<img>` in their template).
  Built for FitGirl Repacks: its posts carry a Steam trailer `.webm` as
  an RSS enclosure, which correctly makes FreshRSS's own `thumbnail()`
  return null (not an image) — but a perfectly good cover-art `<img>`
  sits right in the content and was never considered as a fallback.
  Hooks `entry_before_display` like the `<video poster>` fallback, so
  it applies to entries already in the database too — no backfill
  script needed for this one.

## 1.1 — 2026-09-05

- Added the `og:image` fallback (`entry_before_insert` hook) for feeds
  whose items have no image data at all, not even a `<video poster>` —
  e.g. Bluesky's own `/rss` output. Fetches the entry's own link once,
  at insert time, and pulls `og:image` from it if present.
- Added `backfill-og-image.php`, a one-off script to apply that same
  fallback to entries already in the database for a given feed (the
  hook only fires for newly-inserted entries going forward).

## 1.0 — 2026-09-05

Initial version — `<video poster>` fallback (`entry_before_display`
hook) for feeds that mix plain images and native HTML5 video posts
(built for 9GAG's RSS bridge specifically, but generic to any feed with
the same pattern).
