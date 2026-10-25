<?php

namespace App\Game;

/** House rules, with the same limits and defaults as the app's lobby. */
final class Rules
{
    public const DEFAULTS = ['roundLength' => 30, 'ping' => 2, 'headStart' => 3, 'zone' => true, 'zoneSize' => 420, 'shrinkEvery' => 5];

    public const FINAL_ZONE = 150;

    public static function validation(): array
    {
        return [
            'rules' => ['sometimes', 'array'],
            'rules.roundLength' => ['sometimes', 'integer', 'between:10,180'],
            'rules.ping' => ['sometimes', 'integer', 'between:0,30'],
            'rules.headStart' => ['sometimes', 'integer', 'between:0,15'],
            'rules.zone' => ['sometimes', 'boolean'],
            'rules.zoneSize' => ['sometimes', 'integer', 'between:200,3000'],
            'rules.shrinkEvery' => ['sometimes', 'integer', 'between:0,15'],
        ];
    }

    public static function merge(array $current, array $changes): array
    {
        return array_merge(self::DEFAULTS, $current, array_intersect_key($changes, self::DEFAULTS));
    }

    /** Seconds into the round of every scheduled ping. */
    public static function pingSeconds(array $rules): array
    {
        if ((int) $rules['ping'] <= 0) {
            return [];
        }
        $total = ((int) $rules['headStart'] + (int) $rules['roundLength']) * 60;

        return range((int) $rules['headStart'] * 60, $total, (int) $rules['ping'] * 60);
    }

    /** Seconds of each zone shrink; the zone keeps its final size for the last five minutes. */
    public static function shrinkSeconds(array $rules): array
    {
        if (! $rules['zone'] || (int) $rules['shrinkEvery'] <= 0) {
            return [];
        }
        $start = (int) $rules['headStart'] * 60 + (int) $rules['shrinkEvery'] * 60;
        $last = ((int) $rules['headStart'] + (int) $rules['roundLength']) * 60 - 5 * 60;

        return $start > $last ? [] : range($start, $last, (int) $rules['shrinkEvery'] * 60);
    }

    public static function zoneAt(array $rules, float $second): int
    {
        $steps = self::shrinkSeconds($rules);
        if (! $steps) {
            return (int) $rules['zoneSize'];
        }
        $done = count(array_filter($steps, fn ($at) => $at <= $second));

        return (int) round($rules['zoneSize'] - (($rules['zoneSize'] - self::FINAL_ZONE) * $done) / count($steps));
    }
}
