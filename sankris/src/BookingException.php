<?php
declare(strict_types=1);

namespace Sankris;

/** A booking rule was not met; reasonCode is stable (used for alerts), the message is for people. */
final class BookingException extends \RuntimeException
{
    public string $reasonCode;

    public function __construct(string $reasonCode, string $message)
    {
        parent::__construct($message);
        $this->reasonCode = $reasonCode;
    }

    public static function label(string $code): string
    {
        return [
            'full' => 'Clase llena', 'no_credits' => 'Sin créditos', 'no_subscription' => 'Sin suscripción vigente',
            'session_cancelled' => 'Clase cancelada', 'makeup_limit' => 'Límite de reposiciones', 'student' => 'Alumno inactivo',
            'window' => 'Fuera de la ventana de reserva', 'duplicate' => 'Ya reservada',
        ][$code] ?? $code;
    }
}
