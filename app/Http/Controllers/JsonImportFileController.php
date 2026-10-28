<?php

namespace App\Http\Controllers;

use App\Services\Import\JsonImportSource;
use App\Services\Import\JsonImportSourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class JsonImportFileController extends Controller
{
    public function begin(Request $request, JsonImportSource $sources): JsonResponse
    {
        $userId = $this->userId($request);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.json\z/iD'],
            'size' => ['required', 'integer', 'min:2'],
            'supplier' => ['required', 'string', 'exists:suppliers,code'],
        ]);

        try {
            $source = $sources->create($userId, $input['supplier'], $input['name'], (int) $input['size']);

            return $this->respond([
                'id' => $source['id'],
                'chunk_size' => JsonImportSource::CHUNK_BYTES,
                'received' => (int) $source['received'],
                'total' => (int) $input['size'],
                'size' => (int) $input['size'],
            ], 201);
        } catch (JsonImportSourceException $e) {
            return $this->failure($e);
        }
    }

    public function chunk(Request $request, string $id, JsonImportSource $sources): JsonResponse
    {
        $userId = $this->userId($request);
        $offset = $request->header('X-Upload-Offset');

        if (! is_string($offset) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $offset)
            || filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw ValidationException::withMessages(['offset' => __('admin.json_chunk_invalid')]);
        }

        if ((int) $request->server('CONTENT_LENGTH', 0) > JsonImportSource::CHUNK_BYTES) {
            return $this->respond(['error' => 'chunk_too_large'], 413);
        }

        // Authenticate ownership before creating even a temporary request file.
        try {
            $sources->info($userId, $id);
        } catch (JsonImportSourceException $e) {
            return $this->failure($e);
        }

        $directory = storage_path('app/private/json-import-request-chunks');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return $this->respond(['error' => 'insufficient_disk'], 507);
        }
        $path = @tempnam($directory, 'chunk-');

        if ($path === false || realpath(dirname($path)) !== realpath($directory)) {
            if ($path !== false) {
                File::delete($path);
            }

            return $this->respond(['error' => 'insufficient_disk'], 507);
        }

        chmod($path, 0600);
        $input = null;
        $output = null;

        try {
            $input = $request->getContent(asResource: true);
            $output = fopen($path, 'wb');

            if (! is_resource($input) || ! is_resource($output)) {
                return $this->respond(['error' => 'chunk_unreadable'], 422);
            }

            $written = stream_copy_to_stream($input, $output, JsonImportSource::CHUNK_BYTES + 1);
            fflush($output);
            fclose($output);
            $output = null;

            if ($written === false || $written < 1 || $written > JsonImportSource::CHUNK_BYTES) {
                return $this->respond(['error' => $written > JsonImportSource::CHUNK_BYTES ? 'chunk_too_large' : 'chunk_unreadable'],
                    $written > JsonImportSource::CHUNK_BYTES ? 413 : 422);
            }

            $source = $sources->append($userId, $id, (int) $offset, $path);

            return $this->respond([
                'received' => (int) $source['received'],
                'total' => (int) ($source['size'] ?? $source['totalBytes'] ?? $sources->info($userId, $id)['size']),
            ]);
        } catch (JsonImportSourceException $e) {
            return $this->failure($e);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            File::delete($path);
        }
    }

    public function finish(Request $request, string $id, JsonImportSource $sources): JsonResponse
    {
        $userId = $this->userId($request);

        try {
            $source = $sources->finalize($userId, $id);

            return $this->respond(array_intersect_key($source, array_flip(['id', 'name', 'size', 'sha256', 'supplier', 'state'])));
        } catch (JsonImportSourceException $e) {
            return $this->failure($e);
        }
    }

    protected function userId(Request $request): int
    {
        abort_unless($request->user()?->isStaff() && (int) $request->user()->getAuthIdentifier() > 0, 403);

        return (int) $request->user()->getAuthIdentifier();
    }

    protected function failure(JsonImportSourceException $e): JsonResponse
    {
        return $this->respond(['error' => $e->reason], $e->status);
    }

    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
