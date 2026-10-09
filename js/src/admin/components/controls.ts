import app from 'flarum/admin/app';
import Switch from 'flarum/common/components/Switch';
import Button from 'flarum/common/components/Button';

declare const m: import('mithril').Static;

const t = (key: string, params?: any) => app.translator.trans(`ernestdefoe-logo-manager.admin.${key}`, params);

/**
 * The small controls the studio is built from.
 *
 * They exist so every row in the panel has the same anatomy — label, control,
 * optional help text, and for anything numeric a live readout of the value.
 * A settings screen where each row was laid out by whoever wrote it is
 * exactly the accumulation that reads as unfinished.
 */

export function section(title: string, help: string | null, ...body: any[]) {
  return m('.LogoManager-section', [m('h3.LogoManager-sectionTitle', t(title)), help ? m('.LogoManager-sectionHelp', t(help)) : null, ...body]);
}

export function slider(label: string, value: number, min: number, max: number, unit: string, onchange: (v: number) => void, help?: string) {
  return m('.LogoManager-row.LogoManager-row--slider', [
    m('.LogoManager-rowHead', [m('label.LogoManager-label', t(label)), m('output.LogoManager-value', `${value}${unit}`)]),
    m('input.LogoManager-slider[type=range]', {
      min,
      max,
      value,
      oninput: (e: Event) => onchange(Number((e.target as HTMLInputElement).value)),
    }),
    help ? m('.LogoManager-help', t(help)) : null,
  ]);
}

export function colour(label: string, value: string, onchange: (v: string) => void) {
  return m('.LogoManager-row.LogoManager-row--inline', [
    m('label.LogoManager-label', t(label)),
    m('.LogoManager-colour', [
      m('input.LogoManager-swatch[type=color]', {
        value,
        oninput: (e: Event) => onchange((e.target as HTMLInputElement).value),
      }),
      m('input.FormControl.LogoManager-hex', {
        value,
        spellcheck: false,
        oninput: (e: Event) => {
          const next = (e.target as HTMLInputElement).value.trim();
          if (/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(next)) onchange(next);
        },
      }),
    ]),
  ]);
}

export function toggle(label: string, checked: boolean, onchange: (v: boolean) => void, help?: string) {
  return m('.LogoManager-row', [Switch.component({ state: checked, onchange }, t(label)), help ? m('.LogoManager-help', t(help)) : null]);
}

export function select(label: string | null, value: string, options: { value: string; label: any }[], onchange: (v: string) => void, help?: string) {
  return m('.LogoManager-row', [
    label ? m('label.LogoManager-label', t(label)) : null,
    m(
      'select.FormControl.LogoManager-select',
      { value, onchange: (e: Event) => onchange((e.target as HTMLSelectElement).value) },
      options.map((o) => m('option', { value: o.value, selected: o.value === value }, o.label))
    ),
    help ? m('.LogoManager-help', t(help)) : null,
  ]);
}

/** A segmented control — clearer than a two-option dropdown. */
export function segmented(label: string, value: string, options: { value: string; label: any }[], onchange: (v: string) => void, help?: string) {
  return m('.LogoManager-row', [
    m('label.LogoManager-label', t(label)),
    m(
      '.LogoManager-segmented',
      options.map((o) =>
        m(
          'button.LogoManager-segment',
          { className: o.value === value ? 'is-active' : '', type: 'button', onclick: () => onchange(o.value) },
          o.label
        )
      )
    ),
    help ? m('.LogoManager-help', t(help)) : null,
  ]);
}

/**
 * A grid of artwork tiles.
 *
 * Picking a snowflake from a dropdown that says "snow-cap" is guessing; the
 * ornament is the only description of itself that matters, so it is shown.
 */
export function artGrid(value: string | null, names: string[], art: Record<string, string>, labelKey: string, onchange: (v: string | null) => void) {
  return m('.LogoManager-artGrid', [
    m(
      'button.LogoManager-art.LogoManager-art--none',
      { className: !value ? 'is-active' : '', type: 'button', onclick: () => onchange(null) },
      t('season_none')
    ),
    ...names.map((name) =>
      m(
        'button.LogoManager-art',
        {
          className: value === name ? 'is-active' : '',
          type: 'button',
          title: String(t(`${labelKey}_${name}`)),
          onclick: () => onchange(name),
        },
        [
          m('.LogoManager-artImage', art[name] ? { style: { backgroundImage: `url("${art[name]}")` } } : {}),
          m('.LogoManager-artLabel', t(`${labelKey}_${name}`)),
        ]
      )
    ),
  ]);
}

export function button(label: string, onclick: () => void, options: any = {}) {
  return Button.component({ className: 'Button', onclick, ...options }, t(label));
}
