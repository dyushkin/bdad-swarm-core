<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use ImpactGraph\Cache\EdgeCache;
use ImpactGraph\Support\FileScanner;

/**
 * Extracts OBSERVES edges (event name => observers) from every events.xml (source of truth).
 */
final class EventsXmlReader
{
    /**
     * @var array<string, array<int, array{observer: string, name: string, area: string, file: string}>>
     */
    private array $observers = [];

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
        foreach ($this->scanner->findByName($this->roots, 'events.xml') as $file) {
            $edges = $this->cache->remember('events', $file, fn(): array => $this->parseFile($file));
            foreach ($edges as $edge) {
                $this->observers[$edge['event']][] = [
                    'observer' => $edge['observer'],
                    'name' => $edge['name'],
                    'area' => $edge['area'],
                    'file' => $edge['file'],
                ];
            }
        }
        $this->parsed = true;
    }

    /**
     * @return array<int, array{event: string, observer: string, name: string, area: string, file: string}>
     */
    private function parseFile(string $file): array
    {
        $edges = [];
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            return $edges;
        }
        $area = $this->areaForFile($file);
        foreach ($xml->event as $event) {
            $eventName = (string)$event['name'];
            foreach ($event->observer as $observer) {
                if (((string)$observer['disabled']) === 'true') {
                    continue;
                }
                $edges[] = [
                    'event' => $eventName,
                    'observer' => ltrim((string)$observer['instance'], '\\'),
                    'name' => (string)$observer['name'],
                    'area' => $area,
                    'file' => $file,
                ];
            }
        }
        return $edges;
    }

    /**
     * @return array<int, array{observer: string, name: string, area: string, module: ?string, file: string}>
     */
    public function observersFor(string $eventName): array
    {
        $this->parse();
        $result = [];
        foreach ($this->observers[$eventName] ?? [] as $observer) {
            $result[] = [
                'observer' => $observer['observer'],
                'name' => $observer['name'],
                'area' => $observer['area'],
                'module' => $this->moduleMap->moduleForClass($observer['observer']),
                'file' => $observer['file'],
            ];
        }
        return $result;
    }

    private function areaForFile(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        if (preg_match('#/etc/([^/]+)/events\.xml$#', $normalized, $matches)) {
            return $matches[1];
        }
        return 'global';
    }
}
