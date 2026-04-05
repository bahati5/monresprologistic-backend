<?php

namespace App\Support;

use App\Models\Locker;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LockerNumberGenerator
{
    public static function generate(): string
    {
        $format = trim((string) (Setting::getValue('locker_code_format', '') ?? ''));
        if ($format === '') {
            $format = '{prefix}-{randnum}';
        }

        $prefix = trim((string) (Setting::getValue('locker_prefix', 'MRP') ?? 'MRP')) ?: 'MRP';
        $digits = max(2, min(10, (int) (Setting::getValue('locker_digits', '4') ?: 4)));
        $mode = Setting::getValue('locker_mode', 'random') === 'sequential' ? 'sequential' : 'random';
        $seqPad = max(1, min(12, (int) (Setting::getValue('locker_seq_pad', '') ?: $digits)));

        $usesSeqToken = str_contains($format, '{seq}');
        $usesRandnum = str_contains($format, '{randnum}');
        $usesRandom = str_contains($format, '{random}');

        return DB::transaction(function () use ($format, $prefix, $digits, $mode, $seqPad, $usesSeqToken, $usesRandnum, $usesRandom) {
            $now = now();

            do {
                if ($usesSeqToken) {
                    $seqVal = SequenceSetting::allocateNext('locker_next_seq', 1);
                    $seq = str_pad((string) $seqVal, $seqPad, '0', STR_PAD_LEFT);
                } else {
                    $seq = '';
                }

                if ($usesRandnum) {
                    if ($mode === 'sequential' && ! $usesSeqToken) {
                        $last = Locker::query()->orderByDesc('id')->lockForUpdate()->value('code');
                        $lastNum = $last ? (int) preg_replace('/\D/', '', (string) $last) : 0;
                        $randnum = str_pad((string) ($lastNum + 1), $digits, '0', STR_PAD_LEFT);
                    } else {
                        $randnum = str_pad((string) random_int(0, (int) min(9999999999, pow(10, $digits) - 1)), $digits, '0', STR_PAD_LEFT);
                    }
                } else {
                    $randnum = '';
                }

                $random = $usesRandom ? strtoupper(Str::random(max(4, $digits))) : '';

                $code = ReferenceNumberFormatter::apply($format, array_merge(
                    ReferenceNumberFormatter::localeAndCalendarReplacements($now),
                    [
                        'prefix' => $prefix,
                        'year' => $now->format('Y'),
                        'month' => $now->format('m'),
                        'day' => $now->format('d'),
                        'seq' => $seq,
                        'randnum' => $randnum,
                        'random' => $random,
                    ],
                ));
            } while (Locker::query()->where('code', $code)->exists());

            return $code;
        });
    }
}
