<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * Reads an .xlsx with nothing but zlib.
 *
 * A workbook is a zip of XML files. PHP's ZipArchive would be the obvious
 * tool, but it comes from ext-zip, which is not available everywhere — so the
 * archive's central directory is walked directly and the entries inflated
 * with gzinflate().
 *
 * Hyperlinks are read too: some suppliers put the product's address behind the
 * text of a cell rather than in a column of its own.
 */
class XlsxReader
{
    /** End-of-central-directory and central-directory signatures. */
    protected const EOCD = "\x50\x4b\x05\x06";

    protected const CDIR = "\x50\x4b\x01\x02";

    /** @var array<int, string> */
    protected array $shared = [];

    public function __construct(protected string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
    }

    /**
     * Every row of the sheet, as ['A' => value, 'B' => value, …].
     *
     * A row that carries hyperlinks gets them under '_links', keyed by the same
     * column letters, so a caller that does not care never sees them.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function rows(int $sheet = 1): \Generator
    {
        $archive = file_get_contents($this->path);

        if ($archive === false || ! str_starts_with($archive, "\x50\x4b")) {
            throw new RuntimeException('That file is not a workbook.');
        }

        $entries = $this->entries($archive);

        $this->shared = $this->sharedStrings($archive, $entries);

        $name = "xl/worksheets/sheet{$sheet}.xml";

        if (! isset($entries[$name])) {
            throw new RuntimeException("Sheet {$sheet} is missing from the workbook.");
        }

        $xml = $this->extract($archive, $entries[$name]);
        $links = $this->hyperlinks($xml, $archive, $entries, $sheet);

        $document = new SimpleXMLElement($xml);

        foreach ($document->sheetData->row as $row) {
            $cells = [];
            $rowLinks = [];

            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                $column = rtrim($reference, '0123456789');

                $cells[$column] = $this->value($cell);

                if (isset($links[$reference])) {
                    $rowLinks[$column] = $links[$reference];
                }
            }

            if ($rowLinks) {
                $cells['_links'] = $rowLinks;
            }

            if (array_filter($cells, fn ($v) => $v !== '' && $v !== [])) {
                yield $cells;
            }
        }
    }

    /* ------------------------------------------------------------------ cells */

    protected function value(SimpleXMLElement $cell): string
    {
        $type = (string) $cell['t'];

        // an inline string carries its own text instead of pointing at the table
        if ($type === 'inlineStr') {
            return trim((string) $cell->is->t);
        }

        $raw = (string) $cell->v;

        if ($raw === '') {
            return '';
        }

        if ($type === 's') {
            return $this->shared[(int) $raw] ?? '';
        }

        /*
         * Scientific notation is spelled out, whole or not.
         *
         * A spreadsheet writes a long number as "2.0000000158E+12" and a small
         * fraction as "2.4E-2", and a cell written that way is no longer the
         * thing it holds: the first is not a barcode any more, and the second
         * is not a price. Only the whole ones used to be spelled out, so every
         * reader downstream had to know about exponents on its own — and each
         * of them learned it separately, or didn't. Stripping the non-digits
         * out of "2.4E-2" leaves "2.42", a hundred times the real 0.024, and
         * that is what reached the supplier's stored row.
         *
         * Done here instead, once, so a cell is a number spelled the way a
         * person would spell it and nothing after this has to care.
         */
        if (stripos($raw, 'E') !== false && is_numeric($raw)) {
            return $this->spellOut((float) $raw) ?? trim($raw);
        }

        return trim($raw);
    }

    /**
     * A float written out in full, or null when it cannot be.
     *
     * Fractions are formatted to ten places and the trailing zeros taken off,
     * which is enough for any price and also quietly repairs the noise a
     * spreadsheet leaves behind: "3.5999999999999997E-2" is 0.036.
     *
     * A number too small to survive that, or too large to spell, keeps its
     * original text — wrong-looking is better than silently zero.
     */
    protected function spellOut(float $number): ?string
    {
        if (abs($number) >= 1e18) {
            return null;
        }

        if ($number == floor($number)) {
            return number_format($number, 0, '.', '');
        }

        $text = rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');

        return (float) $text === 0.0 ? null : $text;
    }

    /* ------------------------------------------------------------------ links */

    /**
     * Cell reference to URL, for the sheet's hyperlinks.
     *
     * The sheet names a relationship id; the address itself lives in a
     * relationships file beside it.
     *
     * @return array<string, string>
     */
    protected function hyperlinks(string $sheetXml, string $archive, array $entries, int $sheet): array
    {
        if (! str_contains($sheetXml, '<hyperlink')) {
            return [];
        }

        $relsName = "xl/worksheets/_rels/sheet{$sheet}.xml.rels";

        if (! isset($entries[$relsName])) {
            return [];
        }

        $targets = [];

        foreach ((new SimpleXMLElement($this->extract($archive, $entries[$relsName])))->Relationship as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $links = [];

        if (preg_match_all('/<hyperlink\s+([^>]+)\/?>/', $sheetXml, $matches)) {
            foreach ($matches[1] as $attributes) {
                preg_match('/ref="([^"]+)"/', $attributes, $ref);
                preg_match('/r:id="([^"]+)"/', $attributes, $id);

                if (! $ref || ! $id || ! isset($targets[$id[1]])) {
                    continue;
                }

                // a reference may span a range; the first cell is the one that holds it
                $cell = explode(':', $ref[1])[0];
                $links[$cell] = $targets[$id[1]];
            }
        }

        return $links;
    }

    /* ------------------------------------------------------------------ strings */

    /** @return array<int, string> */
    protected function sharedStrings(string $archive, array $entries): array
    {
        if (! isset($entries['xl/sharedStrings.xml'])) {
            return [];
        }

        $strings = [];

        foreach ((new SimpleXMLElement($this->extract($archive, $entries['xl/sharedStrings.xml'])))->si as $item) {
            // a string is split across runs when parts of it are styled
            $text = '';

            foreach ($item->xpath('.//*[local-name()="t"]') as $part) {
                $text .= (string) $part;
            }

            $strings[] = trim($text);
        }

        return $strings;
    }

    /* ------------------------------------------------------------------ zip */

    /**
     * The archive's central directory: name => [offset, compressed size, method].
     *
     * @return array<string, array{int, int, int}>
     */
    protected function entries(string $archive): array
    {
        $eocd = strrpos($archive, self::EOCD);

        if ($eocd === false) {
            throw new RuntimeException('The workbook is damaged: no directory found.');
        }

        $directory = unpack('vdisk/vstart/vhere/vtotal/Vsize/Voffset', substr($archive, $eocd + 4, 16));
        $position = $directory['offset'];
        $entries = [];

        for ($i = 0; $i < $directory['total']; $i++) {
            if (substr($archive, $position, 4) !== self::CDIR) {
                break;
            }

            $header = unpack(
                'vversion/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed'
                .'/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal',
                substr($archive, $position + 4, 42),
            );

            $name = substr($archive, $position + 46, $header['name']);

            $entries[$name] = [$header['local'], $header['compressed'], $header['method']];

            $position += 46 + $header['name'] + $header['extra'] + $header['comment'];
        }

        return $entries;
    }

    /** @param array{int, int, int} $entry */
    protected function extract(string $archive, array $entry): string
    {
        [$offset, $compressed, $method] = $entry;

        // the local header repeats the name and extra field, with its own lengths
        $local = unpack('vname/vextra', substr($archive, $offset + 26, 4));
        $start = $offset + 30 + $local['name'] + $local['extra'];

        $data = substr($archive, $start, $compressed);

        // 0 = stored, 8 = deflate; nothing else appears in a workbook
        return $method === 0 ? $data : (string) gzinflate($data);
    }
}
