<?php
declare(strict_types=1);

namespace Sankris;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['sk_csrf'])) {
            $_SESSION['sk_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['sk_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            http_response_code(400);
            exit('Solicitud inválida (CSRF). Recarga la página e inténtalo de nuevo.');
        }
    }
}
