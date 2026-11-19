<?php

declare(strict_types=1);

namespace App\Application\Installments;

use App\Domain\Finance\Installments\FeeMode;
use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Installments\InstallmentMethod;
use App\Domain\Finance\Installments\InstallmentTerms;
use App\Domain\Finance\Money\MoneyMath;
use App\Support\Day;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Validated request data => domain terms. Domain rule violations become 422 errors on "terms". */
final class InstallmentInput
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $note,
        public readonly InstallmentTerms $terms,
        public readonly BigDecimal $price,
        public readonly BigDecimal $downPayment,
        public readonly string $fundingType,
        public readonly ?string $cardId,
        public readonly ?string $accountId,
    ) {
    }

    /** @param array<string, mixed> $v already validated by InstallmentRules */
    public static function fromArray(array $v): self
    {
        try {
            $price = MoneyMath::exact2((string) $v['price']);
            $down = MoneyMath::exact2((string) ($v['down_payment'] ?? '0'));
            if ($down->isGreaterThanOrEqualTo($price)) {
                throw new InvalidArgumentException('Down payment must be less than the price.');
            }

            $terms = new InstallmentTerms(
                InstallmentMethod::from($v['interest_method']),
                $price->minus($down),
                (string) ($v['annual_rate_percent'] ?? '0'),
                (int) $v['term_count'],
                Frequency::from($v['frequency']),
                Day::parse($v['start_date']),
                Day::parse($v['first_due_date']),
                (string) ($v['fee_amount'] ?? '0'),
                FeeMode::from($v['fee_mode'] ?? 'none'),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['terms' => [$e->getMessage()]]);
        }

        $isCard = $v['funding_type'] === 'credit_card';

        return new self(
            (string) $v['name'],
            $v['note'] ?? null,
            $terms,
            $price,
            $down,
            (string) $v['funding_type'],
            $isCard ? (string) $v['credit_card_id'] : null,
            $isCard ? null : (string) $v['account_id'],
        );
    }
}