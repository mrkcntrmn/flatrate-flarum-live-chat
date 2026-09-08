<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use Flarum\Foundation\AbstractValidator;
use Flarum\Settings\SettingsRepositoryInterface;

class MessageValidator extends AbstractValidator
{
	protected function getRules()
	{
		$settings = resolve(SettingsRepositoryInterface::class);
		$max_chars = $settings->get('flatrate-live-chat.settings.charlimit');
		
		return 
		[
			'message' => 
			[
				'required',
				"max:$max_chars"
			]
		];
	}
}