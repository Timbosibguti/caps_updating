<?php

namespace DB;

use Config\Config;

class DatabaseInitializer {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Инициализация базы данных: создание схемы и всех таблиц
     */
    public function initialize() {
        // Создаем схему если она не существует
        $this->db->query("CREATE SCHEMA IF NOT EXISTS updates_by_caps;");
        $this->db->query("SET search_path TO updates_by_caps;");
        
        // Создаем все таблицы
        $this->createTables();
        
        // Создаем индексы
        $this->createIndexes();
        
        // Подготавливаем statements
        $this->prepareStatements();
    }
    
    /**
     * Создание всех необходимых таблиц
     */
    private function createTables() {
        // Таблица rotation_summary
        $this->db->query("CREATE TABLE IF NOT EXISTS rotation_summary (
            country TEXT,
            funnel TEXT,
            capcount INTEGER,
            capamount INTEGER,
            day INTEGER,
            month INTEGER,
            year INTEGER,
            uuid TEXT,
            last_notified_threshold INTEGER,
            updatedAmount INTEGER,
            nodes_data JSONB,
            PRIMARY KEY (country, funnel, day, month, year, uuid)
        );");
        
        // Добавляем столбец updatedAmount если его нет (для существующих таблиц)
        $this->db->query("ALTER TABLE rotation_summary ADD COLUMN IF NOT EXISTS updatedAmount INTEGER;");
        
        // Добавляем столбец nodes_data для хранения деталей по каждой C-ноде (UUID → capCount/capAmount)
        $this->db->query("ALTER TABLE rotation_summary ADD COLUMN IF NOT EXISTS nodes_data JSONB;");
        
        // Таблица users
        $this->db->query("CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            email TEXT UNIQUE NOT NULL,
            login TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT NOW()
        );");
    }
    
    /**
     * Создание индексов для всех таблиц
     */
    private function createIndexes() {
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_rotation_summary_date ON rotation_summary(year, month, day);");
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);");
    }
    
    /**
     * Подготовка prepared statements
     */
    private function prepareStatements() {
        $conn = $this->db->getConnection();
        pg_prepare($conn, "check_rotation", "SELECT 1 FROM rotation_summary WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 AND uuid = $6");
        pg_prepare($conn, "insert_rotation", "INSERT INTO rotation_summary (country, funnel, capcount, capamount, day, month, year, uuid, last_notified_threshold) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)");
        pg_prepare($conn, "update_rotation", "UPDATE rotation_summary SET capcount = $3, capamount = $4, uuid = $5, last_notified_threshold = $6 WHERE country = $1 AND funnel = $2 AND day = $7 AND month = $8 AND year = $9");
    }
} 