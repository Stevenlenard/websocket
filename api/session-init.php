<?php
// api/session-init.php
// Lightweight session warm-up endpoint used by the login page to ensure session cookies are present and session vars loaded.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php'; // session started there

// Accept optional email to help warm up session-friendly state (not a login)
$email = isset($_POST['email']) ? trim($_POST['email']) : null;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!empty($_SESSION['admin_id']) || !empty($_SESSION['janitor_id'])) {
    echo json_encode(['success' => true, 'session' => [
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'janitor_id' => $_SESSION['janitor_id'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ]]);
    exit;
}

// If email provided and a matching user exists, set lightweight session fields (used only after successful auth)
if ($email) {
    try {
        // prefer PDO if available
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT admin_id, janitor_id, email FROM (
                (SELECT admin_id AS id, email, 'admin' AS t FROM admins WHERE email = :email LIMIT 1)
                UNION ALL
                (SELECT janitor_id AS id, email, 'janitor' AS t FROM janitors WHERE email = :email LIMIT 1)
            ) AS u LIMIT 1");
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // map result by checking which table returned (admin_id vs janitor_id)
                if (isset($row['id']) && !empty($row['id'])) {
                    // best-effort only: do not mark as fully authenticated
                    $_SESSION['email'] = $email;
                    // role will be set by login-handler after password validation, avoid setting admin/janitor id here
                    echo json_encode(['success' => true, 'session' => ['email' => $email]]);
                    exit;
                }
            }
        } else {
            // fallback minimal behavior: set email only
            $_SESSION['email'] = $email;
            echo json_encode(['success' => true, 'session' => ['email' => $email]]);
            exit;
        }
    } catch (Exception $e) {
        // ignore and return failure
    }
}

echo json_encode(['success' => false, 'message' => 'No active session']);
exit;