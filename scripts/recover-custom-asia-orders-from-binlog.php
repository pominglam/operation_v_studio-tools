<?php

declare(strict_types=1);

/**
 * Recover custom_asia_orders rows from mariadb-binlog -v export.
 * Usage: php scripts/recover-custom-asia-orders-from-binlog.php [verbose.txt]
 */
$verbosePath = $argv[1] ?? __DIR__.'/custom_asia_orders_verbose.txt';

if (! is_readable($verbosePath)) {
    fwrite(STDERR, "Cannot read verbose binlog export: {$verbosePath}\n");
    exit(1);
}

/**
 * @return array<int, string>
 */
function columnMapForCount(int $maxIndex): array
{
    if ($maxIndex >= 40) {
        return [
            1 => 'id', 2 => 'uuid', 3 => 'customer_contact_media', 4 => 'customer_contact_value', 5 => 'product_name',
            6 => 'customer_visual_path', 7 => 'customer_visual_mime', 8 => 'customer_visual_filename',
            9 => 'product_visual_path', 10 => 'product_visual_mime', 11 => 'product_visual_filename',
            12 => 'merchandiser_order_proof_path', 13 => 'merchandiser_order_proof_mime', 14 => 'merchandiser_order_proof_filename',
            15 => 'product_cost_amount', 16 => 'product_cost_currency', 17 => 'shipping_cost_amount', 18 => 'shipping_cost_currency',
            19 => 'landed_cost_cad', 20 => 'product_fx_rate_to_cad', 21 => 'shipping_fx_rate_to_cad', 22 => 'fx_rate_date',
            23 => 'receive_delay_amount', 24 => 'receive_delay_unit', 25 => 'receive_delay_days',
            26 => 'merchandiser_price_multiplier', 27 => 'merchandiser_price_cad', 28 => 'merchandiser_commission_override_cad',
            29 => 'our_price_multiplier', 30 => 'customer_price_cad', 31 => 'our_commission_override_cad',
            32 => 'deposit_percent', 33 => 'deposit_amount_override_cad', 34 => 'customer_offer_locked_at',
            35 => 'deposit_received_at', 36 => 'merchandiser_ordered_at', 37 => 'estimated_arrival_at',
            38 => 'notes', 39 => 'created_at', 40 => 'updated_at',
        ];
    }

    if ($maxIndex >= 37) {
        return [
            1 => 'id', 2 => 'uuid', 3 => 'customer_contact_media', 4 => 'customer_contact_value', 5 => 'product_name',
            6 => 'customer_visual_path', 7 => 'customer_visual_mime', 8 => 'customer_visual_filename',
            9 => 'product_visual_path', 10 => 'product_visual_mime', 11 => 'product_visual_filename',
            12 => 'product_cost_amount', 13 => 'product_cost_currency', 14 => 'shipping_cost_amount', 15 => 'shipping_cost_currency',
            16 => 'landed_cost_cad', 17 => 'product_fx_rate_to_cad', 18 => 'shipping_fx_rate_to_cad', 19 => 'fx_rate_date',
            20 => 'receive_delay_amount', 21 => 'receive_delay_unit', 22 => 'receive_delay_days',
            23 => 'merchandiser_price_multiplier', 24 => 'merchandiser_price_cad', 25 => 'merchandiser_commission_override_cad',
            26 => 'our_price_multiplier', 27 => 'customer_price_cad', 28 => 'our_commission_override_cad',
            29 => 'deposit_percent', 30 => 'deposit_amount_override_cad', 31 => 'customer_offer_locked_at',
            32 => 'deposit_received_at', 33 => 'merchandiser_ordered_at', 34 => 'estimated_arrival_at',
            35 => 'notes', 36 => 'created_at', 37 => 'updated_at',
        ];
    }

    if ($maxIndex >= 25) {
        return [
            1 => 'id', 2 => 'uuid', 3 => 'customer_contact_media', 4 => 'customer_contact_value', 5 => 'product_name',
            6 => 'customer_visual_path', 7 => 'customer_visual_mime', 8 => 'customer_visual_filename',
            9 => 'product_visual_path', 10 => 'product_visual_mime', 11 => 'product_visual_filename',
            12 => 'product_cost_amount', 13 => 'product_cost_currency', 14 => 'shipping_cost_amount', 15 => 'shipping_cost_currency',
            16 => 'landed_cost_cad', 17 => 'product_fx_rate_to_cad', 18 => 'shipping_fx_rate_to_cad', 19 => 'fx_rate_date',
            20 => 'receive_delay_amount', 21 => 'receive_delay_unit', 22 => 'receive_delay_days',
            23 => 'notes', 24 => 'created_at', 25 => 'updated_at',
        ];
    }

    if ($maxIndex >= 22) {
        return [
            1 => 'id', 2 => 'uuid', 3 => 'customer_contact_media', 4 => 'customer_contact_value', 5 => 'product_name',
            6 => 'customer_visual_path', 7 => 'customer_visual_mime', 8 => 'customer_visual_filename',
            9 => 'product_visual_path', 10 => 'product_visual_mime', 11 => 'product_visual_filename',
            12 => 'product_cost_amount', 13 => 'product_cost_currency', 14 => 'shipping_cost_amount', 15 => 'shipping_cost_currency',
            16 => 'landed_cost_cad', 17 => 'product_fx_rate_to_cad', 18 => 'shipping_fx_rate_to_cad', 19 => 'fx_rate_date',
            20 => 'notes', 21 => 'created_at', 22 => 'updated_at',
        ];
    }

    return [
        1 => 'id', 2 => 'uuid', 3 => 'customer_contact_media', 4 => 'customer_contact_value',
        5 => 'customer_visual_path', 6 => 'customer_visual_mime', 7 => 'customer_visual_filename',
        8 => 'product_visual_path', 9 => 'product_visual_mime', 10 => 'product_visual_filename',
        11 => 'product_cost_amount', 12 => 'product_cost_currency', 13 => 'shipping_cost_amount', 14 => 'shipping_cost_currency',
        15 => 'landed_cost_cad', 16 => 'product_fx_rate_to_cad', 17 => 'shipping_fx_rate_to_cad', 18 => 'fx_rate_date',
        19 => 'notes', 20 => 'created_at', 21 => 'updated_at',
    ];
}

/** @var array<int, array<int, mixed>> */
$rows = [];
/** @var array<int, int> */
$rowColumnCounts = [];

$handle = fopen($verbosePath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Failed to open {$verbosePath}\n");
    exit(1);
}

$mode = null;
$section = null;
/** @var array<int, mixed> */
$buffer = [];

while (($line = fgets($handle)) !== false) {
    $line = rtrim($line, "\r\n");

    if (str_starts_with($line, '### INSERT INTO')) {
        $mode = 'insert';
        $section = null;
        $buffer = [];

        continue;
    }

    if (str_starts_with($line, '### UPDATE')) {
        $mode = 'update';
        $section = null;
        $buffer = [];

        continue;
    }

    if (str_starts_with($line, '### DELETE FROM')) {
        $mode = 'delete';
        $section = null;
        $buffer = [];

        continue;
    }

    if ($mode === null) {
        continue;
    }

    if ($line === '### WHERE') {
        $section = 'where';

        continue;
    }

    if ($line === '### SET') {
        if ($mode === 'update') {
            $buffer = [];
        }
        $section = 'set';

        continue;
    }

    if (! str_starts_with($line, '###   @')) {
        if ($line === '# Number of rows: 1' || str_starts_with($line, '# at ')) {
            if ($mode === 'insert' || $mode === 'update') {
                $target = $mode === 'update' ? $buffer : $buffer;
                if ($mode === 'update' && $section !== 'set' && $buffer !== []) {
                    // use last SET block only; flush happens on row boundary after SET parsed
                }
            }

            if ($mode === 'insert' && $buffer !== []) {
                $id = (int) ($buffer[1] ?? 0);
                if ($id > 0) {
                    $rows[$id] = $buffer;
                    $rowColumnCounts[$id] = max(array_keys($buffer));
                }
            }

            if ($mode === 'update' && $buffer !== []) {
                $id = (int) ($buffer[1] ?? 0);
                if ($id > 0) {
                    $incomingMax = max(array_keys($buffer));
                    $existingMax = $rowColumnCounts[$id] ?? 0;
                    if ($incomingMax >= $existingMax) {
                        $rows[$id] = $buffer;
                        $rowColumnCounts[$id] = $incomingMax;
                    } else {
                        $rows[$id] = mergeRow($rows[$id] ?? [], $buffer, replace: false);
                    }
                }
            }

            if ($mode === 'delete' && $buffer !== []) {
                $id = (int) ($buffer[1] ?? 0);
                unset($rows[$id]);
            }

            $mode = null;
            $section = null;
            $buffer = [];
        }

        continue;
    }

    if ($mode === 'update' && $section === 'where') {
        continue;
    }

    if (preg_match('/^###\s+@(\d+)=(.*)$/', $line, $matches) === 1) {
        $index = (int) $matches[1];
        $buffer[$index] = parseBinlogValue($matches[2]);
    }
}

fclose($handle);

if ($rows === []) {
    fwrite(STDERR, "No rows parsed from verbose binlog.\n");
    exit(1);
}

ksort($rows);

$outSql = __DIR__.'/custom_asia_orders_recovered.sql';
$fp = fopen($outSql, 'wb');
if ($fp === false) {
    fwrite(STDERR, "Cannot write {$outSql}\n");
    exit(1);
}

fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nTRUNCATE TABLE custom_asia_orders;\n");

foreach ($rows as $id => $values) {
    $maxIndex = $rowColumnCounts[$id] ?? max(array_keys($values));
    $columns = columnMapForCount($maxIndex);
    $mapped = mapRowToFinalColumns($values, $columns);
    $mapped['id'] = $id;

    $colNames = array_keys($mapped);
    $placeholders = [];
    $params = [];

    foreach ($colNames as $col) {
        $placeholders[] = '?';
        $params[] = $mapped[$col];
    }

    $sql = sprintf(
        'INSERT INTO custom_asia_orders (%s) VALUES (%s);',
        implode(', ', array_map(static fn (string $c): string => "`{$c}`", $colNames)),
        implode(', ', $placeholders),
    );

    fwrite($fp, bindSql($sql, $params)."\n");
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fp);

echo 'Recovered '.count($rows).' rows to '.$outSql.PHP_EOL;

foreach ($rows as $id => $values) {
    $uuid = $values[2] ?? '?';
    $product = $values[5] ?? ($values[38] ?? '?');
    echo "  id={$id} uuid={$uuid} product=".substr((string) $product, 0, 40).PHP_EOL;
}

/**
 * @param  array<int, mixed>  $existing
 * @param  array<int, mixed>  $incoming
 * @return array<int, mixed>
 */
function mergeRow(array $existing, array $incoming, bool $replace): array
{
    if ($replace || $existing === []) {
        return $incoming;
    }

    foreach ($incoming as $index => $value) {
        $existing[$index] = $value;
    }

    return $existing;
}

function parseBinlogValue(string $raw): mixed
{
    if ($raw === 'NULL') {
        return null;
    }

    if (str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
        return stripcslashes(substr($raw, 1, -1));
    }

    if (is_numeric($raw)) {
        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    return $raw;
}

/**
 * @param  array<int, mixed>  $values
 * @param  array<int, string>  $columns
 * @return array<string, mixed>
 */
function mapRowToFinalColumns(array $values, array $columns): array
{
    $maxIndex = max(array_keys($values));
    $mapped = [];

    // Early schema placed `notes` at @38 before many pricing columns existed.
    if ($maxIndex <= 41 && array_key_exists(38, $values) && is_string($values[38]) && str_contains($values[38], ' ')) {
        $mapped['notes'] = $values[38];
        unset($values[38]);
    }

    foreach ($values as $index => $value) {
        $name = $columns[$index] ?? null;
        if ($name === null) {
            continue;
        }

        if ($name === 'created_at' || $name === 'updated_at') {
            $mapped[$name] = is_int($value) ? gmdate('Y-m-d H:i:s', $value) : $value;

            continue;
        }

        if (str_ends_with($name, '_at') && is_int($value)) {
            $mapped[$name] = gmdate('Y-m-d H:i:s', $value);

            continue;
        }

        if ($name === 'fx_rate_date' || $name === 'actual_fx_rate_date' || $name === 'estimated_arrival_at' || $name === 'actual_arrival_at') {
            if (is_string($value) && preg_match('/^(\d{4}):(\d{2}):(\d{2})$/', $value, $m) === 1) {
                $mapped[$name] = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);

                continue;
            }
        }

        $mapped[$name] = $value;
    }

    return $mapped;
}

/** @param  array<int, mixed>  $params */
function bindSql(string $sql, array $params): string
{
    $i = 0;

    return preg_replace_callback('/\?/', static function () use (&$i, $params): string {
        $value = $params[$i++];

        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }, $sql) ?? $sql;
}
