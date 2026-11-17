<?php

namespace App\Services\Warranties;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WarrantyPrivateStorage
{
    public static function within(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root.'/');
    }

    public function put(string $path, string $bytes): void
    {
        if (! preg_match('~\Awarranties/(?:documents|certificates)/[0-9A-HJKMNP-TV-Z]{26}\.(?:pdf|docx)\z~D', $path)) {
            $this->invalid();
        }
        $disk = Storage::disk(config('warranty.disk', 'local'));
        $anchor = realpath(storage_path('app'));
        $candidate = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $disk->path('')), DIRECTORY_SEPARATOR);
        if ($anchor === false || ! self::within($candidate, $anchor)
            || preg_match('~(?:\A|/)\.\.?(?:/|\z)~', str_replace('\\', '/', $candidate))) {
            $this->invalid();
        }
        $parent = $candidate;
        while (! is_dir($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false || ! self::within($resolvedParent, $anchor)) {
            $this->invalid();
        }
        if (! is_dir($candidate) && ! mkdir($candidate, 0700, true) && ! is_dir($candidate)) {
            $this->invalid();
        }
        $root = realpath($candidate);
        $public = realpath(public_path());
        if ($root === false || ! self::within($root, $anchor)
            || ($public !== false && self::within($root, $public))) {
            $this->invalid();
        }
        $directory = dirname($disk->path($path));
        $nearest = $directory;
        while (! is_dir($nearest) && dirname($nearest) !== $nearest) {
            $nearest = dirname($nearest);
        }
        $resolved = realpath($nearest);
        if ($resolved === false || ! self::within($resolved, $root)) {
            $this->invalid();
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->invalid();
        }
        $resolved = realpath($directory);
        $fullPath = $disk->path($path);
        if ($resolved === false || ! self::within($resolved, $root) || file_exists($fullPath) || is_link($fullPath)) {
            $this->invalid();
        }
        // Exclusive creation never replaces an existing original or an already frozen PDF.
        $handle = fopen($fullPath, 'x+b');
        if ($handle === false) {
            $this->invalid();
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    $this->invalid();
                }
                $offset += $written;
            }
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
            chmod($fullPath, 0600);
        } catch (\Throwable $error) {
            fclose($handle);
            unlink($fullPath);
            throw $error;
        }
        fclose($handle);
    }

    protected function invalid(): never
    {
        throw ValidationException::withMessages(['document' => __('warranty.errors.private_storage_invalid')]);
    }
}
