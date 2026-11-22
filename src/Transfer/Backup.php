<?php

namespace Ernestdefoe\LogoManager\Transfer;

use Ernestdefoe\LogoManager\Config;
use Ernestdefoe\LogoManager\Logo\Favicons;
use Ernestdefoe\LogoManager\Logo\LogoStore;
use Ernestdefoe\LogoManager\Season\Season;
use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * The whole setup as one JSON file, files included.
 *
 * Settings alone would not survive the trip from a local copy to the live
 * forum — every logo, favicon and seasonal image would point at a file the
 * other forum has never had. So the files travel inside it, base64-encoded,
 * and an import puts them back under the same names.
 *
 * 🚨 An import is an upload. Every file in it goes through the same checks
 * as one sent from the studio — SVG sanitised, raster sniffed and measured
 * before decoding — and a name must be one this extension could have
 * written, so a crafted file cannot land anywhere else on the assets disk.
 */
class Backup
{
    public const FORMAT = 'ernestdefoe/logo-manager';

    public const VERSION = 1;

    public const MAX_FILES = 200;

    /** Settings carried as they are. Core's own keys are the ones this extension writes. */
    public const SETTINGS = [
        Config::ENABLED,
        Config::FAVICON,
        'logo_path',
        'logo_dark_mode_path',
        'favicon_path',
        'logo_email_copy_path',
        'logo_email_copy_size',
    ];

    protected const EXTENSIONS = [
        'svg' => null,
        'ico' => null,
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config,
        protected LogoStore $store,
    ) {
    }

    public function export(): array
    {
        $settings = [];

        foreach (self::SETTINGS as $key) {
            $settings[$key] = $this->settings->get($key);
        }

        $files = [];

        foreach ($this->referenced($settings) as $name) {
            if (($bytes = $this->store->read($name)) !== null) {
                $files[$name] = base64_encode($bytes);
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported' => gmdate(DATE_ATOM),
            'config' => $this->config->raw(),
            'settings' => $settings,
            'files' => $files,
        ];
    }

    /**
     * @return int how many files were written
     *
     * @throws RuntimeException with a translation key; nothing is written
     */
    public function import(mixed $data): int
    {
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION
            || ! is_array($data['config'] ?? null) || ! is_array($data['settings'] ?? null) || ! is_array($data['files'] ?? [])) {
            throw new RuntimeException('import_invalid');
        }

        $files = $data['files'] ?? [];

        if (count($files) > self::MAX_FILES) {
            throw new RuntimeException('import_invalid');
        }

        // Every file is checked before any is written, so a bad one leaves
        // the forum exactly as it was.
        $clean = [];

        foreach ($files as $name => $encoded) {
            $clean[$name] = $this->check((string) $name, $encoded);
        }

        foreach ($clean as $name => $bytes) {
            $this->store->write($name, $bytes);
        }

        $this->config->write($data['config']);

        foreach (self::SETTINGS as $key) {
            $value = $data['settings'][$key] ?? null;
            $this->settings->set($key, $this->settingValue($key, $value));
        }

        return count($clean);
    }

    protected function settingValue(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($key) {
            Config::ENABLED => (bool) $value ? '1' : '0',
            Config::FAVICON => ($set = $this->config->faviconSet(is_string($value) ? json_decode($value, true) : $value)) ? json_encode($set) : null,
            'logo_email_copy_size' => is_string($value) && preg_match('/^\d{1,5}x\d{1,5}$/', $value) ? $value : null,
            default => is_string($value) && $this->config->filename($value) !== null && $this->store->exists($value) ? $value : null,
        };
    }

    /** @throws RuntimeException */
    protected function check(string $name, mixed $encoded): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($this->config->filename($name) === null || ! array_key_exists($extension, self::EXTENSIONS) || $bytes === false) {
            throw new RuntimeException('import_invalid');
        }

        if ($extension === 'svg') {
            return $this->store->cleanSvg($bytes);
        }

        if ($extension === 'ico') {
            return Favicons::isIco($bytes) && strlen($bytes) <= Favicons::MAX_ICO_BYTES ? $bytes : throw new RuntimeException('import_invalid');
        }

        // Decoded and re-encoded, like an upload: what is written is what the
        // image library drew, never the bytes as they arrived.
        $image = $this->store->decode($bytes, self::EXTENSIONS[$extension]);

        return (string) match ($extension) {
            'png' => $image->toPng(),
            'gif' => $image->toGif(),
            'webp' => $image->toWebp(90),
            default => $image->toJpeg(90),
        };
    }

    /** @return string[] every file the settings and the configuration point at */
    protected function referenced(array $settings): array
    {
        $names = [
            $settings['logo_path'],
            $settings['logo_dark_mode_path'],
            $settings['favicon_path'],
            $settings['logo_email_copy_path'],
        ];

        $names = array_merge($names, array_values($this->config->faviconSet(json_decode((string) $settings[Config::FAVICON], true)) ?? []));

        foreach ($this->config->seasonRules() as $rule) {
            $season = new Season($rule, $this->config);
            $names[] = $season->logo('light');
            $names[] = $season->logo('dark');
            $names[] = $season->email()['file'] ?? null;
            $names = array_merge($names, array_values($season->favicon() ?? []));
        }

        return array_values(array_unique(array_filter($names, fn ($name) => is_string($name) && $this->config->filename($name) !== null)));
    }
}
