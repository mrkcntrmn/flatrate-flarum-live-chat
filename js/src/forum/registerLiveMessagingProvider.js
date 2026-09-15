import LiveConversationView from './components/LiveConversationView';
import MessagesLiveConversationView from './components/MessagesLiveConversationView';
import ChatState from './states/ChatState';
import { createLiveMessagingProvider } from './liveMessagingProvider';

export default function registerLiveMessagingProvider() {
    app.flatRateMessagingSources ??= {};
    app.flatRateMessagingSources.live = createLiveMessagingProvider({
        renderConversation({ key, context }) {
            if (!app.chat) {
                app.chat = new ChatState();
            }
            if (context?.presentationVersion === 2) {
                return m(MessagesLiveConversationView, { roomKey: key });
            }
            return m(LiveConversationView, { roomKey: key, embedded: true, backToLive: false });
        },
    });
}
