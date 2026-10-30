import app from 'flarum/admin/app';
import LiveChatAdminPage from './components/LiveChatAdminPageF2';
import registerLiveAdmin from './registerLiveAdmin';

app.initializers.add('flatrate-live-chat', () => registerLiveAdmin(app, app.registry, LiveChatAdminPage));
