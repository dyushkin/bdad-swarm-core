<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Extracts DISPATCHES edges (method => dispatched event name) from PHP via php-parser AST.
 */
class DispatchScanner
{
    /**
     * @var array<string, array<int, array{event: string, method: ?string, line: int}>>
     */
    private array $cache = [];

    /**
     * @return array<int, array{event: string, method: ?string, line: int}>
     */
    public function eventsInFile(string $file): array
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }
        $events = [];
        if (is_file($file)) {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast = $parser->parse((string)file_get_contents($file)) ?? [];
            $visitor = $this->createVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            $events = $visitor->events;
        }
        return $this->cache[$file] = $events;
    }

    /**
     * @return string[]
     */
    public function eventsForMethod(string $file, string $method): array
    {
        $names = [];
        foreach ($this->eventsInFile($file) as $event) {
            if ($event['method'] === $method) {
                $names[] = $event['event'];
            }
        }
        return array_values(array_unique($names));
    }

    private function createVisitor(): NodeVisitorAbstract
    {
        return new class extends NodeVisitorAbstract {
            /**
             * @var array<int, array{event: string, method: ?string, line: int}>
             */
            public array $events = [];

            private ?string $method = null;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $this->method = $node->name->toString();
                }
                if ($this->isDispatchCall($node)) {
                    /** @var Node\Expr\MethodCall $node */
                    $arg = $node->args[0]->value ?? null;
                    if ($arg instanceof Node\Scalar\String_) {
                        $this->events[] = [
                            'event' => $arg->value,
                            'method' => $this->method,
                            'line' => $node->getStartLine(),
                        ];
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $this->method = null;
                }
                return null;
            }

            private function isDispatchCall(Node $node): bool
            {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'dispatch';
            }
        };
    }
}
