#!/usr/bin/env php
<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;

$provisioner = new RoomProvisioner(new RoomCatalog(), false);
$result = $provisioner->run(RoomProvisioner::MODE_DRY_RUN, []);
echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
if ($result['expected'] !== 42) {
    fwrite(STDERR, "expected 42 rooms\n");
    exit(1);
}
