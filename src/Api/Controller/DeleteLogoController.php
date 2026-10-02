<?php

namespace Ernestdefoe\LogoManager\Api\Controller;

use Ernestdefoe\LogoManager\Logo\LogoStore;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/logo-manager/logos/{scope}/{variant}
 *
 * Removes the files for one slot, and for the permanent logo also clears
 * core's setting so the header falls back to the forum title — the same end
 * state as core's own delete.
 */
class DeleteLogoController implements RequestHandlerInterface
{
    public function __construct(
        protected LogoStore $store,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $scope = strtolower((string) Arr::get($request->getQueryParams(), 'scope', 'base'));
        $scope = preg_match('/^[a-z0-9-]{1,40}$/', $scope) ? $scope : 'base';
        $variant = Arr::get($request->getQueryParams(), 'variant') === 'dark' ? 'dark' : 'light';

        if ($scope === 'base') {
            $key = UploadLogoController::SETTING[$variant];
            $this->store->delete($this->settings->get($key));
            $this->settings->set($key, null);
        }

        $this->store->forget($scope, $variant);

        return new JsonResponse(['deleted' => true]);
    }
}
