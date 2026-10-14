#!/bin/bash

# Запуск бесконечного цикла для fetch_data.php
/bin/bash /var/www/html/loop_runner.sh &

# Запуск supervisor
exec /usr/bin/supervisord 