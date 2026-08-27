<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dates;
use PHPUnit\Framework\TestCase;

final class DatesTest extends TestCase
{
    public function testAllowsMissingDates(): void
    {
        $this->assertTrue(Dates::isValidEndDate(null, null));
        $this->assertTrue(Dates::isValidEndDate('2024-01-01', ''));
        $this->assertTrue(Dates::isValidEndDate('', '2024-01-02'));
    }

    public function testEndAfterStart(): void
    {
        $this->assertTrue(Dates::isValidEndDate('2024-01-01 10:00:00', '2024-01-02 10:00:00'));
    }

    public function testEndBeforeOrEqualStart(): void
    {
        $this->assertFalse(Dates::isValidEndDate('2024-01-02 10:00:00', '2024-01-01 10:00:00'));
        $this->assertFalse(Dates::isValidEndDate('2024-01-01 10:00:00', '2024-01-01 10:00:00'));
    }

    public function testInvalidFormat(): void
    {
        $this->assertFalse(Dates::isValidEndDate('not-a-date', 'also-bad'));
    }
}
