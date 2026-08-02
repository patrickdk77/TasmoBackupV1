<?php
/*
 * Assertions and the per-file test runner.
 *
 * No dependencies on purpose, the app has none either. A test file
 * includes bootstrap.php, calls test() a few times and exits with
 * tb_test_exit().
 */

$GLOBALS['tb_pass'] = 0;
$GLOBALS['tb_fail'] = 0;
$GLOBALS['tb_current'] = '';

function test($name, $fn)
{
    $GLOBALS['tb_current'] = $name;
    try {
        $fn();
        $GLOBALS['tb_pass']++;
        echo "  ok   ".$name."\n";
    } catch (Throwable $e) {
        $GLOBALS['tb_fail']++;
        echo "  FAIL ".$name."\n";
        echo "       ".$e->getMessage()."\n";
        $f = $e->getFile();
        if (strpos($f, TB_ROOT) === 0)
            $f = substr($f, strlen(TB_ROOT) + 1);
        echo "       at ".$f.":".$e->getLine()."\n";
    }
}

function tb_test_exit()
{
    echo sprintf("  %d passed, %d failed\n",
        $GLOBALS['tb_pass'], $GLOBALS['tb_fail']);
    exit($GLOBALS['tb_fail'] > 0 ? 1 : 0);
}

function tb_show($v)
{
    if (is_bool($v))
        return $v ? 'true' : 'false';
    if (is_null($v))
        return 'null';
    if (is_array($v))
        return 'array('.count($v).')';
    if (is_string($v))
        return "'".(strlen($v) > 60 ? substr($v, 0, 57).'...' : $v)."'";
    return (string)$v;
}

function assertTrue($v, $msg = '')
{
    if ($v !== true)
        throw new Exception(($msg ?: 'expected true').
            ', got '.tb_show($v));
}

function assertFalse($v, $msg = '')
{
    if ($v !== false)
        throw new Exception(($msg ?: 'expected false').
            ', got '.tb_show($v));
}

function assertSame($expect, $actual, $msg = '')
{
    if ($expect !== $actual)
        throw new Exception(($msg ?: 'not identical').
            ': expected '.tb_show($expect).
            ', got '.tb_show($actual));
}

function assertEquals($expect, $actual, $msg = '')
{
    if ($expect != $actual)
        throw new Exception(($msg ?: 'not equal').
            ': expected '.tb_show($expect).
            ', got '.tb_show($actual));
}

function assertNotEquals($expect, $actual, $msg = '')
{
    if ($expect == $actual)
        throw new Exception(($msg ?: 'should differ').
            ': both '.tb_show($actual));
}

function assertCount($n, $a, $msg = '')
{
    if (!is_array($a))
        throw new Exception(($msg ?: 'not an array').
            ', got '.tb_show($a));
    if (count($a) !== $n)
        throw new Exception(($msg ?: 'wrong count').
            ': expected '.$n.', got '.count($a));
}

function assertFileExists($p, $msg = '')
{
    if (!file_exists($p))
        throw new Exception(($msg ?: 'missing file').': '.$p);
}

function assertFileMissing($p, $msg = '')
{
    if (file_exists($p))
        throw new Exception(($msg ?: 'file should not exist').': '.$p);
}
