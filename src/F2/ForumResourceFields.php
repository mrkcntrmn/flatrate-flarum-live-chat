<?php

namespace FlatRate\LiveChat\F2;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use FlatRate\LiveChat\Auth\BrandLiveAdminPreview;
use FlatRate\LiveChat\Auth\BrandLiveAvailability;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;

class ForumResourceFields
{
    public function __invoke(): array
    {
        $permissions = [
            'flatrate-live-chat.permissions.chat',
            'flatrate-live-chat.permissions.create',
            'flatrate-live-chat.permissions.create.channel',
            'flatrate-live-chat.permissions.enabled',
            'flatrate-live-chat.permissions.edit',
            'flatrate-live-chat.permissions.delete',
            'flatrate-live-chat.permissions.moderate',
            'flatrate-live-chat.permissions.admin-rooms',
        ];

        $fields = [];
        foreach ($permissions as $permission) {
            $fields[] = Schema\Boolean::make($permission)
                ->get(function (object $model, Context $context) use ($permission) {
                    return $context->getActor()->can($permission);
                });
        }

        $fields[] = Schema\Boolean::make('flatrate-live-chat.settings.attachments')
            ->get(fn () => false);
        $fields[] = Schema\Boolean::make('flatrate-live-chat.settings.email_notifications')
            ->get(fn () => false);
        $fields[] = Schema\Boolean::make('flatrate-live-chat.settings.indexing')
            ->get(fn () => false);

        $fields[] = Schema\Boolean::make('flatrate-live-chat.live_chats_navigation_enabled')
            ->get(function () {
                $settings = resolve(SettingsRepositoryInterface::class);
                $nav = $settings->get('flatrate-live-chat.live_chats_navigation_enabled');

                return $nav === '1' || $nav === 1 || $nav === true || $nav === 'true';
            });

        $fields[] = Schema\Boolean::make('flatrate-live-chat.general_live_enabled')
            ->get(function () {
                $settings = resolve(SettingsRepositoryInterface::class);

                return GeneralLiveGate::isEnabled($settings->get(GeneralLiveGate::SETTING_KEY));
            });

        $fields[] = Schema\Boolean::make(GeneralLiveMainRollout::FORUM_ATTRIBUTE)
            ->get(function (object $model, Context $context) {
                $settings = resolve(SettingsRepositoryInterface::class);

                return (new GeneralLiveMainRollout($settings))->availableTo($context->getActor());
            });

        $fields[] = Schema\Boolean::make(BrandLiveAdminPreview::FORUM_ATTRIBUTE)
            ->get(function (object $model, Context $context) {
                $settings = resolve(SettingsRepositoryInterface::class);

                return (new BrandLiveAdminPreview($settings))->availableTo($context->getActor());
            });

        $fields[] = Schema\Boolean::make(BrandLiveAvailability::FORUM_ATTRIBUTE)
            ->get(function (object $model, Context $context) {
                $settings = resolve(SettingsRepositoryInterface::class);
                $brandPreview = new BrandLiveAdminPreview($settings);

                return (new BrandLiveAvailability($settings, $brandPreview))->availableTo($context->getActor());
            });

        $fields[] = Schema\Str::make('flatrate-live-chat.realtime.decision')
            ->get(fn () => 'CENTRIFUGO_SELF_HOSTED');
        $fields[] = Schema\Str::make('flatrate-live-chat.rollout.profile')
            ->get(fn () => 'general-live-first');

        $fields[] = Schema\Boolean::make('flatrate-live-chat.canPreviewHidden')
            ->get(function (object $model, Context $context) {
                return resolve(ChatAuthorization::class)->canPreviewHiddenChatRooms($context->getActor());
            });

        $config = resolve(CentrifugoClientConfig::class);
        foreach ($config->forumAttributes() as $key => $value) {
            if (is_bool($value)) {
                $fields[] = Schema\Boolean::make($key)->get(fn () => $value);
            } elseif (is_int($value)) {
                $fields[] = Schema\Integer::make($key)->get(fn () => $value);
            } else {
                $fields[] = Schema\Str::make($key)->get(fn () => $value === null ? null : (string) $value);
            }
        }

        return $fields;
    }
}
