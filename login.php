<?php
// Unified login page + handler
// - GET: show login form
// - POST: authenticate against admins and janitors tables, set session, return JSON (for AJAX) or redirect (for normal POST)

require_once __DIR__ . '/includes/config.php'; // provides $pdo, $conn and session (includes/config.php starts session)

// Helper to detect if request is AJAX (fetch)
function is_ajax_request(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) return true;
    return false;
}

// Normalize DB row to id/email/password_hash
function normalize_row(?array $row, string $idKey): ?array {
    if (!$row) return null;
    $r = [];
    $r['id'] = isset($row[$idKey]) ? (int)$row[$idKey] : (isset($row['id']) ? (int)$row['id'] : null);
    if (isset($row['password_hash'])) $r['password_hash'] = $row['password_hash'];
    elseif (isset($row['password'])) $r['password_hash'] = $row['password'];
    elseif (isset($row['passwd'])) $r['password_hash'] = $row['passwd'];
    elseif (isset($row['pwd'])) $r['password_hash'] = $row['pwd'];
    else $r['password_hash'] = null;
    $r['email'] = $row['email'] ?? '';
    return $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept form data via fetch/form POST
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST['remember']) ? true : false;

    $errors = [];
    if ($email === '') $errors['email'] = 'Email is required';
    if ($password === '') $errors['password'] = 'Password is required';

    if (!empty($errors)) {
        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        } else {
            // Non-AJAX: redirect back with an error query param (simple)
            header('Location: login.php?error=' . urlencode('Please fill in required fields'));
            exit;
        }
    }

    try {
        $user = null;
        $role = null;

        // Prefer PDO if available
        if ($pdo instanceof PDO) {
            // Try admins first
            $stmt = $pdo->prepare("SELECT admin_id, email, password_hash, password, passwd, pwd FROM admins WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $user = normalize_row($row, 'admin_id');
                $role = 'admin';
            } else {
                // janitors
                $stmt = $pdo->prepare("SELECT janitor_id, email, password_hash, password, passwd, pwd FROM janitors WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $user = normalize_row($row, 'janitor_id');
                    $role = 'janitor';
                }
            }
        } else {
            // mysqli fallback
            $stmt = $conn->prepare("SELECT admin_id, email, password_hash, password, passwd, pwd FROM admins WHERE email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row) {
                    $user = normalize_row($row, 'admin_id');
                    $role = 'admin';
                }
                $stmt->close();
            }
            if (!$user) {
                $stmt = $conn->prepare("SELECT janitor_id, email, password_hash, password, passwd, pwd FROM janitors WHERE email = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    if ($row) {
                        $user = normalize_row($row, 'janitor_id');
                        $role = 'janitor';
                    }
                    $stmt->close();
                }
            }
        }

        if (!$user) {
            // generic error message to avoid enumeration
            if (is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                exit;
            } else {
                header('Location: login.php?error=' . urlencode('Invalid email or password'));
                exit;
            }
        }

        $stored = $user['password_hash'] ?? null;
        $verified = false;
        if ($stored) {
            if (password_verify($password, $stored)) $verified = true;
            else {
                // fallback (legacy plain text)
                if (hash_equals((string)$stored, (string)$password)) $verified = true;
            }
        }

        if (!$verified) {
            if (is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                exit;
            } else {
                header('Location: login.php?error=' . urlencode('Invalid email or password'));
                exit;
            }
        }

        // Authentication OK -> set session keys used in your app
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        if ($role === 'admin') {
            unset($_SESSION['janitor_id']);
            $_SESSION['admin_id'] = $user['id'];
        } else {
            unset($_SESSION['admin_id']);
            $_SESSION['janitor_id'] = $user['id'];
        }
        $_SESSION['email'] = $email;
        $_SESSION['role'] = $role;
        session_regenerate_id(true);

        // Remember me: extend session cookie lifetime by re-setting cookie
        if ($remember) {
            $lifetime = 30 * 24 * 60 * 60;
            $params = session_get_cookie_params();
            $secure = !empty($params['secure']);
            $httponly = !empty($params['httponly']);
            setcookie(session_name(), session_id(), time() + $lifetime, $params['path'] ?? '/', $params['domain'] ?? '', $secure, $httponly);
        }

        $redirect = ($role === 'admin') ? 'admin-dashboard.php' : 'janitor-dashboard.php';

        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => 'Authentication successful', 'role' => $role, 'redirect' => $redirect]);
            exit;
        } else {
            header('Location: ' . $redirect);
            exit;
        }

    } catch (Exception $e) {
        error_log("[login.php] Exception: " . $e->getMessage());
        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Server error']);
            exit;
        } else {
            http_response_code(500);
            exit("Server error");
        }
    }
}

// ---------- Otherwise show the login HTML (GET) ----------
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Smart Trashbin - Login</title>
  <link rel="stylesheet" href="css/login.css">
  <link rel="stylesheet" href="css/header.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
  <div id="scrollProgress" class="scroll-progress"></div>
  <!-- Navigation Header -->
  <header class="header">
    <div class="header-container">
      <div class="logo-section">
        <div class="logo-wrapper">
          <svg class="animated-logo" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <rect x="30" y="35" width="40" height="50" rx="6" fill="#16a34a"/>
            <rect x="25" y="30" width="50" height="5" fill="#15803d"/>
            <rect x="40" y="20" width="20" height="8" rx="2" fill="#22c55e"/>
            <line x1="40" y1="45" x2="40" y2="80" stroke="#f0fdf4" stroke-width="3" />
            <line x1="50" y1="45" x2="50" y2="80" stroke="#f0fdf4" stroke-width="3" />
            <line x1="60" y1="45" x2="60" y2="80" stroke="#f0fdf4" stroke-width="3" />
          </svg>
        </div>
        <div class="logo-text-section">
          <h1 class="brand-name">Smart Trashbin</h1>
          <p class="header-subtitle">Intelligent Waste Management System</p>
        </div>
      </div>
      <nav class="nav-center">
        <a href="index.php" class="nav-link">Home</a>
        <a href="about.php" class="nav-link">About</a>
        <a href="contact.php" class="nav-link">Contact</a>
        <a href="features.php" class="nav-link">Features</a>
      </nav>
      <nav class="nav-buttons">
        <a href="registration.php" class="btn btn-signup">
          <i class="fas fa-user-plus"></i> Sign Up
        </a>
      </nav>
    </div>
  </header>

  <!-- Login Container -->
  <div class="login-container">
    <div class="background-circle background-circle-1"></div>
    <div class="background-circle background-circle-2"></div>
    <div class="background-circle background-circle-3"></div>

    <div class="login-wrapper">
      <!-- Left Side -->
      <div class="login-branding">
        <div class="branding-content">
          <div class="circle circle-1"></div>
          <div class="circle circle-2"></div>
          <div class="circle circle-3"></div>

          <div class="branding-box">
            <div class="logo-circle">
              <i class="fas fa-trash-alt"></i>
            </div>
            <h1>Smart Trashbin</h1>
            <p>Intelligent Waste Management System</p>
          </div>

          <div class="features-list">
            <div class="feature-item">
              <div class="feature-icon-container"><i class="fas fa-chart-line"></i></div>
              <span>Real-time Monitoring</span>
            </div>
            <div class="feature-item">
              <div class="feature-icon-container"><i class="fas fa-bell"></i></div>
              <span>Automated Alerts</span>
            </div>
            <div class="feature-item">
              <div class="feature-icon-container"><i class="fas fa-chart-bar"></i></div>
              <span>Analytics Dashboard</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Side -->
      <div class="login-form-section">
        <div class="form-container">
          <div class="form-header">
            <h2><span class="header-highlight">Login</span></h2>
            <p>Sign in to access your dashboard</p>
          </div>

          <!-- Unified Login Form -->
          <form id="loginForm" class="auth-form" autocomplete="off">
            <div class="form-group">
              <label for="email">Email Address</label>
              <div class="input-wrapper">
                <input type="email" id="email" name="email" placeholder="Enter your email" required>
              </div>
              <span class="error-message" id="emailError"></span>
            </div>

            <div class="form-group">
              <label for="password">Password</label>
              <div class="input-wrapper">
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
                <button type="button" class="toggle-password" id="togglePassword"><i class="fas fa-eye"></i></button>
              </div>
              <span class="error-message" id="passwordError"></span>
            </div>

            <div class="form-options">
              <label class="remember-me"><input type="checkbox" name="remember" id="remember"><span>Remember me</span></label>
              <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-primary">Sign In</button>
            <div class="auth-footer">
              <p>Don't have an account? <a href="registration.php">Sign up now</a></p>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <div class="footer-content">
      <div class="footer-links">
        <a href="#" onclick="openInfoModal('privacyModal'); return false;">Privacy Policy</a>
        <span class="separator">•</span>
        <a href="#" onclick="openInfoModal('termsModal'); return false;">Terms of Service</a>
        <span class="separator">•</span>
        <a href="#" onclick="openInfoModal('supportModal'); return false;">Support</a>
      </div>
       <span id="footerText" class="footer-text"></span>
      <p class="footer-copyright">&copy; 2025 Smart Trashbin. All rights reserved.</p>
    </div>
  </div>

  <!-- JavaScript -->
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      const loginForm = document.getElementById("loginForm");
      const emailInput = document.getElementById("email");
      const passwordInput = document.getElementById("password");
      const togglePassword = document.getElementById("togglePassword");

      togglePassword.addEventListener("click", e => {
        e.preventDefault();
        const type = passwordInput.type === "password" ? "text" : "password";
        passwordInput.type = type;
        togglePassword.querySelector("i").classList.toggle("fa-eye");
        togglePassword.querySelector("i").classList.toggle("fa-eye-slash");
      });

      emailInput.addEventListener("input", () => { document.getElementById("emailError").textContent = ""; });
      passwordInput.addEventListener("input", () => { document.getElementById("passwordError").textContent = ""; });

      loginForm.addEventListener("submit", async e => {
        e.preventDefault();
        const formData = new FormData(loginForm);
        const rememberMe = document.getElementById("remember").checked;

        try {
          const response = await fetch("login.php", { method: "POST", body: formData, credentials: 'same-origin' });
          // parse as json; if server returns non-json, this will throw and fall back to generic error
          const data = await response.json();
          if (data.success) {
            if (rememberMe) {
              document.cookie = `email=${encodeURIComponent(emailInput.value)}; max-age=${30 * 24 * 60 * 60}; path=/`;
            }

            // try a quick session warm-up; non-blocking
            try {
              const sessionForm = new FormData();
              sessionForm.append('email', emailInput.value || '');
              const sessionFetch = fetch('api/session-init.php', { method: 'POST', body: sessionForm, credentials: 'same-origin' })
                .then(r => r.ok ? r.json().catch(()=>({success:true})) : {success:false});
              await Promise.race([sessionFetch, new Promise(res => setTimeout(()=>res({success:false}),700))]);
            } catch (err) { console.warn('[session-init] error', err); }

            showNotification(data.message || "Welcome!", "success");
            setTimeout(() => { window.location.href = data.redirect || "dashboard.php"; }, 300);
          } else {
            if (data.errors && data.errors.email) showNotification(data.errors.email, "error");
            if (data.errors && data.errors.password) showNotification(data.errors.password, "error");
            if (data.message) showNotification(data.message, "error");
          }
        } catch (err) {
          console.error('Login fetch/parse error', err);
          showNotification("Login failed. Please try again.", "error");
        }
      });

      // Pre-fill email from cookie if exists
      function getCookie(name) {
        const nameEQ = name + "=";
        const cookies = document.cookie.split(';');
        for(let c of cookies) {
          c = c.trim();
          if(c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length));
        }
        return null;
      }
      const savedEmail = getCookie("email");
      if (savedEmail) {
        emailInput.value = savedEmail;
        document.getElementById("remember").checked = true;
      }
    });

    // FOOTER DYNAMIC TEXT
    function initFooterText() {
      const footerText = document.getElementById('footerText');
      if (footerText) {
        const messages = [
          'Making waste management smarter, one bin at a time.',
          'Powered by IoT technology and sustainable innovation.',
          'Join us in creating cleaner, greener communities.',
          'Real-time monitoring for a cleaner tomorrow.'
        ];
        let currentIndex = 0;
        function updateFooterText() {
          footerText.style.opacity = '0';
          setTimeout(() => {
            footerText.textContent = messages[currentIndex];
            footerText.style.opacity = '1';
            currentIndex = (currentIndex + 1) % messages.length;
          }, 500);
        }
        footerText.textContent = messages[0];
        setInterval(updateFooterText, 5000);
      }
    }
    document.addEventListener('DOMContentLoaded', initFooterText);

    function showNotification(msg, type) {
      let n = document.getElementById("notificationMessage");
      if (!n) {
        n = document.createElement("div");
        n.id = "notificationMessage";
        n.style.cssText = "position:fixed;top:20px;right:20px;padding:16px;border-radius:8px;font-weight:600;z-index:9999;";
        document.body.appendChild(n);
      }
      n.textContent = msg;
      n.style.backgroundColor = type === "success" ? "#dcfce7" : "#fee2e2";
      n.style.color = type === "success" ? "#166534" : "#991b1b";
      n.style.borderLeft = type === "success" ? "4px solid #16a34a" : "4px solid #dc2626";
      n.style.display = "block";
      setTimeout(() => n.remove(), 4000);
    }
  </script>
  <script src="js/scroll-progress.js"></script>
  <?php include 'includes/info-modals.php'; ?>
</body>
</html>