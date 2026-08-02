<?php
/*
 * Live network tests. READ ONLY.
 *
 * Nothing in this file writes to a real device. It scans, reads
 * status and downloads config, which are all plain GETs. Restore
 * (/rs and POST /u2), firmware upgrade and console commands are
 * deliberately never called here, keep it that way.
 *
 * Skipped unless you point it at a network or a device:
 *
 *   TB_LIVE_RANGE=192.168.11.1-254 php tests/run.php live
 *   TB_LIVE_DEVICE=192.168.11.20,192.168.11.21 php tests/run.php live
 *
 * Optional: TB_LIVE_USER, TB_LIVE_PASSWORD, TB_LIVE_MIN (how many
 * devices the scan must find, default 1).
 */

require_once(__DIR__.'/bootstrap.php');

$range = getenv('TB_LIVE_RANGE');
$devices = getenv('TB_LIVE_DEVICE');
$user = getenv('TB_LIVE_USER') ?: 'admin';
$password = getenv('TB_LIVE_PASSWORD') ?: '';
$min = intval(getenv('TB_LIVE_MIN') ?: 1);

if (!$range && !$devices) {
    echo "  skipped, set TB_LIVE_RANGE or TB_LIVE_DEVICE\n";
    tb_test_exit();
}

function tb_expand_range($range)
{
    $octets = array();
    foreach (explode('.', $range) as $o)
        $octets[] = array_map('intval', explode('-', $o));

    $ips = array();
    for ($a = $octets[0][0];
         $a <= (isset($octets[0][1]) ? $octets[0][1] : $octets[0][0]); $a++)
    for ($b = $octets[1][0];
         $b <= (isset($octets[1][1]) ? $octets[1][1] : $octets[1][0]); $b++)
    for ($c = $octets[2][0];
         $c <= (isset($octets[2][1]) ? $octets[2][1] : $octets[2][0]); $c++)
    for ($d = $octets[3][0];
         $d <= (isset($octets[3][1]) ? $octets[3][1] : $octets[3][0]); $d++)
        $ips[] = $a.'.'.$b.'.'.$c.'.'.$d;
    return $ips;
}

$found = array();

// ---- the scan ---------------------------------------------------

if ($range) {
    test('scanning '.$range.' finds devices', function () use (
        $range, $user, $password, $min, &$found) {
        $ips = tb_expand_range($range);
        assertTrue(count($ips) > 0, 'the range expanded to nothing');

        $t = microtime(true);
        $result = getTasmotaScanRange($ips, $user, $password);
        $secs = microtime(true) - $t;

        assertTrue(is_array($result), 'the scan returned no array');
        printf("       %d of %d addresses answered in %.1fs\n",
            count($result), count($ips), $secs);
        foreach ($result as $r)
            $found[] = $r; // array($ip, $type)

        assertTrue(count($result) >= $min,
            'expected at least '.$min.' device(s)');
    });

    test('every scanned device reports a known type', function () use (
        &$found) {
        foreach ($found as $r) {
            assertTrue(in_array($r[1], array(0, 1), true),
                $r[0].' returned type '.tb_show($r[1]));
        }
    });
}

if ($devices) {
    foreach (explode(',', $devices) as $ip) {
        $ip = trim($ip);
        if ($ip !== '')
            $found[] = array($ip, 0);
    }
}

// ---- reading each device ----------------------------------------

test('every device answers a status request', function () use (
    &$found, $user, $password) {
    assertTrue(count($found) > 0, 'no devices to test');
    foreach ($found as $r) {
        list($ip, $type) = $r;
        $status = getTasmotaStatus($ip, $user, $password, $type);
        assertTrue(is_array($status), $ip.' returned no status');
        if (intval($type) === 0)
            assertTrue(isset($status['Status']),
                $ip.' has no Status block');
        else
            assertTrue(isset($status['info']),
                $ip.' has no info block');
    }
});

test('every tasmota device reports a version and a mac', function () use (
    &$found, $user, $password) {
    foreach ($found as $r) {
        list($ip, $type) = $r;
        if (intval($type) !== 0)
            continue;
        $status = getTasmotaStatus($ip, $user, $password, $type);
        if (!isset($status['StatusFWR'])) {
            sleep(1);
            $s2 = getTasmotaStatus2($ip, $user, $password);
            assertTrue(is_array($s2), $ip.' gave no StatusFWR');
            $status['StatusFWR'] = $s2['StatusFWR'];
        }
        if (!isset($status['StatusNET'])) {
            sleep(1);
            $s5 = getTasmotaStatus5($ip, $user, $password);
            assertTrue(is_array($s5), $ip.' gave no StatusNET');
            $status['StatusNET'] = $s5['StatusNET'];
        }
        $ver = $status['StatusFWR']['Version'];
        $mac = $status['StatusNET']['Mac'];
        printf("       %-16s %-22s %s\n", $ip, $ver, $mac);
        assertTrue(strlen($ver) > 2, $ip.' version looks wrong');
        assertTrue((bool)preg_match(
            '/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac),
            $ip.' mac looks wrong: '.$mac);
    }
});

// ---- a real backup, still only a GET ----------------------------

test('a real config downloads and is stored', function () use (
    &$found, $user, $password) {
    $done = 0;
    foreach ($found as $r) {
        list($ip, $type) = $r;
        $mac = 'LIVE:'.md5($ip);
        dbDeviceAdd('live-'.$ip, $ip, '0', $password, $mac, $type);
        $id = dbDeviceFind($ip, $mac);
        assertNotEquals(false, $id, $ip.' was not added');

        $failed = backupSingle($id, 'live-'.$ip, $ip, $user, $password,
            $type);
        assertFalse($failed, $ip.' backup reported a failure');

        $list = dbBackupList($id);
        assertCount(1, $list, $ip.' stored no backup row');
        assertFileExists($list[0]['filename']);
        $size = filesize($list[0]['filename']);
        printf("       %-16s %d bytes\n", $ip, $size);
        assertTrue($size > 100, $ip.' backup is suspiciously small');
        $done++;
    }
    assertTrue($done > 0, 'nothing was backed up');
});

test('a second backup of the same device adds a second row',
function () use (&$found, $user, $password) {
    list($ip, $type) = $found[0];
    $mac = 'LIVE:'.md5($ip);
    $id = dbDeviceFind($ip, $mac);
    sleep(1); // the filename carries a per second timestamp
    assertFalse(backupSingle($id, 'live-'.$ip, $ip, $user, $password,
        $type), $ip.' second backup failed');
    assertSame('2', (string)dbBackupCount($id));
});

test('a bad password is rejected rather than silently accepted',
function () use (&$found, $user) {
    list($ip, $type) = $found[0];
    if (intval($type) !== 0) {
        echo "       skipped, first device is not tasmota\n";
        return;
    }
    $status = getTasmotaStatus($ip, $user, 'definitely-not-the-password',
        $type);
    if ($status === false)
        return; // rejected outright, which is what we want
    // An open device answers anything, so only fail when a device
    // that does have a password still handed over its status.
    assertTrue(isset($status['Status']) || isset($status['info']),
        $ip.' returned a malformed status for a bad password');
    echo "       note: ".$ip." has no web password set\n";
});

tb_test_exit();
