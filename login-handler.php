<?php
// login-handler.php
// Tailored to your schema: admins.password and janitors.password
// Checks status='active', supports password_hash verification and legacy plaintext fallback.
// Returns JSON for AJAX clients.

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

require_once __DIR__ . '/includes/config.php'; // provides $pdo, $conn and starts session

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$remember = isset($_POST['remember']) ? true : false;

$errors = [];
if ($email === '') $errors['email'] = 'Email is required';
if ($password === '') $errors['password'] = 'Password is required';
if (!empty($errors)) {
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Normalize row helper
function normalize_user(array $row = null, string $idKey = 'id'): ?array {
    if (!$row) return null;
    return [
        'id' => isset($row[$idKey]) ? (int)$row[$idKey] : (isset($row['id']) ? (int)$row['id'] : null),
        // your schema uses "password" column
        'password' => $row['password'] ?? null,
        'email' => $row['email'] ?? '',
        'status' => $row['status'] ?? null
    ];
}

try {
    $user = null;
    $role = null;

    // 1) Try admins (active only)
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT admin_id, email, password, status FROM admins WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $user = normalize_user($row, 'admin_id');
            $role = 'admin';
        }
    } else {
        $stmt = $conn->prepare("SELECT admin_id, email, password, status FROM admins WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($row) {
                $user = normalize_user($row, 'admin_id');
                $role = 'admin';
            }
            $stmt->close();
        }
    }

    // 2) If not admin, try janitors (active only)
    if (!$user) {
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT janitor_id, email, password, status FROM janitors WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $user = normalize_user($row, 'janitor_id');
                $role = 'janitor';
            }
        } else {
            $stmt = $conn->prepare("SELECT janitor_id, email, password, status FROM janitors WHERE email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row) {
                    $user = normalize_user($row, 'janitor_id');
                    $role = 'janitor';
                }
                $stmt->close();
            }
        }
    }

    if (!$user) {
        // Don't reveal which table failed
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit;
    }

    // Check account status
    if (!empty($user['status']) && strtolower($user['status']) !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Account is not active. Contact administrator.']);
        exit;
    }

    $stored = $user['password'] ?? null;
    $verified = false;
    if ($stored) {
        // If stored looks like a bcrypt/argon hash ($2y$ or $argon), password_verify will work
        if (password_verify($password, $stored)) {
            $verified = true;
        } else {
            // fallback to plain-text compare (legacy)
            if (hash_equals((string)$stored, (string)$password)) {
                $verified = true;
            }
        }
    }

    if (!$verified) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit;
    }

    // Authentication OK -> set session as your app expects
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    if ($role === 'admin') {
        unset($_SESSION['janitor_id']);
        $_SESSION['admin_id'] = $user['id'];
    } else {
        unset($_SESSION['admin_id']);
        $_SESSION['janitor_id'] = $user['id'];
    }
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $role;
    session_regenerate_id(true);

    // Remember me: extend cookie lifetime
    if ($remember) {
        $lifetime = 30 * 24 * 60 * 60;
        $params = session_get_cookie_params();
        $secure = !empty($params['secure']);
        $httponly = !empty($params['httponly']);
        setcookie(session_name(), session_id(), time() + $lifetime, $params['path'] ?? '/', $params['domain'] ?? '', $secure, $httponly);
    }

    $redirect = ($role === 'admin') ? 'admin-dashboard.php' : 'janitor-dashboard.php';

    echo json_encode(['success' => true, 'message' => 'Authentication successful', 'role' => $role, 'redirect' => $redirect]);
    exit;

} catch (Exception $e) {
    error_log("[login-handler] Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}