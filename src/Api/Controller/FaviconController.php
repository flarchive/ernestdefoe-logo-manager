<?php

namespace Ernestdefoe\LogoManager\Api\Controller;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Logo\Favicons;
use Ernestdefoe\LogoManager\Logo\LogoStore;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * POST / DELETE /api/logo-manager/favicons/{scope}.
 *
 * Like the logo, the permanent favicon is written into core's own
 * `favicon_path`, so link previews, the admin and anything else that reads
 * core's setting use it, and it survives uninstalling this extension. The
 * extra formats are recorded beside it. A season's set is handed back for
 * the studio to save with that season.
 */
class FaviconController implements RequestHandlerInterface
{
    public function __construct(
        protected Favicons $favicons,
        protected LogoStore $store,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $scope = strtolower((string) Arr::get($request->getQueryParams(), 'scope', 'base'));
        $scope = preg_match('/^[a-z0-9-]{1,40}$/', $scope) ? $scope : 'base';

        if ($request->getMethod() === 'DELETE') {
            $this->favicons->forget($scope);

            if ($scope === 'base') {
                $this->replaceCore(null);
            }

            return new JsonResponse(['deleted' => true]);
        }

        $file = Arr::get($request->getUploadedFiles(), 'favicon');

        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['favicon' => $this->error('no_file')]);
        }

        try {
            $set = $this->favicons->store($scope, $file, Arr::get($request->getUploadedFiles(), 'raster'));
        } catch (RuntimeException $e) {
            throw new ValidationException(['favicon' => $this->error($e->getMessage())]);
        }

        if ($scope === 'base') {
            $this->replaceCore($set);
        }

        return new JsonResponse(['set' => $set, 'url' => $this->store->url($set['icon'])]);
    }

    protected function replaceCore(?array $set): void
    {
        $previous = $this->settings->get('favicon_path');

        // Core's own upload leaves a file of its own; ours were already swept.
        if ($previous && ! str_starts_with($previous, 'logo-manager-')) {
            $this->store->delete($previous);
        }

        $this->settings->set('favicon_path', $set['icon'] ?? null);
        $this->settings->set(Config::FAVICON, $set === null ? null : json_encode($set));
    }

    protected function error(string $key): string
    {
        return $this->translator->trans("ernestdefoe-logo-manager.lib.errors.$key");
    }
}
