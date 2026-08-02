<?php
/*
 * OpenBeken (openshwprojects/OpenBK7231T_App, issue #105) support.
 * type=2, gated the same way WLED (type=1) is.
 *
 * Every endpoint and JSON shape here was read from the real firmware
 * source, not guessed, see the comments next to each stub handler in
 * stub_server.php and the corresponding code in
 * lib/functions.inc.php. Restore actually applies the gpio layout and
 * startup command via a real, symmetric GET/POST /api/pins, this is
 * not a best-effort text dump the way Tasmota's dl/u2 has no
 * equivalent here.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start(array('kind' => 'openbeken', 'dl' => 200));

// ---- scan detection ------------------------------------------------

test('a bare GET / that redirects to /index is detected as OpenBeken',
function () use ($addr) {
    assertSame(2, getTasmotaScan($addr, 'admin', ''));
});

test('the redirect based scan works inside a range scan too', function ()
use ($addr) {
    // getTasmotaScanRange rebuilds the host from
    // CURLINFO_EFFECTIVE_URL's parsed host component only, dropping
    // any port, a real device is always found on plain port 80. That
    // is pre-existing and shared by every type, not something to fix
    // here, so only the type classification is checked, not the exact
    // host string a non standard test port would break.
    list($host, $port) = explode(':', $addr);
    $result = getTasmotaScanRange(array('10.255.255.1', $addr), 'admin', '');
    assertCount(1, $result, 'expected exactly the one real address');
    assertSame($host, $result[0][0]);
    assertSame(2, $result[0][1]);
});

test('a device that answers 200 with the body marker is also detected',
function () {
    // A device whose /index.html (or an override page) is fetched
    // directly rather than through the / redirect, containing the
    // fixed github link every OpenBeken page emits.
    assertTrue(looksLikeOpenBeken(null,
        '<h1><a href="https://github.com/openshwprojects/OpenBK7231T_App/">x</a></h1>',
        200));
});

test('a plain Tasmota or WLED response is not mistaken for OpenBeken',
function () {
    assertFalse(looksLikeOpenBeken(null, '<html>Tasmota</html>', 200));
    assertFalse(looksLikeOpenBeken(null, '<html>WLED</html>', 200));
});

test('a redirect to somewhere other than /index is not OpenBeken',
function () {
    $ch = curl_init();
    // no real request made, just need a handle curl_getinfo accepts
    assertFalse(looksLikeOpenBeken($ch, '', 301));
    curl_close($ch);
});

// ---- discovery / identity -------------------------------------------

test('discovering a new OpenBeken device adds exactly one row',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('kind' => 'openbeken',
        'obk_mac' => 'aa:bb:cc:dd:ee:02', 'obk_shortname' => 'obk-kitchen'));
    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'device not added: '.$msg);
    $d = dbDevices()[0];
    assertSame('obk-kitchen', $d['name']);
    assertSame('AA:BB:CC:DD:EE:02', $d['mac']);
    assertSame('obk-kitchen', $d['hostname'],
        'shortName should double as the hostname');
    assertSame(2, intval($d['type']));
});

test('the version field carries the build string', function () use (
    $addr) {
    tb_reset_devices();
    tb_stub_set(array('kind' => 'openbeken',
        'obk_build' => 'OpenBK7231T_1.2.3_02_08_2026'));
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertSame('OpenBK7231T_1.2.3_02_08_2026', dbDevices()[0]['version']);
});

test('a device that moved to a new ip is matched by mac, not duplicated',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('kind' => 'openbeken', 'obk_mac' => 'aa:bb:cc:dd:ee:03'));
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:03', null);
    dbDeviceUpdate($id, null, '192.168.222.222');

    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'the ip move duplicated the device: '.$msg);
    assertSame($addr, dbDeviceId($id)['ip']);
});

test('an unreachable openbeken candidate is not added', function () {
    $msg = addTasmotaDevice('127.0.0.1:1', 'admin', '', false, false, 2);
    assertTrue(strpos($msg, 'not responding') !== false ||
        strpos($msg, 'not found') !== false,
        'expected a failure message, got: '.$msg);
});

// ---- backup ----------------------------------------------------------

test('a backup saves info and pins together as one json file',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('kind' => 'openbeken',
        'obk_mac' => 'aa:bb:cc:dd:ee:04', 'obk_shortname' => 'obk-lamp',
        'obk_roles' => array(0, 1, 2), 'obk_channels' => array(0, 1, 0)));
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:04', null);

    assertFalse(backupSingle($id, 'obk-lamp', $addr, 'admin', '', 2),
        'backup reported failure');
    assertSame('1', (string)dbBackupCount($id));

    $list = dbBackupList($id);
    assertFileExists($list[0]['filename']);
    assertTrue(substr($list[0]['filename'], -5) === '.json',
        'expected a .json backup file, got '.$list[0]['filename']);

    $saved = json_decode(file_get_contents($list[0]['filename']), true);
    assertTrue(is_array($saved), 'the backup file is not valid json');
    assertSame('obk-lamp', $saved['info']['shortName']);
    assertSame(array(0, 1, 2), $saved['pins']['roles']);
    assertSame(array(0, 1, 0), $saved['pins']['channels']);
    assertFalse(isset($saved['pins']['states']),
        'live channel state should not be saved as if it were a setting');
});

test('a backup failure on api/info is reported, not silently empty',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('kind' => 'openbeken', 'obk_info_status' => 500));
    addTasmotaDevice($addr, 'admin', '', false, false, 2);
    // addTasmotaDevice itself needs api/info to succeed to add the
    // row at all, so drive backupSingle directly against a device row
    // that exists, to isolate the backup path from discovery.
    dbDeviceAdd('Stub', $addr, '', '', 'AA:BB:CC:DD:EE:05', 2, 'stub');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:05', null);
    assertTrue(backupSingle($id, 'Stub', $addr, 'admin', '', 2),
        'a 500 from api/info should not report a successful backup');
    assertSame('0', (string)dbBackupCount($id));
});

test('a backup failure on api/pins is reported, not silently empty',
function () use ($addr) {
    tb_reset_devices();
    dbDeviceAdd('Stub', $addr, '', '', 'AA:BB:CC:DD:EE:06', 2, 'stub');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:06', null);
    tb_stub_set(array('kind' => 'openbeken', 'obk_pins_status' => 500));
    assertTrue(backupSingle($id, 'Stub', $addr, 'admin', '', 2),
        'a 500 from api/pins should not report a successful backup');
    assertSame('0', (string)dbBackupCount($id));
});

// ---- restore -----------------------------------------------------------

test('restore posts roles, channels and the startup command to api/pins',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('kind' => 'openbeken',
        'obk_roles' => array(0, 1), 'obk_channels' => array(0, 1),
        'obk_startcmd' => 'AddChannel 1 0'));
    dbDeviceAdd('Stub', $addr, '', '', 'AA:BB:CC:DD:EE:07', 2, 'stub');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:07', null);
    backupSingle($id, 'Stub', $addr, 'admin', '', 2);
    $backupFile = dbBackupList($id)[0]['filename'];

    tb_stub_clear_post_bodies();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $backupFile, 2));

    $sent = json_decode(tb_stub_last_post_body(), true);
    assertTrue(is_array($sent), 'the posted body was not valid json');
    assertSame(array(0, 1), $sent['roles']);
    assertSame(array(0, 1), $sent['channels']);
    assertSame('AddChannel 1 0', $sent['deviceCommand']);
});

test('restore does not send /rs or a multipart upload, unlike Tasmota',
function () use ($addr) {
    tb_stub_set(array('kind' => 'openbeken',
        'obk_mac' => 'aa:bb:cc:dd:ee:08'));
    dbDeviceAdd('Stub2', $addr, '', '', 'AA:BB:CC:DD:EE:08', 2, 'stub2');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:08', null);
    backupSingle($id, 'Stub2', $addr, 'admin', '', 2);
    $backupFile = dbBackupList($id)[0]['filename'];

    tb_stub_clear_requests();
    restoreTasmotaBackup($addr, 'admin', '', $backupFile, 2);
    foreach (tb_stub_requests() as $r) {
        assertTrue(strpos($r, 'GET /rs') !== 0,
            'openbeken restore should not touch the tasmota /rs endpoint');
        assertTrue(strpos($r, '/u2') === false,
            'openbeken restore should not touch the tasmota /u2 endpoint');
    }
});

test('a rejected restore reports failure', function () use ($addr) {
    tb_stub_set(array('kind' => 'openbeken', 'obk_pins_status' => 200,
        'obk_mac' => 'aa:bb:cc:dd:ee:09'));
    dbDeviceAdd('Stub3', $addr, '', '', 'AA:BB:CC:DD:EE:09', 2, 'stub3');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:09', null);
    backupSingle($id, 'Stub3', $addr, 'admin', '', 2);
    $backupFile = dbBackupList($id)[0]['filename'];

    tb_stub_set(array('kind' => 'openbeken', 'obk_pins_status' => 400));
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $backupFile, 2),
        'a device refusing the pins post must not report success');
});

test('a malformed backup file is rejected before any request is sent',
function () use ($addr) {
    $bad = TB_TMP.'/data/backups/bad-obk.json';
    file_put_contents($bad, 'not even json');
    tb_stub_clear_requests();
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $bad, 2));
    assertCount(0, tb_stub_requests(),
        'a request went out for a backup file that was not usable');
});

test('an unreachable device reports restore failure', function () {
    $dmp = TB_TMP.'/data/backups/unreachable-obk.json';
    file_put_contents($dmp, json_encode(array(
        'info' => array('startcmd' => ''),
        'pins' => array('roles' => array(0), 'channels' => array(0)),
    )));
    assertFalse(restoreTasmotaBackup('127.0.0.1:1', 'admin', '', $dmp, 2));
});

tb_test_exit();
