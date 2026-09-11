import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import Link from 'flarum/components/Link';
import Button from 'flarum/components/Button';

function displayTitle(chat) {
    const key = chat.room_key?.() || chat.roomKey?.();
    if (key === 'community-general-live') {
        return app.translator.trans('flatrate-live-chat.forum.live_chats.general_label');
    }
    return chat.title();
}

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
        const general = this.rooms.find((c) => (c.room_key?.() || c.roomKey?.()) === 'community-general-live');
        const others = this.rooms.filter((c) => (c.room_key?.() || c.roomKey?.()) !== 'community-general-live');

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

                {!this.loading && !this.error ? (
                    <div>
                        <section className="LiveChatsPage-section">
                            <h2>{app.translator.trans('flatrate-live-chat.forum.live_chats.general_heading')}</h2>
                            {general ? (
                                <ul className="LiveChatsPage-list">{this.roomCard(general)}</ul>
                            ) : (
                                <p className="LiveChatsPage-empty">
                                    {app.translator.trans('flatrate-live-chat.forum.live_chats.general_unavailable')}
                                </p>
                            )}
                        </section>

                        <section className="LiveChatsPage-section">
                            <h2>{app.translator.trans('flatrate-live-chat.forum.live_chats.subscriptions_heading')}</h2>
                            {others.length === 0 ? (
                                <p className="LiveChatsPage-empty">{app.translator.trans('flatrate-live-chat.forum.live_chats.no_subscriptions')}</p>
                            ) : (
                                <ul className="LiveChatsPage-list">{others.map((chat) => this.roomCard(chat))}</ul>
                            )}
                        </section>
                    </div>
                ) : null}
            </div>
        );
    }

    roomCard(chat) {
        const roomKey = chat.room_key?.() || chat.roomKey?.();
        const unread = chat.unreaded?.() || 0;
        const last = chat.last_message?.();
        const preview = last ? last.message?.() || last.content?.() || '' : app.translator.trans('flatrate-live-chat.forum.chat.list.preview.empty');
        const when = last && last.created_at ? last.created_at() : null;

        return (
            <li className="LiveChatsPage-card" key={chat.id()}>
                <Link href={app.route('flatrate-live-chat.live', { roomKey })} className="LiveChatsPage-cardLink">
                    <div className="LiveChatsPage-cardTitle">
                        <span>{displayTitle(chat)}</span>
                        {unread ? <span className="LiveChatsPage-unread">{unread}</span> : null}
                    </div>
                    <div className="LiveChatsPage-cardMeta">
                        <span className="LiveChatsPage-preview">{preview}</span>
                        {when ? <time datetime={when.toISOString?.() || when}>{when.toLocaleString?.() || String(when)}</time> : null}
                    </div>
                    {chat.scope_key?.() ? <div className="LiveChatsPage-scope">{chat.scope_key()}</div> : null}
                </Link>
            </li>
        );
    }
}
