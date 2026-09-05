# Video Thumbnail

A small [FreshRSS](https://freshrss.org) extension that adds a thumbnail to
entries FreshRSS would otherwise show with none.

## Why

`FreshRSS_Entry::thumbnail()` only ever looks at two things: an explicit
`thumbnail` attribute (set from `media:thumbnail`-style RSS tags), or an
RSS `<enclosure>`. It never looks inside the entry's own HTML content for
a plain `<img>`, and it never looks at a `<video poster="...">` attribute.
Some feeds fall through the cracks as a result:

- Feeds that mix plain `<img>` posts with native HTML5 `<video>` posts
  (poster + `<source>`) instead of an image — e.g. 9GAG's RSS output.
  The image posts get a thumbnail, the video posts don't, even though a
  perfectly good preview frame is sitting right there in the `poster`
  attribute.
- Feeds with no image data at all, anywhere in the item — e.g. Bluesky's
  own `/rss` output, which is plain text only, even for posts that
  clearly have an attached image or video. The linked post page usually
  still has a normal `og:image` meta tag, just not the feed itself.

This extension covers both cases as fallbacks, in order, only when
FreshRSS found no thumbnail on its own:

1. **`<video poster>` in the entry's content.** Hooks `entry_before_display`
   (the same hook FreshRSS's own bundled YouTube extension uses) — a
   cheap regex over content already in memory, so it runs on every
   render and fixes entries already in your database too, not just new
   ones.
2. **`og:image` on the entry's linked page.** Hooks `entry_before_insert`
   instead, since checking this means an actual HTTP request to the
   entry's own link — it only runs once, when an entry is first fetched,
   not on every future page view.

## Installation

Same as any FreshRSS extension: drop this repo's contents into
`FreshRSS/extensions/xExtension-VideoThumbnail/`, then enable **Video
Thumbnail** under **Settings → Extensions**.

```sh
git clone <this-repo-url> /path/to/FreshRSS/extensions/xExtension-VideoThumbnail
```

For Docker, the same pattern as a theme applies — bind-mount from a
persistent host path rather than copying into the container, so it
survives image updates:

```sh
-v /path/to/persistent/xExtension-VideoThumbnail:/var/www/FreshRSS/extensions/xExtension-VideoThumbnail
```

## Backfilling entries already in your database

The `og:image` fallback (hook #2 above) only runs for entries fetched
*after* the extension is enabled — it won't retroactively touch posts
already sitting in your database. See `backfill-og-image.php` for a
one-off script that applies it to existing entries for a given feed;
run it once per feed that needs it, from inside the FreshRSS container:

```sh
docker exec -i freshrss php -r "$(cat backfill-og-image.php)" -- <feed_id>
```

## License

MIT — see [LICENSE](LICENSE).
