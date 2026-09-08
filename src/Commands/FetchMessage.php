<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use Flarum\User\User;

class FetchMessage
{
    /**
     * @var mixed
     */
    public $query;

    /**
     * @var User
     */
    public $actor;

    /**
     * @var int
     */
    public $chat_id;

    /**
     * @param mixed $query
     * @param User $actor
     * @param int $chat_id
     */
    public function __construct($query, User $actor, int $chat_id)
    {
        $this->query = $query;
        $this->actor = $actor;
        $this->chat_id = $chat_id;
    }
}
