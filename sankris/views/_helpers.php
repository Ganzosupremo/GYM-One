<?php
declare(strict_types=1);

use Sankris\App;
use Sankris\BookingException;
use Sankris\Csrf;
use Sankris\Money;
use Sankris\ValidationException;

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $page, array $params = []): string
{
    return '?' . http_build_query(array_merge(['p' => $page], $params));
}

function money($amount): string
{
    return h(Money::format($amount, App::boot(dirname(__DIR__, 2))->currency()));
}

function fdate(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date(strlen($d) > 10 ? 'd/m/Y H:i' : 'd/m/Y', $ts) : h($d);
}

function flash(string $type, string $msg): void
{
    $_SESSION['sk_flash'][] = [$type, $msg];
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

function deny(): void
{
    http_response_code(403);
    if (empty($GLOBALS['sk_header_done'])) {
        sk_header('Sin permiso', '');
    }
    echo '<div class="alert alert-danger">No tienes permiso para esta sección. Pide a administración que revise tu rol.</div>';
    sk_footer();
    exit;
}

/** Runs a POST action with CSRF check; user-facing errors become flash messages. */
function on_post(string $action, callable $fn): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== $action) {
        return;
    }
    Csrf::check();
    try {
        $fn($_POST);
    } catch (ValidationException | BookingException $e) {
        flash('danger', $e->getMessage());
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        exit;
    } catch (\Sankris\Stripe\StripeError $e) {
        flash('danger', 'Stripe: ' . $e->getMessage());
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        exit;
    }
}

function form_open(string $action, string $extra = ''): string
{
    return '<form method="post" ' . $extra . '>' . Csrf::field() . '<input type="hidden" name="action" value="' . h($action) . '">';
}

function request_key(): string
{
    return '<input type="hidden" name="request_key" value="' . bin2hex(random_bytes(12)) . '">';
}

function opt(array $options, $selected): string
{
    $out = '';
    foreach ($options as $value => $label) {
        $out .= '<option value="' . h($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
}

function status_label(string $status): string
{
    $map = [
        'active' => ['success', 'Activo'], 'inactive' => ['default', 'Inactivo'], 'trial' => ['info', 'Prueba'],
        'paused' => ['warning', 'Pausada'], 'cancelled' => ['danger', 'Cancelada'], 'ended' => ['default', 'Terminada'],
        'open' => ['warning', 'Pendiente'], 'paid' => ['success', 'Pagada'], 'void' => ['default', 'Anulada'], 'uncollectible' => ['danger', 'Incobrable'],
        'booked' => ['primary', 'Reservada'], 'attended' => ['success', 'Asistió'], 'no_show' => ['danger', 'No asistió'],
        'late_cancelled' => ['warning', 'Cancelación tardía'], 'session_cancelled' => ['default', 'Clase cancelada'],
        'succeeded' => ['success', 'Aprobado'], 'failed' => ['danger', 'Rechazado'], 'pending' => ['info', 'En proceso'], 'requires_action' => ['warning', 'Requiere autenticación'],
    ];
    [$cls, $label] = $map[$status] ?? ['default', $status];
    return '<span class="label label-' . $cls . '">' . h($label) . '</span>';
}

function age_label(?string $birthdate): string
{
    $age = \Sankris\Period::age($birthdate);
    return $age === null ? '—' : $age . ' años';
}

const PERIOD_LABELS = ['monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'semiannual' => 'Semestral', 'annual' => 'Anual', 'once' => 'Pago único'];
const CREDIT_PERIOD_LABELS = ['month' => 'Por mes', 'week' => 'Por semana', 'billing' => 'Por período de cobro', 'validity' => 'Por vigencia del plan'];
const KIND_LABELS = ['membership' => 'Mensualidad', 'class_pass' => 'Pase de clases', 'fee' => 'Cuota (inscripción)', 'camp' => 'Campamento', 'makeup' => 'Reposición'];
const SOURCE_LABELS = ['manual' => 'Manual', 'recurring' => 'Fija', 'makeup' => 'Reposición', 'evaluation' => 'Evaluación'];
