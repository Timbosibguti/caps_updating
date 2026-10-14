<?php

namespace DB\Repository;

class RotationSummaryRepository extends AbstractRepository {
    protected $tableName = 'rotation_summary';

    /**
     * Найти запись по ID (в данном случае по составному ключу)
     */
    public function findById($id) {
        // Для rotation_summary ID - это составной ключ
        return null; // Не используется в данном контексте
    }

    /**
     * Найти все записи
     */
    public function findAll() {
        $query = "SELECT * FROM {$this->tableName} ORDER BY year DESC, month DESC, day DESC";
        // Не передаём параметры, так как в запросе нет плейсхолдеров
        return $this->fetchAll($query);
    }

    /**
     * Найти записи по дате (в БД уже одна запись на country + funnel)
     */
    public function findByDate($year, $month, $day) {
        $query = "SELECT country, funnel, capcount, capamount
                  FROM {$this->tableName}
                  WHERE year = $1 AND month = $2 AND day = $3
                  ORDER BY
                      CASE
                          WHEN funnel LIKE '%CPL%' THEN 1
                          WHEN funnel LIKE '%CPA%' THEN 2
                          ELSE 3
                      END,
                      country,
                      funnel";
        return $this->fetchAll($query, [$year, $month, $day]);
    }

    /**
     * Проверить существование записи
     */
    public function exists($country, $funnel, $day, $month, $year, $uuid) {
        $query = "SELECT 1 FROM {$this->tableName} WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 AND uuid = $6";
        $result = $this->fetchOne($query, [$country, $funnel, $day, $month, $year, $uuid]);
        return $result !== false;
    }

    /**
     * Проверить существование записи по country + funnel + date (без uuid)
     */
    public function existsByCountryFunnelDate($country, $funnel, $day, $month, $year) {
        $query = "SELECT 1 FROM {$this->tableName} WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 LIMIT 1";
        $result = $this->fetchOne($query, [$country, $funnel, $day, $month, $year]);
        return $result !== false;
    }

    /**
     * Получить uuid существующей записи по country + funnel + date
     */
    public function getUuidByCountryFunnelDate($country, $funnel, $day, $month, $year) {
        $query = "SELECT uuid FROM {$this->tableName} WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 LIMIT 1";
        $row = $this->fetchOne($query, [$country, $funnel, $day, $month, $year]);
        return $row !== false && isset($row['uuid']) ? $row['uuid'] : null;
    }

    /**
     * Вставить новую запись
     */
    public function insert($country, $funnel, $capCount, $capAmount, $day, $month, $year, $uuid, $lastNotifiedThreshold = null, $updatedAmount = null, $nodesData = null) {
        $nodesDataJson = $nodesData !== null ? json_encode($nodesData) : null;
        $query = "INSERT INTO {$this->tableName} (country, funnel, capcount, capamount, day, month, year, uuid, last_notified_threshold, updatedAmount, nodes_data) 
                  VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
        return $this->executeQuery($query, [$country, $funnel, $capCount, $capAmount, $day, $month, $year, $uuid, $lastNotifiedThreshold, $updatedAmount, $nodesDataJson]);
    }

    /**
     * Обновить существующую запись
     */
    public function update($country, $funnel, $capCount, $capAmount, $uuid, $lastNotifiedThreshold, $day, $month, $year, $updatedAmount = null, $nodesData = null) {
        $nodesDataJson = $nodesData !== null ? json_encode($nodesData) : null;
        if ($updatedAmount !== null && $updatedAmount > 0) {
            // Если есть положительная дельта - добавляем её к существующему updatedAmount
            $query = "UPDATE {$this->tableName}
                      SET capcount = $3,
                          capamount = $4,
                          uuid = $5,
                          last_notified_threshold = $6,
                          updatedAmount = COALESCE(updatedAmount, 0) + $10,
                          nodes_data = $11
                      WHERE country = $1 AND funnel = $2 AND day = $7 AND month = $8 AND year = $9";
            return $this->executeQuery($query, [$country, $funnel, $capCount, $capAmount, $uuid, $lastNotifiedThreshold, $day, $month, $year, $updatedAmount, $nodesDataJson]);
        } else {
            // Если дельты нет - не трогаем updatedAmount, передаём только нужные параметры
            $query = "UPDATE {$this->tableName}
                      SET capcount = $3,
                          capamount = $4,
                          uuid = $5,
                          last_notified_threshold = $6,
                          nodes_data = $7
                      WHERE country = $1 AND funnel = $2 AND day = $8 AND month = $9 AND year = $10";
            return $this->executeQuery($query, [$country, $funnel, $capCount, $capAmount, $uuid, $lastNotifiedThreshold, $nodesDataJson, $day, $month, $year]);
        }
    }

    public function getLastNotifiedThreshold($country, $funnel, $day, $month, $year, $uuid = null) {
        // Ищем по country + funnel + date (uuid больше не обязателен, т.к. одна запись на country+funnel)
        $query = "SELECT last_notified_threshold FROM {$this->tableName} WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 LIMIT 1";
        $row = $this->fetchOne($query, [$country, $funnel, $day, $month, $year]);
        return $row !== false ? $row['last_notified_threshold'] : null;
    }

    /**
     * Получить текущее значение capAmount для данной комбинации country + funnel за указанную дату
     * Используется для сравнения перед обновлением записи
     */
    public function getLastCapAmount($country, $funnel, $day, $month, $year) {
        // Получаем значение capAmount за текущую дату (если запись существует)
        $query = "SELECT capamount FROM {$this->tableName} WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 LIMIT 1";
        $row = $this->fetchOne($query, [$country, $funnel, $day, $month, $year]);
        if ($row !== false && isset($row['capamount'])) {
            return (int)$row['capamount'];
        }

        // Если записи нет за текущую дату, ищем последнее значение за любую предыдущую дату
        $query = "SELECT capamount FROM {$this->tableName} WHERE country = $1 AND funnel = $2 AND (year < $3 OR (year = $3 AND month < $4) OR (year = $3 AND month = $4 AND day < $5)) ORDER BY year DESC, month DESC, day DESC LIMIT 1";
        $row = $this->fetchOne($query, [$country, $funnel, $year, $month, $day]);
        return $row !== false && isset($row['capamount']) ? (int)$row['capamount'] : null;
    }

    /**
     * Получить полную запись по country + funnel + date
     * Используется для накопления прогресса capCount
     */
    public function getRecordByCountryFunnelDate($country, $funnel, $day, $month, $year) {
        $query = "SELECT capcount, capamount, uuid, last_notified_threshold, updatedAmount, nodes_data 
                  FROM {$this->tableName} 
                  WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5 LIMIT 1";
        $result = $this->fetchOne($query, [$country, $funnel, $day, $month, $year]);

        // Декодируем JSON если он есть
        if ($result !== false && isset($result['nodes_data']) && is_string($result['nodes_data'])) {
            $result['nodes_data'] = json_decode($result['nodes_data'], true) ?? [];
        } elseif ($result !== false && !isset($result['nodes_data'])) {
            $result['nodes_data'] = [];
        }

        return $result;
    }

    public function setLastNotifiedThreshold($country, $funnel, $day, $month, $year, $uuid, $threshold) {
        // Обновляем по country + funnel + date (uuid больше не обязателен для WHERE)
        $query = "UPDATE {$this->tableName} SET last_notified_threshold = $6 WHERE country = $1 AND funnel = $2 AND day = $3 AND month = $4 AND year = $5";
        return $this->executeQuery($query, [$country, $funnel, $day, $month, $year, $threshold]);
    }

    /**
     * Сохранить запись (insert или update)
     */
    public function save($entity) {
        if (!is_array($entity)) {
            return false;
        }

        // Проверяем наличие всех необходимых полей
        $requiredFields = ['country', 'funnel', 'capcount', 'capamount', 'day', 'month', 'year', 'uuid'];
        foreach ($requiredFields as $field) {
            if (!isset($entity[$field])) {
                return false;
            }
        }

        $lastNotifiedThreshold = $entity['last_notified_threshold'] ?? null;
        $updatedAmount = $entity['updatedAmount'] ?? null;
        $nodesData = $entity['nodes_data'] ?? null;

        // Проверяем существование по country + funnel + date (без uuid)
        // Если запись существует, обновляем её (даже если uuid изменился)
        if ($this->existsByCountryFunnelDate($entity['country'], $entity['funnel'], $entity['day'], $entity['month'], $entity['year'])) {
            return $this->update(
                $entity['country'],
                $entity['funnel'],
                $entity['capcount'],
                $entity['capamount'],
                $entity['uuid'],
                $lastNotifiedThreshold,
                $entity['day'],
                $entity['month'],
                $entity['year'],
                $updatedAmount,
                $nodesData
            );
        } else {
            return $this->insert(
                $entity['country'],
                $entity['funnel'],
                $entity['capcount'],
                $entity['capamount'],
                $entity['day'],
                $entity['month'],
                $entity['year'],
                $entity['uuid'],
                $lastNotifiedThreshold,
                $updatedAmount,
                $nodesData
            );
        }
    }

    /**
     * Удалить запись
     */
    public function delete($id) {
        // Не используется в данном контексте
        return false;
    }

    /**
     * Удалить записи старше указанного количества месяцев
     */
    public function deleteOldRecords($months) {
        // $months подставляется напрямую, так как это целое число из константы, SQL-инъекция невозможна
        $query = "DELETE FROM {$this->tableName}
                  WHERE (year, month, day) < (
                      EXTRACT(YEAR FROM NOW() - INTERVAL '{$months} months')::int,
                      EXTRACT(MONTH FROM NOW() - INTERVAL '{$months} months')::int,
                      EXTRACT(DAY FROM NOW() - INTERVAL '{$months} months')::int
                  )";
        return $this->executeQuery($query);
    }

}