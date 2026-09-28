<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Unit\Schema;

use ByfallCode\ByfallCrud\Schema\Database\DatabaseSchemaSource;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseSchemaSourceTest extends TestCase
{
    public function test_it_rejects_an_unsupported_driver(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('sqlite');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Driver non supporté : sqlite');
        new DatabaseSchemaSource($connection);
    }
}
