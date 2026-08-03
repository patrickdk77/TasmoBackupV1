<?php
/*
 * Debug logging.
 *
 * Off by default, switched on with the Debug Logging setting. When on,
 * every network call, discovery decision, backup, restore and cleanup
 * writes a line to the php error log, which in the docker image and
 * the Home Assistant add-on is the container log (php-fpm is
 * configured with error_log = /dev/stderr), so it shows up in
 * "docker logs" or the add-on Log tab with no extra setup.
 *
 * Issue #87: a user could not tell why mqtt discovery only found one
 * of their devices, and turning on the debug flag inside phpMQTT
 * produced nothing visible anywhere.
 *
 * SECURITY: this app puts device credentials straight into request
 * urls, both as http://user:pass@host and as a &password= query
 * argument. Log lines get pasted into bug reports, so every url and
 * message goes through tbRedact() first. Never error_log() a raw url
 * from this codebase, always hand it to tbDebug/tbRedact.
 */

function tbDebugEnabled()
{
    global $settings;
    return isset($settings['debug']) && $settings['debug']=='Y';
}

/*
 * Strips credentials out of anything about to be logged:
 *   http://admin:hunter2@1.2.3.4/cm  ->  http://admin:***@1.2.3.4/cm
 *   ...&password=hunter2&x=1         ->  ...&password=***&x=1
 */
function tbRedact($text)
{
    $text = preg_replace('#(://[^:/@\s]*):[^@/\s]*@#', '$1:***@', $text);
    $text = preg_replace('#([?&](?:password|pass|pwd)=)[^&\s]*#i',
        '$1***', $text);
    return $text;
}

function tbDebug($area, $message)
{
    if (!tbDebugEnabled())
        return;
    error_log('TasmoBackup ['.$area.'] '.tbRedact($message));
}

/*
 * The common "finished an http call" line, so every caller reports the
 * same fields in the same order and nobody has to remember to redact.
 */
function tbDebugHttp($area, $url, $statusCode, $errno, $extra='')
{
    if (!tbDebugEnabled())
        return;
    $msg = $url.' -> http '.intval($statusCode);
    if ($errno)
        $msg .= ' curl_errno='.intval($errno);
    if ($extra !== '')
        $msg .= ' '.$extra;
    tbDebug($area, $msg);
}
