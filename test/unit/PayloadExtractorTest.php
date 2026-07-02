<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @license http://www.horde.org/licenses/lgpl21 LGPL
 */

namespace Horde\Satisfiend\Test\Unit;

use Horde\Satisfiend\PayloadExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadExtractor::class)]
final class PayloadExtractorTest extends TestCase
{
    public function testRepositoryAndActorFromPushPayload(): void
    {
        $payload = (object) [
            'repository' => (object) ['full_name' => 'horde/example'],
            'sender' => (object) ['login' => 'octocat'],
        ];
        $this->assertSame('horde/example', PayloadExtractor::repository($payload));
        $this->assertSame('octocat', PayloadExtractor::actor($payload));
    }

    public function testEmptyStringsWhenFieldsMissing(): void
    {
        $payload = (object) [];
        $this->assertSame('', PayloadExtractor::repository($payload));
        $this->assertSame('', PayloadExtractor::actor($payload));
        $this->assertSame('', PayloadExtractor::nodeId($payload));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function entityKeyProvider(): array
    {
        return [
            ['pull_request'],
            ['issue'],
            ['comment'],
            ['review'],
            ['release'],
        ];
    }

    #[DataProvider('entityKeyProvider')]
    public function testNodeIdProbesEachEntityKey(string $key): void
    {
        $payload = (object) [$key => (object) ['node_id' => 'NODE_ABC']];
        $this->assertSame('NODE_ABC', PayloadExtractor::nodeId($payload));
    }

    public function testNodeIdReturnsEmptyForPushLikePayloads(): void
    {
        // Push payloads have `head_commit` and `commits[]` but none of
        // the probed entity keys; nodeId should be empty.
        $payload = (object) [
            'ref' => 'refs/heads/main',
            'repository' => (object) ['full_name' => 'horde/example'],
            'head_commit' => (object) ['id' => 'abc'],
        ];
        $this->assertSame('', PayloadExtractor::nodeId($payload));
    }
}
