<?php

namespace Ernestdefoe\LogoManager\Style;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Season\Decorations;
use Ernestdefoe\LogoManager\Season\Season;

/**
 * Turns the configuration into the stylesheet that is inlined into <head>.
 *
 * It is emitted server-side, per request, and lands in `$document->head` —
 * which core renders *after* the forum stylesheet precisely so that inline
 * overrides win the cascade. That placement is also why there is no flash:
 * the header is already the right height when the first paint happens, rather
 * than jumping once a script has run.
 *
 * Nothing here escapes anything. Every value has already been clamped to a
 * range, matched against a hex pattern, or checked for membership of a list
 * by {@see Config} and {@see Season} — see the note on that class for why the
 * boundary is there and not here.
 */
class StyleSheet
{
    /** Core's own values, restated so "auto" has something to be relative to. */
    public const CORE_HEADER_HEIGHT = 52;

    public const PHONE_BREAKPOINT = '767.98px';

    public function __construct(
        protected Config $config,
        protected ?Season $season = null,
        protected ?string $lightUrl = null,
        protected ?string $darkUrl = null,
    ) {
    }

    public function build(): string
    {
        if (! $this->config->enabled()) {
            return '';
        }

        $rules = array_filter([
            $this->sizing(),
            $this->alignment(),
            $this->plate(),
            $this->logo(),
            $this->recolor(),
            $this->decoration(),
            $this->hover(),
            $this->weather(),
        ]);

        return implode('', $rules);
    }

    /** Core's own defaults. Matching them means emitting nothing. */
    public const CORE_LOGO_HEIGHT = 30;

    /**
     * The header grows with the logo.
     *
     * This is the whole point of the extension: core exposes neither number,
     * so a logo taller than 30px either overflows a 52px header or is
     * silently clamped by `max-height`.
     *
     * 🚨 Nothing is emitted while the sizes still match core's own.
     *
     * That is not an optimisation. Plenty of themes set the header height
     * themselves — for a background image, or a taller brand bar — and if
     * this extension restated core's 52px on every install it would flatten
     * those themes the moment it was enabled, before the administrator had
     * touched a single control. An extension that changes how the site looks
     * merely by being installed is one people uninstall. So installed and
     * unconfigured is a true no-op, and the overrides appear only once
     * somebody has actually asked for a different size.
     *
     * The one exception is an SVG logo, which needs `height` + `width:auto`
     * whatever the size: `max-height` alone constrains one axis, and an SVG
     * has no intrinsic pixel size to work the other one out from.
     *
     * 🚨 The doubled selectors — `:root:root`, `.App .App-header` — are for
     * ordering, not weight. This stylesheet is inlined in <head> and so are
     * other extensions'; against an equal-specificity rule the winner is
     * whichever `<style>` the server happened to emit last, which is no way
     * to settle the one number this extension exists to own. One extra unit
     * decides it in every ordering and still leaves `!important` in a site's
     * own custom CSS as the last word.
     *
     * `.App-header`'s height is restated alongside the variable because a
     * theme that hard-codes it has opted out of core's own
     * `height: var(--header-height)`. Winning the variable and losing the
     * height would leave the page offset by one number and the header drawn
     * at another — content sliding under the header, which is worse than
     * either value on its own.
     */
    protected function sizing(): string
    {
        $header = $this->config->headerHeight();
        $height = $this->config->height();
        $phone = $this->config->heightPhone();

        $resized = $height !== self::CORE_LOGO_HEIGHT
            || $phone !== self::CORE_LOGO_HEIGHT
            || $header !== self::CORE_HEADER_HEIGHT;

        if (! $resized && ! $this->isSvg()) {
            return '';
        }

        // 🚨 The logo rules get their extra unit of specificity only once a
        // size has actually been set.
        //
        // Themes do reach for this element — one on the test forum carries
        // `.App-drawer .Header-logo { height: auto }` inside its own phone
        // breakpoint, which ties `.App-header .Header-logo` and wins on
        // source order, so the drawer logo ignored its setting entirely.
        // Out-specifying settles that. But doing it unconditionally would
        // mean an unconfigured install silently resizing a themed drawer
        // logo the moment it was enabled, which is the same trap the early
        // return above exists to avoid. Configured is authoritative;
        // unconfigured stays polite.
        $scope = $resized ? '.App .App-header' : '.App-header';

        // Concatenated, not interpolated: `{$scope` inside a double-quoted
        // string is read as a complex-expression interpolation, not a brace
        // followed by a variable.
        $css = "$scope .Header-logo{height:{$height}px;width:auto;max-height:none;max-width:100%;object-fit:contain}"
            .'@media (max-width:'.self::PHONE_BREAKPOINT.'){'.$scope." .Header-logo{height:{$phone}px}}";

        if (! $resized) {
            return $css;
        }

        // 🚨 `min-height` as well as `height`, and both only in this branch.
        //
        // A theme that frames a tall logo often sets `min-height` rather than
        // `height` — a floor beats a height, so setting the height alone left
        // the header drawn at the theme's 120px while the page was offset by
        // the 86px this extension had just published. That gap is not a
        // cosmetic disagreement: `.App`'s padding is what keeps content clear
        // of a fixed header, so the top of every page slid underneath it.
        //
        // Between deferring to the theme and staying self-consistent, the
        // consistent answer wins: a header that came out shorter than the
        // theme intended is visible and one control away from being fixed,
        // whereas content hidden under the header looks like a broken forum
        // and gives no clue which of the two settings caused it. This only
        // ever runs once somebody has asked for a size, and a theme that
        // truly must have its floor still has `!important`.
        //
        // Scoped to tablet-up because that is the only place core gives the
        // header a height at all. On a phone it lives inside the slide-out
        // drawer and is sized by its contents; pinning it there would clip
        // the drawer's own header block.
        return ":root:root{--header-height:{$header}px}"
            .$css
            ."@media (min-width:768px){.App .App-header{height:{$header}px;min-height:{$header}px}}";
    }

    /** Whether the logo actually in use is an SVG. */
    protected function isSvg(): bool
    {
        return $this->lightUrl !== null && str_ends_with(strtok($this->lightUrl, '?'), '.svg');
    }

    /**
     * Centring, done in flow rather than by absolute positioning.
     *
     * Giving the two flanking groups `flex:1 1 0` hands each exactly half the
     * free space, which puts the logo in the true centre. An absolutely
     * positioned title would centre it too — and would then sit *on top of*
     * the navigation as soon as the row got busy. This way a crowded header
     * pushes the logo off-centre instead of hiding links underneath it, which
     * is the failure worth having.
     */
    protected function alignment(): string
    {
        if ($this->config->align() !== 'center') {
            return '';
        }

        return '@media (min-width:768px){'
            .'.App-header .container .Header-title{order:2;flex:0 0 auto}'
            .'.App-header .container .Header-primary{order:1;flex:1 1 0;min-width:0}'
            .'.App-header .container .Header-secondary{order:3;flex:1 1 0;min-width:0;display:flex;justify-content:flex-end}'
            .'}';
    }

    /**
     * A background plate behind the logo.
     *
     * Applied to the anchor, never to the image: the recolour overlay and the
     * seasonal decoration are both positioned against the anchor's box, and
     * padding the image instead would move the image inside a box they are
     * still measuring from.
     */
    protected function plate(): string
    {
        $plate = $this->config->plate();

        if (! $plate['enabled']) {
            return '.App-header .Header-title>a{position:relative;display:inline-flex;align-items:center}';
        }

        return '.App-header .Header-title>a{position:relative;display:inline-flex;align-items:center;'
            ."background:{$plate['color']};padding:{$plate['padding']}px;border-radius:{$plate['radius']}px}";
    }

    /** The filter chain, plus the pixelate filter when one is in play. */
    protected function logo(): string
    {
        $chain = $this->filterChain();

        return $chain === '' ? '' : ".App-header .Header-logo{filter:$chain}";
    }

    public function filterChain(bool $withGlow = false): string
    {
        $e = $this->config->effects($this->season?->effectOverrides());
        $parts = [];

        foreach (['grayscale', 'sepia', 'invert', 'opacity'] as $name) {
            if ($e[$name] !== Config::EFFECTS[$name][0]) {
                $parts[] = "$name({$e[$name]}%)";
            }
        }

        foreach (['saturate', 'brightness', 'contrast'] as $name) {
            if ($e[$name] !== 100) {
                $parts[] = "$name({$e[$name]}%)";
            }
        }

        if ($e['hueRotate'] !== 0) {
            $parts[] = "hue-rotate({$e['hueRotate']}deg)";
        }

        if ($e['blur'] !== 0) {
            $parts[] = "blur({$e['blur']}px)";
        }

        // Pixelation goes after the colour adjustments so it blocks up the
        // final image, and before the shadow so the shadow follows the blocky
        // silhouette rather than tracing the original edge through it.
        if ($e['pixelate'] > 0) {
            $parts[] = 'url(#lm-pixelate)';
        }

        $shadow = $this->config->shadow();

        if ($shadow['enabled']) {
            $parts[] = "drop-shadow({$shadow['x']}px {$shadow['y']}px {$shadow['blur']}px {$shadow['color']})";
        }

        if ($withGlow) {
            $parts[] = 'drop-shadow(0 0 10px rgba(255,255,255,.55))';
        }

        return implode(' ', $parts);
    }

    /** The pixel block size, or 0 when pixelation is off. */
    public function pixelate(): int
    {
        return $this->config->effects($this->season?->effectOverrides())['pixelate'];
    }

    /**
     * Flatten the logo to a single colour.
     *
     * Painted as an overlay on the anchor, masked by the logo's own alpha,
     * with the image itself hidden by `visibility` so its box — and therefore
     * the header's layout — is unchanged. `display:none` would collapse the
     * box the overlay is measured against; a CSS filter chain that lands on
     * an arbitrary target colour does exist but needs a solver per colour.
     */
    protected function recolor(): string
    {
        $recolor = $this->config->recolor($this->season?->recolorOverride());

        if (! $recolor['enabled'] || $this->lightUrl === null) {
            return '';
        }

        $pad = $this->config->plate()['enabled'] ? $this->config->plate()['padding'] : 0;
        $light = $this->cssUrl($this->lightUrl);

        $css = '.App-header .Header-logo{visibility:hidden}'
            .".App-header .Header-title>a::after{content:'';position:absolute;inset:{$pad}px;pointer-events:none;"
            ."background-color:{$recolor['color']};"
            ."-webkit-mask:$light center/contain no-repeat;mask:$light center/contain no-repeat}";

        if ($this->darkUrl !== null) {
            $dark = $this->cssUrl($this->darkUrl);
            $css .= '[data-theme^="dark"] .App-header .Header-title>a::after{'
                ."-webkit-mask-image:$dark;mask-image:$dark}";
        }

        return $css;
    }

    /** The seasonal ornament, pinned to a corner of the logo. */
    protected function decoration(): string
    {
        $name = $this->season?->decoration();

        if ($name === null) {
            return '';
        }

        $uri = Decorations::decoration($name);

        if ($uri === null) {
            return '';
        }

        $size = (int) round($this->config->height() * $this->season->decorationScale() / 100);
        $offset = (int) round($size * 0.3);
        [$vertical, $horizontal] = explode('-', $this->season->corner());

        return ".App-header .Header-title>a::before{content:'';position:absolute;z-index:2;pointer-events:none;"
            ."width:{$size}px;height:{$size}px;"
            ."$vertical:-{$offset}px;$horizontal:-{$offset}px;"
            .'background:'.$this->cssUrl($uri).' center/contain no-repeat}';
    }

    protected function hover(): string
    {
        $hover = $this->config->hover();

        if ($hover === 'none') {
            return '';
        }

        $base = '.App-header .Header-title>a{transition:transform .2s ease}';

        return $base.match ($hover) {
            'lift' => '.App-header .Header-title>a:hover{transform:translateY(-2px)}',
            'grow' => '.App-header .Header-title>a:hover{transform:scale(1.06)}',
            'glow' => '.App-header .Header-title>a:hover .Header-logo{filter:'.($this->filterChain(true) ?: 'none').'}',
            'spin' => '.App-header .Header-title>a:hover .Header-logo{animation:lm-spin .9s ease-in-out}'
                .'@keyframes lm-spin{to{transform:rotate(360deg)}}',
            'wobble' => '.App-header .Header-title>a:hover .Header-logo{animation:lm-wobble .6s ease-in-out}'
                .'@keyframes lm-wobble{0%,100%{transform:rotate(0)}25%{transform:rotate(-7deg)}75%{transform:rotate(7deg)}}',
            default => '',
        };
    }

    /**
     * Falling (or rising) particles across the header.
     *
     * 🚨 All the layers move under ONE animation. Three separate animations
     * would each be animating `background-position`, and the last one declared
     * would simply win — leaving two layers frozen. So a single keyframe pair
     * lists every layer's position, and the layers get different speeds by
     * travelling different distances in the same time.
     *
     * Each distance is a whole number of tiles, which is what makes the loop
     * seamless: land on tile boundaries and the jump back to the start is
     * invisible.
     */
    protected function weather(): string
    {
        $name = $this->season?->weather();

        if ($name === null) {
            return '';
        }

        $layers = Decorations::weather($name, $this->season->weatherDensity());

        if ($layers === []) {
            return '';
        }

        $duration = $this->weatherDuration($name);
        $images = [];
        $sizes = [];
        $from = [];
        $to = [];

        foreach ($layers as [$uri, $tile, $steps, $drift]) {
            $images[] = $this->cssUrl($uri);
            $sizes[] = "{$tile}px {$tile}px";
            $from[] = '0 0';
            $to[] = ($drift * $tile).'px '.($steps * $tile).'px';
        }

        $image = implode(',', $images);
        $size = implode(',', $sizes);

        $layer = "content:'';position:absolute;inset:0;pointer-events:none;z-index:0;overflow:hidden;"
            ."background-image:$image;background-size:$size;"
            ."animation:lm-weather {$duration}s linear infinite";

        // 🚨 The two targets are per-breakpoint, not both everywhere.
        //
        // On a phone `.App-header` is not a bar — it is the whole slide-out
        // drawer, holding the search box, the theme picker and every session
        // link. Snow across all of that is not a decorated header, it is
        // weather over a menu. The phone's actual header bar is
        // `.App-navigation`, which is the strip that stays on screen, so the
        // particles go there instead.
        return '.App-header,.App-navigation{position:relative}'
            ."@media (min-width:768px){.App-header::before{{$layer}}}"
            ."@media (max-width:".self::PHONE_BREAKPOINT."){.App-navigation::before{{$layer}}}"
            .'@keyframes lm-weather{from{background-position:'.implode(',', $from).'}'
            .'to{background-position:'.implode(',', $to).'}}'
            // Motion is decoration here and nothing is lost by holding it
            // still — the particles stay, they just stop moving.
            .'@media (prefers-reduced-motion:reduce){'
            .'.App-header::before,.App-navigation::before{animation:none}}';
    }

    protected function weatherDuration(string $name): int
    {
        return match ($name) {
            'rain' => 3,
            'confetti' => 14,
            'snow' => 18,
            'leaves', 'petals' => 20,
            'embers', 'bubbles' => 24,
            default => 26,
        };
    }

    /**
     * A `url("…")` value.
     *
     * Double quotes are safe around every URL this emits: an asset filename
     * is matched against {@see Config::filename()}, and a data URI has already
     * had its own double quotes turned into single ones by the encoder.
     */
    protected function cssUrl(string $url): string
    {
        return 'url("'.$url.'")';
    }
}
