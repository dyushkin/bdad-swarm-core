<?php

declare(strict_types=1);

namespace ImpactGraph\Test;

use ImpactGraph\Cache\EdgeCache;
use ImpactGraph\Source\MftfIndex;
use ImpactGraph\Source\ModuleMap;
use ImpactGraph\Support\FileScanner;
use PHPUnit\Framework\TestCase;

/**
 * Covers MFTF test discovery by module and group.
 */
final class MftfIndexTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = sys_get_temp_dir() . '/impact-graph-mftf-' . bin2hex(random_bytes(4));
        $module = $this->repoRoot . '/app/code/Vendor/Sample';
        $this->write($module . '/etc/module.xml', '<config><module name="Vendor_Sample"/></config>');
        $this->write($module . '/Test/Mftf/Test/TopLevelTest.xml', $this->testXml('TopLevelTest'));
        $this->write($module . '/Test/Mftf/Test/Variants/NestedTest.xml', $this->testXml('NestedTest'));
        $this->write($module . '/Test/Mftf/Test/Variants/Deeper/DeepTest.xml', $this->testXml('DeepTest'));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repoRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->repoRoot);
    }

    /**
     * Scenario: a module keeps some MFTF tests in subdirectories of Test/Mftf/Test.
     * Expected: module and group lookups include the nested tests.
     */
    public function testIndexesTestsInSubdirectories(): void
    {
        $cache = new EdgeCache($this->repoRoot . '/var/impact/edges.json');
        $moduleMap = new ModuleMap([$this->repoRoot . '/app/code'], new FileScanner(), $cache);
        $moduleMap->build();
        $index = new MftfIndex($this->repoRoot, $moduleMap, $cache);

        $expected = ['DeepTest', 'NestedTest', 'TopLevelTest'];
        self::assertSame($expected, $this->names($index->testsByModule('Vendor_Sample')));
        self::assertSame($expected, $this->names($index->testsByGroup('SampleGroup')));
    }

    /**
     * @param array<int, array{test: string}> $tests
     * @return string[]
     */
    private function names(array $tests): array
    {
        $names = array_column($tests, 'test');
        sort($names);
        return $names;
    }

    private function testXml(string $name): string
    {
        return '<tests><test name="' . $name . '"><annotations><group value="SampleGroup"/></annotations>'
            . '</test></tests>';
    }

    private function write(string $file, string $contents): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }
}
