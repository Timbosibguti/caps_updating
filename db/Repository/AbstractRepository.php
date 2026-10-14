<?php

namespace DB\Repository;

use DB\Database;

abstract class AbstractRepository implements RepositoryInterface {
    protected $db;
    protected $tableName;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Выполнить запрос с параметрами
     */
    protected function executeQuery($query, $params = []) {
        if (empty($params)) {
            return $this->db->query($query);
        }
        return $this->db->queryParams($query, $params);
    }
    
    /**
     * Получить одну запись
     */
    protected function fetchOne($query, $params = []) {
        $result = $this->executeQuery($query, $params);
        if ($result === false) {
            return false;
        }
        $row = pg_fetch_assoc($result);
        if ($row === false) {
            return false;
        }
        return $row;
    }
    
    /**
     * Получить все записи
     */
    protected function fetchAll($query, $params = []) {
        $result = $this->executeQuery($query, $params);
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
} 