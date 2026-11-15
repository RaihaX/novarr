<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The KOReader plugin's update endpoints: GET /api/v1/koreader/plugin and
 * GET /api/v1/koreader/plugin/files/{path}.
 */
class ApiV1KoreaderPluginTest extends TestCase
{
    /**
     * Build id of the shared fixture (_meta.lua = "a", main.lua = "b",
     * sub/x.lua = "c"). koreader/spec/test_updater.lua asserts the same value.
     */
    private const FIXTURE_BUILD = '538de10c3947b2654ade1d18b8bb73b2ecbb675c67da2dcb4f72b01571e7b47c';

    private string $base;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/novarr-koplugin-' . uniqid();
        $this->dir = $this->base . '/novarr.koplugin';
        File::ensureDirectoryExists($this->dir);
        config(['novarr.koreader_plugin_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function write(string $path, string $bytes): void
    {
        File::ensureDirectoryExists(dirname($this->dir . '/' . $path));
        File::put($this->dir . '/' . $path, $bytes);
    }

    private function fixture(): void
    {
        $this->write('_meta.lua', 'a');
        $this->write('main.lua', 'b');
        $this->write('sub/x.lua', 'c');
    }

    public function test_shared_fixture_build_vector(): void
    {
        $this->fixture();

        $plugin = $this->getJson('/api/v1/koreader/plugin')->assertOk()->json('plugin');

        $this->assertSame(self::FIXTURE_BUILD, $plugin['build']);
        $this->assertSame(['_meta.lua', 'main.lua', 'sub/x.lua'], array_column($plugin['files'], 'path'));
        $this->assertSame(3, $plugin['size']);
        $this->assertNull($plugin['version']); // "a" has no version line
    }

    public function test_manifest_shape_order_hash_and_junk_skipped(): void
    {
        $meta = "local _ = require(\"gettext\")\nreturn {\n    fullname = _(\"Novarr\"),\n    version = \"1.2.3\",\n}\n";
        $this->write('_meta.lua', $meta);
        $this->write('main.lua', 'print("main")');
        $this->write('Zeta.lua', 'zeta');            // upper case sorts before lower case (byte order)
        $this->write('ui/deep/nested.lua', 'nested');
        $this->write('api.lua', 'api');
        // Junk that must not appear.
        $this->write('.DS_Store', 'x');
        $this->write('._main.lua', 'x');
        $this->write('ui/.hidden', 'x');
        $this->write('store.lua.tmp', 'x');
        $this->write('main.lua~', 'x');
        $this->write('.git/config', 'x');

        $res = $this->getJson('/api/v1/koreader/plugin')->assertOk();
        $res->assertJsonStructure(['plugin' => ['version', 'build', 'size', 'files' => [['path', 'size', 'sha256']]]]);
        $plugin = $res->json('plugin');

        $expected = ['Zeta.lua', '_meta.lua', 'api.lua', 'main.lua', 'ui/deep/nested.lua'];
        $this->assertSame($expected, array_column($plugin['files'], 'path'));
        $this->assertSame('1.2.3', $plugin['version']);

        $joined = '';
        $size = 0;
        foreach ($plugin['files'] as $f) {
            $bytes = file_get_contents($this->dir . '/' . $f['path']);
            $this->assertSame(strlen($bytes), $f['size']);
            $this->assertSame(hash('sha256', $bytes), $f['sha256']);
            $this->assertSame(['path', 'size', 'sha256'], array_keys($f));
            $joined .= $f['path'] . "\n" . hash('sha256', $bytes) . "\n";
            $size += strlen($bytes);
        }
        $this->assertSame(hash('sha256', $joined), $plugin['build']);
        $this->assertSame($size, $plugin['size']);
    }

    public function test_version_with_single_quotes_and_missing_meta(): void
    {
        $this->write('_meta.lua', "return { version = '2.0.1' }");
        $this->assertSame('2.0.1', $this->getJson('/api/v1/koreader/plugin')->json('plugin.version'));

        File::delete($this->dir . '/_meta.lua');
        $this->write('main.lua', 'b');
        $this->assertNull($this->getJson('/api/v1/koreader/plugin')->assertOk()->json('plugin.version'));
    }

    public function test_build_changes_when_a_file_changes(): void
    {
        $this->fixture();
        $first = $this->getJson('/api/v1/koreader/plugin')->json('plugin.build');
        $this->write('sub/x.lua', 'changed');
        $this->assertNotSame($first, $this->getJson('/api/v1/koreader/plugin')->json('plugin.build'));
    }

    public function test_missing_directory_is_404_with_a_plain_message(): void
    {
        config(['novarr.koreader_plugin_path' => $this->base . '/nope']);

        $this->getJson('/api/v1/koreader/plugin')->assertNotFound()
            ->assertExactJson(['message' => "The KOReader plugin isn't available on this server."]);
        $this->get('/api/v1/koreader/plugin/files/main.lua')->assertNotFound()
            ->assertJson(['message' => "The KOReader plugin isn't available on this server."]);
    }

    public function test_file_download_returns_exact_bytes(): void
    {
        $this->fixture();
        $binary = random_bytes(4096) . "\0\r\n";
        $this->write('sub/blob.bin', $binary);

        $res = $this->get('/api/v1/koreader/plugin/files/main.lua')->assertOk();
        $this->assertSame('b', $res->streamedContent());
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));

        $res = $this->get('/api/v1/koreader/plugin/files/sub/blob.bin')->assertOk();
        $this->assertSame($binary, $res->streamedContent());
    }

    public function test_traversal_and_unlisted_paths_are_404(): void
    {
        $this->fixture();
        $this->write('.hidden.lua', 'secret');      // exists, but not in the manifest
        File::put($this->base . '/outside.lua', 'outside');

        $paths = [
            '../outside.lua',
            '..%2Foutside.lua',
            '..%2F..%2F.env',
            '../.env',
            'sub/../main.lua',
            'sub%2F..%2Fmain.lua',
            '%2Fetc%2Fpasswd',
            '/etc/passwd',
            urlencode($this->base . '/outside.lua'),
            'sub%5Cx.lua',
            '.hidden.lua',
            'nope.lua',
            'sub',
            'SUB/x.lua',
        ];
        foreach ($paths as $path) {
            $res = $this->get('/api/v1/koreader/plugin/files/' . $path);
            $this->assertSame(404, $res->status(), "path {$path} must be refused");
            $this->assertIsString($res->json('message'), "path {$path} answers JSON");
        }
    }
}
