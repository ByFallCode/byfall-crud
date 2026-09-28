<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Generation;

use ByfallCode\ByfallCrud\Generation\Support\SafeFileWriter;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class SafeFileWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'byfall-writer-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
    }

    public function test_it_preserves_existing_files_unless_force_is_enabled(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'Model.php';
        $writer = new SafeFileWriter();

        self::assertTrue($writer->write($path, 'first'));
        self::assertFalse($writer->write($path, 'second'));
        self::assertSame('first', file_get_contents($path));
        self::assertTrue($writer->write($path, 'second', true));
        self::assertSame('second', file_get_contents($path));
    }
}
