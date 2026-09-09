<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

interface RealtimeTokenIssuer
{
    /**
     * Mint a Centrifugo connection JWT.
     *
     * @return array{token:string,expiresAt:int}
     */
    public function issueConnectionToken(string $subject): array;

    /**
     * Mint a channel-bound Centrifugo subscription JWT.
     *
     * @return array{token:string,expiresAt:int}
     */
    public function issueSubscriptionToken(string $subject, string $channel): array;
}
