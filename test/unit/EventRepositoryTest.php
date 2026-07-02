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

use Horde\Db\Adapter;
use Horde\Satisfiend\EventFilter;
use Horde\Satisfiend\EventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventRepository::class)]
final class EventRepositoryTest extends TestCase
{
    public function testFindByDeliveryIdReturnsNullOnEmptyId(): void
    {
        // Empty id must short-circuit; the adapter should never be called.
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->never())->method('selectOne');

        $repo = new EventRepository($adapter);
        $this->assertNull($repo->findByDeliveryId(''));
    }

    public function testFindByDeliveryIdReturnsNullWhenAdapterReturnsFalse(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectOne')
            ->with(
                $this->stringContains('WHERE delivery_id = ?'),
                ['abc-123'],
            )
            ->willReturn(false);

        $repo = new EventRepository($adapter);
        $this->assertNull($repo->findByDeliveryId('abc-123'));
    }

    public function testFindByDeliveryIdReturnsRow(): void
    {
        $row = ['event_id' => 1, 'delivery_id' => 'abc-123', 'slug' => 'github'];
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectOne')
            ->with($this->stringContains('WHERE delivery_id = ?'), ['abc-123'])
            ->willReturn($row);

        $repo = new EventRepository($adapter);
        $this->assertSame($row, $repo->findByDeliveryId('abc-123'));
    }

    public function testListReturnsAdapterResult(): void
    {
        $rows = [['event_id' => 2], ['event_id' => 1]];
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->willReturn($rows);

        $repo = new EventRepository($adapter);
        $this->assertSame($rows, $repo->list(new EventFilter()));
    }

    public function testListWithoutFilterOmitsWhereClause(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->with(
                $this->logicalAnd(
                    $this->logicalNot($this->stringContains('WHERE')),
                    $this->stringContains('ORDER BY event_id DESC'),
                    $this->stringContains('LIMIT 50'),
                    $this->stringContains('OFFSET 0'),
                ),
                [],
            )
            ->willReturn([]);

        (new EventRepository($adapter))->list(new EventFilter(), page: 1, perPage: 50);
    }

    public function testListWithFilterComposesWhereClauseAndBindsParams(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->with(
                $this->stringContains('WHERE slug = ? AND event_type = ? AND debug = ?'),
                ['github', 'push', 0],
            )
            ->willReturn([]);

        (new EventRepository($adapter))->list(new EventFilter(
            slug: 'github',
            eventType: 'push',
            debug: false,
        ));
    }

    public function testListPaginationOffsetIsComputedFromPage(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('LIMIT 25'),
                    $this->stringContains('OFFSET 50'),
                ),
                [],
            )
            ->willReturn([]);

        (new EventRepository($adapter))->list(new EventFilter(), page: 3, perPage: 25);
    }

    public function testListPerPageIsClampedToOneHundred(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->with($this->stringContains('LIMIT 100'), [])
            ->willReturn([]);

        (new EventRepository($adapter))->list(new EventFilter(), page: 1, perPage: 500);
    }

    public function testListPageOneMinimumOffset(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->with($this->stringContains('OFFSET 0'), [])
            ->willReturn([]);

        (new EventRepository($adapter))->list(new EventFilter(), page: 0, perPage: 50);
    }

    public function testFreeTextSearchFansAcrossFourColumns(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectAll')
            ->with(
                $this->stringContains(
                    '(repository LIKE ? OR actor LIKE ? OR delivery_id LIKE ? OR node_id LIKE ?)'
                ),
                ['%octo%', '%octo%', '%octo%', '%octo%'],
            )
            ->willReturn([]);

        (new EventRepository($adapter))->list(new EventFilter(q: 'octo'));
    }

    public function testCountUsesSameFilterAsList(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())
            ->method('selectValue')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('SELECT COUNT(*) FROM satisfiend_events'),
                    $this->stringContains('WHERE status = ?'),
                ),
                ['pending'],
            )
            ->willReturn(42);

        $repo = new EventRepository($adapter);
        $this->assertSame(42, $repo->count(new EventFilter(status: 'pending')));
    }
}
