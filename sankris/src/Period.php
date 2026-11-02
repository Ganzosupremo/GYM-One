<?php
declare(strict_types=1);

namespace Sankris;

use DateTimeImmutable;

/** Date math for billing and credit periods. */
final class Period
{
    public const BILLING_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    public static function addBilling(string $date, string $billingPeriod): ?string
    {
        if (!isset(self::BILLING_MONTHS[$billingPeriod])) {
            return null; // 'once'
        }
        return self::addMonths($date, self::BILLING_MONTHS[$billingPeriod]);
    }

    /** Adds months keeping the anchor day, clamped to month end (Jan 31 + 1 month = Feb 28/29). */
    public static function addMonths(string $date, int $months): string
    {
        $d = new DateTimeImmutable($date);
        $day = (int) $d->format('d');
        $first = $d->modify('first day of this month')->modify("+{$months} months");
        $lastDay = (int) $first->format('t');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min($day, $lastDay))->format('Y-m-d');
    }

    /**
     * Credit window containing $date.
     * week = Monday-Sunday; month = calendar month; billing = [anchor, next billing).
     * @return array{0:string,1:string} inclusive start and end
     */
    public static function creditWindow(string $creditPeriod, string $date, string $anchor, string $billingPeriod, ?int $validityDays = null): array
    {
        $d = new DateTimeImmutable($date);
        switch ($creditPeriod) {
            case 'week':
                $start = $d->modify('monday this week');
                return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
            case 'month':
                return [$d->format('Y-m-01'), $d->format('Y-m-t')];
            case 'validity':
                $start = new DateTimeImmutable($anchor);
                $end = $start->modify('+' . max(1, (int) $validityDays) . ' days')->modify('-1 day');
                return [$start->format('Y-m-d'), $end->format('Y-m-d')];
            case 'billing':
            default:
                $months = self::BILLING_MONTHS[$billingPeriod] ?? 1;
                $start = $anchor;
                while (true) {
                    $next = self::addMonths($start, $months);
                    if ($next > $date) {
                        break;
                    }
                    $start = $next;
                }
                $end = (new DateTimeImmutable(self::addMonths($start, $months)))->modify('-1 day')->format('Y-m-d');
                return [$start, $end];
        }
    }

    public static function age(?string $birthdate, ?string $on = null): ?int
    {
        if (!$birthdate) {
            return null;
        }
        return (new DateTimeImmutable($birthdate))->diff(new DateTimeImmutable($on ?? 'today'))->y;
    }
}
