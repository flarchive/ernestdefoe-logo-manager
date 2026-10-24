<?php

namespace Ernestdefoe\LogoManager\Api\Controller;

use Ernestdefoe\LogoManager\Logo\EmailCopy;
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
 * POST /api/logo-manager/logos/{scope}/{variant}.
 *
 * `scope` is either `base` — the forum's permanent logo — or the id of a
 * season. The permanent logo is written straight into core's own
 * `logo_path` / `logo_dark_mode_path` settings rather than into this
 * extension's configuration, which is deliberate: the header template, the
 * admin layout and the mail templates all read those keys, so the upload
 * works everywhere core's does, and a forum that later uninstalls this
 * extension keeps the logo it uploaded.
 *
 * A season's logo is returned to the client instead, and the studio saves it
 * as part of the configuration. Writing it here as well would mean two
 * writers on one document and a save from the studio could silently undo an
 * upload made moments earlier.
 */
class UploadLogoController implements RequestHandlerInterface
{
    public const SETTING = [
        'light' => 'logo_path',
        'dark' => 'logo_dark_mode_path',
    ];

    public function __construct(
        protected LogoStore $store,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected EmailCopy $emailCopy,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $scope = $this->scope($request);
        $variant = $this->variant($request);

        $file = Arr::get($request->getUploadedFiles(), 'logo');

        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['logo' => $this->error('no_file')]);
        }

        try {
            $filename = $this->store->put($scope, $variant, $file);
        } catch (RuntimeException $e) {
            throw new ValidationException(['logo' => $this->error($e->getMessage())]);
        }

        // For an SVG, the admin's browser also sends the PNG it drew from
        // it: the only form of the logo a mail client will show.
        $raster = str_ends_with($filename, '.svg')
            ? $this->store->clientRaster(Arr::get($request->getUploadedFiles(), 'raster'))
            : null;
        $email = null;

        if ($scope === 'base') {
            $key = self::SETTING[$variant];
            $this->store->delete($this->settings->get($key));
            $this->settings->set($key, $filename);

            if ($variant === 'light') {
                $this->emailCopy->refresh($file, $filename, $raster);
            }
        } elseif ($variant === 'light') {
            $email = $this->emailCopy->season($scope, $raster ?? $this->decodeQuietly((string) $file->getStream()));
        }

        return new JsonResponse([
            'filename' => $filename,
            'url' => $this->store->url($filename),
            'email' => $email,
        ]);
    }

    protected function decodeQuietly(string $bytes): ?\Intervention\Image\Interfaces\ImageInterface
    {
        try {
            return $this->store->decode($bytes);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * 🚨 Both parts come from the ROUTE and are filtered to the shape a slot
     * can have. They end up in a filename, so a value that could carry a
     * slash or a `..` would be choosing where on the disk to write.
     */
    protected function scope(ServerRequestInterface $request): string
    {
        $scope = (string) Arr::get($request->getQueryParams(), 'scope', 'base');
        $scope = strtolower($scope);

        return preg_match('/^[a-z0-9-]{1,40}$/', $scope) ? $scope : 'base';
    }

    protected function variant(ServerRequestInterface $request): string
    {
        return Arr::get($request->getQueryParams(), 'variant') === 'dark' ? 'dark' : 'light';
    }

    /** Messages are keys, not sentences — see the translatability rule. */
    protected function error(string $key): string
    {
        return $this->translator->trans("ernestdefoe-logo-manager.lib.errors.$key");
    }
}
