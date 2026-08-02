<?php
/*
 * Firmware compatibility.
 *
 * The user has no devices running v13, v14 or v15, so these drive the
 * stub with Status 0 shaped the way each of those majors actually
 * emits it. The shapes were taken from the response templates compiled
 * into the released binaries on ota.tasmota.com, not from memory, see
 * tests/stub_server.php.
 *
 * What this pins down: every field the app reads keeps its name and
 * nesting across majors, and discovery plus backup work end to end
 * against all of them.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start(array('status' => 200, 'dl' => 200, 'fw' => '13'));

$VERSIONS = array(
    '13' => '13.4.0(tasmota)',
    '14' => '14.6.0(tasmota)',
    '15' => '15.5.0(tasmota)',
);

foreach ($VERSIONS as $fw => $expect) {
    test('v'.$fw.': discovery reads name, mac, hostname and version',
    function () use ($addr, $fw, $expect) {
        tb_reset_devices();
        tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => $fw));

        $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
        assertCount(1, dbDevices(), 'device not added: '.$msg);

        $d = dbDevices()[0];
        assertSame('Kitchen Light', $d['name'], 'name misread');
        assertSame('AA:BB:CC:DD:EE:FF', $d['mac'], 'mac misread');
        assertSame('kitchen-1234', $d['hostname'], 'hostname misread');
        assertSame($expect, $d['version'], 'version misread');
    });

    test('v'.$fw.': a backup downloads and is stored', function () use (
        $addr, $fw, $expect) {
        $id = dbDevices()[0]['id'];
        assertFalse(backupSingle($id, 'Kitchen Light', $addr, 'admin',
            '', 0), 'backup reported failure on v'.$fw);
        assertSame('1', (string)dbBackupCount($id));
        $list = dbBackupList($id);
        assertSame($expect, $list[0]['version'],
            'the stored backup recorded the wrong version');
        assertFileExists($list[0]['filename']);
    });
}

test('an esp32 build with an Ethernet block still parses', function ()
use ($addr) {
    // v15 on esp32 nests a second Hostname and Mac under
    // StatusNET.Ethernet. The wifi ones must still win.
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => 'esp32'));
    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'device not added: '.$msg);
    $d = dbDevices()[0];
    assertSame('AA:BB:CC:DD:EE:FF', $d['mac'],
        'the ethernet mac was picked up instead of the wifi one');
    assertSame('kitchen-1234', $d['hostname']);
});

test('the same device seen on two firmware majors stays one row',
function () use ($addr) {
    // A device upgraded from v13 to v15 keeps its mac, so it must
    // update in place rather than appear twice.
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '13'));
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices());

    tb_stub_set(array('status' => 200, 'dl' => 200, 'fw' => '15'));
    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'a firmware upgrade duplicated the '.
        'device: '.$msg);
    assertSame('15.5.0(tasmota)', dbDevices()[0]['version'],
        'the version was not refreshed after the upgrade');
});

test('a restore arms settings mode before uploading', function () use (
    $addr) {
    // Tasmota holds a single "what is the next upload" flag per
    // device. GET /rs sets it to UPL_SETTINGS and GET /up sets it to
    // UPL_TASMOTA, in v13, v14 and v15 alike
    // (xdrv_01_9_webserver.ino, end of HandleRestoreConfiguration).
    // Posting to /u2 without the /rs first is what made restore
    // silently do nothing in issue #69, and on a device whose flag was
    // left on firmware it would be far worse than nothing.
    $dmp = TB_TMP.'/data/backups/v-restore.dmp';
    file_put_contents($dmp, str_repeat("\x01\x02", 512));
    tb_stub_set(array('u2' => 200));
    tb_stub_clear_requests();

    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $dmp));

    $reqs = tb_stub_requests();
    $rs = -1;
    $u2 = -1;
    foreach ($reqs as $i => $r) {
        if ($rs < 0 && strpos($r, 'GET /rs') === 0) $rs = $i;
        if ($u2 < 0 && strpos($r, 'POST /u2') === 0) $u2 = $i;
    }
    assertTrue($rs >= 0, 'no GET /rs, the upload would be ignored or '.
        'treated as firmware');
    assertTrue($rs < $u2, '/rs must be armed before /u2');
});

test('the upload goes to bare /u2, no fsz needed', function () {
    // The device form posts to u2?fsz=<size>, but the handler reads
    // fsz only for firmware images (the 0xE9 branch) and defaults it
    // to 0 when absent, so a settings restore does not need it.
    $reqs = tb_stub_requests();
    $found = false;
    foreach ($reqs as $r) {
        if (strpos($r, 'POST /u2') === 0)
            $found = true;
    }
    assertTrue($found, 'the upload did not go to /u2');
});

tb_test_exit();
