<?php

declare(strict_types=1);

namespace App\Application\WhatIf;

use App\Application\Forecast\ForecastContext;
use App\Application\Installments\InstallmentInput;
use App\Application\Installments\InstallmentRules;
use App\Application\Installments\InstallmentService;
use App\Application\Installments\ScheduleItemMapper;
use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\InstallmentEventGenerator;
use App\Domain\Finance\Forecast\SavingsReserveGenerator;
use App\Domain\Finance\Installments\PayoffCalculator;
use App\Domain\Finance\Money\MoneyMath;
use App\Domain\Finance\WhatIf\AddEvents;
use App\Domain\Finance\WhatIf\RemoveSource;
use App\Domain\Finance\WhatIf\SetAmount;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Support\Day;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** JSON "changes" => domain changes. Every change is validated on its own; errors name "changes.<n>.<field>". */
final class ScenarioChangeFactory
{
    private const SOURCE_TYPES = [
        'income_source', 'recurring_expense', 'installment_plan', 'savings_goal', 'planned_item', 'credit_card',
    ];

    public function __construct(private readonly InstallmentService $installments)
    {
    }

    /** @param list<array<string, mixed>> $rawChanges */
    public function build(array $rawChanges, ForecastContext $ctx, string $userId): ScenarioPlan
    {
        $changes = [];
        $extraCharges = [];
        $generator = new InstallmentEventGenerator();

        foreach (array_values($rawChanges) as $i => $raw) {
            $type = (string) ($raw['type'] ?? '');
            $v = $this->validate($type, $raw, $i, $userId);

            try {
                switch ($type) {
                    case 'add_installment':
                        $input = InstallmentInput::fromArray($v);
                        $schedule = $this->installments->schedule($input);
                        if ($input->fundingType === 'account') {
                            $changes[] = new AddEvents(
                                $generator->accountEvents("whatif-{$i}", $input->name, $schedule->items, $ctx->from, $ctx->to)
                            );
                        } else {
                            if (! $ctx->hasCard((string) $input->cardId)) {
                                $this->fail($i, 'credit_card_id', 'บัตรนี้ไม่ได้อยู่ในสถานะใช้งาน');
                            }
                            $extraCharges[$input->cardId] = array_merge(
                                $extraCharges[$input->cardId] ?? [],
                                $generator->cardCharges("whatif-{$i}", $input->name, $schedule->items),
                            );
                        }
                        break;

                    case 'add_one_off':
                        $income = $v['kind'] === 'income';
                        $changes[] = new AddEvents([new CashEvent(
                            Day::parse($v['date']), (string) $v['amount'],
                            $income ? Direction::In : Direction::Out,
                            $income ? EventKind::IncomePlanned : EventKind::ExpensePlanned,
                            $v['name'], 'whatif', null,
                        )]);
                        break;

                    case 'add_monthly_saving':
                        $monthly = MoneyMath::exact2((string) $v['monthly_amount']);
                        $changes[] = new AddEvents((new SavingsReserveGenerator())->generate(
                            "whatif-{$i}", $v['name'], $monthly, $monthly->multipliedBy($ctx->months),
                            $ctx->calendar, $ctx->from, $ctx->months, '0',
                            isset($v['start_date']) ? Day::parse($v['start_date']) : null,
                        ));
                        break;

                    case 'remove_source':
                        $this->assertNotCardCharge($ctx, $v['source_type'], $v['source_id'], $i);
                        $changes[] = new RemoveSource(
                            $v['source_type'], $v['source_id'],
                            isset($v['from_date']) ? Day::parse($v['from_date']) : null,
                        );
                        break;

                    case 'set_amount':
                        $this->assertNotCardCharge($ctx, $v['source_type'], $v['source_id'], $i);
                        $changes[] = new SetAmount(
                            $v['source_type'], $v['source_id'], (string) $v['amount'],
                            isset($v['from_date']) ? Day::parse($v['from_date']) : null,
                        );
                        break;

                    case 'payoff_plan':
                        array_push($changes, ...$this->payoff($v, $ctx, $i));
                        break;
                }
            } catch (InvalidArgumentException $e) {
                $this->fail($i, 'type', $e->getMessage());
            }
        }

        return new ScenarioPlan($changes, $extraCharges);
    }

    /** @return list<\App\Domain\Finance\WhatIf\ScenarioChange> remove the plan's later rows, then pay it off in one event */
    private function payoff(array $v, ForecastContext $ctx, int $i): array
    {
        $plan = InstallmentPlan::query()->whereKey($v['plan_id'])->where('status', 'active')->first();
        if ($plan === null || $plan->funding_type !== 'account') {
            $this->fail($i, 'plan_id', 'รองรับเฉพาะแผนผ่อนที่ยังใช้งานและผ่อนจากบัญชี');
        }

        $date = Day::parse($v['date']);
        if ($date->format('Y-m-d') < $ctx->from->format('Y-m-d')) {
            $this->fail($i, 'date', 'วันที่ปิดบัญชีต้องไม่ก่อนวันเริ่มต้น Forecast');
        }

        // Rows due before the payoff date are still paid normally; the rest are paid off.
        $items = InstallmentScheduleItem::query()
            ->where('plan_id', $plan->id)->where('status', 'pending')
            ->whereDate('due_date', '>=', $date->format('Y-m-d'))
            ->orderBy('installment_no')->get()
            ->map(fn ($row) => ScheduleItemMapper::fromRow($row))->all();

        if ($items === []) {
            $this->fail($i, 'plan_id', 'ไม่มีงวดที่ค้างหลังวันที่ปิดบัญชี');
        }

        $quote = (new PayoffCalculator())->quote($items, (string) ($v['interest_discount'] ?? '0'), (string) ($v['early_fee'] ?? '0'));

        return [
            new RemoveSource('installment_plan', (string) $plan->id, $date),
            new AddEvents([(new InstallmentEventGenerator())->payoffEvent((string) $plan->id, $plan->name, $date, $quote)]),
        ];
    }

    private function assertNotCardCharge(ForecastContext $ctx, string $type, string $id, int $i): void
    {
        foreach ($ctx->cards as $card) {
            foreach ($card->charges as $charge) {
                if ($charge->sourceType === $type && $charge->sourceId === $id) {
                    $this->fail($i, 'source_id', 'รายการที่จ่ายผ่านบัตรเครดิตยังไม่รองรับใน What-if ขั้นนี้');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function validate(string $type, mixed $raw, int $i, string $userId): array
    {
        $money = InstallmentRules::MONEY;
        $date = ['date_format:Y-m-d'];

        $rules = match ($type) {
            'add_installment' => InstallmentRules::for($userId),
            'add_one_off' => [
                'kind' => ['required', Rule::in(['income', 'expense'])],
                'name' => ['required', 'string', 'max:255'],
                'amount' => ['required', $money, 'gt:0'],
                'date' => ['required', ...$date],
            ],
            'add_monthly_saving' => [
                'name' => ['required', 'string', 'max:255'],
                'monthly_amount' => ['required', $money, 'gt:0'],
                'start_date' => ['nullable', ...$date],
            ],
            'remove_source' => [
                'source_type' => ['required', Rule::in(self::SOURCE_TYPES)],
                'source_id' => ['required', 'uuid'],
                'from_date' => ['nullable', ...$date],
            ],
            'set_amount' => [
                'source_type' => ['required', Rule::in(self::SOURCE_TYPES)],
                'source_id' => ['required', 'uuid'],
                'amount' => ['required', $money, 'gt:0'],
                'from_date' => ['nullable', ...$date],
            ],
            'payoff_plan' => [
                'plan_id' => ['required', 'uuid'],
                'date' => ['required', ...$date],
                'interest_discount' => ['nullable', $money],
                'early_fee' => ['nullable', $money],
            ],
            default => null,
        };

        if ($rules === null) {
            $this->fail($i, 'type', 'ชนิดการเปลี่ยนแปลงไม่ถูกต้อง');
        }

        $validator = Validator::make(is_array($raw) ? $raw : [], $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $field => $list) {
                $messages["changes.{$i}.{$field}"] = $list;
            }
            throw ValidationException::withMessages($messages);
        }

        return $validator->validated();
    }

    private function fail(int $i, string $field, string $message): never
    {
        throw ValidationException::withMessages(["changes.{$i}.{$field}" => [$message]]);
    }
}