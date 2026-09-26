import { createLiveMainProvider } from './liveMainProvider';

/**
 * Expose app.flatRateLiveMain for Navigation (fail-closed consumers).
 */
export default function registerLiveMainProvider() {
    // Placeholder until mount creates the realtime-backed instance.
    // Navigation fails closed when available() is false / methods missing.
    app.flatRateLiveMain = createLiveMainProvider({
        app,
        // Realtime is attached after ChatState mount; provider resolves lazily.
    });
}

export function startLiveMainProvider() {
    if (!app.flatRateLiveMain) {
        app.flatRateLiveMain = createLiveMainProvider({ app });
    }
    if (typeof app.flatRateLiveMain.start === 'function') {
        return app.flatRateLiveMain.start();
    }
    return Promise.resolve();
}
