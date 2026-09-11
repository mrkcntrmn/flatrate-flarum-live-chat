/**
 * Shared HTTP message-fetch lock lifecycle.
 * Locks must release on nonempty success, empty success, async rejection,
 * and synchronous request-factory throw.
 */
export function runChatMessagesFetch({ viewport, query, findMessages, insertMessage, notifyMessage, options = {}, redraw = () => {} }) {
    if (viewport.loading || viewport.loadingQueries[query]) {
        return null;
    }

    viewport.loading = true;
    viewport.loadingQueries[query] = true;

    const unlock = () => {
        viewport.loading = false;
        delete viewport.loadingQueries[query];
        redraw();
    };

    let pending;
    try {
        pending = findMessages();
    } catch (error) {
        unlock();
        return Promise.reject(error);
    }

    return Promise.resolve(pending).then(
        (r) => {
            if (r.length) {
                r.forEach((message) => {
                    if (options.withFlash) {
                        message.isNeedToFlash = true;
                    }
                    insertMessage(message);
                });

                if (options.notify) {
                    notifyMessage(r[0]);
                }
            }

            unlock();
            return r;
        },
        (error) => {
            unlock();
            throw error;
        }
    );
}

export function startInitialHistoryFetch(state) {
    if (state.messagesFetched) {
        return false;
    }
    state.messagesFetched = true;
    return true;
}

export function settleInitialHistoryFetch(state, { ok }) {
    if (!ok) {
        state.messagesFetched = false;
    }
}
