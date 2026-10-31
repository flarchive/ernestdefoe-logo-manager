<?php

namespace Ernestdefoe\LogoManager\Logo;

use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The browser-tab icon, in every format a browser might ask for.
 *
 * Core's favicon field takes one file and scales it to a 64px PNG. A modern
 * page wants three: an SVG for browsers that draw one (sharp at any size and
 * able to follow dark mode), a `.ico` for everything else including the
 * `/favicon.ico` a feed reader guesses at, and a 180px PNG the iOS home
 * screen uses. From one upload this writes all of them.
 *
 * An SVG cannot be turned into pixels here — the GD driver core uses does
 * not read SVG, and handing SVG to ImageMagick's delegates is a known attack
 * surface even after sanitising. The admin's own browser draws the cleaned
 * SVG onto a canvas and sends that PNG alongside, and it is decoded and
 * re-encoded here like any other upload.
 */
class Favicons
{
    /** The sizes packed into the `.ico`. */
    public const ICO_SIZES = [16, 32, 48];

    public const TOUCH_SIZE = 180;

    /** Core's own favicon size, kept so link previews look as they did. */
    public const PNG_SIZE = 64;

    public const MAX_ICO_BYTES = 256 * 1024;

    public function __construct(protected LogoStore $store)
    {
    }

    /**
     * Store one upload as a full set and return it.
     *
     * @return array{icon:string,svg:?string,png:?string,ico:?string,touch:?string}
     *
     * @throws RuntimeException with a translation key
     */
    public function store(string $scope, UploadedFileInterface $file, ?UploadedFileInterface $raster): array
    {
        $bytes = (string) $file->getStream();
        $random = bin2hex(random_bytes(4));
        $name = fn (string $suffix) => $this->store->name($scope, 'favicon', $suffix, $random);
        $files = [];
        $set = ['icon' => '', 'svg' => null, 'png' => null, 'ico' => null, 'touch' => null];

        if (self::isIco($bytes)) {
            if (strlen($bytes) > self::MAX_ICO_BYTES) {
                throw new RuntimeException('too_large_raster');
            }

            // An .ico is stored as sent: nothing in core's image stack reads
            // one, and it is served as an image, never as a document.
            $files[$set['ico'] = $name('.ico')] = $bytes;
            $set['icon'] = $set['ico'];
        } elseif ($this->store->isSvg($bytes)) {
            $files[$set['svg'] = $name('.svg')] = $this->store->cleanSvg($bytes);
            $set['icon'] = $set['svg'];

            if ($image = $this->store->clientRaster($raster)) {
                $files += $this->rasters($image, $name, $set);
            }
        } else {
            $image = $this->store->decode($bytes);
            $animated = $image->isAnimated();

            if ($animated) {
                // An animated icon stays a GIF where browsers will animate it,
                // and the still formats take its first frame.
                $files[$set['png'] = $name('.gif')] = (string) $image->scaleDown(self::PNG_SIZE, self::PNG_SIZE)->toGif();
                $image = $this->store->decode($bytes)->removeAnimation(0);
            }

            $files += $this->rasters($image, $name, $set, ! $animated);
            $set['icon'] = $set['png'];
        }

        $this->store->forget($scope, 'favicon');

        foreach ($files as $path => $contents) {
            $this->store->write($path, $contents);
        }

        return $set;
    }

    public function forget(string $scope): void
    {
        $this->store->forget($scope, 'favicon');
    }

    /** The `<link>` tags for a set, or null when there is nothing to say beyond core's own. */
    public function links(array $set): ?string
    {
        $url = fn (?string $file) => $file === null ? null : e($this->store->url($file));
        $html = '';

        if ($ico = $url($set['ico'] ?? null)) {
            $html .= '<link rel="icon" href="'.$ico.'" sizes="any">';
        }

        if ($svg = $url($set['svg'] ?? null)) {
            $html .= '<link rel="icon" href="'.$svg.'" type="image/svg+xml">';
        } elseif ($png = $url($set['png'] ?? null)) {
            $type = str_ends_with($set['png'], '.gif') ? 'image/gif' : 'image/png';
            $html .= '<link rel="icon" href="'.$png.'" type="'.$type.'">';
        }

        if ($touch = $url($set['touch'] ?? null)) {
            $html .= '<link rel="apple-touch-icon" href="'.$touch.'">';
        }

        return $html === '' ? null : $html;
    }

    /**
     * The PNG, the touch icon and the `.ico` from one decoded image.
     *
     * @return array<string,string> filename => bytes
     */
    protected function rasters(ImageInterface $image, callable $name, array &$set, bool $png = true): array
    {
        $source = (string) $image->toPng();
        $square = fn (int $size) => (string) $this->store->decode($source, ['image/png'])
            ->contain($size, $size, 'rgba(0, 0, 0, 0)')
            ->toPng();

        $files = [];

        if ($png) {
            $files[$set['png'] = $name('-'.self::PNG_SIZE.'.png')] = $square(self::PNG_SIZE);
        }

        $files[$set['touch'] = $name('-'.self::TOUCH_SIZE.'.png')] = $square(self::TOUCH_SIZE);
        $files[$set['ico'] = $name('.ico')] = self::ico(array_map($square, self::ICO_SIZES));

        return $files;
    }

    /**
     * Whether some bytes are a well-formed `.ico`: the header, a sane number
     * of entries, and every entry's image lying inside the file. An `.ico` is
     * stored as sent, so this is the whole of its check.
     */
    public static function isIco(string $bytes): bool
    {
        $length = strlen($bytes);

        if ($length < 22 || ! str_starts_with($bytes, "\x00\x00\x01\x00")) {
            return false;
        }

        $count = unpack('v', $bytes, 4)[1];

        if ($count < 1 || $count > 64 || 6 + 16 * $count > $length) {
            return false;
        }

        for ($i = 0; $i < $count; $i++) {
            ['size' => $size, 'offset' => $offset] = unpack('Vsize/Voffset', $bytes, 6 + 16 * $i + 8);

            if ($size < 1 || $offset < 6 + 16 * $count || $offset + $size > $length) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pack PNG images into one `.ico`.
     *
     * Every browser since Windows Vista's era reads PNG-compressed entries, so
     * the file is a 6-byte header, a 16-byte directory entry per image, then
     * the PNGs themselves, unchanged.
     *
     * @param string[] $pngs
     */
    public static function ico(array $pngs): string
    {
        $header = pack('vvv', 0, 1, count($pngs));
        $directory = '';
        $data = '';
        $offset = 6 + 16 * count($pngs);

        foreach ($pngs as $png) {
            [$width, $height] = getimagesizefromstring($png) ?: [0, 0];
            $directory .= pack('CCCCvvVV', $width >= 256 ? 0 : $width, $height >= 256 ? 0 : $height, 0, 0, 1, 32, strlen($png), $offset + strlen($data));
            $data .= $png;
        }

        return $header.$directory.$data;
    }
}
