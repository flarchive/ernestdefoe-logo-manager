import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { button, section } from './controls';

declare const m: import('mithril').Static;

const t = (key: string, params?: any) => app.translator.trans(`ernestdefoe-logo-manager.admin.${key}`, params);

export type FaviconSet = { icon: string; svg: string | null; png: string | null; ico: string | null; touch: string | null };

const SETTING = 'ernestdefoe-logo-manager.favicon';

/**
 * The browser-tab icon: the permanent one on its own tab, and a season's own
 * inside that season's editor.
 *
 * Lazy-loaded with the tab or the season editor, so an admin page that never
 * opens either downloads none of it.
 */
export default class FaviconPanel extends Component<{ studio: any; scope: string; rule?: any }> {
  busy = false;
  error: string | null = null;

  /** The permanent set, as saved — only while core's favicon still points at it. */
  baseSet(): FaviconSet | null {
    const settings = app.data.settings as Record<string, string | null>;
    try {
      const set = JSON.parse(settings[SETTING] || 'null');
      return set && set.icon === settings.favicon_path ? set : null;
    } catch {
      return null;
    }
  }

  set(): FaviconSet | null {
    return this.attrs.scope === 'base' ? this.baseSet() : this.attrs.rule?.favicon || null;
  }

  url(file: string | null): string | null {
    return file ? this.attrs.studio.art().assets + file : null;
  }

  view() {
    const slot = this.slot();

    return this.attrs.scope === 'base' ? section('favicon_heading', 'favicon_help', slot) : slot;
  }

  slot() {
    const set = this.set();
    const settings = app.data.settings as Record<string, string | null>;
    // Without a set of ours, the permanent favicon is whatever core has.
    const icon = set ? this.url(set.icon) : this.attrs.scope === 'base' && settings.favicon_path ? this.url(settings.favicon_path) : null;

    return m('.LogoManager-row.LogoManager-favicon', [
      this.attrs.scope === 'base' ? null : [m('label.LogoManager-label', t('season_favicon')), m('.LogoManager-help', t('season_favicon_help'))],
      this.error ? m('.Alert.Alert--error.LogoManager-error', this.error) : null,
      m('.LogoManager-faviconRow', [
        m(
          '.LogoManager-faviconSizes',
          this.busy
            ? m(LoadingIndicator, { size: 'small' })
            : icon
              ? [64, 32, 16].map((size) => m('img', { src: icon, width: size, height: size, alt: '' }))
              : m('span.LogoManager-slotEmpty', t('favicon_none'))
        ),
        m('.LogoManager-slotActions', [
          m('label.Button.Button--primary.LogoManager-file', [
            t(icon ? 'replace' : 'upload'),
            m('input[type=file]', {
              accept: 'image/svg+xml,image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon,.svg,.ico',
              onchange: (e: Event) => this.upload((e.target as HTMLInputElement).files?.[0] || null),
            }),
          ]),
          icon ? button('remove', () => this.remove()) : null,
        ]),
      ]),
      set ? this.formats(set) : null,
    ]);
  }

  /** What was made from the upload, so "SVG with fallbacks" is visible rather than promised. */
  formats(set: FaviconSet) {
    const chips = [
      ['svg', set.svg],
      ['ico', set.ico],
      ['png', set.png],
      ['touch', set.touch],
    ].filter(([, file]) => file);

    return [
      m(
        '.LogoManager-chips',
        chips.map(([name, file]) =>
          m('a.LogoManager-chip', { href: this.url(file as string), target: '_blank', rel: 'noopener' }, t(`favicon_format_${name}`))
        )
      ),
      set.svg && !set.ico ? m('.LogoManager-help.LogoManager-warn', t('favicon_no_fallbacks')) : null,
    ];
  }

  async upload(file: File | null) {
    if (!file) return;

    this.busy = true;
    this.error = null;
    m.redraw();

    const body = new FormData();
    body.append('favicon', file);

    if (file.type === 'image/svg+xml' || /\.svg$/i.test(file.name)) {
      const { rasterise } = await import('./rasterise');
      const png = await rasterise(file, 512, true);
      if (png) body.append('raster', png, 'favicon.png');
    }

    app
      .request<{ set: FaviconSet; url: string }>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/logo-manager/favicons/${this.attrs.scope}`,
        serialize: (raw: any) => raw,
        body,
      })
      .then((response) => this.saved(response.set))
      .catch((e: any) => {
        this.error = e?.response?.errors?.[0]?.detail || String(t('lib.errors.no_file'));
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  remove() {
    app
      .request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/logo-manager/favicons/${this.attrs.scope}` })
      .then(() => this.saved(null))
      .finally(() => m.redraw());
  }

  /** The base set is saved by the server; a season's is saved with the season. */
  saved(set: FaviconSet | null) {
    if (this.attrs.scope === 'base') {
      const settings = app.data.settings as Record<string, string | null>;
      settings[SETTING] = set ? JSON.stringify(set) : null;
      settings.favicon_path = set ? set.icon : null;
      this.attrs.studio.loadReport();
    } else if (this.attrs.rule) {
      this.attrs.rule.favicon = set;
      this.attrs.studio.commit();
    }
  }
}
