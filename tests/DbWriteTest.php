<?php

use PHPUnit\Framework\TestCase;

final class DbWriteTest extends TestCase
{
    public function testGuardSelectAddsLimitWhenMissing(): void
    {
        $sql = 'SELECT * FROM products';
        $out = guardSelect($sql, 100);
        $this->assertStringContainsString('LIMIT 100', strtoupper($out));
    }

    public function testGuardSelectRejectsNonSelect(): void
    {
        $this->expectException(Exception::class);
        guardSelect('DELETE FROM products', 10);
    }

    public function testGuardSelectRejectsMultipleStatements(): void
    {
        $this->expectException(Exception::class);
        guardSelect('SELECT 1; SELECT 2', 10);
    }

    public function testValidateWriteInsertAllowedColumns(): void
    {
        $exposed = [
            'products' => [
                'write' => [ 'insert' => ['name','sku'] ]
            ]
        ];
        $schema = [];
        $op = [ 'type' => 'insert', 'table' => 'products', 'values' => ['name'=>'N','sku'=>'S'] ];
        $res = validateWrite($op, $exposed, $schema);
        $this->assertSame('insert', $res['type']);
        $this->assertSame('products', $res['table']);
    }

    public function testValidateWriteInsertRejectsUnexpectedColumn(): void
    {
        $this->expectException(Exception::class);
        $exposed = [ 'products' => [ 'write' => [ 'insert' => ['name'] ] ] ];
        $schema = [];
        $op = [ 'type' => 'insert', 'table' => 'products', 'values' => ['name'=>'N','sku'=>'S'] ];
        validateWrite($op, $exposed, $schema);
    }

    public function testValidateWriteUpdateRequiresWhere(): void
    {
        $this->expectException(Exception::class);
        $exposed = [ 'products' => [ 'write' => [ 'update' => ['name'] ] ] ];
        $schema = [];
        $op = [ 'type' => 'update', 'table' => 'products', 'set' => ['name'=>'N'] ];
        validateWrite($op, $exposed, $schema);
    }
}

