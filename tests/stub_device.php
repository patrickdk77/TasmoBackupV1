<?php
/*
 * Starts and controls the fake device in tests/stub_server.php.
 *
 * tb_stub_start() returns the host:port to store in a device row, the
 * app builds its urls straight off that field so a port works fine.
 */

$GLOBALS['tb_stub'] = null;

function tb_stub_conf_file()
{
    return TB_TMP.'/stub.json';
}

function tb_stub_set($conf)
{
    file_put_contents(tb_stub_conf_file(), json_encode($conf));
}

function tb_stub_requests()
{
    $log = TB_TMP.'/stub.log';
    if (!file_exists($log))
        return array();
    return array_values(array_filter(
        explode("\n", file_get_contents($log))));
}

function tb_stub_clear_requests()
{
    @unlink(TB_TMP.'/stub.log');
}

/*
 * The raw body of the most recent POST the stub received, decoded. For
 * checking exactly what a restore sent (roles/channels/deviceCommand
 * for OpenBeken), not just that a request happened.
 */
function tb_stub_last_post_body()
{
    $log = TB_TMP.'/stub.postlog';
    if (!file_exists($log))
        return null;
    $lines = array_values(array_filter(
        explode("\n", file_get_contents($log))));
    if (!$lines)
        return null;
    return base64_decode(end($lines));
}

function tb_stub_clear_post_bodies()
{
    @unlink(TB_TMP.'/stub.postlog');
}

/*
 * Files uploaded to the stub's /ufsu, as name => contents. Lets a test
 * check exactly which berry scripts a restore pushed and with what
 * body, not merely that a request happened.
 */
function tb_stub_uploaded_files()
{
    $log = TB_TMP.'/stub.uploadlog';
    if (!file_exists($log))
        return array();
    $out = array();
    foreach (explode("\n", file_get_contents($log)) as $line) {
        if (trim($line) === '')
            continue;
        $parts = explode(' ', $line, 2);
        $out[$parts[0]] = isset($parts[1]) ? base64_decode($parts[1]) : '';
    }
    return $out;
}

function tb_stub_clear_uploaded_files()
{
    @unlink(TB_TMP.'/stub.uploadlog');
}

function tb_stub_start($conf = array())
{
    if ($GLOBALS['tb_stub'] !== null)
        return $GLOBALS['tb_stub']['addr'];

    tb_stub_set($conf);
    $php = PHP_BINARY;
    $env = array(
        'TB_STUB_CONF' => tb_stub_conf_file(),
        'TB_STUB_LOG' => TB_TMP.'/stub.log',
        'TB_STUB_POSTLOG' => TB_TMP.'/stub.postlog',
        'TB_STUB_UPLOADLOG' => TB_TMP.'/stub.uploadlog',
        'PATH' => getenv('PATH'),
    );

    for ($try = 0; $try < 20; $try++) {
        $port = mt_rand(20000, 60000);
        $cmd = escapeshellarg($php).' -S 127.0.0.1:'.$port.' '.
            escapeshellarg(__DIR__.'/stub_server.php');
        $pipes = array();
        $proc = proc_open($cmd,
            array(1 => array('file', '/dev/null', 'w'),
                  2 => array('file', TB_TMP.'/stub.err', 'a')),
            $pipes, TB_TMP, $env);
        if (!is_resource($proc))
            continue;

        for ($i = 0; $i < 100; $i++) {
            usleep(50000);
            $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($s) {
                fclose($s);
                $GLOBALS['tb_stub'] = array(
                    'proc' => $proc, 'addr' => '127.0.0.1:'.$port);
                register_shutdown_function('tb_stub_stop');
                return $GLOBALS['tb_stub']['addr'];
            }
            $st = proc_get_status($proc);
            if (!$st['running'])
                break; // port taken, try another
        }
        proc_terminate($proc);
        proc_close($proc);
    }
    throw new Exception('could not start the stub device server');
}

function tb_stub_stop()
{
    if ($GLOBALS['tb_stub'] === null)
        return;
    proc_terminate($GLOBALS['tb_stub']['proc']);
    proc_close($GLOBALS['tb_stub']['proc']);
    $GLOBALS['tb_stub'] = null;
}
