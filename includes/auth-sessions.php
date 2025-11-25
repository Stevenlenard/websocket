<?php
// Lightweight helper library for auth_sessions table.
// Works with $pdo (PDO) if present, otherwise falls back to $conn (mysqli).

if (!function_exists('generateRawToken')) {
    function generateRawToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

if (!function_exists('hashToken')) {
    function hashToken($token) {
        return hash('sha256', $token);
    }
}

/**
 * Create a persistent session (returns the raw token to set in cookie)
 * @param string $user_type 'admin'|'janitor'
 * @param int $user_id
 * @param int $days_valid number of days until expiry
 * @return string|false raw token on success or false on failure
 */
function createAuthSession($user_type, $user_id, $days_valid = 30) {
    global $pdo, $conn;
    $raw = generateRawToken(32);
    $hash = hashToken($raw);
    $expires_at = date('Y-m-d H:i:s', time() + ($days_valid * 86400));

    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("INSERT INTO auth_sessions (user_type, user_id, token_hash, ip_address, user_agent, expires_at, created_at, last_activity, is_active) VALUES (:ut, :uid, :th, :ip, :ua, :exp, NOW(), NOW(), 1)");
            $stmt->execute([
                ':ut' => $user_type,
                ':uid' => $user_id,
                ':th' => $hash,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ':exp' => $expires_at
            ]);
            return $raw;
        } elseif (isset($conn)) {
            $stmt = $conn->prepare("INSERT INTO auth_sessions (user_type, user_id, token_hash, ip_address, user_agent, expires_at, created_at, last_activity, is_active) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)");
            if (!$stmt) return false;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $stmt->bind_param("sissss", $user_type, $user_id, $hash, $ip, $ua, $expires_at);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok ? $raw : false;
        }
    } catch (Exception $e) {
        error_log("[auth_sessions] createAuthSession error: " . $e->getMessage());
    }
    return false;
}

/**
 * Validate a raw token. Returns session row (associative) if valid, otherwise false.
 */
function validateAuthToken($rawToken) {
    global $pdo, $conn;
    if (!$rawToken) return false;
    $hash = hashToken($rawToken);
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT * FROM auth_sessions WHERE token_hash = :th AND is_active = 1 AND expires_at > NOW() LIMIT 1");
            $stmt->execute([':th' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : false;
        } elseif (isset($conn)) {
            $stmt = $conn->prepare("SELECT * FROM auth_sessions WHERE token_hash = ? AND is_active = 1 AND expires_at > NOW() LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param("s", $hash);
            $stmt->execute();
            $res = $stmt->get_result();
            $r = $res->fetch_assoc();
            $stmt->close();
            return $r ?: false;
        }
    } catch (Exception $e) {
        error_log("[auth_sessions] validateAuthToken error: " . $e->getMessage());
    }
    return false;
}

/**
 * Refresh last_activity (and optionally extend expiry) for a token.
 * @param string $rawToken
 * @param int|null $extendSeconds optionally extend expiry by seconds
 * @return bool
 */
function refreshAuthSession($rawToken, $extendSeconds = null) {
    global $pdo, $conn;
    if (!$rawToken) return false;
    $hash = hashToken($rawToken);
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            if ($extendSeconds !== null) {
                $stmt = $pdo->prepare("UPDATE auth_sessions SET last_activity = NOW(), expires_at = DATE_ADD(expires_at, INTERVAL :secs SECOND) WHERE token_hash = :th AND is_active = 1");
                return $stmt->execute([':secs' => (int)$extendSeconds, ':th' => $hash]);
            } else {
                $stmt = $pdo->prepare("UPDATE auth_sessions SET last_activity = NOW() WHERE token_hash = :th AND is_active = 1");
                return $stmt->execute([':th' => $hash]);
            }
        } elseif (isset($conn)) {
            if ($extendSeconds !== null) {
                $stmt = $conn->prepare("UPDATE auth_sessions SET last_activity = NOW(), expires_at = DATE_ADD(expires_at, INTERVAL ? SECOND) WHERE token_hash = ? AND is_active = 1");
                if (!$stmt) return false;
                $secs = (int)$extendSeconds;
                $stmt->bind_param("is", $secs, $hash);
            } else {
                $stmt = $conn->prepare("UPDATE auth_sessions SET last_activity = NOW() WHERE token_hash = ? AND is_active = 1");
                if (!$stmt) return false;
                $stmt->bind_param("s", $hash);
            }
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }
    } catch (Exception $e) {
        error_log("[auth_sessions] refreshAuthSession error: " . $e->getMessage());
    }
    return false;
}

/**
 * Deactivate a session by raw token or token hash (returns bool)
 */
function deactivateAuthToken($rawToken) {
    global $pdo, $conn;
    if (!$rawToken) return false;
    $hash = hashToken($rawToken);
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("UPDATE auth_sessions SET is_active = 0 WHERE token_hash = :th");
            return $stmt->execute([':th' => $hash]);
        } elseif (isset($conn)) {
            $stmt = $conn->prepare("UPDATE auth_sessions SET is_active = 0 WHERE token_hash = ?");
            if (!$stmt) return false;
            $stmt->bind_param("s", $hash);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }
    } catch (Exception $e) {
        error_log("[auth_sessions] deactivateAuthToken error: " . $e->getMessage());
    }
    return false;
}

/**
 * Deactivate all sessions for a user
 */
function deactivateAllSessionsForUser($user_type, $user_id) {
    global $pdo, $conn;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("UPDATE auth_sessions SET is_active = 0 WHERE user_type = :ut AND user_id = :uid");
            return $stmt->execute([':ut' => $user_type, ':uid' => $user_id]);
        } elseif (isset($conn)) {
            $stmt = $conn->prepare("UPDATE auth_sessions SET is_active = 0 WHERE user_type = ? AND user_id = ?");
            if (!$stmt) return false;
            $stmt->bind_param("si", $user_type, $user_id);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }
    } catch (Exception $e) {
        error_log("[auth_sessions] deactivateAllSessionsForUser error: " . $e->getMessage());
    }
    return false;
}

/**
 * List active sessions for a user (limit optional)
 */
function getActiveSessionsForUser($user_type, $user_id, $limit = 50) {
    global $pdo, $conn;
    $out = [];
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT session_id, user_type, user_id, ip_address, user_agent, created_at, expires_at, last_activity, is_active FROM auth_sessions WHERE user_type = :ut AND user_id = :uid ORDER BY created_at DESC LIMIT :lim");
            // PDO doesn't allow binding LIMIT as string in some drivers; ensure int type
            $stmt->bindValue(':ut', $user_type);
            $stmt->bindValue(':uid', (int)$user_id, PDO::PARAM_INT);
            $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            $out = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (isset($conn)) {
            $stmt = $conn->prepare("SELECT session_id, user_type, user_id, ip_address, user_agent, created_at, expires_at, last_activity, is_active FROM auth_sessions WHERE user_type = ? AND user_id = ? ORDER BY created_at DESC LIMIT ?");
            if (!$stmt) return [];
            $stmt->bind_param("sii", $user_type, $user_id, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $out[] = $r;
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("[auth_sessions] getActiveSessionsForUser error: " . $e->getMessage());
    }
    return $out;
}
?>