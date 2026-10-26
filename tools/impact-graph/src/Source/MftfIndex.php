<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use ImpactGraph\Cache\EdgeCache;

/**
 * Indexes MFTF acceptance tests (name + groups + owning module) for dependent-domain test selection.
 */
final class MftfIndex
{
    /**
     * @var array<int, array{test: string, groups: string[], module: ?string, file: string}>
     */
    private array $tests = [];

    private bool $built = false;

    public function __construct(
        private readonly string $repoRoot,
        private readonly ModuleMap $moduleMap,
        private readonly EdgeCache $cache
    ) {
    }

    private function build(): void
    {
        if ($this->built) {
            return;
        }
        foreach ($this->testFiles() as $file) {
            $entries = $this->cache->remember('mftf', $file, fn(): array => $this->parseFile($file));
            $module = $this->moduleMap->moduleForFile($file);
            foreach ($entries as $entry) {
                $this->tests[] = [
                    'test' => $entry['test'],
                    'groups' => $entry['groups'],
                    'module' => $module,
                    'file' => $file,
                ];
            }
        }
        $this->built = true;
    }

    /**
     * @return array<int, array{test: string, groups: string[], module: ?string, file: string}>
     */
    public function testsByGroup(string $group): array
    {
        $this->build();
        return array_values(array_filter(
            $this->tests,
            static fn(array $test): bool => in_array($group, $test['groups'], true)
        ));
    }

    /**
     * @return array<int, array{test: string, groups: string[], module: ?string, file: string}>
     */
    public function testsByModule(string $module): array
    {
        $this->build();
        return array_values(array_filter(
            $this->tests,
            static fn(array $test): bool => $test['module'] === $module
        ));
    }

    /**
     * @return string[]
     */
    private function testFiles(): array
    {
        $patterns = [
            $this->repoRoot . '/app/code/*/*/Test/Mftf/Test/*.xml',
            $this->repoRoot . '/ext/*/*/*/Test/Mftf/Test/*.xml',
        ];
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * @return array<int, array{test: string, groups: string[]}>
     */
    private function parseFile(string $file): array
    {
        $entries = [];
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            return $entries;
        }
        foreach ($xml->test as $test) {
            $groups = [];
            foreach ($test->annotations->group ?? [] as $group) {
                $groups[] = (string)$group['value'];
            }
            $entries[] = ['test' => (string)$test['name'], 'groups' => $groups];
        }
        return $entries;
    }
}
