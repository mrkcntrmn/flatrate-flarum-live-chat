<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Serializers;

use Flarum\Api\Serializer\UserSerializer;

class UserChatSerializer extends UserSerializer
{
    protected function getDefaultAttributes($user)
    {
        // Keep parent user allowlist; do not dump chat pivot internals / IPs.
        return parent::getDefaultAttributes($user);
    }
}
