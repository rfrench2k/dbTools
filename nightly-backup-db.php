<?php
// Nightly Backup (nightly-backup.php, ajax-nightly-backup.php): connection to the tools database, which holds the
// backup's setup and run history (backup_* tables). Login from machine env TOOLS_DB_USER / TOOLS_DB_PASS.
const NB_DSN = 'mysql:host=127.0.0.1;port=3307;dbname=tools;charset=utf8mb4';

function nb_db() {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(NB_DSN, getenv('TOOLS_DB_USER'), getenv('TOOLS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function nb_q($sql, array $args = []) {
    $st = nb_db()->prepare($sql);
    $st->execute($args);
    return $st;
}

/** Settings shown on the page: name => [label, input type, what it does]. */
function nb_settingDefs() {
    return [
        'schedule_time' => ['Nightly start time', 'time', 'When the Backup - Nightly task starts.'],
        'weekly_day' => ['Weekly check day', 'weekday', 'Checks the newest copies are really on Google Drive, takes the weekly Qdrant copy, and sends the weekly summary email.'],
        'restore_test_day' => ['Restore test day', 'number:1:28', "Restores each database's newest copy into a scratch database to prove it works, then drops it."],
        'keep_versions' => ['Copies kept', 'number:1:365', 'Per database / collection (an app can have its own).'],
        'keep_monthly' => ['Monthly copies kept', 'number:0:120', 'Plus the newest copy of each of this many months.'],
        'keep_deleted_files_days' => ['Replaced / deleted files kept (days)', 'number:1:365', 'For folders: files deleted or overwritten on the server stay on Drive this long.'],
        'email_to' => ['Email to', 'email', ''],
        'email_summary' => ['Email me', 'summary', 'Runs with errors always email.'],
        'rclone_remote' => ['Google Drive location', 'text', 'rclone remote:folder. Changing it starts new copies there; the old ones stay where they are.'],
    ];
}
