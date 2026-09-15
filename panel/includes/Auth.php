<?php
require_once __DIR__ . '/../config.php';

class Auth {
    public static function check() {
        if (!isset($_SESSION['gameland_logged_in']) || $_SESSION['gameland_logged_in'] !== true) {
            header('Location: login.php');
            exit;
        }

        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
            self::logout();
            header('Location: login.php?msg=expired');
            exit;
        }
        $_SESSION['last_activity'] = time();
    }

    public static function login($username, $password) {
        if ($username !== PANEL_USER) {
            return false;
        }

        $valid = false;
        if (defined('PANEL_PASS')) {
            // Check plain text comparison or hash
            if ($password === PANEL_PASS || (str_starts_with(PANEL_PASS, '$2y$') && password_verify($password, PANEL_PASS))) {
                $valid = true;
            }
        } elseif (defined('PANEL_PASS_HASH')) {
            if (password_verify($password, PANEL_PASS_HASH)) {
                $valid = true;
            }
        }

        if ($valid) {
            $_SESSION['gameland_logged_in'] = true;
            $_SESSION['gameland_user'] = $username;
            $_SESSION['last_activity'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            return true;
        }
        return false;
    }

    public static function logout() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    public static function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify_csrf($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}
