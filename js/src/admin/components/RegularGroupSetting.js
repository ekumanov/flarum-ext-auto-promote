import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Dropdown of existing groups for the promotion target.
 *
 * A picker rather than a group this extension creates for you: you almost
 * certainly already have the trusted group, it already carries the permissions
 * that make promotion mean something, and inventing a second one alongside it
 * would be worse than useless.
 */
export default class RegularGroupSetting extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.loading = false;

    // Unlike settings and permissions, groups are not part of the admin
    // payload, so they have to be fetched before the dropdown can be built.
    if (!app.store.all('groups').length) {
      this.loading = true;

      app.store
        .find('groups')
        .then(() => {
          this.loading = false;
          m.redraw();
        })
        .catch(() => {
          this.loading = false;
          m.redraw();
        });
    }
  }

  view() {
    const stream = this.attrs.stream;

    const options = {
      '': extractLabel(app.translator.trans('ekumanov-auto-promote.admin.settings.regular_group_none')),
    };

    if (!this.loading) {
      app.store
        .all('groups')
        // Guests and Members are everyone already, and auto-promoting anyone
        // into Administrators is not a mistake worth making available.
        .filter((group) => !['1', '2', '3'].includes(group.id()))
        .sort((a, b) => a.nameSingular().localeCompare(b.nameSingular()))
        .forEach((group) => {
          options[group.id()] = group.nameSingular();
        });
    }

    return (
      <div className="Form-group">
        <label>{app.translator.trans('ekumanov-auto-promote.admin.settings.regular_group_label')}</label>
        <div className="helpText">{app.translator.trans('ekumanov-auto-promote.admin.settings.regular_group_help')}</div>
        {this.loading ? <LoadingIndicator display="inline" /> : <Select value={stream() || ''} options={options} onchange={stream} />}
      </div>
    );
  }
}

function extractLabel(translation) {
  return typeof translation === 'string' ? translation : String(translation);
}
