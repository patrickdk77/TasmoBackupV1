<?php
/*
 * Old Tasmota firmware shapes, 3.9.13 through 12.x.
 *
 * The supported floor is 3.9.13: /dl and /rs were introduced there as
 * one feature, and v3.9.12 and earlier register no config download
 * route at all, so 2.x is permanently out of scope.
 *
 * Every fixture and boundary below is sourced in
 * tests/COMPAT-RESEARCH.md, which cites the firmware file and line at
 * the release tag it was read from. There is one test per confirmed
 * defect (findings 32 to 40), each written so that it fails against the
 * code as it was before the fix.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start(array('status' => 200, 'dl' => 200, 'u2' => 200));

function tb_legacy_status($fw)
{
    tb_stub_set(array('status' => 200, 'dl' => 200, 'u2' => 200,
        'fw' => $fw));
    return getTasmotaStatus($GLOBALS['tb_stub']['addr'], 'admin', '', 0);
}

// ---- 32: the scan marker is absent before v5.10.0 ---------------------

test('a device whose root page names nothing is still identified',
function () use ($addr) {
    // HTTP_END was just "</div></body></html>" until v5.10.0 added the
    // version footer, so strpos($body,'Tasmota') cannot work on
    // 3.9.13 through 5.9.1. Falling back to a Status 0 probe is the
    // only way those devices can ever be registered.
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '5.0',
        'nomarker' => true));
    assertSame(0, getTasmotaScan($addr, 'admin', ''),
        'a pre-5.10.0 device could not be identified at all');
});

test('a page with no marker and no status api is still not a device',
function () use ($addr) {
    // The fallback must not turn every random web server into a
    // Tasmota. A 200 page plus a /cm that does not answer Status is
    // the negative case.
    tb_stub_set(array('status' => 500, 'dl' => 200, 'nomarker' => true));
    assertFalse(getTasmotaScan($addr, 'admin', ''),
        'something that is not a Tasmota was claimed as one');
});

// ---- 33: FriendlyName is a scalar string up to v5.12.0 ----------------

test('a scalar FriendlyName is read whole, not one character',
function () use ($addr) {
    // isset($str[0]) is true for any non-empty string and $str[0] is
    // its first byte, so this used to name the device "K".
    $status = tb_legacy_status('5.12');
    assertSame('Kitchen Light', tasmotaFriendlyName($status));
});

test('an array FriendlyName still reads its first entry', function () use (
    $addr) {
    $status = tb_legacy_status('13');
    assertSame('Kitchen Light', tasmotaFriendlyName($status));
});

test('a device named by a scalar FriendlyName gets the whole name',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '5.12'));
    addTasmotaDevice($addr, 'admin', '', true, false, 0);
    $rows = dbDevices();
    assertCount(1, $rows);
    // Status.DeviceName does not exist until v8.3.1, so FriendlyName is
    // what naming falls through to on 5.x. It used to arrive as "K".
    assertSame('Kitchen Light', $rows[0]['name'],
        'the scalar FriendlyName was truncated to its first character');
});

// ---- 34: StatusFWR version key renames --------------------------------

test('the pre-5.7.0 Program key is read as the version', function () use (
    $addr) {
    $status = tb_legacy_status('5.6.1');
    assertTrue(!isset($status['StatusFWR']['Version']),
        'the fixture is wrong, 5.6.1 must not carry a Version key');
    assertSame('5.6.1', tbStatusValue($status['StatusFWR'],
        array('Version','Program','Versie','Wersja')));
});

test('a dutch 5.7-5.10 build spelling Versie is read', function () use (
    $addr) {
    $status = tb_legacy_status('5.7-nl');
    assertSame('5.7.0', tbStatusValue($status['StatusFWR'],
        array('Version','Program','Versie','Wersja')));
});

test('a backup from a Program-era device records its version',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '5.6.1'));
    dbDeviceAdd('Old', $addr, '', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');
    assertFalse(backupSingle($id, 'Old', $addr, 'admin', '', 0));
    $b = dbBackupList($id)[0];
    assertSame('5.6.1', $b['version'],
        'the version was lost, the row would read Unknown');
    assertTrue(strpos($b['filename'], '-v5.6.1.dmp') !== false,
        'the filename lost its version: '.basename($b['filename']));
});

// ---- 35: the mac key is MAC on german 5.7-5.10 builds -----------------

test('a german build spelling MAC still yields a mac', function () use (
    $addr) {
    $status = tb_legacy_status('5.7-de');
    assertTrue(!isset($status['StatusNET']['Mac']),
        'the fixture is wrong, a de build must not carry Mac');
    assertSame('AA:BB:CC:DD:EE:FF',
        strtoupper(tbStatusValue($status['StatusNET'], array('Mac'))));
});

test('a backup from a german build keeps its mac identity',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '5.7-de'));
    dbDeviceAdd('De', $addr, '', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');
    assertFalse(backupSingle($id, 'De', $addr, 'admin', '', 0));
    $b = dbBackupList($id)[0];
    // mac lives on the device row, dbDeviceBackups stamps it there.
    $dev = dbDeviceId($id);
    assertSame('AA:BB:CC:DD:EE:FF', strtoupper($dev['mac']),
        'the mac was lost, so a dhcp change would duplicate the device');
    assertTrue(strpos(basename($b['filename']), '-') !== 0,
        'an empty mac left the filename starting with a dash');
});

// ---- 36: a status2/status5 reply that does not carry the block --------

test('a weblog 0 device is treated as unusable, not backed up blind',
function () use ($addr) {
    // Every /cm answers 200 with {"WARNING":...}. The old code spliced
    // that in as NULL and filed a version-less, mac-less backup, which
    // then let backupCleanup prune the good ones.
    tb_reset_devices();
    dbDeviceAdd('Quiet', $addr, '9.1.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');
    tb_stub_set(array('status' => 200, 'dl' => 200, 'weblog0' => true));

    assertTrue(backupSingle($id, 'Quiet', $addr, 'admin', '', 0),
        'a device that will not identify itself was backed up anyway');
    assertCount(0, dbBackupList($id),
        'an unidentified backup was filed');
});

test('the numbered status blocks really are served separately',
function () use ($addr) {
    // Guards the fixture itself: if /cm ignored cmnd and always
    // returned all of Status 0, the fallback tests above would pass
    // for the wrong reason.
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '13'));
    $s2 = getTasmotaStatus2($addr, 'admin', '');
    assertTrue(isset($s2['StatusFWR']), 'status2 lost its own block');
    assertTrue(!isset($s2['StatusNET']),
        'status2 returned StatusNET too, so the stub ignores cmnd');
});

// ---- 37: /dl answering with the root page at 200 ----------------------

test('an html page from /dl is not stored as a config', function () use (
    $addr) {
    // WebServer 1 (user mode) renders the main page instead of
    // refusing, at HTTP 200.
    tb_reset_devices();
    dbDeviceAdd('UserMode', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '13',
        'webserver_user' => true));

    assertTrue(backupSingle($id, 'UserMode', $addr, 'admin', '', 0),
        'an html page was accepted as a config dump');
    assertCount(0, dbBackupList($id));
});

test('a real binary dump is still accepted', function () use ($addr) {
    tb_reset_devices();
    dbDeviceAdd('Good', $addr, '13.4.0', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '13'));
    assertFalse(backupSingle($id, 'Good', $addr, 'admin', '', 0));
    assertCount(1, dbBackupList($id));
});

// ---- 38: /u2 reporting failure in the body at 200 ---------------------

test('a rejected upload is not reported as a successful restore',
function () use ($addr) {
    // HandleUploadDone always answers 200; the CRC, crc32 and
    // config_version chip-family rejections only show in the body.
    tb_stub_set(array('status' => 200, 'u2' => 200, 'fw' => '13',
        'u2_reject' => true));
    $dmp = TB_TMP.'/data/backups/legacy-restore.dmp';
    file_put_contents($dmp, str_repeat("\x01\x02", 512));
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $dmp, 0),
        'a device that said "Upload Failed" was reported as restored');
});

test('an accepted upload still reports success', function () use ($addr) {
    tb_stub_set(array('status' => 200, 'u2' => 200, 'fw' => '13'));
    $dmp = TB_TMP.'/data/backups/legacy-restore-ok.dmp';
    file_put_contents($dmp, str_repeat("\x01\x02", 512));
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $dmp, 0));
});

// ---- 39: bare inf, and nan or inf inside an array ---------------------

test('bare inf is repaired, not just nan', function () {
    // dtostrfd passed both straight through until v6.3.0 replaced them
    // with null.
    $d = jsonTasmotaDecode('STATUS = {"StatusSNS":{"Temp":inf}}');
    assertSame('Inf', $d['StatusSNS']['Temp']);
});

test('negative inf is repaired', function () {
    $d = jsonTasmotaDecode('STATUS = {"StatusSNS":{"Temp":-inf}}');
    assertSame('-Inf', $d['StatusSNS']['Temp']);
});

test('nan inside an array is repaired', function () {
    $d = jsonTasmotaDecode('STATUS = {"StatusSNS":{"T":[nan,1]}}');
    assertSame('NaN', $d['StatusSNS']['T'][0]);
});

test('a plain nan still decodes the way it always did', function () {
    $d = jsonTasmotaDecode('STATUS = {"StatusSNS":{"Temp":nan}}');
    assertSame('NaN', $d['StatusSNS']['Temp']);
});

// ---- 40: the IPaddress spelling of v5.5.1 to v5.6.1 -------------------

test('the lowercase-a IPaddress spelling is found', function () use (
    $addr) {
    $status = tb_legacy_status('5.6.1');
    assertTrue(!isset($status['StatusNET']['IPAddress']),
        'the fixture is wrong, 5.6.1 must spell it IPaddress');
    assertSame('192.168.1.25',
        tbStatusValue($status['StatusNET'], array('IPAddress','IP')));
});

test('the oldest IP spelling is found', function () use ($addr) {
    $status = tb_legacy_status('5.0');
    assertSame('192.168.1.25',
        tbStatusValue($status['StatusNET'], array('IPAddress','IP')));
});

test('the modern IPAddress spelling is found', function () use ($addr) {
    $status = tb_legacy_status('13');
    assertSame('192.168.1.25',
        tbStatusValue($status['StatusNET'], array('IPAddress','IP')));
});

test('mqtt discovery accepts a v5.5.1-v5.6.1 IPaddress reply',
function () {
    require_once(TB_TMP.'/lib/mqtt.inc.php');
    // The reply shape a 5.6.1 device sends to cmnd/tasmotas/STATUS 5.
    $found = array('status5' =>
        '{"StatusNET":{"Host":"kitchen","IPaddress":"192.168.1.20",'.
        '"Mac":"AA:BB:CC:DD:EE:FF"}}');
    $status = array();
    $tmp = mqttDeviceIdentity($found, 'kitchen', $status);
    assertSame('192.168.1.20', $tmp['ip'],
        'the device would have been dropped as having no ip address');
    assertSame('AA:BB:CC:DD:EE:FF', $tmp['mac']);
});

test('mqtt discovery still accepts the modern IPAddress reply',
function () {
    require_once(TB_TMP.'/lib/mqtt.inc.php');
    $found = array('status5' =>
        '{"StatusNET":{"Hostname":"kitchen","IPAddress":"192.168.1.21",'.
        '"Mac":"AA:BB:CC:DD:EE:F0"}}');
    $status = array();
    $tmp = mqttDeviceIdentity($found, 'kitchen', $status);
    assertSame('192.168.1.21', $tmp['ip']);
});

test('mqtt discovery accepts the oldest IP reply', function () {
    require_once(TB_TMP.'/lib/mqtt.inc.php');
    $found = array('status5' =>
        '{"StatusNET":{"Host":"kitchen","IP":"192.168.1.22"}}');
    $status = array();
    $tmp = mqttDeviceIdentity($found, 'kitchen', $status);
    assertSame('192.168.1.22', $tmp['ip']);
});

test('an mqtt reply with no ip at all is still rejected', function () {
    require_once(TB_TMP.'/lib/mqtt.inc.php');
    $found = array('status5' => '{"StatusNET":{"Host":"kitchen"}}');
    $status = array();
    $tmp = mqttDeviceIdentity($found, 'kitchen', $status);
    assertTrue(!isset($tmp['ip']),
        'a device with no ip must not be treated as reachable');
});

// ---- the plaintext STATUS transcript era -------------------------------

test('a plaintext status transcript parses into every block',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '5.10',
        'plaintext' => true));
    $status = getTasmotaStatus($addr, 'admin', '', 0);
    assertTrue(isset($status['Status']), 'Status block missing');
    assertTrue(isset($status['StatusFWR']), 'StatusFWR block missing');
    assertTrue(isset($status['StatusNET']), 'StatusNET block missing');
    assertSame('5.10.0', tbStatusValue($status['StatusFWR'],
        array('Version','Program','Versie','Wersja')));
});

test('a 3.9.13 device, the oldest supported, backs up end to end',
function () use ($addr) {
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '3.9.13',
        'plaintext' => true, 'nomarker' => true));
    dbDeviceAdd('Ancient', $addr, '', '', 'AA:BB:CC:DD:EE:FF');
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:FF');
    assertFalse(backupSingle($id, 'Ancient', $addr, 'admin', '', 0),
        'the oldest supported firmware could not be backed up');
    $b = dbBackupList($id)[0];
    assertSame('3.9.13', $b['version']);
    assertSame('AA:BB:CC:DD:EE:FF', strtoupper(dbDeviceId($id)['mac']));
});

tb_test_exit();
