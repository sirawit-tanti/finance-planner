<?php

namespace App\Http\Resources;

use App\Domain\Finance\Savings\SavingsPlanner;
use App\Models\SavingsContribution;
use App\Support\Day;
use App\Support\Sql;
use Illuminate\Http\Request;

class SavingsGoalResource extends ModelResource
{
    public function toArray(Request $request): array
    {
        $goal = $this->resource;
        $saved = Sql::sum(SavingsContribution::query()->where('goal_id', $goal->id)->where('status', 'actual'));
        $planner = new SavingsPlanner();
        $months = $planner->monthsToGoal((string) $goal->target_amount, $saved, (string) $goal->monthly_amount);

        return parent::toArray($request) + [
            'saved_amount' => (string) $saved,
            'progress_percent' => (string) $planner->progressPercent((string) $goal->target_amount, $saved),
            'months_to_goal' => $months,
            'estimated_date' => $months === null ? null : $planner->estimatedDate(Day::today(), $months)->format('Y-m-d'),
        ];
    }
}