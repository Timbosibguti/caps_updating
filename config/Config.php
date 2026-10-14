<?php

namespace Config;

use Dotenv\Dotenv;

class Config {
    private static $cache = [];
    private static $loaded = false;

    /**
     * Получить значение переменной окружения
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public static function get($key, $default = null) {
        if (!self::$loaded) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env');
            $dotenv->load();
            self::$loaded = true;
        }
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $value = getenv($key);
        if ($value === false) {
            $value = $default;
        }
        self::$cache[$key] = $value;
        return $value;
    }
} 