/**
 * Page-era unread processing for a mounted ChatViewport.
 * Does not consult legacy floating-frame beingShown / chatIsShown().
 */
export function processVisibleUnread({ wrapper, model, currentChat, messages, autoScroll, apiReadChat, findMessageEl }) {
    if (!(wrapper && model && model.unreaded() && currentChat === model)) {
        return { processed: 0, gated: true };
    }

    let processed = 0;

    for (const message of messages) {
        const msg = findMessageEl(message.id());
        if (msg && wrapper.scrollTop + wrapper.clientHeight >= msg.offsetTop) {
            message.isReaded = true;

            if (autoScroll) {
                apiReadChat(model, new Date());
                model.pushAttributes({ unreaded: 0 });
            } else {
                apiReadChat(model, message);
                model.pushAttributes({ unreaded: model.unreaded() - 1 });
            }

            processed += 1;
        }
    }

    return { processed, gated: false };
}
