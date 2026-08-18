import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Form from 'flarum/common/components/Form';
import Stream from 'flarum/common/utils/Stream';

/**
 * Asks for an optional note before putting a user on the watchlist.
 *
 * The note exists because several moderators share the list: three months from
 * now, "why is this account flagged?" needs an answer that is not somebody's
 * memory.
 */
export default class WatchUserModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    this.user = this.attrs.user;
    this.reason = Stream(this.user.watchReason() || '');
  }

  className() {
    return 'WatchUserModal Modal--small';
  }

  title() {
    // Pass the model, not a string: the translator turns a `user` parameter
    // into the {username} placeholder via displayName().
    return app.translator.trans('ekumanov-auto-promote.forum.watch_modal.title', {
      user: this.user,
    });
  }

  content() {
    return (
      <div className="Modal-body">
        <Form>
          <div className="Form-group">
            <p className="helpText">{app.translator.trans('ekumanov-auto-promote.forum.watch_modal.explanation')}</p>
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ekumanov-auto-promote.forum.watch_modal.reason_label')}</label>
            <textarea
              className="FormControl"
              bidi={this.reason}
              rows="3"
              maxlength="255"
              placeholder={app.translator.trans('ekumanov-auto-promote.forum.watch_modal.reason_placeholder')}
            />
          </div>

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {app.translator.trans('ekumanov-auto-promote.forum.watch_modal.submit_button')}
            </Button>
          </div>
        </Form>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();

    this.loading = true;

    const reason = this.reason().trim();

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
