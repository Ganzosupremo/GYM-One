<?php
require_once '../vendor/autoload.php'; 

// GYM ONE - HEARTBEAT API CALL
$wait = rand(1,10);
sleep($wait);

echo file_get_contents('https://api.gymoneglobal.com/POST/HeartBeat', false, stream_context_create([
    'http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: application/json\r\n",
        'content'=>json_encode(['timestamp'=>date('c')])
    ]
]));

// GYM ONE - HEARTBEAT API HANGUP


function read_env_file($file_path)
{
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];

    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line);
        if (count($line_parts) == 2) {
            $key = trim($line_parts[0]);
            $value = trim($line_parts[1]);
            $env_data[$key] = $value;
        }
    }

    return $env_data;
}

$env_data = read_env_file('../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$smtp_host = $env_data['MAIL_HOST'] ?? '';
$smtp_port = $env_data['MAIL_PORT'] ?? '';
$smtp_encryption = $env_data['MAIL_ENCRYPTION'] ?? '';
$smtp_username = $env_data['MAIL_USERNAME'] ?? '';
$smtp_password = $env_data['MAIL_PASSWORD'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$domain_url = $protocol . $host;

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}


$tomorrow = (new DateTime('tomorrow'))->format('Y-m-d');

$sql = "
    SELECT u.firstname, u.lastname, u.email, c.ticketname, c.expiredate
    FROM current_tickets c
    JOIN users u ON u.userid = c.userid
    WHERE c.expiredate = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $tomorrow);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "There are no notifications to send.\n";
    exit;
}

$transport = (new Swift_SmtpTransport($smtp_host, $smtp_port, $smtp_encryption))
    ->setUsername($smtp_username)
    ->setPassword($smtp_password);

$mailer = new Swift_Mailer($transport);

while ($row = $result->fetch_assoc()) {
    $to      = $row['email'];
    $firstname = $row['firstname'];
    $name    = $row['firstname'] . " " . $row['lastname'];
    $ticket  = $row['ticketname'];

    $replacements = [
        "{first_name}" => $firstname,
        "{membership_name}" => $ticket,
        "{remaining_days}" => "1"
    ];
    $ExpireEmailSubject_PLACEHOLDER = strtr($translations["expireemailsubject"], $replacements);

    $replacements = [
        "{first_name}" => $firstname,
        "{membership_name}" => $ticket
    ];
    $ExpireEmailHero_PLACEHOLDER = strtr($translations["expireemailhero"], $replacements);

    $replacements = [
        "{expiry_date}" => $tomorrow,
        "{remaining_days}" => "1"
    ];
    $ExpireEmailExpireDate_PLACEHOLDER = strtr($translations["expireemailexpiredate"], $replacements);

    $ConfirmEmailFooterWhy_PLACEHOLDER = str_replace("{business_name}", $business_name, $translations["confirmemailfooterwhy"]);

    $action = "{$translations['log_sent_expire']} - {$to} - {$tomorrow}";
    $actioncolor = 'info';
    $zero = 0;
    $sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $zero, $action, $actioncolor);
    $stmt->execute();

    $expDate = (new DateTime($row['expiredate']))->format('Y. m. d.');

    $subject = $ExpireEmailSubject_PLACEHOLDER;
    $body = <<<EOD
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* PRIMARY: #90278e (vibrant blue) ACCENT-DARK: #0742B8 TEXT: #222 MUTED: #6B7280 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; background-color: #f8f9fa; }
        .email-container { max-width: 680px; margin: 0 auto; background: white; }
        .header { padding: 40px 30px 20px; text-align: center; }
        .logo { max-width: 200px; height: auto; }
        .content { padding: 0 30px 30px; }
        .alert-badge { background: #FEF3C7; color: #92400E; padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; margin-bottom: 20px; }
        .hero-title { color: #222; font-size: 24px; font-weight: 700; margin-bottom: 16px; }
        .expiry-info { background: #FEF3C7; border: 1px solid #F59E0B; padding: 20px; border-radius: 8px; margin: 24px 0; }
        .expiry-date { color: #92400E; font-weight: 600; font-size: 18px; }
        .consequences { color: #6B7280; margin-top: 16px; }
        .consequence-item { margin: 8px 0; padding-left: 20px; position: relative; }
        .consequence-item:before { content: "•"; color: #F59E0B; font-weight: bold; position: absolute; left: 0; }
        .cta-button { 
            display: inline-block; 
            background: linear-gradient(135deg, #90278e, #0742B8); 
            color: white; 
            text-decoration: none; 
            padding: 16px 32px; 
            border-radius: 8px; 
            font-weight: 600; 
            font-size: 16px; 
            text-align: center; 
            box-shadow: 0 4px 12px rgba(144, 39, 142, 0.3);
        }
        .cta-container { text-align: center; margin: 32px 0; }
        .footer { background: #f8f9fa; padding: 24px 30px; text-align: center; color: #6B7280; font-size: 12px; }
        .footer a { color: #90278e; text-decoration: none; }
    </style>
</head>
<body>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
        <tr>
            <td>
                <div class="email-container">
                    <div class="header">
                        <img src="{$domain_url}/assets/img/brand/logo.png" alt="GYM Logo" class="logo">
                    </div>
                    
                    <div class="content">
                        <div style="text-align: center;">
                            <span class="alert-badge">⏰ {$translations["expireemailbadge"]}</span>
                        </div>
                        
                        <h1 class="hero-title">{$ExpireEmailHero_PLACEHOLDER}</h1>
                        
                        <div class="expiry-info">
                            <div class="expiry-date">{$ExpireEmailExpireDate_PLACEHOLDER}</div>
                            <div class="consequences">
                                <p><strong>{$translations["expireemailafterdate"]}</strong></p>
                                <div class="consequence-item">{$translations["expireemailconsequence_one"]}</div>
                                <div class="consequence-item">{$translations["expireemailconsequence_two"]}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="footer">
                        <p>{$ConfirmEmailFooterWhy_PLACEHOLDER}</p>
                        <p style="font-size:10px; color:#D1D5DB; display:flex; align-items:center; justify-content:center; gap:8px;">
                            <span>⚡</span>
                            <span>Engineered with <span style="color:#ef4444;">♥</span> by <a href="https://gymoneglobal.com" style="color:#90278e;">GYM One</a></span>
                            <span>⚡</span>
                        </p>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
EOD;


    $message = (new Swift_Message($subject))
        ->setFrom([$smtp_username => $business_name])
        ->setTo([$to => $name])
        ->setBody($body, 'text/html');

    try {
        $mailer->send($message);
        echo "Email sent: $to\n";
    } catch (Exception $e) {
        echo "Error sending email ($to): " . $e->getMessage() . "\n";
    }
}

$stmt->close();
$conn->close();
