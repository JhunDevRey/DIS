#!/bin/sh
# Fresh copy of the throwaway test DB (cltest -> sectest) with known local-only passwords.
SP="${DIS_TEST_DIR:?set DIS_TEST_DIR to the rig folder}"
B=${MYSQL_BIN_DIR:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin}; P=${PHP_BIN:-php}
M="$B/mysql -h127.0.0.1 -P3399 -uroot"
$M -e "DROP DATABASE IF EXISTS sectest; CREATE DATABASE sectest CHARACTER SET utf8mb4;"
"$B/mysqldump" -h127.0.0.1 -P3399 -uroot cltest 2>/dev/null | $M sectest
$M sectest < $SP/me.sql
A=$($P -r "echo password_hash('StaffTest#2026', PASSWORD_DEFAULT);"); C=$($P -r "echo password_hash('ClientTest#2026', PASSWORD_DEFAULT);")
$M sectest -e "UPDATE admin_users SET password_hash='$A' WHERE id=1; UPDATE clients SET password_hash='$C', is_active=1; DELETE FROM login_attempts;"
for f in "$@"; do $M sectest < "$f" || exit 1; done
rm -f $SP/jar_*.txt
echo "db reset"
