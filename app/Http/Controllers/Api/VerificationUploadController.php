<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\MerchantVerificationDocuments as Documents;
use App\Services\PrivateDocumentStream;
use App\Services\RegistrationVerification;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Small encrypted parts avoid proxy/PHP single-request limits without relaxing normal API limits. */
class VerificationUploadController
{
    private function context(Request $r, bool $lock = false): array
    {
        if ($r->is('api/registration/*')) {
            $v = $r->validate(['challenge_id' => 'required|uuid', 'verification_token' => 'required|string|size:64']);
            $q = DB::table('registration_email_verifications')->where('id', $v['challenge_id']);
            $c = ($lock ? $q->lockForUpdate() : $q)->first();
            app(RegistrationVerification::class)->requireValid($c, $v['verification_token']);
            return [null, $c->id, null];
        }
        $m = app(ApiAccess::class)->merchant($r);
        if ($lock) {
            $fresh = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            abort_unless($fresh, 404);
            abort_if(in_array($fresh->status, ['suspended', 'inactive'], true), 403, 'Business access is suspended.');
            abort_if($r->attributes->get('api_role') === 'merchant' && (string) $fresh->merchant_account_id !== (string) $r->attributes->get('api_actor')->id, 403, 'Business ownership changed. Refresh before uploading.');
        }
        return [$m->id, null, (string) $r->attributes->get('api_actor')->id];
    }

    private function scoped(array $context)
    {
        [$merchant, $challenge, $actor] = $context;
        return DB::table('verification_upload_sessions')->where('merchant_id', $merchant)->where('challenge_id', $challenge)->where('actor_id', $actor);
    }

    public function start(Request $r)
    {
        $v = $r->validate(['kind' => ['required', Rule::in(array_keys(Documents::KINDS))], 'filename' => 'required|string|max:255', 'bytes' => 'required|integer|min:1|max:'.Documents::MAX_BYTES]);
        abort_unless(preg_match('/\.(pdf|png|jpe?g)$/i', $v['filename']) && ! preg_match('/[\x00-\x1F]/', $v['filename']), 422, 'Choose a PDF, PNG or JPG no larger than 100 MB.');
        return DB::transaction(function () use ($r, $v) {
            $context = $this->context($r, true);
            abort_if($this->scoped($context)->whereNull('document_id')->where('expires_at', '>', now())->count() >= 3, 429, 'Finish an existing document upload before starting another.');
            $id = (string) Str::uuid();
            DB::table('verification_upload_sessions')->insert(['id' => $id, 'merchant_id' => $context[0], 'challenge_id' => $context[1], 'actor_id' => $context[2], 'kind' => $v['kind'], 'filename_encrypted' => Crypt::encryptString(basename($v['filename'])), 'bytes' => $v['bytes'], 'next_index' => 0, 'expires_at' => now()->addHours(2), 'created_at' => now()]);
            return response()->json(['id' => $id, 'chunk_bytes' => PrivateDocumentStream::BLOCK_BYTES, 'max_bytes' => Documents::MAX_BYTES], 201)->header('Cache-Control', 'private, no-store');
        });
    }

    public function chunk(Request $r, string $id)
    {
        $v = $r->validate(['index' => 'required|integer|min:0|max:99', 'file' => 'required|file|max:1024']);
        return DB::transaction(function () use ($r, $id, $v) {
            $u = $this->scoped($this->context($r))->where('id', $id)->lockForUpdate()->first();
            abort_unless($u, 404);
            abort_if(now()->gte($u->expires_at), 410, 'This upload expired. Select the file again.');
            abort_if($u->document_id, 409, 'This upload is already complete.');
            $index = (int) $v['index'];
            $expected = min(PrivateDocumentStream::BLOCK_BYTES, $u->bytes - $index * PrivateDocumentStream::BLOCK_BYTES);
            abort_unless($expected > 0 && $r->file('file')->getSize() === $expected, 422, 'The upload part has an unexpected length.');
            abort_if($index > $u->next_index, 409, 'Upload parts must arrive in order.');
            $path = 'verification-uploads/'.$u->id.'/'.$index.'.enc';
            $raw = file_get_contents($r->file('file')->getRealPath());
            $disk = Storage::disk('local');
            if ($index < $u->next_index) {
                abort_unless($disk->exists($path) && hash_equals(hash('sha256', Crypt::decryptString($disk->get($path))), hash('sha256', $raw)), 409, 'A different upload part was already received.');
            } else {
                abort_unless($disk->put($path, Crypt::encryptString($raw)), 503, 'Private upload storage is unavailable.');
                chmod($disk->path($path), 0600);
                DB::table('verification_upload_sessions')->where('id', $id)->update(['next_index' => $index + 1]);
            }
            return response()->json(['next_index' => max($u->next_index, $index + 1)])->header('Cache-Control', 'private, no-store');
        });
    }

    public function complete(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $u = $this->scoped($this->context($r, true))->where('id', $id)->lockForUpdate()->first();
            abort_unless($u, 404);
            abort_if(now()->gte($u->expires_at), 410, 'This upload expired. Select the file again.');
            if ($u->document_id) {
                return $this->result($u);
            }
            $count = (int) ceil($u->bytes / PrivateDocumentStream::BLOCK_BYTES);
            abort_unless($u->next_index === $count, 409, 'Some file parts have not arrived. Wait for the upload to finish.');
            $temp = tmpfile();
            abort_unless($temp, 503, 'Temporary document storage is unavailable.');
            try {
                $bytes = 0;
                for ($i = 0; $i < $count; $i++) {
                    $raw = Crypt::decryptString(Storage::disk('local')->get('verification-uploads/'.$u->id.'/'.$i.'.enc'));
                    abort_unless(strlen($raw) === min(PrivateDocumentStream::BLOCK_BYTES, $u->bytes - $bytes), 409, 'Upload part integrity check failed.');
                    abort_unless(fwrite($temp, $raw) === strlen($raw), 503, 'Temporary document storage is full.');
                    $bytes += strlen($raw);
                }
                $file = new UploadedFile(stream_get_meta_data($temp)['uri'], Crypt::decryptString($u->filename_encrypted), null, UPLOAD_ERR_OK, true);
                // Registration proof validation may have cached the original empty file bag.
                $assembled = Request::createFrom($r);
                $assembled->merge(['kind' => $u->kind]);
                $assembled->files->set('file', $file);
                $controller = app(MerchantVerificationDocumentController::class);
                $response = $u->merchant_id ? $controller->upload($assembled) : $controller->stage($assembled);
                $payload = $response->getData(true);
                $document = $u->merchant_id ? collect($payload['items'])->firstWhere('kind', $u->kind)['id'] : $payload['id'];
                DB::table('verification_upload_sessions')->where('id', $id)->update(['document_id' => $document]);
                // The committed document has its own encryption. Temporary encrypted parts can be retired after commit.
                DB::afterCommit(fn () => Storage::disk('local')->deleteDirectory('verification-uploads/'.$id));
                return $response;
            } finally {
                fclose($temp);
            }
        });
    }

    private function result(object $u)
    {
        return response()->json($u->merchant_id ? app(Documents::class)->present($u->merchant_id) : ['id' => $u->document_id, 'kind' => $u->kind, 'status' => 'pending'], $u->merchant_id ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }
}
