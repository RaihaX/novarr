<?php

namespace Tests\Unit;

use App\Support\Bytes;
use PHPUnit\Framework\TestCase;

class BytesTest extends TestCase
{
    public function test_bytes_below_one_kilobyte_are_whole_bytes(): void
    {
        $this->assertSame('0 B', Bytes::human(0));
        $this->assertSame('1 B', Bytes::human(1));
        $this->assertSame('1023 B', Bytes::human(1023));
        $this->assertSame('0 B', Bytes::human(null));
        $this->assertSame('0 B', Bytes::human(-5));
    }

    public function test_binary_units_with_one_decimal(): void
    {
        $this->assertSame('1.0 KB', Bytes::human(1024));
        $this->assertSame('1.5 KB', Bytes::human(1536));
        $this->assertSame('1.0 MB', Bytes::human(1024 ** 2));
        $this->assertSame('1.5 GB', Bytes::human((int) (1.5 * 1024 ** 3)));
        $this->assertSame('680.0 MB', Bytes::human(680 * 1024 ** 2));
        $this->assertSame('1.0 TB', Bytes::human(1024 ** 4));
        $this->assertSame('3.6 TB', Bytes::human((int) (3.6 * 1024 ** 4)));
    }

    public function test_a_value_rounding_up_to_1024_moves_to_the_next_unit(): void
    {
        $this->assertSame('1.0 MB', Bytes::human(1024 ** 2 - 1));      // 1023.999 KB
        $this->assertSame('1.0 GB', Bytes::human(1024 ** 3 - 1024));   // 1023.999 MB
        $this->assertSame('1023.9 MB', Bytes::human((int) (1023.9 * 1024 ** 2)));
    }

    public function test_terabytes_are_the_largest_unit(): void
    {
        $this->assertSame('2,048.0 TB', Bytes::human(2 * 1024 ** 5));
    }

    public function test_decimals_can_be_chosen(): void
    {
        $this->assertSame('1.50 GB', Bytes::human((int) (1.5 * 1024 ** 3), 2));
    }
}
