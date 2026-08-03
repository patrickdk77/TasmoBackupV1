<?php
require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start();

function tb_backup_dir()
{
    return TB_TMP.'/data/backups/';
}

function tb_files($dir)
{
    if (!is_dir($dir))
        return array();
    return array_values(array_diff(scandir($dir), array('.', '..')));
}

// backupSingle returns true on failure and false on success.

// ---- the happy path ---------------------------------------------

test('a working device produces a backup file and a row', function () {
    global $addr;
    tb_stub_set(array('status' => 200, 'dl' => 200));
    dbDeviceAdd('Kitchen', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:01');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01');

    assertFalse(backupSingle($id, 'Kitchen', $addr, 'admin', '', 0),
        'a good backup should report success');
    assertSame('1', (string)dbBackupCount($id));

    $list = dbBackupList($id);
    assertFileExists($list[0]['filename']);
    assertTrue(filesize($list[0]['filename']) > 0,
        'the stored backup should not be empty');
});

test('the backup updates the device version and timestamp', function () {
    global $addr;
    // The row now carries the mac the device reported, so look it up
    // by ip rather than by the placeholder mac it was added with.
    $id = dbDeviceFind($addr, null);
    $d = dbDeviceId($id);
    assertSame('13.4.0(tasmota)', $d['version']);
    assertTrue(strlen($d['lastbackup']) > 10, 'lastbackup not set');
});

test('the backup fills in the mac the device reported', function () {
    global $addr;
    $d = dbDeviceId(dbDeviceFind($addr, null));
    assertSame('AA:BB:CC:DD:EE:FF', $d['mac'],
        'the placeholder mac should have been replaced');
});

// ---- the download failing ---------------------------------------

test('a failed download is reported as a failure', function () {
    global $addr;
    // The device answers status but refuses /dl. This used to return
    // success, so the ui said "Backup completed successfully!" while
    // nothing was saved.
    tb_stub_set(array('status' => 200, 'dl' => 500));
    dbDeviceAdd('Broken', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:09');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:09');

    assertTrue(backupSingle($id, 'Broken', $addr, 'admin', '', 0),
        'a failed download must report failure');
    assertSame('0', (string)dbBackupCount($id));
});

test('a failed download leaves no stub file behind', function () {
    $dir = tb_backup_dir().'Broken';
    assertCount(0, tb_files($dir),
        'an empty partial backup was left on disk');
});

test('a failed backup does not move lastbackup', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:09');
    assertSame(null, dbDeviceId($id)['lastbackup']);
});

// ---- the device being offline -----------------------------------

test('an offline device is reported as a failure', function () {
    dbDeviceAdd('Offline', '127.0.0.1:1', '13.4.0', '',
        'AA:BB:CC:DD:EE:0A');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:0A');
    assertTrue(backupSingle($id, 'Offline', '127.0.0.1:1', 'admin', '', 0),
        'an unreachable device must report failure');
    assertSame('0', (string)dbBackupCount($id));
});

test('a device answering with garbage is a failure', function () {
    global $addr;
    tb_stub_set(array('status' => 200, 'body' => 'not json',
        'dl' => 200));
    dbDeviceAdd('Garbage', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:0B');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:0B');
    assertTrue(backupSingle($id, 'Garbage', $addr, 'admin', '', 0),
        'an unparsable status must report failure');
    assertSame('0', (string)dbBackupCount($id));
});

test('a device rejecting the status request is a failure', function () {
    global $addr;
    tb_stub_set(array('status' => 401, 'dl' => 200));
    dbDeviceAdd('Locked', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:0C');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:0C');
    assertTrue(backupSingle($id, 'Locked', $addr, 'admin', '', 0),
        'an http 401 must report failure');
});

// ---- backupAll and the schedule ---------------------------------

test('backupAll counts the failures it saw', function () {
    global $addr;
    tb_stub_set(array('status' => 200, 'dl' => 500));
    $r = backupAll(false);
    assertTrue(is_array($r), 'backupAll should return a pair');
    assertTrue($r[1] > 0, 'nothing was attempted');
    assertSame($r[1], $r[0],
        'every device is broken here, so all should have failed');
});

test('the schedule runs when no minimum hours was ever saved', function () {
    global $settings, $addr;
    // regression for #95. An unsaved backup_minhours meant the cron
    // run returned immediately while a manual run still worked.
    unset($settings['backup_minhours']);
    tb_stub_set(array('status' => 200, 'dl' => 200));
    $r = backupAll(true);
    assertTrue(is_array($r),
        'the scheduled run bailed out instead of backing up');
    assertTrue($r[1] > 0, 'the scheduled run attempted nothing');
});

test('a minimum of 0 still disables the schedule', function () {
    global $settings;
    $settings['backup_minhours'] = 0;
    assertFalse(backupAll(true),
        '0 hours should keep the schedule switched off');
});

test('a manual run ignores the minimum hours', function () {
    global $settings;
    $settings['backup_minhours'] = 0;
    assertTrue(is_array(backupAll(false)),
        'a manual Backup All must always run');
});

test('the schedule skips devices backed up inside the window',
function () {
    global $settings, $addr;
    $settings['backup_minhours'] = 24;
    tb_stub_set(array('status' => 200, 'dl' => 200));
    backupAll(true);            // everything reachable gets a backup
    $first = backupAll(true);   // the good ones are not due again
    assertTrue($first[1] < 5,
        'devices inside the window should not be retried');
    assertSame($first[1], $first[0],
        'only the broken devices should still be attempted');
});

// ---- cleanup must not run on a failed backup --------------------

test('a failed backup does not prune the older good ones', function () {
    global $settings, $addr;
    $settings['backup_maxdays'] = 1;
    $settings['backup_minhours'] = 0;

    tb_stub_set(array('status' => 200, 'dl' => 200));
    dbDeviceAdd('Pruned', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:0D');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:0D');
    $old = tb_backup_dir().'old-good.dmp';
    file_put_contents($old, 'good backup');
    dbNewBackup($id, 'Pruned', '13.4.0',
        date('Y-m-d H:i:s', time() - (86400 * 30)), 1, $old,
        'AA:BB:CC:DD:EE:0D', 0);
    assertSame('1', (string)dbBackupCount($id));

    // now the device breaks and a scheduled run comes around
    tb_stub_set(array('status' => 200, 'dl' => 500));
    assertTrue(backupSingle($id, 'Pruned', $addr, 'admin', '', 0));
    if (!backupSingle($id, 'Pruned', $addr, 'admin', '', 0))
        backupCleanup($id);

    assertSame('1', (string)dbBackupCount($id),
        'the last good backup was pruned after a failed run');
    assertFileExists($old);
});

test('the scheduled cron path never prunes a locked backup', function ()
use ($addr) {
    // Exercises the real chain cron actually runs: backupall.php calls
    // backupAll(true), which calls backupCleanup() after every
    // successful backup, which calls dbBackupTrim(). Not just
    // dbBackupTrim() directly, so a future change to that chain (a new
    // cleanup call site, a bypass) would be caught here too.
    global $settings;
    tb_reset_devices();
    $settings['backup_minhours'] = 0; // manual-style: always due
    $settings['backup_maxcount'] = 1;
    $settings['backup_maxdays'] = 0;

    dbDeviceAdd('CronLock', $addr, '1', '', 'AA:BB:CC:DD:EE:0E');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:0E');
    $old = tb_backup_dir().'cronlock-old.dmp';
    file_put_contents($old, 'old, locked');
    dbNewBackup($id, 'CronLock', '13.4.0',
        date('Y-m-d H:i:s', time() - 86400), 1, $old,
        'AA:BB:CC:DD:EE:0E', 0);
    dbBackupSetLocked(dbBackupList($id)[0]['id'], true);

    tb_stub_set(array('status' => 200, 'dl' => 200));
    backupAll(true); // the exact call backupall.php makes for cron

    assertFileExists($old, 'the locked backup file was removed by cron');
    $list = dbBackupList($id);
    $found = false;
    foreach ($list as $b) {
        if ($b['filename'] === $old)
            $found = intval($b['locked']) === 1;
    }
    assertTrue($found, 'the locked backup row is gone after a cron run');
});

// ---- bulk download (Download Selected) ---------------------------

/*
 * downloadSelectedBackups() streams a zip and calls exit(0) on
 * success, which would kill this whole test file if called in
 * process. Run in a subprocess pointed at the same throwaway sqlite
 * database (same TB_TMP tree), so it sees the devices/backups this
 * test just created, and capture its raw stdout as the zip bytes.
 */
function tb_run_download_selected($ids, $fn = 'downloadSelectedBackups')
{
    $idsPhp = var_export($ids, true);
    $script = TB_TMP.'/download_probe.php';
    file_put_contents($script,
        "<?php\n\$db_upgrade=true;\n".
        "require_once('".TB_TMP."/lib/functions.inc.php');\n".
        $fn."(".$idsPhp.");\n".
        "echo 'NO_EXIT_REACHED';\n");
    $out = array();
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).
        ' 2>'.escapeshellarg(TB_TMP.'/download_probe.err'), $out, $rc);
    return implode("\n", $out);
}

test('downloading a selection zips each device latest backup',
function () {
    tb_reset_devices();
    dbDeviceAdd('Zip1', '10.7.7.1', '1', '', 'AA:BB:CC:DD:EE:C1');
    dbDeviceAdd('Zip2', '10.7.7.2', '1', '', 'AA:BB:CC:DD:EE:C2');
    $id1 = dbDeviceFind(null, 'AA:BB:CC:DD:EE:C1');
    $id2 = dbDeviceFind(null, 'AA:BB:CC:DD:EE:C2');

    $f1old = TB_TMP.'/data/backups/zip1-old.dmp';
    $f1new = TB_TMP.'/data/backups/zip1-new.dmp';
    $f2 = TB_TMP.'/data/backups/zip2.dmp';
    file_put_contents($f1old, 'zip1 old content');
    file_put_contents($f1new, 'zip1 new content');
    file_put_contents($f2, 'zip2 content');
    dbNewBackup($id1, 'Zip1', '1',
        date('Y-m-d H:i:s', time() - 3600), 1, $f1old,
        'AA:BB:CC:DD:EE:C1', 0);
    dbNewBackup($id1, 'Zip1', '1', date('Y-m-d H:i:s'), 1, $f1new,
        'AA:BB:CC:DD:EE:C1', 0);
    dbNewBackup($id2, 'Zip2', '1', date('Y-m-d H:i:s'), 1, $f2,
        'AA:BB:CC:DD:EE:C2', 0);

    $raw = tb_run_download_selected(array($id1, $id2));
    $zippath = TB_TMP.'/downloaded.zip';
    file_put_contents($zippath, $raw);

    $zip = new ZipArchive();
    assertTrue($zip->open($zippath) === true, 'output was not a valid zip');
    assertSame(2, $zip->numFiles,
        'expected exactly one entry per device (the latest backup)');

    $names = array();
    for ($i = 0; $i < $zip->numFiles; $i++)
        $names[] = $zip->getNameIndex($i);
    $found1 = false;
    $found2 = false;
    foreach ($names as $n) {
        if (strpos($n, 'zip1-new.dmp') !== false) {
            $found1 = true;
            assertSame('zip1 new content', $zip->getFromName($n),
                'the newer backup should have been used, not the older one');
        }
        if (strpos($n, 'zip2.dmp') !== false)
            $found2 = true;
    }
    assertTrue($found1, 'zip1 latest backup missing from the zip');
    assertTrue($found2, 'zip2 backup missing from the zip');
    $zip->close();
});

test('a device with no backups yet is skipped, not fatal', function () {
    tb_reset_devices();
    dbDeviceAdd('NoBackupsYet', '10.7.7.3', '1', '', 'AA:BB:CC:DD:EE:C3');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:C3');

    $raw = tb_run_download_selected(array($id));
    assertSame('NO_EXIT_REACHED', trim($raw),
        'nothing to zip should return false, not exit and stream a zip');
});

test('an empty selection is rejected before doing any work', function () {
    $raw = tb_run_download_selected(array());
    assertSame('NO_EXIT_REACHED', trim($raw));
});

// ---- bulk download of specific versions (listbackups.php) ---------

test('downloading selected versions zips exactly those backups',
function () {
    tb_reset_devices();
    dbDeviceAdd('VerDev', '10.7.7.9', '1', '', 'AA:BB:CC:DD:EE:C9');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:C9');

    $f1 = TB_TMP.'/data/backups/ver1.dmp';
    $f2 = TB_TMP.'/data/backups/ver2.dmp';
    $f3 = TB_TMP.'/data/backups/ver3.dmp';
    file_put_contents($f1, 'version one');
    file_put_contents($f2, 'version two');
    file_put_contents($f3, 'version three, not selected');
    dbNewBackup($devid, 'VerDev', '1',
        date('Y-m-d H:i:s', time() - 3600), 1, $f1,
        'AA:BB:CC:DD:EE:C9', 0);
    dbNewBackup($devid, 'VerDev', '1',
        date('Y-m-d H:i:s', time() - 1800), 1, $f2,
        'AA:BB:CC:DD:EE:C9', 0);
    dbNewBackup($devid, 'VerDev', '1', date('Y-m-d H:i:s'), 1, $f3,
        'AA:BB:CC:DD:EE:C9', 0);

    $list = dbBackupList($devid);
    $wantIds = array();
    foreach ($list as $b) {
        if (basename($b['filename']) === 'ver1.dmp' ||
                basename($b['filename']) === 'ver2.dmp')
            $wantIds[] = $b['id'];
    }
    assertCount(2, $wantIds, 'test setup did not find both target rows');

    $raw = tb_run_download_selected($wantIds,
        'downloadSelectedBackupVersions');
    $zippath = TB_TMP.'/downloaded-versions.zip';
    file_put_contents($zippath, $raw);

    $zip = new ZipArchive();
    assertTrue($zip->open($zippath) === true, 'output was not a valid zip');
    assertSame(2, $zip->numFiles,
        'expected exactly the two selected versions, not all three');
    // locateName can validly return 0 (the first entry), assertNotEquals
    // uses loose comparison where false == 0, so check strictly instead.
    assertTrue($zip->locateName('ver1.dmp') !== false,
        'ver1.dmp missing from the zip');
    assertTrue($zip->locateName('ver2.dmp') !== false,
        'ver2.dmp missing from the zip');
    assertTrue($zip->locateName('ver3.dmp') === false,
        'a version that was not selected ended up in the zip');
    $zip->close();
});

test('an unknown backup id in the selection is skipped, not fatal',
function () {
    $raw = tb_run_download_selected(array(999999),
        'downloadSelectedBackupVersions');
    assertSame('NO_EXIT_REACHED', trim($raw));
});

tb_test_exit();
