<?php

declare(strict_types=1);

namespace ImpactGraph\Support;

/**
 * Recursively finds files by basename under given roots, pruning heavy non-config directories.
 */
final class FileScanner
{
    private const SKIP_DIRS = [
        'Test', 'view', 'i18n', 'node_modules', 'vendor', 'generated', 'var', 'pub', '.git',
    ];

    /**
     * @param string[] $roots
     * @return string[]
     */
    public function findByName(array $roots, string $basename): array
    {
        $found = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $directoryIterator = new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );
            $filter = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::SKIP_DIRS, true);
                    }
                    return true;
                }
            );
            foreach (new \RecursiveIteratorIterator($filter) as $file) {
                if ($file->getFilename() === $basename) {
                    $found[] = $file->getPathname();
                }
            }
        }
        return $found;
    }
}
