<?php
// Minimal session initializer for fast client-side "session warm-up".
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(['success' => false, 'message' => 'Method not allowed']);
	exit;
}

// start session as early & light as possible
session_start();

// sanitize and accept minimal fields to set immediately
$email = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_SANITIZE_EMAIL) : null;
$role  = isset($_POST['role'])  ? preg_replace('/[^a-z0-9_\-]/i', '', $_POST['role']) : null;

if ($email) $_SESSION['email'] = $email;
if ($role)  $_SESSION['role']  = $role;
$_SESSION['session_init_at'] = time();

// write and close right away so the session lock is released fast
session_write_close();

// return immediately — no heavy ops here
echo json_encode(['success' => true]);
