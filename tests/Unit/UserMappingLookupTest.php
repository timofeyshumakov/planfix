<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mapping\UserMapping;
use PHPUnit\Framework\TestCase;

final class UserMappingLookupTest extends TestCase
{
    private array $mapping = [
        'Иван Петров' => 101,
        'Мария Сидорова' => 202,
        'Алексей Богданов' => 303,
    ];

    public function testExactMatch(): void
    {
        $this->assertSame(101, UserMapping::findBitrixUser('Иван Петров', $this->mapping));
    }

    public function testReversedOrderParts(): void
    {
        $this->assertSame(101, UserMapping::findBitrixUser('Петров Иван', $this->mapping));
    }

    public function testSingleTokenReturnsNull(): void
    {
        $this->assertNull(UserMapping::findBitrixUser('Иван', $this->mapping));
    }

    public function testEmptyName(): void
    {
        $this->assertNull(UserMapping::findBitrixUser('', $this->mapping));
        $this->assertNull(UserMapping::findBitrixUser(null, $this->mapping));
    }

    public function testUnknownUser(): void
    {
        $this->assertNull(UserMapping::findBitrixUser('Неизвестный Человек', $this->mapping));
    }
}
