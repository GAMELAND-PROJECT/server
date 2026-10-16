<?php
require_once __DIR__ . '/../config.php';

class Auth {

    private static function getAuthSignature() {
        $secret = defined('PANEL_PASS') ? PANEL_PASS : 'GL_SECRET_2026';
        return hash_hmac('sha256', PANEL_USER . ':' . $secret, 'GAMELAND_PANEL_AUTH_SALT_2026');
    }

    public static function isLoggedIn() {
        if (isset($_SESSION['gameland_logged_in']) && $_SESSION['gameland_logged_in'] === true) {
            return true;
        }
        if (!empty($_COOKIE['gl_auth']) && hash_equals(self::getAuthSignature(), (string)$_COOKIE['gl_auth'])) {
            $_SESSION['gameland_logged_in'] = true;
            $_SESSION['gameland_user'] = PANEL_USER;
            $_SESSION['last_activity'] = time();
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return true;
        }
        return false;
    }

    public static function check() {
        if (!self::isLoggedIn()) {
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

            // Set signed fallback cookie (cross-compatible across all PHP session backends)
            $expires = time() + SESSION_TIMEOUT;
            if (PHP_VERSION_ID >= 70300) {
                setcookie('gl_auth', self::getAuthSignature(), [
                    'expires' => $expires,
                    'path' => '/',
                    'domain' => '',
                    'secure' => false,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                setcookie('gl_auth', self::getAuthSignature(), $expires, '/', '', false, true);
            }
            return true;
        }
        return false;
    }

    public static function logout() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        if (PHP_VERSION_ID >= 70300) {
            setcookie('gl_auth', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie('gl_auth', '', time() - 3600, '/', '', false, true);
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
