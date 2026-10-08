<?php

namespace Ernestdefoe\LogoManager\Logo;

use Flarum\Mail\EmailLogo;
use Illuminate\Contracts\Container\Container;
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
    ) {
    }

    /** Make the copy from a new upload, or drop the old one if that can't be done. */
    public function refresh(UploadedFileInterface $file, string $filename): void
    {
        if (! class_exists(EmailLogo::class)) {
            return;
        }

        $emailLogo = $this->container->make(EmailLogo::class);

        // An SVG can't be rasterised here. Without a copy, emails fall back to
        // `logo_path`, as they did before 2.0.
        if (str_ends_with($filename, '.svg')) {
            $emailLogo->deleteCopy();

            return;
        }

        try {
            $emailLogo->storeCopy((string) $file->getStream()->getMetadata('uri'));
        } catch (Throwable $e) {
            $this->log->warning('[logo-manager] email logo copy failed: '.$e->getMessage());
            $emailLogo->deleteCopy();
        }
    }

    public function forget(): void
    {
        if (class_exists(EmailLogo::class)) {
            $this->container->make(EmailLogo::class)->deleteCopy();
        }
    }
}
