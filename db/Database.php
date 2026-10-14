<?php

namespace DB;

use Config\Config;

class Database {
    private static $instance = null;
    private $conn;

    // Конструктор: подключение к базе данных
    private function __construct() {
        $host = Config::get('DB_HOST', 'localhost');
        $db = Config::get('DB_NAME', 'postgres');
        $user = Config::get('DB_USER', 'postgres');
        $pass = Config::get('DB_PASSWORD', '');
        $port = Config::get('DB_PORT', '5432');
        $conn_string = "host=$host port=$port dbname=$db user=$user password=$pass";
        $this->conn = pg_connect($conn_string);
        if (!$this->conn) {
            throw new \Exception("Ошибка подключения к базе данных");
        }
    }



    // Получить экземпляр Database (Singleton)
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Выполнить обычный SQL-запрос
    public function query($query) {
        $result = @pg_query($this->conn, $query);
        if ($result === false) {
            error_log("Ошибка выполнения SQL: $query");
        }
        return $result;
    }

    // Выполнить SQL-запрос с параметрами
    public function queryParams($query, $params) {
        $result = @pg_query_params($this->conn, $query, $params);
        if ($result === false) {
            error_log("Ошибка выполнения SQL с параметрами: $query | Параметры: " . json_encode($params));
        }
        return $result;
    }

    // Получить внутренний ресурс соединения
    public function getConnection() {
        return $this->conn;
    }



    // Закрыть соединение с базой данных
    public function close() {
        if ($this->conn) {
            pg_close($this->conn);
            $this->conn = null;
            self::$instance = null;
        }
    }
} 