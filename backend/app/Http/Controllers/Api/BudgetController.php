<?php

namespace App\Http\Controllers\Api;

use App\Domain\Finance\Money\MoneyMath;
use App\Http\Requests\BudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Day;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class BudgetController extends CrudController
{
    protected string $model = Budget::class;
    protected string $resource = BudgetResource::class;

    /** One budget per category per month (or default): sending it again updates it. */
    public function store(BudgetRequest $request): JsonResponse
    {
        $v = $request->validated();
        $query = Budget::query()->where('category_id', $v['category_id']);
        isset($v['month']) ? $query->whereDate('month', $v['month']) : $query->whereNull('month');
        $existing = $query->first();

        if ($existing === null) {
            return $this->created($v);
        }

        $existing->fill($v)->save();

        return (new BudgetResource($existing->refresh()))->response()->setStatusCode(200);
    }

    /** Category and month are the budget's identity; only the amount and the warning level can change. */
    public function update(BudgetRequest $request, string $id): JsonResource
    {
        return $this->updated($id, Arr::only($request->validated(), ['amount', 'warn_percent']));
    }

    /** Budget vs real spending per category for one calendar month (default: this month). */
    public function status(Request $request): JsonResponse
    {
        $v = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = $v['month'] ?? Day::today()->format('Y-m');
        $first = Day::parse($month . '-01');
        $range = [$first->format('Y-m-d'), $first->format('Y-m-t')];

        $chosen = [];
        foreach (Budget::query()->get() as $budget) {
            $key = (string) $budget->category_id;
            if ($budget->month === null) {
                $chosen[$key] ??= $budget; // the default, unless a month-specific one replaces it below
            } elseif ($budget->month->format('Y-m') === $month) {
                $chosen[$key] = $budget;
            }
        }
        $names = Category::query()->whereIn('id', array_keys($chosen))->pluck('name', 'id');

        $items = [];
        foreach ($chosen as $categoryId => $budget) {
            $spent = Sql::sum(
                Transaction::query()->where('category_id', $categoryId)->whereIn('type', ['expense', 'card_purchase'])
                    ->whereNull('installment_plan_id')->whereBetween('txn_date', $range)
            )->minus(Sql::sum(
                Transaction::query()->where('category_id', $categoryId)->where('type', 'card_refund')->whereBetween('txn_date', $range)
            ));

            $amount = MoneyMath::exact2((string) $budget->amount);
            $percent = $amount->isPositive()
                ? MoneyMath::round2(MoneyMath::div($spent->multipliedBy(100), $amount))
                : null;

            $level = match (true) {
                $spent->isGreaterThan($amount) => 'over',
                $percent !== null && $percent->isGreaterThanOrEqualTo((int) $budget->warn_percent) => 'warn',
                default => 'ok',
            };

            $items[] = [
                'category_id' => $categoryId,
                'category_name' => $names[$categoryId] ?? null,
                'budget' => (string) $amount,
                'spent' => (string) $spent,
                'remaining' => (string) $amount->minus($spent),
                'percent' => $percent === null ? null : (string) $percent,
                'warn_percent' => (int) $budget->warn_percent,
                'level' => $level,
            ];
        }

        return response()->json(['data' => ['month' => $month, 'items' => $items]]);
    }
}