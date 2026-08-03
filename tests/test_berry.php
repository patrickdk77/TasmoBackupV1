<?php
/*
 * Tasmota32 berry script backup and restore (issue #85).
 *
 * A Tasmota device with a filesystem gets its *.be scripts bundled
 * next to config.dmp in a zip. A device without one keeps producing a
 * plain .dmp, and a plain .dmp still restores, so nothing about the
 * existing behaviour or existing backups changes.
 *
 * Endpoints and formats come from the firmware source,
 * xdrv_50_filesystem.ino, see the comment above getTasmotaBerryFiles.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start(array('status' => 200, 'dl' => 200, 'ufs' => true));

// ---- listing --------------------------------------------------------

test('the filesystem listing finds berry scripts', function () use (
    $addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => true,
        'ufs_files' => array(
            '/autoexec.be' => "print('boot')\n",
            '/helper.be' => "def f() end\n",
        )));
    $files = getTasmotaBerryFiles($addr, 'admin', '');
    sort($files);
    assertSame(array('/autoexec.be', '/helper.be'), $files);
});

test('non berry files are ignored', function () use ($addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => true,
        'ufs_files' => array(
            '/autoexec.be' => "x\n",
            '/notes.txt' => "not a script\n",
            '/photo.jpg' => "binary\n",
        )));
    assertSame(array('/autoexec.be'),
        getTasmotaBerryFiles($addr, 'admin', ''));
});

test('a device with no filesystem yields an empty list, not an error',
function () use ($addr) {
    // esp8266 builds have no USE_UFILESYS and answer 404.
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => false));
    assertSame(array(), getTasmotaBerryFiles($addr, 'admin', ''));
});

test('an unreachable device yields an empty list', function () {
    assertSame(array(), getTasmotaBerryFiles('127.0.0.1:1', 'admin', ''));
});

// ---- reading a single file ------------------------------------------

test('a berry script downloads with its exact contents', function () use (
    $addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => true,
        'ufs_files' => array('/autoexec.be' => "print('exact bytes')\n")));
    assertSame("print('exact bytes')\n",
        getTasmotaFile($addr, 'admin', '', '/autoexec.be'));
});

test('a missing file reports failure', function () use ($addr) {
    assertFalse(getTasmotaFile($addr, 'admin', '', '/nope.be'));
});

// ---- backup ----------------------------------------------------------

test('a device with berry scripts produces a zip holding both',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => true,
        'ufs_files' => array(
            '/autoexec.be' => "print('boot')\n",
            '/lib.be' => "var x = 1\n",
        )));
    dbDeviceAdd('BerryDev', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');

    assertFalse(backupSingle($id, 'BerryDev', $addr, 'admin', '', 0),
        'backup reported failure');
    $file = dbBackupList($id)[0]['filename'];
    assertTrue(substr($file, -4) === '.zip',
        'expected a zip when scripts are present, got '.$file);

    $zip = new ZipArchive();
    assertTrue($zip->open($file) === true, 'the backup is not a valid zip');
    assertTrue($zip->locateName('config.dmp') !== false,
        'config.dmp missing from the bundle');
    assertSame("print('boot')\n", $zip->getFromName('files/autoexec.be'));
    assertSame("var x = 1\n", $zip->getFromName('files/lib.be'));
    assertTrue(strlen($zip->getFromName('config.dmp')) > 0,
        'the config in the bundle is empty');
    $zip->close();
});

test('a device with no filesystem still produces a plain dmp',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => false));
    dbDeviceAdd('PlainDev', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');

    assertFalse(backupSingle($id, 'PlainDev', $addr, 'admin', '', 0));
    $file = dbBackupList($id)[0]['filename'];
    assertTrue(substr($file, -4) === '.dmp',
        'a device with no scripts should still get a .dmp, got '.$file);
});

test('the setting turns the whole thing off', function () use ($addr) {
    global $settings;
    tb_reset_devices();
    $settings['backup_berry'] = 'N';
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => true,
        'ufs_files' => array('/autoexec.be' => "x\n")));
    dbDeviceAdd('OffDev', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');

    assertFalse(backupSingle($id, 'OffDev', $addr, 'admin', '', 0));
    assertTrue(substr(dbBackupList($id)[0]['filename'], -4) === '.dmp',
        'scripts were bundled even though the setting is off');
    $settings['backup_berry'] = 'Y';
});

test('a script that cannot be read fails the backup rather than '.
'silently dropping it', function () use ($addr) {
    tb_reset_devices();
    // The listing advertises two files but only one is readable.
    tb_stub_set(array('status' => 200, 'dl' => 200, 'ufs' => true,
        'ufs_files' => array('/autoexec.be' => "x\n")));
    dbDeviceAdd('PartialDev', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');

    $target = TB_TMP.'/data/backups/partial.zip';
    assertFalse(getTasmotaBackup($addr, 'admin', '', $target, 0,
        array('/autoexec.be', '/vanished.be')),
        'a bundle missing one of its scripts was reported as success');
    assertFileMissing($target,
        'an incomplete bundle was left on disk');
});

// ---- restore ----------------------------------------------------------

test('restoring a bundle uploads the scripts and the config',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200, 'u2' => 200,
        'ufs' => true));
    $bundle = TB_TMP.'/data/backups/restore-bundle.zip';
    $zip = new ZipArchive();
    $zip->open($bundle, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('config.dmp', str_repeat("\x01\x02", 256));
    $zip->addFromString('files/autoexec.be', "print('restored')\n");
    $zip->addFromString('files/two.be', "# second\n");
    $zip->close();

    tb_stub_clear_uploaded_files();
    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $bundle, 0),
        'the bundle restore reported failure');

    $uploaded = tb_stub_uploaded_files();
    assertSame("print('restored')\n", $uploaded['autoexec.be'],
        'autoexec.be was not uploaded with the right contents');
    assertSame("# second\n", $uploaded['two.be']);

    // The config still goes through the normal /rs then /u2 flow.
    $sawRs = false;
    $sawU2 = false;
    foreach (tb_stub_requests() as $r) {
        if (strpos($r, 'GET /rs') === 0) $sawRs = true;
        if (strpos($r, 'POST /u2') === 0) $sawU2 = true;
    }
    assertTrue($sawRs, 'the config restore skipped /rs');
    assertTrue($sawU2, 'the config was never uploaded');
});

test('scripts are uploaded before the config reboots the device',
function () use ($addr) {
    // autoexec.be has to already be on the filesystem when the device
    // comes back up, so the script uploads must precede /u2.
    tb_stub_set(array('status' => 200, 'dl' => 200, 'u2' => 200,
        'ufs' => true));
    $bundle = TB_TMP.'/data/backups/order-bundle.zip';
    $zip = new ZipArchive();
    $zip->open($bundle, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('config.dmp', str_repeat("\x05", 128));
    $zip->addFromString('files/a.be', "# a\n");
    $zip->addFromString('files/b.be', "# b\n");
    $zip->close();

    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $bundle, 0));

    $reqs = tb_stub_requests();
    $lastUfsu = -1;
    $u2 = -1;
    foreach ($reqs as $i => $r) {
        if (strpos($r, 'POST /ufsu') === 0) $lastUfsu = $i;
        if ($u2 < 0 && strpos($r, 'POST /u2') === 0) $u2 = $i;
    }
    assertTrue($lastUfsu >= 0, 'no script upload was logged');
    assertTrue($u2 >= 0, 'no config upload was logged');
    assertTrue($lastUfsu < $u2,
        'the config was restored before the scripts were in place');
});

test('a plain dmp backup still restores unchanged', function () use (
    $addr) {
    tb_stub_set(array('status' => 200, 'u2' => 200, 'ufs' => true));
    $dmp = TB_TMP.'/data/backups/legacy.dmp';
    file_put_contents($dmp, str_repeat("\x03\x04", 256));
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $dmp, 0),
        'an old style .dmp backup no longer restores');
});

test('a failed script upload aborts before rebooting the device',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'u2' => 200, 'ufs' => true,
        'ufsu_status' => 500));
    $bundle = TB_TMP.'/data/backups/failing-bundle.zip';
    $zip = new ZipArchive();
    $zip->open($bundle, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('config.dmp', str_repeat("\x01", 64));
    $zip->addFromString('files/autoexec.be', "x\n");
    $zip->close();

    tb_stub_clear_requests();
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $bundle, 0),
        'a failed script upload reported overall success');
    foreach (tb_stub_requests() as $r) {
        assertTrue(strpos($r, 'POST /u2') !== 0,
            'the config was restored despite a failed script upload');
    }
});

// ---- download naming --------------------------------------------------

test('a berry bundle downloads as a zip, not a dmp', function () {
    assertSame('Kitchen-15_4_0-2026-01-02_03_04_05.zip',
        backupDownloadName(array(
            'name' => 'Kitchen', 'version' => '15.4.0',
            'date' => '2026-01-02 03:04:05',
            'filename' => '/b/AABB-2026_01_02-v15.4.0.zip')));
});

test('a plain config backup still downloads as a dmp', function () {
    assertSame('Kitchen-15_4_0-2026-01-02_03_04_05.dmp',
        backupDownloadName(array(
            'name' => 'Kitchen', 'version' => '15.4.0',
            'date' => '2026-01-02 03:04:05',
            'filename' => '/b/AABB-2026_01_02-v15.4.0.dmp')));
});

test('an openbeken backup downloads as json', function () {
    assertSame('Bulb-1_1_3-2026-01-02_03_04_05.json',
        backupDownloadName(array(
            'name' => 'Bulb', 'version' => '1.1.3',
            'date' => '2026-01-02 03:04:05',
            'filename' => '/b/AABB-2026_01_02-v1.1.3.json')));
});

test('a filename with no usable extension falls back to dmp',
function () {
    assertSame('Odd-1-2026-01-02_03_04_05.dmp',
        backupDownloadName(array(
            'name' => 'Odd', 'version' => '1',
            'date' => '2026-01-02 03:04:05',
            'filename' => '/b/no-extension-here')));
});

test('a zip with no config.dmp is rejected', function () use ($addr) {
    $bundle = TB_TMP.'/data/backups/no-config.zip';
    $zip = new ZipArchive();
    $zip->open($bundle, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('files/autoexec.be', "x\n");
    $zip->close();
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $bundle, 0));
});

tb_test_exit();
