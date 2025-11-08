<?php

use PHPUnit\Framework\TestCase;

final class JsonExtractTest extends TestCase
{
    public function testExtractsFromJsonFence(): void
    {
        $txt = "Here\n```json\n{\"a\":1}\n```\nthanks";
        $out = extractJsonPayload($txt);
        $this->assertIsArray($out);
        $this->assertSame(1, $out['a'] ?? null);
    }

    public function testExtractsFromPlainObject(): void
    {
        $txt = "random {\"b\":2} tail";
        $out = extractJsonPayload($txt);
        $this->assertIsArray($out);
        $this->assertSame(2, $out['b'] ?? null);
    }
}

