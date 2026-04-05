<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

final class SequenceSetting
{
    /**
     * Prochain numéro séquentiel (avant incrément). À appeler dans une transaction avec lock.
     */
    public static function allocateNext(string $key, int $default = 1): int
    {
        $row = DB::table('settings')->where('key', $key)->lockForUpdate()->first();
        $seq = $row ? max(1, (int) $row->value) : $default;
        Setting::setValue($key, (string) ($seq + 1), 'string');

        return $seq;
    }
}
