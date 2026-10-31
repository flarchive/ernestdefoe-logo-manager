<?php

namespace Ernestdefoe\LogoManager\Logo;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Season\Schedule;
use Flarum\Mail\EmailLogo;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\ImageManager;

/**
 * Core's email logo, with the running season's logo in place of the copy of
 * the permanent one.
 *
 * Core resolves the logo as each mail view renders, so a queued email sent
 * on Christmas Day carries the Christmas logo even if it was queued the
 * night before. A dedicated email logo uploaded in core's own Email settings
 * still wins over everything: that is an explicit choice for mail, where a
 * season's logo is a choice for the header.
 */
class SeasonalEmailLogo extends EmailLogo
{
    public function __construct(
        SettingsRepositoryInterface $settings,
        ImageManager $imageManager,
        Factory $filesystemFactory,
        protected Config $config,
        protected Schedule $schedule,
    ) {
        parent::__construct($settings, $imageManager, $filesystemFactory);
    }

    public function resolve(): ?array
    {
        if (! $this->settings->get(self::PATH_KEY) && $this->config->enabled()) {
            $email = $this->schedule->active()?->email();

            if ($email !== null && $this->assetsDir->exists($email['file'])) {
                return ['url' => $this->assetsDir->url($email['file'])] + $this->displaySize($email['size']);
            }
        }

        return parent::resolve();
    }
}
