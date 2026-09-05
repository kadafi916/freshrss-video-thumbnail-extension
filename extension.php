<?php

declare(strict_types=1);

/**
 * FreshRSS_Entry::thumbnail() only ever looks at an explicit "thumbnail"
 * attribute (from media:thumbnail-style tags) or RSS enclosures - it never
 * looks inside the entry's own HTML content for an <img>, and it never
 * looks at a <video poster="..."> attribute at all. Three different feed
 * patterns fall through this gap:
 *
 * 1. Feeds that publish native HTML5 video (poster + <source>) instead
 *    of an <img> - e.g. 9GAG's RSS bridge. addPosterThumbnail() covers
 *    this: pulls the poster attribute out of the entry's own content.
 *
 * 2. Feeds whose entry has a *non-image* enclosure - e.g. FitGirl Repacks
 *    posts often carry a Steam trailer .webm as an enclosure, which
 *    correctly makes FreshRSS's own thumbnail() return null (it's not an
 *    image) - but there's a perfectly good cover-art <img> sitting right
 *    in the content that never gets considered as a fallback.
 *    addContentImageThumbnail() covers this: takes the first <img src>
 *    in the content, skipping a short denylist of common noise (WordPress's
 *    core emoji CDN, gravatar avatars, obvious tracking-pixel domains) and
 *    HTML comments (some sites leave commented-out placeholder images in
 *    their template).
 *
 * 3. Feeds with no image data at all, anywhere - not even a <video
 *    poster> or a usable inline <img> - e.g. Bluesky's own /rss output.
 *    addOgImageThumbnail() covers this: fetches the entry's own linked
 *    page once and pulls its og:image meta tag, if any.
 *
 * The first two hook entry_before_display (the same hook FreshRSS's own
 * YouTube extension uses) - cheap regexes over content already in
 * memory, so they run on every render and fix entries already in the
 * database too, not just newly-fetched ones. The third hooks
 * entry_before_insert instead, since it means an actual HTTP request -
 * it only runs once, when an entry is first fetched.
 */
final class VideoThumbnailExtension extends Minz_Extension
{
	/** Substrings matched against an <img> src (lowercased) to skip it as noise. */
	private const IMG_SRC_DENYLIST = [
		's.w.org/images/core/emoji',
		'gravatar.com',
		'/stats.',
		'stats.info',
		'tracking',
		'beacon',
		'/pixel.',
	];

	#[\Override]
	public function init(): void {
		$this->registerHook('entry_before_display', [$this, 'addPosterThumbnail']);
		$this->registerHook('entry_before_display', [$this, 'addContentImageThumbnail']);
		$this->registerHook('entry_before_insert', [$this, 'addOgImageThumbnail']);
	}

	public function addPosterThumbnail(FreshRSS_Entry $entry): FreshRSS_Entry {
		if ($entry->thumbnail() !== null) {
			return $entry;
		}

		if (preg_match('/<video\b[^>]*\bposter=["\']([^"\']+)["\']/i', $entry->content(), $matches) === 1) {
			$entry->_attribute('thumbnail', ['url' => $matches[1]]);
		}

		return $entry;
	}

	public function addContentImageThumbnail(FreshRSS_Entry $entry): FreshRSS_Entry {
		if ($entry->thumbnail() !== null) {
			return $entry;
		}

		// Strip HTML comments first - some sites leave a commented-out
		// placeholder <img> before the real content.
		$content = preg_replace('/<!--.*?-->/s', '', $entry->content()) ?? '';

		if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $matches) < 1) {
			return $entry;
		}

		foreach ($matches[1] as $src) {
			$lower = strtolower($src);
			$isNoise = false;
			foreach (self::IMG_SRC_DENYLIST as $needle) {
				if (str_contains($lower, $needle)) {
					$isNoise = true;
					break;
				}
			}
			if (!$isNoise) {
				$entry->_attribute('thumbnail', ['url' => $src]);
				break;
			}
		}

		return $entry;
	}

	public function addOgImageThumbnail(FreshRSS_Entry $entry): FreshRSS_Entry {
		if ($entry->thumbnail() !== null) {
			return $entry;
		}

		$link = $entry->link();
		if ($link === '' || !str_starts_with($link, 'http')) {
			return $entry;
		}

		$html = @file_get_contents($link, false, stream_context_create([
			'http' => ['timeout' => 5, 'header' => 'User-Agent: FreshRSS'],
			'https' => ['timeout' => 5, 'header' => 'User-Agent: FreshRSS'],
		]));
		if ($html === false) {
			return $entry;
		}

		if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches) === 1
			|| preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $matches) === 1) {
			$entry->_attribute('thumbnail', ['url' => html_entity_decode($matches[1])]);
		}

		return $entry;
	}
}
