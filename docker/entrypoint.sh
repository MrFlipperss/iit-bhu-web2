#!/bin/sh
# Waits for MySQL, builds the schema + demo data on the very first start, then runs Apache.
set -e
cd /var/www/app
i=0
until php database/init_if_empty.php; do
  i=$((i+1))
  if [ "$i" -ge 30 ]; then echo "Database not reachable, starting anyway"; break; fi
  echo "Waiting for the database ($i)"; sleep 2
done
exec apache2-foreground
