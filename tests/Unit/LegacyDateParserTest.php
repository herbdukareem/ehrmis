<?php

namespace Tests\Unit;

use App\Domain\Legacy\Services\LegacyDateParser;
use Tests\TestCase;

class LegacyDateParserTest extends TestCase
{
    public function test_it_parses_excel_serial_dates_from_spreadsheet_imports(): void
    {
        $parser = app(LegacyDateParser::class);

        $this->assertSame('1964-05-29', $parser->parse(23526, 'dob')['value']);
        $this->assertSame('2025-07-03', $parser->parse(45841, 'dfa')['value']);
        $this->assertSame('2024-05-29', $parser->parse('45441', 'edor')['value']);
        $this->assertSame('2026-05-02', $parser->parse(46144, 'edor')['value']);
    }
}
