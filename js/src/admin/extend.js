import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import RegularGroupSetting from './components/RegularGroupSetting';

export default [
  new Extend.Admin()
    .permission(
      () => ({
        icon: 'fas fa-eye',
        label: app.translator.trans('ekumanov-auto-promote.admin.permission.manage_watchlist'),
        permission: 'ekumanov-auto-promote.manage-watchlist',
      }),
      'moderate'
    )
    // `customSetting` callbacks run in the settings page's context, which is
    // what gives us `this.setting(...)` for the bidirectional stream.
    .customSetting(function () {
      return <RegularGroupSetting stream={this.setting('ekumanov-auto-promote.regular_group_id', '')} />;
    }, 30)
    .setting(
      () => ({
        setting: 'ekumanov-auto-promote.required_posts',
        label: app.translator.trans('ekumanov-auto-promote.admin.settings.required_posts_label'),
        help: app.translator.trans('ekumanov-auto-promote.admin.settings.required_posts_help'),
        type: 'number',
        min: 1,
        max: 1000,
      }),
      20
    )
    .setting(
      () => ({
        setting: 'ekumanov-auto-promote.min_account_age_hours',
        label: app.translator.trans('ekumanov-auto-promote.admin.settings.min_account_age_label'),
        help: app.translator.trans('ekumanov-auto-promote.admin.settings.min_account_age_help'),
        type: 'number',
        min: 0,
        max: 8760,
      }),
      10
    ),
];
