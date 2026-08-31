<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public function test_nigerian_numbers_match_across_common_formats(): void
    {
        $this->assertTrue(Phone::matches('08030000001', '08030000001'));
        $this->assertTrue(Phone::matches('+234 803 000 0001', '08030000001'));
        $this->assertTrue(Phone::matches('2348030000001', '0803-000-0001'));
        $this->assertFalse(Phone::matches('08039999999', '08030000001'));
        $this->assertFalse(Phone::matches('', '08030000001'));
    }
}
