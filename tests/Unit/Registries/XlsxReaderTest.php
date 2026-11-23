<?php

declare(strict_types=1);

namespace Tests\Unit\Registries;

use App\Exceptions\RegistryRequestFailed;
use App\Services\Registries\Parsing\XlsxReader;
use PHPUnit\Framework\TestCase;

final class XlsxReaderTest extends TestCase
{
    private const string NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function workbook(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $this->paths[] = $path;
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * A workbook whose first sheet (by the workbook's order) is xl/worksheets/data.xml.
     */
    private function sample(string $sheetData): string
    {
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        return $this->workbook([
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="'.self::NS.'" xmlns:r="'.$rel.'"><sheets>'
                .'<sheet name="Datos" sheetId="2" r:id="rId7"/><sheet name="SQL" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId7" Target="/xl/worksheets/data.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="'.self::NS.'">'
                .'<si><t>ID</t></si><si><t>TITLE</t></si><si><t>DATE</t></si><si><t>STATUS</t></si>'
                .'<si><r><t>Fase </t></r><r><t>3</t></r><rPh><t>ignored</t></rPh></si><si><t xml:space="preserve"> Finalizado </t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="'.self::NS.'"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>SQL</t></is></c></row></sheetData></worksheet>',
            'xl/worksheets/data.xml' => '<?xml version="1.0"?><worksheet xmlns="'.self::NS.'"><sheetData>'.$sheetData.'</sheetData></worksheet>',
        ]);
    }

    public function test_rows_are_keyed_by_header_with_shared_inline_and_missing_cells(): void
    {
        $path = $this->sample(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2"><v>12</v></c><c r="B2" t="inlineStr"><is><t>Café &amp; té</t></is></c><c r="C2"><v>41047</v></c><c r="D2" t="s"><v>4</v></c></row>'
            .'<row r="3"><c r="A3"><v>13</v></c><c r="D3" t="s"><v>5</v></c></row>'
            .'<row r="4"></row>'
            .'<row r="5"><c r="B5" t="str"><v>  </v></c></row>'
            .'<row r="6"><c r="A6"><v>14</v></c><c r="E6" t="b"><v>1</v></c></row>',
        );

        $reader = new XlsxReader;

        $this->assertSame(['ID', 'TITLE', 'DATE', 'STATUS'], $reader->header($path));
        $this->assertSame([
            ['ID' => '12', 'TITLE' => 'Café & té', 'DATE' => '41047', 'STATUS' => 'Fase 3'],
            ['ID' => '13', 'TITLE' => null, 'DATE' => null, 'STATUS' => 'Finalizado'],
            ['ID' => '14', 'TITLE' => null, 'DATE' => null, 'STATUS' => null],
        ], iterator_to_array($reader->rows($path)));
    }

    public function test_column_letters_become_indexes(): void
    {
        $this->assertSame(0, XlsxReader::columnIndex('A1'));
        $this->assertSame(25, XlsxReader::columnIndex('Z9'));
        $this->assertSame(27, XlsxReader::columnIndex('AB12'));
    }

    public function test_a_file_that_is_not_a_workbook_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->paths[] = $path;
        file_put_contents($path, '<html>not a workbook</html>');

        $this->expectException(RegistryRequestFailed::class);
        iterator_to_array((new XlsxReader)->rows($path));
    }

    public function test_a_zip_without_a_workbook_is_rejected(): void
    {
        $path = $this->workbook(['readme.txt' => 'hello']);

        $this->expectException(RegistryRequestFailed::class);
        (new XlsxReader)->header($path);
    }
}
