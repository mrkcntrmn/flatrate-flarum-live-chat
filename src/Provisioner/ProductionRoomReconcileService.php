<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Provisioner;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RolloutProfile;

/**
 * Production-safe reconcile orchestration.
 *
 * Stricter than RoomProvisioner: initial 0→42 create and already-reconciled
 * no-op only. Partial/extra/drifted states fail closed.
 */
final class ProductionRoomReconcileService
{
    public const ERR_STATE_CHANGED = 'ROOM_RECONCILE_STATE_CHANGED';
    public const ERR_REQUIRES_REVIEW = 'PRODUCTION_ROOM_STATE_REQUIRES_REVIEW';
    public const ERR_BAD_REQUEST = 'ROOM_RECONCILE_BAD_REQUEST';

    /** @var list<string> */
    private const ALLOWED_BODY_KEYS = [
        'expectedCatalogSha256',
        'expectedStateSha256',
        'expectedExistingCanonicalCount',
        'confirm',
    ];

    /**
     * @param callable(): list<array<string,mixed>>|null $loadKeyedRooms
     * @param callable(callable(): mixed): mixed|null $transaction
     * @param callable(RoomProvisioner): array|null $runReconcileWrites
     */
    public function __construct(
        private RoomCatalog $catalog,
        private RolloutProfile $rollout,
        private ?RoomReconcileSnapshot $snapshot = null,
        private $loadKeyedRooms = null,
        private $transaction = null,
        private $runReconcileWrites = null
    ) {
        $this->snapshot = $snapshot ?? new RoomReconcileSnapshot($catalog, $rollout);
        $this->loadKeyedRooms = $loadKeyedRooms ?? [$this, 'defaultLoadKeyedRooms'];
        $this->transaction = $transaction ?? [$this, 'defaultTransaction'];
        $this->runReconcileWrites = $runReconcileWrites ?? [$this, 'defaultRunReconcileWrites'];
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(): array
    {
        return $this->snapshot->analyze(($this->loadKeyedRooms)());
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    public function reconcile(array $body): array
    {
        $parsed = $this->parseBody($body);
        if ($parsed['error'] !== null) {
            return [
                'status' => 400,
                'body' => [
                    'ok' => false,
                    'code' => self::ERR_BAD_REQUEST,
                    'message' => $parsed['error'],
                ],
            ];
        }

        $fresh = $this->snapshot->analyze(($this->loadKeyedRooms)());

        if (!$this->bindingsMatch($parsed, $fresh)) {
            return [
                'status' => 409,
                'body' => [
                    'ok' => false,
                    'code' => self::ERR_STATE_CHANGED,
                    'classification' => $fresh['classification'],
                    'catalogSha256' => $fresh['catalogSha256'],
                    'stateSha256' => $fresh['stateSha256'],
                    'existingCanonicalCount' => $fresh['existingCanonicalCount'],
                    'writesApplied' => false,
                    'createdCount' => 0,
                    'updatedCount' => 0,
                ],
            ];
        }

        if ($fresh['classification'] === RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED) {
            return [
                'status' => 200,
                'body' => [
                    'ok' => true,
                    'classification' => RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED,
                    'createdCount' => 0,
                    'updatedCount' => 0,
                    'writesApplied' => false,
                    'catalogSha256' => $fresh['catalogSha256'],
                    'stateSha256' => $fresh['stateSha256'],
                    'existingCanonicalCount' => 42,
                    'missingCount' => 0,
                    'extraCount' => 0,
                    'driftedCount' => 0,
                ],
            ];
        }

        if ($fresh['classification'] !== RoomReconcileSnapshot::CLASS_READY_INITIAL_CREATE
            || $fresh['existingCanonicalCount'] !== 0
            || $fresh['missingCount'] !== 42
            || $fresh['extraCount'] !== 0
            || $fresh['driftedCount'] !== 0
            || $fresh['catalogCount'] !== 42
            || $fresh['rolloutProfile'] !== RolloutProfile::PROFILE_GENERAL_LIVE_FIRST
        ) {
            return [
                'status' => 409,
                'body' => [
                    'ok' => false,
                    'code' => self::ERR_REQUIRES_REVIEW,
                    'classification' => $fresh['classification'],
                    'existingCanonicalCount' => $fresh['existingCanonicalCount'],
                    'missingCount' => $fresh['missingCount'],
                    'extraCount' => $fresh['extraCount'],
                    'driftedCount' => $fresh['driftedCount'],
                    'writesApplied' => false,
                    'createdCount' => 0,
                    'updatedCount' => 0,
                ],
            ];
        }

        try {
            $result = ($this->transaction)(function () use ($parsed) {
                $inside = $this->snapshot->analyze(($this->loadKeyedRooms)());
                if (!$this->bindingsMatch($parsed, $inside)
                    || $inside['classification'] !== RoomReconcileSnapshot::CLASS_READY_INITIAL_CREATE
                ) {
                    throw new ProductionRoomReconcileException(self::ERR_STATE_CHANGED, 409);
                }

                $provisioner = new RoomProvisioner($this->catalog, true, $this->rollout);
                $writeResult = ($this->runReconcileWrites)($provisioner);

                $after = ($this->loadKeyedRooms)();
                $this->snapshot->assertPostconditionRows($after);
                $post = $this->snapshot->analyze($after);

                return [
                    'createdCount' => count($writeResult['created'] ?? []),
                    'updatedCount' => count($writeResult['updated'] ?? []),
                    'writesApplied' => true,
                    'post' => $post,
                ];
            });
        } catch (ProductionRoomReconcileException $e) {
            return [
                'status' => $e->status,
                'body' => [
                    'ok' => false,
                    'code' => $e->getMessage(),
                    'writesApplied' => false,
                    'createdCount' => 0,
                    'updatedCount' => 0,
                ],
            ];
        } catch (\Throwable $e) {
            // Transaction callback must have rolled back; surface as fail-closed.
            return [
                'status' => 500,
                'body' => [
                    'ok' => false,
                    'code' => 'ROOM_RECONCILE_FAILED',
                    'writesApplied' => false,
                    'createdCount' => 0,
                    'updatedCount' => 0,
                ],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'classification' => RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED,
                'createdCount' => $result['createdCount'],
                'updatedCount' => $result['updatedCount'],
                'writesApplied' => true,
                'catalogSha256' => $result['post']['catalogSha256'],
                'stateSha256' => $result['post']['stateSha256'],
                'existingCanonicalCount' => $result['post']['existingCanonicalCount'],
                'missingCount' => $result['post']['missingCount'],
                'extraCount' => $result['post']['extraCount'],
                'driftedCount' => $result['post']['driftedCount'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{error:?string,expectedCatalogSha256:?string,expectedStateSha256:?string,expectedExistingCanonicalCount:?int,confirm:?string}
     */
    private function parseBody(array $body): array
    {
        foreach (array_keys($body) as $key) {
            if (!in_array((string) $key, self::ALLOWED_BODY_KEYS, true)) {
                return [
                    'error' => 'unknown field: ' . $key,
                    'expectedCatalogSha256' => null,
                    'expectedStateSha256' => null,
                    'expectedExistingCanonicalCount' => null,
                    'confirm' => null,
                ];
            }
        }

        foreach (self::ALLOWED_BODY_KEYS as $required) {
            if (!array_key_exists($required, $body)) {
                return [
                    'error' => 'missing field: ' . $required,
                    'expectedCatalogSha256' => null,
                    'expectedStateSha256' => null,
                    'expectedExistingCanonicalCount' => null,
                    'confirm' => null,
                ];
            }
        }

        if (!is_string($body['expectedCatalogSha256']) || $body['expectedCatalogSha256'] === '') {
            return $this->badField('expectedCatalogSha256');
        }
        if (!is_string($body['expectedStateSha256']) || $body['expectedStateSha256'] === '') {
            return $this->badField('expectedStateSha256');
        }
        if (!is_int($body['expectedExistingCanonicalCount']) && !(is_string($body['expectedExistingCanonicalCount']) && ctype_digit((string) $body['expectedExistingCanonicalCount']))) {
            return $this->badField('expectedExistingCanonicalCount');
        }
        if (!is_string($body['confirm']) || $body['confirm'] === '') {
            return $this->badField('confirm');
        }

        return [
            'error' => null,
            'expectedCatalogSha256' => (string) $body['expectedCatalogSha256'],
            'expectedStateSha256' => (string) $body['expectedStateSha256'],
            'expectedExistingCanonicalCount' => (int) $body['expectedExistingCanonicalCount'],
            'confirm' => (string) $body['confirm'],
        ];
    }

    /**
     * @return array{error:string,expectedCatalogSha256:null,expectedStateSha256:null,expectedExistingCanonicalCount:null,confirm:null}
     */
    private function badField(string $field): array
    {
        return [
            'error' => 'invalid field: ' . $field,
            'expectedCatalogSha256' => null,
            'expectedStateSha256' => null,
            'expectedExistingCanonicalCount' => null,
            'confirm' => null,
        ];
    }

    /**
     * @param array{expectedCatalogSha256:string,expectedStateSha256:string,expectedExistingCanonicalCount:int,confirm:string} $parsed
     * @param array<string,mixed> $fresh
     */
    private function bindingsMatch(array $parsed, array $fresh): bool
    {
        $expectedConfirm = $this->snapshot->expectedConfirmation((string) $fresh['stateSha256']);

        return hash_equals((string) $fresh['catalogSha256'], $parsed['expectedCatalogSha256'])
            && hash_equals((string) $fresh['stateSha256'], $parsed['expectedStateSha256'])
            && ((int) $fresh['existingCanonicalCount'] === (int) $parsed['expectedExistingCanonicalCount'])
            && hash_equals($expectedConfirm, $parsed['confirm']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultLoadKeyedRooms(): array
    {
        return Chat::query()
            ->whereNotNull('room_key')
            ->orderBy('room_key')
            ->get(['id', 'type', 'title', 'room_key', 'scope_type', 'scope_key', 'visibility', 'audience'])
            ->map(static fn ($row) => [
                'id' => $row->id,
                'type' => (int) $row->type,
                'title' => $row->title,
                'room_key' => $row->room_key,
                'scope_type' => $row->scope_type,
                'scope_key' => $row->scope_key,
                'visibility' => $row->visibility,
                'audience' => $row->audience,
            ])
            ->all();
    }

    /**
     * @param callable(): mixed $callback
     * @return mixed
     */
    private function defaultTransaction(callable $callback)
    {
        return Chat::query()->getConnection()->transaction($callback);
    }

    /**
     * @return array{created:list,updated:list}
     */
    private function defaultRunReconcileWrites(RoomProvisioner $provisioner): array
    {
        $existing = ($this->loadKeyedRooms)();
        $result = $provisioner->run(RoomProvisioner::MODE_RECONCILE, $existing);

        return [
            'created' => $result['created'] ?? [],
            'updated' => $result['updated'] ?? [],
        ];
    }
}
