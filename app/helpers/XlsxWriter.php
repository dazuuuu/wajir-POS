<?php
// app/helpers/XlsxWriter.php
// A small, dependency-free .xlsx writer (a zip of XML parts) for statement-
// style reports: a big title, a few info lines, a purple header row, banded
// rows with thin borders, money columns, totals, frozen header. Opens in
// Excel, LibreOffice, Google Sheets and Numbers without warnings.
//
//   $x = new XlsxWriter();
//   $x->sheet('Sheet 1', [
//       'title' => 'TRANSACTION STATEMENT', 'info' => ['Customer: Amina', …],
//       'columns' => [['Date', 18], ['Amount', 14, 'money'], …],  // label, width, kind
//       'rows' => [[…], …], 'totals' => ['', 'Total', 1234.5],     // optional
//   ]);
//   $x->send('statement.xlsx');   // or ->save($path)

class XlsxWriter
{
    private array $sheets = [];

    // Style ids, matching cellXfs in styles() below.
    private const S_TITLE = 1, S_INFO = 2, S_HEAD = 3, S_TEXT = 4, S_TEXT_B = 5, S_MONEY = 6, S_MONEY_B = 7,
        S_TOTAL_LABEL = 8, S_TOTAL_MONEY = 9, S_HEAD_MONEY = 10;

    public function sheet(string $name, array $spec): void
    {
        $name = mb_substr(preg_replace('#[\[\]\*\?/\\\\:]#', ' ', $name), 0, 31) ?: 'Sheet' . (count($this->sheets) + 1);
        $this->sheets[] = ['name' => $name] + $spec;
    }

    public function save(string $path): bool
    {
        if (!$this->sheets) {
            $this->sheet('Sheet 1', ['columns' => [['', 10]], 'rows' => []]);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(static fn($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(fn($i) => '<sheet name="' . $this->x($this->sheets[$i - 1]['name']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>', range(1, $n)))
            . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(static fn($i) => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, $n)))
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($s));
        }
        return $zip->close();
    }

    /** Send to the browser as a download. */
    public function send(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
    }

    private function sheetXml(array $s): string
    {
        $cols = $s['columns'] ?? [];
        $nc = max(1, count($cols));
        $last = self::col($nc);
        $xml = [];
        $merges = [];
        $r = 1;
        if (!empty($s['title'])) {
            $xml[] = '<row r="1" ht="34" customHeight="1">' . $this->cell('A1', $s['title'], self::S_TITLE) . '</row>';
            $merges[] = 'A1:' . $last . '1';
            $r = 2;
        }
        foreach ((array) ($s['info'] ?? []) as $line) {
            $xml[] = '<row r="' . $r . '">' . $this->cell('A' . $r, $line, self::S_INFO) . '</row>';
            $merges[] = 'A' . $r . ':' . $last . $r;
            $r++;
        }
        if (!empty($s['title']) || !empty($s['info'])) {
            $r++; // one empty row before the table
        }
        $headRow = $r;
        $cells = '';
        foreach ($cols as $i => $c) {
            $cells .= $this->cell(self::col($i + 1) . $r, $c[0], ($c[2] ?? '') === 'money' ? self::S_HEAD_MONEY : self::S_HEAD);
        }
        $xml[] = '<row r="' . $r . '" ht="24" customHeight="1">' . $cells . '</row>';
        foreach ((array) ($s['rows'] ?? []) as $k => $row) {
            $r++;
            $band = $k % 2 === 1;
            $cells = '';
            foreach ($cols as $i => $c) {
                $v = $row[$i] ?? '';
                $money = ($c[2] ?? '') === 'money';
                $cells .= $this->cell(self::col($i + 1) . $r, $v, $money ? ($band ? self::S_MONEY_B : self::S_MONEY) : ($band ? self::S_TEXT_B : self::S_TEXT), $money);
            }
            $xml[] = '<row r="' . $r . '" ht="20" customHeight="1">' . $cells . '</row>';
        }
        if (!empty($s['totals'])) {
            $r++;
            $cells = '';
            foreach ($cols as $i => $c) {
                $v = $s['totals'][$i] ?? '';
                $money = ($c[2] ?? '') === 'money';
                $cells .= $this->cell(self::col($i + 1) . $r, $v, $money ? self::S_TOTAL_MONEY : self::S_TOTAL_LABEL, $money && $v !== '');
            }
            $xml[] = '<row r="' . $r . '" ht="22" customHeight="1">' . $cells . '</row>';
        }
        $colXml = '';
        foreach ($cols as $i => $c) {
            $colXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) ($c[1] ?? 14) . '" customWidth="1"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $headRow . '" topLeftCell="A' . ($headRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . ($colXml ? '<cols>' . $colXml . '</cols>' : '')
            . '<sheetData>' . implode('', $xml) . '</sheetData>'
            . ($merges ? '<mergeCells count="' . count($merges) . '">' . implode('', array_map(static fn($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>' : '')
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';
    }

    private function cell(string $ref, $v, int $style, bool $number = false): string
    {
        if ($v === null || $v === '') {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        if ($number || (is_int($v) || is_float($v))) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $v) . '</v></c>';
        }
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $this->x((string) $v) . '</t></is></c>';
    }

    private static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - $m, 26);
        }
        return $s;
    }

    private function x(string $v): string
    {
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** White and purple, like the on-screen statement. */
    private function styles(): string
    {
        $border = '<border><left style="thin"><color rgb="FFE4D9F5"/></left><right style="thin"><color rgb="FFE4D9F5"/></right><top style="thin"><color rgb="FFE4D9F5"/></top><bottom style="thin"><color rgb="FFE4D9F5"/></bottom><diagonal/></border>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><color rgb="FF1F1630"/><name val="Calibri"/></font>'              // 0 body
            . '<font><b/><sz val="22"/><color rgb="FF3B1C6E"/><name val="Calibri"/></font>'          // 1 title
            . '<font><sz val="10"/><color rgb="FF6B5E80"/><name val="Calibri"/></font>'              // 2 info
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'          // 3 header
            . '<font><b/><sz val="11"/><color rgb="FF3B1C6E"/><name val="Calibri"/></font>'          // 4 totals
            . '</fonts>'
            . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF6D28D9"/><bgColor indexed="64"/></patternFill></fill>'  // 2 purple header
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5F0FD"/><bgColor indexed="64"/></patternFill></fill>'  // 3 band
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE9DEFB"/><bgColor indexed="64"/></patternFill></fill>'  // 4 totals
            . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="11">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                     // 0
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 1 title
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                       // 2 info
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>' // 3 head
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" indent="1" wrapText="0"/></xf>'   // 4 text
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>' // 5 text band
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>' // 6 money
            . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>' // 7 money band
            . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>' // 8 total label
            . '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>' // 9 total money
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>' // 10 head money
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
