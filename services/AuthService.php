<?php

namespace Services;

use Config\Constants;

class AuthService {
    /**
     * Проверяет, авторизован ли пользователь
     * @return bool
     */
    public static function isAuthenticated(): bool {
        if (!defined('SESSION_ID')) {
            define('SESSION_ID', 'my_project');
        }
        return isset($_SESSION[SESSION_ID]['logged']);
    }

    /**
     * Требует авторизации, иначе завершает выполнение с HTTP 401 и сообщением
     * @return void
     */
    public static function requireAuth(): void {
        if (!self::isAuthenticated()) {
            // Редирект на Google OAuth, если не авторизован
            require_once __DIR__ . '/../auth.php';
            exit;
        }
    }
} 