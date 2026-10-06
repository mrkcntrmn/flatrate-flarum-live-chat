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
 * Stricter than RoomProvisioner: initial 0→46 create, already-reconciled
 * no-op, or one bound missing-only repair. Every other partial, extra, or
 * drifted state fails closed.
 */
final class ProductionRoomReconcileService
{
    /**
     * The only partial catalog this production reconciler may repair.
     * Any other missing set stays REVIEW_REQUIRED.
     *
     * @var list<string>
     */
    public const MISSING_ONLY_REPAIR_KEYS = [
        'aston-martin-live',
        'jlr-live',
        'land-rover-live',
        'range-rover-live',
    ];

    public const ERR_STATE_CHANGED = 'ROOM_RECONCILE_STATE_CHANGED';
    public const ERR_REQUIRES_REVIEW = 'PRODUCTION_ROOM_STATE_REQUIRES_REVIEW';
    public const ERR_BAD_REQUEST = 'ROOM_RECONCILE_BAD_REQUEST';

    /** @var list<string> */
    private const ALLOWED_BODY_KEYS = [
        'expectedCatalogSha256',
        'expectedStateSha256',
        'expectedExistingCanonicalCount',
        'expectedMissingRoomKeys',
        'confirm',
    ];

    /** @var list<string> */
    private const REQUIRED_BODY_KEYS = [
        'expectedCatalogSha256',
        'expectedStateSha256',
        'expectedExistingCanonicalCount',
        'confirm',
    ];

    /**
     * @param callable(): list<array<string,mixed>>|null $loadKeyedRooms
     * @param callable(callable(): mixed): mixed|null $transaction
     * @param callable(RoomProvisioner): array|null $runReconcileWrites
     * @param callable(RoomProvisioner, list<string>): array{created:list<array<string,mixed>>,updated:list<array<string,mixed>>}|null $createMissingOnly
     */
    public function __construct(
        private RoomCatalog $catalog,
        private RolloutProfile $rollout,
        private ?RoomReconcileSnapshot $snapshot = null,
        private $loadKeyedRooms = null,
        private $transaction = null,
        private $runReconcileWrites = null,
        private $createMissingOnly = null
    ) {
        $this->snapshot = $snapshot ?? new RoomReconcileSnapshot($catalog, $rollout);
        $this->loadKeyedRooms = $loadKeyedRooms ?? [$this, 'defaultLoadKeyedRooms'];
        $this->transaction = $transaction ?? [$this, 'defaultTransaction'];
        $this->runReconcileWrites = $runReconcileWrites ?? [$this, 'defaultRunReconcileWrites'];
        $this->createMissingOnly = $createMissingOnly ?? [$this, 'defaultCreateMissingOnly'];
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(): array
    {
        return $this->inspect();
    }

    /**
     * Snapshot classification, plus the one bound missing-only repair.
     *
     * @return array<string,mixed>
     */
    private function inspect(): array
    {
        $analysis = $this->snapshot->analyze(($this->loadKeyedRooms)());
        if ($this->isExactMissingOnlyRepair($analysis)) {
            $analysis['classification'] = RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR;
        }

        return $analysis;
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

        $fresh = $this->inspect();
        $class = (string) $fresh['classification'];

        if (!$this->bindingsMatch($parsed, $fresh)) {
            return $this->conflict(self::ERR_STATE_CHANGED, $fresh);
        }

        if ($parsed['missingKeysProvided']
            && ($class === RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED
                || $class === RoomReconcileSnapshot::CLASS_READY_INITIAL_CREATE)
        ) {
            return $this->badRequest('unexpected field: expectedMissingRoomKeys');
        }

        if ($class === RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR) {
            if (!$parsed['missingKeysProvided']) {
                return $this->badRequest('missing field: expectedMissingRoomKeys');
            }
            if (!$this->missingKeysMatch($parsed['expectedMissingRoomKeys'], $fresh)) {
                return $this->conflict(self::ERR_STATE_CHANGED, $fresh);
            }
        }

        if ($class === RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED) {
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
                    'existingCanonicalCount' => 46,
                    'missingCount' => 0,
                    'extraCount' => 0,
                    'driftedCount' => 0,
                ],
            ];
        }

        if ($class === RoomReconcileSnapshot::CLASS_READY_INITIAL_CREATE
            && $fresh['existingCanonicalCount'] === 0
            && $fresh['missingCount'] === 46
            && $fresh['extraCount'] === 0
            && $fresh['driftedCount'] === 0
            && $fresh['catalogCount'] === 46
            && $fresh['rolloutProfile'] === RolloutProfile::PROFILE_GENERAL_LIVE_FIRST
        ) {
            return $this->runWrite(function () use ($parsed) {
                $inside = $this->inspect();
                if (!$this->bindingsMatch($parsed, $inside)
                    || $inside['classification'] !== RoomReconcileSnapshot::CLASS_READY_INITIAL_CREATE
                    || $parsed['missingKeysProvided']
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
        }

        if ($class === RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR) {
            return $this->runWrite(function () use ($parsed) {
                $before = ($this->loadKeyedRooms)();
                $inside = $this->inspect();
                if (!$this->bindingsMatch($parsed, $inside)
                    || $inside['classification'] !== RoomReconcileSnapshot::CLASS_READY_MISSING_ONLY_REPAIR
                    || !$this->missingKeysMatch($parsed['expectedMissingRoomKeys'], $inside)
                ) {
                    throw new ProductionRoomReconcileException(self::ERR_STATE_CHANGED, 409);
                }

                $provisioner = new RoomProvisioner($this->catalog, true, $this->rollout);
                $writeResult = ($this->createMissingOnly)($provisioner, self::MISSING_ONLY_REPAIR_KEYS);
                $createdKeys = $this->createdRoomKeys($writeResult['created'] ?? []);
                if ($createdKeys !== self::MISSING_ONLY_REPAIR_KEYS || ($writeResult['updated'] ?? []) !== []) {
                    throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
                }

                $after = ($this->loadKeyedRooms)();
                $this->assertExistingRowsUntouched($before, $after);
                $this->snapshot->assertPostconditionRows($after);
                $post = $this->snapshot->analyze($after);
                if ($post['classification'] !== RoomReconcileSnapshot::CLASS_ALREADY_RECONCILED
                    || $post['existingCanonicalCount'] !== 46
                    || $post['missingCount'] !== 0
                    || $post['extraCount'] !== 0
                    || $post['driftedCount'] !== 0
                ) {
                    throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
                }

                return [
                    'createdCount' => 4,
                    'updatedCount' => 0,
                    'writesApplied' => true,
                    'post' => $post,
                ];
            });
        }

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

    /**
     * @param array<string,mixed> $body
     * @return array{error:?string,expectedCatalogSha256:?string,expectedStateSha256:?string,expectedExistingCanonicalCount:?int,confirm:?string,missingKeysProvided:bool,expectedMissingRoomKeys:?list<string>}
     */
    private function parseBody(array $body): array
    {
        foreach (array_keys($body) as $key) {
            if (!in_array((string) $key, self::ALLOWED_BODY_KEYS, true)) {
                return $this->parseError('unknown field: ' . $key);
            }
        }

        foreach (self::REQUIRED_BODY_KEYS as $required) {
            if (!array_key_exists($required, $body)) {
                return $this->parseError('missing field: ' . $required);
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

        $missingKeysProvided = array_key_exists('expectedMissingRoomKeys', $body);
        $missingKeys = null;
        if ($missingKeysProvided) {
            $missingKeys = $this->parseMissingKeyList($body['expectedMissingRoomKeys']);
            if ($missingKeys === null) {
                return $this->badField('expectedMissingRoomKeys');
            }
        }

        return [
            'error' => null,
            'expectedCatalogSha256' => (string) $body['expectedCatalogSha256'],
            'expectedStateSha256' => (string) $body['expectedStateSha256'],
            'expectedExistingCanonicalCount' => (int) $body['expectedExistingCanonicalCount'],
            'confirm' => (string) $body['confirm'],
            'missingKeysProvided' => $missingKeysProvided,
            'expectedMissingRoomKeys' => $missingKeys,
        ];
    }

    /**
     * @return array{error:string,expectedCatalogSha256:null,expectedStateSha256:null,expectedExistingCanonicalCount:null,confirm:null,missingKeysProvided:false,expectedMissingRoomKeys:null}
     */
    private function parseError(string $error): array
    {
        return [
            'error' => $error,
            'expectedCatalogSha256' => null,
            'expectedStateSha256' => null,
            'expectedExistingCanonicalCount' => null,
            'confirm' => null,
            'missingKeysProvided' => false,
            'expectedMissingRoomKeys' => null,
        ];
    }

    /**
     * @return array{error:string,expectedCatalogSha256:null,expectedStateSha256:null,expectedExistingCanonicalCount:null,confirm:null,missingKeysProvided:false,expectedMissingRoomKeys:null}
     */
    private function badField(string $field): array
    {
        return $this->parseError('invalid field: ' . $field);
    }

    /**
     * A present missing-key binding must be a list of non-empty strings.
     * Set equality is checked later against the fresh snapshot.
     *
     * @return list<string>|null
     */
    private function parseMissingKeyList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $keys = [];
        foreach ($value as $key) {
            if (!is_string($key) || $key === '') {
                return null;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param array<string,mixed> $fresh
     * @return array{status:int,body:array<string,mixed>}
     */
    private function badRequest(string $message): array
    {
        return [
            'status' => 400,
            'body' => [
                'ok' => false,
                'code' => self::ERR_BAD_REQUEST,
                'message' => $message,
                'writesApplied' => false,
                'createdCount' => 0,
                'updatedCount' => 0,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $fresh
     * @return array{status:int,body:array<string,mixed>}
     */
    private function conflict(string $code, array $fresh): array
    {
        return [
            'status' => 409,
            'body' => [
                'ok' => false,
                'code' => $code,
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

    /**
     * @param callable(): array{createdCount:int,updatedCount:int,writesApplied:bool,post:array<string,mixed>} $callback
     * @return array{status:int,body:array<string,mixed>}
     */
    private function runWrite(callable $callback): array
    {
        try {
            $result = ($this->transaction)($callback);
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
     * @param array<string,mixed> $analysis
     */
    private function isExactMissingOnlyRepair(array $analysis): bool
    {
        $keys = $analysis['missingRoomKeys'] ?? null;
        if (!is_array($keys)) {
            return false;
        }
        $sorted = array_values($keys);
        sort($sorted);

        return ($analysis['classification'] ?? null) === RoomReconcileSnapshot::CLASS_REVIEW_REQUIRED
            && (int) ($analysis['catalogCount'] ?? 0) === 46
            && (int) ($analysis['existingCanonicalCount'] ?? -1) === 42
            && (int) ($analysis['missingCount'] ?? -1) === 4
            && (int) ($analysis['extraCount'] ?? -1) === 0
            && (int) ($analysis['driftedCount'] ?? -1) === 0
            && ($analysis['rolloutProfile'] ?? null) === RolloutProfile::PROFILE_GENERAL_LIVE_FIRST
            && $sorted === self::MISSING_ONLY_REPAIR_KEYS;
    }

    /**
     * @param list<string>|null $provided
     * @param array<string,mixed> $fresh
     */
    private function missingKeysMatch(?array $provided, array $fresh): bool
    {
        if ($provided === null) {
            return false;
        }
        $sorted = $provided;
        sort($sorted);
        $unique = array_values(array_unique($sorted));
        if ($sorted !== $unique || $sorted !== self::MISSING_ONLY_REPAIR_KEYS) {
            return false;
        }
        $freshKeys = $fresh['missingRoomKeys'] ?? null;
        if (!is_array($freshKeys)) {
            return false;
        }
        $freshSorted = array_values($freshKeys);
        sort($freshSorted);

        return $freshSorted === $sorted;
    }

    /**
     * @param list<mixed> $created
     * @return list<string>
     */
    private function createdRoomKeys(array $created): array
    {
        $keys = [];
        foreach ($created as $row) {
            if (!is_array($row)) {
                throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
            }
            $key = (string) ($row['roomKey'] ?? '');
            if ($key === '') {
                throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
            }
            $keys[] = $key;
        }
        sort($keys);

        return $keys;
    }

    /**
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     */
    private function assertExistingRowsUntouched(array $before, array $after): void
    {
        $afterByKey = $this->indexRowsByRoomKey($after);
        foreach ($this->indexRowsByRoomKey($before) as $key => $row) {
            if (!isset($afterByKey[$key]) || $afterByKey[$key] !== $row) {
                throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array{id:string,type:int,title:string,room_key:string,scope_type:string,scope_key:string,visibility:string,audience:string}>
     */
    private function indexRowsByRoomKey(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $key = (string) ($row['room_key'] ?? $row['roomKey'] ?? '');
            if ($key === '' || isset($indexed[$key])) {
                throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
            }
            $indexed[$key] = [
                'id' => (string) ($row['id'] ?? ''),
                'type' => (int) ($row['type'] ?? 0),
                'title' => (string) ($row['title'] ?? $row['name'] ?? ''),
                'room_key' => $key,
                'scope_type' => (string) ($row['scope_type'] ?? $row['scopeType'] ?? ''),
                'scope_key' => (string) ($row['scope_key'] ?? $row['scopeKey'] ?? ''),
                'visibility' => (string) ($row['visibility'] ?? ''),
                'audience' => (string) ($row['audience'] ?? ''),
            ];
        }

        return $indexed;
    }

    /**
     * @param list<string> $allowedKeys
     * @return array{created:list,updated:list}
     */
    private function defaultCreateMissingOnly(RoomProvisioner $provisioner, array $allowedKeys): array
    {
        $allowed = array_fill_keys($allowedKeys, true);
        $existing = ($this->loadKeyedRooms)();
        $result = $provisioner->run(RoomProvisioner::MODE_RECONCILE, $existing);
        $created = [];
        foreach ($result['created'] ?? [] as $row) {
            $key = (string) ($row['roomKey'] ?? '');
            if ($key === '' || !isset($allowed[$key])) {
                throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
            }
            $created[] = $row;
        }
        if (($result['updated'] ?? []) !== []) {
            throw new ProductionRoomReconcileException(self::ERR_REQUIRES_REVIEW, 409);
        }

        return [
            'created' => $created,
            'updated' => [],
        ];
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
