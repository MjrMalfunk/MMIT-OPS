<?php
declare(strict_types=1);

require_once __DIR__ . '/syncro.php';

const MMIT_SYNCRO_FOLDER_COMPANY_ROOT = 5027844;
const MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS = 5027864;
const MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS = 5027865;
const MMIT_SYNCRO_FOLDER_DEPLOY_WORKSTATIONS = 5027867;
const MMIT_SYNCRO_FOLDER_DEPLOY_SERVERS = 5027868;

const MMIT_SYNCRO_FIELD_ONBOARDING_STATUS = 'MMIT Onboarding Status';
const MMIT_SYNCRO_FIELD_READY_TO_MOVE = 'MMIT Ready To Move';
const MMIT_SYNCRO_FIELD_PRODUCTION_TARGET = 'MMIT Production Folder Target';
const MMIT_SYNCRO_FIELD_AUTO_MOVE_RESULT = 'MMIT Auto Move Result';
const MMIT_SYNCRO_FIELD_COMPLETED_AT = 'MMIT Onboarding Completed At';

function syncro_production_folder_allowlist(): array
{
    return [
        'Company root/top' => MMIT_SYNCRO_FOLDER_COMPANY_ROOT,
        'Production/Workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'Production/Servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
        'Deploy/Workstations' => MMIT_SYNCRO_FOLDER_DEPLOY_WORKSTATIONS,
        'Deploy/Servers' => MMIT_SYNCRO_FOLDER_DEPLOY_SERVERS,
    ];
}

function syncro_production_move_target_map(): array
{
    return [
        'production/workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'production workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'production/servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
        'production servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
        'servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
    ];
}

function syncro_production_move_normalize_text(mixed $value): string
{
    $text = trim((string)$value);
    $text = preg_replace('~\\s*/\\s*~', '/', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function syncro_production_move_normalize_key(mixed $value): string
{
    return mb_strtolower(syncro_production_move_normalize_text($value), 'UTF-8');
}

function syncro_production_move_target_folder_id(mixed $target): ?int
{
    $key = syncro_production_move_normalize_key($target);
    if ($key === '') {
        return null;
    }
    $map = syncro_production_move_target_map();
    return $map[$key] ?? null;
}

function syncro_production_move_mask_secrets(string $message): string
{
    $message = preg_replace('/(api[_-]?key=)[^\s&]+/i', '$1[redacted]', $message) ?? $message;
    $message = preg_replace('/(authorization\s*[:=]\s*)(bearer\s+)?[^\s,;]+/i', '$1[redacted]', $message) ?? $message;
    if (defined('SYNCRO_API_KEY') && trim((string)SYNCRO_API_KEY) !== '') {
        $message = str_replace((string)SYNCRO_API_KEY, '[redacted]', $message);
    }
    return trim($message);
}

function syncro_production_move_response_errors(array $response, string $fallback): string
{
    $errors = array_filter(array_map(static fn($v): string => trim((string)$v), (array)($response['errors'] ?? [])));
    $message = $errors ? implode(' ', $errors) : $fallback;
    if (!empty($response['status'])) {
        $message .= ' (HTTP ' . (int)$response['status'] . ')';
    }
    return syncro_production_move_mask_secrets($message);
}


function syncro_production_move_redacted_value(mixed $value): mixed
{
    if (is_string($value)) {
        return syncro_production_move_mask_secrets($value);
    }
    if (!is_array($value)) {
        return $value;
    }

    $redacted = [];
    foreach ($value as $key => $item) {
        $keyText = is_string($key) ? $key : (string)$key;
        if (preg_match('/api[_-]?key|authorization|token|secret|password/i', $keyText)) {
            $redacted[$key] = '[redacted]';
            continue;
        }
        $redacted[$key] = syncro_production_move_redacted_value($item);
    }
    return $redacted;
}

function syncro_production_move_response_excerpt(array $response, int $limit = 700): string
{
    $parts = [];
    foreach ((array)($response['errors'] ?? []) as $error) {
        $text = trim((string)$error);
        if ($text !== '') {
            $parts[] = $text;
        }
    }
    if (!empty($response['message'])) {
        $parts[] = (string)$response['message'];
    }
    if (!empty($response['raw_body']) && is_string($response['raw_body'])) {
        $parts[] = $response['raw_body'];
    } elseif (array_key_exists('data', $response)) {
        $json = json_encode(syncro_production_move_redacted_value($response['data']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== 'null') {
            $parts[] = $json;
        }
    }

    $excerpt = syncro_production_move_mask_secrets(trim(implode(' ', array_filter($parts))));
    if ($excerpt === '') {
        $excerpt = 'No Syncro response body was returned.';
    }
    if (mb_strlen($excerpt, 'UTF-8') > $limit) {
        $excerpt = mb_substr($excerpt, 0, $limit, 'UTF-8') . '…';
    }
    return $excerpt;
}

function syncro_production_move_api_path(string $path): string
{
    return '/api/v1/' . ltrim($path, '/');
}

function syncro_production_move_api_request(string $method, string $path, array $query = [], ?array $payload = null): array
{
    $handler = $GLOBALS['syncro_production_move_api_request_handler'] ?? null;
    if (is_callable($handler)) {
        return (array)$handler(strtoupper($method), ltrim($path, '/'), $query, $payload);
    }
    return syncro_api_request($method, $path, $query, $payload);
}

function syncro_production_move_request_diagnostic(string $method, string $path, ?array $payload, array $response): array
{
    return [
        'method' => strtoupper($method),
        'path' => syncro_production_move_api_path($path),
        'http_status' => $response['status'] ?? null,
        'response_excerpt' => syncro_production_move_response_excerpt($response),
        'payload' => $payload === null ? null : syncro_production_move_redacted_value($payload),
    ];
}

function syncro_production_move_now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function syncro_production_move_extract_asset(array $response): array
{
    $data = $response['data'] ?? $response;
    if (isset($data['asset']) && is_array($data['asset'])) {
        return $data['asset'];
    }
    if (isset($data['customer_asset']) && is_array($data['customer_asset'])) {
        return $data['customer_asset'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        return syncro_production_move_extract_asset(['data' => $data['data']]);
    }
    return is_array($data) ? $data : [];
}

function syncro_production_move_asset_list(array $response): array
{
    $data = $response['data'] ?? $response;
    foreach (['assets', 'customer_assets', 'records', 'items', 'results', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            if (array_is_list($data[$key])) {
                return array_values(array_filter($data[$key], static fn($row): bool => is_array($row)));
            }
            if (isset($data[$key]['assets']) || isset($data[$key]['customer_assets'])) {
                return syncro_production_move_asset_list(['data' => $data[$key]]);
            }
        }
    }
    if (array_is_list($data)) {
        return array_values(array_filter($data, static fn($row): bool => is_array($row)));
    }
    return [];
}


function syncro_production_move_is_numeric_option_value(mixed $value): bool
{
    if (is_int($value)) {
        return true;
    }
    if (!is_string($value)) {
        return false;
    }
    $text = syncro_production_move_normalize_text($value);
    return $text !== '' && preg_match('/^\d+$/', $text) === 1;
}

function syncro_production_move_mmit_select_option_map(): array
{
    // Syncro returns numeric option IDs for some MMIT select_box asset custom
    // fields on asset reads. GET /settings exposes the MMIT field definitions,
    // but current Syncro responses do not include option lists for these known
    // MMIT fields. OPS therefore maintains this controlled compatibility map
    // for MMIT-owned Syncro select options. Syncro option definitions remain
    // preferred above this map whenever future /settings responses expose them.
    return [
        syncro_normalize_match_text(MMIT_SYNCRO_FIELD_ONBOARDING_STATUS) => [
            '135355' => 'READY',
        ],
        syncro_normalize_match_text(MMIT_SYNCRO_FIELD_READY_TO_MOVE) => [
            '135359' => 'Yes',
        ],
    ];
}

function syncro_production_move_option_label_from_row(mixed $row): string
{
    if (is_string($row) || is_numeric($row) || is_bool($row)) {
        return syncro_production_move_normalize_text($row);
    }
    if (!is_array($row)) {
        return '';
    }
    foreach (['label', 'name', 'display_name', 'displayName', 'display_value', 'displayValue', 'value_text', 'valueText', 'text', 'title', 'value'] as $key) {
        if (array_key_exists($key, $row) && !is_array($row[$key])) {
            $label = syncro_production_move_normalize_text($row[$key]);
            if ($label !== '' && !syncro_production_move_is_numeric_option_value($label)) {
                return $label;
            }
        }
    }
    return '';
}

function syncro_production_move_option_id_from_row(mixed $key, mixed $row): string
{
    if (is_string($key) && syncro_production_move_is_numeric_option_value($key)) {
        return syncro_production_move_normalize_text($key);
    }
    if (!is_array($row)) {
        return '';
    }
    foreach (['id', 'key', 'option_id', 'optionId', 'field_option_id', 'fieldOptionId', 'answer_id', 'answerId', 'value'] as $idKey) {
        if (array_key_exists($idKey, $row) && syncro_production_move_is_numeric_option_value($row[$idKey])) {
            return syncro_production_move_normalize_text($row[$idKey]);
        }
    }
    return '';
}

function syncro_production_move_definition_field_name(array $row): string
{
    foreach (['name', 'field_name', 'fieldName', 'label', 'title'] as $key) {
        if (array_key_exists($key, $row) && !is_array($row[$key])) {
            $name = syncro_production_move_normalize_text($row[$key]);
            if ($name !== '' && !syncro_production_move_is_numeric_option_value($name)) {
                return $name;
            }
        }
    }
    return '';
}

function syncro_production_move_definition_id(array $row): string
{
    foreach (['id', 'field_definition_id', 'fieldDefinitionId', 'asset_type_field_id', 'assetTypeFieldId', 'custom_field_id', 'customFieldId'] as $key) {
        if (array_key_exists($key, $row) && syncro_production_move_is_numeric_option_value($row[$key])) {
            return syncro_production_move_normalize_text($row[$key]);
        }
    }
    return '';
}

function syncro_production_move_add_definition_options(array &$definitions, string $fieldName, mixed $options): void
{
    $fieldKey = syncro_normalize_match_text($fieldName);
    if ($fieldKey === '' || !is_array($options)) {
        return;
    }
    $rootOptionId = syncro_production_move_option_id_from_row('', $options);
    $rootLabel = syncro_production_move_option_label_from_row($options);
    if ($rootOptionId !== '' && $rootLabel !== '' && syncro_normalize_match_text($rootLabel) !== $fieldKey) {
        $definitions[$fieldKey][$rootOptionId] = $rootLabel;
    }
    foreach ($options as $key => $row) {
        $optionId = syncro_production_move_option_id_from_row($key, $row);
        $label = syncro_production_move_option_label_from_row($row);
        if ($optionId !== '' && $label !== '') {
            $definitions[$fieldKey][$optionId] = $label;
        }
        if (is_array($row)) {
            foreach ($row as $child) {
                if (is_array($child)) {
                    syncro_production_move_add_definition_options($definitions, $fieldName, $child);
                }
            }
        }
    }
}

function syncro_production_move_collect_option_definitions(mixed $data): array
{
    $definitions = [];
    $fieldNamesById = [];
    $walk = function (mixed $node) use (&$walk, &$definitions, &$fieldNamesById): void {
        if (!is_array($node)) {
            return;
        }

        $fieldName = syncro_production_move_definition_field_name($node);
        $definitionId = syncro_production_move_definition_id($node);
        if ($fieldName !== '' && $definitionId !== '') {
            $fieldNamesById[$definitionId] = $fieldName;
        }

        if ($fieldName !== '') {
            foreach (['options', 'option_definition', 'option_definitions', 'optionDefinition', 'optionDefinitions', 'choices', 'answers', 'dropdown_options', 'dropdownOptions', 'field_options', 'fieldOptions', 'possible_values', 'possibleValues', 'values', 'selections'] as $optionsKey) {
                if (isset($node[$optionsKey]) && is_array($node[$optionsKey])) {
                    syncro_production_move_add_definition_options($definitions, $fieldName, $node[$optionsKey]);
                }
            }
        }

        foreach ($node as $child) {
            $walk($child);
        }
    };

    $walk($data);

    // Some Syncro settings shapes split fields and option answers into separate
    // arrays. Link any answer row carrying a field ID back to the field name.
    $linkAnswers = function (mixed $node) use (&$linkAnswers, &$definitions, &$fieldNamesById): void {
        if (!is_array($node)) {
            return;
        }
        foreach (['field_definition_id', 'fieldDefinitionId', 'asset_type_field_id', 'assetTypeFieldId', 'custom_field_id', 'customFieldId', 'field_id', 'fieldId'] as $fieldIdKey) {
            if (array_key_exists($fieldIdKey, $node) && syncro_production_move_is_numeric_option_value($node[$fieldIdKey])) {
                $fieldId = syncro_production_move_normalize_text($node[$fieldIdKey]);
                $fieldName = $fieldNamesById[$fieldId] ?? '';
                $optionId = syncro_production_move_option_id_from_row('', $node);
                $label = syncro_production_move_option_label_from_row($node);
                if ($fieldName !== '' && $optionId !== '' && $label !== '') {
                    $definitions[syncro_normalize_match_text($fieldName)][$optionId] = $label;
                }
            }
        }
        foreach ($node as $child) {
            $linkAnswers($child);
        }
    };
    $linkAnswers($data);

    return $definitions;
}

function syncro_production_move_custom_field_option_definitions(): array
{
    static $definitions = null;
    if ($definitions !== null) {
        return $definitions;
    }
    $definitions = [];

    if (defined('MMIT_SYNCRO_PRODUCTION_MOVER_DISABLE_METADATA_FETCH') && MMIT_SYNCRO_PRODUCTION_MOVER_DISABLE_METADATA_FETCH) {
        return $definitions;
    }

    $settings = syncro_production_move_api_request('GET', 'settings');
    if (empty($settings['ok'])) {
        syncro_debug_log('production_mover_custom_field_metadata_unavailable', [
            'errors' => array_map('syncro_production_move_mask_secrets', (array)($settings['errors'] ?? [])),
        ]);
        return $definitions;
    }

    $definitions = syncro_production_move_collect_option_definitions($settings['data'] ?? []);
    return $definitions;
}


function syncro_production_move_mmit_field_names(): array
{
    return [
        MMIT_SYNCRO_FIELD_ONBOARDING_STATUS,
        MMIT_SYNCRO_FIELD_READY_TO_MOVE,
        MMIT_SYNCRO_FIELD_PRODUCTION_TARGET,
    ];
}

function syncro_production_move_metadata_scalar(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && !is_array($row[$key])) {
            $value = syncro_production_move_normalize_text($row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function syncro_production_move_metadata_field_label(array $row): string
{
    return syncro_production_move_metadata_scalar($row, [
        'display_name',
        'displayName',
        'label',
        'title',
        'name',
        'field_name',
        'fieldName',
        'key',
    ]);
}

function syncro_production_move_metadata_path(string $parent, mixed $key): string
{
    if (is_int($key)) {
        return $parent . '[' . $key . ']';
    }
    $keyText = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$key) ?? (string)$key;
    return $parent . '.' . $keyText;
}

function syncro_production_move_metadata_path_matches(string $path, array $needles): bool
{
    $normalized = syncro_normalize_match_text($path);
    foreach ($needles as $needle) {
        if (str_contains($normalized, syncro_normalize_match_text($needle))) {
            return true;
        }
    }
    return false;
}

function syncro_production_move_metadata_option_rows(mixed $options): array
{
    $rows = [];
    if (!is_array($options)) {
        return $rows;
    }

    $add = static function (mixed $key, mixed $row, string $path) use (&$rows): void {
        $optionId = syncro_production_move_option_id_from_row($key, $row);
        $label = syncro_production_move_option_label_from_row($row);
        if ($optionId !== '' || $label !== '') {
            $rows[] = [
                'id' => $optionId !== '' ? $optionId : null,
                'label' => $label !== '' ? syncro_production_move_mask_secrets($label) : null,
                'metadata_path' => $path,
            ];
        }
    };

    $walk = function (mixed $node, string $path) use (&$walk, &$add): void {
        if (!is_array($node)) {
            return;
        }
        $add('', $node, $path);
        foreach ($node as $key => $row) {
            $childPath = syncro_production_move_metadata_path($path, $key);
            $add($key, $row, $childPath);
            if (is_array($row)) {
                foreach ($row as $nestedKey => $nestedValue) {
                    if (is_array($nestedValue)) {
                        $walk($nestedValue, syncro_production_move_metadata_path($childPath, $nestedKey));
                    }
                }
            }
        }
    };

    $walk($options, '$');

    $deduped = [];
    foreach ($rows as $row) {
        $key = (string)($row['id'] ?? '') . '|' . syncro_normalize_match_text((string)($row['label'] ?? ''));
        if ($key === '|') {
            continue;
        }
        $deduped[$key] = $row;
    }
    return array_values($deduped);
}

function syncro_production_move_settings_metadata_diagnostic(array $settingsData, array $fieldNames = []): array
{
    $fieldNames = $fieldNames ?: syncro_production_move_mmit_field_names();
    $targetKeys = [];
    foreach ($fieldNames as $fieldName) {
        $targetKeys[syncro_normalize_match_text($fieldName)] = $fieldName;
    }

    $customFieldPaths = [];
    $assetCustomFieldPaths = [];
    $fieldEntries = [];
    $optionKeys = ['options', 'option_definition', 'option_definitions', 'optionDefinition', 'optionDefinitions', 'choices', 'answers', 'dropdown_options', 'dropdownOptions', 'field_options', 'fieldOptions', 'possible_values', 'possibleValues', 'values', 'selections'];
    $typeKeys = ['type', 'field_type', 'fieldType', 'input_type', 'inputType', 'kind', 'field_kind', 'fieldKind'];

    $walk = function (mixed $node, string $path) use (&$walk, &$customFieldPaths, &$assetCustomFieldPaths, &$fieldEntries, $targetKeys, $optionKeys, $typeKeys): void {
        if (!is_array($node)) {
            return;
        }

        if (syncro_production_move_metadata_path_matches($path, ['custom_field', 'custom fields', 'asset_type_field', 'asset type field'])) {
            $customFieldPaths[$path] = true;
        }
        if (syncro_production_move_metadata_path_matches($path, ['asset_custom_field', 'asset custom field', 'asset_type_field', 'asset type field']) || (syncro_production_move_metadata_path_matches($path, ['asset']) && syncro_production_move_metadata_path_matches($path, ['custom_field', 'field']))) {
            $assetCustomFieldPaths[$path] = true;
        }

        $candidateValues = [];
        foreach (['name', 'field_name', 'fieldName', 'label', 'title', 'display_name', 'displayName', 'key'] as $key) {
            if (array_key_exists($key, $node) && !is_array($node[$key])) {
                $candidateValues[$key] = syncro_production_move_normalize_text($node[$key]);
            }
        }

        $matchedCanonical = '';
        foreach ($candidateValues as $candidateValue) {
            $candidateKey = syncro_normalize_match_text($candidateValue);
            if (isset($targetKeys[$candidateKey])) {
                $matchedCanonical = $targetKeys[$candidateKey];
                break;
            }
        }

        if ($matchedCanonical !== '') {
            $options = [];
            $optionSources = [];
            foreach ($optionKeys as $optionKey) {
                if (isset($node[$optionKey]) && is_array($node[$optionKey])) {
                    $optionPath = syncro_production_move_metadata_path($path, $optionKey);
                    $optionSources[] = $optionPath;
                    foreach (syncro_production_move_metadata_option_rows($node[$optionKey]) as $optionRow) {
                        $optionRow['metadata_path'] = str_replace('$', $optionPath, (string)$optionRow['metadata_path']);
                        $options[] = $optionRow;
                    }
                }
            }

            $optionMap = [];
            foreach ($options as $option) {
                $key = (string)($option['id'] ?? '') . '|' . syncro_normalize_match_text((string)($option['label'] ?? ''));
                $optionMap[$key] = $option;
            }

            $fieldEntries[$matchedCanonical][] = [
                'field_name' => isset($candidateValues['field_name']) || isset($candidateValues['fieldName']) || isset($candidateValues['name']) ? syncro_production_move_mask_secrets($candidateValues['field_name'] ?? $candidateValues['fieldName'] ?? $candidateValues['name']) : null,
                'key' => isset($candidateValues['key']) ? syncro_production_move_mask_secrets($candidateValues['key']) : null,
                'display_name' => syncro_production_move_metadata_field_label($node) !== '' ? syncro_production_move_mask_secrets(syncro_production_move_metadata_field_label($node)) : null,
                'field_type' => syncro_production_move_metadata_scalar($node, $typeKeys) !== '' ? syncro_production_move_mask_secrets(syncro_production_move_metadata_scalar($node, $typeKeys)) : null,
                'option_list_present' => $optionSources !== [],
                'options' => array_values($optionMap),
                'raw_metadata_path' => $path,
                'option_metadata_paths' => $optionSources,
                'source' => 'GET /settings',
            ];
        }

        foreach ($node as $key => $child) {
            $walk($child, syncro_production_move_metadata_path($path, $key));
        }
    };

    $walk($settingsData, '$');

    $definitionMap = syncro_production_move_collect_option_definitions($settingsData);
    $configuredOptionMap = syncro_production_move_mmit_select_option_map();
    $fields = [];
    foreach ($fieldNames as $fieldName) {
        $fieldKey = syncro_normalize_match_text($fieldName);
        $resolverOptions = [];
        foreach (($definitionMap[$fieldKey] ?? []) as $id => $label) {
            $resolverOptions[] = ['id' => (string)$id, 'label' => syncro_production_move_mask_secrets((string)$label)];
        }
        $configuredOptions = [];
        foreach (($configuredOptionMap[$fieldKey] ?? []) as $id => $label) {
            $configuredOptions[] = ['id' => (string)$id, 'label' => syncro_production_move_mask_secrets((string)$label)];
        }
        $fields[] = [
            'target_field' => $fieldName,
            'found_in_settings' => !empty($fieldEntries[$fieldName]),
            'metadata_entries' => $fieldEntries[$fieldName] ?? [],
            'resolver_option_definition_options' => $resolverOptions,
            'resolver_has_option_ids' => $resolverOptions !== [],
            'configured_option_map_options' => $configuredOptions,
            'configured_option_map_has_ids' => $configuredOptions !== [],
        ];
    }

    return [
        'source' => 'GET /settings',
        'contains_custom_field_definitions' => $customFieldPaths !== [] || $fieldEntries !== [],
        'custom_field_definition_paths' => array_slice(array_keys($customFieldPaths), 0, 50),
        'asset_custom_fields_present' => $assetCustomFieldPaths !== [],
        'asset_custom_field_paths' => array_slice(array_keys($assetCustomFieldPaths), 0, 50),
        'fields' => $fields,
        'resolution_order' => ['direct_or_embedded_display', 'option_definition', 'configured_option_map', 'unresolved_numeric'],
        'configured_option_map_note' => 'Controlled MMIT Syncro select-option compatibility map for known select_box fields whose asset values are numeric IDs while GET /settings omits option lists.',
        'existing_repo_syncro_read_helpers' => [
            [
                'helper' => 'syncro_production_move_custom_field_option_definitions',
                'endpoint' => 'GET /settings',
                'may_expose_definitions_or_options' => true,
                'note' => 'Current production mover metadata resolver; this diagnostic inspects the same response shape before the controlled MMIT select-option compatibility map is used.',
            ],
            [
                'helper' => 'syncro_production_move_fetch_asset',
                'endpoint' => 'GET /customer_assets/{asset_id}',
                'may_expose_definitions_or_options' => false,
                'note' => 'Existing asset reader may expose assigned asset custom-field values/display values, not reusable dropdown option definitions.',
            ],
            [
                'helper' => 'syncro_production_move_find_ready_assets',
                'endpoint' => 'GET /customer_assets',
                'may_expose_definitions_or_options' => false,
                'note' => 'Existing asset list reader may expose assigned asset custom-field values/display values, not reusable dropdown option definitions.',
            ],
        ],
    ];
}

function syncro_production_move_live_settings_metadata_diagnostic(): array
{
    $response = syncro_production_move_api_request('GET', 'settings');
    if (empty($response['ok'])) {
        return [
            'ok' => false,
            'source' => 'GET /settings',
            'errors' => array_map('syncro_production_move_mask_secrets', (array)($response['errors'] ?? ['Unable to fetch Syncro settings.'])),
            'status' => $response['status'] ?? null,
            'contains_custom_field_definitions' => false,
            'asset_custom_fields_present' => false,
        ];
    }

    $diagnostic = syncro_production_move_settings_metadata_diagnostic((array)($response['data'] ?? []));
    $diagnostic['ok'] = true;
    $diagnostic['http_status'] = $response['status'] ?? null;
    return $diagnostic;
}

function syncro_production_move_resolve_custom_field_value(string $fieldName, mixed $rawValue, ?array $optionDefinitions = null): array
{
    $value = syncro_production_move_custom_field_value($rawValue);
    $normalized = syncro_production_move_normalize_text($value);

    if ($normalized === '') {
        return ['value' => '', 'source' => 'empty'];
    }

    if (!syncro_production_move_is_numeric_option_value($normalized)) {
        $source = is_array($rawValue) ? 'embedded_display' : 'direct';
        return ['value' => $normalized, 'source' => $source];
    }

    $optionId = $normalized;
    $fieldKey = syncro_normalize_match_text($fieldName);
    $definitions = $optionDefinitions ?? syncro_production_move_custom_field_option_definitions();
    if (isset($definitions[$fieldKey][$optionId])) {
        return ['value' => $definitions[$fieldKey][$optionId], 'source' => 'option_definition'];
    }

    $configuredOptionMap = syncro_production_move_mmit_select_option_map();
    if (isset($configuredOptionMap[$fieldKey][$optionId])) {
        return ['value' => $configuredOptionMap[$fieldKey][$optionId], 'source' => 'configured_option_map'];
    }

    return ['value' => $optionId, 'source' => 'unresolved_numeric'];
}

function syncro_production_move_custom_field_value(mixed $field): mixed
{
    if (!is_array($field)) {
        return $field;
    }

    foreach ([
        'display_value',
        'displayValue',
        'value_text',
        'valueText',
        'text',
        'field_value',
        'fieldValue',
        'content',
        'answer',
        'selected_value',
        'selectedValue',
        'value',
    ] as $key) {
        if (array_key_exists($key, $field) && !is_array($field[$key])) {
            $value = syncro_production_move_normalize_text($field[$key]);
            if ($value !== '') {
                return $field[$key];
            }
        }
    }

    return '';
}

function syncro_production_move_extract_custom_fields(array $asset): array
{
    $fields = [];
    $sources = [];
    foreach (['custom_fields', 'properties', 'asset_properties', 'fields'] as $key) {
        if (isset($asset[$key]) && is_array($asset[$key])) {
            $sources[] = $asset[$key];
        }
    }

    foreach ($sources as $source) {
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $fields[$key] = syncro_production_move_resolve_custom_field_value($key, $value)['value'];
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $name = (string)($value['name'] ?? $value['field_name'] ?? $value['fieldName'] ?? $value['label'] ?? $value['title'] ?? '');
            if ($name === '') {
                continue;
            }
            $fields[$name] = syncro_production_move_resolve_custom_field_value($name, $value)['value'];
        }
    }
    return $fields;
}

function syncro_production_move_redact_debug_value(mixed $value): mixed
{
    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $key => $child) {
            $keyText = (string)$key;
            if (preg_match('/api[_-]?key|authorization|bearer|token|secret|password/i', $keyText) === 1) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = syncro_production_move_redact_debug_value($child);
        }
        return $redacted;
    }

    if (is_string($value)) {
        return syncro_production_move_mask_secrets($value);
    }

    return $value;
}

function syncro_production_move_custom_field_debug(array $asset, array $fieldNames = []): array
{
    $wanted = array_filter(array_map('syncro_normalize_match_text', $fieldNames));
    $debug = [];
    foreach (['custom_fields', 'properties', 'asset_properties', 'fields'] as $sourceKey) {
        if (!isset($asset[$sourceKey]) || !is_array($asset[$sourceKey])) {
            continue;
        }
        foreach ($asset[$sourceKey] as $key => $value) {
            $candidateName = is_string($key) ? $key : '';
            if ($candidateName === '' && is_array($value)) {
                $candidateName = (string)($value['name'] ?? $value['field_name'] ?? $value['fieldName'] ?? $value['label'] ?? $value['title'] ?? '');
            }
            if ($wanted && !in_array(syncro_normalize_match_text($candidateName), $wanted, true)) {
                continue;
            }
            $resolution = syncro_production_move_resolve_custom_field_value($candidateName, $value);
            $debug[] = [
                'source' => $sourceKey,
                'key' => is_string($key) ? $key : (int)$key,
                'name' => $candidateName,
                'raw_value' => syncro_production_move_redact_debug_value($value),
                'parsed_value' => syncro_production_move_normalize_text(syncro_production_move_custom_field_value($value)),
                'resolved_value' => $resolution['value'],
                'resolution_source' => $resolution['source'],
                'raw' => syncro_production_move_redact_debug_value($value),
            ];
        }
    }
    return $debug;
}

function syncro_production_move_custom_field(array $asset, string $name): string
{
    $target = syncro_normalize_match_text($name);
    foreach (syncro_production_move_extract_custom_fields($asset) as $key => $value) {
        if (syncro_normalize_match_text((string)$key) === $target) {
            return syncro_production_move_normalize_text($value);
        }
    }
    return '';
}

function syncro_production_move_asset_folder_id(array $asset): ?int
{
    foreach (['policy_folder_id', 'policyFolderId', 'policy_folder', 'folder_id'] as $key) {
        if (isset($asset[$key]) && is_numeric($asset[$key])) {
            return (int)$asset[$key];
        }
    }
    foreach (['policy_folder', 'folder'] as $key) {
        if (isset($asset[$key]) && is_array($asset[$key]) && isset($asset[$key]['id']) && is_numeric($asset[$key]['id'])) {
            return (int)$asset[$key]['id'];
        }
    }
    return null;
}

function syncro_production_move_asset_name(array $asset): string
{
    foreach (['name', 'asset_name', 'hostname', 'computer_name', 'display_name'] as $key) {
        $value = trim((string)($asset[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'Asset #' . (string)($asset['id'] ?? 'unknown');
}

function syncro_production_move_validate_asset(array $asset): array
{
    $status = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_ONBOARDING_STATUS);
    $ready = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_READY_TO_MOVE);
    $target = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_PRODUCTION_TARGET);
    $targetFolderId = syncro_production_move_target_folder_id($target);
    $errors = [];

    if (mb_strtoupper($status, 'UTF-8') !== 'READY') {
        $errors[] = MMIT_SYNCRO_FIELD_ONBOARDING_STATUS . ' must be READY.';
    }
    if (mb_strtolower($ready, 'UTF-8') !== 'yes') {
        $errors[] = MMIT_SYNCRO_FIELD_READY_TO_MOVE . ' must be Yes.';
    }
    if ($target === '') {
        $errors[] = MMIT_SYNCRO_FIELD_PRODUCTION_TARGET . ' is missing.';
    } elseif ($targetFolderId === null) {
        $errors[] = MMIT_SYNCRO_FIELD_PRODUCTION_TARGET . ' must be Production/Workstations or Production/Servers.';
    }

    $validation = [
        'ok' => !$errors,
        'errors' => $errors,
        'status' => $status,
        'ready_to_move' => $ready,
        'target' => $target,
        'target_folder_id' => $targetFolderId,
        'current_folder_id' => syncro_production_move_asset_folder_id($asset),
    ];
    $validation['custom_field_debug'] = syncro_production_move_custom_field_debug($asset, [
        MMIT_SYNCRO_FIELD_ONBOARDING_STATUS,
        MMIT_SYNCRO_FIELD_READY_TO_MOVE,
        MMIT_SYNCRO_FIELD_PRODUCTION_TARGET,
    ]);
    return $validation;
}

function syncro_production_move_fetch_asset(int $customerId, int $assetId): array
{
    if ($customerId <= 0 || $assetId <= 0) {
        return ['ok' => false, 'errors' => ['Customer ID and asset ID are required.']];
    }

    $response = syncro_production_move_api_request('GET', 'customer_assets/' . $assetId, ['customer_id' => $customerId]);
    if (!empty($response['ok'])) {
        $asset = syncro_production_move_extract_asset($response);
        if ($asset) {
            return ['ok' => true, 'asset' => $asset, 'response' => $response];
        }
    }

    return [
        'ok' => false,
        'errors' => [syncro_production_move_response_errors($response, 'Unable to fetch Syncro asset.')],
        'response' => $response,
    ];
}

function syncro_production_move_find_ready_assets(int $customerId): array
{
    if ($customerId <= 0) {
        return ['ok' => false, 'errors' => ['Customer ID is required to find ready assets.'], 'assets' => []];
    }
    $response = syncro_production_move_api_request('GET', 'customer_assets', ['customer_id' => $customerId]);
    if (empty($response['ok'])) {
        return ['ok' => false, 'errors' => [syncro_production_move_response_errors($response, 'Unable to list Syncro assets.')], 'assets' => []];
    }

    $ready = [];
    foreach (syncro_production_move_asset_list($response) as $asset) {
        $validation = syncro_production_move_validate_asset($asset);
        if (!empty($validation['ok'])) {
            $ready[] = ['asset' => $asset, 'validation' => $validation];
        }
    }
    return ['ok' => true, 'assets' => $ready];
}

function syncro_production_move_policy_assignment_payload(int $assetId, int $targetFolderId): array
{
    return [
        'changes' => [
            'add_folder' => [],
            'update_folder' => [],
            'remove_folder' => [],
            'update_asset' => [
                [
                    'id' => $assetId,
                    'change' => [
                        'policy_folder_id' => $targetFolderId,
                    ],
                ],
            ],
        ],
    ];
}


function syncro_production_move_is_staging_blocked_response(array $response): bool
{
    if (!empty($response['staging_blocked'])) {
        return true;
    }
    if (strtoupper((string)($response['status'] ?? '')) === 'STAGING_BLOCKED') {
        return true;
    }
    foreach (array_merge((array)($response['errors'] ?? []), [(string)($response['message'] ?? '')]) as $message) {
        if (stripos((string)$message, 'Staging mode: Syncro write skipped') !== false) {
            return true;
        }
    }
    return false;
}

function syncro_production_move_target_label(string $target, int $targetFolderId): string
{
    $target = syncro_production_move_normalize_text($target);
    return ($target !== '' ? $target : 'target folder') . ' (#' . $targetFolderId . ')';
}

function syncro_production_move_update_asset_fields(int $assetId, array $fields): array
{
    if ($assetId <= 0) {
        return ['ok' => false, 'errors' => ['Asset ID is required to write Syncro fields.']];
    }
    $fields = array_filter($fields, static fn($value): bool => $value !== null);
    if (!$fields) {
        return ['ok' => true, 'skipped' => true];
    }

    $payload = ['properties' => $fields];
    return syncro_production_move_api_request('PUT', 'customer_assets/' . $assetId, [], $payload);
}

function syncro_production_move_write_result(int $assetId, string $message, bool $completed): array
{
    $fields = [MMIT_SYNCRO_FIELD_AUTO_MOVE_RESULT => syncro_production_move_mask_secrets($message)];
    if ($completed) {
        $fields[MMIT_SYNCRO_FIELD_COMPLETED_AT] = syncro_production_move_now_utc();
    }
    return syncro_production_move_update_asset_fields($assetId, $fields);
}

function syncro_production_move_update_ticket(?int $ticketId, string $message, bool $closeTicket): array
{
    if ($ticketId === null || $ticketId <= 0) {
        return ['ok' => true, 'skipped' => true, 'message' => 'No ready ticket ID supplied.'];
    }

    $comment = syncro_production_move_api_request('POST', 'tickets/' . $ticketId . '/comments', [], [
        'comment' => [
            'body' => syncro_production_move_mask_secrets($message),
            'hidden' => false,
            'do_not_email' => true,
        ],
    ]);
    if ($closeTicket && !empty($comment['ok'])) {
        $close = syncro_production_move_api_request('PUT', 'tickets/' . $ticketId, [], ['ticket' => ['status' => 'Resolved']]);
        if (empty($close['ok'])) {
            return ['ok' => false, 'errors' => [syncro_production_move_response_errors($close, 'Move succeeded, but ticket close failed.')], 'comment' => $comment, 'close' => $close];
        }
        return ['ok' => true, 'comment' => $comment, 'close' => $close];
    }
    return !empty($comment['ok']) ? ['ok' => true, 'comment' => $comment] : ['ok' => false, 'errors' => [syncro_production_move_response_errors($comment, 'Move result ticket comment failed.')], 'comment' => $comment];
}

function syncro_production_move_asset_update_payload(int $targetFolderId): array
{
    return ['policy_folder_id' => $targetFolderId];
}

function syncro_production_move_update_asset_policy_folder(int $assetId, int $targetFolderId): array
{
    $path = 'customer_assets/' . $assetId;
    $payload = syncro_production_move_asset_update_payload($targetFolderId);
    $response = syncro_production_move_api_request('PUT', $path, [], $payload);
    return [
        'ok' => !empty($response['ok']) && (int)($response['status'] ?? 0) === 200,
        'response' => $response,
        'diagnostic' => syncro_production_move_request_diagnostic('PUT', $path, $payload, $response),
    ];
}

function syncro_production_move_verified_folder_id(array $asset): ?int
{
    return syncro_production_move_asset_folder_id($asset);
}

function syncro_production_move_asset(int $customerId, int $assetId, ?int $readyTicketId = null, bool $dryRun = true, bool $closeTicket = false, ?array $assetOverride = null): array
{
    $asset = $assetOverride;
    $fetch = null;
    if ($asset === null) {
        $fetch = syncro_production_move_fetch_asset($customerId, $assetId);
        if (empty($fetch['ok'])) {
            return ['ok' => false, 'dry_run' => $dryRun, 'errors' => $fetch['errors'] ?? ['Unable to fetch asset.'], 'fetch' => $fetch];
        }
        $asset = (array)$fetch['asset'];
    }

    $assetName = syncro_production_move_asset_name($asset);
    $validation = syncro_production_move_validate_asset($asset);
    if (empty($validation['ok'])) {
        $message = 'Move failed validation for ' . $assetName . ': ' . implode(' ', $validation['errors']);
        if (!$dryRun) {
            syncro_production_move_write_result($assetId, $message, false);
        }
        return ['ok' => false, 'dry_run' => $dryRun, 'asset' => $asset, 'validation' => $validation, 'errors' => $validation['errors'], 'message' => $message];
    }

    $targetFolderId = (int)$validation['target_folder_id'];
    $currentFolderId = $validation['current_folder_id'];
    if ($currentFolderId !== null && (int)$currentFolderId === $targetFolderId) {
        $message = 'Already in target folder.';
        if (!$dryRun) {
            $write = syncro_production_move_write_result($assetId, $message, true);
            $ticket = syncro_production_move_update_ticket($readyTicketId, $message, false);
            $warnings = [];
            if (empty($write['ok'])) {
                $warnings[] = syncro_production_move_response_errors($write, 'Already-in-target result write-back failed.');
            }
            if (empty($ticket['ok'])) {
                $warnings[] = implode(' ', (array)($ticket['errors'] ?? ['Already-in-target ticket update failed.']));
            }
            return ['ok' => true, 'dry_run' => false, 'noop' => true, 'message' => $message, 'validation' => $validation, 'write' => $write, 'ticket' => $ticket, 'warnings' => $warnings];
        }
        return ['ok' => true, 'dry_run' => true, 'noop' => true, 'message' => $message, 'validation' => $validation];
    }

    $payload = syncro_production_move_policy_assignment_payload($assetId, $targetFolderId);
    $assetUpdatePayload = syncro_production_move_asset_update_payload($targetFolderId);
    $targetLabel = syncro_production_move_target_label((string)$validation['target'], $targetFolderId);
    $message = 'Ready to move ' . $assetName . ' to ' . $targetLabel . '.';
    $browserPayloadReference = [
        'label' => 'Browser/UI policy assignment payload reference only; not used for the production mover write path.',
        'method' => 'PATCH',
        'path' => syncro_production_move_api_path('customers/' . $customerId . '/policy_assignments'),
        'payload' => $payload,
    ];
    if ($dryRun) {
        return [
            'ok' => true,
            'dry_run' => true,
            'message' => $message,
            'validation' => $validation,
            'payload' => $payload,
            'asset_update_payload' => $assetUpdatePayload,
            'browser_payload_reference' => $browserPayloadReference,
            'asset' => $asset,
        ];
    }

    $move = syncro_production_move_update_asset_policy_folder($assetId, $targetFolderId);
    $moveResponse = (array)($move['response'] ?? []);
    $moveDiagnostic = (array)($move['diagnostic'] ?? []);
    if (empty($move['ok'])) {
        if (syncro_production_move_is_staging_blocked_response($moveResponse)) {
            $guarded = 'Staging execution blocked as expected. Would move ' . $assetName . ' to ' . $targetLabel . '.';
            return [
                'ok' => true,
                'dry_run' => false,
                'staging_guarded' => true,
                'staging_blocked' => true,
                'production_move_succeeded' => false,
                'message' => $guarded,
                'validation' => $validation,
                'payload' => $payload,
                'asset_update_payload' => $assetUpdatePayload,
                'browser_payload_reference' => $browserPayloadReference,
                'move' => $moveResponse,
                'move_diagnostic' => $moveDiagnostic,
                'warnings' => ['Staging/test guard blocked the Syncro write; no production move was made.'],
            ];
        }

        $status = (int)($moveResponse['status'] ?? 0);
        $fallback = $status === 422
            ? 'Syncro rejected the target policy_folder_id as invalid for this asset/customer.'
            : ($status === 404 ? 'Syncro asset update route returned not found.' : 'Syncro asset policy_folder_id update failed.');
        $failure = 'Move failed for ' . $assetName . ': ' . syncro_production_move_response_errors($moveResponse, $fallback)
            . ' Response excerpt: ' . syncro_production_move_response_excerpt($moveResponse);
        return [
            'ok' => false,
            'dry_run' => false,
            'message' => $failure,
            'validation' => $validation,
            'payload' => $payload,
            'asset_update_payload' => $assetUpdatePayload,
            'browser_payload_reference' => $browserPayloadReference,
            'move' => $moveResponse,
            'move_diagnostic' => $moveDiagnostic,
            'errors' => [$failure],
        ];
    }

    $verify = syncro_production_move_fetch_asset($customerId, $assetId);
    if (empty($verify['ok'])) {
        $failure = 'Move verification failed for ' . $assetName . ': ' . implode(' ', (array)($verify['errors'] ?? ['Unable to re-fetch Syncro asset after move.']));
        return [
            'ok' => false,
            'dry_run' => false,
            'message' => $failure,
            'validation' => $validation,
            'payload' => $payload,
            'asset_update_payload' => $assetUpdatePayload,
            'browser_payload_reference' => $browserPayloadReference,
            'move' => $moveResponse,
            'move_diagnostic' => $moveDiagnostic,
            'verify' => $verify,
            'errors' => [$failure],
        ];
    }

    $verifiedAsset = (array)($verify['asset'] ?? []);
    $verifiedFolderId = syncro_production_move_verified_folder_id($verifiedAsset);
    if ($verifiedFolderId === null || (int)$verifiedFolderId !== $targetFolderId) {
        $failure = 'Move verification failed for ' . $assetName . ': expected policy_folder_id #' . $targetFolderId . ', found ' . ($verifiedFolderId === null ? 'none' : ('#' . $verifiedFolderId)) . '.';
        return [
            'ok' => false,
            'dry_run' => false,
            'message' => $failure,
            'validation' => $validation,
            'payload' => $payload,
            'asset_update_payload' => $assetUpdatePayload,
            'browser_payload_reference' => $browserPayloadReference,
            'move' => $moveResponse,
            'move_diagnostic' => $moveDiagnostic,
            'verify' => $verify,
            'errors' => [$failure],
        ];
    }

    $success = 'Moved to target folder ' . $targetLabel . '.';
    $write = syncro_production_move_write_result($assetId, $success, true);
    $ticket = syncro_production_move_update_ticket($readyTicketId, $success, $closeTicket);
    $warnings = [];
    if (empty($write['ok'])) {
        $warnings[] = syncro_production_move_response_errors($write, 'Move succeeded and verified, but result write-back failed.');
    }
    if (empty($ticket['ok'])) {
        $warnings[] = implode(' ', (array)($ticket['errors'] ?? ['Move succeeded and verified, but ticket update failed.']));
    }
    return [
        'ok' => true,
        'dry_run' => false,
        'message' => $success,
        'validation' => $validation,
        'payload' => $payload,
        'asset_update_payload' => $assetUpdatePayload,
        'browser_payload_reference' => $browserPayloadReference,
        'move' => $moveResponse,
        'move_diagnostic' => $moveDiagnostic,
        'verify' => $verify,
        'verified_policy_folder_id' => $verifiedFolderId,
        'write' => $write,
        'ticket' => $ticket,
        'warnings' => $warnings,
    ];
}
