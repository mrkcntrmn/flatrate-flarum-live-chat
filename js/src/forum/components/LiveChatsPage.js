import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import Link from 'flarum/components/Link';
import Button from 'flarum/components/Button';
import { displayRoomTitle, formatDirectoryTime, orderLiveDirectoryRooms, roomKeyOf } from '../utils/liveChatPresentation';

export default class LiveChatsPage extends Page {
    oninit(vnode) {
        super.oninit(vnode);
        this.bodyClass = 'App--live-chats';
        this.loading = true;
        this.error = null;
        this.rooms = [];
        this.load();
    }

    load() {
        this.loading = true;
        this.error = null;
        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/flatrate-live-chat/live-chats',
        })
            .then((payload) => {
                const models = app.store.pushPayload(payload);
                this.rooms = Array.isArray(models) ? models : models ? [models] : [];
                this.loading = false;
                m.redraw();
            })
            .catch(() => {
                this.error = true;
                this.loading = false;
                m.redraw();
            });
    }

    view() {
        const rooms = orderLiveDirectoryRooms(this.rooms);

        return (
            <div className="LiveChatsPage container">
                <h1 className="LiveChatsPage-title">{app.translator.trans('flatrate-live-chat.forum.live_chats.title')}</h1>

                {this.loading ? <LoadingIndicator /> : null}
                {this.error ? (
                    <div className="LiveChatsPage-error">
                        <p>{app.translator.trans('flatrate-live-chat.forum.live_chats.error')}</p>
                        <Button className="Button Button--primary" onclick={() => this.load()}>
                            {app.translator.trans('flatrate-live-chat.forum.live_chats.retry')}
                        </Button>
                    </div>
                ) : null}

                {!this.loading && !this.error && rooms.length === 0 ? (
                    <p className="LiveChatsPage-empty">{app.translator.trans('flatrate-live-chat.forum.live_chats.empty')}</p>
                ) : null}

                {!this.loading && !this.error && rooms.length > 0 ? (
                    <ul className="LiveChatsPage-list">{rooms.map((chat) => this.roomCard(chat))}</ul>
                ) : null}
            </div>
        );
    }

    roomCard(chat) {
        const roomKey = roomKeyOf(chat);
        const last = chat.last_message?.();
        const preview = last ? last.message?.() || last.content?.() || '' : app.translator.trans('flatrate-live-chat.forum.chat.list.preview.empty');
        const when = last && last.created_at ? last.created_at() : null;

        return (
            <li className="LiveChatsPage-card" key={chat.id()}>
                <Link href={app.route('flatrate-live-chat.live', { roomKey })} className="LiveChatsPage-cardLink">
                    <div className="LiveChatsPage-primary">
                        <span className="LiveChatsPage-cardTitle">{displayRoomTitle(chat)}</span>
                        {when ? (
                            <time className="LiveChatsPage-time" datetime={when.toISOString?.() || when}>
                                {formatDirectoryTime(when)}
                            </time>
                        ) : null}
                    </div>
                    <div className="LiveChatsPage-secondary">
                        <span className="LiveChatsPage-preview">{preview}</span>
                    </div>
                </Link>
            </li>
        );
    }
}
