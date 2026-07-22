<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function go_live_reset_has_internal_user(): bool {
    $user = current_user();
    return is_array($user) && (($user['user_type'] ?? '') === 'INTERNAL');
}

function go_live_reset_count_table(string $table, string $where = '1=1', array $params = []): int {
    if (!db_table_exists($table)) {
        return 0;
    }
    $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', str_replace('`', '', $table), $where);
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function go_live_reset_opening_journal_ids(): array {
    if (!db_table_exists('gl_journal')) {
        return [];
    }
    $sql = "SELECT journal_id
              FROM gl_journal
             WHERE source_type = 'MANUAL'
               AND (
                     reference_number LIKE 'OPEN-%'
                  OR memo LIKE '%Opening balance%'
                  OR memo LIKE '%opening capital%'
               )
             ORDER BY journal_id ASC";
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_map('intval', $rows ?: [])));
}

function go_live_reset_customer_user_ids(): array {
    if (!db_table_exists('portal_user') || !db_column_exists('portal_user', 'user_type')) {
        return [];
    }
    $rows = db()->query("SELECT user_id FROM portal_user WHERE user_type = 'CUSTOMER'")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_map('intval', $rows ?: [])));
}

function go_live_reset_file_candidates(): array {
    $root = dirname(__DIR__);
    $candidates = [];

    $contractDir = $root . '/uploads/contracts';
    if (is_dir($contractDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($contractDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $candidates[] = $file->getPathname();
            }
        }
    }

    if (db_table_exists('expense_attachment')) {
        $rows = db()->query("SELECT provider, provider_file_id, file_url FROM expense_attachment")->fetchAll();
        foreach ($rows as $row) {
            if ((string)($row['provider'] ?? '') !== 'LOCAL') {
                continue;
            }
            foreach (['provider_file_id', 'file_url'] as $key) {
                $value = trim((string)($row[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (strpos($value, 'uploads/') === 0) {
                    $path = $root . '/' . ltrim($value, '/');
                    if (is_file($path)) {
                        $candidates[] = $path;
                    }
                }
            }
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, 'is_file')));
    sort($candidates);
    return $candidates;
}

function go_live_reset_snapshot(): array {
    return [
        'clients' => go_live_reset_count_table('clients'),
        'contacts' => go_live_reset_count_table('client_contact'),
        'locations' => go_live_reset_count_table('client_location'),
        'contracts' => go_live_reset_count_table('contract'),
        'onboarding_tasks' => go_live_reset_count_table('contract_onboarding_task'),
        'client_services' => go_live_reset_count_table('client_service'),
        'recurring_services' => go_live_reset_count_table('recurring_service'),
        'invoices' => go_live_reset_count_table('customer_invoice'),
        'invoice_lines' => go_live_reset_count_table('invoice_line'),
        'invoice_deliveries' => go_live_reset_count_table('invoice_delivery'),
        'payments' => go_live_reset_count_table('payment_receipt'),
        'payment_applications' => go_live_reset_count_table('payment_invoice_apply'),
        'expenses' => go_live_reset_count_table('expense'),
        'expense_attachments' => go_live_reset_count_table('expense_attachment'),
        'webhooks' => go_live_reset_count_table('gateway_webhook_event'),
        'reconciliations' => go_live_reset_count_table('bank_reconciliation'),
        'bank_import_batches' => go_live_reset_count_table('bank_import_batch'),
        'bank_import_transactions' => go_live_reset_count_table('bank_import_transaction'),
        'journals_total' => go_live_reset_count_table('gl_journal'),
        'journals_preserved' => count(go_live_reset_opening_journal_ids()),
        'customer_users' => count(go_live_reset_customer_user_ids()),
        'portal_invites' => go_live_reset_count_table('portal_access_invite'),
        'test_files' => count(go_live_reset_file_candidates()),
    ];
}

function go_live_reset_exec_delete_all(PDO $pdo, string $table): int {
    if (!db_table_exists($table)) {
        return 0;
    }
    return $pdo->exec(sprintf('DELETE FROM `%s`', str_replace('`', '', $table)));
}

function go_live_reset_exec_delete_not_in(PDO $pdo, string $table, string $idColumn, array $keepIds): int {
    if (!db_table_exists($table)) {
        return 0;
    }
    if ($keepIds === []) {
        return $pdo->exec(sprintf('DELETE FROM `%s`', str_replace('`', '', $table)));
    }
    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
    $sql = sprintf('DELETE FROM `%s` WHERE `%s` NOT IN (%s)', str_replace('`', '', $table), str_replace('`', '', $idColumn), $placeholders);
    $st = $pdo->prepare($sql);
    $st->execute(array_values($keepIds));
    return $st->rowCount();
}

function go_live_reset_exec_delete_by_user_ids(PDO $pdo, string $table, string $userColumn, array $userIds): int {
    if ($userIds === [] || !db_table_exists($table) || !db_column_exists($table, $userColumn)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = sprintf('DELETE FROM `%s` WHERE `%s` IN (%s)', str_replace('`', '', $table), str_replace('`', '', $userColumn), $placeholders);
    $st = $pdo->prepare($sql);
    $st->execute(array_values($userIds));
    return $st->rowCount();
}

function go_live_reset_reseed_table(PDO $pdo, string $table, string $idColumn): void {
    if (!db_table_exists($table)) {
        return;
    }
    $sql = sprintf('SELECT COALESCE(MAX(`%s`), 0) FROM `%s`', str_replace('`', '', $idColumn), str_replace('`', '', $table));
    $next = ((int)$pdo->query($sql)->fetchColumn()) + 1;
    $pdo->exec(sprintf('ALTER TABLE `%s` AUTO_INCREMENT = %d', str_replace('`', '', $table), max(1, $next)));
}

function go_live_reset_execute(bool $deleteFiles = true): array {
    $pdo = db();
    $openingJournalIds = go_live_reset_opening_journal_ids();
    $customerUserIds = go_live_reset_customer_user_ids();
    $fileCandidates = go_live_reset_file_candidates();
    $deleted = [];

    try {
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // Customer portal users, if any.
        $deleted['user_role'] = go_live_reset_exec_delete_by_user_ids($pdo, 'user_role', 'user_id', $customerUserIds);
        $deleted['portal_user_mfa'] = go_live_reset_exec_delete_by_user_ids($pdo, 'portal_user_mfa', 'user_id', $customerUserIds);
        $deleted['mfa_backup_code'] = go_live_reset_exec_delete_by_user_ids($pdo, 'mfa_backup_code', 'user_id', $customerUserIds);
        $deleted['user_passkey'] = go_live_reset_exec_delete_by_user_ids($pdo, 'user_passkey', 'user_id', $customerUserIds);
        $deleted['webauthn_credential'] = go_live_reset_exec_delete_by_user_ids($pdo, 'webauthn_credential', 'user_id', $customerUserIds);
        if ($customerUserIds !== [] && db_table_exists('portal_user') && db_column_exists('portal_user', 'user_type')) {
            $deleted['portal_user'] = (int)$pdo->exec("DELETE FROM portal_user WHERE user_type = 'CUSTOMER'");
        } else {
            $deleted['portal_user'] = 0;
        }
        $deleted['portal_access_invite'] = go_live_reset_exec_delete_all($pdo, 'portal_access_invite');

        // Transactional / client-side data.
        $deleteAll = [
            'bank_import_transaction',
            'bank_import_batch',
            'bank_reconciliation_item',
            'bank_reconciliation',
            'gateway_webhook_event',
            'invoice_delivery',
            'payment_invoice_apply',
            'payment_receipt',
            'invoice_line',
            'customer_invoice',
            'expense_attachment',
            'expense',
            'recurring_service',
            'contract_onboarding_task',
            'client_service',
            'contract_service',
            'contract',
            'client_contact',
            'client_location',
            'clients',
        ];

        foreach ($deleteAll as $table) {
            $deleted[$table] = go_live_reset_exec_delete_all($pdo, $table);
        }

        // Preserve opening balance journals only.
        $deleted['gl_journal_line'] = go_live_reset_exec_delete_not_in($pdo, 'gl_journal_line', 'journal_id', $openingJournalIds);
        $deleted['gl_journal'] = go_live_reset_exec_delete_not_in($pdo, 'gl_journal', 'journal_id', $openingJournalIds);

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
            $pdo->rollBack();
        }
        throw $e;
    }

    // Reseed auto-increment counters after commit.
    $reseedTables = [
        'clients' => 'client_id',
        'client_contact' => 'contact_id',
        'client_location' => 'location_id',
        'client_service' => 'client_service_id',
        'contract' => 'contract_id',
        'contract_onboarding_task' => 'task_id',
        'contract_service' => 'contract_service_id',
        'customer_invoice' => 'invoice_id',
        'invoice_line' => 'invoice_line_id',
        'invoice_delivery' => 'delivery_id',
        'expense' => 'expense_id',
        'expense_attachment' => 'attachment_id',
        'payment_receipt' => 'payment_id',
        'payment_invoice_apply' => 'payment_apply_id',
        'gateway_webhook_event' => 'webhook_event_id',
        'bank_import_batch' => 'batch_id',
        'bank_import_transaction' => 'bank_transaction_id',
        'bank_reconciliation' => 'reconciliation_id',
        'bank_reconciliation_item' => 'reconciliation_item_id',
        'recurring_service' => 'recurring_service_id',
        'gl_journal' => 'journal_id',
        'gl_journal_line' => 'journal_line_id',
        'portal_user' => 'user_id',
        'portal_access_invite' => 'invite_id',
        'user_passkey' => 'passkey_id',
        'mfa_backup_code' => 'code_id',
    ];
    foreach ($reseedTables as $table => $idColumn) {
        if (db_column_exists($table, $idColumn)) {
            go_live_reset_reseed_table($pdo, $table, $idColumn);
        }
    }

    $deletedFiles = [];
    if ($deleteFiles) {
        foreach ($fileCandidates as $file) {
            if (@unlink($file)) {
                $deletedFiles[] = $file;
            }
        }
    }

    return [
        'deleted' => $deleted,
        'preserved_opening_journal_ids' => $openingJournalIds,
        'deleted_files' => $deletedFiles,
        'snapshot_after' => go_live_reset_snapshot(),
    ];
}
