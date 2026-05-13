<?php

declare(strict_types=1);

namespace App\Services\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Whitelist-based HTML sanitizer for user-generated content (comments,
 * chat, rich-text fields).
 *
 * Implementation goals (per the swarm-26 XSS audit):
 *  - Pure PHP, no external dependency (mews/purifier is NOT installed
 *    and we keep the composer footprint minimal).
 *  - Allow legitimate inline formatting (`<strong>`, `<em>`, links)
 *    so users can express emphasis without being neutered into plain
 *    text.
 *  - Strip every dangerous tag (`<script>`, `<style>`, `<iframe>`,
 *    `<object>`, `<embed>`, `<form>`, `<input>`, etc.) and every
 *    attribute that isn't on the per-tag allow list.
 *  - Validate `href` on `<a>` against http:// and https:// only — no
 *    `javascript:`, `data:`, `vbscript:`, or schemeless smart-quote
 *    injection.
 *  - Drop every event handler attribute (`on*`) regardless of tag.
 *
 * Notes on DOMDocument:
 *  - `loadHTML()` whines about HTML5 tags and unknown attributes; we
 *    suppress with `LIBXML_NOERROR | LIBXML_NOWARNING`.
 *  - We wrap the input in a `<body>` so the parser doesn't promote it
 *    into an implicit `<html><head>` shell that we'd then have to
 *    unwrap.
 *  - We force UTF-8 with the `<?xml encoding="UTF-8" ?>` prologue trick
 *    so Indonesian diacritics + Arabic harakat survive the round trip.
 */
final class HtmlSanitizer
{
    /**
     * Per-tag attribute allow list. Empty array = tag allowed but with
     * NO attributes. Tag absent from this list = strip the tag (text
     * content is preserved).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TAGS = [
        'b'          => [],
        'strong'     => [],
        'i'          => [],
        'em'         => [],
        'u'          => [],
        'p'          => [],
        'br'         => [],
        'a'          => ['href'],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'code'       => [],
        'pre'        => [],
    ];

    /**
     * Sanitize the given HTML fragment and return safe HTML.
     *
     * Returns the empty string for null/empty input so callers can pipe
     * untrusted values straight in without null checks.
     */
    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Hard-strip any <script>/<style>/<iframe>/<object>/<embed>/<form>/<input>
        // BEFORE feeding to DOMDocument. DOMDocument will decode entity-escaped
        // payloads and re-emit them, but a regex pass on the raw input prevents
        // pathological cases (e.g. `<scr<script>ipt>`) from sneaking through
        // by collapsing the inner tag once the outer is stripped.
        $stripPatterns = [
            '#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is',
            '#<\s*style\b[^>]*>.*?<\s*/\s*style\s*>#is',
            '#<\s*iframe\b[^>]*>.*?<\s*/\s*iframe\s*>#is',
            '#<\s*object\b[^>]*>.*?<\s*/\s*object\s*>#is',
            '#<\s*embed\b[^>]*/?\s*>#is',
            '#<\s*form\b[^>]*>.*?<\s*/\s*form\s*>#is',
            '#<\s*input\b[^>]*/?\s*>#is',
            // Also kill any unclosed <script ... that lacks a closing tag
            '#<\s*script\b[^>]*>#is',
            '#<\s*style\b[^>]*>#is',
            '#<\s*iframe\b[^>]*>#is',
        ];

        $cleaned = preg_replace($stripPatterns, '', $html) ?? '';

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Preserve UTF-8: the prologue trick forces the encoding without
        // the parser inventing a <meta charset> tag.
        $wrapped = '<?xml encoding="UTF-8"?><body>' . $cleaned . '</body>';

        $previousLibxml = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                $wrapped,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if ($loaded === false) {
                // Parse failure → return text-only fallback (no HTML at all)
                return e(strip_tags($cleaned));
            }

            $body = $dom->getElementsByTagName('body')->item(0);

            if (! $body instanceof DOMElement) {
                return e(strip_tags($cleaned));
            }

            $this->walk($body);

            // Re-serialize children of <body> only (the wrapper itself
            // must not appear in the output).
            $out = '';
            foreach ($body->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }

            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxml);
        }
    }

    /**
     * Recursively visit every DOM node and either keep, strip-but-keep-
     * children, or remove entirely. Mutating the tree mid-iteration is
     * fragile, so we collect actions first and apply after the loop.
     */
    private function walk(DOMNode $node): void
    {
        // Snapshot children — replacements / removals during iteration
        // mutate the live NodeList, which we don't want.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                // Text nodes are always safe; DOMDocument already
                // re-encodes entities on saveHTML().
                continue;
            }

            if (! $child instanceof DOMElement) {
                // Comments, processing instructions, etc. — strip.
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (! array_key_exists($tag, self::ALLOWED_TAGS)) {
                // Unallowed tag: unwrap it (move children up one level,
                // then remove the wrapper). This preserves user text
                // inside e.g. `<div>hi</div>` → `hi`.
                $this->unwrap($child);
                continue;
            }

            // Allowed tag → first sanitize its attributes, then recurse.
            $this->sanitizeAttributes($child, self::ALLOWED_TAGS[$tag]);
            $this->walk($child);
        }
    }

    /**
     * Move every child of `$el` to be a sibling immediately before `$el`
     * itself, then drop `$el`. This converts `<div>x<b>y</b></div>` into
     * `x<b>y</b>` after the div is unwrapped.
     */
    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }

        // Recurse first — sanitize the inside before we lift it out, so
        // a `<div onclick=...><script>x</script>foo</div>` doesn't
        // smuggle the script up into the parent.
        $this->walk($el);

        $kids = [];
        foreach ($el->childNodes as $kid) {
            $kids[] = $kid;
        }
        foreach ($kids as $kid) {
            $parent->insertBefore($kid, $el);
        }
        $parent->removeChild($el);
    }

    /**
     * Strip every attribute that isn't in `$allowed`. Drop the element
     * outright (replacing with its text contents) when an `<a href>`
     * fails URL validation — leaving an empty `<a>` is pointless and
     * `<a>` with no href can be styled by adversarial CSS.
     */
    private function sanitizeAttributes(DOMElement $el, array $allowed): void
    {
        // Collect attribute names first; live attribute removal during
        // iteration breaks the underlying NodeMap.
        $attrNames = [];
        foreach ($el->attributes as $attr) {
            $attrNames[] = $attr->nodeName;
        }

        foreach ($attrNames as $name) {
            $lower = strtolower($name);

            // Always nuke event handlers regardless of the allow list —
            // belt-and-suspenders against a future maintainer adding
            // `onclick` to the allow list by accident.
            if (str_starts_with($lower, 'on')) {
                $el->removeAttribute($name);
                continue;
            }

            if (! in_array($lower, $allowed, true)) {
                $el->removeAttribute($name);
                continue;
            }

            // Per-attribute validation.
            if ($lower === 'href') {
                $href = (string) $el->getAttribute($name);
                if (! $this->isSafeUrl($href)) {
                    $el->removeAttribute($name);
                }
            }
        }

        // After the pass, an `<a>` with no href is useless — unwrap so
        // the link text remains as plain text.
        if (strtolower($el->nodeName) === 'a' && ! $el->hasAttribute('href')) {
            $this->unwrap($el);
        }
    }

    /**
     * Allow only http://, https://, and protocol-relative ("//host")
     * URLs. Reject `javascript:`, `data:`, `vbscript:`, `file:`, etc.
     */
    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Strip common whitespace tricks that browsers tolerate inside URL schemes
        // (e.g. `java\tscript:alert(1)`). Normalize before scheme check.
        $normalized = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? $url;

        // Protocol-relative is OK (browser inherits page scheme, which we
        // serve as https in production).
        if (str_starts_with($normalized, '//')) {
            return true;
        }

        // Relative path inside our app — also OK.
        if (str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')) {
            return true;
        }

        // Anchors and mailto: not on the allow list per the audit spec.

        if (! preg_match('#^(https?:)//#i', $normalized)) {
            return false;
        }

        return true;
    }
}
