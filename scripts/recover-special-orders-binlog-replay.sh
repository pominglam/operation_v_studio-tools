#!/usr/bin/env bash
set -euo pipefail

MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-root}"
MYSQL_DB="${MYSQL_DB:-pricing_tool}"
OUT_DIR="${OUT_DIR:-/out}"
BINLOG_DIR="${BINLOG_DIR:-/var/lib/mysql}"
STOP_POS="${STOP_POS:-144282636}"

mysql_cli() {
  mariadb -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" "$@"
}

echo "==> Dump binlog text (052-054, stop ${STOP_POS})..."
mariadb-binlog --stop-position="$STOP_POS" \
  "$BINLOG_DIR/binlog.000052" \
  "$BINLOG_DIR/binlog.000053" \
  "$BINLOG_DIR/binlog.000054" \
  2>/dev/null > "$OUT_DIR/custom_asia_orders_binlog_raw.txt"

echo "==> Extract custom_asia_orders chronological stream (CREATE/ALTER/rows, no DROP)..."
php "$OUT_DIR/extract-custom-asia-binlog-stream.php" "$OUT_DIR/custom_asia_orders_binlog_raw.txt" \
  > "$OUT_DIR/custom_asia_orders_stream.sql" 2> "$OUT_DIR/custom_asia_orders_stream.stats"

echo "    $(cat "$OUT_DIR/custom_asia_orders_stream.stats")"
echo "    stream lines: $(wc -l < "$OUT_DIR/custom_asia_orders_stream.sql")"

echo "==> Drop existing tables..."
mysql_cli -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS special_orders; DROP TABLE IF EXISTS custom_asia_orders; SET FOREIGN_KEY_CHECKS=1;"

echo "==> Replay chronological stream..."
mysql_cli --force < "$OUT_DIR/custom_asia_orders_stream.sql" 2> "$OUT_DIR/custom_asia_orders_stream.errors" || true

if [ -s "$OUT_DIR/custom_asia_orders_stream.errors" ]; then
  echo "    replay stderr lines: $(wc -l < "$OUT_DIR/custom_asia_orders_stream.errors")"
fi

ROW_COUNT="$(mysql_cli -Nse 'SELECT COUNT(*) FROM custom_asia_orders' 2>/dev/null || echo 0)"
echo "==> custom_asia_orders rows: ${ROW_COUNT}"

if [ "$ROW_COUNT" = "0" ]; then
  echo "ERROR: row replay produced zero rows." >&2
  tail -20 "$OUT_DIR/custom_asia_orders_stream.errors" >&2 || true
  exit 1
fi

echo "==> Rename to special_orders..."
mysql_cli <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
SET @has_old := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'custom_asia_orders' AND column_name = 'price_multiplier'
);
SET @has_new := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'custom_asia_orders' AND column_name = 'our_price_multiplier'
);
SET @sql := IF(
  @has_old > 0 AND @has_new > 0,
  'ALTER TABLE custom_asia_orders DROP COLUMN price_multiplier',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
RENAME TABLE custom_asia_orders TO special_orders;
UPDATE special_orders SET customer_visual_path = REPLACE(customer_visual_path, 'custom_asia_orders/', 'special_orders/') WHERE customer_visual_path LIKE 'custom_asia_orders/%';
UPDATE special_orders SET product_visual_path = REPLACE(product_visual_path, 'custom_asia_orders/', 'special_orders/') WHERE product_visual_path LIKE 'custom_asia_orders/%';
UPDATE special_orders SET merchandiser_order_proof_path = REPLACE(merchandiser_order_proof_path, 'custom_asia_orders/', 'special_orders/') WHERE merchandiser_order_proof_path LIKE 'custom_asia_orders/%';
SET FOREIGN_KEY_CHECKS=1;
SQL

mysql_cli -e "SELECT COUNT(*) AS special_orders_rows FROM special_orders;"
mysql_cli -e "SELECT id, uuid, LEFT(product_name,40) AS product_name, customer_contact_value FROM special_orders ORDER BY id;"
