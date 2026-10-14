#!/bin/bash

while true; do
    /usr/local/bin/php /var/www/html/fetch_data.php > /dev/null 2>&1 || true
    sleep 300
done