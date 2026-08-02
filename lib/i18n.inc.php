<?php
/*
 * Localization.
 *
 * User facing strings go through t(), plurals through tn(). Both fall
 * back to the untranslated English when the gettext extension is not
 * loaded, so a bare php install without php-gettext still runs.
 *
 * Catalogues live in locale/<lang>/LC_MESSAGES/tasmobackup.po and are
 * compiled to .mo next to them. See "make pot" to refresh the template
 * after changing a string, and "make mo" to compile. The image builds
 * the .mo files during docker build.
 *
 * The language is picked from the language setting, or from the
 * browser Accept-Language header when that setting is auto or unset,
 * and falls back to English.
 */

define('TB_TEXTDOMAIN', 'tasmobackup');
define('TB_HAS_GETTEXT', function_exists('gettext'));

function tbLocaleDir()
{
    return __DIR__.'/../locale';
}

/*
 * Languages that ship a catalogue, keyed by code. The names are the
 * native spelling, which is what a language picker should show.
 */
function tbLanguages()
{
    static $langs = NULL;
    if ($langs !== NULL)
        return $langs;

    // Codes follow the ones Home Assistant uses, so a translator who
    // already works on HA sees the same names.
    $names = array(
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'nl' => 'Nederlands',
        'af' => 'Afrikaans',
        'bg' => 'Български',
        'fy' => 'Frysk',
        'he' => 'עברית',
        'lt' => 'Lietuvių',
        'it' => 'Italiano',
        'pt' => 'Português',
        'pt-BR' => 'Português (Brasil)',
        'pl' => 'Polski',
        'sv' => 'Svenska',
        'da' => 'Dansk',
        'nb' => 'Norsk bokmål',
        'fi' => 'Suomi',
        'cs' => 'Čeština',
        'sk' => 'Slovenčina',
        'hu' => 'Magyar',
        'el' => 'Ελληνικά',
        'ru' => 'Русский',
        'uk' => 'Українська',
        'tr' => 'Türkçe',
        'ro' => 'Română',
        'ca' => 'Català',
        'ja' => '日本語',
        'ko' => '한국어',
        'zh-Hans' => '简体中文',
        'zh-Hant' => '繁體中文',
        'th' => 'ภาษาไทย',
        'vi' => 'Tiếng Việt',
        'id' => 'Indonesia',
    );

    // English is the source language, it needs no catalogue.
    $langs = array('en' => $names['en']);
    foreach (glob(tbLocaleDir().'/*/LC_MESSAGES/'.TB_TEXTDOMAIN.'.po') as $po) {
        $code = basename(dirname(dirname($po)));
        if ($code === 'en')
            continue;
        $langs[$code] = isset($names[$code]) ? $names[$code] : $code;
    }
    ksort($langs);
    return $langs;
}

/*
 * Accept-Language, most wanted first. A regional tag also yields its
 * primary tag just behind it, so de-CH still finds the de catalogue.
 */
function tbParseAcceptLanguage($header)
{
    $weighted = array();
    foreach (explode(',', $header) as $part) {
        $bits = explode(';', trim($part));
        $code = strtolower(trim($bits[0]));
        if ($code === '' || $code === '*')
            continue;
        $q = 1.0;
        for ($i = 1; $i < count($bits); $i++) {
            $kv = explode('=', $bits[$i], 2);
            if (count($kv) == 2 && trim($kv[0]) === 'q')
                $q = floatval($kv[1]);
        }
        $weighted[] = array($code, $q);
        $primary = strtok($code, '-');
        if ($primary !== $code)
            $weighted[] = array($primary, $q - 0.0001);
    }
    usort($weighted, function ($a, $b) {
        if ($a[1] == $b[1])
            return 0;
        return ($a[1] < $b[1]) ? 1 : -1;
    });

    $codes = array();
    foreach ($weighted as $w) {
        if (!in_array($w[0], $codes))
            $codes[] = $w[0];
    }
    return $codes;
}

/*
 * Browsers send regional tags, catalogues are named by script where
 * the script is what actually differs. Nobody sends zh-Hans, they send
 * zh-CN or zh-TW, so those have to be routed by hand.
 */
function tbLanguageAliases()
{
    return array(
        'zh' => 'zh-Hans',
        'zh-cn' => 'zh-Hans',
        'zh-sg' => 'zh-Hans',
        'zh-my' => 'zh-Hans',
        'zh-hans' => 'zh-Hans',
        'zh-tw' => 'zh-Hant',
        'zh-hk' => 'zh-Hant',
        'zh-mo' => 'zh-Hant',
        'zh-hant' => 'zh-Hant',
        'pt-br' => 'pt-BR',
        'nb' => 'nb',
        'no' => 'nb',
        'nn' => 'nb',
        'iw' => 'he', // the old code, still sent by some clients
        'in' => 'id',
    );
}

/*
 * Resolves one requested tag against what we ship. Tries the tag as
 * given, then the alias table, then the primary subtag, all case
 * insensitively because language tags are not case sensitive.
 */
function tbMatchLanguage($want)
{
    $want = strtolower(trim($want));
    if ($want === '')
        return false;

    $index = array();
    foreach (tbLanguages() as $code => $name)
        $index[strtolower($code)] = $code;

    if (isset($index[$want]))
        return $index[$want];

    $aliases = tbLanguageAliases();
    if (isset($aliases[$want])) {
        $alias = strtolower($aliases[$want]);
        if (isset($index[$alias]))
            return $index[$alias];
    }

    $primary = strtok($want, '-');
    if ($primary !== $want) {
        if (isset($index[$primary]))
            return $index[$primary];
        if (isset($aliases[$primary])) {
            $alias = strtolower($aliases[$primary]);
            if (isset($index[$alias]))
                return $index[$alias];
        }
    }
    return false;
}

function tbPickLanguage()
{
    global $settings;

    $want = isset($settings['language']) ? strtolower(trim($settings['language'])) : '';
    if ($want !== '' && $want !== 'auto') {
        $match = tbMatchLanguage($want);
        // Configured for something we do not ship: English, not the
        // browser, because the setting was an explicit choice.
        return ($match === false) ? 'en' : $match;
    }

    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        foreach (tbParseAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE']) as $code) {
            $match = tbMatchLanguage($code);
            if ($match !== false)
                return $match;
        }
    }
    return 'en';
}

function tbLang()
{
    return isset($GLOBALS['TB_LANG']) ? $GLOBALS['TB_LANG'] : 'en';
}

function tbSetupLocale($lang = NULL)
{
    if ($lang === NULL)
        $lang = tbPickLanguage();
    $GLOBALS['TB_LANG'] = $lang;

    if (TB_HAS_GETTEXT) {
        // musl carries no locale data, so the env vars are what
        // actually steer the catalogue lookup here, not setlocale.
        putenv('LANGUAGE='.$lang);
        putenv('LC_ALL='.$lang);
        setlocale(LC_ALL, $lang.'.UTF-8', $lang, 'C.UTF-8', 'C');
        bindtextdomain(TB_TEXTDOMAIN, tbLocaleDir());
        if (function_exists('bind_textdomain_codeset'))
            bind_textdomain_codeset(TB_TEXTDOMAIN, 'UTF-8');
        textdomain(TB_TEXTDOMAIN);
    }
    return $lang;
}

function t($msgid)
{
    return TB_HAS_GETTEXT ? gettext($msgid) : $msgid;
}

function tn($singular, $plural, $count)
{
    if (TB_HAS_GETTEXT)
        return ngettext($singular, $plural, $count);
    return ($count == 1) ? $singular : $plural;
}

/*
 * A datetime out of the database, in the reader's language. The raw
 * value is kept for the sort attribute so the column still orders
 * chronologically whatever the display format is.
 */
function tbFormatDateTime($value)
{
    if (!isset($value) || strlen(trim($value)) < 10)
        return '';
    $ts = strtotime($value);
    if ($ts === false)
        return $value;
    if (class_exists('IntlDateFormatter')) {
        $fmt = new IntlDateFormatter(tbLang(),
            IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT);
        $out = $fmt->format($ts);
        if ($out !== false)
            return $out;
    }
    return date('Y-m-d H:i', $ts);
}

/*
 * The DataTables strings, built from the same catalogue rather than
 * shipping a separate language file per locale.
 */
function tbDataTablesLanguage()
{
    return json_encode(array(
        'emptyTable' => t('No data available in table'),
        'info' => t('Showing _START_ to _END_ of _TOTAL_ entries'),
        'infoEmpty' => t('Showing 0 to 0 of 0 entries'),
        'infoFiltered' => t('(filtered from _MAX_ total entries)'),
        'lengthMenu' => t('Show _MENU_ entries'),
        'loadingRecords' => t('Loading...'),
        'processing' => t('Processing...'),
        'search' => t('Search:'),
        'zeroRecords' => t('No matching records found'),
        'paginate' => array(
            'first' => t('First'),
            'last' => t('Last'),
            'next' => t('Next'),
            'previous' => t('Previous'),
        ),
        'aria' => array(
            'sortAscending' => t(': activate to sort column ascending'),
            'sortDescending' => t(': activate to sort column descending'),
        ),
    ), JSON_UNESCAPED_UNICODE);
}
