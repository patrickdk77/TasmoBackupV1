<?php
/*
 * Discovery through addTasmotaDevice, the path that actually creates
 * the duplicate rows users complain about (#89). Everything here runs
 * against the stub device.
 */

require_once(__DIR__.'/bootstrap.php');

$addr = tb_stub_start(array('status' => 200, 'dl' => 200));

// The stub reports mac AA:BB:CC:DD:EE:FF and hostname kitchen-1234.

test('discovering a new device adds exactly one row', function () use (
    $addr) {
    assertCount(0, dbDevices());
    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'expected one row, got: '.$msg);
    $d = dbDevices()[0];
    assertSame('AA:BB:CC:DD:EE:FF', $d['mac']);
    assertSame('kitchen-1234', $d['hostname']);
    assertSame($addr, $d['ip']);
});

test('discovering the same device again does not add a row', function ()
use ($addr) {
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'a rediscovery duplicated the device');
});

test('a device that moved to a new ip is matched, not duplicated',
function () use ($addr) {
    // Pretend the row was learned at an old lease, exactly what dhcp
    // does overnight. Discovery at the new address must update it.
    $id = dbDevices()[0]['id'];
    dbDeviceUpdate($id, null, '192.168.222.222');
    assertSame('192.168.222.222', dbDeviceId($id)['ip']);

    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'the dhcp move created a second row: '.$msg);
    assertSame($addr, dbDeviceId($id)['ip'],
        'the row kept the stale ip');
});

test('a device with no mac is still matched by hostname', function ()
use ($addr) {
    // Older Tasmota builds, and devices behind a segment where the mac
    // cannot be read, report no Mac at all.
    tb_stub_set(array('status' => 200, 'dl' => 200, 'nomac' => true));
    $id = dbDevices()[0]['id'];
    dbDeviceUpdate($id, null, '192.168.222.223');

    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(1, dbDevices(), 'matched on neither mac nor hostname: '.$msg);
    assertSame($addr, dbDeviceId($id)['ip']);
});

test('a genuinely different device does get its own row', function ()
use ($addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200,
        'mac' => 'AA:BB:CC:DD:EE:02', 'hostname' => 'bedroom-9999'));
    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertCount(2, dbDevices(),
        'a different mac and hostname should be a new device: '.$msg);
});

test('a device reporting neither mac nor hostname falls back to ip',
function () use ($addr) {
    tb_stub_set(array('status' => 200, 'dl' => 200, 'nomac' => true,
        'nohostname' => true));
    $before = count(dbDevices());
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertSame($before, count(dbDevices()),
        'the ip fallback should have matched a row at this address');
});

test('an unreachable address is reported, not added', function () {
    $before = count(dbDevices());
    $msg = addTasmotaDevice('127.0.0.1:1', 'admin', '', false, false,
        null);
    assertSame($before, count(dbDevices()),
        'an unreachable address was added anyway');
    assertTrue(strpos($msg, 'not found') !== false,
        'expected a not found message, got: '.$msg);
});

test('a device answering with garbage is not added', function () use (
    $addr) {
    tb_stub_set(array('status' => 200, 'body' => 'nonsense',
        'dl' => 200));
    $before = count(dbDevices());
    addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertSame($before, count(dbDevices()),
        'a device with an unreadable status was added');
});

test('a legacy row with no mac is adopted, not duplicated', function ()
use ($addr) {
    // What an install from before the mac column looks like: a row
    // holding the address and nothing else.
    tb_reset_devices();
    tb_stub_set(array('status' => 200, 'dl' => 200,
        'mac' => 'AA:BB:CC:DD:EE:03', 'hostname' => 'legacy-host'));
    dbDeviceAdd('LegacyRow', $addr, '6.0.0', '', '', 0, '');
    $id = dbDeviceFind($addr, null, null);
    $before = count(dbDevices());

    $msg = addTasmotaDevice($addr, 'admin', '', false, false, null);
    assertSame($before, count(dbDevices()),
        'the identity-less row should have been adopted: '.$msg);
    $d = dbDeviceId($id);
    assertSame('AA:BB:CC:DD:EE:03', $d['mac'], 'the mac was not filled in');
    assertSame('legacy-host', $d['hostname'],
        'the hostname was not filled in');
});

tb_test_exit();
