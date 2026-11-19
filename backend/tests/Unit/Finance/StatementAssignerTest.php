<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\CreditCards\StatementAssigner;
use App\Domain\Finance\Installments\Frequency;
use App\Domain\Finance\Installments\InstallmentMethod;
use App\Domain\Finance\Installments\InstallmentTerms;
use App\Domain\Finance\Installments\ScheduleBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StatementAssignerTest extends TestCase
{
    /** @return array{0: string, 1: string, 2: string} [cycleStart, statementDate, dueDate] */
    private function cycle(int $statementDay, int $dueDay, string $date): array
    {
        $c = (new StatementAssigner())->cycleFor($statementDay, $dueDay, new DateTimeImmutable($date));

        return [
            $c->cycleStart->format('Y-m-d'),
            $c->statementDate->format('Y-m-d'),
            $c->dueDate->format('Y-m-d'),
        ];
    }

    // ---- Example from the requirement: statement day 5, due day 25 ----

    public function test_purchase_on_3_november_goes_to_the_5_november_statement(): void
    {
        $this->assertSame(['2026-10-06', '2026-11-05', '2026-11-25'], $this->cycle(5, 25, '2026-11-03'));
    }

    public function test_purchase_on_the_statement_day_itself_stays_in_that_statement(): void
    {
        $this->assertSame(['2026-10-06', '2026-11-05', '2026-11-25'], $this->cycle(5, 25, '2026-11-05'));
    }

    public function test_purchase_on_6_november_goes_to_the_next_statement(): void
    {
        $this->assertSame(['2026-11-06', '2026-12-05', '2026-12-25'], $this->cycle(5, 25, '2026-11-06'));
    }

    public function test_cycles_cross_the_year_boundary(): void
    {
        $this->assertSame(['2026-12-06', '2027-01-05', '2027-01-25'], $this->cycle(5, 25, '2026-12-06'));
        $this->assertSame(['2026-12-06', '2027-01-05', '2027-01-25'], $this->cycle(5, 25, '2027-01-01'));
    }

    // ---- Due day on or before the statement day => due next month ----

    public function test_due_day_before_statement_day_is_due_in_the_following_month(): void
    {
        $this->assertSame(['2026-10-26', '2026-11-25', '2026-12-10'], $this->cycle(25, 10, '2026-11-25'));
        $this->assertSame(['2026-11-26', '2026-12-25', '2027-01-10'], $this->cycle(25, 10, '2026-11-26'));
        $this->assertSame(['2026-12-26', '2027-01-25', '2027-02-10'], $this->cycle(25, 10, '2026-12-26'));
    }

    public function test_due_day_equal_to_statement_day_is_due_in_the_following_month(): void
    {
        $this->assertSame(['2026-10-11', '2026-11-10', '2026-12-10'], $this->cycle(10, 10, '2026-11-10'));
    }

    // ---- Short months ----

    public function test_statement_day_31_is_clamped_in_february(): void
    {
        // 2026-02-28 is the (clamped) statement date; the cycle started on 1 Feb (after 31 Jan).
        $this->assertSame(['2026-02-01', '2026-02-28', '2026-03-15'], $this->cycle(31, 15, '2026-02-28'));
        // 1 March starts the next cycle, which ends 31 March.
        $this->assertSame(['2026-03-01', '2026-03-31', '2026-04-15'], $this->cycle(31, 15, '2026-03-01'));
        $this->assertSame(['2026-01-01', '2026-01-31', '2026-02-15'], $this->cycle(31, 15, '2026-01-31'));
    }

    public function test_statement_day_31_in_a_leap_year(): void
    {
        $this->assertSame('2028-02-29', $this->cycle(31, 15, '2028-02-29')[1]);
    }

    public function test_due_day_after_statement_day_is_clamped_to_the_same_month(): void
    {
        // statement 30 (=> 28 Feb), due 31 > 30 => same month, clamped to 28 Feb (equal to the statement date).
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-02-28'], $this->cycle(30, 31, '2026-02-10'));
    }

    // ---- Properties that must hold for every date ----

    public function test_every_date_falls_inside_its_cycle_and_cycles_are_contiguous(): void
    {
        $assigner = new StatementAssigner();
        $combos = [[5, 25], [25, 10], [31, 15], [30, 31], [1, 28], [28, 28]];
        $utc = new \DateTimeZone('UTC');

        foreach ($combos as [$statementDay, $dueDay]) {
            $date = new DateTimeImmutable('2026-01-01 00:00:00', $utc);
            $end = new DateTimeImmutable('2028-03-31 00:00:00', $utc); // includes the 2028 leap February

            while ($date <= $end) {
                $c = $assigner->cycleFor($statementDay, $dueDay, $date);
                $day = $date->format('Y-m-d');
                $label = "{$statementDay}/{$dueDay} on {$day}";

                // Compare calendar days as strings (Y-m-d sorts correctly), never time-of-day.
                $start = $c->cycleStart->format('Y-m-d');
                $statement = $c->statementDate->format('Y-m-d');
                $due = $c->dueDate->format('Y-m-d');

                $this->assertTrue($day >= $start, "date before cycle start: {$label}");
                $this->assertTrue($day <= $statement, "date after statement date: {$label}");
                $this->assertTrue($statement <= $due, "due before statement: {$label}");

                // The day before this cycle starts must be the previous cycle's statement date.
                $dayBefore = $c->cycleStart->modify('-1 day');
                $previous = $assigner->cycleFor($statementDay, $dueDay, $dayBefore);
                $this->assertSame(
                    $dayBefore->format('Y-m-d'),
                    $previous->statementDate->format('Y-m-d'),
                    "gap or overlap between cycles: {$label}"
                );

                $date = $date->modify('+1 day');
            }
        }
    }

    // ---- Installment rows charged to a card use the same rule ----

    public function test_installment_rows_are_assigned_by_their_due_date(): void
    {
        $schedule = (new ScheduleBuilder())->build(new InstallmentTerms(
            InstallmentMethod::Zero, '3000', '0', 3, Frequency::Monthly,
            new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-20'),
        ));
        $assigner = new StatementAssigner();

        $statementDates = array_map(
            fn ($item) => $assigner->cycleFor(5, 25, $item->dueDate)->statementDate->format('Y-m-d'),
            $schedule->items
        );

        $this->assertSame(['2026-12-05', '2027-01-05', '2027-02-05'], $statementDates);
    }

    // ---- Validation ----

    public function test_invalid_days_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StatementAssigner())->cycleFor(0, 25, new DateTimeImmutable('2026-11-03'));
    }

    public function test_invalid_due_day_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StatementAssigner())->cycleFor(5, 32, new DateTimeImmutable('2026-11-03'));
    }
}