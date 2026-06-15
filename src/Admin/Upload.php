<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Validates and stores uploaded images under /media. No external service —
 * files land on disk and are referenced by filename in the DB (same convention
 * as the migrated catalog). Filenames are slugified + de-duplicated.
 */
final class Upload
{
    private const ALLOWED = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const MAX_BYTES = 12_000_000; // 12 MB

    public function __construct(private string $mediaRoot) {}

    /**
     * Store files into /media/{subdir}. Returns stored {filename,url} + errors.
     * @param array<int,UploadedFileInterface> $files
     * @return array{ok:array<int,array{filename:string,url:string}>,errors:array<int,string>}
     */
    public function store(array $files, string $subdir): array
    {
        $subdir = trim($subdir, '/');
        $absDir = $this->mediaRoot . '/' . $subdir;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0775, true);
        }
        $ok = [];
        $errors = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors[] = 'Eroare la încărcare.';
                continue;
            }
            $size = $file->getSize();
            if ($size !== null && $size > self::MAX_BYTES) {
                $errors[] = 'Fișier prea mare (max 12 MB).';
                continue;
            }
            $orig = (string) ($file->getClientFilename() ?? 'imagine');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!isset(self::ALLOWED[$ext])) {
                $errors[] = 'Tip nepermis: ' . $orig . ' (doar jpg/png/webp).';
                continue;
            }
            $base = slugify(pathinfo($orig, PATHINFO_FILENAME)) ?: 'imagine';
            $filename = $this->uniqueName($absDir, $base, $ext);
            $full = $absDir . '/' . $filename;

            try {
                $file->moveTo($full);
            } catch (\Throwable) {
                $errors[] = 'Nu am putut salva ' . $orig . '.';
                continue;
            }
            // Confirm it is really an image (defense in depth).
            if (@getimagesize($full) === false) {
                @unlink($full);
                $errors[] = 'Fișier invalid: ' . $orig . '.';
                continue;
            }
            $ok[] = [
                'filename' => $filename,
                'url'      => '/media/' . $subdir . '/' . rawurlencode($filename),
            ];
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    private function uniqueName(string $dir, string $base, string $ext): string
    {
        $name = $base . '.' . $ext;
        $i = 1;
        while (is_file($dir . '/' . $name)) {
            $name = $base . '-' . $i . '.' . $ext;
            $i++;
        }
        return $name;
    }
}
