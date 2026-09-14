export function messagingUiEnabled() {
    return !!app.forum?.attribute?.('flatrateMessagingUiEnabled');
}

export function liveIndexRedirectHref() {
    if (typeof app !== 'undefined' && app.routes && app.routes['flatrate-messaging.index'] && typeof app.route === 'function') {
        const base = app.route('flatrate-messaging.index');
        return `${base}${base.includes('?') ? '&' : '?'}filter=live`;
    }
    return '/messages?filter=live';
}

export function liveRoomRedirectHref(roomKey) {
    return '/messages/live/' + encodeURIComponent(roomKey || '');
}
