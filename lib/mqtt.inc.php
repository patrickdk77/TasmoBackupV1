<?php

require_once(__DIR__.'/phpMQTT2.php');


GLOBAL $mqtt_found;
$mqtt_found=[];

function setupMQTT($server, $port=1883, $user, $password)
{
    $mqtt = new phpMQTT($server, $port, 'TasmoBackup');
    //$mqtt = new Bluerhinos\phpMQTT($server, $port, 'TasmoBackup');

    if(!$mqtt->connect(true, NULL, $user, $password)) {
        tbDebug('mqtt', 'connect to '.$server.':'.$port.' FAILED'.
            ($user?' as user '.$user:' with no credentials'));
        return false;
    }
    tbDebug('mqtt', 'connected to '.$server.':'.$port);
    return $mqtt;
}

/*
 * Turns one device's collected STATUS replies into the ip / mac / name
 * triple the scan needs. Split out of getTasmotaMQTTScan so it can be
 * tested without a broker; $status is filled in by reference because
 * the caller passes the merged document on to addTasmotaDevice.
 *
 * The ip key has three spellings and none of the boundaries is 5.12.0,
 * which is what the comments here used to claim: "IP" up to v5.5.0,
 * "IPaddress" with a lowercase a for v5.5.1 to v5.6.1, then "IPAddress"
 * from v5.7.0 on. The middle spelling matched neither branch, so those
 * devices were dropped with "reported no ip address" even though their
 * http interface worked. tbStatusValue matches case insensitively,
 * which collapses the last two, and the alias list covers the first.
 *   src: sonoff/sonoff.ino:1783 @v5.5.0, :1808 @v5.6.1,
 *        sonoff/language/en-GB.h D_CMND_IPADDRESS @v5.7.0
 */
function mqttDeviceIdentity($found, $topic, &$status)
{
    global $settings;

    $tmp = array();
    $status = array_merge(jsonTasmotaDecode($found['status5']));
    $net = isset($status['StatusNET']) ? $status['StatusNET'] : null;
    if (($v=tbStatusValue($net, array('IPAddress','IP'))) !== '')
        $tmp['ip']=$v;
    if (($v=tbStatusValue($net, array('Mac'))) !== '')
        $tmp['mac']=$v;
    if(isset($found['status'])) {
        $status=array_merge($status,jsonTasmotaDecode($found['status']));
        if(isset($settings['use_topic_as_name']) && $settings['use_topic_as_name']=='F')
            $tmp['name']=trim(str_replace(array('/stat','stat/'),array('',''),$topic)," \t\r\n\v\0/");
        else {
            if (isset($status['Status']['Topic']))
                $tmp['name']=$status['Status']['Topic'];
        }
        if(!isset($settings['use_topic_as_name']) || $settings['use_topic_as_name']=='N') {
            if (isset($status['Status']['DeviceName']) && strlen(preg_replace('/\s+/', '',$status['Status']['DeviceName']))>0)
                $tmp['name']=$status['Status']['DeviceName'];
            else if (tasmotaFriendlyName($status) !== '')
                $tmp['name']=tasmotaFriendlyName($status);
        }
    }
    if(isset($found['status2'])) {
        $status=array_merge($status,jsonTasmotaDecode($found['status2']));
    }
    return $tmp;
}

function getTasmotaMQTTScan($mqtt,$topic,$user=false,$password=false,$slim=false)
{
    GLOBAL $mqtt_found,$settings;

    $topics['+/stat/STATUS'] = array('qos' => 0, 'function' => 'collectMQTTStatus');
    $topics['+/stat/STATUS2'] = array('qos' => 0, 'function' => 'collectMQTTStatus2');
    $topics['+/stat/STATUS5'] = array('qos' => 0, 'function' => 'collectMQTTStatus5');
    $topics['stat/+/STATUS'] = array('qos' => 0, 'function' => 'collectMQTTStatus');
    $topics['stat/+/STATUS2'] = array('qos' => 0, 'function' => 'collectMQTTStatus2');
    $topics['stat/+/STATUS5'] = array('qos' => 0, 'function' => 'collectMQTTStatus5');

    if(isset($settings['mqtt_topic_format'])) {
        $custom_topic=str_replace(array('%prefix%','%topic%'),array('stat','+'),$settings['mqtt_topic_format']);
        $topics[$custom_topic.'/STATUS'] = array('qos' => 0, 'function' => 'collectMQTTStatus');
        $topics[$custom_topic.'/STATUS2'] = array('qos' => 0, 'function' => 'collectMQTTStatus2');
        $topics[$custom_topic.'/STATUS5'] = array('qos' => 0, 'function' => 'collectMQTTStatus5');
    }
    tbDebug('mqtt', 'subscribing to: '.implode(', ', array_keys($topics)));
    $mqtt->subscribe($topics);

    $step1=$step2=$step3=$step4=$step5=$step6=true;
    $ts=time();
    while(($i=time()-$ts)<10) {
        if($step1 && !$slim) {
            $step1=false;
            // HomeAssistant swapped
            $mqtt->publish($topic.'/cmnd/STATUS','0');
            if($topic=='tasmotas')
                $mqtt->publish('sonoffs/cmnd/STATUS','0');
        }
        if($step2 && $i>0.60 && !$slim) {
            $step2=false;
            if(isset($settings['mqtt_topic_format'])) {
                $mqtt->publish(str_replace(array('%prefix%','%topic%'),array('stat',$topic),$settings['mqtt_topic_format']).'/STATUS','0');
                if($topic=='tasmotas')
                    $mqtt->publish(str_replace(array('%prefix%','%topic%'),array('stat','sonoffs'),$settings['mqtt_topic_format']).'/STATUS','0'); 
            }
        }
        if($step3 && $i>1.10 && !$slim) {
            $step3=false;
            $mqtt->publish('cmnd/'.$topic.'/STATUS','0');
            if($topic=='tasmotas')
                $mqtt->publish('cmnd/sonoffs/STATUS','0');
        }

        if($step4 && $i>2.10) {
            $step4=false;
            // Default
            $mqtt->publish($topic.'/cmnd/STATUS','5');
            if($topic=='tasmotas')
                $mqtt->publish('sonoffs/cmnd/STATUS','5');
        }
        if($step5 && $i>2.60) {
            $step5=false;
            if(isset($settings['mqtt_topic_format'])) {
                $mqtt->publish(str_replace(array('%prefix%','%topic%'),array('stat',$topic),$settings['mqtt_topic_format']).'/STATUS','5');
                if($topic=='tasmotas')
                    $mqtt->publish(str_replace(array('%prefix%','%topic%'),array('stat','sonoffs'),$settings['mqtt_topic_format']).'/STATUS','5');
            }
        }
        if($step6 && $i>3.20) {
            $step6=false;
            $mqtt->publish('cmnd/'.$topic.'/STATUS','5');
            if($topic=='tasmotas')
                $mqtt->publish('cmnd/sonoffs/STATUS','5');
        }
        $mqtt->proc(false);
        usleep(30000);
    }
    tbDebug('mqtt', 'listen window closed, '.count($mqtt_found).
        ' topic(s) replied: '.
        (count($mqtt_found)?implode(', ', array_keys($mqtt_found)):'none'));
    $results=[];
    foreach($mqtt_found as $topic => $found) {
        // Reset per device. Without this one device's ip and mac leak
        // onto the next entry that does not report its own.
        $tmp=array();
        $status=array('Topic'=>$topic);
        if(isset($found['status5'])) {
            $tmp=mqttDeviceIdentity($found, $topic, $status);
            if (!isset($tmp['ip'])) {
                tbDebug('mqtt', $topic.': replied to STATUS5 but reported '.
                    'no ip address, cannot reach it, skipping');
                continue;
            }
            tbDebug('mqtt', $topic.': usable, ip='.$tmp['ip'].' mac='.
                (isset($tmp['mac'])?$tmp['mac']:'(none)').' name='.
                (isset($tmp['name'])?$tmp['name']:'(none)'));
            if (isset($settings['autoadd_scan']) && $settings['autoadd_scan']=='Y') {
                addTasmotaDevice($tmp['ip'], $user, $password,false,$status);
            } else {
                $results[]=$tmp;
            }
        } else {
            // The gate that made issue #87 impossible to diagnose: a
            // device that answered STATUS but never STATUS5 is dropped
            // here without a word. STATUS5 is what carries the ip.
            tbDebug('mqtt', $topic.': answered ('.
                implode('+', array_keys($found)).
                ') but no STATUS5, so no ip address, skipping');
        }
    }
    tbDebug('mqtt', 'scan produced '.count($results).' device(s)');
    return $results;
}


function collectMQTTStatus($topic, $msg)
{
    GLOBAL $mqtt_found;

    //Get Name
    $name=false;
    if(isset($topic))
        $name=substr($topic,0,strrpos($topic,'/'));
    if($name) {
        $mqtt_found[$name]['status']=$msg;
    }
    return;
}

function collectMQTTStatus2($topic, $msg)
{
    GLOBAL $mqtt_found;

    //Get Version
    $name=false;
    if(isset($topic))
        $name=substr($topic,0,strrpos($topic,'/'));
    if($name) {
        $mqtt_found[$name]['status2']=$msg;
    }
    return;
}

function collectMQTTStatus5($topic, $msg)
{
    GLOBAL $mqtt_found;

    //Get IP
    $name=false;
    if(isset($topic))
        $name=substr($topic,0,strrpos($topic,'/'));
    if($name) {
        $mqtt_found[$name]['status5']=$msg;
    }
    return;
}

