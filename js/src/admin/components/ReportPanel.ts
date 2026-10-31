import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';

declare const m: import('mithril').Static;

const t = (key: string, params?: any) => app.translator.trans(`ernestdefoe-logo-manager.admin.${key}`, params);

const ICONS: Record<string, string> = {
  error: 'fas fa-circle-xmark',
  warning: 'fas fa-triangle-exclamation',
  info: 'fas fa-circle-info',
};

/**
 * The health report: broken or missing files, seasons that can never run,
 * and what is about to change. Built by the server each time the studio
 * opens; this only words it.
 */
export default class ReportPanel extends Component<{ studio: any }> {
  making: string | null = null;

  /**
   * The server can't draw an SVG, so the browser draws the one the report
   * sent — the same as at upload — and the server re-encodes the PNG.
   */
  async makeCopy(action: { scope: string; svg: string }) {
    this.making = action.scope;

    try {
      const { rasterise } = await import('./rasterise');
      const png = await rasterise(new File([action.svg], 'logo.svg', { type: 'image/svg+xml' }), 240);

      if (!png) throw new Error('rasterise');

      const body = new FormData();
      body.append('raster', png, 'logo.png');

      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/logo-manager/email-copy/${encodeURIComponent(action.scope)}`,
        serialize: (raw: any) => raw,
        body,
      });

      app.alerts.show({ type: 'success' }, t('report_made_copy'));
    } catch (e: any) {
      if (!e?.response) app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-logo-manager.lib.errors.invalid_image'));
    } finally {
      this.making = null;
      this.attrs.studio.loadReport();
    }
  }

  view() {
    const report = this.attrs.studio.report;

    if (!report) return m(LoadingIndicator);

    return m('.LogoManager-section.LogoManager-report', [
      m('.LogoManager-reportHead', [
        m('h3.LogoManager-sectionTitle', t('report_heading')),
        Button.component({ className: 'Button', icon: 'fas fa-rotate', onclick: () => this.attrs.studio.loadReport() }, t('report_refresh')),
      ]),
      m('.LogoManager-cards', [
        this.card('report_now', report.now ? report.now.name : t('report_permanent'), null),
        this.card(
          'report_next',
          report.next ? report.next.name || t('report_permanent') : t('report_no_change'),
          report.next ? t('report_on', { date: this.date(report.next.date), days: report.next.days }) : null
        ),
        this.card('report_seasons', String(report.seasons), null),
      ]),
      report.items.length
        ? m(
            'ul.LogoManager-reportItems',
            report.items.map((item: any) =>
              m(`li.LogoManager-reportItem.is-${item.level}`, [
                m('i.icon', { className: ICONS[item.level] }),
                m('span', this.message(item)),
                item.action
                  ? Button.component(
                      { className: 'Button Button--primary', loading: this.making === item.action.scope, onclick: () => this.makeCopy(item.action) },
                      t('report_make_copy')
                    )
                  : null,
              ])
            )
          )
        : m('.LogoManager-reportItem.is-ok', [m('i.icon.fas.fa-circle-check'), m('span', t('report_ok'))]),
      m('.LogoManager-help', t('report_checked', { time: new Date(report.checkedAt).toLocaleTimeString() })),
    ]);
  }

  card(label: string, value: any, sub: any) {
    return m('.LogoManager-card', [
      m('.LogoManager-cardLabel', t(label)),
      m('.LogoManager-cardValue', value),
      sub ? m('.LogoManager-help', sub) : null,
    ]);
  }

  message(item: any) {
    const p = item.params || {};

    return t(`report_${item.key}`, {
      ...p,
      date: p.date ? this.date(p.date) : undefined,
      slot: p.slot ? t(`report_slot_${p.slot}`) : undefined,
      fix: p.fix ? t(`report_fix_${p.fix}`) : undefined,
      where: p.season ? t('report_where_season', { season: p.season }) : t('report_where_permanent'),
    });
  }

  date(ymd: string) {
    return new Date(`${ymd}T12:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric' });
  }
}
