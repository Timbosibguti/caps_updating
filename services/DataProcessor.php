<?php

namespace Services;

use Config\Constants;

class DataProcessor {
    /**
     * Добавляет UUID узла в агрегированный список, если он валиден.
     */
    private static function appendNodeUuid(array &$funnelData, array $node): void {
        $nodeUuid = $node['nodeUuid'] ?? $node['uuid'] ?? null;
        if (!is_string($nodeUuid) || $nodeUuid === '') {
            return;
        }

        if (!isset($funnelData['uuid']) || !is_array($funnelData['uuid'])) {
            $funnelData['uuid'] = [];
        }

        $funnelData['uuid'][] = $nodeUuid;
    }

    /**
     * Обрабатывает дерево данных и возвращает результаты по каждой C-ноде отдельно
     * @param array $data
     * @param callable $notifyCallback
     * @return array Структура: $result[$country][$funnel][$nodeUuid] = ['capCount' => ..., 'capAmount' => ...]
     */
    public static function processTree(array $data, callable $notifyCallback = null): array {
        $result = [];

        // Проверяем структуру данных
        if (!isset($data['data']) || !isset($data['data']['tree']) || !is_array($data['data']['tree'])) {
            return $result;
        }

        foreach ($data['data']['tree'] as $key => $row) {
            if (!is_array($row) || !isset($row['name'])) {
                continue; // Пропускаем некорректные данные
            }
            // Пропускаем отключённые страны
            if (!isset($row['isEnabled']) || !$row['isEnabled']) {
                continue;
            }
            $country = $row['name'];
            $childrens = isset($row['children']) && is_array($row['children']) ? $row['children'] : [];
            foreach ($childrens as $key => $children) {
                if (!is_array($children) || !isset($children['name'])) {
                    continue; // Пропускаем некорректные данные
                }
                // Пропускаем отключённые SPLIT CPL/CPA и т.д.
                if (!isset($children['isEnabled']) || !$children['isEnabled']) {
                    continue;
                }
                $funnel = $children['name'] === '---' ? $children['name'] . ":" . ($key + Constants::FUNNEL_INDEX_OFFSET) : $children['name'];
                $childrenC = isset($children['children']) && is_array($children['children']) ? $children['children'] : [];
                foreach ($childrenC as $ll) {
                    if (!is_array($ll)) {
                        continue; // Пропускаем некорректные данные
                    }
                    $isHaveChildren = isset($ll['children']) && is_array($ll['children']);
                    if ($isHaveChildren) {
                        $childrenCC = $ll['children'];
                        foreach ($childrenCC as $dd) {
                            if (!is_array($dd) || !isset($dd['isEnabled']) || !isset($ll['isEnabled'])) {
                                continue; // Пропускаем некорректные данные
                            }
                            // Проверяем: страна (row), SPLIT (children), родитель (ll) и дочерний (dd) должны быть включены
                            if ($dd['isEnabled'] && $ll['isEnabled'] && $children['isEnabled'] && $row['isEnabled']) {
                                $nodeUuid = $dd['nodeUuid'] ?? $dd['uuid'] ?? null;
                                if (is_string($nodeUuid) && $nodeUuid !== '') {
                                    $ddCapCount = isset($dd['capCount']) ? (int)$dd['capCount'] : 0;
                                    $ddCapAmount = isset($dd['capAmount']) ? (int)$dd['capAmount'] : 0;

                                    $countryUuid = $row['nodeUuid'] ?? $row['uuid'] ?? null;
                                    if (!isset($result[$country][$funnel])) {
                                        $result[$country][$funnel] = [
                                            'countryUuid' => $countryUuid,
                                            'nodes' => []
                                        ];
                                    }
                                    $updatedAt = $dd['updatedAt'] ?? null;
                                    $partnerUuid = $ll['nodeUuid'] ?? $ll['uuid'] ?? null;
                                    $partnerName = $ll['name'] ?? null;
                                    $partnerPql = $ll['pql'] ?? null;
                                    $result[$country][$funnel]['nodes'][$nodeUuid] = [
                                        'capCount' => $ddCapCount,
                                        'capAmount' => $ddCapAmount,
                                        'updatedAt' => $updatedAt,
                                        'partnerUuid' => $partnerUuid,
                                        'partnerName' => $partnerName,
                                        'partnerPql' => $partnerPql
                                    ];
                                }
                            }
                        }
                    } else {
                        if (!isset($children['isEnabled']) || !isset($ll['isEnabled'])) {
                            continue; // Пропускаем некорректные данные
                        }
                        // Проверяем: страна (row), SPLIT (children) и дочерний (ll) должны быть включены
                        if ($children['isEnabled'] && $ll['isEnabled'] && $row['isEnabled']) {
                            $nodeUuid = $ll['nodeUuid'] ?? $ll['uuid'] ?? null;
                            if (is_string($nodeUuid) && $nodeUuid !== '') {
                                $llCapCount = isset($ll['capCount']) ? (int)$ll['capCount'] : 0;
                                $llCapAmount = isset($ll['capAmount']) ? (int)$ll['capAmount'] : 0;

                                $countryUuid = $row['nodeUuid'] ?? $row['uuid'] ?? null;
                                if (!isset($result[$country][$funnel])) {
                                    $result[$country][$funnel] = [
                                        'countryUuid' => $countryUuid,
                                        'nodes' => []
                                    ];
                                }
                                $updatedAt = $ll['updatedAt'] ?? null;
                                $partnerUuid = $ll['nodeUuid'] ?? $ll['uuid'] ?? null;
                                $partnerName = $ll['name'] ?? null;
                                $partnerPql = $ll['pql'] ?? null;
                                $result[$country][$funnel]['nodes'][$nodeUuid] = [
                                    'capCount' => $llCapCount,
                                    'capAmount' => $llCapAmount,
                                    'updatedAt' => $updatedAt,
                                    'partnerUuid' => $partnerUuid,
                                    'partnerName' => $partnerName,
                                    'partnerPql' => $partnerPql
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }
} 