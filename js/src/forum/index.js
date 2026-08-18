import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
import Badge from 'flarum/common/components/Badge';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';
import extractText from 'flarum/common/utils/extractText';
import WatchUserModal from './components/WatchUserModal';

export { default as WatchUserModal } from './components/WatchUserModal';

app.initializers.add('ekumanov-auto-promote', () => {
  User.prototype.isWatched = Model.attribute('isWatched');
  User.prototype.isRegular = Model.attribute('isRegular');
  User.prototype.watchReason = Model.attribute('watchReason');
  User.prototype.watchedAt = Model.attribute('watchedAt', Model.transformDate);
  User.prototype.watchedByUsername = Model.attribute('watchedByUsername');
  User.prototype.canWatchlist = Model.attribute('canWatchlist');
  User.prototype.canPromote = Model.attribute('canPromote');

  /**
   * The watchlist badge. The server only serializes `isWatched` to staff, so
   * for everyone else — the watched user very much included — this attribute is
   * absent and the badge simply never exists in the payload, let alone the DOM.
   */
  extend(User.prototype, 'badges', function (items) {
    if (!this.isWatched()) return;

    items.add(
      'ekumanov-watched',
      <Badge type="ekumanov-watched" icon="fas fa-eye" color="#B8531B" label={watchTooltip(this)} />,
      20
    );
  });

  extend(UserControls, 'moderationControls', function (items, user) {
    if (!app.forum.attribute('canManageWatchlist')) return;

    const watched = !!user.isWatched();

    if (user.canWatchlist()) {
      if (watched) {
        items.add(
          'ekumanov-unwatch',
          <Button
            icon="fas fa-eye-slash"
            helperText={app.translator.trans('ekumanov-auto-promote.forum.user_controls.unwatch_help')}
            onclick={() => unwatch(user)}
          >
            {app.translator.trans('ekumanov-auto-promote.forum.user_controls.unwatch_button')}
          </Button>,
          -8
        );
      } else {
        items.add(
          'ekumanov-watch',
          <Button
            icon="fas fa-eye"
            helperText={app.translator.trans('ekumanov-auto-promote.forum.user_controls.watch_help')}
            onclick={() => app.modal.show(WatchUserModal, { user })}
          >
            {app.translator.trans('ekumanov-auto-promote.forum.user_controls.watch_button')}
          </Button>,
          -8
        );
      }
    }

    // Offered to anyone not already trusted, watched or not — promoting a
    // watched user is how you say "I looked, they're fine".
    if (user.canPromote() && !user.isRegular()) {
      items.add(
        'ekumanov-promote',
        <Button
          icon="fas fa-user-check"
          helperText={app.translator.trans(
            watched
              ? 'ekumanov-auto-promote.forum.user_controls.promote_watched_help'
              : 'ekumanov-auto-promote.forum.user_controls.promote_help'
          )}
          onclick={() => promote(user)}
        >
          {app.translator.trans('ekumanov-auto-promote.forum.user_controls.promote_button', {
            group:
              app.forum.attribute('autoPromoteGroupName') ||
              app.translator.trans('ekumanov-auto-promote.forum.user_controls.fallback_group_name'),
          })}
        </Button>,
        -9
      );
    }
  });
});

function watchTooltip(user) {
  const by = user.watchedByUsername();
  const at = user.watchedAt();
  const reason = user.watchReason();

  const label = extractText(
    app.translator.trans('ekumanov-auto-promote.forum.badge.tooltip', {
      // Deliberately not named `user`: the translator treats that key as a User
      // model and calls displayName() on it. This is a plain username string.
      moderator: by || extractText(app.translator.trans('ekumanov-auto-promote.forum.badge.unknown_moderator')),
      date: at ? formatDate(at) : '?',
    })
  );

  return reason ? `${label} — ${reason}` : label;
}

/**
 * Deliberately not dayjs: it is not a webpack external, so importing it here
 * would bundle a second copy of the library into this extension's forum.js.
 */
function formatDate(date) {
  try {
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  } catch (e) {
    return date.toISOString().slice(0, 10);
  }
}

function unwatch(user) {
  return user
    .save({ isWatched: false })
    .then(() => m.redraw())
    .catch(() => {});
}

function promote(user) {
  const data = { isRegular: true };

  // Promoting clears the flag; only send it when there is one to clear, so the
  // request stays a no-op for users who were never watched.
  if (user.isWatched()) data.isWatched = false;

  return user
    .save(data)
    .then(() => m.redraw())
    .catch(() => {});
}
