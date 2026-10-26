<?php

declare(strict_types=1);

namespace ImpactGraph\Cache;

/**
 * Thin, incremental edge cache keyed by per-file content hash. Not a whole-repo graph: it memoizes only the
 * parse results of files an actual run touched, invalidating each entry surgically when its file changes.
 */
class EdgeCache
{
    /**
     * @var array<string, array<string, array{hash: string, data: mixed}>>
     */
    private array $store = [];

    private bool $dirty = false;

    public function __construct(private readonly string $file)
    {
        if (is_file($this->file)) {
            $decoded = json_decode((string)file_get_contents($this->file), true);
            if (is_array($decoded)) {
                $this->store = $decoded;
            }
        }
    }

    public function remember(string $namespace, string $file, callable $producer): mixed
    {
        $hash = is_file($file) ? (string)sha1_file($file) : '';
        $entry = $this->store[$namespace][$file] ?? null;
        if ($entry !== null && $entry['hash'] === $hash) {
            return $entry['data'];
        }
        $data = $producer();
        $this->store[$namespace][$file] = ['hash' => $hash, 'data' => $data];
        $this->dirty = true;
        return $data;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($this->file, json_encode($this->store, JSON_UNESCAPED_SLASHES));
        $this->dirty = false;
    }

    public function __destruct()
    {
        $this->save();
    }
}
