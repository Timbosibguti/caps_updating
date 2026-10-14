<?php

namespace DB\Repository;

class UserRepository extends AbstractRepository {
    protected $tableName = 'users';
    
    /**
     * Найти запись по ID
     */
    public function findById($id) {
        $query = "SELECT * FROM {$this->tableName} WHERE id = $1";
        return $this->fetchOne($query, [$id]);
    }
    
    /**
     * Найти все записи
     */
    public function findAll() {
        $query = "SELECT * FROM {$this->tableName} ORDER BY created_at DESC";
        return $this->fetchAll($query);
    }
    
    /**
     * Найти пользователя по email
     */
    public function findByEmail($email) {
        $query = "SELECT * FROM {$this->tableName} WHERE email = $1";
        $result = $this->fetchOne($query, [$email]);
        return $result !== false ? $result : null;
    }
    
    /**
     * Сохранить запись
     */
    public function save($entity) {
        if (!is_array($entity)) {
            return false;
        }
        
        if (isset($entity['id'])) {
            // Обновление существующего пользователя
            if (!isset($entity['email']) || !isset($entity['login'])) {
                return false;
            }
            $query = "UPDATE {$this->tableName} SET email = $2, login = $3 WHERE id = $1";
            return $this->executeQuery($query, [$entity['id'], $entity['email'], $entity['login']]);
        } else {
            // Создание нового пользователя
            if (!isset($entity['email']) || !isset($entity['login'])) {
                return false;
            }
            $query = "INSERT INTO {$this->tableName} (email, login) VALUES ($1, $2) ON CONFLICT (email) DO NOTHING RETURNING *";
            return $this->fetchOne($query, [$entity['email'], $entity['login']]);
        }
    }
    
    /**
     * Добавить пользователя по email
     */
    public function addUserByEmail($email) {
        $login = explode('@', $email)[0];
        $query = "INSERT INTO {$this->tableName} (email, login) VALUES ($1, $2) ON CONFLICT (email) DO NOTHING RETURNING *";
        $result = $this->fetchOne($query, [$email, $login]);
        return $result !== false;
    }
    
    /**
     * Удалить запись
     */
    public function delete($id) {
        $query = "DELETE FROM {$this->tableName} WHERE id = $1";
        return $this->executeQuery($query, [$id]);
    }
} 