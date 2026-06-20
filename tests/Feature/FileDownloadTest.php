<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_without_id_returns_404(): void
    {
        $this->get('/file/whatever.txt')->assertNotFound();
    }

    public function test_file_with_unknown_id_returns_404(): void
    {
        $this->get('/file/whatever.txt?id=unknown')->assertNotFound();
    }

    public function test_path_escaping_the_disk_root_is_safe(): void
    {
        Storage::fake('uploads');

        // Even if a row's `path` somehow contains traversal characters or an
        // absolute path, the file is orphaned (no message) so it is denied
        // before the disk lookup even runs.
        $file = File::create([
            'path' => '/etc/passwd',
            'name' => 'passwd',
        ]);

        $this->get('/file/passwd?id='.$file->uuid)->assertNotFound();
    }

    public function test_orphaned_file_without_message_returns_404(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('orphan.txt', 'data');

        $file = File::create([
            'path' => 'orphan.txt',
            'name' => 'orphan.txt',
        ]);

        // No message/report association → always 404, even without a token.
        $this->get('/file/orphan.txt?id='.$file->uuid)->assertNotFound();
    }

    public function test_file_with_wrong_disk_returns_404(): void
    {
        Storage::fake('uploads');

        $file = File::create([
            'path' => 'secret.txt',
            'name' => 'secret.txt',
            'disk' => 's3',
        ]);

        $this->get('/file/secret.txt?id='.$file->uuid)->assertNotFound();
    }

    public function test_valid_stored_file_is_served_with_reporter_token(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        [$file, $report] = $this->makeFileWithReport();

        $this->get('/file/example.txt?id='.$file->uuid.'&token='.$report->reporter_token)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=example.txt');
    }

    public function test_valid_stored_file_is_served_with_administrator_token(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        [$file, $report] = $this->makeFileWithReport();

        $this->get('/file/example.txt?id='.$file->uuid.'&token='.$report->administrator_token)
            ->assertOk();
    }

    public function test_valid_stored_file_is_served_to_authenticated_admin(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        [$file] = $this->makeFileWithReport();

        $this->actingAsGlobalAdmin()
            ->get('/file/example.txt?id='.$file->uuid)
            ->assertOk();
    }

    public function test_file_denied_without_token_or_auth(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        [$file] = $this->makeFileWithReport();

        $this->get('/file/example.txt?id='.$file->uuid)->assertForbidden();
    }

    public function test_file_denied_with_wrong_token(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        [$file] = $this->makeFileWithReport();

        $this->get('/file/example.txt?id='.$file->uuid.'&token=00000000-0000-0000-0000-000000000000')
            ->assertForbidden();
    }

    public function test_topic_admin_can_access_their_topic_file(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $topic->admins()->attach($admin);

        [$file] = $this->makeFileWithReport($topic);

        $this->actingAsUser('topicadmin')
            ->get('/file/example.txt?id='.$file->uuid)
            ->assertOk();
    }

    public function test_topic_admin_cannot_access_other_topic_file(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        $ownTopic = Topic::create([
            'name_de' => 'Own', 'name_en' => 'Own', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $otherTopic = Topic::create([
            'name_de' => 'Other', 'name_en' => 'Other', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $ownTopic->admins()->attach($admin);

        [$file] = $this->makeFileWithReport($otherTopic);

        $this->actingAsUser('topicadmin')
            ->get('/file/example.txt?id='.$file->uuid)
            ->assertForbidden();
    }

    /**
     * @return array{0: File, 1: Report}
     */
    private function makeFileWithReport(?Topic $topic = null): array
    {
        $topic ??= Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);

        Storage::disk('uploads')->put('example.txt', 'hello world');

        $file = File::create([
            'path' => 'example.txt',
            'name' => 'example.txt',
        ]);

        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create(['report_id' => $report->id, 'content' => 'body', 'is_admin' => false]);
        $message->files()->attach($file->id);

        return [$file, $report];
    }
}
