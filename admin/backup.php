<?php
// =============================================================================
// admin/backup.php  –  one-click SQL dump of the BreadBreak database
//
// Admin only. Written in pure PHP rather than shelling out to mysqldump so it
// works even when exec() is disabled or MySQL is installed somewhere else.
// Produces a file that phpMyAdmin -> Import (or `breadbreak_db < file.sql`)
// can read back.
// =============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dbName   = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: 'breadbreak_db');
$filename = $dbName . '_backup_' . date('Ymd_His') . '.sql';

// ── Table list ───────────────────────────────────────────────────────────────
// Base tables only — this schema has no views, routines or triggers. If any
// are added later they need their own handling; dumping them as tables would
// produce an invalid file.
$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
      ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

if (!$tables) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('No tables found in the current database.');
}

// Statements are capped by byte size, not row count: 200 rows of text is tiny
// but 200 photos is megabytes, and a single oversized statement fails the
// restore with "Got a packet bigger than 'max_allowed_packet'". The server
// copy of that variable cannot be raised from here (the session copy is
// read-only), so the dump has to stay under it on its own.
$maxPacket = (int) $pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
$batchCap  = (int) min(524288, max(65536, $maxPacket * 0.4));   // 512KB, 40% of packet

/**
 * Column metadata in ordinal order, so it lines up with SELECT *.
 *
 * 'binary' matters: PDO::quote() assumes a character set and would corrupt
 * photo bytes, so blob columns are written as X'hex' instead.
 */
function dumpColumnsFor(PDO $pdo, string $table): array
{
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $c) {
        $type = (string) $c['Type'];
        $cols[] = [
            'name'    => (string) $c['Field'],
            'numeric' => (bool) preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\(/i', $type),
            'binary'  => (bool) preg_match('/^(tinyblob|blob|mediumblob|longblob|binary|varbinary)/i', $type),
        ];
    }
    return $cols;
}

/**
 * Turn one PHP value into a SQL literal using the column's type.
 *
 * Binary columns deliberately use an escaped literal rather than X'hex'.
 * Hex doubles the bytes, which pushes a 489KB photo to 978KB — right up
 * against MariaDB's default max_allowed_packet of 1MB, and the session copy of
 * that variable is read-only so it cannot be raised from the dump itself.
 * Escaping keeps it near 1:1 (this is also what mysqldump does by default).
 * Round-trip equality is covered by compare_db.php in the test run.
 */
function dumpLiteral(PDO $pdo, $value, array $col): string
{
    if ($value === null) {
        return 'NULL';
    }
    if ($col['binary']) {
        $quoted = $pdo->quote((string) $value);
        if ($quoted !== false && $quoted !== '') {
            return '_binary ' . $quoted;
        }
        return "X'" . bin2hex((string) $value) . "'";   // fallback: always safe
    }
    if ($col['numeric'] && is_numeric($value)) {
        return (string) $value;          // unquoted: keeps DECIMAL/INT exact
    }
    $quoted = $pdo->quote((string) $value);
    return $quoted === false ? "''" : $quoted;
}

// ── Stream the file ──────────────────────────────────────────────────────────
while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Expires: 0');

function emit(string $s): void
{
    echo $s;
    if (function_exists('flush')) {
        @flush();
    }
}

emit("-- BreadBreak database backup\n");
emit("-- Database:  " . $dbName . "\n");
emit("-- Generated: " . date('Y-m-d H:i:s T') . "\n");
emit("-- Tables:    " . count($tables) . "\n");
emit("-- Restore:   phpMyAdmin -> Import,  or:  " . $dbName . " < this_file.sql\n");
emit("-- Server:    max_allowed_packet = " . $maxPacket . " bytes (statements stay under it)\n");
emit("--\n");
emit("-- NOTE: Re-run migration is NOT how you save data — this file is.\n");
emit("--       Take one before making big changes.\n\n");

emit("SET NAMES utf8mb4;\n");
emit("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
emit("SET FOREIGN_KEY_CHECKS = 0;\n");
emit("SET UNIQUE_CHECKS = 0;\n\n");

// ── Pass 1: schema ───────────────────────────────────────────────────────────
$known = [];
foreach ($tables as $table) {
    $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['Create Table'])) {
        continue;
    }
    $known[] = $table;

    emit("-- ---------------------------------------------------------\n");
    emit("-- Table structure: `$table`\n");
    emit("-- ---------------------------------------------------------\n");
    emit("DROP TABLE IF EXISTS `$table`;\n");
    emit($row['Create Table'] . ";\n\n");
}

// ── Pass 2: data ─────────────────────────────────────────────────────────────
$totalRows = 0;
foreach ($known as $table) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($count === 0) {
        continue;
    }

    $cols = dumpColumnsFor($pdo, $table);
    if (!$cols) {
        continue;
    }

    $colList = [];
    foreach ($cols as $c) {
        $colList[] = '`' . $c['name'] . '`';
    }
    $head = "INSERT INTO `$table` (" . implode(',', $colList) . ") VALUES\n";

    $stmt = $pdo->query("SELECT * FROM `$table`");
    $batch = [];
    $batchBytes = 0;
    $written = 0;

    $flush = function () use (&$batch, &$batchBytes, &$written, $head) {
        if (!$batch) return;
        emit($head . '  ' . implode(",\n  ", $batch) . ";\n");
        $written += count($batch);
        $batch = [];
        $batchBytes = 0;
    };

    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $values = [];
        foreach ($row as $i => $v) {
            // A column count mismatch would silently corrupt the dump — bail.
            if (!isset($cols[$i])) {
                fwrite(STDERR, "Column mismatch on `$table`\n");
                exit(1);
            }
            $values[] = dumpLiteral($pdo, $v, $cols[$i]);
        }
        $rowSql = '(' . implode(',', $values) . ')';
        $rowLen = strlen($rowSql) + 4;

        // A single row bigger than the cap cannot be split across statements
        // (it would be an invalid row), so it goes out on its own.
        if ($rowLen > $batchCap) {
            $flush();
            $batch = [$rowSql];
            $flush();
            continue;
        }
        if ($batch && ($batchBytes + $rowLen) > $batchCap) {
            $flush();
        }
        $batch[] = $rowSql;
        $batchBytes += $rowLen;
    }
    $flush();

    $totalRows += $written;
    emit("-- `$table`: $written row(s)\n\n");
}

emit("SET FOREIGN_KEY_CHECKS = 1;\n");
emit("SET UNIQUE_CHECKS = 1;\n\n");
emit("-- End of backup · " . count($known) . " table(s) · " . $totalRows . " row(s)\n");
exit;
