<?php

namespace App\Domain\Staff\Services;

use App\Domain\Legacy\Support\LegacyIdentifier;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class StaffPersonalDetailsWorkbookReader
{
    public function read(string $path, string $sheetName, bool $includeSalaryPlacement = false): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('Staff workbook not found.');
        }
        $reader = IOFactory::createReaderForFile($path);
        if (! in_array($sheetName, $reader->listWorksheetNames($path), true)) {
            throw new InvalidArgumentException('Selected worksheet not found.');
        }
        $reader->setLoadSheetsOnly($sheetName);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName($sheetName);
            $lastColumn = $sheet->getHighestDataColumn();
            $lastRow = $sheet->getHighestDataRow();
            $headers = [];
            foreach ($sheet->rangeToArray('A1:'.$lastColumn.'1', null, false, false)[0] as $index => $header) {
                $headers[strtolower(trim((string) $header))] = Coordinate::stringFromColumnIndex($index + 1);
            }
            $nameColumn = $headers['surname'] ?? $headers['name'] ?? $headers['full_name'] ?? null;
            if (! $nameColumn || ! isset($headers['file_no'], $headers['lga'])) {
                throw new InvalidArgumentException('The sheet must include a name, file_no and lga column.');
            }
            if ($includeSalaryPlacement && ! isset($headers['level_step'])) {
                throw new InvalidArgumentException('The sheet must include an explicit Level_Step column.');
            }
            $rows = [];
            for ($number = 2; $number <= $lastRow; $number++) {
                $read = function (?string $column, bool $formatted = true) use ($sheet, $number): mixed {
                    if ($column === null) {
                        return null;
                    }
                    $cell = $sheet->getCell($column.$number);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw new InvalidArgumentException('Identity fields must contain values, not formulas: '.$column.$number);
                    }

                    return $formatted ? $cell->getFormattedValue() : $cell->getValue();
                };
                $name = LegacyIdentifier::normalize($read($nameColumn));
                if ($name === null) {
                    continue;
                }
                $row = [
                    'row' => $number, 'name' => $name,
                    'cno' => LegacyIdentifier::normalize($read($headers['cno'] ?? null)),
                    'psn' => LegacyIdentifier::normalize($read($headers['psn'] ?? null)),
                    'mda' => LegacyIdentifier::normalize($read($headers['mda'] ?? null)),
                    'department' => LegacyIdentifier::normalize($read($headers['department'] ?? null)),
                    'dob' => $this->date($read($headers['date_of_birth'] ?? $headers['dob'] ?? null, false)),
                    'file_no' => LegacyIdentifier::normalize($read($headers['file_no'])),
                    'lga' => LegacyIdentifier::normalize($read($headers['lga'])),
                ];
                if ($includeSalaryPlacement) {
                    $row['level_step'] = $read($headers['level_step']);
                    $row['source_cadre'] = $read($headers['initial_cadre'] ?? null);
                    $row['source_rank'] = $read($headers['initial_rank'] ?? null);
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    protected function date(mixed $value): ?string
    {
        if (is_numeric($value) && (float) $value > 0) {
            return Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!Y-m-d H:i:s', '!d/m/Y', '!d-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, trim((string) $value));
            if ($date && ! DateTimeImmutable::getLastErrors()) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
