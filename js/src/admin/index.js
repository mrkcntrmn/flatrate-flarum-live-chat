import { extend } from 'flarum/extend';
import app from 'flarum/app';
import PermissionGrid from 'flarum/components/PermissionGrid';

app.initializers.add('flatrate-live-chat', (app) => {
    app.extensionData
        .for('flatrate-live-chat')
        .registerSetting({
            setting: 'flatrate-live-chat.settings.charlimit',
            label: app.translator.trans('flatrate-live-chat.admin.settings.charlimit'),
            type: 'number',
        })
        .registerSetting({
            setting: 'flatrate-live-chat.settings.floodgate.number',
            label: app.translator.trans('flatrate-live-chat.admin.settings.floodgate.number'),
            type: 'number',
        })
        .registerSetting({
            setting: 'flatrate-live-chat.settings.floodgate.time',
            label: app.translator.trans('flatrate-live-chat.admin.settings.floodgate.time'),
            type: 'text',
        })
        .registerSetting({
            setting: 'flatrate-live-chat.settings.display.minimize',
            label: app.translator.trans('flatrate-live-chat.admin.settings.display.minimize'),
            type: 'switch',
        })
        .registerSetting({
            setting: 'flatrate-live-chat.settings.display.censor',
            label: app.translator.trans('flatrate-live-chat.admin.settings.display.censor'),
            type: 'switch',
        })
        .registerSetting({
            setting: 'flatrate-live-chat.live_chats_navigation_enabled',
            label: app.translator.trans('flatrate-live-chat.admin.settings.live_chats_navigation'),
            type: 'boolean',
            default: false,
        })
        .registerPermission(
            {
                icon: 'fas fa-eye',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.enabled'),
                permission: 'flatrate-live-chat.permissions.enabled',
                allowGuest: true,
            },
            'view'
        )
        .registerPermission(
            {
                icon: 'fas fa-comment-medical',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.create.chat'),
                permission: 'flatrate-live-chat.permissions.create',
            },
            'start'
        )
        .registerPermission(
            {
                icon: 'fas fa-comment-medical',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.create.channel'),
                permission: 'flatrate-live-chat.permissions.create.channel',
            },
            'start'
        )
        .registerPermission(
            {
                icon: 'fas fa-comments',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.post'),
                permission: 'flatrate-live-chat.permissions.chat',
            },
            'reply'
        )
        .registerPermission(
            {
                icon: 'fas fa-pencil-alt',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.edit'),
                permission: 'flatrate-live-chat.permissions.edit',
            },
            'reply'
        )
        .registerPermission(
            {
                icon: 'far fa-trash-alt',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.delete'),
                permission: 'flatrate-live-chat.permissions.delete',
            },
            'reply'
        )
        .registerPermission(
            {
                icon: 'fas fa-eye',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.moderate.vision'),
                permission: 'flatrate-live-chat.permissions.moderate.vision',
            },
            'moderate'
        )
        .registerPermission(
            {
                icon: 'far fa-trash-alt',
                label: app.translator.trans('flatrate-live-chat.admin.permissions.moderate.delete'),
                permission: 'flatrate-live-chat.permissions.moderate.delete',
            },
            'moderate'
        );
});
