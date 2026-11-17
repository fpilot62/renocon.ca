<?php
/**
 * Small .xlsx writer. This repo does not ship an Excel library.
 * The server's PhpSpreadsheet file belongs to the old page builder and
 * needs that builder's globals, so this report does not call it.
 * Writes inline strings and numbers. No macros.
 */

function xlsx_col_name(int $index): string
{
    $name = '';
    $n = $index;
    while ($n > 0) {
        $n--;
        $name = chr(65 + ($n % 26)) . $name;
        $n = intdiv($n, 26);
    }
    return $name;
}

function xlsx_sheet_name(string $name, int $fallback): string
{
    $name = trim(str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $name));
    if ($name === '') {
        $name = 'Sheet' . $fallback;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 31, 'UTF-8');
    }
    return substr($name, 0, 31);
}

function xlsx_xml_text(string $value): string
{
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
    if (!is_string($value)) {
        $value = '';
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** A leading = + - or @ would become a formula. Store it as text. */
function xlsx_safe_text(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function xlsx_number_text($value): string
{
    if (!is_finite((float)$value)) {
        return '0';
    }
    $text = number_format((float)$value, 2, '.', '');
    if ($text === '-0.00') {
        return '0.00';
    }
    return $text;
}

/**
 * @param array<int,array<int,mixed>> $rows
 */
function xlsx_sheet_xml(array $rows): string
{
    $body = '';
    $r = 0;
    foreach ($rows as $row) {
        $r++;
        if (!is_array($row)) {
            $row = [$row];
        }
        $cells = '';
        $c = 0;
        foreach ($row as $value) {
            $c++;
            $ref = xlsx_col_name($c) . $r;
            if ($value === null || $value === '') {
                $cells .= '<c r="' . $ref . '"/>';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $cells .= '<c r="' . $ref . '"><v>' . xlsx_number_text($value) . '</v></c>';
                continue;
            }
            $text = xlsx_xml_text(xlsx_safe_text((string)$value));
            $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
        }
        $body .= '<row r="' . $r . '">' . $cells . '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $body . '</sheetData></worksheet>';
}

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>';
}

/**
 * @param array<string,array<int,array<int,mixed>>> $sheets name => rows
 */
function xlsx_build(array $sheets): string
{
    if (!$sheets) {
        throw new RuntimeException('The workbook has no sheets.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel export needs the PHP zip extension.');
    }
    $sheetXml = [];
    $sheetNames = [];
    $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++;
        if (!is_array($rows)) {
            throw new RuntimeException('A sheet has no rows.');
        }
        $sheetNames[] = xlsx_sheet_name((string)$name, $i);
        $sheetXml[$i] = xlsx_sheet_xml($rows);
    }
    $sheetTags = '';
    $rels = '';
    $overrides = '';
    foreach ($sheetNames as $n => $sheetName) {
        $id = $n + 1;
        $sheetTags .= '<sheet name="' . xlsx_xml_text($sheetName) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $styleId = count($sheetNames) + 1;
    $rels .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetTags . '</sheets></workbook>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $overrides
        . '</Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $rels
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        throw new RuntimeException('The Excel file could not be started.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('The Excel file could not be started.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', xlsx_styles_xml());
    foreach ($sheetXml as $id => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', $xml);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('The Excel file was empty.');
    }
    return $bytes;
}
