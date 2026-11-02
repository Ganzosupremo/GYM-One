<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

$userid = $_SESSION['adminuser'];

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

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
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

$alerts_html = '';

$sql = "SELECT userid, Firstname, Lastname, username, is_boss FROM workers";
$result = $conn->query($sql);

$dayNames = [
    1 => $translations["Mon"],
    2 => $translations["Tue"],
    3 => $translations["Wed"],
    4 => $translations["Thu"],
    5 => $translations["Fri"],
    6 => $translations["Sat"],
    7 => $translations["Sun"]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_boss == 1) {

    if (isset($_POST['weekly_save'])) {

        $old_hours = [];
        $res = $conn->query("SELECT * FROM opening_hours ORDER BY day");
        while ($r = $res->fetch_assoc()) {
            $old_hours[$r['day']] = [
                'open' => $r['open_time'],
                'close' => $r['close_time']
            ];
        }

        foreach ($dayNames as $day => $n) {
            $isClosed = isset($_POST['closed'][$day]);
            $open = $isClosed ? null : ($_POST['open_time'][$day] ?? null);
            $close = $isClosed ? null : ($_POST['close_time'][$day] ?? null);

            $stmt = $conn->prepare("
                UPDATE opening_hours 
                SET open_time=?, close_time=? 
                WHERE day=?
            ");
            $stmt->bind_param("ssi", $open, $close, $day);
            $stmt->execute();
            $stmt->close();
        }

        $changes = [];
        foreach ($dayNames as $day => $day_name) {
            $isClosed = isset($_POST['closed'][$day]);
            $new_open = $isClosed ? null : ($_POST['open_time'][$day] ?? null);
            $new_close = $isClosed ? null : ($_POST['close_time'][$day] ?? null);

            $old_open = $old_hours[$day]['open'];
            $old_close = $old_hours[$day]['close'];

            if ($old_open !== $new_open || $old_close !== $new_close) {
                $old_text = $old_open && $old_close ? "$old_open - $old_close" : $translations["closed"];
                $new_text = $new_open && $new_close ? "$new_open - $new_close" : $translations["closed"];

                $changes["{$day_name}_old"] = $old_text;
                $changes["{$day_name}_new"] = $new_text;
            }
        }

        if (!empty($changes)) {
            $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) VALUES (?, ?, ?, ?, NOW())";
            $stmt_log = $conn->prepare($log_sql);
            $action = $translations["log_openhours_week_edit"];
            $color = "info";
            $details = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $stmt_log->bind_param("isss", $userid, $action, $color, $details);
            $stmt_log->execute();
            $stmt_log->close();
        }
    }

    if (isset($_POST['exception_save'])) {
        $date = $_POST['exception_date'];
        $isClosed = isset($_POST['exception_closed']) ? 1 : 0;
        $open = $isClosed ? null : ($_POST['exception_open'] ?: null);
        $close = $isClosed ? null : ($_POST['exception_close'] ?: null);

        $check_sql = "SELECT open_time, close_time, is_closed FROM opening_hours_exceptions WHERE date = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $existing = $check_result->fetch_assoc();
        $check_stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO opening_hours_exceptions
            (date, open_time, close_time, is_closed)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                open_time=VALUES(open_time),
                close_time=VALUES(close_time),
                is_closed=VALUES(is_closed)
        ");
        $stmt->bind_param("sssd", $date, $open, $close, $isClosed);
        $stmt->execute();
        $stmt->close();

        $changes = [];

        if ($existing) {
            $old_text = $existing['is_closed'] ? $translations["closed"] : ($existing['open_time'] . " - " . $existing['close_time']);
            $new_text = $isClosed ? $translations["closed"] : ($open . " - " . $close);

            $changes['date'] = $date;
            $changes['exception_old'] = $old_text;
            $changes['exception_new'] = $new_text;

            $log_action = $translations["log_exception_edit"] . ": " . $date;
        } else {
            $new_text = $isClosed ? $translations["closed"] : ($open . " - " . $close);

            $changes['date'] = $date;
            $changes['exception_new'] = $new_text;

            $log_action = $translations["log_exception_new"] . ": " . $date;
        }

        $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) VALUES (?, ?, ?, ?, NOW())";
        $stmt_log = $conn->prepare($log_sql);
        $color = "warning";
        $details = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $stmt_log->bind_param("isss", $userid, $log_action, $color, $details);
        $stmt_log->execute();
        $stmt_log->close();
    }

    if (isset($_POST['exception_delete'])) {
        $delete_date = $_POST['exception_delete_date'];

        $get_sql = "SELECT open_time, close_time, is_closed FROM opening_hours_exceptions WHERE date = ?";
        $get_stmt = $conn->prepare($get_sql);
        $get_stmt->bind_param("s", $delete_date);
        $get_stmt->execute();
        $old_result = $get_stmt->get_result();
        $old_exception = $old_result->fetch_assoc();
        $get_stmt->close();

        $stmt = $conn->prepare(
            "DELETE FROM opening_hours_exceptions WHERE date=?"
        );
        $stmt->bind_param("s", $delete_date);
        $stmt->execute();
        $stmt->close();

        if ($old_exception) {
            $old_text = $old_exception['is_closed'] ? $translations["closed"] : ($old_exception['open_time'] . " - " . $old_exception['close_time']);

            $changes = [
                'date' => $delete_date,
                'deleted_exception' => $old_text
            ];

            $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) VALUES (?, ?, ?, ?, NOW())";
            $stmt_log = $conn->prepare($log_sql);
            $action = $translations["log_exception_delete"] . ": " . $delete_date;
            $color = "danger";
            $details = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $stmt_log->bind_param("isss", $userid, $action, $color, $details);
            $stmt_log->execute();
            $stmt_log->close();
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$days = [];
$res = $conn->query("SELECT * FROM opening_hours ORDER BY day");
while ($r = $res->fetch_assoc())
    $days[] = $r;

$exceptions = [];
$res = $conn->query("SELECT * FROM opening_hours_exceptions ORDER BY date");
while ($r = $res->fetch_assoc())
    $exceptions[] = $r;

$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

$conn->close();
?>


<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $translations["dashboard"]; ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                    <li><a href="../../sankris/"><i class="bi bi-people-fill"></i> Sankris: familias y cobros</a></li>
                    <li><a href="../../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a>
                    </li>
                    <li><a href="../../statistics"><i class="bi bi-bar-chart"></i>
                            <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../../boss/sell"><i class="bi bi-shop"></i>
                            <?php echo $translations["sellpage"]; ?></a></li>
                    <li><a href="../../invoices"><i class="bi bi-receipt"></i>
                            <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown active">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i>
                                <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li><a href="../../boss/packages"><?php echo $translations["packagepage"]; ?></a></li>
                                <li class="active"><a href="#"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../../boss/chroom"><?php echo $translations["chroompage"]; ?></a></li>
                                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i>
                            <?php echo $translations["ticketspage"]; ?></a></li>
                    <li><a href="../../trainers/timetable"><i class="bi bi-calendar-event"></i>
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
                        <a class="sidebar-link" href="../../sankris/">
                            <i class="bi bi-people-fill"></i> Sankris: familias y cobros
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
                        <li class="sidebar-item active">
                            <a class="sidebar-link" href="#">
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
                        <a class="sidebar-ling" href="../../shop/tickets">
                            <i class="bi bi-ticket"></i>
                            <span><?php echo $translations["ticketspage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-header">
                        <?php echo $translations["trainersclass"]; ?>
                    </li>
                    <li><a class="sidebar-link" href="../../trainers/timetable">
                            <i class="bi bi-calendar-event"></i>
                            <span><?php echo $translations["timetable"]; ?></span>
                        </a></li>
                    <li><a class="sidebar-link" href="../../trainers/personal">
                            <i class="bi bi-award"></i>
                            <span><?php echo $translations["trainers"]; ?></span>
                        </a></li>
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

                        <?= $alerts_html ?? '' ?>

                        <div class="card shadow mb-4">
                            <div class="card-body">

                                <?php if ($is_boss == 1): ?>

                                    <h3 class="mb-3"><?php echo $translations["weekly-opentime"]; ?></h3>

                                    <form method="post" action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                        <input type="hidden" name="weekly_save" value="1">

                                        <table class="table table-bordered align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th><?= $translations["day"]; ?></th>
                                                    <th><?= $translations["opentime"]; ?></th>
                                                    <th><?= $translations["closetime"]; ?></th>
                                                    <th class="text-center"><?= $translations["checkbox-closed"]; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($days as $day): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($dayNames[$day['day']]) ?></td>

                                                        <td>
                                                            <input type="time" class="form-control"
                                                                name="open_time[<?= $day['day'] ?>]"
                                                                value="<?= htmlspecialchars($day['open_time']) ?>"
                                                                <?= is_null($day['open_time']) ? 'disabled' : '' ?>>
                                                        </td>

                                                        <td>
                                                            <input type="time" class="form-control"
                                                                name="close_time[<?= $day['day'] ?>]"
                                                                value="<?= htmlspecialchars($day['close_time']) ?>"
                                                                <?= is_null($day['open_time']) ? 'disabled' : '' ?>>
                                                        </td>

                                                        <td class="text-center">
                                                            <input type="checkbox" name="closed[<?= $day['day'] ?>]" value="1"
                                                                <?= is_null($day['open_time']) ? 'checked' : '' ?>
                                                                onclick="toggleDay(this, <?= $day['day'] ?>)">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>

                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> <?= $translations["save"]; ?>
                                        </button>
                                    </form>

                                    <hr class="my-4">

                                    <h3 class="mb-3"><?php echo $translations["special-opentime"]; ?></h3>

                                    <form method="post" class="row g-2 mb-4">
                                        <div class="col-md-3">
                                            <input type="date" name="exception_date" required class="form-control">
                                        </div>

                                        <div class="col-md-3">
                                            <input type="time" name="exception_open" class="form-control">
                                        </div>

                                        <div class="col-md-3">
                                            <input type="time" name="exception_close" class="form-control">
                                        </div>

                                        <div class="col-md-2 d-flex align-items-center">
                                            <input type="checkbox" name="exception_closed" id="exception_closed"
                                                onclick="toggleException(this)">
                                            <label for="exception_closed"
                                                class="ms-2 mb-0"><?= $translations["checkbox-closed"]; ?></label>
                                        </div>

                                        <div class="col-md-12 mt-2">
                                            <button name="exception_save" class="btn btn-success">
                                                <i class="bi bi-plus-circle"></i> <?php echo $translations["add"]; ?>
                                            </button>
                                        </div>
                                    </form>

                                    <table class="table table-striped table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th><?php echo $translations["date-log"]; ?></th>
                                                <th><?php echo $translations["openhourspage"]; ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($exceptions as $e): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($e['date']) ?></td>
                                                    <td>
                                                        <?= $e['is_closed']
                                                            ? '<strong class="text-danger">' . htmlspecialchars($translations["closed"]) . '</strong>'
                                                            : htmlspecialchars(
                                                                (new DateTime($e['open_time']))->format('H:i') . ' – ' .
                                                                (new DateTime($e['close_time']))->format('H:i')
                                                            )
                                                            ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <form method="post">
                                                            <input type="hidden" name="exception_delete_date"
                                                                value="<?= htmlspecialchars($e['date']) ?>">
                                                            <button name="exception_delete" class="btn btn-danger btn-sm">
                                                                <?php echo $translations["delete"]; ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                <?php else: ?>
                                    <div class="alert alert-danger">
                                        <?= $translations["dont-access"]; ?>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    function toggleDay(checkbox, day) {
                        const openInput = document.querySelector(`input[name="open_time[${day}]"]`);
                        const closeInput = document.querySelector(`input[name="close_time[${day}]"]`);

                        if (checkbox.checked) {
                            openInput.disabled = true;
                            closeInput.disabled = true;
                            openInput.value = '';
                            closeInput.value = '';
                        } else {
                            openInput.disabled = false;
                            closeInput.disabled = false;
                        }
                    }

                    function toggleException(checkbox) {
                        const open = document.querySelector('input[name="exception_open"]');
                        const close = document.querySelector('input[name="exception_close"]');

                        if (checkbox.checked) {
                            open.disabled = true;
                            close.disabled = true;
                            open.value = '';
                            close.value = '';
                        } else {
                            open.disabled = false;
                            close.disabled = false;
                        }
                    }
                </script>

            </div>
        </div>
    </div>
    <!-- EXIT MODAL -->
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
    <script>
        function toggleTimeFields(checkbox, dayNumber) {
            const openTime = document.querySelector(`input[name="open_time[${dayNumber}]"]`);
            const closeTime = document.querySelector(`input[name="close_time[${dayNumber}]"]`);
            if (checkbox.checked) {
                openTime.value = '';
                closeTime.value = '';
                openTime.disabled = true;
                closeTime.disabled = true;
            } else {
                openTime.disabled = false;
                closeTime.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="closed"]');
            checkboxes.forEach(checkbox => {
                const dayNumber = checkbox.name.match(/\d+/)[0];
                toggleTimeFields(checkbox, dayNumber);
            });
        });
    </script>
    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>