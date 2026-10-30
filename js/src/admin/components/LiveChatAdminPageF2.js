import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LiveChatRoomsDashboard from './LiveChatRoomsDashboard';

export default class LiveChatAdminPageF2 extends ExtensionPage {
    content() {
        return (
            <div className="container">
                <LiveChatRoomsDashboard roomPath="/messages/live/" />
            </div>
        );
    }
}
