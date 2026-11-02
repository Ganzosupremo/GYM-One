<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
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
        $fields = [
            'BUSINESS_NAME' => 'business_name',
            'STREET' => 'street',
            'COUNTRY' => 'country',
            'CITY' => 'city',
            'HOUSE_NUMBER' => 'house_number',
            'DESCRIPTION' => 'description',
            'META_KEY' => 'metakey',
            'LANG_CODE' => 'lang_code',
            'CURRENCY' => 'currency',
            'GOOGLE_KEY' => 'gkey',
            'CAPACITY' => 'capacity',
            'PHONE_NO' => 'phone_no',
            'ABOUT' => 'about'
        ];

        $new_data = [];
        foreach ($fields as $env_key => $post_key) {
            $new_data[$env_key] = $_POST[$post_key] ?? '';
        }

        $old_data = [];
        foreach ($fields as $env_key => $post_key) {
            $old_data[$env_key] = $env_data[$env_key] ?? '';
        }

        $changes = [];
        foreach ($fields as $env_key => $post_key) {
            if ($old_data[$env_key] !== $new_data[$env_key]) {
                $changes["{$env_key}_old"] = $old_data[$env_key];
                $changes["{$env_key}_new"] = $new_data[$env_key];
            }
        }

        foreach ($fields as $env_key => $post_key) {
            $env_data[$env_key] = $new_data[$env_key];
        }

        $env_content = '';
        foreach ($env_data as $key => $value) {
            $env_content .= "$key=$value\n";
        }

        if (file_put_contents('../../../.env', $env_content) !== false) {
            if (!empty($changes)) {
                $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) VALUES (?, ?, ?, ?, NOW())";
                $stmt_log = $conn->prepare($log_sql);
                $action = $translations['success-update-env-main'];
                $color = "info";
                $details = json_encode($changes, JSON_UNESCAPED_UNICODE);
                $stmt_log->bind_param("isss", $userid, $action, $color, $details);
                $stmt_log->execute();
                $stmt_log->close();
            }

            $alerts_html .= "<div class='alert alert-success'>{$translations["success-update"]}</div>";
            header("Refresh:2");
        } else {
            $alerts_html .= "<div class='alert alert-danger'>{$translations["error-env"]}</div>";
            header("Refresh:2");
        }
    }
}

function handleFileUpload($fileInputName, $targetFileName, $uploadDir, $submitName)
{
    if (isset($_POST[$submitName]) && isset($_FILES[$fileInputName])) {
        $file_type = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        $allowed_types = array('png', 'jpg', 'jpeg');
        if (!in_array($file_type, $allowed_types)) {
            return;
        }
        if ($_FILES[$fileInputName]['size'] > 4000000) {
            return;
        }

        $file_tmp = $_FILES[$fileInputName]['tmp_name'];
        $target_file = $uploadDir . $targetFileName;

        if (move_uploaded_file($file_tmp, $target_file)) {
            header("Refresh:1");
            exit;
        }
    }
}

$upload_dir = '../../../assets/img/brand/';

handleFileUpload('logoFile', 'logo.png', $upload_dir, 'uploadLogo');
handleFileUpload('backgroundFile', 'background.png', $upload_dir, 'uploadBackground');
handleFileUpload('faviconFile', 'favicon.png', $upload_dir, 'uploadFavicon');


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
                                <li class="active"><a href="#"><?php echo $translations["businesspage"]; ?></a></li>
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
                        <li class="sidebar-item active">
                            <a class="sidebar-link" href="#">
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
                        <?php echo $alerts_html; ?>
                        <div class="card shadow">
                            <div class="card-body">

                                <?php
                                if ($is_boss == 1) {
                                    ?>
                                    <form method="POST">
                                        <div class="form-row">
                                            <div class="form-group col-md-6">
                                                <label for="business_name"><?php echo $translations["gym-name"]; ?>:</label>
                                                <input type="text" class="form-control" id="business_name"
                                                    name="business_name" required
                                                    value="<?= htmlspecialchars($env_data['BUSINESS_NAME'] ?? '') ?>">
                                            </div>
                                            <div class="form-group col-md-3">
                                                <label for="lang_code"><?php echo $translations["lang"] ?>:</label>
                                                <select class="form-control" id="lang_code" name="lang_code">
                                                    <option value="HU" <?= ($env_data['LANG_CODE'] ?? '') == 'HU' ? 'selected' : '' ?>><?php echo $translations["HU"]; ?></option>
                                                    <option value="ES" <?= ($env_data['LANG_CODE'] ?? '') == 'ES' ? 'selected' : '' ?>><?php echo $translations["ES"]; ?></option>
                                                    <option value="GB" <?= ($env_data['LANG_CODE'] ?? '') == 'GB' ? 'selected' : '' ?>><?php echo $translations["GB"]; ?></option>
                                                    <option value="DE" <?= ($env_data['LANG_CODE'] ?? '') == 'DE' ? 'selected' : '' ?>><?php echo $translations["DE"]; ?></option>
                                                    <option value="TR" <?= ($env_data['LANG_CODE'] ?? '') == 'TR' ? 'selected' : '' ?>><?php echo $translations["TR"]; ?></option>

                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="alert alert-danger">
                                                    <?php echo $translations["restartserver"]; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group col-md-8">
                                                <label
                                                    for="description"><?php echo $translations["websitedescription"]; ?>:</label>
                                                <input type="text" class="form-control" id="description" name="description"
                                                    required minlength="20"
                                                    value="<?= htmlspecialchars($env_data['DESCRIPTION'] ?? '') ?>">
                                            </div>
                                            <div class="form-group col-md-4">
                                                <label for="capacity"><?php echo $translations["capacityenv"]; ?>:</label>
                                                <input type="number" class="form-control" id="capacity" name="capacity"
                                                    min="10" required
                                                    value="<?= htmlspecialchars($env_data['CAPACITY'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group col-md-12">
                                                <label for="metakey"><?php echo $translations["metakeys"]; ?>:</label>
                                                <input type="text" class="form-control" id="metakey" name="metakey"
                                                    value="<?= htmlspecialchars($env_data['META_KEY'] ?? '') ?>">
                                                <small id="keywordsInfo"
                                                    class="form-text"><?php echo $translations["metakeys-separeate"]; ?>
                                                    <code>(,)</code></small>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group col-md-4">
                                                <label for="country"><?php echo $translations["country"]; ?>:</label>
                                                <input type="text" class="form-control" id="country" name="country" required
                                                    value="<?= htmlspecialchars($env_data['COUNTRY'] ?? '') ?>">
                                            </div>
                                            <div class="form-group col-md-3">
                                                <label for="city"><?php echo $translations["city"]; ?>:</label>
                                                <input type="text" class="form-control" id="city" name="city" required
                                                    value="<?= htmlspecialchars($env_data['CITY'] ?? '') ?>">
                                            </div>
                                            <div class="form-group col-md-3">
                                                <label for="street"><?php echo $translations["street"]; ?>:</label>
                                                <input type="text" class="form-control" id="street" name="street" required
                                                    value="<?= htmlspecialchars($env_data['STREET'] ?? '') ?>">
                                            </div>
                                            <div class="form-group col-md-2">
                                                <label for="house_number"><?php echo $translations["hause-no"]; ?>:</label>
                                                <input type="number" class="form-control" id="house_number" required
                                                    name="house_number"
                                                    value="<?= htmlspecialchars($env_data['HOUSE_NUMBER'] ?? '') ?>">
                                            </div>
                                            <div class="form-row">
                                                <div class="form-group col-md-4">
                                                    <label for="currency"><?php echo $translations["currency"]; ?>:</label>
                                                    <input type="text" class="form-control" id="currency" name="currency"
                                                        required
                                                        value="<?= htmlspecialchars($env_data['CURRENCY'] ?? '') ?>">
                                                </div>
                                                <div class="form-group col-md-4">
                                                    <label for="phone_no"><?php echo $translations["fno"]; ?>:</label>
                                                    <input type="tel" class="form-control" id="phone_no" name="phone_no"
                                                        required
                                                        value="<?= htmlspecialchars($env_data['PHONE_NO'] ?? '') ?>">
                                                </div>
                                                <div class="form-group col-md-4">
                                                    <label
                                                        for="gkey"><?php echo $translations["googletrakckey"]; ?>:</label>
                                                    <input type="text" class="form-control" id="gkey" name="gkey"
                                                        value="<?= htmlspecialchars($env_data['GOOGLE_KEY'] ?? '') ?>">
                                                    <small><?php echo $translations["googlekeyonly"]; ?></small>
                                                </div>
                                            </div>
                                            <div class="form-row">
                                                <div class="form-group col-sm-12">
                                                    <label for="about"><?php echo $translations["aboutdesc"]; ?>:</label>
                                                    <textarea class="form-control" id="about" name="about" required
                                                        minlength="50"
                                                        rows="3"><?= htmlspecialchars($env_data['ABOUT'] ?? '') ?></textarea>
                                                </div>
                                            </div>

                                        </div>
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i>
                                            <?php echo $translations["save"]; ?></button>

                                    </form>
                                    <?php
                                } else {
                                    echo $translations["dont-access"];
                                }

                                ?>

                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <?php
                        if ($is_boss == 1) {
                            ?>
                            <div class="col-md-4">
                                <div class="card shadow">
                                    <div class="card-header">
                                        <?php echo $translations["logo-upload"]; ?>
                                    </div>
                                    <div class="card-body">
                                        <form action="" method="post" enctype="multipart/form-data">
                                            <div class="form-group">
                                                <label
                                                    for="logoFile"><?php echo $translations["select-upload-logo"]; ?></label>
                                                <input type="file" class="form-control-file" id="logoFile" name="logoFile"
                                                    accept="image/png, image/jpeg">
                                            </div>
                                            <button type="submit" class="btn btn-primary"
                                                name="uploadLogo"><?php echo $translations["logo-upload"]; ?></button>
                                        </form>
                                        <div class="row text-center">
                                            <div class="col">
                                                <img class="img img-fluid" width="150px"
                                                    src="../../../assets/img/brand/logo.png?<?php echo filemtime("../../../assets/img/brand/logo.png"); ?>"
                                                    alt="Logo Preview">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card shadow">
                                    <div class="card-header">
                                        <?php echo $translations["background-upload"]; ?>
                                    </div>
                                    <div class="card-body">
                                        <form action="" method="post" enctype="multipart/form-data">
                                            <div class="form-group">
                                                <label
                                                    for="backgroundFile"><?php echo $translations["select-upload-background"]; ?></label>
                                                <input type="file" class="form-control-file" id="backgroundFile"
                                                    name="backgroundFile" accept="image/png, image/jpeg">
                                            </div>
                                            <button type="submit" class="btn btn-primary"
                                                name="uploadBackground"><?php echo $translations["background-upload"]; ?></button>
                                        </form>
                                        <div class="row text-center">
                                            <div class="col">
                                                <img class="img img-fluid" width="150px"
                                                    src="../../../assets/img/brand/background.png?<?php echo filemtime("../../../assets/img/brand/background.png"); ?>"
                                                    alt="Background Preview">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card shadow">
                                    <div class="card-header">
                                        <?php echo $translations["favicon-upload"]; ?>
                                    </div>
                                    <div class="card-body">
                                        <form action="" method="post" enctype="multipart/form-data">
                                            <div class="form-group">
                                                <label
                                                    for="faviconFile"><?php echo $translations["select-upload-favicon"]; ?></label>
                                                <input type="file" class="form-control-file" id="faviconFile"
                                                    name="faviconFile" accept="image/png, image/jpeg">
                                            </div>
                                            <button type="submit" class="btn btn-primary"
                                                name="uploadFavicon"><?php echo $translations["favicon-upload"]; ?></button>
                                        </form>
                                        <div class="row text-center">
                                            <div class="col">
                                                <img class="img img-fluid" width="150px"
                                                    src="../../../assets/img/brand/favicon.png?<?php echo filemtime("../../../assets/img/brand/favicon.png"); ?>"
                                                    alt="Favicon Preview">
                                            </div>
                                        </div>
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
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>