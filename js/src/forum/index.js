import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
import Badge from 'flarum/common/components/Badge';
import Button from 'flarum/common/components/Button';
import Tooltip from 'flarum/common/components/Tooltip';
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

    const label = watchTooltip(this);

    items.add(
      'ekumanov-watched',
      // Badge builds its own Tooltip when handed a `label`, but that one is
      // rendered next to the trigger and so is clipped by any ancestor with
      // `overflow: hidden` — which is exactly what a post header is, cutting
      // the text off mid-word. Render the badge bare and anchor our own
      // tooltip to <body> so it can escape the clip.
      <Tooltip text={label} container="body">
        <Badge type="ekumanov-watched" icon="fas fa-eye" color="#B8531B" aria-label={label} />
      </Tooltip>,
      20
    );
  });

  extend(UserControls, 'moderationControls', function (items, user) {
    if (!app.forum.attribute('canManageWatchlist')) return;

    const watched = !!user.isWatched();

    // Explanations live in `title` rather than `helperText`: helperText renders
    // a second line inside every menu item, which makes the dropdown far too
    // tall on a phone.
    if (user.canWatchlist()) {
      if (watched) {
        items.add(
          'ekumanov-watch-note',
          <Button
            icon="fas fa-pencil-alt"
            title={extractText(app.translator.trans('ekumanov-auto-promote.forum.user_controls.note_help'))}
            onclick={() => app.modal.show(WatchUserModal, { user, editing: true })}
          >
            {app.translator.trans('ekumanov-auto-promote.forum.user_controls.note_button')}
          </Button>,
          -8
        );

        items.add(
          'ekumanov-unwatch',
          <Button
            icon="fas fa-eye-slash"
            title={extractText(app.translator.trans('ekumanov-auto-promote.forum.user_controls.unwatch_help'))}
            onclick={() => unwatch(user)}
          >
            {app.translator.trans('ekumanov-auto-promote.forum.user_controls.unwatch_button')}
          </Button>,
          -9
        );
      } else {
        items.add(
          'ekumanov-watch',
          <Button
            icon="fas fa-eye"
            title={extractText(app.translator.trans('ekumanov-auto-promote.forum.user_controls.watch_help'))}
            onclick={() => app.modal.show(WatchUserModal, { user })}
          >
            {app.translator.trans('ekumanov-auto-promote.forum.user_controls.watch_button')}
          </Button>,
          -8
        );
      }
    }

    // Offered to anyone not already trusted, watched or not — promoting a
    // watched user is how you say "I looked, they're fine". Staff report as
    // trusted, so this never appears for them.
    if (user.canPromote() && !user.isRegular()) {
      items.add(
        'ekumanov-promote',
        <Button
          icon="fas fa-user-check"
          title={extractText(
            app.translator.trans(
              watched
                ? 'ekumanov-auto-promote.forum.user_controls.promote_watched_help'
                : 'ekumanov-auto-promote.forum.user_controls.promote_help'
            )
          )}
          onclick={() => promote(user)}
        >
          {app.translator.trans('ekumanov-auto-promote.forum.user_controls.promote_button', {
            group:
              app.forum.attribute('autoPromoteGroupName') ||
              app.translator.trans('ekumanov-auto-promote.forum.user_controls.fallback_group_name'),
          })}
        </Button>,
        -10
      );
    }
  });
});

/**
 * Deliberately short. The note can be a couple of sentences, and a tooltip is
 * the wrong place to read those — it only says whether one exists, and the note
 * itself lives in the modal behind "Watchlist note".
 */
function watchTooltip(user) {
  const by = user.watchedByUsername();
  const at = user.watchedAt();

  const key = user.watchReason()
    ? 'ekumanov-auto-promote.forum.badge.tooltip_with_note'
    : 'ekumanov-auto-promote.forum.badge.tooltip';

  return extractText(
    app.translator.trans(key, {
      // Deliberately not named `user`: the translator treats that key as a User
      // model and calls displayName() on it. This is a plain username string.
      moderator: by || extractText(app.translator.trans('ekumanov-auto-promote.forum.badge.unknown_moderator')),
      date: at ? formatDate(at) : '?',
    })
  );
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
