<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

class EventMessageChatCreated extends AbstractEventMessage
{
	public $id = 'chatCreated';

	/**
	 * @param array $users
	 */
	public function __construct($users)
	{
		$this->users = $users;
	}

	public function getAttributes()
	{
		return [
			'users' => $this->users
		];
	}
}