<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentService
{
    public const DISK = 'local';

    public function store(Model $owner, UploadedFile $file, DocumentType $type, ?User $actor = null): Document
    {
        $path = $file->store('documents/'.$type->value, self::DISK);

        return $owner->documents()->create([
            'type' => $type,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'uploaded_by' => $actor?->id,
        ]);
    }

    public function storeFile(UploadedFile $file, DocumentType $type, string $documentableType, int $documentableId, ?User $actor = null): Document
    {
        $path = $file->store('documents/'.$type->value, self::DISK);

        return Document::query()->create([
            'documentable_type' => $documentableType,
            'documentable_id' => $documentableId,
            'type' => $type,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'uploaded_by' => $actor?->id,
        ]);
    }

    public function download(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);
        assert($disk instanceof FilesystemAdapter);

        return $disk->download(
            $document->path,
            $document->original_name,
        );
    }

    public function delete(?Document $document): void
    {
        if ($document === null) {
            return;
        }

        Storage::disk($document->disk)->delete($document->path);
        $document->delete();
    }
}
