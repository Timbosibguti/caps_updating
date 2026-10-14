#!/bin/bash

# Скрипт для запуска fetch_data.php через Docker
# Этот скрипт будет запускаться cron'ом на хост-машине каждую минуту

# Переходим в директорию проекта
cd "$(dirname "$0")"

# Логируем начало выполнения
echo "$(date): Starting fetch_data.php execution" >> ./fetch_cron.log

# Запускаем fetch_data.php напрямую
/usr/local/bin/php fetch_data.php
exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo "$(date): fetch_data.php executed successfully" >> ./fetch_cron.log
else
    echo "$(date): fetch_data.php failed with exit code $exit_code" >> ./fetch_cron.log
fi 