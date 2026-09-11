<?php

/**
 * PostToolUse hook: reject test files that name a table outside the fixture allowlist.
 *
 * `easi-power/laravel-db2` is a shared, public package, so its tests should stick to
 * generic, obviously-fictional schema names. Reads the hook payload on stdin, and exits
 * 2 (which feeds the message back to Claude as a blocking error) when tests/ gains an
 * unknown name.
 *
 * Only table positions are checked. Column names are too numerous and too generic to
 * allowlist without drowning the signal.
 */

const ALLOWLIST = __DIR__.'/../fixture-tables.txt';

/** Positions in the source where a string literal is a table name. */
const TABLE_PATTERNS = [
    '/->table\(\s*[\'"]([^\'"]+)[\'"]/',
    '/->from\(\s*[\'"]([^\'"]+)[\'"]/',
    '/\bBlueprint\s*\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
    '/->(?:wrapTable|hasTable|getColumnListing|getColumns|hasColumn)\(\s*[\'"]([^\'"]+)[\'"]/',
];

$payload = json_decode(stream_get_contents(STDIN) ?: '{}', true) ?: [];

$path = $payload['tool_response']['filePath'] ?? $payload['tool_input']['file_path'] ?? '';
$path = str_replace('\\', '/', $path);

if (! str_contains($path, '/tests/') || ! str_ends_with($path, '.php') || ! is_file($path)) {
    exit(0);
}

$allowed = array_filter(
    array_map('trim', file(ALLOWLIST, FILE_IGNORE_NEW_LINES)),
    static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
);

$source = file_get_contents($path);
$offenders = [];

foreach (TABLE_PATTERNS as $pattern) {
    preg_match_all($pattern, $source, $matches);

    foreach ($matches[1] as $literal) {
        // 'inventory.products as main' -> ['inventory', 'products']
        $name = trim(preg_split('/\s+as\s+/i', $literal)[0]);

        foreach (explode('.', $name) as $segment) {
            if ($segment !== '' && ! in_array($segment, $allowed, true)) {
                $offenders[$segment] = true;
            }
        }
    }
}

if ($offenders === []) {
    exit(0);
}

fwrite(STDERR, sprintf(
    "%s uses table names that are not on the fixture allowlist: %s\n\n"
    ."This package is shared and public, so its tests should stick to generic names.\n"
    ."Rename them to something generic and obviously fictional, or — if the name really\n"
    ."is generic — add it to .claude/fixture-tables.txt.\n",
    $path,
    implode(', ', array_keys($offenders)),
));

exit(2);
