import LiveConversationView from './components/LiveConversationView';
import ChatState from './states/ChatState';
import { createLiveMessagingProvider } from './liveMessagingProvider';

export default function registerLiveMessagingProvider() {
    app.flatRateMessagingSources ??= {};
    app.flatRateMessagingSources.live = createLiveMessagingProvider({
        renderConversation({ key }) {
            if (!app.chat) {
                app.chat = new ChatState();
            }
            return m(LiveConversationView, { roomKey: key, embedded: true, backToLive: false });
        },
    });
}
