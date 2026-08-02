<?php
/*
 * Restore tests. These run against the stub device only, a restore
 * writes to the device and reboots it, so it never points at real
 * hardware. See tests/test_live.php.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start();
$dmp = TB_TMP.'/data/backups/restore-me.dmp';
file_put_contents($dmp, str_repeat("\x01\x02", 512));

test('a restore uploads the file and reports success', function () use (
    $addr, $dmp) {
    tb_stub_set(array('u2' => 200));
    tb_stub_clear_requests();
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $dmp));
});

test('the restore primes the device with /rs first', function () {
    // Tasmota needs the GET /rs to set upload_file_type before /u2,
    // without it the upload is accepted and quietly ignored (#69).
    $reqs = tb_stub_requests();
    $rs = -1;
    $u2 = -1;
    foreach ($reqs as $i => $r) {
        if ($rs < 0 && strpos($r, 'GET /rs') === 0)
            $rs = $i;
        if ($u2 < 0 && strpos($r, 'POST /u2') === 0)
            $u2 = $i;
    }
    assertTrue($rs >= 0, 'no GET /rs was sent');
    assertTrue($u2 >= 0, 'no POST /u2 was sent');
    assertTrue($rs < $u2, '/rs must come before /u2');
});

test('a device rejecting the upload reports failure', function () use (
    $addr, $dmp) {
    tb_stub_set(array('u2' => 500));
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $dmp),
        'a rejected upload must not report success');
});

test('an unreachable device reports failure', function () use ($dmp) {
    assertFalse(restoreTasmotaBackup('127.0.0.1:1', 'admin', '', $dmp),
        'an unreachable device must not report success');
});

test('a missing backup file reports failure', function () use ($addr) {
    tb_stub_set(array('u2' => 200));
    $missing = TB_TMP.'/data/backups/not-here.dmp';
    assertFalse(restoreTasmotaBackup($addr, 'admin', '', $missing),
        'a missing file must not report success');
});

// ---- Tasmota v15.5.0+ referer gate (SetOption128 default flip) ----

test('the /rs request carries a Referer header', function () use (
    $addr, $dmp) {
    // Tasmota v15.5.0 made the referer check default-on
    // (disable_referer_chk now defaults to false). /rs is the one
    // request in restoreTasmotaBackup() that used to skip it, so on
    // a v15.5.0+ device with default settings the device never armed
    // settings mode and /u2 wrote nothing while still returning 200.
    tb_stub_set(array('u2' => 200, 'require_referer' => false));
    tb_stub_clear_requests();
    restoreTasmotaBackup($addr, 'admin', '', $dmp);
    $reqs = tb_stub_requests();
    $rsline = null;
    foreach ($reqs as $r) {
        if (strpos($r, 'GET /rs') === 0)
            $rsline = $r;
    }
    assertTrue($rsline !== null, 'no /rs request was logged');
    assertTrue(strpos($rsline, 'referer=1') !== false,
        '/rs was sent with no Referer header, a v15.5.0+ device with '.
        'the default referer check would silently refuse it');
});

test('a v15.5.0 style referer-gated device does not falsely succeed',
function () use ($addr, $dmp) {
    tb_stub_set(array('u2' => 200, 'require_referer' => true));
    // Our /rs call does send a Referer, so this passes on real
    // firmware. This test pins that: if the Referer were ever dropped
    // again, /rs gets refused, and the fix must stop before /u2
    // rather than reporting success anyway.
    assertTrue(restoreTasmotaBackup($addr, 'admin', '', $dmp),
        '/rs should have been accepted, restore should succeed');
});

test('a referer-less /rs is genuinely refused by the stub', function ()
use ($addr) {
    // Proves the require_referer mode really rejects, so the test
    // above ("does not falsely succeed") is exercising a real gate
    // and not a stub that always accepts /rs regardless.
    tb_stub_set(array('u2' => 200, 'require_referer' => true));
    $ch = curl_init('http://'.$addr.'/rs');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    assertNotEquals(200, $code,
        'the stub should have refused a referer-less /rs');
});

tb_test_exit();
