<?php
/*
 * Test bootstrap.
 *
 * lib/db.inc.php loads its config from __DIR__/../data/config.inc.php,
 * so a test run gets its own throwaway copy of the app instead of
 * touching the real data directory. Every test file runs in its own
 * php process, so each one gets a clean database.
 */

define('TB_ROOT', dirname(__DIR__));

$tb_tmp = sys_get_temp_dir().'/tasmobackup-test-'.getmypid().'-'.mt_rand();
define('TB_TMP', $tb_tmp);

mkdir(TB_TMP.'/lib', 0777, true);
mkdir(TB_TMP.'/data/backups', 0777, true);
mkdir(TB_TMP.'/HA_addon', 0777, true);

foreach (glob(TB_ROOT.'/lib/*.php') as $f) {
    copy($f, TB_TMP.'/lib/'.basename($f));
}
// functions.inc.php reads the version out of this
copy(TB_ROOT.'/HA_addon/config.json', TB_TMP.'/HA_addon/config.json');

// The real catalogues, so tbLanguages() sees the languages actually
// shipped. Only the .po files, a test that wants a compiled catalogue
// builds its own .mo.
mkdir(TB_TMP.'/locale', 0777, true);
if (file_exists(TB_ROOT.'/locale/tasmobackup.pot'))
    copy(TB_ROOT.'/locale/tasmobackup.pot', TB_TMP.'/locale/tasmobackup.pot');
foreach (glob(TB_ROOT.'/locale/*/LC_MESSAGES/tasmobackup.po') as $po) {
    $lang = basename(dirname(dirname($po)));
    mkdir(TB_TMP.'/locale/'.$lang.'/LC_MESSAGES', 0777, true);
    copy($po, TB_TMP.'/locale/'.$lang.'/LC_MESSAGES/tasmobackup.po');
}

file_put_contents(TB_TMP.'/data/config.inc.php',
    "<?php\n".
    "\$DBType   = 'sqlite';\n".
    "\$DBServer = '';\n".
    "\$DBUser   = '';\n".
    "\$DBPassword = '';\n".
    "\$DBName   = 'data/testdb';\n");

function tb_rmtree($dir)
{
    if (!is_dir($dir))
        return;
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..')
            continue;
        $p = $dir.'/'.$e;
        is_dir($p) ? tb_rmtree($p) : unlink($p);
    }
    rmdir($dir);
}

register_shutdown_function(function () {
    if (getenv('TB_KEEP_TMP'))
        fwrite(STDERR, "kept ".TB_TMP."\n");
    else
        tb_rmtree(TB_TMP);
});

$db_upgrade = true;
require_once(TB_TMP.'/lib/functions.inc.php');

// Backups land inside the throwaway tree, not the real data dir.
dbSettingsUpdate('backup_folder', TB_TMP.'/data/backups/');

/*
 * Empties the device table so a test can set up a clean scenario
 * without rows left behind by the tests above it.
 */
function tb_reset_devices()
{
    global $db_handle;
    $db_handle->exec('delete from devices');
    $db_handle->exec('delete from backups');
}

require_once(__DIR__.'/assert.php');
require_once(TB_TMP.'/lib/i18n.inc.php');
require_once(__DIR__.'/stub_device.php');
