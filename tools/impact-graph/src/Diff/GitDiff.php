<?php

declare(strict_types=1);

namespace ImpactGraph\Diff;

/**
 * Git-diff helpers: changed PHP files and file contents at a base revision.
 */
class GitDiff
{
    public function __construct(private readonly string $repoRoot)
    {
    }

    /**
     * @return string[]
     */
    public function changedPhpFiles(string $ref): array
    {
        $command = 'git -C ' . escapeshellarg($this->repoRoot)
            . ' diff --name-only ' . escapeshellarg($ref);
        exec($command, $lines, $code);
        if ($code !== 0) {
            return [];
        }
        return array_values(array_filter(
            $lines,
            static fn(string $line): bool =>
                str_ends_with($line, '.php')
                && (str_starts_with($line, 'app/code/') || str_starts_with($line, 'ext/'))
        ));
    }

    public function fileAtRef(string $ref, string $relativePath): ?string
    {
        $base = $this->baseRef($ref);
        $command = 'git -C ' . escapeshellarg($this->repoRoot)
            . ' show ' . escapeshellarg($base . ':' . $relativePath) . ' 2>/dev/null';
        exec($command, $lines, $code);
        return $code === 0 ? implode("\n", $lines) : null;
    }

    private function baseRef(string $ref): string
    {
        foreach (['...', '..'] as $separator) {
            if (str_contains($ref, $separator)) {
                return explode($separator, $ref, 2)[0] ?: 'HEAD';
            }
        }
        return $ref;
    }
}
