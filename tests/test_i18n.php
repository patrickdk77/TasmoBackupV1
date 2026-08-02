<?php
/*
 * Language selection and the translation layer.
 *
 * The catalogues themselves are built in the temp tree so these tests
 * do not depend on which languages happen to be shipped.
 */

require_once(__DIR__.'/bootstrap.php');

// Build throwaway catalogues: de, fr and zh-Hans.
$LOCDIR = TB_TMP.'/locale';
foreach (array('de' => 'Sicherung', 'fr' => 'Sauvegarde',
    'zh-Hans' => 'BEIFEN') as $code => $word) {
    @mkdir($LOCDIR.'/'.$code.'/LC_MESSAGES', 0777, true);
    $po = TB_TMP.'/'.$code.'.po';
    file_put_contents($po,
        "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n".
        "\n".
        "msgid \"Backup\"\nmsgstr \"".$word."\"\n");
    exec('msgfmt -o '.escapeshellarg($LOCDIR.'/'.$code.
        '/LC_MESSAGES/tasmobackup.mo').' '.escapeshellarg($po).' 2>&1');
}

// Point the app at them.
function tbLocaleDirOverride() { return $GLOBALS['LOCDIR']; }

// ---- accept-language parsing --------------------------------------

test('accept-language is ordered by quality', function () {
    $c = tbParseAcceptLanguage('en;q=0.5,de;q=0.9,fr;q=0.7');
    assertSame('de', $c[0]);
    assertSame('fr', $c[1]);
    assertSame('en', $c[2]);
});

test('a regional tag also offers its primary tag', function () {
    $c = tbParseAcceptLanguage('de-CH');
    assertSame('de-ch', $c[0]);
    assertSame('de', $c[1]);
});

test('a bare header defaults to quality 1', function () {
    $c = tbParseAcceptLanguage('nl,de;q=0.8');
    assertSame('nl', $c[0]);
});

test('junk in accept-language does not explode', function () {
    assertCount(0, tbParseAcceptLanguage(''));
    assertCount(0, tbParseAcceptLanguage('*'));
    assertCount(0, tbParseAcceptLanguage(',,,'));
    $c = tbParseAcceptLanguage('de;q=nonsense');
    assertSame('de', $c[0]);
});

// ---- matching -----------------------------------------------------

test('an exact language matches', function () {
    assertSame('en', tbMatchLanguage('en'));
});

test('matching ignores case', function () {
    assertSame('en', tbMatchLanguage('EN'));
    assertSame('en', tbMatchLanguage('En'));
});

test('an unknown language does not match', function () {
    assertFalse(tbMatchLanguage('kl'));
    assertFalse(tbMatchLanguage(''));
    assertFalse(tbMatchLanguage('not-a-language'));
});

test('a regional tag falls back to the primary language', function () {
    // en-GB is not shipped, en is
    assertSame('en', tbMatchLanguage('en-GB'));
    assertSame('en', tbMatchLanguage('en-us'));
});

// ---- picking ------------------------------------------------------

test('no setting and no header gives english', function () {
    global $settings;
    unset($settings['language']);
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    assertSame('en', tbPickLanguage());
});

test('an explicit setting wins over the browser', function () {
    global $settings;
    $settings['language'] = 'en';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,fr;q=0.9';
    assertSame('en', tbPickLanguage());
});

test('a setting for a language we do not ship gives english',
function () {
    global $settings;
    $settings['language'] = 'kl';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';
    assertSame('en', tbPickLanguage(),
        'an explicit unknown choice should not silently use the browser');
});

test('auto uses the browser', function () {
    global $settings;
    $settings['language'] = 'auto';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-GB,en;q=0.9';
    assertSame('en', tbPickLanguage());
});

// ---- translation --------------------------------------------------

test('gettext is available in this build', function () {
    assertTrue(TB_HAS_GETTEXT,
        'the image is supposed to ship php gettext');
});

test('an untranslated string comes back unchanged', function () {
    tbSetupLocale('en');
    assertSame('Backup', t('Backup'));
});

test('a translated string comes back translated', function () use (
    $LOCDIR) {
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    tbSetupLocale('de');
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    assertSame('Sicherung', t('Backup'));
});

test('switching language inside one request works', function () use (
    $LOCDIR) {
    tbSetupLocale('fr');
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    assertSame('Sauvegarde', t('Backup'));
    tbSetupLocale('de');
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    assertSame('Sicherung', t('Backup'));
});

test('a script subtag catalogue loads', function () use ($LOCDIR) {
    tbSetupLocale('zh-Hans');
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    assertSame('BEIFEN', t('Backup'),
        'a zh-Hans directory should resolve');
});

test('a missing catalogue falls back to the english source',
function () use ($LOCDIR) {
    tbSetupLocale('nl');
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    assertSame('Backup', t('Backup'));
    assertSame('Nothing here', t('Nothing here'));
});

test('a string missing from a catalogue falls back', function () use (
    $LOCDIR) {
    tbSetupLocale('de');
    bindtextdomain(TB_TEXTDOMAIN, $LOCDIR);
    assertSame('Restore', t('Restore'),
        'an untranslated msgid should come back as english');
});

test('plurals pick the right form', function () {
    tbSetupLocale('en');
    assertSame('1 backup', sprintf(tn('%d backup', '%d backups', 1), 1));
    assertSame('3 backups', sprintf(tn('%d backup', '%d backups', 3), 3));
    assertSame('0 backups', sprintf(tn('%d backup', '%d backups', 0), 0));
});

// ---- dates --------------------------------------------------------

test('a datetime formats and stays sortable at the source',
function () {
    tbSetupLocale('en');
    $out = tbFormatDateTime('2026-08-02 14:30:00');
    assertTrue(strlen($out) > 6, 'got nothing back: '.$out);
    assertTrue(strpos($out, '2026') !== false,
        'the year should survive: '.$out);
});

test('an empty or bad datetime formats to empty, not a crash',
function () {
    assertSame('', tbFormatDateTime(null));
    assertSame('', tbFormatDateTime(''));
    assertSame('', tbFormatDateTime('   '));
});

test('the datatables language block is valid json', function () {
    tbSetupLocale('en');
    $j = json_decode(tbDataTablesLanguage(), true);
    assertTrue(is_array($j), 'not decodable');
    assertTrue(isset($j['paginate']['next']), 'paginate missing');
    assertTrue(isset($j['search']), 'search missing');
});

tb_test_exit();
