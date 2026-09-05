<?php

declare(strict_types=1);

/**
 * FreshRSS_Entry::thumbnail() only ever looks at an explicit "thumbnail"
 * attribute (from media:thumbnail-style tags) or RSS enclosures - it never
 * looks inside the entry's own HTML content for an <img>, and it never
 * looks at a <video poster="..."> attribute at all. Feeds that publish
 * native HTML5 video (poster + <source>) instead of an <img> - e.g. 9GAG's
 * RSS bridge - end up with no thumbnail for those entries even though a
 * perfectly good preview image is sitting right there in the poster
 * attribute.
 *
 * addPosterThumbnail() hooks entry_before_display (the same hook
 * FreshRSS's own YouTube extension uses), so it runs at render time -
 * it fixes entries already in the database too, not just newly-fetched
 * ones. It's cheap (regex over content already in memory), so running
 * it on every render is fine.
 *
 * addOgImageThumbnail() covers a different case: feeds (e.g. Bluesky's
 * own /rss output) whose items have no image data at all, anywhere -
 * not even a <video poster> - but whose linked page has a normal
 * og:image meta tag. Since checking that means an actual HTTP request,
 * it hooks entry_before_insert instead (runs once, when the entry is
 * first fetched, not on every future render).
 */
final class VideoThumbnailExtension extends Minz_Extension
{
	#[\Override]
	public function init(): void {
		$this->registerHook('entry_before_display', [$this, 'addPosterThumbnail']);
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
