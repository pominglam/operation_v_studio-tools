<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:'.__DIR__.'/../database/database.sqlite');
$row = $pdo->query('select id, payload from jobs order by id desc limit 1')->fetch(PDO::FETCH_ASSOC);

if (! $row) {
    echo "no jobs\n";
    exit(0);
}

echo "job_id={$row['id']}\n";
$payload = json_decode((string) $row['payload'], true);
if (! is_array($payload)) {
    echo "payload_not_json\n";
    exit(0);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";


