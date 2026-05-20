<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function client_slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'client';
}

function client_unique_slug(PDO $pdo, string $baseSlug, ?int $excludeId = null): string
{
    $slug = $baseSlug;
    $i = 2;
    while (true) {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare('SELECT client_id FROM clients WHERE client_slug = ? AND client_id <> ? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $pdo->prepare('SELECT client_id FROM clients WHERE client_slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $i;
        $i++;
    }
}

function client_next_code(PDO $pdo): string
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    return sprintf('CL-%04d', $count + 1);
}

function client_create(array $data): int
{
    $pdo = db();
    $legalName = trim((string)($data['legal_name'] ?? ''));
    $dbaName = trim((string)($data['dba_name'] ?? ''));
    $status = trim((string)($data['status'] ?? 'LEAD'));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $website = trim((string)($data['website'] ?? ''));
    $taxExempt = !empty($data['tax_exempt']) ? 1 : 0;
    $notes = trim((string)($data['notes'] ?? ''));

    if ($legalName === '') throw new InvalidArgumentException('Legal name is required.');
    if ($email === '') throw new InvalidArgumentException('Email is required for Syncro-ready clients.');
    if ($phone === '') throw new InvalidArgumentException('Phone is required for Syncro-ready clients.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email address is invalid.');
    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Website URL is invalid.');

    $allowed = ['LEAD', 'ACTIVE', 'SUSPENDED', 'FORMER'];
    if (!in_array($status, $allowed, true)) $status = 'LEAD';

    $clientCode = client_next_code($pdo);
    $clientSlug = client_unique_slug($pdo, client_slugify($legalName));

    $stmt = $pdo->prepare('INSERT INTO clients (client_code, client_slug, legal_name, dba_name, status, email, phone, website, tax_exempt, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $clientCode,
        $clientSlug,
        $legalName,
        $dbaName !== '' ? $dbaName : null,
        $status,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $website !== '' ? $website : null,
        $taxExempt,
        $notes !== '' ? $notes : null,
    ]);

    return (int)$pdo->lastInsertId();
}


function client_update(int $clientId, array $data): void
{
    $pdo = db();
    $client = client_get_by_id($clientId);
    if (!$client) throw new InvalidArgumentException('Client not found.');

    $legalName = trim((string)($data['legal_name'] ?? ''));
    $dbaName = trim((string)($data['dba_name'] ?? ''));
    $status = trim((string)($data['status'] ?? 'LEAD'));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $website = trim((string)($data['website'] ?? ''));
    $taxExempt = !empty($data['tax_exempt']) ? 1 : 0;
    $notes = trim((string)($data['notes'] ?? ''));

    if ($legalName === '') throw new InvalidArgumentException('Legal name is required.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email address is invalid.');
    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Website URL is invalid.');

    $allowed = ['LEAD', 'ACTIVE', 'SUSPENDED', 'FORMER'];
    if (!in_array($status, $allowed, true)) $status = 'LEAD';

    $clientSlug = client_unique_slug($pdo, client_slugify($legalName), $clientId);

    $stmt = $pdo->prepare('UPDATE clients SET client_slug = ?, legal_name = ?, dba_name = ?, status = ?, email = ?, phone = ?, website = ?, tax_exempt = ?, notes = ? WHERE client_id = ?');
    $stmt->execute([
        $clientSlug,
        $legalName,
        $dbaName !== '' ? $dbaName : null,
        $status,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $website !== '' ? $website : null,
        $taxExempt,
        $notes !== '' ? $notes : null,
        $clientId,
    ]);
}

function client_get_location_by_id(int $locationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM client_location WHERE location_id = ? LIMIT 1');
    $stmt->execute([$locationId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function client_update_location(int $locationId, array $data): void
{
    $existing = client_get_location_by_id($locationId);
    if (!$existing) throw new InvalidArgumentException('Location not found.');
    $pdo = db();
    $name = trim((string)($data['location_name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Location name is required.');
    $address1 = trim((string)($data['address1'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $state = trim((string)($data['state'] ?? ''));
    $postalCode = trim((string)($data['postal_code'] ?? ''));
    $country = trim((string)($data['country'] ?? '')) ?: 'US';
    if ($address1 === '' || $city === '' || $state === '' || $postalCode === '') throw new InvalidArgumentException('Address, city, state, and postal code are required for Syncro-ready locations.');
    $stmt = $pdo->prepare('UPDATE client_location SET location_name = ?, address1 = ?, address2 = ?, city = ?, state = ?, postal_code = ?, country = ?, is_primary = ?, notes = ? WHERE location_id = ?');
    $stmt->execute([
        $name,
        $address1,
        trim((string)($data['address2'] ?? '')) ?: null,
        $city,
        $state,
        $postalCode,
        $country,
        !empty($data['is_primary']) ? 1 : 0,
        trim((string)($data['notes'] ?? '')) ?: null,
        $locationId,
    ]);
}

function client_get_contact_by_id(int $contactId): ?array
{
    $stmt = db()->prepare('SELECT * FROM client_contact WHERE contact_id = ? LIMIT 1');
    $stmt->execute([$contactId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function client_update_contact(int $contactId, array $data): void
{
    $existing = client_get_contact_by_id($contactId);
    if (!$existing) throw new InvalidArgumentException('Contact not found.');
    $pdo = db();
    $first = trim((string)($data['first_name'] ?? ''));
    $last = trim((string)($data['last_name'] ?? ''));
    if ($first === '' || $last === '') throw new InvalidArgumentException('First and last name are required.');
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email address is invalid.');
    $locationId = isset($data['location_id']) && $data['location_id'] !== '' ? (int)$data['location_id'] : null;
    $stmt = $pdo->prepare('UPDATE client_contact SET location_id = ?, first_name = ?, last_name = ?, title = ?, email = ?, phone = ?, mobile = ?, is_primary = ?, is_billing_contact = ?, is_technical_contact = ?, notes = ? WHERE contact_id = ?');
    $stmt->execute([
        $locationId,
        $first,
        $last,
        trim((string)($data['title'] ?? '')) ?: null,
        $email !== '' ? $email : null,
        trim((string)($data['phone'] ?? '')) ?: null,
        trim((string)($data['mobile'] ?? '')) ?: null,
        !empty($data['is_primary']) ? 1 : 0,
        !empty($data['is_billing_contact']) ? 1 : 0,
        !empty($data['is_technical_contact']) ? 1 : 0,
        trim((string)($data['notes'] ?? '')) ?: null,
        $contactId,
    ]);
}

function client_get_all(): array
{
    $stmt = db()->query('SELECT c.*, (SELECT COUNT(*) FROM contract ctr WHERE ctr.client_id = c.client_id AND ctr.status = "ACTIVE") AS active_contract_count FROM clients c ORDER BY c.legal_name ASC');
    return $stmt->fetchAll();
}

function client_get_by_id(int $clientId): ?array
{
    $stmt = db()->prepare('SELECT * FROM clients WHERE client_id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function client_get_locations(int $clientId): array
{
    $stmt = db()->prepare('SELECT * FROM client_location WHERE client_id = ? ORDER BY is_primary DESC, location_name ASC');
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

function client_get_contacts(int $clientId): array
{
    $stmt = db()->prepare('SELECT cc.*, cl.location_name FROM client_contact cc LEFT JOIN client_location cl ON cl.location_id = cc.location_id WHERE cc.client_id = ? ORDER BY cc.is_primary DESC, cc.last_name ASC, cc.first_name ASC');
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

function client_add_location(int $clientId, array $data): int
{
    $pdo = db();
    $name = trim((string)($data['location_name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Location name is required.');
    $address1 = trim((string)($data['address1'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $state = trim((string)($data['state'] ?? ''));
    $postalCode = trim((string)($data['postal_code'] ?? ''));
    $country = trim((string)($data['country'] ?? '')) ?: 'US';
    if ($address1 === '' || $city === '' || $state === '' || $postalCode === '') throw new InvalidArgumentException('Address, city, state, and postal code are required for Syncro-ready locations.');
    $stmt = $pdo->prepare('INSERT INTO client_location (client_id, location_name, address1, address2, city, state, postal_code, country, is_primary, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $clientId,
        $name,
        $address1,
        trim((string)($data['address2'] ?? '')) ?: null,
        $city,
        $state,
        $postalCode,
        $country,
        !empty($data['is_primary']) ? 1 : 0,
        trim((string)($data['notes'] ?? '')) ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

function client_add_contact(int $clientId, array $data): int
{
    $pdo = db();
    $first = trim((string)($data['first_name'] ?? ''));
    $last = trim((string)($data['last_name'] ?? ''));
    if ($first === '' || $last === '') throw new InvalidArgumentException('First and last name are required.');
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email address is invalid.');
    $locationId = isset($data['location_id']) && $data['location_id'] !== '' ? (int)$data['location_id'] : null;
    $stmt = $pdo->prepare('INSERT INTO client_contact (client_id, location_id, first_name, last_name, title, email, phone, mobile, is_primary, is_billing_contact, is_technical_contact, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $clientId,
        $locationId,
        $first,
        $last,
        trim((string)($data['title'] ?? '')) ?: null,
        $email !== '' ? $email : null,
        trim((string)($data['phone'] ?? '')) ?: null,
        trim((string)($data['mobile'] ?? '')) ?: null,
        !empty($data['is_primary']) ? 1 : 0,
        !empty($data['is_billing_contact']) ? 1 : 0,
        !empty($data['is_technical_contact']) ? 1 : 0,
        trim((string)($data['notes'] ?? '')) ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

function client_normalize_match_text(?string $value): string
{
    $value = trim(mb_strtolower((string)$value, 'UTF-8'));
    if ($value === '') return '';
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function client_normalize_phone(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    if (strlen($digits) > 10 && str_starts_with($digits, '1')) {
        $digits = substr($digits, -10);
    }
    return $digits;
}

function client_find_existing_match(array $data, ?int $excludeClientId = null): ?array
{
    $pdo = db();
    $sql = 'SELECT c.*,
                   cl.location_id AS primary_location_id,
                   cl.location_name AS primary_location_name,
                   cl.address1 AS primary_address1,
                   cl.address2 AS primary_address2,
                   cl.city AS primary_city,
                   cl.state AS primary_state,
                   cl.postal_code AS primary_postal_code,
                   cl.country AS primary_country,
                   cc.contact_id AS primary_contact_id,
                   cc.first_name AS primary_contact_first_name,
                   cc.last_name AS primary_contact_last_name,
                   cc.title AS primary_contact_title,
                   cc.email AS primary_contact_email,
                   cc.phone AS primary_contact_phone
            FROM clients c
            LEFT JOIN client_location cl ON cl.client_id = c.client_id AND cl.is_primary = 1
            LEFT JOIN client_contact cc ON cc.client_id = c.client_id AND cc.is_primary = 1';
    $params = [];
    if ($excludeClientId !== null && $excludeClientId > 0) {
        $sql .= ' WHERE c.client_id <> ?';
        $params[] = $excludeClientId;
    }
    $sql .= ' ORDER BY c.client_id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $targetLegal = client_normalize_match_text((string)($data['legal_name'] ?? ''));
    $targetDba = client_normalize_match_text((string)($data['dba_name'] ?? ''));
    $targetEmail = client_normalize_match_text((string)($data['email'] ?? ''));
    $targetPhone = client_normalize_phone((string)($data['phone'] ?? ''));
    $targetCity = client_normalize_match_text((string)($data['city'] ?? ''));
    $targetState = client_normalize_match_text((string)($data['state'] ?? ''));
    $targetZip = client_normalize_match_text((string)($data['postal_code'] ?? ''));
    $targetCompanyNames = array_values(array_filter(array_unique([$targetLegal, $targetDba])));

    $best = null;
    foreach ($rows as $row) {
        $companyNames = array_values(array_filter(array_unique([
            client_normalize_match_text((string)($row['legal_name'] ?? '')),
            client_normalize_match_text((string)($row['dba_name'] ?? '')),
        ])));
        $companyMatch = false;
        foreach ($targetCompanyNames as $targetName) {
            if ($targetName === '') continue;
            if (in_array($targetName, $companyNames, true)) {
                $companyMatch = true;
                break;
            }
        }
        $emailMatch = $targetEmail !== '' && in_array($targetEmail, array_filter([
            client_normalize_match_text((string)($row['email'] ?? '')),
            client_normalize_match_text((string)($row['primary_contact_email'] ?? '')),
        ]), true);
        $phoneMatch = $targetPhone !== '' && in_array($targetPhone, array_filter([
            client_normalize_phone((string)($row['phone'] ?? '')),
            client_normalize_phone((string)($row['primary_contact_phone'] ?? '')),
        ]), true);
        $cityMatch = $targetCity !== '' && $targetCity === client_normalize_match_text((string)($row['primary_city'] ?? ''));
        $stateMatch = $targetState !== '' && $targetState === client_normalize_match_text((string)($row['primary_state'] ?? ''));
        $zipMatch = $targetZip !== '' && $targetZip === client_normalize_match_text((string)($row['primary_postal_code'] ?? ''));

        $score = 0;
        $signals = [];
        if ($companyMatch) { $score += 10; $signals[] = 'company'; }
        if ($emailMatch) { $score += 12; $signals[] = 'email'; }
        if ($phoneMatch) { $score += 6; $signals[] = 'phone'; }
        if ($cityMatch) { $score += 2; $signals[] = 'city'; }
        if ($stateMatch) { $score += 2; $signals[] = 'state'; }
        if ($zipMatch) { $score += 3; $signals[] = 'zip'; }

        $confident = ($emailMatch && $companyMatch)
            || ($companyMatch && ($zipMatch || ($cityMatch && $stateMatch)))
            || ($emailMatch && ($zipMatch || ($cityMatch && $stateMatch) || $phoneMatch));

        if (!$confident) {
            continue;
        }

        if ($best === null || $score > $best['score']) {
            $row['match_score'] = $score;
            $row['match_signals'] = $signals;
            $best = $row;
        }
    }

    return $best;
}


function client_delete_summary(int $clientId): array
{
    $pdo = db();
    $summary = [
        'client' => client_get_by_id($clientId),
        'locations' => 0,
        'contacts' => 0,
        'contracts' => 0,
        'services' => 0,
        'recurring_services' => 0,
        'invoices' => 0,
        'payments' => 0,
        'expenses' => 0,
        'can_delete' => false,
        'blockers' => [],
    ];
    if (!$summary['client']) {
        return $summary;
    }
    $counts = [
        'locations' => 'SELECT COUNT(*) FROM client_location WHERE client_id = ?',
        'contacts' => 'SELECT COUNT(*) FROM client_contact WHERE client_id = ?',
        'contracts' => 'SELECT COUNT(*) FROM contract WHERE client_id = ?',
        'services' => 'SELECT COUNT(*) FROM client_service WHERE client_id = ?',
        'recurring_services' => 'SELECT COUNT(*) FROM recurring_service WHERE client_id = ?',
        'invoices' => 'SELECT COUNT(*) FROM customer_invoice WHERE client_id = ?',
        'payments' => 'SELECT COUNT(*) FROM payment_receipt WHERE client_id = ?',
    ];
    foreach ($counts as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$clientId]);
        $summary[$key] = (int)$stmt->fetchColumn();
    }
    // Expenses are not client-linked in current schema; reserved for future use.
    $summary['expenses'] = 0;
    if ($summary['invoices'] > 0) {
        $summary['blockers'][] = 'Customer invoices exist in OPS.';
    }
    if ($summary['payments'] > 0) {
        $summary['blockers'][] = 'Customer payments exist in OPS.';
    }
    $summary['can_delete'] = $summary['blockers'] === [];
    return $summary;
}

function client_delete(int $clientId): void
{
    $pdo = db();
    $summary = client_delete_summary($clientId);
    if (empty($summary['client'])) {
        throw new InvalidArgumentException('Client not found.');
    }
    if (!$summary['can_delete']) {
        throw new RuntimeException('This client cannot be deleted because accounting history exists.');
    }

    $stmt = $pdo->prepare('DELETE FROM clients WHERE client_id = ?');
    $stmt->execute([$clientId]);
}
