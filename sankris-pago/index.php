<?php
// Página a la que Stripe devuelve al responsable después de pagar o guardar su tarjeta.
// No confirma nada por sí misma: el pago se registra cuando llega el aviso (webhook) de Stripe.
$estado = (string) ($_GET['estado'] ?? '');
$mensajes = [
    'pagado' => ['¡Gracias! Recibimos tu pago.', 'En unos minutos aparecerá aplicado a tu factura. Te enviaremos el comprobante.'],
    'tarjeta-guardada' => ['Tu tarjeta quedó guardada.', 'Las mensualidades se cobrarán automáticamente en su fecha. Puedes pedir el cambio de tarjeta en recepción.'],
    'cancelado' => ['No se realizó ningún cobro.', 'Si fue un error, puedes volver a abrir el enlace que te enviamos o escribirnos.'],
];
[$titulo, $detalle] = $mensajes[$estado] ?? ['Sankris Gymnastics', 'Si tienes dudas sobre tu pago, escríbenos o pasa por recepción.'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pago · Sankris Gymnastics</title>
    <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        :root { --brand: #90278e; --pink: #eb008b; --ink: #2a2233; --muted: #6b6375; --bg: #f7f5fa; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 6px 24px rgba(42, 34, 51, .1); max-width: 440px; width: 100%; padding: 36px 28px; text-align: center; border-top: 6px solid var(--brand); }
        img { width: 120px; margin-bottom: 18px; }
        h1 { font-size: 22px; margin: 0 0 10px; color: var(--brand); }
        p { color: var(--muted); line-height: 1.5; margin: 0 0 22px; }
        a { display: inline-block; background: var(--pink); color: #fff; text-decoration: none; padding: 10px 22px; border-radius: 24px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <img src="../assets/img/brand/logo.png" alt="Sankris Gymnastics">
        <h1><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($detalle, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="../">Volver al inicio</a>
    </div>
</body>
</html>
