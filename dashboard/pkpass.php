<?php
session_start();
require __DIR__ . '/../vendor/autoload.php';

use PKPass\PKPass;
use PKPass\PKPassException;



function read_env_file($file_path)
{
    if (!file_exists($file_path)) {
        return [];
    }

    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];

    foreach ($env_lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $line_parts = explode('=', $line, 2);
        if (count($line_parts) == 2) {
            $key = trim($line_parts[0]);
            $value = trim($line_parts[1]);

            $env_data[$key] = $value;
        }
    }

    return $env_data;
}

$copyright_year = date("Y");

$env_data = read_env_file('../.env');

$db_host       = $env_data['DB_SERVER']     ?? '';
$db_username   = $env_data['DB_USERNAME']   ?? '';
$db_password   = $env_data['DB_PASSWORD']   ?? '';
$db_name       = $env_data['DB_NAME']       ?? '';
$country       = $env_data['COUNTRY']       ?? '';
$street        = $env_data['STREET']        ?? '';
$city          = $env_data['CITY']          ?? '';
$hause_no      = $env_data['HOUSE_NUMBER']  ?? '';
$description   = $env_data['DESCRIPTION']   ?? '';
$metakey       = $env_data['META_KEY']      ?? '';
$gkey          = $env_data['GOOGLE_KEY']    ?? '';
$capacity      = $env_data['CAPACITY']      ?? '';
$about_us      = $env_data['ABOUT']         ?? '';
$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code     = $env_data['LANG_CODE']     ?? '';

$lang = $lang_code;

$langDir  = __DIR__ . "/../assets/lang/";
$langFile = $langDir . "$lang.json";

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

$userid = $_SESSION['userid'];
$sql = "SELECT CONCAT(firstname, ' ', lastname) AS fullname, birthdate 
            FROM users 
            WHERE userid = ?";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    $stmt->bind_result($fullname, $birthdate);

    if ($stmt->fetch()) {
        $memberName = $fullname;
        $memberBirthdate = $birthdate;
    }
    $stmt->close();
};

$pass = [
    'formatVersion'      => 1,
    'passTypeIdentifier' => 'pass.com.gymone.valid',
    'serialNumber'       => uniqid('gymone-'),
    'teamIdentifier'     => 'GYMONE_GWALLET',
    'organizationName'   => 'GYM One',
    'description'        => 'GYM One Membership - GWallet',
    'logoText'           => $business_name,
    'foregroundColor'    => 'rgb(255,255,255)',
    'backgroundColor'    => 'rgb(144, 39, 142)',
    'labelColor'         => 'rgba(0, 162, 255, 1)',
    'relevantDate'       => date('c'),
    'images' => [
        'icon' => 'icon.png',
        'logo' => 'logo.png'
    ],
    'generic' => [
        'primaryFields' => [
            ['key' => 'name', 'label' => $translations["fullname"], 'value' => $memberName]
        ],
        'secondaryFields' => [
            ['key' => 'birthdate', 'label' => $translations["birthday"], 'value' => $memberBirthdate]
        ],
        'backFields' => [
            [
                'key' => 'partner_text',
                'label' => $translations["partner_otherbtn"],
                'value' => $translations["gwalletinfobox"],
            ],
            [
                'key' => 'motivation',
                'label' => "💪 " . $translations["gwalletmotivation"],
                'value' => $translations["gwalletmotivation_value"],
            ],
            [
                'key' => 'copyright',
                'label' => $translations["license"],
                'value' => $translations["copyright"] . " " . $copyright_year . "© ",
            ]
        ]
    ],
    'barcode' => [
        'format'           => 'PKBarcodeFormatQR',
        'message'          => $userid,
        'messageEncoding'  => 'utf-8'
    ]
];

$tmpDir = sys_get_temp_dir() . '/pkpass_' . uniqid();
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    die("Nem tudom létrehozni a temp könyvtárat\n");
}

file_put_contents($tmpDir . '/pass.json', json_encode($pass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$iconSrc = __DIR__ . '/../assets/img/icon.png';
$logoSrc = __DIR__ . '/../assets/img/logo.png';

if (!file_exists($iconSrc)) {
    rrmdir($tmpDir);
    die("Hiányzik: icon.png ($iconSrc)\n");
}
if (!file_exists($logoSrc)) {
    rrmdir($tmpDir);
    die("Hiányzik: logo.png ($logoSrc)\n");
}

copy($iconSrc, $tmpDir . '/icon.png');
copy($logoSrc, $tmpDir . '/logo.png');

$manifest = [];
foreach (scandir($tmpDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $manifest[$f] = sha1_file($tmpDir . '/' . $f);
}
file_put_contents($tmpDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));


$outputPkpass = __DIR__ . '/' . $business_name . '_' . $userid . '.pkpass';
$zip = new ZipArchive();
if ($zip->open($outputPkpass, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    rrmdir($tmpDir);
    die("Nem sikerült a pkpass ZIP létrehozása\n");
}
foreach (scandir($tmpDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $zip->addFile($tmpDir . '/' . $f, $f);
}
$zip->close();

rrmdir($tmpDir);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="' . $business_name . '_' . $userid . '.pkpass"');
    header('Content-Length: ' . filesize($outputPkpass));
    readfile($outputPkpass);
    unlink($outputPkpass);
    exit;
} else {
    echo "PKPass (unsigned) elkészült: $outputPkpass\n";
}



function rrmdir($dir)
{
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . "/" . $item;
        if (is_dir($path)) rrmdir($path);
        else unlink($path);
    }
    rmdir($dir);
}
