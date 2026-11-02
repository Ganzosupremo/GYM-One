<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

$userid = $_SESSION['adminuser'];

$alerts_html = "";

function read_env_file($file_path)
{
    if (!file_exists($file_path)) {
        die("A .env fájl nem található: $file_path");
    }
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];

    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line, 2);
        if (count($line_parts) == 2) {
            $key = trim($line_parts[0]);
            $value = trim($line_parts[1]);
            $env_data[$key] = $value;
        }
    }

    return $env_data;
}

$env_data = read_env_file('../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';

$lang = $lang_code;
$langDir = __DIR__ . "/../../assets/lang/";
$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

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

// Fetch all logs for JavaScript filtering
$sql = "SELECT logs.id, logs.userid, workers.username as username, logs.action, logs.actioncolor, logs.time, logs.details
        FROM logs 
        LEFT JOIN workers ON logs.userid = workers.userid 
        ORDER BY logs.time DESC";
$result = $conn->query($sql);

$logs = [];
while ($row = $result->fetch_assoc()) {
    $row['details'] = $row['details'] ? json_decode($row['details'], true) : [];
    if (!$row['username']) {
        $row['username'] = $translations["log_user_system"] ?? "System";
    }
    $logs[] = $row;
}

if (isset($_POST['delete_old_logs'])) {
    $date_limit = date('Y-m-d', strtotime('-15 days'));

    $sql = "DELETE FROM logs WHERE time < ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date_limit);
    if ($stmt->execute()) {
        $delete_message = $translations["success-log-delete"];
        $action = $translations['success-log-delete'];
        $actioncolor = 'warning';
        $sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $userid, $action, $actioncolor);
        $stmt->execute();
        header("Refresh:2");
    } else {
        $delete_message = "An error occurred during the deletion: " . $conn->error;
        header("Refresh:2");
    }
}

$username = 'mayerbalintdev';
$repo = 'GYM-One';
$current_version = $version;

$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$latest_version = curl_exec($ch);
curl_close($ch);

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_code, ENT_QUOTES, 'UTF-8'); ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($translations["dashboard"], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="../../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        .log-details {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #143df7;
        }
        .log-details.show {
            display: block;
        }
        .detail-row {
            padding: 5px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            display: inline-block;
            min-width: 150px;
        }
        .detail-value {
            color: #212529;
        }
        .log-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .log-row:hover {
            background-color: #f8f9fa;
        }
        .expand-icon {
            transition: transform 0.2s;
        }
        .expand-icon.rotated {
            transform: rotate(90deg);
        }
        .filter-section {
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#"><img src="../../assets/img/brand/logo-white.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../dashboard"><i class="bi bi-speedometer"></i>
                            <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li><a href="../statistics"><i class="bi bi-bar-chart"></i>
                            <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../boss/sell"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a>
                    </li>
                    <li><a href="../invoices"><i class="bi bi-receipt"></i>
                            <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i>
                                <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li><a href="../boss/packages"><?php echo $translations["packagepage"]; ?></a></li>
                                <li><a href="../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../boss/chroom"><?php echo $translations["chroompage"]; ?></a></li>
                                <li><a href="../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../shop/tickets"><i class="bi bi-ticket"></i>
                            <?php echo $translations["ticketspage"]; ?></a></li>
                    <li><a href="../trainers/timetable"><i class="bi bi-calendar-event"></i>
                            <?php echo $translations["timetable"]; ?></a></li>
                    <li><a href="../trainers/personal"><i class="bi bi-award"></i>
                            <?php echo $translations["trainers"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../updater"><i class="bi bi-cloud-download"></i>
                                <?php echo $translations["updatepage"]; ?>
                                <?php if ($is_new_version_available): ?>
                                    <span class="badge badge-warning"><i class="bi bi-exclamation-circle"></i></span>
                                <?php endif; ?>
                            </a></li>
                    <?php } ?>
                    <li class="active"><a href="#"><i class="bi bi-clock-history"></i>
                            <?php echo $translations["logpage"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../assets/img/brand/logo-white.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?> - <?php echo $version; ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../dashboard/">
                            <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../users">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../statistics">
                            <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../boss/sell">
                            <i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="../invoices/" class="sidebar-link">
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
                            <a class="sidebar-link" href="../boss/mainsettings">
                                <i class="bi bi-gear"></i>
                                <span><?php echo $translations["businesspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/workers">
                                <i class="bi bi-people"></i>
                                <span><?php echo $translations["workers"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/packages">
                                <i class="bi bi-box-seam"></i>
                                <span><?php echo $translations["packagepage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/hours">
                                <i class="bi bi-clock"></i>
                                <span><?php echo $translations["openhourspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/smtp">
                                <i class="bi bi-envelope-at"></i>
                                <span><?php echo $translations["mailpage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/chroom">
                                <i class="bi bi-duffle"></i>
                                <span><?php echo $translations["chroompage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/rule">
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
                        <a class="sidebar-link" href="../boss/sell">
                            <i class="bi bi-ticket"></i>
                            <span><?php echo $translations["ticketspage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-header">
                        <?php echo $translations["trainersclass"]; ?>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../trainers/timetable">
                            <i class="bi bi-calendar-event"></i>
                            <span><?php echo $translations["timetable"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../trainers/personal">
                            <i class="bi bi-award"></i>
                            <span><?php echo $translations["trainers"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-header"><?php echo $translations["other-header"]; ?></li>
                    <?php
                    if ($is_boss === 1) {
                        ?>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../updater">
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
                    <li class="sidebar-item active">
                        <a class="sidebar-ling" href="#">
                            <i class="bi bi-clock-history"></i>
                            <span><?php echo $translations["logpage"]; ?></span>
                        </a>
                    </li>
                </ul><br>
            </div>
            <div class="col-sm-10">
                <div class="d-none topnav d-sm-inline-block">
                    <a href="https://gymoneglobal.com/discord" class="btn btn-primary mx-1" target="_blank"
                        rel="noopener noreferrer">
                        <i class="bi bi-question-circle"></i>
                        <?php echo htmlspecialchars($translations["support"], ENT_QUOTES, 'UTF-8'); ?>
                    </a>

                    <a href="https://gymoneglobal.com/docs" class="btn btn-danger" target="_blank"
                        rel="noopener noreferrer">
                        <i class="bi bi-journals"></i>
                        <?php echo htmlspecialchars($translations["docs"], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo htmlspecialchars($translations["logout"], ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <?php echo $alerts_html; ?>
                        <div class="card shadow">
                            <div class="card-body">
                                <?php if (isset($delete_message)): ?>
                                    <div class="alert alert-info">
                                        <?php echo htmlspecialchars($delete_message, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                
                                <!-- Filter Section -->
                                <div class="filter-section">
                                    <div class="panel panel-default">
                                        <div class="panel-heading">
                                            <h3 class="panel-title"><i class="bi bi-funnel"></i> <?php echo htmlspecialchars($translations["filters"], ENT_QUOTES, 'UTF-8'); ?></h3>
                                        </div>
                                        <div class="panel-body">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label><?php echo $translations["username"];?></label>
                                                        <input type="text" class="form-control" id="userFilter" placeholder="<?php echo $translations["searchbyuser"];?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label><?php echo $translations["type"];?></label>
                                                        <select class="form-control" id="typeFilter">
                                                            <option value=""><?php echo $translations["alloption"];?></option>
                                                            <option value="success"><?php echo $translations["successoption"];?></option>
                                                            <option value="warning"><?php echo $translations["warningoption"];?></option>
                                                            <option value="danger"><?php echo $translations["dangeroption"];?></option>
                                                            <option value="info"><?php echo $translations["infooption"];?></option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label><?php echo $translations["from-date"];?></label>
                                                        <input type="date" class="form-control" id="dateFrom">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label><?php echo $translations["to-date"];?></label>
                                                        <input type="date" class="form-control" id="dateTo">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th><?php echo htmlspecialchars($translations["username"], ENT_QUOTES, 'UTF-8'); ?>
                                                </th>
                                                <th><?php echo htmlspecialchars($translations["action-log"], ENT_QUOTES, 'UTF-8'); ?>
                                                </th>
                                                <th><?php echo htmlspecialchars($translations["date-log"], ENT_QUOTES, 'UTF-8'); ?>
                                                </th>
                                                <th><?php echo $translations["details"]; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="logsTableBody">
                                        </tbody>
                                    </table>
                                </div>
                                <div id="noLogsMessage" class="text-center" style="display: none; padding: 20px;">
                                    <p class="text-muted">
                                        <?php echo htmlspecialchars($translations["notexist-log"], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                </div>
                                <form method="POST">
                                    <button type="submit" name="delete_old_logs" class="btn btn-danger mb-3">
                                        <i class="bi bi-trash"></i>
                                        <?php echo htmlspecialchars($translations["deletelog"], ENT_QUOTES, 'UTF-8'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
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

                        <a href="../logout.php" type="button" class="btn btn-danger" style="padding: 8px 25px;">
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
        // All logs data from PHP
        let allLogs = <?php echo json_encode($logs, JSON_UNESCAPED_UNICODE); ?>;

        function formatTime(timeString) {
            const date = new Date(timeString);
            const now = new Date();
            const diff = now - date;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(diff / 3600000);
            const days = Math.floor(diff / 86400000);
            
            if (minutes < 60) return minutes + ' minutes ago';
            if (hours < 24) return hours + ' hours ago';
            if (days < 7) return days + ' days ago';
            
            return timeString.split(' ')[0];
        }

        function renderLogDetails(details) {
            if (!details || Object.keys(details).length === 0) {
                return '<p class="text-muted">No details available</p>';
            }
            
            let html = '';
            for (const [key, value] of Object.entries(details)) {
                html += `
                    <div class="detail-row">
                        <span class="detail-label">${key}:</span>
                        <span class="detail-value">${value}</span>
                    </div>
                `;
            }
            return html;
        }

        function toggleDetails(logId) {
            const detailsDiv = document.getElementById('details-' + logId);
            const icon = document.getElementById('icon-' + logId);
            
            if (detailsDiv.classList.contains('show')) {
                detailsDiv.classList.remove('show');
                icon.classList.remove('rotated');
            } else {
                detailsDiv.classList.add('show');
                icon.classList.add('rotated');
            }
        }

        function renderLogs(logs) {
            const tbody = document.getElementById('logsTableBody');
            const noLogsMessage = document.getElementById('noLogsMessage');
            
            if (logs.length === 0) {
                tbody.innerHTML = '';
                noLogsMessage.style.display = 'block';
                return;
            }
            
            noLogsMessage.style.display = 'none';
            
            tbody.innerHTML = logs.map(log => `
                <tr class="log-row" onclick="toggleDetails(${log.id})">
                    <td><b>${log.id}</b></td>
                    <td>${log.username} (ID: ${log.userid})</td>
                    <td class="text-${log.actioncolor}"><p>${log.action}</p></td>
                    <td>${log.time}</td>
                    <td>
                        <i class="bi bi-chevron-right expand-icon" id="icon-${log.id}"></i>
                    </td>
                </tr>
                <tr>
                    <td colspan="5" style="padding: 0;">
                        <div class="log-details" id="details-${log.id}">
                            ${renderLogDetails(log.details)}
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function applyFilters() {
            const userFilter = document.getElementById('userFilter').value.toLowerCase();
            const typeFilter = document.getElementById('typeFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            const filtered = allLogs.filter(log => {
                // User filter
                const userMatch = !userFilter || 
                    log.username.toLowerCase().includes(userFilter) || 
                    log.userid.toString().includes(userFilter);

                // Type filter
                const typeMatch = !typeFilter || log.actioncolor === typeFilter;

                // Date filters
                const logDate = new Date(log.time);
                const fromMatch = !dateFrom || logDate >= new Date(dateFrom);
                const toMatch = !dateTo || logDate <= new Date(dateTo + ' 23:59:59');

                return userMatch && typeMatch && fromMatch && toMatch;
            });

            renderLogs(filtered);
        }

        document.getElementById('userFilter').addEventListener('input', applyFilters);
        document.getElementById('typeFilter').addEventListener('change', applyFilters);
        document.getElementById('dateFrom').addEventListener('change', applyFilters);
        document.getElementById('dateTo').addEventListener('change', applyFilters);

        // Initial render
        renderLogs(allLogs);
    </script>
    <script src="../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"
        integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa"
        crossorigin="anonymous"></script>
</body>

</html>