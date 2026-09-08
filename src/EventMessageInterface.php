<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

/**
 * @property string $id
 */
interface EventMessageInterface
{
	public function content();
}