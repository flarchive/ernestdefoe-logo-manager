import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import LogoStudio from './components/LogoStudio';
import { ENABLED, KEY } from './config';

declare const m: import('mithril').Static;

const t = (key: string) => app.translator.trans(`ernestdefoe-logo-manager.admin.${key}`);

/**
 * One master switch, then the studio.
 *
 * Everything else lives in a single JSON document on one setting rather than
 * three dozen separate keys: the studio edits it as a whole, saves it as a
 * whole, and adding a control later does not mean a migration.
 */
export default [
  new Admin()
    .setting(() => ({
      setting: ENABLED,
      type: 'boolean',
      label: t('enabled'),
      help: t('enabled_help'),
    }))
    .customSetting(function (this: { setting: (key: string, fallback?: string) => (value?: string) => string }) {
      return m(LogoStudio, { valueStream: this.setting(KEY, '{}') });
    }),
];
