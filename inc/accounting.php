<?php
 declare(strict_types=1);
 
 require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/syncro.php';
 
 function accounting_is_enabled(): bool {
     return defined('ACCOUNTING_ENABLED') ? (bool)ACCOUNTING_ENABLED : false;
 }
 
 function accounting_is_ready(): bool {
     foreach (['gl_account', 'vendor', 'expense', 'gl_journal', 'gl_journal_line', 'customer_invoice', 'invoice_line', 'payment_receipt', 'payment_invoice_apply'] as $table) {
         if (!db_table_exists($table)) {
             return false;
         }
     }
     return true;
 }
 
 function accounting_h(string $value): string {
     return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
 }
 
 function accounting_subnav(string $active): void {
     $items = [
         'home' => ['/accounting/index.php', 'Overview'],
         'accounts' => ['/accounting/accounts.php', 'Chart of Accounts'],
         'vendors' => ['/accounting/vendors.php', 'Vendors'],
         'expenses' => ['/accounting/expenses.php', 'Expenses'],
         'bills' => ['/accounting/bills.php', 'Bills'],
         'invoices' => ['/accounting/invoices.php', 'Invoices'],
         'payments' => ['/payments/index.php', 'Payments'],
         'recurring' => ['/accounting/recurring.php', 'Recurring'],
         'receivables' => ['/accounting/receivables.php', 'Receivables'],
         'reconcile' => ['/accounting/reconcile.php', 'Reconcile'],
         'capital' => ['/accounting/capital.php', 'Capital'],
     ];
 
     echo '<div style="display:flex;justify-content:center;margin:8px 0 18px">';
     echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center">';
     foreach ($items as $key => [$href, $label]) {
         $styles = 'padding:8px 12px;border-radius:999px;text-decoration:none;border:1px solid rgba(59,130,246,.22);background:rgba(59,130,246,.10);color:#dbeafe;font-size:13px;';
         if ($key === $active) {
             $styles .= 'background:rgba(47,108,255,.28);border-color:rgba(47,108,255,.55);';
         }
         echo '<a href="' . accounting_h(BASE_URL . $href) . '" style="' . $styles . '">' . accounting_h($label) . '</a>';
     }
     echo '</div></div>';
 }
 
 function accounting_require_ready(): void {
     if (!accounting_is_enabled()) {
         http_response_code(404);
         echo 'Accounting module disabled.';
         exit;
     }
     if (!accounting_is_ready()) {
         http_response_code(500);
         echo 'Accounting tables are not installed yet.';
         exit;
     }
 }
 
 function accounting_get_account_types(): array {
     return ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE'];
 }
 
 function accounting_get_expense_statuses(): array {
     return ['DRAFT', 'SUBMITTED', 'APPROVED', 'PAID', 'VOID'];
 }
 
 function accounting_get_invoice_statuses(): array {
     return ['DRAFT', 'ISSUED', 'PARTIALLY_PAID', 'PAID', 'VOID'];
 }

function accounting_get_payment_methods(): array {
    return ['CARD', 'ACH', 'CASH', 'OTHER'];
}

function accounting_payment_statuses(): array {
    return ['PENDING', 'POSTED', 'VOID', 'FAILED'];
}

function accounting_payment_table_supports_processor_fields(): bool {
    return db_column_exists('payment_receipt', 'gross_amount');
}

function accounting_find_default_fee_expense_account_id(): ?int {
    foreach (['5070', '5060'] as $code) {
        $id = accounting_find_account_id_by_code($code);
        if ($id !== null) {
            return $id;
        }
    }
    return null;
}

function accounting_payment_account_options(): array {
    $accounts = accounting_account_options(['ASSET']);
    $preferredCodes = ['1000', '1010', '1020', '1030'];
    $preferred = [];
    $fallback = [];
    foreach ($accounts as $account) {
        $code = (string)($account['account_code'] ?? '');
        $name = strtoupper((string)($account['account_name'] ?? ''));
        if (in_array($code, $preferredCodes, true) || str_contains($name, 'CHECKING') || str_contains($name, 'UNDDEPOSITED') || str_contains($name, 'UNDEPOSITED') || str_contains($name, 'CASH')) {
            $preferred[] = $account;
        }
    }
    if ($preferred !== []) {
        return $preferred;
    }
    foreach ($accounts as $account) {
        $name = strtoupper((string)($account['account_name'] ?? ''));
        $detail = strtoupper((string)($account['detail_type'] ?? ''));
        if (str_contains($detail, 'BANK') || str_contains($name, 'BANK') || str_contains($name, 'CHECKING') || str_contains($name, 'CASH')) {
            $fallback[] = $account;
        }
    }
    return $fallback !== [] ? $fallback : $accounts;
}

function accounting_list_fee_expense_accounts(): array {
    $accounts = accounting_account_options(['EXPENSE']);
    $preferred = [];
    foreach ($accounts as $account) {
        $code = (string)($account['account_code'] ?? '');
        $name = strtoupper((string)($account['account_name'] ?? ''));
        if ($code === '5070' || str_contains($name, 'MERCHANT') || str_contains($name, 'PROCESSING')) {
            $preferred[] = $account;
        }
    }
    return $preferred !== [] ? $preferred : $accounts;
}



function accounting_invoice_lookup_by_number(string $invoiceNumber): ?array {
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return null;
    }
    $st = db()->prepare("SELECT invoice_id FROM customer_invoice WHERE invoice_number = ? LIMIT 1");
    $st->execute([$invoiceNumber]);
    $id = $st->fetchColumn();
    if ($id === false) {
        return null;
    }
    return accounting_get_invoice((int)$id);
}

function accounting_invoice_payment_link(string $invoiceNumber, string $method): string {
    $method = strtoupper(trim($method));
    $allowed = ['ACH', 'CARD'];
    if (!in_array($method, $allowed, true)) {
        $method = 'ACH';
    }
    return BASE_URL . '/payments/pay.php?invoice=' . rawurlencode($invoiceNumber) . '&method=' . rawurlencode($method);
}

function accounting_invoice_payment_link_html(array $invoice, string $method): string {
    $invoiceNumber = (string)($invoice['invoice_number'] ?? '');
    if ($invoiceNumber === '') {
        return '';
    }
    $label = $method === 'CARD' ? 'Open card link' : 'Open ACH link';
    return '<a class="btn btn-secondary" href="' . accounting_h(accounting_invoice_payment_link($invoiceNumber, $method)) . '" target="_blank" rel="noopener" style="text-decoration:none;text-align:left;">' . accounting_h($label) . '</a>';
}

function accounting_invoice_stripe_payment_url(array $invoice): string {
    return trim((string)($invoice['stripe_hosted_invoice_url'] ?? ''));
}

function accounting_invoice_has_stripe_payment_page(array $invoice): bool {
    return accounting_invoice_stripe_payment_url($invoice) !== '';
}
function accounting_invoice_lifecycle_stage_html(array $invoice): string {
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    $steps = [
        ['key' => 'DRAFT', 'label' => 'Draft'],
        ['key' => 'ISSUED', 'label' => 'Issued'],
        ['key' => 'PAID', 'label' => 'Paid'],
    ];
    $current = $status === 'PARTIALLY_PAID' ? 'ISSUED' : ($status === 'VOID' ? 'DRAFT' : $status);
    $rank = ['DRAFT' => 1, 'ISSUED' => 2, 'PAID' => 3];
    $currentRank = $rank[$current] ?? 1;
    $html = '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
    foreach ($steps as $idx => $step) {
        $isDone = ($rank[$step['key']] ?? 0) <= $currentRank;
        $bg = $isDone ? 'rgba(34,197,94,.18)' : 'rgba(148,163,184,.12)';
        $border = $isDone ? 'rgba(34,197,94,.28)' : 'rgba(148,163,184,.18)';
        $color = $isDone ? '#bbf7d0' : '#cbd5e1';
        $html .= '<span style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' . $bg . ';border:1px solid ' . $border . ';color:' . $color . ';">' . accounting_h($step['label']) . '</span>';
        if ($idx < count($steps) - 1) {
            $html .= '<span style="opacity:.45;">→</span>';
        }
    }
    if ($status === 'PARTIALLY_PAID') {
        $html .= '<span style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(245,158,11,.18);border:1px solid rgba(245,158,11,.28);color:#fde68a;">Partial payment received</span>';
    }
    if ($status === 'VOID') {
        $html .= '<span style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(248,113,113,.18);border:1px solid rgba(248,113,113,.28);color:#fecaca;">Voided</span>';
    }
    return $html . '</div>';
}

function accounting_invoice_status_badge_html(array $invoice): string {
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    $map = [
        'DRAFT' => ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1', 'label' => 'DRAFT'],
        'ISSUED' => ['bg' => 'rgba(59,130,246,.18)', 'border' => 'rgba(59,130,246,.28)', 'color' => '#bfdbfe', 'label' => 'ISSUED'],
        'PARTIALLY_PAID' => ['bg' => 'rgba(245,158,11,.18)', 'border' => 'rgba(245,158,11,.28)', 'color' => '#fde68a', 'label' => 'PARTIALLY PAID'],
        'PAID' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0', 'label' => 'PAID'],
        'VOID' => ['bg' => 'rgba(239,68,68,.18)', 'border' => 'rgba(239,68,68,.28)', 'color' => '#fecaca', 'label' => 'VOID'],
    ];
    $style = $map[$status] ?? $map['DRAFT'];
    $label = $style['label'];
    return '<span class="status-badge" style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">'
        . accounting_h($label) . '</span>';
}

function accounting_payment_method_badge_html(string $method): string {
    $method = strtoupper(trim($method));
    $map = [
        'CARD' => ['bg' => 'rgba(168,85,247,.18)', 'border' => 'rgba(168,85,247,.34)', 'color' => '#e9d5ff'],
        'ACH' => ['bg' => 'rgba(59,130,246,.18)', 'border' => 'rgba(59,130,246,.30)', 'color' => '#bfdbfe'],
        'CASH' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0'],
        'OTHER' => ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1'],
    ];
    $style = $map[$method] ?? $map['OTHER'];
    return '<span class="status-badge" style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">' . accounting_h($method) . '</span>';
}

function accounting_payment_status_badge_html(string $status): string {
    $status = strtoupper(trim($status));
    $map = [
        'POSTED' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0'],
        'PROCESSED' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0'],
        'PENDING' => ['bg' => 'rgba(245,158,11,.18)', 'border' => 'rgba(245,158,11,.28)', 'color' => '#fde68a'],
        'RECEIVED' => ['bg' => 'rgba(59,130,246,.18)', 'border' => 'rgba(59,130,246,.28)', 'color' => '#bfdbfe'],
        'IGNORED' => ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1'],
        'VOID' => ['bg' => 'rgba(239,68,68,.18)', 'border' => 'rgba(239,68,68,.28)', 'color' => '#fecaca'],
        'FAILED' => ['bg' => 'rgba(244,63,94,.18)', 'border' => 'rgba(244,63,94,.28)', 'color' => '#fecdd3'],
    ];
    $style = $map[$status] ?? $map['PENDING'];
    return '<span class="status-badge" style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">' . accounting_h($status) . '</span>';
}




function accounting_client_service_status_badge_html(string $status): string {
    $status = strtoupper(trim($status));
    $map = [
        'ACTIVE' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0'],
        'PAUSED' => ['bg' => 'rgba(245,158,11,.18)', 'border' => 'rgba(245,158,11,.28)', 'color' => '#fde68a'],
        'ENDED' => ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1'],
    ];
    $style = $map[$status] ?? $map['PAUSED'];
    return '<span class="status-badge" style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">' . accounting_h($status) . '</span>';
}

function accounting_recurring_status_badge_html(bool $active): string {
    return accounting_client_service_status_badge_html($active ? 'ACTIVE' : 'PAUSED');
}

function accounting_invoice_link_html(int $invoiceId, string $invoiceNumber, string $hrefBase = BASE_URL . '/accounting/invoice_view.php'): string {
    $href = accounting_h($hrefBase . '?id=' . $invoiceId);
    return '<a class="invoice-link" href="' . $href . '"><span class="invoice-link-icon" aria-hidden="true">ð§¾</span><span>' . accounting_h($invoiceNumber) . '</span></a>';
}
 
 function accounting_get_catalog_item_types(): array {
     return ['SERVICE', 'LICENSE'];
 }
 
 function accounting_get_catalog_billing_modes(): array {
     return ['ONE_TIME', 'RECURRING'];
 }
 
 function accounting_catalog_supports_billing_mode(): bool {
     return db_table_exists('service_item') && db_column_exists('service_item', 'billing_mode');
 }
 
 function accounting_get_recurring_cycles(): array {
     return ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL'];
 }
 
 function accounting_get_term_options(): array {
     return [0 => 'Month-to-month', 3 => '3 months', 6 => '6 months', 12 => '12 months'];
 }

function accounting_service_category_ready(): bool {
     return db_table_exists('service_category') && db_column_exists('service_item', 'category_id');
 }
 
 function accounting_list_service_categories(bool $activeOnly = true): array {
     if (!accounting_service_category_ready()) return [];
     $sql = 'SELECT * FROM service_category WHERE 1 = 1';
     $params = [];
     if ($activeOnly) $sql .= ' AND is_active = 1';
     $sql .= ' ORDER BY sort_order ASC, category_name ASC';
     $st = db()->prepare($sql);
     $st->execute($params);
     return $st->fetchAll();
 }
 
 function accounting_get_service_category(int $categoryId): ?array {
     if (!accounting_service_category_ready() || $categoryId <= 0) return null;
     $st = db()->prepare('SELECT * FROM service_category WHERE category_id = ? LIMIT 1');
     $st->execute([$categoryId]);
     $row = $st->fetch();
     return $row ?: null;
 }
 
 function accounting_service_category_code_options(): array {
     return ['BUNDLE_BASE', 'CORE', 'SECURITY', 'BACKUP', 'ADMIN', 'INFRA', 'PROJECT'];
 }
 
 function accounting_service_category_badge_html(?string $categoryName, ?string $categoryCode = null): string {
     $label = trim((string)$categoryName);
     if ($label === '') $label = trim((string)$categoryCode);
     if ($label === '') $label = 'Uncategorized';
     return '<span style="display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.22);color:#dbeafe;">' . accounting_h($label) . '</span>'; 
 }
 
 function accounting_group_categories_by_type(array $categories): array {
     $grouped = [];
     foreach ($categories as $category) {
         $key = strtoupper(trim((string)($category['category_type'] ?? 'OTHER')));
         if (!isset($grouped[$key])) $grouped[$key] = [];
         $grouped[$key][] = $category;
     }
     return $grouped;
 }
 
 function accounting_bundle_base_category_ids(): array {
     if (!accounting_service_category_ready()) return [];
     $st = db()->query('SELECT category_id FROM service_category WHERE is_active = 1 AND is_bundle_base = 1 ORDER BY sort_order ASC, category_name ASC');
     return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
 }
 
 function accounting_bundle_included_category_ids(): array {
     if (!accounting_service_category_ready()) return [];
     $st = db()->query('SELECT category_id FROM service_category WHERE is_active = 1 AND is_bundle_eligible = 1 ORDER BY sort_order ASC, category_name ASC');
     return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
 }
 
 function accounting_bundle_addon_category_ids(): array {
     if (!accounting_service_category_ready()) return [];
     $st = db()->query('SELECT category_id FROM service_category WHERE is_active = 1 AND is_addon_eligible = 1 ORDER BY sort_order ASC, category_name ASC');
     return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
 }
 
 function accounting_summary(): array {
     $pdo = db();
     $mtdCollected = 0.0;
     $paymentCount = 0;
     if (db_table_exists('payment_receipt')) {
         $supportsExtended = accounting_payment_table_supports_processor_fields();
         $amountSql = $supportsExtended ? 'COALESCE(net_amount, amount_received)' : 'amount_received';
         $statusWhere = $supportsExtended && db_column_exists('payment_receipt', 'payment_status')
             ? " AND COALESCE(payment_status, 'POSTED') <> 'VOID'"
             : '';
         $mtdCollected = (float)$pdo->query("SELECT COALESCE(SUM({$amountSql}),0) FROM payment_receipt WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND payment_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH){$statusWhere}")->fetchColumn();
         $paymentCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_receipt WHERE 1 = 1{$statusWhere}")->fetchColumn();
     }

     return [
         'account_count' => (int)$pdo->query('SELECT COUNT(*) FROM gl_account WHERE is_active = 1')->fetchColumn(),
         'vendor_count' => (int)$pdo->query('SELECT COUNT(*) FROM vendor WHERE is_active = 1')->fetchColumn(),
         'expense_count' => (int)$pdo->query('SELECT COUNT(*) FROM expense')->fetchColumn(),
         'draft_expense_count' => (int)$pdo->query("SELECT COUNT(*) FROM expense WHERE status = 'DRAFT'")->fetchColumn(),
         'mtd_expense_total' => (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM expense WHERE status <> 'VOID' AND posting_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND posting_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)")->fetchColumn(),
         'open_bill_count' => (int)$pdo->query("SELECT COUNT(*) FROM expense WHERE status IN ('SUBMITTED','APPROVED')")->fetchColumn(),
         'draft_invoice_count' => (int)$pdo->query("SELECT COUNT(*) FROM customer_invoice WHERE status = 'DRAFT'")->fetchColumn(),
         'overdue_invoice_count' => (int)$pdo->query("SELECT COUNT(*) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn(),
         'open_receivable_total' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0")->fetchColumn(),
         'mtd_collected_total' => $mtdCollected,
         'payment_count' => $paymentCount,
         'catalog_item_count' => db_table_exists('service_item') ? (int)$pdo->query('SELECT COUNT(*) FROM service_item WHERE is_active = 1')->fetchColumn() : 0,
         'recurring_item_count' => db_table_exists('recurring_service') ? (int)$pdo->query('SELECT COUNT(*) FROM recurring_service WHERE active = 1')->fetchColumn() : 0,
     ];
 }
 
 function accounting_recent_expenses(int $limit = 8): array {
     $limit = max(1, min(50, $limit));
     $sql = "SELECT e.expense_id, e.expense_date, e.posting_date, e.status, e.total_amount, e.memo,
                    v.vendor_name,
                    a.account_code, a.account_name,
                    EXISTS(SELECT 1 FROM gl_journal j WHERE j.source_type = 'EXPENSE' AND j.source_id = e.expense_id AND j.status <> 'VOID') AS has_journal
             FROM expense e
             LEFT JOIN vendor v ON v.vendor_id = e.vendor_id
             INNER JOIN gl_account a ON a.account_id = e.expense_account_id
             ORDER BY e.expense_date DESC, e.expense_id DESC
             LIMIT {$limit}";
     return db()->query($sql)->fetchAll();
 }
 
 function accounting_recent_invoices(int $limit = 8): array {
     $limit = max(1, min(50, $limit));
     $sql = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.status, i.total_amount, i.balance_due,
                    c.legal_name, c.dba_name
             FROM customer_invoice i
             INNER JOIN clients c ON c.client_id = i.client_id
             ORDER BY i.invoice_date DESC, i.invoice_id DESC
             LIMIT {$limit}";
     return db()->query($sql)->fetchAll();
 }
 
 function accounting_list_accounts(?string $type = null): array {
     $params = [];
     $sql = "SELECT a.*, parent.account_code AS parent_code, parent.account_name AS parent_name
             FROM gl_account a
             LEFT JOIN gl_account parent ON parent.account_id = a.parent_account_id";
     if ($type !== null && in_array($type, accounting_get_account_types(), true)) {
         $sql .= ' WHERE a.account_type = ?';
         $params[] = $type;
     }
     $sql .= ' ORDER BY a.account_type, a.account_code';
     $st = db()->prepare($sql);
     $st->execute($params);
     return $st->fetchAll();
 }
 
 function accounting_get_account(int $accountId): ?array {
     $st = db()->prepare('SELECT * FROM gl_account WHERE account_id = ? LIMIT 1');
     $st->execute([$accountId]);
     $row = $st->fetch();
     return $row ?: null;
 }
 
 function accounting_account_options(array $types = []): array {
     $params = [];
     $sql = 'SELECT account_id, account_code, account_name, account_type, detail_type, description FROM gl_account WHERE is_active = 1';
     if ($types !== []) {
         $placeholders = implode(',', array_fill(0, count($types), '?'));
         $sql .= " AND account_type IN ($placeholders)";
         $params = array_values($types);
     }
     $sql .= ' ORDER BY account_code';
     $st = db()->prepare($sql);
     $st->execute($params);
     return $st->fetchAll();
 }
 
 function accounting_find_account_id_by_code(string $code): ?int {
     $st = db()->prepare('SELECT account_id FROM gl_account WHERE account_code = ? LIMIT 1');
     $st->execute([$code]);
     $id = $st->fetchColumn();
     return $id === false ? null : (int)$id;
 }
 
 function accounting_default_cash_account_code(): string {
     $code = trim((string)(defined('GL_DEFAULT_CASH_ACCT_NO') ? GL_DEFAULT_CASH_ACCT_NO : '1000'));
     return $code !== '' ? $code : '1000';
 }
 
 function accounting_default_cash_account_id(): int {
     return (int)(accounting_find_account_id_by_code(accounting_default_cash_account_code()) ?? 0);
 }
 
 function accounting_default_owner_contribution_account_id(): int {
     return (int)(accounting_find_account_id_by_code('3000') ?? 0);
 }
 
 function accounting_default_owner_draw_account_id(): int {
     return (int)(accounting_find_account_id_by_code('3100') ?? accounting_default_owner_contribution_account_id());
 }
 
 function accounting_account_option_label(array $account): string {
     $code = trim((string)($account['account_code'] ?? ''));
     $name = trim((string)($account['account_name'] ?? ''));
     if ($code !== '' && $name !== '') {
         return $code . ' · ' . $name;
     }
     return $code !== '' ? $code : $name;
 }
 
 function accounting_save_account(array $data, ?int $accountId = null): array {
     $code = trim((string)($data['account_code'] ?? ''));
     $name = trim((string)($data['account_name'] ?? ''));
     $type = strtoupper(trim((string)($data['account_type'] ?? '')));
     $detail = trim((string)($data['detail_type'] ?? ''));
     $description = trim((string)($data['description'] ?? ''));
     $parentId = (int)($data['parent_account_id'] ?? 0);
     $isActive = !empty($data['is_active']) ? 1 : 0;
 
     $errors = [];
     if ($code === '') $errors[] = 'Account code is required.';
     if ($name === '') $errors[] = 'Account name is required.';
     if (!in_array($type, accounting_get_account_types(), true)) $errors[] = 'Choose a valid account type.';
 
     if ($errors) return ['ok' => false, 'errors' => $errors];
 
     $pdo = db();
     $existing = $pdo->prepare('SELECT account_id FROM gl_account WHERE (account_code = ? OR account_name = ?) ' . ($accountId ? 'AND account_id <> ?' : '') . ' LIMIT 1');
     $params = [$code, $name];
     if ($accountId) $params[] = $accountId;
     $existing->execute($params);
     if ($existing->fetch()) {
         return ['ok' => false, 'errors' => ['Account code or name already exists.']];
     }
 
     if ($accountId) {
         $row = accounting_get_account($accountId);
         if (!$row) return ['ok' => false, 'errors' => ['Account not found.']];
         if ((int)$row['is_system'] === 1 && $row['account_code'] !== $code) {
             return ['ok' => false, 'errors' => ['System account codes cannot be changed.']];
         }
         $st = $pdo->prepare('UPDATE gl_account SET account_code = ?, account_name = ?, account_type = ?, detail_type = ?, parent_account_id = ?, description = ?, is_active = ? WHERE account_id = ?');
         $st->execute([$code, $name, $type, $detail !== '' ? $detail : null, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, $isActive, $accountId]);
         return ['ok' => true, 'account_id' => $accountId, 'message' => 'Account updated.'];
     }
 
     $st = $pdo->prepare('INSERT INTO gl_account (account_code, account_name, account_type, detail_type, parent_account_id, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
     $st->execute([$code, $name, $type, $detail !== '' ? $detail : null, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, $isActive]);
     return ['ok' => true, 'account_id' => (int)$pdo->lastInsertId(), 'message' => 'Account created.'];
 }
 
 function accounting_list_vendors(): array {
     $sql = "SELECT v.*, a.account_code, a.account_name,
                    (SELECT COUNT(*) FROM expense e WHERE e.vendor_id = v.vendor_id) AS expense_count
             FROM vendor v
             LEFT JOIN gl_account a ON a.account_id = v.default_expense_account_id
             ORDER BY v.vendor_name";
     return db()->query($sql)->fetchAll();
 }
 
 function accounting_get_vendor(int $vendorId): ?array {
     $st = db()->prepare('SELECT * FROM vendor WHERE vendor_id = ? LIMIT 1');
     $st->execute([$vendorId]);
     $row = $st->fetch();
     return $row ?: null;
 }

function accounting_remove_vendor(int $vendorId): array {
    $vendor = accounting_get_vendor($vendorId);
    if (!$vendor) return ['ok' => false, 'errors' => ['Vendor not found.']];

    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM expense WHERE vendor_id = ?');
    $st->execute([$vendorId]);
    $expenseCount = (int)$st->fetchColumn();

    if ($expenseCount > 0) {
        $upd = $pdo->prepare('UPDATE vendor SET is_active = 0 WHERE vendor_id = ?');
        $upd->execute([$vendorId]);
        return ['ok' => true, 'message' => 'Vendor deactivated because expense history exists.'];
    }

    $del = $pdo->prepare('DELETE FROM vendor WHERE vendor_id = ?');
    $del->execute([$vendorId]);
    return ['ok' => true, 'message' => 'Vendor deleted.'];
}
 
 function accounting_save_vendor(array $data, ?int $vendorId = null): array {
     $vendorName = trim((string)($data['vendor_name'] ?? ''));
     $vendorCode = trim((string)($data['vendor_code'] ?? ''));
     $contactName = trim((string)($data['contact_name'] ?? ''));
     $email = trim((string)($data['email'] ?? ''));
     $phone = trim((string)($data['phone'] ?? ''));
     $website = trim((string)($data['website'] ?? ''));
     $paymentTerms = trim((string)($data['payment_terms'] ?? ''));
     $notes = trim((string)($data['notes'] ?? ''));
     $defaultExpenseAccountId = (int)($data['default_expense_account_id'] ?? 0);
     $isActive = !empty($data['is_active']) ? 1 : 0;
 
     $errors = [];
     if ($vendorName === '') $errors[] = 'Vendor name is required.';
     if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
     if ($errors) return ['ok' => false, 'errors' => $errors];
 
     $pdo = db();
     if ($vendorId) {
         $st = $pdo->prepare('UPDATE vendor SET vendor_code = ?, vendor_name = ?, contact_name = ?, email = ?, phone = ?, website = ?, payment_terms = ?, default_expense_account_id = ?, notes = ?, is_active = ? WHERE vendor_id = ?');
         $st->execute([
             $vendorCode !== '' ? $vendorCode : null,
             $vendorName,
             $contactName !== '' ? $contactName : null,
             $email !== '' ? $email : null,
             $phone !== '' ? $phone : null,
             $website !== '' ? $website : null,
             $paymentTerms !== '' ? $paymentTerms : null,
             $defaultExpenseAccountId > 0 ? $defaultExpenseAccountId : null,
             $notes !== '' ? $notes : null,
             $isActive,
             $vendorId,
         ]);
         return ['ok' => true, 'vendor_id' => $vendorId, 'message' => 'Vendor updated.'];
     }
 
     $st = $pdo->prepare('INSERT INTO vendor (vendor_code, vendor_name, contact_name, email, phone, website, payment_terms, default_expense_account_id, notes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
     $st->execute([
         $vendorCode !== '' ? $vendorCode : null,
         $vendorName,
         $contactName !== '' ? $contactName : null,
         $email !== '' ? $email : null,
         $phone !== '' ? $phone : null,
         $website !== '' ? $website : null,
         $paymentTerms !== '' ? $paymentTerms : null,
         $defaultExpenseAccountId > 0 ? $defaultExpenseAccountId : null,
         $notes !== '' ? $notes : null,
         $isActive,
     ]);
     return ['ok' => true, 'vendor_id' => (int)$pdo->lastInsertId(), 'message' => 'Vendor created.'];
 }
 
 function accounting_list_expenses(int $limit = 100, array $statuses = []): array {
     $limit = max(1, min(500, $limit));
     $statuses = array_values(array_filter(array_map(static fn($status) => strtoupper(trim((string)$status)), $statuses)));
     $params = [];
     $where = '';
     if ($statuses) {
         $placeholders = implode(',', array_fill(0, count($statuses), '?'));
         $where = "WHERE e.status IN ({$placeholders})";
         $params = $statuses;
     }
 
     $sql = "SELECT e.*, v.vendor_name,
                    exp.account_code AS expense_account_code, exp.account_name AS expense_account_name,
                    pay.account_code AS payable_account_code, pay.account_name AS payable_account_name,
                    pm.account_code AS payment_account_code, pm.account_name AS payment_account_name,
                    EXISTS(SELECT 1 FROM gl_journal j WHERE j.source_type = 'EXPENSE' AND j.source_id = e.expense_id AND j.status <> 'VOID') AS has_journal
             FROM expense e
             LEFT JOIN vendor v ON v.vendor_id = e.vendor_id
             INNER JOIN gl_account exp ON exp.account_id = e.expense_account_id
             INNER JOIN gl_account pay ON pay.account_id = e.payable_account_id
             LEFT JOIN gl_account pm ON pm.account_id = e.payment_account_id
             {$where}
             ORDER BY e.expense_date DESC, e.expense_id DESC
             LIMIT {$limit}";
 
     if (!$params) {
         return db()->query($sql)->fetchAll();
     }
 
     $st = db()->prepare($sql);
     $st->execute($params);
     return $st->fetchAll();
 }
 
 function accounting_post_expense_journal(PDO $pdo, array $expense, int $userId): void {
     $status = (string)$expense['status'];
     $sourceType = 'EXPENSE';
     $reference = $expense['reference_number'] ?: null;
     $memo = trim((string)($expense['memo'] ?? '')) ?: 'Expense posting';
 
     $st = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', ?, ?, ?, ?, ?)");
     $st->execute([$expense['posting_date'], $sourceType, $expense['expense_id'], $reference, $memo, $userId]);
     $journalId = (int)$pdo->lastInsertId();
 
     $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, vendor_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
     $total = (float)$expense['total_amount'];
     $vendorId = $expense['vendor_id'] ? (int)$expense['vendor_id'] : null;
 
     $line->execute([$journalId, 1, (int)$expense['expense_account_id'], $vendorId, $total, 0, 'Expense']);
 
     $creditAccountId = (int)$expense['payable_account_id'];
     $creditMemo = 'Accounts payable';
     if ($status === 'PAID' && !empty($expense['payment_account_id'])) {
         $creditAccountId = (int)$expense['payment_account_id'];
         $creditMemo = 'Paid from account';
     }
     $line->execute([$journalId, 2, $creditAccountId, $vendorId, 0, $total, $creditMemo]);
 }
 
 function accounting_has_expense_journal(int $expenseId): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM gl_journal WHERE source_type = 'EXPENSE' AND source_id = ? AND status <> 'VOID'");
    $st->execute([$expenseId]);
    return (int)$st->fetchColumn() > 0;
}

function accounting_can_edit_expense(array $expense): bool {
    return ((string)($expense['status'] ?? '') === 'DRAFT')
        && empty($expense['has_journal'])
        && !accounting_has_expense_payment_journal((int)($expense['expense_id'] ?? 0));
}

function accounting_can_approve_expense(array $expense): bool {
    return ((string)($expense['status'] ?? '') === 'SUBMITTED')
        && !accounting_has_expense_payment_journal((int)($expense['expense_id'] ?? 0));
}

function accounting_update_expense(int $expenseId, array $data, int $userId): array {
    $expense = accounting_get_expense($expenseId);
    if (!$expense) return ['ok' => false, 'errors' => ['Bill not found.']];
    if (!accounting_can_edit_expense($expense)) {
        return ['ok' => false, 'errors' => ['Only unposted draft bills can be edited.']];
    }

    $vendorId = (int)($data['vendor_id'] ?? 0);
    $expenseDate = trim((string)($data['expense_date'] ?? ''));
    $postingDate = trim((string)($data['posting_date'] ?? ''));
    $dueDate = trim((string)($data['due_date'] ?? ''));
    $referenceNumber = trim((string)($data['reference_number'] ?? ''));
    $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
    $subtotalAmount = round((float)($data['subtotal_amount'] ?? 0), 2);
    $taxAmount = round((float)($data['tax_amount'] ?? 0), 2);
    $expenseAccountId = (int)($data['expense_account_id'] ?? 0);
    $payableAccountId = (int)($data['payable_account_id'] ?? 0);
    $paymentAccountId = (int)($data['payment_account_id'] ?? 0);
    $memo = trim((string)($data['memo'] ?? ''));
    $paymentDate = trim((string)($data['payment_date'] ?? ''));

    if ($expenseDate === '') $expenseDate = date('Y-m-d');
    if ($postingDate === '') $postingDate = $expenseDate;
    if ($payableAccountId <= 0) $payableAccountId = accounting_find_account_id_by_code('2000') ?? 0;

    $errors = [];
    if (!in_array($status, accounting_get_expense_statuses(), true)) $errors[] = 'Choose a valid expense status.';
    if ($subtotalAmount < 0) $errors[] = 'Subtotal cannot be negative.';
    if ($taxAmount < 0) $errors[] = 'Tax cannot be negative.';
    if ($expenseAccountId <= 0) $errors[] = 'Choose an expense category account.';
    if ($payableAccountId <= 0) $errors[] = 'Choose a payable account.';
    if ($status === 'PAID') $errors[] = 'Use Pay bill after submission instead of marking a draft bill paid from edit.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $totalAmount = round($subtotalAmount + $taxAmount, 2);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE expense SET vendor_id = ?, expense_date = ?, posting_date = ?, due_date = ?, reference_number = ?, status = ?, subtotal_amount = ?, tax_amount = ?, total_amount = ?, expense_account_id = ?, payable_account_id = ?, payment_account_id = ?, memo = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP WHERE expense_id = ?');
        $st->execute([
            $vendorId > 0 ? $vendorId : null,
            $expenseDate,
            $postingDate,
            $dueDate !== '' ? $dueDate : null,
            $referenceNumber !== '' ? $referenceNumber : null,
            $status,
            $subtotalAmount,
            $taxAmount,
            $totalAmount,
            $expenseAccountId,
            $payableAccountId,
            $paymentAccountId > 0 ? $paymentAccountId : null,
            $memo !== '' ? $memo : null,
            $paymentDate !== '' ? $paymentDate : null,
            $expenseId,
        ]);

        if (in_array($status, ['SUBMITTED', 'APPROVED'], true) && !accounting_has_expense_journal($expenseId)) {
            accounting_post_expense_journal($pdo, [
                'expense_id' => $expenseId,
                'posting_date' => $postingDate,
                'reference_number' => $referenceNumber,
                'memo' => $memo,
                'status' => $status,
                'total_amount' => $totalAmount,
                'expense_account_id' => $expenseAccountId,
                'payable_account_id' => $payableAccountId,
                'payment_account_id' => null,
                'vendor_id' => $vendorId > 0 ? $vendorId : null,
            ], $userId);
        }

        $pdo->commit();
        return ['ok' => true, 'expense_id' => $expenseId, 'message' => 'Bill updated.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to update bill: ' . $e->getMessage()]];
    }
}

function accounting_create_expense(array $data, int $userId): array {
     $vendorId = (int)($data['vendor_id'] ?? 0);
     $expenseDate = trim((string)($data['expense_date'] ?? ''));
     $postingDate = trim((string)($data['posting_date'] ?? ''));
     $dueDate = trim((string)($data['due_date'] ?? ''));
     $referenceNumber = trim((string)($data['reference_number'] ?? ''));
     $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
     $subtotalAmount = round((float)($data['subtotal_amount'] ?? 0), 2);
     $taxAmount = round((float)($data['tax_amount'] ?? 0), 2);
     $expenseAccountId = (int)($data['expense_account_id'] ?? 0);
     $payableAccountId = (int)($data['payable_account_id'] ?? 0);
     $paymentAccountId = (int)($data['payment_account_id'] ?? 0);
     $memo = trim((string)($data['memo'] ?? ''));
     $paymentDate = trim((string)($data['payment_date'] ?? ''));
 
     if ($expenseDate === '') $expenseDate = date('Y-m-d');
     if ($postingDate === '') $postingDate = $expenseDate;
     if ($payableAccountId <= 0) $payableAccountId = accounting_find_account_id_by_code('2000') ?? 0;
 
     $errors = [];
     if (!in_array($status, accounting_get_expense_statuses(), true)) $errors[] = 'Choose a valid expense status.';
     if ($subtotalAmount < 0) $errors[] = 'Subtotal cannot be negative.';
     if ($taxAmount < 0) $errors[] = 'Tax cannot be negative.';
     if ($expenseAccountId <= 0) $errors[] = 'Choose an expense category account.';
     if ($payableAccountId <= 0) $errors[] = 'Choose a payable account.';
     if ($status === 'PAID' && $paymentAccountId <= 0) $errors[] = 'Choose the account used to pay this expense.';
     if ($errors) return ['ok' => false, 'errors' => $errors];
 
     $totalAmount = round($subtotalAmount + $taxAmount, 2);
     $pdo = db();
     $pdo->beginTransaction();
     try {
         $st = $pdo->prepare('INSERT INTO expense (vendor_id, expense_date, posting_date, due_date, reference_number, status, subtotal_amount, tax_amount, total_amount, expense_account_id, payable_account_id, payment_account_id, memo, created_by, approved_by, paid_at, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
         $approvedBy = in_array($status, ['APPROVED', 'PAID'], true) ? $userId : null;
         $paidAt = $status === 'PAID' ? date('Y-m-d H:i:s') : null;
         $st->execute([
             $vendorId > 0 ? $vendorId : null,
             $expenseDate,
             $postingDate,
             $dueDate !== '' ? $dueDate : null,
             $referenceNumber !== '' ? $referenceNumber : null,
             $status,
             $subtotalAmount,
             $taxAmount,
             $totalAmount,
             $expenseAccountId,
             $payableAccountId,
             $paymentAccountId > 0 ? $paymentAccountId : null,
             $memo !== '' ? $memo : null,
             $userId,
             $approvedBy,
             $paidAt,
             $paymentDate !== '' ? $paymentDate : null,
         ]);
         $expenseId = (int)$pdo->lastInsertId();
 
         if (in_array($status, ['SUBMITTED', 'APPROVED', 'PAID'], true)) {
             accounting_post_expense_journal($pdo, [
                 'expense_id' => $expenseId,
                 'posting_date' => $postingDate,
                 'reference_number' => $referenceNumber,
                 'memo' => $memo,
                 'status' => $status,
                 'total_amount' => $totalAmount,
                 'expense_account_id' => $expenseAccountId,
                 'payable_account_id' => $payableAccountId,
                 'payment_account_id' => $paymentAccountId > 0 ? $paymentAccountId : null,
                 'vendor_id' => $vendorId > 0 ? $vendorId : null,
             ], $userId);
         }
 
         $pdo->commit();
         return ['ok' => true, 'expense_id' => $expenseId, 'message' => 'Expense saved.'];
     } catch (Throwable $e) {
         if ($pdo->inTransaction()) $pdo->rollBack();
         return ['ok' => false, 'errors' => ['Failed to save expense: ' . $e->getMessage()]];
     }
 }
 
 function accounting_list_bills(int $limit = 100): array {
     $limit = max(1, min(500, $limit));
     $sql = "SELECT e.expense_id, e.expense_date, e.posting_date, e.due_date, e.reference_number, e.status, e.total_amount, e.payment_date,
                    v.vendor_name,
                    exp.account_code AS expense_account_code, exp.account_name AS expense_account_name,
                    pay.account_code AS payable_account_code, pay.account_name AS payable_account_name,
                    pm.account_code AS payment_account_code, pm.account_name AS payment_account_name
             FROM expense e
             LEFT JOIN vendor v ON v.vendor_id = e.vendor_id
             INNER JOIN gl_account exp ON exp.account_id = e.expense_account_id
             INNER JOIN gl_account pay ON pay.account_id = e.payable_account_id
             LEFT JOIN gl_account pm ON pm.account_id = e.payment_account_id
             ORDER BY COALESCE(e.due_date, e.expense_date) DESC, e.expense_id DESC
             LIMIT {$limit}";
     return db()->query($sql)->fetchAll();
 }
 
 function accounting_get_expense(int $expenseId): ?array {
    return accounting_get_bill($expenseId);
}

function accounting_get_bill(int $expenseId): ?array {
     $sql = "SELECT e.*, v.vendor_name,
                    exp.account_code AS expense_account_code, exp.account_name AS expense_account_name,
                    pay.account_code AS payable_account_code, pay.account_name AS payable_account_name,
                    pm.account_code AS payment_account_code, pm.account_name AS payment_account_name
             FROM expense e
             LEFT JOIN vendor v ON v.vendor_id = e.vendor_id
             INNER JOIN gl_account exp ON exp.account_id = e.expense_account_id
             INNER JOIN gl_account pay ON pay.account_id = e.payable_account_id
             LEFT JOIN gl_account pm ON pm.account_id = e.payment_account_id
             WHERE e.expense_id = ? LIMIT 1";
     $st = db()->prepare($sql);
     $st->execute([$expenseId]);
     $row = $st->fetch();
     return $row ?: null;
 }
 
 function accounting_list_expense_attachments(int $expenseId): array {
    if ($expenseId <= 0 || !db_table_exists('expense_attachment')) {
        return [];
    }
    $st = db()->prepare("SELECT attachment_id, expense_id, provider, provider_file_id, file_name, file_url, mime_type, file_size, checksum_sha256, uploaded_by, uploaded_at FROM expense_attachment WHERE expense_id = ? ORDER BY uploaded_at DESC, attachment_id DESC");
    $st->execute([$expenseId]);
    return $st->fetchAll();
}

function accounting_count_expense_attachments(int $expenseId): int {
    if ($expenseId <= 0 || !db_table_exists('expense_attachment')) {
        return 0;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM expense_attachment WHERE expense_id = ?');
    $st->execute([$expenseId]);
    return (int)$st->fetchColumn();
}

function accounting_add_expense_attachment(int $expenseId, array $payload): array {
    if ($expenseId <= 0 || !db_table_exists('expense_attachment')) {
        return ['ok' => false, 'errors' => ['Expense attachment storage is not available.']];
    }
    $expense = accounting_get_expense($expenseId);
    if (!$expense) {
        return ['ok' => false, 'errors' => ['Bill not found.']];
    }

    $provider = strtoupper(trim((string)($payload['provider'] ?? 'LOCAL')));
    if (!in_array($provider, ['LOCAL', 'ONEDRIVE', 'S3', 'OTHER'], true)) {
        $provider = 'OTHER';
    }

    $fileName = trim((string)($payload['file_name'] ?? ''));
    if ($fileName === '') {
        return ['ok' => false, 'errors' => ['Receipt file name is required.']];
    }

    $st = db()->prepare('INSERT INTO expense_attachment (expense_id, provider, provider_file_id, file_name, file_url, mime_type, file_size, checksum_sha256, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $expenseId,
        $provider,
        trim((string)($payload['provider_file_id'] ?? '')) ?: null,
        $fileName,
        trim((string)($payload['file_url'] ?? '')) ?: null,
        trim((string)($payload['mime_type'] ?? '')) ?: null,
        !empty($payload['file_size']) ? (int)$payload['file_size'] : null,
        trim((string)($payload['checksum_sha256'] ?? '')) ?: null,
        !empty($payload['uploaded_by']) ? (int)$payload['uploaded_by'] : null,
    ]);

    return ['ok' => true, 'attachment_id' => (int)db()->lastInsertId()];
}

function accounting_has_expense_payment_journal(int $expenseId): bool {
     $st = db()->prepare("SELECT COUNT(*) FROM gl_journal WHERE source_type = 'PAYMENT' AND source_id = ? AND status <> 'VOID'");
     $st->execute([$expenseId]);
     return (int)$st->fetchColumn() > 0;
 }
 
 function accounting_approve_bill(int $expenseId, int $userId): array {
     $bill = accounting_get_bill($expenseId);
     if (!$bill) return ['ok' => false, 'errors' => ['Bill not found.']];
     if (!accounting_can_approve_expense($bill)) {
         return ['ok' => false, 'errors' => ['Only submitted bills can be approved.']];
     }
     if ($userId <= 0) {
         return ['ok' => false, 'errors' => ['Valid approver required.']];
     }
 
     $pdo = db();
     $pdo->beginTransaction();
     try {
         if (!accounting_has_expense_journal($expenseId)) {
             accounting_post_expense_journal($pdo, [
                 'expense_id' => $expenseId,
                 'posting_date' => $bill['posting_date'],
                 'reference_number' => $bill['reference_number'],
                 'memo' => $bill['memo'],
                 'status' => 'SUBMITTED',
                 'total_amount' => (float)$bill['total_amount'],
                 'expense_account_id' => (int)$bill['expense_account_id'],
                 'payable_account_id' => (int)$bill['payable_account_id'],
                 'payment_account_id' => null,
                 'vendor_id' => !empty($bill['vendor_id']) ? (int)$bill['vendor_id'] : null,
             ], $userId);
         }
 
         $st = $pdo->prepare('UPDATE expense SET status = ?, approved_by = ?, updated_at = CURRENT_TIMESTAMP WHERE expense_id = ?');
         $st->execute(['APPROVED', $userId, $expenseId]);
 
         $pdo->commit();
         return ['ok' => true, 'message' => 'Bill approved.'];
     } catch (Throwable $e) {
         if ($pdo->inTransaction()) $pdo->rollBack();
         return ['ok' => false, 'errors' => ['Failed to approve bill: ' . $e->getMessage()]];
     }
 }
 
 function accounting_pay_bill(int $expenseId, $paymentAccountId, string $paymentDate = '', int $userId = 0): array {
     $bill = accounting_get_bill($expenseId);
     if (is_array($paymentAccountId)) {
         $payload = $paymentAccountId;
         $paymentAccountId = (int)($payload['payment_account_id'] ?? 0);
         $paymentDate = trim((string)($payload['payment_date'] ?? ''));
     } else {
         $paymentAccountId = (int)$paymentAccountId;
     }
     if (!$bill) return ['ok' => false, 'errors' => ['Bill not found.']];
     if ((string)$bill['status'] !== 'APPROVED') {
         return ['ok' => false, 'errors' => ['Only approved bills can be paid.']];
     }
     if ($paymentAccountId <= 0) {
         return ['ok' => false, 'errors' => ['Choose a payment account.']];
     }
     if (accounting_has_expense_payment_journal($expenseId)) {
         return ['ok' => false, 'errors' => ['This bill has already been paid.']];
     }
 
     $paymentDate = trim($paymentDate) !== '' ? $paymentDate : date('Y-m-d');
     $pdo = db();
     $pdo->beginTransaction();
     try {
         if (!accounting_has_expense_journal($expenseId)) {
             accounting_post_expense_journal($pdo, [
                 'expense_id' => $expenseId,
                 'posting_date' => $bill['posting_date'],
                 'reference_number' => $bill['reference_number'],
                 'memo' => $bill['memo'],
                 'status' => 'APPROVED',
                 'total_amount' => (float)$bill['total_amount'],
                 'expense_account_id' => (int)$bill['expense_account_id'],
                 'payable_account_id' => (int)$bill['payable_account_id'],
                 'payment_account_id' => null,
                 'vendor_id' => !empty($bill['vendor_id']) ? (int)$bill['vendor_id'] : null,
             ], $userId);
         }
 
         $upd = $pdo->prepare('UPDATE expense SET status = ?, payment_account_id = ?, payment_date = ?, paid_at = CURRENT_TIMESTAMP, approved_by = COALESCE(approved_by, ?) WHERE expense_id = ?');
         $upd->execute(['PAID', $paymentAccountId, $paymentDate, $userId, $expenseId]);
 
         $memo = trim((string)($bill['memo'] ?? '')) ?: 'Bill payment';
         $reference = $bill['reference_number'] ?: null;
         $st = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'PAYMENT', ?, ?, ?, ?)");
         $st->execute([$paymentDate, $expenseId, $reference, $memo, $userId]);
         $journalId = (int)$pdo->lastInsertId();
 
         $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, vendor_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
         $vendorId = $bill['vendor_id'] ? (int)$bill['vendor_id'] : null;
         $amount = (float)$bill['total_amount'];
         $line->execute([$journalId, 1, (int)$bill['payable_account_id'], $vendorId, $amount, 0, 'Accounts payable settled']);
         $line->execute([$journalId, 2, $paymentAccountId, $vendorId, 0, $amount, 'Payment account']);
 
         $pdo->commit();
         return ['ok' => true, 'message' => 'Bill marked paid and posted.'];
     } catch (Throwable $e) {
         if ($pdo->inTransaction()) $pdo->rollBack();
         return ['ok' => false, 'errors' => ['Failed to pay bill: ' . $e->getMessage()]];
     }
 }
 
 function accounting_list_clients(): array {
     return db()->query("SELECT client_id, client_code, legal_name, dba_name, status FROM clients ORDER BY legal_name")->fetchAll();
 }
 
 function accounting_list_contracts_for_client(int $clientId): array {
     $st = db()->prepare("SELECT contract_id, contract_number, contract_name, contract_type, status FROM contract WHERE client_id = ? ORDER BY start_date DESC, contract_name ASC");
     $st->execute([$clientId]);
     return $st->fetchAll();
 }
 
 function accounting_client_invoice_prefix(array $client): string {
    $source = trim((string)($client['dba_name'] ?: $client['legal_name'] ?: $client['client_code'] ?? ''));
    if ($source === '') {
        return 'CLIENT';
    }
    $source = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $source) ?: $source;
    $parts = preg_split('/\s+/', trim($source)) ?: [];
    $ignored = ['inc','llc','l.l.c','corp','corporation','co','company','ltd','limited','pllc','the','and'];
    $sig = [];
    foreach ($parts as $part) {
        $lower = strtolower($part);
        if ($lower === '' || in_array($lower, $ignored, true)) {
            continue;
        }
        $sig[] = $part;
    }
    if (!$sig) {
        $sig = $parts;
    }
    $prefix = '';
    foreach (array_slice($sig, 0, 4) as $part) {
        $prefix .= strtoupper(substr($part, 0, 1));
    }
    if (strlen($prefix) < 3 && !empty($sig[0])) {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $sig[0]) ?: $sig[0], 0, 4));
    }
    $prefix = preg_replace('/[^A-Z0-9]+/', '', strtoupper($prefix)) ?: 'CLIENT';
    return substr($prefix, 0, 6);
}

function accounting_get_client(int $clientId): ?array {
    $st = db()->prepare('SELECT client_id, client_code, legal_name, dba_name, status, email, phone FROM clients WHERE client_id = ? LIMIT 1');
    $st->execute([$clientId]);
    $row = $st->fetch();
    return $row ?: null;
}

function accounting_next_invoice_number(PDO $pdo, int $clientId, ?string $invoiceDate = null): string {
     $client = accounting_get_client($clientId);
     if (!$client) {
         throw new RuntimeException('Client not found for invoice numbering.');
     }
     $year = (int)date('Y', strtotime($invoiceDate ?: 'now'));
     $prefix = accounting_client_invoice_prefix($client) . '-' . $year . '-';
     $st = $pdo->prepare("SELECT invoice_number FROM customer_invoice WHERE client_id = ? AND invoice_number LIKE ? ORDER BY invoice_id DESC LIMIT 1");
     $st->execute([$clientId, $prefix . '%']);
     $last = (string)($st->fetchColumn() ?: '');
     $seq = 1;
     if (preg_match('/^(?:[A-Z0-9]{1,6}-\d{4}-)(\d{3,})$/', $last, $m)) {
         $seq = ((int)$m[1]) + 1;
     }
     return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
 }
 
 function accounting_has_invoice_journal(int $invoiceId): bool {
     $st = db()->prepare("SELECT COUNT(*) FROM gl_journal WHERE source_type = 'INVOICE' AND source_id = ? AND status <> 'VOID'");
     $st->execute([$invoiceId]);
     return (int)$st->fetchColumn() > 0;
 }
 
 
function accounting_validate_invoice_for_issue(array $invoice, array $lines): array {
    $errors = [];
    if (((string)($invoice['status'] ?? '')) !== 'DRAFT') {
        $errors[] = 'Only draft invoices can be issued.';
    }
    if ((int)($invoice['invoice_id'] ?? 0) <= 0) {
        $errors[] = 'Invoice record is invalid.';
    }
    if ((int)($invoice['ar_account_id'] ?? 0) <= 0) {
        $errors[] = 'Invoice is missing an A/R account.';
    }
    if ((float)($invoice['total_amount'] ?? 0) <= 0) {
        $errors[] = 'Invoice total must be greater than zero before issuing.';
    }
    if (!$lines) {
        $errors[] = 'Add at least one invoice line before issuing.';
    }
    foreach ($lines as $idx => $line) {
        if (trim((string)($line['description'] ?? '')) === '') {
            $errors[] = 'Line ' . ($idx + 1) . ': description is required.';
        }
        if ((float)($line['quantity'] ?? 0) <= 0) {
            $errors[] = 'Line ' . ($idx + 1) . ': quantity must be greater than zero.';
        }
        if ((int)($line['revenue_account_id'] ?? 0) <= 0) {
            $errors[] = 'Line ' . ($idx + 1) . ': revenue account is required.';
        }
        if ((float)($line['line_total'] ?? 0) < 0) {
            $errors[] = 'Line ' . ($idx + 1) . ': line total cannot be negative.';
        }
    }
    return $errors;
}

function accounting_post_invoice_journal(PDO $pdo, array $invoice, int $userId): void {
    $st = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'INVOICE', ?, ?, ?, ?)");
    $memo = 'Invoice ' . $invoice['invoice_number'];
    $st->execute([$invoice['invoice_date'], $invoice['invoice_id'], $invoice['invoice_number'], $memo, $userId]);
    $journalId = (int)$pdo->lastInsertId();

    $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, client_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $total = (float)$invoice['total_amount'];
    $clientId = (int)$invoice['client_id'];
    $lineNumber = 1;
    $line->execute([$journalId, $lineNumber++, (int)$invoice['ar_account_id'], $clientId, $total, 0, 'Accounts receivable']);

    $revenueGroups = [];
    $lines = is_array($invoice['lines'] ?? null) ? $invoice['lines'] : [];
    if ($lines) {
        foreach ($lines as $invoiceLine) {
            $accountId = (int)($invoiceLine['revenue_account_id'] ?? 0);
            $amount = round((float)($invoiceLine['line_total'] ?? ((float)($invoiceLine['quantity'] ?? 0) * (float)($invoiceLine['unit_price'] ?? 0))), 2);
            if ($accountId <= 0 || $amount == 0.0) {
                continue;
            }
            if (!isset($revenueGroups[$accountId])) {
                $revenueGroups[$accountId] = 0.0;
            }
            $revenueGroups[$accountId] += $amount;
        }
    }
    if (!$revenueGroups) {
        $revenueGroups[(int)$invoice['revenue_account_id']] = $total;
    }
    foreach ($revenueGroups as $accountId => $amount) {
        $line->execute([$journalId, $lineNumber++, (int)$accountId, $clientId, 0, round((float)$amount, 2), 'Revenue']);
    }
}

function accounting_normalize_invoice_lines(array $data): array {
    $lines = [];
    $hasArrayLines = isset($data['line_description']) || isset($data['line_item_id']) || isset($data['line_quantity']) || isset($data['line_unit_price']) || isset($data['line_revenue_account_id']);
    if ($hasArrayLines) {
        $descriptions = is_array($data['line_description'] ?? null) ? $data['line_description'] : [];
        $itemIds = is_array($data['line_item_id'] ?? null) ? $data['line_item_id'] : [];
        $serviceCodes = is_array($data['line_service_code'] ?? null) ? $data['line_service_code'] : [];
        $quantities = is_array($data['line_quantity'] ?? null) ? $data['line_quantity'] : [];
        $prices = is_array($data['line_unit_price'] ?? null) ? $data['line_unit_price'] : [];
        $revenues = is_array($data['line_revenue_account_id'] ?? null) ? $data['line_revenue_account_id'] : [];
        $max = max(count($descriptions), count($itemIds), count($serviceCodes), count($quantities), count($prices), count($revenues));
        for ($i = 0; $i < $max; $i++) {
            $line = [
                'item_id' => (int)($itemIds[$i] ?? 0),
                'service_code' => trim((string)($serviceCodes[$i] ?? '')),
                'description' => trim((string)($descriptions[$i] ?? '')),
                'quantity' => (float)($quantities[$i] ?? 0),
                'unit_price' => (float)($prices[$i] ?? 0),
                'revenue_account_id' => (int)($revenues[$i] ?? 0),
            ];
            if ($line['item_id'] <= 0 && $line['description'] === '' && $line['quantity'] <= 0 && abs($line['unit_price']) < 0.00001 && $line['revenue_account_id'] <= 0) {
                continue;
            }
            $lines[] = $line;
        }
    }
    if (!$lines) {
        $lines[] = [
            'item_id' => (int)($data['item_id'] ?? 0),
            'service_code' => trim((string)($data['service_code'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')),
            'quantity' => (float)($data['quantity'] ?? 1),
            'unit_price' => (float)($data['unit_price'] ?? 0),
            'revenue_account_id' => (int)($data['revenue_account_id'] ?? 0),
        ];
    }
    return $lines;
}

function accounting_can_edit_invoice(array $invoice): bool {
    return ((string)($invoice['status'] ?? '') === 'DRAFT')
        && empty($invoice['has_journal'])
        && empty(accounting_invoice_payments((int)($invoice['invoice_id'] ?? 0)));
}

function accounting_update_invoice(int $invoiceId, array $data, int $userId): array {
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) return ['ok' => false, 'errors' => ['Invoice not found.']];
    if (!accounting_can_edit_invoice($invoice)) {
        return ['ok' => false, 'errors' => ['Only unposted draft invoices can be edited.']];
    }

    $normalized = accounting_normalize_invoice_lines($data);
    if (empty($normalized['ok'])) return $normalized;

    $clientId = (int)($data['client_id'] ?? 0);
    $contractId = (int)($data['contract_id'] ?? 0);
    $invoiceDate = trim((string)($data['invoice_date'] ?? ''));
    $dueDate = trim((string)($data['due_date'] ?? ''));
    $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
    $arAccountId = (int)($data['ar_account_id'] ?? 0);
    $memo = trim((string)($data['memo'] ?? ''));
    $normalizedLines = $normalized['lines'];

    $errors = [];
    if ($clientId <= 0) $errors[] = 'Choose a client.';
    if ($invoiceDate === '') $errors[] = 'Invoice date is required.';
    if ($status !== 'DRAFT') $errors[] = 'Draft invoices can be edited, then issued from the review screen.';
    if ($arAccountId <= 0) $errors[] = 'Accounts receivable account was not found.';
    if (!$normalizedLines) $errors[] = 'Add at least one invoice line.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $subtotal = 0.0;
    foreach ($normalizedLines as $lineData) {
        $subtotal += (float)$lineData['line_total'];
    }
    $subtotal = round($subtotal, 2);
    $headerRevenueAccountId = (int)$normalizedLines[0]['revenue_account_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE customer_invoice SET client_id = ?, contract_id = ?, invoice_date = ?, due_date = ?, status = ?, subtotal_amount = ?, tax_amount = 0.00, total_amount = ?, balance_due = ?, ar_account_id = ?, revenue_account_id = ?, memo = ?, updated_at = CURRENT_TIMESTAMP WHERE invoice_id = ?');
        $upd->execute([
            $clientId,
            $contractId > 0 ? $contractId : null,
            $invoiceDate,
            $dueDate !== '' ? $dueDate : null,
            $status,
            $subtotal,
            $subtotal,
            $subtotal,
            $arAccountId,
            $headerRevenueAccountId,
            $memo !== '' ? $memo : null,
            $invoiceId,
        ]);

        $pdo->prepare('DELETE FROM invoice_line WHERE invoice_id = ?')->execute([$invoiceId]);
        $lineStmt = $pdo->prepare('INSERT INTO invoice_line (invoice_id, line_number, service_code, description, quantity, unit_price, taxable, tax_amount, line_total, revenue_account_id) VALUES (?, ?, ?, ?, ?, ?, 0, 0.00, ?, ?)');
        foreach ($normalizedLines as $index => $lineData) {
            $lineStmt->execute([
                $invoiceId,
                $index + 1,
                $lineData['service_code'] !== '' ? $lineData['service_code'] : null,
                $lineData['description'],
                $lineData['quantity'],
                $lineData['unit_price'],
                $lineData['line_total'],
                $lineData['revenue_account_id'],
            ]);
        }

        $pdo->commit();
        return ['ok' => true, 'invoice_id' => $invoiceId, 'message' => 'Invoice updated.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to update invoice: ' . $e->getMessage()]];
    }
}

function accounting_create_invoice(array $data, int $userId): array {
    $clientId = (int)($data['client_id'] ?? 0);
    $invoiceDate = trim((string)($data['invoice_date'] ?? ''));
    $dueDate = trim((string)($data['due_date'] ?? ''));
    $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
    $memo = trim((string)($data['memo'] ?? ''));
    $contractId = (int)($data['contract_id'] ?? 0);
    $arAccountId = (int)($data['ar_account_id'] ?? 0);

    if ($invoiceDate === '') $invoiceDate = date('Y-m-d');
    if ($dueDate === '') $dueDate = date('Y-m-d', strtotime($invoiceDate . ' +15 days'));
    if ($arAccountId <= 0) $arAccountId = accounting_find_account_id_by_code('1100') ?? 0;

    $lines = accounting_normalize_invoice_lines($data);
    $normalizedLines = [];
    $errors = [];
    foreach ($lines as $idx => $lineData) {
        $itemId = (int)($lineData['item_id'] ?? 0);
        $serviceCode = trim((string)($lineData['service_code'] ?? ''));
        $description = trim((string)($lineData['description'] ?? ''));
        $quantity = (float)($lineData['quantity'] ?? 0);
        $unitPrice = (float)($lineData['unit_price'] ?? 0);
        $revenueAccountId = (int)($lineData['revenue_account_id'] ?? 0);

        if ($itemId > 0 && db_table_exists('service_item')) {
            $item = accounting_get_catalog_item($itemId);
            if ($item) {
                if ($description === '') $description = (string)$item['item_name'];
                if ($serviceCode === '') $serviceCode = (string)($item['item_code'] ?? '');
                if ($revenueAccountId <= 0 && !empty($item['revenue_account_id'])) $revenueAccountId = (int)$item['revenue_account_id'];
                if ((float)$unitPrice === 0.0 && isset($item['default_unit_price'])) $unitPrice = (float)$item['default_unit_price'];
            }
        }

        $isBlankRow = $itemId <= 0
            && $description === ''
            && $revenueAccountId <= 0
            && abs($quantity - 1.0) < 0.00001
            && abs($unitPrice) < 0.00001;
        if ($isBlankRow || ($itemId <= 0 && $description === '' && $revenueAccountId <= 0 && $quantity <= 0 && abs($unitPrice) < 0.00001)) {
            continue;
        }

        if ($description === '') $errors[] = 'Line ' . ($idx + 1) . ': description is required.';
        if ($quantity <= 0) $errors[] = 'Line ' . ($idx + 1) . ': quantity must be greater than zero.';
        if ($unitPrice < 0) $errors[] = 'Line ' . ($idx + 1) . ': unit price cannot be negative.';
        if ($revenueAccountId <= 0) $errors[] = 'Line ' . ($idx + 1) . ': choose a revenue account.';

        $normalizedLines[] = [
            'item_id' => $itemId,
            'service_code' => $serviceCode,
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'revenue_account_id' => $revenueAccountId,
            'line_total' => round($quantity * $unitPrice, 2),
        ];
    }

    if ($clientId <= 0) $errors[] = 'Choose a client.';
    if (!in_array($status, accounting_get_invoice_statuses(), true)) $errors[] = 'Choose a valid invoice status.';
    if ($arAccountId <= 0) $errors[] = 'Accounts receivable account was not found.';
    if (!$normalizedLines) $errors[] = 'Add at least one invoice line.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $subtotal = 0.0;
    foreach ($normalizedLines as $lineData) {
        $subtotal += (float)$lineData['line_total'];
    }
    $subtotal = round($subtotal, 2);
    $headerRevenueAccountId = (int)$normalizedLines[0]['revenue_account_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $invoiceNumber = accounting_next_invoice_number($pdo, $clientId, $invoiceDate);
        $st = $pdo->prepare('INSERT INTO customer_invoice (client_id, contract_id, invoice_number, invoice_date, due_date, status, subtotal_amount, tax_amount, total_amount, balance_due, ar_account_id, revenue_account_id, memo, source_system, source_record_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $clientId,
            $contractId > 0 ? $contractId : null,
            $invoiceNumber,
            $invoiceDate,
            $dueDate,
            $status,
            $subtotal,
            $subtotal,
            $status === 'PAID' ? 0 : $subtotal,
            $arAccountId,
            $headerRevenueAccountId,
            $memo !== '' ? $memo : null,
            $data['source_system'] ?? 'PORTAL',
            $data['source_record_id'] ?? null,
            $userId,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();

        $lineStmt = $pdo->prepare('INSERT INTO invoice_line (invoice_id, line_number, service_code, description, quantity, unit_price, taxable, tax_amount, line_total, revenue_account_id) VALUES (?, ?, ?, ?, ?, ?, 0, 0.00, ?, ?)');
        foreach ($normalizedLines as $index => $lineData) {
            $lineStmt->execute([
                $invoiceId,
                $index + 1,
                $lineData['service_code'] !== '' ? $lineData['service_code'] : null,
                $lineData['description'],
                $lineData['quantity'],
                $lineData['unit_price'],
                $lineData['line_total'],
                $lineData['revenue_account_id'],
            ]);
        }

        if (in_array($status, ['ISSUED', 'PARTIALLY_PAID', 'PAID'], true)) {
            accounting_post_invoice_journal($pdo, [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'client_id' => $clientId,
                'total_amount' => $subtotal,
                'ar_account_id' => $arAccountId,
                'revenue_account_id' => $headerRevenueAccountId,
                'lines' => $normalizedLines,
            ], $userId);
        }

        $pdo->commit();
        return ['ok' => true, 'invoice_id' => $invoiceId, 'message' => 'Invoice created.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to create invoice: ' . $e->getMessage()]];
    }
}

function accounting_list_invoices(int $limit = 100): array {
     $limit = max(1, min(500, $limit));
     $sql = "SELECT i.*, c.legal_name, c.dba_name,
                    a.account_code AS ar_account_code, a.account_name AS ar_account_name,
                    r.account_code AS revenue_account_code, r.account_name AS revenue_account_name,
                    EXISTS(SELECT 1 FROM gl_journal j WHERE j.source_type = 'INVOICE' AND j.source_id = i.invoice_id AND j.status <> 'VOID') AS has_journal
             FROM customer_invoice i
             INNER JOIN clients c ON c.client_id = i.client_id
             INNER JOIN gl_account a ON a.account_id = i.ar_account_id
             INNER JOIN gl_account r ON r.account_id = i.revenue_account_id
             ORDER BY i.invoice_date DESC, i.invoice_id DESC
             LIMIT {$limit}";
     return db()->query($sql)->fetchAll();
 }
 
 function accounting_get_invoice(int $invoiceId): ?array {
     $st = db()->prepare("SELECT i.*, c.client_code, c.legal_name, c.dba_name, c.email AS client_email,
                                 a.account_code AS ar_account_code, a.account_name AS ar_account_name,
                                 r.account_code AS revenue_account_code, r.account_name AS revenue_account_name,
                                 ctr.contract_number, ctr.contract_name,
                                 cl.address1 AS client_address1, cl.address2 AS client_address2,
                                 cl.city AS client_city, cl.state AS client_state,
                                 cl.postal_code AS client_postal_code, cl.country AS client_country,
                                 EXISTS(SELECT 1 FROM gl_journal j WHERE j.source_type = 'INVOICE' AND j.source_id = i.invoice_id AND j.status <> 'VOID') AS has_journal
                          FROM customer_invoice i
                          INNER JOIN clients c ON c.client_id = i.client_id
                          INNER JOIN gl_account a ON a.account_id = i.ar_account_id
                          INNER JOIN gl_account r ON r.account_id = i.revenue_account_id
                          LEFT JOIN contract ctr ON ctr.contract_id = i.contract_id
                          LEFT JOIN client_location cl ON cl.location_id = (
                              SELECT cl2.location_id
                              FROM client_location cl2
                              WHERE cl2.client_id = i.client_id
                              ORDER BY cl2.is_primary DESC, cl2.location_id ASC
                              LIMIT 1
                          )
                          WHERE i.invoice_id = ? LIMIT 1");
     $st->execute([$invoiceId]);
     $row = $st->fetch();
     return $row ?: null;
}

function accounting_invoice_lines(int $invoiceId): array {
     $st = db()->prepare("SELECT il.*, ga.account_code AS revenue_account_code, ga.account_name AS revenue_account_name
                          FROM invoice_line il
                          LEFT JOIN gl_account ga ON ga.account_id = il.revenue_account_id
                          WHERE il.invoice_id = ? ORDER BY il.line_number ASC, il.invoice_line_id ASC");
     $st->execute([$invoiceId]);
     return $st->fetchAll();
}

function accounting_invoice_can_receive_payment(array $invoice): bool {
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    $balanceDue = round((float)($invoice['balance_due'] ?? 0), 2);
    return in_array($status, ['ISSUED', 'PARTIALLY_PAID'], true) && $balanceDue > 0.00001;
}

function accounting_invoice_can_void(array $invoice): bool {
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    if (!in_array($status, ['DRAFT', 'ISSUED'], true)) {
        return false;
    }
    $invoiceId = (int)($invoice['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        return false;
    }
    $payments = accounting_invoice_payments($invoiceId);
    foreach ($payments as $payment) {
        $paymentStatus = strtoupper(trim((string)($payment['payment_status'] ?? 'POSTED')));
        if (!in_array($paymentStatus, ['VOID', 'FAILED'], true)) {
            return false;
        }
    }
    return true;
}

function accounting_void_invoice(int $invoiceId, string $reason, int $userId): array {
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) {
        return ['ok' => false, 'errors' => ['Invoice not found.']];
    }
    if (!accounting_invoice_can_void($invoice)) {
        return ['ok' => false, 'errors' => ['Only draft or unpaid issued invoices can be voided.']];
    }

    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'errors' => ['Void reason is required.']];
    }

    $lines = accounting_invoice_lines($invoiceId);
    $pdo = db();
    try {
        $stripeInvoiceId = trim((string)($invoice['stripe_invoice_id'] ?? ''));
        if ($stripeInvoiceId !== '') {
            require_once __DIR__ . '/payment_gateway.php';
            if (function_exists('payment_gateway_stripe_retire_invoice')) {
                payment_gateway_stripe_retire_invoice($stripeInvoiceId);
            }
        }

        $pdo->beginTransaction();

        if ($status === 'ISSUED' && !empty($invoice['has_journal'])) {
            $journal = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'INVOICE_VOID', ?, ?, ?, ?)");
            $journal->execute([
                date('Y-m-d'),
                $invoiceId,
                'VOID-' . (string)$invoice['invoice_number'],
                'Void invoice ' . (string)$invoice['invoice_number'] . ' - ' . $reason,
                $userId > 0 ? $userId : null,
            ]);
            $journalId = (int)$pdo->lastInsertId();

            $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, client_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $lineNumber = 1;
            $revenueGroups = [];
            foreach ($lines as $invoiceLine) {
                $accountId = (int)($invoiceLine['revenue_account_id'] ?? 0);
                $amount = round((float)($invoiceLine['line_total'] ?? ((float)($invoiceLine['quantity'] ?? 0) * (float)($invoiceLine['unit_price'] ?? 0))), 2);
                if ($accountId <= 0 || abs($amount) < 0.00001) {
                    continue;
                }
                if (!isset($revenueGroups[$accountId])) {
                    $revenueGroups[$accountId] = 0.0;
                }
                $revenueGroups[$accountId] += $amount;
            }
            if (!$revenueGroups) {
                $revenueGroups[(int)$invoice['revenue_account_id']] = round((float)$invoice['total_amount'], 2);
            }
            foreach ($revenueGroups as $accountId => $amount) {
                $line->execute([$journalId, $lineNumber++, (int)$accountId, (int)$invoice['client_id'], round((float)$amount, 2), 0, 'Reverse revenue for voided invoice']);
            }
            $line->execute([$journalId, $lineNumber++, (int)$invoice['ar_account_id'], (int)$invoice['client_id'], 0, round((float)$invoice['total_amount'], 2), 'Reverse accounts receivable']);
        }

        $fields = ['status = ?', 'balance_due = 0.00', 'updated_at = CURRENT_TIMESTAMP'];
        $params = ['VOID'];
        if (db_column_exists('customer_invoice', 'stripe_invoice_status')) {
            $fields[] = 'stripe_invoice_status = ?';
            $params[] = $stripeInvoiceId !== '' ? 'void' : null;
        }
        if (db_column_exists('customer_invoice', 'stripe_sync_status')) {
            $fields[] = 'stripe_sync_status = ?';
            $params[] = $stripeInvoiceId !== '' ? 'SYNCED' : 'PENDING';
        }
        if (db_column_exists('customer_invoice', 'stripe_last_sync_at')) {
            $fields[] = 'stripe_last_sync_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if (db_column_exists('customer_invoice', 'stripe_last_error')) {
            $fields[] = 'stripe_last_error = NULL';
        }
        $params[] = $invoiceId;
        $st = $pdo->prepare('UPDATE customer_invoice SET ' . implode(', ', $fields) . ' WHERE invoice_id = ?');
        $st->execute($params);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Invoice voided successfully.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['Failed to void invoice: ' . $e->getMessage()]];
    }
}

function accounting_list_open_invoices_for_payment(int $limit = 200, ?int $clientId = null): array {
    $limit = max(1, min(500, $limit));
    $params = [];
    $sql = "SELECT i.*, c.client_code, c.legal_name, c.dba_name,
                   a.account_code AS ar_account_code, a.account_name AS ar_account_name,
                   r.account_code AS revenue_account_code, r.account_name AS revenue_account_name,
                   EXISTS(SELECT 1 FROM gl_journal j WHERE j.source_type = 'INVOICE' AND j.source_id = i.invoice_id AND j.status <> 'VOID') AS has_journal
            FROM customer_invoice i
            INNER JOIN clients c ON c.client_id = i.client_id
            INNER JOIN gl_account a ON a.account_id = i.ar_account_id
            INNER JOIN gl_account r ON r.account_id = i.revenue_account_id
            WHERE i.status IN ('ISSUED','PARTIALLY_PAID') AND i.balance_due > 0";
    if ($clientId !== null && $clientId > 0) {
        $sql .= ' AND i.client_id = ?';
        $params[] = $clientId;
    }
    $sql .= " ORDER BY COALESCE(i.due_date, i.invoice_date) ASC, i.invoice_id ASC LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_issue_invoice(int $invoiceId, int $userId): array {
     $invoice = accounting_get_invoice($invoiceId);
     if (!$invoice) {
         return ['ok' => false, 'errors' => ['Invoice not found.']];
     }
     $lines = accounting_invoice_lines($invoiceId);
     $errors = accounting_validate_invoice_for_issue($invoice, $lines);
     if (accounting_has_invoice_journal($invoiceId)) {
         $errors[] = 'This invoice has already been posted to the general ledger.';
     }
     if ($errors) {
         return ['ok' => false, 'errors' => array_values(array_unique($errors))];
     }

     $pdo = db();
     $pdo->beginTransaction();
     try {
         $upd = $pdo->prepare("UPDATE customer_invoice SET status = 'ISSUED', balance_due = total_amount, updated_at = CURRENT_TIMESTAMP WHERE invoice_id = ? AND status = 'DRAFT'");
         $upd->execute([$invoiceId]);
         if ($upd->rowCount() !== 1) {
             throw new RuntimeException('Invoice could not be marked as issued.');
         }

         accounting_post_invoice_journal($pdo, [
             'invoice_id' => $invoiceId,
             'invoice_number' => $invoice['invoice_number'],
             'invoice_date' => $invoice['invoice_date'],
             'client_id' => $invoice['client_id'],
             'total_amount' => $invoice['total_amount'],
             'ar_account_id' => $invoice['ar_account_id'],
             'revenue_account_id' => $invoice['revenue_account_id'],
             'lines' => $lines,
         ], $userId);

         $pdo->commit();
         return ['ok' => true, 'message' => 'Invoice issued and posted to the general ledger.'];
     } catch (Throwable $e) {
         if ($pdo->inTransaction()) $pdo->rollBack();
         return ['ok' => false, 'errors' => ['Failed to issue invoice: ' . $e->getMessage()]];
     }
}


function accounting_invoice_payments(int $invoiceId): array {
    if (!db_table_exists('payment_receipt') || !db_table_exists('payment_invoice_apply')) {
        return [];
    }

    $supportsExtended = accounting_payment_table_supports_processor_fields();
    $grossSql = $supportsExtended ? 'COALESCE(p.gross_amount, p.amount_received)' : 'p.amount_received';
    $feeSql = $supportsExtended ? 'COALESCE(p.fee_amount, 0)' : '0';
    $netSql = $supportsExtended ? 'COALESCE(p.net_amount, p.amount_received)' : 'p.amount_received';
    $statusSql = $supportsExtended ? "COALESCE(p.payment_status, 'POSTED')" : "'POSTED'";

    $sql = "SELECT p.payment_id, p.payment_date, p.payment_method, p.reference_number, p.memo, p.created_at,
                   {$grossSql} AS gross_amount,
                   {$feeSql} AS fee_amount,
                   {$netSql} AS net_amount,
                   pia.amount_applied,
                   {$statusSql} AS payment_status,
                   pu.display_name AS created_by_name
            FROM payment_invoice_apply pia
            INNER JOIN payment_receipt p ON p.payment_id = pia.payment_id
            LEFT JOIN portal_user pu ON pu.user_id = p.created_by
            WHERE pia.invoice_id = ?
            ORDER BY p.payment_date DESC, p.payment_id DESC";
    $st = db()->prepare($sql);
    $st->execute([$invoiceId]);
    return $st->fetchAll();
}

function accounting_invoice_payment_snapshot(array $invoice, ?array $payments = null): array {
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    if ($payments === null) {
        $invoiceId = (int)($invoice['invoice_id'] ?? 0);
        $payments = $invoiceId > 0 ? accounting_invoice_payments($invoiceId) : [];
    }

    $payments = is_array($payments) ? $payments : [];
    $lastPayment = null;
    $totalApplied = 0.0;

    foreach ($payments as $payment) {
        $paymentStatus = strtoupper(trim((string)($payment['payment_status'] ?? 'POSTED')));
        if ($paymentStatus === 'VOID' || $paymentStatus === 'FAILED') {
            continue;
        }

        $totalApplied += (float)($payment['amount_applied'] ?? $payment['gross_amount'] ?? 0);

        if ($lastPayment === null) {
            $lastPayment = $payment;
            continue;
        }

        $currentDate = (string)($payment['payment_date'] ?? '');
        $lastDate = (string)($lastPayment['payment_date'] ?? '');
        $currentId = (int)($payment['payment_id'] ?? 0);
        $lastId = (int)($lastPayment['payment_id'] ?? 0);
        if ($currentDate > $lastDate || ($currentDate === $lastDate && $currentId > $lastId)) {
            $lastPayment = $payment;
        }
    }

    $balanceDue = round((float)($invoice['balance_due'] ?? 0), 2);
    $totalAmount = round((float)($invoice['total_amount'] ?? 0), 2);
    $isPaid = $status === 'PAID' || ($balanceDue <= 0.00001 && ($totalAmount > 0 || $totalApplied > 0));
    $hasPayments = $totalApplied > 0.00001 || $lastPayment !== null;

    $lastPaymentDate = trim((string)($lastPayment['payment_date'] ?? ''));
    $lastPaymentMethod = strtoupper(trim((string)($lastPayment['payment_method'] ?? '')));
    $lastReference = trim((string)($lastPayment['reference_number'] ?? ''));

    $detailParts = [];
    if ($isPaid) {
        $detailParts[] = 'Paid in full';
        if ($lastPaymentDate !== '') {
            $detailParts[] = 'on ' . $lastPaymentDate;
        }
        if ($lastPaymentMethod !== '') {
            $detailParts[] = 'via ' . $lastPaymentMethod;
        }
        if ($lastReference !== '') {
            $detailParts[] = '(Ref ' . $lastReference . ')';
        }
    } elseif ($status === 'PARTIALLY_PAID' || $hasPayments) {
        $detailParts[] = 'Payments received: $' . number_format($totalApplied, 2);
        $detailParts[] = 'Remaining balance: $' . number_format($balanceDue, 2);
        if ($lastPaymentDate !== '') {
            $detailParts[] = 'Last payment ' . $lastPaymentDate;
        }
    } else {
        $detailParts[] = 'No payment recorded yet';
    }

    return [
        'status' => $status,
        'is_paid' => $isPaid,
        'show_paid_watermark' => $isPaid,
        'has_payments' => $hasPayments,
        'payments_received' => round($totalApplied, 2),
        'balance_due' => $balanceDue,
        'last_payment_date' => $lastPaymentDate,
        'last_payment_method' => $lastPaymentMethod,
        'last_reference_number' => $lastReference,
        'detail_text' => implode(' ', $detailParts),
    ];
}

function accounting_payment_totals(array $filters = []): array {
    if (!db_table_exists('payment_receipt')) {
        return ['count' => 0, 'gross_total' => 0.0, 'fee_total' => 0.0, 'net_total' => 0.0, 'applied_total' => 0.0, 'unapplied_total' => 0.0];
    }

    $supportsExtended = accounting_payment_table_supports_processor_fields();
    $grossSql = $supportsExtended ? 'COALESCE(p.gross_amount, p.amount_received)' : 'p.amount_received';
    $feeSql = $supportsExtended ? 'COALESCE(p.fee_amount, 0)' : '0';
    $netSql = $supportsExtended ? 'COALESCE(p.net_amount, p.amount_received)' : 'p.amount_received';
    $appliedSql = $supportsExtended ? 'COALESCE(p.applied_amount, p.amount_received)' : 'p.amount_received';
    $unappliedSql = $supportsExtended ? 'COALESCE(p.unapplied_amount, 0)' : '0';

    $params = [];
    $where = [];
    if (!empty($filters['method']) && in_array((string)$filters['method'], accounting_get_payment_methods(), true)) {
        $where[] = 'p.payment_method = ?';
        $params[] = (string)$filters['method'];
    }
    if (!empty($filters['status']) && in_array((string)$filters['status'], accounting_payment_statuses(), true)) {
        $where[] = $supportsExtended ? 'p.payment_status = ?' : "'POSTED' = ?";
        $params[] = (string)$filters['status'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'p.payment_date >= ?';
        $params[] = (string)$filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'p.payment_date <= ?';
        $params[] = (string)$filters['date_to'];
    }
    if (!empty($filters['client'])) {
        $where[] = '(c.legal_name LIKE ? OR c.dba_name LIKE ? OR c.client_code LIKE ? OR p.reference_number LIKE ?)';
        $like = '%' . trim((string)$filters['client']) . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = "SELECT COUNT(*) AS payment_count,
                   COALESCE(SUM({$grossSql}), 0) AS gross_total,
                   COALESCE(SUM({$feeSql}), 0) AS fee_total,
                   COALESCE(SUM({$netSql}), 0) AS net_total,
                   COALESCE(SUM({$appliedSql}), 0) AS applied_total,
                   COALESCE(SUM({$unappliedSql}), 0) AS unapplied_total
            FROM payment_receipt p
            INNER JOIN clients c ON c.client_id = p.client_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch() ?: [];
    return [
        'count' => (int)($row['payment_count'] ?? 0),
        'gross_total' => (float)($row['gross_total'] ?? 0),
        'fee_total' => (float)($row['fee_total'] ?? 0),
        'net_total' => (float)($row['net_total'] ?? 0),
        'applied_total' => (float)($row['applied_total'] ?? 0),
        'unapplied_total' => (float)($row['unapplied_total'] ?? 0),
    ];
}

function accounting_list_payments(array $filters = [], int $limit = 200): array {
    if (!db_table_exists('payment_receipt')) {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $supportsExtended = accounting_payment_table_supports_processor_fields();
    $grossSql = $supportsExtended ? 'COALESCE(p.gross_amount, p.amount_received)' : 'p.amount_received';
    $feeSql = $supportsExtended ? 'COALESCE(p.fee_amount, 0)' : '0';
    $netSql = $supportsExtended ? 'COALESCE(p.net_amount, p.amount_received)' : 'p.amount_received';
    $appliedSql = $supportsExtended ? 'COALESCE(p.applied_amount, p.amount_received)' : 'p.amount_received';
    $unappliedSql = $supportsExtended ? 'COALESCE(p.unapplied_amount, 0)' : '0';
    $statusSql = $supportsExtended ? "COALESCE(p.payment_status, 'POSTED')" : "'POSTED'";

    $params = [];
    $where = [];
    if (!empty($filters['method']) && in_array((string)$filters['method'], accounting_get_payment_methods(), true)) {
        $where[] = 'p.payment_method = ?';
        $params[] = (string)$filters['method'];
    }
    if (!empty($filters['status']) && in_array((string)$filters['status'], accounting_payment_statuses(), true)) {
        $where[] = $supportsExtended ? 'p.payment_status = ?' : "'POSTED' = ?";
        $params[] = (string)$filters['status'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'p.payment_date >= ?';
        $params[] = (string)$filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'p.payment_date <= ?';
        $params[] = (string)$filters['date_to'];
    }
    if (!empty($filters['client'])) {
        $where[] = '(c.legal_name LIKE ? OR c.dba_name LIKE ? OR c.client_code LIKE ? OR p.reference_number LIKE ?)';
        $like = '%' . trim((string)$filters['client']) . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = "SELECT p.payment_id, p.payment_date, p.payment_method, p.reference_number, p.memo, p.created_at,
                   {$grossSql} AS gross_amount,
                   {$feeSql} AS fee_amount,
                   {$netSql} AS net_amount,
                   {$appliedSql} AS applied_amount,
                   {$unappliedSql} AS unapplied_amount,
                   {$statusSql} AS payment_status,
                   c.client_id, c.client_code, c.legal_name, c.dba_name,
                   pu.display_name AS created_by_name,
                   MAX(i.invoice_id) AS invoice_id,
                   MAX(i.invoice_number) AS invoice_number
            FROM payment_receipt p
            INNER JOIN clients c ON c.client_id = p.client_id
            LEFT JOIN portal_user pu ON pu.user_id = p.created_by
            LEFT JOIN payment_invoice_apply pia ON pia.payment_id = p.payment_id
            LEFT JOIN customer_invoice i ON i.invoice_id = pia.invoice_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " GROUP BY p.payment_id, p.payment_date, p.payment_method, p.reference_number, p.memo, p.created_at, {$grossSql}, {$feeSql}, {$netSql}, {$appliedSql}, {$unappliedSql}, {$statusSql}, c.client_id, c.client_code, c.legal_name, c.dba_name, pu.display_name
              ORDER BY p.payment_date DESC, p.payment_id DESC
              LIMIT {$limit}";

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_get_payment(int $paymentId): ?array {
    if ($paymentId <= 0 || !db_table_exists('payment_receipt')) {
        return null;
    }

    $supportsExtended = accounting_payment_table_supports_processor_fields();
    $grossSql = $supportsExtended ? 'COALESCE(p.gross_amount, p.amount_received)' : 'p.amount_received';
    $feeSql = $supportsExtended ? 'COALESCE(p.fee_amount, 0)' : '0';
    $netSql = $supportsExtended ? 'COALESCE(p.net_amount, p.amount_received)' : 'p.amount_received';
    $appliedSql = $supportsExtended ? 'COALESCE(p.applied_amount, p.amount_received)' : 'p.amount_received';
    $unappliedSql = $supportsExtended ? 'COALESCE(p.unapplied_amount, 0)' : '0';
    $statusSql = $supportsExtended ? "COALESCE(p.payment_status, 'POSTED')" : "'POSTED'";

    $extraColumns = [];
    foreach (['processor_name', 'processor_txn_id', 'processor_payment_intent_id', 'processor_charge_id', 'processor_customer_id', 'settled_at', 'voided_at', 'void_reason'] as $column) {
        $extraColumns[] = db_column_exists('payment_receipt', $column) ? 'p.' . $column : 'NULL AS ' . $column;
    }

    $sql = "SELECT p.payment_id, p.client_id, p.payment_date, p.payment_method, p.reference_number, p.memo, p.created_at,
                   p.deposit_account_id, p.ar_account_id,
                   {$grossSql} AS gross_amount,
                   {$feeSql} AS fee_amount,
                   {$netSql} AS net_amount,
                   {$appliedSql} AS applied_amount,
                   {$unappliedSql} AS unapplied_amount,
                   {$statusSql} AS payment_status,
                   " . implode(', ', $extraColumns) . ",
                   c.client_code, c.legal_name, c.dba_name,
                   da.account_code AS deposit_account_code, da.account_name AS deposit_account_name,
                   ara.account_code AS ar_account_code, ara.account_name AS ar_account_name,
                   fea.account_code AS fee_account_code, fea.account_name AS fee_account_name,
                   pu.display_name AS created_by_name
            FROM payment_receipt p
            INNER JOIN clients c ON c.client_id = p.client_id
            LEFT JOIN gl_account da ON da.account_id = p.deposit_account_id
            LEFT JOIN gl_account ara ON ara.account_id = p.ar_account_id
            LEFT JOIN gl_account fea ON fea.account_id = p.fee_expense_account_id
            LEFT JOIN portal_user pu ON pu.user_id = p.created_by
            WHERE p.payment_id = ?
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$paymentId]);
    $row = $st->fetch();
    return $row ?: null;
}

function accounting_payment_applications(int $paymentId): array {
    if ($paymentId <= 0 || !db_table_exists('payment_invoice_apply')) {
        return [];
    }

    $sql = "SELECT pia.payment_apply_id, pia.amount_applied,
                   i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.status, i.total_amount, i.balance_due,
                   c.client_id, c.legal_name, c.dba_name
            FROM payment_invoice_apply pia
            INNER JOIN customer_invoice i ON i.invoice_id = pia.invoice_id
            INNER JOIN clients c ON c.client_id = i.client_id
            WHERE pia.payment_id = ?
            ORDER BY i.invoice_date DESC, i.invoice_id DESC";
    $st = db()->prepare($sql);
    $st->execute([$paymentId]);
    return $st->fetchAll();
}

function accounting_payment_journals(int $paymentId): array {
    if ($paymentId <= 0 || !db_table_exists('gl_journal') || !db_table_exists('gl_journal_line')) {
        return [];
    }

    $sql = "SELECT j.journal_id, j.journal_date, j.status, j.source_type, j.reference_number, j.memo,
                   l.line_number, l.debit_amount, l.credit_amount, l.line_memo,
                   a.account_code, a.account_name
            FROM gl_journal j
            INNER JOIN gl_journal_line l ON l.journal_id = j.journal_id
            LEFT JOIN gl_account a ON a.account_id = l.account_id
            WHERE (j.source_type = 'PAYMENT' AND j.source_id = ?)
               OR (j.source_type = 'PAYMENT_VOID' AND j.reference_number = ?)
               OR (j.source_type = 'PAYMENT_REFUND' AND j.reference_number = ?)
            ORDER BY j.journal_date DESC, j.journal_id DESC, l.line_number ASC";
    $st = db()->prepare($sql);
    $st->execute([$paymentId, 'VOID-' . $paymentId, 'REFUND-' . $paymentId]);
    return $st->fetchAll();
}

function accounting_void_payment(int $paymentId, string $reason, int $userId): array {
    $payment = accounting_get_payment($paymentId);
    if (!$payment) {
        return ['ok' => false, 'errors' => ['Payment not found.']];
    }
    if (strtoupper((string)$payment['payment_status']) === 'VOID') {
        return ['ok' => false, 'errors' => ['This payment is already voided.']];
    }
    if (strtoupper((string)$payment['payment_status']) !== 'POSTED') {
        return ['ok' => false, 'errors' => ['Only posted payments can be voided right now.']];
    }
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'errors' => ['Void reason is required.']];
    }

    $applications = accounting_payment_applications($paymentId);
    if ($applications === []) {
        return ['ok' => false, 'errors' => ['Payment has no invoice applications to reverse.']];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($applications as $application) {
            $invoiceId = (int)$application['invoice_id'];
            $invoice = accounting_get_invoice($invoiceId);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found while voiding payment.');
            }
            $restoredBalance = round((float)$invoice['balance_due'] + (float)$application['amount_applied'], 2);
            $invoiceStatus = $restoredBalance <= 0.00001 ? 'PAID' : 'PARTIALLY_PAID';
            if ($restoredBalance >= ((float)$invoice['total_amount'] - 0.00001)) {
                $invoiceStatus = 'ISSUED';
            }
            $st = $pdo->prepare('UPDATE customer_invoice SET balance_due = ?, status = ? WHERE invoice_id = ?');
            $st->execute([$restoredBalance, $invoiceStatus, $invoiceId]);
        }

        $voidFields = [];
        $voidParams = [];
        if (db_column_exists('payment_receipt', 'payment_status')) {
            $voidFields[] = 'payment_status = ?';
            $voidParams[] = 'VOID';
        }
        if (db_column_exists('payment_receipt', 'voided_at')) {
            $voidFields[] = 'voided_at = NOW()';
        }
        if (db_column_exists('payment_receipt', 'void_reason')) {
            $voidFields[] = 'void_reason = ?';
            $voidParams[] = $reason;
        } else {
            $existingMemo = trim((string)($payment['memo'] ?? ''));
            $memo = trim($existingMemo . ($existingMemo !== '' ? "
" : '') . 'VOID: ' . $reason);
            $voidFields[] = 'memo = ?';
            $voidParams[] = $memo;
        }
        if ($voidFields !== []) {
            $voidParams[] = $paymentId;
            $sql = 'UPDATE payment_receipt SET ' . implode(', ', $voidFields) . ' WHERE payment_id = ?';
            $st = $pdo->prepare($sql);
            $st->execute($voidParams);
        }

        $journal = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'PAYMENT_VOID', ?, ?, ?, ?)");
        $journal->execute([
            date('Y-m-d'),
            $paymentId,
            'VOID-' . $paymentId,
            'Void payment #' . $paymentId . ' - ' . $reason,
            $userId,
        ]);
        $journalId = (int)$pdo->lastInsertId();

        $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, client_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $lineNumber = 1;
        $line->execute([$journalId, $lineNumber++, (int)$payment['ar_account_id'], (int)$payment['client_id'], (float)$payment['applied_amount'], 0, 'Reverse accounts receivable settlement']);
        if ((float)$payment['fee_amount'] > 0.00001 && !empty($payment['fee_account_code'])) {
            $feeAccountId = (int)accounting_find_account_id_by_code((string)$payment['fee_account_code']);
            if ($feeAccountId > 0) {
                $line->execute([$journalId, $lineNumber++, $feeAccountId, (int)$payment['client_id'], 0, (float)$payment['fee_amount'], 'Reverse merchant processing fee']);
            }
        }
        $line->execute([$journalId, $lineNumber++, (int)$payment['deposit_account_id'], (int)$payment['client_id'], 0, (float)$payment['net_amount'], 'Reverse customer payment deposit']);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Payment voided and invoice balances restored.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['Failed to void payment: ' . $e->getMessage()]];
    }
}

function accounting_payment_can_refund(array $payment): bool {
    $status = strtoupper(trim((string)($payment['payment_status'] ?? 'POSTED')));
    if ($status !== 'POSTED') {
        return false;
    }
    $processor = strtoupper(trim((string)($payment['processor_name'] ?? '')));
    if ($processor !== 'STRIPE') {
        return false;
    }
    if (trim((string)($payment['processor_charge_id'] ?? '')) !== '' || trim((string)($payment['processor_payment_intent_id'] ?? '')) !== '') {
        return true;
    }
    $processorTxnId = trim((string)($payment['processor_txn_id'] ?? ''));
    return $processorTxnId !== '' && preg_match('/^(in_|pi_|ch_|py_)/i', $processorTxnId) === 1;
}

function accounting_refund_payment(int $paymentId, string $reason, int $userId): array {
    $payment = accounting_get_payment($paymentId);
    if (!$payment) {
        return ['ok' => false, 'errors' => ['Payment not found.']];
    }
    if (!accounting_payment_can_refund($payment)) {
        return ['ok' => false, 'errors' => ['Only posted Stripe payments can be refunded from this screen.']];
    }
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'errors' => ['Refund reason is required.']];
    }
    if ((float)($payment['unapplied_amount'] ?? 0) > 0.00001) {
        return ['ok' => false, 'errors' => ['Payments with unapplied balances cannot be refunded from this screen yet.']];
    }

    require_once __DIR__ . '/payment_gateway.php';

    try {
        $refund = payment_gateway_stripe_create_refund($payment, null, $reason);
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Stripe refund failed: ' . $e->getMessage()]];
    }

    $refundId = trim((string)($refund['id'] ?? ''));
    $applications = accounting_payment_applications($paymentId);
    if ($applications === []) {
        return ['ok' => false, 'errors' => ['Stripe refunded successfully, but no invoice applications were found to reverse locally. Manual review is required.']];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($applications as $application) {
            $invoiceId = (int)$application['invoice_id'];
            $invoice = accounting_get_invoice($invoiceId);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found while refunding payment.');
            }
            $restoredBalance = round((float)$invoice['balance_due'] + (float)$application['amount_applied'], 2);
            $invoiceStatus = $restoredBalance <= 0.00001 ? 'PAID' : 'PARTIALLY_PAID';
            if ($restoredBalance >= ((float)$invoice['total_amount'] - 0.00001)) {
                $invoiceStatus = 'ISSUED';
            }
            $st = $pdo->prepare('UPDATE customer_invoice SET balance_due = ?, status = ? WHERE invoice_id = ?');
            $st->execute([$restoredBalance, $invoiceStatus, $invoiceId]);
        }

        $voidFields = [];
        $voidParams = [];
        if (db_column_exists('payment_receipt', 'payment_status')) {
            $voidFields[] = 'payment_status = ?';
            $voidParams[] = 'VOID';
        }
        if (db_column_exists('payment_receipt', 'voided_at')) {
            $voidFields[] = 'voided_at = NOW()';
        }
        $note = 'REFUND' . ($refundId !== '' ? ' ' . $refundId : '') . ': ' . $reason;
        if (db_column_exists('payment_receipt', 'void_reason')) {
            $voidFields[] = 'void_reason = ?';
            $voidParams[] = $note;
        }
        $existingMemo = trim((string)($payment['memo'] ?? ''));
        $memo = trim($existingMemo . ($existingMemo !== '' ? "
" : '') . $note);
        $voidFields[] = 'memo = ?';
        $voidParams[] = $memo;
        if ($refundId !== '' && db_column_exists('payment_receipt', 'processor_txn_id') && trim((string)($payment['processor_txn_id'] ?? '')) === '') {
            $voidFields[] = 'processor_txn_id = ?';
            $voidParams[] = $refundId;
        }
        $voidParams[] = $paymentId;
        $st = $pdo->prepare('UPDATE payment_receipt SET ' . implode(', ', $voidFields) . ' WHERE payment_id = ?');
        $st->execute($voidParams);

        $journal = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'PAYMENT_REFUND', ?, ?, ?, ?)");
        $journal->execute([
            date('Y-m-d'),
            $paymentId,
            'REFUND-' . $paymentId,
            'Refund payment #' . $paymentId . ($refundId !== '' ? ' (' . $refundId . ')' : '') . ' - ' . $reason,
            $userId > 0 ? $userId : null,
        ]);
        $journalId = (int)$pdo->lastInsertId();

        $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, client_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $lineNumber = 1;
        $refundedAmount = round((float)($payment['applied_amount'] ?? $payment['gross_amount'] ?? 0), 2);
        if ($refundedAmount <= 0.00001) {
            $refundedAmount = round((float)($payment['gross_amount'] ?? 0), 2);
        }
        $line->execute([$journalId, $lineNumber++, (int)$payment['ar_account_id'], (int)$payment['client_id'], $refundedAmount, 0, 'Restore accounts receivable after Stripe refund']);
        $line->execute([$journalId, $lineNumber++, (int)$payment['deposit_account_id'], (int)$payment['client_id'], 0, $refundedAmount, 'Cash refund to customer']);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Stripe refund created and invoice balances restored locally.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $extra = $refundId !== '' ? ' Stripe refund id: ' . $refundId . '.' : '';
        return ['ok' => false, 'errors' => ['Stripe refund succeeded, but the local reversal failed: ' . $e->getMessage() . $extra . ' Manual review is required.']];
    }
}

function accounting_record_invoice_payment(int $invoiceId, array $data, int $userId): array {
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) return ['ok' => false, 'errors' => ['Invoice not found.']];
    $status = strtoupper((string)$invoice['status']);
    if (!in_array($status, ['ISSUED', 'PARTIALLY_PAID'], true)) return ['ok' => false, 'errors' => ['Only issued invoices can receive payments.']];

    $paymentDate = trim((string)($data['payment_date'] ?? '')) ?: date('Y-m-d');
    $paymentMethod = strtoupper(trim((string)($data['payment_method'] ?? 'OTHER')));
    $grossAmount = round((float)($data['gross_amount'] ?? $data['amount'] ?? 0), 2);
    $feeAmount = round((float)($data['fee_amount'] ?? 0), 2);
    $depositAccountId = (int)($data['deposit_account_id'] ?? 0);
    $feeExpenseAccountId = (int)($data['fee_expense_account_id'] ?? 0);
    $reference = trim((string)($data['reference_number'] ?? ''));
    $memo = trim((string)($data['memo'] ?? ''));
    $processorName = trim((string)($data['processor_name'] ?? ''));
    $processorTxnId = trim((string)($data['processor_txn_id'] ?? ''));
    $paymentStatus = strtoupper(trim((string)($data['payment_status'] ?? 'POSTED')));
    $effectiveUserId = $userId > 0 ? $userId : null;

    if (!in_array($paymentMethod, accounting_get_payment_methods(), true)) {
        $paymentMethod = 'OTHER';
    }
    if ($paymentMethod === 'CASH') {
        $feeAmount = 0.00;
    }
    if (!in_array($paymentStatus, accounting_payment_statuses(), true)) {
        $paymentStatus = 'POSTED';
    }

    $netAmount = round($grossAmount - $feeAmount, 2);
    $appliedAmount = $grossAmount;
    $unappliedAmount = 0.00;

    $errors = [];
    if ($grossAmount <= 0) $errors[] = 'Gross amount must be greater than zero.';
    if ($feeAmount < 0) $errors[] = 'Fee amount cannot be negative.';
    if ($netAmount < 0) $errors[] = 'Fee amount cannot exceed gross amount.';
    if ($appliedAmount - (float)$invoice['balance_due'] > 0.00001) $errors[] = 'Gross amount cannot exceed the invoice balance.';
    if ($depositAccountId <= 0) $errors[] = 'Choose a deposit account.';
    if ($feeAmount > 0 && $feeExpenseAccountId <= 0) $errors[] = 'Choose a fee expense account when a processing fee is entered.';
    if ($paymentDate === '') $errors[] = 'Payment date is required.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $supportsExtended = accounting_payment_table_supports_processor_fields();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($supportsExtended) {
            $st = $pdo->prepare("INSERT INTO payment_receipt (client_id, payment_date, payment_method, reference_number, amount_received, gross_amount, fee_amount, net_amount, applied_amount, unapplied_amount, deposit_account_id, ar_account_id, fee_expense_account_id, memo, payment_status, processor_name, processor_txn_id, source_system, source_record_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PORTAL_INVOICE', ?, ?)");
            $st->execute([
                (int)$invoice['client_id'],
                $paymentDate,
                $paymentMethod,
                $reference !== '' ? $reference : null,
                $grossAmount,
                $grossAmount,
                $feeAmount,
                $netAmount,
                $appliedAmount,
                $unappliedAmount,
                $depositAccountId,
                (int)$invoice['ar_account_id'],
                $feeExpenseAccountId > 0 ? $feeExpenseAccountId : null,
                $memo !== '' ? $memo : null,
                $paymentStatus,
                $processorName !== '' ? $processorName : null,
                $processorTxnId !== '' ? $processorTxnId : null,
                (string)$invoiceId,
                $effectiveUserId,
            ]);
        } else {
            $st = $pdo->prepare("INSERT INTO payment_receipt (client_id, payment_date, payment_method, reference_number, amount_received, deposit_account_id, ar_account_id, memo, source_system, source_record_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PORTAL_INVOICE', ?, ?)");
            $st->execute([
                (int)$invoice['client_id'],
                $paymentDate,
                $paymentMethod,
                $reference !== '' ? $reference : null,
                $grossAmount,
                $depositAccountId,
                (int)$invoice['ar_account_id'],
                $memo !== '' ? $memo : null,
                (string)$invoiceId,
                $effectiveUserId,
            ]);
        }
        $paymentId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('INSERT INTO payment_invoice_apply (payment_id, invoice_id, amount_applied) VALUES (?, ?, ?)');
        $st->execute([$paymentId, $invoiceId, $appliedAmount]);

        if ($paymentStatus === 'POSTED') {
            $memoText = 'Payment for ' . $invoice['invoice_number'];
            $st = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'PAYMENT', ?, ?, ?, ?)");
            $st->execute([$paymentDate, $paymentId, $reference !== '' ? $reference : $invoice['invoice_number'], $memoText, $effectiveUserId]);
            $journalId = (int)$pdo->lastInsertId();

            $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, client_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $lineNumber = 1;
            $line->execute([$journalId, $lineNumber++, $depositAccountId, (int)$invoice['client_id'], $netAmount, 0, 'Customer payment deposit']);
            if ($feeAmount > 0 && $feeExpenseAccountId > 0) {
                $line->execute([$journalId, $lineNumber++, $feeExpenseAccountId, (int)$invoice['client_id'], $feeAmount, 0, 'Merchant processing fee']);
            }
            $line->execute([$journalId, $lineNumber++, (int)$invoice['ar_account_id'], (int)$invoice['client_id'], 0, $appliedAmount, 'Accounts receivable settlement']);
        }

        $newBalance = round((float)$invoice['balance_due'] - $appliedAmount, 2);
        $newStatus = $newBalance <= 0.00001 ? 'PAID' : 'PARTIALLY_PAID';
        $st = $pdo->prepare('UPDATE customer_invoice SET balance_due = ?, status = ? WHERE invoice_id = ?');
        $st->execute([$newBalance <= 0 ? 0 : $newBalance, $newStatus, $invoiceId]);
        $pdo->commit();
        return ['ok' => true, 'payment_id' => $paymentId, 'message' => $newStatus === 'PAID' ? 'Payment recorded and invoice marked paid.' : 'Partial payment recorded.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to record payment: ' . $e->getMessage()]];
    }
}


function accounting_bank_reconciliation_ready(): bool {
    return db_table_exists('bank_reconciliation') && db_table_exists('bank_reconciliation_item');
}

function accounting_bank_reconciliation_account_options(): array {
    return accounting_payment_account_options();
}

function accounting_default_reconciliation_account_id(): int {
    $default = accounting_default_cash_account_id();
    if ($default > 0) {
        return $default;
    }
    $options = accounting_bank_reconciliation_account_options();
    return (int)($options[0]['account_id'] ?? 0);
}

function accounting_get_bank_reconciliation(int $reconciliationId): ?array {
    if ($reconciliationId <= 0 || !accounting_bank_reconciliation_ready()) {
        return null;
    }
    $sql = "SELECT br.*, a.account_code, a.account_name,
                   pu.display_name AS created_by_name,
                   cu.display_name AS completed_by_name
            FROM bank_reconciliation br
            INNER JOIN gl_account a ON a.account_id = br.account_id
            LEFT JOIN portal_user pu ON pu.user_id = br.created_by
            LEFT JOIN portal_user cu ON cu.user_id = br.completed_by
            WHERE br.reconciliation_id = ?
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$reconciliationId]);
    $row = $st->fetch();
    return $row ?: null;
}

function accounting_get_bank_reconciliation_by_statement(int $accountId, string $statementEndingDate): ?array {
    if ($accountId <= 0 || trim($statementEndingDate) === '' || !accounting_bank_reconciliation_ready()) {
        return null;
    }
    $sql = "SELECT br.*, a.account_code, a.account_name,
                   pu.display_name AS created_by_name,
                   cu.display_name AS completed_by_name
            FROM bank_reconciliation br
            INNER JOIN gl_account a ON a.account_id = br.account_id
            LEFT JOIN portal_user pu ON pu.user_id = br.created_by
            LEFT JOIN portal_user cu ON cu.user_id = br.completed_by
            WHERE br.account_id = ? AND br.statement_ending_date = ?
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$accountId, $statementEndingDate]);
    $row = $st->fetch();
    return $row ?: null;
}

function accounting_previous_bank_reconciliation(int $accountId, string $statementEndingDate): ?array {
    if ($accountId <= 0 || trim($statementEndingDate) === '' || !accounting_bank_reconciliation_ready()) {
        return null;
    }
    $sql = "SELECT br.*, a.account_code, a.account_name
            FROM bank_reconciliation br
            INNER JOIN gl_account a ON a.account_id = br.account_id
            WHERE br.account_id = ? AND br.statement_ending_date < ?
            ORDER BY br.statement_ending_date DESC, br.reconciliation_id DESC
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$accountId, $statementEndingDate]);
    $row = $st->fetch();
    return $row ?: null;
}

function accounting_list_recent_bank_reconciliations(?int $accountId = null, int $limit = 8): array {
    if (!accounting_bank_reconciliation_ready()) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $sql = "SELECT br.*, a.account_code, a.account_name,
                   pu.display_name AS created_by_name,
                   cu.display_name AS completed_by_name
            FROM bank_reconciliation br
            INNER JOIN gl_account a ON a.account_id = br.account_id
            LEFT JOIN portal_user pu ON pu.user_id = br.created_by
            LEFT JOIN portal_user cu ON cu.user_id = br.completed_by";
    $params = [];
    if ($accountId !== null && $accountId > 0) {
        $sql .= ' WHERE br.account_id = ?';
        $params[] = $accountId;
    }
    $sql .= " ORDER BY br.statement_ending_date DESC, br.reconciliation_id DESC LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_bank_ledger_balance(int $accountId, string $statementEndingDate): float {
    if ($accountId <= 0 || trim($statementEndingDate) === '') {
        return 0.0;
    }
    $sql = "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0)
            FROM gl_journal_line l
            INNER JOIN gl_journal j ON j.journal_id = l.journal_id
            WHERE l.account_id = ?
              AND j.status = 'POSTED'
              AND j.journal_date <= ?";
    $st = db()->prepare($sql);
    $st->execute([$accountId, $statementEndingDate]);
    return round((float)$st->fetchColumn(), 2);
}

function accounting_bank_reconciliation_prior_cleared_balance(int $accountId, string $statementEndingDate, ?int $excludeReconciliationId = null): float {
    if ($accountId <= 0 || trim($statementEndingDate) === '' || !accounting_bank_reconciliation_ready()) {
        return 0.0;
    }
    $sql = "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0)
            FROM bank_reconciliation_item bri
            INNER JOIN bank_reconciliation br ON br.reconciliation_id = bri.reconciliation_id
            INNER JOIN gl_journal_line l ON l.journal_line_id = bri.journal_line_id
            INNER JOIN gl_journal j ON j.journal_id = l.journal_id
            WHERE br.account_id = ?
              AND br.statement_ending_date < ?
              AND j.status = 'POSTED'";
    $params = [$accountId, $statementEndingDate];
    if ($excludeReconciliationId !== null && $excludeReconciliationId > 0) {
        $sql .= ' AND br.reconciliation_id <> ?';
        $params[] = $excludeReconciliationId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return round((float)$st->fetchColumn(), 2);
}

function accounting_bank_reconciliation_selected_total(int $reconciliationId): float {
    if ($reconciliationId <= 0 || !accounting_bank_reconciliation_ready()) {
        return 0.0;
    }
    $sql = "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0)
            FROM bank_reconciliation_item bri
            INNER JOIN gl_journal_line l ON l.journal_line_id = bri.journal_line_id
            INNER JOIN gl_journal j ON j.journal_id = l.journal_id
            WHERE bri.reconciliation_id = ?
              AND j.status = 'POSTED'";
    $st = db()->prepare($sql);
    $st->execute([$reconciliationId]);
    return round((float)$st->fetchColumn(), 2);
}

function accounting_bank_reconciliation_activity(int $accountId, string $statementEndingDate, ?int $reconciliationId = null): array {
    if ($accountId <= 0 || trim($statementEndingDate) === '') {
        return [];
    }
    $sql = "SELECT l.journal_line_id, l.journal_id, j.journal_date, j.source_type, j.source_id, j.reference_number, j.memo AS journal_memo,
                   l.line_number, l.debit_amount, l.credit_amount, (l.debit_amount - l.credit_amount) AS signed_amount,
                   l.line_memo, l.client_id, l.vendor_id,
                   c.client_code, c.legal_name, c.dba_name,
                   v.vendor_name,
                   bri.reconciliation_id AS selected_reconciliation_id,
                   br.statement_ending_date AS selected_statement_date
            FROM gl_journal_line l
            INNER JOIN gl_journal j ON j.journal_id = l.journal_id
            LEFT JOIN clients c ON c.client_id = l.client_id
            LEFT JOIN vendor v ON v.vendor_id = l.vendor_id
            LEFT JOIN bank_reconciliation_item bri ON bri.journal_line_id = l.journal_line_id
            LEFT JOIN bank_reconciliation br ON br.reconciliation_id = bri.reconciliation_id
            WHERE l.account_id = ?
              AND j.status = 'POSTED'
              AND j.journal_date <= ?";
    $params = [$accountId, $statementEndingDate];
    if ($reconciliationId !== null && $reconciliationId > 0) {
        $sql .= ' AND (bri.reconciliation_id IS NULL OR bri.reconciliation_id = ?)';
        $params[] = $reconciliationId;
    } else {
        $sql .= ' AND bri.reconciliation_id IS NULL';
    }
    $sql .= ' ORDER BY j.journal_date ASC, l.journal_id ASC, l.line_number ASC, l.journal_line_id ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_bank_reconciliation_summary(int $accountId, string $statementEndingDate, float $statementEndingBalance = 0.0, ?int $reconciliationId = null): array {
    $ledgerBalance = accounting_bank_ledger_balance($accountId, $statementEndingDate);
    $priorCleared = accounting_bank_reconciliation_prior_cleared_balance($accountId, $statementEndingDate, $reconciliationId);
    $selectedTotal = $reconciliationId ? accounting_bank_reconciliation_selected_total($reconciliationId) : 0.0;
    $clearedBalance = round($priorCleared + $selectedTotal, 2);
    $difference = round($statementEndingBalance - $clearedBalance, 2);
    $outstandingBalance = round($ledgerBalance - $clearedBalance, 2);

    $items = accounting_bank_reconciliation_activity($accountId, $statementEndingDate, $reconciliationId);
    $selectedCount = 0;
    $outstandingCount = 0;
    foreach ($items as $item) {
        $isSelected = ($reconciliationId !== null && $reconciliationId > 0 && (int)($item['selected_reconciliation_id'] ?? 0) === $reconciliationId);
        if ($isSelected) {
            $selectedCount++;
        } else {
            $outstandingCount++;
        }
    }

    return [
        'ledger_balance' => $ledgerBalance,
        'prior_cleared_balance' => $priorCleared,
        'selected_total' => $selectedTotal,
        'cleared_balance' => $clearedBalance,
        'difference' => $difference,
        'outstanding_balance' => $outstandingBalance,
        'selected_count' => $selectedCount,
        'outstanding_count' => $outstandingCount,
    ];
}

function accounting_bank_reconciliation_source_label(array $row): string {
    $sourceType = strtoupper(trim((string)($row['source_type'] ?? 'MANUAL')));
    $sourceId = (int)($row['source_id'] ?? 0);
    $reference = trim((string)($row['reference_number'] ?? ''));
    $prefix = match ($sourceType) {
        'PAYMENT' => 'Payment',
        'INVOICE' => 'Invoice',
        'EXPENSE' => 'Bill',
        'ADJUSTMENT' => 'Adjustment',
        'MANUAL' => 'Manual',
        default => $sourceType,
    };
    $label = $sourceId > 0 ? $prefix . ' #' . $sourceId : $prefix;
    if ($reference !== '') {
        $label .= ' · ' . $reference;
    }
    return $label;
}

function accounting_bank_reconciliation_source_href(array $row): ?string {
    $sourceType = strtoupper(trim((string)($row['source_type'] ?? '')));
    $sourceId = (int)($row['source_id'] ?? 0);
    if ($sourceId <= 0) {
        return null;
    }
    return match ($sourceType) {
        'PAYMENT' => BASE_URL . '/payments/view.php?id=' . $sourceId,
        'INVOICE' => BASE_URL . '/accounting/invoice_view.php?id=' . $sourceId,
        'EXPENSE' => BASE_URL . '/accounting/bills.php',
        default => null,
    };
}

function accounting_save_bank_reconciliation(int $accountId, string $statementEndingDate, float $statementEndingBalance, array $journalLineIds, int $userId, string $notes = '', bool $complete = false): array {
    if (!accounting_bank_reconciliation_ready()) {
        return ['ok' => false, 'errors' => ['Bank reconciliation tables are not installed yet. Run the SQL patch first.']];
    }
    if ($accountId <= 0) {
        return ['ok' => false, 'errors' => ['Choose a bank account.']];
    }
    if (trim($statementEndingDate) === '') {
        return ['ok' => false, 'errors' => ['Statement ending date is required.']];
    }

    $account = accounting_get_account($accountId);
    if (!$account || strtoupper((string)($account['account_type'] ?? '')) !== 'ASSET') {
        return ['ok' => false, 'errors' => ['Choose a valid asset account for reconciliation.']];
    }

    $statementEndingBalance = round($statementEndingBalance, 2);
    $notes = trim($notes);
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $journalLineIds), static fn($id) => $id > 0)));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = accounting_get_bank_reconciliation_by_statement($accountId, $statementEndingDate);
        if ($existing) {
            $reconciliationId = (int)$existing['reconciliation_id'];
            $st = $pdo->prepare('UPDATE bank_reconciliation SET statement_ending_balance = ?, notes = ?, status = ?, completed_by = NULL, completed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE reconciliation_id = ?');
            $st->execute([$statementEndingBalance, $notes !== '' ? $notes : null, 'OPEN', $reconciliationId]);
        } else {
            $st = $pdo->prepare("INSERT INTO bank_reconciliation (account_id, statement_ending_date, statement_ending_balance, status, notes, created_by) VALUES (?, ?, ?, 'OPEN', ?, ?)");
            $st->execute([$accountId, $statementEndingDate, $statementEndingBalance, $notes !== '' ? $notes : null, $userId > 0 ? $userId : null]);
            $reconciliationId = (int)$pdo->lastInsertId();
        }

        $allowedRows = accounting_bank_reconciliation_activity($accountId, $statementEndingDate, $reconciliationId);
        $allowedIds = [];
        foreach ($allowedRows as $row) {
            $allowedIds[(int)$row['journal_line_id']] = true;
        }
        $selectedIds = array_values(array_filter($selectedIds, static fn($id) => isset($allowedIds[$id])));

        $currentIds = $pdo->prepare('SELECT journal_line_id FROM bank_reconciliation_item WHERE reconciliation_id = ?');
        $currentIds->execute([$reconciliationId]);
        $existingIds = array_map('intval', array_column($currentIds->fetchAll(), 'journal_line_id'));
        $existingMap = array_fill_keys($existingIds, true);
        $selectedMap = array_fill_keys($selectedIds, true);

        $insert = $pdo->prepare('INSERT INTO bank_reconciliation_item (reconciliation_id, journal_line_id) VALUES (?, ?)');
        foreach ($selectedIds as $journalLineId) {
            if (!isset($existingMap[$journalLineId])) {
                $insert->execute([$reconciliationId, $journalLineId]);
            }
        }

        if ($existingIds) {
            $removeIds = [];
            foreach ($existingIds as $journalLineId) {
                if (!isset($selectedMap[$journalLineId])) {
                    $removeIds[] = $journalLineId;
                }
            }
            if ($removeIds) {
                $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
                $params = array_merge([$reconciliationId], $removeIds);
                $sql = 'DELETE FROM bank_reconciliation_item WHERE reconciliation_id = ? AND journal_line_id IN (' . $placeholders . ')';
                $st = $pdo->prepare($sql);
                $st->execute($params);
            }
        }

        $summary = accounting_bank_reconciliation_summary($accountId, $statementEndingDate, $statementEndingBalance, $reconciliationId);
        $completed = false;
        $warning = null;
        if ($complete) {
            if (abs((float)$summary['difference']) <= 0.009) {
                $st = $pdo->prepare("UPDATE bank_reconciliation SET status = 'COMPLETED', completed_by = ?, completed_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE reconciliation_id = ?");
                $st->execute([$userId > 0 ? $userId : null, $reconciliationId]);
                $completed = true;
            } else {
                $warning = 'Reconciliation saved, but the difference must be $0.00 before it can be completed.';
            }
        }

        $pdo->commit();
        return [
            'ok' => true,
            'reconciliation_id' => $reconciliationId,
            'completed' => $completed,
            'warning' => $warning,
            'summary' => $summary,
            'message' => $completed ? 'Reconciliation completed.' : 'Reconciliation saved.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['Failed to save reconciliation: ' . $e->getMessage()]];
    }
}

function accounting_catalog_item_exists(): bool {
     return db_table_exists('service_item');
 }
 
 function accounting_list_catalog_items(?string $type = null, bool $activeOnly = false, ?string $billingMode = null, ?int $categoryId = null, ?array $categoryIds = null): array {
     if (!accounting_catalog_item_exists()) return [];
     $params = [];
     $selectCategory = accounting_service_category_ready() ? ', sc.category_code, sc.category_name, sc.category_type, sc.sort_order AS category_sort_order, sc.is_bundle_eligible, sc.is_addon_eligible, sc.is_bundle_base, sc.is_recurring_eligible' : '';
     $joinCategory = accounting_service_category_ready() ? ' LEFT JOIN service_category sc ON sc.category_id = s.category_id' : '';
     $sql = "SELECT s.*, a.account_code, a.account_name,
                    (SELECT COUNT(*) FROM recurring_service rs WHERE rs.item_id = s.item_id AND rs.active = 1) AS recurring_count
                    {$selectCategory}
             FROM service_item s
             LEFT JOIN gl_account a ON a.account_id = s.revenue_account_id
             {$joinCategory}
             WHERE 1 = 1";
     if ($type !== null && in_array($type, accounting_get_catalog_item_types(), true)) {
         $sql .= ' AND s.item_type = ?';
         $params[] = $type;
     }
     if ($activeOnly) {
         $sql .= ' AND s.is_active = 1';
     }
     if ($billingMode !== null && accounting_catalog_supports_billing_mode() && in_array($billingMode, accounting_get_catalog_billing_modes(), true)) {
         $sql .= ' AND s.billing_mode = ?';
         $params[] = $billingMode;
     }
     if (accounting_service_category_ready()) {
         if ($categoryId !== null && $categoryId > 0) {
             $sql .= ' AND s.category_id = ?';
             $params[] = $categoryId;
         }
         if ($categoryIds !== null) {
             $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
             if ($categoryIds) {
                 $sql .= ' AND s.category_id IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ')';
                 $params = array_merge($params, $categoryIds);
             } else {
                 $sql .= ' AND 1 = 0';
             }
         }
     }
     $sql .= accounting_service_category_ready() ? ' ORDER BY COALESCE(sc.sort_order, 9999), sc.category_name, s.item_type, s.item_name' : ' ORDER BY s.item_type, s.item_name';
     $st = db()->prepare($sql);
     $st->execute($params);
     return $st->fetchAll();
 }
 
 function accounting_get_catalog_item(int $itemId): ?array {
     if (!accounting_catalog_item_exists()) return null;
     $sql = accounting_service_category_ready()
         ? 'SELECT s.*, sc.category_code, sc.category_name, sc.category_type, sc.is_bundle_eligible, sc.is_addon_eligible, sc.is_bundle_base, sc.is_recurring_eligible FROM service_item s LEFT JOIN service_category sc ON sc.category_id = s.category_id WHERE s.item_id = ? LIMIT 1'
         : 'SELECT * FROM service_item WHERE item_id = ? LIMIT 1';
     $st = db()->prepare($sql);
     $st->execute([$itemId]);
     $row = $st->fetch();
     return $row ?: null;
 }
 
 function accounting_save_catalog_item(array $data, ?int $itemId = null): array {
     if (!accounting_catalog_item_exists()) {
         return ['ok' => false, 'errors' => ['Catalog tables are not installed yet. Run the products/services SQL migration first.']];
     }
     $itemCode = trim((string)($data['item_code'] ?? ''));
     $itemName = trim((string)($data['item_name'] ?? ''));
     $itemType = strtoupper(trim((string)($data['item_type'] ?? 'SERVICE')));
     $description = trim((string)($data['description'] ?? ''));
     $defaultUnitPrice = round((float)($data['default_unit_price'] ?? 0), 2);
     $billingMode = strtoupper(trim((string)($data['billing_mode'] ?? 'RECURRING')));
     $billingCycle = strtoupper(trim((string)($data['default_billing_cycle'] ?? 'MONTHLY')));
     $termMonths = (int)($data['term_months'] ?? 0);
     $revenueAccountId = (int)($data['revenue_account_id'] ?? 0);
     $categoryId = accounting_service_category_ready() ? (int)($data['category_id'] ?? 0) : 0;
     $isTaxable = !empty($data['is_taxable']) ? 1 : 0;
     $isActive = !empty($data['is_active']) ? 1 : 0;
 
     $errors = [];
     if ($itemName === '') $errors[] = 'Item name is required.';
     if (!in_array($itemType, accounting_get_catalog_item_types(), true)) $errors[] = 'Choose a valid item type.';
     if (accounting_catalog_supports_billing_mode() && !in_array($billingMode, accounting_get_catalog_billing_modes(), true)) $errors[] = 'Choose a valid billing mode.';
     if ($billingMode !== 'ONE_TIME' && !in_array($billingCycle, accounting_get_recurring_cycles(), true)) $errors[] = 'Choose a valid default billing cycle.';
     if ($defaultUnitPrice < 0) $errors[] = 'Default unit price cannot be negative.';
     if ($revenueAccountId <= 0) $errors[] = 'Choose a revenue account.';
     if (accounting_service_category_ready() && $categoryId <= 0) $errors[] = 'Choose a service category.';
     if ($billingMode === 'ONE_TIME') {
         $billingCycle = null;
         $termMonths = 0;
     }
     if ($errors) return ['ok' => false, 'errors' => $errors];
 
     $pdo = db();
     $sql = 'SELECT item_id FROM service_item WHERE (item_name = ?' . ($itemCode !== '' ? ' OR item_code = ?' : '') . ')' . ($itemId ? ' AND item_id <> ?' : '') . ' LIMIT 1';
     $params = [$itemName];
     if ($itemCode !== '') $params[] = $itemCode;
     if ($itemId) $params[] = $itemId;
     $dup = $pdo->prepare($sql);
     $dup->execute($params);
     if ($dup->fetch()) {
         return ['ok' => false, 'errors' => ['Item name or code already exists.']];
     }
 
     if ($itemId) {
         if (accounting_catalog_supports_billing_mode()) {
             $st = $pdo->prepare('UPDATE service_item SET item_code = ?, item_name = ?, item_type = ?, description = ?, default_unit_price = ?, billing_mode = ?, default_billing_cycle = ?, term_months = ?, revenue_account_id = ?, category_id = ?, is_taxable = ?, is_active = ? WHERE item_id = ?');
             $st->execute([$itemCode !== '' ? $itemCode : null, $itemName, $itemType, $description !== '' ? $description : null, $defaultUnitPrice, $billingMode, $billingCycle, $termMonths > 0 ? $termMonths : null, $revenueAccountId, $categoryId > 0 ? $categoryId : null, $isTaxable, $isActive, $itemId]);
         } else {
             $st = $pdo->prepare('UPDATE service_item SET item_code = ?, item_name = ?, item_type = ?, description = ?, default_unit_price = ?, default_billing_cycle = ?, term_months = ?, revenue_account_id = ?, category_id = ?, is_taxable = ?, is_active = ? WHERE item_id = ?');
             $st->execute([$itemCode !== '' ? $itemCode : null, $itemName, $itemType, $description !== '' ? $description : null, $defaultUnitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $revenueAccountId, $categoryId > 0 ? $categoryId : null, $isTaxable, $isActive, $itemId]);
         }
         return ['ok' => true, 'item_id' => $itemId, 'message' => 'Catalog item updated.'];
     }
     if (accounting_catalog_supports_billing_mode()) {
         $st = $pdo->prepare('INSERT INTO service_item (item_code, item_name, item_type, description, default_unit_price, billing_mode, default_billing_cycle, term_months, revenue_account_id, category_id, is_taxable, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
         $st->execute([$itemCode !== '' ? $itemCode : null, $itemName, $itemType, $description !== '' ? $description : null, $defaultUnitPrice, $billingMode, $billingCycle, $termMonths > 0 ? $termMonths : null, $revenueAccountId, $categoryId > 0 ? $categoryId : null, $isTaxable, $isActive]);
     } else {
         $st = $pdo->prepare('INSERT INTO service_item (item_code, item_name, item_type, description, default_unit_price, default_billing_cycle, term_months, revenue_account_id, category_id, is_taxable, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
         $st->execute([$itemCode !== '' ? $itemCode : null, $itemName, $itemType, $description !== '' ? $description : null, $defaultUnitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $revenueAccountId, $categoryId > 0 ? $categoryId : null, $isTaxable, $isActive]);
     }
     return ['ok' => true, 'item_id' => (int)$pdo->lastInsertId(), 'message' => 'Catalog item created.'];
 }
 
 function accounting_recurring_ready(): bool {
     return db_table_exists('recurring_service');
 }
 
 function accounting_recurring_summary(): array {
     if (!accounting_recurring_ready()) return ['active_count' => 0, 'due_today_count' => 0, 'monthly_value' => 0.0, 'annual_value' => 0.0];
     $pdo = db();
     return [
         'active_count' => (int)$pdo->query('SELECT COUNT(*) FROM recurring_service WHERE active = 1')->fetchColumn(),
         'due_today_count' => (int)$pdo->query('SELECT COUNT(*) FROM recurring_service WHERE active = 1 AND next_bill_date <= CURDATE()')->fetchColumn(),
         'monthly_value' => (float)$pdo->query("SELECT COALESCE(SUM(quantity * unit_price),0) FROM recurring_service WHERE active = 1 AND billing_cycle = 'MONTHLY'")->fetchColumn(),
         'annual_value' => (float)$pdo->query("SELECT COALESCE(SUM(quantity * unit_price),0) FROM recurring_service WHERE active = 1 AND billing_cycle = 'ANNUAL'")->fetchColumn(),
     ];
 }
 
 function accounting_list_recurring_items(int $limit = 200): array {
     if (!accounting_recurring_ready()) return [];
     $limit = max(1, min(500, $limit));
     $sql = "SELECT rs.*, c.legal_name, c.dba_name,
                    si.item_name, si.item_code, si.item_type, si.default_billing_cycle,
                    ctr.contract_name, ctr.contract_number
             FROM recurring_service rs
             INNER JOIN clients c ON c.client_id = rs.client_id
             LEFT JOIN service_item si ON si.item_id = rs.item_id
             LEFT JOIN contract ctr ON ctr.contract_id = rs.contract_id
             ORDER BY rs.next_bill_date ASC, c.legal_name ASC, rs.recurring_service_id DESC
             LIMIT {$limit}";
     return db()->query($sql)->fetchAll();
 }
 
 function accounting_create_recurring_item(array $data): array {
     if (!accounting_recurring_ready()) return ['ok' => false, 'errors' => ['Recurring tables are not installed yet.']];
     $errors = [];
     $clientId = (int)($data['client_id'] ?? 0);
     $contractId = (int)($data['contract_id'] ?? 0);
     $itemId = (int)($data['item_id'] ?? 0);
     $description = trim((string)($data['description'] ?? ''));
     $itemType = strtoupper(trim((string)($data['item_type'] ?? 'SERVICE')));
     $billingType = strtoupper(trim((string)($data['billing_type'] ?? 'FIXED')));
     $billingCycle = strtoupper(trim((string)($data['billing_cycle'] ?? 'MONTHLY')));
     $quantity = (float)($data['quantity'] ?? 1);
     $unitPrice = (float)($data['unit_price'] ?? 0);
     $termMonths = (int)($data['term_months'] ?? 0);
     $nextBillDate = trim((string)($data['next_bill_date'] ?? ''));
     $startDate = trim((string)($data['start_date'] ?? ''));
     $endDate = trim((string)($data['end_date'] ?? ''));
     $autoRenew = !empty($data['auto_renew']) ? 1 : 0;
     $taxable = !empty($data['taxable']) ? 1 : 0;
     $notes = trim((string)($data['notes'] ?? ''));
 
     $item = $itemId > 0 ? accounting_get_catalog_item($itemId) : null;
     if ($item) {
         if (!empty($item['billing_mode']) && strtoupper((string)$item['billing_mode']) === 'ONE_TIME') $errors[] = 'One-time catalog items cannot be assigned to recurring billing.';
         if ($description === '') $description = (string)$item['item_name'];
         if ($billingCycle === '' || !in_array($billingCycle, accounting_get_recurring_cycles(), true)) $billingCycle = (string)$item['default_billing_cycle'];
         if ((float)$unitPrice === 0.0) $unitPrice = (float)$item['default_unit_price'];
         if ($termMonths <= 0 && !empty($item['term_months'])) $termMonths = (int)$item['term_months'];
         if ($itemType === '') $itemType = (string)$item['item_type'];
     }
 
     if ($nextBillDate === '') $nextBillDate = date('Y-m-d');
     if ($startDate === '') $startDate = $nextBillDate;
     $errors = [];
     if ($clientId <= 0) $errors[] = 'Choose a client.';
     if ($description === '') $errors[] = 'Description is required.';
     if (!in_array($itemType, accounting_get_catalog_item_types(), true)) $errors[] = 'Choose a valid item type.';
     if (!in_array($billingCycle, accounting_get_recurring_cycles(), true)) $errors[] = 'Choose a valid billing cycle.';
     if ($quantity <= 0) $errors[] = 'Covered users must be greater than zero.';
     if ($unitPrice < 0) $errors[] = 'Unit price cannot be negative.';
     if ($errors) return ['ok' => false, 'errors' => $errors];
 
     $pdo = db();
     $st = $pdo->prepare('INSERT INTO recurring_service (client_id, contract_id, contract_service_id, item_id, item_type, description, billing_type, billing_cycle, quantity, unit_price, taxable, next_bill_date, last_billed_date, active, notes, term_months, auto_renew, start_date, end_date) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?, ?, ?, ?, ?)');
     $st->execute([
         $clientId,
         $contractId > 0 ? $contractId : null,
         $itemId > 0 ? $itemId : null,
         $itemType,
         $description,
         $billingType !== '' ? $billingType : 'FIXED',
         $billingCycle,
         $quantity,
         $unitPrice,
         $taxable,
         $nextBillDate,
         $notes !== '' ? $notes : null,
         $termMonths > 0 ? $termMonths : null,
         $autoRenew,
         $startDate !== '' ? $startDate : null,
         $endDate !== '' ? $endDate : null,
     ]);
     return ['ok' => true, 'recurring_service_id' => (int)$pdo->lastInsertId(), 'message' => 'Recurring item created.'];
 }
 
 function accounting_add_billing_interval(string $currentDate, string $billingCycle): string {
     return match ($billingCycle) {
         'QUARTERLY' => date('Y-m-d', strtotime($currentDate . ' +3 months')),
         'SEMIANNUAL' => date('Y-m-d', strtotime($currentDate . ' +6 months')),
         'ANNUAL' => date('Y-m-d', strtotime($currentDate . ' +1 year')),
         default => date('Y-m-d', strtotime($currentDate . ' +1 month')),
     };
 }
 
function accounting_recurring_invoice_group_key(array $row, string $invoiceDate): string {
    return implode(':', [
        (string)((int)($row['client_id'] ?? 0)),
        (string)((int)($row['contract_id'] ?? 0)),
        strtoupper(trim((string)($row['billing_cycle'] ?? 'MONTHLY'))),
        $invoiceDate,
    ]);
}

function accounting_find_existing_recurring_invoice(PDO $pdo, string $groupKey): ?array {
    $st = $pdo->prepare("SELECT invoice_id, invoice_number, status
                        FROM customer_invoice
                        WHERE source_system = 'RECURRING_BATCH'
                          AND source_record_id = ?
                          AND status <> 'VOID'
                        ORDER BY invoice_id DESC
                        LIMIT 1");
    $st->execute([$groupKey]);
    $row = $st->fetch();
    return $row ?: null;
}

function accounting_generate_recurring_invoices(?string $asOfDate = null, int $userId = 0): array {
    if (!accounting_recurring_ready()) return ['ok' => false, 'created' => [], 'skipped' => [], 'errors' => ['Recurring tables are not installed yet.'], 'as_of_date' => $asOfDate ?: date('Y-m-d')];
    $asOfDate = $asOfDate ?: date('Y-m-d');
    $pdo = db();
    $sql = "SELECT rs.*, c.legal_name, c.dba_name, ct.contract_number, ct.contract_name,
                   si.item_name, si.item_code, si.item_type AS catalog_item_type, si.revenue_account_id,
                   cs.service_code, cs.service_name, cs.description AS contract_service_description,
                   cs.is_included, cs.sort_order
            FROM recurring_service rs
            INNER JOIN clients c ON c.client_id = rs.client_id
            LEFT JOIN contract ct ON ct.contract_id = rs.contract_id
            LEFT JOIN service_item si ON si.item_id = rs.item_id
            LEFT JOIN contract_service cs ON cs.contract_service_id = rs.contract_service_id
            WHERE rs.active = 1 AND rs.next_bill_date <= ?
            ORDER BY rs.client_id, rs.contract_id, rs.next_bill_date, COALESCE(cs.sort_order, 9999), rs.recurring_service_id";
    $st = $pdo->prepare($sql);
    $st->execute([$asOfDate]);
    $rows = $st->fetchAll();

    $groups = [];
    foreach ($rows as $row) {
        $groupKey = accounting_recurring_invoice_group_key($row, $asOfDate);
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'group_key' => $groupKey,
                'client_id' => (int)$row['client_id'],
                'contract_id' => (int)($row['contract_id'] ?? 0),
                'invoice_date' => $asOfDate,
                'billing_cycle' => strtoupper(trim((string)($row['billing_cycle'] ?? 'MONTHLY'))),
                'contract_number' => (string)($row['contract_number'] ?? ''),
                'contract_name' => (string)($row['contract_name'] ?? ''),
                'client_name' => (string)($row['dba_name'] ?: $row['legal_name'] ?: 'Client'),
                'rows' => [],
            ];
        }
        $groups[$groupKey]['rows'][] = $row;
    }

    $created = [];
    $skipped = [];
    $errors = [];
    $defaultRevenueAccountId = accounting_find_account_id_by_code('4000') ?? 0;
    $arAccountId = accounting_find_account_id_by_code('1100') ?? 0;

    foreach ($groups as $group) {
        try {
            $existing = accounting_find_existing_recurring_invoice($pdo, (string)$group['group_key']);
            if ($existing) {
                $skipped[] = [
                    'group_key' => (string)$group['group_key'],
                    'invoice_id' => (int)$existing['invoice_id'],
                    'invoice_number' => (string)$existing['invoice_number'],
                    'client_id' => (int)$group['client_id'],
                    'contract_id' => (int)$group['contract_id'],
                ];
                continue;
            }

            $lineDescriptions = [];
            $lineItemIds = [];
            $lineServiceCodes = [];
            $lineQuantities = [];
            $lineUnitPrices = [];
            $lineRevenueAccounts = [];
            $generatedFrom = [];

            foreach ($group['rows'] as $row) {
                $desc = trim((string)($row['description'] ?: $row['service_name'] ?: $row['contract_service_description'] ?: $row['item_name'] ?: 'Recurring item'));
                $lineDescriptions[] = $desc;
                $lineItemIds[] = (int)($row['item_id'] ?? 0);
                $lineServiceCodes[] = (string)($row['service_code'] ?: $row['item_code'] ?: '');
                $lineQuantities[] = (float)$row['quantity'];
                $lineUnitPrices[] = (float)$row['unit_price'];
                $lineRevenueAccounts[] = !empty($row['revenue_account_id']) ? (int)$row['revenue_account_id'] : $defaultRevenueAccountId;
                $generatedFrom[] = '#' . (int)$row['recurring_service_id'];
            }

            $memoParts = [];
            if (!empty($group['contract_number'])) {
                $memoParts[] = 'Generated from recurring billing for ' . (string)$group['contract_number'];
            } elseif (!empty($group['contract_name'])) {
                $memoParts[] = 'Generated from recurring billing for ' . (string)$group['contract_name'];
            } else {
                $memoParts[] = 'Generated from recurring billing';
            }
            $memoParts[] = 'Items: ' . implode(', ', $generatedFrom);

            $result = accounting_create_invoice([
                'client_id' => (int)$group['client_id'],
                'contract_id' => (int)$group['contract_id'],
                'invoice_date' => (string)$group['invoice_date'],
                'due_date' => date('Y-m-d', strtotime((string)$group['invoice_date'] . ' +15 days')),
                'status' => 'DRAFT',
                'line_description' => $lineDescriptions,
                'line_item_id' => $lineItemIds,
                'line_service_code' => $lineServiceCodes,
                'line_quantity' => $lineQuantities,
                'line_unit_price' => $lineUnitPrices,
                'line_revenue_account_id' => $lineRevenueAccounts,
                'ar_account_id' => $arAccountId,
                'memo' => implode(' | ', $memoParts),
                'source_system' => 'RECURRING_BATCH',
                'source_record_id' => (string)$group['group_key'],
            ], $userId);
            if (empty($result['ok'])) {
                $errors[] = 'Recurring group ' . (string)$group['group_key'] . ': ' . implode('; ', $result['errors'] ?? ['Invoice generation failed.']);
                continue;
            }

            $upd = $pdo->prepare('UPDATE recurring_service SET last_billed_date = ?, next_bill_date = ?, updated_at = CURRENT_TIMESTAMP WHERE recurring_service_id = ?');
            foreach ($group['rows'] as $row) {
                $nextDate = accounting_add_billing_interval((string)$row['next_bill_date'], (string)$row['billing_cycle']);
                $upd->execute([$asOfDate, $nextDate, (int)$row['recurring_service_id']]);
            }

            $created[] = [
                'group_key' => (string)$group['group_key'],
                'client_id' => (int)$group['client_id'],
                'contract_id' => (int)$group['contract_id'],
                'invoice_id' => (int)$result['invoice_id'],
                'line_count' => count($group['rows']),
                'recurring_service_ids' => array_map(static fn(array $r): int => (int)$r['recurring_service_id'], $group['rows']),
            ];
        } catch (Throwable $e) {
            $errors[] = 'Recurring group ' . (string)$group['group_key'] . ': ' . $e->getMessage();
        }
    }

    return ['ok' => count($errors) === 0, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'as_of_date' => $asOfDate];
}
 
 function accounting_receivables_summary(): array {
    $pdo = db();
    return [
        'open_invoice_count' => (int)$pdo->query("SELECT COUNT(*) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0")->fetchColumn(),
        'overdue_invoice_count' => (int)$pdo->query("SELECT COUNT(*) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn(),
        'total_open_ar' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0")->fetchColumn(),
        'current_bucket' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND (due_date IS NULL OR due_date >= CURDATE())")->fetchColumn(),
        'bucket_1_30' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND due_date < CURDATE() AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn(),
        'bucket_31_60' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)")->fetchColumn(),
        'bucket_61_90' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetchColumn(),
        'bucket_90_plus' => (float)$pdo->query("SELECT COALESCE(SUM(balance_due),0) FROM customer_invoice WHERE status IN ('ISSUED','PARTIALLY_PAID') AND balance_due > 0 AND due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetchColumn(),
    ];
 }
 
 function accounting_receivables_aging(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $sql = "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.status, i.total_amount, i.balance_due,
                   c.client_id, c.legal_name, c.dba_name,
                   CASE
                     WHEN i.due_date IS NULL OR i.due_date >= CURDATE() THEN 'CURRENT'
                     WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN '1-30'
                     WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN '31-60'
                     WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN '61-90'
                     ELSE '90+'
                   END AS aging_bucket,
                   GREATEST(DATEDIFF(CURDATE(), i.due_date), 0) AS days_past_due
            FROM customer_invoice i
            INNER JOIN clients c ON c.client_id = i.client_id
            WHERE i.status IN ('ISSUED','PARTIALLY_PAID') AND i.balance_due > 0
            ORDER BY days_past_due DESC, i.due_date ASC, i.invoice_id DESC
            LIMIT {$limit}";
    return db()->query($sql)->fetchAll();
 }
 
 function accounting_receivable_client_balances(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $sql = "SELECT c.client_id, c.legal_name, c.dba_name,
                   COUNT(i.invoice_id) AS open_invoice_count,
                   COALESCE(SUM(i.balance_due),0) AS balance_due,
                   MIN(i.due_date) AS oldest_due_date,
                   MAX(CASE WHEN i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN 1 ELSE 0 END) AS has_overdue
            FROM customer_invoice i
            INNER JOIN clients c ON c.client_id = i.client_id
            WHERE i.status IN ('ISSUED','PARTIALLY_PAID') AND i.balance_due > 0
            GROUP BY c.client_id, c.legal_name, c.dba_name
            ORDER BY balance_due DESC, c.legal_name ASC
            LIMIT {$limit}";
    return db()->query($sql)->fetchAll();
 }
 
 function accounting_capital_history(int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $sql = "SELECT j.journal_id, j.journal_date, j.reference_number, j.memo, j.source_type,
                   debit.account_code AS debit_code, debit.account_name AS debit_name,
                   credit.account_code AS credit_code, credit.account_name AS credit_name,
                   dline.debit_amount AS amount,
                   CASE
                       WHEN credit.account_type = 'EQUITY' THEN 'CONTRIBUTION'
                       WHEN debit.account_type = 'EQUITY' THEN 'DRAW'
                       ELSE 'MANUAL'
                   END AS entry_kind
            FROM gl_journal j
            INNER JOIN gl_journal_line dline ON dline.journal_id = j.journal_id AND dline.debit_amount > 0
            INNER JOIN gl_account debit ON debit.account_id = dline.account_id
            INNER JOIN gl_journal_line cline ON cline.journal_id = j.journal_id AND cline.credit_amount > 0
            INNER JOIN gl_account credit ON credit.account_id = cline.account_id
            WHERE j.source_type = 'MANUAL' AND (credit.account_type = 'EQUITY' OR debit.account_type = 'EQUITY')
            ORDER BY j.journal_date DESC, j.journal_id DESC
            LIMIT {$limit}";
    return db()->query($sql)->fetchAll();
 }
 
 function accounting_record_capital_contribution(array $data, int $userId): array {
    $journalDate = trim((string)($data['journal_date'] ?? '')) ?: date('Y-m-d');
    $amount = round((float)($data['amount'] ?? 0), 2);
    $debitAccountId = (int)($data['debit_account_id'] ?? 0);
    $equityAccountId = (int)($data['equity_account_id'] ?? 0);
    $reference = trim((string)($data['reference_number'] ?? ''));
    $memo = trim((string)($data['memo'] ?? ''));
 
    $errors = [];
    if ($amount <= 0) $errors[] = 'Contribution amount must be greater than zero.';
    if ($debitAccountId <= 0) $errors[] = 'Choose the account receiving the funds or asset.';
    if ($equityAccountId <= 0) $errors[] = 'Choose an equity account.';
    if ($errors) return ['ok' => false, 'errors' => $errors];
 
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'MANUAL', NULL, ?, ?, ?)");
        $st->execute([$journalDate, $reference !== '' ? $reference : null, $memo !== '' ? $memo : 'Owner contribution', $userId]);
        $journalId = (int)$pdo->lastInsertId();
 
        $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?)');
        $line->execute([$journalId, 1, $debitAccountId, $amount, 0, 'Contribution asset/funding received']);
        $line->execute([$journalId, 2, $equityAccountId, 0, $amount, 'Owner capital / equity']);
 
        $pdo->commit();
        return ['ok' => true, 'journal_id' => $journalId, 'message' => 'Owner funding posted.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to post owner funding: ' . $e->getMessage()]];
    }
 }
 
 function accounting_record_owner_draw(array $data, int $userId): array {
    $journalDate = trim((string)($data['journal_date'] ?? '')) ?: date('Y-m-d');
    $amount = round((float)($data['amount'] ?? 0), 2);
    $assetAccountId = (int)($data['asset_account_id'] ?? 0);
    $equityAccountId = (int)($data['equity_account_id'] ?? 0);
    $reference = trim((string)($data['reference_number'] ?? ''));
    $memo = trim((string)($data['memo'] ?? ''));
 
    $errors = [];
    if ($amount <= 0) $errors[] = 'Owner draw amount must be greater than zero.';
    if ($assetAccountId <= 0) $errors[] = 'Choose the account the draw was paid from.';
    if ($equityAccountId <= 0) $errors[] = 'Choose an equity account for the draw.';
    if ($errors) return ['ok' => false, 'errors' => $errors];
 
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO gl_journal (journal_date, status, source_type, source_id, reference_number, memo, posted_by) VALUES (?, 'POSTED', 'MANUAL', NULL, ?, ?, ?)");
        $st->execute([$journalDate, $reference !== '' ? $reference : null, $memo !== '' ? $memo : 'Owner draw', $userId]);
        $journalId = (int)$pdo->lastInsertId();
 
        $line = $pdo->prepare('INSERT INTO gl_journal_line (journal_id, line_number, account_id, debit_amount, credit_amount, line_memo) VALUES (?, ?, ?, ?, ?, ?)');
        $line->execute([$journalId, 1, $equityAccountId, $amount, 0, 'Owner draw / equity reduction']);
        $line->execute([$journalId, 2, $assetAccountId, 0, $amount, 'Cash or asset paid out to owner']);
 
        $pdo->commit();
        return ['ok' => true, 'journal_id' => $journalId, 'message' => 'Owner draw posted.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to post owner draw: ' . $e->getMessage()]];
    }
 }

function accounting_mail_from_email(): string {
    return ops_mail_sender_profile('billing')['email'];
}

function accounting_mail_from_name(): string {
    return ops_mail_sender_profile('billing')['name'];
}

function accounting_mail_sandbox_enabled(): bool {
    return ops_mail_sandbox_enabled();
}

function accounting_mail_sandbox_to(): string {
    return ops_mail_sandbox_to();
}

function accounting_mail_effective_recipient(string $email): string {
    $email = trim($email);
    if (accounting_mail_sandbox_enabled()) {
        return accounting_mail_sandbox_to();
    }
    return $email;
}

function accounting_invoice_delivery_table_exists(): bool {
    return db_table_exists('invoice_delivery');
}

function accounting_invoice_delivery_log(int $invoiceId, int $limit = 10): array {
    if (!accounting_invoice_delivery_table_exists()) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $sql = "SELECT d.*, pu.display_name AS sent_by_name
            FROM invoice_delivery d
            LEFT JOIN portal_user pu ON pu.user_id = d.sent_by
            WHERE d.invoice_id = ?
            ORDER BY d.sent_at DESC, d.delivery_id DESC
            LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute([$invoiceId]);
    return $st->fetchAll();
}

function accounting_invoice_email_defaults(array $invoice): array {
    $clientName = trim((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'there'));
    $subject = 'Invoice ' . (string)$invoice['invoice_number'] . ' from Midwest Managed IT';
    $body = "Hi {$clientName},

";
    $body .= 'Your invoice ' . (string)$invoice['invoice_number'] . ' is ready.' . "

";
    $body .= 'Amount due: $' . number_format((float)$invoice['balance_due'], 2) . "
";
    $body .= 'Invoice date: ' . accounting_email_display_date((string)($invoice['invoice_date'] ?? '')) . "
";
    if (!empty($invoice['due_date'])) {
        $body .= 'Due date: ' . accounting_email_display_date((string)$invoice['due_date']) . "
";
    }
    $body .= "
Please use the secure payment button in this email to review and pay your invoice online.
";
    $body .= 'A PDF copy of the invoice is attached for your records.' . "

";
    $body .= 'If you have any questions, reply to this email or contact us at ' . accounting_mail_from_email() . '.' . "

";
    $body .= "Thank you,
Midwest Managed IT
";

    if (accounting_mail_sandbox_enabled()) {
        $subject = '[TEST] ' . $subject;
    }

    return [
        'to' => accounting_mail_effective_recipient((string)($invoice['client_email'] ?? '')),
        'subject' => $subject,
        'body' => $body,
    ];
}

function accounting_plain_to_html(string $text): string {
    $escaped = accounting_h($text);
    $escaped = preg_replace("/(\r\n|\r|\n){2,}/", "</p><p>", $escaped) ?? $escaped;
    $escaped = nl2br($escaped);
    return '<p>' . $escaped . '</p>';
}

function accounting_email_display_date(string $date): string {
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('m/d/Y', $ts) : $date;
}

function accounting_invoice_email_logo_url(): string {
    $candidates = [
        'assets/brand/mmit-favicon-512.png',
        'assets/brand/mmit-favicon-64.png',
        'assets/brand/mmit-logo-horizontal-light.png',
    ];
    foreach ($candidates as $candidate) {
        if (is_file(__DIR__ . '/../' . $candidate)) {
            return rtrim((string)BASE_URL, '/') . '/' . ltrim($candidate, '/');
        }
    }
    return '';
}

function accounting_render_invoice_email_html(array $invoice, string $messageBody, string $stripeUrl, bool $sandboxMode = false, string $originalTo = ''): string {
    $clientName = trim((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'there'));
    $invoiceNumber = trim((string)($invoice['invoice_number'] ?? 'Invoice'));
    $amountDue = '$' . number_format((float)($invoice['balance_due'] ?? 0), 2);
    $invoiceDate = accounting_email_display_date((string)($invoice['invoice_date'] ?? ''));
    $dueDate = accounting_email_display_date((string)($invoice['due_date'] ?? ''));
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    $logoUrl = accounting_invoice_email_logo_url();
    $safeLogoUrl = accounting_h($logoUrl);
    $bodyHtml = accounting_plain_to_html(trim($messageBody));
    $safeStripeUrl = accounting_h($stripeUrl);
    $safeOriginalTo = accounting_h($originalTo);
    $safeSandboxTo = accounting_h(accounting_mail_sandbox_to());

    $summaryRows = '<tr>'
        . '<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">Invoice #</td>'
        . '<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;font-weight:700;text-align:right;">' . accounting_h($invoiceNumber) . '</td>'
        . '</tr>'
        . '<tr>'
        . '<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">Invoice date</td>'
        . '<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;text-align:right;">' . accounting_h($invoiceDate !== '' ? $invoiceDate : '-') . '</td>'
        . '</tr>';
    if ($dueDate !== '') {
        $summaryRows .= '<tr>'
            . '<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;font-size:13px;">Due date</td>'
            . '<td style="padding:12px 0;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;text-align:right;">' . accounting_h($dueDate) . '</td>'
            . '</tr>';
    }
    $summaryRows .= '<tr>'
        . '<td style="padding:14px 0 0;color:#111827;font-size:14px;font-weight:700;">Amount due</td>'
        . '<td style="padding:14px 0 0;color:#10233f;font-size:24px;font-weight:800;text-align:right;">' . accounting_h($amountDue) . '</td>'
        . '</tr>';

    $sandboxBanner = '';
    if ($sandboxMode) {
        $sandboxBanner = '<tr><td style="padding:0 0 18px 0;">'
            . '<div style="background:#fff7ed;border:1px solid #fdba74;border-radius:12px;padding:14px 16px;color:#9a3412;font-size:13px;line-height:1.55;">'
            . '<strong style="display:block;margin-bottom:4px;">Test mode is active</strong>'
            . 'This invoice email is being redirected to <strong>' . $safeSandboxTo . '</strong> while sandbox mail mode is enabled.'
            . ($safeOriginalTo !== '' ? '<br>Original client email on invoice: <strong>' . $safeOriginalTo . '</strong>' : '')
            . '</div></td></tr>';
    }

    $buttonBlock = '';
    if ($stripeUrl !== '' && in_array($status, ['ISSUED', 'PARTIALLY_PAID'], true) && (float)($invoice['balance_due'] ?? 0) > 0.0) {
        $buttonBlock = '<tr><td style="padding:0 0 20px 0;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td style="border-radius:12px;background:#1d4ed8;">'
            . '<a href="' . $safeStripeUrl . '" style="display:inline-block;padding:14px 22px;font-family:Arial,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:12px;">View &amp; Pay Invoice</a>'
            . '</td></tr></table>'
            . '<div style="margin-top:12px;color:#6b7280;font-size:12px;line-height:1.5;">Prefer the full link? <a href="' . $safeStripeUrl . '" style="color:#1d4ed8;text-decoration:none;word-break:break-all;">' . $safeStripeUrl . '</a></div>'
            . '</td></tr>';
    }

    $logoBlock = '<div style="width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#2563eb 0%,#7c3aed 100%);display:inline-block;"></div>';
    if ($logoUrl !== '') {
        $logoBlock = '<img src="' . $safeLogoUrl . '" alt="Midwest Managed IT" width="56" height="56" style="display:block;width:56px;height:56px;border:0;outline:none;text-decoration:none;">';
    }

    return '<!doctype html>'
        . '<html><body style="margin:0;padding:0;background:#eef2ff;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef2ff;margin:0;padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:680px;background:#eef2ff;">'
        . $sandboxBanner
        . '<tr><td>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;border:1px solid #dbe3f0;border-radius:20px;overflow:hidden;">'
        . '<tr><td style="padding:24px 28px 18px 28px;border-bottom:1px solid #e5e7eb;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
        . '<td align="left" valign="top" style="width:72px;">' . $logoBlock . '</td>'
        . '<td align="left" valign="middle">'
        . '<div style="font-size:22px;line-height:1.2;font-weight:800;color:#10233f;">Midwest Managed IT</div>'
        . '<div style="margin-top:6px;font-size:13px;line-height:1.45;color:#6b7280;">Invoice delivery for ' . accounting_h($clientName) . '</div>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
        . '<tr><td style="padding:0 0 18px 0;font-size:16px;line-height:1.7;color:#111827;">' . $bodyHtml . '</td></tr>'
        . $buttonBlock
        . '<tr><td style="padding:0 0 20px 0;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;padding:0 18px;">'
        . $summaryRows
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:0 0 20px 0;">'
        . '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;padding:16px 18px;color:#334155;font-size:13px;line-height:1.65;">'
        . '<strong style="display:block;font-size:13px;color:#10233f;margin-bottom:6px;">Included with this email</strong>'
        . 'A PDF copy of the invoice is attached for your records. Customers paying through Stripe can also download the invoice and receipt from the secure payment page after payment.'
        . '</div>'
        . '</td></tr>'
        . '<tr><td style="font-size:12px;line-height:1.65;color:#64748b;padding-top:4px;">Questions? Reply to this email or contact <a href="mailto:billing@midwestmanagedit.com" style="color:#1d4ed8;text-decoration:none;">billing@midwestmanagedit.com</a>.</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '<div style="padding:14px 12px 0 12px;text-align:center;font-size:11px;line-height:1.6;color:#64748b;">Midwest Managed IT · Secure invoice delivery powered by your portal and Stripe-hosted payment pages.</div>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';
}

function accounting_render_invoice_pdf_bytes(array $invoice, array $lines, array $includedGroups = []): string {
    require_once __DIR__ . '/../vendor/autoload.php';

    if (ob_get_length()) {
        ob_end_clean();
    }

    $companyName = 'Midwest Managed IT';
    $companyLegalName = 'LnK Consulting, LLC dba Midwest Managed IT';
    $companyEmail = 'billing@midwestmanagedit.com';

    $clientName = trim((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'Client'));
    $invoiceNumber = (string)($invoice['invoice_number'] ?? ('INV-' . (int)($invoice['invoice_id'] ?? 0)));
    $invoiceDate = (string)($invoice['invoice_date'] ?? '');
    $dueDate = (string)($invoice['due_date'] ?? '');
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    $memo = trim((string)($invoice['memo'] ?? ''));
    $totalAmount = (float)($invoice['total_amount'] ?? 0);
    $balanceDue = (float)($invoice['balance_due'] ?? $totalAmount);
    $subtotalAmount = (float)($invoice['subtotal_amount'] ?? $totalAmount);
    $taxAmount = (float)($invoice['tax_amount'] ?? 0);
    $paymentsReceived = max(0.0, $totalAmount - $balanceDue);
    $paymentSnapshot = accounting_invoice_payment_snapshot($invoice);
    if ((float)($paymentSnapshot['payments_received'] ?? 0) > 0) {
        $paymentsReceived = max($paymentsReceived, (float)$paymentSnapshot['payments_received']);
    }
    $clientEmail = trim((string)($invoice['client_email'] ?? ''));
    $contractNumber = trim((string)($invoice['contract_number'] ?? ''));

    $billLines = [$clientName];
    $addr1 = trim((string)($invoice['client_address1'] ?? ''));
    $addr2 = trim((string)($invoice['client_address2'] ?? ''));
    $city = trim((string)($invoice['client_city'] ?? ''));
    $state = trim((string)($invoice['client_state'] ?? ''));
    $postal = trim((string)($invoice['client_postal_code'] ?? ''));
    $country = trim((string)($invoice['client_country'] ?? ''));

    if ($addr1 !== '') $billLines[] = $addr1;
    if ($addr2 !== '') $billLines[] = $addr2;
    $cityLine = trim(implode(', ', array_filter([$city, $state])));
    if ($postal !== '') {
        $cityLine = trim($cityLine . ' ' . $postal);
    }
    if ($cityLine !== '') $billLines[] = $cityLine;
    if ($country !== '' && strtoupper($country) !== 'US') $billLines[] = $country;
    if (count($billLines) === 1 && $clientEmail !== '') $billLines[] = $clientEmail;
    if ($contractNumber !== '') $billLines[] = 'Contract: ' . $contractNumber;

    $logoCandidates = [
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-light.jpg',
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-light.jpeg',
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-light.png',
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-dark.svg',
        __DIR__ . '/../assets/brand/mmit-logo-stacked-dark.svg',
        __DIR__ . '/../assets/brand/logo.jpg',
        __DIR__ . '/../assets/brand/logo.jpeg',
        __DIR__ . '/../assets/brand/logo.svg',
        __DIR__ . '/../assets/brand/logo.png',
    ];
    $logoHtml = '<div class="company-fallback">' . htmlspecialchars($companyName) . '</div>';
    foreach ($logoCandidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            $mime = 'image/png';
            if ($ext === 'svg') {
                $mime = 'image/svg+xml';
            } elseif ($ext === 'jpg' || $ext === 'jpeg') {
                $mime = 'image/jpeg';
            }
            $data = base64_encode((string)file_get_contents($candidate));
            $logoHtml = '<img src="data:' . $mime . ';base64,' . $data . '" style="max-width:300px; height:auto;">';
            break;
        }
    }

    $statusClass = 'draft';
    if ($status === 'PAID') {
        $statusClass = 'paid';
    } elseif ($status === 'ISSUED' || $status === 'PARTIALLY_PAID') {
        $statusClass = 'issued';
    }

    $lineRows = '';
    foreach ($lines as $item) {
        $desc = (string)($item['description'] ?? 'Invoice line');
        $code = trim((string)($item['service_code'] ?? ''));
        $revCode = trim((string)($item['revenue_account_code'] ?? ''));
        $revName = trim((string)($item['revenue_account_name'] ?? ''));
        $secondary = trim(implode('  |  ', array_filter([
            $code !== '' ? $code : null,
            ($revCode !== '' || $revName !== '') ? trim($revCode . ' - ' . $revName, ' -') : null,
        ])));
        $lineRows .= '<tr>'
            . '<td><div class="desc-main">' . htmlspecialchars($desc) . '</div>'
            . ($secondary !== '' ? '<div class="desc-sub">' . htmlspecialchars($secondary) . '</div>' : '')
            . '</td>'
            . '<td class="num">' . number_format((float)($item['quantity'] ?? 0), 0) . '</td>'
            . '<td class="num">$' . number_format((float)($item['unit_price'] ?? 0), 2) . '</td>'
            . '<td class="num total-col">$' . number_format((float)($item['line_total'] ?? 0), 2) . '</td>'
            . '</tr>';
    }
    if ($lineRows === '') {
        $lineRows = '<tr><td>No invoice lines</td><td class="num">0</td><td class="num">$0.00</td><td class="num total-col">$0.00</td></tr>';
    }

    $notesText = $memo !== '' ? nl2br(htmlspecialchars($memo)) : 'Any invoice questions, please email billing@midwestmanagedit.com. Thank you.';
    $paymentText = 'Online payment links will appear here after merchant setup is complete.';
    if (!empty($paymentSnapshot['is_paid'])) {
        $paymentText = (string)$paymentSnapshot['detail_text'];
    } elseif (!empty($paymentSnapshot['has_payments'])) {
        $paymentText = (string)$paymentSnapshot['detail_text'];
    } elseif (accounting_invoice_has_stripe_payment_page($invoice)) {
        $paymentText = 'Pay securely online through Stripe: ' . htmlspecialchars(accounting_invoice_stripe_payment_url($invoice));
    }

    $watermarkHtml = '';
    if (!empty($paymentSnapshot['show_paid_watermark'])) {
        $watermarkSub = '';
        if (!empty($paymentSnapshot['last_payment_date'])) {
            $safePaidDate = htmlspecialchars((string)$paymentSnapshot['last_payment_date']);
            $watermarkSub = '<div class="watermark-sub-shell"><span class="watermark-sub watermark-sub-outline">PAID ' . $safePaidDate . '</span><span class="watermark-sub watermark-sub-main">PAID ' . $safePaidDate . '</span></div>';
        }
        $watermarkHtml = '<div class="watermark-shell"><div class="watermark watermark-outline">PAID</div><div class="watermark watermark-main">PAID</div>' . $watermarkSub . '</div>';
    }

    $html = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 34px 38px 58px 38px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; color: #172033; font-size: 12px; padding-bottom: 64px; }
    .page-shell { position: relative; z-index: 1; }
    .watermark-shell { position: fixed; top: 36%; left: 2%; width: 96%; text-align: center; transform: rotate(-26deg); transform-origin: center center; z-index: 0; }
    .watermark { width: 100%; text-align: center; font-weight: 900; }
    .watermark-outline { font-size: 100px; letter-spacing: 10px; color: rgba(22, 163, 74, 0.08); }
    .watermark-main { position: absolute; top: 5px; left: 0; font-size: 92px; letter-spacing: 11px; color: rgba(22, 163, 74, 0.15); }
    .watermark-sub-shell { position: absolute; top: 96px; left: 0; width: 100%; text-align: center; }
    .watermark-sub { display: block; width: 100%; text-align: center; }
    .watermark-sub-outline { font-size: 22px; font-weight: 800; letter-spacing: 3px; color: rgba(22, 163, 74, 0.08); }
    .watermark-sub-main { position: absolute; top: 2px; left: 0; font-size: 19px; font-weight: 800; letter-spacing: 4px; color: rgba(22, 163, 74, 0.17); }
    .header { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .header td { vertical-align: top; }
    .logo-wrap { width: 52%; text-align: left; }
    .logo-block { display: inline-block; width: 320px; text-align: center; }
    .logo-image img { display: block; margin: 0 auto; max-width: 300px; height: auto; }
    .logo-legal { margin-top: 8px; text-align: left; padding-left: 10px; font-size: 9.5px; color: #334155; }
    .logo-email { margin-top: 4px; text-align: left; padding-left: 10px; font-size: 10px; color: #4b5563; }
    .company-fallback { font-size: 24px; font-weight: 700; color: #10233f; }
    .invoice-wrap { width: 48%; text-align: right; }
    .invoice-title { font-size: 28px; font-weight: 800; color: #10233f; letter-spacing: 0.8px; margin: 0; }
    .invoice-number { font-size: 14px; font-weight: 700; color: #10233f; text-align: right; margin-top: 8px; }
    .status-pill { display: inline-block; margin-top: 10px; padding: 6px 14px; border-radius: 999px; font-size: 10px; font-weight: 700; letter-spacing: 0.6px; }
    .status-pill.draft { background: #e5e7eb; color: #475569; }
    .status-pill.issued { background: #dbeafe; color: #0f766e; }
    .status-pill.paid { background: #dcfce7; color: #166534; }
    .meta { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 24px; }
    .meta-box, .billto-box { border: 1px solid #d9e2ec; border-radius: 12px; }
    .box-title { font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.6px; }
    .billto-box { width: 52%; padding: 16px; }
    .meta-box { width: 42%; padding: 16px; }
    .bill-line { margin-bottom: 3px; }
    .bill-line.name { font-weight: 700; color: #10233f; margin-bottom: 8px; }
    .meta-row { margin-bottom: 8px; }
    .meta-label { display: inline-block; width: 92px; color: #64748b; font-weight: 700; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .lines thead th { background: #10233f; color: #fff; padding: 10px 12px; font-size: 11px; text-align: left; }
    .lines thead th.num { text-align: right; }
    .lines tbody td { border-bottom: 1px solid #e5e7eb; padding: 11px 12px; vertical-align: top; }
    .lines tbody td.num { text-align: right; white-space: nowrap; }
    .desc-main { font-weight: 700; color: #172033; margin-bottom: 2px; }
    .desc-sub { font-size: 10px; color: #64748b; }
    .summary-wrap { width: 100%; margin-top: 18px; }
    .summary-table { width: 44%; margin-left: auto; border-collapse: collapse; }
    .summary-table td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; }
    .summary-table td:last-child { text-align: right; font-weight: 700; }
    .summary-table tr.total td { background: #f8fafc; font-size: 13px; color: #10233f; }
    .bottom { margin-top: 26px; width: 100%; border-collapse: separate; border-spacing: 0; }
    .bottom td { vertical-align: top; }
    .notes, .payment { border: 1px solid #d9e2ec; border-radius: 12px; padding: 14px 16px; }
    .notes { width: 48%; }
    .payment { width: 48%; }
    .footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 4px;
        text-align: center;
        font-size: 10px;
        color: #64748b;
        border-top: 1px solid #d9e2ec;
        padding-top: 8px;
    }
</style>
</head>
<body>' . $watermarkHtml . '
    <div class="page-shell">
    <table class="header">
        <tr>
            <td class="logo-wrap"><div class="logo-block"><div class="logo-image">' . $logoHtml . '</div><div class="logo-legal">' . htmlspecialchars($companyLegalName) . '</div><div class="logo-email">' . htmlspecialchars($companyEmail) . '</div></div></td>
            <td class="invoice-wrap">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">#' . htmlspecialchars($invoiceNumber) . '</div>
                <div class="status-pill ' . $statusClass . '">' . htmlspecialchars($status) . '</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="billto-box">
                <div class="box-title">Bill To</div>';
    foreach ($billLines as $idx => $line) {
        $class = $idx === 0 ? 'bill-line name' : 'bill-line';
        $html .= '<div class="' . $class . '">' . htmlspecialchars($line) . '</div>';
    }
    $html .= '</td>
            <td style="width: 6%;"></td>
            <td class="meta-box">
                <div class="meta-row"><span class="meta-label">Invoice Date</span> ' . htmlspecialchars($invoiceDate !== '' ? $invoiceDate : '-') . '</div>
                <div class="meta-row"><span class="meta-label">Due Date</span> ' . htmlspecialchars($dueDate !== '' ? $dueDate : '-') . '</div>
                <div class="meta-row"><span class="meta-label">Balance Due</span> $' . number_format($balanceDue, 2) . '</div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Line Total</th>
            </tr>
        </thead>
        <tbody>' . $lineRows . '</tbody>
    </table>

    <div class="summary-wrap">
        <table class="summary-table">
            <tr><td>Subtotal</td><td>$' . number_format($subtotalAmount, 2) . '</td></tr>'
            . ($taxAmount > 0 ? '<tr><td>Tax</td><td>$' . number_format($taxAmount, 2) . '</td></tr>' : '')
            . ($paymentsReceived > 0 ? '<tr><td>Payments Received</td><td>-$' . number_format($paymentsReceived, 2) . '</td></tr>' : '') . '
            <tr><td>Balance Due</td><td>$' . number_format($balanceDue, 2) . '</td></tr>
            <tr class="total"><td>Total</td><td>$' . number_format($totalAmount, 2) . '</td></tr>
        </table>
    </div>

    <table class="bottom">
        <tr>
            <td class="notes">
                <div class="box-title">Notes</div>
                <div>' . $notesText . '</div>
            </td>
            <td style="width: 4%;"></td>
            <td class="payment">
                <div class="box-title">Payment Details</div>
                <div>' . htmlspecialchars($paymentText) . '</div>
            </td>
        </tr>
    </table>

    <div class="footer">LnK Consulting, LLC dba Midwest Managed IT | billing@midwestmanagedit.com | Thank you for your business and prompt payment.</div>
    </div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('defaultPaperSize', 'letter');
    $options->setChroot(dirname(__DIR__));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}
function accounting_issue_and_send_invoice(int $invoiceId, array $data, int $userId): array {
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) {
        return ['ok' => false, 'errors' => ['Invoice not found.']];
    }

    if ((string)($invoice['status'] ?? 'DRAFT') === 'DRAFT') {
        $issue = accounting_issue_invoice($invoiceId, $userId);
        if (empty($issue['ok'])) {
            return $issue;
        }
    }

    $send = accounting_send_invoice_email($invoiceId, $data, $userId);
    if (empty($send['ok'])) {
        return $send;
    }

    return ['ok' => true, 'message' => (string)($send['message'] ?? 'Invoice issued, posted, and emailed successfully.')];
}

function accounting_send_invoice_email(int $invoiceId, array $data, int $userId): array {
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) {
        return ['ok' => false, 'errors' => ['Invoice not found.']];
    }
    if ((string)$invoice['status'] === 'DRAFT') {
        return ['ok' => false, 'errors' => ['Issue the invoice before sending it.']];
    }

    $stripeWarnings = [];
    if ((float)($invoice['balance_due'] ?? 0) > 0.00001) {
        require_once __DIR__ . '/payment_gateway.php';
        if (function_exists('payment_gateway_stripe_enabled') && payment_gateway_stripe_enabled()) {
            $needsStripeSync = !accounting_invoice_has_stripe_payment_page($invoice)
                || strtoupper(trim((string)($invoice['status'] ?? ''))) === 'PARTIALLY_PAID';
            if ($needsStripeSync) {
                $sync = payment_gateway_stripe_sync_local_invoice($invoiceId);
                if (!empty($sync['ok'])) {
                    $invoice = accounting_get_invoice($invoiceId) ?: $invoice;
                } else {
                    $stripeWarnings = array_merge($stripeWarnings, $sync['errors'] ?? ['Stripe payment page could not be prepared.']);
                }
            }
        }
    }

    $to = trim((string)($data['email_to'] ?? ''));
    $subject = trim((string)($data['email_subject'] ?? ''));
    $body = trim((string)($data['email_body'] ?? ''));
    $originalTo = $to;
    $to = accounting_mail_effective_recipient($to);
    $errors = [];
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid recipient email.';
    }
    if ($subject === '') {
        $errors[] = 'Subject is required.';
    }
    if ($body === '') {
        $errors[] = 'Email body is required.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => array_merge($errors, $stripeWarnings)];
    }

    $stripeUrl = accounting_invoice_stripe_payment_url($invoice);
    $plainBody = trim($body);
    if ($stripeUrl !== '' && stripos($plainBody, $stripeUrl) === false) {
        $plainBody = rtrim($plainBody) . "

Secure payment link:
" . $stripeUrl . "
";
    } elseif ($stripeUrl === '' && (float)($invoice['balance_due'] ?? 0) > 0.00001) {
        $achLink = accounting_invoice_payment_link((string)$invoice['invoice_number'], 'ACH');
        $cardLink = accounting_invoice_payment_link((string)$invoice['invoice_number'], 'CARD');
        if (stripos($plainBody, $achLink) === false && stripos($plainBody, $cardLink) === false) {
            $plainBody = rtrim($plainBody) . "

Payment options:
Pay by ACH: " . $achLink . "
Pay by Card: " . $cardLink . "
";
        }
    }

    $lines = accounting_invoice_lines($invoiceId);
    $pdfBytes = accounting_render_invoice_pdf_bytes($invoice, $lines);
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$invoice['invoice_number']) . '.pdf';
    $htmlEmailBody = $body;
    if (accounting_mail_sandbox_enabled()) {
        $subject = preg_match('/^\[TEST\]/', $subject) ? $subject : '[TEST] ' . $subject;
        $plainBody = "TEST MODE: This invoice email is being redirected to " . accounting_mail_sandbox_to() . " while mail sandbox mode is enabled.\n"
            . "Original requested recipient: " . $originalTo . "\n\n" . $plainBody;
    }

    $plainBody = str_replace(["\r\n", "\r"], "\n", $plainBody);
    $htmlBody = accounting_render_invoice_email_html($invoice, $htmlEmailBody, $stripeUrl, accounting_mail_sandbox_enabled(), $originalTo);

    $sendResult = ops_mail_send([
        'sender_channel' => 'billing',
        'to' => $to,
        'subject' => $subject,
        'text_body' => $plainBody,
        'html_body' => $htmlBody,
        'attachments' => [[
            'filename' => $filename,
            'content_type' => 'application/pdf',
            'content_bytes' => $pdfBytes,
        ]],
    ]);

    $ok = !empty($sendResult['ok']);
    $error = $ok ? null : (string) ($sendResult['error'] ?? 'OPS mailer returned an unknown error.');
    $to = (string) ($sendResult['to'] ?? $to);

    if (accounting_invoice_delivery_table_exists()) {
        $st = db()->prepare("INSERT INTO invoice_delivery (invoice_id, recipient_email, subject_line, body_preview, delivery_status, error_message, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            $invoiceId,
            $to,
            $subject,
            substr($plainBody, 0, 500),
            $ok ? 'SENT' : 'FAILED',
            $error,
            $userId > 0 ? $userId : null,
        ]);
    }

    if (!$ok) {
        return ['ok' => false, 'errors' => ['Invoice email could not be sent. ' . ($error !== null ? $error : 'Check the OPS mail configuration.')]];
    }
    $transport = (string) ($sendResult['transport'] ?? 'mail');
    $message = 'Invoice emailed to ' . $to . ' via ' . strtoupper($transport) . '.';
    if ($stripeWarnings) {
        $message .= ' Stripe note: ' . implode(' ', array_unique($stripeWarnings));
    }
    return ['ok' => true, 'message' => $message];
}

function accounting_delivery_status_badge_html(string $status): string {
    $status = strtoupper(trim($status));
    $map = [
        'SENT' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0'],
        'FAILED' => ['bg' => 'rgba(239,68,68,.18)', 'border' => 'rgba(239,68,68,.28)', 'color' => '#fecaca'],
    ];
    $style = $map[$status] ?? ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1'];
    return '<span class="status-badge" style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">' . accounting_h($status) . '</span>';
}


function accounting_get_pricing_models(): array {
    return ['FIXED', 'PER_USER', 'PER_DEVICE', 'PER_LICENSE'];
}

function accounting_client_service_ready(): bool {
    return db_table_exists('client_service') && db_table_exists('service_item') && db_table_exists('recurring_service');
}

function accounting_client_service_summary(): array {
    if (!accounting_client_service_ready()) {
        return ['active_count' => 0, 'due_today_count' => 0, 'mrr_value' => 0.0, 'arr_value' => 0.0];
    }
    $pdo = db();
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM client_service WHERE status = 'ACTIVE'")->fetchColumn();
    $dueToday = (int)$pdo->query("SELECT COUNT(*) FROM client_service WHERE status = 'ACTIVE' AND next_bill_date <= CURDATE()") ->fetchColumn();
    $mrr = (float)$pdo->query("SELECT COALESCE(SUM(CASE billing_cycle WHEN 'MONTHLY' THEN quantity * unit_price WHEN 'QUARTERLY' THEN (quantity * unit_price) / 3 WHEN 'SEMIANNUAL' THEN (quantity * unit_price) / 6 WHEN 'ANNUAL' THEN (quantity * unit_price) / 12 ELSE 0 END),0) FROM client_service WHERE status = 'ACTIVE'")->fetchColumn();
    $arr = (float)$pdo->query("SELECT COALESCE(SUM(CASE billing_cycle WHEN 'MONTHLY' THEN (quantity * unit_price) * 12 WHEN 'QUARTERLY' THEN (quantity * unit_price) * 4 WHEN 'SEMIANNUAL' THEN (quantity * unit_price) * 2 WHEN 'ANNUAL' THEN quantity * unit_price ELSE 0 END),0) FROM client_service WHERE status = 'ACTIVE'")->fetchColumn();
    return ['active_count' => $activeCount, 'due_today_count' => $dueToday, 'mrr_value' => round($mrr, 2), 'arr_value' => round($arr, 2)];
}

function accounting_list_client_services(int $limit = 200, ?int $clientId = null): array {
    if (!accounting_client_service_ready()) return [];
    $limit = max(1, min(500, $limit));
    $sql = "SELECT cs.*, c.client_code, c.legal_name, c.dba_name,
                   si.item_code, si.item_name, rs.last_billed_date AS recurring_last_billed_date, rs.active AS recurring_active,
                   u.display_name AS created_by_name
            FROM client_service cs
            INNER JOIN clients c ON c.client_id = cs.client_id
            LEFT JOIN service_item si ON si.item_id = cs.item_id
            LEFT JOIN recurring_service rs ON rs.recurring_service_id = cs.recurring_service_id
            LEFT JOIN portal_user u ON u.user_id = cs.created_by";
    $params = [];
    if ($clientId !== null && $clientId > 0) {
        $sql .= " WHERE cs.client_id = ?";
        $params[] = $clientId;
    }
    $sql .= " ORDER BY c.legal_name ASC, cs.next_bill_date ASC, cs.client_service_id DESC LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_create_client_service_assignment(array $data, int $userId = 0): array {
    if (!accounting_client_service_ready()) {
        return ['ok' => false, 'errors' => ['Client service tables are not installed yet.']];
    }

    $clientId = (int)($data['client_id'] ?? 0);
    $contractId = (int)($data['contract_id'] ?? 0);
    $itemId = (int)($data['item_id'] ?? 0);
    $pricingModel = strtoupper(trim((string)($data['pricing_model'] ?? 'FIXED')));
    $description = trim((string)($data['description'] ?? ''));
    $quantity = (float)($data['quantity'] ?? 0);
    $unitPrice = (float)($data['unit_price'] ?? 0);
    $billingCycle = strtoupper(trim((string)($data['billing_cycle'] ?? 'MONTHLY')));
    $termMonths = (int)($data['term_months'] ?? 0);
    $startDate = trim((string)($data['start_date'] ?? ''));
    $nextBillDate = trim((string)($data['next_bill_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? ''));
    $revenueAccountId = (int)($data['revenue_account_id'] ?? 0);
    $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
    $autoRenew = !empty($data['auto_renew']) ? 1 : 0;
    $taxable = !empty($data['taxable']) ? 1 : 0;
    $notes = trim((string)($data['notes'] ?? ''));

    $errors = [];
    if ($clientId <= 0) $errors[] = 'Choose a client.';
    if ($itemId <= 0) $errors[] = 'Choose a catalog item.';
    if (!in_array($pricingModel, accounting_get_pricing_models(), true)) $errors[] = 'Choose a valid pricing model.';
    if (!in_array($billingCycle, accounting_get_recurring_cycles(), true)) $errors[] = 'Choose a valid billing cycle.';
    if ($quantity <= 0) $errors[] = 'Covered users must be greater than zero.';
    if ($unitPrice < 0) $errors[] = 'Unit price cannot be negative.';
    if ($revenueAccountId <= 0) $errors[] = 'Choose a revenue account.';
    if (!in_array($status, ['ACTIVE', 'PAUSED', 'ENDED'], true)) $errors[] = 'Choose a valid status.';

    $item = $itemId > 0 ? accounting_get_catalog_item($itemId) : null;
    if ($item) {
        if ($description === '') $description = trim((string)$item['item_name']);
        if ($unitPrice == 0.0) $unitPrice = (float)$item['default_unit_price'];
        if (($billingCycle === '' || !in_array($billingCycle, accounting_get_recurring_cycles(), true)) && !empty($item['default_billing_cycle'])) {
            $billingCycle = (string)$item['default_billing_cycle'];
        }
        if ($termMonths <= 0 && !empty($item['term_months'])) $termMonths = (int)$item['term_months'];
        if ($revenueAccountId <= 0 && !empty($item['revenue_account_id'])) $revenueAccountId = (int)$item['revenue_account_id'];
        if (!$taxable && !empty($item['is_taxable'])) $taxable = 1;
    }

    if ($description === '') $errors[] = 'Description is required.';
    if ($startDate === '') $startDate = date('Y-m-d');
    if ($nextBillDate === '') $nextBillDate = $startDate;
    foreach ([['Start date', $startDate], ['Next bill date', $nextBillDate]] as [$label, $value]) {
        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[] = $label . ' must be a valid date.';
        }
    }
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $errors[] = 'End date must be a valid date.';
    }
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $recurringActive = $status === 'ACTIVE' ? 1 : 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rs = $pdo->prepare('INSERT INTO recurring_service (client_id, contract_id, contract_service_id, item_id, item_type, description, billing_type, billing_cycle, quantity, unit_price, taxable, next_bill_date, last_billed_date, active, notes, term_months, auto_renew, start_date, end_date) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)');
        $rs->execute([
            $clientId,
            $contractId > 0 ? $contractId : null,
            $itemId,
            ($item['item_type'] ?? 'SERVICE'),
            $description,
            $pricingModel,
            $billingCycle,
            $quantity,
            $unitPrice,
            $taxable,
            $nextBillDate,
            $recurringActive,
            $notes !== '' ? $notes : null,
            $termMonths > 0 ? $termMonths : null,
            $autoRenew,
            $startDate,
            $endDate !== '' ? $endDate : null,
        ]);
        $recurringServiceId = (int)$pdo->lastInsertId();

        $cs = $pdo->prepare('INSERT INTO client_service (client_id, contract_id, item_id, recurring_service_id, pricing_model, description, quantity, unit_price, billing_cycle, term_months, start_date, next_bill_date, end_date, revenue_account_id, taxable, auto_renew, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $cs->execute([
            $clientId,
            $contractId > 0 ? $contractId : null,
            $itemId,
            $recurringServiceId,
            $pricingModel,
            $description,
            $quantity,
            $unitPrice,
            $billingCycle,
            $termMonths > 0 ? $termMonths : null,
            $startDate,
            $nextBillDate,
            $endDate !== '' ? $endDate : null,
            $revenueAccountId,
            $taxable,
            $autoRenew,
            $status,
            $notes !== '' ? $notes : null,
            $userId > 0 ? $userId : null,
        ]);
        $clientServiceId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['ok' => true, 'client_service_id' => $clientServiceId, 'recurring_service_id' => $recurringServiceId, 'message' => 'Client service assigned successfully.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Failed to save client service assignment: ' . $e->getMessage()]];
    }
}


function accounting_proration_period_dates(array $service, string $effectiveDate): array {
    $effectiveDate = trim($effectiveDate) !== '' ? trim($effectiveDate) : date('Y-m-d');
    $end = trim((string)($service['next_bill_date'] ?? ''));
    if ($end === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = date('Y-m-d', strtotime($effectiveDate . ' +1 month'));
    }
    $cycle = strtoupper(trim((string)($service['billing_cycle'] ?? 'MONTHLY')));
    $modifier = match ($cycle) {
        'QUARTERLY' => '-3 months',
        'SEMIANNUAL' => '-6 months',
        'ANNUAL' => '-1 year',
        default => '-1 month',
    };
    $start = date('Y-m-d', strtotime($end . ' ' . $modifier));
    if ($effectiveDate < $start) {
        $effectiveDate = $start;
    }
    if ($effectiveDate > $end) {
        $effectiveDate = $end;
    }
    return [$start, $end, $effectiveDate];
}

function accounting_service_proration_preview(array $service, array $changes, string $effectiveDate): array {
    [$periodStart, $periodEnd, $effectiveDate] = accounting_proration_period_dates($service, $effectiveDate);
    $oldQuantity = round((float)($service['quantity'] ?? 0), 2);
    $oldUnitPrice = round((float)($service['unit_price'] ?? 0), 2);
    $newQuantity = round((float)($changes['quantity'] ?? $oldQuantity), 2);
    $newUnitPrice = round((float)($changes['unit_price'] ?? $oldUnitPrice), 2);
    $oldAmount = round($oldQuantity * $oldUnitPrice, 2);
    $newAmount = round($newQuantity * $newUnitPrice, 2);
    $periodDays = max(1, (int)round((strtotime($periodEnd) - strtotime($periodStart)) / 86400));
    $remainingDays = max(0, (int)round((strtotime($periodEnd) - strtotime($effectiveDate)) / 86400));
    $deltaRecurring = round($newAmount - $oldAmount, 2);
    $prorationAmount = round(($deltaRecurring * $remainingDays) / $periodDays, 2);
    return [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'effective_date' => $effectiveDate,
        'period_days' => $periodDays,
        'remaining_days' => $remainingDays,
        'old_amount' => $oldAmount,
        'new_amount' => $newAmount,
        'delta_recurring' => $deltaRecurring,
        'proration_amount' => $prorationAmount,
    ];
}

function accounting_create_service_proration_invoice(int $clientServiceId, array $changes, string $effectiveDate, int $userId = 0): array {
    $service = accounting_get_client_service($clientServiceId);
    if (!$service) {
        return ['ok' => false, 'errors' => ['Client service not found for proration.']];
    }
    $preview = accounting_service_proration_preview($service, $changes, $effectiveDate);
    if ($preview['proration_amount'] <= 0) {
        return ['ok' => false, 'errors' => ['Proration draft is only created when the mid-cycle change increases the amount due.']];
    }
    $description = trim((string)($changes['description'] ?? $service['description'] ?? 'Service adjustment'));
    $memo = 'Proration generated from client service update. Effective ' . $preview['effective_date']
        . '. Old recurring $' . number_format((float)$preview['old_amount'], 2)
        . ', new recurring $' . number_format((float)$preview['new_amount'], 2)
        . ', ' . (int)$preview['remaining_days'] . ' of ' . (int)$preview['period_days'] . ' days remaining in cycle.';
    return accounting_create_invoice([
        'client_id' => (int)$service['client_id'],
        'contract_id' => (int)($service['contract_id'] ?? 0),
        'invoice_date' => $preview['effective_date'],
        'due_date' => $preview['effective_date'],
        'status' => 'DRAFT',
        'memo' => $memo,
        'source_system' => 'PRORATION',
        'source_record_id' => (string)$clientServiceId,
        'item_id' => (int)($service['item_id'] ?? 0),
        'service_code' => (string)($service['item_code'] ?? ''),
        'description' => $description . ' proration (' . $preview['effective_date'] . ' through ' . $preview['period_end'] . ')',
        'quantity' => 1,
        'unit_price' => $preview['proration_amount'],
        'revenue_account_id' => (int)$service['revenue_account_id'],
    ], $userId);
}

function accounting_get_client_service(int $clientServiceId): ?array {
    if (!accounting_client_service_ready() || $clientServiceId <= 0) return null;
    $sql = "SELECT cs.*, c.client_code, c.legal_name, c.dba_name, si.item_code, si.item_name, si.item_type, rs.last_billed_date AS recurring_last_billed_date, rs.active AS recurring_active, u.display_name AS created_by_name FROM client_service cs INNER JOIN clients c ON c.client_id = cs.client_id LEFT JOIN service_item si ON si.item_id = cs.item_id LEFT JOIN recurring_service rs ON rs.recurring_service_id = cs.recurring_service_id LEFT JOIN portal_user u ON u.user_id = cs.created_by WHERE cs.client_service_id = ? LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$clientServiceId]);
    return $st->fetch() ?: null;
}

function accounting_update_client_service(int $clientServiceId, array $data, int $userId = 0): array {
    $service = accounting_get_client_service($clientServiceId);
    if (!$service) return ['ok' => false, 'errors' => ['Client service not found.']];
    $quantity = (float)($data['quantity'] ?? $service['quantity'] ?? 0);
    $unitPrice = (float)($data['unit_price'] ?? $service['unit_price'] ?? 0);
    $billingCycle = strtoupper(trim((string)($data['billing_cycle'] ?? $service['billing_cycle'] ?? 'MONTHLY')));
    $termMonths = (int)($data['term_months'] ?? $service['term_months'] ?? 0);
    $description = trim((string)($data['description'] ?? $service['description'] ?? ''));
    $startDate = trim((string)($data['start_date'] ?? $service['start_date'] ?? ''));
    $nextBillDate = trim((string)($data['next_bill_date'] ?? $service['next_bill_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? $service['end_date'] ?? ''));
    $revenueAccountId = (int)($data['revenue_account_id'] ?? $service['revenue_account_id'] ?? 0);
    $taxable = !empty($data['taxable']) ? 1 : 0;
    $autoRenew = !empty($data['auto_renew']) ? 1 : 0;
    $notes = trim((string)($data['notes'] ?? $service['notes'] ?? ''));
    $errors = [];
    if ($description === '') $errors[] = 'Description is required.';
    if ($quantity <= 0) $errors[] = 'Covered users must be greater than zero.';
    if ($unitPrice < 0) $errors[] = 'Unit price cannot be negative.';
    if (!in_array($billingCycle, accounting_get_recurring_cycles(), true)) $errors[] = 'Choose a valid billing cycle.';
    if ($revenueAccountId <= 0) $errors[] = 'Choose a revenue account.';
    foreach ([['Start date',$startDate],['Next bill date',$nextBillDate]] as [$label,$value]) {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $errors[] = $label . ' must be a valid date.';
    }
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $errors[] = 'End date must be a valid date.';
    if ($errors) return ['ok' => false, 'errors' => $errors];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE client_service SET description = ?, quantity = ?, unit_price = ?, billing_cycle = ?, term_months = ?, start_date = ?, next_bill_date = ?, end_date = ?, revenue_account_id = ?, taxable = ?, auto_renew = ?, notes = ? WHERE client_service_id = ?');
        $st->execute([$description, $quantity, $unitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $startDate, $nextBillDate, $endDate !== '' ? $endDate : null, $revenueAccountId, $taxable, $autoRenew, $notes !== '' ? $notes : null, $clientServiceId]);
        if (!empty($service['recurring_service_id'])) {
            $active = strtoupper((string)$service['status']) === 'ACTIVE' ? 1 : 0;
            $st = $pdo->prepare('UPDATE recurring_service SET description = ?, billing_type = ?, billing_cycle = ?, quantity = ?, unit_price = ?, taxable = ?, next_bill_date = ?, active = ?, notes = ?, term_months = ?, auto_renew = ?, start_date = ?, end_date = ? WHERE recurring_service_id = ?');
            $st->execute([$description, $service['pricing_model'], $billingCycle, $quantity, $unitPrice, $taxable, $nextBillDate, $active, $notes !== '' ? $notes : null, $termMonths > 0 ? $termMonths : null, $autoRenew, $startDate, $endDate !== '' ? $endDate : null, (int)$service['recurring_service_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => 'Client service updated.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Unable to update client service: ' . $e->getMessage()]];
    }
}

function accounting_change_client_service_status(int $clientServiceId, string $status, int $userId = 0, string $reason = ''): array {
    $service = accounting_get_client_service($clientServiceId);
    if (!$service) return ['ok' => false, 'errors' => ['Client service not found.']];
    $status = strtoupper(trim($status));
    if (!in_array($status, ['ACTIVE', 'PAUSED', 'ENDED'], true)) return ['ok' => false, 'errors' => ['Invalid client service status.']];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $notes = trim((string)($service['notes'] ?? ''));
        if ($reason !== '') {
            $stamp = date('Y-m-d H:i');
            $notes = trim($notes . "
[{$stamp}] Status changed to {$status}" . ($reason !== '' ? ': ' . $reason : ''));
        }
        $st = $pdo->prepare('UPDATE client_service SET status = ?, notes = ? WHERE client_service_id = ?');
        $st->execute([$status, $notes !== '' ? $notes : null, $clientServiceId]);
        if (!empty($service['recurring_service_id'])) {
            $active = $status === 'ACTIVE' ? 1 : 0;
            $st = $pdo->prepare('UPDATE recurring_service SET active = ?, notes = ? WHERE recurring_service_id = ?');
            $st->execute([$active, $notes !== '' ? $notes : null, (int)$service['recurring_service_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => 'Client service status updated to ' . $status . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Unable to change client service status: ' . $e->getMessage()]];
    }
}

function accounting_get_recurring_item(int $recurringServiceId): ?array {
    if (!accounting_recurring_ready() || $recurringServiceId <= 0) return null;
    $sql = "SELECT rs.*, c.client_code, c.legal_name, c.dba_name, si.item_code, si.item_name, si.revenue_account_id, cs.client_service_id, cs.status AS client_service_status FROM recurring_service rs INNER JOIN clients c ON c.client_id = rs.client_id LEFT JOIN service_item si ON si.item_id = rs.item_id LEFT JOIN client_service cs ON cs.recurring_service_id = rs.recurring_service_id WHERE rs.recurring_service_id = ? LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$recurringServiceId]);
    return $st->fetch() ?: null;
}

function accounting_update_recurring_item(int $recurringServiceId, array $data, int $userId = 0): array {
    $item = accounting_get_recurring_item($recurringServiceId);
    if (!$item) return ['ok' => false, 'errors' => ['Recurring item not found.']];
    $quantity = (float)($data['quantity'] ?? $item['quantity'] ?? 0);
    $unitPrice = (float)($data['unit_price'] ?? $item['unit_price'] ?? 0);
    $billingCycle = strtoupper(trim((string)($data['billing_cycle'] ?? $item['billing_cycle'] ?? 'MONTHLY')));
    $description = trim((string)($data['description'] ?? $item['description'] ?? ''));
    $startDate = trim((string)($data['start_date'] ?? $item['start_date'] ?? ''));
    $nextBillDate = trim((string)($data['next_bill_date'] ?? $item['next_bill_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? $item['end_date'] ?? ''));
    $termMonths = (int)($data['term_months'] ?? $item['term_months'] ?? 0);
    $taxable = !empty($data['taxable']) ? 1 : 0;
    $autoRenew = !empty($data['auto_renew']) ? 1 : 0;
    $notes = trim((string)($data['notes'] ?? $item['notes'] ?? ''));
    $errors = [];
    if ($description === '') $errors[] = 'Description is required.';
    if ($quantity <= 0) $errors[] = 'Covered users must be greater than zero.';
    if ($unitPrice < 0) $errors[] = 'Unit price cannot be negative.';
    if (!in_array($billingCycle, accounting_get_recurring_cycles(), true)) $errors[] = 'Choose a valid billing cycle.';
    if ($nextBillDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextBillDate)) $errors[] = 'Next bill date must be valid.';
    if ($startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $errors[] = 'Start date must be valid.';
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $errors[] = 'End date must be valid.';
    if ($errors) return ['ok' => false, 'errors' => $errors];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE recurring_service SET description = ?, billing_cycle = ?, quantity = ?, unit_price = ?, taxable = ?, next_bill_date = ?, notes = ?, term_months = ?, auto_renew = ?, start_date = ?, end_date = ? WHERE recurring_service_id = ?');
        $st->execute([$description, $billingCycle, $quantity, $unitPrice, $taxable, $nextBillDate, $notes !== '' ? $notes : null, $termMonths > 0 ? $termMonths : null, $autoRenew, $startDate, $endDate !== '' ? $endDate : null, $recurringServiceId]);
        if (!empty($item['client_service_id'])) {
            $st = $pdo->prepare('UPDATE client_service SET description = ?, quantity = ?, unit_price = ?, billing_cycle = ?, term_months = ?, start_date = ?, next_bill_date = ?, end_date = ?, taxable = ?, auto_renew = ?, notes = ? WHERE client_service_id = ?');
            $st->execute([$description, $quantity, $unitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $startDate, $nextBillDate, $endDate !== '' ? $endDate : null, $taxable, $autoRenew, $notes !== '' ? $notes : null, (int)$item['client_service_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => 'Recurring item updated.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Unable to update recurring item: ' . $e->getMessage()]];
    }
}

function accounting_change_recurring_status(int $recurringServiceId, bool $active, int $userId = 0, string $reason = ''): array {
    $item = accounting_get_recurring_item($recurringServiceId);
    if (!$item) return ['ok' => false, 'errors' => ['Recurring item not found.']];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $notes = trim((string)($item['notes'] ?? ''));
        if ($reason !== '') {
            $stamp = date('Y-m-d H:i');
            $notes = trim($notes . "
[{$stamp}] Recurring item " . ($active ? 'activated' : 'paused') . ': ' . $reason);
        }
        $st = $pdo->prepare('UPDATE recurring_service SET active = ?, notes = ? WHERE recurring_service_id = ?');
        $st->execute([$active ? 1 : 0, $notes !== '' ? $notes : null, $recurringServiceId]);
        if (!empty($item['client_service_id'])) {
            $st = $pdo->prepare('UPDATE client_service SET status = ?, notes = ? WHERE client_service_id = ?');
            $st->execute([$active ? 'ACTIVE' : 'PAUSED', $notes !== '' ? $notes : null, (int)$item['client_service_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => 'Recurring item ' . ($active ? 'activated.' : 'paused.')];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Unable to change recurring status: ' . $e->getMessage()]];
    }
}

function accounting_list_source_invoices(string $sourceSystem, string $sourceRecordId): array {
    $st = db()->prepare('SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.due_date, i.status, i.total_amount, i.balance_due, c.legal_name, c.dba_name FROM customer_invoice i INNER JOIN clients c ON c.client_id = i.client_id WHERE i.source_system = ? AND i.source_record_id = ? ORDER BY i.invoice_date DESC, i.invoice_id DESC');
    $st->execute([$sourceSystem, $sourceRecordId]);
    return $st->fetchAll();
}


function accounting_contract_ready(): bool {
    return db_table_exists('contract') && db_table_exists('contract_service');
}

function accounting_contract_status_options(): array {
    return [
        'DRAFT' => 'Draft',
        'PENDING_SIGNATURE' => 'Pending Signature',
        'SIGNED_PENDING_ONBOARDING' => 'Signed / Pending Onboarding',
        'ONBOARDING' => 'Onboarding In Progress',
        'ACTIVE' => 'Active',
        'EXPIRED' => 'Expired',
        'CANCELLED' => 'Cancelled',
    ];
}

function accounting_contract_template_ready(): bool {
    return db_table_exists('contract_template');
}

function accounting_contract_default_templates(): array {
    return [
        'MSA' => [
            'name' => 'MSA_v2_packet',
            'body' => <<<'TEXT'
1. Order of Precedence; Contract Structure
This Master Managed Services Agreement sets out the general legal terms governing Provider’s recurring managed IT services. Each signed Order Form, together with the Support Policy / SLA attached as Exhibit A and any selected add-on schedule, is incorporated into and governed by this Agreement.
If there is a conflict among the contract documents, the following order controls unless the parties expressly state otherwise in the relevant Order Form: (a) the Order Form, (b) this Agreement, and (c) the Support Policy / SLA.

2. Definitions
Covered Environment means the users, endpoints, servers, cloud tenants, network components, and other assets expressly listed or reasonably described in the applicable Order Form.
Managed Services means the recurring services expressly included in the applicable Order Form and no others.
Response Target means Provider’s target time for initial acknowledgment, intake, and triage of a properly submitted support ticket during stated Support Hours.
Support Hours means Monday through Friday, 9:00 a.m. to 6:00 p.m. Eastern Time, excluding Provider-observed holidays, unless the applicable Order Form states otherwise.
Third-Party Services means software, cloud services, telecommunications, internet connectivity, utilities, hardware, backup platforms, security tools, and vendor services provided by parties other than Provider.

3. Nature of Services; Remote-First Model
Provider delivers recurring managed IT services on a subscription basis for the Covered Environment described in the applicable Order Form. The services are designed to provide ongoing monitoring, maintenance, administration, security tooling, ticket-based support, and related remote assistance as expressly stated in the applicable Order Form.
This Agreement is not a break-fix arrangement, warranty program, insurer relationship, or guarantee of uninterrupted operations, complete cybersecurity protection, perfect uptime, or complete recoverability of data.
Unless an Order Form expressly states that onsite work is included, services are delivered remotely. Nothing in this Agreement creates an implied obligation for Provider to maintain local field staff, dispatch onsite personnel, or meet a physical arrival time.

4. Scope of Services; Changes; Out-of-Scope Work
Provider shall perform only the Managed Services expressly identified in the applicable Order Form. Services not expressly included are outside scope and billable at Provider’s then-current rates or under a separate statement of work.
Out-of-scope work includes, unless expressly included in writing: onsite labor; after-hours work; holiday work; project work; procurement; cabling; hardware installation or replacement; structured wiring; custom software development; compliance consulting; legal or regulatory advice; remediation of pre-existing conditions; unsupported or end-of-life systems; home networks and personal devices not listed in the Covered Environment; and line-of-business application support beyond ordinary vendor liaison.
If Client requests a material change in service levels, covered quantities, vendors, security stack, backup scope, cloud scope, or supported locations, the parties shall document the change through a revised Order Form, approved quote, or written change order.

5. Onsite Services; Geography; Travel
Onsite services, if requested by Client and accepted by Provider, are outside the recurring monthly services unless expressly included in the applicable Order Form.
Approved onsite services are subject to Provider’s staffing, geography, travel availability, and scheduling constraints. Provider may decline onsite work outside its practical service area or fulfill such work through qualified subcontractors or field technicians.
Unless otherwise stated in writing, onsite work may be billed at Provider’s then-current rates, plus travel time, mileage, lodging, third-party technician costs, parking, tolls, and any applicable minimum service charge.

6. Support Requests; Ticketing; Response Targets
Client shall submit support requests through Provider’s designated ticketing, portal, email, or emergency channel. Provider may require ticket creation before commencing non-emergency support work.
Response Targets apply only to Provider’s initial acknowledgment and triage of a properly submitted support ticket during Support Hours. Response Targets are not guarantees of final resolution, service restoration, onsite dispatch, workaround delivery, or completion within a specific time.
Provider may reasonably assign or adjust ticket priority based on business impact, number of affected users, security implications, availability of a workaround, completeness of information supplied by Client, and whether the affected system is within the Covered Environment.
Any service clock is tolled while Provider is waiting on Client information, credentials, approvals, vendor action, replacement hardware, internet restoration, or access to the affected systems.

7. Client Responsibilities
Client shall designate one or more authorized contacts with authority to approve changes, purchases, remediation actions, restore decisions, and communications regarding service incidents.
Client shall provide timely access to systems, credentials, premises, documentation, and vendor relationships reasonably necessary for Provider to perform the Managed Services.
Client shall maintain legally obtained and properly licensed software, vendor subscriptions, internet connectivity, electrical power, and environmental conditions reasonably necessary for the services.
Client shall not disable, interfere with, or circumvent Provider’s monitoring, security, backup, remote management, ticketing, or alerting tools without Provider’s written approval.
Client remains responsible for its business decisions, internal policies, user training, legal compliance obligations, data classification, records retention decisions, and approval of purchases or replacements.

8. Fees; Invoicing; Taxes
Client shall pay the recurring and non-recurring fees stated in the applicable Order Form. Unless the Order Form states otherwise, recurring fees are billed monthly in advance and non-recurring fees are billed when incurred.
All invoices are due within fifteen (15) days after invoice date. Client shall notify Provider in writing of any specific, good-faith invoice dispute within seven (7) days after invoice date, or the invoice will be deemed accepted.
Fees are exclusive of applicable sales, use, excise, communications, and similar taxes, which shall be paid by Client except for taxes measured solely by Provider’s net income.

9. Late Payment; Suspension; Collection Costs
Overdue undisputed amounts accrue interest at the lesser of 1.5% per month or the maximum lawful rate.
Provider may suspend all or part of the services on written notice if undisputed amounts remain unpaid for ten (10) days after the due date.
Provider may also suspend services immediately if Client’s nonpayment, interference, or security posture materially risks harm to Provider, Provider’s tools, or other clients, provided Provider gives prompt notice when commercially reasonable.
Client shall reimburse Provider for reasonable costs of collection, including court costs and reasonable attorney fees, incurred in collecting overdue undisputed amounts to the extent permitted by law.

10. Term; Renewal; Termination
This Agreement begins on the Effective Date of the first Order Form and continues until terminated in accordance with this Agreement. Each Order Form begins on its own start date and continues through its stated Initial Service Term. Unless a signed Order Form expressly states a different minimum, the Initial Service Term for Managed Services is six (6) months.
After the Initial Service Term, an Order Form renews automatically on a month-to-month basis unless either Party gives at least thirty (30) days' written notice of cancellation or non-renewal before the requested end date.
Either Party may terminate for material breach if the breach is not cured within thirty (30) days after written notice, except that nonpayment, repeated security noncooperation, abusive conduct, or unauthorized tampering with managed systems may be subject to a shorter cure period of ten (10) days.
If Client terminates an Order Form without cause before the end of the Initial Service Term, Client shall pay: (a) all accrued but unpaid fees, (b) any non-cancelable third-party charges or pass-through costs committed for Client, and (c) as liquidated damages and not as a penalty, fifty percent (50%) of the remaining unpaid monthly recurring charges for the balance of the Initial Service Term. The parties agree this formula is intended as a reasonable pre-estimate of Provider’s loss, recognizing that actual damages would be difficult to calculate at the time of contracting.

11. Third-Party Services; Procurement; Vendor Dependencies
Provider may recommend or resell Third-Party Services, but unless expressly stated otherwise in a signed writing, Provider is not the manufacturer, publisher, licensor, carrier, or utility for those services.
Provider is not responsible for outages, defects, price increases, feature changes, data loss, security incidents, support delays, policy changes, or end-of-life decisions caused by internet providers, utilities, cloud vendors, backup vendors, payment processors, Microsoft, Google, Huntress, or other third-party platforms.
When Provider acts as a procurement facilitator, Client remains bound by the third-party vendor’s end user terms, usage restrictions, and service limitations. Client remains financially responsible for approved third-party commitments, licenses, backup services, security tools, productivity platforms, and other pass-through costs that survive cancellation or have non-cancelable vendor terms.

12. Confidentiality
Each Party receiving Confidential Information from the other Party shall use that information only for performance of this Agreement, protect it with reasonable care, and not disclose it except to employees, contractors, advisers, insurers, or financing sources with a legitimate need to know and equivalent confidentiality obligations.
Confidential Information does not include information that is or becomes public through no breach of this Agreement, was already lawfully known, is independently developed without use of the other Party’s Confidential Information, or is rightfully received from a third party without confidentiality restrictions.
A Party may disclose Confidential Information if required by law, subpoena, court order, or regulatory process, provided the receiving Party gives prompt notice when legally permitted and reasonably cooperates with efforts to limit disclosure.

13. Data, Backup, Security, and Recovery
Provider may supply monitoring, security tooling, managed endpoint protection, backup oversight, restore assistance, disaster recovery planning assistance, and related best-practice services as stated in the applicable Order Form. Those services reduce risk but do not guarantee prevention of all malware, ransomware, phishing, account compromise, downtime, corruption, deletion, or data loss events.
Unless the applicable Order Form expressly states otherwise, Client remains responsible for determining what data is critical, what retention period is required, whether a tested business continuity plan is needed, what recovery point and recovery time objectives are acceptable, and whether additional offline, immutable, air-gapped, or legal-hold solutions are required.
Provider’s restore efforts are commercially reasonable assistance only. Successful restore, recovery, or continuity is dependent on the state of the source systems, backup integrity, third-party vendor performance, elapsed time, data corruption, scope of the incident, and factors outside Provider’s control.

14. Warranties; Disclaimer
Provider warrants that it will perform the Managed Services in a commercially reasonable manner consistent with generally accepted industry practice for remote managed service providers.
Except for the express warranty in the preceding sentence, the services are provided as is and as available, and Provider disclaims all implied warranties, including merchantability, fitness for a particular purpose, and non-infringement.
Provider does not warrant uninterrupted service, error-free operation, prevention of every security incident, or compatibility of every third-party application, driver, network, or vendor environment.

15. Limitation of Liability
Neither Party shall be liable to the other for any lost profits, lost revenue, loss of goodwill, indirect damages, consequential damages, exemplary damages, punitive damages, special damages, or diminution-in-value damages arising out of or related to this Agreement, even if advised of the possibility of such damages.
Provider’s aggregate liability arising out of or related to this Agreement shall not exceed the total fees actually paid by Client to Provider under the affected Order Form during the twelve (12) months immediately preceding the event giving rise to the claim.
The foregoing limitations do not limit: (a) Client’s payment obligations, (b) either Party’s liability for fraud, willful misconduct, or gross negligence to the extent such limitation is prohibited by law, (c) either Party’s confidentiality obligations, or (d) Client’s indemnity obligations under this Agreement.

16. Indemnification
Client shall defend, indemnify, and hold harmless Provider and its owners, employees, contractors, and agents from and against third-party claims, damages, losses, liabilities, judgments, settlements, costs, and reasonable attorney fees arising out of or related to: (a) Client’s data, content, or instructions; (b) Client’s unlawful, infringing, or non-compliant use of technology; (c) Client’s failure to maintain required licenses or permissions; (d) bodily injury or property damage caused by Client’s personnel or systems; or (e) Client’s breach of this Agreement.
Provider shall defend and indemnify Client from third-party claims alleging that any Provider-created deliverable specifically developed for Client under a separate written statement of work infringes a United States intellectual property right, except to the extent the claim arises from Client content, Client instructions, third-party products, combinations not supplied by Provider, or Client’s unauthorized modifications.
The indemnified Party shall promptly notify the indemnifying Party of the claim, reasonably cooperate in the defense, and permit the indemnifying Party to control the defense and settlement, provided no settlement admits fault or imposes non-monetary obligations on the indemnified Party without its consent.

17. Dispute Resolution; Governing Law; Venue
Before filing suit, the Parties shall first attempt in good faith to resolve any material dispute through business-level discussions between authorized representatives. This informal discussion period shall last at least ten (10) business days unless emergency injunctive relief is sought.
This Agreement is governed by the laws of the State of Indiana, without regard to its conflict-of-laws rules.
The Parties consent to exclusive jurisdiction and venue in the state courts located in Allen County, Indiana, and, if applicable, the federal court with jurisdiction there, for any dispute arising out of or related to this Agreement.

18. Miscellaneous
Neither Party may assign this Agreement without the other Party’s prior written consent, except to an affiliate or in connection with a merger, asset sale, or sale of substantially all of its business, provided the assignee assumes this Agreement in writing.
Provider may use subcontractors and third-party tools in performing the services, provided Provider remains responsible for its subcontractors’ performance of contracted obligations.
No waiver is effective unless in writing. Failure to enforce a provision once does not waive the right to enforce it later.
If any provision is held unenforceable, the remaining provisions remain in effect and shall be construed to best carry out the original commercial intent.
This Agreement, together with each Order Form and incorporated exhibit, constitutes the entire agreement between the Parties regarding its subject matter and supersedes prior proposals, discussions, and understandings.
The Parties agree that this Agreement and any Order Form may be executed electronically and in counterparts, each of which is deemed an original and all of which together constitute one instrument.
TEXT
        ],
        'SLA' => [
            'name' => 'SLA_v2_packet',
            'body' => <<<'TEXT'
Support Hours
Monday through Friday, 9:00 a.m. to 6:00 p.m. Eastern Time, excluding Provider-observed holidays, unless the applicable Order Form states otherwise.

Response Targets
- Critical: 1 business hour for initial acknowledgment and triage
- High: 4 business hours for initial acknowledgment and triage
- Normal: next business day for initial acknowledgment and triage

How to Request Support
Client shall submit support requests through Provider’s designated ticketing system, portal, email, or emergency contact method. Provider may require ticket creation before commencing non-emergency support work.

Scope and Expectations
Response Targets apply only to Provider’s initial acknowledgment and triage of a properly submitted support ticket during Support Hours. They are not guarantees of final resolution, service restoration, onsite dispatch, workaround delivery, or completion within a specific time.

Priority and Service Clock Tolling
Provider may assign or adjust ticket priority based on business impact, number of affected users, security implications, availability of a workaround, completeness of information supplied by Client, and whether the affected system is within the Covered Environment.
Any service clock is tolled while Provider is waiting on Client information, credentials, approvals, vendor action, replacement hardware, internet restoration, or access to the affected systems.

Out-of-Scope / Exclusions
Unless expressly included in the applicable Order Form, onsite labor, after-hours work, holiday work, project work, procurement, cabling, hardware installation or replacement, structured wiring, custom software development, compliance consulting, legal or regulatory advice, remediation of pre-existing conditions, unsupported or end-of-life systems, home networks, personal devices not listed in the Covered Environment, and line-of-business application support beyond ordinary vendor liaison are outside scope and separately billable.
TEXT
        ],
    ];
}

function accounting_contract_template_is_legacy(string $templateType, string $body): bool {
    $templateType = strtoupper(trim($templateType));
    $normalized = trim(preg_replace('/\s+/', ' ', $body));
    if ($normalized === '') {
        return true;
    }

    if ($templateType === 'MSA') {
        return strlen($normalized) < 3500 || (stripos($normalized, '1. Scope of Agreement') !== false && stripos($normalized, 'Order of Precedence') === false);
    }

    if ($templateType === 'SLA') {
        return strlen($normalized) < 900 || stripos($normalized, 'Critical: 1 business hour') !== false;
    }

    return false;
}

function accounting_contract_template_body(string $templateType): string {
    $templateType = strtoupper(trim($templateType));
    if (accounting_contract_template_ready()) {
        $st = db()->prepare('SELECT body FROM contract_template WHERE template_type = ? AND is_active = 1 ORDER BY template_id DESC LIMIT 1');
        $st->execute([$templateType]);
        $body = (string)($st->fetchColumn() ?: '');
        if ($body !== '' && !accounting_contract_template_is_legacy($templateType, $body)) {
            return $body;
        }
    }
    $defaults = accounting_contract_default_templates();
    return (string)($defaults[$templateType]['body'] ?? '');
}

function accounting_bundle_catalog_ready(): bool {
    return db_table_exists('service_bundle') && db_table_exists('service_bundle_item');
}

function accounting_bundle_item_role_options(): array {
    return ['INCLUDED', 'ADDON_OPTION', 'REQUIRED'];
}

function accounting_list_bundles(bool $activeOnly = false): array {
    if (!accounting_bundle_catalog_ready()) return [];
    $sql = "SELECT sb.*, ga.account_code, ga.account_name, si.item_name AS base_item_name, si.item_code AS base_item_code,
                   (SELECT COUNT(*) FROM service_bundle_item sbi WHERE sbi.bundle_id = sb.bundle_id) AS bundle_item_count
            FROM service_bundle sb
            LEFT JOIN gl_account ga ON ga.account_id = sb.revenue_account_id
            LEFT JOIN service_item si ON si.item_id = sb.base_item_id
            WHERE 1 = 1";
    if ($activeOnly) $sql .= ' AND sb.is_active = 1';
    $sql .= " ORDER BY CASE UPPER(sb.bundle_code) WHEN 'ESSENTIAL' THEN 1 WHEN 'SECURE' THEN 2 WHEN 'COMPLETE' THEN 3 ELSE 99 END, sb.bundle_name ASC";
    $st = db()->prepare($sql);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
        $row = accounting_apply_bundle_profile($row);
    }
    unset($row);
    return $rows;
}


function accounting_get_bundle(int $bundleId): ?array {
    if (!accounting_bundle_catalog_ready() || $bundleId <= 0) return null;
    $st = db()->prepare('SELECT * FROM service_bundle WHERE bundle_id = ? LIMIT 1');
    $st->execute([$bundleId]);
    $row = $st->fetch();
    return $row ? accounting_apply_bundle_profile($row) : null;
}

function accounting_get_bundle_by_code(string $bundleCode): ?array {
    if (!accounting_bundle_catalog_ready()) return null;
    $bundleCode = strtoupper(trim($bundleCode));
    if ($bundleCode === '') return null;
    $st = db()->prepare('SELECT * FROM service_bundle WHERE bundle_code = ? LIMIT 1');
    $st->execute([$bundleCode]);
    $row = $st->fetch();
    return $row ? accounting_apply_bundle_profile($row) : null;
}

function accounting_get_bundle_items(int $bundleId, ?string $role = null): array {
    if (!accounting_bundle_catalog_ready() || $bundleId <= 0) return [];
    $sql = "SELECT sbi.*, si.item_code, si.item_name, si.item_type, si.description AS item_description, si.pricing_model, si.billing_mode,
                   si.default_unit_price, si.default_billing_cycle, si.term_months, si.revenue_account_id, si.is_taxable
            FROM service_bundle_item sbi
            INNER JOIN service_item si ON si.item_id = sbi.item_id
            WHERE sbi.bundle_id = ?";
    $params = [$bundleId];
    if ($role !== null && in_array($role, accounting_bundle_item_role_options(), true)) {
        $sql .= ' AND sbi.item_role = ?';
        $params[] = $role;
    }
    $sql .= ' ORDER BY sbi.sort_order ASC, si.item_name ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_save_bundle(array $data, ?int $bundleId = null): array {
    if (!accounting_bundle_catalog_ready()) {
        return ['ok' => false, 'errors' => ['Bundle catalog tables are not installed yet. Run the service catalog engine SQL migration first.']];
    }
    $bundleCode = strtoupper(trim((string)($data['bundle_code'] ?? '')));
    $bundleName = trim((string)($data['bundle_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $pricingModel = strtoupper(trim((string)($data['pricing_model'] ?? 'FIXED')));
    $defaultUnitPrice = round((float)($data['default_unit_price'] ?? 0), 2);
    $billingCycle = strtoupper(trim((string)($data['default_billing_cycle'] ?? 'MONTHLY')));
    $termMonths = (int)($data['term_months'] ?? 0);
    $revenueAccountId = (int)($data['revenue_account_id'] ?? 0);
    $baseItemId = (int)($data['base_item_id'] ?? 0);
    $isTaxable = !empty($data['is_taxable']) ? 1 : 0;
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $includedIds = array_values(array_unique(array_map('intval', (array)($data['included_item_ids'] ?? []))));
    $addonIds = array_values(array_unique(array_map('intval', (array)($data['addon_item_ids'] ?? []))));

    $errors = [];
    $baseItem = accounting_get_catalog_item($baseItemId);
    if ($bundleCode === '') $errors[] = 'Bundle code is required.';
    if ($bundleName === '') $errors[] = 'Bundle name is required.';
    if (!in_array($pricingModel, accounting_get_pricing_models(), true)) $errors[] = 'Choose a valid pricing model.';
    if (!in_array($billingCycle, accounting_get_recurring_cycles(), true)) $errors[] = 'Choose a valid billing cycle.';
    if ($defaultUnitPrice < 0) $errors[] = 'Bundle price cannot be negative.';
    if ($revenueAccountId <= 0) $errors[] = 'Choose a revenue account.';
    if ($baseItemId <= 0) $errors[] = 'Choose the base catalog item for this bundle.';
    if ($baseItemId > 0 && !$baseItem) $errors[] = 'Selected base catalog item was not found.';
    if ($baseItem && accounting_service_category_ready() && empty($baseItem['is_bundle_base'])) $errors[] = 'Base catalog item must use a Bundle Base category.';
    if (accounting_service_category_ready()) {
        $includedAllowed = accounting_bundle_included_category_ids();
        $addonAllowed = accounting_bundle_addon_category_ids();
        foreach ($includedIds as $includedId) {
            $item = accounting_get_catalog_item((int)$includedId);
            if (!$item) { $errors[] = 'One or more included services could not be found.'; break; }
            if (!in_array((int)($item['category_id'] ?? 0), $includedAllowed, true)) {
                $errors[] = 'Included services must use bundle-eligible service categories.'; break;
            }
        }
        foreach ($addonIds as $addonId) {
            $item = accounting_get_catalog_item((int)$addonId);
            if (!$item) { $errors[] = 'One or more add-on services could not be found.'; break; }
            if (!in_array((int)($item['category_id'] ?? 0), $addonAllowed, true)) {
                $errors[] = 'Add-ons must use add-on eligible service categories.'; break;
            }
        }
    }
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $pdo = db();
    $dup = $pdo->prepare('SELECT bundle_id FROM service_bundle WHERE (bundle_code = ? OR bundle_name = ?)' . ($bundleId ? ' AND bundle_id <> ?' : '') . ' LIMIT 1');
    $params = [$bundleCode, $bundleName];
    if ($bundleId) $params[] = $bundleId;
    $dup->execute($params);
    if ($dup->fetch()) return ['ok' => false, 'errors' => ['Bundle code or name already exists.']];

    $pdo->beginTransaction();
    try {
        if ($bundleId) {
            $st = $pdo->prepare('UPDATE service_bundle SET bundle_code = ?, bundle_name = ?, description = ?, pricing_model = ?, default_unit_price = ?, default_billing_cycle = ?, term_months = ?, revenue_account_id = ?, base_item_id = ?, is_taxable = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE bundle_id = ?');
            $st->execute([$bundleCode, $bundleName, $description !== '' ? $description : null, $pricingModel, $defaultUnitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $revenueAccountId, $baseItemId, $isTaxable, $isActive, $bundleId]);
            $pdo->prepare('DELETE FROM service_bundle_item WHERE bundle_id = ?')->execute([$bundleId]);
        } else {
            $st = $pdo->prepare('INSERT INTO service_bundle (bundle_code, bundle_name, description, pricing_model, default_unit_price, default_billing_cycle, term_months, revenue_account_id, base_item_id, is_taxable, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$bundleCode, $bundleName, $description !== '' ? $description : null, $pricingModel, $defaultUnitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $revenueAccountId, $baseItemId, $isTaxable, $isActive]);
            $bundleId = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO service_bundle_item (bundle_id, item_id, item_role, default_quantity, override_unit_price, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        $sort = 10;
        $ins->execute([$bundleId, $baseItemId, 'REQUIRED', 1, null, $sort]);
        $sort += 10;
        foreach ($includedIds as $itemId) {
            if ($itemId <= 0 || $itemId === $baseItemId) continue;
            $ins->execute([$bundleId, $itemId, 'INCLUDED', 1, 0, $sort]);
            $sort += 10;
        }
        foreach ($addonIds as $itemId) {
            if ($itemId <= 0 || $itemId === $baseItemId || in_array($itemId, $includedIds, true)) continue;
            $ins->execute([$bundleId, $itemId, 'ADDON_OPTION', 1, null, $sort]);
            $sort += 10;
        }
        $pdo->commit();
        return ['ok' => true, 'bundle_id' => $bundleId, 'message' => 'Bundle saved.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Unable to save bundle: ' . $e->getMessage()]];
    }
}


function accounting_pricing_model_label(?string $model, bool $lowercase = false): string {
    $model = strtoupper(trim((string)$model));
    $labels = [
        'PER_USER' => $lowercase ? 'per user' : 'Per User',
        'PER_DEVICE' => $lowercase ? 'per device' : 'Per Device',
        'PER_LICENSE' => $lowercase ? 'per license' : 'Per License',
        'ONE_TIME' => $lowercase ? 'one-time' : 'One-Time',
        'FIXED' => $lowercase ? 'flat fee' : 'Flat Fee',
    ];
    return $labels[$model] ?? ($lowercase ? strtolower(str_replace('_', ' ', $model)) : ucwords(strtolower(str_replace('_', ' ', $model))));
}

function accounting_catalog_item_default_pricing_model(array $item): string {
    $explicit = strtoupper(trim((string)($item['pricing_model'] ?? '')));
    if (in_array($explicit, accounting_get_pricing_models(), true)) {
        return $explicit;
    }

    $code = strtoupper(trim((string)($item['item_code'] ?? '')));
    if (in_array($code, ['EP-BKUP', 'DSTR-RCVR', 'SRVR-MGMT', 'EPP-EDR', 'DNS-FLTR', 'SEC-MON', 'COMP-MON', 'MSP-ESS', 'MSP-SEC', 'MSP-COMP', 'SRVR-BK-500', 'SRVR-BK-1000', 'SRVR-BK-1500', 'SRVR-BK-2000'], true)) {
        return 'PER_DEVICE';
    }
    if (in_array($code, ['MS-365', 'GW-SEC', 'M365-BKUP', 'GW-BKUP', 'HUNT-ITDR', 'SAT-TRAIN'], true)) {
        return 'PER_USER';
    }
    return 'FIXED';
}


function accounting_complete_included_m365_users(): int {
    return 0;
}

function accounting_find_service_item_by_code(string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '' || !db_table_exists('service_item')) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM service_item WHERE item_code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ?: null;
}


function accounting_service_package_profiles(): array {
    return [
        'ESSENTIAL' => [
            'display_name' => 'Manage IT',
            'default_unit_price' => 85.00,
            'description' => 'Syncro-powered remote monitoring, patching, managed Microsoft Defender, remote helpdesk, asset tracking, client portal access, and monthly health visibility for small environments that need reliable day-to-day support on a per-device basis.',
            'included_services' => [
                'Syncro remote monitoring and maintenance',
                'Patch and update management',
                'Managed Microsoft Defender baseline',
                'Remote helpdesk support',
                'Asset tracking',
                'Client portal access',
                'Monthly health summary',
            ],
            'not_included_unless_selected' => [
                'Productivity platform and license',
                'Endpoint backup up to 250 GB per workstation',
                'Additional endpoint backup storage blocks beyond 250 GB per workstation',
                'SaaS backup for Microsoft 365 or Google Workspace',
                'Server management',
                'Server backup billed by protected storage tier',
                'Managed firewall / network security',
            ],
        ],
        'SECURE' => [
            'display_name' => 'Protect IT',
            'default_unit_price' => 145.00,
            'description' => 'Everything in Manage IT plus ScoutDNS filtering, Huntress managed EDR, Huntress ITDR, Defender for Business or equivalent managed protection coverage when applicable, endpoint backup up to 250 GB per workstation, SaaS backup for the selected Microsoft 365 or Google Workspace users, and a stronger protection stack for environments that need fuller day-to-day security coverage.',
            'included_services' => [
                'Everything in Manage IT',
                'ScoutDNS filtering',
                'Huntress Managed EDR',
                'Huntress ITDR',
                'Defender for Business or equivalent managed protection coverage when applicable',
                'Endpoint backup included up to 250 GB per workstation',
                'SaaS backup for the selected Microsoft 365 or Google Workspace users',
                'Microsoft 365 Business Basic or Google Workspace Business Starter included when a productivity platform is selected',
                'Enhanced security monitoring and escalation support',
                'Monthly security summary',
            ],
            'not_included_unless_selected' => [
                'Additional endpoint backup storage blocks beyond 250 GB per workstation',
                'Server management',
                'Server backup billed by protected storage tier',
                'Managed firewall / network security',
                'Productivity upgrades above the included base license',
            ],
        ],
        'COMPLETE' => [
            'display_name' => 'Govern IT',
            'default_unit_price' => 195.00,
            'description' => 'Everything in Protect IT plus Huntress SAT, harder endpoint and admin baselines, recovery readiness checks, security posture reporting, and stronger governance review for clients that need a tighter operational and compliance-oriented lane.',
            'included_services' => [
                'Everything in Protect IT',
                'Huntress SAT or equivalent',
                'Endpoint hardening baseline',
                'Device encryption management',
                'Hardening and privilege review',
                'Recovery readiness checks',
                'Monthly security posture / audit report',
                'Quarterly restore / recovery validation',
                'Priority incident handling',
                'Stronger policy / governance baseline',
                'Administrative best-practice review for Microsoft 365 or Google Workspace',
            ],
            'not_included_unless_selected' => [
                'Additional endpoint backup storage blocks beyond 250 GB per workstation',
                'Server management',
                'Server backup billed by protected storage tier',
                'Managed firewall / network security',
            ],
        ],
    ];
}

function accounting_bundle_profile_for_code(string $bundleCode): ?array {
    $profiles = accounting_service_package_profiles();
    $bundleCode = strtoupper(trim($bundleCode));
    return $profiles[$bundleCode] ?? null;
}

function accounting_apply_bundle_profile(array $bundle): array {
    $profile = accounting_bundle_profile_for_code((string)($bundle['bundle_code'] ?? ''));
    if (!$profile) {
        return $bundle;
    }
    $bundle['bundle_name'] = (string)$profile['display_name'];
    if (!isset($bundle['name']) || trim((string)$bundle['name']) === '' || in_array(trim((string)$bundle['name']), ['Essential IT', 'Secure IT', 'Complete IT'], true)) {
        $bundle['name'] = (string)$profile['display_name'];
    }
    if (isset($profile['default_unit_price'])) {
        $bundle['default_unit_price'] = (float)$profile['default_unit_price'];
    }
    $bundle['description'] = (string)$profile['description'];
    $bundle['included_services'] = (array)$profile['included_services'];
    $bundle['not_included_unless_selected'] = (array)($profile['not_included_unless_selected'] ?? []);
    return $bundle;
}

function accounting_productivity_catalog(): array {
    return [
        'NONE' => [
            'name' => 'No productivity platform',
            'licenses' => [],
        ],
        'M365' => [
            'name' => 'Microsoft 365',
            'licenses' => [
                'BASIC' => [
                    'item_code' => 'M365-BASIC',
                    'item_name' => 'Microsoft 365 Business Basic',
                    'default_unit_price' => 9.00,
                    'description' => 'Microsoft 365 Business Basic productivity licensing billed per managed user.',
                ],
                'STANDARD' => [
                    'item_code' => 'M365-STANDARD',
                    'item_name' => 'Microsoft 365 Business Standard',
                    'default_unit_price' => 18.00,
                    'description' => 'Microsoft 365 Business Standard productivity licensing billed per managed user.',
                ],
                'PREMIUM' => [
                    'item_code' => 'M365-PREMIUM',
                    'item_name' => 'Microsoft 365 Business Premium',
                    'default_unit_price' => 30.00,
                    'description' => 'Microsoft 365 Business Premium productivity licensing billed per managed user.',
                ],
            ],
        ],
        'GW' => [
            'name' => 'Google Workspace',
            'licenses' => [
                'STARTER' => [
                    'item_code' => 'GW-STARTER',
                    'item_name' => 'Google Workspace Business Starter',
                    'default_unit_price' => 10.00,
                    'description' => 'Google Workspace Business Starter productivity licensing billed per managed user.',
                ],
                'STANDARD' => [
                    'item_code' => 'GW-STANDARD',
                    'item_name' => 'Google Workspace Business Standard',
                    'default_unit_price' => 17.00,
                    'description' => 'Google Workspace Business Standard productivity licensing billed per managed user.',
                ],
                'PLUS' => [
                    'item_code' => 'GW-PLUS',
                    'item_name' => 'Google Workspace Business Plus',
                    'default_unit_price' => 25.00,
                    'description' => 'Google Workspace Business Plus productivity licensing billed per managed user.',
                ],
            ],
        ],
    ];
}

function accounting_productivity_included_base_license(string $packageCode, string $platform): string {
    $packageCode = strtoupper(trim($packageCode));
    $platform = strtoupper(trim($platform));
    if ($packageCode === 'SECURE') {
        if ($platform === 'M365') return 'BASIC';
        if ($platform === 'GW') return 'STARTER';
    }
    return 'NONE';
}

function accounting_productivity_license_options(string $packageCode, string $platform): array {
    $packageCode = strtoupper(trim($packageCode));
    $platform = strtoupper(trim($platform));

    if ($platform === 'M365') {
        if ($packageCode === 'COMPLETE') return ['PREMIUM'];
        if ($packageCode === 'SECURE') return ['NONE', 'STANDARD', 'PREMIUM'];
        return ['BASIC', 'STANDARD', 'PREMIUM'];
    }
    if ($platform === 'GW') {
        if ($packageCode === 'COMPLETE') return ['PLUS'];
        if ($packageCode === 'SECURE') return ['NONE', 'STANDARD', 'PLUS'];
        return ['STARTER', 'STANDARD', 'PLUS'];
    }
    return [];
}

function accounting_productivity_default_license(string $packageCode, string $platform): string {
    $packageCode = strtoupper(trim($packageCode));
    $platform = strtoupper(trim($platform));
    if ($packageCode === 'SECURE') {
        return 'NONE';
    }
    if ($platform === 'M365') {
        return $packageCode === 'COMPLETE' ? 'PREMIUM' : 'BASIC';
    }
    if ($platform === 'GW') {
        return $packageCode === 'COMPLETE' ? 'PLUS' : 'STARTER';
    }
    return 'NONE';
}

function accounting_normalize_productivity_selection(string $packageCode, string $platform, string $license): array {
    $platform = strtoupper(trim($platform));
    $license = strtoupper(trim($license));
    $catalog = accounting_productivity_catalog();
    if ($platform === '' || $platform === 'NONE' || !isset($catalog[$platform])) {
        return ['platform' => 'NONE', 'license' => 'NONE'];
    }
    $allowed = accounting_productivity_license_options($packageCode, $platform);
    if (!$allowed) {
        return ['platform' => $platform, 'license' => 'NONE'];
    }
    if ($license === '' || $license === 'NONE' || !in_array($license, $allowed, true)) {
        $license = accounting_productivity_default_license($packageCode, $platform);
    }
    if (!in_array($license, $allowed, true)) {
        $license = $allowed[0];
    }
    return ['platform' => $platform, 'license' => $license];
}

function accounting_find_or_upsert_service_item(array $template): array {
    $code = strtoupper(trim((string)($template['item_code'] ?? '')));
    if ($code === '') {
        throw new RuntimeException('Missing service item code.');
    }

    $existing = accounting_find_service_item_by_code($code);
    $revenueAccountId = (int)($template['revenue_account_id'] ?? (accounting_find_account_id_by_code('4000') ?? 0));
    $categoryId = (int)($template['category_id'] ?? 0);

    if ($existing) {
        $pdo = db();
        $pdo->prepare('UPDATE service_item SET item_name = ?, description = ?, default_unit_price = ?, pricing_model = ?, billing_mode = ?, default_billing_cycle = ?, term_months = ?, revenue_account_id = ?, category_id = ?, is_taxable = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE item_id = ?')
            ->execute([
                (string)$template['item_name'],
                (string)$template['description'],
                (float)$template['default_unit_price'],
                (string)($template['pricing_model'] ?? 'PER_USER'),
                (string)($template['billing_mode'] ?? 'RECURRING'),
                (string)($template['default_billing_cycle'] ?? 'MONTHLY'),
                (int)($template['term_months'] ?? 12),
                $revenueAccountId,
                $categoryId,
                !empty($template['is_taxable']) ? 1 : 0,
                1,
                (int)$existing['item_id'],
            ]);
        return accounting_find_service_item_by_code($code) ?: $existing;
    }

    $pdo = db();
    $pdo->prepare('INSERT INTO service_item (item_code, item_name, item_type, description, default_unit_price, pricing_model, billing_mode, default_billing_cycle, term_months, revenue_account_id, category_id, is_taxable, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([
            $code,
            (string)$template['item_name'],
            (string)($template['item_type'] ?? 'SERVICE'),
            (string)$template['description'],
            (float)$template['default_unit_price'],
            (string)($template['pricing_model'] ?? 'PER_USER'),
            (string)($template['billing_mode'] ?? 'RECURRING'),
            (string)($template['default_billing_cycle'] ?? 'MONTHLY'),
            (int)($template['term_months'] ?? 12),
            $revenueAccountId,
            $categoryId,
            !empty($template['is_taxable']) ? 1 : 0,
        ]);
    return accounting_find_service_item_by_code($code) ?: [];
}

function accounting_ensure_productivity_service_items(): array {
    if (!db_table_exists('service_item')) {
        return [];
    }

    $revenueAccountId = (int)(accounting_find_account_id_by_code('4000') ?? 0);
    $backupCategory = accounting_find_service_item_by_code('EP-BKUP');
    $backupCategoryId = (int)($backupCategory['category_id'] ?? 0);
    $adminCategory = accounting_find_service_item_by_code('MS-365');
    $adminCategoryId = (int)($adminCategory['category_id'] ?? 0);

    $templates = [];
    foreach (accounting_productivity_catalog() as $platform) {
        foreach ((array)($platform['licenses'] ?? []) as $license) {
            $templates[] = [
                'item_code' => (string)$license['item_code'],
                'item_name' => (string)$license['item_name'],
                'item_type' => 'SERVICE',
                'description' => (string)$license['description'],
                'default_unit_price' => (float)$license['default_unit_price'],
                'pricing_model' => 'PER_USER',
                'billing_mode' => 'RECURRING',
                'default_billing_cycle' => 'MONTHLY',
                'term_months' => 12,
                'revenue_account_id' => $revenueAccountId,
                'category_id' => $adminCategoryId,
                'is_taxable' => 0,
            ];
        }
    }

    $templates[] = [
        'item_code' => 'DNS-FLTR',
        'item_name' => 'DNS Filtering',
        'item_type' => 'SERVICE',
        'description' => 'Managed DNS filtering to block phishing, malware, ransomware, and unsafe websites for covered devices.',
        'default_unit_price' => 4.00,
        'pricing_model' => 'PER_DEVICE',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'EP-BKUP',
        'item_name' => 'Endpoint Backup + Recovery (Up to 250 GB)',
        'item_type' => 'SERVICE',
        'description' => 'Managed endpoint backup with restore assistance and recovery support for protected workstations, including up to 250 GB of compressed cloud storage per workstation.',
        'default_unit_price' => 15.00,
        'pricing_model' => 'PER_DEVICE',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'EP-BKUP-X150',
        'item_name' => 'Endpoint Backup Storage Extension (+150 GB)',
        'item_type' => 'SERVICE',
        'description' => 'Additional endpoint backup storage block covering each extra 150 GB above the included 250 GB per protected workstation.',
        'default_unit_price' => 15.00,
        'pricing_model' => 'FIXED',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'M365-BKUP',
        'item_name' => 'Microsoft 365 SaaS Backup',
        'item_type' => 'SERVICE',
        'description' => 'Managed Microsoft 365 backup covering Exchange, OneDrive, SharePoint, and Teams for protected users.',
        'default_unit_price' => 6.00,
        'pricing_model' => 'PER_USER',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'GW-BKUP',
        'item_name' => 'Google Workspace SaaS Backup',
        'item_type' => 'SERVICE',
        'description' => 'Managed Google Workspace backup for protected users, including Gmail and shared collaboration data where protected by the selected backup scope.',
        'default_unit_price' => 6.00,
        'pricing_model' => 'PER_USER',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];
    $templates[] = [
        'item_code' => 'SRVR-BK-500',
        'item_name' => 'Server Backup - Up to 500 GB',
        'item_type' => 'SERVICE',
        'description' => 'Managed server backup with recovery support for a protected server, including up to 500 GB of compressed protected storage.',
        'default_unit_price' => 59.00,
        'pricing_model' => 'PER_DEVICE',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'SRVR-BK-1000',
        'item_name' => 'Server Backup - 501 GB to 1 TB',
        'item_type' => 'SERVICE',
        'description' => 'Managed server backup with recovery support for a protected server using 501 GB to 1 TB of compressed protected storage.',
        'default_unit_price' => 99.00,
        'pricing_model' => 'PER_DEVICE',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'SRVR-BK-1500',
        'item_name' => 'Server Backup - 1 TB to 1.5 TB',
        'item_type' => 'SERVICE',
        'description' => 'Managed server backup with recovery support for a protected server using more than 1 TB and up to 1.5 TB of compressed protected storage.',
        'default_unit_price' => 149.00,
        'pricing_model' => 'PER_DEVICE',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $templates[] = [
        'item_code' => 'SRVR-BK-2000',
        'item_name' => 'Server Backup - 1.5 TB to 2 TB',
        'item_type' => 'SERVICE',
        'description' => 'Managed server backup with recovery support for a protected server using more than 1.5 TB and up to 2 TB of compressed protected storage.',
        'default_unit_price' => 199.00,
        'pricing_model' => 'PER_DEVICE',
        'billing_mode' => 'RECURRING',
        'default_billing_cycle' => 'MONTHLY',
        'term_months' => 12,
        'revenue_account_id' => $revenueAccountId,
        'category_id' => $backupCategoryId,
        'is_taxable' => 0,
    ];

    $results = [];
    foreach ($templates as $template) {
        $item = accounting_find_or_upsert_service_item($template);
        if (!empty($item['item_code'])) {
            $results[(string)$item['item_code']] = $item;
        }
    }

    $legacyCodes = ['DSTR-RCVR', 'SRVR-BKUP', 'SRVR-BK-FILE', 'SRVR-BK-STD', 'SRVR-BK-SQL', 'SRVR-BK-VMSOCKET', 'SRVR-BK-VMHOST'];
    $legacyCodes = array_values(array_filter($legacyCodes, static fn(string $code): bool => !isset($results[$code])));
    if ($legacyCodes) {
        $placeholders = implode(',', array_fill(0, count($legacyCodes), '?'));
        db()->prepare("UPDATE service_item SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE item_code IN ($placeholders)")->execute($legacyCodes);
    }

    return $results;
}

function accounting_productivity_selection_details(array $services): array {
    $catalog = accounting_productivity_catalog();
    $lookup = [];
    foreach ($catalog as $platformCode => $platform) {
        foreach ((array)($platform['licenses'] ?? []) as $licenseCode => $license) {
            $lookup[(string)$license['item_code']] = [
                'platform_code' => $platformCode,
                'platform_name' => (string)$platform['name'],
                'license_code' => $licenseCode,
                'license_name' => (string)$license['item_name'],
            ];
        }
    }

    foreach ($services as $svc) {
        $code = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
        if ($code !== '' && isset($lookup[$code])) {
            return array_merge($lookup[$code], [
                'quantity' => (float)($svc['quantity'] ?? 0),
                'unit_price' => (float)($svc['unit_price'] ?? 0),
                'description' => (string)($svc['description'] ?? $svc['service_name'] ?? ''),
            ]);
        }
    }

    return [
        'platform_code' => 'NONE',
        'platform_name' => 'No productivity platform selected',
        'license_code' => 'NONE',
        'license_name' => 'None selected',
        'quantity' => 0.0,
        'unit_price' => 0.0,
        'description' => '',
    ];
}

function accounting_not_included_unless_selected(array $package, array $services): array {
    $items = (array)($package['not_included_unless_selected'] ?? []);
    $selectedCodes = [];
    foreach ($services as $svc) {
        $selectedCodes[] = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
    }

    if (in_array('EP-BKUP', $selectedCodes, true)) {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'Endpoint backup') === false));
    }
    if (in_array('M365-BKUP', $selectedCodes, true) || in_array('GW-BKUP', $selectedCodes, true)) {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'SaaS backup') === false));
    }
    if (in_array('EP-BKUP-X150', $selectedCodes, true)) {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'storage blocks') === false));
    }
    if (in_array('SRVR-MGMT', $selectedCodes, true)) {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'Server management') === false));
    }
    if (array_filter($selectedCodes, static fn(string $code): bool => str_starts_with($code, 'SRVR-BK-'))) {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'Server backup') === false));
    }
    if (in_array('FW-NETSEC', $selectedCodes, true)) {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'firewall') === false));
    }

    $productivity = accounting_productivity_selection_details($services);
    if (($productivity['platform_code'] ?? 'NONE') !== 'NONE') {
        $items = array_values(array_filter($items, static fn(string $label): bool => stripos($label, 'Productivity platform') === false));
    }

    return array_values(array_unique($items));
}

function accounting_service_packages(): array {
    accounting_ensure_productivity_service_items();
    $profiles = accounting_service_package_profiles();
    $allowedAddonCodes = ['DNS-FLTR', 'EP-BKUP', 'EP-BKUP-X150', 'M365-BKUP', 'GW-BKUP', 'SAT-TRAIN', 'SRVR-MGMT', 'SRVR-BK-500', 'SRVR-BK-1000', 'SRVR-BK-1500', 'SRVR-BK-2000', 'FW-NETSEC'];
    $addonSort = array_flip($allowedAddonCodes);

    $catalogAddons = [];
    foreach ($allowedAddonCodes as $code) {
        $item = accounting_find_service_item_by_code($code);
        if (!$item) {
            continue;
        }
        $catalogAddons[$code] = [
            'item_id' => (int)$item['item_id'],
            'item_code' => (string)$item['item_code'],
            'item_name' => (string)$item['item_name'],
            'description' => (string)($item['description'] ?: $item['item_name']),
            'default_unit_price' => (float)($item['default_unit_price'] ?? 0),
            'pricing_model' => accounting_catalog_item_default_pricing_model($item),
            'billing_cycle' => (string)($item['default_billing_cycle'] ?? 'MONTHLY'),
            'term_months' => (int)($item['term_months'] ?? 0),
            'revenue_account_id' => (int)($item['revenue_account_id'] ?? 0),
            'taxable' => !empty($item['is_taxable']),
        ];
    }

    if (accounting_bundle_catalog_ready()) {
        $packages = [];
        foreach (accounting_list_bundles(true) as $bundle) {
            $bundleCode = (string)($bundle['bundle_code'] ?? '');
            $profile = $profiles[$bundleCode] ?? null;
            if (!$profile) {
                continue;
            }

            $package = [
                'bundle_id' => (int)$bundle['bundle_id'],
                'code' => $bundleCode,
                'name' => (string)$profile['display_name'],
                'default_unit_price' => (float)$bundle['default_unit_price'],
                'pricing_model' => (string)$bundle['pricing_model'],
                'description' => (string)$profile['description'],
                'included_services' => (array)$profile['included_services'],
                'addon_services' => array_values($catalogAddons),
                'default_billing_cycle' => (string)($bundle['default_billing_cycle'] ?: 'MONTHLY'),
                'term_months' => (int)($bundle['term_months'] ?? 0),
                'base_item_id' => (int)($bundle['base_item_id'] ?? 0),
                'revenue_account_id' => (int)($bundle['revenue_account_id'] ?? 0),
                'is_taxable' => !empty($bundle['is_taxable']),
                'not_included_unless_selected' => (array)($profile['not_included_unless_selected'] ?? []),
            ];
            usort($package['addon_services'], static function (array $a, array $b) use ($addonSort): int {
                $left = $addonSort[strtoupper((string)($a['item_code'] ?? ''))] ?? 999;
                $right = $addonSort[strtoupper((string)($b['item_code'] ?? ''))] ?? 999;
                return $left <=> $right;
            });
            $packages[$bundleCode] = $package;
        }

        if ($packages) {
            foreach ($packages as $code => $package) {
                $packages[$code]['included_services'] = accounting_expand_package_service_labels((array)$package['included_services'], $packages);
            }
            return $packages;
        }
    }

    $defaultRevenue = (int)(accounting_find_account_id_by_code('4000') ?? 0);
    $defaults = [
        'ESSENTIAL' => 85.00,
        'SECURE' => 145.00,
        'COMPLETE' => 195.00,
    ];
    $packages = [];
    foreach ($profiles as $code => $profile) {
        $packages[$code] = [
            'code' => $code,
            'name' => (string)$profile['display_name'],
            'default_unit_price' => (float)($defaults[$code] ?? 0),
            'pricing_model' => 'PER_DEVICE',
            'description' => (string)$profile['description'],
            'included_services' => (array)$profile['included_services'],
            'addon_services' => array_values($catalogAddons),
            'default_billing_cycle' => 'MONTHLY',
            'term_months' => 12,
            'base_item_id' => 0,
            'revenue_account_id' => $defaultRevenue,
            'is_taxable' => false,
            'not_included_unless_selected' => (array)($profile['not_included_unless_selected'] ?? []),
        ];
    }

    foreach ($packages as $code => $package) {
        $packages[$code]['included_services'] = accounting_expand_package_service_labels((array)$package['included_services'], $packages);
    }

    return $packages;
}

function accounting_expand_package_service_labels(array $labels, array $packages, array $seen = []): array {
    $expanded = [];

    foreach ($labels as $label) {
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }

        if (preg_match('/^Everything in\s+(.+)$/i', $label, $m)) {
            $target = strtoupper(trim((string)$m[1]));
            $target = preg_replace('/\s+SERVICE$/i', '', $target);
            $target = preg_replace('/\s+BUNDLE$/i', '', $target);

            $matchedCode = null;
            foreach ($packages as $code => $package) {
                $codeUpper = strtoupper((string)$code);
                $nameUpper = strtoupper(trim((string)($package['name'] ?? '')));
                $nameWithIt = strtoupper(trim($nameUpper . ' IT'));

                if ($target === $codeUpper || $target === $nameUpper || $target === $nameWithIt) {
                    $matchedCode = (string)$code;
                    break;
                }
            }

            if ($matchedCode !== null && !in_array($matchedCode, $seen, true)) {
                $childSeen = $seen;
                $childSeen[] = $matchedCode;

                $nested = accounting_expand_package_service_labels(
                    (array)($packages[$matchedCode]['included_services'] ?? []),
                    $packages,
                    $childSeen
                );

                foreach ($nested as $nestedLabel) {
                    if (!in_array($nestedLabel, $expanded, true)) {
                        $expanded[] = $nestedLabel;
                    }
                }
                continue;
            }
        }

        if (!in_array($label, $expanded, true)) {
            $expanded[] = $label;
        }
    }

    return $expanded;
}

function accounting_expand_contract_service_rows(array $services): array {
    $packages = accounting_service_packages();
    $expanded = [];

    foreach ($services as $svc) {
        $name = trim((string)($svc['service_name'] ?? ''));
        $isIncluded = !empty($svc['is_included']);

        if ($isIncluded && preg_match('/^Everything in\s+(.+)$/i', $name)) {
            $labels = accounting_expand_package_service_labels([$name], $packages);

            foreach ($labels as $label) {
                $copy = $svc;
                $copy['service_name'] = $label;
                $copy['description'] = $label;
                $expanded[] = $copy;
            }
            continue;
        }

        $expanded[] = $svc;
    }

    return $expanded;
}

function accounting_contract_term_options(): array {
    return [0 => 'Month-to-month', 12 => '12 months', 24 => '24 months', 36 => '36 months'];
}

function accounting_contract_prefix(array $client): string {
    $prefix = accounting_client_invoice_prefix($client);
    if ($prefix === '') {
        $prefix = 'CLIENT';
    }
    if ($prefix === 'MMI') {
        return 'MMI';
    }
    return $prefix . '-CNT';
}

function accounting_next_contract_number(PDO $pdo, int $clientId): string {
    $client = accounting_get_client($clientId);
    if (!$client) {
        throw new RuntimeException('Client not found for contract numbering.');
    }
    $prefix = accounting_contract_prefix($client) . '-';
    $st = $pdo->prepare("SELECT contract_number FROM contract WHERE client_id = ? AND contract_number LIKE ? ORDER BY contract_id DESC LIMIT 1");
    $st->execute([$clientId, $prefix . '%']);
    $last = (string)($st->fetchColumn() ?: '');
    $seq = 1;
    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{3,})$/', $last, $m)) {
        $seq = ((int)$m[1]) + 1;
    }
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

function accounting_contract_summary(): array {
    if (!accounting_contract_ready()) return ['active_count' => 0, 'draft_count' => 0, 'mrr_value' => 0.0, 'expiring_count' => 0];
    $pdo = db();
    return [
        'active_count' => (int)$pdo->query("SELECT COUNT(*) FROM contract WHERE status = 'ACTIVE'")->fetchColumn(),
        'draft_count' => (int)$pdo->query("SELECT COUNT(*) FROM contract WHERE status = 'DRAFT'")->fetchColumn(),
        'mrr_value' => (float)$pdo->query("SELECT COALESCE(SUM(base_amount),0) FROM contract WHERE status IN ('ACTIVE','DRAFT') AND billing_cycle = 'MONTHLY'")->fetchColumn(),
        'expiring_count' => (int)$pdo->query("SELECT COUNT(*) FROM contract WHERE status IN ('ACTIVE','DRAFT') AND end_date IS NOT NULL AND end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn(),
    ];
}

function accounting_list_contracts(int $limit = 200, ?int $clientId = null): array {
    if (!accounting_contract_ready()) return [];
    $limit = max(1, min(500, $limit));
    $where = '';
    $params = [];
    if ($clientId !== null && $clientId > 0) {
        $where = 'WHERE ctr.client_id = ?';
        $params[] = $clientId;
    }
    $sql = "SELECT ctr.*, c.client_code, c.legal_name, c.dba_name,
                   (SELECT COUNT(*) FROM client_service cs WHERE cs.contract_id = ctr.contract_id) AS service_count,
                   (SELECT COALESCE(SUM(CASE WHEN cs.status = 'ACTIVE' THEN cs.quantity * cs.unit_price ELSE 0 END),0) FROM client_service cs WHERE cs.contract_id = ctr.contract_id) AS active_service_value
            FROM contract ctr
            INNER JOIN clients c ON c.client_id = ctr.client_id
            {$where}
            ORDER BY ctr.start_date DESC, ctr.contract_id DESC
            LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function accounting_get_contract(int $contractId): ?array {
    if (!accounting_contract_ready() || $contractId <= 0) return null;
    $sql = "SELECT ctr.*, c.client_code, c.legal_name, c.dba_name, c.email AS client_email, c.phone AS client_phone,
                   cc.first_name, cc.last_name, cc.email AS contact_email, cc.phone AS contact_phone,
                   cl.location_name, cl.address1, cl.address2, cl.city, cl.state, cl.postal_code, cl.country,
                   (SELECT COALESCE(SUM(CASE WHEN cs.status = 'ACTIVE' THEN cs.quantity * cs.unit_price ELSE 0 END),0) FROM client_service cs WHERE cs.contract_id = ctr.contract_id) AS active_service_value
            FROM contract ctr
            INNER JOIN clients c ON c.client_id = ctr.client_id
            LEFT JOIN client_contact cc ON cc.client_id = ctr.client_id AND cc.is_primary = 1
            LEFT JOIN client_location cl ON cl.client_id = ctr.client_id AND cl.is_primary = 1
            WHERE ctr.contract_id = ? LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$contractId]);
    return $st->fetch() ?: null;
}

function accounting_get_contract_services(int $contractId): array {
    $sql = "SELECT cs.*, ga.account_code, ga.account_name
            FROM contract_service cs
            LEFT JOIN gl_account ga ON ga.account_id = (SELECT revenue_account_id FROM service_item WHERE item_code = cs.service_code LIMIT 1)
            WHERE cs.contract_id = ?
            ORDER BY cs.sort_order, cs.contract_service_id";
    $st = db()->prepare($sql);
    $st->execute([$contractId]);
    return $st->fetchAll();
}


function accounting_group_contract_services(array $services): array {
    $groups = [];
    $standalone = ['bundle_id' => 0, 'bundle_name' => 'Additional Services', 'base' => null, 'included' => [], 'addons' => []];

    foreach ($services as $svc) {
        $bundleId = (int)($svc['bundle_id'] ?? 0);
        $isIncluded = !empty($svc['is_included']);

        if ($bundleId > 0) {
            if (!isset($groups[$bundleId])) {
                $groups[$bundleId] = [
                    'bundle_id' => $bundleId,
                    'bundle_name' => (string)($svc['service_name'] ?? 'Service Bundle'),
                    'base' => null,
                    'included' => [],
                    'addons' => [],
                ];
            }

            if ($isIncluded) {
                $groups[$bundleId]['included'][] = $svc;
                continue;
            }

            if ($groups[$bundleId]['base'] === null) {
                $groups[$bundleId]['base'] = $svc;
                $groups[$bundleId]['bundle_name'] = (string)($svc['service_name'] ?: $groups[$bundleId]['bundle_name']);
            } else {
                $groups[$bundleId]['addons'][] = $svc;
            }
            continue;
        }

        if ($isIncluded) {
            $standalone['included'][] = $svc;
        } elseif ($standalone['base'] === null) {
            $standalone['base'] = $svc;
            $standalone['bundle_name'] = (string)($svc['service_name'] ?: 'Additional Services');
        } else {
            $standalone['addons'][] = $svc;
        }
    }

    $result = array_values($groups);
    if ($standalone['base'] || $standalone['included'] || $standalone['addons']) {
        $result[] = $standalone;
    }
    return $result;
}

function accounting_contract_included_services_summary(int $contractId): array {
    $services = accounting_expand_contract_service_rows(accounting_get_contract_services($contractId));
    $groups = accounting_group_contract_services($services);
    $summary = [];
    foreach ($groups as $group) {
        $summary[] = [
            'bundle_name' => (string)($group['bundle_name'] ?? 'Service Bundle'),
            'included' => $group['included'] ?? [],
        ];
    }
    return $summary;
}

function accounting_contract_client_services(int $contractId): array {
    $sql = "SELECT cs.*, si.item_code, si.item_name FROM client_service cs LEFT JOIN service_item si ON si.item_id = cs.item_id WHERE cs.contract_id = ? ORDER BY cs.created_at ASC, cs.client_service_id ASC";
    $st = db()->prepare($sql);
    $st->execute([$contractId]);
    return $st->fetchAll();
}

function accounting_contract_invoices(int $contractId): array {
    $st = db()->prepare("SELECT invoice_id, invoice_number, invoice_date, due_date, status, total_amount, balance_due FROM customer_invoice WHERE contract_id = ? ORDER BY invoice_date DESC, invoice_id DESC");
    $st->execute([$contractId]);
    return $st->fetchAll();
}


function accounting_contract_onboarding_ready(): bool {
    return db_table_exists('contract_onboarding_task');
}

function accounting_contract_selected_item_codes(int $contractId): array {
    $rows = accounting_get_contract_services($contractId);
    $codes = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)($row['service_code'] ?? '')));
        if ($code !== '') $codes[$code] = true;
        if (!empty($row['item_id'])) {
            $item = accounting_get_catalog_item((int)$row['item_id']);
            $itemCode = strtoupper(trim((string)($item['item_code'] ?? '')));
            if ($itemCode !== '') $codes[$itemCode] = true;
        }
    }
    return array_keys($codes);
}


function accounting_contract_detect_package_lane(array $contract, array $codeSet): string {
    $sla = strtoupper(trim((string)($contract['sla_level'] ?? '')));
    $contractName = strtoupper(trim((string)($contract['contract_name'] ?? '')));
    $statusMap = [
        'MSP-COMP' => 'COMPLETE',
        'MSP-COMPLETE' => 'COMPLETE',
        'MSP-SEC' => 'SECURE',
        'MSP-SECURE' => 'SECURE',
        'MSP-ESS' => 'ESSENTIAL',
        'MSP-ESSENTIAL' => 'ESSENTIAL',
    ];
    foreach ($statusMap as $code => $lane) {
        if (isset($codeSet[$code])) return $lane;
    }
    if (in_array($sla, ['GOVERN IT', 'COMPLETE', 'COMPLETE IT'], true) || str_contains($contractName, 'GOVERN IT')) {
        return 'COMPLETE';
    }
    if (in_array($sla, ['PROTECT IT', 'SECURE', 'SECURE IT'], true) || str_contains($contractName, 'PROTECT IT')) {
        return 'SECURE';
    }
    return 'ESSENTIAL';
}

function accounting_contract_detect_productivity_platform(array $codeSet): string {
    foreach (['M365-BASIC', 'M365-STANDARD', 'M365-PREMIUM', 'M365-BKUP', 'MS-365'] as $code) {
        if (isset($codeSet[$code])) return 'M365';
    }
    foreach (['GW-STARTER', 'GW-STANDARD', 'GW-PLUS', 'GW-BKUP', 'GW-SEC'] as $code) {
        if (isset($codeSet[$code])) return 'GW';
    }
    return 'NONE';
}

function accounting_contract_managed_onboarding_task_codes(): array {
    return [
        'PRIMARY_CONTACT_READY',
        'SYNCRO_ORG_READY',
        'SYNCRO_BASELINE_READY',
        'DEVICE_AGENTS_DEPLOYED',
        'PORTAL_ACCESS_READY',
        'SECURITY_STACK_READY',
        'BACKUP_READY',
        'CLOUD_TENANT_READY',
        'SERVER_MANAGEMENT_READY',
        'SERVER_BACKUP_READY',
        'NETWORK_SECURITY_READY',
        'HUNTRESS_READY',
        'DNS_FILTERING_READY',
        'SAAS_BACKUP_READY',
        'SAT_READY',
        'GOVERNANCE_BASELINE_READY',
        'RECOVERY_VALIDATION_READY',
        'GO_LIVE_APPROVED',
    ];
}

function accounting_contract_default_onboarding_tasks(int $contractId): array {
    $contract = accounting_get_contract($contractId);
    if (!$contract) return [];

    $codes = accounting_contract_selected_item_codes($contractId);
    $codeSet = array_fill_keys($codes, true);
    $packageLane = accounting_contract_detect_package_lane($contract, $codeSet);
    $platform = accounting_contract_detect_productivity_platform($codeSet);

    $hasEndpointBackup = isset($codeSet['EP-BKUP']) || in_array($packageLane, ['SECURE', 'COMPLETE'], true);
    $hasSaasBackup = isset($codeSet['M365-BKUP']) || isset($codeSet['GW-BKUP']) || (in_array($packageLane, ['SECURE', 'COMPLETE'], true) && $platform !== 'NONE');
    $hasAnyBackup = $hasEndpointBackup || $hasSaasBackup || array_filter(array_keys($codeSet), static fn(string $code): bool => str_starts_with($code, 'SRVR-BK-'));
    $hasCloudTenant = $platform !== 'NONE' || isset($codeSet['HUNT-ITDR']);
    $hasServerMgmt = isset($codeSet['SRVR-MGMT']);
    $hasServerBackup = (bool)array_filter(array_keys($codeSet), static fn(string $code): bool => str_starts_with($code, 'SRVR-BK-'));
    $hasNetworkSecurity = isset($codeSet['FW-NETSEC']);
    $hasHuntress = isset($codeSet['SEC-MON']) || isset($codeSet['HUNT-ITDR']) || in_array($packageLane, ['SECURE', 'COMPLETE'], true);
    $hasDnsFiltering = isset($codeSet['DNS-FLTR']) || in_array($packageLane, ['SECURE', 'COMPLETE'], true);
    $hasSat = isset($codeSet['SAT-TRAIN']) || $packageLane === 'COMPLETE';

    $platformLabel = $platform === 'GW' ? 'Google Workspace' : 'Microsoft 365';
    $governanceLabel = $platform === 'GW' ? 'Google Workspace admin and sharing baseline' : 'Microsoft 365 / Entra admin and security baseline';
    $backupLabelParts = [];
    if ($hasEndpointBackup) $backupLabelParts[] = 'N-able Cove endpoint protection';
    if ($hasServerBackup) $backupLabelParts[] = 'server backup coverage';
    if ($hasSaasBackup) $backupLabelParts[] = $platformLabel . ' backup coverage';
    $backupLabel = $backupLabelParts ? implode(', ', $backupLabelParts) : 'backup coverage';

    $tasks = [
        ['code' => 'PRIMARY_CONTACT_READY', 'name' => 'Primary and billing contacts confirmed', 'detail' => 'Verify the day-to-day contact, billing contact, email addresses, phone numbers, and approval path before service begins.', 'required' => 1, 'sort' => 10],
        ['code' => 'SYNCRO_ORG_READY', 'name' => 'Syncro organization created', 'detail' => 'Push the client into Syncro and confirm the company record is ready for device deployment, policies, and ticket routing.', 'required' => 1, 'sort' => 20],
        ['code' => 'SYNCRO_BASELINE_READY', 'name' => 'Syncro policies and monitoring baseline applied', 'detail' => 'Confirm the core Syncro policy stack, patching lane, alerting, tray workflow, and asset standards are applied for the chosen package.', 'required' => 1, 'sort' => 30],
        ['code' => 'DEVICE_AGENTS_DEPLOYED', 'name' => 'Covered devices checking in', 'detail' => 'Install the Syncro agent on the covered endpoints and verify the contracted workstation or server counts are reporting cleanly.', 'required' => 1, 'sort' => 40],
        ['code' => 'PORTAL_ACCESS_READY', 'name' => 'Client portal and billing access tested', 'detail' => 'Send the client admin invite, confirm the branded portal access lane works, and verify the billing contact can receive invoice mail.', 'required' => 1, 'sort' => 45],
        ['code' => 'SECURITY_STACK_READY', 'name' => 'Managed Microsoft Defender baseline verified', 'detail' => 'Confirm the managed Microsoft Defender baseline, policy enforcement, and healthy device state match the contracted ' . ($packageLane === 'ESSENTIAL' ? 'Manage IT' : ($packageLane === 'SECURE' ? 'Protect IT' : 'Govern IT')) . ' lane.', 'required' => 1, 'sort' => 50],
    ];

    if ($hasCloudTenant) {
        $tasks[] = ['code' => 'CLOUD_TENANT_READY', 'name' => $platformLabel . ' tenant access confirmed', 'detail' => 'Verify admin access, licensing, domain state, and handoff details for the ' . $platformLabel . ' tenant tied to this client.', 'required' => 1, 'sort' => 55];
    }
    if ($hasDnsFiltering) {
        $tasks[] = ['code' => 'DNS_FILTERING_READY', 'name' => 'ScoutDNS filtering configured', 'detail' => 'Confirm ScoutDNS policies, roaming coverage or network forwarding, and reporting are aligned to the client devices and sites in scope.', 'required' => 1, 'sort' => 60];
    }
    if ($hasHuntress) {
        $tasks[] = ['code' => 'HUNTRESS_READY', 'name' => 'Huntress protection stack verified', 'detail' => 'Confirm Huntress managed EDR and identity coverage are attached where contracted, alerts are flowing, and escalation contacts are correct.', 'required' => 1, 'sort' => 70];
    }
    if ($hasAnyBackup) {
        $tasks[] = ['code' => 'BACKUP_READY', 'name' => 'N-able Cove backup configured and healthy', 'detail' => 'Confirm ' . $backupLabel . ' is configured in Cove and that the initial protection job or first healthy status is visible.', 'required' => 1, 'sort' => 80];
    }
    if ($hasSaasBackup) {
        $tasks[] = ['code' => 'SAAS_BACKUP_READY', 'name' => $platformLabel . ' backup scope verified', 'detail' => 'Verify mailbox, OneDrive / SharePoint or Google Drive coverage, retention settings, and the correct protected users for ' . $platformLabel . ' backup.', 'required' => 1, 'sort' => 85];
    }
    if ($hasServerMgmt) {
        $tasks[] = ['code' => 'SERVER_MANAGEMENT_READY', 'name' => 'Server management access confirmed', 'detail' => 'Verify server access, patching, monitoring, remote support tooling, and any Syncro or security policy differences for protected servers.', 'required' => 1, 'sort' => 90];
    }
    if ($hasServerBackup) {
        $tasks[] = ['code' => 'SERVER_BACKUP_READY', 'name' => 'Server backup scope verified', 'detail' => 'Confirm the selected server backup mode, retention expectations, and recovery notes for each protected server workload.', 'required' => 1, 'sort' => 100];
    }
    if ($hasNetworkSecurity) {
        $tasks[] = ['code' => 'NETWORK_SECURITY_READY', 'name' => 'Managed firewall / network security access confirmed', 'detail' => 'Verify firewall admin access, VPN or remote network notes, DNS filtering handoff, and any site-level network security tooling in scope.', 'required' => 1, 'sort' => 110];
    }
    if ($hasSat) {
        $tasks[] = ['code' => 'SAT_READY', 'name' => 'Security awareness training baseline ready', 'detail' => 'Confirm the awareness training lane, user groups, rollout timing, and reporting cadence are ready for the contracted governance package.', 'required' => 1, 'sort' => 120];
    }
    if ($packageLane === 'COMPLETE') {
        $tasks[] = ['code' => 'GOVERNANCE_BASELINE_READY', 'name' => 'Governance and admin baseline reviewed', 'detail' => 'Confirm the ' . $governanceLabel . ', privilege model, policy hardening expectations, and monthly governance review lane for Govern IT.', 'required' => 1, 'sort' => 130];
        if ($hasAnyBackup) {
            $tasks[] = ['code' => 'RECOVERY_VALIDATION_READY', 'name' => 'Recovery validation plan documented', 'detail' => 'Record the restore validation expectation, priority systems, and recovery notes so the Govern IT lane starts with a real-world recovery plan.', 'required' => 1, 'sort' => 140];
        }
    }

    $tasks[] = ['code' => 'GO_LIVE_APPROVED', 'name' => 'Go-live approved', 'detail' => 'Final sanity check complete. The client is ready for production support and recurring billing can begin.', 'required' => 1, 'sort' => 190];

    return $tasks;
}

function accounting_contract_autocomplete_onboarding_tasks(int $contractId): void {
    if (!accounting_contract_onboarding_ready() || $contractId <= 0) return;
    $contract = accounting_get_contract($contractId);
    if (!$contract) return;

    $updates = [];
    $hasContactName = trim((string)($contract['first_name'] ?? '') . ' ' . (string)($contract['last_name'] ?? '')) !== '';
    $hasContactEmail = trim((string)($contract['contact_email'] ?? $contract['client_email'] ?? '')) !== '';
    $hasContactPhone = trim((string)($contract['contact_phone'] ?? $contract['client_phone'] ?? '')) !== '';
    if ($hasContactName && $hasContactEmail && $hasContactPhone) {
        $updates[] = 'PRIMARY_CONTACT_READY';
    }
    if (!empty($contract['syncro_customer_id']) && strtoupper((string)($contract['syncro_sync_status'] ?? '')) === 'SYNCED') {
        $updates[] = 'SYNCRO_ORG_READY';
    }

    if (!empty($updates)) {
        $placeholders = implode(',', array_fill(0, count($updates), '?'));
        $params = array_merge([date('Y-m-d H:i:s'), $contractId], $updates);
        db()->prepare("UPDATE contract_onboarding_task SET is_completed = 1, completed_at = COALESCE(completed_at, ?), updated_at = CURRENT_TIMESTAMP WHERE contract_id = ? AND task_code IN ($placeholders)")->execute($params);
    }

    if (strtoupper((string)($contract['status'] ?? '')) === 'ACTIVE') {
        $goLiveAt = trim((string)($contract['go_live_at'] ?? $contract['signed_date'] ?? $contract['updated_at'] ?? date('Y-m-d H:i:s')));
        db()->prepare('UPDATE contract_onboarding_task SET is_completed = 1, completed_at = COALESCE(completed_at, ?), updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?')->execute([$goLiveAt !== '' ? $goLiveAt : date('Y-m-d H:i:s'), $contractId]);
    }
}

function accounting_contract_ensure_onboarding_tasks(int $contractId): void {
    if (!accounting_contract_onboarding_ready() || $contractId <= 0) return;
    $contract = accounting_get_contract($contractId);
    if (!$contract) return;
    $status = strtoupper((string)($contract['status'] ?? 'DRAFT'));
    $hasSignedCopy = trim((string)($contract['signed_document_path'] ?? '')) !== '';
    if (!$hasSignedCopy && !in_array($status, ['ONBOARDING', 'SIGNED_PENDING_ONBOARDING', 'ACTIVE'], true)) {
        return;
    }
    $tasks = accounting_contract_default_onboarding_tasks($contractId);
    if (!$tasks) return;
    $pdo = db();
    $existing = $pdo->prepare('SELECT task_id, task_code, is_completed FROM contract_onboarding_task WHERE contract_id = ?');
    $existing->execute([$contractId]);
    $have = [];
    foreach ($existing->fetchAll() as $row) {
        $have[(string)$row['task_code']] = $row;
    }
    $desired = [];
    foreach ($tasks as $task) {
        $desired[(string)$task['code']] = $task;
    }

    $ins = $pdo->prepare('INSERT INTO contract_onboarding_task (contract_id, task_code, task_name, task_detail, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE contract_onboarding_task SET task_name = ?, task_detail = ?, is_required = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE task_id = ?');
    foreach ($desired as $code => $task) {
        if (!empty($have[$code])) {
            $upd->execute([(string)$task['name'], (string)$task['detail'], (int)$task['required'], (int)$task['sort'], (int)$have[$code]['task_id']]);
            continue;
        }
        $ins->execute([$contractId, $code, $task['name'], $task['detail'], (int)$task['required'], (int)$task['sort']]);
    }

    $managedCodes = array_fill_keys(accounting_contract_managed_onboarding_task_codes(), true);
    $deleteIds = [];
    foreach ($have as $code => $row) {
        if (!isset($managedCodes[$code]) || isset($desired[$code])) continue;
        $deleteIds[] = (int)$row['task_id'];
    }
    if ($deleteIds) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $pdo->prepare("DELETE FROM contract_onboarding_task WHERE task_id IN ($placeholders)")->execute($deleteIds);
    }

    accounting_contract_autocomplete_onboarding_tasks($contractId);
}

function accounting_contract_get_onboarding_tasks(int $contractId): array {
    if (!accounting_contract_onboarding_ready() || $contractId <= 0) return [];
    $contract = accounting_get_contract($contractId);
    if (!$contract) return [];
    $status = strtoupper((string)($contract['status'] ?? 'DRAFT'));
    $hasSignedCopy = trim((string)($contract['signed_document_path'] ?? '')) !== '';
    if (!$hasSignedCopy && !in_array($status, ['ONBOARDING', 'SIGNED_PENDING_ONBOARDING', 'ACTIVE'], true)) {
        return [];
    }
    accounting_contract_ensure_onboarding_tasks($contractId);
    $st = db()->prepare('SELECT task_id, contract_id, task_code, task_name, task_detail, is_required, is_completed, completed_at, completed_by, sort_order FROM contract_onboarding_task WHERE contract_id = ? ORDER BY sort_order, task_id');
    $st->execute([$contractId]);
    return $st->fetchAll();
}

function accounting_contract_onboarding_progress(int $contractId): array {
    if (!accounting_contract_onboarding_ready() || $contractId <= 0) {
        return ['required' => 0, 'completed' => 0, 'remaining' => 0, 'percent' => 0, 'all_complete' => true];
    }
    $tasks = accounting_contract_get_onboarding_tasks($contractId);
    $required = 0;
    $completed = 0;
    foreach ($tasks as $task) {
        if (empty($task['is_required'])) continue;
        $required++;
        if (!empty($task['is_completed'])) $completed++;
    }
    $remaining = max(0, $required - $completed);
    $percent = $required > 0 ? (int)round(($completed / $required) * 100) : 0;
    return ['required' => $required, 'completed' => $completed, 'remaining' => $remaining, 'percent' => $percent, 'all_complete' => ($required > 0 && $remaining === 0)];
}

function accounting_contract_set_onboarding_task(int $taskId, bool $complete, int $userId = 0): array {
    if (!accounting_contract_onboarding_ready() || $taskId <= 0) return ['ok' => false, 'errors' => ['Onboarding checklist is not installed yet.']];
    $st = db()->prepare('SELECT task_id, contract_id, task_code, task_name FROM contract_onboarding_task WHERE task_id = ? LIMIT 1');
    $st->execute([$taskId]);
    $task = $st->fetch();
    if (!$task) return ['ok' => false, 'errors' => ['Onboarding task not found.']];

    if ($complete && strtoupper((string)($task['task_code'] ?? '')) === 'SYNCRO_ORG_READY') {
        $syncroResult = syncro_contract_activation_sync((int)$task['contract_id']);
        if (empty($syncroResult['ok']) || !empty($syncroResult['skipped'])) {
            return ['ok' => false, 'errors' => $syncroResult['errors'] ?? ['Syncro sync is still pending for this contract.']];
        }
        if (db_column_exists('contract', 'syncro_pushed_at')) {
            db()->prepare('UPDATE contract SET syncro_pushed_at = COALESCE(syncro_pushed_at, NOW()), updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?')->execute([(int)$task['contract_id']]);
        }
    }

    if ($complete) {
        db()->prepare('UPDATE contract_onboarding_task SET is_completed = 1, completed_at = NOW(), completed_by = ?, updated_at = CURRENT_TIMESTAMP WHERE task_id = ?')->execute([$userId ?: null, $taskId]);
        accounting_contract_autocomplete_onboarding_tasks((int)$task['contract_id']);
        $message = (string)$task['task_name'] . ' marked complete.';
        if (strtoupper((string)($task['task_code'] ?? '')) === 'SYNCRO_ORG_READY') {
            $message .= ' Syncro organization synced successfully.';
        }
    } else {
        db()->prepare('UPDATE contract_onboarding_task SET is_completed = 0, completed_at = NULL, completed_by = NULL, updated_at = CURRENT_TIMESTAMP WHERE task_id = ?')->execute([$taskId]);
        $message = (string)$task['task_name'] . ' marked incomplete.';
    }

    return ['ok' => true, 'contract_id' => (int)$task['contract_id'], 'message' => $message];
}

function accounting_contract_status_badge_html(string $status): string {
    $status = strtoupper(trim($status));
    $map = [
        'ACTIVE' => ['rgba(34,197,94,.18)', 'rgba(34,197,94,.42)', '#d1fae5'],
        'DRAFT' => ['rgba(148,163,184,.18)', 'rgba(148,163,184,.35)', '#e2e8f0'],
        'PENDING_SIGNATURE' => ['rgba(59,130,246,.18)', 'rgba(59,130,246,.35)', '#dbeafe'],
        'SIGNED_PENDING_ONBOARDING' => ['rgba(139,92,246,.18)', 'rgba(139,92,246,.35)', '#ede9fe'],
        'ONBOARDING' => ['rgba(14,165,233,.18)', 'rgba(14,165,233,.35)', '#e0f2fe'],
        'EXPIRED' => ['rgba(245,158,11,.18)', 'rgba(245,158,11,.35)', '#fef3c7'],
        'CANCELLED' => ['rgba(248,113,113,.18)', 'rgba(248,113,113,.35)', '#fee2e2'],
    ];
    [$bg,$border,$fg] = $map[$status] ?? ['rgba(59,130,246,.18)','rgba(59,130,246,.35)','#dbeafe'];
    return '<span class="badge status-badge" style="display:inline-flex;min-width:110px;justify-content:center;padding:4px 10px;border-radius:999px;border:1px solid '.$border.';background:'.$bg.';color:'.$fg.';font-size:12px;font-weight:700;">'.accounting_h(str_replace('_', ' ', $status)).'</span>';
}

function accounting_contract_packet_template_html(string $body): string {
    $body = trim(str_replace(["
", "
"], "
", $body));
    if ($body === '') {
        return '';
    }

    $blocks = preg_split('/
\s*
+/', $body) ?: [];
    $html = '';

    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("
", trim((string)$block))), static fn(string $line): bool => $line !== ''));
        if (!$lines) {
            continue;
        }

        $title = '';
        if (count($lines) > 1 && (preg_match('/^\d+\.\s+/', $lines[0]) === 1 || strlen($lines[0]) <= 90)) {
            $title = array_shift($lines);
        }

        $html .= '<div class="legal-block">';
        if ($title !== '') {
            $html .= '<div class="legal-title">' . nl2br(htmlspecialchars($title)) . '</div>';
        }

        $listItems = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $listItems[] = $m[1];
                continue;
            }

            if ($listItems) {
                $html .= '<ul class="legal-list">';
                foreach ($listItems as $item) {
                    $html .= '<li>' . nl2br(htmlspecialchars($item)) . '</li>';
                }
                $html .= '</ul>';
                $listItems = [];
            }

            $html .= '<p>' . nl2br(htmlspecialchars($line)) . '</p>';
        }

        if ($listItems) {
            $html .= '<ul class="legal-list">';
            foreach ($listItems as $item) {
                $html .= '<li>' . nl2br(htmlspecialchars($item)) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
    }

    return $html;
}

function accounting_render_contract_pdf_bytes(array $contract, array $services): string {
    require_once __DIR__ . '/../vendor/autoload.php';

    if (ob_get_length()) {
        ob_end_clean();
    }

    $services = accounting_expand_contract_service_rows($services);
    $groups = accounting_group_contract_services($services);

    $logoCandidates = [
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-light.jpg',
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-light.jpeg',
        __DIR__ . '/../assets/brand/mmit-logo-horizontal-light.png',
        __DIR__ . '/../assets/brand/logo.jpg',
        __DIR__ . '/../assets/brand/logo.jpeg',
        __DIR__ . '/../assets/brand/logo.png',
    ];

    $logoPath = null;
    foreach ($logoCandidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $logoPath = $candidate;
            break;
        }
    }

    $contractNumber = (string)($contract['contract_number'] ?? ('CNT-' . (int)($contract['contract_id'] ?? 0)));
    $contractName = (string)($contract['contract_name'] ?? 'Managed Services Agreement');
    $clientName = trim((string)($contract['dba_name'] ?: $contract['legal_name'] ?: 'Client'));
    $legalName = trim((string)($contract['legal_name'] ?? ''));
    $startDate = (string)($contract['start_date'] ?? '');
    $endDate = (string)($contract['end_date'] ?? '');
    $billingCycle = (string)($contract['billing_cycle'] ?? 'MONTHLY');
    $serviceLevel = (string)($contract['sla_level'] ?? '');
    $status = (string)($contract['status'] ?? 'DRAFT');
    $notes = trim((string)($contract['notes'] ?? ''));
    $coveredUsers = (float)($contract['covered_users'] ?? 0);
    $coveredDevices = (float)($contract['covered_devices'] ?? 0);
    $autoRenew = !empty($contract['auto_renew']);

    $packages = accounting_service_packages();
    $productivitySelection = accounting_productivity_selection_details($services);
    $productivityCatalog = accounting_productivity_catalog();
    $productivityCodes = [];
    foreach ($productivityCatalog as $platformMeta) {
        foreach ((array)($platformMeta['licenses'] ?? []) as $licenseMeta) {
            $productivityCodes[] = strtoupper((string)($licenseMeta['item_code'] ?? ''));
        }
    }

    $coveredServers = 0.0;
    $baseServiceCode = '';
    $selectedAddons = [];
    foreach ($services as $svc) {
        $code = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
        if ($code !== '' && str_starts_with($code, 'MSP-') && $baseServiceCode === '') {
            $baseServiceCode = preg_replace('/^MSP-/', '', $code) ?? '';
        }
        if ($code === 'SRVR-MGMT' || str_starts_with($code, 'SRVR-BK-')) {
            $coveredServers = max($coveredServers, (float)($svc['quantity'] ?? 0));
        }
        if (empty($svc['is_included']) && $code !== '' && !str_starts_with($code, 'MSP-') && !in_array($code, $productivityCodes, true)) {
            $selectedAddons[] = $svc;
        }
    }

    $servicePackage = null;
    if ($baseServiceCode !== '' && isset($packages[$baseServiceCode])) {
        $servicePackage = $packages[$baseServiceCode];
    }
    if (!$servicePackage) {
        foreach ($packages as $pkg) {
            if (strcasecmp((string)($pkg['name'] ?? ''), $serviceLevel) === 0) {
                $servicePackage = $pkg;
                break;
            }
        }
    }
    $includedServices = $servicePackage ? (array)($servicePackage['included_services'] ?? []) : [];
    if (!$includedServices) {
        foreach ($groups as $group) {
            foreach ((array)($group['included'] ?? []) as $svc) {
                $label = trim((string)($svc['service_name'] ?? $svc['description'] ?? ''));
                if ($label !== '' && !in_array($label, $includedServices, true)) {
                    $includedServices[] = $label;
                }
            }
        }
    }
    $notIncluded = $servicePackage ? accounting_not_included_unless_selected($servicePackage, $services) : [];

    $contactName = trim((string)($contract['first_name'] ?? '') . ' ' . (string)($contract['last_name'] ?? ''));
    $contactEmail = (string)($contract['contact_email'] ?? $contract['client_email'] ?? '');

    $serviceAddressLines = [];
    $addr1 = trim((string)($contract['address1'] ?? ''));
    $addr2 = trim((string)($contract['address2'] ?? ''));
    $city = trim((string)($contract['city'] ?? ''));
    $state = trim((string)($contract['state'] ?? ''));
    $postal = trim((string)($contract['postal_code'] ?? ''));

    if ($addr1 !== '' || $addr2 !== '') {
        $serviceAddressLines[] = trim($addr1 . ' ' . $addr2);
    }
    $cityLine = trim(preg_replace('/\s+/', ' ', trim($city . ', ' . $state . ' ' . $postal)));
    $cityLine = trim($cityLine, ', ');
    if ($cityLine !== '') {
        $serviceAddressLines[] = $cityLine;
    }

    $monthlyTotal = 0.0;
    foreach ($services as $svc) {
        if (!empty($svc['is_included'])) {
            continue;
        }
        $monthlyTotal += (float)($svc['quantity'] ?? 0) * (float)($svc['unit_price'] ?? 0);
    }
    if ($monthlyTotal <= 0) {
        $monthlyTotal = (float)($contract['base_amount'] ?? 0);
    }

    $logoHtml = '';
    if ($logoPath !== null) {
        $mime = 'image/jpeg';
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $mime = 'image/png';
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        }
        $logoData = base64_encode((string)file_get_contents($logoPath));
        $logoHtml = '<img src="data:' . $mime . ';base64,' . $logoData . '" style="width:230px;height:auto;">';
    } else {
        $logoHtml = '<div class="company-name">Midwest Managed IT</div>';
    }

    $summaryRows = [
        ['Contract', $contractName],
        ['Contract #', $contractNumber],
        ['Client', $clientName],
        ['Legal name', $legalName !== '' ? $legalName : $clientName],
        ['Primary contact', $contactName !== '' ? $contactName : '—'],
        ['Contact email', $contactEmail !== '' ? $contactEmail : '—'],
        ['Service package selected', ($servicePackage['name'] ?? '') !== '' ? (string)$servicePackage['name'] : ($serviceLevel !== '' ? $serviceLevel : '—')],
        ['Productivity platform selected', (string)($productivitySelection['platform_name'] ?? 'No productivity platform selected')],
        ['License level selected', (string)($productivitySelection['license_name'] ?? 'None selected')],
        ['Billing cycle', $billingCycle],
        ['Monthly recurring total', '$' . number_format($monthlyTotal, 2)],
        ['Covered workstations', $coveredDevices > 0 ? number_format($coveredDevices, 0) : '—'],
        ['Covered users / seats', $coveredUsers > 0 ? number_format($coveredUsers, 0) : '—'],
        ['Covered servers', $coveredServers > 0 ? number_format($coveredServers, 0) : '0'],
        ['Start date', $startDate !== '' ? $startDate : '—'],
        ['End date', $endDate !== '' ? $endDate : 'Month-to-month after initial term'],
        ['Renewal', $autoRenew ? 'Auto-renews after initial term unless non-renewed in writing' : 'No auto-renew'],
        ['Status', strtoupper($status)],
    ];

    if ($serviceAddressLines) {
        $summaryRows[] = ['Service address', implode('<br>', array_map(static fn(string $line): string => htmlspecialchars($line), $serviceAddressLines))];
    }

    $summaryHtml = '<table class="summary-table">';
    foreach ($summaryRows as [$label, $value]) {
        $summaryHtml .= '<tr><th>' . htmlspecialchars((string)$label) . '</th><td>' . (str_contains((string)$value, '<br>') ? (string)$value : htmlspecialchars((string)$value)) . '</td></tr>';
    }
    $summaryHtml .= '</table>';

    $serviceScheduleHtml = '';
    $serviceScheduleHtml .= '<div class="service-card">';
    $serviceScheduleHtml .= '<div class="service-title">' . htmlspecialchars((string)($servicePackage['name'] ?? ($serviceLevel !== '' ? $serviceLevel : 'Service Package'))) . '</div>';
    if (!empty($servicePackage['description'])) {
        $serviceScheduleHtml .= '<div class="service-desc">' . htmlspecialchars((string)$servicePackage['description']) . '</div>';
    }
    $serviceScheduleHtml .= '<div class="service-price">' . htmlspecialchars('$' . number_format($monthlyTotal, 2) . ' total recurring summary') . '</div>';
    $serviceScheduleHtml .= '<div class="section-label">Selected Platform and License</div>';
    $serviceScheduleHtml .= '<p>' . htmlspecialchars((string)($productivitySelection['platform_name'] ?? 'No productivity platform selected')) . ' · ' . htmlspecialchars((string)($productivitySelection['license_name'] ?? 'None selected')) . '</p>';
    $serviceScheduleHtml .= '<div class="section-label">Covered Environment Summary</div>';
    $serviceScheduleHtml .= '<p>' . htmlspecialchars('Workstations: ' . ($coveredDevices > 0 ? number_format($coveredDevices, 0) : '0') . ' · Users/Seats: ' . ($coveredUsers > 0 ? number_format($coveredUsers, 0) : '0') . ' · Servers: ' . ($coveredServers > 0 ? number_format($coveredServers, 0) : '0')) . '</p>';

    if ($includedServices) {
        $serviceScheduleHtml .= '<div class="section-label">Included with This Package</div><ul>';
        foreach ($includedServices as $label) {
            $serviceScheduleHtml .= '<li>' . htmlspecialchars((string)$label) . '</li>';
        }
        $serviceScheduleHtml .= '</ul>';
    }

    if (($productivitySelection['platform_code'] ?? 'NONE') !== 'NONE') {
        $serviceScheduleHtml .= '<div class="section-label">Selected Platform License</div><ul>';
        $serviceScheduleHtml .= '<li>' . htmlspecialchars((string)($productivitySelection['license_name'] ?? 'Selected license')) . ' — ' . htmlspecialchars(number_format((float)($productivitySelection['quantity'] ?? 0), 0) . ' @ $' . number_format((float)($productivitySelection['unit_price'] ?? 0), 2) . ' · per user') . '</li>';
        $serviceScheduleHtml .= '</ul>';
    }

    if ($selectedAddons) {
        $serviceScheduleHtml .= '<div class="section-label">Optional Add-Ons Selected</div><ul>';
        foreach ($selectedAddons as $svc) {
            $label = (string)($svc['service_name'] ?: $svc['description'] ?: 'Add-on');
            $qty = (float)($svc['quantity'] ?? 0);
            $unit = (float)($svc['unit_price'] ?? 0);
            $basis = strtoupper((string)($svc['billing_type'] ?? 'FIXED'));
            $serviceScheduleHtml .= '<li>' . htmlspecialchars($label) . ' — ' . htmlspecialchars(number_format($qty, 0) . ' @ $' . number_format($unit, 2) . ' · ' . accounting_pricing_model_label($basis, true)) . '</li>';
        }
        $serviceScheduleHtml .= '</ul>';
    }

    if ($notIncluded) {
        $serviceScheduleHtml .= '<div class="section-label">Not Included Unless Selected</div><ul>';
        foreach ($notIncluded as $label) {
            $serviceScheduleHtml .= '<li>' . htmlspecialchars((string)$label) . '</li>';
        }
        $serviceScheduleHtml .= '</ul>';
    }
    $serviceScheduleHtml .= '</div>';

    $agreementNotes = $notes !== ''
        ? nl2br(htmlspecialchars($notes))
        : 'This packet is generated from the selected Midwest Managed IT service level, covered quantities, and any chosen add-ons. Pricing and scope follow the service schedule and legal terms below. Onboarding and setup work begins once approved and is non-refundable after work starts.';

    $msaHtml = accounting_contract_packet_template_html(accounting_contract_template_body('MSA'));
    $slaHtml = accounting_contract_packet_template_html(accounting_contract_template_body('SLA'));

    $html = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 38px 42px; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11.5px; color: #111827; line-height: 1.34; }
    .header-table, .summary-table, .signature-table { width: 100%; border-collapse: collapse; }
    .header-table td { vertical-align: top; }
    .header-right { text-align: right; }
    .company-name { font-size: 24px; font-weight: 800; }
    .packet-word { font-size: 22px; font-weight: 800; letter-spacing: 1px; }
    .contract-no { font-size: 13px; font-weight: 700; margin-top: 6px; }
    h1 { font-size: 22px; margin: 0 0 6px 0; }
    .subhead { font-size: 12px; color: #4b5563; margin-bottom: 12px; }
    .section-title { font-size: 15px; font-weight: 800; margin: 14px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #cbd5e1; }
    .summary-table th { width: 31%; text-align: left; padding: 5px 7px; background: #eff6ff; border: 1px solid #dbeafe; font-size: 11px; }
    .summary-table td { padding: 5px 7px; border: 1px solid #dbeafe; }
    .service-card { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; margin-bottom: 10px; page-break-inside: avoid; }
    .service-title { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
    .service-price { font-size: 11px; font-weight: 700; margin-bottom: 4px; color: #0f172a; }
    .service-desc { margin-bottom: 6px; color: #374151; }
    .section-label { font-weight: 700; margin-top: 8px; margin-bottom: 3px; }
    ul { margin: 3px 0 6px 18px; padding: 0; }
    li { margin-bottom: 3px; }
    p { margin: 0 0 6px 0; }
    .legal-block { margin-bottom: 10px; page-break-inside: avoid; }
    .legal-title { font-weight: 800; margin-bottom: 5px; }
    .legal-list { margin-top: 0; }
    .notes-box { padding: 8px 10px; border-radius: 8px; border: 1px solid #d1d5db; background: #f8fafc; }
    .signature-wrap { margin-top: 28px; }
    .signature-table td { width: 48%; vertical-align: top; padding-top: 18px; }
    .sig-line { border-top: 1px solid #111827; padding-top: 8px; margin-right: 18px; min-height: 38px; }
    .sig-party { font-weight: 700; margin-bottom: 8px; }
    .sig-row { margin: 0; }
    .sig-gap { height: 10px; }
</style>
</head>
<body>
<div class="header">
    <table class="header-table">
        <tr>
            <td>' . $logoHtml . '</td>
            <td class="header-right">
                <div class="packet-word">AGREEMENT PACKET</div>
                <div class="contract-no">#' . htmlspecialchars($contractNumber) . '</div>
            </td>
        </tr>
    </table>
</div>
<h1>Managed Services Agreement Packet</h1>
<div class="subhead">Order form, service schedule, support policy, and legal terms for ' . htmlspecialchars($clientName) . '.</div>
<div class="section-title">Order Form Summary</div>
' . $summaryHtml . '
<div class="section-title">Service Schedule</div>
' . $serviceScheduleHtml . '
<div class="section-title">Agreement Notes</div>
<div class="notes-box">' . $agreementNotes . '</div>
<div class="section-title">Support Policy / SLA</div>
' . $slaHtml . '
<div class="section-title">Master Managed Services Agreement</div>
' . $msaHtml . '
<div class="signature-wrap">
    <table class="signature-table">
        <tr>
            <td>
                <div class="sig-line">
                    <div class="sig-party">Client</div>
                    <div class="sig-row">Printed Name: ____________________________</div>
                    <div class="sig-gap"></div>
                    <div class="sig-row">Signature: _______________________________</div>
                    <div class="sig-gap"></div>
                    <div class="sig-row">Title: ___________________________________</div>
                    <div class="sig-gap"></div>
                    <div class="sig-row">Date: ____________________________________</div>
                </div>
            </td>
            <td>
                <div class="sig-line">
                    <div class="sig-party">LnK Consulting, LLC dba Midwest Managed IT</div>
                    <div class="sig-row">Printed Name: ____________________________</div>
                    <div class="sig-gap"></div>
                    <div class="sig-row">Signature: _______________________________</div>
                    <div class="sig-gap"></div>
                    <div class="sig-row">Title: ___________________________________</div>
                    <div class="sig-gap"></div>
                    <div class="sig-row">Date: ____________________________________</div>
                </div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('defaultPaperSize', 'letter');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

function accounting_contract_billing_sync(int $contractId, string $status, int $userId = 0): array {
    $contract = accounting_get_contract($contractId);
    if (!$contract) return ['ok' => false, 'errors' => ['Contract not found.']];
    if (!accounting_client_service_ready() || !accounting_recurring_ready()) {
        return ['ok' => false, 'errors' => ['Client service or recurring billing tables are not installed yet.']];
    }

    $status = strtoupper(trim($status));
    $activate = $status === 'ACTIVE';
    $serviceStatus = $activate ? 'ACTIVE' : ($status === 'CANCELLED' ? 'ENDED' : 'PAUSED');
    $recurringActive = $activate ? 1 : 0;
    $contractId = (int)$contract['contract_id'];
    $clientId = (int)$contract['client_id'];
    $billingStartDate = trim((string)($contract['billing_start_date'] ?? ''));
    if ($billingStartDate === '' && !empty($contract['go_live_at'])) {
        $billingStartDate = substr((string)$contract['go_live_at'], 0, 10);
    }
    $startDate = $billingStartDate !== '' ? $billingStartDate : (string)($contract['start_date'] ?: date('Y-m-d'));
    $endDate = (string)($contract['end_date'] ?: null);
    $termMonths = 0;
    if ($endDate) {
        try {
            $s = new DateTimeImmutable($startDate);
            $e = new DateTimeImmutable($endDate);
            $termMonths = max(0, ((int)$e->format('Y') - (int)$s->format('Y')) * 12 + ((int)$e->format('n') - (int)$s->format('n')));
        } catch (Throwable $e) {
            $termMonths = 0;
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $updatedClient = 0;
        $updatedRecurring = 0;
        $createdClient = 0;
        $createdRecurring = 0;

        $clientRows = accounting_contract_client_services($contractId);
        if ($clientRows) {
            if ($activate) {
                $st = $pdo->prepare('UPDATE client_service SET status = ?, next_bill_date = ?, start_date = ?, end_date = ?, updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?');
                $st->execute([$serviceStatus, $startDate, $startDate, $endDate ?: null, $contractId]);
                $updatedClient = $st->rowCount();

                $st = $pdo->prepare('UPDATE recurring_service SET active = ?, next_bill_date = ?, start_date = ?, end_date = ?, updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?');
                $st->execute([$recurringActive, $startDate, $startDate, $endDate ?: null, $contractId]);
                $updatedRecurring = $st->rowCount();
            } else {
                $st = $pdo->prepare('UPDATE client_service SET status = ?, next_bill_date = COALESCE(next_bill_date, ?), start_date = COALESCE(start_date, ?), end_date = ?, updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?');
                $st->execute([$serviceStatus, $startDate, $startDate, $endDate ?: null, $contractId]);
                $updatedClient = $st->rowCount();

                $st = $pdo->prepare('UPDATE recurring_service SET active = ?, next_bill_date = COALESCE(next_bill_date, ?), start_date = COALESCE(start_date, ?), end_date = ?, updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?');
                $st->execute([$recurringActive, $startDate, $startDate, $endDate ?: null, $contractId]);
                $updatedRecurring = $st->rowCount();
            }
        } else {
            $contractServices = accounting_get_contract_services($contractId);
            $insertRecurring = $pdo->prepare('INSERT INTO recurring_service (client_id, contract_id, contract_service_id, item_id, description, item_type, billing_type, billing_cycle, quantity, unit_price, taxable, next_bill_date, term_months, last_billed_date, active, auto_renew, start_date, end_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)');
            $insertClient = $pdo->prepare('INSERT INTO client_service (client_id, contract_id, item_id, recurring_service_id, parent_client_service_id, pricing_model, description, quantity, unit_price, billing_cycle, term_months, start_date, next_bill_date, end_date, revenue_account_id, taxable, auto_renew, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $itemLookupStmt = $pdo->prepare('SELECT item_id, revenue_account_id, item_type FROM service_item WHERE item_id = ? LIMIT 1');
            $codeLookupStmt = $pdo->prepare('SELECT item_id, revenue_account_id, item_type FROM service_item WHERE item_code = ? LIMIT 1');

            $parentClientServiceId = null;
            foreach ($contractServices as $svc) {
                if (!empty($svc['is_included'])) {
                    continue;
                }

                $itemId = !empty($svc['item_id']) ? (int)$svc['item_id'] : 0;
                $revenueAccountId = 0;
                $itemType = 'SERVICE';
                if ($itemId > 0) {
                    $itemLookupStmt->execute([$itemId]);
                    $itemRow = $itemLookupStmt->fetch();
                    if ($itemRow) {
                        $revenueAccountId = (int)($itemRow['revenue_account_id'] ?? 0);
                        $itemType = (string)($itemRow['item_type'] ?? 'SERVICE');
                    }
                } elseif (!empty($svc['service_code'])) {
                    $codeLookupStmt->execute([(string)$svc['service_code']]);
                    $itemRow = $codeLookupStmt->fetch();
                    if ($itemRow) {
                        $itemId = (int)($itemRow['item_id'] ?? 0);
                        $revenueAccountId = (int)($itemRow['revenue_account_id'] ?? 0);
                        $itemType = (string)($itemRow['item_type'] ?? 'SERVICE');
                    }
                }
                if ($revenueAccountId <= 0) {
                    $revenueAccountId = (int)(accounting_find_account_id_by_code('4000') ?? 0);
                }

                $description = (string)($svc['description'] ?: $svc['service_name'] ?: 'Contract service');
                $billingType = strtoupper(trim((string)($svc['billing_type'] ?: 'FIXED')));
                $quantity = (float)($svc['quantity'] ?? 1);
                $unitPrice = (float)($svc['unit_price'] ?? 0);
                $taxable = !empty($svc['taxable']) ? 1 : 0;

                $insertRecurring->execute([
                    $clientId,
                    $contractId,
                    (int)$svc['contract_service_id'],
                    $itemId > 0 ? $itemId : null,
                    $description,
                    $itemType ?: 'SERVICE',
                    $billingType,
                    (string)$contract['billing_cycle'],
                    $quantity,
                    $unitPrice,
                    $taxable,
                    $startDate,
                    $termMonths > 0 ? $termMonths : null,
                    $recurringActive,
                    !empty($contract['auto_renew']) ? 1 : 0,
                    $startDate,
                    $endDate ?: null,
                    'Generated from contract go-live',
                ]);
                $recurringId = (int)$pdo->lastInsertId();
                $createdRecurring++;

                $isAddon = $parentClientServiceId !== null || ((int)($svc['bundle_id'] ?? 0) > 0 && !empty($svc['item_id']) && (float)$svc['unit_price'] > 0);
                $insertClient->execute([
                    $clientId,
                    $contractId,
                    $itemId > 0 ? $itemId : null,
                    $recurringId,
                    $isAddon ? $parentClientServiceId : null,
                    $billingType,
                    (string)($svc['service_name'] ?: $description),
                    $quantity,
                    $unitPrice,
                    (string)$contract['billing_cycle'],
                    $termMonths > 0 ? $termMonths : null,
                    $startDate,
                    $startDate,
                    $endDate ?: null,
                    $revenueAccountId,
                    $taxable,
                    !empty($contract['auto_renew']) ? 1 : 0,
                    $serviceStatus,
                    'Generated from contract go-live',
                    $userId ?: null,
                ]);
                $clientServiceId = (int)$pdo->lastInsertId();
                $createdClient++;

                if ($parentClientServiceId === null) {
                    $parentClientServiceId = $clientServiceId;
                }
            }
        }

        $pdo->commit();
        return [
            'ok' => true,
            'created_client_services' => $createdClient,
            'created_recurring_services' => $createdRecurring,
            'updated_client_services' => $updatedClient,
            'updated_recurring_services' => $updatedRecurring,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Billing sync failed: ' . $e->getMessage()]];
    }
}

function accounting_generate_contract_initial_invoice(int $contractId, int $userId = 0, ?string $invoiceDate = null): array {
    if ($contractId <= 0 || !accounting_recurring_ready()) {
        return ['ok' => false, 'errors' => ['Recurring billing is not ready.']];
    }

    $pdo = db();
    $contract = accounting_get_contract($contractId);
    if (!$contract) {
        return ['ok' => false, 'errors' => ['Contract not found.']];
    }

    $sql = "SELECT rs.*, c.legal_name, c.dba_name, ct.contract_number, ct.contract_name,
                   si.item_name, si.item_code, si.item_type AS catalog_item_type, si.revenue_account_id,
                   cs.service_code, cs.service_name, cs.description AS contract_service_description,
                   cs.is_included, cs.sort_order
            FROM recurring_service rs
            INNER JOIN clients c ON c.client_id = rs.client_id
            INNER JOIN contract ct ON ct.contract_id = rs.contract_id
            LEFT JOIN service_item si ON si.item_id = rs.item_id
            LEFT JOIN contract_service cs ON cs.contract_service_id = rs.contract_service_id
            WHERE rs.contract_id = ? AND rs.active = 1
            ORDER BY rs.next_bill_date ASC, COALESCE(cs.sort_order, 9999), rs.recurring_service_id";
    $st = $pdo->prepare($sql);
    $st->execute([$contractId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return ['ok' => false, 'errors' => ['No active recurring items are linked to this contract yet.']];
    }

    $invoiceDate = $invoiceDate ?: (string)($contract['billing_start_date'] ?: $rows[0]['next_bill_date'] ?: $contract['start_date'] ?: date('Y-m-d'));
    $groupKey = accounting_recurring_invoice_group_key($rows[0], $invoiceDate);
    $existing = accounting_find_existing_recurring_invoice($pdo, $groupKey);
    if ($existing) {
        return [
            'ok' => true,
            'invoice_id' => (int)$existing['invoice_id'],
            'invoice_number' => (string)$existing['invoice_number'],
            'skipped_existing' => true,
            'message' => 'Initial draft invoice already exists for this contract cycle.',
        ];
    }

    $defaultRevenueAccountId = accounting_find_account_id_by_code('4000') ?? 0;
    $arAccountId = accounting_find_account_id_by_code('1100') ?? 0;
    $lineDescriptions = [];
    $lineItemIds = [];
    $lineServiceCodes = [];
    $lineQuantities = [];
    $lineUnitPrices = [];
    $lineRevenueAccounts = [];
    $generatedFrom = [];

    foreach ($rows as $row) {
        $desc = trim((string)($row['description'] ?: $row['service_name'] ?: $row['contract_service_description'] ?: $row['item_name'] ?: 'Recurring item'));
        $lineDescriptions[] = $desc;
        $lineItemIds[] = (int)($row['item_id'] ?? 0);
        $lineServiceCodes[] = (string)($row['service_code'] ?: $row['item_code'] ?: '');
        $lineQuantities[] = (float)$row['quantity'];
        $lineUnitPrices[] = (float)$row['unit_price'];
        $lineRevenueAccounts[] = !empty($row['revenue_account_id']) ? (int)$row['revenue_account_id'] : $defaultRevenueAccountId;
        $generatedFrom[] = '#' . (int)$row['recurring_service_id'];
    }

    $memoParts = [];
    if (!empty($contract['contract_number'])) {
        $memoParts[] = 'Generated from contract go-live for ' . (string)$contract['contract_number'];
    } elseif (!empty($contract['contract_name'])) {
        $memoParts[] = 'Generated from contract go-live for ' . (string)$contract['contract_name'];
    } else {
        $memoParts[] = 'Generated from contract go-live';
    }
    $memoParts[] = 'Items: ' . implode(', ', $generatedFrom);

    $result = accounting_create_invoice([
        'client_id' => (int)$contract['client_id'],
        'contract_id' => $contractId,
        'invoice_date' => $invoiceDate,
        'due_date' => date('Y-m-d', strtotime($invoiceDate . ' +15 days')),
        'status' => 'DRAFT',
        'line_description' => $lineDescriptions,
        'line_item_id' => $lineItemIds,
        'line_service_code' => $lineServiceCodes,
        'line_quantity' => $lineQuantities,
        'line_unit_price' => $lineUnitPrices,
        'line_revenue_account_id' => $lineRevenueAccounts,
        'ar_account_id' => $arAccountId,
        'memo' => implode(' | ', $memoParts),
        'source_system' => 'RECURRING_BATCH',
        'source_record_id' => $groupKey,
    ], $userId);
    if (empty($result['ok'])) {
        return $result;
    }

    $upd = $pdo->prepare('UPDATE recurring_service SET last_billed_date = ?, next_bill_date = ?, updated_at = CURRENT_TIMESTAMP WHERE recurring_service_id = ?');
    foreach ($rows as $row) {
        $currentBillDate = (string)($row['next_bill_date'] ?: $invoiceDate);
        $nextDate = accounting_add_billing_interval($currentBillDate, (string)$row['billing_cycle']);
        $upd->execute([$invoiceDate, $nextDate, (int)$row['recurring_service_id']]);
    }

    return [
        'ok' => true,
        'invoice_id' => (int)$result['invoice_id'],
        'message' => 'Initial draft invoice created.',
    ];
}

function accounting_contract_status_update(int $contractId, string $status, int $userId = 0, array $meta = []): array {
    if ($contractId <= 0) return ['ok' => false, 'errors' => ['Invalid contract.']];
    $allowed = array_keys(accounting_contract_status_options());
    $status = strtoupper(trim($status));
    if (!in_array($status, $allowed, true)) return ['ok' => false, 'errors' => ['Invalid contract status.']];

    $contract = accounting_get_contract($contractId);
    if (!$contract) return ['ok' => false, 'errors' => ['Contract not found.']];

    $requiresSignedCopy = in_array($status, ['SIGNED_PENDING_ONBOARDING', 'ONBOARDING', 'ACTIVE'], true);
    if ($requiresSignedCopy && db_column_exists('contract', 'signed_document_path')) {
        $existingSignedPath = trim((string)($contract['signed_document_path'] ?? ''));
        $incomingSignedPath = trim((string)($meta['signed_document_path'] ?? ''));
        if ($existingSignedPath === '' && $incomingSignedPath === '') {
            return ['ok' => false, 'errors' => ['Upload the signed PDF before moving this contract into onboarding or activation.']];
        }
        if ($existingSignedPath !== '' && !array_key_exists('signed_document_path', $meta)) {
            $meta['signed_document_path'] = $existingSignedPath;
        }
        if (db_column_exists('contract', 'signed_date') && !array_key_exists('signed_date', $meta) && empty($contract['signed_date'])) {
            $meta['signed_date'] = date('Y-m-d H:i:s');
        }
        if (db_column_exists('contract', 'signed_by') && !array_key_exists('signed_by', $meta) && empty($contract['signed_by'])) {
            $meta['signed_by'] = trim((string)(current_user()['full_name'] ?? current_user()['email'] ?? 'Portal user'));
        }
        if (db_column_exists('contract', 'signed_ip') && !array_key_exists('signed_ip', $meta) && empty($contract['signed_ip'])) {
            $meta['signed_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        }
    }

    if ($status === 'ONBOARDING' && db_column_exists('contract', 'onboarding_started_at') && !array_key_exists('onboarding_started_at', $meta) && empty($contract['onboarding_started_at'])) {
        $meta['onboarding_started_at'] = date('Y-m-d H:i:s');
    }

    if ($status === 'ACTIVE') {
        if (accounting_contract_onboarding_ready()) {
            $progress = accounting_contract_onboarding_progress($contractId);
            if (!$progress['all_complete']) {
                return ['ok' => false, 'errors' => ['Complete the required onboarding checklist before marking this contract active and starting billing.']];
            }
        }
        if (db_column_exists('contract', 'go_live_at') && !array_key_exists('go_live_at', $meta) && empty($contract['go_live_at'])) {
            $meta['go_live_at'] = date('Y-m-d H:i:s');
        }
        if (db_column_exists('contract', 'billing_start_date') && !array_key_exists('billing_start_date', $meta) && empty($contract['billing_start_date'])) {
            $meta['billing_start_date'] = date('Y-m-d');
        }
    }

    $fields = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];
    foreach (['signed_by','signed_date','signed_ip','signed_document_path','audit_document_path','onboarding_started_at','go_live_at','billing_start_date','syncro_pushed_at'] as $col) {
        if (db_column_exists('contract', $col) && array_key_exists($col, $meta)) {
            $fields[] = $col . ' = ?';
            $params[] = $meta[$col] ?: null;
        }
    }
    $params[] = $contractId;
    db()->prepare('UPDATE contract SET ' . implode(', ', $fields) . ' WHERE contract_id = ?')->execute($params);

    $sync = accounting_contract_billing_sync($contractId, $status, $userId);
    if (empty($sync['ok'])) {
        return ['ok' => false, 'errors' => $sync['errors'] ?? ['Unable to sync billing state.']];
    }

    $parts = [];
    if (!empty($sync['created_client_services']) || !empty($sync['created_recurring_services'])) {
        $parts[] = (int)($sync['created_client_services'] ?? 0) . ' client service(s) created';
        $parts[] = (int)($sync['created_recurring_services'] ?? 0) . ' recurring item(s) created';
    }
    if (!empty($sync['updated_client_services']) || !empty($sync['updated_recurring_services'])) {
        $parts[] = (int)($sync['updated_client_services'] ?? 0) . ' client service(s) updated';
        $parts[] = (int)($sync['updated_recurring_services'] ?? 0) . ' recurring item(s) updated';
    }

    $message = 'Contract status updated.';
    if ($parts) {
        $message .= ' ' . implode(' / ', $parts) . '.';
    }

    if (in_array($status, ['SIGNED_PENDING_ONBOARDING', 'ONBOARDING', 'ACTIVE'], true) && accounting_contract_onboarding_ready()) {
        accounting_contract_ensure_onboarding_tasks($contractId);
    }

    if (in_array($status, ['SIGNED_PENDING_ONBOARDING', 'ONBOARDING'], true)) {
        try {
            $syncroResult = syncro_contract_activation_sync($contractId);
            if (!empty($syncroResult['ok']) && empty($syncroResult['skipped'])) {
                if (db_column_exists('contract', 'syncro_pushed_at')) {
                    db()->prepare('UPDATE contract SET syncro_pushed_at = COALESCE(syncro_pushed_at, NOW()), updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?')->execute([$contractId]);
                }
                accounting_contract_autocomplete_onboarding_tasks($contractId);
                $message .= ' Syncro ' . (string)($syncroResult['action'] ?? 'sync') . ' completed for onboarding.';
            } elseif (!empty($syncroResult['errors'])) {
                $message .= ' Syncro sync pending: ' . implode(' ', array_map('strval', (array)$syncroResult['errors']));
            }
        } catch (Throwable $e) {
            error_log('Syncro onboarding sync failed: ' . $e->getMessage());
            $message .= ' Syncro sync pending.';
        }
        return ['ok' => true, 'message' => $message];
    }

    if ($status === 'ACTIVE') {
        $contractAfter = accounting_get_contract($contractId) ?: $contract;
        if (!empty($contractAfter['invoice_autogen_enabled'])) {
            $invoiceDate = (string)($contractAfter['billing_start_date'] ?: substr((string)($contractAfter['go_live_at'] ?? ''), 0, 10) ?: $contractAfter['start_date'] ?: date('Y-m-d'));
            $invoiceResult = accounting_generate_contract_initial_invoice($contractId, $userId, $invoiceDate);
            if (!empty($invoiceResult['ok'])) {
                if (empty($invoiceResult['skipped_existing']) && !empty($invoiceResult['invoice_id'])) {
                    $message .= ' Draft invoice #' . (int)$invoiceResult['invoice_id'] . ' created.';
                }
            } elseif (!preg_grep('/already exists/i', (array)($invoiceResult['errors'] ?? []))) {
                return ['ok' => false, 'errors' => $invoiceResult['errors'] ?? ['Unable to create the initial draft invoice.']];
            }
        }

        try {
            $syncroResult = syncro_contract_activation_sync($contractId);
            if (!empty($syncroResult['ok']) && empty($syncroResult['skipped'])) {
                if (db_column_exists('contract', 'syncro_pushed_at')) {
                    db()->prepare('UPDATE contract SET syncro_pushed_at = COALESCE(syncro_pushed_at, NOW()), updated_at = CURRENT_TIMESTAMP WHERE contract_id = ?')->execute([$contractId]);
                }
                accounting_contract_autocomplete_onboarding_tasks($contractId);
                $syncAction = (string)($syncroResult['action'] ?? 'synced');
                $message .= ' Syncro ' . $syncAction . ' successfully.';
            } elseif (!empty($syncroResult['errors'])) {
                $message .= ' Syncro sync pending: ' . implode(' ', array_map('strval', (array)$syncroResult['errors']));
            }
        } catch (Throwable $e) {
            error_log('Syncro activation sync failed: ' . $e->getMessage());
            $message .= ' Syncro sync pending.';
        }
    }
    return ['ok' => true, 'message' => $message];
}

function accounting_contract_upload_contract_file(int $contractId, array $file, string $kind): array {
    if ($contractId <= 0 || empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'errors' => ['Choose a PDF file to upload.']];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return ['ok' => false, 'errors' => ['Upload failed.']];
    $name = strtolower((string)($file['name'] ?? ''));
    if (!str_ends_with($name, '.pdf')) return ['ok' => false, 'errors' => ['Upload must be a PDF file.']];
    $root = dirname(__DIR__);
    $dir = $root . '/uploads/contracts';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return ['ok' => false, 'errors' => ['Unable to create uploads directory.']];
    $safeKind = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($kind)) ?: 'file';
    $targetName = 'contract-' . $contractId . '-' . $safeKind . '-' . date('Ymd-His') . '.pdf';
    $target = $dir . '/' . $targetName;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) return ['ok' => false, 'errors' => ['Unable to store uploaded file.']];
    return ['ok' => true, 'relative_path' => 'uploads/contracts/' . $targetName];
}

function accounting_contract_upload_signed_copy(int $contractId, array $file): array {
    $stored = accounting_contract_upload_contract_file($contractId, $file, 'signed');
    if (empty($stored['ok'])) return $stored;
    $meta = [
        'signed_document_path' => (string)$stored['relative_path'],
        'signed_date' => date('Y-m-d H:i:s'),
        'signed_by' => trim((string)(current_user()['full_name'] ?? current_user()['email'] ?? 'Uploaded signed copy')),
        'signed_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'onboarding_started_at' => date('Y-m-d H:i:s'),
    ];
    return accounting_contract_status_update($contractId, 'ONBOARDING', (int)(current_user()['user_id'] ?? 0), $meta);
}

function accounting_contract_upload_audit_copy(int $contractId, array $file): array {
    $stored = accounting_contract_upload_contract_file($contractId, $file, 'audit');
    if (empty($stored['ok'])) return $stored;
    if (!db_column_exists('contract', 'audit_document_path')) {
        return ['ok' => false, 'errors' => ['Run the audit trail SQL migration first.']];
    }
    db()->prepare('UPDATE contract SET audit_document_path = ?, updated_at = NOW() WHERE contract_id = ?')->execute([(string)$stored['relative_path'], $contractId]);
    return ['ok' => true, 'message' => 'Audit trail PDF uploaded.'];
}


function accounting_default_addon_unit_price(string $addonCode, float $packageUnitPrice, array $addon = []): float {
    $addonCode = strtoupper(trim($addonCode));
    if ($addonCode === 'SRVR-MGMT') {
        return round(max(0, $packageUnitPrice) + 15, 2);
    }
    return round((float)($addon['default_unit_price'] ?? 0), 2);
}

function accounting_allowed_addon_codes_for_package(string $packageCode, string $platform): array {
    $packageCode = strtoupper(trim($packageCode));
    $platform = strtoupper(trim($platform));
    $codes = ['EP-BKUP-X150', 'SRVR-MGMT', 'SRVR-BK-500', 'SRVR-BK-1000', 'SRVR-BK-1500', 'SRVR-BK-2000', 'FW-NETSEC'];
    if ($packageCode === 'ESSENTIAL') {
        array_unshift($codes, 'DNS-FLTR', 'EP-BKUP');
        if ($platform === 'M365') {
            $codes[] = 'M365-BKUP';
        } elseif ($platform === 'GW') {
            $codes[] = 'GW-BKUP';
        }
    } elseif ($packageCode === 'SECURE') {
        $codes[] = 'SAT-TRAIN';
    }
    return array_values(array_unique($codes));
}

function accounting_create_contract_bundle(array $data, int $userId = 0): array {
    if (!accounting_contract_ready()) return ['ok' => false, 'errors' => ['Contract tables are not ready.']];
    $packages = accounting_service_packages();
    $clientId = (int)($data['client_id'] ?? 0);
    $packageCode = strtoupper(trim((string)($data['package_code'] ?? 'ESSENTIAL')));
    $package = $packages[$packageCode] ?? null;
    $packagePricingModel = strtoupper(trim((string)($package['pricing_model'] ?? 'FIXED')));
    $selectedAddons = array_values(array_unique(array_map('intval', (array)($data['addon_item_ids'] ?? []))));
    $termMonths = (int)($data['term_months'] ?? ($package['term_months'] ?? 6));
    $startDate = trim((string)($data['start_date'] ?? date('Y-m-d')));
    $quantity = round((float)($data['quantity'] ?? 1), 2);
    $coveredUsers = round((float)($data['covered_users'] ?? 0), 2);
    $coveredDevices = round((float)($data['covered_devices'] ?? 0), 2);
    $serverCount = round((float)($data['server_count'] ?? 0), 2);
    $unitPrice = round((float)($data['unit_price'] ?? ($package['default_unit_price'] ?? 0)), 2);
    $billingCycle = strtoupper(trim((string)($data['billing_cycle'] ?? ($package['default_billing_cycle'] ?? 'MONTHLY'))));
    $status = strtoupper(trim((string)($data['status'] ?? 'DRAFT')));
    $contractName = trim((string)($data['contract_name'] ?? (($package['name'] ?? 'Managed Services') . ' Agreement')));
    $notes = trim((string)($data['notes'] ?? ''));
    $productivity = accounting_normalize_productivity_selection(
        $packageCode,
        (string)($data['productivity_platform'] ?? 'NONE'),
        (string)($data['productivity_license'] ?? 'NONE')
    );
    $productivityCatalog = accounting_productivity_catalog();
    $includedBaseLicense = accounting_productivity_included_base_license($packageCode, (string)($productivity['platform'] ?? 'NONE'));
    $includedBaseMeta = null;
    if (($productivity['platform'] ?? 'NONE') !== 'NONE' && $includedBaseLicense !== 'NONE') {
        $includedBaseMeta = $productivityCatalog[$productivity['platform']]['licenses'][$includedBaseLicense] ?? null;
    }
    $selectedLicenseMeta = null;
    if (($productivity['platform'] ?? 'NONE') !== 'NONE' && ($productivity['license'] ?? 'NONE') !== 'NONE') {
        $selectedLicenseMeta = $productivityCatalog[$productivity['platform']]['licenses'][$productivity['license']] ?? null;
    }

    if ($packagePricingModel === 'PER_DEVICE' && $coveredDevices <= 0) {
        $coveredDevices = $quantity;
    }
    if ($packagePricingModel === 'PER_USER' && $coveredUsers <= 0) {
        $coveredUsers = $quantity;
    }
    $errors = [];
    if (!$package) $errors[] = 'Choose a valid service package.';
    if ($quantity <= 0) $errors[] = ($packagePricingModel === 'PER_DEVICE' ? 'Covered devices must be greater than zero.' : 'Covered users must be greater than zero.');
    if ($coveredUsers < 0) $errors[] = 'Covered users cannot be negative.';
    if ($coveredDevices < 0) $errors[] = 'Covered devices cannot be negative.';
    if ($serverCount < 0) $errors[] = 'Covered servers cannot be negative.';
    if ($unitPrice < 0) $errors[] = 'Unit price cannot be negative.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $errors[] = 'Start date must be valid.';
    if (!in_array($billingCycle, ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL'], true)) $errors[] = 'Billing cycle must be valid.';
    if (!in_array($status, array_keys(accounting_contract_status_options()), true)) $status = 'DRAFT';
    if (($productivity['platform'] ?? 'NONE') !== 'NONE' && $coveredUsers <= 0) {
        $errors[] = 'Covered users must be greater than zero when a productivity platform is selected.';
    }

    $legalName = trim((string)($data['legal_name'] ?? ''));
    $dbaName = trim((string)($data['dba_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    if ($clientId <= 0 && $legalName === '') $errors[] = 'Client name is required for a new contract.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Client email is invalid.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $catalogItems = accounting_ensure_productivity_service_items();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($clientId <= 0) {
            require_once __DIR__ . '/clients.php';
            $clientId = client_create([
                'legal_name' => $legalName, 'dba_name' => $dbaName, 'status' => 'ACTIVE', 'email' => $email, 'phone' => $phone,
                'website' => trim((string)($data['website'] ?? '')), 'tax_exempt' => !empty($data['tax_exempt']) ? 1 : 0,
                'notes' => trim((string)($data['client_notes'] ?? '')),
            ]);
            $locationName = trim((string)($data['location_name'] ?? 'Main Office'));
            $address1 = trim((string)($data['address1'] ?? ''));
            if ($locationName !== '' || $address1 !== '') {
                client_add_location($clientId, ['location_name' => $locationName !== '' ? $locationName : 'Main Office', 'address1' => $address1, 'address2' => trim((string)($data['address2'] ?? '')), 'city' => trim((string)($data['city'] ?? '')), 'state' => trim((string)($data['state'] ?? '')), 'postal_code' => trim((string)($data['postal_code'] ?? '')), 'country' => trim((string)($data['country'] ?? 'US')), 'is_primary' => 1]);
            }
            $contactFirst = trim((string)($data['contact_first_name'] ?? ''));
            $contactLast = trim((string)($data['contact_last_name'] ?? ''));
            if ($contactFirst !== '' && $contactLast !== '') {
                client_add_contact($clientId, ['first_name' => $contactFirst, 'last_name' => $contactLast, 'title' => trim((string)($data['contact_title'] ?? '')), 'email' => trim((string)($data['contact_email'] ?? $email)), 'phone' => trim((string)($data['contact_phone'] ?? $phone)), 'is_primary' => 1, 'is_billing_contact' => 1, 'is_technical_contact' => 1]);
            }
        }

        $contractNumber = accounting_next_contract_number($pdo, $clientId);
        $endDate = $termMonths > 0 ? date('Y-m-d', strtotime($startDate . ' +' . $termMonths . ' months')) : null;
        $baseAmount = round($quantity * $unitPrice, 2);
        if ($packagePricingModel === 'PER_DEVICE') {
            $coveredDevices = max(1, $quantity);
        } elseif ($packagePricingModel === 'PER_USER') {
            $coveredUsers = max(1, $quantity);
        }
        $invoiceAutogenEnabled = !empty($data['create_draft_invoice']) ? 1 : 0;
        $ins = $pdo->prepare('INSERT INTO contract (client_id, contract_number, contract_name, contract_type, start_date, end_date, billing_cycle, sla_level, base_amount, covered_users, covered_devices, invoice_autogen_enabled, auto_renew, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$clientId, $contractNumber, $contractName, 'MSP', $startDate, $endDate, $billingCycle, $package['name'], $baseAmount, max(0, $coveredUsers), max(1, $coveredDevices), $invoiceAutogenEnabled, !empty($data['auto_renew']) ? 1 : 0, $status, $notes !== '' ? $notes : $package['description']]);
        $contractId = (int)$pdo->lastInsertId();

        $itemId = (int)($package['base_item_id'] ?? ($data['item_id'] ?? 1));
        $revenueAccountId = (int)($package['revenue_account_id'] ?? 0);
        if ($revenueAccountId <= 0) $revenueAccountId = accounting_find_account_id_by_code('4000') ?? (int)($data['revenue_account_id'] ?? 0);
        $taxable = !empty($package['is_taxable']) ? 1 : 0;

        $line = $pdo->prepare('INSERT INTO contract_service (contract_id, bundle_id, item_id, service_code, service_name, description, billing_type, quantity, unit_price, taxable, is_included, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $line->execute([$contractId, (int)($package['bundle_id'] ?? 0) ?: null, $itemId > 0 ? $itemId : null, 'MSP-' . $packageCode, $package['name'] . ' Service', $package['description'], $package['pricing_model'], $quantity, $unitPrice, $taxable, 0, 10]);
        $billableContractServiceId = (int)$pdo->lastInsertId();
        $sort = 20;
        foreach ((array)($package['included_services'] ?? []) as $included) {
            $line->execute([$contractId, (int)($package['bundle_id'] ?? 0) ?: null, null, null, $included, $included, 'FIXED', 1, 0, 0, 1, $sort]);
            $sort += 10;
        }

        $rs = $pdo->prepare('INSERT INTO recurring_service (client_id, contract_id, contract_service_id, item_id, description, item_type, billing_type, billing_cycle, quantity, unit_price, taxable, next_bill_date, term_months, last_billed_date, active, auto_renew, start_date, end_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)');
        $rs->execute([$clientId, $contractId, $billableContractServiceId, $itemId, $package['name'] . ' Service', 'SERVICE', $package['pricing_model'], $billingCycle, $quantity, $unitPrice, $taxable, $startDate, $termMonths > 0 ? $termMonths : null, $status === 'ACTIVE' ? 1 : 0, !empty($data['auto_renew']) ? 1 : 0, $startDate, $endDate, $notes !== '' ? $notes : null]);
        $recurringId = (int)$pdo->lastInsertId();

        $cs = $pdo->prepare('INSERT INTO client_service (client_id, contract_id, item_id, recurring_service_id, parent_client_service_id, pricing_model, description, quantity, unit_price, billing_cycle, term_months, start_date, next_bill_date, end_date, revenue_account_id, taxable, auto_renew, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $cs->execute([$clientId, $contractId, $itemId, $recurringId, null, $package['pricing_model'], $package['name'] . ' Service', $quantity, $unitPrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $startDate, $startDate, $endDate, $revenueAccountId, $taxable, !empty($data['auto_renew']) ? 1 : 0, $status === 'ACTIVE' ? 'ACTIVE' : 'PAUSED', $notes !== '' ? $notes : null, $userId ?: null]);
        $clientServiceId = (int)$pdo->lastInsertId();

        $addonMap = [];
        foreach ((array)($package['addon_services'] ?? []) as $addon) $addonMap[(int)$addon['item_id']] = $addon;
        $allowedAddonCodes = accounting_allowed_addon_codes_for_package($packageCode, (string)($productivity['platform'] ?? 'NONE'));
        foreach ($selectedAddons as $addonItemId) {
            $addon = $addonMap[$addonItemId] ?? null;
            if (!$addon) continue;
            $addonPricingModel = strtoupper(trim((string)($addon['pricing_model'] ?? 'FIXED')));
            $addonCode = strtoupper(trim((string)($addon['item_code'] ?? '')));
            if (!in_array($addonCode, $allowedAddonCodes, true)) {
                continue;
            }
            if ($addonCode === 'SRVR-MGMT' || str_starts_with($addonCode, 'SRVR-BK-')) {
                $defaultAddonQty = max(0, $serverCount);
            } else {
                $defaultAddonQty = $addonPricingModel === 'PER_DEVICE' ? max(1, $coveredDevices) : ($addonPricingModel === 'PER_USER' ? max(0, $coveredUsers) : max(1, $quantity));
            }
            $addonQty = (float)($data['addon_qty'][$addonItemId] ?? $defaultAddonQty);
            if ($addonCode === 'SRVR-MGMT' || str_starts_with($addonCode, 'SRVR-BK-')) {
                if ($addonQty <= 0) {
                    throw new RuntimeException(($addon['item_name'] ?? 'Server add-on') . ' requires a server quantity greater than zero.');
                }
            } elseif ($addonPricingModel === 'PER_USER') {
                if ($addonQty <= 0) {
                    throw new RuntimeException(($addon['item_name'] ?? 'Selected add-on') . ' requires a user quantity greater than zero.');
                }
            } else {
                $addonQty = max(1, $addonQty);
            }
            $defaultAddonPrice = accounting_default_addon_unit_price($addonCode, $unitPrice, $addon);
            $addonPrice = round((float)($data['addon_price'][$addonItemId] ?? $defaultAddonPrice), 2);
            $addonCycle = strtoupper(trim((string)($data['addon_cycle'][$addonItemId] ?? ($addon['billing_cycle'] ?? $billingCycle))));
            $addonTaxable = !empty($addon['taxable']) ? 1 : 0;
            $addonRevenue = (int)($addon['revenue_account_id'] ?? $revenueAccountId);
            $line->execute([$contractId, (int)($package['bundle_id'] ?? 0) ?: null, $addonItemId, (string)($addon['item_code'] ?: null), (string)$addon['item_name'], (string)($addon['description'] ?: $addon['item_name']), $addonPricingModel, $addonQty, $addonPrice, $addonTaxable, 0, $sort]);
            $addonContractServiceId = (int)$pdo->lastInsertId();
            $sort += 10;
            $rs->execute([$clientId, $contractId, $addonContractServiceId, $addonItemId, (string)($addon['description'] ?: $addon['item_name']), 'SERVICE', $addonPricingModel, $addonCycle, $addonQty, $addonPrice, $addonTaxable, $startDate, $termMonths > 0 ? $termMonths : null, $status === 'ACTIVE' ? 1 : 0, !empty($data['auto_renew']) ? 1 : 0, $startDate, $endDate, 'Bundle add-on']);
            $addonRecurringId = (int)$pdo->lastInsertId();
            $cs->execute([$clientId, $contractId, $addonItemId, $addonRecurringId, $clientServiceId, $addonPricingModel, (string)$addon['item_name'], $addonQty, $addonPrice, $addonCycle, $termMonths > 0 ? $termMonths : null, $startDate, $startDate, $endDate, $addonRevenue, $addonTaxable, !empty($data['auto_renew']) ? 1 : 0, $status === 'ACTIVE' ? 'ACTIVE' : 'PAUSED', 'Bundle add-on', $userId ?: null]);
        }

        if (($productivity['platform'] ?? 'NONE') !== 'NONE' && ($productivity['license'] ?? 'NONE') === 'NONE' && $includedBaseMeta) {
            $includedCode = (string)$includedBaseMeta['item_code'];
            $includedItem = $catalogItems[$includedCode] ?? accounting_find_service_item_by_code($includedCode);
            if ($includedItem) {
                $includedDescription = (string)($includedItem['description'] ?: $includedItem['item_name']);
                $includedDescription .= ' Included with Protect IT when a productivity platform is selected.';
                $line->execute([$contractId, (int)($package['bundle_id'] ?? 0) ?: null, (int)$includedItem['item_id'], (string)$includedItem['item_code'], (string)$includedItem['item_name'], $includedDescription, 'PER_USER', max(0, $coveredUsers), 0, 0, 1, $sort]);
                $sort += 10;
            }
        }

        if ($selectedLicenseMeta) {
            $licenseCode = (string)$selectedLicenseMeta['item_code'];
            $licenseItem = $catalogItems[$licenseCode] ?? accounting_find_service_item_by_code($licenseCode);
            if (!$licenseItem) {
                throw new RuntimeException('Unable to create the selected productivity license catalog item.');
            }
            $licenseQty = max(0, $coveredUsers);
            $defaultLicensePrice = (float)($licenseItem['default_unit_price'] ?? $selectedLicenseMeta['default_unit_price'] ?? 0);
            if ($packageCode === 'SECURE' && $includedBaseMeta) {
                $defaultLicensePrice = max(0, $defaultLicensePrice - (float)($includedBaseMeta['default_unit_price'] ?? 0));
            }
            $licensePrice = round((float)($data['productivity_price'] ?? $defaultLicensePrice), 2);
            $licenseRevenue = (int)($licenseItem['revenue_account_id'] ?? $revenueAccountId);
            $licenseDescription = (string)($licenseItem['description'] ?: $licenseItem['item_name']);
            if ($packageCode === 'SECURE' && $includedBaseMeta) {
                $licenseDescription .= ' Upgrade pricing reflects the difference above the included base productivity license.';
            }
            $line->execute([$contractId, (int)($package['bundle_id'] ?? 0) ?: null, (int)$licenseItem['item_id'], (string)$licenseItem['item_code'], (string)$licenseItem['item_name'], $licenseDescription, 'PER_USER', $licenseQty, $licensePrice, !empty($licenseItem['is_taxable']) ? 1 : 0, 0, $sort]);
            $licenseContractServiceId = (int)$pdo->lastInsertId();
            $sort += 10;
            $rs->execute([$clientId, $contractId, $licenseContractServiceId, (int)$licenseItem['item_id'], $licenseDescription, 'SERVICE', 'PER_USER', $billingCycle, $licenseQty, $licensePrice, !empty($licenseItem['is_taxable']) ? 1 : 0, $startDate, $termMonths > 0 ? $termMonths : null, $status === 'ACTIVE' ? 1 : 0, !empty($data['auto_renew']) ? 1 : 0, $startDate, $endDate, 'Selected productivity platform and license']);
            $licenseRecurringId = (int)$pdo->lastInsertId();
            $cs->execute([$clientId, $contractId, (int)$licenseItem['item_id'], $licenseRecurringId, $clientServiceId, 'PER_USER', (string)$licenseItem['item_name'], $licenseQty, $licensePrice, $billingCycle, $termMonths > 0 ? $termMonths : null, $startDate, $startDate, $endDate, $licenseRevenue, !empty($licenseItem['is_taxable']) ? 1 : 0, !empty($data['auto_renew']) ? 1 : 0, $status === 'ACTIVE' ? 'ACTIVE' : 'PAUSED', 'Selected productivity platform and license', $userId ?: null]);
        }

        $pdo->commit();
        $message = 'Contract, client service, and recurring billing created.';
        if (!empty($data['create_draft_invoice']) && $status === 'ACTIVE') {
            $invoiceResult = accounting_generate_contract_initial_invoice($contractId, $userId, $startDate);
            if (empty($invoiceResult['ok']) && empty($invoiceResult['skipped_existing'])) {
                return ['ok' => false, 'errors' => $invoiceResult['errors'] ?? ['Contract saved, but the initial draft invoice could not be created.']];
            }
            if (!empty($invoiceResult['invoice_id'])) {
                $message .= ' Draft invoice #' . (int)$invoiceResult['invoice_id'] . ' created.';
            }
        }
        if ($status === 'ACTIVE') {
            try {
                $syncroResult = syncro_contract_activation_sync($contractId);
                if (!empty($syncroResult['ok']) && empty($syncroResult['skipped'])) {
                    $message .= ' Syncro ' . (string)($syncroResult['action'] ?? 'synced') . ' successfully.';
                } elseif (!empty($syncroResult['errors'])) {
                    $message .= ' Syncro sync pending: ' . implode(' ', array_map('strval', (array)$syncroResult['errors']));
                }
            } catch (Throwable $e) {
                error_log('Syncro contract create sync failed: ' . $e->getMessage());
                $message .= ' Syncro sync pending.';
            }
        }
        return ['ok' => true, 'contract_id' => $contractId, 'client_id' => $clientId, 'client_service_id' => $clientServiceId, 'message' => $message];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Unable to create contract bundle: ' . $e->getMessage()]];
    }
}
