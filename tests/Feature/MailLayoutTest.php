<?php

namespace Tests\Feature;

use App\Mail\AdminAlertMail;
use App\Mail\MailPreviews;
use App\Mail\MailTheme;
use App\Mail\SendToKindle;
use App\Mail\TestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\UsesAccounts;
use Tests\TestCase;

/**
 * Every email is in the ONE shared frame (resources/views/emails/layout +
 * layout_text): the header wordmark, the footer, a plain-text part. The
 * owner's alerts render their structured fields; /mail-preview is the
 * admin's.
 */
class MailLayoutTest extends TestCase
{
    use RefreshDatabase;
    use UsesAccounts;

    public function test_every_mail_renders_html_and_text_in_the_shared_frame(): void
    {
        $this->assertGreaterThanOrEqual(20, count(MailPreviews::all()));

        foreach (MailPreviews::all() as $name => $entry) {
            $mail = ($entry['make'])();
            $html = $mail->render();

            $this->assertStringContainsString('data-mail-layout="novarr"', $html, $name);
            $this->assertStringContainsString('data-mail-header', $html, $name);
            $this->assertStringContainsString('NOVARR<span style="color: #F0B429;">.</span>', $html, $name);
            $this->assertStringContainsString('data-mail-footer', $html, $name);
            $this->assertStringContainsString('<meta name="color-scheme" content="dark">', $html, $name);
            $this->assertSame(1, substr_count($html, '<html'), "{$name}: one document");
            $this->assertNotSame('', trim((string) $mail->subject), $name);

            $text = MailPreviews::text($mail);
            $this->assertStringStartsWith('NOVARR.', $text, $name);
            $this->assertStringContainsString("\n--\n", $text, "{$name}: the text footer");
            $this->assertStringNotContainsString('<table', $text, "{$name}: no markup in the text part");
        }
    }

    public function test_every_mailable_in_app_mail_has_a_preview(): void
    {
        $previewed = collect(MailPreviews::all())->map(fn($e) => get_class(($e['make'])()))->unique()->all();

        foreach (glob(app_path('Mail/*.php')) as $file) {
            $class = 'App\\Mail\\' . basename($file, '.php');
            if (!is_subclass_of($class, Mailable::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $this->assertContains($class, $previewed, "{$class} has no preview in App\\Mail\\MailPreviews");
        }
    }

    public function test_the_admin_alert_renders_its_structured_fields(): void
    {
        $mail = new AdminAlertMail('Added automatically: Blightfall', 'Added automatically: “Blightfall” was downloaded.', 'https://home.test/novels/4', [
            'eyebrow' => 'Downloads',
            'heading' => 'Added automatically',
            'tone' => 'success',
            'intro' => 'It is in the library now.',
            'cover' => 'https://read.example.com/storage/covers/4.jpg',
            'title' => 'Blightfall',
            'meta' => 'Book · Ava Reid',
            'chip' => 'In the library',
            'items' => [['title' => 'Dune', 'meta' => '7 results', 'status' => 'Pick one', 'tone' => 'warning'], ['meta' => 'no title: dropped']],
            'facts' => ['Alert below' => '10% free'],
            'note' => 'Not found (404)',
            'note_label' => 'Top reason',
            'button' => 'Open the book',
            'secondary' => ['text' => 'Open Downloads', 'url' => 'https://home.test/downloads'],
        ]);
        $html = $mail->render();

        $this->assertStringContainsString('<title>Added automatically</title>', $html);
        $this->assertStringContainsString('data-tone="success"', $html);
        $this->assertStringContainsString(MailTheme::SUCCESS, $html);
        $this->assertStringContainsString('It is in the library now.', $html);
        $this->assertStringContainsString('src="https://read.example.com/storage/covers/4.jpg"', $html);
        $this->assertStringContainsString('data-mail-card', $html);
        $this->assertStringContainsString('Book · Ava Reid', $html);
        $this->assertStringContainsString('In the library', $html);
        $this->assertSame(1, substr_count($html, 'data-mail-item'), 'an item without a title is dropped');
        $this->assertStringContainsString('Dune', $html);
        $this->assertStringContainsString('Pick one', $html);
        $this->assertStringContainsString('Alert below', $html);
        $this->assertStringContainsString('Not found (404)', $html);
        $this->assertStringContainsString('Open the book &rarr;', $html);
        $this->assertStringContainsString('href="https://home.test/novels/4"', $html);
        $this->assertStringContainsString('href="https://home.test/downloads"', $html);
        $this->assertStringContainsString(AdminAlertMail::FOOTER, $html);

        $text = MailPreviews::text($mail);
        $this->assertStringContainsString("Blightfall — Book · Ava Reid [In the library]", $text);
        $this->assertStringContainsString('- Dune — 7 results [Pick one]', $text);
        $this->assertStringContainsString("Open the book:\nhttps://home.test/novels/4", $text);
        $this->assertStringContainsString('Open Downloads: https://home.test/downloads', $text);
    }

    public function test_a_plain_admin_alert_still_reads_well(): void
    {
        $mail = new AdminAlertMail('Disk space low', 'Disk space is low.', 'https://home.test/health/storage', ['tone' => 'nonsense']);
        $html = $mail->render();

        $this->assertSame('info', $mail->details['tone'], 'an unknown tone is info');
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Disk space low</h1>', $html);
        $this->assertStringContainsString('Disk space is low.', $html);
        $this->assertStringContainsString('Open in Novarr &rarr;', $html);
        $this->assertStringNotContainsString('data-mail-card', $html);
        $this->assertStringNotContainsString('data-mail-list', $html);

        $none = (new AdminAlertMail('No link', 'Nothing to open.'))->render();
        $this->assertStringNotContainsString('data-mail-button', $none);
    }

    public function test_the_admin_alert_service_hands_the_details_to_the_mail(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $this->owner()->forceFill(['email' => 'owner@example.test'])->save();

        app(\App\Services\AdminAlert::class)->send('Subject', 'Message.', 'https://home.test/x', ['button' => 'Pick a result', 'tone' => 'warning']);

        Mail::assertSent(AdminAlertMail::class, fn(AdminAlertMail $m) => $m->hasTo('owner@example.test')
            && $m->body === 'Message.' && $m->details['button'] === 'Pick a result' && $m->details['tone'] === 'warning');
    }

    public function test_the_kindle_mail_keeps_the_title_as_its_subject_and_the_epub_attached(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'epub');
        file_put_contents($path, 'epub');
        $mail = new SendToKindle($path, 'Shadow Slave', 'Shadow Slave.epub', 'Guiltythree');
        $html = $mail->render();

        $this->assertSame('Shadow Slave', $mail->subject);
        $this->assertStringContainsString('Sent from Novarr.', $html);
        $this->assertStringContainsString('by Guiltythree', $html);
        $this->assertCount(1, $mail->attachments);
        $this->assertSame('Shadow Slave.epub', $mail->attachments[0]['options']['as']);
        @unlink($path);
    }

    public function test_the_settings_test_email_uses_the_shared_frame(): void
    {
        Mail::fake();
        \App\Setting::put('summary_email', 'owner@example.test');

        $this->postJson(route('settings.test_email'))->assertOk()->assertJson(['success' => true]);

        Mail::assertSent(TestMail::class, fn(TestMail $m) => $m->hasTo('owner@example.test')
            && str_contains($m->render(), TestMail::MESSAGE) && $m->subject === 'Novarr test email');
    }

    public function test_the_preview_pages_list_and_render_every_mail(): void
    {
        $index = $this->get('/mail-preview')->assertOk()->getContent();
        foreach (array_keys(MailPreviews::all()) as $name) {
            $this->assertStringContainsString('data-mail-preview="' . $name . '"', $index);
        }

        $this->get('/mail-preview/alert-added')->assertOk()->assertSee('data-mail-layout="novarr"', false)->assertSee('Added automatically');
        $text = $this->get('/mail-preview/alert-added?part=text')->assertOk();
        $this->assertStringStartsWith('text/plain', $text->headers->get('Content-Type'));
        $this->assertStringStartsWith('NOVARR.', $text->getContent());
        $this->get('/mail-preview/no-such-mail')->assertNotFound();
    }

    public function test_the_preview_pages_are_the_admins_only(): void
    {
        $owner = $this->enableSignIn();
        $reader = $this->makeReader();

        $this->asNobody()->get('/mail-preview')->assertRedirect();
        $this->asSignedIn($reader)->get('/mail-preview')->assertForbidden();
        $this->asSignedIn($reader)->get('/mail-preview/invite')->assertForbidden();
        $this->asSignedIn($owner)->get('/mail-preview')->assertOk();
        $this->asSignedIn($owner)->get('/mail-preview/invite')->assertOk();
    }
}
