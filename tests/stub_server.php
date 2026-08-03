<?php
/*
 * Fake Tasmota / WLED device, run by tests through `php -S`.
 *
 * Per request it reads the json file named by TB_STUB_CONF, so a test
 * can change how the device behaves without restarting the server:
 *
 *   status       http status for /cm?cmnd=status 0 and /json
 *   dl           http status for /dl, /cfg.json, /presets.json
 *   u2           http status for the /u2 restore upload
 *   body         optional replacement body for the status response
 *   require_referer  when true, /rs refuses a request with no Referer
 *                header, modelling Tasmota v15.5.0+ default behaviour
 *                (SetOption128 / disable_referer_chk defaults off)
 *   kind         'wled' or 'openbeken' switches / and /json away from
 *                the Tasmota defaults
 *   ufs          when false, /ufsd and /ufsu answer 404, modelling a
 *                build with no USE_UFILESYS (any esp8266 device)
 *   ufs_files    path => contents for the /ufsd listing and downloads
 *   ufsu_status  http status for the /ufsu berry script upload
 *   obk_info_status / obk_pins_status  http status for /api/info and
 *                GET+POST /api/pins (default 200)
 *   obk_mac / obk_shortname / obk_build / obk_startcmd / obk_roles /
 *   obk_channels   override the corresponding /api/info or /api/pins
 *                field
 */

$conf = array('status' => 200, 'dl' => 200, 'u2' => 200,
    'require_referer' => false, 'obk_info_status' => 200,
    'obk_pins_status' => 200);
$f = getenv('TB_STUB_CONF');
if ($f && is_readable($f)) {
    $j = json_decode(file_get_contents($f), true);
    if (is_array($j))
        $conf = array_merge($conf, $j);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('TB_STUB_LOG');
if ($log)
    file_put_contents($log, $_SERVER['REQUEST_METHOD'].' '.
        $_SERVER['REQUEST_URI'].
        (empty($_SERVER['HTTP_REFERER']) ? ' referer=0' : ' referer=1').
        "\n", FILE_APPEND);

// Raw request bodies, one base64 line per POST, so a test can check
// exactly what a restore sent without worrying about embedded
// newlines. tb_stub_last_post_body() in stub_device.php reads this.
$postLog = getenv('TB_STUB_POSTLOG');
if ($postLog && $_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($postLog,
        base64_encode(file_get_contents('php://input'))."\n",
        FILE_APPEND);
}

function tb_stub_fail($code)
{
    http_response_code($code);
    header('Content-Type: text/plain');
    echo "stub failure\n";
}

/*
 * Status 0 shaped the way each firmware major actually emits it. The
 * field names and nesting come from the response templates compiled
 * into the released binaries, checked with:
 *   strings tasmota.bin | grep '{"StatusNET":{'
 * against ota.tasmota.com release-13.4.0, release-14.6.0 and
 * release-15.5.0.
 *
 * The differences between majors are real: v13 emits Power as a
 * number, v14 changed it to a string bitmask and added PowerLock, and
 * v15 renders Core with dots instead of underscores. None of them
 * touch a field this app reads, which is the point of testing all
 * three.
 */
function tb_stub_status($fw)
{
    $status = array(
        'Status' => array(
            'Module' => 1,
            'DeviceName' => 'Kitchen Light',
            'FriendlyName' => array('Kitchen Light'),
            'Topic' => 'kitchen',
            'ButtonTopic' => '0',
            'Power' => 1,
            'PowerOnState' => 3,
            'LedState' => 1,
            'SaveData' => 1,
            'SaveState' => 1,
        ),
        'StatusFWR' => array(
            'Version' => '13.4.0(tasmota)',
            'BuildDateTime' => '2024-02-05T10:00:00',
            'Boot' => 7,
            'Core' => '2_7_6',
            'SDK' => '2.2.2-dev(38a443e)',
            'CpuFrequency' => 80,
            'Hardware' => 'ESP8266EX',
        ),
        'StatusLOG' => array('SerialLog' => 2, 'WebLog' => 2),
        'StatusMEM' => array('ProgramSize' => 616, 'Free' => 384,
            'Heap' => 26, 'ProgramFlashSize' => 1024),
        'StatusNET' => array(
            'Hostname' => 'kitchen-1234',
            'IPAddress' => '192.168.1.25',
            'Gateway' => '192.168.1.1',
            'Subnetmask' => '255.255.255.0',
            'DNSServer1' => '192.168.1.1',
            'DNSServer2' => '0.0.0.0',
            'Mac' => 'AA:BB:CC:DD:EE:FF',
            'Webserver' => 2,
            'HTTP_API' => 1,
            'WifiConfig' => 4,
            'WifiPower' => 17.0,
        ),
        'StatusTIM' => array('UTC' => '2026-08-02T12:00:00',
            'Local' => '2026-08-02T12:00:00'),
    );

    if ($fw === '14' || $fw === '15') {
        // v14 turned Power into a string bitmask and added PowerLock
        $status['Status']['Power'] = '1';
        $status['Status']['PowerLock'] = '0';
        $status['StatusFWR']['Version'] = '14.6.0(tasmota)';
        $status['StatusFWR']['Core'] = '2_7_8';
    }
    if ($fw === '15') {
        $status['StatusFWR']['Version'] = '15.5.0(tasmota)';
        $status['StatusFWR']['Core'] = '2.7.8';
        unset($status['StatusMEM']['ProgramFlashSize']);
    }
    if ($fw === 'esp32') {
        $status['StatusFWR']['Version'] = '15.5.0(tasmota)';
        $status['StatusFWR']['Hardware'] = 'ESP32-D0WDQ6';
        $status['StatusNET']['Ethernet'] = array(
            'Hostname' => 'kitchen-eth', 'IPAddress' => '0.0.0.0',
            'Mac' => 'AA:BB:CC:DD:EE:F0');
    }
    return $status;
}

$status = tb_stub_status(isset($conf['fw']) ? (string)$conf['fw'] : '13');

$wled = array(
    'info' => array(
        'ver' => '0.14.0',
        'name' => 'WLED Strip',
        'mac' => 'a1b2c3d4e5f6',
    ),
);

if (!empty($conf['nomac']))
    unset($status['StatusNET']['Mac']);
if (!empty($conf['nohostname']))
    unset($status['StatusNET']['Hostname']);
if (isset($conf['mac']))
    $status['StatusNET']['Mac'] = $conf['mac'];
if (isset($conf['hostname']))
    $status['StatusNET']['Hostname'] = $conf['hostname'];

$obkKind = isset($conf['kind']) && $conf['kind'] === 'openbeken';

if ($path === '/') {
    if ($obkKind) {
        // Real OpenBeken 302-redirects a bare / to /index rather than
        // answering directly (src/httpserver/new_http.c,
        // http_fn_empty_url), that redirect is the scan signature.
        http_response_code(302);
        header('Location: /index');
        return;
    }
    // what a scan sees, it only looks for the marker word
    if ($conf['status'] != 200)
        return tb_stub_fail($conf['status']);
    header('Content-Type: text/html');
    $kind = isset($conf['kind']) && $conf['kind'] === 'wled'
        ? 'WLED' : 'Tasmota';
    echo '<html><head><title>'.$kind.'</title></head><body>'.
        $kind.'</body></html>';
    return;
}

if ($path === '/index') {
    header('Content-Type: text/html');
    echo '<html><head><title>obk</title></head><body>'.
        '<a href="https://github.com/openshwprojects/OpenBK7231T_App/">obk</a>'.
        '</body></html>';
    return;
}

if ($path === '/cm') {
    if ($conf['status'] != 200)
        return tb_stub_fail($conf['status']);
    header('Content-Type: application/json');
    echo isset($conf['body']) ? $conf['body'] : json_encode($status);
    return;
}

if ($path === '/json') {
    if ($conf['status'] != 200)
        return tb_stub_fail($conf['status']);
    header('Content-Type: application/json');
    echo isset($conf['body']) ? $conf['body'] : json_encode($wled);
    return;
}

if ($path === '/dl') {
    if ($conf['dl'] != 200)
        return tb_stub_fail($conf['dl']);
    header('Content-Type: application/octet-stream');
    echo str_repeat("\x01\x02\x03\x04", 1024); // stand-in config.dmp
    return;
}

if ($path === '/cfg.json' || $path === '/presets.json' ||
    $path === '/edit') {
    if ($conf['dl'] != 200)
        return tb_stub_fail($conf['dl']);
    header('Content-Type: application/json');
    echo '{"stub":"'.trim($path, '/').'"}';
    return;
}

if ($path === '/ufsd') {
    // Tasmota filesystem. A build without USE_UFILESYS has no such
    // route at all, which conf 'ufs' => false models.
    if (empty($conf['ufs']))
        return tb_stub_fail(404);

    $files = isset($conf['ufs_files']) ? $conf['ufs_files']
        : array('/autoexec.be' => "# autoexec\nprint('hi')\n");

    if (isset($_GET['download'])) {
        $want = $_GET['download'];
        if (!isset($files[$want]))
            return tb_stub_fail(404);
        header('Content-Type: application/octet-stream');
        echo $files[$want];
        return;
    }

    // The listing shape comes from UFS_FORM_SDC_DIRb in
    // xdrv_50_filesystem.ino: an anchor per file whose href is
    // ufsd?download=<path>.
    header('Content-Type: text/html');
    echo '<html><body><div>';
    foreach ($files as $p => $content) {
        echo "<pre><a href='ufsd?download=".htmlspecialchars($p).
            "' file='".htmlspecialchars(basename($p))."'>".
            htmlspecialchars(basename($p)).'</a>  2026-01-01 00:00:00  '.
            strlen($content)."</pre>\n";
    }
    echo '</div></body></html>';
    return;
}

if ($path === '/ufsu') {
    if (empty($conf['ufs']))
        return tb_stub_fail(404);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($conf['ufsu_status']) && $conf['ufsu_status'] != 200)
            return tb_stub_fail($conf['ufsu_status']);
        // Record what was uploaded so a test can assert on it.
        $log = getenv('TB_STUB_UPLOADLOG');
        if ($log && isset($_FILES['ufsu'])) {
            file_put_contents($log,
                $_FILES['ufsu']['name'].' '.
                base64_encode(file_get_contents(
                    $_FILES['ufsu']['tmp_name']))."\n",
                FILE_APPEND);
        }
        http_response_code(200);
        echo 'Upload Successful';
        return;
    }
    http_response_code(200);
    echo 'ufs upload form';
    return;
}

if ($path === '/upload') {
    // WLED restore. server.on("/upload", HTTP_POST, ...) with
    // handleUpload keying off the multipart filename, not the field
    // name. Present v0.13.0 through v16.0.1.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        return tb_stub_fail(404);
    if (isset($conf['upload_status']) && $conf['upload_status'] != 200)
        return tb_stub_fail($conf['upload_status']);
    $log = getenv('TB_STUB_UPLOADLOG');
    if ($log && isset($_FILES['data'])) {
        // PHP strips the directory from ['name'], but WLED keys off the
        // full multipart filename and 0.13.x needs the leading slash,
        // so log ['full_path'] (php 8.1+) which preserves it verbatim.
        $sent = isset($_FILES['data']['full_path'])
            ? $_FILES['data']['full_path'] : $_FILES['data']['name'];
        file_put_contents($log,
            $sent.' '.
            base64_encode(file_get_contents(
                $_FILES['data']['tmp_name']))."\n",
            FILE_APPEND);
    }
    http_response_code(200);
    // Text the firmware actually returns for a cfg.json upload.
    if (isset($_FILES['data']) &&
            strpos($_FILES['data']['name'], 'cfg.json') !== false)
        echo "Configuration restore successful.\nRebooting...";
    else
        echo 'File Uploaded!';
    return;
}

if ($path === '/reset') {
    http_response_code(200);
    echo 'Rebooting now...';
    return;
}

if ($path === '/api/info') {
    // Field names and shape come from
    // src/httpserver/rest_interface.c:http_rest_get_info(), read
    // directly from the openshwprojects/OpenBK7231T_App source.
    if ($conf['obk_info_status'] != 200)
        return tb_stub_fail($conf['obk_info_status']);
    header('Content-Type: application/json');
    echo json_encode(array(
        'uptime_s' => 3600,
        'build' => isset($conf['obk_build']) ? $conf['obk_build']
            : 'OpenBK7231T_1234567_06_02_2026',
        'ip' => '10.10.10.10',
        'mac' => isset($conf['obk_mac']) ? $conf['obk_mac']
            : 'aa:bb:cc:dd:ee:02',
        'flags' => '0',
        'mqtthost' => '0.0.0.0:1883',
        'mqtttopic' => 'obk-kitchen',
        'chipset' => 'BK7231T',
        'webapp' => 'lfs',
        'shortName' => isset($conf['obk_shortname'])
            ? $conf['obk_shortname'] : 'obk-kitchen',
        'startcmd' => isset($conf['obk_startcmd']) ? $conf['obk_startcmd']
            : 'AddChannel 1 0\nSetGPIODirection 6 1',
        'supportsSSDP' => 0,
        'supportsClientDeviceDB' => true,
    ));
    return;
}

if ($path === '/api/pins') {
    if ($conf['obk_pins_status'] != 200)
        return tb_stub_fail($conf['obk_pins_status']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Real device replies 200 with {"success":200,"msg":"OK"},
        // http_rest_error() sets the real HTTP status code, not just
        // a body flag (rest_interface.c:http_rest_error()).
        header('Content-Type: application/json');
        echo '{"success":200, "msg":"OK"}';
        return;
    }
    // src/httpserver/rest_interface.c:http_rest_get_pins()
    header('Content-Type: application/json');
    echo json_encode(array(
        'rolenames' => array('None', 'Relay', 'Button'),
        'roles' => isset($conf['obk_roles']) ? $conf['obk_roles']
            : array(0, 0, 0, 0, 0, 0, 1, 2),
        'channels' => isset($conf['obk_channels']) ? $conf['obk_channels']
            : array(0, 0, 0, 0, 0, 0, 1, 0),
        'states' => array(0, 0, 0, 0, 0, 0, 1, 0),
    ));
    return;
}

if ($path === '/rs') {
    if (!empty($conf['require_referer']) &&
            empty($_SERVER['HTTP_REFERER'])) {
        // What a real device sends back for a referer-denied request
        // (a bare `return;` from HttpCheckPriviledgedAccess with no
        // response ever queued) isn't pinned down from source alone,
        // the underlying ESP web server core isn't vendored in the
        // Tasmota repo. Modelled here as an unambiguous rejection
        // rather than guessing a byte-exact wire format, since the
        // client-side fix has to treat "did not get an ok" as failure
        // however that shows up.
        return tb_stub_fail(403);
    }
    http_response_code(200);
    echo 'ok';
    return;
}

if ($path === '/u2') {
    if ($conf['u2'] != 200)
        return tb_stub_fail($conf['u2']);
    http_response_code(200);
    echo 'Upload Successful';
    return;
}

tb_stub_fail(404);
