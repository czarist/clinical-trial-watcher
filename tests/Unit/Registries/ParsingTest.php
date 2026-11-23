<?php

declare(strict_types=1);

namespace Tests\Unit\Registries;

use App\Services\Registries\Parsing\CsvReader;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Parsing\TextTable;
use PHPUnit\Framework\TestCase;

final class ParsingTest extends TestCase
{
    public function test_html_values_are_read_by_xpath_and_labels(): void
    {
        $html = <<<'HTML'
            <html><head><meta charset="windows-1252"><title>Trial</title></head><body>
            <h1 class="title">  A study of
              caf\xE9 </h1>
            <table><tr><th>Status:</th><td>Recruiting</td></tr><tr><th>Phase</th><td> II </td></tr></table>
            <dl><dt>Sponsor</dt><dd>Example &amp; Co</dd></dl>
            <ul class="ids"><li>NCT01234567</li><li></li><li>EUCTR2019-001234-12</li></ul>
            </body></html>
            HTML;
        $page = new HtmlDocument(str_replace('\xE9', "\xE9", $html));

        $this->assertSame('A study of café', $page->text('//h1[@class="title"]'));
        $this->assertSame(['NCT01234567', 'EUCTR2019-001234-12'], $page->texts('//ul[@class="ids"]/li'));
        $this->assertSame(['Status' => 'Recruiting', 'Phase' => 'II', 'Sponsor' => 'Example & Co'], $page->labelledValues());
        $this->assertNull($page->text('//h2'));
    }

    public function test_csv_rows_are_keyed_by_header_and_converted_to_utf8(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $content = "id,title,phase\nUMIN000001,".'"試験, 第一"'.",\n\nUMIN000002,短い,2,extra\n";
        file_put_contents($path, mb_convert_encoding($content, 'SJIS-win', 'UTF-8'));

        $rows = iterator_to_array((new CsvReader('SJIS-win'))->rows($path));

        // A UTF-8 export with a byte order mark keeps its first column name.
        file_put_contents($path, "\u{FEFF}".$content);
        $this->assertSame($rows, iterator_to_array((new CsvReader)->rows($path)));
        file_put_contents($path, "\u{FEFF}".'"id","title","phase"'."\nUMIN000003,x,1\n");
        $this->assertSame([['id' => 'UMIN000003', 'title' => 'x', 'phase' => '1']], iterator_to_array((new CsvReader)->rows($path)));
        unlink($path);

        $this->assertSame([
            ['id' => 'UMIN000001', 'title' => '試験, 第一', 'phase' => null],
            ['id' => 'UMIN000002', 'title' => '短い', 'phase' => '2'],
        ], $rows);
    }

    public function test_fixed_width_tables_join_wrapped_cells_and_skip_repeated_headers(): void
    {
        $text = implode("\n", [
            'Egyptian Drug Authority - Clinical trials',
            '',
            'No.   Protocol title              Phase    Sponsor',
            '1     A study of drug X in        III      Pharma A',
            '      adults with asthma                   Egypt',
            '2     Drug Y for migraine         II       Pharma B',
            "\f".'No.   Protocol title              Phase    Sponsor',
            '3     Drug Z                      I        Pharma C',
        ]);

        $rows = (new TextTable(['No.', 'Protocol title', 'Phase', 'Sponsor']))->parse($text);

        $this->assertSame([
            ['No.' => '1', 'Protocol title' => 'A study of drug X in adults with asthma', 'Phase' => 'III', 'Sponsor' => 'Pharma A Egypt'],
            ['No.' => '2', 'Protocol title' => 'Drug Y for migraine', 'Phase' => 'II', 'Sponsor' => 'Pharma B'],
            ['No.' => '3', 'Protocol title' => 'Drug Z', 'Phase' => 'I', 'Sponsor' => 'Pharma C'],
        ], $rows);
    }

    public function test_a_table_without_its_header_yields_no_rows(): void
    {
        $this->assertSame([], (new TextTable(['No.', 'Title']))->parse("some text\nwithout the table"));
    }
}
