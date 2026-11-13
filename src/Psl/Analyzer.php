<?php

namespace Shirahcan\Pml\Psl;

use stdClass;

/**
 * The advisory static analyzer. It walks the AST and reports {@see Diagnostic}s that help an author
 * fix mistakes BEFORE running - but it NEVER blocks: `Psl::compile()` always returns the AST plus
 * whatever was found (aid-not-enforce). Prefer silence when unsure; a false-positive warning erodes
 * trust more than a missed one.
 *
 * Checks: undefined-variable (error), const-reassignment (error), unreachable-code (warning),
 * wrong-arity (warning, known local functions AND std-lib module calls), unknown-member (warning, a
 * `module.fn` the std-lib manifest does not declare), unknown-type (warning), type-mismatch (warning,
 * only the blatant annotated-vs-literal case), unused-variable (info).
 *
 * The std-lib vocabulary (string/number/list/map/date/imm) is read from the SAME
 * `resources/psl/stdlib/manifest.json` the browser registers natives from, so what the analyzer
 * knows can never drift from what actually runs. A locally declared name shadows a module, exactly as
 * the browser registry lets a caller root shadow the std-lib.
 */
class Analyzer
{
    private const KNOWN_TYPES = ['number', 'string', 'boolean', 'list', 'map', 'any'];

    /** @var Diagnostic[] */
    private array $diagnostics = [];

    /** @var array<string, array<string, array<string, mixed>>>|null Std-lib surface, lazily read from the manifest. */
    private static ?array $stdlibCache = null;

    /**
     * @param  array<string, mixed>  $program  a Program node
     * @param  array<int, string>  $contextRoots  names the page supplies at runtime (never "undefined")
     * @return Diagnostic[]
     */
    public function analyze(array $program, array $contextRoots = []): array
    {
        $this->diagnostics = [];

        $global = $this->newScope(null);

        // Seed the std-lib namespaces first so `string.upper` is not "undefined". Context roots are
        // seeded AFTER, so a real page field named like a module overrides it (real data wins, exactly
        // as the browser registry spreads `{...buildStdlib(), ...context}`).
        foreach (array_keys($this->stdlibModules()) as $module) {
            $info = $this->declare($global, $module, false, 'map', 0, 0, false, null);
            $info->used = true;
            $info->kind = 'module';
        }

        foreach ($contextRoots as $name) {
            $info = $this->declare($global, $name, false, 'any', 0, 0, false, null);
            $info->used = true;
            $info->kind = 'context';
        }

        $this->walkScope($program['body'], $global);

        usort(
            $this->diagnostics,
            static fn (Diagnostic $a, Diagnostic $b): int => [$a->line, $a->col] <=> [$b->line, $b->col],
        );

        return $this->diagnostics;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stmts
     */
    private function walkScope(array $stmts, stdClass $scope): void
    {
        // Hoist function declarations so a forward reference is not a false "undefined".
        foreach ($stmts as $stmt) {
            if ($stmt['type'] === 'FuncDecl') {
                $fn = $this->declare($scope, $stmt['name'], false, 'any', $stmt['line'], $stmt['col'], true, count($stmt['params']));
                $fn->used = true; // a named function definition is not "unused" noise
            }
        }

        $this->walkStatements($stmts, $scope);
        $this->reportUnused($scope);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stmts
     */
    private function walkStatements(array $stmts, stdClass $scope): void
    {
        $terminated = false;
        $reported = false;

        foreach ($stmts as $stmt) {
            if ($terminated && ! $reported) {
                $this->add('unreachable-code', Diagnostic::WARNING, 'This code can never run; an exit above always runs first.', $stmt['line'], $stmt['col']);
                $reported = true;
            }

            if ($this->walkStatement($stmt, $scope)) {
                $terminated = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return bool whether this statement always exits its block (return/break/continue)
     */
    private function walkStatement(array $node, stdClass $scope): bool
    {
        switch ($node['type']) {
            case 'VarDecl':
                if ($node['init'] !== null) {
                    $this->walkExpr($node['init'], $scope);
                }
                $this->checkType($node['varType'], $node['line'], $node['col']);
                $this->checkVarInitType($node);
                $this->declare($scope, $node['name'], $node['kind'] === 'const', $node['varType'] ?? 'any', $node['line'], $node['col'], false, null);

                return false;

            case 'FuncDecl':
                $this->walkFunction($node, $scope); // the name was hoisted in walkScope

                return false;

            case 'Block':
                $this->walkScope($node['body'], $this->newScope($scope));

                return false;

            case 'If':
                foreach ($node['branches'] as $branch) {
                    if ($branch['cond'] !== null) {
                        $this->walkExpr($branch['cond'], $scope);
                    }
                    $this->walkScope($branch['body']['body'], $this->newScope($scope));
                }

                return false;

            case 'While':
                $this->walkExpr($node['cond'], $scope);
                $this->walkScope($node['body']['body'], $this->newScope($scope));

                return false;

            case 'For':
                $this->walkExpr($node['iterable'], $scope);
                $loop = $this->newScope($scope);
                $this->declare($loop, $node['itemVar'], false, 'any', $node['line'], $node['col'], false, null)->used = true;
                if ($node['keyVar'] !== null) {
                    $this->declare($loop, $node['keyVar'], false, 'any', $node['line'], $node['col'], false, null)->used = true;
                }
                $this->walkScope($node['body']['body'], $loop);

                return false;

            case 'Return':
                if ($node['value'] !== null) {
                    $this->walkExpr($node['value'], $scope);
                }

                return true;

            case 'Break':
            case 'Continue':
                return true;

            default:
                $this->walkExpr($node, $scope); // an expression statement

                return false;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function walkFunction(array $node, stdClass $scope): void
    {
        $fnScope = $this->newScope($scope);
        foreach ($node['params'] as $param) {
            $this->checkType($param['paramType'], $node['line'], $node['col']);
            $this->declare($fnScope, $param['name'], false, $param['paramType'] ?? 'any', $node['line'], $node['col'], false, null)->used = true;
        }
        $this->checkType($node['returnType'], $node['line'], $node['col']);
        $this->walkScope($node['body']['body'], $fnScope);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function walkExpr(array $node, stdClass $scope): void
    {
        switch ($node['type']) {
            case 'Identifier':
                $info = $this->resolve($scope, $node['name']);
                if ($info === null) {
                    $this->add('undefined-variable', Diagnostic::ERROR, "\"{$node['name']}\" is not defined.", $node['line'], $node['col']);
                } else {
                    $info->used = true;
                }

                return;

            case 'Unary':
                $this->walkExpr($node['operand'], $scope);

                return;

            case 'Binary':
                $this->walkExpr($node['left'], $scope);
                $this->walkExpr($node['right'], $scope);

                return;

            case 'Ternary':
                $this->walkExpr($node['cond'], $scope);
                $this->walkExpr($node['then'], $scope);
                $this->walkExpr($node['else'], $scope);

                return;

            case 'ListLit':
                foreach ($node['elements'] as $element) {
                    $this->walkExpr($element, $scope);
                }

                return;

            case 'MapLit':
                foreach ($node['entries'] as $entry) {
                    $this->walkExpr($entry['value'], $scope);
                }

                return;

            case 'Member':
                if ($this->isStdlibModuleRef($node['object'], $scope)) {
                    // A std-lib namespace, not a value to resolve; check the member exists instead.
                    $this->checkModuleMember($node['object']['name'], $node['name'], $node['line'], $node['col']);

                    return;
                }
                if ($this->isRemovedStateMethod($node['object'], $node['name'], $scope)) {
                    // state.get/set/has were removed; only object notation reaches the store now. An
                    // ERROR so the composer's self-lint repairs it (and a hand-author is told clearly),
                    // never a silent runtime no-op.
                    $this->add('removed-member', Diagnostic::ERROR, "state.{$node['name']}(...) was removed; use object notation: state.<key> to read, state.<key> = value to write, state.<key>++ to count.", $node['line'], $node['col']);
                    $this->walkExpr($node['object'], $scope);

                    return;
                }
                $this->walkExpr($node['object'], $scope); // `.name` is a property, not a variable

                return;

            case 'Index':
                $this->walkExpr($node['object'], $scope);
                $this->walkExpr($node['index'], $scope);

                return;

            case 'FuncExpr':
                $this->walkFunction($node, $scope);

                return;

            case 'Call':
                $this->walkExpr($node['callee'], $scope);
                foreach ($node['args'] as $arg) {
                    $this->walkExpr($arg, $scope);
                }
                $this->checkArity($node, $scope);

                return;

            case 'Assign':
                $this->walkAssign($node, $scope);

                return;

            case 'Update':
                $this->walkUpdate($node, $scope);

                return;

            default:
                // literals: nothing to resolve
                return;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function walkAssign(array $node, stdClass $scope): void
    {
        $this->walkExpr($node['value'], $scope);
        $target = $node['target'];

        if ($target['type'] !== 'Identifier') {
            $this->walkExpr($target, $scope); // member/index target: resolve its object + index

            return;
        }

        $info = $this->resolve($scope, $target['name']);
        if ($info === null) {
            $this->add('undefined-variable', Diagnostic::ERROR, "\"{$target['name']}\" is not defined.", $target['line'], $target['col']);

            return;
        }
        if ($info->const) {
            $this->add('const-reassignment', Diagnostic::ERROR, "\"{$target['name']}\" is a const and cannot be reassigned.", $node['line'], $node['col']);

            return;
        }
        if ($node['op'] !== '=') {
            $info->used = true; // a compound assignment reads the current value
        }
    }

    /**
     * `++x` / `x++` reads AND writes its operand, so it flags an undefined variable or a const, exactly
     * like a compound assignment; a member/index operand is walked for its object + index.
     *
     * @param  array<string, mixed>  $node
     */
    private function walkUpdate(array $node, stdClass $scope): void
    {
        $target = $node['operand'];

        if ($target['type'] !== 'Identifier') {
            $this->walkExpr($target, $scope);

            return;
        }

        $info = $this->resolve($scope, $target['name']);
        if ($info === null) {
            $this->add('undefined-variable', Diagnostic::ERROR, "\"{$target['name']}\" is not defined.", $target['line'], $target['col']);

            return;
        }
        if ($info->const) {
            $this->add('const-reassignment', Diagnostic::ERROR, "\"{$target['name']}\" is a const and cannot be reassigned.", $node['line'], $node['col']);

            return;
        }
        $info->used = true;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function checkArity(array $node, stdClass $scope): void
    {
        $callee = $node['callee'];

        if ($callee['type'] === 'Member' && $this->isStdlibModuleRef($callee['object'], $scope)) {
            $this->checkModuleArity($callee['object']['name'], $callee['name'], $node);

            return;
        }

        if ($callee['type'] !== 'Identifier') {
            return; // only direct named calls have a knowable arity
        }
        $info = $this->resolve($scope, $callee['name']);
        if ($info === null || ! $info->isFunction || $info->arity === null) {
            return;
        }
        $got = count($node['args']);
        if ($got !== $info->arity) {
            $this->add('wrong-arity', Diagnostic::WARNING, "\"{$callee['name']}\" expects {$info->arity} argument(s) but got {$got}.", $node['line'], $node['col']);
        }
    }

    /**
     * `state.get` / `state.set` / `state.has` on the reserved reactive `state` root - the removed
     * methods. Only when `state` is the context root (a local variable named state that shadows it is
     * the author's own object and is left alone).
     *
     * @param  array<string, mixed>  $object
     */
    private function isRemovedStateMethod(array $object, string $name, stdClass $scope): bool
    {
        if ($object['type'] !== 'Identifier' || $object['name'] !== 'state') {
            return false;
        }
        if (! in_array($name, ['get', 'set', 'has'], true)) {
            return false;
        }
        $info = $this->resolve($scope, 'state');

        return $info !== null && $info->kind === 'context';
    }

    /**
     * Is `object` a bare reference to a std-lib namespace (string/number/list/map/date/imm) that a
     * real local/context declaration has NOT shadowed? Shadowing wins because a nearer `declare`
     * overwrites the seeded module entry (global) or resolves first (inner scope).
     *
     * @param  array<string, mixed>  $object
     */
    private function isStdlibModuleRef(array $object, stdClass $scope): bool
    {
        if ($object['type'] !== 'Identifier') {
            return false;
        }
        $info = $this->resolve($scope, $object['name']);

        return $info !== null && $info->kind === 'module';
    }

    /**
     * Warn (advisory) when `module.member` names a function the manifest does not declare. Unknown here
     * means "will be undefined at runtime", but we stay a warning, never an error - the manifest could
     * lag a just-added native, and a false stop erodes trust more than a missed hint.
     */
    private function checkModuleMember(string $module, string $member, int $line, int $col): void
    {
        $functions = $this->stdlibModules()[$module] ?? [];
        if (! isset($functions[$member])) {
            $this->add('unknown-member', Diagnostic::WARNING, "\"{$module}\" has no function \"{$member}\".", $line, $col);
        }
    }

    /**
     * Warn on a wrong argument count for a KNOWN std-lib function. An unknown member is left to
     * {@see checkModuleMember} (already warned) and a null arity means variadic (no check).
     *
     * @param  array<string, mixed>  $node  the Call node
     */
    private function checkModuleArity(string $module, string $member, array $node): void
    {
        $signature = $this->stdlibModules()[$module][$member] ?? null;
        $arity = is_array($signature) ? ($signature['arity'] ?? null) : null;
        if ($arity === null) {
            return;
        }
        $got = count($node['args']);
        if ($got !== $arity) {
            $this->add('wrong-arity', Diagnostic::WARNING, "\"{$module}.{$member}\" expects {$arity} argument(s) but got {$got}.", $node['line'], $node['col']);
        }
    }

    /**
     * The std-lib surface (module -> function -> signature), read from the manifest that is the single
     * source of truth for BOTH this analyzer and the browser evaluator. Loaded by a file-relative path
     * (not `resource_path()`) so the analyzer stays a pure class usable without a booted app, and
     * cached for the process (the manifest is a build artifact that does not change at runtime).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function stdlibModules(): array
    {
        if (self::$stdlibCache === null) {
            $path = dirname(__DIR__, 2).'/resources/psl/stdlib/manifest.json';
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$stdlibCache = is_array($data) && isset($data['modules']) && is_array($data['modules'])
                ? $data['modules']
                : [];
        }

        return self::$stdlibCache;
    }

    private function checkType(?string $type, int $line, int $col): void
    {
        if ($type !== null && ! in_array($type, self::KNOWN_TYPES, true)) {
            $this->add('unknown-type', Diagnostic::WARNING, "\"{$type}\" is not a known type.", $line, $col);
        }
    }

    /**
     * Narrow, low-false-positive type check: an annotated declaration whose initializer is a literal
     * of a clearly different type. Anything non-obvious stays silent (gradual typing).
     *
     * @param  array<string, mixed>  $node
     */
    private function checkVarInitType(array $node): void
    {
        $type = $node['varType'];
        $init = $node['init'];
        if ($type === null || $init === null || $type === 'any' || ! in_array($type, self::KNOWN_TYPES, true)) {
            return;
        }

        $literalType = match ($init['type']) {
            'NumberLit' => 'number',
            'StringLit' => 'string',
            'BoolLit' => 'boolean',
            'ListLit' => 'list',
            'MapLit' => 'map',
            default => null,
        };

        if ($literalType !== null && $literalType !== $type) {
            $this->add('type-mismatch', Diagnostic::WARNING, "This declares a {$type} but the value is a {$literalType}.", $node['line'], $node['col']);
        }
    }

    private function reportUnused(stdClass $scope): void
    {
        foreach ($scope->vars as $name => $info) {
            if ($info->kind !== 'context' && ! $info->used) {
                $this->add('unused-variable', Diagnostic::INFO, "\"{$name}\" is declared but never used.", $info->line, $info->col);
            }
        }
    }

    private function newScope(?stdClass $parent): stdClass
    {
        $scope = new stdClass;
        $scope->vars = [];
        $scope->parent = $parent;

        return $scope;
    }

    private function declare(stdClass $scope, string $name, bool $const, string $type, int $line, int $col, bool $isFunction, ?int $arity): stdClass
    {
        $info = new stdClass;
        $info->const = $const;
        $info->type = $type;
        $info->used = false;
        $info->line = $line;
        $info->col = $col;
        $info->isFunction = $isFunction;
        $info->arity = $arity;
        $info->kind = 'var';
        $scope->vars[$name] = $info;

        return $info;
    }

    private function resolve(stdClass $scope, string $name): ?stdClass
    {
        $current = $scope;
        while ($current !== null) {
            if (isset($current->vars[$name])) {
                return $current->vars[$name];
            }
            $current = $current->parent;
        }

        return null;
    }

    private function add(string $code, string $severity, string $message, int $line, int $col): void
    {
        $this->diagnostics[] = new Diagnostic($code, $severity, $message, $line, $col);
    }
}
