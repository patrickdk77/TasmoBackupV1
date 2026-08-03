<?php
/*
 * Debug logging (issue #87).
 *
 * Two things matter here: that it is genuinely silent when off, and
 * that it never leaks a device password. This app puts credentials
 * into request urls, and debug output is exactly the thing people
 * paste into bug reports.
 */

require_once(__DIR__.'/bootstrap.php');

/*
 * Runs $fn with error_log redirected to a file, returns what was
 * logged. error_log's destination is an ini setting, so this captures
 * the real code path rather than a stubbed logger.
 */
function tb_capture_log($fn)
{
    $logfile = TB_TMP.'/captured.log';
    @unlink($logfile);
    $prev = ini_get('error_log');
    ini_set('error_log', $logfile);
    try {
        $fn();
    } catch (Throwable $e) {
        ini_set('error_log', $prev);
        throw $e;
    }
    ini_set('error_log', $prev);
    return file_exists($logfile) ? file_get_contents($logfile) : '';
}

// ---- the switch ----------------------------------------------------

test('nothing is logged when the debug setting is off', function () {
    global $settings;
    $settings['debug'] = 'N';
    $out = tb_capture_log(function () {
        tbDebug('test', 'this must not appear');
        tbDebugHttp('test', 'http://1.2.3.4/cm', 200, 0);
    });
    assertSame('', $out, 'debug output leaked while switched off');
});

test('nothing is logged when the debug setting was never saved',
function () {
    global $settings;
    unset($settings['debug']);
    $out = tb_capture_log(function () {
        tbDebug('test', 'this must not appear either');
    });
    assertSame('', $out, 'debug defaulted to on');
});

test('lines are logged when the debug setting is on', function () {
    global $settings;
    $settings['debug'] = 'Y';
    $out = tb_capture_log(function () {
        tbDebug('scan', 'hello from the scan');
    });
    assertTrue(strpos($out, 'hello from the scan') !== false,
        'the message was not logged: '.$out);
    assertTrue(strpos($out, 'TasmoBackup [scan]') !== false,
        'the area prefix is missing: '.$out);
});

test('the http helper reports status and curl errno', function () {
    global $settings;
    $settings['debug'] = 'Y';
    $out = tb_capture_log(function () {
        tbDebugHttp('backup', 'http://1.2.3.4/dl', 500, 7, 'extra=1');
    });
    assertTrue(strpos($out, 'http 500') !== false, $out);
    assertTrue(strpos($out, 'curl_errno=7') !== false, $out);
    assertTrue(strpos($out, 'extra=1') !== false, $out);
});

// ---- redaction, the part that must never regress -------------------

test('a password in the url userinfo is redacted', function () {
    assertSame('http://admin:***@1.2.3.4/cm?cmnd=status%200',
        tbRedact('http://admin:hunter2@1.2.3.4/cm?cmnd=status%200'));
});

test('a password in the query string is redacted', function () {
    assertSame('http://1.2.3.4/cm?cmnd=x&user=admin&password=***',
        tbRedact('http://1.2.3.4/cm?cmnd=x&user=admin&password=hunter2'));
});

test('both password positions are redacted at once', function () {
    $raw = 'http://admin:hunter2@1.2.3.4/cm?cmnd=x&password=hunter2&z=1';
    $out = tbRedact($raw);
    assertTrue(strpos($out, 'hunter2') === false,
        'the password survived redaction: '.$out);
    assertTrue(strpos($out, 'z=1') !== false,
        'redaction ate the rest of the query string: '.$out);
});

test('redaction leaves a credential-free url untouched', function () {
    $clean = 'http://1.2.3.4/cm?cmnd=status%200';
    assertSame($clean, tbRedact($clean));
});

test('a real logged request never contains the password', function () {
    // The end to end property: not just that tbRedact works, but that
    // the logging path actually applies it.
    global $settings;
    $settings['debug'] = 'Y';
    $out = tb_capture_log(function () {
        tbDebugHttp('status',
            'http://admin:sup3rs3cret@10.0.0.5/cm?cmnd=status%200'.
            '&user=admin&password=sup3rs3cret', 200, 0);
    });
    assertTrue(strpos($out, 'sup3rs3cret') === false,
        'the device password reached the log: '.$out);
    assertTrue(strpos($out, '10.0.0.5') !== false,
        'redaction should keep the useful parts: '.$out);
});

// ---- it actually fires from the real code paths --------------------

test('a real http call through the app logs a line', function () {
    global $settings;
    $settings['debug'] = 'Y';
    $out = tb_capture_log(function () {
        // Unreachable on purpose: the point is that the attempt is
        // logged, which is what someone diagnosing a silent failure
        // needs to see.
        getTasmotaStatus('127.0.0.1:1', 'admin', 'topsecret', 0);
    });
    assertTrue(strpos($out, 'TasmoBackup [status]') !== false,
        'the status call logged nothing: '.$out);
    assertTrue(strpos($out, 'topsecret') === false,
        'the password reached the log: '.$out);
});

test('a scan logs its verdict for an address', function () {
    global $settings;
    $settings['debug'] = 'Y';
    $out = tb_capture_log(function () {
        getTasmotaScan('127.0.0.1:1', 'admin', '');
    });
    assertTrue(strpos($out, 'TasmoBackup [scan]') !== false,
        'the scan logged nothing: '.$out);
});

test('a locked backup refusal is logged', function () {
    global $settings;
    $settings['debug'] = 'Y';
    tb_reset_devices();
    dbDeviceAdd('DebugLock', '10.20.20.1', '1', '', 'AA:BB:CC:DD:EE:F5');
    $devid = dbDeviceFind(null, 'AA:BB:CC:DD:EE:F5');
    $f = TB_TMP.'/data/backups/debuglock.dmp';
    file_put_contents($f, 'x');
    dbNewBackup($devid, 'DebugLock', '1', date('Y-m-d H:i:s'), 1, $f);
    $bid = dbBackupList($devid)[0]['id'];
    dbBackupSetLocked($bid, true);

    $out = tb_capture_log(function () use ($bid) {
        dbBackupDel($bid);
    });
    assertTrue(strpos($out, 'it is locked') !== false,
        'the locked refusal was not logged: '.$out);
});

tb_test_exit();
