<?php

namespace Ernestdefoe\LogoManager\Season;

/**
 * The seasonal artwork, drawn in code.
 *
 * Every decoration and every weather particle is an inline SVG turned into a
 * data URI, so the extension ships no image files at all. That is not a size
 * trick — it means the artwork cannot 404, cannot be blocked by a CDN rule,
 * needs no upload directory and no asset-publishing step, and survives a
 * `cache:clear` that would take a compiled asset with it.
 *
 * 🚨 Attributes are written with single quotes and only the five characters
 * that break a data URI are escaped. Running the whole string through
 * `rawurlencode` also works and roughly doubles it — and this stylesheet is
 * inlined into the <head> of every page, so the shorter form is worth the
 * five-line encoder.
 */
class Decorations
{
    /** Corner artwork pinned to the logo. Drawn on a 100x100 viewBox. */
    public static function decoration(string $name): ?string
    {
        $svg = match ($name) {
            'santa-hat' => "<path d='M14 66C18 34 40 12 66 14c18 1 26 14 22 26-4 13-18 20-32 24L16 74z' fill='#d92b2b'/>"
                ."<path d='M14 66C18 34 40 12 66 14c-14 8-24 26-28 42z' fill='#b81f1f' opacity='.4'/>"
                ."<rect x='4' y='60' width='74' height='24' rx='12' fill='#fff' stroke='#e3e9f2' stroke-width='1.5'/>"
                ."<circle cx='90' cy='40' r='13' fill='#fff' stroke='#e3e9f2' stroke-width='1.5'/>",

            'snow-cap' => "<path d='M2 48c12-16 24-12 34-20 10-8 22-6 30 2 10-6 24 0 30 14 2 6 0 12-6 12H8c-6 0-8-4-6-8z' fill='#fbfdff' stroke='#cfe2f5' stroke-width='2' stroke-linejoin='round'/>"
                ."<g fill='#fbfdff' stroke='#cfe2f5' stroke-width='2' stroke-linejoin='round'>"
                ."<path d='M18 52l5 22 5-22z'/><path d='M54 52l5 28 5-28z'/><path d='M78 52l4 17 4-17z'/></g>",

            'pumpkin' => "<path d='M46 30c0-12-4-18-12-22 12-2 20 6 20 22z' fill='#3f7d33'/>"
                ."<ellipse cx='50' cy='62' rx='40' ry='32' fill='#f07a1a'/>"
                ."<g fill='#dd6910'><ellipse cx='26' cy='62' rx='13' ry='31'/><ellipse cx='74' cy='62' rx='13' ry='31'/></g>"
                ."<g fill='#5a2a06'><path d='M32 50l13 9-13 5z'/><path d='M68 50L55 59l13 5z'/>"
                ."<path d='M30 72l9-5 6 6 5-6 5 6 6-6 9 5-8 11H38z'/></g>",

            'hearts' => "<path d='M56 92C28 71 17 56 17 43c0-13 9-21 20-21 8 0 15 5 19 11 4-6 11-11 19-11 11 0 20 8 20 21 0 13-11 28-39 49z' fill='#e0447a'/>"
                ."<path d='M26 60C11 49 5 41 5 34c0-7 5-11 11-11 4 0 8 2 10 6 2-4 6-6 10-6 6 0 11 4 11 11 0 7-6 15-21 26z' fill='#ff8fb1'/>",

            'shamrock' => "<g fill='#2e9e4f'><ellipse cx='50' cy='28' rx='16' ry='20'/>"
                ."<ellipse cx='28' cy='54' rx='20' ry='16'/><ellipse cx='72' cy='54' rx='20' ry='16'/></g>"
                ."<path d='M50 54c3 14 5 24-4 38' stroke='#2e9e4f' stroke-width='7' fill='none' stroke-linecap='round'/>",

            'party-hat' => "<path d='M50 10l32 78H18z' fill='#5b8ff9'/>"
                ."<g fill='#ffd166'><path d='M50 34l8 18H42z'/><path d='M50 60l12 26H38z'/></g>"
                ."<circle cx='50' cy='10' r='10' fill='#ff5d8f'/>",

            'easter-egg' => "<clipPath id='e'><path d='M50 6c22 0 38 30 38 52 0 20-17 36-38 36S12 78 12 58C12 36 28 6 50 6z'/></clipPath>"
                ."<path d='M50 6c22 0 38 30 38 52 0 20-17 36-38 36S12 78 12 58C12 36 28 6 50 6z' fill='#fff0f5'/>"
                ."<g clip-path='url(#e)'><path d='M0 40h100v10H0z' fill='#7ec8e3'/><path d='M0 66h100v10H0z' fill='#ffb3c9'/>"
                ."<g fill='#ffd166'><circle cx='28' cy='28' r='6'/><circle cx='60' cy='22' r='6'/><circle cx='40' cy='88' r='6'/><circle cx='74' cy='58' r='6'/></g></g>",

            'autumn-leaf' => "<path d='M50 94C30 74 12 55 12 38 12 21 29 8 50 8s38 13 38 30c0 17-18 36-38 56z' fill='#e2761b'/>"
                ."<path d='M50 96V26' stroke='#a8501a' stroke-width='3.5' stroke-linecap='round'/>"
                ."<g stroke='#a8501a' stroke-width='3' stroke-linecap='round' fill='none'>"
                ."<path d='M50 44L27 31M50 44l23-13M50 66L31 53M50 66l19-13'/></g>",

            'blossom' => "<g fill='#ffb3c9'><ellipse cx='50' cy='24' rx='14' ry='21'/>"
                ."<ellipse cx='50' cy='24' rx='14' ry='21' transform='rotate(72 50 50)'/>"
                ."<ellipse cx='50' cy='24' rx='14' ry='21' transform='rotate(144 50 50)'/>"
                ."<ellipse cx='50' cy='24' rx='14' ry='21' transform='rotate(216 50 50)'/>"
                ."<ellipse cx='50' cy='24' rx='14' ry='21' transform='rotate(288 50 50)'/></g>"
                ."<circle cx='50' cy='50' r='11' fill='#ffd166'/>",

            'sun' => "<g stroke='#ffb020' stroke-width='7' stroke-linecap='round'>"
                ."<path d='M50 4v14M50 82v14M4 50h14M82 50h14M17 17l10 10M73 73l10 10M83 17L73 27M27 73L17 83'/></g>"
                ."<circle cx='50' cy='50' r='24' fill='#ffc531'/>",

            'sparkles' => "<g fill='#ffd166'><path d='M54 8c4 26 8 32 34 36-26 4-30 10-34 36-4-26-8-32-34-36 26-4 30-10 34-36z'/></g>"
                ."<g fill='#fff0a8'><path d='M18 58c2 13 4 16 17 18-13 2-15 5-17 18-2-13-4-16-17-18 13-2 15-5 17-18z'/>"
                ."<path d='M84 62c1 8 3 10 11 11-8 1-10 3-11 11-1-8-3-10-11-11 8-1 10-3 11-11z'/></g>",

            'cobweb' => "<g stroke='#c9d2e0' stroke-width='3' fill='none' stroke-linecap='round'>"
                ."<path d='M0 0v96M0 0l28 92M0 0l62 78M0 0l88 50M0 0h96'/>"
                ."<path d='M0 26A26 26 0 0026 0M0 50A50 50 0 0050 0M0 74A74 74 0 0074 0M0 96A96 96 0 0096 0'/></g>"
                ."<g stroke='#2b2f3a' stroke-width='3' stroke-linecap='round'><path d='M50 62l-12-6M50 62l-10 10M50 62l12-12M50 62l14 4'/></g>"
                ."<circle cx='52' cy='64' r='9' fill='#2b2f3a'/>",

            'candle' => "<rect x='36' y='40' width='28' height='54' rx='5' fill='#f6f1e4'/>"
                ."<path d='M36 48h28v6H36z' fill='#e6dcc6'/>"
                ."<path d='M50 4c13 15 17 24 17 30 0 10-8 17-17 17s-17-7-17-17c0-6 4-15 17-30z' fill='#ffa41b'/>"
                ."<path d='M50 18c7 10 9 14 9 19 0 5-4 9-9 9s-9-4-9-9c0-5 2-9 9-19z' fill='#fff0a8'/>",

            'bunting' => "<path d='M2 16c30 24 66 24 96 0' stroke='#8a94a6' stroke-width='3' fill='none'/>"
                ."<path d='M10 22l16 4-6 24z' fill='#e0447a'/><path d='M34 30l18 2-8 26z' fill='#ffd166'/>"
                ."<path d='M58 32l18-4-4 26z' fill='#5b8ff9'/><path d='M82 26l14-6v24z' fill='#2e9e4f'/>",

            'firework' => "<g stroke='#ffd166' stroke-width='5' stroke-linecap='round'>"
                ."<path d='M50 50V14M50 50v36M50 50H14M50 50h36M50 50L25 25M50 50l25 25M50 50l25-25M50 50L25 75'/></g>"
                ."<g fill='#ff5d8f'><circle cx='50' cy='8' r='5'/><circle cx='50' cy='92' r='5'/><circle cx='8' cy='50' r='5'/>"
                ."<circle cx='92' cy='50' r='5'/><circle cx='19' cy='19' r='4'/><circle cx='81' cy='81' r='4'/>"
                ."<circle cx='81' cy='19' r='4'/><circle cx='19' cy='81' r='4'/></g>"
                ."<circle cx='50' cy='50' r='8' fill='#fff0a8'/>",

            'ribbon' => "<g stroke='#e0447a' stroke-width='13' fill='none' stroke-linecap='round'>"
                ."<path d='M34 94l24-62c6-14 2-24-8-24'/><path d='M66 94L42 32c-6-14-2-24 8-24'/></g>",

            default => null,
        };

        return $svg === null ? null : self::uri($svg);
    }

    /**
     * Weather layers: one entry per parallax layer, as
     * [background-image, tile size in px, tiles travelled down, tiles
     * travelled sideways].
     *
     * 🚨 The last two are COUNTS OF TILES, not pixels and not seconds.
     *
     * Every layer shares one animation of one duration (see
     * `StyleSheet::weather()` for why it has to), so a layer's speed is how
     * far it travels in that time — and its travel has to land on a whole
     * number of tiles or the loop visibly jumps when it restarts. Negative
     * counts move the layer up (embers, bubbles) or leftwards.
     *
     * Three layers at different sizes and speeds is what stops falling
     * particles reading as one repeating tile sliding down the screen.
     *
     * 🚨 Every tile is drawn on a 100x100 viewBox and then painted at its own
     * `background-size`, so a shape's size in the artwork is a FRACTION of
     * the tile, not a number of pixels. Sizes below are given in pixels and
     * converted against the tile — the first cut hard-coded viewBox units and
     * a 28-unit leaf on a 140px tile came out five times too big, filling the
     * header with orange blobs.
     *
     * @return array<int, array{0:string,1:int,2:int,3:int}>
     */
    public static function weather(string $name, int $density): array
    {
        $defs = match ($name) {
            'snow' => [
                [130, 4, 1, fn (int $t) => self::dots('#ffffff', 0.90, $t, [[18, 22, 2.2], [70, 58, 1.7], [44, 90, 2.0], [92, 34, 1.4]])],
                [175, 2, -1, fn (int $t) => self::dots('#ffffff', 0.65, $t, [[34, 40, 1.9], [86, 16, 1.5], [58, 76, 2.1]])],
                [230, 1, 1, fn (int $t) => self::dots('#ffffff', 0.45, $t, [[62, 28, 1.7], [16, 68, 1.4], [90, 88, 1.9]])],
            ],
            'rain' => [
                [90, 3, 1, fn (int $t) => self::strokes('#a8c8ff', 0.55, $t, [[20, 18, 15, 12], [64, 52, 15, 12], [40, 84, 15, 12]], 1.3)],
                [130, 3, 1, fn (int $t) => self::strokes('#cfe2ff', 0.35, $t, [[52, 26, 19, 14], [14, 70, 19, 14]], 1.5)],
                [64, 4, 1, fn (int $t) => self::strokes('#8fb4f5', 0.30, $t, [[36, 40, 11, 10], [78, 78, 11, 10]], 1.1)],
            ],
            'embers' => [
                [150, -2, 1, fn (int $t) => self::dots('#ff8a3d', 0.85, $t, [[26, 34, 2.0], [78, 72, 1.6], [50, 96, 2.2]])],
                [190, -1, -1, fn (int $t) => self::dots('#ffc531', 0.60, $t, [[64, 24, 1.7], [18, 82, 2.0]])],
                [240, -1, 1, fn (int $t) => self::dots('#ff5d3d', 0.45, $t, [[88, 48, 1.5], [34, 68, 1.8]])],
            ],
            'bubbles' => [
                [150, -2, 1, fn (int $t) => self::rings('#ffffff', 0.50, $t, [[26, 34, 11], [80, 78, 8]])],
                [200, -1, -1, fn (int $t) => self::rings('#dff1ff', 0.40, $t, [[64, 24, 14], [20, 84, 9]])],
                [250, -1, 1, fn (int $t) => self::rings('#ffffff', 0.30, $t, [[88, 52, 8], [40, 70, 12]])],
            ],
            'sparkles' => [
                [120, 2, 1, fn (int $t) => self::shapes('spark', '#ffe9a8', 0.95, $t, [[24, 30, 11, 0], [76, 70, 8, 20]])],
                [170, 1, -1, fn (int $t) => self::shapes('spark', '#fff6d6', 0.75, $t, [[62, 22, 9, 10], [18, 76, 12, -15]])],
                [215, 1, 1, fn (int $t) => self::shapes('spark', '#ffd166', 0.55, $t, [[86, 54, 10, 30], [36, 84, 7, 0]])],
            ],
            'leaves' => [
                [140, 2, 1, fn (int $t) => self::shapes('leaf', '#e2761b', 0.95, $t, [[22, 28, 12, 25], [74, 72, 10, -40]])],
                [190, 1, 1, fn (int $t) => self::shapes('leaf', '#c2521a', 0.8, $t, [[60, 20, 11, 70], [16, 78, 13, -20]])],
                [240, 1, -1, fn (int $t) => self::shapes('leaf', '#f0a336', 0.7, $t, [[84, 50, 12, 130], [38, 86, 9, 10]])],
            ],
            'petals' => [
                [130, 2, 1, fn (int $t) => self::shapes('petal', '#ffb3c9', 0.95, $t, [[24, 30, 12, -25], [78, 68, 9, 30]])],
                [180, 1, 1, fn (int $t) => self::shapes('petal', '#ffd6e2', 0.8, $t, [[62, 18, 11, 15], [18, 80, 13, -50]])],
                [230, 1, -1, fn (int $t) => self::shapes('petal', '#ff8fb1', 0.7, $t, [[86, 52, 10, 60], [40, 88, 12, 0]])],
            ],
            'confetti' => [
                [140, 3, 1, fn (int $t) => self::shapes('confetti', '#5b8ff9', 0.95, $t, [[20, 26, 9, 24], [72, 66, 8, -40]])],
                [190, 2, -1, fn (int $t) => self::shapes('confetti', '#ff5d8f', 0.9, $t, [[58, 18, 9, -15], [16, 74, 8, 50]])],
                [240, 2, 1, fn (int $t) => self::shapes('confetti', '#ffd166', 0.85, $t, [[84, 48, 8, 60], [36, 84, 9, 10]])],
            ],
            default => [],
        };

        $out = [];

        foreach (array_slice($defs, 0, max(1, min(3, $density))) as [$size, $down, $across, $make]) {
            $out[] = [$make($size), $size, $down, $across];
        }

        return $out;
    }

    /** Soft dots: [x%, y%, radius in px]. */
    protected static function dots(string $color, float $alpha, int $tile, array $points): string
    {
        $body = '';

        foreach ($points as [$x, $y, $r]) {
            $body .= "<circle cx='$x' cy='$y' r='".self::px($r, $tile)."'/>";
        }

        return self::uri("<g fill='$color' opacity='$alpha'>$body</g>");
    }

    /** Hollow circles, for bubbles: [x%, y%, diameter in px]. */
    protected static function rings(string $color, float $alpha, int $tile, array $points): string
    {
        $body = '';
        $width = self::px(1.4, $tile);

        foreach ($points as [$x, $y, $d]) {
            $body .= "<circle cx='$x' cy='$y' r='".self::px($d / 2, $tile)."'/>";
        }

        return self::uri("<g fill='none' stroke='$color' stroke-width='$width' opacity='$alpha'>$body</g>");
    }

    /** Slanted lines, for rain: [x%, y%, length in px, lean in px]. */
    protected static function strokes(string $color, float $alpha, int $tile, array $lines, float $weight): string
    {
        $body = '';

        foreach ($lines as [$x, $y, $length, $lean]) {
            $dx = -self::px($lean, $tile);
            $dy = self::px($length, $tile);
            $body .= "<path d='M$x ".$y."l$dx $dy'/>";
        }

        return self::uri("<g stroke='$color' stroke-width='".self::px($weight, $tile)."' opacity='$alpha' stroke-linecap='round' fill='none'>$body</g>");
    }

    /**
     * Scattered particles: [x%, y%, size in px, rotation].
     *
     * Each shape is drawn in a 10x10 box around its own origin so that
     * rotating it spins it in place rather than swinging it across the tile.
     */
    protected static function shapes(string $shape, string $color, float $alpha, int $tile, array $items): string
    {
        $path = match ($shape) {
            // A pointed lens rather than a rounded blob: at 11px the points are
            // the only thing that says "leaf" and not "pebble".
            'leaf' => "<path d='M0-5C3-2.5 3 2.5 0 5-3 2.5-3-2.5 0-5z'/>",
            'petal' => "<ellipse cx='0' cy='0' rx='5' ry='3'/>",
            'confetti' => "<rect x='-2' y='-3.5' width='4' height='7' rx='1'/>",
            'spark' => "<path d='M0-5c.8 3.5 1.2 4.2 5 5-3.8.8-4.2 1.5-5 5-.8-3.5-1.2-4.2-5-5 3.8-.8 4.2-1.5 5-5z'/>",
            default => '',
        };

        $body = '';

        foreach ($items as [$x, $y, $size, $rotation]) {
            $scale = round(self::px($size, $tile) / 10, 3);
            $body .= "<g transform='translate($x $y) rotate($rotation) scale($scale)'>$path</g>";
        }

        return self::uri("<g fill='$color' opacity='$alpha'>$body</g>");
    }

    /** A length in screen pixels, expressed in the tile's 100-unit viewBox. */
    protected static function px(float $pixels, int $tile): float
    {
        return round($pixels / max(1, $tile) * 100, 3);
    }


    /**
     * Wrap a fragment in an <svg> and encode it for use inside `url("…")`.
     *
     * Only `%`, `#`, `<`, `>` and `&` are escaped — `%` first, or the escapes
     * introduced after it would be escaped again.
     */
    protected static function uri(string $body, int $w = 100, int $h = 100): string
    {
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $w $h' width='$w' height='$h'>$body</svg>";

        return 'data:image/svg+xml,'.str_replace(
            ['%', '#', '<', '>', '&'],
            ['%25', '%23', '%3C', '%3E', '%26'],
            $svg
        );
    }
}
