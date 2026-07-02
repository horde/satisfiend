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

use Horde\Satisfiend\EventFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventFilter::class)]
final class EventFilterTest extends TestCase
{
    public function testDefaultConstructionYieldsNoConstraints(): void
    {
        $f = new EventFilter();
        $this->assertNull($f->slug);
        $this->assertNull($f->eventType);
        $this->assertNull($f->debug);
        $this->assertSame([], $f->toQueryParams());
    }

    public function testFromQueryParamsSkipsEmptyStrings(): void
    {
        $f = EventFilter::fromQueryParams([
            'slug' => '',
            'event_type' => '   ',
            'repository' => 'horde/example',
        ]);
        $this->assertNull($f->slug);
        $this->assertNull($f->eventType);
        $this->assertSame('horde/example', $f->repository);
    }

    public function testFromQueryParamsIgnoresNonStringValues(): void
    {
        // Query params can legitimately be arrays under `foo[]=` syntax;
        // filter fields should silently drop those instead of crashing.
        $f = EventFilter::fromQueryParams([
            'slug' => ['nope'],
            'actor' => 'octocat',
        ]);
        $this->assertNull($f->slug);
        $this->assertSame('octocat', $f->actor);
    }

    /**
     * @return list<array{0:string,1:?bool}>
     */
    public static function debugValueProvider(): array
    {
        return [
            ['1', true],
            ['true', true],
            ['TRUE', true],
            ['yes', true],
            ['0', false],
            ['false', false],
            ['no', false],
            ['maybe', null],
            ['', null],
        ];
    }

    #[DataProvider('debugValueProvider')]
    public function testDebugCoercion(string $input, ?bool $expected): void
    {
        $f = EventFilter::fromQueryParams(['debug' => $input]);
        $this->assertSame($expected, $f->debug);
    }

    public function testToQueryParamsRoundTrip(): void
    {
        $original = EventFilter::fromQueryParams([
            'slug' => 'github',
            'event_type' => 'push',
            'debug' => '1',
            'q' => 'octocat',
        ]);
        $rebuilt = EventFilter::fromQueryParams($original->toQueryParams());
        $this->assertEquals($original, $rebuilt);
    }

    public function testDebugFlagRoundTripsAsStringForm(): void
    {
        $f = new EventFilter(debug: false);
        $this->assertSame(['debug' => '0'], $f->toQueryParams());
    }
}
