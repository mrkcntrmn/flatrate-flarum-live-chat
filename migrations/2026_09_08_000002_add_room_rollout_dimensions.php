<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('neonchat_chats', 'visibility')) {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->string('visibility', 32)->nullable()->default('hidden');
                $table->string('audience', 32)->nullable()->default('staff-preview');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('neonchat_chats', 'visibility')) {
            $schema->table('neonchat_chats', function (Blueprint $table) {
                $table->dropColumn(['visibility', 'audience']);
            });
        }
    },
];
