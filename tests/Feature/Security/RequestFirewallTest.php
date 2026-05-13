<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RequestFirewall (WAF-lite) tests
|--------------------------------------------------------------------------
|
| These cases are placeholders — the bodies document the expected
| behaviour but each case is `markTestSkipped` because the existing
| FLiK test suite uses minimal Pest-style assertions and does not yet
| boot a full HTTP kernel for middleware-level integration tests. They
| serve as executable documentation of the WAF contract: what we
| guarantee will be blocked and what we guarantee will pass through to
| downstream sanitisers.
|
| To run the full set, drop the `markTestSkipped` lines and ensure:
|   - config('security.waf.enabled') === true
|   - config('security.waf.mode') === 'block'
|   - the cache driver is array (not redis) so ban-list state doesn't
|     bleed between tests
|
*/

test('Path containing ../etc/passwd is blocked with 403', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    $response = $this->get('/movies/../etc/passwd');
    $response->assertStatus(403);
    $response->assertSeeText('Request blocked');
});

test('Query string with SQLi probe is blocked with 403', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    $response = $this->get("/movies?q='%20OR%201=1");
    $response->assertStatus(403);
});

test('POST body with <script> tag to /movies is blocked with 403', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    $response = $this->post('/movies', ['title' => '<script>alert(1)</script>']);
    $response->assertStatus(403);
});

test('Same XSS payload to /comment is allowed (route allowlist)', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    // /comment is in config('security.waf.route_allowlist') — body
    // inspection is skipped so the downstream HtmlSanitizer can
    // strip the tag instead of the WAF blocking the whole request.
    // The request still requires auth so we expect a 302 (login
    // redirect) rather than a 403, never a WAF block.
    $response = $this->post('/comment', ['body' => '<script>alert(1)</script>']);
    expect($response->status())->not->toBe(403);
});

test('Path traversal still blocked even on allowlisted /admin/* route', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    // Allowlist skips BODY inspection only — path/query are always
    // inspected because no legitimate URL needs `../` segments.
    $response = $this->get('/admin/movies/../../etc/passwd');
    $response->assertStatus(403);
});

test('X-Bypass-Waf header with valid token bypasses inspection', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    config(['security.waf.bypass_token' => 'test-secret']);

    $response = $this->withHeaders(['X-Bypass-Waf' => 'test-secret'])
        ->get('/movies?q='.urlencode("' OR 1=1"));
    expect($response->status())->not->toBe(403);
});

test('IP is temp-banned after 5 hits in 5 minutes', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    // First 4 hits → 403 with audit row each.
    // 5th hit → 403 + ban set in cache (waf:ip:ban:{ip}, TTL 60min).
    // 6th request to ANY path → 403 immediately, no rule scan.
    for ($i = 0; $i < 5; $i++) {
        $this->get('/movies?q='.urlencode("' OR 1=1"))->assertStatus(403);
    }
    $this->get('/movies')->assertStatus(403); // banned even for clean URL
});

test('log_only mode logs but does not block', function () {
    $this->markTestSkipped('WAF integration tests are documentation-only — see file header.');

    config(['security.waf.mode' => 'log_only']);
    $response = $this->get('/movies?q='.urlencode("' OR 1=1"));
    expect($response->status())->not->toBe(403);
    // Audit row still written with mode=log_only in meta.
});
