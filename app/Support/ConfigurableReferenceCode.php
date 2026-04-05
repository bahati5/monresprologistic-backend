<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ConfigurableReferenceCode
{
    /**
     * @param  Builder<Model>  $query  requête pour tester l’unicité sur la colonne reference_code
     */
    public static function allocate(
        string $formatKey,
        string $formatDefault,
        string $prefixKey,
        string $prefixDefault,
        string $padKey,
        int $padDefault,
        string $nextSeqKey,
        Builder $query,
    ): string {
        $format = trim((string) (Setting::getValue($formatKey, '') ?? ''));
        if ($format === '') {
            $format = $formatDefault;
        }
        $prefix = trim((string) (Setting::getValue($prefixKey, '') ?? ''));
        if ($prefix === '') {
            $prefix = $prefixDefault;
        }
        $pad = max(1, min(12, (int) (Setting::getValue($padKey, (string) $padDefault) ?: $padDefault)));

        return DB::transaction(function () use ($format, $prefix, $pad, $nextSeqKey, $query) {
            $now = now();
            do {
                $seqVal = SequenceSetting::allocateNext($nextSeqKey, 1);
                $seq = str_pad((string) $seqVal, $pad, '0', STR_PAD_LEFT);
                $code = ReferenceNumberFormatter::apply($format, array_merge(
                    ReferenceNumberFormatter::localeAndCalendarReplacements($now),
                    [
                        'prefix' => $prefix,
                        'year' => $now->format('Y'),
                        'month' => $now->format('m'),
                        'day' => $now->format('d'),
                        'seq' => $seq,
                    ],
                ));
            } while ($query->clone()->where('reference_code', $code)->exists());

            return $code;
        });
    }
}
