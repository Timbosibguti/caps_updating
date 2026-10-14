# Настройка Cron для caps_updating

## Описание
Настройка автоматического запуска `fetch_data.php` каждую минуту через cron.

## Быстрая настройка

### 1. Запустить контейнеры
```bash
docker-compose up -d
```

### 2. Сделать скрипт исполняемым
```bash
chmod +x run_fetch.sh
```

### 3. Протестировать скрипт
```bash
./run_fetch.sh
```

### 4. Настроить cron (каждую минуту)
```bash
crontab -e
```

Добавить строку:
```
* * * * * /полный/путь/к/проекту/caps_updating/run_fetch.sh
```

### 5. Проверить работу
```bash
# Посмотреть cron jobs
crontab -l

# Проверить логи
tail -f fetch_cron.log
```

## Проверка работы
- Логи cron: `tail -f fetch_cron.log`
- Статус контейнеров: `docker-compose ps` 