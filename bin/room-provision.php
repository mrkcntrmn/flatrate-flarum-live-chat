#!/usr/bin/env php
<?php
/**
 * Room provision CLI for disposable Flarum runtimes.
 *
 * Usage (from Flarum root with extension enabled):
 *   php vendor/flatrate/flarum-live-chat/bin/room-provision.php validate|dry-run|reconcile
 *
 * Reconcile requires FLATRATE_LIVE_CHAT_ALLOW_WRITES=1 (disposable only).
 */

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Rollout\RolloutProfile;

$flarumRoot = getenv('FLARUM_BASE') ?: getcwd();
if (!is_file($flarumRoot . '/site.php') && is_file(dirname(__DIR__, 3) . '/site.php')) {
    $flarumRoot = dirname(__DIR__, 3);
}
chdir($flarumRoot);

require $flarumRoot . '/vendor/autoload.php';

$site = require $flarumRoot . '/site.php';
$app = $site->bootApp();

$mode = $argv[1] ?? RoomProvisioner::MODE_DRY_RUN;
$allowWrites = in_array(getenv('FLATRATE_LIVE_CHAT_ALLOW_WRITES'), ['1', 'true'], true)
    || ($mode === RoomProvisioner::MODE_RECONCILE && ($argv[2] ?? '') === '--allow-writes');

$existing = Chat::query()
    ->whereNotNull('room_key')
    ->get(['id', 'title', 'room_key', 'scope_type', 'scope_key', 'visibility', 'audience'])
    ->map(fn ($row) => [
        'id' => $row->id,
        'title' => $row->title,
        'room_key' => $row->room_key,
        'scope_type' => $row->scope_type,
        'scope_key' => $row->scope_key,
        'visibility' => $row->visibility,
        'audience' => $row->audience,
    ])
    ->all();

$provisioner = new RoomProvisioner(new RoomCatalog(), $allowWrites, new RolloutProfile());
$result = $provisioner->run($mode, $existing);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

if (($result['expected'] ?? 0) !== 42 && $mode !== RoomProvisioner::MODE_VALIDATE) {
    fwrite(STDERR, "expected catalog size 42\n");
    exit(1);
}
