<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Event\Message;

use Flarum\User\User;
use FlatRate\LiveChat\Message;

class Deleting
{
    /**
     * The message that is going to be deleted.
     *
     * @var Message
     */
    public $message;

    /**
     * The user who is performing the action.
     *
     * @var User
     */
    public $actor;

    /**
     * @param Message $message
     * @param User $actor
     */
    public function __construct(Message $message, User $actor)
    {
        $this->message = $message;
        $this->actor = $actor;
    }
}
