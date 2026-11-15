<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Cache\FileMemo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeakReference;

use function gc_collect_cycles;
use function str_repeat;

/**
 * @mago-expect lint:too-many-methods
 */
#[CoversClass(FileMemo::class)]
final class FileMemoTest extends TestCase
{
    /**
     * For the same text, the value built the first time is returned.
     */
    public function testTheSameTextKeepsTheValue(): void
    {
        $memo = new FileMemo();

        self::assertSame('first', $memo->get('<?php echo 1;', static fn(): string => 'first'));
        self::assertSame('first', $memo->get('<?php echo 1;', static fn(): string => 'again'));
    }

    /**
     * An equal text in another string counts as the same file.
     *
     * Each request decodes its own string, so the text has to be compared by
     * value.
     */
    public function testAnEqualTextInAnotherStringKeepsTheValue(): void
    {
        $memo = new FileMemo();
        $text = str_repeat(string: 'a', times: 3);

        self::assertSame('first', $memo->get($text, static fn(): string => 'first'));
        self::assertSame('first', $memo->get('aaa', static fn(): string => 'again'));
    }

    /**
     * The value is built again for a changed text, and again on a return to the old text.
     */
    public function testChangedTextBuildsAgain(): void
    {
        $memo = new FileMemo();

        self::assertSame('first', $memo->get('<?php echo 1;', static fn(): string => 'first'));
        self::assertSame('second', $memo->get('<?php echo 2;', static fn(): string => 'second'));
        self::assertSame('third', $memo->get('<?php echo 1;', static fn(): string => 'third'));
    }

    /**
     * Two memos for the same text keep separate values.
     */
    public function testTwoMemosKeepTheirOwnValues(): void
    {
        $tokens = new FileMemo();
        $scopes = new FileMemo();

        self::assertSame('tokens', $tokens->get('<?php echo 1;', static fn(): string => 'tokens'));
        self::assertSame('scopes', $scopes->get('<?php echo 1;', static fn(): string => 'scopes'));
        self::assertSame('tokens', $tokens->get('<?php echo 1;', static fn(): string => 'again'));
        self::assertSame('other', $scopes->get('<?php echo 2;', static fn(): string => 'other'));
        self::assertSame('tokens', $tokens->get('<?php echo 1;', static fn(): string => 'again'));
    }

    /**
     * The value is built again for a new snapshot of the same text.
     *
     * Two snapshots of the same text can hold different nodes.
     */
    public function testANewSnapshotOfTheSameTextBuildsAgain(): void
    {
        $memo = new FileMemo();
        $first = self::sourceFile('<?php echo 1;');
        $second = self::sourceFile('<?php echo 1;');

        self::assertSame('first', $memo->get($first, static fn(): string => 'first'));
        self::assertSame('first', $memo->get($first, static fn(): string => 'again'));
        self::assertSame('second', $memo->get($second, static fn(): string => 'second'));
    }

    /**
     * A memo keeping snapshots keeps each snapshot's values.
     *
     * An analyzer hook that waits on the host lets a request for another
     * file run in between. The first file's values must still be there
     * afterwards.
     */
    public function testAMemoKeepingSnapshotsKeepsTheValuesOfEachSnapshot(): void
    {
        $memo = (new FileMemo())->keepingSnapshots();
        $first = self::sourceFile('<?php echo 1;');
        $second = self::sourceFile('<?php echo 2;');

        self::assertSame('a', self::value($memo, key: $first, value: 'a'));
        self::assertSame('b', self::value($memo, key: $second, value: 'b'));
        self::assertSame('a', self::value($memo, key: $first, value: 'again'));
        self::assertSame('b', self::value($memo, key: $second, value: 'again'));
    }

    /**
     * A memo without keepingSnapshots() keeps only the last snapshot's values.
     */
    public function testAPlainMemoKeepsOnlyTheLastSnapshot(): void
    {
        $memo = new FileMemo();
        $first = self::sourceFile('<?php echo 1;');
        $second = self::sourceFile('<?php echo 2;');

        self::assertSame('a', self::value($memo, key: $first, value: 'a'));
        self::assertSame('b', self::value($memo, key: $second, value: 'b'));
        self::assertSame('again', self::value($memo, key: $first, value: 'again'));
    }

    /**
     * A memo keeping snapshots frees a snapshot's values once it's gone.
     *
     * A worker analyzes thousands of files, so the values of a snapshot have
     * to go when the snapshot does.
     */
    public function testAMemoKeepingSnapshotsLetsGoOfASnapshot(): void
    {
        $memo = (new FileMemo())->keepingSnapshots();
        $file = self::sourceFile('<?php echo 1;');
        $memo->get($file, static fn(): string => 'value');
        $memo->get(self::sourceFile('<?php echo 2;'), static fn(): string => 'next');
        $memo->get('<?php echo 3;', static fn(): string => 'text');

        $reference = WeakReference::create($file);
        unset($file);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    /**
     * A null value counts as a kept value.
     */
    public function testANullValueIsKept(): void
    {
        $memo = new FileMemo();
        $memo->keep('<?php', value: null);

        self::assertTrue($memo->has('<?php'));
        self::assertNull($memo->value());
    }

    /**
     * A value kept after another file switched the memo goes to its own file.
     *
     * A build that waits on the host lets a request for another file switch
     * the memo in between.
     */
    public function testAValueKeptAfterAnotherFileTookTheMemoLandsWithItsFile(): void
    {
        $memo = (new FileMemo())->keepingSnapshots();
        $first = self::sourceFile('<?php echo 1;');
        $second = self::sourceFile('<?php echo 2;');

        self::assertFalse($memo->has($first));
        self::assertSame('second', self::value($memo, key: $second, value: 'second'));
        $memo->keep($first, value: 'first');

        self::assertSame('second', self::value($memo, key: $second, value: 'again'));
        self::assertSame('first', self::value($memo, key: $first, value: 'again'));
    }

    /**
     * Returns the value for the key, or keeps and returns $value when it's
     * missing. This is how the rules use the memo.
     *
     * @param FileMemo<string> $memo
     */
    private static function value(FileMemo $memo, string|SourceFile $key, string $value): string
    {
        return $memo->has($key) ? $memo->value() : $memo->keep($key, $value);
    }

    /**
     * A snapshot of the given text without nodes.
     */
    private static function sourceFile(string $contents): SourceFile
    {
        return new SourceFile(
            PHPVersion::fromParts(major: 8, minor: 1),
            'modules/corpus/corpus.module',
            $contents,
            [],
            new NodeStore([], '', 0),
            new ResolvedNameStore('', '', '', 0),
            new TriviaStore('', 0),
            null,
        );
    }
}
