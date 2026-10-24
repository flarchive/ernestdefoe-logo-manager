<?php

namespace Ernestdefoe\LogoManager\Api\Controller;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Logo\EmailCopy;
use Ernestdefoe\LogoManager\Logo\LogoStore;
use Ernestdefoe\LogoManager\Season\Season;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/logo-manager/email-copy/{scope} — the Report's "Make the email
 * copy" button.
 *
 * Only for an SVG logo, which the server cannot draw: the admin's browser
 * draws the SVG the Report sent it and posts the PNG here, decoded and
 * re-encoded like any upload. Nothing is uploaded by hand, so there is no
 * file for anyone to find again.
 */
class EmailCopyController implements RequestHandlerInterface
{
    public function __construct(
        protected LogoStore $store,
        protected EmailCopy $emailCopy,
        protected SettingsRepositoryInterface $settings,
        protected Config $config,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $scope = strtolower((string) Arr::get($request->getQueryParams(), 'scope', 'base'));
        $raster = $this->store->clientRaster(Arr::get($request->getUploadedFiles(), 'raster'));

        if ($raster === null) {
            throw new ValidationException(['raster' => $this->translator->trans('ernestdefoe-logo-manager.lib.errors.invalid_image')]);
        }

        if ($scope === 'base') {
            if (! $this->isSvg($this->settings->get('logo_path'))) {
                throw new ValidationException(['raster' => $this->translator->trans('ernestdefoe-logo-manager.lib.errors.not_svg')]);
            }

            $this->emailCopy->fromRaster($raster);

            return new JsonResponse(['made' => true]);
        }

        foreach ($this->config->seasonRules() as $rule) {
            $season = new Season($rule, $this->config);
            $email = $season->email()['file'] ?? null;

            // Rewritten under the name the configuration already records, and
            // only a name this extension made for that season's email slot.
            if ($season->id() === $scope && $email && $this->isSvg($season->logo('light'))
                && str_starts_with($email, $this->store->prefix($scope, 'email'))
                && $this->emailCopy->rewriteSeason($email, $raster)) {
                return new JsonResponse(['made' => true]);
            }
        }

        throw new ValidationException(['raster' => $this->translator->trans('ernestdefoe-logo-manager.lib.errors.not_svg')]);
    }

    protected function isSvg(?string $path): bool
    {
        return $path !== null && str_ends_with(strtolower($path), '.svg') && $this->store->exists($path);
    }
}
