<?php
require_once(__DIR__.'/bootstrap.php');

// ---- jsonTasmotaDecode, the happy paths -------------------------

test('decodes plain status json', function () {
    $d = jsonTasmotaDecode('{"Status":{"Topic":"kitchen"}}');
    assertSame('kitchen', $d['Status']['Topic']);
});

test('decodes json holding control characters', function () {
    $d = jsonTasmotaDecode("{\"Status\":{\"Topic\":\"kit\x01chen\"}}");
    assertTrue(isset($d['Status']['Topic']),
        'control characters should be stripped, not fatal');
});

test('decodes console style STATUS = output', function () {
    $raw = "STATUS = {\"Status\":{\"Topic\":\"kitchen\"}}";
    $d = jsonTasmotaDecode($raw);
    assertSame('kitchen', $d['Status']['Topic']);
});

test('joins the multi part STATUS blocks', function () {
    $raw = "STATUS = {\"Status\":{\"Topic\":\"kitchen\"}}".
           "STATUS2 = {\"StatusFWR\":{\"Version\":\"13.4.0\"}}";
    $d = jsonTasmotaDecode($raw);
    assertSame('kitchen', $d['Status']['Topic']);
    assertSame('13.4.0', $d['StatusFWR']['Version']);
});

test('rewrites bare nan so the json stays valid', function () {
    $raw = "STATUS = {\"Status\":{\"Topic\":\"kitchen\"},".
           "\"Temp\":nan}";
    $d = jsonTasmotaDecode($raw);
    assertSame('NaN', $d['Temp']);
});

// ---- jsonTasmotaDecode, the bad input ---------------------------

test('returns an empty array for unparsable input', function () {
    assertSame(array(), jsonTasmotaDecode('this is not json at all'));
});

test('returns an empty array for an empty string', function () {
    assertSame(array(), jsonTasmotaDecode(''));
});

test('returns an empty array for html error pages', function () {
    assertSame(array(),
        jsonTasmotaDecode('<html><body>401 Unauthorized</body></html>'));
});

test('returns an empty array for truncated json', function () {
    assertSame(array(), jsonTasmotaDecode('{"Status":{"Topic":'));
});

test('a bare json scalar is not treated as a status', function () {
    $d = jsonTasmotaDecode('42');
    assertFalse(isset($d['Status']), 'scalar must not look like status');
});

// ---- getBetween -------------------------------------------------

test('getBetween pulls out the delimited text', function () {
    assertSame('here', getBetween('xx<a>here</a>yy', '<a>', '</a>'));
});

test('getBetween returns empty when the start is missing', function () {
    assertSame('', getBetween('nothing to find', '<a>', '</a>'));
});

test('getBetween returns the tail when the end is missing', function () {
    assertSame('here and on', getBetween('<a>here and on', '<a>', '</a>'));
});

tb_test_exit();
