<?php

namespace App\Actions\MunicipalData;

use App\Models\DataSource;
use App\MunicipalData\SourceArtifact;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StoreSourceArtifact
{
    /**
     * @return array{disk: string, path: string, checksum: string, mime_type: string, size_bytes: int}
     */
    public function fromFile(DataSource $source, string $filePath): array
    {
        $checksum = hash_file('sha256', $filePath);

        if ($checksum === false) {
            throw new RuntimeException("Unable to hash source artifact: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'bin';
        $disk = (string) config('municipal_data.disk');
        $path = $this->path($source, $checksum, $extension);
        $stream = fopen($filePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open source artifact: {$filePath}");
        }

        try {
            if (! Storage::disk($disk)->put($path, $stream)) {
                throw new RuntimeException("Unable to store source artifact: {$filePath}");
            }
        } finally {
            fclose($stream);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'checksum' => $checksum,
            'mime_type' => mime_content_type($filePath) ?: 'application/octet-stream',
            'size_bytes' => filesize($filePath) ?: 0,
        ];
    }

    /**
     * @return array{disk: string, path: string, checksum: string, mime_type: string, size_bytes: int}
     */
    public function fromFetched(DataSource $source, SourceArtifact $artifact): array
    {
        $checksum = hash('sha256', $artifact->contents);
        $disk = (string) config('municipal_data.disk');
        $path = $this->path($source, $checksum, $artifact->extension);

        if (! Storage::disk($disk)->put($path, $artifact->contents)) {
            throw new RuntimeException('Unable to store fetched source artifact.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'checksum' => $checksum,
            'mime_type' => $artifact->mimeType,
            'size_bytes' => strlen($artifact->contents),
        ];
    }

    private function path(DataSource $source, string $checksum, string $extension): string
    {
        $base = trim((string) config('municipal_data.artifact_path'), '/');

        return "{$base}/{$source->slug}/".substr($checksum, 0, 2)."/{$checksum}.{$extension}";
    }
}
