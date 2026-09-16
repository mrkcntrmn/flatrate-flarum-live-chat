import humanTime from 'flarum/utils/humanTime';
import Component from 'flarum/Component';
import classList from 'flarum/utils/classList';
import extractText from 'flarum/utils/extractText';
import SubtreeRetainer from 'flarum/utils/SubtreeRetainer';

import ChatAvatar from './ChatAvatar';

/**
 * Live/group directory row preview.
 * FORUM-MESSAGING-006UI: never render last-message body text here.
 * Allowed: title, kind/privacy hint, recency, unread (parent).
 */
export default class ChatPreview extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        this.model = this.attrs.model;

        this.subtree = new SubtreeRetainer(
            () => this.model.freshness,
            () => app.chat.getCurrentChat(),

            // Reactive attrs
            () => this.model.isNeedToFlash
        );
    }

    onbeforeupdate(vnode) {
        super.onbeforeupdate(vnode);
        this.model = this.attrs.model;

        return this.subtree.needsRebuild();
    }

    view(vnode) {
        return (
            <div style={{ position: 'relative' }}>
                <div className={classList({ 'panel-preview': true, active: app.chat.getCurrentChat() == this.model })}>{this.componentPreview()}</div>
                {this.model.unreaded() ? <div className="unreaded">{this.model.unreaded()}</div> : null}
            </div>
        );
    }

    oncreate(vnode) {
        super.oncreate(vnode);
        if (this.model.isNeedToFlash) {
            app.chat.flashItem($(vnode.dom));
            this.model.isNeedToFlash = false;
        }
    }

    onupdate(vnode) {
        super.onupdate(vnode);
        if (this.model.isNeedToFlash) {
            app.chat.flashItem($(vnode.dom));
            this.model.isNeedToFlash = false;
        }
    }

    componentMessageTime() {
        let lastMessage = this.model.last_message();
        if (!lastMessage) return null;
        let time = new Date(lastMessage.created_at());
        if (Date.now() - time.getTime() < 60 * 60 * 12 * 1000) {
            let nl = (n) => (n < 10 ? '0' : '') + n;
            return nl(time.getHours()) + ':' + nl(time.getMinutes());
        }

        return humanTime(lastMessage.created_at());
    }

    /**
     * Kind · privacy metadata only — never last_message.message().
     */
    componentMetaLine() {
        const visibility = typeof this.model.visibility === 'function' ? this.model.visibility() : null;
        const isPrivate = visibility === 'private' || visibility === 'hidden' || Number(this.model.type()) === 0;
        const kindLabel = isPrivate
            ? app.translator.trans('flatrate-live-chat.forum.chat.list.preview.kind_group')
            : app.translator.trans('flatrate-live-chat.forum.chat.list.preview.kind_live');
        const privacyLabel = isPrivate
            ? app.translator.trans('flatrate-live-chat.forum.chat.list.preview.privacy_private')
            : app.translator.trans('flatrate-live-chat.forum.chat.list.preview.privacy_public');

        return (
            <div className="message meta">
                <span className="empty">
                    {kindLabel} · {privacyLabel}
                </span>
            </div>
        );
    }

    componentPreview() {
        return [
            <ChatAvatar model={this.model} />,
            <div class="previewBody">
                <div className="title" title={this.model.title()}>
                    {this.model.icon() ? <i class={this.model.icon()} style={{ color: this.model.color() }}></i> : null}
                    {this.model.title()}
                </div>
                {this.componentMetaLine()}
            </div>,
            this.model.last_message() ? (
                <div className="timestamp" title={extractText(this.model.last_message().created_at())}>
                    {(this.humanTime = this.componentMessageTime())}
                </div>
            ) : null,
        ];
    }

    componentPreviewChannel() {
        return [
            <ChatAvatar model={this.model} />,
            <div style="display: flex; flex-direction: column">
                <div className="title" title={this.model.title()}>
                    {this.model.title()}
                </div>
                {this.componentMetaLine()}
            </div>,
            this.model.last_message() ? (
                <div className="timestamp" title={extractText(this.model.last_message().created_at())}>
                    {(this.humanTime = this.componentMessageTime())}
                </div>
            ) : null,
        ];
    }
}
