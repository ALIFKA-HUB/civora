<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountImportTemplateController
{
    public function __invoke(Request $request, string $entityType, string $format): StreamedResponse
    {
        validator(compact('entityType', 'format'), [
            'entityType' => ['required', Rule::in(['student', 'teacher'])],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ])->validate();

        $headers = $entityType === 'student' ? ['name', 'nisn', 'major_code', 'class_code'] : ['name', 'nip'];
        $fileName = 'template-'.$entityType.'-civora.'.$format;
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers): void {
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers);
                fclose($output);
            }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).'1', $header, DataType::TYPE_STRING);
        }
        foreach (array_intersect(['nisn', 'nip'], $headers) as $identifier) {
            // Identifier cells stay text even when spreadsheet software fills the template.
            $column = Coordinate::stringFromColumnIndex(array_search($identifier, $headers, true) + 1);
            $sheet->getStyle($column.'2:'.$column.'5001')->getNumberFormat()->setFormatCode('@');
        }

        return response()->streamDownload(function () use ($book): void {
            $writer = new Xlsx($book);
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $fileName, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
