<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use Flarum\User\User;

class EventMessageChatEdited extends AbstractEventMessage
{
	public $id = 'chatEdited';

	/**
	 * @param User $editor
	 * @param string $column
	 * @param mixed $old
	 * @param mixed $new
	 */
	public function __construct(string $column, $old, $new)
	{
		$this->column = $column;
		$this->old = $old;
		$this->new = $new;
	}

	public function getAttributes()
	{
		return [
			'column' => $this->column,
			'old' => $this->old,
			'new' => $this->new
		];
	}
}