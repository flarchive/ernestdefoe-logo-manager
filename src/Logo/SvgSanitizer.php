<?php

namespace Ernestdefoe\LogoManager\Logo;

use DOMDocument;
use enshrined\svgSanitize\Sanitizer;

/**
 * Cleans an uploaded SVG before it is ever written to disk.
 *
 * 🚨 This is not optional and it is not about malformed files. An SVG served
 * from the forum's own domain is a document on the forum's own origin: it can
 * carry `<script>`, `onload=`, a `javascript:` href, or a `<foreignObject>`
 * full of HTML. The header renders the logo through an `<img>`, where none of
 * that executes — but the file also sits at a plain URL under /assets, and
 * anything that gets somebody to open that URL directly is running script as
 * the forum. That is stored XSS, reachable by anyone who can persuade an
 * administrator to upload a "logo".
 *
 * Core sidesteps all of this by refusing SVG outright and re-encoding every
 * upload through Intervention. Accepting SVG means taking on the job core
 * declined, so the file is parsed, stripped of every element and attribute
 * outside the sanitiser's allow-list, and stripped of remote references —
 * which also stops a logo quietly reporting every page view to a third party.
 */
class SvgSanitizer
{
    /** Cleaned SVG source, or null when the file is not usable SVG at all. */
    public function clean(string $dirty): ?string
    {
        $sanitizer = new Sanitizer();

        // A logo has no business fetching anything: this drops external
        // `use` targets, remote images and remote stylesheets, which are both
        // a privacy leak and a way to change what the logo shows later.
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);

        $clean = $sanitizer->sanitize($dirty);

        if ($clean === false || trim($clean) === '') {
            return null;
        }

        return $this->ensureViewBox($clean);
    }

    /**
     * Guarantee a viewBox on the root element.
     *
     * The header sizes the logo with `height: Npx; width: auto`, which needs
     * an intrinsic aspect ratio to work out the width from. An SVG with no
     * viewBox has none, so the browser falls back to the replaced-element
     * default of 300x150 and the logo renders squashed into a 2:1 box. Most
     * exports include a viewBox; the ones that carry only `width`/`height`
     * are the ones that would otherwise look broken for no visible reason.
     */
    protected function ensureViewBox(string $svg): string
    {
        $document = new DOMDocument();

        // 🚨 LIBXML_NONET, and never LIBXML_NOENT: entity substitution is what
        // turns a parse into an XXE file read. The sanitiser above has already
        // run, so this is defence in depth rather than the only guard.
        $loaded = @$document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if (! $loaded || ! $document->documentElement) {
            return $svg;
        }

        $root = $document->documentElement;

        if ($root->hasAttribute('viewBox') || ! $root->hasAttribute('width') || ! $root->hasAttribute('height')) {
            return $svg;
        }

        $width = $this->length($root->getAttribute('width'));
        $height = $this->length($root->getAttribute('height'));

        if ($width === null || $height === null) {
            return $svg;
        }

        $root->setAttribute('viewBox', "0 0 $width $height");

        return $document->saveXML() ?: $svg;
    }

    /** A CSS length like "120" or "120px" as a number, ignoring other units. */
    protected function length(string $value): ?float
    {
        if (! preg_match('/^\s*([0-9]*\.?[0-9]+)\s*(px)?\s*$/i', $value, $match)) {
            return null;
        }

        $number = (float) $match[1];

        return $number > 0 ? $number : null;
    }
}
