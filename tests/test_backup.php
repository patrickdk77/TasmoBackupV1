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

tb_test_exit();
