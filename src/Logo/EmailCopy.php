<?php

namespace Ernestdefoe\LogoManager\Logo;

use Flarum\Mail\EmailLogo;
use Illuminate\Contracts\Container\Container;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the logo in emails in step with the permanent light logo.
 *
 * 🚨 From Flarum 2.0.0, emails do not show `logo_path`. Core's own upload
 * stores a PNG copy beside the WebP logo, and emails prefer that copy over
 * `logo_path`. An upload here bypasses core's controller, so without this
 * a copy left by an earlier core upload went on being mailed after the logo
 * was replaced or removed. A WebP logo with no copy shows no logo at all.
 *
 * Earlier versions have no such class, so this does nothing there.
 */
class EmailCopy
{
    public function __construct(
        protected Container $container,
        protected LoggerInterface $log,
        protected LogoStore $store,
    ) {
    }

    /**
     * Make the copy from a new upload, or drop the old one if that can't be done.
     *
     * An SVG can't be rasterised on the server, so for one the copy is made
     * from the PNG the admin's browser drew from it. Mail clients — Gmail and
     * Outlook among them — do not show SVG at all, so without that PNG an SVG
     * logo meant no logo in any email.
     */
    public function refresh(UploadedFileInterface $file, string $filename, ?ImageInterface $raster = null): void
    {
        if (! class_exists(EmailLogo::class)) {
            return;
        }

        $emailLogo = $this->container->make(EmailLogo::class);

        if (str_ends_with($filename, '.svg') && $raster === null) {
            $emailLogo->deleteCopy();

            return;
        }

        $temp = null;

        try {
            $source = (string) $file->getStream()->getMetadata('uri');

            if (str_ends_with($filename, '.svg')) {
                $temp = tempnam(sys_get_temp_dir(), 'lm-email');
                file_put_contents($temp, (string) $raster->toPng());
                $source = $temp;
            }

            $emailLogo->storeCopy($source);
        } catch (Throwable $e) {
            $this->log->warning('[logo-manager] email logo copy failed: '.$e->getMessage());
            $emailLogo->deleteCopy();
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /**
     * An email-safe copy of a season's logo, in that season's own slot.
     *
     * @return array{file:string,size:string}|null
     */
    public function season(string $scope, ?ImageInterface $image): ?array
    {
        $this->store->forget($scope, 'email');

        if ($image === null || ! class_exists(EmailLogo::class)) {
            return null;
        }

        try {
            [$encoded, $extension, $size] = $this->container->make(EmailLogo::class)->encode($image);
            $name = $this->store->name($scope, 'email', '.'.$extension);
            $this->store->write($name, (string) $encoded);

            return ['file' => $name, 'size' => $size];
        } catch (Throwable $e) {
            $this->log->warning('[logo-manager] seasonal email logo failed: '.$e->getMessage());

            return null;
        }
    }

    public function forget(): void
    {
        if (class_exists(EmailLogo::class)) {
            $this->container->make(EmailLogo::class)->deleteCopy();
        }
    }
}
