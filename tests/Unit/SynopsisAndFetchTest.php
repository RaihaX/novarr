<?php

namespace Tests\Unit;

use App\Http\Controllers\NovelController;
use Tests\TestCase;

/**
 * Synopsis HTML sanitising (rendered raw via {!! !!}) and FlareSolverr
 * response classification.
 */
class SynopsisAndFetchTest extends TestCase
{
    public function testSanitiserStripsTagsAndAttributes(): void
    {
        $this->assertSame('<p>Hi</p>', sanitizeSynopsisHtml('<img src=x onerror=alert(1)><p onclick="x">Hi</p>'));
    }

    public function testSanitiserDropsScriptAndStyleBodies(): void
    {
        $this->assertSame(
            '<p>Story</p>',
            sanitizeSynopsisHtml('<script>alert(1)</script><style>p{}</style><p>Story</p><script src="x">')
        );
    }

    public function testSanitiserKeepsWhitelistedFormatting(): void
    {
        $html = '<p class="a">One<br/>Two <em>three</em> <strong style="x">four</strong></p><ul><li>a</li></ul>';
        $this->assertSame('<p>One<br>Two <em>three</em> <strong>four</strong></p><ul><li>a</li></ul>', sanitizeSynopsisHtml($html));
    }

    public function testSanitiserHandlesQuotedGreaterThanAndLinks(): void
    {
        $this->assertSame('<p>Hi</p>', sanitizeSynopsisHtml('<p title="a>b" onmouseover=\'x\'>Hi</p>'));
        $this->assertSame('<p>click</p>', sanitizeSynopsisHtml('<p><a href="javascript:alert(1)">click</a></p>'));
        $this->assertSame('', sanitizeSynopsisHtml(null));
    }

    private function cleanSynopsis(?string $html, string $name = 'Some Novel'): ?string
    {
        $controller = (new \ReflectionClass(NovelController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(NovelController::class, 'cleanSynopsis');
        $method->setAccessible(true);

        return $method->invoke($controller, $html, $name);
    }

    public function testCleanSynopsisSanitises(): void
    {
        $this->assertSame(
            '<p>A long enough synopsis about a hero.</p>',
            $this->cleanSynopsis('<h3>Description</h3><p onclick="steal()">A long enough synopsis about a hero.</p><script>x()</script>')
        );
        $this->assertNull($this->cleanSynopsis('<p>Some Novel</p>'));
        $this->assertNull($this->cleanSynopsis('<script>alert("this is long enough to pass")</script>'));
    }

    public function testFlareSolverrClassification(): void
    {
        $ok = classifyFlareSolverrResponse(['status' => 'ok', 'solution' => ['status' => 200, 'response' => '<html></html>']]);
        $this->assertSame('<html></html>', $ok['html']);
        $this->assertNull($ok['reason']);

        $nf = classifyFlareSolverrResponse(['status' => 'ok', 'solution' => ['status' => 404, 'response' => '<html>Not found</html>']]);
        $this->assertNull($nf['html']);
        $this->assertSame('http_404', $nf['reason']);
        $this->assertFalse($nf['retry']);

        $err = classifyFlareSolverrResponse(['status' => 'ok', 'solution' => ['status' => 503, 'response' => '<html>down</html>']]);
        $this->assertSame('http_5xx', $err['reason']);
        $this->assertTrue($err['retry']);

        $this->assertSame('http_4xx', classifyFlareSolverrResponse(['status' => 'ok', 'solution' => ['status' => 403, 'response' => 'x']])['reason']);
        $this->assertSame('decode_failed', classifyFlareSolverrResponse(null)['reason']);
        $this->assertSame('flaresolverr_error', classifyFlareSolverrResponse(['status' => 'error', 'message' => 'timeout'])['reason']);
        $this->assertSame('empty_response', classifyFlareSolverrResponse(['status' => 'ok', 'solution' => ['status' => 200, 'response' => '']])['reason']);

        // No status reported (older FlareSolverr) — treat as success.
        $this->assertSame('x', classifyFlareSolverrResponse(['status' => 'ok', 'solution' => ['response' => 'x']])['html']);
    }

    public function testLenientDecodeSurvivesInvalidUtf8(): void
    {
        $json = '{"status":"ok","solution":{"status":200,"response":"caf' . "\xE9" . '"}}';
        $this->assertNull(json_decode($json, true));
        $data = json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertSame("caf\u{FFFD}", classifyFlareSolverrResponse($data)['html']);
    }
}
