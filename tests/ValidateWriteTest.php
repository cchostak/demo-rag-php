<?php

use PHPUnit\Framework\TestCase;

final class ValidateWriteTest extends TestCase
{
    public function testUpdateAllowedColumnsAndWhere(): void
    {
        $exposed = [
            'products' => [
                'write' => [ 'update' => ['price','stock'] ]
            ]
        ];
        $schema = [];
        $op = [
            'type' => 'update',
            'table' => 'products',
            'set' => ['price'=>12.5],
            'where_equals' => ['sku' => 'ABC']
        ];
        $res = validateWrite($op, $exposed, $schema);
        $this->assertSame('update', $res['type']);
        $this->assertArrayHasKey('set', $res);
        $this->assertArrayHasKey('where_equals', $res);
    }
}

