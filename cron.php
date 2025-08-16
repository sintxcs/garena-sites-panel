<?php
// Set a secret key to prevent unauthorized access.
$secret_key = "cron-job-org";
if (($_GET['key'] ?? '') !== $secret_key) {
    header("HTTP/1.1 403 Forbidden");
    die("Access denied.");
}

// Define constants needed by the script
define('DB_USERS', 'db/users.json');
define('DB_LINKS', 'db/links.json');
define('API_DIR', 'api');
define('BASE_URL', 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/') . '/');
define('REVOKED_LIFETIME_EXPIRATION', '+1 day');

echo "Cron job started at " . date('Y-m-d H:i:s') . "\n";

function get_db($file) { return file_exists($file) ? json_decode(file_get_contents($file), true) ?: [] : []; }
function save_db($file, $data) { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
function rrmdir($dir) { if (!is_dir($dir)) return; $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($files as $f) { ($f->isDir() ? 'rmdir' : 'unlink')($f->getRealPath()); } rmdir($dir); }

$users = get_db(DB_USERS);
$links = get_db(DB_LINKS);
$users_updated = false;
$links_updated = false;
$now = time();

// --- Step 1: Check for expired premium users and revoke their lifetime links ---
foreach ($users as $username => &$user_data) {
    if ($user_data['role'] === 'premium' && $user_data['premium_expires'] !== 'lifetime' && $now > $user_data['premium_expires']) {
        echo "User '{$username}' premium expired. Reverting to free.\n";
        $user_data['role'] = 'free'; $user_data['premium_expires'] = 0; $user_data['claimed_key'] = '';
        $users_updated = true;
        
        $revoked_expiration = strtotime(REVOKED_LIFETIME_EXPIRATION);
        foreach ($links as $id => &$link) {
            if ($link['owner'] === $username && $link['expires'] === 'lifetime') {
                echo "  -> Revoking lifetime link {$id}.\n";
                $link['expires'] = $revoked_expiration; $link['expires_text'] = '1 Day (Revoked)';
                $links_updated = true;
            }
        }
    }
}

// --- Step 2: Check for and delete expired API links ---
foreach ($links as $id => $link) {
    if ($link['expires'] !== 'lifetime' && $now > $link['expires']) {
        echo "API link {$id} expired. Deleting files...\n";
        rrmdir($link['path']);
        unset($links[$id]);
        $links_updated = true;
    }
}

if ($users_updated) { save_db(DB_USERS, $users); echo "Users database updated.\n"; }
if ($links_updated) { save_db(DB_LINKS, $links); echo "Links database updated.\n"; }

echo "Cron job finished.\n";