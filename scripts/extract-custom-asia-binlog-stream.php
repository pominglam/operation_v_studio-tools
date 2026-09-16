<?php

declare(strict_types=1);

/**
 * Extract replayable custom_asia_orders binlog stream (DDL + full row transactions).
 *
 * Usage:
 *   php scripts/extract-custom-asia-binlog-stream.php [binlog_raw.txt] > stream.sql
 */
$rawPath = $argv[1] ?? __DIR__.'/custom_asia_orders_binlog_raw.txt';

if (! is_readable($rawPath)) {
    fwrite(STDERR, "Cannot read binlog dump: {$rawPath}\n");
    exit(1);
}

/** @var list<string> */
$lines = file($rawPath, FILE_IGNORE_NEW_LINES);
if ($lines === false || $lines === []) {
    fwrite(STDERR, "Empty binlog dump\n");
    exit(1);
}

echo "/*!50530 SET @@SESSION.PSEUDO_SLAVE_MODE=1*/;\n";
echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n";
echo "DELIMITER /*!*/;\n";

emitFormatDescription($lines);

$lineCount = count($lines);
$emitted = 0;

for ($i = 0; $i < $lineCount; $i++) {
    $line = $lines[$i];

    if (isDdlLine($line)) {
        $start = findEventStart($lines, $i);
        $end = findTerminator($lines, $i);
        emitRange($lines, $start, $end);
        $emitted++;
        $i = $end;

        continue;
    }

    if ($line === 'BEGIN') {
        $end = findCommit($lines, $i);
        if ($end === null) {
            continue;
        }

        $txn = slice($lines, findTxnStart($lines, $i), $end);
        if (transactionTouchesCustomAsia($txn)) {
            emitLines($txn);
            $emitted++;
        }

        $i = $end;
    }
}

echo "DELIMITER ;\n";
echo "/*!50530 SET @@SESSION.PSEUDO_SLAVE_MODE=0*/;\n";
echo "SET FOREIGN_KEY_CHECKS=1;\n";

fwrite(STDERR, "Emitted {$emitted} binlog chunks\n");

/**
 * @param  list<string>  $lines
 */
function emitFormatDescription(array $lines): void
{
    $inBinlog = false;
    /** @var list<string> $chunk */
    $chunk = [];

    foreach ($lines as $line) {
        if ($line === "BINLOG '") {
            $inBinlog = true;
            $chunk = [$line];

            continue;
        }

        if ($inBinlog) {
            $chunk[] = $line;
            if ($line === "'/*!*/;") {
                echo implode("\n", $chunk)."\n";

                return;
            }
        }

        if (str_starts_with($line, '# at ') && $inBinlog) {
            break;
        }
    }
}

function isDdlLine(string $line): bool
{
    if (preg_match('/DROP TABLE `custom_asia_orders`/i', $line) === 1) {
        return false;
    }

    return preg_match('/\b(create|alter)\s+table\s+`custom_asia_orders`/i', $line) === 1;
}

/**
 * @param  list<string>  $lines
 */
function findEventStart(array $lines, int $from): int
{
    for ($i = $from; $i >= 0 && $i >= $from - 10; $i--) {
        if (str_starts_with($lines[$i], '# at ')) {
            return $i;
        }
    }

    return max(0, $from - 2);
}

/**
 * @param  list<string>  $lines
 */
function findTerminator(array $lines, int $from): int
{
    $lineCount = count($lines);
    for ($i = $from; $i < $lineCount; $i++) {
        if ($lines[$i] === '/*!*/;') {
            return $i;
        }
    }

    return $from;
}

/**
 * @param  list<string>  $lines
 */
function findCommit(array $lines, int $from): ?int
{
    $lineCount = count($lines);
    for ($i = $from; $i < $lineCount; $i++) {
        if ($lines[$i] === 'COMMIT/*!*/;') {
            return $i;
        }
    }

    return null;
}

/**
 * @param  list<string>  $lines
 */
function findTxnStart(array $lines, int $beginIdx): int
{
    for ($i = $beginIdx; $i >= 0 && $i >= $beginIdx - 12; $i--) {
        if (str_starts_with($lines[$i], '# at ') && isset($lines[$i + 1]) && str_contains($lines[$i + 1], 'Query')) {
            return $i;
        }
    }

    return max(0, $beginIdx - 3);
}

/**
 * @param  list<string>  $lines
 * @return list<string>
 */
function slice(array $lines, int $start, int $end): array
{
    return array_slice($lines, $start, $end - $start + 1);
}

/**
 * @param  list<string>  $txn
 */
function transactionTouchesCustomAsia(array $txn): bool
{
    return str_contains(implode("\n", $txn), '`pricing_tool`.`custom_asia_orders`');
}

/**
 * @param  list<string>  $lines
 */
function emitLines(array $lines): void
{
    foreach ($lines as $line) {
        if (shouldSkipLine($line)) {
            continue;
        }

        echo $line."\n";
    }
}

/**
 * @param  list<string>  $lines
 */
function emitRange(array $lines, int $start, int $end): void
{
    emitLines(slice($lines, $start, $end));
}

function shouldSkipLine(string $line): bool
{
    return str_contains($line, 'check_constraint_checks')
        || str_contains($line, 'system_versioning_insert_history');
}
