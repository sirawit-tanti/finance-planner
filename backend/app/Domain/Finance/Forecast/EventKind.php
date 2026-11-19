<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

enum EventKind: string
{
    case IncomeRecurring = 'income_recurring';
    case IncomePlanned = 'income_planned';
    case ExpenseRecurring = 'expense_ecurring';
    case ExpensePlanned = 'expense_planned';
    case ExpenseGeneral = 'expense_general';
    case CardPayment = 'card_payment';
    case Installment = 'installment';
    case SavingsReserve = 'saveing_reserve';
}