<?php

declare(strict_types=1);

namespace ImpactGraph;

use ImpactGraph\Cache\EdgeCache;
use ImpactGraph\Diff\GitDiff;
use ImpactGraph\Resolve\Resolver;
use ImpactGraph\Source\CollaboratorScanner;
use ImpactGraph\Source\DiXmlReader;
use ImpactGraph\Source\DispatchScanner;
use ImpactGraph\Source\EventsXmlReader;
use ImpactGraph\Source\MftfIndex;
use ImpactGraph\Source\MockScanner;
use ImpactGraph\Source\ModuleMap;
use ImpactGraph\Support\FileScanner;

/**
 * Phase 0 CLI (P0-1/P0-2 subset): di.xml/events.xml edge queries + file-level diff resolution.
 */
final class Cli
{
    /**
     * @var string[]
     */
    private array $roots;

    private ModuleMap $moduleMap;
    private DiXmlReader $diReader;
    private EventsXmlReader $eventsReader;
    private DispatchScanner $dispatchScanner;
    private MockScanner $mockScanner;
    private GitDiff $git;
    private Resolver $resolver;
    private MftfIndex $mftfIndex;

    public function __construct(private readonly string $repoRoot)
    {
        $this->roots = [$this->repoRoot . '/app/code', $this->repoRoot . '/ext'];
        $scanner = new FileScanner();
        $cache = new EdgeCache($this->repoRoot . '/var/impact/edges.json');
        $this->moduleMap = new ModuleMap($this->roots, $scanner, $cache);
        $this->moduleMap->build();
        $this->diReader = new DiXmlReader($this->roots, $scanner, $this->moduleMap, $cache);
        $this->eventsReader = new EventsXmlReader($this->roots, $scanner, $this->moduleMap, $cache);
        $this->dispatchScanner = new DispatchScanner();
        $this->mockScanner = new MockScanner();
        $this->git = new GitDiff($this->repoRoot);
        $this->resolver = new Resolver($this->repoRoot, $this->git, new CollaboratorScanner(), $this->mockScanner);
        $this->mftfIndex = new MftfIndex($this->repoRoot, $this->moduleMap, $cache);
    }

    /**
     * @param string[] $args
     */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        if ($command !== 'analyze') {
            $this->printUsage();
            return $command === 'help' ? 0 : 1;
        }

        $options = $this->parseOptions(array_slice($args, 1));
        $json = ($options['format'] ?? 'text') === 'json';

        if (isset($options['event'])) {
            return $this->render(['event' => $options['event'],
                'observers' => $this->eventsReader->observersFor($options['event'])], $json);
        }
        if (isset($options['plugins-subject'])) {
            return $this->render(['subject' => $options['plugins-subject'],
                'plugins' => $this->diReader->pluginsForSubject($options['plugins-subject'])], $json);
        }
        if (isset($options['plugins-method'])) {
            return $this->render(['method' => $options['plugins-method'],
                'plugins' => $this->diReader->pluginsForMethod($options['plugins-method'])], $json);
        }
        if (isset($options['preference'])) {
            return $this->render(['for' => $options['preference'],
                'concretes' => $this->diReader->preferencesFor($options['preference'])], $json);
        }
        if (isset($options['dispatches'])) {
            return $this->render($this->analyzeDispatches($options['dispatches']), $json);
        }
        if (isset($options['mocks'])) {
            return $this->render($this->analyzeMocks($options['mocks']), $json);
        }
        if (isset($options['mftf-group'])) {
            return $this->render(['group' => $options['mftf-group'],
                'tests' => $this->mftfIndex->testsByGroup($options['mftf-group'])], $json);
        }
        if (isset($options['mftf-module'])) {
            return $this->render(['module' => $options['mftf-module'],
                'tests' => $this->mftfIndex->testsByModule($options['mftf-module'])], $json);
        }
        if (isset($options['mock-impact'])) {
            return $this->render($this->resolver->mockImpact($options['mock-impact']), $json);
        }
        if (isset($options['diff'])) {
            return $this->render($this->analyzeDiff($options['diff']), $json);
        }

        $this->printUsage();
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeDispatches(string $target): array
    {
        $file = $this->resolvePath($target);
        $dispatches = [];
        foreach ($this->dispatchScanner->eventsInFile($file) as $edge) {
            $dispatches[] = [
                'event' => $edge['event'],
                'method' => $edge['method'] ?? '',
                'observers' => $this->observerLabels($edge['event']),
            ];
        }
        return ['target' => $target, 'dispatches' => $dispatches];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeMocks(string $target): array
    {
        $surface = $this->mockScanner->surface($this->resolvePath($target));
        $mocks = [];
        foreach ($surface['mocks'] as $receiver => $class) {
            $mocks[] = ['receiver' => $receiver, 'class' => $class];
        }
        $stubs = [];
        foreach ($surface['stubs'] as $receiver => $methods) {
            $stubs[] = ['receiver' => $receiver, 'methods' => $methods];
        }
        return ['target' => $target, 'mocks' => $mocks, 'stubs' => $stubs];
    }

    /**
     * @return string[]
     */
    private function observerLabels(string $event): array
    {
        $labels = [];
        foreach ($this->eventsReader->observersFor($event) as $observer) {
            $labels[] = $observer['observer'] . ' (' . ($observer['module'] ?? '?') . ')';
        }
        return $labels;
    }

    private function resolvePath(string $target): string
    {
        if (is_file($target)) {
            return $target;
        }
        $underRoot = $this->repoRoot . '/' . ltrim($target, '/');
        if (is_file($underRoot)) {
            return $underRoot;
        }
        return $this->moduleMap->classToFile($target) ?? $target;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeDiff(string $ref): array
    {
        $changed = $this->changedPhpFiles($ref);
        $result = ['diff' => $ref, 'changed' => []];
        foreach ($changed as $file) {
            $absolute = $this->repoRoot . '/' . $file;
            $class = $this->moduleMap->fileToClass($absolute);
            $dispatched = $this->dispatchScanner->eventsInFile($absolute);
            $observes = [];
            foreach ($dispatched as $edge) {
                foreach ($this->observerLabels($edge['event']) as $label) {
                    $observes[] = $edge['event'] . ' -> ' . $label;
                }
            }
            $result['changed'][] = [
                'file' => $file,
                'class' => $class,
                'module' => $this->moduleMap->moduleForFile($absolute),
                'plugins' => $class !== null ? $this->diReader->pluginsForSubject($class) : [],
                'dispatches' => array_values(array_unique(array_map(
                    static fn(array $edge): string => $edge['event'],
                    $dispatched
                ))),
                'observes' => array_values(array_unique($observes)),
            ];
        }
        return $result;
    }

    /**
     * @return string[]
     */
    private function changedPhpFiles(string $ref): array
    {
        return $this->git->changedPhpFiles($ref);
    }

    /**
     * @param string[] $args
     * @return array<string, string>
     */
    private function parseOptions(array $args): array
    {
        $options = [];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $key = substr($arg, 2);
            if (str_contains($key, '=')) {
                [$key, $value] = explode('=', $key, 2);
                $options[$key] = $value;
                continue;
            }
            $next = $args[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $options[$key] = $next;
                $i++;
            } else {
                $options[$key] = '';
            }
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render(array $payload, bool $json): int
    {
        if ($json) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }
        $this->renderText($payload);
        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderText(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                echo $key . ':' . PHP_EOL;
                foreach ($value as $row) {
                    echo '  - ' . (is_array($row) ? $this->flatten($row) : (string)$row) . PHP_EOL;
                }
                continue;
            }
            echo $key . ': ' . (string)$value . PHP_EOL;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function flatten(array $row): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $value = implode('; ', array_map(
                    fn($item): string => is_array($item) ? '{' . $this->flatten($item) . '}' : (string)$item,
                    $value
                ));
            }
            $parts[] = $key . '=' . (string)$value;
        }
        return implode('  ', $parts);
    }

    private function printUsage(): void
    {
        echo <<<TXT
        impact-graph (MIG Phase 0 — P0-1/P0-2)

        Usage:
          impact-graph analyze --event <name>              observers of an event
          impact-graph analyze --plugins-subject <FQCN>    plugins intercepting a type
          impact-graph analyze --plugins-method <method>   plugins whose class handles a method
          impact-graph analyze --preference <interface>    preference concrete(s)
          impact-graph analyze --dispatches <file|FQCN>    events dispatched (+ observers)
          impact-graph analyze --mocks <testfile|FQCN>     mocked classes + stubbed methods
          impact-graph analyze --diff <git-ref>            changed classes + plugins + dispatch->observers
          impact-graph analyze --mock-impact <git-ref>     stale-mock flags (new collaborators vs base stubs)
          impact-graph analyze --mftf-group <group>        MFTF tests in a group (e.g. msi)
          impact-graph analyze --mftf-module <Module>      MFTF tests owned by a module
          [--format=json]                                  machine-readable output

        TXT;
    }
}
