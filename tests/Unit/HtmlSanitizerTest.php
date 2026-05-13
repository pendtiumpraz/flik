<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * XSS regression tests for {@see HtmlSanitizer}.
 *
 * Each `script_*` / `event_handler_*` / `bad_url_*` case is a real
 * payload reported in OWASP's XSS cheat sheet — if any of these start
 * passing through cleanly we have a regression.
 *
 * Pure PHPUnit\Framework\TestCase (no Laravel kernel) so it runs in
 * the Unit suite without DB/config booting.
 */
final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer();
    }

    public function test_strips_script_tag(): void
    {
        $out = $this->sanitizer->sanitize('hello <script>alert(1)</script> world');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('hello', $out);
        $this->assertStringContainsString('world', $out);
    }

    public function test_strips_img_onerror(): void
    {
        $out = $this->sanitizer->sanitize('<img src=x onerror=alert(1)>');
        // <img> is not on the allow list → fully stripped (no children)
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('<img', $out);
    }

    public function test_strips_event_handlers_on_allowed_tag(): void
    {
        $out = $this->sanitizer->sanitize('<a href="https://example.com" onclick="alert(1)">x</a>');
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('href="https://example.com"', $out);
    }

    public function test_keeps_legitimate_formatting(): void
    {
        $in = '<strong>bold</strong> and <em>italic</em> with <p>para</p>';
        $out = $this->sanitizer->sanitize($in);
        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<em>italic</em>', $out);
        $this->assertStringContainsString('<p>para</p>', $out);
    }

    public function test_keeps_safe_https_link(): void
    {
        $out = $this->sanitizer->sanitize('<a href="https://flik.id/movie/inception">Inception</a>');
        $this->assertStringContainsString('href="https://flik.id/movie/inception"', $out);
        $this->assertStringContainsString('Inception', $out);
    }

    public function test_strips_javascript_url(): void
    {
        $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('alert', $out);
        // No href left → <a> is unwrapped → "click" survives as text.
        $this->assertStringContainsString('click', $out);
    }

    public function test_strips_data_url(): void
    {
        $out = $this->sanitizer->sanitize('<a href="data:text/html,<script>alert(1)</script>">x</a>');
        $this->assertStringNotContainsString('data:', $out);
        $this->assertStringNotContainsString('alert', $out);
    }

    public function test_strips_iframe(): void
    {
        $out = $this->sanitizer->sanitize('<iframe src="https://evil.example/x"></iframe>safe');
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertStringContainsString('safe', $out);
    }

    public function test_strips_style_block(): void
    {
        $out = $this->sanitizer->sanitize('<style>body{display:none}</style>visible');
        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringNotContainsString('display:none', $out);
        $this->assertStringContainsString('visible', $out);
    }

    public function test_strips_form_and_input(): void
    {
        $out = $this->sanitizer->sanitize('<form action="/x"><input type=text></form>after');
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
        $this->assertStringContainsString('after', $out);
    }

    public function test_unwraps_unknown_tag_keeping_children(): void
    {
        $out = $this->sanitizer->sanitize('<div><strong>kept</strong></div>');
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringContainsString('<strong>kept</strong>', $out);
    }

    public function test_strips_disallowed_attributes(): void
    {
        $out = $this->sanitizer->sanitize('<p class="evil" id="x" style="color:red">text</p>');
        $this->assertStringNotContainsString('class=', $out);
        $this->assertStringNotContainsString('id=', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('<p>text</p>', $out);
    }

    public function test_handles_null_input(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function test_keeps_relative_link(): void
    {
        $out = $this->sanitizer->sanitize('<a href="/movie/test">x</a>');
        $this->assertStringContainsString('href="/movie/test"', $out);
    }

    public function test_strips_vbscript_url(): void
    {
        $out = $this->sanitizer->sanitize('<a href="vbscript:msgbox(1)">x</a>');
        $this->assertStringNotContainsString('vbscript', $out);
    }

    public function test_strips_nested_script_attempt(): void
    {
        // Classic mutation payload: outer <script> is hard-stripped via
        // regex first, the inner residue then has nothing to attach to.
        $out = $this->sanitizer->sanitize('<scr<script>ipt>alert(1)</scr</script>ipt>');
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_preserves_indonesian_unicode(): void
    {
        $out = $this->sanitizer->sanitize('<p>Selamat datang di FLiK — film bagus, harga terjangkau.</p>');
        $this->assertStringContainsString('Selamat', $out);
        $this->assertStringContainsString('terjangkau', $out);
        $this->assertStringContainsString('—', $out);
    }
}
