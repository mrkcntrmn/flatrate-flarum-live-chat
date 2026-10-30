import app from 'flarum/app';
import LiveChatAdminPage from './components/LiveChatAdminPage';
import registerLiveAdmin from './registerLiveAdmin';

app.initializers.add('flatrate-live-chat', app => registerLiveAdmin(app, app.extensionData, LiveChatAdminPage));
