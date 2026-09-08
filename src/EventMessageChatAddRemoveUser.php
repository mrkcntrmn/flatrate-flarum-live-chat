<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

class EventMessageChatAddRemoveUser extends AbstractEventMessage
{
	public $id = 'chatAddRemoveUser';

	/**
	 * @param array $add
	 * @param array $remove
	 */
	public function __construct($add, $remove)
	{
		$this->add = $add;
		$this->remove = $remove;
	}

	public function getAttributes()
	{
		return [
			'add' => $this->add,
			'remove' => $this->remove
		];
	}
}