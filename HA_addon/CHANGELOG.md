## ⬆️ Fixes

- Add OpenBeken support, discovered by scan and backed up via its own api
- Backup and restore Tasmota32 berry scripts alongside the config
- Add checkboxes with select-all for bulk delete, download and send-command
- Bulk download produces one zip holding the latest backup of each device
- Add backup locking, a locked backup is never deleted until unlocked
- Scheduled pruning skips locked backups
- Add Debug Logging setting, logs http, mqtt, scan, backup and restore
- Fix downloads always named .dmp, now follows the stored file type
