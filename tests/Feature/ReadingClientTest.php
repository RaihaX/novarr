<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use App\NovelChapter;
use App\ReadingPosition;
use App\Services\ReadState;
use App\Support\ReadingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Where a read came from (App\Support\ReadingClient): stamped on the
 * chapter_reads row when the chapter is newly marked read and on the
 * reading position; cleared on unread; the address cleared after
 * NOVARR_AUTH_LOG_DAYS by novarr:prune-auth-log.
 */
class ReadingClientTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    private const KOREADER_UA = 'KOReader/2024.04 (Kindle)';

    private const FIREFOX_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:126.0) Gecko/20100101 Firefox/126.0';

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function row(NovelChapter $chapter, ?int $userId = null): object
    {
        return DB::table('chapter_reads')->where('user_id', $userId ?? $this->owner()->id)->where('novel_chapter_id', $chapter->id)->first();
    }

    private function position(NovelChapter $chapter, ?int $userId = null): ReadingPosition
    {
        return ReadingPosition::where('user_id', $userId ?? $this->owner()->id)->where('novel_id', $chapter->novel_id)->firstOrFail();
    }

    public function test_the_columns_exist_and_the_migration_can_be_rolled_back_and_rerun(): void
    {
        foreach (['ip', 'device', 'client', 'public'] as $column) {
            $this->assertTrue(Schema::hasColumn('chapter_reads', $column), "chapter_reads.{$column}");
        }
        foreach (['ip', 'client', 'public'] as $column) {
            $this->assertTrue(Schema::hasColumn('reading_positions', $column), "reading_positions.{$column}");
        }

        $migration = require database_path('migrations/2026_12_03_000001_add_client_to_chapter_reads_and_reading_positions.php');
        $migration->up(); // already applied: a no-op
        $migration->down();
        $this->assertFalse(Schema::hasColumn('chapter_reads', 'ip'));
        $this->assertFalse(Schema::hasColumn('reading_positions', 'client'));
        $this->assertTrue(Schema::hasColumn('reading_positions', 'device_name'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('chapter_reads', 'public'));
    }

    public function test_from_describes_the_request_and_never_throws(): void
    {
        $this->assertNull(ReadingClient::from(null));

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.23', 'HTTP_USER_AGENT' => self::FIREFOX_UA]);
        $client = ReadingClient::from($request);
        $this->assertSame(['ip' => '192.168.1.23', 'device' => null, 'client' => 'Firefox on macOS', 'public' => false], $client->columns());

        // An unknown User-Agent is no description.
        $this->assertNull(ReadingClient::from(Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'zzz']))->client);
        $this->assertSame(['ip' => null, 'device' => null, 'client' => null, 'public' => false], ReadingClient::columnsOf(null));
    }

    public function test_opening_a_chapter_in_the_web_reader_stamps_the_read_and_the_position(): void
    {
        [, $chapters] = $this->novelWithChapters('Web Read', 2);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.40'])
            ->withHeader('User-Agent', self::FIREFOX_UA)
            ->withUnencryptedCookies(['novarr_device' => 'browser-abc-12345', 'novarr_device_name' => 'Study Mac'])
            ->get(route('chapters.show', $chapters[1]->id))->assertOk();

        $row = $this->row($chapters[1]);
        $this->assertNotNull($row->read_at);
        $this->assertSame(['192.168.1.40', 'Study Mac', 'Firefox on macOS', false], [$row->ip, $row->device, $row->client, (bool) $row->public]);

        $position = $this->position($chapters[1]);
        $this->assertSame(['192.168.1.40', 'Study Mac', 'Firefox on macOS', false], [$position->ip, $position->device_name, $position->client, $position->public]);
    }

    public function test_an_api_read_names_the_token_unless_the_device_names_itself(): void
    {
        $owner = $this->enableSignIn();
        [, $chapters] = $this->novelWithChapters('App Read', 3);
        $token = $this->tokenFor($owner, 'Kindle Paperwhite');

        // No device-name header: the token's name, the UA's description.
        $this->asToken($token)->withHeader('User-Agent', self::KOREADER_UA)->withServerVariables(['REMOTE_ADDR' => '192.168.1.60'])
            ->postJson('/api/v1/chapters/' . $chapters[1]->id . '/progress', ['progress' => 30, 'read' => true])->assertOk();
        $row = $this->row($chapters[1]);
        $this->assertSame(['192.168.1.60', 'Kindle Paperwhite', 'KOReader'], [$row->ip, $row->device, $row->client]);
        $this->assertSame('KOReader', $this->position($chapters[1])->client);

        // X-Novarr-Device-Name wins over the token's name.
        $this->asToken($token)->withHeaders(['User-Agent' => self::KOREADER_UA, 'X-Novarr-Device' => 'kindle-device-1', 'X-Novarr-Device-Name' => 'Bedroom Kindle'])
            ->postJson('/api/v1/chapters/' . $chapters[2]->id . '/read', ['read' => true])->assertOk();
        $this->assertSame('Bedroom Kindle', $this->row($chapters[2])->device);
        $this->assertSame('Bedroom Kindle', $this->position($chapters[2])->device_name);
    }

    public function test_a_read_on_the_public_address_is_flagged_with_the_forwarded_visitor_address(): void
    {
        // TRUSTED_PROXIES=192.168.1.50 (as PublicAddressTest does it).
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        TrustProxies::at(['192.168.1.50']);
        $owner = $this->enableSignIn();
        config(['novarr.public_hosts' => ['read.example.com']]);
        [, $chapters] = $this->novelWithChapters('Away Read', 1);

        $this->asToken($this->tokenFor($owner, 'Phone'))
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->postJson('http://read.example.com/api/v1/chapters/' . $chapters[1]->id . '/progress', ['progress' => 10, 'read' => true])
            ->assertOk();

        $row = $this->row($chapters[1]);
        $this->assertSame('203.0.113.9', $row->ip);
        $this->assertTrue((bool) $row->public);
        $this->assertTrue($this->position($chapters[1])->public);
        $this->assertSame('203.0.113.9', $this->position($chapters[1])->ip);
    }

    public function test_a_read_chapter_keeps_its_stamp_unread_clears_it_and_bulk_stamps_every_row(): void
    {
        [$novel, $chapters] = $this->novelWithChapters('Stamped', 4);
        $readState = app(ReadState::class);
        $home = new ReadingClient('192.168.1.10', 'Phone', 'Novarr app on Android');
        $away = new ReadingClient('203.0.113.5', 'Laptop', 'Firefox on macOS', true);

        $readState->markOpened($chapters[1], client: $home);
        // Touched again from elsewhere: already read, the stamp stays.
        $readState->setRead($chapters[1], true, now(), client: $away);
        $readState->markOpened($chapters[1], client: $away);
        $readState->recordProgress($chapters[1], 50, true, now(), client: $away);
        $this->assertSame(['192.168.1.10', 'Phone', false], [$this->row($chapters[1])->ip, $this->row($chapters[1])->device, (bool) $this->row($chapters[1])->public]);

        // Unread clears it; read again takes the new one.
        $readState->setRead($chapters[1], false, now(), client: $away);
        $row = $this->row($chapters[1]);
        $this->assertSame([null, null, null, false], [$row->ip, $row->device, $row->client, (bool) $row->public]);
        $readState->setRead($chapters[1], true, now(), client: $away);
        $this->assertSame(['203.0.113.5', 'Laptop', true], [$this->row($chapters[1])->ip, $this->row($chapters[1])->device, (bool) $this->row($chapters[1])->public]);

        // A progress write that marks it read stamps; without a client nothing is stamped.
        $readState->recordProgress($chapters[2], 20, true, now(), client: $home);
        $this->assertSame('Phone', $this->row($chapters[2])->device);
        $readState->markOpened($chapters[3]);
        $this->assertNull($this->row($chapters[3])->ip);

        // Bulk: every row it marks read (existing and new) gets the stamp.
        $readState->markMany(NovelChapter::where('novel_id', $novel->id), true, now(), client: $away);
        foreach ($chapters as $chapter) {
            $this->assertSame('203.0.113.5', $this->row($chapter)->ip, "chapter {$chapter->id}");
        }
        $readState->markMany(NovelChapter::where('novel_id', $novel->id), false, now(), client: $away);
        $this->assertSame(0, DB::table('chapter_reads')->whereNotNull('ip')->count());
    }

    public function test_web_bulk_read_and_read_through_stamp_the_request(): void
    {
        [$novel, $chapters] = $this->novelWithChapters('Bulk Stamp', 3);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.77'])->withHeader('User-Agent', self::FIREFOX_UA)
            ->postJson(route('chapters.read_through', $chapters[2]->id))->assertOk();
        $this->assertSame('192.168.1.77', $this->row($chapters[1])->ip);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.78'])
            ->postJson(route('chapters.bulk_read'), ['read' => true, 'scope' => 'all', 'novel_id' => $novel->id])->assertOk();
        $this->assertSame('192.168.1.78', $this->row($chapters[3])->ip);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.79'])
            ->postJson(route('chapters.bulk_read'), ['read' => false, 'scope' => 'all', 'novel_id' => $novel->id])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.79'])
            ->postJson(route('novels.bulk'), ['action' => 'read', 'ids' => [$novel->id]])->assertOk();
        $this->assertSame('192.168.1.79', $this->row($chapters[1])->ip);
    }

    public function test_prune_clears_old_addresses_and_keeps_the_rest(): void
    {
        [, $chapters] = $this->novelWithChapters('Pruned', 2);
        $owner = $this->owner();
        DB::table('chapter_reads')->insert([
            ['user_id' => $owner->id, 'novel_id' => $chapters[1]->novel_id, 'novel_chapter_id' => $chapters[1]->id, 'read_at' => now()->subDays(31), 'bulk' => false, 'manual' => false,
                'ip' => '192.168.1.5', 'device' => 'Kindle', 'client' => 'KOReader', 'public' => true],
            ['user_id' => $owner->id, 'novel_id' => $chapters[2]->novel_id, 'novel_chapter_id' => $chapters[2]->id, 'read_at' => now()->subDays(5), 'bulk' => false, 'manual' => false,
                'ip' => '192.168.1.6', 'device' => 'Kindle', 'client' => 'KOReader', 'public' => false],
        ]);
        ReadingPosition::create(['user_id' => $owner->id, 'novel_id' => $chapters[1]->novel_id, 'novel_chapter_id' => $chapters[1]->id, 'recorded_at' => now()->subDays(40), 'ip' => '192.168.1.7', 'client' => 'KOReader']);

        config(['novarr.auth_log_days' => 0]);
        $this->artisan('novarr:prune-auth-log')->assertExitCode(0);
        $this->assertSame(3, DB::table('chapter_reads')->whereNotNull('ip')->count() + DB::table('reading_positions')->whereNotNull('ip')->count());

        config(['novarr.auth_log_days' => 30]);
        $this->artisan('novarr:prune-auth-log')->expectsOutputToContain('Cleared the address of 2 reads')->assertExitCode(0);
        $old = $this->row($chapters[1]);
        $this->assertSame([null, 'Kindle', 'KOReader', true], [$old->ip, $old->device, $old->client, (bool) $old->public]);
        $this->assertSame('192.168.1.6', $this->row($chapters[2])->ip);
        $this->assertNull(ReadingPosition::first()->ip);
        $this->assertSame('KOReader', ReadingPosition::first()->client);
    }
}
