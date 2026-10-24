<?php

namespace Ernestdefoe\LogoManager\Logo;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Reads and writes the logo files.
 *
 * Files live on the `flarum-assets` disk, where core already keeps the logo
 * and favicon, so they are served by the web server and are not swept away by
 * a cache clear.
 *
 * The important difference from core is what happens to a raster upload.
 * `UploadLogoController` scales every logo to `height: 60` and re-encodes it
 * as WebP — which is why a large logo looks soft the moment it is displayed
 * any bigger than core's own 30px. Since this extension exists to display it
 * bigger, it keeps enough resolution to do that: twice the largest height the
 * settings allow, so the logo is still sharp on a 2x display.
 */
class LogoStore
{
    /** Twice the 300px ceiling on logo height, for high-density screens. */
    public const MAX_RASTER_HEIGHT = 600;

    public const MAX_SVG_BYTES = 512 * 1024;

    public const MAX_RASTER_BYTES = 8 * 1024 * 1024;

    /** Matches core's own guard against a decompression bomb. */
    public const MAX_RESOLUTION = 24_000_000;

    public const RASTER_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    protected Cloud $disk;

    public function __construct(
        Factory $filesystem,
        protected ImageManager $images,
        protected SvgSanitizer $svg,
    ) {
        $disk = $filesystem->disk('flarum-assets');

        // url() is on Cloud, not the base contract; core types this disk the
        // same way (Frontend\Assets, ExtensionManager).
        if (! $disk instanceof Cloud) {
            throw new RuntimeException('The flarum-assets disk cannot produce public URLs.');
        }

        $this->disk = $disk;
    }

    /**
     * Store an upload for one slot and return the filename written.
     *
     * @throws RuntimeException with a translation key when the file is not
     *                          something we are willing to store.
     */
    public function put(string $scope, string $variant, UploadedFileInterface $file): string
    {
        $bytes = (string) $file->getStream();

        // The sniffed type, never the one the browser claimed. A client can
        // say anything; `finfo` reads the file.
        [$contents, $extension] = $this->isSvg($bytes)
            ? [$this->cleanSvg($bytes), 'svg']
            : $this->prepareRaster($bytes);

        $name = $this->name($scope, $variant, '.'.$extension);

        $this->forget($scope, $variant);
        $this->disk->put($name, $contents);

        return $name;
    }

    /** A fresh filename in one slot: the slot's prefix, a random part, then `$suffix`. */
    public function name(string $scope, string $variant, string $suffix, ?string $random = null): string
    {
        return $this->prefix($scope, $variant).($random ?? bin2hex(random_bytes(4))).$suffix;
    }

    public function write(string $name, string $contents): void
    {
        $this->disk->put($name, $contents);
    }

    public function read(string $path): ?string
    {
        return $this->disk->exists($path) ? (string) $this->disk->get($path) : null;
    }

    /** Whether some bytes are an SVG, by sniffing rather than by name. */
    public function isSvg(string $bytes): bool
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return $type === 'image/svg+xml' || $this->looksLikeSvg($bytes, $type);
    }

    /**
     * A cleaned SVG.
     *
     * @throws RuntimeException with a translation key
     */
    public function cleanSvg(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_SVG_BYTES) {
            throw new RuntimeException('too_large_svg');
        }

        return $this->svg->clean($bytes) ?? throw new RuntimeException('invalid_svg');
    }

    /**
     * Decode a raster image after the same checks an upload gets: a type we
     * accept, a size cap, and the pixel count read from the header before
     * any decoder allocates a buffer for it.
     *
     * @throws RuntimeException with a translation key
     */
    public function decode(string $bytes, array $types = self::RASTER_TYPES): ImageInterface
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        if (! in_array($type, $types, true)) {
            throw new RuntimeException('unsupported_type');
        }

        if (strlen($bytes) > self::MAX_RASTER_BYTES) {
            throw new RuntimeException('too_large_raster');
        }

        // Checked from the header, before anything decodes the image: a
        // decoder allocates a buffer for width x height whatever the
        // compressed file weighs, so a small file declaring vast dimensions
        // is a way to exhaust memory.
        $dimensions = @getimagesizefromstring($bytes);

        if ($dimensions === false) {
            throw new RuntimeException('invalid_image');
        }

        if ($dimensions[0] * $dimensions[1] > self::MAX_RESOLUTION) {
            throw new RuntimeException('too_many_pixels');
        }

        return $this->images->read($bytes);
    }

    /**
     * The PNG the admin's browser drew from an SVG, for the places an SVG
     * cannot go (mail clients, `.ico`, the iOS home screen).
     *
     * 🚨 Untrusted bytes like any upload: it must sniff as PNG and pass the
     * same pixel checks, and it is decoded and re-encoded rather than stored.
     */
    public function clientRaster(?UploadedFileInterface $file): ?ImageInterface
    {
        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        try {
            return $this->decode((string) $file->getStream(), ['image/png']);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** Remove every file whose name starts with one slot's prefix. */
    public function forgetPrefix(string $prefix): void
    {
        foreach ($this->disk->files() as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                $this->disk->delete($file);
            }
        }
    }

    /**
     * 🚨 `finfo` reports plain SVG as `image/svg+xml` only when the file has
     * an XML declaration or a doctype. A file that opens straight at `<svg`
     * — which most hand-written and most exported logos do — comes back as
     * `text/plain` or `text/html`, and would be rejected as "not an image"
     * while looking perfectly valid to the person uploading it.
     *
     * Only the text types get the second look; a real PNG never reaches here.
     */
    protected function looksLikeSvg(string $bytes, string $type): bool
    {
        if (! in_array($type, ['text/plain', 'text/html', 'text/xml', 'application/xml', ''], true)) {
            return false;
        }

        return (bool) preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096));
    }

    /** @return array{0:string,1:string} the encoded bytes and the extension. */
    protected function prepareRaster(string $bytes): array
    {
        $image = $this->decode($bytes)->scaleDown(height: self::MAX_RASTER_HEIGHT);

        // An animated logo stays a GIF. Re-encoding it to WebP the way core
        // does is fine for a still, but it is the only format here that can
        // lose the animation entirely.
        return $image->isAnimated()
            ? [(string) $image->toGif(), 'gif']
            : [(string) $image->toWebp(90), 'webp'];
    }

    /**
     * Remove every file belonging to one slot.
     *
     * Swept by prefix rather than by the recorded filename: an upload that
     * writes its file and then fails before the configuration is saved leaves
     * an orphan nothing has a record of, and going by prefix means the next
     * upload clears it.
     */
    public function forget(string $scope, string $variant): void
    {
        $this->forgetPrefix($this->prefix($scope, $variant));
    }

    public function delete(?string $path): void
    {
        if ($path && $this->disk->exists($path)) {
            $this->disk->delete($path);
        }
    }

    public function url(string $path): string
    {
        return $this->disk->url($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    /**
     * 🚨 The scope and the variant are joined with a dot, which a season id
     * cannot contain.
     *
     * With a hyphen, a season called `winter` and a season called
     * `winter-light` produce the prefixes `…-winter-light-` and
     * `…-winter-light-light-` — the first is a prefix of the second, so
     * re-uploading one season's logo would delete the other's. The separator
     * is doing real work.
     */
    public function prefix(string $scope, string $variant): string
    {
        $scope = preg_replace('/[^a-z0-9-]/', '', strtolower($scope)) ?: 'base';
        $variant = in_array($variant, ['dark', 'email', 'favicon'], true) ? $variant : 'light';

        return "logo-manager-$scope.$variant-";
    }
}
