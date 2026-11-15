<?php

declare(strict_types=1);

namespace MagoPHPUnit\Tests;

use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;
use MagoPHPUnit\Internal\Declaration\FileMembers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeakReference;

use function gc_collect_cycles;

#[CoversClass(FileMembers::class)]
final class FileMembersTest extends TestCase
{
    /**
     * The cache entry for a snapshot is freed once the snapshot is gone.
     *
     * A worker analyzes thousands of files, so the entry for one has to go
     * once its snapshot is gone and another file is read. That only works
     * while nothing in the entry references the snapshot. PHP 8.1 and 8.2
     * never collect a weak map entry whose value references its own key.
     */
    public function testTheCacheLetsGoOfASnapshot(): void
    {
        $file = self::sourceFile();
        FileMembers::of($file);
        FileMembers::of(self::sourceFile());

        $reference = WeakReference::create($file);
        unset($file);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    /**
     * The same snapshot gets the same instance, and another snapshot a new one.
     */
    public function testOneSnapshotGetsOneEntry(): void
    {
        $file = self::sourceFile();

        self::assertSame(FileMembers::of($file), FileMembers::of($file));
        self::assertNotSame(FileMembers::of($file), FileMembers::of(self::sourceFile()));
    }

    /**
     * An empty snapshot of a PHP file.
     */
    private static function sourceFile(): SourceFile
    {
        return new SourceFile(
            PHPVersion::fromParts(major: 8, minor: 1),
            'modules/corpus/src/Thing.php',
            '<?php',
            [],
            new NodeStore([], '', 0),
            new ResolvedNameStore('', '', '', 0),
            new TriviaStore('', 0),
            null,
        );
    }
}
