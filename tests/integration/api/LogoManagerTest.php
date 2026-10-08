<?php

namespace Ernestdefoe\LogoManager\Tests\integration\api;

use Ernestdefoe\LogoManager\Config;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

class LogoManagerTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-logo-manager');

        $this->prepareDatabase([User::class => [$this->normalUser()]]);

        // Every page render below would otherwise rebuild the forum's JS
        // with source maps, which outgrows PHP's default memory limit.
        $this->config('debug', false);
    }

    private function file(string $bytes, string $name = 'logo.png', string $type = 'image/png'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $bytes);

        return ['logo' => new UploadedFile($path, strlen($bytes), UPLOAD_ERR_OK, $name, $type)];
    }

    private function png(int $width = 40, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function asActor(ServerRequestInterface $request, ?int $actor): ServerRequestInterface
    {
        if ($actor) {
            return $this->requestAsUser($request, $actor);
        }

        // A guest's write needs a session and its CSRF token, as a browser has.
        $initial = $this->send($this->request('GET', '/api'));

        return $this->requestWithCookiesFrom($request->withHeader('X-CSRF-Token', $initial->getHeaderLine('X-CSRF-Token')), $initial);
    }

    private function upload(string $scope, string $variant, ?int $actor, ?array $files = null): array
    {
        $response = $this->send(
            $this->asActor($this->request('POST', "/api/logo-manager/logos/$scope/$variant"), $actor)
                ->withUploadedFiles($files ?? $this->file($this->png()))
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function remove(string $scope, string $variant, ?int $actor): int
    {
        return $this->send($this->asActor($this->request('DELETE', "/api/logo-manager/logos/$scope/$variant"), $actor))->getStatusCode();
    }

    private function stored(string $key): ?string
    {
        return $this->database()->table('settings')->where('key', $key)->value('value');
    }

    private function disk()
    {
        return $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');
    }

    private function page(string $path = '/', ?int $actor = null): string
    {
        return (string) $this->send($this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : []))->getBody();
    }

    /** The forum resource's attributes, from a page's JSON payload. */
    private function forumAttributes(string $html): array
    {
        preg_match('#<script id="flarum-json-payload" type="application/json">(.*?)</script>#s', $html, $m);

        return array_values(array_filter(json_decode($m[1], true)['resources'], fn ($r) => $r['type'] === 'forums'))[0]['attributes'];
    }

    #[Test]
    public function only_an_admin_can_upload_or_remove_a_logo()
    {
        foreach ([null, 2] as $actor) {
            [$status] = $this->upload('base', 'light', $actor);
            $this->assertSame(403, $status);
            $this->assertSame(403, $this->remove('base', 'light', $actor));
        }

        $this->assertNull($this->stored('logo_path'));
    }

    #[Test]
    public function the_permanent_logo_goes_into_cores_own_settings()
    {
        // A logo uploaded through core's own setting, before this extension.
        $this->disk()->put('logo-core.png', $this->png());
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('logo_path', 'logo-core.png');

        [$status, $body] = $this->upload('base', 'light', 1);
        $this->assertFalse($this->disk()->exists('logo-core.png'), 'The logo it replaces is removed from the disk');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/^logo-manager-base\.light-[0-9a-f]{8}\.webp$/', $body['filename']);
        $this->assertSame($body['filename'], $this->stored('logo_path'));
        $this->assertStringEndsWith('/assets/'.$body['filename'], $body['url']);
        $first = $body['filename'];

        [, $body] = $this->upload('base', 'light', 1);
        $this->assertFalse($this->disk()->exists($first), 'A replaced logo is removed from the disk');

        [, $dark] = $this->upload('base', 'dark', 1);
        $this->assertSame($dark['filename'], $this->stored('logo_dark_mode_path'));
        $this->assertSame($body['filename'], $this->stored('logo_path'), 'The dark logo leaves the light one alone');

        $this->assertSame(200, $this->remove('base', 'light', 1));
        $this->assertNull($this->stored('logo_path'));
        $this->assertFalse($this->disk()->exists($body['filename']));
    }

    #[Test]
    public function emails_show_the_permanent_logo_not_the_one_it_replaced()
    {
        if (! class_exists(\Flarum\Mail\EmailLogo::class)) {
            $this->markTestSkipped('Emails have had their own copy of the logo since Flarum 2.0.0.');
        }

        // The copy core made when the old logo was uploaded on its own page.
        $settings = $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
        $this->disk()->put('logo-email-old.png', $this->png());
        $settings->set('logo_email_copy_path', 'logo-email-old.png');
        $settings->set('logo_email_copy_size', '40x20');

        $this->upload('base', 'light', 1, $this->file($this->png(300, 100)));
        $copy = $this->stored('logo_email_copy_path');
        $this->assertNotSame('logo-email-old.png', $copy, 'The replaced logo is not mailed any more');
        $this->assertFalse($this->disk()->exists('logo-email-old.png'));
        $this->assertStringEndsWith('.png', (string) $copy, 'Mail clients get a PNG, not the WebP');
        $this->assertTrue($this->disk()->exists($copy));
        $this->assertSame('300x100', $this->stored('logo_email_copy_size'));

        $this->upload('base', 'dark', 1);
        $this->upload('season-1', 'light', 1);
        $this->assertSame($copy, $this->stored('logo_email_copy_path'), 'Only the permanent light logo is mailed');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40"><rect width="120" height="40" fill="red"/></svg>';
        $this->upload('base', 'light', 1, $this->file($svg, 'logo.svg', 'image/svg+xml'));
        $this->assertNull($this->stored('logo_email_copy_path'), 'An SVG leaves emails on logo_path, as before 2.0');
        $this->assertFalse($this->disk()->exists($copy));

        $this->upload('base', 'light', 1);
        $copy = $this->stored('logo_email_copy_path');
        $this->assertNotNull($copy);
        $this->assertSame(200, $this->remove('base', 'light', 1));
        $this->assertNull($this->stored('logo_email_copy_path'), 'A removed logo is not mailed');
        $this->assertFalse($this->disk()->exists($copy));
    }

    #[Test]
    public function a_seasons_logo_is_returned_not_written_to_settings()
    {
        [$status, $body] = $this->upload('winter', 'light', 1);

        $this->assertSame(200, $status);
        $this->assertStringStartsWith('logo-manager-winter.light-', $body['filename']);
        $this->assertNull($this->stored('logo_path'));
        $this->assertTrue($this->disk()->exists($body['filename']));
    }

    #[Test]
    public function an_svg_is_stripped_of_script_before_it_is_stored()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40" onload="alert(1)">'
            .'<script>alert(document.cookie)</script>'
            .'<a href="javascript:alert(2)"><rect width="120" height="40" fill="red"/></a>'
            .'<image href="https://tracker.example/pixel.png"/></svg>';

        [$status, $body] = $this->upload('base', 'light', 1, $this->file($svg, 'logo.svg', 'image/svg+xml'));

        $this->assertSame(200, $status);
        $this->assertStringEndsWith('.svg', $body['filename']);

        $stored = $this->disk()->get($body['filename']);
        $this->assertStringContainsString('<rect', $stored);
        foreach (['<script', 'onload', 'javascript:', 'tracker.example'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $stored);
        }
        $this->assertStringContainsString('viewBox="0 0 120 40"', $stored, 'A viewBox is added so the logo keeps its shape');
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_refused()
    {
        // The assets disk outlives each test, so compare against what is there.
        $before = $this->disk()->files();

        [$status] = $this->upload('base', 'light', 1, []);
        $this->assertSame(422, $status, 'No file');

        [$status] = $this->upload('base', 'light', 1, $this->file('<?php echo "hi";', 'logo.png'));
        $this->assertSame(422, $status, 'The sniffed type, not the claimed one');

        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagebmp($image);
        [$status] = $this->upload('base', 'light', 1, $this->file((string) ob_get_clean(), 'logo.png'));
        $this->assertSame(422, $status, 'A real image, but not a type a logo may be');

        $this->assertNull($this->stored('logo_path'));
        $this->assertSame($before, $this->disk()->files(), 'Nothing written');
    }

    #[Test]
    public function the_forum_page_carries_the_logo_styles_unless_switched_off()
    {
        $this->upload('base', 'light', 1);

        $html = $this->page();
        $this->assertStringContainsString('<style id="logo-manager">', $html);

        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Config::ENABLED, '0');
        $this->assertStringNotContainsString('<style id="logo-manager">', $this->page());
    }

    #[Test]
    public function a_running_season_swaps_the_logo_without_touching_the_setting()
    {
        [, $base] = $this->upload('base', 'light', 1);
        [, $season] = $this->upload('always', 'light', 1);

        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Config::KEY, json_encode([
            'seasons' => ['enabled' => true, 'rules' => [[
                'id' => 'always', 'enabled' => true,
                'when' => ['type' => 'range', 'from' => '01-01', 'to' => '12-31'],
                'logo' => ['light' => $season['filename']],
            ]]],
        ]));

        $html = $this->page();
        $forum = $this->forumAttributes($html);

        $this->assertSame($season['url'], $forum['logoUrl']);
        $this->assertSame($season['url'], $forum['logoDarkModeUrl'], 'A light-only season covers dark mode too');
        $this->assertStringContainsString($season['filename'], $html);
        $this->assertSame($base['filename'], $this->stored('logo_path'), 'Nothing persisted');

        // Switched off, the extension changes nothing — not even mid-season.
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Config::ENABLED, '0');
        $this->assertSame($base['url'], $this->forumAttributes($this->page())['logoUrl']);
    }

    #[Test]
    public function the_admin_studio_gets_the_artwork_and_the_assets_base()
    {
        $html = $this->page('/admin', 1);

        preg_match('#<script id="flarum-json-payload" type="application/json">(.*?)</script>#s', $html, $m);
        $art = json_decode($m[1], true)['logoManagerArt'];

        $this->assertNotEmpty($art['decorations']);
        $this->assertNotEmpty($art['weather']);
        $this->assertStringEndsWith('/assets/', $art['assets']);
        $this->assertNull($art['activeSeason']);
    }
}
