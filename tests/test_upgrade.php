<?php
/*
 * Schema upgrade. An install that predates a column has to gain it on
 * the next run, with its rows intact. This builds a second throwaway
 * app pointed at an old shaped database and loads the real db layer
 * against it in a subprocess.
 */

require_once(__DIR__.'/bootstrap.php');

function tb_old_app($create_sql, $seed)
{
    static $n = 0;
    $n++;
    $dir = TB_TMP.'/old'.$n;
    mkdir($dir.'/lib', 0777, true);
    mkdir($dir.'/data', 0777, true);
    mkdir($dir.'/HA_addon', 0777, true);
    foreach (glob(TB_ROOT.'/lib/*.php') as $f)
        copy($f, $dir.'/lib/'.basename($f));
    copy(TB_ROOT.'/HA_addon/config.json', $dir.'/HA_addon/config.json');
    file_put_contents($dir.'/data/config.inc.php',
        "<?php\n\$DBType='sqlite';\n\$DBServer='';\n\$DBUser='';\n".
        "\$DBPassword='';\n\$DBName='data/olddb';\n");

    $pdo = new PDO('sqlite:'.$dir.'/data/olddb.sqlite3');
    $pdo->exec($create_sql);
    foreach ($seed as $sql)
        $pdo->exec($sql);
    $pdo = null;
    return $dir;
}

function tb_run_in($dir, $php)
{
    $script = $dir.'/probe.php';
    file_put_contents($script,
        "<?php\n\$db_upgrade=true;\n".
        "require_once('".$dir."/lib/functions.inc.php');\n".$php);
    $out = array();
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).
        ' 2>&1', $out, $rc);
    return array($rc, implode("\n", $out));
}

// A 1.06 era database: mac and type exist, hostname does not.
$OLD = "CREATE TABLE devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name varchar(128) NOT NULL,
            ip varchar(64) NOT NULL,
            mac varchar(32) NOT NULL,
            type INTEGER NOT NULL DEFAULT 0,
            version varchar(128) NOT NULL,
            lastbackup datetime DEFAULT NULL,
            noofbackups INTEGER DEFAULT NULL,
            password varchar(128) DEFAULT NULL )";

$SEED = array(
    "INSERT INTO devices (name,ip,mac,type,version) VALUES
        ('Kitchen','192.168.1.25','AA:BB:CC:DD:EE:01',0,'13.4.0')",
    "CREATE TABLE settings (name varchar(128) PRIMARY KEY NOT NULL,
        value varchar(255) NOT NULL)",
    "INSERT INTO settings VALUES ('amount','25')",
);

test('an old database gains the hostname column', function () use (
    $OLD, $SEED) {
    $dir = tb_old_app($OLD, $SEED);
    list($rc, $out) = tb_run_in($dir,
        '$c=array();'.
        'foreach($db_handle->query("pragma table_info(devices)") as $r)'.
        '  $c[]=$r["name"];'.
        'echo implode(",",$c);');
    assertSame(0, $rc, 'the upgrade failed: '.$out);
    assertTrue(strpos($out, 'hostname') !== false,
        'hostname column missing after upgrade, columns: '.$out);
});

test('the upgrade keeps the existing rows and settings', function () use (
    $OLD, $SEED) {
    $dir = tb_old_app($OLD, $SEED);
    list($rc, $out) = tb_run_in($dir,
        'global $settings;'.
        '$d=dbDevices();'.
        'echo count($d),"|",$d[0]["name"],"|",$d[0]["mac"],"|",'.
        '$d[0]["hostname"],"|",$settings["amount"];');
    assertSame(0, $rc, 'the upgrade failed: '.$out);
    assertSame('1|Kitchen|AA:BB:CC:DD:EE:01||25', $out,
        'the migrated row did not survive intact');
});

test('an upgraded row is matchable by mac right away', function () use (
    $OLD, $SEED) {
    $dir = tb_old_app($OLD, $SEED);
    list($rc, $out) = tb_run_in($dir,
        // the device moved to a new lease since the upgrade
        'var_export(dbDeviceFind("192.168.99.99","aa:bb:cc:dd:ee:01",'.
        '"kitchen-1234") !== false);');
    assertSame(0, $rc, 'the lookup failed: '.$out);
    assertSame('true', $out,
        'a migrated row could not be matched by its mac');
});

test('running the upgrade twice is harmless', function () use (
    $OLD, $SEED) {
    $dir = tb_old_app($OLD, $SEED);
    list($rc1, $o1) = tb_run_in($dir, 'echo "first";');
    list($rc2, $o2) = tb_run_in($dir, 'echo count(dbDevices());');
    assertSame(0, $rc1, 'first run failed: '.$o1);
    assertSame(0, $rc2, 'second run failed: '.$o2);
    assertSame('1', $o2, 'the second run disturbed the data: '.$o2);
});

test('a database with no tables at all is created from scratch',
function () {
    $dir = tb_old_app('CREATE TABLE placeholder (x int)', array());
    list($rc, $out) = tb_run_in($dir,
        'echo count(dbDevices()),"|";'.
        'echo dbDeviceAdd("New","10.0.0.1","1","","AA:BB:CC:DD:EE:99",'.
        '0,"new-host")?"added":"failed";');
    assertSame(0, $rc, 'a fresh database failed: '.$out);
    assertSame('0|added', $out);
});

test('the upgrade normalises macs stored in other spellings',
function () use ($OLD) {
    $seed = array(
        "INSERT INTO devices (name,ip,mac,type,version) VALUES
            ('Lower','10.0.0.1','aa:bb:cc:dd:ee:01',0,'1')",
        "INSERT INTO devices (name,ip,mac,type,version) VALUES
            ('Bare','10.0.0.2','AABBCCDDEE02',0,'1')",
        "INSERT INTO devices (name,ip,mac,type,version) VALUES
            ('Dashes','10.0.0.3','aa-bb-cc-dd-ee-03',0,'1')",
        "INSERT INTO devices (name,ip,mac,type,version) VALUES
            ('Blank','10.0.0.4','',0,'1')",
    );
    $dir = tb_old_app($OLD, $seed);
    list($rc, $out) = tb_run_in($dir,
        '$m=array();'.
        'foreach(dbDevices() as $d) $m[]=$d["mac"];'.
        'echo implode(",",$m);');
    assertSame(0, $rc, 'the upgrade failed: '.$out);
    assertSame('AA:BB:CC:DD:EE:01,AA:BB:CC:DD:EE:02,AA:BB:CC:DD:EE:03,',
        $out, 'stored macs were not brought into one shape');
});

test('a normalised legacy mac is matchable after the upgrade',
function () use ($OLD) {
    $seed = array(
        "INSERT INTO devices (name,ip,mac,type,version) VALUES
            ('Bare','10.0.0.2','AABBCCDDEE02',0,'1')",
    );
    $dir = tb_old_app($OLD, $seed);
    list($rc, $out) = tb_run_in($dir,
        'var_export(dbDeviceFind("10.9.9.9","aa:bb:cc:dd:ee:02",null)'.
        ' !== false);');
    assertSame('true', $out, 'the migrated mac did not match: '.$out);
});

tb_test_exit();
