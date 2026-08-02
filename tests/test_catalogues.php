<?php
/*
 * Catalogue hygiene.
 *
 * Guards the translation files themselves: they have to compile, they
 * may only translate strings that actually exist in the source, and
 * the template has to be current. Skips cleanly before the template
 * has been generated for the first time.
 */

require_once(__DIR__.'/bootstrap.php');

$POT = TB_ROOT.'/locale/tasmobackup.pot';
$POS = glob(TB_ROOT.'/locale/*/LC_MESSAGES/tasmobackup.po');

if (!file_exists($POT)) {
    echo "  skipped, run make pot first\n";
    tb_test_exit();
}

/* All msgids in a po or pot file, unescaped enough to compare. */
function tb_msgids($file)
{
    $ids = array();
    $cur = NULL;
    foreach (file($file) as $line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $cur = $m[1];
            continue;
        }
        if ($cur !== NULL && preg_match('/^"(.*)"$/', $line, $m)) {
            $cur .= $m[1]; // continuation line
            continue;
        }
        if ($cur !== NULL) {
            if ($cur !== '')
                $ids[$cur] = true;
            $cur = NULL;
        }
    }
    if ($cur !== NULL && $cur !== '')
        $ids[$cur] = true;
    return $ids;
}

function tb_header($file)
{
    $txt = file_get_contents($file);
    $end = strpos($txt, "\n\n");
    return ($end === false) ? $txt : substr($txt, 0, $end);
}

$potids = tb_msgids($POT);

test('the template holds a sensible number of strings', function () use (
    $potids) {
    assertTrue(count($potids) > 50,
        'only '.count($potids).' strings extracted, wrapping looks '.
        'incomplete');
});

test('the template is up to date with the source', function () use (
    $POT, $potids) {
    // Re-extract into a temp file and compare the msgid sets. A t()
    // added without running make pot would strand that string.
    $tmp = TB_TMP.'/fresh.pot';
    $sources = 'index.php settings.php scan.php edit.php '.
        'listbackups.php upgrade.php export.php backupall.php lib/*.php';
    $cmd = 'cd '.escapeshellarg(TB_ROOT).' && xgettext --from-code=UTF-8 '.
        '--language=PHP --keyword=t --keyword=tn:1,2 -o '.
        escapeshellarg($tmp).' '.$sources.' 2>&1';
    $out = array();
    $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        echo "       skipped, xgettext unavailable: ".
            implode(' ', $out)."\n";
        return;
    }
    $fresh = tb_msgids($tmp);
    $missing = array_diff_key($fresh, $potids);
    assertCount(0, $missing,
        'these strings are in the source but not in the template, run '.
        'make pot: '.implode(' | ', array_slice(array_keys($missing), 0, 5)));
});

test('every catalogue compiles', function () use ($POS) {
    if (!$POS) {
        echo "       no catalogues yet\n";
        return;
    }
    foreach ($POS as $po) {
        $out = array();
        $rc = 0;
        exec('msgfmt --check-format -o /dev/null '.escapeshellarg($po).
            ' 2>&1', $out, $rc);
        assertSame(0, $rc, basename(dirname(dirname($po))).
            ' does not compile: '.implode(' ', $out));
    }
});

test('no catalogue invents a string that is not in the source',
function () use ($POS, $potids) {
    if (!$POS)
        return;
    foreach ($POS as $po) {
        $lang = basename(dirname(dirname($po)));
        $stray = array_diff_key(tb_msgids($po), $potids);
        assertCount(0, $stray, $lang.' translates strings that do not '.
            'exist in the source: '.
            implode(' | ', array_slice(array_keys($stray), 0, 3)));
    }
});

test('every catalogue declares utf-8', function () use ($POS) {
    if (!$POS)
        return;
    foreach ($POS as $po) {
        $lang = basename(dirname(dirname($po)));
        assertTrue(stripos(tb_header($po), 'charset=UTF-8') !== false,
            $lang.' does not declare charset=UTF-8');
    }
});

test('every catalogue declares plural forms', function () use ($POS) {
    if (!$POS)
        return;
    foreach ($POS as $po) {
        $lang = basename(dirname(dirname($po)));
        assertTrue(stripos(tb_header($po), 'Plural-Forms:') !== false,
            $lang.' has no Plural-Forms header, plurals would break');
    }
});

test('every catalogue directory is a language we know about',
function () use ($POS) {
    if (!$POS)
        return;
    $known = tbLanguages();
    foreach ($POS as $po) {
        $lang = basename(dirname(dirname($po)));
        assertTrue(isset($known[$lang]),
            $lang.' has a catalogue but no entry in tbLanguages(), so '.
            'it can never be selected');
    }
});

test('a machine seeded catalogue keeps its fuzzy marks', function () use (
    $POS) {
    // Fuzzy entries are excluded by msgfmt, which is what keeps an
    // unreviewed guess from reaching users. If a catalogue claims to be
    // seeded it must still say so.
    if (!$POS)
        return;
    foreach ($POS as $po) {
        $txt = file_get_contents($po);
        if (stripos($txt, 'machine translated') === false)
            continue; // a reviewed catalogue, nothing to check
        assertTrue(strpos($txt, '#, fuzzy') !== false,
            basename(dirname(dirname($po))).' says it is machine '.
            'translated but carries no fuzzy marks');
    }
});

tb_test_exit();
