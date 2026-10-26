<?php

declare(strict_types=1);

namespace ImpactGraph\Resolve;

use ImpactGraph\Diff\GitDiff;
use ImpactGraph\Source\CollaboratorScanner;
use ImpactGraph\Source\MockScanner;

/**
 * Correlates a diff's new collaborators against base-revision unit-test stub sets to flag stale mocks.
 */
class Resolver
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly GitDiff $git,
        private readonly CollaboratorScanner $collaborators,
        private readonly MockScanner $mocks
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function mockImpact(string $ref): array
    {
        $changed = $this->git->changedPhpFiles($ref);
        $seedSources = $this->seedSources($ref, $this->sourceFiles($changed));
        $flags = [];
        foreach ($this->unitTestFiles($changed) as $testFile) {
            $baseCode = $this->git->fileAtRef($ref, $testFile);
            if ($baseCode === null) {
                continue;
            }
            foreach ($this->mocks->surfaceByMethodFromCode($baseCode) as $testMethod => $info) {
                foreach ($info['calls'] as $calledMethod) {
                    if (!isset($seedSources[$calledMethod])) {
                        continue;
                    }
                    $newCollaborators = $this->collaborators->newCollaborators(
                        $seedSources[$calledMethod]['base'],
                        $seedSources[$calledMethod]['head'],
                        $calledMethod
                    );
                    $missing = array_values(array_diff($newCollaborators, $info['stubs']));
                    if ($missing !== []) {
                        $flags[] = [
                            'test' => $testMethod,
                            'file' => $testFile,
                            'seed' => $calledMethod,
                            'new_collaborators' => $newCollaborators,
                            'missing_stubs' => $missing,
                        ];
                    }
                }
            }
        }
        return ['diff' => $ref, 'mock_impact' => $flags];
    }

    /**
     * @param string[] $sourceFiles
     * @return array<string, array{base: string, head: string}>
     */
    private function seedSources(string $ref, array $sourceFiles): array
    {
        $seeds = [];
        foreach ($sourceFiles as $file) {
            $headCode = (string)@file_get_contents($this->repoRoot . '/' . $file);
            $baseCode = $this->git->fileAtRef($ref, $file) ?? '';
            foreach ($this->collaborators->methodNames($headCode) as $method) {
                $seeds[$method] = ['base' => $baseCode, 'head' => $headCode];
            }
        }
        return $seeds;
    }

    /**
     * @param string[] $changed
     * @return string[]
     */
    private function sourceFiles(array $changed): array
    {
        return array_values(array_filter($changed, static fn(string $f): bool => !str_contains($f, '/Test/')));
    }

    /**
     * @param string[] $changed
     * @return string[]
     */
    private function unitTestFiles(array $changed): array
    {
        return array_values(array_filter($changed, static fn(string $f): bool => str_contains($f, '/Test/Unit/')));
    }
}
