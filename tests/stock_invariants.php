<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function fail_invariant(string $message): never
{
    fwrite(STDERR, '[stock-invariants] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

$driftRows = Database::fetchAll(
    'SELECT item.id,
            item.name,
            item.current_quantity,
            COALESCE(balance_totals.balance_quantity, 0) AS balance_quantity
     FROM items item
     LEFT JOIN (
         SELECT item_id, COALESCE(SUM(quantity), 0) AS balance_quantity
         FROM item_storage_balances
         GROUP BY item_id
     ) balance_totals ON balance_totals.item_id = item.id
     WHERE ROUND(item.current_quantity, 2) != ROUND(COALESCE(balance_totals.balance_quantity, 0), 2)
     ORDER BY item.name ASC
     LIMIT 50'
);

if ($driftRows !== []) {
    foreach ($driftRows as $row) {
        fwrite(
            STDERR,
            sprintf(
                "[stock-invariants] drift item #%d %s: item=%0.2f balances=%0.2f\n",
                (int) $row['id'],
                (string) $row['name'],
                (float) $row['current_quantity'],
                (float) $row['balance_quantity']
            )
        );
    }

    fail_invariant('Item totals must equal summed storage balances.');
}

$negativeRows = Database::fetchAll(
    'SELECT balance.item_id,
            item.name AS item_name,
            balance.storage_id,
            storage.name AS storage_name,
            balance.quantity
     FROM item_storage_balances balance
     INNER JOIN items item ON item.id = balance.item_id
     INNER JOIN storages storage ON storage.id = balance.storage_id
     WHERE balance.quantity < 0
     ORDER BY item.name ASC, storage.name ASC
     LIMIT 50'
);

if ($negativeRows !== []) {
    foreach ($negativeRows as $row) {
        fwrite(
            STDERR,
            sprintf(
                "[stock-invariants] negative balance item #%d %s in storage #%d %s: %0.2f\n",
                (int) $row['item_id'],
                (string) $row['item_name'],
                (int) $row['storage_id'],
                (string) $row['storage_name'],
                (float) $row['quantity']
            )
        );
    }

    fail_invariant('Storage balances must not be negative.');
}

echo '[stock-invariants] PASS' . PHP_EOL;
