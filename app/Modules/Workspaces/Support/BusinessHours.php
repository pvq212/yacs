<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Support;

use App\Support\Database\Records as R;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BusinessHours
{
    public static function isOpen(object $inbox): bool
    {
        if ($inbox->business_hours_id === null) {
            return true;
        }
        $hours = DB::table('business_hours')->where('id', $inbox->business_hours_id)->first();
        if ($hours === null) {
            return false;
        }
        $now = CarbonImmutable::now($hours->timezone);
        foreach (R::json($hours->holidays) as $holiday) {
            if ($holiday['date'] === $now->toDateString() && $holiday['closed']) {
                return false;
            }
        }
        $ranges = R::json($hours->weekly_schedule)[strtolower($now->format('D'))] ?? [];
        foreach ($ranges as [$start, $end]) {
            if ($now->format('H:i') >= $start && $now->format('H:i') < $end) {
                return true;
            }
        }

        return false;
    }
}
