<?php
/*
 * WLED config restore.
 *
 * WLED has no Tasmota-style settings dump, but it does accept a file
 * written straight back onto its filesystem. The supported request is
 * the one its own Security settings page makes:
 *
 *   POST /upload, multipart/form-data, form field "data", with the
 *   multipart filename set to the destination path.
 *
 * The handler keys off the multipart FILENAME, not the field name, and
 * 0.13.x does not normalise a missing leading slash, so the name has to
 * be "/cfg.json" and "/presets.json" literally. Route exists v0.13.0
 * through v16.0.1.
 *   src: wled00/wled_server.cpp handleUpload(),
 *        wled00/data/settings_sec.htm uploadFile()
 *
 * Uploading cfg.json reboots the device by itself from 0.14.0 on, so
 * the config must be sent last, and 0.13.x needs a follow-up /reset.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start(array('status' => 200, 'dl' => 200, 'kind' => 'wled'));

function tb_wled_zip($name, $cfg = '{"id":{"name":"Strip"}}',
    $presets = '{"0":{}}')
{
    $path = TB_TMP.'/data/backups/'.$name;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    if ($cfg !== null)
        $zip->addFromString('cfg.json', $cfg);
    if ($presets !== null)
        $zip->addFromString('presets.json', $presets);
    $zip->close();
    return $path;
}

// ---- the happy path ---------------------------------------------------

test('a wled backup restores both files', function () use ($addr) {
    tb_stub_set(array('status' => 200, 'kind' => 'wled'));
    $zipfile = tb_wled_zip('wled-ok-v0.15.5.zip',
        '{"id":{"name":"Desk"}}', '{"1":{"n":"Sunset"}}');

    tb_stub_clear_uploaded_files();
    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1),
        'the wled restore reported failure');

    $up = tb_stub_uploaded_files();
    assertSame('{"id":{"name":"Desk"}}', $up['/cfg.json'],
        'cfg.json was not uploaded with the right contents');
    assertSame('{"1":{"n":"Sunset"}}', $up['/presets.json']);
});

test('the destination path keeps its leading slash', function () {
    // 0.13.x calls WLED_FS.open(filename) with no normalisation, so a
    // bare "cfg.json" would be written to the wrong place or rejected.
    $up = tb_stub_uploaded_files();
    foreach (array_keys($up) as $name) {
        assertTrue(substr($name, 0, 1) === '/',
            'uploaded as "'.$name.'", must start with a slash');
    }
});

test('the upload goes to POST /upload', function () {
    $sawPost = false;
    foreach (tb_stub_requests() as $r) {
        if (strpos($r, 'POST /upload') === 0)
            $sawPost = true;
    }
    assertTrue($sawPost, 'no POST /upload was made');
});

test('presets go up before the config reboots the device',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'kind' => 'wled'));
    $zipfile = tb_wled_zip('wled-order-v0.15.5.zip');
    tb_stub_clear_uploaded_files();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));

    // tb_stub_uploaded_files preserves upload order in the log.
    $names = array_keys(tb_stub_uploaded_files());
    assertSame(array('/presets.json', '/cfg.json'), $names,
        'cfg.json must be last, it reboots the device on 0.14.0+');
});

// ---- version specific behaviour ---------------------------------------

test('a 0.13.x restore follows up with an explicit reset', function () use (
    $addr) {
    // 0.13.x handleUpload does not set doReboot for cfg.json.
    tb_stub_set(array('status' => 200, 'kind' => 'wled'));
    $zipfile = tb_wled_zip('wled-old-v0.13.3.zip');
    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));

    $sawReset = false;
    foreach (tb_stub_requests() as $r) {
        if (strpos($r, 'GET /reset') === 0) $sawReset = true;
    }
    assertTrue($sawReset, '0.13.x needs an explicit /reset');
});

test('a 0.14+ restore does not reset, the device reboots itself',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'kind' => 'wled'));
    $zipfile = tb_wled_zip('wled-new-v0.14.4.zip');
    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));

    foreach (tb_stub_requests() as $r) {
        assertTrue(strpos($r, 'GET /reset') !== 0,
            '0.14.0+ reboots itself, /reset would be a second reboot');
    }
});

test('a v16 backup is treated as new, not as 0.13.x', function () use (
    $addr) {
    // WLED left the 0.x scheme at 16.0.0. version_compare must not
    // read "16.0.1" as older than "0.14.0".
    tb_stub_set(array('status' => 200, 'kind' => 'wled'));
    $zipfile = tb_wled_zip('wled-v16.0.1.zip');
    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));

    foreach (tb_stub_requests() as $r) {
        assertTrue(strpos($r, 'GET /reset') !== 0,
            'a v16 device was mistaken for 0.13.x');
    }
});

// ---- error paths -------------------------------------------------------

test('a zip with no cfg.json is refused', function () use ($addr) {
    $zipfile = tb_wled_zip('wled-nocfg.zip', null, '{"0":{}}');
    tb_stub_clear_uploaded_files();
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));
    assertCount(0, tb_stub_uploaded_files(),
        'nothing should be uploaded when the backup is unusable');
});

test('an empty cfg.json is refused rather than wiping the device',
function () use ($addr) {
    $zipfile = tb_wled_zip('wled-emptycfg.zip', '', '{"0":{}}');
    tb_stub_clear_uploaded_files();
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));
    assertCount(0, tb_stub_uploaded_files());
});

test('a backup with no presets still restores the config', function () use (
    $addr) {
    // A 0.13.x device with no presets never produced a presets.json.
    tb_stub_set(array('status' => 200, 'kind' => 'wled'));
    $zipfile = tb_wled_zip('wled-nopresets-v0.15.5.zip',
        '{"id":{"name":"Bare"}}', null);
    tb_stub_clear_uploaded_files();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));

    $up = tb_stub_uploaded_files();
    assertSame(array('/cfg.json'), array_keys($up));
});

test('a pin locked device fails instead of reporting success',
function () use ($addr) {
    // 0.14.0+ answers 401 when a settings PIN is set and not entered.
    tb_stub_set(array('status' => 200, 'kind' => 'wled',
        'upload_status' => 401));
    $zipfile = tb_wled_zip('wled-locked-v0.15.5.zip');
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1),
        'a 401 from a pin locked device was reported as success');
});

test('a failed presets upload aborts before touching the config',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'kind' => 'wled',
        'upload_status' => 500));
    $zipfile = tb_wled_zip('wled-fail-v0.15.5.zip');
    tb_stub_clear_uploaded_files();
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $zipfile, 1));
    assertCount(0, tb_stub_uploaded_files(),
        'the config must not be sent after a failed presets upload');
});

test('an unreachable device reports failure', function () {
    $zipfile = tb_wled_zip('wled-unreachable-v0.15.5.zip');
    assertFalse(restoreTasmotaBackup('127.0.0.1:1', 'admin', '',
        $zipfile, 1));
});

test('a corrupt zip is refused', function () use ($addr) {
    $bad = TB_TMP.'/data/backups/wled-corrupt-v0.15.5.zip';
    file_put_contents($bad, 'this is not a zip');
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $bad, 1));
});

tb_test_exit();
