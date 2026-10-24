<?php

namespace Ernestdefoe\LogoManager;

use Ernestdefoe\LogoManager\Logo\SeasonalEmailLogo;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Mail\EmailLogo;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Emails carry the running season's logo. Core resolves the email
        // logo through the container as each mail view renders.
        if (class_exists(EmailLogo::class)) {
            $this->container->bind(EmailLogo::class, SeasonalEmailLogo::class);
        }
    }
}
