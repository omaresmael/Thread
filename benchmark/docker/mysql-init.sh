#!/bin/sh
# Loads data/orders.sql (written for SQLite) into the benchmark's MySQL at its first start: the table and index in
# MySQL syntax, then the file's INSERT statements as they are.
{
    echo "CREATE TABLE orders (id INT PRIMARY KEY, customer_id INT NOT NULL, reference VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL, total_cents INT NOT NULL, created_at DATETIME NOT NULL,
        INDEX orders_customer_id_index (customer_id));"
    sed -n '/^INSERT/,$p' /bench-data/orders.sql
} | mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"
