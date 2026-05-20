<?php
declare(strict_types=1);

function render_pills(string $active = ''): void
{
    $items = [
        'dashboard' => ['/dashboard/index.php', 'Dashboard'],
        'clients'   => ['/clients/index.php', 'Clients'],
        'contracts' => ['/contracts/index.php', 'Contracts'],
        'products'  => ['/products/index.php', 'Products'],
        'licenses'  => ['/licenses/index.php', 'Licenses'],
        'accounting'=> ['/accounting/index.php', 'Accounting'],
        'admin'     => ['/admin/index.php', 'Admin'],
        'logout'    => ['/logout.php', 'Logout'],
    ];

    echo '<div class="navwrap"><nav class="nav" aria-label="Primary">';
    foreach ($items as $key => [$href, $label]) {
        $cls = 'navpill' . ($key === $active ? ' active' : '');
        echo '<a class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars(BASE_URL . $href, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</nav></div>';
}
