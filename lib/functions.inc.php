<?php
require_once (__DIR__.'/db.inc.php');
require_once (__DIR__.'/i18n.inc.php');

$strJsonFileContents = file_get_contents(__DIR__.'/../HA_addon/config.json');
$array = json_decode($strJsonFileContents, true);
$GLOBALS['VERSION']=$array['version'];

// Needs the settings, which db.inc.php has just loaded.
tbSetupLocale();

function getBetween($content, $start, $end)
{
    $r = explode($start, $content);
    if (isset($r[1])) {
        $r = explode($end, $r[1]);
        return $r[0];
    }
    return '';
}

function jsonTasmotaDecode($json)
{
    $data=json_decode($json,true);
    if(json_last_error() == JSON_ERROR_CTRL_CHAR) {
        $data=json_decode(preg_replace('/[[:cntrl:]]/','',$json),true);
    }
    if(json_last_error() !== JSON_ERROR_NONE) {
	$string = substr( $json, strpos( $json, "STATUS = " ) );
        if( strpos( $string, "POWER = " ) !== FALSE ) {
            $string = substr( $string, strpos( $string, "{" ) );
            $string = substr( $string, 0, strrpos( $string, "}" )+1 );
        }
        if( strpos( $string, "ERGEBNIS = " ) !== FALSE ) {
            $string = substr( $string, strpos( $string, "{" ) );
            $string = substr( $string, 0, strrpos( $string, "}" )+1 );
        }
        if( strpos( $string, "RESULT = " ) !== FALSE ) {
            $string = substr( $string, strpos( $string, "{" ) );
            $string = substr( $string, 0, strrpos( $string, "}" )+1 );
        }
        $remove  = [ PHP_EOL, "\n", "STATUS = ", "}STATUS1 = {", "}STATUS2 = {",
            "}STATUS3 = {", "}STATUS4 = {", "}STATUS5 = {", "}STATUS6 = {",
            "}STATUS7 = {", "}in = {", "}STATUS8 = {", "}STATUS9 = {", "}STATUS10 = {",
            "}STATUS11 = {", "STATUS2 = ", ":nan,", ":nan}", ];
        $replace = [ "", "", "", ",", ",", ",", ",", ",", ",", ",", ",", ",",
            ",", ",", ",", "", ":\"NaN\",", ":\"NaN\"}", ];
        $string = str_replace( $remove, $replace, $string );
        //remove everything befor ethe first {
        $string = strstr( $string, '{' );
        $data=json_decode($string,true);
        if(json_last_error() !== JSON_ERROR_NONE) {
            $data=array();
        }
    }
    return $data;
}

/*
 * OpenBeken (openshwprojects/OpenBK7231T_App) has no fixed brand
 * string in its page body, the title and h1 are just the user's own
 * device name, same as a renamed Tasmota. But a bare GET / (no query
 * args, exactly what a scan sends) 302-redirects to /index instead of
 * answering directly, unlike Tasmota and WLED which both serve their
 * root page straight at 200. Checked against the actual firmware
 * source (src/httpserver/new_http.c, http_fn_empty_url and the fixed
 * htmlBodyStart github link), not guessed.
 */
function looksLikeOpenBeken($ch, $data, $statusCode)
{
    if (strpos($data, 'openshwprojects/OpenBK7231T_App') !== false)
        return true;
    if ($statusCode >= 300 && $statusCode < 400) {
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($redirect !== false && strpos($redirect, '/index') !== false)
            return true;
    }
    return false;
}

function getTasmotaScan($ip, $user, $password)
{
    global $settings;

    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    ));
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $isOpenBeken = looksLikeOpenBeken($ch, $data, $statusCode);
    curl_close($ch);
    tbDebugHttp('scan', $url, $statusCode, $err,
        'bytes='.strlen($data).' openbeken='.($isOpenBeken?'yes':'no'));
    if ($isOpenBeken) {
        tbDebug('scan', $ip.': detected OpenBeken (type 2)');
        if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
            addTasmotaDevice($ip, $user, $password, true, false, 2);
        } else {
            return 2;
        }
    }
    if ($err || $statusCode != 200) {
        tbDebug('scan', $ip.': no usable response, not a device');
        return false;
    }
    if (strpos($data, 'Tasmota') !== false) {
        tbDebug('scan', $ip.': detected Tasmota (type 0)');
        if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
            addTasmotaDevice($ip, $user, $password, true, false, 0);
        } else {
            return 0;
        }
    }
    if (strpos($data, 'WLED') !== false) {
        tbDebug('scan', $ip.': detected WLED (type 1)');
        if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
            addTasmotaDevice($ip, $user, $password, true, false, 1);
        } else {
            return 1;
        }
    }
    tbDebug('scan', $ip.': answered but no Tasmota/WLED/OpenBeken marker');
    return false;
}

function getTasmotaScanRange($iprange, $user, $password)
{
    global $settings;

    $result=array();
    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
    );
    $range=15;
    if($range > count($iprange)) $range=count($iprange);
    tbDebug('scan', 'range scan starting over '.count($iprange).
        ' addresses, '.$range.' at a time');
    $master = curl_multi_init();
    for($i=0;$i<$range;$i++) {
        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password).'@'. $iprange[$i] . '/';
        $ch = curl_init($url);
        $options[CURLOPT_REFERER]='http://'.$iprange[$i].'/';
        $options[CURLOPT_HTTPHEADER]=array('Origin: http://'.$iprange[$i]);
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($master, $ch);
    }
    // No $i-- here. It used to re-queue the 15th address, so that one
    // host was probed twice and listed twice in the scan results.

    do {
        while(($execrun = curl_multi_exec($master, $run)) == CURLM_CALL_MULTI_PERFORM) { ; }
        if($execrun != CURLM_OK) {
            break;
        }
        while($done = curl_multi_info_read($master)) {
            $statusCode = curl_getinfo($done['handle'], CURLINFO_HTTP_CODE);
            $url = parse_url(curl_getinfo($done['handle'], CURLINFO_EFFECTIVE_URL));
            $data = curl_multi_getcontent($done['handle']);
            // Checked ahead of the 200-only gate below: OpenBeken's
            // bare / redirects (302) instead of answering directly.
            if (looksLikeOpenBeken($done['handle'], $data, $statusCode)) {
                tbDebug('scan', $url['host'].': http '.$statusCode.
                    ', detected OpenBeken (type 2)');
                if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
                    addTasmotaDevice($url['host'], $user, $password, true, false, 2);
                } else {
                    array_push($result,array($url['host'],2));
                }
            }
            if ($statusCode == 200) {
                if (strpos($data, 'Tasmota') !== false) {
                    tbDebug('scan', $url['host'].
                        ': http 200, detected Tasmota (type 0)');
                    if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
                        addTasmotaDevice($url['host'], $user, $password, true);
                    } else {
                        array_push($result,array($url['host'],0));
                    }
                }
                if (strpos($data, 'WLED') !== false) {
                    tbDebug('scan', $url['host'].
                        ': http 200, detected WLED (type 1)');
                    if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
                        addTasmotaDevice($url['host'], $user, $password, true, false, 1);
                    } else {
                        array_push($result,array($url['host'],1));
                    }
                }
            }
            unset($data);
            unset($url);
            unset($statusCode);
            if($i<count($iprange)) {
                $url = 'http://'.rawurlencode($user).':'.rawurlencode($password).'@'. $iprange[$i] . '/';
                $ch = curl_init($url);
                $options[CURLOPT_REFERER]='http://'.$iprange[$i].'/';
                $options[CURLOPT_HTTPHEADER]=array('Origin: http://'.$iprange[$i]);
                curl_setopt_array($ch, $options);
                $i++;
                curl_multi_add_handle($master, $ch);
            }
            curl_multi_remove_handle($master, $done['handle']);
            curl_close($done['handle']);
        }
    } while($run);
    curl_multi_close($master);
    tbDebug('scan', 'range scan finished, '.count($result).' device(s) found');
    return $result;
}

function getTasmotaStatus($ip, $user, $password, $type=0)
{
    //Get Name
    $url = 'http://' .rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/cm?cmnd=status%200&user='.rawurlencode($user).'&password=' . rawurlencode($password);
    if(intval($type)===1)
        $url = 'http://' .rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/json';
    if(intval($type)===2)
        $url = 'http://' .rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/api/info';
    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('status', $url, $statusCode, $err,
        'bytes='.strlen($data));
    if ($err || $statusCode != 200) {
        return false;
    }
    $json=jsonTasmotaDecode($data);
    if(isset($json["Status"]))
        return $json;
    if(isset($json["info"]))
        return $json;
    if(isset($json["mac"]))
        return $json;
    sleep(1);
    $data=getTasmotaOldStatus($ip, $user, $password);
    if(isset($data['Status'])) {
        $json["Status"]=$data["Status"];
    }
    return $json;
}

function getTasmotaOldStatus($ip, $user, $password)
{
    //Get Name
    $url = 'http://' .rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/cm?cmnd=status&user='.rawurlencode($user).'&password=' . rawurlencode($password);
    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('status0-old', $url, $statusCode, $err, 'bytes='.strlen($data));
    if ($err || $statusCode != 200) {
        return false;
    }
    return jsonTasmotaDecode($data);
}

function getTasmotaStatus2($ip, $user, $password)
{
    //Get Version
    $url = 'http://' . rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/cm?cmnd=status%202&user='.rawurlencode($user).'&password=' . rawurlencode($password);
    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('status2', $url, $statusCode, $err, 'bytes='.strlen($data));
    if ($err || $statusCode != 200) {
        return false;
    }
    return jsonTasmotaDecode($data);
}

function getTasmotaStatus5($ip, $user, $password)
{
    //Get Mac
    $url = 'http://' . rawurlencode($user).':'.rawurlencode($password).'@'. $ip . '/cm?cmnd=status%205&user='.rawurlencode($user).'&password=' . rawurlencode($password);
    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('status5', $url, $statusCode, $err, 'bytes='.strlen($data));
    if ($err || $statusCode != 200) {
        return false;
    }
    return jsonTasmotaDecode($data);
}

function restoreTasmotaBackup($ip, $user, $password, $filename, $type=0)
{
    if (intval($type)===1) { // WLED
        // The backup is the zip getTasmotaBackup built: cfg.json plus
        // presets.json. Both go back via POST /upload.
        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            tbDebug('restore', $ip.': wled backup zip could not be opened');
            return false;
        }
        $cfg = $zip->getFromName('cfg.json');
        $presets = $zip->getFromName('presets.json');
        $zip->close();
        if ($cfg === false || strlen($cfg) < 1) {
            tbDebug('restore', $ip.': wled backup has no cfg.json');
            return false;
        }

        // Presets first. Uploading cfg.json reboots the device from
        // 0.14.0 on, so it has to be the last thing sent.
        if ($presets !== false && strlen($presets) > 0) {
            if (!putWledFile($ip, $user, $password, '/presets.json',
                    $presets)) {
                tbDebug('restore', $ip.': presets upload failed, not '.
                    'sending the config');
                return false;
            }
        }
        if (!putWledFile($ip, $user, $password, '/cfg.json', $cfg))
            return false;

        // 0.13.x does not set doReboot for cfg.json, so the restored
        // config only takes effect after an explicit reset. 0.14.0+
        // reboots itself and this is skipped.
        $version = substr(strstr(basename($filename, '.zip'), 'v'), 1);
        if ($version !== '' && version_compare($version, '0.14.0', '<')) {
            $rurl = 'http://'.rawurlencode($user).':'.
                rawurlencode($password)."@".$ip.'/reset';
            $rs = curl_init($rurl);
            curl_setopt_array($rs, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_REFERER => 'http://'.$ip.'/',
            ));
            curl_exec($rs);
            $rerr = curl_errno($rs);
            $rcode = curl_getinfo($rs, CURLINFO_HTTP_CODE);
            curl_close($rs);
            tbDebugHttp('restore', $rurl, $rcode, $rerr,
                'wled 0.13.x explicit reboot');
        }
        tbDebug('restore', $ip.': wled config restored');
        return true;
    }

    if (intval($type)===2) { // OpenBeken
        // One POST to /api/pins with the gpio roles/channels and the
        // startup command, which OpenBeken executes immediately. No
        // priming request, no reboot, unlike the Tasmota flow below.
        $backup = json_decode(file_get_contents($filename), true);
        if (!is_array($backup) || !isset($backup['pins']))
            return false;

        $body = array();
        if (isset($backup['pins']['roles']))
            $body['roles'] = $backup['pins']['roles'];
        if (isset($backup['pins']['channels']))
            $body['channels'] = $backup['pins']['channels'];
        if (isset($backup['info']['startcmd']) &&
                strlen($backup['info']['startcmd']) > 0)
            $body['deviceCommand'] = $backup['info']['startcmd'];
        if (count($body) < 1)
            return false;

        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/api/pins';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_ENCODING => "",
            CURLOPT_REFERER => 'http://'.$ip.'/',
        ));
        curl_exec($ch);
        $err = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        tbDebugHttp('restore', $url, $statusCode, $err,
            'openbeken pins+startcmd');
        return (!$err && $statusCode == 200);
    }

    // A Tasmota backup taken from a device with berry scripts is a zip
    // of config.dmp plus files/*.be (issue #85). A plain .dmp is the
    // older shape and still restores, so old backups keep working.
    if (intval($type)===0 && strtolower(substr($filename,-4))==='.zip') {
        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            tbDebug('restore', $ip.': backup zip could not be opened');
            return false;
        }
        $dmp = $zip->getFromName('config.dmp');
        if ($dmp === false) {
            $zip->close();
            tbDebug('restore', $ip.': backup zip has no config.dmp');
            return false;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tbrst');
        file_put_contents($tmp, $dmp);

        // Scripts first, then the config. The config restore reboots
        // the device, so doing it last means the scripts are already
        // in place when it comes back up and runs autoexec.be.
        $scripts = 0;
        $failed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (strpos($entry, 'files/') !== 0)
                continue;
            $content = $zip->getFromIndex($i);
            if ($content === false)
                continue;
            if (putTasmotaFile($ip, $user, $password, basename($entry),
                    $content))
                $scripts++;
            else
                $failed++;
        }
        $zip->close();
        tbDebug('restore', $ip.': restored '.$scripts.' berry script(s)'.
            ($failed?', '.$failed.' failed':''));

        if ($failed > 0) {
            // Do not reboot into a half restored script set.
            @unlink($tmp);
            return false;
        }
        $ok = restoreTasmotaBackup($ip, $user, $password, $tmp, 0);
        @unlink($tmp);
        return $ok;
    }

    // GET /rs first to set upload_file_type=UPL_SETTINGS on the device.
    // Tasmota v15.5.0 flipped SetOption128 (disable_referer_chk) to
    // default off, so /rs is now referer-gated by default and a
    // request with no Referer is silently refused. Without this the
    // device never arms settings mode and the /u2 upload below still
    // returns 200 while writing nothing.
    $rs = curl_init('http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/rs');
    curl_setopt_array($rs, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    ));
    $rsData = curl_exec($rs);
    $rsErr = curl_errno($rs);
    $rsCode = curl_getinfo($rs, CURLINFO_HTTP_CODE);
    curl_close($rs);
    tbDebugHttp('restore', 'http://'.$ip.'/rs', $rsCode, $rsErr,
        'arming settings mode');
    if ($rsErr || $rsCode != 200) {
        tbDebug('restore', $ip.': /rs refused, not uploading. On '.
            'Tasmota v15.5+ the referer check is on by default '.
            '(SetOption128)');
        // Refused (referer check, wrong password, device offline): do
        // not proceed to /u2, it would report a false success.
        return false;
    }
	
    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/u2';

    $cfile = new CURLFile($filename,'application/octet-stream','config.dmp');
    $fields = array('u2' => $cfile);

    $options = array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => array('Content-Type: multipart/form-data'),
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip, 'Expect:'),
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);

    $result=curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('restore', $url, $statusCode, $err,
        'uploaded '.basename($filename));
    if (!$err && $statusCode == 200) {
        return true;
    }
    return false;
}

/*
 * The name a single backup downloads as. Split out from
 * downloadTasmotaBackup so it can be tested without streaming a file
 * and exiting. The extension follows the stored file: it used to be
 * hardcoded to .dmp, which already mislabelled WLED (.zip) and
 * OpenBeken (.json) backups, and now also Tasmota berry bundles.
 */
function backupDownloadName($backup)
{
    $filename = $backup['name'] . '-' . $backup['version'] . '-' . $backup['date'];
    $filename = preg_replace('/(\s+|:|\.|\()/', '_', $filename);
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '', $filename);
    $ext = strtolower(pathinfo($backup['filename'], PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,5}$/', $ext))
        $ext = 'dmp';
    return $filename.'.'.$ext;
}

function downloadTasmotaBackup($backup)
{
    if(file_exists($backup['filename'])) {
        header("Cache-Control: no-cache private",true);
        header("Content-Description: Backup ".$backup['name']);
        header('Content-disposition: attachment; filename="'.
            backupDownloadName($backup).'"',true);
        header("Content-Type: application/octet-stream",true);
        header("Content-Transfer-Encoding: binary",true);
        header('Content-Length: '. filesize($backup['filename']),true);
        readfile($backup['filename']);
        exit(0);
    }
    return false;
}

/*
 * One zip holding the most recent backup of each device id passed in,
 * for the bulk "Download Selected" action. A device with no backups
 * yet is skipped rather than failing the whole download. Streams the
 * zip and exits on success, same convention as downloadTasmotaBackup,
 * so a caller only has to handle the failure case.
 */
/*
 * Streams a zip built from $entries (each an array with 'path' and
 * 'name' keys) and exits on success, same convention as
 * downloadTasmotaBackup: a caller only has to handle the false case.
 * Shared by the device list's "Download Selected" (one backup per
 * device) and the per-device backup listing's "Download Selected"
 * (specific versions of one device).
 */
function downloadZipOf($entries, $zipnameprefix, $description)
{
    if (!is_array($entries) || count($entries) < 1)
        return false;

    $tmpfile = tempnam(sys_get_temp_dir(), 'tbzip');
    $zip = new ZipArchive();
    if ($zip->open($tmpfile, ZipArchive::OVERWRITE) === false) {
        @unlink($tmpfile);
        return false;
    }

    $added = 0;
    $usednames = array();
    foreach ($entries as $entry) {
        if (!file_exists($entry['path']))
            continue;
        $entryname = $entry['name'];
        // Two entries can share a name, keep every one.
        if (isset($usednames[$entryname])) {
            $usednames[$entryname]++;
            $entryname = $usednames[$entryname].'-'.$entryname;
        } else {
            $usednames[$entryname] = 1;
        }
        $zip->addFile($entry['path'], $entryname);
        $added++;
    }
    $zip->close();

    if ($added < 1) {
        @unlink($tmpfile);
        return false;
    }

    header("Cache-Control: no-cache private",true);
    header("Content-Description: ".$description,true);
    header('Content-disposition: attachment; filename="'.$zipnameprefix.'-'.
        date('Ymd-His').'.zip"',true);
    header("Content-Type: application/zip",true);
    header("Content-Transfer-Encoding: binary",true);
    header('Content-Length: '. filesize($tmpfile),true);
    readfile($tmpfile);
    @unlink($tmpfile);
    exit(0);
}

function downloadSelectedBackups($ids)
{
    if (!is_array($ids) || count($ids) < 1)
        return false;

    $entries = array();
    foreach ($ids as $id) {
        $device = dbDeviceId(intval($id));
        if ($device === false)
            continue;
        $backups = dbBackupList($device['id']);
        if (!is_array($backups) || count($backups) < 1)
            continue;
        $latest = $backups[0];
        $entries[] = array(
            'path' => $latest['filename'],
            'name' => preg_replace('/[^A-Za-z0-9_\-]/', '_', $device['name']).
                '-'.basename($latest['filename']),
        );
    }
    return downloadZipOf($entries, 'tasmobackup-selected',
        'Selected device backups');
}

/*
 * The bulk download on the per-device backup listing: specific backup
 * versions of one device, picked individually rather than "latest
 * per device".
 */
function downloadSelectedBackupVersions($ids)
{
    if (!is_array($ids) || count($ids) < 1)
        return false;

    $entries = array();
    foreach ($ids as $id) {
        $backup = dbBackupId(intval($id));
        if ($backup === false)
            continue;
        $entries[] = array(
            'path' => $backup['filename'],
            'name' => basename($backup['filename']),
        );
    }
    return downloadZipOf($entries, 'tasmobackup-versions',
        'Selected backup versions');
}

/*
 * A free text console command, sent to every selected device that
 * supports one. Tasmota and OpenBeken both expose the identical
 * GET /cm?cmnd=... model (OpenBeken's http_fn_cm was written to match
 * Tasmota's), WLED has no equivalent single command string, callers
 * should skip type 1 before calling this rather than rely on it
 * failing gracefully.
 */
function sendDeviceCommand($ip, $user, $password, $command, $type=0)
{
    if (intval($type)===1) // WLED: no free text command api
        return false;

    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.
        '/cm?cmnd='.rawurlencode($command).
        '&user='.rawurlencode($user).'&password='.rawurlencode($password);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    ));
    curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('command', $url, $statusCode, $err, 'cmnd='.$command);
    return (!$err && $statusCode == 200);
}

/*
 * Tasmota filesystem (UFS) access, used for berry scripts (issue #85).
 *
 * Verified against the firmware source, xdrv_50_filesystem.ino:
 *   GET  /ufsd                 directory listing, html, each entry is
 *                              <a href='ufsd?download=/x.be' file='x.be'>
 *   GET  /ufsd?download=<path> the file itself
 *   POST /ufsu?fsz=<bytes>     multipart upload, form field "ufsu",
 *                              the part filename is the target name
 *
 * Unlike a settings restore there is no priming step: the POST handler
 * sets UPL_UFSFILE itself every time. fsz is optional but worth
 * sending, the firmware uses it for a free space check before it
 * starts writing (webserver HandleUploadLoop, UPL_UFSFILE branch).
 *
 * Builds without USE_UFILESYS have no /ufsd at all and answer 404,
 * which is the normal case for esp8266. Callers treat that as "this
 * device has no scripts", not as an error.
 */
function getTasmotaBerryFiles($ip, $user, $password)
{
    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/ufsd';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    ));
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $statusCode != 200) {
        tbDebugHttp('berry', $url, $statusCode, $err,
            'no filesystem on this device');
        return array();
    }

    $files = array();
    if (preg_match_all("/ufsd\?download=([^'\"&>]+)/i", $data, $m)) {
        foreach ($m[1] as $path) {
            $path = html_entity_decode($path, ENT_QUOTES, 'UTF-8');
            if (strtolower(substr($path, -3)) !== '.be')
                continue;
            if (!in_array($path, $files))
                $files[] = $path;
        }
    }
    tbDebugHttp('berry', $url, $statusCode, $err,
        count($files).' berry script(s) found');
    return $files;
}

function getTasmotaFile($ip, $user, $password, $path)
{
    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.
        '/ufsd?download='.rawurlencode($path);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
    ));
    $data = curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    tbDebugHttp('berry', $url, $statusCode, $err,
        'bytes='.strlen($data));
    if ($err || $statusCode != 200)
        return false;
    return $data;
}

function putTasmotaFile($ip, $user, $password, $name, $content)
{
    // Written to a temp file because CURLFile needs a path, and the
    // multipart part filename is what the device saves it as.
    $tmp = tempnam(sys_get_temp_dir(), 'tbufs');
    if ($tmp === false || file_put_contents($tmp, $content) === false)
        return false;

    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.
        '/ufsu?fsz='.strlen($content);
    $cfile = new CURLFile($tmp, 'application/octet-stream',
        basename($name));
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array('ufsu' => $cfile),
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip, 'Expect:'),
    ));
    curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    tbDebugHttp('berry', $url, $statusCode, $err,
        'uploaded '.basename($name).' ('.strlen($content).' bytes)');
    return (!$err && $statusCode == 200);
}

/*
 * Writes one file to a WLED device's filesystem.
 *
 * This is the same request WLED's own Security settings page makes:
 * POST /upload, multipart/form-data, form field "data", with the
 * multipart filename set to the destination path. The handler keys off
 * the multipart filename, not the field name, and 0.13.x does not
 * normalise a missing leading slash, so $name must start with one.
 * Route present v0.13.0 through v16.0.1.
 *   src: wled00/wled_server.cpp handleUpload(),
 *        wled00/data/settings_sec.htm uploadFile()
 *
 * Uploading cfg.json reboots the device by itself from 0.14.0 onward.
 * A PIN-locked device answers 401, which is reported as failure, this
 * app has nowhere to store a WLED settings PIN.
 */
function putWledFile($ip, $user, $password, $name, $content)
{
    $tmp = tempnam(sys_get_temp_dir(), 'tbwled');
    if ($tmp === false || file_put_contents($tmp, $content) === false)
        return false;

    $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.
        '/upload';
    // The third CURLFile argument is the multipart filename, which is
    // what WLED uses as the destination path.
    $cfile = new CURLFile($tmp, 'application/json', $name);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array('data' => $cfile),
        CURLOPT_ENCODING => "",
        CURLOPT_REFERER => 'http://'.$ip.'/',
        CURLOPT_HTTPHEADER => array('Origin: http://'.$ip, 'Expect:'),
    ));
    curl_exec($ch);
    $err = curl_errno($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    tbDebugHttp('restore', $url, $statusCode, $err,
        'wled uploaded '.$name.' ('.strlen($content).' bytes)');
    if ($statusCode == 401 || $statusCode == 500)
        tbDebug('restore', $ip.': upload refused, a WLED settings PIN '.
            'or OTA lock is set and this app cannot unlock it');
    return (!$err && $statusCode == 200);
}

function getTasmotaBackup($ip, $user, $password, $filename, $type=0, $berryFiles=array())
{
    //Get Backup

    if(intval($type)===0) { // Tasmota
        // With berry scripts the backup becomes a zip holding
        // config.dmp plus files/<script>.be. Without them it stays a
        // bare config.dmp exactly as before, so nothing changes for a
        // device with no filesystem and old backups still restore.
        $bundling = (is_array($berryFiles) && count($berryFiles) > 0);
        $dmpfile = $bundling
            ? tempnam(sys_get_temp_dir(), 'tbdmp') : $filename;

        $fp = fopen($dmpfile, 'w+');
        if ($fp === false) {
            return false;
        }
        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/dl';
        $options = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
            CURLOPT_ENCODING => "",
            CURLOPT_REFERER => 'http://'.$ip.'/',
            CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        curl_setopt($ch,CURLOPT_FILE,$fp);
        curl_exec($ch);
        $err = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);
        curl_close($ch);
        tbDebugHttp('backup', $url, $statusCode, $err,
            'wrote '.(file_exists($dmpfile)?filesize($dmpfile):0).
            ' bytes to '.basename($dmpfile));

        if ($err || $statusCode != 200) {
            if ($bundling)
                @unlink($dmpfile);
            return false;
        }
        if (!$bundling)
            return true;

        // Config is in hand, now collect the scripts alongside it.
        $zip = new ZipArchive();
        if ($zip->open($filename, ZipArchive::CREATE|ZipArchive::OVERWRITE) === false) {
            @unlink($dmpfile);
            return false;
        }
        $zip->addFile($dmpfile, 'config.dmp');
        $saved = 0;
        foreach ($berryFiles as $path) {
            $content = getTasmotaFile($ip, $user, $password, $path);
            if ($content === false) {
                // A script we listed but could not read means an
                // incomplete backup, better to fail than to store a
                // bundle that silently lost a file.
                tbDebug('berry', $ip.': could not read '.$path.
                    ', abandoning this backup');
                $zip->close();
                @unlink($dmpfile);
                @unlink($filename);
                return false;
            }
            $zip->addFromString('files/'.basename($path), $content);
            $saved++;
        }
        $zip->close();
        @unlink($dmpfile);
        tbDebug('berry', $ip.': bundled config.dmp plus '.$saved.
            ' berry script(s) into '.basename($filename));
        return true;
    } else if(intval($type)===1) { // WLED
        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/edit?download=cfg.json';

        //parsing version from filename
        $version = substr(strstr(basename($filename, '.zip'), 'v'), 1);

        if (version_compare($version,'0.13.1',  '>')){
            $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/cfg.json?download';
        }
        $options = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
            CURLOPT_ENCODING => "",
            CURLOPT_REFERER => 'http://'.$ip.'/',
            CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $cfg = curl_exec($ch);
        $err = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        tbDebugHttp('backup', $url, $statusCode, $err,
            'wled cfg.json bytes='.strlen($cfg));
        if($err || $statusCode !== 200)
            return false;

        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/edit?download=presets.json';

        if (version_compare($version,'0.13.0',  '>')){
            $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/presets.json?download';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $presets = curl_exec($ch);
        $err = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        tbDebugHttp('backup', $url, $statusCode, $err,
            'wled presets.json bytes='.strlen($presets));
        if($err || $statusCode !== 200)
            return false;

        $zip = new ZipArchive;
        if($zip->open($filename, ZipArchive::CREATE) === FALSE)
            return false;
        if($zip->addFromString('cfg.json', $cfg) === FALSE)
            return false;
        if($zip->addFromString('presets.json', $presets) === FALSE)
            return false;
        $zip->close();
        return true;
    } else if(intval($type)===2) { // OpenBeken
        // No Tasmota-dl/WLED-cfg.json equivalent exists: no single
        // portable settings blob. api/info carries identity plus the
        // startup command string, api/pins carries the gpio role and
        // channel assignment a restore actually needs to make the
        // device work again. Saved together as one json file.
        $options = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'TasmoBackup '.$GLOBALS['VERSION'],
            CURLOPT_ENCODING => "",
            CURLOPT_REFERER => 'http://'.$ip.'/',
            CURLOPT_HTTPHEADER => array('Origin: http://'.$ip),
        );

        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/api/info';
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $infoData = curl_exec($ch);
        $err = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        tbDebugHttp('backup', $url, $statusCode, $err,
            'openbeken api/info bytes='.strlen($infoData));
        if ($err || $statusCode != 200)
            return false;
        $info = json_decode($infoData, true);
        if (!is_array($info))
            return false;

        $url = 'http://'.rawurlencode($user).':'.rawurlencode($password)."@".$ip.'/api/pins';
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $pinsData = curl_exec($ch);
        $err = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        tbDebugHttp('backup', $url, $statusCode, $err,
            'openbeken api/pins bytes='.strlen($pinsData));
        if ($err || $statusCode != 200)
            return false;
        $pins = json_decode($pinsData, true);
        if (!is_array($pins))
            return false;

        // states is the live on/off value of every channel at backup
        // time, not a setting. Dropping it so a restore does not force
        // outputs to whatever they happened to be during the backup.
        unset($pins['states']);

        $backup = array('info' => $info, 'pins' => $pins);
        return (file_put_contents($filename,
            json_encode($backup, JSON_UNESCAPED_SLASHES)) !== false);
    }

    return false;
}

function backupCleanup($id)
{
    global $settings;

    $backupfolder = $settings['backup_folder'];

    $days=0;
    $count=0;
    if(isset($settings['backup_maxdays']))
        $days=intval($settings['backup_maxdays']);
    if(isset($settings['backup_maxcount']))
        $count=intval($settings['backup_maxcount']);
    if($days>0 || $count>0)
        return dbBackupTrim($id,$days,$count);
    return true;
}

function backupSingle($id, $name, $ip, $user, $password, $type=0)
{
    global $settings;

    $backupfolder = $settings['backup_folder'];

    if ($status=getTasmotaStatus($ip, $user, $password, $type)) {
        if(intval($type)===0) { // Tasmota
            if (!isset($status['StatusFWR'])) {
                sleep(1);
                if ($status2=getTasmotaStatus2($ip, $user, $password)) {
                    $status['StatusFWR']=$status2['StatusFWR'];
                } else
                    return true; // Device Offline
            }
	    if (!isset($status['StatusNET'])) {
                sleep(1);
                if ($status5=getTasmotaStatus5($ip, $user, $password)) {
                    $status['StatusNET']=$status5['StatusNET'];
                } else
                    return true; // Device Offline
            }
        }
        if(intval($type)===1) { // WLED
            if (!isset($status['info']['ver']))
                return true;
        }
        if(intval($type)===2) { // OpenBeken
            if (!isset($status['mac']))
                return true;
        }
    } else {
        tbDebug('backup', $ip.': no status response, treating as offline');
        return true; // Device Offline
    }

    $hostname = '';
    if(intval($type)===0) { // Tasmota
        $version = $status['StatusFWR']['Version'];
        $mac = strtoupper($status['StatusNET']['Mac']);
        if (isset($status['StatusNET']['Hostname']))
            $hostname = $status['StatusNET']['Hostname'];

        if (!isset($settings['autoupdate_name']) || (isset($settings['autoupdate_name']) && $settings['autoupdate_name']=='Y')) {
            if(isset($settings['use_topic_as_name']) && $settings['use_topic_as_name']=='F') {
            // Empty
            } else {
                if (isset($status['Status']['Topic']))
                    $name=$status['Status']['Topic'];
                if(!isset($settings['use_topic_as_name']) || $settings['use_topic_as_name']=='N') {
                    if (isset($status['Status']['DeviceName']) && strlen(preg_replace('/\s+/', '',$status['Status']['DeviceName']))>0)
                        $name=$status['Status']['DeviceName'];
                    else if (isset($status['Status']['FriendlyName'][0]))
                        $name=$status['Status']['FriendlyName'][0];
                }
            }
        }
    } else if (intval($type)===1) { // WLED
        if(isset($status['info']['name']))
            $name=trim($status['info']['name']);
        if(isset($status['info']['ver']))
            $version=trim($status['info']['ver']);
        if(isset($status['info']['mac']))
            $mac=implode(':',str_split(str_replace(array('.',':'),array('',''),trim($status['info']['mac'])),2));
    } else if (intval($type)===2) { // OpenBeken
        if(isset($status['shortName'])) {
            $name=trim($status['shortName']);
            $hostname=trim($status['shortName']);
        }
        if(isset($status['build']))
            $version=trim($status['build']);
        if(isset($status['mac']))
            $mac=$status['mac'];
    }

    // The caller picked this row by address. If the device answering
    // that address is a different device that has a row of its own, do
    // not file its backup here and do not stamp its mac and hostname
    // over this row.
    if ($mac !== '' || $hostname !== '') {
        $owner = dbDeviceFind(NULL, $mac, $hostname);
        if ($owner !== false && intval($owner) !== intval($id)) {
            tbDebug('backup', $ip.': answered by device id '.$owner.
                ' but this row is id '.$id.', refusing to file the '.
                'backup here');
            return true; // a different device holds this address now
        }
    }

    $savename = preg_replace('/\s+/', '_', $name);
    $savename = preg_replace('/[^A-Za-z0-9_\-]/', '', $savename);
    $savemac = preg_replace('/[^A-Za-z0-9_\-]/','', $mac);

    if (!file_exists($backupfolder . $savename)) {
        $oldmask = umask(0);
        mkdir($backupfolder . $savename, 0777, true);
        umask($oldmask);
    }
    $date = date('Y-m-d H:i:s');
    $savedate = preg_replace('/(\s+|:)/', '_', $date);
    $savedate = preg_replace('/[^A-Za-z0-9_\-]/', '', $savedate);

    // Berry scripts (issue #85). Only Tasmota has a filesystem to ask
    // about, and only when the setting is on. A device with no
    // filesystem answers 404 and gets an empty list, which keeps the
    // backup a plain .dmp exactly as before.
    $berryFiles = array();
    if (intval($type)===0 &&
            (!isset($settings['backup_berry']) ||
             $settings['backup_berry']=='Y')) {
        $berryFiles = getTasmotaBerryFiles($ip, $user, $password);
    }

    $ext='.dmp';
    if(intval($type)===0 && count($berryFiles)>0) $ext='.zip';
    if(intval($type)===1) $ext='.zip';
    if(intval($type)===2) $ext='.json';


    $saveto = $backupfolder . $savename . "/" . $savemac . "-" . $savedate . '-v' . $version . $ext;

    sleep(1);
    if (getTasmotaBackup($ip, $user, $password, $saveto, $type, $berryFiles)) {
        $directory = $backupfolder . $savename . "/";
/*
        // Initialize filecount variavle
        $filecount = 0;

        $files2 = glob($directory . "*");

        if ($files2) {
            $noofbackups = count($files2);
            #echo $noofbackups;
        }
*/
        if (!dbNewBackup($id, $name, $version, $date, 1, $saveto, $mac, $type, $hostname)) {
            tbDebug('backup', $ip.': downloaded but the database insert '.
                'failed');
            return true;
        }
        tbDebug('backup', $ip.': saved '.$saveto);
        return false;
    }
    // The download failed. Returning false here reported the backup as
    // a success, and let backupCleanup prune older good backups even
    // though nothing new was saved.
    tbDebug('backup', $ip.': download failed, discarding partial file');
    if (file_exists($saveto))
        unlink($saveto);
    return true;
}

function backupAll($docker=false)
{
    global $db_handle;
    global $settings;

    // 23 is the default the settings page shows. Defaulting to 0 here
    // meant a never-saved setting silently skipped every scheduled
    // backup while a manual Backup All still worked.
    $hours=23;
    if(isset($settings['backup_minhours']))
        $hours=intval($settings['backup_minhours']);
    if($docker && $hours==0) // 0 disables scheduled backups
        return false;
    if ($docker && isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') { // auto scan on schedule
        if(isset($settings['mqtt_host']) && isset($settings['mqtt_port']) && strlen($settings['mqtt_host'])>1) {
            require_once(__DIR__.'/mqtt.inc.php');
            $mqtt=setupMQTT($settings['mqtt_host'], $settings['mqtt_port'], $settings['mqtt_user'], $settings['mqtt_password']);
            $username='admin';
            if(isset($settings['tasmota_username'])) $username=$settings['tasmota_username'];
            $password='';
            if(isset($settings['tasmota_password'])) $password=$settings['tasmota_password'];
            if($mqtt) getTasmotaMQTTScan($mqtt,$settings['mqtt_topic'],$username,$password,true);
        }
    }
    tbDebug('backupall', ($docker?'scheduled':'manual').
        ' run starting, minimum '.$hours.'h between backups');
    $stm = $db_handle->prepare("select * from devices where lastbackup < :date or lastbackup is NULL ");
    $stm->execute(array(":date" => date('Y-m-d H:i:s',time()-(3600*$hours))));
    $errorcount = 0;
    $totalcount = 0;
    while ($db_field = $stm->fetch(PDO::FETCH_ASSOC)) {
        $totalcount++;
        if (backupSingle($db_field['id'], $db_field['name'], $db_field['ip'], 'admin', $db_field['password'], $db_field['type'])) {
            $errorcount++;
        } else {
            backupCleanup($db_field['id']);
        }
    }
    tbDebug('backupall', 'finished, '.$errorcount.' failed of '.
        $totalcount.' attempted');
    return array($errorcount,$totalcount);
}

/*
 * Pulls the identity out of a status response. Both discovery paths
 * used to carry their own copy of this, which is how they drifted
 * apart.
 */
function statusIdentity($status, $type, &$name, &$version, &$mac, &$hostname)
{
    global $settings;

    if(intval($type)===0) { // Tasmota
        if(isset($settings['use_topic_as_name']) && $settings['use_topic_as_name']=='F' && isset($status['Topic'])) {
            $name=trim(str_replace(array('/stat','stat/'),array('',''),$status['Topic'])," \t\r\n\v\0/");
        } else {
            if (isset($status['Status']['Topic']))
                $name=$status['Status']['Topic'];
            if(!isset($settings['use_topic_as_name']) || $settings['use_topic_as_name']=='N') {
                if (isset($status['Status']['DeviceName']) && strlen(preg_replace('/\s+/', '',$status['Status']['DeviceName']))>0)
                    $name=$status['Status']['DeviceName'];
                else if (isset($status['Status']['FriendlyName'][0]))
                    $name=$status['Status']['FriendlyName'][0];
            }
        }
        if (isset($status['StatusFWR']['Version']))
            $version=$status['StatusFWR']['Version'];
        if (isset($status['StatusNET']['Mac']))
            $mac=strtoupper($status['StatusNET']['Mac']);
        if (isset($status['StatusNET']['Hostname']))
            $hostname=$status['StatusNET']['Hostname'];
    } else if (intval($type)===1) { // WLED
        if(isset($status['info']['name']))
            $name=trim($status['info']['name']);
        if(isset($status['info']['ver']))
            $version=trim($status['info']['ver']);
        if(isset($status['info']['mac']))
            $mac=implode(':',str_split(str_replace(array('.',':'),array('',''),trim($status['info']['mac'])),2));
    } else if (intval($type)===2) { // OpenBeken
        // shortName doubles as both the friendly name and the mDNS
        // hostname, OpenBeken has no separate concept of the two.
        // mac is already lowercase colon separated, dbNormalizeMac
        // handles any case/format so no reformatting is needed here.
        if(isset($status['shortName'])) {
            $name=trim($status['shortName']);
            $hostname=trim($status['shortName']);
        }
        if(isset($status['build']))
            $version=trim($status['build']);
        if(isset($status['mac']))
            $mac=$status['mac'];
    }
}

function addTasmotaDevice($ip, $user, $password, $verified=false, $status=false, $type=null)
{
    global $settings;

    if(!$verified || !isset($type)) {
        if (($type=getTasmotaScan($ip, $user, $password))===false) {
            return sprintf(t('%s: Device not found.'), $ip);
        }
    }
    // Left as null when the device did not tell us, so an update does
    // not overwrite a good stored value with a placeholder.
    $name=NULL;
    $version=NULL;
    $mac='';
    $hostname='';
    $newname=NULL;
    if (!dbDeviceExist($ip)) {
        if ($status===false)
            $status=getTasmotaStatus($ip, $user, $password, $type);
        if (isset($status) && $status) {
            if(intval($type)===0) { // Tasmota
                // The isset guards matter: a device that answers with
                // something other than the block we asked for used to
                // leave StatusNET null, which meant no mac, which meant
                // a row with no identity that could never be rematched.
                if (!isset($status['StatusNET'])) {
                    sleep(1);
                    $status5=getTasmotaStatus5($ip, $user, $password);
                    if (isset($status5['StatusNET']))
                        $status['StatusNET']=$status5['StatusNET'];
                    else
                        return sprintf(t('%s: Device not responding to '.
                            'status5 request.'), $ip);
                }
                if(!isset($status['StatusFWR'])) {
                    sleep(1);
                    $status2=getTasmotaStatus2($ip, $user, $password);
                    if (isset($status2['StatusFWR']))
                        $status['StatusFWR']=$status2['StatusFWR'];
                    else
                        return sprintf(t('%s: Device not responding to '.
                            'status2 request.'), $ip);
                }
            }
            statusIdentity($status,$type,$name,$version,$mac,$hostname);
            tbDebug('discover', $ip.': identity mac='.
                ($mac!==''?$mac:'(none)').' hostname='.
                ($hostname!==''?$hostname:'(none)').' type='.$type);
            if (($id=dbDeviceFind($ip,$mac,$hostname))>0) {
                tbDebug('discover', $ip.': matched existing device id '.
                    $id.', updating it');
                if (!isset($settings['autoupdate_name']) || (isset($settings['autoupdate_name']) && $settings['autoupdate_name']=='Y'))
                    $newname=$name;
                if(dbDeviceUpdate($id,$newname,$ip,$version,$password,$mac,$type,$hostname))
                    return sprintf(t('%1$s: %2$s information has been '.
                        'updated!'), $ip, $name);
                else
                    return sprintf(t('%1$s: %2$s already exists in the '.
                        'database!'), $ip, $name);
            } else {
                tbDebug('discover', $ip.': no existing device matched, '.
                    'adding a new one');
                if (dbDeviceAdd(isset($name)?$name:$ip, $ip,
                        isset($version)?$version:'', $password, $mac,
                        $type, $hostname)) {
                    return sprintf(t('%1$s: %2$s Added Successfully!'),
                        $ip, $name);
                }
            }
            return sprintf(t('%1$s: %2$s Error adding device to '.
                'database.'), $ip, $name);
        }
        return sprintf(t('%s: Device not responding to status request.'),
            $ip);
    } else {
        // A row already carries this ip. That is not proof it is the
        // same device: dhcp hands a freed address to the next device,
        // and the old owner still holds the row. Ask the device who it
        // is before deciding. When it was scanned over mqtt the status
        // is already in hand, so this costs no extra request there.
        if ($status===false)
            $status=getTasmotaStatus($ip, $user, $password, $type);
        if (isset($status) && $status) {
            statusIdentity($status,$type,$name,$version,$mac,$hostname);

            // dbDeviceFind decides what counts as the same device: a
            // reported identity never falls back to a plain ip match,
            // it can only adopt a row that has no identity at all.
            tbDebug('discover', $ip.': address already known, device '.
                'reports mac='.($mac!==''?$mac:'(none)').' hostname='.
                ($hostname!==''?$hostname:'(none)'));
            $id=dbDeviceFind($ip,$mac,$hostname);
            if ($id>0) {
                if (!isset($settings['autoupdate_name']) || (isset($settings['autoupdate_name']) && $settings['autoupdate_name']=='Y') && isset($name))
                    $newname=$name;
                if(dbDeviceUpdate($id,isset($newname)?$newname:NULL,$ip,isset($version)?$version:NULL,$password,isset($mac)?$mac:NULL,$type,$hostname))
                    return sprintf(t('%1$s: %2$s information has been '.
                        'updated!'), $ip, isset($name)?$name:'');
                else
                    return sprintf(t('%1$s: %2$s already exists in the '.
                        'database!'), $ip, isset($name)?$name:'');
            }
            // Known address, unknown device: it is a different device
            // that inherited the address, so it gets its own row.
            tbDebug('discover', $ip.': a different device now holds this '.
                'address, adding it as a new device');
            if (dbDeviceAdd(isset($name)?$name:$ip, $ip,
                    isset($version)?$version:'', $password, $mac,
                    $type, $hostname)) {
                return sprintf(t('%1$s: %2$s Added Successfully!'),
                    $ip, isset($name)?$name:'');
            }
        }
    }
    return sprintf(t('%s: This device already exists in the database!'),
        $ip);
}


function TBHeader($name=false,$favicon=true,$init=false,$track=true,$redirect=false)
{
    global $settings;

    $colormode = 'auto';
    if(isset($settings['theme']) && $settings['theme']=='light') { // Enforce Light mode
        $colormode = 'light';
    }
    if(isset($settings['theme']) && $settings['theme']=='dark') { // Enforce Dark mode
        $colormode = 'dark';
    }

    echo '<!DOCTYPE html><html lang="'.htmlspecialchars(tbLang()).'"><head>';
if($redirect!==false && $redirect>0) {
    echo '<meta http-equiv="refresh" content="'.$redirect.';url=index.php" />';
}
if($favicon) {
?>
<link rel="shortcut icon" href="favicon.ico">
<link rel="icon" sizes="16x16 32x32 64x64" href="favicon.ico">
<link rel="icon" type="image/png" sizes="196x196" href="favicon/192.png">
<link rel="icon" type="image/png" sizes="160x160" href="favicon/160.png">
<link rel="icon" type="image/png" sizes="96x96" href="favicon/96.png">
<link rel="icon" type="image/png" sizes="64x64" href="favicon/64.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon/32.png">
<link rel="icon" type="image/png" sizes="16x16" href="favicon/16.png">
<link rel="apple-touch-icon" href="favicon/57.png">
<link rel="apple-touch-icon" sizes="114x114" href="favicon/114.png">
<link rel="apple-touch-icon" sizes="72x72" href="favicon/72.png">
<link rel="apple-touch-icon" sizes="144x144" href="favicon/144.png">
<link rel="apple-touch-icon" sizes="60x60" href="favicon/60.png">
<link rel="apple-touch-icon" sizes="120x120" href="favicon/120.png">
<link rel="apple-touch-icon" sizes="76x76" href="favicon/76.png">
<link rel="apple-touch-icon" sizes="152x152" href="favicon/152.png">
<link rel="apple-touch-icon" sizes="180x180" href="favicon/180.png">
<?php }

if($track) { ?>
<!-- Global site tag (gtag.js) - Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=UA-116906-4"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'UA-116906-4');
</script>
<?php } ?>
<title><?php echo ($name!==false) ?
    sprintf(t('TasmoBackup: %s'), $name) : 'TasmoBackup'; ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="resources/bootstrap.min.css">
  <script src="resources/jquery.min.js"></script>
  <script src="resources/bootstrap.min.js"></script>
<?php if($init !== false) { ?>
  <script src="resources/datatables.min.js"></script>
  <script src="resources/sorting.min.js"></script>
  <link rel="stylesheet" type="text/css" href="resources/datatables.min.css"/>
    <script class="init">
    // Table chrome comes from the same catalogue as everything else,
    // set as a default so no page has to pass it.
    $.extend(true, $.fn.dataTable.defaults, {
        "language": <?php echo tbDataTablesLanguage(); ?>
    });
    <?php echo $init; ?>
    </script>
<?php } ?>
  <script>
    function updateColorScheme(mode) {
      if(mode === 'light') {
          document.documentElement.setAttribute('data-bs-theme', 'light');
      } else if(mode === 'dark') {
          document.documentElement.setAttribute('data-bs-theme', 'dark');
      } else {
          document.documentElement.setAttribute('data-bs-theme', window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      }
    }
    updateColorScheme('<?php echo $colormode; ?>');
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', updateColorScheme);
  </script>
</head>
<?php
}

function TBFooter()
{
    global $VERSION;
?>
<br><br>
<div style='text-align:right;font-size:11px;'><hr/><a href='https://github.com/danmed/TasmoBackupV1' target='_blank' style='color:#aaa;'><?php echo sprintf(
    t('TasmoBackup %s by Dan Medhurst'),
    $GLOBALS['VERSION']); ?></a></div>
<?php
}

function getGithubTasmotaReleaseData()
{
    global $settings;

    // put the releases json in the backup_folder
    $backupfolder = $settings['backup_folder'];
    $get_new_version = false;

    $github_tasmota_release_data_file = $backupfolder . "/github-tasmota-release-data.json";
    // check if file exists
    if ( file_exists($github_tasmota_release_data_file) === true ) {
        $_mtime = filemtime($github_tasmota_release_data_file);
        $yesterday = strtotime("-1 days");
        if ( $_mtime <= $yesterday ) {
            $get_new_version = true;
        }
    } else {
        $get_new_version = true;
    }

    if ( $get_new_version ) {
        // get Tasmota release data from github - but only if it is the next calendar day
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: PHP'
                ]
            ]
        ];
        $context = stream_context_create($opts);
        $github_tasmota_version_release_details = file_get_contents("https://api.github.com/repos/arendst/Tasmota/releases", false, $context);
        file_put_contents($github_tasmota_release_data_file, $github_tasmota_version_release_details);
    } else {
        $github_tasmota_version_release_details = file_get_contents($github_tasmota_release_data_file);
    }
    $github_tasmota_release_data = json_decode($github_tasmota_version_release_details, true);

    return $github_tasmota_release_data;
}
