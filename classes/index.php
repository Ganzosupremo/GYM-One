<?php function read_env_file($file_path)
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
$copyright_year = date("Y");

$env_data = read_env_file('../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';
$country = $env_data['COUNTRY'] ?? '';
$street = $env_data['STREET'] ?? '';
$city = $env_data['CITY'] ?? '';
$hause_no = $env_data['HOUSE_NUMBER'] ?? '';
$description = $env_data['DESCRIPTION'] ?? '';
$metakey = $env_data['META_KEY'] ?? '';
$gkey = $env_data['GOOGLE_KEY'] ?? '';
$mailadress = $env_data['MAIL_USERNAME'] ?? '';
$phoneno = $env_data['PHONE_NO'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$currency = $env_data['CURRENCY'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

$dayNames = [
    1 => $translations["Mon"],
    2 => $translations["Tue"],
    3 => $translations["Wed"],
    4 => $translations["Thu"],
    5 => $translations["Fri"],
    6 => $translations["Sat"],
    7 => $translations["Sun"]
];

$days = [];
$result = $conn->query("SELECT * FROM opening_hours ORDER BY day ASC");
while ($row = $result->fetch_assoc()) {
    $days[] = $row;
}

$today = new DateTime('today');
$maxDate = (new DateTime('today'))->modify('+14 days');

$todayStr = $today->format('Y-m-d');
$maxDateStr = $maxDate->format('Y-m-d');

$exceptions = [];
$stmt = $conn->prepare("
    SELECT * 
    FROM opening_hours_exceptions 
    WHERE date BETWEEN ? AND ?
    ORDER BY date ASC
");
$stmt->bind_param("ss", $todayStr, $maxDateStr);
$stmt->execute();

$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $exceptions[] = $row;
}
$stmt->close();

$months = [
    1 => $translations["Jan"],
    2 => $translations["Feb"],
    3 => $translations["Mar"],
    4 => $translations["Apr"],
    5 => $translations["May"],
    6 => $translations["Jun"],
    7 => $translations["Jul"],
    8 => $translations["Aug"],
    9 => $translations["Sep"],
    10 => $translations["Oct"],
    11 => $translations["Nov"],
    12 => $translations["Dec"],
];


// Sankris: programas con sus horarios y cuotas
$programs = [];
$programsResult = $conn->query("SELECT * FROM programs ORDER BY sort_order, name");
if ($programsResult) {
    while ($program = $programsResult->fetch_assoc()) {
        $program['slots'] = [];
        $program['evaluations'] = [];
        $program['tickets'] = [];
        $programs[$program['id']] = $program;
    }
}

$dayOrder = array_flip(array_values($dayNames));
$slotsResult = $conn->query("SELECT * FROM timetable WHERE program_id IS NOT NULL ORDER BY start_time");
if ($slotsResult) {
    while ($slot = $slotsResult->fetch_assoc()) {
        if (!isset($programs[$slot['program_id']])) {
            continue;
        }
        $key = !empty($slot['is_evaluation']) ? 'evaluations' : 'slots';
        // Agrupa por nombre de clase y hora: "Baby 3 años · 15:00 → Lunes, Martes"
        $groupKey = $slot['event_name'] . '|' . substr($slot['start_time'], 0, 5) . '|' . substr($slot['end_time'], 0, 5);
        $programs[$slot['program_id']][$key][$groupKey][] = $slot['day_of_week'];
    }
}

// Orden: primer día de la semana y luego hora de inicio
foreach ($programs as $programId => $program) {
    foreach (['slots', 'evaluations'] as $key) {
        uksort($programs[$programId][$key], function ($a, $b) use ($programs, $programId, $key, $dayOrder) {
            $firstDay = function ($groupKey) use ($programs, $programId, $key, $dayOrder) {
                return min(array_map(function ($d) use ($dayOrder) {
                    return $dayOrder[$d] ?? 9;
                }, $programs[$programId][$key][$groupKey]));
            };
            $cmp = $firstDay($a) <=> $firstDay($b);
            return $cmp !== 0 ? $cmp : strcmp(explode('|', $a)[1], explode('|', $b)[1]);
        });
    }
    $slotNames = array_unique(array_map(function ($groupKey) {
        return explode('|', $groupKey)[0];
    }, array_keys($programs[$programId]['slots'])));
    $programs[$programId]['show_slot_names'] = count($slotNames) > 1;
}

$ticketsResult = $conn->query("SELECT * FROM tickets WHERE program_id IS NOT NULL ORDER BY price");
if ($ticketsResult) {
    while ($ticket = $ticketsResult->fetch_assoc()) {
        if (isset($programs[$ticket['program_id']])) {
            $programs[$ticket['program_id']]['tickets'][] = $ticket;
        }
    }
}

$enrollments = [];
$enrollResult = $conn->query("SELECT * FROM tickets WHERE expire_days >= 365 ORDER BY name");
if ($enrollResult) {
    $enrollments = $enrollResult->fetch_all(MYSQLI_ASSOC);
}

function sankris_age_label($program, $translations)
{
    $min = $program['age_min'];
    $max = $program['age_max'];
    $years = $translations["years"] ?? "años";
    if ($min !== null && $max !== null) {
        return $min . ' - ' . $max . ' ' . $years;
    }
    if ($min !== null) {
        return ($translations["fromage"] ?? "Desde") . ' ' . $min . ' ' . $years;
    }
    return '';
}

function sankris_days_label(array $days, array $dayOrder)
{
    $days = array_values(array_unique($days));
    usort($days, function ($a, $b) use ($dayOrder) {
        return ($dayOrder[$a] ?? 9) <=> ($dayOrder[$b] ?? 9);
    });
    return implode(', ', $days);
}

?>




<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $business_name; ?> - <?php echo $translations["classespage"] ?? "Clases y horarios"; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- CUSTOM STYLE INSERT HERE! -->
    <link rel="stylesheet" href="../assets/css/default.css">
    <!-- CUSTOM STYLE INSERT HERE! -->
    <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
    <meta name="title" content="<?php echo $business_name; ?> - <?php echo $translations["classespage"] ?? "Clases y horarios"; ?>">
    <meta name="description" content="<?php echo $description; ?>">
    <meta name="keywords" content="<?php echo $metakey; ?>">
    <meta name="robots" content="index, follow">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="author" content="<?php echo $business_name; ?>">

</head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $gkey; ?>"></script>
<script>
    window.dataLayer = window.dataLayer || [];

    function gtag() {
        dataLayer.push(arguments);
    }
    gtag('js', new Date());

    gtag('config', '<?php echo $gkey; ?>');
</script>

<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light">
            <img class="img" src="../assets/img/brand/logo.png" width="148px" alt="<?php echo $business_name; ?> Logo">
            <button class="navbar-toggler custom-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false"
                aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="../"><?php echo $translations["mainpage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../trainers"><?php echo $translations["trainerspage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="#"><?php echo $translations["classespage"] ?? "Clases y horarios"; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../prices/"><?php echo $translations["pricespage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../contact/"><?php echo $translations["contactpage"]; ?></a>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a href="../login/" rel="noopener noreferrer" title="Login" class="nav-link ps-0 ps-lg-3 pe-3">
                            <i class="bi bi-person-circle"></i>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
    <div class="container-fluid">
        <div class="row">
            <div class="col bg-imageback">
            </div>
        </div>
        <div class="container">
            <div class="row mt-4 text-center justify-content-center">
                <div class="col-lg-8">
                    <h1><?php echo $translations["classespage"] ?? "Clases y horarios"; ?></h1>
                    <p class="lead"><?php echo $translations["classesintro"] ?? "Programas de gimnasia artística por edad y nivel. Cada nueva gimnasta comienza con una clase de evaluación inicial."; ?></p>
                </div>
            </div>
            <?php if (empty($programs)): ?>
                <div class="alert alert-warning" role="alert"><?php echo $translations["noclasses"] ?? "Todavía no hay clases publicadas."; ?></div>
            <?php else: ?>
                <div class="row mt-3">
                    <?php foreach ($programs as $program): ?>
                        <div class="col-md-6 col-xl-4 mb-4">
                            <div class="card h-100 shadow-sm program-card" style="border-top: 6px solid <?= htmlspecialchars($program['color']) ?>;">
                                <div class="card-body">
                                    <h4 class="card-title" style="color: <?= htmlspecialchars($program['color']) ?>;"><?= htmlspecialchars($program['name']) ?></h4>
                                    <p class="mb-2">
                                        <?php $ageLabel = sankris_age_label($program, $translations); ?>
                                        <?php if ($ageLabel !== ''): ?><span class="badge program-badge"><i class="bi bi-person"></i> <?= htmlspecialchars($ageLabel) ?></span><?php endif; ?>
                                        <?php if (!empty($program['max_students'])): ?><span class="badge program-badge"><i class="bi bi-people"></i> <?= (int) $program['max_students'] ?> <?= $translations["pergroup"] ?? "por grupo"; ?></span><?php endif; ?>
                                        <?php if (!empty($program['duration_minutes'])): ?><span class="badge program-badge"><i class="bi bi-clock"></i> <?= (int) $program['duration_minutes'] ?> min</span><?php endif; ?>
                                        <?php if (!empty($program['area'])): ?><span class="badge program-badge"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($program['area']) ?></span><?php endif; ?>
                                    </p>
                                    <p class="card-text"><?= htmlspecialchars($program['description'] ?? '') ?></p>

                                    <?php if (!empty($program['slots'])): ?>
                                        <h6 class="mt-3"><?= $translations["schedule"] ?? "Horarios"; ?></h6>
                                        <table class="table table-sm program-table mb-2">
                                            <tbody>
                                                <?php foreach ($program['slots'] as $groupKey => $slotDays): ?>
                                                    <?php [$slotName, $slotStart, $slotEnd] = explode('|', $groupKey); ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars(sankris_days_label($slotDays, $dayOrder)) ?><?php if (!empty($program['show_slot_names'])): ?><br><small><?= htmlspecialchars($slotName) ?></small><?php endif; ?></td>
                                                        <td class="text-end text-nowrap"><?= htmlspecialchars($slotStart . ' - ' . $slotEnd) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="small"><i class="bi bi-info-circle"></i> <?= $translations["schedulebyrequest"] ?? "Horarios específicos por nivel. Consultar en administración."; ?></p>
                                    <?php endif; ?>

                                    <?php if (!empty($program['evaluations'])): ?>
                                        <p class="small mb-2"><strong><?= $translations["evaluationclass"] ?? "Clase de evaluación inicial"; ?>:</strong>
                                            <?php $evalTexts = [];
                                            foreach ($program['evaluations'] as $groupKey => $evalDays) {
                                                [, $slotStart] = explode('|', $groupKey);
                                                $evalTexts[] = sankris_days_label($evalDays, $dayOrder) . ' ' . $slotStart;
                                            }
                                            echo htmlspecialchars(implode(' · ', $evalTexts)); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (!empty($program['tickets'])): ?>
                                        <h6 class="mt-3"><?= $translations["monthlyfee"] ?? "Suscripción mensual"; ?></h6>
                                        <ul class="list-unstyled mb-0">
                                            <?php foreach ($program['tickets'] as $ticket): ?>
                                                <?php if ((int) $ticket['expire_days'] >= 365) continue; ?>
                                                <li class="d-flex justify-content-between">
                                                    <span><?= htmlspecialchars($ticket['name']) ?></span>
                                                    <strong class="text-nowrap"><?= htmlspecialchars($currency) ?><?= number_format((float) $ticket['price'], 2) ?></strong>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($enrollments)): ?>
                    <div class="row mb-4 justify-content-center">
                        <div class="col-lg-8 text-center">
                            <h5><?= $translations["annualenrollment"] ?? "Inscripción anual"; ?></h5>
                            <?php foreach ($enrollments as $ticket): ?>
                                <span class="badge program-badge me-2"><?= htmlspecialchars($ticket['name']) ?>: <?= htmlspecialchars($currency) ?><?= number_format((float) $ticket['price'], 2) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            <div class="container">
                <div class="row gy-4">
                    <div class="mt-3"></div>
                    <div class="col-md-4 mb-1">
                        <h2 class="mb-4">
                            <img src="../assets/img/brand/logo.png" alt="<?php echo $business_name; ?> - Logo"
                                height="105">
                        </h2>

                        <p><?php echo $city; ?></p>
                        <p><?php echo $street; ?> <?php echo $hause_no; ?></p>
                    </div>
                    <div class="col-md-3 offset-md-1">
                        <?php if (!empty($days)): ?>
                            <div class="list-group">
                                <?php foreach ($days as $day): ?>
                                    <div class="list-group-itemcustom d-flex justify-content-between align-items-center">
                                        <span><strong><?= htmlspecialchars($dayNames[$day['day']]) ?></strong></span>
                                        <span class="text-center justify-content-center">
                                            <?php if (is_null($day['open_time']) && is_null($day['close_time'])): ?>
                                                <span class="badge bg-danger"><?= $translations["closed"]; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">
                                                    <?= date('H:i', strtotime($day['open_time'])) ?> -
                                                    <?= date('H:i', strtotime($day['close_time'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                <hr>
                                <?php foreach ($exceptions as $ex): ?>
                                    <?php
                                    $date = new DateTime($ex['date']);
                                    $monthName = $months[(int) $date->format('n')];
                                    $day = $date->format('j');
                                    ?>
                                    <div class="list-group-itemcustom d-flex justify-content-between align-items-center">
                                        <span><strong><?= $monthName . ' ' . $day . '.' ?></strong></span>

                                        <span class="text-center justify-content-center">
                                            <?php if ($ex['is_closed']): ?>
                                                <span class="badge bg-danger"><?= $translations["closed"]; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <?= date('H:i', strtotime($ex['open_time'])) ?> -
                                                    <?= date('H:i', strtotime($ex['close_time'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-2 offset-md-1">
                        <h5 class="text-light mb-4"></h5>

                    </div>
                </div>

                <div class="border-top border-secondary pt-3 mt-3">
                    <p class="small text-center mb-0">
                        Copyright © <?php echo $copyright_year; ?> <?php echo $business_name; ?> -
                        <?php echo $translations["copyright"]; ?>
                        &nbsp;<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="red"
                            class="bi bi-heart-fill" viewBox="0 0 16 16">
                            <path fill-rule="evenodd"
                                d="M8 1.314C12.438-3.248 23.534 4.735 8 15-7.534 4.736 3.562-3.248 8 1.314">
                            </path>
                        </svg>
                        <a href="https://www.gymoneglobal.com/?lang=<?php echo $lang_code; ?>">GYM One</a>
                    </p>
                </div>
            </div>
        </div>
</body>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>

</html>