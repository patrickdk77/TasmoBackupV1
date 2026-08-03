<?php
require_once(__DIR__.'/bootstrap.php');

// ---- settings ---------------------------------------------------

test('a setting round trips', function () {
    global $settings;
    assertTrue(dbSettingsUpdate('amount', '50'));
    assertSame('50', $settings['amount']);
});

test('an existing setting is replaced, not duplicated', function () {
    global $db_handle, $settings;
    dbSettingsUpdate('amount', '10');
    dbSettingsUpdate('amount', '20');
    $stm = $db_handle->prepare(
        "select count(*) from settings where name='amount'");
    $stm->execute();
    assertSame('1', (string)$stm->fetchColumn());
    assertSame('20', $settings['amount']);
});

// ---- adding devices ---------------------------------------------

test('a device can be added and read back', function () {
    assertTrue(dbDeviceAdd('Kitchen', '192.168.1.25', '13.4.0', '',
        'AA:BB:CC:DD:EE:01'));
    $id = dbDeviceFind('192.168.1.25', 'AA:BB:CC:DD:EE:01');
    assertNotEquals(false, $id);
    $d = dbDeviceId($id);
    assertSame('Kitchen', $d['name']);
    assertSame('192.168.1.25', $d['ip']);
});

test('a device with no mac can still be added', function () {
    assertTrue(dbDeviceAdd('Legacy', '192.168.1.30', '6.0.0', '', ''));
    assertTrue(dbDeviceExist('192.168.1.30', ''));
});

// ---- lookups, the negative half ---------------------------------

test('an unknown ip and mac do not exist', function () {
    assertFalse(dbDeviceExist('10.9.9.9', 'FF:FF:FF:FF:FF:FF'));
    assertFalse(dbDeviceFind('10.9.9.9', 'FF:FF:FF:FF:FF:FF'));
});

test('an empty mac must not match the mac-less device', function () {
    // regression for #89. The empty string used to match every row
    // with a blank mac, so unrelated devices looked like duplicates.
    assertFalse(dbDeviceExist('10.9.9.9', ''),
        'empty mac matched an existing row');
    assertFalse(dbDeviceFind('10.9.9.9', ''),
        'empty mac matched an existing row');
});

test('a known mac wins over an unknown ip', function () {
    $id = dbDeviceFind('10.9.9.9', 'AA:BB:CC:DD:EE:01');
    assertNotEquals(false, $id);
    assertSame('Kitchen', dbDeviceId($id)['name']);
});

test('a known ip still matches when no mac is passed', function () {
    assertTrue(dbDeviceExist('192.168.1.25', null));
});

test('dbDeviceId on a missing id returns false', function () {
    assertFalse(dbDeviceId(999999));
});

test('dbDeviceIp on a missing ip returns an empty list', function () {
    assertCount(0, dbDeviceIp('10.9.9.9'));
});

// ---- updates ----------------------------------------------------

test('a device can be renamed by id', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01');
    assertTrue(dbDeviceUpdate($id, 'Kitchen Light'));
    assertSame('Kitchen Light', dbDeviceId($id)['name']);
});

test('an update with no id and no mac changes nothing', function () {
    assertFalse(dbDeviceUpdate(null, 'Nope'));
});

// ---- backups ----------------------------------------------------

test('a backup row updates the device counters', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01');
    $f = TB_TMP.'/data/backups/b1.dmp';
    file_put_contents($f, 'x');
    assertTrue(dbNewBackup($id, 'Kitchen Light', '13.4.0',
        date('Y-m-d H:i:s'), 1, $f, 'AA:BB:CC:DD:EE:01', 0));
    assertSame('1', (string)dbBackupCount($id));
    assertSame('1', (string)dbDeviceId($id)['noofbackups']);
});

test('a backup with no version is stored as Unknown', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01');
    $f = TB_TMP.'/data/backups/b2.dmp';
    file_put_contents($f, 'x');
    dbNewBackup($id, 'Kitchen Light', '', date('Y-m-d H:i:s'), 1, $f,
        'AA:BB:CC:DD:EE:01', 0);
    $list = dbBackupList($id);
    assertSame('Unknown', $list[0]['version']);
});

test('counting backups of an unknown device gives zero', function () {
    assertSame('0', (string)dbBackupCount(999999));
});

test('deleting a missing backup id returns cleanly', function () {
    assertFalse(dbBackupDel(999999));
});

test('deleting a backup removes the row and the file', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01');
    $f = TB_TMP.'/data/backups/b3.dmp';
    file_put_contents($f, 'x');
    dbNewBackup($id, 'Kitchen Light', '13.4.0', date('Y-m-d H:i:s'), 1,
        $f, 'AA:BB:CC:DD:EE:01', 0);
    $before = dbBackupCount($id);
    $list = dbBackupList($id);
    assertTrue(dbBackupDel($list[0]['id']));
    assertFileMissing($f);
    assertEquals($before - 1, dbBackupCount($id));
});

// ---- trimming ---------------------------------------------------

test('trim with no limits keeps everything', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01');
    $before = dbBackupCount($id);
    dbBackupTrim($id, 0, 0);
    assertEquals($before, dbBackupCount($id));
});

test('trim to a max count keeps exactly that many', function () {
    assertTrue(dbDeviceAdd('Trimmed', '192.168.1.40', '13.4.0', '',
        'AA:BB:CC:DD:EE:02'));
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:02');
    for ($i = 0; $i < 5; $i++) {
        $f = TB_TMP.'/data/backups/t'.$i.'.dmp';
        file_put_contents($f, 'x');
        dbNewBackup($id, 'Trimmed', '13.4.0',
            date('Y-m-d H:i:s', time() - (86400 * (10 - $i))), 1, $f,
            'AA:BB:CC:DD:EE:02', 0);
    }
    assertSame('5', (string)dbBackupCount($id));
    dbBackupTrim($id, 0, 2);
    assertSame('2', (string)dbBackupCount($id));
});

test('trim by age only removes the older rows', function () {
    assertTrue(dbDeviceAdd('Aged', '192.168.1.41', '13.4.0', '',
        'AA:BB:CC:DD:EE:03'));
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:03');
    $old = TB_TMP.'/data/backups/old.dmp';
    $new = TB_TMP.'/data/backups/new.dmp';
    file_put_contents($old, 'x');
    file_put_contents($new, 'x');
    dbNewBackup($id, 'Aged', '13.4.0',
        date('Y-m-d H:i:s', time() - (86400 * 40)), 1, $old,
        'AA:BB:CC:DD:EE:03', 0);
    dbNewBackup($id, 'Aged', '13.4.0', date('Y-m-d H:i:s'), 1, $new,
        'AA:BB:CC:DD:EE:03', 0);
    dbBackupTrim($id, 30, 0);
    assertSame('1', (string)dbBackupCount($id));
    assertFileMissing($old);
    assertFileExists($new);
});

// ---- deleting devices -------------------------------------------

test('deleting an unknown ip returns false', function () {
    assertFalse(dbDeviceDel('10.9.9.9'));
});

test('deleting a device takes its backups with it', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:03');
    assertTrue(dbDeviceDel('192.168.1.41'));
    assertFalse(dbDeviceId($id));
    assertSame('0', (string)dbBackupCount($id));
});

test('deleting by an unknown id returns false', function () {
    assertFalse(dbDeviceDelById(999999));
    assertFalse(dbDeviceDelById(0));
});

test('deleting by id takes its backups with it, same as by ip',
function () {
    dbDeviceAdd('ById', '10.6.6.1', '1', '', 'AA:BB:CC:DD:EE:B0');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:B0');
    $f = TB_TMP.'/data/backups/byid.dmp';
    file_put_contents($f, 'x');
    dbNewBackup($id, 'ById', '1', date('Y-m-d H:i:s'), 1, $f,
        'AA:BB:CC:DD:EE:B0', 0);
    assertTrue(dbDeviceDelById($id));
    assertFalse(dbDeviceId($id));
    assertFileMissing($f, 'the backup file should be removed too');
});

// ---- rename -------------------------------------------------------

test('renaming an unknown address reports failure', function () {
    assertFalse(dbDeviceRename('10.9.9.9', 'Nope', '10.9.9.9', ''),
        'a rename that matched nothing reported success');
});

test('renaming touches one row, not every row sharing the ip',
function () {
    dbDeviceAdd('ShareA', '10.5.5.5', '1', '', 'AA:BB:CC:DD:EE:A1', 0,
        'share-a');
    dbDeviceAdd('ShareB', '10.5.5.5', '1', '', 'AA:BB:CC:DD:EE:B1', 0,
        'share-b');
    assertTrue(dbDeviceRename('10.5.5.5', 'RenamedA', '10.5.5.6', '',
        'AA:BB:CC:DD:EE:A1'));
    assertSame('RenamedA',
        dbDeviceId(dbDeviceFind(null, 'AA:BB:CC:DD:EE:A1', null))['name']);
    $b = dbDeviceId(dbDeviceFind(null, 'AA:BB:CC:DD:EE:B1', null));
    assertSame('ShareB', $b['name'], 'the other row was renamed too');
    assertSame('10.5.5.5', $b['ip'], 'the other row was moved too');
});

// ---- locked backups (issue #88) ------------------------------------

function tb_make_backup($devid, $file, $ageDays = 0)
{
    file_put_contents($file, 'x');
    $date = date('Y-m-d H:i:s', time() - (86400 * $ageDays));
    dbNewBackup($devid, 'Locking', '1', $date, 1, $file);
    $list = dbBackupList($devid);
    return $list[0]['id']; // most recent, matches the date just inserted
}

test('locking and unlocking a backup round trips', function () {
    dbDeviceAdd('LockDev', '10.8.8.1', '1', '', 'AA:BB:CC:DD:EE:D1');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:D1');
    $bid = tb_make_backup($devid, TB_TMP.'/data/backups/lock1.dmp');

    assertSame('0', (string)dbBackupId($bid)['locked']);
    assertTrue(dbBackupSetLocked($bid, true));
    assertSame('1', (string)dbBackupId($bid)['locked']);
    assertTrue(dbBackupSetLocked($bid, false));
    assertSame('0', (string)dbBackupId($bid)['locked']);
});

test('locking an unknown backup id returns false', function () {
    assertFalse(dbBackupSetLocked(999999, true));
});

test('dbBackupLockLatest locks the most recent backup, not an older one',
function () {
    dbDeviceAdd('LockDev2', '10.8.8.2', '1', '', 'AA:BB:CC:DD:EE:D2');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:D2');
    $old = tb_make_backup($devid, TB_TMP.'/data/backups/lock2old.dmp', 5);
    $new = tb_make_backup($devid, TB_TMP.'/data/backups/lock2new.dmp', 0);

    assertTrue(dbBackupLockLatest($devid));
    assertSame('1', (string)dbBackupId($new)['locked']);
    assertSame('0', (string)dbBackupId($old)['locked'],
        'the older backup should not have been locked');
});

test('dbBackupLockLatest on a device with no backups returns false',
function () {
    dbDeviceAdd('NoBackupsLock', '10.8.8.3', '1', '', 'AA:BB:CC:DD:EE:D3');
    assertFalse(dbBackupLockLatest(
        dbDeviceFind(null, 'AA:BB:CC:DD:EE:D3')));
});

test('a locked backup cannot be deleted through dbBackupDel', function () {
    dbDeviceAdd('LockDev4', '10.8.8.4', '1', '', 'AA:BB:CC:DD:EE:D4');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:D4');
    $bid = tb_make_backup($devid, TB_TMP.'/data/backups/lock4.dmp');
    dbBackupSetLocked($bid, true);

    assertFalse(dbBackupDel($bid), 'a locked backup reported as deleted');
    assertFileExists(TB_TMP.'/data/backups/lock4.dmp');
    assertNotEquals(false, dbBackupId($bid), 'the row was removed anyway');

    dbBackupSetLocked($bid, false);
    assertTrue(dbBackupDel($bid), 'unlocking should allow delete again');
    assertFileMissing(TB_TMP.'/data/backups/lock4.dmp');
});

test('dbBackupTrim by count never removes a locked backup', function () {
    dbDeviceAdd('LockDev5', '10.8.8.5', '1', '', 'AA:BB:CC:DD:EE:D5');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:D5');
    $locked = tb_make_backup($devid, TB_TMP.'/data/backups/lock5a.dmp', 10);
    dbBackupSetLocked($locked, true);
    for ($i = 0; $i < 4; $i++)
        tb_make_backup($devid, TB_TMP.'/data/backups/lock5b'.$i.'.dmp',
            3 - $i);

    // 1 locked + 4 unlocked = 5 total, keep 2.
    dbBackupTrim($devid, 0, 2);

    assertNotEquals(false, dbBackupId($locked),
        'the locked backup was removed by a count trim');
    assertSame('1', (string)dbBackupId($locked)['locked']);
    // The count budget applies only to the unlocked pool, so 2 of the
    // 4 unlocked backups survive alongside the 1 locked one.
    assertSame('3', (string)dbBackupCount($devid));
});

test('dbBackupTrim by age never removes a locked backup', function () {
    dbDeviceAdd('LockDev6', '10.8.8.6', '1', '', 'AA:BB:CC:DD:EE:D6');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:D6');
    $locked = tb_make_backup($devid, TB_TMP.'/data/backups/lock6.dmp', 90);
    dbBackupSetLocked($locked, true);

    dbBackupTrim($devid, 30, 0); // remove anything older than 30 days

    assertNotEquals(false, dbBackupId($locked),
        'an old locked backup was removed by an age trim');
    assertFileExists(TB_TMP.'/data/backups/lock6.dmp');
});

test('deleting a device preserves its locked backups', function () {
    // "never deleted, ever, unless unlocked" is taken literally: even
    // removing the device itself must not take a locked backup with
    // it, dbBackupTrim(...,$all=true) is what dbDeviceDelById uses to
    // wipe a device's backups, and it is built on the same filter.
    dbDeviceAdd('LockDev7', '10.8.8.7', '1', '', 'AA:BB:CC:DD:EE:D7');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:D7');
    $locked = tb_make_backup($devid, TB_TMP.'/data/backups/lock7.dmp');
    dbBackupSetLocked($locked, true);

    assertTrue(dbDeviceDelById($devid));
    assertFalse(dbDeviceId($devid), 'the device itself should be gone');
    assertNotEquals(false, dbBackupId($locked),
        'the locked backup was deleted along with its device');
    assertFileExists(TB_TMP.'/data/backups/lock7.dmp');
});

tb_test_exit();
