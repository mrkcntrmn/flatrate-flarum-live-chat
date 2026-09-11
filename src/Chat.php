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

class Chat extends AbstractModel
{
    protected $table = 'neonchat_chats';

    protected $dates = ['created_at'];

    protected $fillable = [
        'title',
        'color',
        'icon',
        'type',
        'creator_id',
        'created_at',
        'room_key',
        'scope_type',
        'scope_key',
        'visibility',
        'audience',
    ];

    /**
     * Create a new chat row.
     */
    public static function build($title, $color, $icon, $type, $creator_id = null, $created_at = null)
    {
        $chat = new static;

        $chat->title = $title;
        $chat->color = $color;
        $chat->icon = $icon;
        $chat->type = $type;
        $chat->creator_id = $creator_id;
        $chat->created_at = $created_at;

        return $chat;
    }

    public function unreadedCount($chatUser)
    {
        $start = $chatUser->readed_at;
        if ($start == null) {
            $start = 0;
        }

        $query = $this->messages()->where('created_at', '>', $start);
        if ($chatUser->removed_at) {
            $query->where('created_at', '<=', $chatUser->removed_at);
        }

        return $query->count();
    }

    /**
     * Lookup membership without auto-join side effects.
     * Reading a public room must NOT mutate membership.
     */
    public function getChatUser(User $user)
    {
        if (!$user->id) {
            return null;
        }

        return ChatUser::where('chat_id', $this->id)->where('user_id', $user->id)->first();
    }

    /**
     * Explicit join only — never called from read/list paths.
     * Reactivates a previously removed membership (subscribe / post).
     */
    public function ensureMembership(User $user)
    {
        if (!$user->id || (int) $this->type !== 1) {
            return $this->getChatUser($user);
        }

        $chatUser = $this->getChatUser($user);
        $now = Carbon::now();
        if (!$chatUser) {
            $this->users()->attach($user->id, ['readed_at' => $now, 'joined_at' => $now]);
            return ChatUser::build($this->id, $user->id, $now, $now);
        }
        if ($chatUser->removed_at) {
            $chatUser->removed_at = null;
            $chatUser->removed_by = null;
            $chatUser->save();
        }
        return $chatUser;
    }

    /**
     * Active subscription create/reactivate (idempotent).
     */
    public function subscribe(User $user)
    {
        return $this->ensureMembership($user);
    }

    /**
     * Soft-unsubscribe via removed_at. Idempotent. Does not delete messages.
     */
    public function unsubscribe(User $user): void
    {
        $chatUser = $this->getChatUser($user);
        if (!$chatUser || $chatUser->removed_at) {
            return;
        }
        $chatUser->removed_at = Carbon::now();
        $chatUser->removed_by = $user->id;
        $chatUser->save();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'neonchat_chat_user')->withPivot('joined_at', 'removed_by', 'role', 'readed_at', 'removed_at');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function last_message()
    {
        return $this->hasOne(Message::class)->orderBy('id', 'desc')->whereNull('deleted_by');
    }

    public function first_message()
    {
        return $this->hasOne(Message::class)->orderBy('id', 'asc')->whereNull('deleted_by');
    }
}
