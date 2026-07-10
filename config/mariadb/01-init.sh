#!/usr/bin/env bash
set -euo pipefail

mariadb -u root -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS coasterpedia;
    CREATE USER IF NOT EXISTS 'coasterpedia'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
    GRANT ALL PRIVILEGES ON coasterpedia.* TO 'coasterpedia'@'%';

    CREATE DATABASE IF NOT EXISTS cargo_db;
    CREATE USER IF NOT EXISTS 'cargo_user'@'%' IDENTIFIED BY '${CARGO_MYSQL_PASSWORD}';
    GRANT ALL PRIVILEGES ON cargo_db.* TO 'cargo_user'@'%';

    CREATE DATABASE IF NOT EXISTS coasterpedia_analytics;
    CREATE USER IF NOT EXISTS 'coasterpedia_analytics'@'%' IDENTIFIED BY '${MATOMO_DATABASE_PASSWORD}';
    GRANT ALL PRIVILEGES ON coasterpedia_analytics.* TO 'coasterpedia_analytics'@'%';

    CREATE DATABASE IF NOT EXISTS coasterpedia_tools;
    CREATE USER IF NOT EXISTS 'coasterpedia_tools'@'%' IDENTIFIED BY '${COASTERPEDIA_TOOLS_MYSQL_PASSWORD}';
    GRANT ALL PRIVILEGES ON coasterpedia_tools.* TO 'coasterpedia_tools'@'%';

    CREATE USER IF NOT EXISTS 'backup'@'%' IDENTIFIED BY '${BACKUP_MYSQL_PASSWORD}'; 
    GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, PROCESS, RELOAD, BINLOG MONITOR ON *.* TO 'backup'@'%';
    GRANT REPLICATION SLAVE ON *.* TO 'backup'@'%';
    GRANT INSERT, UPDATE, DELETE ON coasterpedia.dr_heartbeat TO 'backup'@'%';
    FLUSH PRIVILEGES;

    FLUSH PRIVILEGES;
EOSQL