<?php

namespace Tests\Unit;

use App\Domain\Crm\Phone;
use PHPUnit\Framework\TestCase;

class CrmPhoneTest extends TestCase
{
    public function test_indonesian_numbers_normalize_to_one_format(): void
    {
        foreach (['0812-3456-7890', '+62 812 3456 7890', '812 3456 7890', '6281234567890', '(0812) 34567890', '0062 812 3456 7890', '81234567890.0'] as $raw) {
            $this->assertSame('6281234567890', Phone::normalize($raw), $raw);
        }
        foreach (['', '-', '021 555 1234', '12345', 'abc', '0812'] as $bad) {
            $this->assertNull(Phone::normalize($bad), $bad);
        }
        $this->assertSame('0812-3456-7890', Phone::display('6281234567890'));
        $this->assertSame('0812-****-7890', Phone::masked('6281234567890'));
    }
}
