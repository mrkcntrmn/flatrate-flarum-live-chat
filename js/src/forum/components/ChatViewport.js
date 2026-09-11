import Component from 'flarum/Component';
import LoadingIndicator from 'flarum/components/LoadingIndicator';

import ChatInput from './ChatInput';
import ChatMessage from './ChatMessage';
import ChatEventMessage from './ChatEventMessage';
import ChatWelcome from './ChatWelcome';
import Message from '../models/Message';
import timedRedraw from '../utils/timedRedraw';
import { processVisibleUnread } from '../utils/processVisibleUnread';
import { startInitialHistoryFetch, settleInitialHistoryFetch } from '../utils/chatMessagesFetchLifecycle';

export default class ChatViewport extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        this.model = this.attrs.chatModel;
        if (this.model) {
            this.state = app.chat.getViewportState(this.model);
        }
    }

    oncreate(vnode) {
        super.oncreate(vnode);
        this.loadChat();
    }

    onupdate(vnode) {
        super.onupdate(vnode);

        // this.attrs is broken in onupdate hook
        const model = vnode.attrs.chatModel;

        if (model !== this.model) {
            this.model = model;
            if (this.model) {
                this.state = app.chat.getViewportState(this.model);
                this.loadChat();
            }
            app.chat.flashItem($('.wrapper'));
        }
    }

    loadChat() {
        if (!this.state) return;

        const oldScroll = this.state.scroll.oldScroll;
        this.reloadMessages();
        m.redraw();

        setTimeout(() => {
            const wrapper = this.getChatWrapper();
            if (!wrapper) return;
            wrapper.scrollTop = wrapper.scrollHeight - wrapper.clientHeight - oldScroll;
        }, 200);
    }

    view(vnode) {
        if (this.model) {
            return (
                <div className="ChatViewport">
                    <div
                        className="wrapper"
                        oncreate={this.wrapperOnCreate.bind(this)}
                        onbeforeupdate={this.wrapperOnBeforeUpdate.bind(this)}
                        onupdate={this.wrapperOnUpdate.bind(this)}
                        onremove={this.wrapperOnRemove.bind(this)}
                    >
                        {this.componentLoader(this.state.scroll.loading)}
                        {this.componentsChatMessages(this.model).concat(
                            this.state.input.writingPreview ? this.componentChatMessage(this.state.input.previewModel) : []
                        )}
                    </div>
                    <ChatInput
                        state={this.state}
                        model={this.model}
                        oninput={() => {
                            if (this.nearBottom() && !this.state.messageEditing) {
                                this.scrollToBottom();
                            }
                        }}
                    ></ChatInput>
                    {this.isFastScrollAvailable() ? this.componentScroller() : null}
                </div>
            );
        }

        return (
            <div className="ChatViewport">
                <ChatWelcome />;
            </div>
        );
    }

    componentChatMessage(model) {
        return model.type() ? <ChatEventMessage key={model.id()} model={model} /> : <ChatMessage key={model.id()} model={model} />;
    }

    componentsChatMessages(chat) {
        return app.chat.getChatMessages().map((model) => this.componentChatMessage(model));
    }

    componentScroller() {
        return (
            <div className="scroller" onclick={this.fastScroll.bind(this)}>
                <i class="fas fa-angle-down"></i>
            </div>
        );
    }

    componentLoader(watch) {
        return watch ? (
            <msgloader className="message-wrapper--loading">
                <LoadingIndicator className="loading-old Button-icon" />
            </msgloader>
        ) : null;
    }
    getChatWrapper() {
        if (this.scrollElement) {
            return this.scrollElement;
        }
        return this.element?.querySelector('.wrapper') ?? document.querySelector('.ChatViewport .wrapper');
    }

    isFastScrollAvailable() {
        let chatWrapper = this.getChatWrapper();
        return (
            (this.state.newPushedPosts ||
                this.model.unreaded() >= 30 ||
                (chatWrapper && chatWrapper.scrollHeight > 2000 && chatWrapper.scrollTop < chatWrapper.scrollHeight - 2000)) &&
            !this.nearBottom()
        );
    }

    fastScroll(e) {
        if (this.model.unreaded() >= 30) this.fastMessagesFetch(e);
        else {
            let chatWrapper = this.getChatWrapper();
            chatWrapper.scrollTop = Math.max(chatWrapper.scrollTop, chatWrapper.scrollHeight - 3000);
            this.scrollToBottom();
        }
    }

    fastMessagesFetch(e) {
        e.redraw = false;
        app.chat.chatmessages = [];

        app.chat.apiFetchChatMessages(this.model).then((r) => {
            this.scrollToBottom();
            timedRedraw(300);

            this.model.pushAttributes({ unreaded: 0 });
            let message = app.chat.getChatMessages((mdl) => mdl.chat() == this.model).slice(-1)[0];
            app.chat.apiReadChat(this.model, message);
        });
    }

    wrapperOnCreate(vnode) {
        super.oncreate(vnode);
        this.wrapperOnUpdate(vnode);

        this.scrollElement = vnode.dom;
        this.boundScrollListener = this.wrapperOnScroll.bind(this);
        this.scrollElement.addEventListener('scroll', this.boundScrollListener, { passive: true });
    }

    wrapperOnBeforeUpdate(vnode, vnodeNew) {
        super.onbeforeupdate(vnode, vnodeNew);
        if (!this.state.autoScroll && this.nearBottom() && this.state.newPushedPosts) {
            this.scrollAfterUpdate = true;
        }
    }

    wrapperOnUpdate(vnode) {
        super.onupdate(vnode);
        let el = vnode.dom;
        if (this.model && this.state.scroll.autoScroll) {
            if (this.autoScrollTimeout) clearTimeout(this.autoScrollTimeout);
            this.autoScrollTimeout = setTimeout(this.scrollToBottom.bind(this, true), 100);
        }
        if (el.scrollTop <= 0) el.scrollTop = 1;
        this.checkUnreaded();

        if (this.scrollAfterUpdate) {
            this.scrollAfterUpdate = false;
            this.scrollToBottom();
        }
    }

    wrapperOnRemove(vnode) {
        super.onremove(vnode);
        if (this.scrollElement && this.boundScrollListener) {
            this.scrollElement.removeEventListener('scroll', this.boundScrollListener);
        }
        this.scrollElement = null;
        this.boundScrollListener = null;
    }

    wrapperOnScroll(e) {
        const el = e?.currentTarget || this.getChatWrapper();
        if (!el) return;

        this.state.scroll.oldScroll = el.scrollHeight - el.clientHeight - el.scrollTop;

        this.checkUnreaded();

        if (this.lastFastScrollStatus != this.isFastScrollAvailable()) {
            this.lastFastScrollStatus = this.isFastScrollAvailable();
            m.redraw();
        }

        let currentHeight = el.scrollHeight;

        if (this.atBottom()) {
            this.state.newPushedPosts = false;
        }

        if (this.state.scroll.autoScroll || this.state.loading || this.scrolling) return;

        if (!this.state.messageEditing && el.scrollTop >= 0) {
            if (el.scrollTop <= 500) {
                let topMessage = app.chat.getChatMessages((model) => model.chat() == this.model)[0];
                if (topMessage && topMessage != this.model.first_message()) {
                    app.chat.apiFetchChatMessages(this.model, topMessage.created_at().toISOString());
                }
            } else if (el.scrollTop + el.clientHeight >= currentHeight - 500) {
                let bottomMessage = app.chat.getChatMessages((model) => model.chat() == this.model).slice(-1)[0];
                if (bottomMessage && bottomMessage != this.model.last_message()) {
                    app.chat.apiFetchChatMessages(this.model, bottomMessage.created_at().toISOString());
                }
            }
        }
    }

    checkUnreaded() {
        if (!this.model || !this.model.unreaded()) {
            return;
        }

        const wrapper = this.getChatWrapper();
        const result = processVisibleUnread({
            wrapper,
            model: this.model,
            currentChat: app.chat.getCurrentChat(),
            messages: app.chat.getChatMessages((mdl) => mdl.chat() == this.model && mdl.created_at() >= this.model.readed_at() && !mdl.isReaded),
            autoScroll: !!this.state.scroll.autoScroll,
            apiReadChat: app.chat.apiReadChat.bind(app.chat),
            findMessageEl: (id) => document.querySelector(`.message-wrapper[data-id="${id}"`),
        });

        if (result.processed > 0) {
            m.redraw();
        }
    }

    scrollToAnchor(anchor) {
        let element;
        if (anchor instanceof Message) element = $(`.message-wrapper[data-id="${anchor.id()}"`)[0];
        else element = anchor;

        let chatWrapper = this.getChatWrapper();
        if (chatWrapper && element)
            $(chatWrapper)
                .stop()
                .animate({ scrollTop: element.offsetTop - element.offsetHeight }, 500);
        else setTimeout(scroll, 100);
    }

    scrollToBottom(force = false) {
        this.scrolling = true;
        let chatWrapper = this.getChatWrapper();
        if (chatWrapper) {
            const notAtBottom = !force && this.atBottom();
            const fewMessages = chatWrapper.scrollHeight <= chatWrapper.clientHeight + 200;
            if (notAtBottom || fewMessages) return;

            const time = this.pixelsFromBottom() < 80 ? 0 : 250;

            $(chatWrapper)
                .stop()
                .animate({ scrollTop: chatWrapper.scrollHeight }, time, 'swing', () => {
                    this.state.scroll.autoScroll = false;
                    this.scrolling = false;
                });
        }
    }

    reloadMessages() {
        if (!startInitialHistoryFetch(this.state)) {
            return;
        }

        let query;
        if (this.model.unreaded()) {
            query = this.model.readed_at()?.toISOString() ?? new Date(0).toISOString();
            this.state.scroll.autoScroll = false;
        }

        const pending = app.chat.apiFetchChatMessages(this.model, query);
        if (!pending || typeof pending.then !== 'function') {
            settleInitialHistoryFetch(this.state, { ok: false });
            return;
        }

        pending.then(
            () => {
                settleInitialHistoryFetch(this.state, { ok: true });
                if (this.model.unreaded()) {
                    let anchor = app.chat.getChatMessages((mdl) => mdl.chat() == this.model && mdl.created_at() > this.model.readed_at())[0];
                    this.scrollToAnchor(anchor);
                } else this.state.scroll.autoScroll = true;

                m.redraw();
            },
            () => {
                settleInitialHistoryFetch(this.state, { ok: false });
            }
        );
    }

    nearBottom() {
        return this.pixelsFromBottom() <= 500;
    }

    atBottom() {
        return this.pixelsFromBottom() <= 5;
    }

    pixelsFromBottom() {
        const element = this.getChatWrapper();
        if (!element) return Number.POSITIVE_INFINITY;
        return Math.abs(element.scrollHeight - element.scrollTop - element.clientHeight);
    }
}
