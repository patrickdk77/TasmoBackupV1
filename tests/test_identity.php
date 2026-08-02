<?php
/*
 * Device identity: match on mac, then hostname, then ip.
 *
 * The scenario behind all of this is a dhcp lease change. The device is
 * the same device, its ip is not, and it must not turn into a second
 * row.
 */

require_once(__DIR__.'/bootstrap.php');

// ---- normalising --------------------------------------------------

test('macs normalise to upper case with colons', function () {
    assertSame('AA:BB:CC:DD:EE:FF', dbNormalizeMac('aa:bb:cc:dd:ee:ff'));
    assertSame('AA:BB:CC:DD:EE:FF', dbNormalizeMac('AABBCCDDEEFF'));
    assertSame('AA:BB:CC:DD:EE:FF', dbNormalizeMac('aa-bb-cc-dd-ee-ff'));
    assertSame('AA:BB:CC:DD:EE:FF', dbNormalizeMac('AA:BB:CC:DD:EE:FF'));
});

test('a junk mac normalises to empty, never a partial match', function () {
    assertSame('', dbNormalizeMac(''));
    assertSame('', dbNormalizeMac(null));
    assertSame('', dbNormalizeMac('not-a-mac'));
    assertSame('', dbNormalizeMac('AA:BB:CC'));
    assertSame('', dbNormalizeMac('00'));
});

test('hostnames normalise to lower case', function () {
    assertSame('kitchen-1234', dbNormalizeHostname('Kitchen-1234'));
    assertSame('kitchen-1234', dbNormalizeHostname('  KITCHEN-1234 '));
    assertSame('kitchen', dbNormalizeHostname('kitchen.'));
    assertSame('', dbNormalizeHostname(''));
    assertSame('', dbNormalizeHostname(null));
});

// ---- the dhcp move ------------------------------------------------

test('a device that changed ip is matched by mac', function () {
    dbDeviceAdd('Kitchen', '192.168.1.25', '13.4.0', '',
        'AA:BB:CC:DD:EE:01', 0, 'kitchen-1234');
    $id = dbDeviceFind('192.168.1.25', 'AA:BB:CC:DD:EE:01', 'kitchen-1234');
    assertNotEquals(false, $id);

    // same device, new lease
    $again = dbDeviceFind('192.168.1.99', 'AA:BB:CC:DD:EE:01', 'kitchen-1234');
    assertSame($id, $again, 'a new ip produced a different row');
});

test('a device that changed ip and mac case still matches', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01', null);
    assertSame($id, dbDeviceFind('192.168.1.99', 'aa:bb:cc:dd:ee:01', null),
        'lower case mac missed the stored upper case one');
    assertSame($id, dbDeviceFind('192.168.1.99', 'AABBCCDDEE01', null),
        'a separator-less mac missed the stored one');
});

test('a device with no mac is matched by hostname', function () {
    dbDeviceAdd('Porch', '192.168.1.26', '13.4.0', '', '', 0,
        'porch-5678');
    $id = dbDeviceFind('192.168.1.26', '', 'porch-5678');
    assertNotEquals(false, $id);
    assertSame($id, dbDeviceFind('192.168.1.200', '', 'porch-5678'),
        'the hostname should have matched after the ip changed');
    assertSame($id, dbDeviceFind('192.168.1.200', '', 'PORCH-5678'),
        'hostname matching must ignore case');
});

test('an updated device keeps one row and gains the new ip', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:01', null);
    $before = count(dbDevices());
    assertTrue(dbDeviceUpdate($id, 'Kitchen', '192.168.1.99', '13.4.0',
        '', 'AA:BB:CC:DD:EE:01', 0, 'kitchen-1234'));
    assertSame($before, count(dbDevices()), 'a row was added, not updated');
    assertSame('192.168.1.99', dbDeviceId($id)['ip'],
        'the stored ip was not moved to the new lease');
});

// ---- precedence ---------------------------------------------------

test('mac wins over a hostname pointing at another row', function () {
    dbDeviceAdd('First', '10.0.0.1', '1', '', 'AA:BB:CC:DD:EE:10', 0,
        'shared-name');
    dbDeviceAdd('Second', '10.0.0.2', '1', '', 'AA:BB:CC:DD:EE:20', 0,
        'other-name');
    $second = dbDeviceFind(null, 'AA:BB:CC:DD:EE:20', null);
    assertSame($second,
        dbDeviceFind('10.0.0.9', 'AA:BB:CC:DD:EE:20', 'shared-name'),
        'the hostname beat the mac');
});

test('hostname wins over an ip pointing at another row', function () {
    $second = dbDeviceFind(null, 'AA:BB:CC:DD:EE:20', null);
    assertSame($second, dbDeviceFind('10.0.0.1', '', 'other-name'),
        'the ip beat the hostname');
});

test('ip is only used when mac and hostname are unknown', function () {
    $first = dbDeviceFind(null, 'AA:BB:CC:DD:EE:10', null);
    assertSame($first, dbDeviceFind('10.0.0.1', '', ''));
    assertSame($first, dbDeviceFind('10.0.0.1', null, null));
});

// ---- the negative half --------------------------------------------

test('an empty mac and hostname never match a stored row', function () {
    // regression for #89, the empty string used to match any row whose
    // mac was blank
    assertFalse(dbDeviceFind('10.9.9.9', '', ''));
    assertFalse(dbDeviceExist('10.9.9.9', '', ''));
});

test('a junk mac does not match the device with no mac', function () {
    assertFalse(dbDeviceFind('10.9.9.9', 'garbage', ''),
        'an unparsable mac matched something');
});

test('an unknown device is not found by any key', function () {
    assertFalse(dbDeviceFind('10.9.9.9', 'FF:FF:FF:FF:FF:FF',
        'nothing-like-this'));
});

test('a blank hostname column does not collide with a blank lookup',
function () {
    dbDeviceAdd('NoHost', '10.0.0.3', '1', '', 'AA:BB:CC:DD:EE:30', 0, '');
    assertFalse(dbDeviceFind('10.9.9.9', '', ''),
        'the row stored with an empty hostname was matched');
});

// ---- writes stay normalised ---------------------------------------

test('a device added with a messy mac is stored normalised', function () {
    dbDeviceAdd('Messy', '10.0.0.4', '1', '', 'a1b2c3d4e5f6', 0,
        'MESSY-Host');
    $d = dbDeviceId(dbDeviceFind('10.0.0.4', null, null));
    assertSame('A1:B2:C3:D4:E5:F6', $d['mac']);
    assertSame('messy-host', $d['hostname']);
});

test('an update never blanks a known mac or hostname', function () {
    $id = dbDeviceFind(null, 'A1:B2:C3:D4:E5:F6', null);
    assertTrue(dbDeviceUpdate($id, 'Messy2', '10.0.0.4', '2', '', '', 0,
        ''));
    $d = dbDeviceId($id);
    assertSame('A1:B2:C3:D4:E5:F6', $d['mac'], 'the mac was blanked');
    assertSame('messy-host', $d['hostname'], 'the hostname was blanked');
    assertSame('Messy2', $d['name'], 'the name did not update');
});

test('a backup fills in a hostname learned later', function () {
    $id = dbDeviceFind(null, 'AA:BB:CC:DD:EE:30', null);
    assertSame('', dbDeviceId($id)['hostname']);
    $f = TB_TMP.'/data/backups/host.dmp';
    file_put_contents($f, 'x');
    assertTrue(dbNewBackup($id, 'NoHost', '13.4.0',
        date('Y-m-d H:i:s'), 1, $f, 'AA:BB:CC:DD:EE:30', 0,
        'Learned-Later'));
    assertSame('learned-later', dbDeviceId($id)['hostname']);
});

// ---- adoption of identity-less rows (audit findings) ---------------

test('a row with no mac is adopted, not duplicated, when the mac shows up',
function () {
    dbDeviceAdd('Legacy', '10.1.1.1', '6.0.0', '', '', 0, '');
    $id = dbDeviceFind('10.1.1.1', null, null);
    assertNotEquals(false, $id);
    // the device now reports a real mac from the same address
    assertSame($id, dbDeviceFind('10.1.1.1', 'AA:BB:CC:DD:EE:70', 'legacy-host'),
        'the identity-less row at this ip should have been adopted');
});

test('a valid mac that misses does not hijack an identified row',
function () {
    dbDeviceAdd('Owner', '10.1.1.2', '1', '', 'AA:BB:CC:DD:EE:71', 0,
        'owner-host');
    // different device, same recycled address
    assertFalse(dbDeviceFind('10.1.1.2', 'AA:BB:CC:DD:EE:72', 'new-host'),
        'a known-good mac that missed must not fall back to the ip');
});

test('a junk mac is treated as no mac, not as a failed match',
function () {
    dbDeviceAdd('Junky', '10.1.1.3', '1', '', '', 0, '');
    $id = dbDeviceFind('10.1.1.3', null, null);
    assertSame($id, dbDeviceFind('10.1.1.3', 'not-a-mac', ''),
        'an unparsable mac should fall back like no mac at all');
});

tb_test_exit();
