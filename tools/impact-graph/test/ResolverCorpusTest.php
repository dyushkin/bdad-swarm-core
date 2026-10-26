<?php

declare(strict_types=1);

namespace ImpactGraph\Test;

use ImpactGraph\Cache\EdgeCache;
use ImpactGraph\Diff\GitDiff;
use ImpactGraph\Resolve\Resolver;
use ImpactGraph\Source\CollaboratorScanner;
use ImpactGraph\Source\MftfIndex;
use ImpactGraph\Source\MockScanner;
use ImpactGraph\Source\ModuleMap;
use ImpactGraph\Support\FileScanner;
use PHPUnit\Framework\TestCase;

/**
 * Regression-replay corpus: asserts the Resolver's stale-mock recall on historical tickets.
 */
final class ResolverCorpusTest extends TestCase
{
    private string $repoRoot;
    private GitDiff $git;
    private Resolver $resolver;
    private MftfIndex $mftfIndex;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 4);
        $this->git = new GitDiff($this->repoRoot);
        $this->resolver = new Resolver(
            $this->repoRoot,
            $this->git,
            new CollaboratorScanner(),
            new MockScanner()
        );
        $roots = [$this->repoRoot . '/app/code', $this->repoRoot . '/ext'];
        $cache = new EdgeCache($this->repoRoot . '/var/impact/edges.json');
        $moduleMap = new ModuleMap($roots, new FileScanner(), $cache);
        $moduleMap->build();
        $this->mftfIndex = new MftfIndex($this->repoRoot, $moduleMap, $cache);
    }

    /**
     * Scenario: replay each corpus diff through the Resolver's mock-impact analysis.
     * Expected: every corpus entry's must_flag_mock test is flagged with its expected new collaborators.
     */
    public function testCorpusMockImpactRecall(): void
    {
        $entries = $this->corpus();
        self::assertNotEmpty($entries, 'No corpus fixtures found.');
        foreach ($entries as $name => $entry) {
            $ref = (string)$entry['diff_ref'];
            if ($this->git->changedPhpFiles($ref) === []) {
                self::markTestSkipped("Corpus '$name': diff '$ref' not available in this checkout.");
            }
            $report = $this->resolver->mockImpact($ref);
            $flag = $this->findFlag($report['mock_impact'], (string)$entry['must_flag_mock']);
            self::assertNotNull(
                $flag,
                "Corpus '$name': expected a stale-mock flag for {$entry['must_flag_mock']}."
            );
            foreach ($entry['expected_new_collaborators'] as $collaborator) {
                self::assertContains(
                    $collaborator,
                    $flag['missing_stubs'],
                    "Corpus '$name': expected '$collaborator' among missing stubs for {$entry['must_flag_mock']}."
                );
            }
        }
    }

    /**
     * Scenario: the acceptance test each corpus entry must run is discoverable by its group in the MFTF index.
     * Expected: MftfIndex.testsByGroup(must_include_group) contains must_include_test.
     */
    public function testCorpusAcceptanceTestIsIndexed(): void
    {
        foreach ($this->corpus() as $name => $entry) {
            $names = array_column(
                $this->mftfIndex->testsByGroup((string)$entry['must_include_group']),
                'test'
            );
            self::assertContains(
                $entry['must_include_test'],
                $names,
                "Corpus '$name': MFTF index should find {$entry['must_include_test']} "
                . "in group {$entry['must_include_group']}."
            );
        }
    }

    /**
     * Scenario: auto-selecting the acceptance test directly from a header-only diff (no explicit group hint).
     * Expected: deferred — needs Phase 1 CALLS; recall is held meanwhile via domains.md adjacency.
     */
    public function testCorpusAcceptanceSelectionFromDiffRequiresCallGraph(): void
    {
        self::markTestIncomplete(
            'Diff-driven acceptance selection (header-only diff -> item-cancel event) needs Phase 1 CALLS; '
            . 'recall is currently held via domains.md adjacency + MFTF group/module index.'
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function corpus(): array
    {
        $entries = [];
        foreach (glob(__DIR__ . '/corpus/*.json') ?: [] as $file) {
            $entries[basename($file)] = json_decode((string)file_get_contents($file), true);
        }
        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     * @return array<string, mixed>|null
     */
    private function findFlag(array $flags, string $testMethod): ?array
    {
        foreach ($flags as $flag) {
            if ($flag['test'] === $testMethod || str_ends_with((string)$flag['test'], $testMethod)) {
                return $flag;
            }
        }
        return null;
    }
}
