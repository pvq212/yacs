<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Modules\Files\Files;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Modules\Tasks\Tasks;
use App\Support\Database\Records as R;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\World;
use Tests\TestCase;

final class AttachmentTest extends TestCase
{
    private function visitor(string $method, string $path, array $body, string $token): TestResponse
    {
        return $this->call($method, '/api/v1/widget/'.$path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => R::id()], json_encode((object) $body));
    }

    private function fixture(): array
    {
        Storage::fake('testing');
        config(['yacs.files.disk' => 'testing']);
        $world = World::create();
        $inbox = $world->inbox($world->brand());
        $session = $world->in(fn () => app(VisitorSessions::class)->anonymous($inbox, []));

        return [$world, $inbox, $session];
    }

    private function upload(World $world, string $token, string $body, string $mime = 'text/plain'): array
    {
        $ticket = $this->visitor('POST', 'files/uploads', ['filename' => 'example.txt', 'content_type' => $mime, 'bytes' => strlen($body), 'purpose' => 'chat'], $token);
        $ticket->assertCreated();
        $this->assertMatchesOpenApi($ticket, 'initiateVisitorUpload');
        $this->call('PUT', $ticket->json('data.upload_url'), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $body)->assertNoContent();
        $complete = $this->visitor('POST', 'files/'.$ticket->json('data.file_id').'/complete', [], $token);
        $complete->assertAccepted();
        $this->assertMatchesOpenApi($complete, 'completeVisitorUpload');
        $world->in(fn () => app(Tasks::class)->run(DB::table('async_tasks')->where('entity_id', $ticket->json('data.file_id'))->value('id'), $world->workspace->id));
        $file = $this->visitor('GET', 'files/'.$ticket->json('data.file_id'), [], $token);
        $file->assertOk();

        return [$ticket->json('data.file_id'), $ticket->json('data.upload_url'), $file->json('data')];
    }

    public function test_upload_download_contact_isolation_and_logout_revocation(): void
    {
        [$world,$inbox,$session] = $this->fixture();
        $token = $session['access_token'];
        [$id,$upload,$file] = $this->upload($world, $token, '可供下載的測試附件');
        $this->assertSame('clean', $file['state']);
        $conversation = $this->visitor('POST', 'conversations', [], $token)->json('data.id');
        $sent = $this->visitor('POST', "conversations/$conversation/messages", ['client_message_id' => R::id(), 'body_text' => '附件', 'attachment_ids' => [$id]], $token);
        $sent->assertCreated();
        $other = $world->in(fn () => app(VisitorSessions::class)->anonymous($inbox, []));
        $this->visitor('GET', "files/$id/download", [], $other['access_token'])->assertNotFound();
        $url = $this->visitor('GET', "files/$id/download", [], $token)->json('data.url');
        $download = $this->get($url);
        $download->assertOk();
        $this->assertSame('可供下載的測試附件', $download->streamedContent());
        $this->call('PUT', $upload, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], '可供下載的測試附件')->assertConflict();
        $this->visitor('POST', 'logout', [], $token)->assertNoContent();
        $this->get($url)->assertUnauthorized();
    }

    public function test_executable_and_svg_content_are_rejected_and_cannot_attach(): void
    {
        [$world,$inbox,$session] = $this->fixture();
        $token = $session['access_token'];
        [$id,,$file] = $this->upload($world, $token, '<?php system("id");');
        $this->assertSame('rejected', $file['state']);
        $conversation = $this->visitor('POST', 'conversations', [], $token)->json('data.id');
        $this->visitor('POST', "conversations/$conversation/messages", ['client_message_id' => R::id(), 'body_text' => 'unsafe', 'attachment_ids' => [$id]], $token)->assertForbidden();
        [,, $svg] = $this->upload($world, $token, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png');
        $this->assertSame('rejected', $svg['state']);
    }

    public function test_storage_switch_keeps_existing_files_on_their_original_disk(): void
    {
        [$world, , $session] = $this->fixture();
        [$id] = $this->upload($world, $session['access_token'], '切換前的附件');
        Storage::fake('r2');
        config(['yacs.files.disk' => 'r2']);
        $url = $this->visitor('GET', "files/$id/download", [], $session['access_token'])->json('data.url');
        $download = $this->get($url);
        $download->assertOk();
        $this->assertSame('切換前的附件', $download->streamedContent());
        $world->in(function () use ($id): void {
            $this->assertSame('testing', DB::table('files')->where('id', $id)->value('storage_disk'));
        });
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_remote_object_is_encrypted_and_ciphertext_cannot_move_to_another_key(): void
    {
        [$world, , $session] = $this->fixture();
        Storage::fake('r2');
        config(['yacs.files.disk' => 'r2']);
        [$id, , $metadata] = $this->upload($world, $session['access_token'], '遠端附件的機密內容');
        $this->assertSame('clean', $metadata['state']);
        $file = $world->in(fn () => DB::table('files')->where('id', $id)->first());
        $ciphertext = Storage::disk('r2')->get($file->object_key);
        $this->assertStringStartsWith('yacs1.', $ciphertext);
        $this->assertStringNotContainsString('遠端附件的機密內容', $ciphertext);
        $url = $this->visitor('GET', "files/$id/download", [], $session['access_token'])->json('data.url');
        $download = $this->get($url);
        $download->assertOk();
        $this->assertSame('遠端附件的機密內容', $download->streamedContent());
        $other = clone $file;
        $other->object_key .= '-different-object';
        Storage::disk('r2')->put($other->object_key, $ciphertext);
        $this->expectException(\RuntimeException::class);
        app(Files::class)->disk($other)->get($other->object_key);
    }
}
