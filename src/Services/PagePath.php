<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Support\Str;

/**
 * Kept in sync with PagePathHelper::normalize() and FileHelper (SimpleStats backend),
 * so a page reads identically as entry page and as viewed page. Case is kept on purpose.
 */
class PagePath
{
    public const MAX_LENGTH = 255;

    protected const NON_PAGE_EXTENSIONS = [
        // images
        'jpg', 'jpeg', 'png', 'apng', 'gif', 'bmp', 'webp', 'svg', 'tif', 'tiff', 'avif', 'ico',
        // documents
        'css', 'js', 'js.map', 'json', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'xml', 'yaml',
        // media
        'mp3', 'wav', 'flac', 'mp4', 'avi', 'mov', 'mkv', 'webm', 'ogg', 'wmv',
        // archive
        'zip', 'tar', 'gz', '7z', 'rar', 'bak', 'sql', 'tgz',
        // server
        'env', 'ini', 'sh', 'inc', 'exe', 'settings', 'back', 'fileupload', 'conf',
    ];

    public static function normalize(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = Str::before(Str::before($path, '#'), '?');

        if (! Str::startsWith($path, '/')) {
            return null;
        }

        if ($path !== '/' && Str::endsWith($path, '/')) {
            $path = Str::beforeLast($path, '/');
        }

        if (self::hasNonPageExtension($path)) {
            return null;
        }

        // The backend cuts at 252 characters plus "...", so any limit above that
        // yields the same stored page while keeping batches small.
        return Str::substr($path, 0, self::MAX_LENGTH);
    }

    /**
     * Matches only a dotted extension of the last path segment, so slugs that merely end in
     * those letters (e.g. "/accept-json", "/commit-hash", "/holding-you-back") stay pages.
     */
    protected static function hasNonPageExtension(string $path): bool
    {
        $lastSegment = Str::lower(Str::afterLast($path, '/'));
        $extensions = array_map(fn (string $extension): string => '.'.$extension, self::NON_PAGE_EXTENSIONS);

        return Str::endsWith($lastSegment, $extensions);
    }
}
