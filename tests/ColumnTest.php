<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\Column;
use Givanov95\DataTable\Columns\EnumColumn;
use Givanov95\DataTable\Columns\RelationColumn;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testColumnExposesGetters(): void
    {
        $col = new Column('id', '#', searchable: true, orderable: true);

        $this->assertSame('id', $col->getDatabaseColumnName());
        $this->assertSame('#', $col->getLabel());
        $this->assertTrue($col->isSearchable());
        $this->assertTrue($col->isOrderable());
        $this->assertFalse($col->isExactMatch());
    }

    public function testRelationColumnParsesDotPath(): void
    {
        $rel = new RelationColumn('user.profile.name', 'Profile name', searchable: true);

        $this->assertSame('name', $rel->relationColumn);
        $this->assertSame('user.profile', $rel->relationString);
        $this->assertSame(['user', 'profile', 'name'], $rel->relations);
        $this->assertSame('profile', $rel->targetTable);
        $this->assertSame('name', $rel->getDatabaseColumnName());
    }

    public function testRelationColumnRejectsShallowPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RelationColumn('user');
    }

    public function testEnumColumnRejectsNonEnumString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EnumColumn('NotAClassThatExists');
    }
}
