<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../../dashboard");
    exit();
}

$userid = $_SESSION['adminuser'];

$alerts_html = "";

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

$env_data = read_env_file('../../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$capacity = $env_data["CAPACITY"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}
$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

$translations = json_decode(file_get_contents($langFile), true);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['business_name'])) {
        $business_name = $_POST['business_name'] ?? '';
        $street_env = $_POST['street'] ?? '';
        $country_env = $_POST['country'] ?? '';
        $city_env = $_POST['city'] ?? '';
        $house_no_env = $_POST['house_number'] ?? '';
        $description_env = $_POST["description"] ?? '';
        $metakey_env = $_POST["metakey"] ?? '';
        $langcode_env = $_POST['lang_code'] ?? '';
        $currency_env = $_POST['currency'] ?? '';
        $gkey_env = $_POST['gkey'] ?? '';
        $capacity_env = $_POST["capacity"] ?? '';
        $phoneno_env = $_POST["phone_no"] ?? '';

        $env_data["BUSINESS_NAME"] = $business_name;
        $env_data['STREET'] = $street_env;
        $env_data['COUNTRY'] = $country_env;
        $env_data['CITY'] = $city_env;
        $env_data['DESCRIPTION'] = $description_env;
        $env_data['META_KEY'] = $metakey_env;
        $env_data['HOUSE_NUMBER'] = $house_no_env;
        $env_data['LANG_CODE'] = $langcode_env;
        $env_data['CURRENCY'] = $currency_env;
        $env_data['GOOGLE_KEY'] = $gkey_env;
        $env_data["CAPACITY"] = $capacity_env;
        $env_data["PHONE_NO"] = $phoneno_env;

        $env_content = '';
        foreach ($env_data as $key => $value) {
            $env_content .= "$key=$value\n";
        }

        if (file_put_contents('../../../.env', $env_content) !== false) {
            $alerts_html .= "<div class='alert alert-success'>{$translations["success-update"]}</div>";
            $action = $translations['success-update-env-main'];
            $actioncolor = 'danger';
            $sql = "INSERT INTO logs (userid, action, actioncolor, time) 
            VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $userid, $action, $actioncolor);
            $stmt->execute();

            header("Refresh:2");
        } else {
            $alerts_html .= "<div class='alert alert-danger'>{$translations["error-env"]}</div>";
            header("Refresh:2");
        }
    }
}

$sql = "SELECT is_boss FROM workers WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$stmt->store_result();



$is_boss = null;

if ($stmt->num_rows > 0) {
    $stmt->bind_result($is_boss);
    $stmt->fetch();
}
$stmt->close();

$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$alerts_html = '';

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_event'])) {
    $event_name = trim($_POST['event_name']);
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $day_of_week = $_POST['day_of_week'];
    $color = $_POST['color'];
    // Sankris: programa (grupo/edad), cupo y si es clase de evaluación
    $program_id = !empty($_POST['program_id']) ? (int) $_POST['program_id'] : null;
    $capacity = ($_POST['capacity'] ?? '') !== '' ? (int) $_POST['capacity'] : null;
    $is_evaluation = isset($_POST['is_evaluation']) ? 1 : 0;

    $sql = "INSERT INTO timetable (event_name, start_time, end_time, day_of_week, color, program_id, capacity, is_evaluation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssiii', $event_name, $start_time, $end_time, $day_of_week, $color, $program_id, $capacity, $is_evaluation);
    $stmt->execute();
    $stmt->close();
    $alerts_html .= "<div class='alert alert-success'>{$translations["eventadded"]}</div>";
    header("Refresh:1");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_event'])) {
    // Sankris: se elimina un solo horario (por id), no todas las clases con el mismo nombre
    $event_id = (int) $_POST['event_id'];

    $sql = "DELETE FROM timetable WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $stmt->close();
    $alerts_html .= "<div class='alert alert-warning'>{$translations["eventdeleted"]}</div>";
    header("Refresh:1");
}

$days_of_week = [$translations["Mon"], $translations["Tue"], $translations["Wed"], $translations["Thu"], $translations["Fri"], $translations["Sat"], $translations["Sun"]];
$timetable = [];
foreach ($days_of_week as $day) {
    $sql = "SELECT * FROM timetable WHERE day_of_week = ? ORDER BY start_time";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $day);
    $stmt->execute();
    $result = $stmt->get_result();
    $timetable[$day] = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$sql = "SELECT id, event_name, day_of_week, start_time, end_time FROM timetable ORDER BY event_name, FIELD(day_of_week, ?, ?, ?, ?, ?, ?, ?), start_time";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sssssss', ...$days_of_week);
$stmt->execute();
$allEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$programs = [];
$programsResult = $conn->query("SELECT id, name FROM programs ORDER BY sort_order, name");
if ($programsResult) {
    $programs = $programsResult->fetch_all(MYSQLI_ASSOC);
}

$hours = [];
$start = strtotime('06:00');
$end = strtotime('21:00');
while ($start < $end) {
    $next = strtotime('+1 hour', $start);
    $hours[] = date('H:i', $start) . '-' . date('H:i', $next);
    $start = $next;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $translations["dashboard"]; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="../../../assets/img/brand/favicon.png" type="image/x-icon">
</head>
<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#"><img src="../../../assets/img/brand/logo-white.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../../dashboard"><i class="bi bi-speedometer"></i>
                            <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a>
                    </li>
                    <li><a href="../../statistics"><i class="bi bi-bar-chart"></i>
                            <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../../boss/sell"><i class="bi bi-shop"></i>
                            <?php echo $translations["sellpage"]; ?></a></li>
                    <li><a href="../../invoices"><i class="bi bi-receipt"></i>
                            <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i>
                                <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li><a href="../../boss/packages"><?php echo $translations["packagepage"]; ?></a></li>
                                <li><a href="../../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../../boss/chroom"><?php echo $translations["chroompage"]; ?></a></li>
                                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i>
                            <?php echo $translations["ticketspage"]; ?></a></li>
                    <li class="active"><a href="#"><i class="bi bi-calendar-event"></i>
                            <?php echo $translations["timetable"]; ?></a></li>
                    <li><a href="../../trainers/personal"><i class="bi bi-award"></i>
                            <?php echo $translations["trainers"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../../updater"><i class="bi bi-cloud-download"></i>
                                <?php echo $translations["updatepage"]; ?>
                                <?php if ($is_new_version_available): ?>
                                    <span class="badge badge-warning"><i class="bi bi-exclamation-circle"></i></span>
                                <?php endif; ?>
                            </a></li>
                    <?php } ?>
                    <li><a href="../../log"><i class="bi bi-clock-history"></i>
                            <?php echo $translations["logpage"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../../assets/img/brand/logo-white.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?> - <?php echo $version; ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../dashboard/">
                            <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../users">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../statistics">
                            <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/sell">
                            <i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="../../invoices/" class="sidebar-link">
                            <i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?>
                        </a>
                    </li>
                    <?php
                    if ($is_boss === 1) {
                        ?>
                        <li class="sidebar-header">
                            <?php echo $translations["settings"]; ?>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/mainsettings">
                                <i class="bi bi-gear"></i>
                                <span><?php echo $translations["businesspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/workers">
                                <i class="bi bi-people"></i>
                                <span><?php echo $translations["workers"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/packages">
                                <i class="bi bi-box-seam"></i>
                                <span><?php echo $translations["packagepage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/hours">
                                <i class="bi bi-clock"></i>
                                <span><?php echo $translations["openhourspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/smtp">
                                <i class="bi bi-envelope-at"></i>
                                <span><?php echo $translations["mailpage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/chroom">
                                <i class="bi bi-duffle"></i>
                                <span><?php echo $translations["chroompage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/rule">
                                <i class="bi bi-file-ruled"></i>
                                <span><?php echo $translations["rulepage"]; ?></span>
                            </a>
                        </li>
                        <?php
                    }
                    ?>
                    <li class="sidebar-header">
                        <?php echo $translations["shopcategory"]; ?>
                    </li>
                    <li class="sidebar-item">
                        <!-- <a class="sidebar-ling" href="../shop/gateway">
                            <i class="bi bi-shield-lock"></i>
                            <span><?php echo $translations["gatewaypage"]; ?></span>
                        </a> -->
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../shop/tickets">
                            <i class="bi bi-ticket"></i>
                            <span><?php echo $translations["ticketspage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-header">
                        <?php echo $translations["trainersclass"]; ?>
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-calendar-event"></i>
                            <span><?php echo $translations["timetable"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../trainers/personal">
                            <i class="bi bi-award"></i>
                            <span><?php echo $translations["trainers"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-header"><?php echo $translations["other-header"]; ?></li>
                    <?php
                    if ($is_boss === 1) {
                        ?>
                        <li class="sidebar-item">
                            <a class="sidebar-ling" href="../../updater">
                                <i class="bi bi-cloud-download"></i>
                                <span><?php echo $translations["updatepage"]; ?></span>
                                <?php if ($is_new_version_available): ?>
                                    <span class="sidebar-badge badge">
                                        <i class="bi bi-exclamation-circle"></i>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php
                    }
                    ?>
                    <li class="sidebar-item">
                        <a class="sidebar-ling" href="../../log">
                            <i class="bi bi-clock-history"></i>
                            <span><?php echo $translations["logpage"]; ?></span>
                        </a>
                    </li>
                </ul><br>
            </div>

            <br>
            <div class="col-sm-10">
                <div class="d-none topnav d-sm-inline-block">
                    <a href="https://gymoneglobal.com/discord" class="btn btn-primary mx-1" target="_blank"
                        rel="noopener noreferrer">
                        <i class="bi bi-question-circle"></i>
                        <?php echo $translations["support"]; ?>
                    </a>

                    <a href="https://gymoneglobal.com/docs" class="btn btn-danger" target="_blank"
                        rel="noopener noreferrer">
                        <i class="bi bi-journals"></i>
                        <?php echo $translations["docs"]; ?>
                    </a>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo $translations["logout"]; ?>
                    </button>
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <?php echo $alerts_html; ?>
                        <div class="card shadow">
                            <div class="card-body">

                                <?php if ($is_boss == 1): ?>

                                    <!-- FullCalendar CSS + JS -->
                                    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css"
                                        rel="stylesheet">
                                    <script
                                        src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
                                    <script
                                        src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.10/locales-all.global.min.js"></script>

                                    <style>
                                        #calendar {
                                            width: 100%;
                                        }

                                        .fc-timegrid-slot {
                                            height: 34px !important;
                                            /* 1 óra = 60px */
                                        }
                                    </style>

                                    <?php

                                    /*
                                     * A DB-ből jövő napnevek → FullCalendar napok
                                     * A te $days_of_week nyelvi nevei alapján
                                     */

                                    $dayIndex = [
                                        $translations["Mon"] => 1,
                                        $translations["Tue"] => 2,
                                        $translations["Wed"] => 3,
                                        $translations["Thu"] => 4,
                                        $translations["Fri"] => 5,
                                        $translations["Sat"] => 6,
                                        $translations["Sun"] => 7
                                    ];

                                    // aktuális hét hétfőjének dátuma
                                    $monday = date('Y-m-d', strtotime('monday this week'));

                                    // FullCalendar események
                                    $fc_events = [];

                                    foreach ($days_of_week as $day) {

                                        if (!isset($timetable[$day]))
                                            continue;

                                        foreach ($timetable[$day] as $event) {

                                            // melyik napon legyen
                                            $dow = $dayIndex[$day];
                                            $eventDate = date('Y-m-d', strtotime($monday . " + " . ($dow - 1) . " days"));

                                            // kezdés + befejezés
                                            $start = $eventDate . " " . $event["start_time"];
                                            $end = $eventDate . " " . $event["end_time"];

                                            $fc_events[] = [
                                                "title" => $event["event_name"] . (!empty($event["capacity"]) ? " (" . $event["capacity"] . ")" : ""),
                                                "start" => $start,
                                                "end" => $end,
                                                "color" => $event["color"]
                                            ];
                                        }
                                    }

                                    ?>

                                    <script>
                                        const timetableEvents = <?php echo json_encode($fc_events); ?>;
                                    </script>

                                    <div id="calendar"></div>

                                    <script>
                                        document.addEventListener('DOMContentLoaded', function () {
                                            let calendarEl = document.getElementById('calendar');

                                            let calendar = new FullCalendar.Calendar(calendarEl, {
                                                initialView: 'timeGridWeek',
                                                slotMinTime: "07:00:00",
                                                slotMaxTime: "20:00:00",
                                                allDaySlot: true,
                                                height: "auto",
                                                events: timetableEvents,
                                                firstDay: 1, // hétfő
                                                editable: false,
                                                eventOverlap: true,
                                                nowIndicator: true,
                                                locale: '<?php echo strtolower($lang_code); ?>',

                                                headerToolbar: {
                                                    left: '',
                                                    center: 'title',
                                                    right: ''
                                                }
                                            });

                                            calendar.render();
                                        });
                                    </script>

                                <?php else: ?>

                                    <?php echo $translations["dont-access"]; ?>

                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <?php
                    if ($is_boss == 1) {
                        ?>
                        <div class="col-md-6">
                            <div class="card shadow">
                                <div class="card-header">
                                    <?php echo $translations["add-timetable-event"]; ?>
                                </div>
                                <div class="card-body">
                                    <form action="" method="POST" class="mb-4">
                                        <input type="hidden" name="add_event" value="1">
                                        <div class="form-group">
                                            <label for="event_name"><?php echo $translations["eventname"]; ?></label>
                                            <input type="text" class="form-control" id="event_name" name="event_name"
                                                required>
                                        </div>
                                        <div class="form-group">
                                            <label for="start_time"><?php echo $translations["starttime"]; ?></label>
                                            <input type="time" class="form-control" id="start_time" name="start_time"
                                                required>
                                        </div>
                                        <div class="form-group">
                                            <label for="end_time"><?php echo $translations["endtime"]; ?></label>
                                            <input type="time" class="form-control" id="end_time" name="end_time" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="day_of_week"><?php echo $translations["witchday"]; ?></label>
                                            <select class="form-control" id="day_of_week" name="day_of_week" required>
                                                <?php foreach ($days_of_week as $day): ?>
                                                    <option value="<?= htmlspecialchars($day) ?>"><?= htmlspecialchars($day) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="program_id"><?php echo $translations["program"] ?? "Programa"; ?></label>
                                            <select class="form-control" id="program_id" name="program_id">
                                                <option value="">-</option>
                                                <?php foreach ($programs as $program): ?>
                                                    <option value="<?= (int) $program['id'] ?>"><?= htmlspecialchars($program['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="capacity"><?php echo $translations["classcapacity"] ?? "Cupo"; ?></label>
                                            <input type="number" min="1" class="form-control" id="capacity" name="capacity" placeholder="6">
                                        </div>
                                        <div class="checkbox">
                                            <label><input type="checkbox" name="is_evaluation" value="1">
                                                <?php echo $translations["evaluationclass"] ?? "Clase de evaluación inicial"; ?></label>
                                        </div>
                                        <div class="form-group">
                                            <label for="color">Color</label>
                                            <input type="color" class="form-control" id="color" name="color" value="#90278e"
                                                required>
                                        </div>
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i>
                                            <?php echo $translations["add"]; ?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card shadow">
                                <div class="card-body">
                                    <form action="" method="POST" class="mb-4">
                                        <input type="hidden" name="delete_event" value="1">
                                        <div class="form-group">
                                            <label for="event_id"><?php echo $translations["eventdelete"]; ?></label>
                                            <select class="form-control" id="event_id" name="event_id" required>
                                                <?php foreach ($allEvents as $event): ?>
                                                    <option value="<?= (int) $event['id'] ?>">
                                                        <?= htmlspecialchars($event['event_name'] . ' · ' . $event['day_of_week'] . ' ' . substr($event['start_time'], 0, 5) . '-' . substr($event['end_time'], 0, 5)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i>
                                            <?php echo $translations["delete"]; ?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php
                    } else {
                        echo $translations["dont-access"];
                    }
                    ?>


                </div>
            </div>
        </div>
    </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" style="margin-top: 100px;">
            <div class="modal-content" style="border: none; box-shadow: 0 0 40px rgba(0,0,0,.2);">
                <div class="modal-body text-center" style="padding: 40px;">

                    <div style="margin-bottom: 25px;">
                        <div style="width: 80px; height: 80px; margin: 0 auto;
                                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                                border-radius: 50%;
                                display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-box-arrow-right" style="color: #fff; font-size: 40px;"></i>
                        </div>
                    </div>

                    <h4 style="font-weight: bold; margin-bottom: 15px;">
                        <p><?php echo $translations["exit-modal"]; ?></p>
                    </h4>

                    <div class="text-center">
                        <a type="button" class="btn btn-default" data-dismiss="modal"
                            style="padding: 8px 25px; margin-right: 10px;">
                            <i class="bi bi-x-circle" style="margin-right: 5px;"></i>
                            <?php echo $translations["not-yet"]; ?>
                        </a>

                        <a href="../../logout.php" type="button" class="btn btn-danger" style="padding: 8px 25px;">
                            <i class="bi bi-check-circle" style="margin-right: 5px;"></i>
                            <?php echo $translations["confirm"]; ?>
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS! -->
    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"
        integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa"
        crossorigin="anonymous"></script>
</body>

</html>