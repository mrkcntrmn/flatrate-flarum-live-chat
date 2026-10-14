import app from 'flarum/app';
import Component from 'flarum/Component';
import Button from 'flarum/components/Button';

const REFRESH_MS = 60000;
const RETRY_MS = 120000;

function number(value) {
    const parsed = Number(value == null ? 0 : value);
    return Number.isFinite(parsed) ? parsed.toLocaleString() : String(value);
}

function relativeTime(value) {
    if (!value) return 'No activity';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Unknown' : date.toLocaleString();
}

function gateLabel(value) {
    return value ? 'ON' : 'OFF';
}

export default class LiveChatRoomsDashboard extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.data = null;
        this.loading = true;
        this.error = '';
        this.viewMode = 'overview';
        this.query = '';
        this.sort = 'name';
        this.selectedRoomKey = null;
        this.refreshTimer = null;
    }

    oncreate() {
        this.load();
    }

    onremove() {
        if (this.refreshTimer) window.clearTimeout(this.refreshTimer);
    }

    schedule(delay) {
        if (this.refreshTimer) window.clearTimeout(this.refreshTimer);
        this.refreshTimer = window.setTimeout(() => this.load(this.selectedRoomKey, true), delay);
    }

    load(roomKey = null, quiet = false) {
        if (!quiet) this.loading = true;
        this.error = '';
        const suffix = roomKey ? `?roomKey=${encodeURIComponent(roomKey)}` : '';

        return app
            .request({
                method: 'GET',
                url: `${app.forum.attribute('apiUrl')}/flatrate-live-chat/admin/dashboard${suffix}`,
            })
            .then((data) => {
                this.data = data;
                this.loading = false;
                this.error = '';
                this.schedule(REFRESH_MS);
                m.redraw();
            })
            .catch(() => {
                this.loading = false;
                this.error = 'Live Chat monitoring data could not be loaded.';
                this.schedule(RETRY_MS);
                m.redraw();
            });
    }

    selectRoom(roomKey) {
        this.selectedRoomKey = roomKey;
        this.viewMode = 'detail';
        this.load(roomKey);
    }

    view() {
        return (
            <div className="FlatRateLiveAdminDashboard">
                <div className="FlatRateLiveAdminHeader">
                    <div>
                        <h2>Live Chat Rooms</h2>
                        <p className="helpText">
                            Private, read-only room health and activity monitoring. Message text and participant identities are never shown.
                        </p>
                    </div>
                    <Button className="Button" icon="fas fa-sync-alt" loading={this.loading} onclick={() => this.load(this.selectedRoomKey)}>
                        Refresh
                    </Button>
                </div>

                {this.error ? <div className="Alert Alert--error">{this.error}</div> : null}
                {!this.data ? this.emptyState() : this.dashboard()}
            </div>
        );
    }

    emptyState() {
        return <div className="FlatRateLiveAdminLoading">{this.loading ? 'Loading room telemetry…' : 'No monitoring data is available.'}</div>;
    }

    dashboard() {
        const sampled = this.data.sampledAt ? new Date(this.data.sampledAt).toLocaleString() : 'Unknown';
        return (
            <div>
                <div className={`FlatRateLiveAdminHealth ${this.data.health.status === 'catalog_reconciled' ? 'is-catalog' : 'is-attention'}`}>
                    <strong>{this.data.health.status === 'catalog_reconciled' ? 'Catalog reconciled' : 'Catalog needs attention'}</strong>
                    <span>Catalog integrity only. Presence is on demand. Historical realtime health is not instrumented. Sampled {sampled}.</span>
                </div>
                {this.tabs()}
                {this.viewMode === 'overview' ? this.overview() : null}
                {this.viewMode === 'rooms' ? this.rooms() : null}
                {this.viewMode === 'detail' ? this.detail() : null}
            </div>
        );
    }

    tabs() {
        return (
            <div className="FlatRateLiveAdminTabs" role="tablist" aria-label="Live Chat monitoring views">
                {['overview', 'rooms', 'detail'].map((mode) => (
                    <button
                        type="button"
                        className={this.viewMode === mode ? 'is-active' : ''}
                        role="tab"
                        aria-selected={this.viewMode === mode ? 'true' : 'false'}
                        disabled={mode === 'detail' && !this.selectedRoomKey}
                        onclick={() => (this.viewMode = mode)}
                    >
                        {mode === 'overview' ? 'Overview' : mode === 'rooms' ? 'All Rooms' : 'Room Detail'}
                    </button>
                ))}
            </div>
        );
    }

    overview() {
        const r = this.data.reconciliation;
        const t = this.data.totals;
        return (
            <section className="FlatRateLiveAdminPanel">
                <div className="FlatRateLiveAdminCards">
                    {this.card('Expected rooms', r.catalogCount, 'Canonical catalog')}
                    {this.card('Present rooms', r.existingCanonicalCount, `${r.missingCount} missing`)}
                    {this.card('Drifted rooms', r.driftedCount, `${r.extraCount} extra`)}
                    {this.card('Messages · 24h', t.messages24h, `${t.roomsWithActivity24h} active rooms`)}
                    {this.card('Messages · 1h', t.messages1h, `${t.messages7d} in 7 days`)}
                    {this.card('System events · 24h', t.eventMessages24h, 'Kept separate from member posts')}
                </div>
                <h3>Rollout gates</h3>
                <div className="FlatRateLiveAdminGates">
                    {this.gate('General operational', this.data.gates.generalLiveEnabled)}
                    {this.gate('Admin Preview', this.data.gates.adminPreviewEnabled)}
                    {this.gate('Member Beta', this.data.gates.memberBetaEnabled)}
                    {this.gate('Broad User Live', this.data.gates.userLiveEnabled)}
                </div>
                <div className="FlatRateLiveAdminNotice">
                    Presence is sampled only for the room you open. A provider result of zero is a real count. If presence is unavailable, this
                    dashboard shows Unavailable rather than zero. Historical realtime availability and latency are <strong>not instrumented</strong>.
                </div>
            </section>
        );
    }

    card(label, value, detail) {
        return (
            <div className="FlatRateLiveAdminCard">
                <span>{label}</span>
                <strong>{number(value)}</strong>
                <small>{detail}</small>
            </div>
        );
    }

    gate(label, value) {
        return (
            <div className={value ? 'is-on' : 'is-off'}>
                <span>{label}</span>
                <strong>{gateLabel(value)}</strong>
            </div>
        );
    }

    filteredRooms() {
        const query = this.query.trim().toLowerCase();
        const rooms = (this.data.rooms || []).filter(
            (room) => !query || `${room.name} ${room.roomKey} ${room.scopeKey}`.toLowerCase().includes(query)
        );
        return rooms.sort((a, b) => {
            if (this.sort === 'messages24h') return b.messages24h - a.messages24h || a.name.localeCompare(b.name);
            if (this.sort === 'lastActivity') return String(b.lastActivityAt || '').localeCompare(String(a.lastActivityAt || ''));
            if (this.sort === 'state') return a.catalogState.localeCompare(b.catalogState) || a.name.localeCompare(b.name);
            return a.name.localeCompare(b.name);
        });
    }

    rooms() {
        const rooms = this.filteredRooms();
        return (
            <section className="FlatRateLiveAdminPanel">
                <div className="FlatRateLiveAdminFilters">
                    <input
                        className="FormControl"
                        type="search"
                        value={this.query}
                        placeholder="Search room or key"
                        oninput={(event) => (this.query = event.target.value)}
                    />
                    <select className="FormControl" value={this.sort} onchange={(event) => (this.sort = event.target.value)}>
                        <option value="name">Sort: Room name</option>
                        <option value="messages24h">Sort: 24h messages</option>
                        <option value="lastActivity">Sort: Last activity</option>
                        <option value="state">Sort: Catalog state</option>
                    </select>
                </div>
                <div className="FlatRateLiveAdminTableWrap">
                    <table className="FlatRateLiveAdminTable">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>State</th>
                                <th>Audience</th>
                                <th>Live</th>
                                <th>1h</th>
                                <th>24h</th>
                                <th>7d</th>
                                <th>Posters · 24h</th>
                                <th>Last activity</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rooms.map((room) => (
                                <tr key={room.roomKey}>
                                    <td>
                                        <strong>{room.name}</strong>
                                        <small>{room.roomKey}</small>
                                    </td>
                                    <td>
                                        <span className={`FlatRateLiveAdminBadge is-${room.catalogState}`}>{room.catalogState}</span>
                                    </td>
                                    <td>
                                        {room.visibility} · {room.audience}
                                    </td>
                                    <td>
                                        {room.presence ? (room.presence.available ? number(room.presence.uniqueUsers) : 'Unavailable') : 'On demand'}
                                    </td>
                                    <td>{number(room.messages1h)}</td>
                                    <td>{number(room.messages24h)}</td>
                                    <td>{number(room.messages7d)}</td>
                                    <td>{number(room.uniquePosters24h)}</td>
                                    <td>{relativeTime(room.lastActivityAt)}</td>
                                    <td>
                                        <Button className="Button Button--link" onclick={() => this.selectRoom(room.roomKey)}>
                                            Inspect
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {!rooms.length ? <p className="helpText">No rooms match this search.</p> : null}
            </section>
        );
    }

    selectedRoom() {
        return (this.data.rooms || []).find((room) => room.roomKey === this.selectedRoomKey) || null;
    }

    detail() {
        const room = this.selectedRoom();
        if (!room) return <section className="FlatRateLiveAdminPanel">Choose a room from All Rooms.</section>;
        const presence = room.presence;
        const forumUrl = String(app.forum.attribute('baseUrl') || '').replace(/\/$/, '');
        return (
            <section className="FlatRateLiveAdminPanel FlatRateLiveAdminDetail">
                <div className="FlatRateLiveAdminDetailHeader">
                    <div>
                        <h3>{room.name}</h3>
                        <code>{room.roomKey}</code>
                    </div>
                    <a className="Button" href={`${forumUrl}/live/${encodeURIComponent(room.roomKey)}`} target="_blank" rel="noopener noreferrer">
                        Open room
                    </a>
                </div>
                <div className="FlatRateLiveAdminCards">
                    {this.card(
                        'Live users',
                        presence ? (presence.available ? presence.uniqueUsers : '—') : '—',
                        presence && presence.available ? `Sampled ${relativeTime(presence.sampledAt)}` : 'Provider unavailable or room missing'
                    )}
                    {this.card('Messages · 1h', room.messages1h, `${room.messages24h} in 24 hours`)}
                    {this.card('Messages · 7d', room.messages7d, `${room.uniquePosters24h} unique posters · 24h`)}
                </div>
                <dl className="FlatRateLiveAdminDefinitionList">
                    <div>
                        <dt>Catalog state</dt>
                        <dd>{room.catalogState}</dd>
                    </div>
                    <div>
                        <dt>Scope</dt>
                        <dd>
                            {room.scopeType} · {room.scopeKey}
                        </dd>
                    </div>
                    <div>
                        <dt>Visibility</dt>
                        <dd>{room.visibility}</dd>
                    </div>
                    <div>
                        <dt>Audience</dt>
                        <dd>{room.audience}</dd>
                    </div>
                    <div>
                        <dt>Admin Preview</dt>
                        <dd>{gateLabel(room.effectiveAvailability.adminPreview)}</dd>
                    </div>
                    <div>
                        <dt>Approved Member Beta</dt>
                        <dd>{gateLabel(room.effectiveAvailability.approvedMemberBeta)}</dd>
                    </div>
                    <div>
                        <dt>Broad Member access</dt>
                        <dd>{gateLabel(room.effectiveAvailability.broadMember)}</dd>
                    </div>
                    <div>
                        <dt>Last activity</dt>
                        <dd>{relativeTime(room.lastActivityAt)}</dd>
                    </div>
                </dl>
                <p className="FlatRateLiveAdminPrivacy">
                    Privacy boundary: this view exposes aggregate counts only. It never requests message bodies or participant identities.
                </p>
            </section>
        );
    }
}
