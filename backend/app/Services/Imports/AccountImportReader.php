<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use ZipArchive;

final class AccountImportReader
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 5000;

    private const MAX_COLUMNS = 16;

    private const MAX_RECORD_BYTES = 65536;

    private const MAX_CELL_CHARACTERS = 1000;

    private const MAX_ZIP_ENTRIES = 200;

    private const MAX_EXPANDED_BYTES = 50 * 1024 * 1024;

    private const HEADERS = [
        'student' => ['name', 'nisn', 'major_code', 'class_code'],
        'teacher' => ['name', 'nip'],
    ];

    /** @return array{rows: list<array<string, string>>, errors: list<array{row: int, field: string, message: string}>} */
    public function read(UploadedFile|string $source, string $entityType): array
    {
        if (! isset(self::HEADERS[$entityType])) {
            throw ValidationException::withMessages(['entity_type' => 'Jenis import tidak valid.']);
        }

        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        $extension = strtolower($source instanceof UploadedFile ? $source->getClientOriginalExtension() : pathinfo($source, PATHINFO_EXTENSION));
        $size = $source instanceof UploadedFile ? $source->getSize() : (is_file($path) ? filesize($path) : false);
        if (! $path || $size === false || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Ukuran berkas maksimal 10 MB.']);
        }

        if ($extension === 'csv') {
            [$headers, $records, $types] = $this->readCsv($path);
        } elseif ($extension === 'xlsx') {
            [$headers, $records, $types] = $this->readXlsx($path);
        } else {
            throw ValidationException::withMessages(['file' => 'Berkas harus berupa CSV UTF-8 atau XLSX.']);
        }

        $expected = self::HEADERS[$entityType];
        $headerErrors = [];
        foreach ($expected as $header) {
            if (! in_array($header, $headers, true) && ! ($entityType === 'teacher' && $header === 'nip')) {
                $headerErrors[] = ['row' => 1, 'field' => $header, 'message' => 'Kolom wajib tidak ditemukan.'];
            }
        }
        foreach ($headers as $header) {
            if (! in_array($header, $expected, true)) {
                $headerErrors[] = ['row' => 1, 'field' => 'header', 'message' => 'Kolom tidak dikenal.'];
            }
        }
        if (count($headers) !== count(array_unique($headers))) {
            $headerErrors[] = ['row' => 1, 'field' => 'header', 'message' => 'Nama kolom tidak boleh berulang.'];
        }
        if ($headerErrors !== []) {
            return ['rows' => [], 'errors' => $headerErrors];
        }

        $rows = [];
        $errors = [];
        foreach ($records as $index => $record) {
            $rowNumber = $index + 2;
            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'Berkas melebihi 5.000 baris data.']);
            }
            if (count($record) > count($headers)) {
                $errors[] = ['row' => $rowNumber, 'field' => 'header', 'message' => 'Jumlah kolom data melebihi header.'];
            }
            $record = array_pad(array_slice($record, 0, count($headers)), count($headers), '');
            if (count(array_filter($record, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($headers as $column => $header) {
                $value = (string) ($record[$column] ?? '');
                if ($this->isActiveContent($value)) {
                    $errors[] = ['row' => $rowNumber, 'field' => $header, 'message' => 'Formula atau konten aktif tidak diizinkan.'];
                }
                if (mb_strlen($value) > self::MAX_CELL_CHARACTERS) {
                    $errors[] = ['row' => $rowNumber, 'field' => $header, 'message' => 'Isi kolom melebihi 1.000 karakter.'];
                    $value = mb_substr($value, 0, self::MAX_CELL_CHARACTERS);
                }
                if ($extension === 'xlsx' && in_array($header, ['nisn', 'nip'], true)
                    && trim($value) !== '' && ! in_array($types[$index][$column] ?? null, [DataType::TYPE_STRING, DataType::TYPE_STRING2, DataType::TYPE_INLINE], true)) {
                    $errors[] = ['row' => $rowNumber, 'field' => $header, 'message' => 'Identifier XLSX harus disimpan sebagai teks agar nol awal tetap terjaga.'];
                }
                $row[$header] = trim($value);
            }
            $rows[] = $row;
        }

        if ($rows === [] && $headerErrors === []) {
            $errors[] = ['row' => 1, 'field' => 'file', 'message' => 'Berkas harus memiliki sedikitnya satu baris data.'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @return array{list<string>, list<list<string>>, array} */
    private function readCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'CSV harus menggunakan UTF-8.']);
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $this->assertCsvBounds($contents);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        if (! is_array($header)) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'CSV tidak memiliki header.']);
        }

        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        $records = [];
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $records[] = array_map(fn ($value) => (string) ($value ?? ''), $record);
            if (count($records) > self::MAX_ROWS) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'Berkas melebihi 5.000 baris data.']);
            }
        }
        fclose($stream);

        return [$headers, $records, []];
    }

    private function assertCsvBounds(string $contents): void
    {
        $quoted = false;
        $columns = 1;
        $recordBytes = 0;
        $length = strlen($contents);
        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];
            $recordBytes++;
            if ($recordBytes > self::MAX_RECORD_BYTES) {
                throw ValidationException::withMessages(['file' => 'Panjang satu baris CSV melebihi batas aman.']);
            }
            if ($character === '"') {
                if ($quoted && ($contents[$index + 1] ?? null) === '"') {
                    $index++;
                    $recordBytes++;
                } else {
                    $quoted = ! $quoted;
                }
            } elseif ($character === ',' && ! $quoted) {
                if (++$columns > self::MAX_COLUMNS) {
                    throw ValidationException::withMessages(['file' => 'CSV melebihi batas 16 kolom.']);
                }
            } elseif ($character === "\n" && ! $quoted) {
                $columns = 1;
                $recordBytes = 0;
            }
        }
        if ($quoted) {
            throw ValidationException::withMessages(['file' => 'CSV memiliki kutip yang tidak berpasangan.']);
        }
    }

    /** @return array{list<string>, list<list<string>>, array} */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Berkas XLSX tidak valid.']);
        }
        $expanded = 0;
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw ValidationException::withMessages(['file' => 'Struktur XLSX melebihi batas aman.']);
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = $entry['name'] ?? '';
            $expanded += (int) ($entry['size'] ?? 0);
            if ($expanded > self::MAX_EXPANDED_BYTES
                || preg_match('~(?:^|/)(?:activeX|embeddings|externalLinks|customXml|oleObjects|macrosheets|dialogsheets)(?:/|$)|\.bin$|xl/connections\.xml$~i', $name)) {
                $zip->close();
                throw ValidationException::withMessages(['file' => 'Konten aktif atau ukuran XLSX tidak diizinkan.']);
            }
        }
        $zip->close();

        try {
            $reader = IOFactory::createReader('Xlsx');
            $sheetNames = $reader->listWorksheetNames($path);
            $info = $reader->listWorksheetInfo($path);
            if (count($sheetNames) !== 1 || count($info) !== 1
                || $info[0]['totalRows'] > self::MAX_ROWS + 1 || $info[0]['totalColumns'] > 4) {
                throw new \RuntimeException('Invalid worksheet count');
            }
            $reader->setLoadSheetsOnly($sheetNames[0]);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 5001 && in_array($columnAddress, ['A', 'B', 'C', 'D'], true);
                }
            });
            $book = $reader->load($path);
            $sheet = $book->getSheet(0);
            $maxColumn = min($sheet->getHighestDataColumn(), 'D');
            $headers = [];
            $columnIndexes = range('A', $maxColumn);
            foreach ($columnIndexes as $column) {
                $cell = $sheet->getCell($column.'1');
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    throw new \RuntimeException('Formula header');
                }
                $headers[] = strtolower(trim((string) $cell->getValue()));
            }
            $records = [];
            $types = [];
            $lastRow = min($sheet->getHighestDataRow(), self::MAX_ROWS + 1);
            for ($row = 2; $row <= $lastRow; $row++) {
                $values = [];
                $types[$row - 2] = [];
                foreach ($columnIndexes as $columnIndex => $column) {
                    $cell = $sheet->getCell($column.$row);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $values[] = (string) $cell->getValue();
                    } else {
                        $values[] = (string) ($cell->getValue() ?? '');
                    }
                    $types[$row - 2][$columnIndex] = $cell->getDataType();
                }
                $records[] = $values;
            }
            $book->disconnectWorksheets();

            return [$headers, $records, $types];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'XLSX tidak dapat dibaca atau memuat struktur yang tidak diizinkan.']);
        }
    }

    private function isActiveContent(string $value): bool
    {
        return preg_match('/^\s*[=+@\-]/u', $value) === 1
            || preg_match('~<\s*[a-z!?/][^>]*>~i', $value) === 1;
    }
}
