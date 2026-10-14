import ExtensionPage from 'flarum/components/ExtensionPage';
import LiveChatRoomsDashboard from './LiveChatRoomsDashboard';

export default class LiveChatAdminPage extends ExtensionPage {
    content() {
        return (
            <div className="container">
                <LiveChatRoomsDashboard />
            </div>
        );
    }
}
