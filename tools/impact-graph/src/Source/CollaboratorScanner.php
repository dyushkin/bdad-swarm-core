<?php

declare(strict_types=1);

namespace ImpactGraph\Source;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Resolves the external collaborator method-call set of a method, following intra-class self-calls
 * transitively (so collaborators introduced via a new private helper are still attributed to the caller).
 */
class CollaboratorScanner
{
    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder();
    }

    /**
     * @return string[]
     */
    public function collaborators(string $code, string $method): array
    {
        $methods = $this->indexMethods($code);
        $collaborators = [];
        $visited = [];
        $this->walk($method, $methods, $collaborators, $visited);
        return array_values(array_unique($collaborators));
    }

    /**
     * @return string[]
     */
    public function delta(string $baseCode, string $headCode, string $method): array
    {
        $base = $this->collaborators($baseCode, $method);
        $head = $this->collaborators($headCode, $method);
        return array_values(array_diff($head, $base));
    }

    /**
     * @return string[]
     */
    public function methodNames(string $code): array
    {
        return array_keys($this->indexMethods($code));
    }

    /**
     * Collaborators reached from $seed via call sites that this diff ADDED — a new call site in a changed
     * method, or any call site inside a method the diff introduced. This is stricter than a reachable-name
     * set-difference: it catches a collaborator that was already reachable elsewhere but is newly invoked here.
     *
     * @return string[]
     */
    public function newCollaborators(string $baseCode, string $headCode, string $seed): array
    {
        $head = $this->indexMethods($headCode);
        $base = $this->indexMethods($baseCode);
        if (!isset($head[$seed])) {
            return [];
        }
        $reachable = [];
        $this->reach($seed, $head, $reachable);
        $new = [];
        foreach (array_keys($reachable) as $method) {
            $headExternal = $this->directExternal($head[$method]);
            $newExternal = isset($base[$method])
                ? array_diff($headExternal, $this->directExternal($base[$method]))
                : $headExternal;
            foreach ($newExternal as $collaborator) {
                $new[] = $collaborator;
            }
        }
        return array_values(array_unique($new));
    }

    /**
     * @param array<string, Node\Stmt\ClassMethod> $methods
     * @param array<string, bool> $seen
     */
    private function reach(string $method, array $methods, array &$seen): void
    {
        if (isset($seen[$method]) || !isset($methods[$method])) {
            return;
        }
        $seen[$method] = true;
        foreach ($this->finder->findInstanceOf([$methods[$method]], Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && $this->isSelfCall($call)) {
                $this->reach($call->name->toString(), $methods, $seen);
            }
        }
    }

    /**
     * @return string[]
     */
    private function directExternal(Node\Stmt\ClassMethod $node): array
    {
        $external = [];
        foreach ($this->finder->findInstanceOf([$node], Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && !$this->isSelfCall($call)) {
                $external[] = $call->name->toString();
            }
        }
        return array_values(array_unique($external));
    }

    /**
     * @return array<string, Node\Stmt\ClassMethod>
     */
    private function indexMethods(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];
        $map = [];
        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            $map[$method->name->toString()] = $method;
        }
        return $map;
    }

    /**
     * @param array<string, Node\Stmt\ClassMethod> $methods
     * @param string[] $collaborators
     * @param array<string, bool> $visited
     */
    private function walk(string $method, array $methods, array &$collaborators, array &$visited): void
    {
        if (isset($visited[$method]) || !isset($methods[$method])) {
            return;
        }
        $visited[$method] = true;
        foreach ($this->finder->findInstanceOf([$methods[$method]], Node\Expr\MethodCall::class) as $call) {
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }
            $name = $call->name->toString();
            if ($this->isSelfCall($call)) {
                $this->walk($name, $methods, $collaborators, $visited);
            } else {
                $collaborators[] = $name;
            }
        }
    }

    private function isSelfCall(Node\Expr\MethodCall $call): bool
    {
        return $call->var instanceof Node\Expr\Variable && $call->var->name === 'this';
    }
}
