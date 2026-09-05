<?php

declare(strict_types=1);

/**
 * One-off backfill for the og:image fallback in extension.php.
 *
 * That fallback hooks entry_before_insert, so it only ever applies to
 * entries fetched *after* the extension was enabled. This script applies
 * the same logic to entries already sitting in the database for a given
 * feed, since re-fetching an existing entry doesn't re-run insert hooks.
 *
 * Run from inside the FreshRSS container (adjust the config.php path if
 * yours differs from the standard Docker image layout):
 *
 *   docker exec -i freshrss php -r "$(cat backfill-og-image.php)" -- <feed_id>
 *
 * Safe to re-run: it skips any entry that already has a thumbnail
 * attribute, whether set by this script, the extension itself, or
 * FreshRSS's own detection.
 */

$feedId = (int)($argv[1] ?? 0);
if ($feedId <= 0) {
	fwrite(STDERR, "Usage: php backfill-og-image.php <feed_id>\n");
	exit(1);
}

$config = include '/var/www/FreshRSS/data/config.php';
$db = $config['db'];
$pdo = new PDO(
	'mysql:host=' . $db['host'] . ';dbname=' . $db['base'],
	$db['user'],
	$db['password']
);

// Table names are prefixed with the FreshRSS username ("kadafi_" here) -
// adjust if you run multiple accounts or a different username.
$rows = $pdo->prepare('SELECT id, link, attributes FROM kadafi_entry WHERE id_feed = ?');
$rows->execute([$feedId]);

$updated = 0;
$skipped = 0;
$failed = 0;

foreach ($rows->fetchAll() as $row) {
	$attrs = json_decode((string)$row['attributes'], true) ?: [];
	if (!empty($attrs['thumbnail']['url'])) {
		$skipped++;
		continue;
	}

	$html = @file_get_contents($row['link'], false, stream_context_create([
		'http' => ['timeout' => 6, 'header' => 'User-Agent: FreshRSS'],
		'https' => ['timeout' => 6, 'header' => 'User-Agent: FreshRSS'],
	]));
	if ($html === false) {
		$failed++;
		echo "FETCH FAIL: {$row['link']}\n";
		continue;
	}

	$matched = preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1
		|| preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m) === 1;

	if ($matched) {
		$attrs['thumbnail'] = ['url' => html_entity_decode($m[1])];
		$update = $pdo->prepare('UPDATE kadafi_entry SET attributes = ? WHERE id = ?'); // see table-prefix note above
		$update->execute([json_encode($attrs), $row['id']]);
		$updated++;
	} else {
		$failed++;
		echo "NO OG:IMAGE: {$row['link']}\n";
	}
}

echo "updated={$updated} skipped={$skipped} failed={$failed}\n";
