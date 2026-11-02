<?php
declare(strict_types=1);

namespace Sankris;

use DateTimeImmutable;

/**
 * Recurring weekly classes come from GYM One's timetable, whose day_of_week holds the
 * translated day name ("Lunes"). This class maps names to ISO weekday numbers.
 */
final class Schedule
{
    private Db $db;
    private array $dayNumbers = [];

    private const FALLBACK = [
        1 => ['Lunes', 'Monday', 'Hétfő'], 2 => ['Martes', 'Tuesday', 'Kedd'], 3 => ['Miércoles', 'Wednesday', 'Szerda'],
        4 => ['Jueves', 'Thursday', 'Csütörtök'], 5 => ['Viernes', 'Friday', 'Péntek'], 6 => ['Sábado', 'Saturday', 'Szombat'],
        7 => ['Domingo', 'Sunday', 'Vasárnap'],
    ];

    public function __construct(Db $db, array $translations = [])
    {
        $this->db = $db;
        foreach (['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7] as $key => $n) {
            if (!empty($translations[$key])) {
                $this->dayNumbers[mb_strtolower($translations[$key])] = $n;
            }
        }
        foreach (self::FALLBACK as $n => $names) {
            foreach ($names as $name) {
                $this->dayNumbers[mb_strtolower($name)] ??= $n;
            }
        }
    }

    public function dayNumber(string $name): ?int
    {
        return $this->dayNumbers[mb_strtolower(trim($name))] ?? null;
    }

    public function dayName(int $n): string
    {
        return self::FALLBACK[$n][0];
    }

    public function slot(int $timetableId): ?array
    {
        return $this->db->one(
            'SELECT t.*, p.name AS program_name, p.booking_open_days, p.cancel_deadline_hours, p.makeups_per_month,
                    tr.name AS trainer_name
             FROM timetable t LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN trainers tr ON tr.id = t.trainer_id
             WHERE t.id = ?',
            [$timetableId]
        );
    }

    /** All timetable slots that run on $date (Y-m-d), with exception info. */
    public function sessionsOn(string $date): array
    {
        $weekday = (int) (new DateTimeImmutable($date))->format('N');
        $rows = $this->db->all(
            'SELECT t.*, p.name AS program_name, tr.name AS trainer_name
             FROM timetable t LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN trainers tr ON tr.id = t.trainer_id
             ORDER BY t.start_time, t.event_name'
        );
        $exceptions = $this->exceptionsOn($date);
        $out = [];
        foreach ($rows as $row) {
            if ($this->dayNumber((string) $row['day_of_week']) !== $weekday) {
                continue;
            }
            $row['exception'] = $exceptions[(int) $row['id']] ?? $exceptions[0] ?? null;
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<int,array> keyed by timetable_id (0 = whole day) */
    public function exceptionsOn(string $date): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM sk_session_exceptions WHERE session_date = ?', [$date]) as $ex) {
            $out[(int) ($ex['timetable_id'] ?? 0)] = $ex;
        }
        return $out;
    }

    public function runsOn(array $slot, string $date): bool
    {
        return $this->dayNumber((string) $slot['day_of_week']) === (int) (new DateTimeImmutable($date))->format('N');
    }

    public function isCancelled(int $timetableId, string $date): ?array
    {
        $ex = $this->exceptionsOn($date);
        return $ex[$timetableId] ?? $ex[0] ?? null;
    }

    public static function sessionStart(array $slot, string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' ' . $slot['start_time']);
    }
}
