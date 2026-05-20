<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();

$summary = accounting_summary();
$recentExpenses = accounting_recent_expenses(6);
$recentInvoices = accounting_recent_invoices(6);

$groups = [
    'Payables' => [
        ['label' => 'Expenses Entered', 'value' => (string) $summary['expense_count']],
        ['label' => 'Draft Expenses', 'value' => (string) $summary['draft_expense_count']],
        ['label' => 'Open Bills', 'value' => (string) $summary['open_bill_count']],
        ['label' => 'Month-to-Date Spend', 'value' => '$' . number_format((float) $summary['mtd_expense_total'], 2)],
    ],
    'Receivables' => [
        ['label' => 'Draft Invoices', 'value' => (string) $summary['draft_invoice_count']],
        ['label' => 'Overdue Invoices', 'value' => (string) $summary['overdue_invoice_count']],
        ['label' => 'Open A/R', 'value' => '$' . number_format((float) $summary['open_receivable_total'], 2)],
        ['label' => 'Month-to-Date Collected', 'value' => '$' . number_format((float) ($summary['mtd_collected_total'] ?? 0), 2)],
    ],
];

$quickActions = [
    ['href' => BASE_URL . '/accounting/accounts.php', 'label' => 'Manage chart of accounts'],
    ['href' => BASE_URL . '/accounting/vendors.php', 'label' => 'Add or edit vendors'],
    ['href' => BASE_URL . '/accounting/bills.php', 'label' => 'Enter vendor bills'],
    ['href' => BASE_URL . '/products/index.php', 'label' => 'Manage products & services'],
    ['href' => BASE_URL . '/clients/services.php', 'label' => 'Assign services to clients'],
    ['href' => BASE_URL . '/accounting/recurring.php', 'label' => 'Set up recurring billing'],
    ['href' => BASE_URL . '/accounting/invoice_new.php', 'label' => 'Create one-off / project invoice'],
    ['href' => BASE_URL . '/accounting/receive_payment.php', 'label' => 'Receive customer payment'],
    ['href' => BASE_URL . '/accounting/invoices.php', 'label' => 'Review invoice register'],
    ['href' => BASE_URL . '/accounting/receivables.php', 'label' => 'Review A/R aging'],
    ['href' => BASE_URL . '/payments/index.php', 'label' => 'Open payment register'],
    ['href' => BASE_URL . '/accounting/reconcile.php', 'label' => 'Reconcile bank account'],
    ['href' => BASE_URL . '/accounting/capital.php', 'label' => 'Post owner funding / draw'],
];

page_header('Accounting', 'accounting');
accounting_subnav('home');
?>
<style>
.accounting-dashboard { display:grid; gap:16px; }
.accounting-top-grid { display:grid; grid-template-columns:minmax(0, 1.8fr) minmax(320px, 1fr); gap:16px; align-items:start; }
.accounting-group-grid { display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr)); }
.accounting-group { padding:16px; height:100%; }
.accounting-group h2 { margin:0 0 12px; font-size:18px; }
.accounting-group .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.accounting-main-column, .accounting-side-column { display:grid; gap:16px; }
.accounting-main-split { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:16px; align-items:start; }
.accounting-panel { padding:16px; }
.accounting-panel-header { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; margin-bottom:12px; }
.accounting-panel-title { margin:0; font-size:18px; }
.accounting-panel-subtitle { opacity:.75; font-size:14px; }
.accounting-panel-link { white-space:nowrap; }
.accounting-actions { display:grid; gap:10px; }
.accounting-action-link { padding:12px 14px; border-radius:12px; border:1px solid rgba(59,130,246,.22); background:rgba(59,130,246,.10); color:#dbeafe; text-decoration:none; transition:background-color .15s ease, transform .15s ease; }
.accounting-action-link:hover { background:rgba(59,130,246,.16); transform:translateY(-1px); }
.accounting-summary-strip { display:grid; gap:12px; grid-template-columns:repeat(5, minmax(0, 1fr)); }
.accounting-summary-mini { padding:14px 16px; }
.accounting-summary-mini .metric-value { font-size:22px; }
.accounting-side-column .accounting-panel { height:100%; }
@media (max-width: 1180px) { .accounting-top-grid, .accounting-main-split { grid-template-columns:1fr; } }
@media (max-width: 960px) { .accounting-group-grid { grid-template-columns:1fr; } .accounting-summary-strip { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
@media (max-width: 720px) { .accounting-panel-header { align-items:flex-start; flex-direction:column; } .accounting-panel-link { white-space:normal; } .accounting-group .metric-grid, .accounting-summary-strip { grid-template-columns:1fr; } }
</style>

<div class="accounting-dashboard">
    <div class="accounting-top-grid">
        <div class="accounting-main-column">
            <div class="accounting-group-grid">
                <?php foreach ($groups as $groupLabel => $cards): ?>
                    <section class="card accounting-group">
                        <h2><?= accounting_h($groupLabel) ?></h2>
                        <div class="metric-grid">
                            <?php foreach ($cards as $card): ?>
                                <div class="card metric-card">
                                    <div class="metric-label"><?= accounting_h($card['label']) ?></div>
                                    <div class="metric-value"><?= accounting_h($card['value']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="accounting-side-column">
            <section class="card accounting-panel">
                <div class="accounting-panel-header">
                    <div>
                        <h2 class="accounting-panel-title">Quick actions</h2>
                        <div class="accounting-panel-subtitle">Most-used accounting jumps in one place.</div>
                    </div>
                </div>
                <div class="accounting-actions">
                    <?php foreach ($quickActions as $action): ?>
                        <a class="accounting-action-link" href="<?= accounting_h($action['href']) ?>"><?= accounting_h($action['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>

    <div class="accounting-summary-strip">
        <div class="card accounting-summary-mini"><div class="metric-label">Active Accounts</div><div class="metric-value"><?= (int) $summary['account_count'] ?></div></div>
        <div class="card accounting-summary-mini"><div class="metric-label">Active Vendors</div><div class="metric-value"><?= (int) $summary['vendor_count'] ?></div></div>
        <div class="card accounting-summary-mini"><div class="metric-label">Catalog Items</div><div class="metric-value"><?= (int) $summary['catalog_item_count'] ?></div></div>
        <div class="card accounting-summary-mini"><div class="metric-label">Recurring Items</div><div class="metric-value"><?= (int) $summary['recurring_item_count'] ?></div></div>
        <div class="card accounting-summary-mini"><div class="metric-label">Client Services</div><div class="metric-value"><?= (int) ($summary['client_service_count'] ?? 0) ?></div></div>
    </div>

            <div class="accounting-main-split">
                <section class="card accounting-panel table-shell">
                    <div class="accounting-panel-header">
                        <div>
                            <h2 class="accounting-panel-title">Recent expenses</h2>
                            <div class="accounting-panel-subtitle">Latest bookkeeping activity and draft/vendor spend visibility.</div>
                        </div>
                        <a class="accounting-panel-link" href="<?= accounting_h(BASE_URL) ?>/accounting/expenses.php">Open expenses →</a>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Vendor</th>
                                <th>Category</th>
                                <th class="status-cell">Status</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentExpenses): ?>
                                <tr><td colspan="5" class="empty-state">No expenses have been entered yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentExpenses as $row): ?>
                                    <tr>
                                        <td><?= accounting_h((string) $row['expense_date']) ?></td>
                                        <td><?= accounting_h((string) ($row['vendor_name'] ?: '—')) ?></td>
                                        <td><?= accounting_h((string) ($row['account_name'] ?: 'Unassigned')) ?></td>
                                        <td class="status-cell"><?= accounting_h((string) $row['status']) ?></td>
                                        <td class="num">$<?= number_format((float) $row['total_amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="card accounting-panel table-shell">
                    <div class="accounting-panel-header">
                        <div>
                            <h2 class="accounting-panel-title">Recent invoices</h2>
                            <div class="accounting-panel-subtitle">Recurring services and one-off work both land here for review.</div>
                        </div>
                        <a class="accounting-panel-link" href="<?= accounting_h(BASE_URL) ?>/accounting/invoices.php">Open invoices →</a>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Client</th>
                                <th>Date</th>
                                <th class="status-cell">Status</th>
                                <th class="num">Amount</th>
                                <th class="num">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentInvoices): ?>
                                <tr><td colspan="6" class="empty-state">No invoices have been generated yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentInvoices as $row): ?>
                                    <tr>
                                        <td><?= accounting_invoice_link_html((int) $row['invoice_id'], (string) $row['invoice_number']) ?></td>
                                        <td><?= accounting_h((string) ($row['dba_name'] ?: $row['legal_name'])) ?></td>
                                        <td><?= accounting_h((string) $row['invoice_date']) ?></td>
                                        <td class="status-cell"><?= accounting_invoice_status_badge_html($row) ?></td>
                                        <td class="num">$<?= number_format((float) $row['total_amount'], 2) ?></td>
                                        <td class="num">$<?= number_format((float) $row['balance_due'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
    </div>
</div>
<?php page_footer(); ?>
