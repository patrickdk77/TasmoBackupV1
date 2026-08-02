<?php
/*
 * Test runner.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php db backup  run tests/test_db.php and test_backup.php
 *
 * Each file runs in its own php process so one test file cannot leak
 * globals or a database handle into the next.
 */

$want = array_slice($argv, 1);
$files = glob(__DIR__.'/test_*.php');
sort($files);

if ($want) {
    $files = array_values(array_filter($files, function ($f) use ($want) {
        foreach ($want as $w)
            if (strpos(basename($f), $w) !== false)
                return true;
        return false;
    }));
    if (!$files) {
        fwrite(STDERR, "no test files matched\n");
        exit(2);
    }
}

$failed = array();
$start = microtime(true);
foreach ($files as $f) {
    echo basename($f)."\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($f), $rc);
    if ($rc !== 0)
        $failed[] = basename($f);
    echo "\n";
}

printf("%d file(s) in %.1fs\n", count($files), microtime(true) - $start);
if ($failed) {
    echo "FAILED: ".implode(', ', $failed)."\n";
    exit(1);
}
echo "all green\n";
