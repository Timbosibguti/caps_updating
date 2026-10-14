# caps_updating

Сервис на PHP для мониторинга caps/rotation, OAuth через Google, уведомления в Slack и сохранение сводок в PostgreSQL.

## Требования

- Docker и Docker Compose **или** PHP 8.x + PostgreSQL + Composer
- Google OAuth Client (Web)
- Slack Incoming Webhook (и при необходимости прокси для второго workspace)

## Быстрый старт (Docker)

1. Скопируйте окружение:

   ```bash
   cp .env.example .env
   ```

2. Заполните `.env` (БД, Google OAuth, Slack, `API_TOKEN` / `API_URL` для `fetch_data.php`).

3. Запуск:

   ```bash
   docker compose -f docker-compose.dev.yml up -d --build
   ```

4. UI: `http://localhost:8002/` (порт см. в `docker-compose.dev.yml`).

Файл `docker-compose.yml` в `.gitignore` — для локального/прод окружения скопируйте `docker-compose.dev.yml` или храните свой compose отдельно.

## Cron / fetch

Скрипт `fetch_data.php` подтягивает данные из API и шлёт уведомления. Пример обёртки: `run_fetch.sh`, `loop_runner.sh`, `check_cron.sh`.

## Структура

- `index.php` — веб-интерфейс
- `auth.php` — Google OAuth
- `fetch_data.php` — фоновая выгрузка и Slack
- `db/` — PostgreSQL
- `services/` — обработка данных и уведомления

## Безопасность

- Не коммитьте `.env`, `docker-compose.yml` с реальными паролями, `rotation.json` (дамп дерева rotation), `history-slack.txt`.
- Секреты, которые когда-либо попали в git, нужно **ротировать** (БД, API keys, Slack, Google OAuth).

## Лицензия

MIT — см. [LICENSE](LICENSE).
