<?php

/*
 * Logo Manager for Flarum 2 — SVG logos, real size control that takes the
 * header with it, alignment, live effects and a seasonal schedule. MIT.
 *
 * No core files are touched and no JavaScript is loaded on the forum: the
 * whole appearance is one inline stylesheet, composed per request and placed
 * in <head> after the forum stylesheet, where core intends inline overrides
 * to win. That is what keeps the header the right height in the first paint
 * rather than after a script has run.
 */

use Ernestdefoe\LogoManager\Api\Controller\DeleteLogoController;
use Ernestdefoe\LogoManager\Api\Controller\UploadLogoController;
use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Content\AdminArtPayload;
use Ernestdefoe\LogoManager\Content\InjectLogoStyles;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->content(InjectLogoStyles::class),

    // The admin layout renders the same header, and the studio's live preview
    // is only honest if the real thing is styled the same way beside it.
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less')
        ->content(InjectLogoStyles::class)
        ->content(AdminArtPayload::class),

    new Extend\Locales(__DIR__.'/locale'),

    // Without a registered default the admin switch renders as off on a fresh
    // install, which reads as "installed but not working".
    (new Extend\Settings())
        ->default(Config::ENABLED, true),

    (new Extend\Routes('api'))
        ->post('/logo-manager/logos/{scope}/{variant}', 'logo-manager.upload', UploadLogoController::class)
        ->delete('/logo-manager/logos/{scope}/{variant}', 'logo-manager.delete', DeleteLogoController::class),
];
