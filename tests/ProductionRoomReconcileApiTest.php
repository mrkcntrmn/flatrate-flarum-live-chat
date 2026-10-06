<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Api\Controllers\RoomReconcileController;
use FlatRate\LiveChat\Api\Controllers\RoomReconcilePreviewController;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Provisioner\ProductionRoomReconcileService;
use FlatRate\LiveChat\Provisioner\RoomProvisioner;
use FlatRate\LiveChat\Provisioner\RoomReconcileSnapshot;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\User;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class ProductionRoomReconcileApiTest extends TestCase
{
    private RoomCatalog $catalog;
    private RolloutProfile $rollout;
    private RoomReconcileSnapshot $snapshot;

    /** @var list<array<string,mixed>> */
    private array $store = [];

    private int $nextId = 1;
    private int $writeCalls = 0;
    private bool $failNextWrite = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new RoomCatalog();
        $this->rollout = new RolloutProfile();
        $this->snapshot = new RoomReconcileSnapshot($this->catalog, $this->rollout);
        $this->store = [];
        $this->nextId = 1;
        $this->writeCalls = 0;
        $this->failNextWrite = false;
    }

    private function service(): ProductionRoomReconcileService
    {
        return new ProductionRoomReconcileService(
            $this->catalog,
            $this->rollout,
            $this->snapshot,
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
            function (RoomProvisioner $provisioner) {
                $this->writeCalls++;
                if ($this->failNextWrite) {
                    $this->failNextWrite = false;
                    throw new \RuntimeException('induced write failure');
                }
                $created = [];
                foreach ($this->catalog->rooms() as $room) {
                    $policy = $this->rollout->policyForRoomKey($room['roomKey']);
                    $id = $this->nextId++;
                    $this->store[] = [
                        'id' => $id,
                        'type' => 1,
                        'title' => $room['name'],
                        'room_key' => $room['roomKey'],
                        'scope_type' => $room['scopeType'],
                        'scope_key' => $room['scopeKey'],
                        'visibility' => $policy['visibility'],
                        'audience' => $policy['audience'],
                    ];
                    $created[] = ['roomKey' => $room['roomKey'], 'id' => $id];
                }

                return ['created' => $created, 'updated' => []];
            }
        );
    }

    /** @return list<array<string,mixed>> */
    private function buildExact46(): array
    {
        $rows = [];
        $id = 1;
        foreach ($this->catalog->rooms() as $room) {
            $policy = $this->rollout->policyForRoomKey($room['roomKey']);
            $rows[] = [
                'id' => $id++,
                'type' => 1,
                'title' => $room['name'],
                'room_key' => $room['roomKey'],
                'scope_type' => $room['scopeType'],
                'scope_key' => $room['scopeKey'],
                'visibility' => $policy['visibility'],
                'audience' => $policy['audience'],
            ];
        }

        return $rows;
    }

    private function admin(): User
    {
        $u = new User(1);
        $u->isAdmin = true;

        return $u;
    }

    private function member(): User
    {
        $u = new User(10);
        $u->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
            ChatAuthorization::PERM_MODERATE => false,
            ChatAuthorization::PERM_ADMIN_ROOMS => true,
        ];

        return $u;
    }

    private function moderator(): User
    {
        $u = new User(20);
        $u->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
            ChatAuthorization::PERM_MODERATE => true,
            ChatAuthorization::PERM_ADMIN_ROOMS => true,
        ];

        return $u;
    }

    public function testPreviewAuthMatrix(): void
    {
        $controller = new RoomReconcilePreviewController($this->service(), new ChatAuthorization());

        $guest = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/rooms/reconcile-preview'))
            ->withAttribute('actor', new User(null));
        $this->assertSame(403, $controller->handle($guest)->getStatusCode());

        $memberReq = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/rooms/reconcile-preview'))
            ->withAttribute('actor', $this->member());
        $this->assertSame(403, $controller->handle($memberReq)->getStatusCode());

        $modReq = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/rooms/reconcile-preview'))
            ->withAttribute('actor', $this->moderator());
        $this->assertSame(403, $controller->handle($modReq)->getStatusCode());

        $suspended = $this->admin();
        $suspended->suspended_until = date('c', time() + 3600);
        $susReq = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/rooms/reconcile-preview'))
            ->withAttribute('actor', $suspended);
        $this->assertSame(403, $controller->handle($susReq)->getStatusCode());

        $okReq = (new ServerRequest('GET', '/api/flatrate-live-chat/admin/rooms/reconcile-preview'))
            ->withAttribute('actor', $this->admin());
        $ok = $controller->handle($okReq);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('no-store', $ok->getHeaderLine('Cache-Control'));
        $body = json_decode((string) $ok->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(RoomReconcileSnapshot::CLASS_READY_INITIAL_CREATE, $body['classification']);
        $this->assertSame(46, $body['catalogCount']);
        $this->assertSame(0, $body['existingCanonicalCount']);
        $this->assertSame(46, $body['missingCount']);
        $this->assertSame(0, $body['extraCount']);
        $this->assertSame(0, $body['driftedCount']);
        $this->assertSame(1, $body['memberVisibleExpected']);
        $this->assertSame(45, $body['staffPreviewExpected']);
        $blob = (string) $ok->getBody();
        foreach (['BEGIN RSA', 'edge-key', 'api-key', 'Cookie', 'Authorization', 'token'] as $secretish) {
            $this->assertStringNotContainsStringIgnoringCase($secretish, $blob);
        }
    }

    public function testPostAuthMatrix(): void
    {
        $controller = new RoomReconcileController($this->service(), new ChatAuthorization());
        $preview = $this->service()->preview();
        $payload = [
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 0,
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ];

        foreach ([new User(null), $this->member(), $this->moderator()] as $actor) {
            $req = (new ServerRequest('POST', '/api/flatrate-live-chat/admin/rooms/reconcile'))
                ->withAttribute('actor', $actor)
                ->withParsedBody($payload);
            $this->assertSame(403, $controller->handle($req)->getStatusCode());
        }

        $suspended = $this->admin();
        $suspended->suspended_until = date('c', time() + 3600);
        $susReq = (new ServerRequest('POST', '/api/flatrate-live-chat/admin/rooms/reconcile'))
            ->withAttribute('actor', $suspended)
            ->withParsedBody($payload);
        $this->assertSame(403, $controller->handle($susReq)->getStatusCode());

        $okReq = (new ServerRequest('POST', '/api/flatrate-live-chat/admin/rooms/reconcile'))
            ->withAttribute('actor', $this->admin())
            ->withParsedBody($payload);
        $ok = $controller->handle($okReq);
        $this->assertSame(200, $ok->getStatusCode());
        $body = json_decode((string) $ok->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(46, $body['createdCount']);
        $this->assertTrue($body['writesApplied']);
    }

    public function testBadRequestsNoWrites(): void
    {
        $service = $this->service();
        $preview = $service->preview();
        $goodConfirm = $this->snapshot->expectedConfirmation($preview['stateSha256']);

        $cases = [
            ['expectedStateSha256' => $preview['stateSha256'], 'expectedExistingCanonicalCount' => 0, 'confirm' => $goodConfirm],
            [
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedExistingCanonicalCount' => 0,
                'confirm' => $goodConfirm,
            ],
            [
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedStateSha256' => str_repeat('0', 64),
                'expectedExistingCanonicalCount' => 0,
                'confirm' => $goodConfirm,
            ],
            [
                'expectedCatalogSha256' => str_repeat('a', 64),
                'expectedStateSha256' => $preview['stateSha256'],
                'expectedExistingCanonicalCount' => 0,
                'confirm' => $goodConfirm,
            ],
            [
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedStateSha256' => $preview['stateSha256'],
                'expectedExistingCanonicalCount' => 1,
                'confirm' => $goodConfirm,
            ],
            [
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedStateSha256' => $preview['stateSha256'],
                'expectedExistingCanonicalCount' => 0,
                'confirm' => 'YES',
            ],
            [
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedStateSha256' => $preview['stateSha256'],
                'expectedExistingCanonicalCount' => 0,
                'confirm' => $goodConfirm,
                'extraField' => true,
            ],
        ];

        foreach ($cases as $body) {
            $writesBefore = $this->writeCalls;
            $result = $service->reconcile($body);
            $this->assertContains($result['status'], [400, 409], json_encode($body));
            $this->assertSame($writesBefore, $this->writeCalls);
            $this->assertCount(0, $this->store);
        }

        $controller = new RoomReconcileController($service, new ChatAuthorization());
        $queryOverride = (new ServerRequest('POST', '/api/flatrate-live-chat/admin/rooms/reconcile?mode=reconcile'))
            ->withAttribute('actor', $this->admin())
            ->withQueryParams(['mode' => 'reconcile'])
            ->withParsedBody([
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedStateSha256' => $preview['stateSha256'],
                'expectedExistingCanonicalCount' => 0,
                'confirm' => $goodConfirm,
            ]);
        $this->assertSame(400, $controller->handle($queryOverride)->getStatusCode());
        $this->assertCount(0, $this->store);
    }

    public function testStateChangeRace(): void
    {
        $service = $this->service();
        $preview = $service->preview();
        $payload = [
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 0,
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ];

        $this->store = array_slice($this->buildExact46(), 0, 1);
        $result = $service->reconcile($payload);
        $this->assertSame(409, $result['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_STATE_CHANGED, $result['body']['code']);
        $this->assertFalse($result['body']['writesApplied']);
        $this->assertSame(0, $this->writeCalls);
        $this->assertCount(1, $this->store);
    }

    public function testSuccessfulInitialAndIdempotentSecond(): void
    {
        $service = $this->service();
        $preview = $service->preview();
        $payload = [
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 0,
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ];
        $first = $service->reconcile($payload);
        $this->assertSame(200, $first['status']);
        $this->assertSame(46, $first['body']['createdCount']);
        $this->assertSame(0, $first['body']['updatedCount']);
        $this->assertTrue($first['body']['writesApplied']);
        $this->assertSame(46, $first['body']['existingCanonicalCount']);
        $this->assertSame(0, $first['body']['missingCount']);
        $ids = array_column($this->store, 'id');
        $keys = array_column($this->store, 'room_key');

        $againPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $againPreview['classification']);
        $secondPayload = [
            'expectedCatalogSha256' => $againPreview['catalogSha256'],
            'expectedStateSha256' => $againPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 46,
            'confirm' => $this->snapshot->expectedConfirmation($againPreview['stateSha256']),
        ];
        $writesBefore = $this->writeCalls;
        $second = $service->reconcile($secondPayload);
        $this->assertSame(200, $second['status']);
        $this->assertSame(0, $second['body']['createdCount']);
        $this->assertSame(0, $second['body']['updatedCount']);
        $this->assertFalse($second['body']['writesApplied']);
        $this->assertSame($writesBefore, $this->writeCalls);
        $this->assertSame($ids, array_column($this->store, 'id'));
        $this->assertSame($keys, array_column($this->store, 'room_key'));
    }

    public function testPartialExtraDriftFailClosed(): void
    {
        $service = $this->service();

        foreach ([1, 23, 45] as $n) {
            $this->store = array_slice($this->buildExact46(), 0, $n);
            $preview = $service->preview();
            $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $preview['classification']);
            $payload = [
                'expectedCatalogSha256' => $preview['catalogSha256'],
                'expectedStateSha256' => $preview['stateSha256'],
                'expectedExistingCanonicalCount' => $preview['existingCanonicalCount'],
                'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
            ];
            $writesBefore = $this->writeCalls;
            $result = $service->reconcile($payload);
            $this->assertSame(409, $result['status']);
            $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $result['body']['code']);
            $this->assertSame($writesBefore, $this->writeCalls);
            $this->assertCount($n, $this->store);
        }

        $this->store = $this->buildExact46();
        $this->store[] = [
            'id' => 999,
            'type' => 1,
            'title' => 'Rogue',
            'room_key' => 'rogue-noncatalog-live',
            'scope_type' => 'board',
            'scope_key' => 'rogue',
            'visibility' => RoomVisibility::HIDDEN,
            'audience' => RoomAudience::STAFF_PREVIEW,
        ];
        $extraPreview = $service->preview();
        $this->assertSame(1, $extraPreview['extraCount']);
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $extraPreview['classification']);
        $extraResult = $service->reconcile([
            'expectedCatalogSha256' => $extraPreview['catalogSha256'],
            'expectedStateSha256' => $extraPreview['stateSha256'],
            'expectedExistingCanonicalCount' => $extraPreview['existingCanonicalCount'],
            'confirm' => $this->snapshot->expectedConfirmation($extraPreview['stateSha256']),
        ]);
        $this->assertSame(409, $extraResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $extraResult['body']['code']);

        $this->store = $this->buildExact46();
        $this->store[0]['title'] = 'Mutated Title';
        $driftPreview = $service->preview();
        $this->assertSame(1, $driftPreview['driftedCount']);
        $driftResult = $service->reconcile([
            'expectedCatalogSha256' => $driftPreview['catalogSha256'],
            'expectedStateSha256' => $driftPreview['stateSha256'],
            'expectedExistingCanonicalCount' => $driftPreview['existingCanonicalCount'],
            'confirm' => $this->snapshot->expectedConfirmation($driftPreview['stateSha256']),
        ]);
        $this->assertSame(409, $driftResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $driftResult['body']['code']);
        $this->assertSame('Mutated Title', $this->store[0]['title']);
    }

    public function testCanonicalTypeDriftFailClosed(): void
    {
        $service = $this->service();

        // General Live type=0
        $this->store = $this->buildExact46();
        foreach ($this->store as $i => $row) {
            if ($row['room_key'] === 'community-general-live') {
                $this->store[$i]['type'] = 0;
                break;
            }
        }
        $preview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $preview['classification']);
        $this->assertSame(1, $preview['driftedCount']);
        $writesBefore = $this->writeCalls;
        $result = $service->reconcile([
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => $preview['existingCanonicalCount'],
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ]);
        $this->assertSame(409, $result['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $result['body']['code']);
        $this->assertFalse($result['body']['writesApplied']);
        $this->assertSame(0, $result['body']['createdCount']);
        $this->assertSame(0, $result['body']['updatedCount']);
        $this->assertSame($writesBefore, $this->writeCalls);

        // Brand room type=0
        $this->store = $this->buildExact46();
        foreach ($this->store as $i => $row) {
            if ($row['room_key'] === 'toyota-live') {
                $this->store[$i]['type'] = 0;
                break;
            }
        }
        $brandPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $brandPreview['classification']);
        $this->assertSame(1, $brandPreview['driftedCount']);
        $writesBefore = $this->writeCalls;
        $brandResult = $service->reconcile([
            'expectedCatalogSha256' => $brandPreview['catalogSha256'],
            'expectedStateSha256' => $brandPreview['stateSha256'],
            'expectedExistingCanonicalCount' => $brandPreview['existingCanonicalCount'],
            'confirm' => $this->snapshot->expectedConfirmation($brandPreview['stateSha256']),
        ]);
        $this->assertSame(409, $brandResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $brandResult['body']['code']);
        $this->assertFalse($brandResult['body']['writesApplied']);
        $this->assertSame(0, $brandResult['body']['createdCount']);
        $this->assertSame(0, $brandResult['body']['updatedCount']);
        $this->assertSame($writesBefore, $this->writeCalls);

        // Same room: bad type + bad title counts once
        $this->store = $this->buildExact46();
        foreach ($this->store as $i => $row) {
            if ($row['room_key'] === 'toyota-live') {
                $this->store[$i]['type'] = 0;
                $this->store[$i]['title'] = 'Mutated Toyota';
                break;
            }
        }
        $doublePreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $doublePreview['classification']);
        $this->assertSame(1, $doublePreview['driftedCount']);

        // Valid exact 46 remains ALREADY_RECONCILED no-op
        $this->store = $this->buildExact46();
        $validPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $validPreview['classification']);
        $this->assertSame(0, $validPreview['driftedCount']);
        $writesBefore = $this->writeCalls;
        $noop = $service->reconcile([
            'expectedCatalogSha256' => $validPreview['catalogSha256'],
            'expectedStateSha256' => $validPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 46,
            'confirm' => $this->snapshot->expectedConfirmation($validPreview['stateSha256']),
        ]);
        $this->assertSame(200, $noop['status']);
        $this->assertFalse($noop['body']['writesApplied']);
        $this->assertSame(0, $noop['body']['createdCount']);
        $this->assertSame(0, $noop['body']['updatedCount']);
        $this->assertSame($writesBefore, $this->writeCalls);
    }

    public function testTransactionRollbackOnWriteFailure(): void
    {
        $service = $this->service();
        $preview = $service->preview();
        $this->failNextWrite = true;
        $result = $service->reconcile([
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 0,
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ]);
        $this->assertSame(500, $result['status']);
        $this->assertSame('ROOM_RECONCILE_FAILED', $result['body']['code']);
        $this->assertFalse($result['body']['writesApplied']);
        $this->assertCount(0, $this->store);
        $this->assertSame(1, $this->writeCalls);
    }

    public function testResponseNeverLeaksSecrets(): void
    {
        $service = $this->service();
        $preview = $service->preview();
        $blob = json_encode($preview);
        $this->assertStringNotContainsString('BEGIN', $blob);
        $this->assertStringNotContainsString('centrifugo', strtolower($blob));
        $this->assertStringNotContainsString('jwt', strtolower($blob));
        $this->assertStringNotContainsString('cookie', strtolower($blob));
    }

    public function testExactFourMissingOnlyRepairLeavesExistingRowsUntouched(): void
    {
        $repairWrites = 0;
        $service = $this->repairService($repairWrites);
        $this->store = $this->buildWithoutKeys(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS);
        $this->nextId = 1000;
        $original = $this->store;
        $originalIds = array_column($original, 'id', 'room_key');

        $preview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR, $preview['classification']);
        $this->assertSame(46, $preview['catalogCount']);
        $this->assertSame(42, $preview['existingCanonicalCount']);
        $this->assertSame(4, $preview['missingCount']);
        $this->assertSame(0, $preview['extraCount']);
        $this->assertSame(0, $preview['driftedCount']);
        $this->assertSame(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS, $preview['missingRoomKeys']);

        $payload = [
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
            'expectedMissingRoomKeys' => array_reverse(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS),
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ];
        $result = $service->reconcile($payload);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);
        $this->assertSame(4, $result['body']['createdCount']);
        $this->assertSame(0, $result['body']['updatedCount']);
        $this->assertTrue($result['body']['writesApplied']);
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $result['body']['classification']);
        $this->assertSame(46, $result['body']['existingCanonicalCount']);
        $this->assertSame(0, $result['body']['missingCount']);
        $this->assertSame(0, $result['body']['extraCount']);
        $this->assertSame(0, $result['body']['driftedCount']);
        $this->assertSame(1, $repairWrites);
        $this->assertSame(0, $this->writeCalls);
        $this->assertCount(46, $this->store);

        $after = [];
        foreach ($this->store as $row) {
            $after[$row['room_key']] = $row;
        }
        foreach ($original as $row) {
            $this->assertSame($row, $after[$row['room_key']]);
            $this->assertSame($originalIds[$row['room_key']], $after[$row['room_key']]['id']);
        }
        $catalogByKey = [];
        foreach ($this->catalog->rooms() as $room) {
            $catalogByKey[$room['roomKey']] = $room;
        }
        foreach (ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS as $key) {
            $this->assertSame(RoomVisibility::HIDDEN, $after[$key]['visibility']);
            $this->assertSame(RoomAudience::STAFF_PREVIEW, $after[$key]['audience']);
            $this->assertSame($catalogByKey[$key]['scopeType'], $after[$key]['scope_type']);
            $this->assertSame($catalogByKey[$key]['scopeKey'], $after[$key]['scope_key']);
            $this->assertSame(1, $after[$key]['type']);
        }
        $this->assertSame(RoomVisibility::VISIBLE, $after['community-general-live']['visibility']);
        $this->assertSame(RoomAudience::MEMBERS, $after['community-general-live']['audience']);
        $brandHidden = 0;
        foreach ($after as $key => $row) {
            if ($key !== 'community-general-live'
                && $row['visibility'] === RoomVisibility::HIDDEN
                && $row['audience'] === RoomAudience::STAFF_PREVIEW
            ) {
                $brandHidden++;
            }
        }
        $this->assertSame(45, $brandHidden);

        $again = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $again['classification']);
        $this->assertSame(46, $again['existingCanonicalCount']);
        $this->assertSame([], $again['missingRoomKeys']);
    }

    public function testMissingOnlyRepairStaysFailClosed(): void
    {
        $repairWrites = 0;
        $service = $this->repairService($repairWrites);

        $this->store = $this->buildWithoutKeys(['ford-live', 'alfa-romeo-live', 'genesis-live', 'toyota-live']);
        $otherPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $otherPreview['classification']);
        $this->assertSame(4, $otherPreview['missingCount']);
        $this->assertNotSame(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS, $otherPreview['missingRoomKeys']);
        $other = $service->reconcile([
            'expectedCatalogSha256' => $otherPreview['catalogSha256'],
            'expectedStateSha256' => $otherPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
            'expectedMissingRoomKeys' => $otherPreview['missingRoomKeys'],
            'confirm' => $this->snapshot->expectedConfirmation($otherPreview['stateSha256']),
        ]);
        $this->assertSame(409, $other['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $other['body']['code']);
        $this->assertFalse($other['body']['writesApplied']);
        $this->assertSame(0, $repairWrites);
        $this->assertCount(42, $this->store);

        $this->store = $this->buildWithoutKeys(['aston-martin-live', 'jlr-live', 'land-rover-live', 'ford-live']);
        $overlapPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $overlapPreview['classification']);
        $overlap = $service->reconcile([
            'expectedCatalogSha256' => $overlapPreview['catalogSha256'],
            'expectedStateSha256' => $overlapPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
            'expectedMissingRoomKeys' => ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS,
            'confirm' => $this->snapshot->expectedConfirmation($overlapPreview['stateSha256']),
        ]);
        $this->assertSame(409, $overlap['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $overlap['body']['code']);
        $this->assertSame(0, $repairWrites);

        $this->store = $this->buildWithoutKeys(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS);
        $preview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR, $preview['classification']);
        $omitted = $service->reconcile([
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ]);
        $this->assertSame(400, $omitted['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_BAD_REQUEST, $omitted['body']['code']);
        $this->assertSame(0, $repairWrites);
        $this->assertCount(42, $this->store);

        $wrongSet = $service->reconcile([
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
            'expectedMissingRoomKeys' => ['ford-live', 'alfa-romeo-live', 'genesis-live', 'toyota-live'],
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ]);
        $this->assertSame(409, $wrongSet['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_STATE_CHANGED, $wrongSet['body']['code']);
        $this->assertSame(0, $repairWrites);
        $this->assertCount(42, $this->store);

        $this->store[0]['title'] = 'Mutated Title';
        $driftPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $driftPreview['classification']);
        $this->assertSame(1, $driftPreview['driftedCount']);
        $drifted = $service->reconcile([
            'expectedCatalogSha256' => $driftPreview['catalogSha256'],
            'expectedStateSha256' => $driftPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
            'expectedMissingRoomKeys' => ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS,
            'confirm' => $this->snapshot->expectedConfirmation($driftPreview['stateSha256']),
        ]);
        $this->assertSame(409, $drifted['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $drifted['body']['code']);
        $this->assertSame('Mutated Title', $this->store[0]['title']);
        $this->assertSame(0, $repairWrites);

        $this->store = [];
        $initial = $service->preview();
        $rejected = $service->reconcile([
            'expectedCatalogSha256' => $initial['catalogSha256'],
            'expectedStateSha256' => $initial['stateSha256'],
            'expectedExistingCanonicalCount' => 0,
            'expectedMissingRoomKeys' => ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS,
            'confirm' => $this->snapshot->expectedConfirmation($initial['stateSha256']),
        ]);
        $this->assertSame(400, $rejected['status']);
        $this->assertSame(0, $repairWrites);
        $this->assertSame(0, $this->writeCalls);
        $this->assertCount(0, $this->store);
    }

    public function testMissingOnlyRepairRejectsOtherPartialsAndBadBindings(): void
    {
        $repairWrites = 0;
        $service = $this->repairService($repairWrites);

        $this->store = $this->buildWithoutKeys(['aston-martin-live', 'jlr-live', 'land-rover-live']);
        $three = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $three['classification']);
        $this->assertSame(43, $three['existingCanonicalCount']);
        $this->assertSame(3, $three['missingCount']);
        $threeResult = $service->reconcile($this->repairBody($three, [
            'expectedExistingCanonicalCount' => 43,
            'expectedMissingRoomKeys' => $three['missingRoomKeys'],
        ]));
        $this->assertSame(409, $threeResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $threeResult['body']['code']);
        $this->assertCount(43, $this->store);

        $fiveKeys = array_merge(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS, ['ford-live']);
        $this->store = $this->buildWithoutKeys($fiveKeys);
        $five = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $five['classification']);
        $this->assertSame(41, $five['existingCanonicalCount']);
        $this->assertSame(5, $five['missingCount']);
        $fiveResult = $service->reconcile($this->repairBody($five, [
            'expectedExistingCanonicalCount' => 41,
            'expectedMissingRoomKeys' => $five['missingRoomKeys'],
        ]));
        $this->assertSame(409, $fiveResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $fiveResult['body']['code']);
        $this->assertCount(41, $this->store);

        $this->store = $this->buildWithoutKeys(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS);
        $this->store[] = [
            'id' => 999,
            'type' => 1,
            'title' => 'Rogue',
            'room_key' => 'rogue-noncatalog-live',
            'scope_type' => 'board',
            'scope_key' => 'rogue',
            'visibility' => RoomVisibility::HIDDEN,
            'audience' => RoomAudience::STAFF_PREVIEW,
        ];
        $extra = $service->preview();
        $this->assertSame(1, $extra['extraCount']);
        $this->assertSame(RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED, $extra['classification']);
        $extraResult = $service->reconcile($this->repairBody($extra));
        $this->assertSame(409, $extraResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_REQUIRES_REVIEW, $extraResult['body']['code']);
        $this->assertCount(43, $this->store);

        $preview = $this->readyRepairPreview($service);
        $bindingCases = [
            $this->repairBody($preview, ['expectedStateSha256' => str_repeat('0', 64)]),
            $this->repairBody($preview, ['expectedCatalogSha256' => str_repeat('a', 64)]),
            $this->repairBody($preview, ['expectedExistingCanonicalCount' => 41]),
            $this->repairBody($preview, ['confirm' => 'YES']),
            $this->repairBody($preview, [
                'expectedMissingRoomKeys' => array_merge(
                    ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS,
                    ['aston-martin-live']
                ),
            ]),
        ];
        foreach ($bindingCases as $body) {
            $writesBefore = $repairWrites;
            $result = $service->reconcile($body);
            $this->assertSame(409, $result['status'], json_encode($body));
            $this->assertSame(ProductionRoomReconcileService::ERR_STATE_CHANGED, $result['body']['code']);
            $this->assertFalse($result['body']['writesApplied']);
            $this->assertSame($writesBefore, $repairWrites);
            $this->assertCount(42, $this->store);
        }

        $this->store = $this->buildExact46();
        $complete = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $complete['classification']);
        $completeResult = $service->reconcile($this->repairBody($complete, [
            'expectedExistingCanonicalCount' => 46,
            'expectedMissingRoomKeys' => ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS,
        ]));
        $this->assertSame(400, $completeResult['status']);
        $this->assertSame(ProductionRoomReconcileService::ERR_BAD_REQUEST, $completeResult['body']['code']);
        $this->assertSame(0, $repairWrites);
        $this->assertSame(0, $this->writeCalls);
        $this->assertCount(46, $this->store);
    }

    public function testRepairPostconditionFailureRollsBack(): void
    {
        $repairWrites = 0;
        $service = new ProductionRoomReconcileService(
            $this->catalog,
            $this->rollout,
            $this->snapshot,
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
            function (RoomProvisioner $provisioner) {
                $this->writeCalls++;
                throw new \RuntimeException('initial reconcile writer must not run for missing-only repair');
            },
            function (RoomProvisioner $provisioner, array $keys) use (&$repairWrites) {
                $repairWrites++;
                foreach ($this->catalog->rooms() as $room) {
                    if (!in_array($room['roomKey'], $keys, true)) {
                        continue;
                    }
                    $id = $this->nextId++;
                    $this->store[] = [
                        'id' => $id,
                        'type' => 1,
                        'title' => $room['name'],
                        'room_key' => $room['roomKey'],
                        'scope_type' => $room['scopeType'],
                        'scope_key' => $room['scopeKey'],
                        'visibility' => RoomVisibility::VISIBLE,
                        'audience' => RoomAudience::MEMBERS,
                    ];
                }

                return [
                    'created' => array_map(
                        static fn (string $key) => ['roomKey' => $key],
                        $keys
                    ),
                    'updated' => [],
                ];
            }
        );

        $this->store = $this->buildWithoutKeys(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS);
        $before = $this->store;
        $preview = $service->preview();
        $result = $service->reconcile($this->repairBody($preview));

        $this->assertSame(500, $result['status']);
        $this->assertSame('ROOM_RECONCILE_FAILED', $result['body']['code']);
        $this->assertFalse($result['body']['writesApplied']);
        $this->assertSame(0, $result['body']['createdCount']);
        $this->assertSame(0, $result['body']['updatedCount']);
        $this->assertSame(1, $repairWrites);
        $this->assertSame($before, $this->store);
        $this->assertCount(42, $this->store);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyRepairPreview(ProductionRoomReconcileService $service): array
    {
        $this->store = $this->buildWithoutKeys(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS);
        $preview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR, $preview['classification']);
        $this->assertSame(46, $preview['catalogCount']);
        $this->assertSame(42, $preview['existingCanonicalCount']);
        $this->assertSame(4, $preview['missingCount']);
        $this->assertSame(0, $preview['extraCount']);
        $this->assertSame(0, $preview['driftedCount']);
        $this->assertSame(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS, $preview['missingRoomKeys']);

        return $preview;
    }

    /**
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function repairBody(array $preview, array $overrides = []): array
    {
        return array_replace([
            'expectedCatalogSha256' => $preview['catalogSha256'],
            'expectedStateSha256' => $preview['stateSha256'],
            'expectedExistingCanonicalCount' => $preview['existingCanonicalCount'],
            'expectedMissingRoomKeys' => ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS,
            'confirm' => $this->snapshot->expectedConfirmation($preview['stateSha256']),
        ], $overrides);
    }

    private function repairService(int &$repairWrites): ProductionRoomReconcileService
    {
        return new ProductionRoomReconcileService(
            $this->catalog,
            $this->rollout,
            $this->snapshot,
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
            function (RoomProvisioner $provisioner) {
                $this->writeCalls++;
                throw new \RuntimeException('initial reconcile writer must not run for missing-only repair');
            },
            function (RoomProvisioner $provisioner, array $keys) use (&$repairWrites) {
                $repairWrites++;
                $this->assertSame(ProductionRoomReconcileService::MISSING_ONLY_REPAIR_KEYS, $keys);
                $existing = [];
                foreach ($this->store as $row) {
                    $existing[$row['room_key']] = true;
                }
                $created = [];
                foreach ($this->catalog->rooms() as $room) {
                    if (!in_array($room['roomKey'], $keys, true) || isset($existing[$room['roomKey']])) {
                        continue;
                    }
                    $policy = $this->rollout->policyForRoomKey($room['roomKey']);
                    $id = $this->nextId++;
                    $this->store[] = [
                        'id' => $id,
                        'type' => 1,
                        'title' => $room['name'],
                        'room_key' => $room['roomKey'],
                        'scope_type' => $room['scopeType'],
                        'scope_key' => $room['scopeKey'],
                        'visibility' => $policy['visibility'],
                        'audience' => $policy['audience'],
                    ];
                    $created[] = ['roomKey' => $room['roomKey'], 'id' => $id];
                }

                return ['created' => $created, 'updated' => []];
            }
        );
    }

    /**
     * @param list<string> $keys
     * @return list<array<string,mixed>>
     */
    private function buildWithoutKeys(array $keys): array
    {
        $drop = array_fill_keys($keys, true);
        $rows = [];
        foreach ($this->buildExact46() as $row) {
            if (!isset($drop[$row['room_key']])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
