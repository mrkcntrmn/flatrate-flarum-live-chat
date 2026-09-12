import Component from 'flarum/Component';
import Button from 'flarum/components/Button';
import ChatEditModal from './ChatEditModal';
import { throttle } from 'flarum/utils/throttleDebounce';
import extractText from 'flarum/utils/extractText';

export default class ChatInput extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        this.model = this.attrs.model;
        this.state = this.attrs.state;

        app.chat.input = this;

        this.messageCharLimit = app.forum.attribute('flatrate-live-chat.settings.charlimit') ?? 512;

        this.updatePlaceholder();
    }

    oncreate(vnode) {
        super.oncreate(vnode);

        let inputState = this.state.input;
        let input = this.$('#chat-input')[0];
        input.lineHeight = parseInt(window.getComputedStyle(input).getPropertyValue('line-height'));
        inputState.element = input;

        if (inputState.content() && inputState.content().length) {
            this.inputProcess({ target: input });
        }

        this.updateLimit();
    }

    onbeforeupdate(vnode, old) {
        super.onbeforeupdate(vnode, old);

        if (this.model !== this.attrs.model) {
            this.model = this.attrs.model;
            this.state = this.attrs.state;
        }
        this.updatePlaceholder();
    }

    updatePlaceholder() {
        if (!app.session.user) this.inputPlaceholder = app.translator.trans('flatrate-live-chat.forum.errors.unauthenticated');
        else if (!app.chat.getPermissions().post) this.inputPlaceholder = app.translator.trans('flatrate-live-chat.forum.errors.chatdenied');
        else if (this.model.removed_at()) this.inputPlaceholder = app.translator.trans('flatrate-live-chat.forum.errors.removed');
        else this.inputPlaceholder = app.translator.trans('flatrate-live-chat.forum.chat.placeholder');
    }

    view() {
        const canPost = app.chat.getPermissions().post && !this.model.removed_at();
        const remaining = this.messageCharLimit - (this.state.input.messageLength || 0);
        const showCounter = remaining < 100;
        const sendLabel = extractText(app.translator.trans('flatrate-live-chat.forum.chat.send'));

        return (
            <div className="ChatInput input-wrapper">
                <textarea
                    id="chat-input"
                    maxlength={this.messageCharLimit}
                    disabled={!canPost}
                    placeholder={this.inputPlaceholder}
                    onkeypress={this.inputPressEnter.bind(this)}
                    oninput={this.inputProcess.bind(this)}
                    onpaste={this.inputProcess.bind(this)}
                    onkeyup={this.inputSaveDraft.bind(this)}
                    rows={this.state.input.rows}
                    value={this.state.input.content()}
                    onupdate={() => this.saveDraft.apply(this)}
                />
                {this.state.messageEditing ? (
                    <Button
                        className="Button Button--icon Button--flat ChatInput-cancel"
                        icon="fas fa-times"
                        onclick={this.state.messageEditEnd.bind(this.state)}
                        aria-label={extractText(app.translator.trans('flatrate-live-chat.forum.chat.cancel_edit'))}
                    />
                ) : null}
                {this.model.removed_at() && this.model.removed_by() === parseInt(app.session.user.id()) ? (
                    <Button className="Button Button--primary ButtonRejoin" onclick={() => app.modal.show(ChatEditModal, { model: this.model })}>
                        {app.translator.trans('flatrate-live-chat.forum.chat.rejoin')}
                    </Button>
                ) : (
                    [
                        <Button
                            className="Button Button--icon Button--primary ChatInput-send"
                            icon="fas fa-paper-plane"
                            disabled={!canPost}
                            onclick={this.inputPressButton.bind(this)}
                            title={sendLabel}
                            aria-label={sendLabel}
                        />,
                        <div id="chat-limiter" className={'ChatInput-limiter' + (showCounter ? ' reaching-limit' : '')} hidden={!showCounter}>
                            {remaining}
                        </div>,
                    ]
                )}
            </div>
        );
    }

    updateLimit() {
        const limiter = this.element && this.element.querySelector('#chat-limiter');
        const remaining = this.messageCharLimit - (this.state.input.messageLength || 0);
        if (!limiter) return;
        limiter.innerText = String(remaining);
        limiter.hidden = remaining >= 100;
        limiter.className = remaining < 100 ? 'ChatInput-limiter reaching-limit' : 'ChatInput-limiter';
    }

    saveDraft(text = this.state.input.content()) {
        this.state.input.lastDraft != text &&
            throttle(300, () => {
                this.state.setChatStorageValue('draft', text);
            })();
        this.state.input.lastDraft = text;
    }

    inputSaveDraft(e) {
        if (e) e.redraw = false;

        let input = e.target;
        this.saveDraft(input.value.trim());
    }

    resizeInput() {
        let input = this.state.getChatInput();

        input.rows = 1;
        this.state.input.rows = Math.min(input.scrollHeight / input.lineHeight, app.screen() === 'phone' ? 2 : 5);
        input.rows = this.state.input.rows;
    }

    inputProcess(e) {
        if (e) e.redraw = false;

        let input = e.target;
        this.state.input.content(input.value);
        let inputValue = input.value.trim();
        this.state.input.messageLength = inputValue.length;
        this.updateLimit();

        this.resizeInput();

        if (this.state.input.messageLength) {
            if (!this.state.input.writingPreview && !this.state.messageEditing) this.inputPreviewStart(inputValue);
        } else {
            if (this.state.input.writingPreview && !inputValue.length) this.inputPreviewEnd();
        }

        if (this.state.messageEditing) this.state.messageEditing.content = inputValue;
        else if (this.state.input.writingPreview) this.state.input.previewModel.content = inputValue;

        if (this.attrs.oninput) this.attrs.oninput(e);
    }

    inputPressEnter(e) {
        e.redraw = false;
        if (e.keyCode == 13 && !e.shiftKey) {
            this.state.messageSend();
            return false;
        }
        return true;
    }

    inputPressButton() {
        this.state.messageSend();
    }

    inputPreviewStart(content) {
        if (!this.state.input.writingPreview) {
            this.state.input.writingPreview = true;

            this.state.input.previewModel = app.store.createRecord('chatmessages');
            this.state.input.previewModel.pushData({
                id: 0,
                attributes: { message: ' ', created_at: 0 },
                relationships: { user: app.session.user, chat: this.model },
            });
            Object.assign(this.state.input.previewModel, { isEditing: true, isNeedToFlash: true, content });
        } else this.state.input.previewModel.isNeedToFlash = true;

        m.redraw();
    }

    inputPreviewEnd() {
        this.state.input.writingPreview = false;

        m.redraw();
    }
}
