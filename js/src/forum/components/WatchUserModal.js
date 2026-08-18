import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Form from 'flarum/common/components/Form';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';

/**
 * Adds a user to the watchlist, or — with `editing: true` — reads and edits the
 * note on someone already watched.
 *
 * The note exists because several moderators share the list: three months from
 * now, "why is this account flagged?" needs an answer that is not somebody's
 * memory. It lives here rather than in the badge tooltip because it can run to
 * a couple of sentences, and a tooltip is the wrong shape for that.
 */
export default class WatchUserModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    this.user = this.attrs.user;
    this.editing = !!this.attrs.editing;
    this.reason = Stream(this.user.watchReason() || '');
  }

  className() {
    return 'WatchUserModal Modal--small';
  }

  title() {
    // Pass the model, not a string: the translator turns a `user` parameter
    // into the {username} placeholder via displayName().
    return app.translator.trans(
      this.editing
        ? 'ekumanov-auto-promote.forum.watch_modal.edit_title'
        : 'ekumanov-auto-promote.forum.watch_modal.title',
      { user: this.user }
    );
  }

  content() {
    return (
      <div className="Modal-body">
        <Form>
          {this.editing ? this.attribution() : this.explanation()}

          <div className="Form-group">
            <label>{app.translator.trans('ekumanov-auto-promote.forum.watch_modal.reason_label')}</label>
            <textarea
              className="FormControl"
              bidi={this.reason}
              rows="3"
              maxlength="255"
              placeholder={extractText(
                app.translator.trans('ekumanov-auto-promote.forum.watch_modal.reason_placeholder')
              )}
            />
          </div>

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {app.translator.trans(
                this.editing
                  ? 'ekumanov-auto-promote.forum.watch_modal.save_button'
                  : 'ekumanov-auto-promote.forum.watch_modal.submit_button'
              )}
            </Button>
          </div>
        </Form>
      </div>
    );
  }

  explanation() {
    return (
      <div className="Form-group">
        <p className="helpText">{app.translator.trans('ekumanov-auto-promote.forum.watch_modal.explanation')}</p>
      </div>
    );
  }

  /** Who flagged this account and when — the other half of the audit trail. */
  attribution() {
    const by = this.user.watchedByUsername();
    const at = this.user.watchedAt();

    return (
      <div className="Form-group">
        <p className="helpText">
          {app.translator.trans('ekumanov-auto-promote.forum.watch_modal.added_by', {
            moderator:
              by || extractText(app.translator.trans('ekumanov-auto-promote.forum.badge.unknown_moderator')),
            date: at ? at.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '?',
          })}
        </p>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();

    this.loading = true;

    const reason = this.reason().trim();

    // isWatched stays true when editing; the server keeps the original
    // attribution rather than re-stamping it with whoever edited the note.
    return this.user
      .save({ isWatched: true, watchReason: reason || null })
      .then(() => {
        this.hide();
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
