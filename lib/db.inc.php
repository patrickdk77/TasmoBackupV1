<?php

require_once(__DIR__.'/../data/config.inc.php');

global $db_handle;
global $settings;
global $db_upgrade;

if ($DBType=='mysql') {
    $db_handle = new \PDO('mysql:host='.$DBServer.';dbname='.$DBName, $DBUser, $DBPassword);
    $GLOBALS['DBType']='mysql';
}
if ($DBType=='sqlite') {
    if (!isset($DBName)) {
        $DBName = 'data/tasmobackupdb';
    }
    if (substr_compare($DBName,'data/',0,5)==0) {
        $DBName = __DIR__.'/../'.$DBName;
    }
    $db_handle = new \PDO('sqlite:'.$DBName.'.sqlite3');
    $GLOBALS['DBType']='sqlite';
}

if ($db_handle && $db_upgrade) {
    if ($GLOBALS['DBType']=='mysql') {
        $db_handle->exec("CREATE TABLE IF NOT EXISTS devices (
            id int(11) AUTO_INCREMENT PRIMARY KEY NOT NULL,
            name varchar(128) NOT NULL,
            ip varchar(64) NOT NULL,
            mac varchar(32) NOT NULL,
            hostname varchar(128) NOT NULL DEFAULT '',
            type int(4) NOT NULL DEFAULT 0,
            version varchar(128) NOT NULL,
            lastbackup datetime DEFAULT NULL,
            noofbackups int(11) DEFAULT NULL,
            password varchar(128) DEFAULT NULL )
        ");

        $db_handle->exec("CREATE TABLE IF NOT EXISTS backups (
            id bigint(20) AUTO_INCREMENT PRIMARY KEY NOT NULL,
            deviceid int(11) NOT NULL,
            name varchar(128) NOT NULL,
            version varchar(128) NOT NULL,
            date datetime DEFAULT NULL,
            filename varchar(1080),
            data text,
            INDEX (deviceid,date) )
        ");

        $db_handle->exec("CREATE TABLE IF NOT EXISTS settings (
            name varchar(128) PRIMARY KEY NOT NULL,
            value varchar(255) NOT NULL )
        ");

        $stm=$db_handle->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='".$GLOBALS['DBName']."' AND TABLE_NAME='devices' AND COLUMN_NAME='mac';");
        $stm->execute();
        $cnt=intval($stm->fetchColumn());
        if($cnt<1) {
            $db_handle->exec("ALTER TABLE devices ADD COLUMN mac varchar(32) NOT NULL DEFAULT '' AFTER ip;");
        }

        $stm=$db_handle->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='".$GLOBALS['DBName']."' AND TABLE_NAME='devices' AND COLUMN_NAME='type';");
        $stm->execute();
        $cnt=intval($stm->fetchColumn());
        if($cnt<1) {
            $db_handle->exec("ALTER TABLE devices ADD COLUMN type int(3) NOT NULL DEFAULT 0 AFTER mac;");
        }

        $stm=$db_handle->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='".$GLOBALS['DBName']."' AND TABLE_NAME='devices' AND COLUMN_NAME='hostname';");
        $stm->execute();
        $cnt=intval($stm->fetchColumn());
        if($cnt<1) {
            $db_handle->exec("ALTER TABLE devices ADD COLUMN hostname varchar(128) NOT NULL DEFAULT '' AFTER mac;");
        }
    }

    if ($GLOBALS['DBType']=='sqlite') {
        $db_handle->exec("CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name varchar(128) NOT NULL,
            ip varchar(64) NOT NULL,
            mac varchar(32) NOT NULL,
	    hostname varchar(128) NOT NULL DEFAULT '',
	    type INTEGER NOT NULL DEFAULT 0,
	    version varchar(128) NOT NULL,
	    lastbackup datetime DEFAULT NULL,
	    noofbackups INTEGER DEFAULT NULL,
	    password varchar(128) DEFAULT NULL )
        ");

        $db_handle->exec("CREATE TABLE IF NOT EXISTS backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            deviceid INTEGER NOT NULL,
            name varchar(128) NOT NULL,
            version varchar(128) NOT NULL,
            date datetime DEFAULT NULL,
            filename varchar(1080),
            data text )
        ");

        $db_handle->exec("CREATE INDEX IF NOT EXISTS backupsdeviceid
            ON backups(deviceid, date)
        ");

        $db_handle->exec("CREATE TABLE IF NOT EXISTS settings (
            name varchar(128) PRIMARY KEY NOT NULL,
            value varchar(255) NOT NULL )
        ");

        $curstate = error_reporting();
        error_reporting(0);
        // php8 defaults PDO to ERRMODE_EXCEPTION, so a column that is
        // already there throws instead of just warning
        try {
            @$db_handle->exec("ALTER TABLE devices ADD COLUMN mac varchar(32) NOT NULL DEFAULT ''");
        } catch (PDOException $e) {
        }
        try {
            @$db_handle->exec("ALTER TABLE devices ADD COLUMN type INTEGER NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
        }
        try {
            @$db_handle->exec("ALTER TABLE devices ADD COLUMN hostname varchar(128) NOT NULL DEFAULT ''");
        } catch (PDOException $e) {
        }
        error_reporting($curstate);
    }

    // Rows written before macs were normalised can hold any spelling,
    // aa-bb-cc-dd-ee-ff or aabbccddeeff. Lookups fold case but cannot
    // fold separators in portable sql, so bring the stored values into
    // the one shape now. Cheap, only runs on the upgrade path.
    if ($db_handle) {
        $stm=$db_handle->prepare("select id,mac from devices where mac <> ''");
        if($stm->execute()) {
            $fix=array();
            while($row=$stm->fetch(PDO::FETCH_ASSOC)) {
                $norm=dbNormalizeMac($row['mac']);
                if($norm !== '' && $norm !== $row['mac'])
                    $fix[$row['id']]=$norm;
            }
            if(count($fix)>0) {
                $upd=$db_handle->prepare("update devices set mac = :mac where id = :id");
                foreach($fix as $id => $norm) {
                    $upd->bindValue(':mac',$norm,PDO::PARAM_STR);
                    $upd->bindValue(':id',$id,PDO::PARAM_INT);
                    $upd->execute();
                }
            }
        }
    }
}

if ($db_handle) {
    $stm = $db_handle->prepare("select name,value from settings");
    if($stm && $stm->execute()) {
        while($result=$stm->fetch(PDO::FETCH_ASSOC)) {
            $settings[$result['name']]=$result['value'];
        }
    }
}

if(!isset($settings['backup_folder']))
    $settings['backup_folder']='data/backups/';
else if(substr($settings['backup_folder'],-1) !== '/')
    $settings['backup_folder'] .= '/';

if(isset($settings['mqtt_topic_format']))
    $settings['mqtt_topic_format']=trim($settings['mqtt_topic_format']," \t\n\r\0\v/");

function dbSettingsUpdate($name,$value)
{
    global $db_handle;
    global $settings;
    $stm = $db_handle->prepare("REPLACE INTO settings(name,value) VALUES(:name,:value)");
    if(!$stm->execute(array(':value'=>$value,':name'=>$name)))
        return false;
    $settings[$name]=$value;
    return true;
}

/*
 * Device identity.
 *
 * A device is matched on mac first, then hostname, then ip. Only the
 * mac and the hostname survive a dhcp lease change, the ip is the last
 * resort and is what used to hand out a second row for a device that
 * simply moved.
 *
 * Both are compared case insensitively. Tasmota reports the mac
 * uppercase, WLED reports it lowercase and without separators, and
 * hostnames are case insensitive by definition, so a stored value can
 * easily differ in case from the one being looked up. sqlite compares
 * text case sensitively, MySQL usually does not, so the comparison is
 * folded here to make both backends behave the same.
 */

function dbNormalizeMac($mac)
{
    if(!isset($mac))
        return '';
    $mac=strtoupper(preg_replace('/[^0-9A-Fa-f]/','',$mac));
    if(strlen($mac)!=12)
        return '';
    return implode(':',str_split($mac,2));
}

function dbNormalizeHostname($hostname)
{
    if(!isset($hostname))
        return '';
    return strtolower(trim($hostname," \t\n\r\0\x0B."));
}

function dbDeviceFind($ip=NULL,$mac=NULL,$hostname=NULL)
{
    global $db_handle;

    $mac=dbNormalizeMac($mac);
    $hostname=dbNormalizeHostname($hostname);
    $haveip=(isset($ip) && $ip !== "");

    if($mac !== "") {
        $stm = $db_handle->prepare("select id from devices where upper(mac) = :mac");
        $stm->bindValue(':mac', $mac, PDO::PARAM_STR);
        if ($stm->execute()) {
            if (($data=$stm->fetchColumn()) > 0 )
                return $data;
        }
    }

    if($hostname !== "") {
        // Hostnames are not guaranteed unique, two devices can carry
        // the same one. A hostname match is only trusted when it does
        // not contradict a mac we already know, otherwise the pair
        // would collapse onto one row and overwrite each other.
        $stm = $db_handle->prepare("select id from devices
            where lower(hostname) = :hostname
            and (:mac = '' or mac = '' or mac is null
                 or upper(mac) = :mac)");
        $stm->bindValue(':hostname', $hostname, PDO::PARAM_STR);
        $stm->bindValue(':mac', $mac, PDO::PARAM_STR);
        if ($stm->execute()) {
            if (($data=$stm->fetchColumn()) > 0 )
                return $data;
        }
    }

    if($mac !== "" || $hostname !== "") {
        // The device said who it is and no row claims that identity.
        // The only row this can still be is one carrying no identity of
        // its own, an install from before the mac column or a device
        // that never reported one. Adopt that row and let the caller
        // fill it in. Anything else at this address is a different
        // device that inherited a freed lease, and it must not take
        // over the row that already belongs to someone.
        if($haveip) {
            $stm = $db_handle->prepare("select id from devices where ip = :ip
                and (mac = '' or mac is null)
                and (hostname = '' or hostname is null)");
            $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
            if ($stm->execute()) {
                if (($data=$stm->fetchColumn()) > 0)
                    return $data;
            }
        }
        return false;
    }

    // Nothing but an address to go on.
    if($haveip) {
        $stm = $db_handle->prepare("select id from devices where ip = :ip");
        $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
        if ($stm->execute()) {
            if (($data=$stm->fetchColumn()) > 0)
                return $data;
        }
    }
    return false;
}

function dbDeviceExist($ip=NULL,$mac=NULL,$hostname=NULL)
{
    return dbDeviceFind($ip,$mac,$hostname) !== false;
}

function dbDeviceIp($ip)
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from devices where ip = :ip");
    $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbDeviceMac($mac)
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from devices where upper(mac) = :mac");
    $stm->bindValue(':mac', dbNormalizeMac($mac), PDO::PARAM_STR);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbDeviceHostname($hostname)
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from devices where lower(hostname) = :hostname");
    $stm->bindValue(':hostname', dbNormalizeHostname($hostname), PDO::PARAM_STR);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbDeviceId($id)
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from devices where id = :id");
    $stm->bindValue(':id', $id, PDO::PARAM_INT);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetch(PDO::FETCH_ASSOC);
}

function dbDevices()
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from devices");
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbBackupId($id)
{
    global $db_handle;

    $stm = $db_handle->prepare("select * from backups where id = :id ");
    $stm->bindValue(':id', $id, PDO::PARAM_INT);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetch(PDO::FETCH_ASSOC);
}

function dbBackupList($id,$days=0)
{
    global $db_handle;

    $days=intval($days);
    $datecond='';
    if($days>0) {
        $date = date('Y-m-d H:i:s',time()-(86400*$days));
        $datecond = ' and date < "'.$date.'" ';
    }
    $stm = $db_handle->prepare("select * from backups where deviceid = :id ".$datecond." order by date desc");
    $stm->bindValue(':id', $id, PDO::PARAM_INT);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbBackupCount($id)
{
    global $db_handle;

    $stm = $db_handle->prepare("select count(*) from backups where deviceid = :id");
    $stm->bindValue(':id', $id, PDO::PARAM_INT);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchColumn();
}

function dbBackupTrim($id,$days,$count,$all=false)
{
    global $db_handle;

    $days=intval($days);
    $count=intval($count);
    if($days==0 && $count==0 && !$all)
        return true;

    $result=dbBackupList($id,$days);
    if(!is_array($result))
        return false;
    if(count($result)<1)
        return true;
    if($count>0) {
        $backups=dbBackupCount($id);
        $count=($backups-$count); // Number to save - total backups - Number over age = number to remove
        if($count>count($result))
            $count=count($result);
    } else {
        $count=count($result);
    }
    if($count>0) {
        for(;$count>0;$count--) {
            $backup=array_pop($result);
            unlink($backup['filename']);
            $stm = $db_handle->prepare("delete from backups where id = :id");
            $stm->execute(array(":id" => $backup['id']));
        }
        dbDeviceBackups($id);
    }
}

function dbBackupDel($id)
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from backups where id = :id");
    $stm->bindValue(':id',$id, PDO::PARAM_INT);
    if(!$stm->execute())
        return false;
    $row=$stm->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)) // nothing to delete, do not report success
        return false;
    if(isset($row['filename']))
        unlink($row['filename']);
    $stm = $db_handle->prepare("delete from backups where id = :id");
    $stm->bindValue(':id',$id, PDO::PARAM_INT);
    return $stm->execute();
}

function dbDevicesListBackups($count)
{
    global $db_handle;
    $stm = $db_handle->prepare("select id from devices where noofbackups > :count ");
    $stm->bindValue(':count', $count, PDO::PARAM_INT);
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbDevicesSort()
{
    global $db_handle;
    $stm = $db_handle->prepare("select * from devices order by name desc");
    if (!$stm->execute()) {
        return false;
    }
    return $stm->fetchAll(PDO::FETCH_ASSOC);
}

function dbDeviceAdd($name, $ip, $version, $password, $mac, $type=0, $hostname='')
{
    global $db_handle;
    $stm = $db_handle->prepare("INSERT INTO devices (name,ip,mac,hostname,type,version,password) VALUES (:name, :ip, :mac, :hostname, :type, :version, :password)");
    $stm->bindValue(':name', $name, PDO::PARAM_STR);
    $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stm->bindValue(':mac', dbNormalizeMac($mac), PDO::PARAM_STR);
    $stm->bindValue(':hostname', dbNormalizeHostname($hostname), PDO::PARAM_STR);
    $stm->bindValue(':type', $type, PDO::PARAM_INT);
    $stm->bindValue(':version', $version, PDO::PARAM_STR);
    $stm->bindValue(':password', $password, PDO::PARAM_STR);

    return $stm->execute();
}

function dbDeviceRename($oldip, $name, $ip, $password, $mac=NULL)
{
    global $db_handle;

    // Resolve a single row first. Updating straight off the address
    // rewrote every row that happened to share the old ip, and it
    // reported success even when it matched nothing at all.
    $id=dbDeviceFind($oldip,$mac,NULL);
    if($id===false)
        return false;

    $stm = $db_handle->prepare("UPDATE devices SET name = :name, ip = :ip, password = :password WHERE id = :id");
    $stm->bindValue(':name', $name, PDO::PARAM_STR);
    $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stm->bindValue(':password', $password, PDO::PARAM_STR);
    $stm->bindValue(':id', $id, PDO::PARAM_INT);

    return $stm->execute();
}

function dbDeviceDel($ip)
{
    global $db_handle;
    $stm = $db_handle->prepare("select id from devices where ip = :ip");
    $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
    if (!$stm->execute())
        return false;
    $id=intval($stm->fetchColumn());
    if($id==0)
        return false;
    dbBackupTrim($id,0,0,true);
    $stm = $db_handle->prepare("delete from devices where id = :id");
    $stm->bindValue(':id', $id, PDO::PARAM_INT);
    return $stm->execute();
}

function dbDeviceUpdate($id=NULL,$name=NULL,$ip=NULL,$version=NULL,$password=NULL,$mac=NULL,$type=NULL,$hostname=NULL)
{
    global $db_handle;

    // Built as a list and joined, the old string concatenation left a
    // trailing comma whenever password was the field left out.
    if(isset($mac))
        $mac=dbNormalizeMac($mac);
    if(isset($hostname))
        $hostname=dbNormalizeHostname($hostname);

    $set=array();
    if(isset($version))
        $set[]='version = :version';
    if(isset($ip))
        $set[]='ip = :ip';
    if(isset($name))
        $set[]='name = :name';
    if(isset($mac) && $mac !== '')
        $set[]='mac = :mac';
    if(isset($hostname) && $hostname !== '')
        $set[]='hostname = :hostname';
    if(isset($type))
        $set[]='type = :type';
    if(isset($password))
        $set[]='password = :password';
    if(count($set)<1)
        return false;
    $setcond=implode(', ',$set);
    if(isset($id)) {
        $stm = $db_handle->prepare('UPDATE devices SET '.$setcond.' WHERE id = :id');
    } else if(isset($mac) && $mac !== '' && isset($ip)) {
        $stm = $db_handle->prepare('UPDATE devices SET '.$setcond.' WHERE (upper(mac) = :mac ) or (ip = :ip and mac="")');
    }
    if(isset($stm)) {
        if(isset($version))
            $stm->bindValue(':version', $version, PDO::PARAM_STR);
        if(isset($password))
            $stm->bindValue(':password', $password, PDO::PARAM_STR);
        if(isset($mac) && $mac !== '')
            $stm->bindValue(':mac', $mac, PDO::PARAM_STR);
        if(isset($hostname) && $hostname !== '')
            $stm->bindValue(':hostname', $hostname, PDO::PARAM_STR);
        if(isset($type))
            $stm->bindValue(':type', $type, PDO::PARAM_INT);
        if(isset($name))
            $stm->bindValue(':name', $name, PDO::PARAM_STR);
        if(isset($ip))
            $stm->bindValue(':ip', $ip, PDO::PARAM_STR);
        if(isset($id))
            $stm->bindValue(':id', $id, PDO::PARAM_INT);
        return $stm->execute();
    }
    return false;
}

function dbDeviceBackups($id,$date=NULL,$version=NULL,$name=NULL,$mac=NULL,$type=NULL,$hostname=NULL)
{
    global $db_handle;

    $count = dbBackupCount($id);
    if(isset($mac))
        $mac=dbNormalizeMac($mac);
    if(isset($hostname))
        $hostname=dbNormalizeHostname($hostname);

    $set=array();
    if(isset($version))
        $set[]='version = :version';
    if(isset($date))
        $set[]='lastbackup = :date';
    if(isset($name))
        $set[]='name = :name';
    if(isset($mac) && $mac !== '')
        $set[]='mac = :mac';
    if(isset($hostname) && $hostname !== '')
        $set[]='hostname = :hostname';
    if(isset($type))
        $set[]='type = :type';
    $set[]='noofbackups = :noofbackups';
    $stm = $db_handle->prepare('UPDATE devices SET '.implode(', ',$set).' WHERE id = :id');
    if(isset($version))
        $stm->bindValue(':version', $version, PDO::PARAM_STR);
    if(isset($mac) && $mac !== '')
        $stm->bindValue(':mac', $mac, PDO::PARAM_STR);
    if(isset($hostname) && $hostname !== '')
        $stm->bindValue(':hostname', $hostname, PDO::PARAM_STR);
    if(isset($type))
        $stm->bindValue(':type', $type, PDO::PARAM_INT);
    if(isset($name))
        $stm->bindValue(':name', $name, PDO::PARAM_STR);
    if(isset($date))
        $stm->bindValue(':date', $date, PDO::PARAM_STR);
    $stm->bindValue(':noofbackups', $count, PDO::PARAM_STR);
    $stm->bindValue(':id', $id, PDO::PARAM_INT);
    return $stm->execute();
}

function dbNewBackup($id, $name, $version, $date, $noofbackups, $filename, $mac=NULL, $type=NULL, $hostname=NULL)
{
    global $db_handle;
    if(!isset($version) || strlen($version)<2) { $version='Unknown'; }
    $stm = $db_handle->prepare("INSERT INTO backups(deviceid,name,version,date,filename) VALUES(:deviceid, :name, :version, :date, :filename)");
    $stm->bindValue(':deviceid', $id, PDO::PARAM_INT);
    $stm->bindValue(':name', $name, PDO::PARAM_STR);
    $stm->bindValue(':version', $version, PDO::PARAM_STR);
    $stm->bindValue(':date', $date, PDO::PARAM_STR);
    $stm->bindValue(':filename', $filename, PDO::PARAM_STR);
    if (!$stm->execute()) {
        trigger_error("insert error: ".$stm->errorInfo()[2], E_USER_NOTICE);
        return false;
    }
    return dbDeviceBackups($id,$date,$version,$name,$mac,$type,$hostname);
}

