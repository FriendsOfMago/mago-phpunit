<?php

declare(strict_types=1);

namespace MagoPHPUnit\Linter;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function array_filter;
use function array_key_exists;
use function array_values;
use function stripos;
use function strtolower;

/**
 * Finds the assertion calls of a method once for all the rules that ask.
 *
 * Mago sends a whole file in one request. The worker runs every active rule
 * on a method before it moves to the next node, and passes the same file
 * and node objects to each rule. So the answers for the last file and the
 * last method are kept, and the rules share them.
 *
 * @internal
 */
final class MethodScan
{
    /**
     * The scan shared by the rules of this worker.
     */
    private static ?self $shared = null;

    /**
     * The last file.
     */
    private ?SourceFile $file = null;

    /**
     * Whether the last file has the text `assert`, which starts the name of
     * every assertion.
     */
    private bool $assertions = false;

    /**
     * Whether the last file has the text `test`, which every test method
     * needs in its name, a `#[Test]` attribute or a `@test` tag.
     */
    private bool $tests = false;

    /**
     * The call index of the last file, or null before a method body is searched.
     */
    private ?CallIndex $index = null;

    /**
     * The last method.
     */
    private ?Node $method = null;

    /**
     * Whether the last method is a test, or null before it's checked.
     */
    private ?bool $test = null;

    /**
     * The assertion and helper calls of the last method, or null before
     * its calls are looked up.
     *
     * @var list<AssertionCall>|null
     */
    private ?array $calls = null;

    /**
     * The scan shared by every rule of this worker.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * The assertion calls of a test method, or an empty list for another method.
     *
     * @return list<AssertionCall>
     */
    public function assertionsInTest(SourceFile $file, Node $method): array
    {
        if (!$this->file($file)->assertions || !$this->isTest($file, $method)) {
            return [];
        }

        return self::assertionsOnly($this->calls($file, $method));
    }

    /**
     * The assertion and helper calls of a test method, or an empty list for another method.
     *
     * @return list<AssertionCall>
     */
    public function callsInTest(SourceFile $file, Node $method): array
    {
        if (!$this->file($file)->tests || !$this->isTest($file, $method)) {
            return [];
        }

        return $this->calls($file, $method);
    }

    /**
     * The assertion calls of any method.
     *
     * @return list<AssertionCall>
     */
    public function assertionsInMethod(SourceFile $file, Node $method): array
    {
        if (!$this->file($file)->assertions) {
            return [];
        }

        return self::assertionsOnly($this->calls($file, $method));
    }

    /**
     * Reads the text of a new file once, and forgets the last method.
     *
     * The worker passes one file object for every node of a request.
     *
     * @return $this
     */
    private function file(SourceFile $file): self
    {
        if ($this->file !== $file) {
            $this->file = $file;
            $this->assertions = stripos($file->contents, needle: 'assert') !== false;
            $this->tests = stripos($file->contents, needle: 'test') !== false;
            $this->index = null;
            $this->method = null;
        }

        return $this;
    }

    /**
     * Whether the method is a test, checked once per method.
     */
    private function isTest(SourceFile $file, Node $method): bool
    {
        $this->select($method);

        return $this->test ??= TestMethods::isTest($file, $method);
    }

    /**
     * The assertion and helper calls in the method's body, found once per method.
     *
     * @return list<AssertionCall>
     */
    private function calls(SourceFile $file, Node $method): array
    {
        $this->select($method);
        if ($this->calls !== null) {
            return $this->calls;
        }

        $body = $file->getFirstDescendant($method, NodeKind::MethodBody);
        if ($body === null) {
            return $this->calls = [];
        }

        $this->index ??= new CallIndex($file);
        $names = [AssertionNames::ASSERTIONS, AssertionNames::HELPERS];
        $calls = [];
        foreach ($this->index->callsIn($file, $body) as [$node, $operator]) {
            $call = TestMethods::call($file, $node, $operator, $names);
            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $this->calls = $calls;
    }

    /**
     * Forgets the answers for the last method when another one is asked about.
     */
    private function select(Node $method): void
    {
        if ($this->method !== $method) {
            $this->method = $method;
            $this->test = null;
            $this->calls = null;
        }
    }

    /**
     * The calls to an assertion, without the helpers.
     *
     * @param list<AssertionCall> $calls
     *
     * @return list<AssertionCall>
     */
    private static function assertionsOnly(array $calls): array
    {
        return array_values(array_filter($calls, static fn(AssertionCall $call): bool => array_key_exists(
            strtolower($call->name),
            AssertionNames::ASSERTIONS,
        )));
    }
}
