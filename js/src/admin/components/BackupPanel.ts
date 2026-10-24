import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import { section } from './controls';

declare const m: import('mithril').Static;

const t = (key: string, params?: any) => app.translator.trans(`ernestdefoe-logo-manager.admin.${key}`, params);

/**
 * Export the whole setup — settings, seasons and every file they use — as
 * one JSON file, and import one. Made for building a look on a local copy
 * and moving it to the live forum in one step.
 */
export default class BackupPanel extends Component<{ studio: any }> {
  busy = false;
  message: string | null = null;
  error: string | null = null;

  view() {
    return [
      section(
        'backup_export_heading',
        'backup_export_help',
        Button.component(
          { className: 'Button Button--primary', icon: 'fas fa-download', loading: this.busy, onclick: () => this.export() },
          t('backup_export')
        )
      ),
      section(
        'backup_import_heading',
        'backup_import_help',
        this.error ? m('.Alert.Alert--error.LogoManager-error', this.error) : null,
        this.message ? m('.Alert.Alert--success', this.message) : null,
        m('label.Button.LogoManager-file', { className: this.busy ? 'disabled' : '' }, [
          t('backup_import'),
          m('input[type=file]', {
            accept: 'application/json,.json',
            disabled: this.busy,
            onchange: (e: Event) => this.import((e.target as HTMLInputElement).files?.[0] || null),
          }),
        ])
      ),
    ];
  }

  export() {
    this.busy = true;

    app
      .request<any>({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/logo-manager/backup` })
      .then((data) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
        link.download = `logo-manager-${new Date().toISOString().slice(0, 10)}.json`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  async import(file: File | null) {
    if (!file) return;

    this.error = this.message = null;
    let data: any;

    try {
      data = JSON.parse(await file.text());
    } catch {
      this.error = String(t('backup_invalid'));
      m.redraw();
      return;
    }

    if (!confirm(String(t('backup_import_confirm')))) return;

    this.busy = true;
    m.redraw();

    app
      .request<{ files: number }>({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/logo-manager/backup`, body: data })
      .then((response) => {
        this.message = String(t('backup_imported', { count: response.files }));
        // Every setting on this page just changed underneath it.
        setTimeout(() => window.location.reload(), 1200);
      })
      .catch((e: any) => {
        this.error = e?.response?.errors?.[0]?.detail || String(t('backup_invalid'));
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
