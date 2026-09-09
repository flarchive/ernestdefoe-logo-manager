import app from 'flarum/admin/app';

/**
 * The shape of the stored configuration, and the small amount of logic the
 * studio needs to draw a live preview of it.
 *
 * 🚨 `filterChain` below mirrors `Style\StyleSheet::filterChain()` in PHP. The
 * duplication is deliberate and bounded: the preview has to show the result
 * before anything is saved, and asking the server on every slider drag would
 * be a request per frame. Keep the two in step — if a filter is added to one,
 * add it to the other, or the preview quietly stops matching the forum.
 */

export const KEY = 'ernestdefoe-logo-manager.config';

/** The master switch — a plain setting, not part of the document above. */
export const ENABLED = 'ernestdefoe-logo-manager.enabled';

export type Effects = {
  grayscale: number;
  sepia: number;
  invert: number;
  saturate: number;
  brightness: number;
  contrast: number;
  hueRotate: number;
  blur: number;
  opacity: number;
  pixelate: number;
};

export type SeasonRule = {
  id: string;
  name: string;
  enabled: boolean;
  when: { type: 'range' | 'feast'; from?: string; to?: string; feast?: string; before?: number; after?: number };
  logo?: { light?: string | null; dark?: string | null };
  decoration?: string | null;
  corner?: string;
  decorationScale?: number;
  weather?: string | null;
  weatherDensity?: number;
  effects?: Partial<Effects> | null;
  recolor?: { enabled: boolean; color: string } | null;
};

export type Cfg = {
  size: { height: number; heightPhone: number; headerHeight: number | null; align: 'left' | 'center' };
  plate: { enabled: boolean; color: string; padding: number; radius: number };
  effects: Effects;
  recolor: { enabled: boolean; color: string };
  shadow: { enabled: boolean; color: string; x: number; y: number; blur: number };
  hover: string;
  seasons: { enabled: boolean; timezone: string; rules: SeasonRule[] };
};

/** Identity values: an untouched config produces no filter at all. */
export const NEUTRAL: Effects = {
  grayscale: 0,
  sepia: 0,
  invert: 0,
  saturate: 100,
  brightness: 100,
  contrast: 100,
  hueRotate: 0,
  blur: 0,
  opacity: 100,
  pixelate: 0,
};

export const DEFAULTS: Cfg = {
  size: { height: 30, heightPhone: 30, headerHeight: null, align: 'left' },
  plate: { enabled: false, color: '#ffffff', padding: 6, radius: 8 },
  effects: { ...NEUTRAL },
  recolor: { enabled: false, color: '#ffffff' },
  shadow: { enabled: false, color: '#000000', x: 0, y: 2, blur: 6 },
  hover: 'none',
  seasons: { enabled: true, timezone: '', rules: [] },
};

/** Slider bounds, matching the clamps in `Config::EFFECTS`. */
export const EFFECT_RANGE: Record<keyof Effects, [number, number, string]> = {
  pixelate: [0, 24, 'px'],
  grayscale: [0, 100, '%'],
  sepia: [0, 100, '%'],
  invert: [0, 100, '%'],
  saturate: [0, 400, '%'],
  brightness: [0, 300, '%'],
  contrast: [0, 300, '%'],
  hueRotate: [0, 360, '°'],
  blur: [0, 20, 'px'],
  opacity: [0, 100, '%'],
};

export const EFFECT_ORDER: (keyof Effects)[] = [
  'pixelate',
  'grayscale',
  'sepia',
  'invert',
  'saturate',
  'brightness',
  'contrast',
  'hueRotate',
  'blur',
  'opacity',
];

export const HOVERS = ['none', 'lift', 'grow', 'glow', 'spin', 'wobble'];

export const DECORATIONS = [
  'santa-hat',
  'snow-cap',
  'pumpkin',
  'hearts',
  'shamrock',
  'party-hat',
  'easter-egg',
  'autumn-leaf',
  'blossom',
  'sun',
  'sparkles',
  'cobweb',
  'candle',
  'bunting',
  'firework',
  'ribbon',
];

export const WEATHER = ['snow', 'leaves', 'petals', 'confetti', 'sparkles', 'rain', 'embers', 'bubbles'];

export const FEASTS = ['easter', 'thanksgiving_us', 'thanksgiving_ca', 'lunar_new_year', 'mothers_day_us', 'fathers_day_us'];

export const CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

export const EFFECT_PRESETS: Record<string, Partial<Effects>> = {
  none: { ...NEUTRAL },
  mono: { ...NEUTRAL, grayscale: 100, contrast: 110 },
  pixel: { ...NEUTRAL, pixelate: 5, saturate: 120 },
  glow: { ...NEUTRAL, saturate: 160, brightness: 115 },
  soft: { ...NEUTRAL },
  vintage: { ...NEUTRAL, sepia: 55, saturate: 80, contrast: 95 },
};

/** Season starting points. Dates are month-day; a feast rule moves each year. */
export function seasonPresets(): SeasonRule[] {
  const t = (k: string) => String(app.translator.trans(`ernestdefoe-logo-manager.admin.season_preset_${k}`));
  const range = (from: string, to: string) => ({ type: 'range' as const, from, to });

  return [
    { id: 'christmas', name: t('christmas'), enabled: true, when: range('12-01', '12-26'), decoration: 'santa-hat', corner: 'top-right', weather: 'snow', weatherDensity: 2 },
    { id: 'winter', name: t('winter'), enabled: true, when: range('12-27', '02-28'), decoration: 'snow-cap', corner: 'top-left', weather: 'snow', weatherDensity: 1 },
    { id: 'new-year', name: t('new_year'), enabled: true, when: range('12-31', '01-02'), decoration: 'firework', corner: 'top-right', weather: 'confetti', weatherDensity: 3 },
    { id: 'lunar-new-year', name: t('lunar_new_year'), enabled: true, when: { type: 'feast', feast: 'lunar_new_year', before: 3, after: 5 }, decoration: 'firework', corner: 'top-right', weather: 'sparkles', weatherDensity: 2 },
    { id: 'valentines', name: t('valentines'), enabled: true, when: range('02-10', '02-15'), decoration: 'hearts', corner: 'top-right', weather: 'petals', weatherDensity: 2 },
    { id: 'st-patricks', name: t('st_patricks'), enabled: true, when: range('03-15', '03-18'), decoration: 'shamrock', corner: 'top-right' },
    { id: 'easter', name: t('easter'), enabled: true, when: { type: 'feast', feast: 'easter', before: 5, after: 1 }, decoration: 'easter-egg', corner: 'top-right', weather: 'petals', weatherDensity: 1 },
    { id: 'spring', name: t('spring'), enabled: true, when: range('03-20', '05-31'), decoration: 'blossom', corner: 'top-right', weather: 'petals', weatherDensity: 1 },
    { id: 'summer', name: t('summer'), enabled: true, when: range('06-01', '08-31'), decoration: 'sun', corner: 'top-right' },
    { id: 'pride', name: t('pride'), enabled: true, when: range('06-01', '06-30'), decoration: 'bunting', corner: 'top-right', weather: 'confetti', weatherDensity: 1 },
    { id: 'autumn', name: t('autumn'), enabled: true, when: range('09-22', '11-20'), decoration: 'autumn-leaf', corner: 'top-right', weather: 'leaves', weatherDensity: 2 },
    { id: 'halloween', name: t('halloween'), enabled: true, when: range('10-24', '11-01'), decoration: 'pumpkin', corner: 'top-right', weather: 'embers', weatherDensity: 2, effects: { saturate: 130, contrast: 110 } },
    { id: 'thanksgiving', name: t('thanksgiving'), enabled: true, when: { type: 'feast', feast: 'thanksgiving_us', before: 3, after: 1 }, decoration: 'autumn-leaf', corner: 'top-right', weather: 'leaves', weatherDensity: 1 },
    { id: 'birthday', name: t('birthday'), enabled: true, when: range('01-01', '01-01'), decoration: 'party-hat', corner: 'top-right', weather: 'confetti', weatherDensity: 3 },
  ];
}

/** Fill in anything missing so the studio never reads `undefined`. */
export function normalise(raw: any): Cfg {
  const source = raw && typeof raw === 'object' ? raw : {};

  return {
    size: { ...DEFAULTS.size, ...(source.size || {}) },
    plate: { ...DEFAULTS.plate, ...(source.plate || {}) },
    effects: { ...NEUTRAL, ...(source.effects || {}) },
    recolor: { ...DEFAULTS.recolor, ...(source.recolor || {}) },
    shadow: { ...DEFAULTS.shadow, ...(source.shadow || {}) },
    hover: source.hover || 'none',
    seasons: {
      enabled: source.seasons?.enabled !== false,
      timezone: source.seasons?.timezone || '',
      rules: Array.isArray(source.seasons?.rules) ? source.seasons.rules : [],
    },
  };
}

/**
 * The CSS `filter` value for a set of effects.
 *
 * Mirrors `StyleSheet::filterChain()`. `pixelate` is deliberately excluded:
 * on the forum it is an SVG filter reference, and the preview draws that a
 * different way (see `previewPixelate`).
 */
export function filterChain(effects: Effects, shadow: Cfg['shadow'], pixelateRef: string | null = null): string {
  const parts: string[] = [];

  (['grayscale', 'sepia', 'invert', 'opacity'] as const).forEach((name) => {
    if (effects[name] !== NEUTRAL[name]) parts.push(`${name}(${effects[name]}%)`);
  });

  (['saturate', 'brightness', 'contrast'] as const).forEach((name) => {
    if (effects[name] !== 100) parts.push(`${name}(${effects[name]}%)`);
  });

  if (effects.hueRotate !== 0) parts.push(`hue-rotate(${effects.hueRotate}deg)`);
  if (effects.blur !== 0) parts.push(`blur(${effects.blur}px)`);
  if (pixelateRef && effects.pixelate > 0) parts.push(`url(#${pixelateRef})`);
  if (shadow.enabled) parts.push(`drop-shadow(${shadow.x}px ${shadow.y}px ${shadow.blur}px ${shadow.color})`);

  return parts.join(' ') || 'none';
}

/** The header height the server would derive, so the preview agrees with it. */
export function headerHeight(cfg: Cfg): number {
  if (cfg.size.headerHeight !== null && cfg.size.headerHeight !== undefined) {
    return Math.max(30, Math.min(400, cfg.size.headerHeight));
  }

  return Math.max(52, cfg.size.height + 22);
}
