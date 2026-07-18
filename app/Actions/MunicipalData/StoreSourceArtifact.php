<?php

namespace App\Actions\MunicipalData;

use App\DTO\MunicipalData\SourceArtifact;
use App\DTO\MunicipalData\StoredSourceArtifact;
use App\Models\DataSource;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StoreSourceArtifact
{
    public function fromFile(DataSource $source, string $filePath): StoredSourceArtifact
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

        return new StoredSourceArtifact(
            disk: $disk,
            path: $path,
            checksum: $checksum,
            mimeType: mime_content_type($filePath) ?: 'application/octet-stream',
            sizeBytes: filesize($filePath) ?: 0,
        );
    }

    public function fromFetched(DataSource $source, SourceArtifact $artifact): StoredSourceArtifact
    {
        $checksum = hash('sha256', $artifact->contents);
        $disk = (string) config('municipal_data.disk');
        $path = $this->path($source, $checksum, $artifact->extension);

        if (! Storage::disk($disk)->put($path, $artifact->contents)) {
            throw new RuntimeException('Unable to store fetched source artifact.');
        }

        return new StoredSourceArtifact(
            disk: $disk,
            path: $path,
            checksum: $checksum,
            mimeType: $artifact->mimeType,
            sizeBytes: strlen($artifact->contents),
        );
    }

    private function path(DataSource $source, string $checksum, string $extension): string
    {
        $base = trim((string) config('municipal_data.artifact_path'), '/');

        return "{$base}/{$source->slug}/".substr($checksum, 0, 2)."/{$checksum}.{$extension}";
    }
}
