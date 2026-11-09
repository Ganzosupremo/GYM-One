<?php
session_start();

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

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$alerts_html = '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    $alerts_html .= '<div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-circle"></i> Adatbázis kapcsolat sikertelen: ' . $conn->connect_error . '
                    </div>';
}

$loginlikeadmin_PLACEHOLDER = str_replace("{gym_name}", $business_name, $translations["loginlikeadmin"]);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT userid, password_hash FROM workers WHERE username = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $alerts_html .= '<div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-circle"></i> SQL lekérdezés előkészítése sikertelen: ' . $conn->error . '
                        </div>';
        exit();
    }

    $stmt->bind_param("s", $username);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password_hash'])) {
                $_SESSION['adminuser'] = $row['userid'];

                $ip_address = $_SERVER['REMOTE_ADDR'];
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
                } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                    $ip_address = $_SERVER['HTTP_CLIENT_IP'];
                }

                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? $translations["unknown"];

                $browser = $translations["unknown"];
                if (strpos($user_agent, 'Chrome') !== false)
                    $browser = 'Chrome';
                elseif (strpos($user_agent, 'Firefox') !== false)
                    $browser = 'Firefox';
                elseif (strpos($user_agent, 'Safari') !== false)
                    $browser = 'Safari';
                elseif (strpos($user_agent, 'Edge') !== false)
                    $browser = 'Edge';
                elseif (strpos($user_agent, 'Opera') !== false)
                    $browser = 'Opera';

                $os = $translations["unknown"];
                if (strpos($user_agent, 'Windows') !== false)
                    $os = 'Windows';
                elseif (strpos($user_agent, 'Mac') !== false)
                    $os = 'macOS';
                elseif (strpos($user_agent, 'Linux') !== false)
                    $os = 'Linux';
                elseif (strpos($user_agent, 'Android') !== false)
                    $os = 'Android';
                elseif (strpos($user_agent, 'iOS') !== false)
                    $os = 'iOS';

                $device = $browser . ', ' . $os;

                $location = $translations["unknown"];

                $geo = @file_get_contents("http://ip-api.com/json/" . $ip_address . "?fields=country,city,regionName,query,status");
                if ($geo !== false) {
                    $geo = json_decode($geo, true);
                    if ($geo && $geo['status'] === 'success') {
                        $location = $geo['country'] . ', ' . $geo['regionName'] . ', ' . $geo['city'];
                    }
                }

                $log_details = json_encode([
                    'ip_address' => $ip_address,
                    'device' => $device,
                    'location' => $location,
                    'login_time' => date('Y-m-d H:i:s')
                ], JSON_UNESCAPED_UNICODE);

                $log_action = $translations["log_admin_successful_login"];
                $log_color = 'success';

                $log_userid = $_SESSION['adminuser'];

                $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) 
            VALUES (?, ?, ?, ?, NOW())";
                $log_stmt = $conn->prepare($log_sql);
                $log_stmt->bind_param("isss", $log_userid, $log_action, $log_color, $log_details);
                $log_stmt->execute();
                $log_stmt->close();
                header("Location: dashboard/");
                exit();
            } else {
                $ip_address = $_SERVER['REMOTE_ADDR'];
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
                } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                    $ip_address = $_SERVER['HTTP_CLIENT_IP'];
                }

                $location = $translations["unknown"];

                $geo = @file_get_contents("http://ip-api.com/json/" . $ip_address . "?fields=country,city,regionName,query,status");
                if ($geo !== false) {
                    $geo = json_decode($geo, true);
                    if ($geo && $geo['status'] === 'success') {
                        $location = $geo['country'] . ', ' . $geo['regionName'] . ', ' . $geo['city'];
                    }
                }

                $attempted_username = $_POST['username'] ?? $translations["unknown"];

                $log_details = json_encode([
                    'ip_address' => $ip_address,
                    'location' => $location,
                    'attempted_username' => $attempted_username,
                    'status' => $translations["log_admin_unsuccessful_login"]
                ], JSON_UNESCAPED_UNICODE);

                $log_action = $translations["log_admin_unsuccessful_login"];
                $log_color = 'danger';
                $log_userid = null;

                $log_sql = "INSERT INTO logs (userid, action, actioncolor, details, time) 
VALUES (?, ?, ?, ?, NOW())";
                $log_stmt = $conn->prepare($log_sql);
                $log_stmt->bind_param("isss", $log_userid, $log_action, $log_color, $log_details);
                $log_stmt->execute();
                $log_stmt->close();

                $alerts_html .= '<div class="alert alert-danger" role="alert">
                                    <i class="fas fa-exclamation-circle"></i> ' . $translations["incorrect-pass"] . '
                                </div>';
            }
        } else {
            $alerts_html .= '<div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle"></i> ' . $translations["user-notexist"] . '
                            </div>';
        }
    } else {
        $alerts_html .= '<div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-circle"></i> Hiba történt: ' . $conn->error . '
                        </div>';
    }

    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $business_name; ?> - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            background: #0f0f1e;
            min-height: 100vh;
            display: flex;
            position: relative;
            overflow: hidden;
        }

        .split-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        .brand-side {
            flex: 1;
            background: linear-gradient(135deg, #0950dc 0%, #096ed2 50%, #0960dc 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px;
            position: relative;
            overflow: hidden;
        }

        .brand-side::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255, 107, 107, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            top: -100px;
            right: -100px;
            animation: pulse 8s ease-in-out infinite;
        }

        .brand-side::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(78, 205, 196, 0.1) 0%, transparent 70%);
            border-radius: 50%;
            bottom: -50px;
            left: -50px;
            animation: pulse 6s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 0.5;
            }

            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }

        .brand-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 500px;
        }

        .brand-logo {
            width: 280px;
            height: auto;
            margin-bottom: 40px;
            filter: drop-shadow(0 10px 30px rgba(0, 0, 0, 0.3));
            animation: fadeInUp 0.8s ease-out;
        }

        .brand-title {
            font-size: 48px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 15px;
            letter-spacing: -1px;
            animation: fadeInUp 0.8s ease-out 0.2s both;
        }

        .brand-subtitle {
            font-size: 24px;
            color: #f1f1f1;
            font-weight: 600;
            margin-bottom: 25px;
            animation: fadeInUp 0.8s ease-out 0.3s both;
        }

        .brand-description {
            font-size: 16px;
            color: #f1f1f1;
            line-height: 1.8;
            animation: fadeInUp 0.8s ease-out 0.4s both;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 50px;
            animation: fadeInUp 0.8s ease-out 0.5s both;
        }

        .feature-item {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .feature-item:hover {
            background: rgba(9, 110, 210, 0.08);
            transform: translateY(-3px);
        }

        .feature-item i {
            font-size: 28px;
            color: #f1f1f1;
            margin-bottom: 12px;
        }

        .feature-item h4 {
            font-size: 14px;
            color: #fff;
            font-weight: 600;
            margin: 0;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-side {
            flex: 1;
            background: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 60px;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #0950dc 0%, #096ed2 100%);
            color: #fff;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 30px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .admin-badge i {
            font-size: 14px;
        }

        .login-header {
            margin-bottom: 40px;
        }

        .login-header h1 {
            font-size: 36px;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .login-header p {
            font-size: 15px;
            color: #64748b;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 10px;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
            z-index: 2;
        }

        .form-control {
            width: 100%;
            padding: 16px 20px 16px 52px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 500;
            color: #1e293b;
            background: #f8fafc;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #096ed2;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(9, 110, 210, 0.1);
        }

        .form-control::placeholder {
            color: #cbd5e1;
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #0950dc 0%, #096ed2 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(9, 110, 210, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(9, 110, 210, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.4s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .alert i {
            font-size: 16px;
        }

        .security-note {
            margin-top: 30px;
            padding: 20px;
            background: #f1f5f9;
            border-radius: 12px;
            border-left: 4px solid #096ed2;
        }

        .security-note h5 {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .security-note p {
            font-size: 12px;
            color: #64748b;
            margin: 0;
            line-height: 1.6;
        }

        @media (max-width: 1024px) {
            .brand-side {
                display: none;
            }

            .login-side {
                flex: 1;
            }
        }

        @media (max-width: 576px) {
            .login-side {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 28px;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="split-container">
        <div class="brand-side">
            <div class="brand-content">
                <img src="../assets/img/logo.png" alt="GYM One Logo" class="brand-logo">
                <p class="brand-description">
                    <?php echo $translations["herotext"]; ?>
                </p>

                <div class="feature-grid">
                    <div class="feature-item">
                        <i class="fas fa-users"></i>
                        <h4><?php echo $translations["loginusermanagger"]; ?></h4>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-chart-line"></i>
                        <h4><?php echo $translations["statspage"]; ?></h4>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-calendar-check"></i>
                        <h4><?php echo $translations["loginuserpassmanagger"]; ?></h4>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-shield-alt"></i>
                        <h4><?php echo $translations["loginusersecured"]; ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="login-side">
            <div class="login-container">
                <div class="admin-badge">
                    <i class="fas fa-shield-alt"></i>
                    <?php echo $translations["adminlogin"]; ?>
                </div>

                <div class="login-header">
                    <h1><?php echo $translations["welcome"]; ?></h1>
                    <p><?php echo $loginlikeadmin_PLACEHOLDER; ?></p>
                </div>

                <?php if (!empty($alerts_html))
                    echo $alerts_html; ?>

                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="form-group">
                        <label class="form-label" for="username"><?php echo $translations["username"]; ?></label>
                        <div class="input-group">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" class="form-control" id="username" name="username"
                                placeholder="<?php echo $translations["username_placeholder"]; ?>" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password"><?php echo $translations["password"]; ?></label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" class="form-control" id="password" name="password"
                                placeholder="<?php echo $translations["password_placeholder"]; ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> <?php echo $translations["login"]; ?>
                    </button>
                </form>

                <div class="security-note">
                    <h5>
                        <i class="fas fa-info-circle"></i>
                        <?php echo $translations["secure_adminlogin_desc"]; ?>
                    </h5>
                    <p><?php echo $translations["secure_adminlogin_desc_1"]; ?></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
        integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js"
        integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy"
        crossorigin="anonymous"></script>
</body>

</html>