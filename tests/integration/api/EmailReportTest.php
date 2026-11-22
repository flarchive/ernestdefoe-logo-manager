<?php

namespace Ernestdefoe\LogoManager\Tests\integration\api;

use Ernestdefoe\LogoManager\Config;
use Flarum\Mail\EmailLogo;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Report's view of the email logo: which one is actually mailed, and a
 * copy whose file has gone (discuss.flarum.org/d/39835/19).
 */
class EmailReportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(EmailLogo::class)) {
            $this->markTestSkipped('No email logo before Flarum 2.0');
        }

        $this->extension('ernestdefoe-logo-manager');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
        $this->config('debug', false);
    }

    /** A copy repaired in place writes its path and size: two upserts, once. */
    protected function allowedRepeatedQueries(): array
    {
        return ['settings" ("key", "value")', 'settings` (`key`, `value`)'];
    }

    private function upload(string $bytes, string $name = 'x.png', string $type = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lm');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, strlen($bytes), UPLOAD_ERR_OK, $name, $type);
    }

    private function png(int $width = 200, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10"><rect width="20" height="10"/></svg>';
    }

    private function post(string $path, array $files, int $actor = 1): array
    {
        $response = $this->send($this->request('POST', $path, ['authenticatedAs' => $actor])->withUploadedFiles($files));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function report(): array
    {
        $response = $this->send($this->request('GET', '/api/logo-manager/report', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['items'];
    }

    private function keys(array $items): array
    {
        return array_map(fn ($i) => $i['level'].':'.$i['key'].(isset($i['params']['season']) ? ':'.$i['params']['season'] : ''), $items);
    }

    private function settings(): SettingsRepositoryInterface
    {
        return $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
    }

    private function disk()
    {
        return $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');
    }

    private function logo(string $bytes, string $name = 'x.png', string $type = 'image/png', ?string $raster = null, string $scope = 'base'): array
    {
        $files = ['logo' => $this->upload($bytes, $name, $type)];

        if ($raster !== null) {
            $files['raster'] = $this->upload($raster);
        }

        return $this->post("/api/logo-manager/logos/$scope/light", $files)[1];
    }

    private function season(array $rule): void
    {
        $this->settings()->set(Config::KEY, json_encode(['seasons' => ['enabled' => true, 'rules' => [$rule + [
            'id' => 'always', 'name' => 'Always', 'enabled' => true,
            'when' => ['type' => 'range', 'from' => '01-01', 'to' => '12-31'],
        ]]]]));
    }

    #[Test]
    public function an_email_logo_from_the_appearance_page_wins_so_a_missing_copy_is_not_an_error()
    {
        $this->logo($this->png());
        $this->disk()->put('email-logo-own.png', $this->png());
        $this->settings()->set(EmailLogo::PATH_KEY, 'email-logo-own.png');
        $this->settings()->set(EmailLogo::COPY_PATH_KEY, 'logo-email-tg4hjgki.png');

        $items = $this->report();

        $this->assertSame(['info:email_uses_settings'], $this->keys($items));
        $this->assertStringNotContainsString('tg4hjgki', json_encode($items), 'No internal filename');

        // The fallback copy was quietly put back all the same.
        $copy = $this->settings()->get(EmailLogo::COPY_PATH_KEY);
        $this->assertNotSame('logo-email-tg4hjgki.png', $copy);
        $this->assertTrue($this->disk()->exists($copy));
    }

    #[Test]
    public function a_missing_email_logo_from_the_appearance_page_points_there()
    {
        $this->settings()->set(EmailLogo::PATH_KEY, 'email-logo-gone.png');

        $this->assertSame(['error:email_settings_missing_file'], $this->keys($this->report()));
    }

    #[Test]
    public function a_raster_logos_missing_copy_is_made_again_from_the_logo()
    {
        $this->logo($this->png());
        $old = $this->settings()->get(EmailLogo::COPY_PATH_KEY);
        $this->disk()->delete($old);

        $this->assertSame(['info:email_remade'], $this->keys($this->report()));

        $copy = $this->settings()->get(EmailLogo::COPY_PATH_KEY);
        $this->assertTrue($this->disk()->exists($copy));
        $this->assertSame([200, 100], array_slice(getimagesizefromstring($this->disk()->get($copy)), 0, 2));
        $this->assertSame('200x100', $this->settings()->get(EmailLogo::COPY_SIZE_KEY));

        $this->assertSame([], $this->report(), 'Healed once, then clean');
    }

    #[Test]
    public function an_svg_logos_stale_copy_is_cleared_and_the_browser_makes_a_new_one()
    {
        $this->logo($this->svg(), 'l.svg', 'image/svg+xml');
        $this->settings()->set(EmailLogo::COPY_PATH_KEY, 'logo-email-tg4hjgki.png');

        $items = $this->report();

        $this->assertSame(['warning:email_svg_no_copy'], $this->keys($items));
        $this->assertNull($this->settings()->get(EmailLogo::COPY_PATH_KEY), 'The stale row is gone');
        $this->assertSame('base', $items[0]['action']['scope']);
        $this->assertStringContainsString('<svg', $items[0]['action']['svg']);

        $this->assertSame(403, $this->post('/api/logo-manager/email-copy/base', ['raster' => $this->upload($this->png(240, 120))], 2)[0]);
        $this->assertSame(422, $this->post('/api/logo-manager/email-copy/base', ['raster' => $this->upload('<html></html>')])[0]);

        [$status] = $this->post('/api/logo-manager/email-copy/base', ['raster' => $this->upload($this->png(240, 120))]);

        $this->assertSame(200, $status);
        $this->assertTrue($this->disk()->exists($this->settings()->get(EmailLogo::COPY_PATH_KEY)));
        $this->assertSame([], $this->report());
    }

    #[Test]
    public function the_button_refuses_a_logo_that_is_not_an_svg()
    {
        $this->logo($this->png());

        $this->assertSame(422, $this->post('/api/logo-manager/email-copy/base', ['raster' => $this->upload($this->png())])[0]);
    }

    /**
     * Upgrading from 1.0.x: an SVG logo there had its copy deleted, and a
     * WebP logo uploaded before 1.0.3 never had one.
     */
    #[Test]
    public function upgrading_from_1_0_leaves_nothing_saying_upload_it_again()
    {
        $this->logo($this->svg(), 'l.svg', 'image/svg+xml');
        $this->settings()->set(EmailLogo::COPY_PATH_KEY, null);

        $this->assertSame(['warning:email_svg_no_copy'], $this->keys($this->report()));

        $this->logo($this->png());
        $this->settings()->set(EmailLogo::COPY_PATH_KEY, null);

        $this->assertSame(['info:email_remade'], $this->keys($this->report()));
        $this->assertTrue($this->disk()->exists($this->settings()->get(EmailLogo::COPY_PATH_KEY)));
    }

    #[Test]
    public function a_seasons_missing_email_logo_is_rewritten_under_its_recorded_name()
    {
        $body = $this->logo($this->png(), scope: 'always');
        $this->season(['logo' => ['light' => $body['filename']], 'email' => $body['email']]);
        $this->disk()->delete($body['email']['file']);

        $this->assertSame(['info:email_remade:Always'], $this->keys($this->report()));
        $this->assertTrue($this->disk()->exists($body['email']['file']), 'Same name, so the saved configuration stays right');

        // An SVG season: the browser draws it.
        $body = $this->logo($this->svg(), 'l.svg', 'image/svg+xml', $this->png(240, 120), 'always');
        $this->season(['logo' => ['light' => $body['filename']], 'email' => $body['email']]);
        $this->disk()->delete($body['email']['file']);

        $items = $this->report();
        $this->assertSame(['error:email_svg_missing:Always'], $this->keys($items));
        $this->assertSame('always', $items[0]['action']['scope']);

        $this->assertSame(200, $this->post('/api/logo-manager/email-copy/always', ['raster' => $this->upload($this->png(240, 120))])[0]);
        $this->assertTrue($this->disk()->exists($body['email']['file']));

        // A season's email logo is not mailed behind one from the Appearance page.
        $this->disk()->delete($body['email']['file']);
        $this->disk()->put('email-logo-own.png', $this->png());
        $this->settings()->set(EmailLogo::PATH_KEY, 'email-logo-own.png');
        $this->assertSame(['info:email_uses_settings'], $this->keys($this->report()));
    }
}
