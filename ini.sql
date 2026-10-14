-- Создание схемы (если используется)
CREATE SCHEMA IF NOT EXISTS updates_by_caps;
SET search_path TO updates_by_caps;

-- Таблица rotation_summary
CREATE TABLE IF NOT EXISTS rotation_summary (
    country TEXT,
    funnel TEXT,
    capcount INTEGER,
    capamount INTEGER,
    day INTEGER,
    month INTEGER,
    year INTEGER,
    uuid TEXT,
    last_notified_threshold INTEGER,
    nodes_data TEXT DEFAULT NULL,
    PRIMARY KEY (country, funnel, day, month, year, uuid)
);

-- Таблица users
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email TEXT UNIQUE NOT NULL,
    login TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Индексы
CREATE INDEX IF NOT EXISTS idx_rotation_summary_date ON rotation_summary(year, month, day);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email); 
