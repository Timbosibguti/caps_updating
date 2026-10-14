<?php

namespace DB\Repository;

interface RepositoryInterface {
    /**
     * Найти запись по ID
     */
    public function findById($id);
    
    /**
     * Найти все записи
     */
    public function findAll();
    
    /**
     * Сохранить запись
     */
    public function save($entity);
    
    /**
     * Удалить запись
     */
    public function delete($id);
} 