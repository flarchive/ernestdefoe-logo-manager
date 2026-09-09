<?php

namespace Ernestdefoe\LogoManager;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The whole configuration, read once and normalised.
 *
 * 🚨 Everything in here ends up inside a CSS declaration, and the source is a
 * JSON blob an administrator writes through the ordinary settings API. So no
 * value is ever passed through as given: numbers are clamped to a range,
 * colours must match a hex pattern, and every enumerated value must be a
 * member of its list. A value that fails falls back to the default rather
 * than being dropped, so a hand-edited blob degrades to something sane
 * instead of a half-written stylesheet.
 *
 * The alternative — quoting on the way out — would mean trusting that every
 * future call site remembered to do it. Normalising once at the boundary
 * means the rest of the extension can treat these values as safe, because
 * they are.
 */
class Config
{
    public const KEY = 'ernestdefoe-logo-manager.config';

    /**
     * The master switch is a setting of its own rather than a field inside
     * the JSON document. The admin page's own boolean control writes a plain
     * settings row, so keeping it in the blob would have given the switch and
     * the studio two different places to disagree about.
     */
    public const ENABLED = 'ernestdefoe-logo-manager.enabled';

    public const ALIGNMENTS = ['left', 'center'];

    public const HOVERS = ['none', 'lift', 'grow', 'glow', 'spin', 'wobble'];

    /**
     * Filter names mapped to [default, min, max]. Defaults are the identity
     * value for that filter, so an untouched config produces no filter at all.
     */
    public const EFFECTS = [
        'grayscale'  => [0, 0, 100],
        'sepia'      => [0, 0, 100],
        'invert'     => [0, 0, 100],
        'saturate'   => [100, 0, 400],
        'brightness' => [100, 0, 300],
        'contrast'   => [100, 0, 300],
        'hueRotate'  => [0, 0, 360],
        'blur'       => [0, 0, 20],
        'opacity'    => [100, 0, 100],
        'pixelate'   => [0, 0, 24],
    ];

    protected array $data;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
        $raw = $this->settings->get(self::KEY);
        $decoded = $raw ? json_decode($raw, true) : null;

        $this->data = is_array($decoded) ? $decoded : [];
    }

    public function enabled(): bool
    {
        $value = $this->settings->get(self::ENABLED);

        // Unset means on: the extension having been enabled is the intent.
        return $value === null ? true : (bool) (int) $value;
    }

    /** Logo height in px on a normal screen. */
    public function height(): int
    {
        return $this->int(['size', 'height'], 30, 12, 300);
    }

    /** Logo height in px inside the phone drawer. */
    public function heightPhone(): int
    {
        return $this->int(['size', 'heightPhone'], 30, 12, 200);
    }

    /**
     * The header's own height, or null to derive it.
     *
     * Core's default pairs a 52px header with a 30px logo, so 22px is the
     * chrome around it. Growing the logo without growing the header is what
     * makes an enlarged logo overflow, which is the whole reason this
     * extension exists — so "auto" is the default and it keeps that ratio,
     * never shrinking below core's own 52px.
     */
    public function headerHeight(): int
    {
        $manual = $this->data['size']['headerHeight'] ?? null;

        if ($manual !== null && $manual !== '' && $manual !== 'auto') {
            return $this->clamp((int) $manual, 30, 400);
        }

        return max(52, $this->height() + 22);
    }

    public function isHeaderHeightAuto(): bool
    {
        $manual = $this->data['size']['headerHeight'] ?? null;

        return $manual === null || $manual === '' || $manual === 'auto';
    }

    public function align(): string
    {
        return $this->enum(['size', 'align'], self::ALIGNMENTS, 'left');
    }

    public function hover(): string
    {
        return $this->enum(['hover'], self::HOVERS, 'none');
    }

    public function plate(): array
    {
        return [
            'enabled' => (bool) ($this->data['plate']['enabled'] ?? false),
            'color'   => $this->color(['plate', 'color'], '#ffffff'),
            'padding' => $this->int(['plate', 'padding'], 6, 0, 60),
            'radius'  => $this->int(['plate', 'radius'], 8, 0, 100),
        ];
    }

    public function shadow(): array
    {
        return [
            'enabled' => (bool) ($this->data['shadow']['enabled'] ?? false),
            'color'   => $this->color(['shadow', 'color'], '#000000'),
            'x'       => $this->int(['shadow', 'x'], 0, -40, 40),
            'y'       => $this->int(['shadow', 'y'], 2, -40, 40),
            'blur'    => $this->int(['shadow', 'blur'], 6, 0, 60),
        ];
    }

    /**
     * The base effect values, with a season's overrides folded in when one is
     * running. A season supplies only the keys it cares about, so a season
     * that pixelates does not also reset a permanent drop of saturation.
     */
    public function effects(?array $overrides = null): array
    {
        $stored = is_array($this->data['effects'] ?? null) ? $this->data['effects'] : [];
        $stored = array_merge($stored, is_array($overrides) ? $overrides : []);

        $out = [];

        foreach (self::EFFECTS as $name => [$default, $min, $max]) {
            $value = $stored[$name] ?? $default;
            $out[$name] = is_numeric($value) ? $this->clamp((int) round((float) $value), $min, $max) : $default;
        }

        return $out;
    }

    public function recolor(?array $override = null): array
    {
        $source = is_array($override) ? $override : ($this->data['recolor'] ?? []);

        return [
            'enabled' => (bool) ($source['enabled'] ?? false),
            'color'   => $this->hex($source['color'] ?? null) ?? '#ffffff',
        ];
    }

    /** The stored filename for a logo variant, or null. */
    public function logo(string $variant): ?string
    {
        return $this->filename($this->data['logos'][$variant] ?? null);
    }

    public function seasonsEnabled(): bool
    {
        return (bool) ($this->data['seasons']['enabled'] ?? true);
    }

    public function timezone(): string
    {
        $tz = $this->data['seasons']['timezone'] ?? '';

        return is_string($tz) && in_array($tz, timezone_identifiers_list(), true)
            ? $tz
            : date_default_timezone_get();
    }

    /** @return array<int, array<string, mixed>> the raw season rules, in order. */
    public function seasonRules(): array
    {
        $rules = $this->data['seasons']['rules'] ?? [];

        return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
    }

    /** The stored blob, for handing back to the admin client untouched. */
    public function raw(): array
    {
        return $this->data;
    }

    public function write(array $data): void
    {
        $this->data = $data;
        $this->settings->set(self::KEY, json_encode($data));
    }

    /**
     * A filename is only ever one our own store wrote, but it arrives through
     * the same admin-writable blob as everything else — so it is checked
     * against the shape we write rather than merely checked for "..".
     */
    public function filename(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) && ! str_contains($value, '..')
            ? $value
            : null;
    }

    public function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)
            ? $value
            : null;
    }

    protected function color(array $path, string $default): string
    {
        return $this->hex($this->at($path)) ?? $default;
    }

    protected function int(array $path, int $default, int $min, int $max): int
    {
        $value = $this->at($path);

        return is_numeric($value) ? $this->clamp((int) round((float) $value), $min, $max) : $default;
    }

    protected function enum(array $path, array $allowed, string $default): string
    {
        $value = $this->at($path);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    protected function at(array $path): mixed
    {
        $node = $this->data;

        foreach ($path as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    protected function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
