<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('neonchat_chats', 'room_key')) {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->string('room_key', 191)->nullable();
                $table->string('scope_type', 64)->nullable();
                $table->string('scope_key', 191)->nullable();
            });
        }
        try {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->unique('room_key', 'neonchat_chats_room_key_unique');
            });
        } catch (\Throwable $e) {
            // index may already exist
        }
        try {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->unique(['scope_type', 'scope_key'], 'neonchat_chats_scope_unique');
            });
        } catch (\Throwable $e) {
            // index may already exist
        }
    },
    'down' => function (Builder $schema) {
        try {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->dropUnique('neonchat_chats_room_key_unique');
            });
        } catch (\Throwable $e) {
        }
        try {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->dropUnique('neonchat_chats_scope_unique');
            });
        } catch (\Throwable $e) {
        }
        if ($schema->hasColumn('neonchat_chats', 'room_key')) {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->dropColumn(['room_key', 'scope_type', 'scope_key']);
            });
        }
    },
];
