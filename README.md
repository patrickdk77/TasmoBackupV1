# TasmoBackupV1
Backup the configs of all your Tasmota devices

# Latest Changes
* add openbeken support, discovered by scan and backed up via its own api
* backup and restore tasmota32 berry scripts alongside the config
* add checkboxes with select-all for bulk delete, download and send-command
* bulk download produces one zip holding the latest backup of each device
* add backup locking, a locked backup is never deleted until unlocked
* scheduled pruning skips locked backups
* add debug logging setting, logs http, mqtt, scan, backup and restore
* fix downloads always named .dmp, now follows the stored file type
* fix duplicate devices created when a device's ip changed (now matched by mac, then hostname, then ip)
* add hostname column, toggle it like the mac column
* fix failed backups being reported as successful (and pruning good backups afterward)
* fix scheduled backups doing nothing until backup-all min hours had been saved once
* fix dark mode settings/lock icons not showing
* restore now reports success or failure instead of no feedback
* fix restore silently failing on tasmota v15.5+ (referer check now enforced by default)
* verified compatible with tasmota v13/v14/v15 http and mqtt api
* add multi-language interface, auto-detects from browser
* seed 28 language catalogues (unreviewed machine translations, see Translations below)
* add docker buildx build option, cross builds without qemu binaries
* add automated test suite, see Development below
* fixup sorting/datatables
* fixed restores
* added wled backups
* fix zero size backup bug
* Add referer for tasmota new security feature
* fixed mqtt overscanning
* changed backup downloads to use device name, version, and date
* added alert if device isn't getting backed up visually
* fixed sorting on ip and version
* fix bug when doing mqtt scanning
* tasmota 9.0 status change
* recording mac addresses

# Features
* Add single devices
* Discover devices
* Backup single devices
* Backup all devices
* Remove devices
* Download individual backups
* Bulk select devices or backups to delete, download or lock
* Send a console command to several devices at once
* Lock a backup so it is never deleted, by hand or by the scheduled prune
* No duplicates (matched by Mac, then Hostname, then IP)
* Optional Hostname column
* Multi-language interface
* Optional debug logging to the container log

# WLED
Backups of wled are done via downloading the cfg.json and presets.json and putting them in a zip file.
the limited mqtt support in wled means there is no way to automatically scan, so only ip scanning is supported

# OpenBeken
OpenBeken has no settings dump to download, so the backup is built from its own
api rather than anything Tasmota shaped. It records the gpio layout that makes
the device work (`/api/pins` roles and channels) plus the startup command and
the identity fields from `/api/info`, written out as json. A restore posts the
roles, channels and startup command back to `/api/pins`, which OpenBeken applies
immediately without a reboot. Discovery is by ip scan, the same as WLED.

# Tasmota32 Berry scripts
An esp32 running Tasmota has a filesystem, and anything on it (`autoexec.be` and
whatever it pulls in) is part of how the device behaves but is not in the config
dump. When a device has `.be` files the backup becomes a zip holding `config.dmp`
plus a `files/` directory with the scripts, otherwise it stays a plain `.dmp`
exactly as before, so esp8266 devices and every existing backup are unaffected.
A restore uploads the scripts first and the config last, so the scripts are
already in place when the device reboots and runs `autoexec.be`. Turn it off
with Settings > Backup Tasmota32 Berry scripts.


# Install via Hass.io aka HomeAssistant Supervisor
Go into home assisant, then the supervisor
Click on the Add-On Store
paste in http://github.com/danmed/TasmoBackupV1 into the Add new repository
via url box, and click add
Scroll down near the bottom and locate TasmoBackup

More info at: https://www.home-assistant.io/hassio/installing_third_party_addons/

# Install via Docker-compose
```yaml
version: '2'
services:
    tasmobackup:
        ports:
            - '8259:80'
        volumes:
            - ./data:/var/www/html/data
        environment:
            # MYSQL env's are not needed if you are using sqlite
            - MYSQL_SERVER=IPADDRESS
            - MYSQL_USERNAME=USERNAME
            - MYSQL_PASSWORD=PASSWORD
            # change below to mysql if you don't want to use sqlite
            # you will need to have a mysql server (set above) with a blank database already created.
            - DBTYPE=sqlite
            # if using Mysql remove the data/ from the below line
            # if using Sqlite the data/ is required!
            - DBNAME=data/tasmobackup
        container_name: TasmoBackup
        image: 'danmed/tasmobackupv1'
```
# Docker Run

SQLITE: 
```
docker run -d -p 8259:80 -v ./data:/var/www/html/data -e DBTYPE=sqlite -e DBNAME=data/tasmobackup --name TasmoBackup danmed/tasmobackupv1
```
Note : pay attention to the difference's between the sqlite and mysql database names.

MYSQL:
```
docker run -d -p 8259:80 -v ./data:/var/www/html/data -e DBTYPE=mysql -e MYSQL_SERVER=192.168.2.10 -e MYSQL_USERNAME=root -e MYSQL_PASSWORD=password -e DBNAME=tasmobackup --name TasmoBackup danmed/tasmobackupv1
```

# Install via Raw PHP
```
git clone https://github.com/danmed/TasmoBackupV1
cd TasmoBackupV1
mkdir data
chown www-data data
cp config.inc.php.example data/config.inc.php
```

Edit data/config.inc.php if you wish to change to using mysql database
instead of sqlite.
Make sure the data directory is owned by the user php runs as, or it will
not be able to save your backups or create/update the sqlite file

Run the upgrade.php script to initialize your new database, or to upgrade
your existing one when changing versions.

# Scheduled Backups
* backupall.php exists to do literally that.. Schedule this with your chosen means (nodered, curl, scheduled tasks etc)

# Screenshots

![Alt text](https://i.imgur.com/2swMzG9.png)
![Alt text](https://i.imgur.com/27Pm7lH.png)
![Alt text](https://i.imgur.com/QReTLxp.png)
![Alt text](https://i.imgur.com/e2ruv2t.png)

# Development

## Tests

    make test        the offline test suite, runs in the same php image the container ships
    make test-live    read only checks against real devices on your network, nothing is
                       written or restored, see tests/test_live.php for TB_LIVE_RANGE /
                       TB_LIVE_DEVICE

## Building

    make build     cross builds with qemu-user-static, the original method
    make buildx    cross builds with docker buildx --platform, no qemu binaries needed

Both produce the same per-architecture images and tags, see the Makefile
and hooks/ for details.

# Translations

The interface is translated with gettext. Catalogues live in
`locale/<lang>/LC_MESSAGES/tasmobackup.po`, one directory per language,
using the same language codes Home Assistant uses.

Pick a language in Settings, or leave it on `Automatic (browser)` and
it follows the browser's `Accept-Language`. Anything not translated
falls back to English, so a partial catalogue is perfectly usable.

## Helping translate

Open the `.po` file for your language in [Poedit](https://poedit.net/)
or any gettext editor and fill in the entries. Entries marked
**fuzzy** are machine translated starting points that nobody has
checked yet: they are ignored at runtime until you confirm them, so
correcting and unmarking a fuzzy entry is what puts it on screen.

You never need to touch PHP to add or finish a language.

For a language that does not exist yet, copy `locale/tasmobackup.pot`
to `locale/<lang>/LC_MESSAGES/tasmobackup.po` and translate from there.

## For developers

    make pot        refresh locale/tasmobackup.pot from the source
    make update-po  merge new and changed strings into every catalogue
    make mo         compile catalogues for a local, non docker run

The docker image compiles the catalogues during the build, so a normal
install needs none of the above. Wrap new user facing strings in `t()`,
or `tn()` when they count something, and run `make pot`. `make test`
fails if the template has drifted from the source.

# To-Do
* Parse backup configs

# Support
[![ko-fi](https://www.ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/E1E21J93T)
