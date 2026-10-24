<?php

namespace Ernestdefoe\LogoManager\Content;

use Ernestdefoe\LogoManager\Logo\LogoStore;
use Ernestdefoe\LogoManager\Season\Decorations;
use Ernestdefoe\LogoManager\Season\Schedule;
use Ernestdefoe\LogoManager\Season\Season;
use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hands the studio the same artwork the forum gets.
 *
 * The decorations and weather tiles are generated in PHP, and the studio's
 * live preview has to draw the very thing the forum will draw. Redrawing them
 * in TypeScript would be a second copy of sixteen pieces of artwork that
 * nothing keeps in step — the first colour tweak that landed in one and not
 * the other would make the preview a liar.
 *
 * So the art is put in the admin payload instead: one source, admin-only, and
 * no extra request when the studio opens.
 */
class AdminArtPayload
{
    public function __construct(
        protected LogoStore $store,
        protected Schedule $schedule,
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        $decorations = [];

        foreach (Season::DECORATIONS as $name) {
            $decorations[$name] = Decorations::decoration($name);
        }

        $weather = [];

        foreach (Season::WEATHER as $name) {
            $weather[$name] = Decorations::weather($name, 3);
        }

        $document->payload['logoManagerArt'] = [
            'decorations' => $decorations,
            'weather' => $weather,
            // Where a stored filename becomes a URL. The studio needs this to
            // preview a season's logo, and the assets disk is not always
            // `<baseUrl>/assets` — it can be an S3 or R2 bucket on another
            // host entirely, so guessing the path client-side would show a
            // broken image on exactly the sites that configured one.
            'assets' => $this->assetsBase(),
            // Which season is running, decided by the same schedule the forum
            // uses rather than by a second implementation in the browser.
            'activeSeason' => $this->schedule->active()?->id(),
        ];
    }

    protected function assetsBase(): string
    {
        $probe = 'logo-manager-probe';
        $url = $this->store->url($probe);

        return str_ends_with($url, $probe) ? substr($url, 0, -strlen($probe)) : $url;
    }
}
