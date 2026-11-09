<?php
declare(strict_types=1);

namespace Sankris;

use DateTimeImmutable;

/**
 * Phase 3: bookings (reservas), recurring bookings, makeups and attendance.
 * Booking, attendance and gym access are separate records: attendance lives on the booking,
 * gym check-in stays in GYM One.
 */
final class Bookings
{
    private Db $db;
    private Audit $audit;
    private Schedule $schedule;
    private Credits $credits;

    public const ACTIVE_STATUSES = ['booked', 'attended', 'no_show'];

    public function __construct(Db $db, Audit $audit, Schedule $schedule, Credits $credits)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->schedule = $schedule;
        $this->credits = $credits;
    }

    /**
     * Books a student into a session.
     * @param array $opts source (manual|recurring|makeup|evaluation), enforce_window (bool), now (Y-m-d H:i:s), recurring_id
     * @return int booking id
     */
    public function book(int $studentId, int $timetableId, string $date, array $opts = []): int
    {
        Subscriptions::validateDate($date);
        $source = $opts['source'] ?? 'manual';
        $now = new DateTimeImmutable($opts['now'] ?? 'now');
        $enforceWindow = (bool) ($opts['enforce_window'] ?? false);

        return $this->db->transaction(function () use ($studentId, $timetableId, $date, $source, $now, $enforceWindow, $opts) {
            // Lock the slot row so two bookings cannot both take the last place
            $slot = $this->db->one('SELECT * FROM timetable WHERE id = ? FOR UPDATE', [$timetableId]);
            if ($slot === null) {
                throw new BookingException('no_slot', 'La clase no existe.');
            }
            $slot = $this->schedule->slot($timetableId);
            if (!$this->schedule->runsOn($slot, $date)) {
                throw new BookingException('wrong_day', 'Esa clase no se da ese día.');
            }
            if ($ex = $this->schedule->isCancelled($timetableId, $date)) {
                throw new BookingException('session_cancelled', 'La clase está cancelada: ' . $ex['reason']);
            }
            $start = Schedule::sessionStart($slot, $date);
            if ($start <= $now) {
                throw new BookingException('past', 'La clase ya empezó o ya pasó.');
            }
            if ($enforceWindow) {
                $openDays = (int) ($slot['booking_open_days'] ?? 14);
                if ($start > $now->modify("+{$openDays} days")) {
                    throw new BookingException('window', "Las reservas abren {$openDays} días antes de la clase.");
                }
            }
            $student = $this->db->one('SELECT * FROM sk_students WHERE id = ?', [$studentId]);
            if ($student === null || $student['status'] === 'inactive') {
                throw new BookingException('student', 'El alumno no existe o está inactivo.');
            }

            $existing = $this->db->one('SELECT * FROM sk_bookings WHERE student_id = ? AND timetable_id = ? AND session_date = ? FOR UPDATE', [$studentId, $timetableId, $date]);
            if ($existing && in_array($existing['status'], self::ACTIVE_STATUSES, true)) {
                throw new BookingException('duplicate', 'El alumno ya tiene reservada esa clase.');
            }

            $capacity = $slot['capacity'] !== null ? (int) $slot['capacity'] : null;
            if ($capacity !== null) {
                $taken = (int) $this->db->value(
                    "SELECT COUNT(*) FROM sk_bookings WHERE timetable_id = ? AND session_date = ? AND status IN ('booked', 'attended', 'no_show')",
                    [$timetableId, $date]
                );
                if ($taken >= $capacity) {
                    throw new BookingException('full', 'La clase está llena.');
                }
            }

            $sub = null;
            if ($source !== 'evaluation' && !(int) $slot['is_evaluation']) {
                $sub = $this->eligibleSubscription($studentId, $slot, $date);
                if ($sub === null) {
                    throw new BookingException('no_subscription', 'El alumno no tiene una suscripción vigente que incluya este programa.');
                }
                if ($source === 'makeup') {
                    $this->checkMakeupLimit($studentId, $slot, $date);
                }
                $available = $this->credits->available($sub, $date);
                if ($available !== null && $available <= 0) {
                    throw new BookingException('no_credits', 'El alumno no tiene créditos disponibles en este período.');
                }
            } else {
                $source = 'evaluation';
            }

            $data = [
                'student_id' => $studentId, 'timetable_id' => $timetableId, 'session_date' => $date,
                'subscription_id' => $sub['id'] ?? null, 'source' => $source, 'status' => 'booked',
                'recurring_id' => $opts['recurring_id'] ?? null, 'cancelled_at' => null,
                'attendance_marked_at' => null, 'attendance_marked_by' => null, 'worker_id' => $this->audit->workerId(),
            ];
            if ($existing) {
                $this->db->update('sk_bookings', $data, 'id = ?', [$existing['id']]);
                $id = (int) $existing['id'];
            } else {
                $id = $this->db->insert('sk_bookings', $data);
            }
            if ($sub !== null) {
                $this->credits->consume($sub, $id, $date);
            }
            $this->audit->log('booking', $id, 'book', null, $data);
            return $id;
        });
    }

    /** Active subscription that covers the date and includes the slot's program, with credits first. */
    public function eligibleSubscription(int $studentId, array $slot, string $date): ?array
    {
        $subs = $this->db->all("SELECT * FROM sk_subscriptions WHERE student_id = ? AND status IN ('active', 'paused', 'cancelled') AND kind IN ('membership', 'class_pass', 'camp', 'makeup') ORDER BY start_date", [$studentId]);
        $best = null;
        foreach ($subs as $sub) {
            if (!Credits::coversDate($sub, $date)) {
                continue;
            }
            $programs = array_map('intval', array_column($this->db->all('SELECT program_id FROM sk_subscription_programs WHERE subscription_id = ?', [$sub['id']]), 'program_id'));
            if ($programs !== [] && $slot['program_id'] !== null && !in_array((int) $slot['program_id'], $programs, true)) {
                continue;
            }
            $available = $this->credits->available($sub, $date);
            if ($available === null || $available > 0) {
                return $sub;
            }
            $best ??= $sub;
        }
        return $best;
    }

    private function checkMakeupLimit(int $studentId, array $slot, string $date): void
    {
        $limit = (int) ($slot['makeups_per_month'] ?? 2);
        $used = (int) $this->db->value(
            "SELECT COUNT(*) FROM sk_bookings WHERE student_id = ? AND source = 'makeup' AND status IN ('booked', 'attended', 'no_show') AND DATE_FORMAT(session_date, '%Y-%m') = ?",
            [$studentId, substr($date, 0, 7)]
        );
        if ($used >= $limit) {
            throw new BookingException('makeup_limit', "Ya usó las {$limit} reposiciones de este mes.");
        }
    }

    /**
     * Cancels a booking. Before the program's deadline the credit is returned;
     * after it the booking is a late cancellation and keeps the credit, unless staff forces a refund.
     */
    public function cancel(int $bookingId, array $opts = []): string
    {
        $now = new DateTimeImmutable($opts['now'] ?? 'now');
        $force = (bool) ($opts['force_refund'] ?? false);
        return $this->db->transaction(function () use ($bookingId, $now, $force) {
            $b = $this->db->one('SELECT * FROM sk_bookings WHERE id = ? FOR UPDATE', [$bookingId]) ?? throw new BookingException('missing', 'Reserva no encontrada.');
            if ($b['status'] !== 'booked') {
                throw new BookingException('status', 'Sólo se cancelan reservas activas.');
            }
            $slot = $this->schedule->slot((int) $b['timetable_id']);
            $deadline = Schedule::sessionStart($slot, $b['session_date'])->modify('-' . (int) ($slot['cancel_deadline_hours'] ?? 12) . ' hours');
            $inTime = $now <= $deadline;
            $status = ($inTime || $force) ? 'cancelled' : 'late_cancelled';
            $this->db->update('sk_bookings', ['status' => $status, 'cancelled_at' => $now->format('Y-m-d H:i:s')], 'id = ?', [$bookingId]);
            if ($status === 'cancelled' && $b['subscription_id']) {
                $sub = $this->db->one('SELECT * FROM sk_subscriptions WHERE id = ?', [$b['subscription_id']]);
                if ($sub) {
                    $this->credits->refund($sub, $bookingId, $b['session_date'], $force && !$inTime ? 'Devolución manual (cancelación tardía)' : '');
                }
            }
            $this->audit->log('booking', $bookingId, 'cancel', ['status' => 'booked'], ['status' => $status]);
            return $status;
        });
    }

    public function markAttendance(int $bookingId, string $status): void
    {
        if (!in_array($status, ['attended', 'no_show', 'booked'], true)) {
            throw new ValidationException('Estado de asistencia no válido.');
        }
        $b = $this->db->one('SELECT * FROM sk_bookings WHERE id = ?', [$bookingId]) ?? throw new ValidationException('Reserva no encontrada.');
        if (!in_array($b['status'], self::ACTIVE_STATUSES, true)) {
            throw new ValidationException('La reserva está cancelada.');
        }
        $this->db->update('sk_bookings', [
            'status' => $status,
            'attendance_marked_at' => $status === 'booked' ? null : date('Y-m-d H:i:s'),
            'attendance_marked_by' => $status === 'booked' ? null : $this->audit->workerId(),
        ], 'id = ?', [$bookingId]);
        $this->audit->log('booking', $bookingId, 'attendance', ['status' => $b['status']], ['status' => $status]);
    }

    /** Cancels a session (or a whole day if $timetableId is null) and returns every credit. */
    public function cancelSession(string $date, ?int $timetableId, string $reason): int
    {
        Subscriptions::validateDate($date);
        if (trim($reason) === '') {
            throw new ValidationException('Indica el motivo (asueto, mantenimiento...).');
        }
        return $this->db->transaction(function () use ($date, $timetableId, $reason) {
            try {
                $this->db->insert('sk_session_exceptions', ['session_date' => $date, 'timetable_id' => $timetableId, 'reason' => $reason, 'worker_id' => $this->audit->workerId()]);
            } catch (\PDOException $e) {
                if (!Db::isDuplicateKey($e)) {
                    throw $e;
                }
            }
            $sql = "SELECT * FROM sk_bookings WHERE session_date = ? AND status = 'booked'" . ($timetableId !== null ? ' AND timetable_id = ?' : '');
            $bookings = $this->db->all($sql, $timetableId !== null ? [$date, $timetableId] : [$date]);
            foreach ($bookings as $b) {
                $this->db->update('sk_bookings', ['status' => 'session_cancelled', 'cancelled_at' => date('Y-m-d H:i:s')], 'id = ?', [$b['id']]);
                if ($b['subscription_id'] && ($sub = $this->db->one('SELECT * FROM sk_subscriptions WHERE id = ?', [$b['subscription_id']]))) {
                    $this->credits->refund($sub, (int) $b['id'], $date, 'Clase cancelada: ' . $reason);
                }
            }
            $this->audit->log('session', $timetableId, 'cancel_session', null, ['date' => $date, 'reason' => $reason, 'bookings' => count($bookings)]);
            return count($bookings);
        });
    }

    public function removeException(int $exceptionId): void
    {
        $ex = $this->db->one('SELECT * FROM sk_session_exceptions WHERE id = ?', [$exceptionId]);
        if ($ex) {
            $this->db->run('DELETE FROM sk_session_exceptions WHERE id = ?', [$exceptionId]);
            $this->audit->log('session', $ex['timetable_id'] !== null ? (int) $ex['timetable_id'] : null, 'restore_session', $ex, null);
        }
    }

    public function roster(int $timetableId, string $date): array
    {
        return $this->db->all(
            'SELECT b.*, s.first_name, s.last_name, s.birthdate, s.level, s.family_id, s.medical_notes,
                    (SELECT COUNT(*) FROM sk_bookings b2 WHERE b2.student_id = b.student_id AND b2.status = "attended") AS attended_total
             FROM sk_bookings b JOIN sk_students s ON s.id = b.student_id
             WHERE b.timetable_id = ? AND b.session_date = ? ORDER BY FIELD(b.status, "booked", "attended", "no_show", "late_cancelled", "cancelled", "session_cancelled"), s.first_name',
            [$timetableId, $date]
        );
    }

    public function counts(string $date): array
    {
        $out = [];
        foreach ($this->db->all("SELECT timetable_id, SUM(status IN ('booked','attended','no_show')) AS booked, SUM(status = 'attended') AS attended FROM sk_bookings WHERE session_date = ? GROUP BY timetable_id", [$date]) as $r) {
            $out[(int) $r['timetable_id']] = $r;
        }
        return $out;
    }

    public function forStudent(int $studentId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT b.*, t.event_name, t.start_time, t.end_time FROM sk_bookings b JOIN timetable t ON t.id = b.timetable_id
             WHERE b.student_id = ? ORDER BY b.session_date DESC, t.start_time DESC LIMIT ' . (int) $limit,
            [$studentId]
        );
    }

    // ---------- Recurring bookings (reservas fijas) ----------

    public function addRecurring(int $studentId, int $timetableId, string $startDate, ?string $endDate): int
    {
        Subscriptions::validateDate($startDate);
        if ($endDate !== null) {
            Subscriptions::validateDate($endDate);
        }
        $this->schedule->slot($timetableId) ?? throw new ValidationException('Clase no encontrada.');
        $data = ['student_id' => $studentId, 'timetable_id' => $timetableId, 'start_date' => $startDate, 'end_date' => $endDate, 'worker_id' => $this->audit->workerId()];
        $id = $this->db->insert('sk_recurring_bookings', $data);
        $this->audit->log('recurring_booking', $id, 'create', null, $data);
        return $id;
    }

    public function stopRecurring(int $id, string $fromDate): void
    {
        $before = $this->db->one('SELECT * FROM sk_recurring_bookings WHERE id = ?', [$id]) ?? throw new ValidationException('Reserva fija no encontrada.');
        $this->db->update('sk_recurring_bookings', ['active' => 0, 'end_date' => $fromDate], 'id = ?', [$id]);
        // Cancel the future bookings it created
        foreach ($this->db->all("SELECT id FROM sk_bookings WHERE recurring_id = ? AND session_date >= ? AND status = 'booked'", [$id, $fromDate]) as $b) {
            $this->cancel((int) $b['id'], ['force_refund' => true]);
        }
        $this->audit->log('recurring_booking', $id, 'stop', $before, ['active' => 0, 'end_date' => $fromDate]);
    }

    public function recurringFor(int $studentId): array
    {
        return $this->db->all(
            'SELECT r.*, t.event_name, t.day_of_week, t.start_time FROM sk_recurring_bookings r JOIN timetable t ON t.id = r.timetable_id
             WHERE r.student_id = ? ORDER BY r.active DESC, t.start_time',
            [$studentId]
        );
    }

    /**
     * Creates the bookings of every active recurring booking for the next $horizonDays days.
     * Idempotent: existing bookings are skipped; failures become alerts once.
     * @return array{created:int, alerts:int}
     */
    public function generateRecurring(string $today, int $horizonDays, ?string $now = null): array
    {
        $created = 0;
        $alerts = 0;
        $end = (new DateTimeImmutable($today))->modify("+{$horizonDays} days")->format('Y-m-d');
        $rows = $this->db->all('SELECT * FROM sk_recurring_bookings WHERE active = 1 AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)', [$end, $today]);
        foreach ($rows as $r) {
            $slot = $this->schedule->slot((int) $r['timetable_id']);
            if ($slot === null) {
                continue;
            }
            for ($d = new DateTimeImmutable(max($today, $r['start_date'])); $d->format('Y-m-d') <= $end; $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                if (($r['end_date'] !== null && $date > $r['end_date']) || !$this->schedule->runsOn($slot, $date)) {
                    continue;
                }
                if ($this->db->value('SELECT id FROM sk_bookings WHERE student_id = ? AND timetable_id = ? AND session_date = ?', [$r['student_id'], $r['timetable_id'], $date])) {
                    continue; // already booked, or cancelled by the family: never re-book automatically
                }
                if ($this->schedule->isCancelled((int) $r['timetable_id'], $date)) {
                    continue;
                }
                try {
                    $this->book((int) $r['student_id'], (int) $r['timetable_id'], $date, ['source' => 'recurring', 'recurring_id' => (int) $r['id'], 'now' => $now ?? 'now']);
                    $created++;
                } catch (BookingException $e) {
                    if ($e->reasonCode === 'past') {
                        continue;
                    }
                    try {
                        $this->db->insert('sk_booking_alerts', ['recurring_id' => $r['id'], 'student_id' => $r['student_id'], 'timetable_id' => $r['timetable_id'], 'session_date' => $date, 'reason' => $e->reasonCode]);
                        $alerts++;
                    } catch (\PDOException $dup) {
                        if (!Db::isDuplicateKey($dup)) {
                            throw $dup;
                        }
                    }
                }
            }
        }
        return ['created' => $created, 'alerts' => $alerts];
    }

    public function openAlerts(): array
    {
        return $this->db->all(
            'SELECT a.*, s.first_name, s.last_name, s.family_id, t.event_name, t.start_time FROM sk_booking_alerts a
             JOIN sk_students s ON s.id = a.student_id JOIN timetable t ON t.id = a.timetable_id
             WHERE a.resolved = 0 ORDER BY a.session_date, t.start_time'
        );
    }

    public function resolveAlert(int $id): void
    {
        $this->db->update('sk_booking_alerts', ['resolved' => 1], 'id = ?', [$id]);
    }
}
