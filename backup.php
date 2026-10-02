<?php
/**
 * Standalone backup runner for cron (optional — the app also self-triggers backups on page load).
 *
 * It forces one snapshot of data/tracker.sqlite into data/backups/ and rotates old ones
 * (every snapshot from the last 24h, then one per day for 30 days).
 *
 * Usage (daily) — run `crontab -e` and add a line like:
 *     0 3 * * * /usr/bin/php /full/path/to/office-tracker/backup.php >/dev/null 2>&1
 *
 * Adjust the php path (`which php`) and the script path to your install.
 */

require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    // Not meant to be web-accessible; the app self-triggers backups anyway.
    http_response_code(403);
    exit("Run from the command line (cron).\n");
}

$path = backup_create($pdo);
echo $path ? ('Backup written: ' . $path . "\n") : "No backup created (MySQL mode, or write failed).\n";
