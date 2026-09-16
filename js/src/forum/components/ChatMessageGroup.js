import Component from 'flarum/Component';
import avatar from 'flarum/helpers/avatar';
import username from 'flarum/helpers/username';
import fullTime from 'flarum/helpers/fullTime';
import classList from 'flarum/utils/classList';
import humanTime from 'flarum/utils/humanTime';
import extractText from 'flarum/utils/extractText';
import Link from 'flarum/components/Link';

import ChatMessage from './ChatMessage';

/**
 * Messages V2 group shell: owns identity + timestamp once per consecutive run.
 * FORUM-MESSAGING-008UI
 */
export default class ChatMessageGroup extends Component {
  view() {
    const group = this.attrs.group;
    if (!group || !group.messages || !group.messages.length) return null;

    const author = this.authorForPresentation();
    const first = group.timestampModel || group.messages[0];
    const createdAt = typeof first.created_at === 'function' ? first.created_at() : null;
    const nameText = extractText(username(author)) + ':';

    return (
      <div
        className={classList({
          ChatMessageGroup: true,
          'ChatMessageGroup--own': !!group.own,
        })}
        data-author-id={group.authorId || undefined}
      >
        <div className="ChatMessageGroup-header">
          <div className="ChatMessageGroup-identity">
            {group.own ? (
              <a className="ChatMessageGroup-name" onclick={this.insertMention.bind(this)}>
                {nameText}
              </a>
            ) : null}
            {this.avatarNode(author)}
            {!group.own ? (
              <a className="ChatMessageGroup-name" onclick={this.insertMention.bind(this)}>
                {nameText}
              </a>
            ) : null}
          </div>
          {createdAt ? (
            <time className="ChatMessageGroup-time" title={extractText(fullTime(createdAt))} datetime={createdAt.toISOString?.() || undefined}>
              {humanTime(createdAt)}
            </time>
          ) : null}
        </div>
        <div className="ChatMessageGroup-messages">
          {group.messages.map((model) => (
            <ChatMessage
              key={typeof model.id === 'function' ? model.id() : model.id}
              model={model}
              presentationVersion={2}
              grouped={true}
            />
          ))}
        </div>
      </div>
    );
  }

  /**
   * Canonical author for the group header (same contract as ChatMessage).
   */
  authorForPresentation() {
    const group = this.attrs.group;
    const first = group.messages[0];
    const messageAuthor = typeof first.user === 'function' ? first.user() : first.user;

    if (group.own && app.session.user) {
      return app.session.user;
    }

    return messageAuthor;
  }

  insertMention(e) {
    e.preventDefault();
    const first = this.attrs.group.messages[0];
    const viewportState = app.chat.getViewportState(first.chat());
    viewportState.onChatMessageClicked('insertMention', first);
    app.chat.onChatMessageClicked('insertMention', first);
  }

  avatarNode(author) {
    if (author) {
      return (
        <Link className="ChatMessageGroup-avatar" href={app.route.user(author)}>
          <span>{avatar(author, { className: 'avatar' })}</span>
        </Link>
      );
    }

    return (
      <div className="ChatMessageGroup-avatar">
        <span>{avatar(author, { className: 'avatar' })}</span>
      </div>
    );
  }
}
