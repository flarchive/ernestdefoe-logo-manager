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

/** Favicon, health report, preview, seasonal email logo, and backup. */
class ToolsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-logo-manager');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
        $this->config('debug', false);
    }

    private function upload(string $bytes, string $name = 'x.png', string $type = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lm');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, strlen($bytes), UPLOAD_ERR_OK, $name, $type);
    }

    private function png(int $width = 40, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10"><script>alert(1)</script><rect width="20" height="10"/></svg>';
    }

    private function post(string $path, array $files, int $actor = 1): array
    {
        $response = $this->send($this->request('POST', $path, ['authenticatedAs' => $actor])->withUploadedFiles($files));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function json(string $method, string $path, ?array $body = null, int $actor = 1): array
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => $actor, 'json' => $body ?? []]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function settings(): SettingsRepositoryInterface
    {
        return $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
    }

    private function disk()
    {
        return $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');
    }

    private function page(string $path = '/', ?int $actor = null): string
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query);

        return (string) $this->send($request)->getBody();
    }

    /**
     * An import writes each of its eight settings through the repository, one
     * upsert each. It is unavoidable — the repository has no batch write, and
     * going around it would leave a settings cache (fof/redis) stale — and it
     * runs once, when an administrator restores a backup.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['settings" ("key", "value")', 'settings` (`key`, `value`)'];
    }

    private function seasons(array $rule): void
    {
        $this->settings()->set(Config::KEY, json_encode(['seasons' => ['enabled' => true, 'rules' => [$rule + [
            'id' => 'always', 'name' => 'Always', 'enabled' => true,
            'when' => ['type' => 'range', 'from' => '01-01', 'to' => '12-31'],
        ]]]]));
    }

    #[Test]
    public function a_raster_favicon_becomes_a_full_set_in_cores_setting()
    {
        [$status, $body] = $this->post('/api/logo-manager/favicons/base', ['favicon' => $this->upload($this->png(300, 200))]);

        $this->assertSame(200, $status);
        $set = $body['set'];
        $this->assertSame($set['png'], $this->settings()->get('favicon_path'));

        foreach (['png', 'ico', 'touch'] as $key) {
            $this->assertTrue($this->disk()->exists($set[$key]), $key);
        }

        $ico = $this->disk()->get($set['ico']);
        $this->assertStringStartsWith("\x00\x00\x01\x00\x03\x00", $ico, 'Three sizes in the .ico');
        $this->assertSame([180, 180], array_slice(getimagesizefromstring($this->disk()->get($set['touch'])), 0, 2), 'Padded square');

        $html = $this->page();
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString($set['ico'], $html);
        $this->assertStringNotContainsString('rel="shortcut icon"', $html);

        // A later upload through core's own Appearance page wins.
        $this->settings()->set('favicon_path', 'favicon-core.png');
        $this->assertStringNotContainsString('apple-touch-icon', $this->page());
    }

    #[Test]
    public function an_svg_favicon_is_sanitised_and_gets_fallbacks_from_the_browsers_png()
    {
        [, $body] = $this->post('/api/logo-manager/favicons/base', [
            'favicon' => $this->upload($this->svg(), 'f.svg', 'image/svg+xml'),
            'raster' => $this->upload($this->png(512, 512)),
        ]);

        $set = $body['set'];
        $this->assertSame($set['svg'], $set['icon']);
        $this->assertStringNotContainsString('<script', $this->disk()->get($set['svg']));
        $this->assertNotNull($set['ico']);
        $this->assertStringContainsString('type="image/svg+xml"', $this->page());

        // Without a usable PNG from the browser, the SVG still works alone.
        [, $body] = $this->post('/api/logo-manager/favicons/base', [
            'favicon' => $this->upload($this->svg(), 'f.svg', 'image/svg+xml'),
            'raster' => $this->upload('<html>not a png</html>', 'x.png'),
        ]);
        $this->assertNull($body['set']['ico']);
        $this->assertFalse($this->disk()->exists($set['svg']), 'The previous set was swept');
    }

    #[Test]
    public function only_an_admin_reaches_the_tools()
    {
        $this->assertSame(403, $this->post('/api/logo-manager/favicons/base', ['favicon' => $this->upload($this->png())], 2)[0]);
        $this->assertSame(403, $this->json('GET', '/api/logo-manager/report', null, 2)[0]);
        $this->assertSame(403, $this->json('GET', '/api/logo-manager/backup', null, 2)[0]);
        $this->assertSame(403, $this->json('POST', '/api/logo-manager/backup', ['format' => 'x'], 2)[0]);
        $this->assertNull($this->settings()->get('favicon_path'));
    }

    #[Test]
    public function the_report_finds_missing_files_and_dead_seasons()
    {
        $this->settings()->set('logo_path', 'logo-gone.png');
        $this->settings()->set(Config::KEY, json_encode(['seasons' => ['enabled' => true, 'rules' => [
            ['id' => 'year', 'name' => 'Year', 'enabled' => true, 'when' => ['type' => 'range', 'from' => '01-01', 'to' => '12-31']],
            ['id' => 'xmas', 'name' => 'Xmas', 'enabled' => true, 'when' => ['type' => 'range', 'from' => '12-24', 'to' => '12-26']],
            ['id' => 'bad', 'name' => 'Bad', 'enabled' => true, 'when' => ['type' => 'range', 'from' => '13-45', 'to' => '01-02']],
        ]]]));

        [$status, $report] = $this->json('GET', '/api/logo-manager/report');
        $keys = array_map(fn ($i) => $i['key'].':'.($i['params']['season'] ?? $i['params']['slot'] ?? ''), $report['items']);

        $this->assertSame(200, $status);
        $this->assertContains('missing_file:logo_light', $keys);
        $this->assertContains('shadowed:Xmas', $keys);
        $this->assertContains('never_runs:Bad', $keys);
        $this->assertSame('year', $report['now']['id']);
        $this->assertSame('error', $report['items'][0]['level'], 'Errors first');
    }

    #[Test]
    public function only_an_admin_can_preview_a_season_on_the_forum()
    {
        $this->seasons(['enabled' => false, 'weather' => 'snow']);

        $this->assertStringNotContainsString('lm-weather', $this->page('/'), 'A disabled season does not run');

        $preview = $this->page('/?logo-manager-preview=always', 1);
        $this->assertStringContainsString('lm-weather', $preview);
        $this->assertStringContainsString('id="logo-manager-preview"', $preview);

        $guest = $this->page('/?logo-manager-preview=always');
        $this->assertStringNotContainsString('lm-weather', $guest);
        $this->assertStringNotContainsString('logo-manager-preview"', $guest);
    }

    #[Test]
    public function a_seasons_logo_goes_into_emails_while_it_runs()
    {
        if (! class_exists(EmailLogo::class)) {
            $this->markTestSkipped('No email logo before Flarum 2.0');
        }

        [, $body] = $this->post('/api/logo-manager/logos/always/light', ['logo' => $this->upload($this->png(200, 100))]);
        $this->assertNotNull($body['email']);
        $this->assertTrue($this->disk()->exists($body['email']['file']));

        $this->seasons(['logo' => ['light' => $body['filename']], 'email' => $body['email']]);
        $logo = $this->app()->getContainer()->make(EmailLogo::class)->resolve();
        $this->assertStringEndsWith($body['email']['file'], $logo['url']);
        $this->assertSame(60, $logo['height']);

        // A dedicated email logo set in core's Email settings still wins.
        $this->disk()->put('email-own.png', $this->png());
        $this->settings()->set(EmailLogo::PATH_KEY, 'email-own.png');
        $this->assertStringEndsWith('email-own.png', $this->app()->getContainer()->make(EmailLogo::class)->resolve()['url']);
    }

    #[Test]
    public function an_svg_logo_gets_an_email_copy_from_the_browsers_png()
    {
        if (! class_exists(EmailLogo::class)) {
            $this->markTestSkipped('No email logo before Flarum 2.0');
        }

        $this->post('/api/logo-manager/logos/base/light', [
            'logo' => $this->upload($this->svg(), 'l.svg', 'image/svg+xml'),
            'raster' => $this->upload($this->png(480, 240)),
        ]);

        $copy = $this->settings()->get(EmailLogo::COPY_PATH_KEY);
        $this->assertNotNull($copy);
        $this->assertStringEndsWith('.png', $copy);
        $this->assertSame('240x120', $this->settings()->get(EmailLogo::COPY_SIZE_KEY));
    }

    #[Test]
    public function a_backup_carries_its_files_and_restores_them()
    {
        [, $logo] = $this->post('/api/logo-manager/logos/base/light', ['logo' => $this->upload($this->png(80, 40))]);
        [, $season] = $this->post('/api/logo-manager/logos/always/light', ['logo' => $this->upload($this->svg(), 's.svg', 'image/svg+xml')]);
        $this->seasons(['logo' => ['light' => $season['filename']], 'weather' => 'snow']);

        [$status, $backup] = $this->json('GET', '/api/logo-manager/backup');
        $this->assertSame(200, $status);
        $this->assertArrayHasKey($logo['filename'], $backup['files']);
        $this->assertArrayHasKey($season['filename'], $backup['files']);

        // Wipe, then restore.
        $this->disk()->delete([$logo['filename'], $season['filename']]);
        $this->settings()->set(Config::KEY, '{}');
        $this->settings()->set('logo_path', null);

        [$status, $result] = $this->json('POST', '/api/logo-manager/backup', $backup);
        $this->assertSame(200, $status);
        $this->assertSame(3, $result['files'], 'Both logos and the email copy of the permanent one');
        $this->assertSame($logo['filename'], $this->settings()->get('logo_path'));
        $this->assertTrue($this->disk()->exists($season['filename']));
        $this->assertStringContainsString('lm-weather', $this->page());
    }

    #[Test]
    public function an_import_is_checked_like_an_upload_and_all_or_nothing()
    {
        $base = ['format' => 'ernestdefoe/logo-manager', 'version' => 1, 'config' => ['hover' => 'spin'], 'settings' => []];

        // A script inside an SVG is stripped on the way in.
        $this->json('POST', '/api/logo-manager/backup', $base + ['files' => ['logo-manager-base.light-aa.svg' => base64_encode($this->svg())]]);
        $this->assertStringNotContainsString('<script', $this->disk()->get('logo-manager-base.light-aa.svg'));

        $refused = [
            'path' => ['../../evil.png' => base64_encode($this->png())],
            'type' => ['logo-x.php' => base64_encode('<?php echo 1;')],
            'lie' => ['logo-x.png' => base64_encode('<html><script>x</script></html>')],
        ];

        foreach ($refused as $why => $files) {
            $this->settings()->set(Config::KEY, '{"hover":"lift"}');
            [$status] = $this->json('POST', '/api/logo-manager/backup', $base + ['files' => ['logo-ok.png' => base64_encode($this->png())] + $files]);

            $this->assertSame(422, $status, $why);
            $this->assertFalse($this->disk()->exists('logo-ok.png'), "$why: nothing written");
            $this->assertSame('{"hover":"lift"}', $this->settings()->get(Config::KEY), "$why: settings untouched");
        }

        $this->assertSame(422, $this->json('POST', '/api/logo-manager/backup', ['format' => 'other'])[0]);
    }

    #[Test]
    public function an_ico_favicon_is_stored_as_sent_only_when_well_formed()
    {
        $png = $this->png(32, 32);
        $ico = \Ernestdefoe\LogoManager\Logo\Favicons::ico([$png]);

        [$status, $body] = $this->post('/api/logo-manager/favicons/base', ['favicon' => $this->upload($ico, 'f.ico', 'image/x-icon')]);
        $this->assertSame(200, $status);
        $this->assertSame($ico, $this->disk()->get($body['set']['ico']));

        // An .ico header pointing past the end of the file is not an icon.
        $forged = substr($ico, 0, 22).'<html><script>alert(1)</script></html>';
        $this->assertSame(422, $this->post('/api/logo-manager/favicons/base', ['favicon' => $this->upload($forged, 'f.ico', 'image/x-icon')])[0]);
    }
}
