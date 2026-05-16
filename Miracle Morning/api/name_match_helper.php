<?php
// Shared helpers for matching imported names to member names.

function hm_normalize_person_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function hm_member_name_sql_expr(string $column): string {
    return "LOWER(TRIM(REPLACE(REPLACE(REPLACE($column, '  ', ' '), '  ', ' '), '  ', ' ')))";
}

function hm_find_member_by_name(PDO $pdo, string $name): ?array {
    $normalized = hm_normalize_person_name($name);
    if ($normalized === '') return null;

    $stmt = $pdo->prepare("
        SELECT id, name
        FROM members
        WHERE status = 'Active'
          AND " . hm_member_name_sql_expr('name') . " = ?
        ORDER BY name ASC
        LIMIT 1
    ");
    $stmt->execute([$normalized]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    return $member ?: null;
}
?>
