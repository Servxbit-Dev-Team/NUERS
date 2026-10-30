<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class SalesReportExport
{
    public function render(array $report, string $format): array
    {
        if ($format === 'pdf') {
            return [app(DocumentPdf::class)->renderHtml(view('reports.merchant-sales', ['report' => $report])->render()), 'application/pdf'];
        }
        $rows = $this->rows($report);
        if ($format === 'xlsx') {
            return [$this->xlsx($rows), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($f, array_map(fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) ? "'".$value : $value, $row), escape: '');
        }
        rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);

        return [$csv, 'text/csv; charset=UTF-8'];
    }

    private function rows(array $r): array
    {
        $rows = [['NUERS Sales Report — Internal'], ['Business', $r['merchant']['name'], 'TIN', $r['merchant']['tin']], ['Date From (PHT)', $r['filters']['from'], 'Date To (PHT)', $r['filters']['to']], ['Generated', $r['generated_at']], ['Snapshot SHA-256', $r['snapshot_hash']], ['Applied filters', json_encode($r['filters'], JSON_UNESCAPED_UNICODE)], [$r['notice']], [],
            ['Date (PHT)', 'Document No.', 'Customer / Buyer', 'Buyer TIN', 'Document Type', 'Branch', 'Customer Type (current registry)', 'Gross incl. VAT (PHP)', 'VATable Base (PHP)', 'VAT (PHP)', 'Zero-Rated Base (PHP)', 'Exempt Base (PHP)', 'Non-VAT Base (PHP)', 'Net excl. VAT (PHP)', 'BIR Evidence', 'Included', 'Exclusion reason', 'Original Source Amount (PHP)', 'Source', 'Source ID', 'Original Invoice ID', 'Sale Category', 'Statutory Discount (PHP)', 'VAT Adjustment (PHP)', 'Manual Discount PHP', 'Total Discount PHP']];
        foreach ([...$r['included'], ...$r['excluded']] as $v) {
            $rows[] = $this->detailRow($v);
        }
        $t = $r['totals'];
        $rows[] = ['RECONCILED TOTAL', '', '', '', '', '', '', $t['gross'] / 100, $t['vatable'] / 100, $t['vat'] / 100, $t['zero'] / 100, $t['exempt'] / 100, $t['non_vat'] / 100, $t['net'] / 100, '', '', '', '', '', '', '', '', $t['statutory_discount'] / 100, $t['vat_adjustment'] / 100, $t['manual_discount'] / 100, $t['discount'] / 100];

        return $rows;
    }

    public function detailRow(array $v): array
    {
        return [$v['date'], $v['number'], $v['customer'], $v['tin'] ?? '', $v['type'], $v['branch'] ?? 'Not recorded', $v['customer_type'], $v['gross'] / 100, $v['vatable'] / 100, $v['vat'] / 100, $v['zero'] / 100, $v['exempt'] / 100, $v['non_vat'] / 100, $v['net'] / 100, $v['bir_status'], $v['included'] ? 'Yes' : 'No', $v['exclusion_reason'] ?? '', $v['source_amount'] / 100, $v['source'], $v['document_id'], $v['original_invoice_id'] ?? '', $v['sale_category'], $v['statutory_discount'] / 100, $v['vat_adjustment'] / 100, $v['manual_discount'] / 100, $v['discount'] / 100];
    }

    /** Keep large CSV files bounded in memory; worksheet/PDF exports have explicit limits. */
    public function writeCsv($handle, array $report, iterable $records): int
    {
        $skeleton = $this->rows([...$report, 'included' => [], 'excluded' => []]);
        $footer = array_pop($skeleton);
        $write = static fn ($row) => fputcsv($handle, array_map(static fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) ? "'".$value : $value, $row), escape: '');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($skeleton as $row) {
            $write($row);
        }
        $count = 0;
        foreach ($records as $row) {
            if ($write($this->detailRow($row)) === false) {
                throw new \RuntimeException('Export storage is full.');
            } $count++;
        }
        $write($footer);
        return $count;
    }

    private function xlsx(array $rows): string
    {
        $dir = storage_path('app/private/report-'.Str::uuid());
        File::makeDirectory($dir, 0700, true);
        try {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="9" topLeftCell="A10" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="24" width="22" customWidth="1"/></cols><sheetData>';
            foreach ($rows as $index => $row) {
                $xml .= '<row r="'.($index + 1).'">';
                foreach ($row as $i => $value) {
                    $column = '';
                    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                        $column = chr(65 + (($n - 1) % 26)).$column;
                    }
                    $ref = $column.($index + 1);
                    // All user-controlled values are inline strings, never spreadsheet formulas.
                    $xml .= is_int($value) || is_float($value) ? '<c r="'.$ref.'"><v>'.$value.'</v></c>' : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData><autoFilter ref="A9:X'.(count($rows) - 1).'"/></worksheet>';
            $zip = new ZipArchive();
            abort_unless($zip->open($dir.'/report.xlsx', ZipArchive::CREATE) === true, 503, 'Excel export could not be created.');
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sales Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();

            return file_get_contents($dir.'/report.xlsx');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
