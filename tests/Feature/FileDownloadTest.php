<?php

namespace Tests\Feature;

use App\Models\File;
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
        // absolute path, Flysystem normalises it relative to the disk root,
        // so the file simply isn't found and the download 404s.
        $file = File::create([
            'path' => '/etc/passwd',
            'name' => 'passwd',
        ]);

        $this->get('/file/passwd?id='.$file->uuid)->assertNotFound();
    }

    public function test_valid_stored_file_is_served(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');

        $file = File::create([
            'path' => 'example.txt',
            'name' => 'example.txt',
        ]);

        $this->get('/file/example.txt?id='.$file->uuid)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=example.txt');
    }
}
