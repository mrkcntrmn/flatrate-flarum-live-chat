<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Provisioner\ProductionRoomReconcileService;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Provisioner\RoomReconcileSnapshot;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use PHPUnit\Framework\TestCase;

/**
 * Focused transactional invariants for production reconcile.
 */
class ProductionRoomReconcileTransactionTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $store = [];
    private int $nextId = 1;
    private bool $failAfterPartial = false;

    public function testAtomicInitialReconcileRollbackLeavesZero(): void
    {
        $catalog = new RoomCatalog();
        $rollout = new RolloutProfile();
        $snapshot = new RoomReconcileSnapshot($catalog, $rollout);

        $service = new ProductionRoomReconcileService(
            $catalog,
            $rollout,
            $snapshot,
            fn () => $this->store,
            function (callable $cb) {
                $snap = $this->store;
                $next = $this->nextId;
                try {
                    return $cb();
                } catch (\Throwable $e) {
                    $this->store = $snap;
                    $this->nextId = $next;
                    throw $e;
                }
            },
            function (RoomProvisioner $provisioner) use ($catalog, $rollout) {
                $created = [];
                $i = 0;
                foreach ($catalog->rooms() as $room) {
                    $i++;
                    $policy = $rollout->policyForRoomKey($room['roomKey']);
                    $this->store[] = [
                        'id' => $this->nextId++,
                        'type' => 1,
                        'title' => $room['name'],
                        'room_key' => $room['roomKey'],
                        'scope_type' => $room['scopeType'],
                        'scope_key' => $room['scopeKey'],
                        'visibility' => $policy['visibility'],
                        'audience' => $policy['audience'],
                    ];
                    $created[] = ['roomKey' => $room['roomKey']];
                    if ($this->failAfterPartial && $i === 21) {
                        throw new \RuntimeException('induced mid-reconcile failure');
                    }
                }

                return ['created' => $created, 'updated' => []];
            }
        );

        $preview = $service->preview();
        $this->failAfterPartial = true;
        $result = $service->reconcile([
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 0,
            'confirm' => $snapshot->expectedConfirmation($preview['stateSha256']),
        ]);

        $this->assertSame(500, $result['status']);
        $this->assertCount(0, $this->store);
        $this->assertSame(0, $result['body']['createdCount']);
        $this->assertFalse($result['body']['writesApplied']);
    }
}
