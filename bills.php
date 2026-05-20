<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
header('Location: ' . BASE_URL . '/accounting/bills.php', true, 302);
exit;
