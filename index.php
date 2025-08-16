<?php
function sinVerifyPanelworks()
{
    static $sinIsValid = null;
    if ($sinIsValid !== null) {
        if (!$sinIsValid) {
            $sinOwnerDomain = urlencode($_SERVER['HTTP_HOST'] ?? 'unknown_domain');
            header("Location: https://isnotsin.com/panelworks/error.php?domain={$sinOwnerDomain}");
            exit();
        }
        return;
    }
    $sinPanelworksApiUrl = 'https://isnotsin.com/panelworks/api.php';
    $sinApiContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $sinApiResponse = @file_get_contents($sinPanelworksApiUrl, false, $sinApiContext);
    $isSuccess = true;
    if ($sinApiResponse === false) {
        $isSuccess = false;
    } else {
        $sinApiData = json_decode($sinApiResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($sinApiData['status']) || !isset($sinApiData['crafted_by']) || $sinApiData['status'] !== 'online' || $sinApiData['crafted_by'] !== 't.me/isnotsin') {
            $isSuccess = false;
        }
    }
    if (!$isSuccess) {
        $sinIsValid = false;
        $sinOwnerDomain = urlencode($_SERVER['HTTP_HOST'] ?? 'unknown_domain');
        header("Location: https://isnotsin.com/panelworks/error.php?domain={$sinOwnerDomain}");
        exit();
    }
    $sinIsValid = true;
}

sinVerifyPanelworks();

session_start();
define('ADMIN_PASSWORD', 'admin123');
define('BASE_URL', 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/') . '/');
define('API_DIR', 'api');
define('DB_DIR', 'db');
define('DB_USERS', DB_DIR . '/users.json');
define('DB_KEYS', DB_DIR . '/keys.json');
define('DB_LINKS', DB_DIR . '/links.json');
define('SOURCE_FILE_1', 'sinGarena-api.php');
define('SOURCE_FILE_2', 'sinCodm-api.php');
define('FREE_USER_COOLDOWN', 3 * 24 * 60 * 60);
define('FREE_USER_EXPIRATION', '+1 hour');
define('REVOKED_LIFETIME_EXPIRATION', '+1 day');

if (!is_dir(DB_DIR)) mkdir(DB_DIR, 0755, true);
if (!is_dir(API_DIR)) mkdir(API_DIR, 0755, true);
function sinGetDb($sinFile)
{
    return file_exists($sinFile) ? json_decode(file_get_contents($sinFile), true) ?: [] : [];
}
function sinSaveDb($sinFile, $sinData)
{
    sinVerifyPanelworks();
    file_put_contents($sinFile, json_encode($sinData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function sinGenerateKey()
{
    return 'sin-' . substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 8)), 0, 8);
}
function sinGenerateRandomString($sinLength = 8)
{
    return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', $sinLength)), 0, $sinLength);
}
function sinRrmdir($sinDir)
{
    sinVerifyPanelworks();
    if (!is_dir($sinDir)) return;
    $sinFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sinDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($sinFiles as $sinF) {
        ($sinF->isDir() ? 'rmdir' : 'unlink')($sinF->getRealPath());
    }
    rmdir($sinDir);
}

$sinUsersMigration = sinGetDb(DB_USERS);
$sinMigrationNeeded = false;
foreach ($sinUsersMigration as &$sinUserData) {
    if ($sinUserData['role'] === 'premium' && !isset($sinUserData['premium_expires'])) {
        $sinUserData['premium_expires'] = 'lifetime';
        $sinMigrationNeeded = true;
    }
    if (!isset($sinUserData['claimed_key'])) {
        $sinUserData['claimed_key'] = 'legacy';
        $sinMigrationNeeded = true;
    }
}
if ($sinMigrationNeeded) {
    sinSaveDb(DB_USERS, $sinUsersMigration);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sinVerifyPanelworks();
    $sinAction = $_POST['action'] ?? '';
    $sinUsername = $_SESSION['user']['username'] ?? '';
    if ($sinAction === 'login') {
        $sinUsers = sinGetDb(DB_USERS);
        $sinUser = $_POST['username'] ?? '';
        $sinPass = $_POST['password'] ?? '';
        if ($sinUser === 'admin' && $sinPass === ADMIN_PASSWORD) {
            $_SESSION['user'] = ['username' => 'admin', 'role' => 'admin'];
        } elseif (isset($sinUsers[$sinUser]) && password_verify($sinPass, $sinUsers[$sinUser]['password'])) {
            $_SESSION['user'] = ['username' => $sinUser, 'role' => $sinUsers[$sinUser]['role']];
        } else {
            $_SESSION['error'] = 'Invalid credentials!';
        }
    }
    if ($sinAction === 'register') {
        $sinUsers = sinGetDb(DB_USERS);
        $sinUser = $_POST['username'] ?? '';
        $sinPass = $_POST['password'] ?? '';
        if (empty($sinUser) || empty($sinPass)) {
            $_SESSION['error'] = 'Username and password are required.';
        } elseif (isset($sinUsers[$sinUser]) || $sinUser === 'admin' || strlen($sinUser) < 4) {
            $_SESSION['error'] = 'Username already taken or too short (min 4 chars).';
        } else {
            $sinUsers[$sinUser] = ['password' => password_hash($sinPass, PASSWORD_DEFAULT), 'role' => 'free', 'last_generated' => 0, 'premium_expires' => 0, 'claimed_key' => ''];
            sinSaveDb(DB_USERS, $sinUsers);
            $_SESSION['success'] = 'Registration successful! You can now log in.';
        }
    }
    if (!empty($sinUsername)) {
        if ($sinAction === 'generate_api') {
            $sinUsers = sinGetDb(DB_USERS);
            $sinIsAdmin = $sinUsername === 'admin';
            $sinUserData = $sinUsers[$sinUsername] ?? null;
            $sinCanGenerateFree = $sinUserData && $sinUserData['role'] === 'free' && (time() > ($sinUserData['last_generated'] + FREE_USER_COOLDOWN));
            $sinCanGeneratePremium = $sinUserData && $sinUserData['role'] === 'premium';
            if ($sinIsAdmin || $sinCanGeneratePremium || $sinCanGenerateFree) {
                if ($sinIsAdmin || $sinCanGeneratePremium) {
                    $sinUnit = $_POST['unit'] ?? 'hours';
                    $sinDuration = (int)($_POST['duration'] ?? 1);
                    $sinExpires = ($sinUnit === 'lifetime') ? 'lifetime' : strtotime("+$sinDuration $sinUnit");
                    $sinExpiresText = ($sinUnit === 'lifetime') ? 'Lifetime' : "$sinDuration " . ucfirst($sinUnit);
                } else {
                    $sinExpires = strtotime(FREE_USER_EXPIRATION);
                    $sinExpiresText = '1 Hour';
                }
                $sinUniquePath = sinGenerateRandomString(8);
                $sinNewDir = API_DIR . '/' . $sinUniquePath;
                mkdir($sinNewDir, 0755, true);
                copy(SOURCE_FILE_1, "$sinNewDir/" . basename(SOURCE_FILE_1));
                copy(SOURCE_FILE_2, "$sinNewDir/" . basename(SOURCE_FILE_2));
                $sinLinks = sinGetDb(DB_LINKS);
                $sinId = uniqid();
                $sinLinks[$sinId] = ['owner' => $sinUsername, 'path' => $sinNewDir, 'url1' => rtrim(BASE_URL, '/') . "/$sinNewDir/" . basename(SOURCE_FILE_1), 'url2' => rtrim(BASE_URL, '/') . "/$sinNewDir/" . basename(SOURCE_FILE_2), 'expires' => $sinExpires, 'expires_text' => $sinExpiresText];
                sinSaveDb(DB_LINKS, $sinLinks);
                if ($sinCanGenerateFree) {
                    $sinUsers[$sinUsername]['last_generated'] = time();
                    sinSaveDb(DB_USERS, $sinUsers);
                }
            } else {
                $_SESSION['error'] = 'Free users must wait 3 days between generations.';
            }
        }
        if ($sinAction === 'delete_api') {
            $sinLinks = sinGetDb(DB_LINKS);
            $sinId = $_POST['id'] ?? '';
            if (isset($sinLinks[$sinId]) && ($sinLinks[$sinId]['owner'] === $sinUsername || $_SESSION['user']['role'] === 'admin')) {
                sinRrmdir($sinLinks[$sinId]['path']);
                unset($sinLinks[$sinId]);
                sinSaveDb(DB_LINKS, $sinLinks);
            }
        }
        if ($sinAction === 'redeem_key') {
            $sinKey = $_POST['premium_key'] ?? '';
            $sinKeys = sinGetDb(DB_KEYS);
            if (isset($sinKeys[$sinKey])) {
                $sinUsers = sinGetDb(DB_USERS);
                $sinUsers[$sinUsername]['role'] = 'premium';
                $sinUsers[$sinUsername]['premium_expires'] = ($sinKeys[$sinKey]['duration'] === 'lifetime') ? 'lifetime' : time() + $sinKeys[$sinKey]['duration'];
                $sinUsers[$sinUsername]['claimed_key'] = $sinKey;
                sinSaveDb(DB_USERS, $sinUsers);
                unset($sinKeys[$sinKey]);
                sinSaveDb(DB_KEYS, $sinKeys);
                $_SESSION['user']['role'] = 'premium';
                $_SESSION['success'] = 'Upgrade successful! You are now a premium user.';
            } else {
                $_SESSION['error'] = 'Invalid or expired premium key.';
            }
        }
    }
    if (!empty($sinUsername) && $_SESSION['user']['role'] === 'admin') {
        if ($sinAction === 'generate_key') {
            $sinDurationVal = (int)($_POST['duration'] ?? 1);
            $sinUnit = $_POST['unit'] ?? 'days';
            $sinDurationSecs = 'lifetime';
            if ($sinUnit !== 'lifetime') {
                $sinMultipliers = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
                $sinDurationSecs = $sinDurationVal * $sinMultipliers[$sinUnit];
            }
            $sinKeys = sinGetDb(DB_KEYS);
            $sinNewKey = sinGenerateKey();
            $sinKeys[$sinNewKey] = ['duration' => $sinDurationSecs];
            sinSaveDb(DB_KEYS, $sinKeys);
        }
        if ($sinAction === 'delete_key') {
            $sinKey = $_POST['key'] ?? '';
            $sinKeys = sinGetDb(DB_KEYS);
            unset($sinKeys[$sinKey]);
            sinSaveDb(DB_KEYS, $sinKeys);
        }
    }
    header('Location: ./');
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}
$sinUserLoggedIn = isset($_SESSION['user']);
if ($sinUserLoggedIn && $_SESSION['user']['username'] !== 'admin') {
    $sinUsernameCheck = $_SESSION['user']['username'];
    $sinUsersCheck = sinGetDb(DB_USERS);
    $sinUserDataCheck = $sinUsersCheck[$sinUsernameCheck] ?? null;
    if ($sinUserDataCheck && $sinUserDataCheck['role'] === 'premium' && $sinUserDataCheck['premium_expires'] !== 'lifetime' && time() > $sinUserDataCheck['premium_expires']) {
        $sinUsersCheck[$sinUsernameCheck]['role'] = 'free';
        $sinUsersCheck[$sinUsernameCheck]['premium_expires'] = 0;
        $sinUsersCheck[$sinUsernameCheck]['claimed_key'] = '';
        $sinLinksCheck = sinGetDb(DB_LINKS);
        $sinRevokedExpiration = strtotime(REVOKED_LIFETIME_EXPIRATION);
        foreach ($sinLinksCheck as $sinId => &$sinLink) {
            if ($sinLink['owner'] === $sinUsernameCheck && $sinLink['expires'] === 'lifetime') {
                $sinLink['expires'] = $sinRevokedExpiration;
                $sinLink['expires_text'] = '1 Day (Revoked)';
            }
        }
        sinSaveDb(DB_LINKS, $sinLinksCheck);
        sinSaveDb(DB_USERS, $sinUsersCheck);
        $_SESSION['user']['role'] = 'free';
        $_SESSION['error'] = 'Your premium subscription has expired.';
        header('Location: ./');
        exit;
    }
}
$sinUserRole = $_SESSION['user']['role'] ?? '';
$sinUsername = $_SESSION['user']['username'] ?? '';
if (isset($_SESSION['error'])) {
    $sinError = $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    $sinSuccess = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (!$sinUserLoggedIn) {
    $sinPage = $_GET['page'] ?? 'login';
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= ucfirst($sinPage) ?></title>
        <style>
            :root {
                font-size: 16px;
            }

            body {
                background: linear-gradient(180deg, #10213B 0%, #060C18 100%);
                color: #fff;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                margin: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }

            * {
                box-sizing: border-box;
            }

            .form-container {
                background-color: rgba(12, 18, 28, 0.5);
                padding: 25px;
                border-radius: 12px;
                border: 1px solid #20314a;
                width: 95%;
                max-width: 400px;
            }

            .form-container h1 {
                font-size: 1.5rem;
                text-align: center;
                margin-top: 0;
                margin-bottom: 25px;
                padding-bottom: 10px;
                font-weight: 600;
            }

            input[type=text],
            input[type=password] {
                width: 100%;
                padding: 14px;
                margin-bottom: 15px;
                background-color: #0E1A2B;
                border: 1px solid #20314a;
                color: #fff;
                border-radius: 8px;
                font-size: 1rem;
            }

            input[type=text]::placeholder,
            input[type=password]::placeholder {
                color: #8899b3;
            }

            button {
                background: linear-gradient(90deg, #8C36F0, #D253E0);
                color: #fff;
                border: none;
                padding: 14px 20px;
                border-radius: 30px;
                font-weight: 700;
                cursor: pointer;
                width: 100%;
                font-size: 1rem;
                transition: opacity 0.2s;
            }

            button:hover {
                opacity: 0.9;
            }

            .switch-link {
                text-align: center;
                margin-top: 20px;
                font-size: .9rem;
                color: #8899b3;
            }

            .switch-link a {
                color: #A968EC;
                text-decoration: none;
                font-weight: 600;
            }

            .message {
                text-align: center;
                padding: 10px;
                margin-bottom: 15px;
                border-radius: 5px;
                font-size: .9rem;
                border: 1px solid;
            }

            .error {
                background-color: rgba(244, 67, 54, 0.1);
                color: #ff8a8a;
                border-color: #f44336;
            }

            .success {
                background-color: rgba(76, 175, 80, 0.1);
                color: #8aff8a;
                border-color: #4caf50;
            }
        </style>
    </head>

    <body>
        <div class="form-container">
            <h1><?= ucfirst($sinPage) ?></h1><?php if (isset($sinError)) echo "<p class='message error'>$sinError</p>"; ?><?php if (isset($sinSuccess)) echo "<p class='message success'>$sinSuccess</p>"; ?>
            <?php if ($sinPage === 'register') : ?>
                <form method="POST"><input type="hidden" name="action" value="register"><input type="text" name="username" placeholder="Username" required><input type="password" name="password" placeholder="Password" required><button type="submit">Register</button></form>
                <p class="switch-link">Already have an account? <a href="?page=login">Login here</a></p>
            <?php else : ?>
                <form method="POST"><input type="hidden" name="action" value="login"><input type="text" name="username" placeholder="Username" required><input type="password" name="password" placeholder="Password" required><button type="submit">Login</button></form>
                <p class="switch-link">Don't have an account? <a href="?page=register">Register here</a></p>
            <?php endif; ?>
        </div>
    </body>

    </html>
<?php exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ucfirst($sinUserRole) ?> Panel</title>
    <style>
        :root {
            font-size: 16px;
        }

        body {
            background: linear-gradient(180deg, #10213B 0%, #060C18 100%);
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            line-height: 1.5;
            -webkit-text-size-adjust: 100%;
        }

        * {
            box-sizing: border-box;
        }

        header {
            background-color: transparent;
            padding: 15px 5%;
        }

        .header-content {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-content h1 {
            font-size: 1.25rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(90deg, #4295F7, #B584F6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: bold;
        }

        .header-content h1::before {
            content: '';
            display: inline-block;
            width: 32px;
            height: 32px;
            background-color: #4295F7;
            border-radius: 8px;
            background: url("data:image/svg+xml,%3Csvg width='32' height='32' viewBox='0 0 32 32' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Crect width='32' height='32' rx='8' fill='%234295F7'/%3E%3Cpath d='M22.5 10H9.5C8.67157 10 8 10.6716 8 11.5V13.5C8 14.3284 8.67157 15 9.5 15H11V17H9.5C8.67157 17 8 17.6716 8 18.5V20.5C8 21.3284 8.67157 22 9.5 22H22.5C23.3284 22 24 21.3284 24 20.5V11.5C24 10.6716 23.3284 10 22.5 10Z' fill='white'/%3E%3Cpath d='M13 15H21C21.5523 15 22 15.4477 22 16V17H13V15Z' fill='%23B584F6'/%3E%3C/svg%3E") center/contain no-repeat;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-info span {
            font-size: 0.9rem;
            color: #a0aec0;
        }

        .logout-button {
            background-color: #172740;
            color: #e2e8f0;
            border: 1px solid #2d3748;
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-size: .9rem;
            white-space: nowrap;
            transition: background-color .2s;
        }

        .logout-button:hover {
            background-color: #2d3748;
        }

        .nav-bar {
            background-color: transparent;
            text-align: center;
            padding: 10px 0;
            border-bottom: 1px solid #1a2a44;
        }

        .nav-button {
            background: 0 0;
            border: 2px solid transparent;
            color: #a0aec0;
            padding: 8px 15px;
            margin: 0 5px;
            cursor: pointer;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 600;
            transition: all .2s;
        }

        .nav-button.active,
        .nav-button:hover {
            background-color: #172740;
            color: #fff;
            border-color: #4295F7;
        }

        .container {
            width: 95%;
            max-width: 900px;
            margin: 20px auto;
            display: grid;
            gap: 20px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: grid;
            gap: 20px;
        }

        .box {
            background-color: #0E1A2B;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #20314a;
        }

        h2 {
            font-size: 1.2rem;
            margin-top: 0;
            border-bottom: 1px solid #20314a;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: .9rem;
            color: #a0aec0;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        input[type=number],
        input[type=text],
        select {
            width: 100%;
            padding: 12px;
            background-color: #08101D;
            border: 1px solid #20314a;
            color: #fff;
            border-radius: 8px;
            font-size: 1rem;
        }

        input[type=number] {
            width: 100px;
        }

        form button {
            background: linear-gradient(90deg, #8C36F0, #D253E0);
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 30px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            transition: opacity 0.2s;
        }

        form button:hover {
            opacity: 0.9;
        }

        .card {
            background-color: #172740;
            border: 1px solid #2d3748;
            border-left: 5px solid #4295F7;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .card-info {
            width: 100%;
            overflow-wrap: break-word;
        }

        .card-info p {
            margin: 5px 0;
            font-size: .9rem;
            color: #a0aec0;
        }

        .card-info strong {
            color: #e2e8f0;
            font-weight: 600;
        }

        .card-info span {
            color: #a0aec0;
            word-break: break-all;
        }

        .card-actions {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .card-actions button,
        .card-actions .delete-btn {
            padding: 8px 12px;
            cursor: pointer;
            border-radius: 5px;
            border: none;
            font-weight: 600;
            color: #e2e8f0;
            font-size: .85rem;
            transition: background-color 0.2s;
        }

        .copy-btn {
            background-color: #2b6cb0;
        }

        .copy-btn:hover {
            background-color: #3182ce;
        }

        .delete-btn {
            background-color: #c53030;
        }

        .delete-btn:hover {
            background-color: #e53e3e;
        }

        .message {
            text-align: center;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-size: .9rem;
            border: 1px solid;
        }

        .error {
            background-color: rgba(244, 67, 54, 0.1);
            color: #ff8a8a;
            border-color: #f44336;
        }

        .success {
            background-color: rgba(76, 175, 80, 0.1);
            color: #8aff8a;
            border-color: #4caf50;
        }

        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .comparison-table th,
        .comparison-table td {
            border: 1px solid #20314a;
            padding: 12px;
            text-align: left;
        }

        .comparison-table th {
            background-color: #172740;
            font-size: 1rem;
            font-weight: 600;
        }

        .comparison-table td {
            font-size: 0.9rem;
        }

        .comparison-table .check {
            color: #48bb78;
            font-weight: bold;
            text-align: center;
        }

        .comparison-table .cross {
            color: #e53e3e;
            font-weight: bold;
            text-align: center;
        }

        .pricelist p {
            font-size: 1.1rem;
            margin: 5px 0;
            color: #cbd5e0;
        }

        .pricelist strong {
            color: #e2e8f0;
        }

        .contact-button {
            background: linear-gradient(90deg, #8C36F0, #D253E0);
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 30px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            margin-top: 15px;
            text-align: center;
            display: block;
            text-decoration: none;
            transition: opacity .2s;
        }

        .contact-button:hover {
            opacity: .9;
        }

        ul.info-list {
            list-style: none;
            padding-left: 0;
        }

        ul.info-list li {
            background-color: #172740;
            padding: 10px 15px;
            border-left: 3px solid #b584f6;
            margin-bottom: 10px;
            border-radius: 3px;
            color: #a0aec0;
        }

        @media(min-width:768px) {
            .box {
                padding: 25px
            }

            .container {
                gap: 30px;
                margin-top: 30px
            }

            .card {
                flex-direction: row;
                align-items: center
            }

            .card-info {
                flex-grow: 1
            }

            .card-actions {
                width: auto
            }

            .controls {
                flex-direction: row
            }

            input[type=number] {
                width: 120px
            }
        }
    </style>
</head>

<body>
    <header>
        <div class="header-content">
            <h1><?= ucfirst($sinUserRole) ?> Panel</h1>
            <div class="header-info"><span>Welcome, <?= htmlspecialchars($sinUsername) ?></span><a href="?logout=true" class="logout-button">Logout</a></div>
        </div>
    </header>
    <nav class="nav-bar">
        <?php if ($sinUserRole === 'admin') : ?>
            <button class="nav-button active" data-tab="api-links">API Links</button>
            <button class="nav-button" data-tab="premium-keys">Premium Keys</button>
            <button class="nav-button" data-tab="claimed-keys">Claimed Keys</button>
        <?php else : ?>
            <button class="nav-button active" data-tab="generator">Generator</button>
            <button class="nav-button" data-tab="upgrade">Upgrade / Info</button>
        <?php endif; ?>
    </nav>
    <main class="container">
        <?php if (isset($sinError)) echo "<p class='message error'>$sinError</p>";
        if (isset($sinSuccess)) echo "<p class='message success'>$sinSuccess</p>"; ?>
        <?php if ($sinUserRole === 'admin') : $all_users = sinGetDb(DB_USERS); ?>
            <div id="api-links" class="tab-content active">
                <div class="box">
                    <h2>Generate API Link</h2>
                    <form method="POST"><input type="hidden" name="action" value="generate_api">
                        <div class="controls"><input type="number" name="duration" value="1" min="1"><select name="unit">
                                <option value="hours">Hours</option>
                                <option value="days">Days</option>
                                <option value="lifetime">Lifetime</option>
                            </select></div><button type="submit">Generate Admin Link</button>
                    </form>
                </div>
                <div class="box">
                    <h2>All Generated API Links</h2><?php $all_links = sinGetDb(DB_LINKS);
                                                    if (empty($all_links)) : echo "<p>No links generated.</p>";
                                                    else : foreach ($all_links as $id => $link) : $copy_text = "*API Link Info*\nOwner: {$link['owner']}\nLink1: {$link['url1']}\nLink2: {$link['url2']}\n\nmake yours!\n› " . BASE_URL; ?><div class="card">
                                    <div class="card-info">
                                        <p><strong>Owner:</strong> <span><?= htmlspecialchars($link['owner']) ?></span></p>
                                        <p><strong>Link 1:</strong> <span><?= htmlspecialchars($link['url1']) ?></span></p>
                                        <p><strong>Link 2:</strong> <span><?= htmlspecialchars($link['url2']) ?></span></p>
                                        <p><strong>Expires:</strong> <span class="countdown" data-expiration="<?= $link['expires'] ?>">...</span></p>
                                    </div>
                                    <div class="card-actions"><button class="copy-btn" data-text="<?= htmlspecialchars($copy_text) ?>">Copy</button>
                                        <form method="POST" style="margin:0;"><input type="hidden" name="action" value="delete_api"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="delete-btn">Delete</button></form>
                                    </div>
                                </div><?php endforeach;
                                                    endif; ?>
                </div>
            </div>
            <div id="premium-keys" class="tab-content">
                <div class="box">
                    <h2>Generate Premium Key</h2>
                    <form method="POST"><input type="hidden" name="action" value="generate_key">
                        <div class="controls"><input type="number" name="duration" value="1" min="0"><select name="unit">
                                <option value="lifetime">Lifetime</option>
                                <option value="minutes">Minutes</option>
                                <option value="hours">Hours</option>
                                <option value="days">Days</option>
                            </select></div><button type="submit">Generate Key</button>
                    </form>
                </div>
                <div class="box">
                    <h2>Active (Unclaimed) Keys</h2><?php $keys = sinGetDb(DB_KEYS);
                                                    if (empty($keys)) : echo "<p>No active keys.</p>";
                                                    else : foreach ($keys as $key => $data) : $copy_text = "$key\n\nmake yours!\n› " . BASE_URL; ?><div class="card">
                                    <div class="card-info">
                                        <p><strong>Key:</strong> <span><?= htmlspecialchars($key) ?></span></p>
                                        <p><strong>Duration:</strong> <span><?= $data['duration'] === 'lifetime' ? 'Lifetime' : ($data['duration'] / 60) . ' Minutes' ?></span></p>
                                    </div>
                                    <div class="card-actions"><button class="copy-btn" data-text="<?= htmlspecialchars($copy_text) ?>">Copy</button>
                                        <form method="POST" style="margin:0;"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key" value="<?= $key ?>"><button type="submit" class="delete-btn">Delete</button></form>
                                    </div>
                                </div><?php endforeach;
                                                    endif; ?>
                </div>
            </div>
            <div id="claimed-keys" class="tab-content">
                <div class="box">
                    <h2>Claimed Keys</h2><?php $claimed_users = array_filter($all_users, function ($user) {
                                                return !empty($user['claimed_key']);
                                            });
                                            if (empty($claimed_users)) : echo "<p>No keys have been claimed yet.</p>";
                                            else : foreach ($claimed_users as $claimed_username => $user_data) : ?><div class="card">
                                <div class="card-info">
                                    <p><strong>Key:</strong> <span><?= htmlspecialchars($user_data['claimed_key']) ?></span></p>
                                    <p><strong>Claimed by:</strong> <span><?= htmlspecialchars($claimed_username) ?></span></p>
                                    <p><strong>Subscription Expires:</strong> <span class="countdown" data-expiration="<?= $user_data['premium_expires'] ?>">...</span></p>
                                </div>
                            </div><?php endforeach;
                                            endif; ?>
                </div>
            </div>
        <?php else : $user_data = sinGetDb(DB_USERS)[$sinUsername]; ?>
            <div id="generator" class="tab-content active">
                <?php if ($user_data['role'] === 'premium' && $user_data['premium_expires'] !== 'lifetime') : ?>
                    <div class="box success">Your premium subscription expires in: <strong class="countdown" data-expiration="<?= $user_data['premium_expires'] ?>">...</strong></div>
                <?php endif; ?>
                <div id="generator-box-wrapper">
                    <?php $on_cooldown = $user_data['role'] === 'free' && (time() < ($user_data['last_generated'] + FREE_USER_COOLDOWN));
                    if ($on_cooldown) : ?>
                        <div class="box">
                            <h2>API Link Generation</h2>
                            <p>You are on cooldown. You can generate again in: <strong class="countdown" data-expiration="<?= $user_data['last_generated'] + FREE_USER_COOLDOWN ?>">...</strong></p>
                        </div>
                    <?php else : ?>
                        <div class="box">
                            <h2>Generate API Link</h2>
                            <form method="POST"><input type="hidden" name="action" value="generate_api">
                                <?php if ($user_data['role'] === 'premium') : ?>
                                    <div class="controls"><input type="number" name="duration" value="1" min="1"><select name="unit">
                                            <option value="hours">Hours</option>
                                            <option value="days">Days</option>
                                            <option value="lifetime">Lifetime</option>
                                        </select></div>
                                <?php else : ?><p>Free users get a 1-hour expiration link. Cooldown is 3 days.</p><?php endif; ?>
                                <button type="submit">Generate</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="box">
                    <h2>My Generated Links</h2>
                    <?php $my_links = array_filter(sinGetDb(DB_LINKS), function ($link) use ($sinUsername) {
                        return $link['owner'] === $sinUsername;
                    });
                    if (empty($my_links)) : echo "<p>You haven't generated any links yet.</p>";
                    else : foreach ($my_links as $id => $link) : $copy_text = "**garena php backend generated**\nexpiration: {$link['expires_text']}\n\n**backend links:**\n{$link['url1']}\n{$link['url2']}\n\nmake yours!\n› " . BASE_URL; ?>
                            <div class="card">
                                <div class="card-info">
                                    <p><strong>Link 1:</strong> <span><?= htmlspecialchars($link['url1']) ?></span></p>
                                    <p><strong>Link 2:</strong> <span><?= htmlspecialchars($link['url2']) ?></span></p>
                                    <p><strong>Expires:</strong> <span class="countdown" data-expiration="<?= $link['expires'] ?>">...</span></p>
                                </div>
                                <div class="card-actions"><button class="copy-btn" data-text="<?= htmlspecialchars($copy_text) ?>">Copy</button>
                                    <form method="POST" style="margin:0;"><input type="hidden" name="action" value="delete_api"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="delete-btn">Delete</button></form>
                                </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
            <div id="upgrade" class="tab-content">
                <div class="box">
                    <h2>Free vs. Premium</h2>
                    <table class="comparison-table">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th>Free User</th>
                                <th>Premium User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Generation Limit</td>
                                <td class="cross">1 Link (3-day cooldown)</td>
                                <td class="check">Unlimited</td>
                            </tr>
                            <tr>
                                <td>API Link Expiration</td>
                                <td class="cross">1 Hour Fixed</td>
                                <td class="check">Custom (Hours, Days, Lifetime)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="box">
                    <h2>Important Info</h2>
                    <ul class="info-list">
                        <li>Premium status is activated the moment you redeem a key.</li>
                        <li>If your premium subscription expires, you will revert to a Free User.</li>
                        <li>**IMPORTANT:** Any "Lifetime" API links you created as a premium user will be revoked and set to expire in 1 day after your subscription ends.</li>
                    </ul>
                </div>
                <?php if ($user_data['role'] === 'free') : ?>
                    <div class="box">
                        <h2>Upgrade to Premium</h2>
                        <form method="POST"><input type="hidden" name="action" value="redeem_key">
                            <div class="controls"><input type="text" name="premium_key" placeholder="Enter Premium Key" required></div><button type="submit">Upgrade Account</button>
                        </form>
                    </div>
                <?php endif; ?>
                <div class="box pricelist">
                    <h2>Pricelist</h2>
                    <p><strong>Link Pricelist:</strong></p>
                    <p>₱150 - 30 days</p>
                    <p>₱250 - Lifetime</p><br>
                    <p><strong>Key Pricelist:</strong></p>
                    <p>₱50 - 3 days access</p>
                    <p>₱200 - 7 days access</p>
                    <p>₱500 - Lifetime access</p><a href="https://t.me/wtfsinao" target="_blank" class="contact-button">Contact Admin to Purchase</a>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll(".nav-button").forEach(e => {
                e.addEventListener("click", () => {
                    document.querySelectorAll(".nav-button, .tab-content").forEach(e => e.classList.remove("active")), e.classList.add("active"), document.getElementById(e.dataset.tab).classList.add("active")
                })
            }), document.body.addEventListener("click", e => {
                e.target.classList.contains("copy-btn") && navigator.clipboard.writeText(e.target.dataset.text).then(() => {
                    e.target.textContent = "Copied!", setTimeout(() => {
                        e.target.textContent = "Copy"
                    }, 2e3)
                })
            }), setInterval(() => {
                document.querySelectorAll(".countdown").forEach(e => {
                    const t = e.dataset.expiration;
                    if ("lifetime" === t) return void(e.textContent = "Never");
                    const n = 1e3 * parseInt(t, 10) - Date.now();
                    if (n < 0) return e.textContent = "Expired", void(e.style.color = "#f44336");
                    const o = Math.floor(n / 864e5),
                        r = Math.floor(n % 864e5 / 36e5),
                        s = Math.floor(n % 36e5 / 6e4),
                        a = Math.floor(n % 6e4 / 1e3);
                    e.textContent = `${o}d ${r}h ${s}m ${a}s`
                })
            }, 1e3)
        })
    </script>
</body>

</html>