<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use Flarum\User\User;

class DeleteMessage
{
    /**
     * The chat message id
     *
     * @var int
     */
	public $id;

    /**
     * The user performing the action.
     *
     * @var User
     */
    public $actor;

    /**
     * @param int		$id
     * @param User		$actor
     */
    public function __construct(int $id, User $actor)
    {
        $this->id = $id;
		$this->actor = $actor;
    }
}
