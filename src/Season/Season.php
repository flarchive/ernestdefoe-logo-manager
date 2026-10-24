<?php

namespace Ernestdefoe\LogoManager\Season;

use Ernestdefoe\LogoManager\Config;

/**
 * One seasonal rule, normalised the same way the base config is.
 *
 * A season may override any part of the permanent look: its own logo files,
 * its own effect values, a decoration pinned to a corner of the logo, and a
 * weather layer over the header. Everything it does not set is inherited, so
 * a rule that only wants snow falling is three fields, not a second copy of
 * the entire configuration.
 */
class Season
{
    public const DECORATIONS = [
        'santa-hat', 'snow-cap', 'pumpkin', 'hearts', 'shamrock', 'party-hat',
        'easter-egg', 'autumn-leaf', 'blossom', 'sun', 'sparkles', 'cobweb',
        'candle', 'bunting', 'firework', 'ribbon',
    ];

    public const WEATHER = [
        'snow', 'leaves', 'petals', 'confetti', 'sparkles', 'rain', 'embers', 'bubbles',
    ];

    public const CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

    public function __construct(protected array $rule, protected Config $config)
    {
    }

    public function id(): string
    {
        $id = $this->rule['id'] ?? '';

        return is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id) ? $id : 'season';
    }

    public function enabled(): bool
    {
        return (bool) ($this->rule['enabled'] ?? true);
    }

    public function whenType(): string
    {
        $type = $this->rule['when']['type'] ?? 'range';

        return in_array($type, ['range', 'feast'], true) ? $type : 'range';
    }

    /** A "mm-dd" bound as an MMDD integer, or null when it is not a real date. */
    public function monthDay(string $key): ?int
    {
        $value = $this->rule['when'][$key] ?? null;

        if (! is_string($value) || ! preg_match('/^(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        [, $month, $day] = $m;

        if ((int) $month < 1 || (int) $month > 12 || (int) $day < 1 || (int) $day > 31) {
            return null;
        }

        return (int) ($month.$day);
    }

    public function feast(): ?string
    {
        $feast = $this->rule['when']['feast'] ?? null;

        return is_string($feast) && in_array($feast, Feasts::ALL, true) ? $feast : null;
    }

    /** Days either side of a moveable feast. */
    public function window(string $side): int
    {
        $value = $this->rule['when'][$side] ?? ($side === 'before' ? 3 : 1);

        return is_numeric($value) ? max(0, min(60, (int) $value)) : 0;
    }

    public function logo(string $variant): ?string
    {
        return $this->config->filename($this->rule['logo'][$variant] ?? null);
    }

    public function decoration(): ?string
    {
        $value = $this->rule['decoration'] ?? null;

        return is_string($value) && in_array($value, self::DECORATIONS, true) ? $value : null;
    }

    public function corner(): string
    {
        $value = $this->rule['corner'] ?? 'top-right';

        return is_string($value) && in_array($value, self::CORNERS, true) ? $value : 'top-right';
    }

    /** Decoration size as a percentage of the logo's height. */
    public function decorationScale(): int
    {
        $value = $this->rule['decorationScale'] ?? 55;

        return is_numeric($value) ? max(10, min(200, (int) $value)) : 55;
    }

    public function weather(): ?string
    {
        $value = $this->rule['weather'] ?? null;

        return is_string($value) && in_array($value, self::WEATHER, true) ? $value : null;
    }

    /** Weather density, 1–3, mapped to how many particle layers are drawn. */
    public function weatherDensity(): int
    {
        $value = $this->rule['weatherDensity'] ?? 2;

        return is_numeric($value) ? max(1, min(3, (int) $value)) : 2;
    }

    public function effectOverrides(): ?array
    {
        return is_array($this->rule['effects'] ?? null) ? $this->rule['effects'] : null;
    }

    public function recolorOverride(): ?array
    {
        return is_array($this->rule['recolor'] ?? null) ? $this->rule['recolor'] : null;
    }
}
