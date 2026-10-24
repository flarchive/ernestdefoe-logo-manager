<?php

namespace Ernestdefoe\LogoManager\Logo;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Throwable;

/**
 * The pixel shape of a logo file, so the header can reserve its box before
 * the image has loaded.
 *
 * A logo sized by height alone is zero pixels wide until its file arrives,
 * and everything laid out after it — the navigation, an ornament pinned to
 * its corner — moves sideways when it does. An `aspect-ratio` from the
 * server fixes the width in the first paint.
 *
 * The shapes are kept in one settings row, because every setting is already
 * loaded with the request: a page view costs no read of its own. A file is
 * only measured the first time it is seen, which also covers a logo uploaded
 * through core's own settings page rather than through this extension.
 */
class Dimensions
{
    public const KEY = 'ernestdefoe-logo-manager.dimensions';

    /** A logo is replaced a handful of times; the map never needs to grow past this. */
    protected const KEEP = 24;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Factory $filesystem,
    ) {
    }

    /** @return array{0:int,1:int}|null width and height, or null when unknown. */
    public function of(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $map = json_decode((string) $this->settings->get(self::KEY), true);
        $map = is_array($map) ? $map : [];

        if (! array_key_exists($path, $map)) {
            // [0, 0] is remembered too, so an unmeasurable file is read once
            // and not on every page view after it.
            $map[$path] = $this->measure($path) ?? [0, 0];
            $this->settings->set(self::KEY, json_encode(array_slice($map, -self::KEEP, null, true)));
        }

        [$width, $height] = array_map('intval', array_values((array) $map[$path]) + [0, 0]);

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    protected function measure(string $path): ?array
    {
        try {
            $disk = $this->filesystem->disk('flarum-assets');

            if (! $disk->exists($path)) {
                return null;
            }

            return $this->fromBytes($path, (string) $disk->get($path));
        } catch (Throwable) {
            return null;
        }
    }

    public function fromBytes(string $path, string $bytes): ?array
    {
        if (str_ends_with(strtolower($path), '.svg')) {
            return self::svg($bytes);
        }

        $size = @getimagesizefromstring($bytes);

        return $size && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
    }

    /**
     * An SVG's shape from its root element: the viewBox when it has one, which
     * is what the browser scales by, else plain numeric width and height.
     *
     * @return array{0:int,1:int}|null
     */
    public static function svg(string $bytes): ?array
    {
        if (! preg_match('/<svg\b[^>]*>/is', $bytes, $root)) {
            return null;
        }

        $tag = $root[0];

        if (preg_match('/\bviewBox\s*=\s*["\']\s*[-\d.e]+[\s,]+[-\d.e]+[\s,]+([\d.e]+)[\s,]+([\d.e]+)/i', $tag, $box)) {
            [$width, $height] = [(float) $box[1], (float) $box[2]];
        } elseif (preg_match('/\swidth\s*=\s*["\']\s*([\d.]+)(?:px)?\s*["\']/i', $tag, $w)
            && preg_match('/\sheight\s*=\s*["\']\s*([\d.]+)(?:px)?\s*["\']/i', $tag, $h)) {
            [$width, $height] = [(float) $w[1], (float) $h[1]];
        } else {
            return null;
        }

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        // Kept as a ratio at a usable precision; the values only ever feed
        // `aspect-ratio`, so the unit is irrelevant.
        $scale = 1000 / max($width, $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }
}
