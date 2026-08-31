<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class ImageUpload
{
    private const MAX_BYTES = 5_242_880;

    public static function fromBase64(?string $payload, string $filename = 'capture.jpg'): ?UploadedFile
    {
        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        $payload = trim($payload);
        $extension = 'jpg';

        if (preg_match('/^data:image\/(jpeg|jpg|png|webp|gif);base64,(.+)$/is', $payload, $matches) === 1) {
            $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
            $binary = base64_decode($matches[2], true);
        } else {
            $binary = base64_decode($payload, true);
        }

        if ($binary === false || strlen($binary) < 32 || strlen($binary) > self::MAX_BYTES) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'srs-photo-');
        if ($temporary === false) {
            return null;
        }

        file_put_contents($temporary, $binary);

        $mime = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        return new UploadedFile(
            $temporary,
            pathinfo($filename, PATHINFO_FILENAME).'.'.$extension,
            $mime,
            null,
            true,
        );
    }
}
