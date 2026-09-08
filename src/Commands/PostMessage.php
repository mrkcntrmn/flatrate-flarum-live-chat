<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use Flarum\User\User;

class PostMessage
{

    /**
     * @var User
     */
    public $actor;

    /**
     * @var array
     */
    public $data;

    /**
     * @var string|null
     */
    public $ip_address;

    /**
     * @param User $actor
     * @param mixed $data
     * @param string|null $ip_address CHAT_IP_PERSISTENCE=false — callers pass null
     */
    public function __construct(User $actor, $data, ?string $ip_address = null)
    {
        $this->actor = $actor;
        $this->data = $data;
        $this->ip_address = $ip_address;
    }
}
