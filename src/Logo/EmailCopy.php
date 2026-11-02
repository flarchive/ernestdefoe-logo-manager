<?php

namespace Ernestdefoe\LogoManager\Logo;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Season\Season;
use Flarum\Mail\EmailLogo;
use Flarum\Settings\SettingsRepositoryInterface;
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
        protected SettingsRepositoryInterface $settings,
        protected Config $config,
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

        try {
            if (str_ends_with($filename, '.svg')) {
                $this->storeFromBytes((string) $raster->toPng());
            } else {
                $emailLogo->storeCopy((string) $file->getStream()->getMetadata('uri'));
            }
        } catch (Throwable $e) {
            $this->log->warning('[logo-manager] email logo copy failed: '.$e->getMessage());
            $emailLogo->deleteCopy();
        }
    }

    /**
     * Make the permanent look's copy from the PNG the admin's browser drew
     * from an SVG logo — the Report's "Make the email copy" button.
     */
    public function fromRaster(ImageInterface $raster): void
    {
        if (class_exists(EmailLogo::class)) {
            $this->storeFromBytes((string) $raster->toPng());
        }
    }

    /**
     * Rewrite a season's recorded email file under the same name.
     *
     * The same name, so the configuration the studio holds in memory stays
     * right: a new name would be undone by the studio's next save.
     */
    public function rewriteSeason(string $name, ImageInterface $image): bool
    {
        if (! class_exists(EmailLogo::class)) {
            return false;
        }

        [$encoded, $extension] = $this->container->make(EmailLogo::class)->encode($image);

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
            return false;
        }

        $this->store->write($name, (string) $encoded);

        return true;
    }

    /**
     * Put back an email copy whose file has gone, where the server can.
     *
     * 🚨 A settings row can outlive its file — a forum moved to a new server
     * without its assets folder, a backup restored over a newer one, a file
     * removed by hand. Core then mails a broken image, and the Report used to
     * say "upload it again" about a filename nobody had ever seen.
     *
     * - A raster logo is re-encoded from the logo file itself.
     * - An SVG logo can't be drawn on the server, so the stale row is
     *   cleared and the Report offers the button that has the browser do it.
     * - The same goes for a WebP logo with no copy at all (an upgrade from a
     *   version that never made one): mail clients cannot show WebP.
     *
     * @return string[] the scopes put back ('base' or a season id)
     */
    public function heal(): array
    {
        if (! class_exists(EmailLogo::class)) {
            return [];
        }

        $healed = [];
        $copy = (string) $this->settings->get(EmailLogo::COPY_PATH_KEY);
        $logo = (string) $this->settings->get('logo_path');
        $logoBytes = $logo !== '' ? $this->store->read($logo) : null;

        $stale = $copy !== '' && ! $this->store->exists($copy);
        $absent = $copy === '' && preg_match('/\.webp$/i', $logo);

        if ($stale || $absent) {
            try {
                if ($logoBytes !== null && ! $this->store->isSvg($logoBytes)) {
                    $this->storeFromBytes($logoBytes);
                    $healed[] = 'base';
                } elseif ($stale) {
                    $this->container->make(EmailLogo::class)->deleteCopy();
                }
            } catch (Throwable $e) {
                $this->log->warning('[logo-manager] email logo repair failed: '.$e->getMessage());
            }
        }

        foreach ($this->config->seasonRules() as $rule) {
            $season = new Season($rule, $this->config);
            $email = $season->email()['file'] ?? null;
            $light = $season->logo('light');

            if (! $email || ! $light || $this->store->exists($email) || ! str_starts_with($email, $this->store->prefix($season->id(), 'email'))) {
                continue;
            }

            try {
                $bytes = $this->store->read($light);

                if ($bytes !== null && ! $this->store->isSvg($bytes) && $this->rewriteSeason($email, $this->store->decode($bytes))) {
                    $healed[] = $season->id();
                }
            } catch (Throwable $e) {
                $this->log->warning('[logo-manager] seasonal email logo repair failed: '.$e->getMessage());
            }
        }

        return $healed;
    }

    protected function storeFromBytes(string $bytes): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'lm-email');

        try {
            file_put_contents($temp, $bytes);
            $this->container->make(EmailLogo::class)->storeCopy($temp);
        } finally {
            @unlink($temp);
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
