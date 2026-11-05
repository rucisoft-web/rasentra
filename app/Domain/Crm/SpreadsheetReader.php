<?php

namespace App\Domain\Crm;

use App\Support\DomainException;
use ZipArchive;

/**
 * Membaca lembar pertama file .xlsx atau .csv menjadi baris array. Cukup untuk daftar member
 * (nama, nomor HP, kode member, ...) tanpa pustaka spreadsheet tambahan, sehingga tetap ringan
 * di hosting cPanel. Pemisah CSV (koma atau titik koma ala Excel Indonesia) dideteksi otomatis.
 */
final class SpreadsheetReader
{
    /** @return list<list<string>> */
    public function read(string $path, string $fileName): array
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx' => $this->xlsx($path),
            'csv', 'txt' => $this->csv((string) file_get_contents($path)),
            default => throw new DomainException('crm_file_type', 'Gunakan file .xlsx atau .csv.', 422),
        };
    }

    /** @return list<list<string>> */
    public function csv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($content, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');

        $rows = [];
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        while (($row = fgetcsv($h, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(fn ($v) => trim((string) $v), $row);
            }
        }
        fclose($h);

        return $rows;
    }

    /** @return list<list<string>> */
    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new DomainException('crm_file_invalid', 'File Excel tidak bisa dibuka. Simpan ulang sebagai .xlsx atau .csv.', 422);
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sst = $this->xml($xml);
            foreach ($sst->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheetPath = $this->firstSheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheetXml === false) {
            throw new DomainException('crm_file_invalid', 'Lembar pertama file Excel tidak ditemukan.', 422);
        }

        $rows = [];
        foreach ($this->xml($sheetXml)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = $this->columnIndex((string) $c['r']);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };
                $cells[$col] = trim($value);
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $rows[] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
            }
        }

        return $rows;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $workbook = $this->xml($wb);
        $sheet = $workbook->sheets->sheet[0] ?? null;
        $rid = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach ($this->xml($rels)->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref);
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        // Tanpa entitas eksternal (aman dari XXE).
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        if ($doc === false) {
            throw new DomainException('crm_file_invalid', 'Isi file Excel rusak.', 422);
        }

        return $doc;
    }
}
