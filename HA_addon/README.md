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
* verified compatible with tasmota v13/v14/v15
* add multi-language interface, auto-detects from browser
* add wled backups (only ip scanning, wled has limited mqtt support)
* fix single click restores
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
* Backup and restore Tasmota32 berry scripts alongside the config
* OpenBeken devices, backed up via their own api
* No duplicates (matched by Mac, then Hostname, then IP)
* Optional Hostname column
* Multi-language interface
* Optional debug logging to the container log

# To-Do
* Parse backup configs

[![ko-fi](https://www.ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/E1E21J93T)
