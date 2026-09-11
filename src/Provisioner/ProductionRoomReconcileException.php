<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Provisioner;

final class ProductionRoomReconcileException extends \RuntimeException
{
    public function __construct(
        string $code,
        public readonly int $status = 409
    ) {
        parent::__construct($code);
    }
}
