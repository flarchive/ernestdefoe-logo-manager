<?php

namespace Ernestdefoe\LogoManager\Content;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Logo\LogoStore;
use Ernestdefoe\LogoManager\Season\Schedule;
use Ernestdefoe\LogoManager\Season\Season;
use Ernestdefoe\LogoManager\Style\StyleSheet;
use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Everything this extension does to a page happens here, once per request.
 *
 * Three things, in order: work out whether a season is running, swap the logo
 * for that season's if it has one, and inline the stylesheet that sizes,
 * positions and decorates it.
 *
 * The stylesheet goes into `$document->head`, which core renders *after* the
 * forum stylesheet so that inline blocks win the cascade — so there is no
 * flash of an unstyled header, and no JavaScript runs on the forum at all.
 */
class InjectLogoStyles
{
    public function __construct(
        protected Config $config,
        protected Schedule $schedule,
        protected LogoStore $store,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        if (! $this->config->enabled()) {
            return;
        }

        $season = $this->schedule->active();

        $light = $this->resolve($season, 'light');
        $dark = $this->resolve($season, 'dark');

        if ($season !== null && $season->logo('light') !== null) {
            $this->swapLogo($document, $light, $dark);
        }

        $sheet = new StyleSheet($this->config, $season, $light, $dark);
        $css = $sheet->build();

        if ($css !== '') {
            $document->head[] = '<style id="logo-manager">'.$css.'</style>';
        }

        if (($block = $sheet->pixelate()) > 0) {
            $document->foot[] = $this->pixelateFilter($block);
        }
    }

    /**
     * The URL of the logo actually in use for one variant.
     *
     * A season that supplies only a light logo takes over the dark one too.
     * The alternative is a forum showing this year's Christmas logo in light
     * mode and last year's permanent one in dark, which nobody asked for and
     * which is hard to notice if you never switch themes.
     */
    protected function resolve(?Season $season, string $variant): ?string
    {
        if ($season !== null) {
            $file = $season->logo($variant) ?? ($variant === 'dark' ? $season->logo('light') : null);

            if ($file !== null && $this->store->exists($file)) {
                return $this->store->url($file);
            }
        }

        $key = $variant === 'dark' ? 'logo_dark_mode_path' : 'logo_path';
        $path = $this->config->filename($this->settings->get($key));

        return $path !== null && $this->store->exists($path) ? $this->store->url($path) : null;
    }

    /**
     * Point the page at the seasonal logo without touching any setting.
     *
     * The header `<img>` is rendered from `$forum['logoUrl']`, which comes
     * from the forum API document — so changing it there changes the markup
     * the browser receives, with nothing persisted and nothing to undo when
     * the season ends.
     *
     * 🚨 The payload is patched as well as the document. `CorePayload` copies
     * the forum resource out of the API document into `$document->payload`,
     * and whether it has already run depends on callback ordering — patching
     * both makes this independent of that ordering. Miss it and the server
     * markup is seasonal while `app.forum.attribute('logoUrl')` still returns
     * the permanent logo.
     */
    protected function swapLogo(Document $document, ?string $light, ?string $dark): void
    {
        $api = $document->getForumApiDocument();

        Arr::set($api, 'data.attributes.logoUrl', $light);
        Arr::set($api, 'data.attributes.logoDarkModeUrl', $dark);

        $document->setForumApiDocument($api);

        foreach ($document->payload['resources'] ?? [] as $index => $resource) {
            if (($resource['type'] ?? null) === 'forums') {
                $document->payload['resources'][$index]['attributes']['logoUrl'] = $light;
                $document->payload['resources'][$index]['attributes']['logoDarkModeUrl'] = $dark;
            }
        }
    }

    /**
     * The pixelate filter, as an inline SVG at the end of the body.
     *
     * There is no CSS filter function for this, so it is done with SVG filter
     * primitives: flood a two-pixel dot in the middle of one block, tile that
     * across the image, sample the source only where the dots are, then
     * dilate each sample back out to fill its block.
     *
     * 🚨 It has to be inline in the document. A `filter: url()` pointing at a
     * data URI or an external file works in Chrome and Firefox and does
     * nothing at all in Safari, where it would silently render the logo
     * unfiltered.
     */
    protected function pixelateFilter(int $block): string
    {
        $half = max(1, (int) round($block / 2));

        return '<svg id="logo-manager-filters" width="0" height="0" aria-hidden="true" focusable="false"'
            .' style="position:absolute;width:0;height:0;overflow:hidden">'
            .'<filter id="lm-pixelate" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">'
            ."<feFlood x=\"$half\" y=\"$half\" width=\"2\" height=\"2\"/>"
            ."<feComposite width=\"$block\" height=\"$block\"/>"
            .'<feTile result="tiles"/>'
            .'<feComposite in="SourceGraphic" in2="tiles" operator="in"/>'
            ."<feMorphology operator=\"dilate\" radius=\"$half\"/>"
            .'</filter></svg>';
    }
}
