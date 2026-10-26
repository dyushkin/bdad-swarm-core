<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Extracts MOCKS edges from a unit test: mocked class per receiver + stubbed method set.
 */
class MockScanner
{
    private const CREATE_FNS = ['createMock', 'getMockBuilder', 'createPartialMock', 'createStub'];
    private const STUB_FNS = ['method', 'onlyMethods', 'addMethods'];

    /**
     * @return array{mocks: array<string, string>, stubs: array<string, string[]>}
     */
    public function surface(string $file): array
    {
        if (!is_file($file)) {
            return ['mocks' => [], 'stubs' => []];
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse((string)file_get_contents($file)) ?? [];
        $finder = new NodeFinder();

        $mocks = [];
        foreach ($finder->findInstanceOf($ast, Node\Expr\Assign::class) as $assign) {
            $key = $this->receiverKey($assign->var);
            $class = $this->findMockedClass($assign->expr, $finder);
            if ($key !== null && $class !== null) {
                $mocks[$key] = $class;
            }
        }

        $stubs = [];
        foreach ($finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
            if (
                !$call->name instanceof Node\Identifier
                || !in_array($call->name->toString(), self::STUB_FNS, true)
            ) {
                continue;
            }
            $key = $this->rootKey($call);
            if ($key === null) {
                continue;
            }
            foreach ($this->stubbedMethodNames($call->name->toString(), $call) as $method) {
                $stubs[$key][] = $method;
            }
        }
        foreach ($stubs as $key => $names) {
            $stubs[$key] = array_values(array_unique($names));
        }
        return ['mocks' => $mocks, 'stubs' => $stubs];
    }

    /**
     * Per-test-method effective stub set (setUp stubs are global) + method-call names (to detect the seed).
     *
     * @return array<string, array{stubs: string[], calls: string[]}>
     */
    public function surfaceByMethod(string $file): array
    {
        return is_file($file) ? $this->surfaceByMethodFromCode((string)file_get_contents($file)) : [];
    }

    /**
     * @return array<string, array{stubs: string[], calls: string[]}>
     */
    public function surfaceByMethodFromCode(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];
        $finder = new NodeFinder();
        $classMethods = $finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);

        $global = [];
        foreach ($classMethods as $method) {
            if ($method->name->toString() === 'setUp') {
                $global = $this->stubsIn($method, $finder);
            }
        }

        $result = [];
        foreach ($classMethods as $method) {
            $name = $method->name->toString();
            if (!str_starts_with($name, 'test')) {
                continue;
            }
            $result[$name] = [
                'stubs' => array_values(array_unique(array_merge($global, $this->stubsIn($method, $finder)))),
                'calls' => $this->callsIn($method, $finder),
            ];
        }
        return $result;
    }

    /**
     * @return string[]
     */
    private function stubsIn(Node\Stmt\ClassMethod $method, NodeFinder $finder): array
    {
        $stubs = [];
        foreach ($finder->findInstanceOf([$method], Node\Expr\MethodCall::class) as $call) {
            if (
                $call->name instanceof Node\Identifier
                && in_array($call->name->toString(), self::STUB_FNS, true)
            ) {
                foreach ($this->stubbedMethodNames($call->name->toString(), $call) as $stubbed) {
                    $stubs[] = $stubbed;
                }
            }
        }
        return array_values(array_unique($stubs));
    }

    /**
     * @return string[]
     */
    private function callsIn(Node\Stmt\ClassMethod $method, NodeFinder $finder): array
    {
        $calls = [];
        foreach ($finder->findInstanceOf([$method], Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier) {
                $calls[] = $call->name->toString();
            }
        }
        return array_values(array_unique($calls));
    }

    private function findMockedClass(Node $expr, NodeFinder $finder): ?string
    {
        foreach ($finder->findInstanceOf([$expr], Node\Expr\MethodCall::class) as $call) {
            if (
                !$call->name instanceof Node\Identifier
                || !in_array($call->name->toString(), self::CREATE_FNS, true)
            ) {
                continue;
            }
            $arg = $call->args[0]->value ?? null;
            if ($arg instanceof Node\Expr\ClassConstFetch && $arg->class instanceof Node\Name) {
                return $arg->class->toString();
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function stubbedMethodNames(string $function, Node\Expr\MethodCall $call): array
    {
        $arg = $call->args[0]->value ?? null;
        if ($function === 'method') {
            return $arg instanceof Node\Scalar\String_ ? [$arg->value] : [];
        }
        $names = [];
        if ($arg instanceof Node\Expr\Array_) {
            foreach ($arg->items as $item) {
                if ($item !== null && $item->value instanceof Node\Scalar\String_) {
                    $names[] = $item->value->value;
                }
            }
        }
        return $names;
    }

    private function rootKey(Node\Expr\MethodCall $call): ?string
    {
        $node = $call->var;
        while ($node instanceof Node\Expr\MethodCall) {
            $node = $node->var;
        }
        return $this->receiverKey($node);
    }

    private function receiverKey(Node $node): ?string
    {
        if (
            $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
        ) {
            return 'this->' . $node->name->toString();
        }
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return '$' . $node->name;
        }
        return null;
    }
}
