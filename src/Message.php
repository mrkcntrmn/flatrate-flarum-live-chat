<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use Carbon\Carbon;
use Flarum\User\User;
use Flarum\Database\AbstractModel;

class Message extends AbstractModel
{
    protected $table = 'neonchat_messages';

    protected $dates = ['created_at', 'edited_at'];

    /**
     * CHAT_IP_PERSISTENCE=false — ip_address is never written.
     */
    public static function build(
        $message,
        $user_id,
        $created_at,
        $chat_id = 1,
        $ip_address = null,
        $type = 0,
        $is_readed = false,
        $edited_at = null,
        $deleted_by = null
    ) {
        $msg = new static;

        $msg->message = $message;
        $msg->user_id = $user_id;
        $msg->created_at = $created_at;
        $msg->edited_at = $edited_at;
        $msg->deleted_by = $deleted_by;
        $msg->chat_id = $chat_id;
        $msg->type = $type;
        $msg->is_readed = $is_readed;
        // Intentionally do not persist IP addresses.
        $msg->ip_address = null;

        return $msg;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function deleted_by()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }
}
