## ⬆️ Fixes

- Fix duplicate devices created when a device's ip changed, now matched by mac then hostname before ip
- Add Hostname column, toggle it like the Mac column
- Fix failed backups being reported as successful, and pruning good backups afterward
- Fix scheduled backups doing nothing until Backup-All Min Hours had been saved once
- Fix dark mode settings/lock icons not showing
- Restore now reports success or failure instead of no feedback
- Fix restore silently failing on Tasmota v15.5+, referer check now enforced by default
- Verified compatible with Tasmota v13/v14/v15
- Add multi-language interface, Settings > Language

