import app from 'flarum/app';

// Admin-only R1 monitoring panel. Flarum's extension settings remain the
// authoritative place to change the existing General/Admin/Beta/User switches.
// No room writes and no background realtime subscriptions.
const value = (n) => (typeof n === 'number' ? n.toLocaleString() : '—');
const flag = (v) => (v === true ? 'ON' : 'OFF');

const LiveChatMonitoringPanel = {
    oninit() {
        this.loading = false;
        this.data = null;
        this.error = null;
        this.query = '';
        this.selected = '';
        this.generation = 0;
        this.load();
    },

    onremove() {
        // Discard late replies after navigating away from the extension page.
        this.generation += 1;
    },

    async load(roomKey = this.selected) {
        if (this.loading) return;
        const generation = ++this.generation;
        this.loading = true;
        this.error = null;
        try {
            const suffix = roomKey ? `?presence_room=${encodeURIComponent(roomKey)}` : '';
            const payload = await app.request({
                method: 'GET',
                url: `${app.forum.attribute('apiUrl')}/flatrate-live-chat/admin/rooms/dashboard${suffix}`,
            });
            if (generation !== this.generation) return;
            this.data = payload;
        } catch (error) {
            if (generation !== this.generation) return;
            this.error = error?.status === 404
                ? 'Admin monitoring is disabled. Enable the dashboard switch below and save the extension settings.'
                : 'Unable to read Live Chat monitoring data. Confirm administrator access and the forum health.';
        } finally {
            if (generation === this.generation) {
                this.loading = false;
                m.redraw();
            }
        }
    },

    view() {
        const data = this.data;
        const q = this.query.trim().toLowerCase();
        const rooms = (data?.rooms || []).filter((room) => {
            return !q || room.title.toLowerCase().includes(q) || room.roomKey.toLowerCase().includes(q);
        });
        const selected = data?.selectedPresence;
        const card = (label, valueText) => m('div', {
            style: 'border:1px solid #ddd;border-radius:8px;padding:10px 14px;min-width:120px;flex:1',
        }, [
            m('small', { style: 'display:block;color:#656565' }, label),
            m('strong', { style: 'font-size:20px' }, String(valueText)),
        ]);

        return m('section.Form-group', {
            style: 'border-left:4px solid #c72d5d;padding:12px 16px;margin:0 0 25px 0',
        }, [
            m('h3', 'Live Chat Rooms — Administration & Monitoring'),
            m('p.helpText', 'Read-only census of the 46 canonical rooms. Presence is sampled only for the selected room; unknown is never zero. Settings below remain authoritative.'),
            m('button.Button', { type: 'button', disabled: this.loading, onclick: () => this.load() }, this.loading ? 'Refreshing…' : 'Refresh snapshot'),
            this.error ? m('p', { role: 'alert', style: 'margin-top:12px;color:#a32232' }, this.error) : null,
            data ? [
                m('p', { style: 'font-size:12px;margin-top:10px' }, `Snapshot: ${data.sampledAt} • PHP / Flarum-owned • no message bodies collected`),
                m('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin:12px 0' }, [
                    card('Expected rooms', data.expected),
                    card('Present / exact', data.present - data.drifted),
                    card('Missing', data.missing),
                    card('Drifted', data.drifted),
                    card('Human messages / 24h', value(data.messages24h)),
                ]),
                m('p', [
                    m('strong', 'Current rollout: '),
                    `General operational: ${flag(data.rollout?.generalLiveOperational)} · Admin Preview: ${flag(data.rollout?.adminPreview)} · Beta: ${flag(data.rollout?.memberBeta)} · User Live: ${flag(data.rollout?.userLive)}`,
                ]),
                m('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin:12px 0' }, [
                    m('input.FormControl', {
                        type: 'search', placeholder: 'Find brand or room key', 'aria-label': 'Search rooms',
                        value: this.query, oninput: (event) => { this.query = event.target.value; },
                        style: 'max-width:340px',
                    }),
                    m('select.FormControl', {
                        'aria-label': 'Sample room online presence', value: this.selected,
                        onchange: (event) => { this.selected = event.target.value; this.load(this.selected); },
                        style: 'max-width:340px',
                    }, [
                        m('option', { value: '' }, 'Select a room for live presence…'),
                        ...(data.rooms || []).map((r) => m('option', { value: r.roomKey }, r.title)),
                    ]),
                ]),
                selected ? m('p', {
                    role: 'status', style: 'padding:8px;background:#f5f5f5',
                }, `${selected.roomKey}: ${selected.available ? value(selected.liveUserCount) + ' online' : 'presence unavailable'} · sampled ${selected.sampledAt}`) : null,
                m('div', { style: 'overflow-x:auto' }, m('table', {
                    className: 'Table', style: 'width:100%;border-collapse:collapse',
                }, [
                    m('thead', m('tr', ['Room', 'Catalog', 'Visibility', 'Audience', '1h posts', '24h posts', '7d posts', '24h authors', 'Last activity'].map((s) => m('th', { style: 'padding:9px;text-align:left;white-space:nowrap' }, s)))),
                    m('tbody', rooms.map((r) => m('tr', { key: r.roomKey }, [
                        m('td', { style: 'padding:8px;white-space:nowrap' },
                            m('a', { href: `${app.forum.attribute('baseUrl')}/live/${encodeURIComponent(r.roomKey)}`, target: '_blank', rel: 'noopener noreferrer' }, r.title)),
                        m('td', { style: 'padding:8px' }, r.state),
                        m('td', { style: 'padding:8px' }, r.visibility || '—'),
                        m('td', { style: 'padding:8px' }, r.audience || '—'),
                        m('td', { style: 'padding:8px' }, value(r.messages1h)),
                        m('td', { style: 'padding:8px' }, value(r.messages24h)),
                        m('td', { style: 'padding:8px' }, value(r.messages7d)),
                        m('td', { style: 'padding:8px' }, value(r.authors24h)),
                        m('td', { style: 'padding:8px;white-space:nowrap' }, r.lastActivityAt || '—'),
                    ]))),
                ])),
                m('p.helpText', { style: 'margin-top:12px' }, 'Online totals, realtime latency, and historical error trends are not yet instrumented. Room settings changes require the existing administrative controls; dashboard actions cannot alter room permissions.'),
            ] : null,
        ]);
    },
};

export default LiveChatMonitoringPanel;
