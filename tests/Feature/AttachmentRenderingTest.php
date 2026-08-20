<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentRenderingTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array{name: string, bytes: string}> $uploads */
    private function messageWithFiles(array $uploads): Message
    {
        Storage::fake('uploads');

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $report = Report::create(['topic_id' => $topic->id]);

        $body = "\n**Attachments**\n\n";
        $files = [];
        foreach ($uploads as $upload) {
            Storage::disk('uploads')->put($upload['name'], $upload['bytes']);
            $file = File::create(['path' => $upload['name'], 'name' => $upload['name']]);
            $files[] = $file->id;
            $body .= '['.$file->name.']('
                .route('file.download', [
                    'name' => $file->name,
                    'id' => $file->uuid,
                    'token' => $report->reporter_token,
                ])
                .")\n";
        }

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $body,
            'is_admin' => false,
        ]);
        $message->files()->sync($files);

        return $message->load('files');
    }

    public function test_multiple_attachments_render_as_a_card_grid(): void
    {
        $html = $this->messageWithFiles([
            ['name' => 'attachment-aaaaaaaa.pdf', 'bytes' => str_repeat('x', 2048)],
            ['name' => 'attachment-bbbbbbbb.png', 'bytes' => 'img'],
        ])->renderedBody();

        // One grid wrapping both cards – not two bare anchors side by side.
        $this->assertSame(1, substr_count($html, '<span class="attachment-grid">'));
        $this->assertSame(2, substr_count($html, '<a class="attachment attachment-'));
        $this->assertStringContainsString('attachment attachment-pdf', $html);
        $this->assertStringContainsString('attachment attachment-image', $html);

        // Type and size are surfaced on the card.
        $this->assertStringContainsString('PDF · 2 KB', $html);
        $this->assertStringContainsString('attachment-aaaaaaaa.pdf', $html);
    }

    public function test_unknown_extension_falls_back_to_the_generic_card(): void
    {
        $html = $this->messageWithFiles([
            ['name' => 'attachment-cccccccc.bin', 'bytes' => 'data'],
        ])->renderedBody();

        $this->assertStringContainsString('<a class="attachment attachment-file"', $html);
        $this->assertStringContainsString('BIN · 4 B', $html);
    }

    public function test_missing_blob_still_renders_a_card_without_a_size(): void
    {
        $message = $this->messageWithFiles([
            ['name' => 'attachment-dddddddd.png', 'bytes' => 'img'],
        ]);
        Storage::disk('uploads')->delete('attachment-dddddddd.png');

        $html = $message->renderedBody();

        $this->assertStringContainsString('<a class="attachment attachment-image"', $html);
        $this->assertStringContainsString('>PNG</span>', $html);
    }

    public function test_links_the_reporter_typed_are_left_alone(): void
    {
        $message = $this->messageWithFiles([
            ['name' => 'attachment-eeeeeeee.png', 'bytes' => 'img'],
        ]);
        $message->content .= "\n\n[my notes](https://example.org/file/notes?id=123)\n";

        $html = $message->renderedBody();

        $this->assertStringContainsString('<a href="https://example.org/file/notes?id=123">my notes</a>', $html);
        $this->assertSame(1, substr_count($html, '<a class="attachment'));
    }

    public function test_message_without_files_is_untouched(): void
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id,
            'content' => 'Just **text**.',
            'is_admin' => true,
        ]);

        $this->assertStringNotContainsString('attachment', $message->renderedBody());
    }
}
