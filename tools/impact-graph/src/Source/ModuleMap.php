<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use ImpactGraph\Cache\EdgeCache;
use ImpactGraph\Support\FileScanner;

/**
 * Symlink-safe mapping between module names, filesystem paths, and namespaces.
 */
class ModuleMap
{
    /**
     * @var array<string, string> module name => canonical module root path
     */
    private array $modules = [];

    /**
     * @var array<int, array{path: string, module: string}> sorted by path length desc
     */
    private array $sorted = [];

    private bool $built = false;

    /**
     * @param string[] $roots
     */
    public function __construct(
        private readonly array $roots,
        private readonly FileScanner $scanner,
        private readonly EdgeCache $cache
    ) {
    }

    public function build(): void
    {
        if ($this->built) {
            return;
        }
        foreach ($this->scanner->findByName($this->roots, 'module.xml') as $file) {
            $meta = $this->cache->remember('module', $file, fn(): array => $this->parseModule($file));
            if ($meta['name'] !== '') {
                $this->modules[$meta['name']] = $meta['root'];
            }
        }
        foreach ($this->modules as $name => $path) {
            $this->sorted[] = ['path' => $path, 'module' => $name];
        }
        usort($this->sorted, static fn(array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));
        $this->built = true;
    }

    public function moduleForFile(string $file): ?string
    {
        $real = realpath($file) ?: $file;
        foreach ($this->sorted as $entry) {
            if (str_starts_with($real, $entry['path'] . DIRECTORY_SEPARATOR)) {
                return $entry['module'];
            }
        }
        return null;
    }

    public function moduleForClass(string $fqcn): ?string
    {
        $parts = explode('\\', ltrim($fqcn, '\\'));
        if (count($parts) < 2) {
            return null;
        }
        $candidate = $parts[0] . '_' . $parts[1];
        return isset($this->modules[$candidate]) ? $candidate : null;
    }

    public function pathForModule(string $module): ?string
    {
        return $this->modules[$module] ?? null;
    }

    public function classToFile(string $fqcn): ?string
    {
        $module = $this->moduleForClass($fqcn);
        if ($module === null) {
            return null;
        }
        $prefix = str_replace('_', '\\', $module);
        $tail = ltrim(substr(ltrim($fqcn, '\\'), strlen($prefix)), '\\');
        $file = $this->modules[$module] . '/' . str_replace('\\', '/', $tail) . '.php';
        return is_file($file) ? $file : null;
    }

    public function fileToClass(string $file): ?string
    {
        $module = $this->moduleForFile($file);
        if ($module === null) {
            return null;
        }
        $real = realpath($file) ?: $file;
        $relative = ltrim(substr($real, strlen($this->modules[$module])), '/');
        if (!str_ends_with($relative, '.php')) {
            return null;
        }
        $relative = substr($relative, 0, -4);
        $prefix = str_replace('_', '\\', $module);
        return $prefix . '\\' . str_replace('/', '\\', $relative);
    }

    /**
     * @return array{name: string, root: string}
     */
    private function parseModule(string $file): array
    {
        $xml = @simplexml_load_file($file);
        $name = $xml !== false ? (string)($xml->module['name'] ?? '') : '';
        $moduleRoot = dirname($file, 2);
        return ['name' => $name, 'root' => realpath($moduleRoot) ?: $moduleRoot];
    }
}
