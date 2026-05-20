<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
header('Location: ' . BASE_URL . '/accounting/expenses.php', true, 302);
exit;
