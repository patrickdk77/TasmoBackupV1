# TasmoBackupV1
Backup the configs of all your Tasmota devices


# Latest Changes
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
* No duplicates (matched by Mac, then Hostname, then IP)
* Optional Hostname column
* Multi-language interface

# To-Do
* Parse backup configs

[![ko-fi](https://www.ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/E1E21J93T)
