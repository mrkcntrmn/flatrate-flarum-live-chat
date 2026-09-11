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
    private function buildExact42(): array
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
        $this->assertSame(42, $body['catalogCount']);
        $this->assertSame(0, $body['existingCanonicalCount']);
        $this->assertSame(42, $body['missingCount']);
        $this->assertSame(0, $body['extraCount']);
        $this->assertSame(0, $body['driftedCount']);
        $this->assertSame(1, $body['memberVisibleExpected']);
        $this->assertSame(41, $body['staffPreviewExpected']);
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
        $this->assertSame(42, $body['createdCount']);
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

        $this->store = array_slice($this->buildExact42(), 0, 1);
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
        $this->assertSame(42, $first['body']['createdCount']);
        $this->assertSame(0, $first['body']['updatedCount']);
        $this->assertTrue($first['body']['writesApplied']);
        $this->assertSame(42, $first['body']['existingCanonicalCount']);
        $this->assertSame(0, $first['body']['missingCount']);
        $ids = array_column($this->store, 'id');
        $keys = array_column($this->store, 'room_key');

        $againPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $againPreview['classification']);
        $secondPayload = [
            'expectedCatalogSha256' => $againPreview['catalogSha256'],
            'expectedStateSha256' => $againPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
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

        foreach ([1, 21, 41] as $n) {
            $this->store = array_slice($this->buildExact42(), 0, $n);
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

        $this->store = $this->buildExact42();
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

        $this->store = $this->buildExact42();
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
        $this->store = $this->buildExact42();
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
        $this->store = $this->buildExact42();
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
        $this->store = $this->buildExact42();
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

        // Valid exact 42 remains ALREADY_RECONCILED no-op
        $this->store = $this->buildExact42();
        $validPreview = $service->preview();
        $this->assertSame(RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED, $validPreview['classification']);
        $this->assertSame(0, $validPreview['driftedCount']);
        $writesBefore = $this->writeCalls;
        $noop = $service->reconcile([
            'expectedCatalogSha256' => $validPreview['catalogSha256'],
            'expectedStateSha256' => $validPreview['stateSha256'],
            'expectedExistingCanonicalCount' => 42,
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
}
