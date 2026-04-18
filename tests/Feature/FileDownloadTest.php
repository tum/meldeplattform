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

    public function test_file_with_escape_path_is_denied(): void
    {
        $file = File::create([
            'location' => '/etc/passwd',
            'name' => 'passwd',
        ]);

        $this->get('/file/passwd?id='.$file->uuid)
            ->assertStatus(403);
    }

    public function test_valid_stored_file_is_served(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('example.txt', 'hello world');
        $absPath = Storage::disk('uploads')->path('example.txt');

        $file = File::create([
            'location' => $absPath,
            'name' => 'example.txt',
        ]);

        $this->get('/file/example.txt?id='.$file->uuid)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=example.txt');
    }
}
