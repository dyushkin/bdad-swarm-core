<?php

declare(strict_types=1);

namespace ImpactGraph\Diff;

/**
 * Git-diff helpers: changed PHP files and file contents at a base revision.
 *
 * Covers the root repository and nested repositories (for example magento2ee, magento2b2b) whose modules are
 * symlinked into the root's app/code or ext tree.
 */
class GitDiff
{
    /**
     * @var array<string, string> root-relative path => repository that owns it
     */
    private array $owners = [];

    public function __construct(private readonly string $repoRoot)
    {
    }

    /**
     * @return string[]
     */
    public function changedPhpFiles(string $ref): array
    {
        $files = [];
        foreach ($this->repositories() as $repository) {
            foreach ($this->git($repository, 'diff --name-only ' . escapeshellarg($ref)) ?? [] as $line) {
                if (!$this->isSourcePath($line) || isset($this->owners[$line])) {
                    continue;
                }
                if ($repository !== $this->repoRoot && !is_file($this->repoRoot . '/' . $line)) {
                    continue;
                }
                $this->owners[$line] = $repository;
                $files[] = $line;
            }
        }
        return $files;
    }

    public function fileAtRef(string $ref, string $relativePath): ?string
    {
        $base = $this->baseRef($ref);
        $candidates = isset($this->owners[$relativePath])
            ? [$this->owners[$relativePath]]
            : $this->repositories();
        foreach ($candidates as $repository) {
            $lines = $this->git($repository, 'show ' . escapeshellarg($base . ':' . $relativePath) . ' 2>/dev/null');
            if ($lines !== null) {
                return implode("\n", $lines);
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function repositories(): array
    {
        $repositories = [$this->repoRoot];
        foreach (glob($this->repoRoot . '/*/.git') ?: [] as $gitDir) {
            $repository = dirname($gitDir);
            if (is_dir($repository . '/app/code') || is_dir($repository . '/ext')) {
                $repositories[] = $repository;
            }
        }
        return $repositories;
    }

    /**
     * @return string[]|null
     */
    private function git(string $repository, string $arguments): ?array
    {
        exec('git -C ' . escapeshellarg($repository) . ' ' . $arguments, $lines, $code);
        return $code === 0 ? $lines : null;
    }

    private function isSourcePath(string $path): bool
    {
        return str_ends_with($path, '.php')
            && (str_starts_with($path, 'app/code/') || str_starts_with($path, 'ext/'));
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
