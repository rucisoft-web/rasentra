<?php

namespace App\Domain\Crm;

use App\Models\Crm\Import;
use App\Models\Crm\Tag;
use App\Support\DomainException;
use Illuminate\Support\Carbon;

/**
 * Impor daftar member satu outlet. Baris judul dikenali dari nama kolom yang umum dipakai
 * outlet (Nama, No HP/WhatsApp/Telepon, Kode Member, Email, Tgl Lahir, Kota). Nomor yang
 * sudah terdaftar, baik di outlet ini maupun outlet lain, digabung ke pelanggan yang sama.
 */
final class MemberImporter
{
    public const MAX_ROWS = 50000;

    private const HEADERS = [
        'name' => ['nama', 'name', 'nama member', 'nama pelanggan', 'nama customer', 'customer', 'pelanggan', 'member'],
        'phone' => ['hp', 'no hp', 'nomor hp', 'no. hp', 'handphone', 'no handphone', 'phone', 'telepon', 'telp', 'no telp', 'whatsapp', 'wa', 'no wa', 'nomor wa', 'mobile', 'no. telp'],
        'member_code' => ['kode member', 'kode', 'member code', 'no member', 'nomor member', 'id member', 'member id', 'kode pelanggan'],
        'email' => ['email', 'e-mail', 'surel'],
        'birth_date' => ['tanggal lahir', 'tgl lahir', 'ulang tahun', 'birthday', 'birth date', 'lahir'],
        'city' => ['kota', 'city', 'domisili'],
        'joined_on' => ['tanggal daftar', 'tgl daftar', 'join date', 'bergabung', 'tanggal gabung'],
    ];

    public function __construct(private readonly CustomerDirectory $directory, private readonly SpreadsheetReader $reader) {}

    public function import(string $path, string $fileName, int $outletId, ?int $userId, ?int $tagId = null): Import
    {
        $rows = $this->reader->read($path, $fileName);
        $headerAt = $this->findHeader($rows);
        if ($headerAt === null) {
            throw new DomainException('crm_header', 'Kolom nomor HP tidak ditemukan. Baris judul minimal berisi "Nama" dan "No HP".', 422);
        }
        $map = $this->mapColumns($rows[$headerAt]);
        $data = array_slice($rows, $headerAt + 1);
        if (count($data) > self::MAX_ROWS) {
            throw new DomainException('crm_too_many', 'Maksimal '.number_format(self::MAX_ROWS, 0, ',', '.').' baris per file.', 422);
        }

        $created = $merged = 0;
        $errors = [];
        $seen = [];
        $tag = $tagId ? Tag::find($tagId) : null;
        foreach ($data as $i => $row) {
            $line = $headerAt + $i + 2;
            $get = fn (string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
            if (implode('', $row) === '') {
                continue;
            }
            $phone = Phone::normalize($get('phone'));
            if ($phone === null) {
                $errors[] = ['line' => $line, 'value' => $get('phone'), 'message' => 'Nomor HP tidak valid'];

                continue;
            }
            if (isset($seen[$phone])) {
                $errors[] = ['line' => $line, 'value' => $get('phone'), 'message' => 'Nomor sama dengan baris '.$seen[$phone]];

                continue;
            }
            $seen[$phone] = $line;
            [$customer, $isNew] = $this->directory->upsert($phone, [
                'name' => $get('name'),
                'email' => filter_var($get('email'), FILTER_VALIDATE_EMAIL) ?: null,
                'birth_date' => $this->date($get('birth_date')),
                'city' => $get('city'),
                'member_code' => $get('member_code'),
                'joined_on' => $this->date($get('joined_on')),
            ], $outletId, 'import');
            $isNew ? $created++ : $merged++;
            $tag?->id && $customer->tags()->syncWithoutDetaching([$tag->id]);
        }

        return Import::create([
            'outlet_id' => $outletId,
            'user_id' => $userId,
            'file_name' => mb_substr($fileName, 0, 255),
            'sha256' => hash_file('sha256', $path),
            'row_count' => $created + $merged + count($errors),
            'created_count' => $created,
            'merged_count' => $merged,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 500),
        ]);
    }

    private function findHeader(array $rows): ?int
    {
        foreach (array_slice($rows, 0, 10, true) as $i => $row) {
            if (isset($this->mapColumns($row)['phone'])) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $col => $label) {
            $label = strtolower(trim(preg_replace('/\s+/', ' ', (string) $label), " :*\t"));
            foreach (self::HEADERS as $key => $aliases) {
                if (! isset($map[$key]) && in_array($label, $aliases, true)) {
                    $map[$key] = $col;
                }
            }
        }

        return $map;
    }

    /** Tanggal dari Excel (nomor seri) atau teks 31/12/1990, 1990-12-31, 31-12-1990. */
    private function date(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        try {
            if (is_numeric($v) && (float) $v > 1000 && (float) $v < 80000) {
                return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
            }
            if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $v, $m)) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
            }
            if (preg_match('#^\d{4}-\d{2}-\d{2}#', $v)) {
                return Carbon::parse(substr($v, 0, 10))->toDateString();
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
