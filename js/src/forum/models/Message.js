import Model from 'flarum/Model';

export default class Message extends Model {}

Object.assign(Message.prototype, {
    message: Model.attribute('message'),
    content: Model.attribute('content'),
    user: Model.hasOne('user'),
    userId: Model.attribute('userId'),
    deleted_by: Model.hasOne('deleted_by'),
    chat: Model.hasOne('chat'),
    chatId: Model.attribute('chatId'),
    chat_id: Model.attribute('chat_id'),
    created_at: Model.attribute('created_at', Model.transformDate),
    edited_at: Model.attribute('edited_at', Model.transformDate),
    type: Model.attribute('type'),
    is_readed: Model.attribute('is_readed'),
    ip_address: Model.attribute('ip_address'),
    is_censored: Model.attribute('is_censored'),
});

// F2 payloads may only include `content`; keep historical `.message()` callers working.
const messageAttr = Message.prototype.message;
const contentAttr = Message.prototype.content;
Message.prototype.message = function messageWithContentFallback() {
    const primary = messageAttr.call(this);
    if (primary != null && primary !== '') return primary;
    return contentAttr.call(this);
};
