<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use ImpactGraph\Cache\EdgeCache;
use ImpactGraph\Support\FileScanner;

/**
 * Extracts INTERCEPTS (plugins) and PREFERS (preferences) edges from every di.xml (source of truth).
 */
final class DiXmlReader
{
    /**
     * @var array<int, array{subject: string, plugin: string, name: string, sortOrder: string, file: string}>
     */
    private array $intercepts = [];

    /**
     * @var array<int, array{for: string, type: string, file: string}>
     */
    private array $preferences = [];

    private bool $parsed = false;

    /**
     * @param string[] $roots
     */
    public function __construct(
        private readonly array $roots,
        private readonly FileScanner $scanner,
        private readonly ModuleMap $moduleMap,
        private readonly EdgeCache $cache
    ) {
    }

    private function parse(): void
    {
        if ($this->parsed) {
            return;
        }
        foreach ($this->scanner->findByName($this->roots, 'di.xml') as $file) {
            $data = $this->cache->remember('di', $file, fn(): array => $this->parseFile($file));
            foreach ($data['intercepts'] as $edge) {
                $this->intercepts[] = $edge;
            }
            foreach ($data['preferences'] as $edge) {
                $this->preferences[] = $edge;
            }
        }
        $this->parsed = true;
    }

    /**
     * @return array{intercepts: array<int, array<string, string>>, preferences: array<int, array<string, string>>}
     */
    private function parseFile(string $file): array
    {
        $intercepts = [];
        $preferences = [];
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            return ['intercepts' => $intercepts, 'preferences' => $preferences];
        }
        foreach ($xml->type as $type) {
            $subject = ltrim((string)$type['name'], '\\');
            foreach ($type->plugin as $plugin) {
                if (((string)$plugin['disabled']) === 'true') {
                    continue;
                }
                $intercepts[] = [
                    'subject' => $subject,
                    'plugin' => ltrim((string)$plugin['type'], '\\'),
                    'name' => (string)$plugin['name'],
                    'sortOrder' => (string)$plugin['sortOrder'],
                    'file' => $file,
                ];
            }
        }
        foreach ($xml->preference as $preference) {
            $preferences[] = [
                'for' => ltrim((string)$preference['for'], '\\'),
                'type' => ltrim((string)$preference['type'], '\\'),
                'file' => $file,
            ];
        }
        return ['intercepts' => $intercepts, 'preferences' => $preferences];
    }

    /**
     * @return array<int, array{plugin: string, name: string, methods: string[], module: ?string, file: string}>
     */
    public function pluginsForSubject(string $subject): array
    {
        $this->parse();
        $needle = ltrim($subject, '\\');
        $result = [];
        foreach ($this->intercepts as $edge) {
            if ($edge['subject'] === $needle) {
                $result[] = $this->describePlugin($edge);
            }
        }
        return $result;
    }

    /**
     * @return array<int, array{subject: string, plugin: string, methods: string[], module: ?string, file: string}>
     */
    public function pluginsForMethod(string $method): array
    {
        $this->parse();
        $target = lcfirst($method);
        $result = [];
        foreach ($this->intercepts as $edge) {
            $methods = $this->resolvePluginMethods($edge['plugin']);
            if (in_array($target, $methods, true)) {
                $result[] = [
                    'subject' => $edge['subject'],
                    'plugin' => $edge['plugin'],
                    'methods' => $methods,
                    'module' => $this->moduleMap->moduleForClass($edge['plugin']),
                    'file' => $edge['file'],
                ];
            }
        }
        return $result;
    }

    /**
     * @return array<int, array{type: string, module: ?string, file: string}>
     */
    public function preferencesFor(string $interface): array
    {
        $this->parse();
        $needle = ltrim($interface, '\\');
        $result = [];
        foreach ($this->preferences as $preference) {
            if ($preference['for'] === $needle) {
                $result[] = [
                    'type' => $preference['type'],
                    'module' => $this->moduleMap->moduleForClass($preference['type']),
                    'file' => $preference['file'],
                ];
            }
        }
        return $result;
    }

    /**
     * @param array{subject: string, plugin: string, name: string, sortOrder: string, file: string} $edge
     * @return array{plugin: string, name: string, methods: string[], module: ?string, file: string}
     */
    private function describePlugin(array $edge): array
    {
        return [
            'plugin' => $edge['plugin'],
            'name' => $edge['name'],
            'methods' => $this->resolvePluginMethods($edge['plugin']),
            'module' => $this->moduleMap->moduleForClass($edge['plugin']),
            'file' => $edge['file'],
        ];
    }

    /**
     * Light method resolution: regex the plugin class for before/after/around handlers.
     * Phase 1 replaces this with a php-parser AST pass.
     *
     * @return string[]
     */
    private function resolvePluginMethods(string $pluginClass): array
    {
        $file = $this->moduleMap->classToFile($pluginClass);
        if ($file === null) {
            return [];
        }
        $code = (string)file_get_contents($file);
        if (!preg_match_all('/function\s+(before|after|around)([A-Z][A-Za-z0-9_]*)\s*\(/', $code, $matches)) {
            return [];
        }
        $methods = array_map(static fn(string $name): string => lcfirst($name), $matches[2]);
        return array_values(array_unique($methods));
    }
}
