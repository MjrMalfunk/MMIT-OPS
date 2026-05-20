<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';

require_login();
accounting_require_ready();
csrf_check();

$expenseId = (int)($_POST['expense_id'] ?? $_GET['expense_id'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? 'bills'));
$allowedTargets = [
    'bills' => '/accounting/bills.php',
    'expenses' => '/accounting/expenses.php',
];
$redirectPath = $allowedTargets[$returnTo] ?? '/accounting/bills.php';
$userId = (int)(current_user()['user_id'] ?? 0);

$result = accounting_approve_bill($expenseId, $userId);
if (!empty($result['ok'])) {
    header('Location: ' . BASE_URL . $redirectPath . '?approved=1');
    exit;
}

$error = rawurlencode(implode(' ', $result['errors'] ?? ['Unable to approve bill.']));
header('Location: ' . BASE_URL . $redirectPath . '?error=' . $error);
exit;
