import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import {
  Cfg,
  CORNERS,
  DECORATIONS,
  EFFECT_ORDER,
  EFFECT_PRESETS,
  EFFECT_RANGE,
  Effects,
  FEASTS,
  filterChain,
  headerHeight,
  HOVERS,
  normalise,
  NEUTRAL,
  SeasonRule,
  seasonPresets,
  WEATHER,
} from '../config';
import { artGrid, button, colour, section, segmented, select, slider, toggle } from './controls';

declare const m: import('mithril').Static;

const t = (key: string, params?: any) => app.translator.trans(`ernestdefoe-logo-manager.admin.${key}`, params);

type Art = {
  decorations: Record<string, string>;
  weather: Record<string, [string, number, number, number][]>;
  assets: string;
  activeSeason: string | null;
};

const TABS = ['logo', 'size', 'effects', 'seasons'] as const;

/**
 * The Logo Studio.
 *
 * One screen, four tabs, and a preview that is always on show above them —
 * because every control here changes how one thing looks, and a slider whose
 * result you cannot see is a slider you set by trial and error.
 */
export default class LogoStudio extends Component<{ valueStream: (v?: string) => string }> {
  value!: (v?: string) => string;
  cfg!: Cfg;
  tab: string = 'logo';
  dark = false;
  uploading: string | null = null;
  error: string | null = null;
  dragging: number | null = null;

  /** Logo URLs held locally so an upload shows immediately, before a save. */
  urls: Record<string, string | null> = {};

  oninit(vnode: any) {
    super.oninit(vnode);
    this.value = this.attrs.valueStream;

    let parsed: any = {};
    try {
      parsed = JSON.parse(this.value() || '{}');
    } catch {
      parsed = {};
    }

    this.cfg = normalise(parsed);
    this.urls['base.light'] = (app.forum.attribute('logoUrl') as string) || null;
    this.urls['base.dark'] = (app.forum.attribute('logoDarkModeUrl') as string) || null;
  }

  art(): Art {
    return ((app.data as any).logoManagerArt || { decorations: {}, weather: {}, assets: '', activeSeason: null }) as Art;
  }

  /** Persist to the bound stream; the page's own Save button writes it. */
  commit() {
    this.value(JSON.stringify(this.cfg));
  }

  set(path: string, value: any) {
    const keys = path.split('.');
    let node: any = this.cfg;
    while (keys.length > 1) node = node[keys.shift() as string];
    node[keys[0]] = value;
    this.commit();
  }

  view() {
    return m('.LogoManager', [
      this.error ? m('.LogoManager-error.Alert.Alert--error', this.error) : null,
      this.preview(),
      m(
        '.LogoManager-tabs',
        TABS.map((tab) =>
          m(
            'button.LogoManager-tab',
            { className: this.tab === tab ? 'is-active' : '', type: 'button', onclick: () => (this.tab = tab) },
            t(`tab_${tab}`)
          )
        )
      ),
      m('.LogoManager-panel', this.panel()),
    ]);
  }

  panel() {
    switch (this.tab) {
      case 'size':
        return this.sizePanel();
      case 'effects':
        return this.effectsPanel();
      case 'seasons':
        return this.seasonsPanel();
      default:
        return this.logoPanel();
    }
  }

  // ---------------------------------------------------------------- preview

  /**
   * A mock header, not the real one.
   *
   * The real header is right there at the top of the admin page and is styled
   * by the extension already — but it shows the *saved* configuration, and a
   * preview that only updates after saving is not a preview. This one is
   * driven from the unsaved values, and carries the same light/dark switch so
   * a dark-mode logo can be checked without changing your own theme.
   */
  preview() {
    const cfg = this.cfg;
    const season = this.previewSeason();
    const effects: Effects = { ...cfg.effects, ...((season?.effects || {}) as Partial<Effects>) };
    const height = headerHeight(cfg);
    const logo = this.previewLogoUrl(season);
    const art = this.art();

    const decoration = season?.decoration ? art.decorations[season.decoration] : null;
    const decorationSize = Math.round(cfg.size.height * ((season?.decorationScale ?? 55) / 100));
    const decorationOffset = Math.round(decorationSize * 0.3);
    const [vertical, horizontal] = (season?.corner || 'top-right').split('-');

    const weatherLayers = season?.weather ? (art.weather[season.weather] || []).slice(0, season.weatherDensity ?? 2) : [];
    const recolor = (season?.recolor as any) || cfg.recolor;

    return m('.LogoManager-preview', [
      m('.LogoManager-previewBar', [
        m('span.LogoManager-previewLabel', t('preview')),
        m('.LogoManager-segmented.LogoManager-segmented--small', [
          m(
            'button.LogoManager-segment',
            { className: !this.dark ? 'is-active' : '', type: 'button', onclick: () => (this.dark = false) },
            t('preview_light')
          ),
          m(
            'button.LogoManager-segment',
            { className: this.dark ? 'is-active' : '', type: 'button', onclick: () => (this.dark = true) },
            t('preview_dark')
          ),
        ]),
      ]),
      m(
        '.LogoManager-previewStage',
        { className: this.dark ? 'is-dark' : '' },
        m('.LogoManager-mockHeader', { style: { height: `${height}px` } }, [
          weatherLayers.length
            ? m('.LogoManager-mockWeather', {
                style: {
                  backgroundImage: weatherLayers.map((l) => `url("${l[0]}")`).join(','),
                  backgroundSize: weatherLayers.map((l) => `${l[1]}px ${l[1]}px`).join(','),
                },
              })
            : null,
          m('.LogoManager-mockRow', { className: cfg.size.align === 'center' ? 'is-centred' : '' }, [
            m('.LogoManager-mockNav', [m('span'), m('span'), m('span')]),
            m(
              '.LogoManager-mockLogo',
              {
                style: cfg.plate.enabled
                  ? { background: cfg.plate.color, padding: `${cfg.plate.padding}px`, borderRadius: `${cfg.plate.radius}px` }
                  : {},
              },
              [
                logo
                  ? m('img', {
                      src: logo,
                      alt: '',
                      style: {
                        height: `${cfg.size.height}px`,
                        width: 'auto',
                        display: 'block',
                        visibility: recolor.enabled ? 'hidden' : 'visible',
                        filter: filterChain(effects, cfg.shadow, 'lm-preview-pixelate'),
                      },
                    })
                  : m('span.LogoManager-mockTitle', app.forum.attribute('title') as string),
                recolor.enabled && logo
                  ? m('.LogoManager-mockRecolor', {
                      style: {
                        backgroundColor: recolor.color,
                        maskImage: `url("${logo}")`,
                        WebkitMaskImage: `url("${logo}")`,
                      },
                    })
                  : null,
                decoration
                  ? m('.LogoManager-mockDecoration', {
                      style: {
                        width: `${decorationSize}px`,
                        height: `${decorationSize}px`,
                        [vertical]: `-${decorationOffset}px`,
                        [horizontal]: `-${decorationOffset}px`,
                        backgroundImage: `url("${decoration}")`,
                      },
                    })
                  : null,
              ]
            ),
            m('.LogoManager-mockControls', [m('span'), m('span.is-round')]),
          ]),
        ])
      ),
      m('.LogoManager-previewNote', t('preview_note')),
      this.pixelateFilter(effects.pixelate),
    ]);
  }

  /**
   * The pixelate filter for the preview.
   *
   * The same primitives the server injects: flood a dot per block, tile it,
   * sample the source through it, then dilate each sample to fill its block.
   * It has to live in this document — a `filter: url()` at a data URI does
   * nothing in Safari.
   */
  pixelateFilter(block: number) {
    if (!block) return null;
    const half = Math.max(1, Math.round(block / 2));

    return m(
      'svg.LogoManager-filters',
      { width: 0, height: 0, 'aria-hidden': 'true', focusable: 'false' },
      m('filter', { id: 'lm-preview-pixelate', x: 0, y: 0, width: '100%', height: '100%', 'color-interpolation-filters': 'sRGB' }, [
        m('feFlood', { x: half, y: half, width: 2, height: 2 }),
        m('feComposite', { width: block, height: block }),
        m('feTile', { result: 'tiles' }),
        m('feComposite', { in: 'SourceGraphic', in2: 'tiles', operator: 'in' }),
        m('feMorphology', { operator: 'dilate', radius: half }),
      ])
    );
  }

  /** While the Seasons tab is open, preview the season being edited. */
  previewSeason(): SeasonRule | null {
    if (this.tab !== 'seasons') return null;
    return this.editing !== null ? this.cfg.seasons.rules[this.editing] || null : null;
  }

  previewLogoUrl(season: SeasonRule | null): string | null {
    const variant = this.dark ? 'dark' : 'light';

    if (season) {
      const file = season.logo?.[variant] || season.logo?.light;
      if (file) return this.art().assets + file;
    }

    return this.urls[`base.${variant}`] || this.urls['base.light'] || null;
  }

  // ------------------------------------------------------------- logo panel

  logoPanel() {
    return section('logo_heading', 'logo_help', m('.LogoManager-uploads', [this.uploadSlot('base', 'light'), this.uploadSlot('base', 'dark')]));
  }

  uploadSlot(scope: string, variant: 'light' | 'dark') {
    const key = `${scope}.${variant}`;
    const url = this.urls[key];
    const busy = this.uploading === key;

    return m('.LogoManager-slot', { className: variant === 'dark' ? 'is-dark' : '' }, [
      m('label.LogoManager-label', t(variant === 'dark' ? 'logo_dark' : 'logo_light')),
      m(
        '.LogoManager-slotPreview',
        busy ? m(LoadingIndicator, { size: 'small' }) : url ? m('img', { src: url, alt: '' }) : m('span.LogoManager-slotEmpty', t('no_logo'))
      ),
      m('.LogoManager-slotActions', [
        m('label.Button.Button--primary.LogoManager-file', [
          t(url ? 'replace' : 'upload'),
          m('input[type=file]', {
            accept: 'image/svg+xml,image/png,image/jpeg,image/webp,image/gif,.svg',
            onchange: (e: Event) => this.upload(scope, variant, (e.target as HTMLInputElement).files?.[0] || null),
          }),
        ]),
        url ? button('remove', () => this.remove(scope, variant)) : null,
      ]),
      variant === 'dark' ? m('.LogoManager-help', t('logo_dark_help')) : null,
    ]);
  }

  upload(scope: string, variant: 'light' | 'dark', file: File | null) {
    if (!file) return;

    const key = `${scope}.${variant}`;
    const body = new FormData();
    body.append('logo', file);

    this.uploading = key;
    this.error = null;

    app
      .request<{ filename: string; url: string }>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/logo-manager/logos/${scope}/${variant}`,
        serialize: (raw: any) => raw,
        body,
      })
      .then((response) => {
        this.uploading = null;
        // Cache-bust: the filename changes on every upload, but a replaced
        // logo at a URL the browser has already seen would otherwise keep
        // showing the old one in this preview.
        this.urls[key] = `${response.url}?t=${Date.now()}`;

        if (scope !== 'base') {
          const rule = this.cfg.seasons.rules.find((r) => r.id === scope);
          if (rule) {
            rule.logo = { ...(rule.logo || {}), [variant]: response.filename };
            this.commit();
          }
        }

        m.redraw();
      })
      .catch((e: any) => {
        this.uploading = null;
        this.error = e?.response?.errors?.[0]?.detail || String(t('lib.errors.no_file'));
        m.redraw();
      });
  }

  remove(scope: string, variant: 'light' | 'dark') {
    app
      .request({
        method: 'DELETE',
        url: `${app.forum.attribute('apiUrl')}/logo-manager/logos/${scope}/${variant}`,
      })
      .then(() => {
        this.urls[`${scope}.${variant}`] = null;

        if (scope !== 'base') {
          const rule = this.cfg.seasons.rules.find((r) => r.id === scope);
          if (rule) {
            rule.logo = { ...(rule.logo || {}), [variant]: null };
            this.commit();
          }
        }

        m.redraw();
      });
  }

  // ------------------------------------------------------------- size panel

  sizePanel() {
    const cfg = this.cfg;
    const auto = cfg.size.headerHeight === null || cfg.size.headerHeight === undefined;

    return [
      section(
        'size_heading',
        null,
        slider('height', cfg.size.height, 12, 300, 'px', (v) => this.set('size.height', v), 'height_help'),
        slider('height_phone', cfg.size.heightPhone, 12, 200, 'px', (v) => this.set('size.heightPhone', v), 'height_phone_help'),
        segmented(
          'header_height',
          auto ? 'auto' : 'manual',
          [
            { value: 'auto', label: t('header_height_auto') },
            { value: 'manual', label: t('header_height_manual') },
          ],
          (v) => this.set('size.headerHeight', v === 'auto' ? null : headerHeight(cfg)),
          'header_height_help'
        ),
        auto ? null : slider('header_height', cfg.size.headerHeight as number, 30, 400, 'px', (v) => this.set('size.headerHeight', v)),
        segmented(
          'align',
          cfg.size.align,
          [
            { value: 'left', label: t('align_left') },
            { value: 'center', label: t('align_center') },
          ],
          (v) => this.set('size.align', v),
          'align_help'
        )
      ),
      section(
        'plate_heading',
        'plate_help',
        toggle('plate_enabled', cfg.plate.enabled, (v) => this.set('plate.enabled', v)),
        cfg.plate.enabled ? colour('plate_color', cfg.plate.color, (v) => this.set('plate.color', v)) : null,
        cfg.plate.enabled ? slider('plate_padding', cfg.plate.padding, 0, 60, 'px', (v) => this.set('plate.padding', v)) : null,
        cfg.plate.enabled ? slider('plate_radius', cfg.plate.radius, 0, 100, 'px', (v) => this.set('plate.radius', v)) : null
      ),
    ];
  }

  // ---------------------------------------------------------- effects panel

  effectsPanel() {
    const cfg = this.cfg;

    return [
      section(
        'effects_heading',
        'effects_help',
        m(
          '.LogoManager-presets',
          Object.keys(EFFECT_PRESETS).map((name) =>
            m(
              'button.LogoManager-preset',
              {
                type: 'button',
                onclick: () => {
                  this.cfg.effects = { ...NEUTRAL, ...EFFECT_PRESETS[name] };
                  if (name === 'soft') this.cfg.shadow = { ...this.cfg.shadow, enabled: true, blur: 10, y: 3 };
                  if (name === 'glow') this.cfg.shadow = { enabled: true, color: '#5b8ff9', x: 0, y: 0, blur: 14 };
                  this.commit();
                },
              },
              t(`preset_${name}`)
            )
          )
        ),
        ...EFFECT_ORDER.map((name) => {
          const [min, max, unit] = EFFECT_RANGE[name];
          return slider(
            `effect_${name}`,
            cfg.effects[name],
            min,
            max,
            unit,
            (v) => this.set(`effects.${name}`, v),
            name === 'pixelate' ? 'effect_pixelate_help' : undefined
          );
        })
      ),
      section(
        'recolor_heading',
        'recolor_help',
        toggle('recolor_enabled', cfg.recolor.enabled, (v) => this.set('recolor.enabled', v)),
        cfg.recolor.enabled ? colour('recolor_color', cfg.recolor.color, (v) => this.set('recolor.color', v)) : null
      ),
      section(
        'shadow_heading',
        null,
        toggle('shadow_enabled', cfg.shadow.enabled, (v) => this.set('shadow.enabled', v)),
        cfg.shadow.enabled ? colour('shadow_color', cfg.shadow.color, (v) => this.set('shadow.color', v)) : null,
        cfg.shadow.enabled ? slider('shadow_x', cfg.shadow.x, -40, 40, 'px', (v) => this.set('shadow.x', v)) : null,
        cfg.shadow.enabled ? slider('shadow_y', cfg.shadow.y, -40, 40, 'px', (v) => this.set('shadow.y', v)) : null,
        cfg.shadow.enabled ? slider('shadow_blur', cfg.shadow.blur, 0, 60, 'px', (v) => this.set('shadow.blur', v)) : null
      ),
      section(
        'hover_heading',
        null,
        // The section is already titled "On hover"; labelling the control the
        // same thing again just says it twice.
        select(
          null,
          cfg.hover,
          HOVERS.map((h) => ({ value: h, label: t(`hover_${h}`) })),
          (v) => this.set('hover', v)
        )
      ),
    ];
  }

  // ---------------------------------------------------------- seasons panel

  editing: number | null = null;

  seasonsPanel() {
    const rules = this.cfg.seasons.rules;

    return [
      section(
        'seasons_heading',
        'seasons_help',
        toggle('seasons_enabled', this.cfg.seasons.enabled, (v) => this.set('seasons.enabled', v)),
        select(
          'seasons_timezone',
          this.cfg.seasons.timezone || '',
          // A dash for the default read as an empty, broken-looking box. It
          // is also the option most people will leave selected, so it says
          // what it does. `supportedValuesOf` is guarded because spreading
          // `undefined` into an array literal throws rather than yielding
          // nothing.
          [
            { value: '', label: t('seasons_timezone_default') },
            ...(((Intl as any).supportedValuesOf?.('timeZone') as string[] | undefined) ?? []).map((z) => ({ value: z, label: z })),
          ],
          (v) => this.set('seasons.timezone', v),
          'seasons_timezone_help'
        ),
        rules.length
          ? m(
              '.LogoManager-seasonList',
              rules.map((rule, index) => this.seasonRow(rule, index))
            )
          : m('.LogoManager-empty', t('season_empty')),
        m('.LogoManager-help', t('season_presets')),
        m(
          '.LogoManager-presets',
          seasonPresets().map((preset) =>
            m(
              'button.LogoManager-preset',
              {
                type: 'button',
                disabled: rules.some((r) => r.id === preset.id),
                onclick: () => {
                  this.cfg.seasons.rules = [...rules, { ...preset }];
                  this.editing = this.cfg.seasons.rules.length - 1;
                  this.commit();
                },
              },
              preset.name
            )
          )
        )
      ),
      this.editing !== null && rules[this.editing] ? this.seasonEditor(rules[this.editing], this.editing) : null,
    ];
  }

  /**
   * 🚨 Reordering is drag-and-drop, never a pair of arrows.
   *
   * Order is meaningful here — the first matching rule wins — so a list of a
   * dozen seasons genuinely gets rearranged, and nudging one from the bottom
   * to the top an arrow at a time is the kind of thing that makes software
   * feel a decade old.
   */
  seasonRow(rule: SeasonRule, index: number) {
    const active = this.art().activeSeason === rule.id;

    return m(
      '.LogoManager-season',
      {
        key: rule.id,
        className: [this.editing === index ? 'is-editing' : '', this.dragging === index ? 'is-dragging' : ''].join(' '),
        draggable: true,
        ondragstart: (e: DragEvent) => {
          this.dragging = index;
          e.dataTransfer?.setData('text/plain', String(index));
        },
        ondragover: (e: DragEvent) => {
          e.preventDefault();
          if (this.dragging === null || this.dragging === index) return;
          const rules = [...this.cfg.seasons.rules];
          const [moved] = rules.splice(this.dragging, 1);
          rules.splice(index, 0, moved);
          this.cfg.seasons.rules = rules;
          if (this.editing === this.dragging) this.editing = index;
          this.dragging = index;
          this.commit();
        },
        ondragend: () => {
          this.dragging = null;
          m.redraw();
        },
      },
      [
        m('span.LogoManager-grip', { title: String(t('season_drag')) }, m('i.fas.fa-grip-vertical')),
        m('button.LogoManager-seasonName', { type: 'button', onclick: () => (this.editing = this.editing === index ? null : index) }, [
          rule.decoration && this.art().decorations[rule.decoration]
            ? m('.LogoManager-seasonIcon', { style: { backgroundImage: `url("${this.art().decorations[rule.decoration]}")` } })
            : null,
          m('span', rule.name || rule.id),
          active ? m('span.LogoManager-badge', t('season_active_now')) : null,
        ]),
        m('span.LogoManager-seasonWhen', this.describe(rule)),
        m(
          'button.LogoManager-iconButton',
          { type: 'button', title: String(t('season_delete')), onclick: () => this.deleteSeason(index) },
          m('i.fas.fa-trash')
        ),
      ]
    );
  }

  describe(rule: SeasonRule): string {
    if (rule.when.type === 'feast') {
      return String(t(`feast_${rule.when.feast}`));
    }
    return `${rule.when.from ?? '—'} → ${rule.when.to ?? '—'}`;
  }

  deleteSeason(index: number) {
    const rules = [...this.cfg.seasons.rules];
    rules.splice(index, 1);
    this.cfg.seasons.rules = rules;
    if (this.editing === index) this.editing = null;
    else if (this.editing !== null && this.editing > index) this.editing -= 1;
    this.commit();
  }

  seasonEditor(rule: SeasonRule, index: number) {
    const art = this.art();
    const set = (field: string, value: any) => {
      (rule as any)[field] = value;
      this.commit();
    };
    const setWhen = (field: string, value: any) => {
      rule.when = { ...rule.when, [field]: value };
      this.commit();
    };

    return m('.LogoManager-editor', [
      m('.LogoManager-row.LogoManager-row--inline', [
        m('label.LogoManager-label', t('season_name')),
        m('input.FormControl', { value: rule.name, oninput: (e: Event) => set('name', (e.target as HTMLInputElement).value) }),
      ]),
      toggle('season_enabled', rule.enabled !== false, (v) => set('enabled', v)),
      segmented(
        'season_when',
        rule.when.type,
        [
          { value: 'range', label: t('season_when_range') },
          { value: 'feast', label: t('season_when_feast') },
        ],
        (v) => setWhen('type', v)
      ),
      rule.when.type === 'range'
        ? m('.LogoManager-dates', [
            m('.LogoManager-row.LogoManager-row--inline', [
              m('label.LogoManager-label', t('season_from')),
              m('input.FormControl[type=text][placeholder=MM-DD]', {
                value: rule.when.from || '',
                oninput: (e: Event) => setWhen('from', (e.target as HTMLInputElement).value),
              }),
            ]),
            m('.LogoManager-row.LogoManager-row--inline', [
              m('label.LogoManager-label', t('season_to')),
              m('input.FormControl[type=text][placeholder=MM-DD]', {
                value: rule.when.to || '',
                oninput: (e: Event) => setWhen('to', (e.target as HTMLInputElement).value),
              }),
            ]),
          ])
        : [
            select(
              'season_feast',
              rule.when.feast || 'easter',
              FEASTS.map((f) => ({ value: f, label: t(`feast_${f}`) })),
              (v) => setWhen('feast', v)
            ),
            slider('season_before', rule.when.before ?? 3, 0, 60, 'd', (v) => setWhen('before', v)),
            slider('season_after', rule.when.after ?? 1, 0, 60, 'd', (v) => setWhen('after', v)),
          ],
      m('.LogoManager-row', [
        m('label.LogoManager-label', t('season_logo')),
        m('.LogoManager-help', t('season_logo_help')),
        this.uploadSlot(rule.id, 'light'),
      ]),
      m('.LogoManager-row', [
        m('label.LogoManager-label', t('season_decoration')),
        m('.LogoManager-help', t('season_decoration_help')),
        artGrid(rule.decoration || null, DECORATIONS, art.decorations, 'decoration', (v) => set('decoration', v)),
      ]),
      rule.decoration
        ? select(
            'season_corner',
            rule.corner || 'top-right',
            CORNERS.map((c) => ({ value: c, label: t(`corner_${c}`) })),
            (v) => set('corner', v)
          )
        : null,
      rule.decoration ? slider('season_scale', rule.decorationScale ?? 55, 10, 200, '%', (v) => set('decorationScale', v)) : null,
      m('.LogoManager-row', [
        m('label.LogoManager-label', t('season_weather')),
        m('.LogoManager-help', t('season_weather_help')),
        m('.LogoManager-weatherGrid', [
          m(
            'button.LogoManager-weatherTile.LogoManager-art--none',
            { className: !rule.weather ? 'is-active' : '', type: 'button', onclick: () => set('weather', null) },
            t('season_none')
          ),
          ...WEATHER.map((name) => {
            const layers = art.weather[name] || [];
            return m(
              'button.LogoManager-weatherTile',
              {
                className: rule.weather === name ? 'is-active' : '',
                type: 'button',
                onclick: () => set('weather', name),
                style: {
                  backgroundImage: layers.map((l) => `url("${l[0]}")`).join(','),
                  backgroundSize: layers.map((l) => `${l[1]}px ${l[1]}px`).join(','),
                },
              },
              m('span', t(`weather_${name}`))
            );
          }),
        ]),
      ]),
      rule.weather ? slider('season_density', rule.weatherDensity ?? 2, 1, 3, '', (v) => set('weatherDensity', v)) : null,
    ]);
  }
}
