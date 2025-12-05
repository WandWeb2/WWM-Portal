# WWM Portal - Emergency Access Quick Reference

## Database Disconnected? Access Logs Anyway!

### Immediate Actions

#### 1️⃣ Check if API is Alive
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"debug_test","test":"api_connection","token":"ADMIN_TOKEN"}'
```

#### 2️⃣ Check Database Status
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"debug_test","test":"database_status","token":"ADMIN_TOKEN"}'
```

#### 3️⃣ View System Logs (DB + File Fallback)
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"get_system_logs","token":"ADMIN_TOKEN"}'
```

#### 4️⃣ Full Emergency Status
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"debug_test","test":"emergency_status","token":"ADMIN_TOKEN"}'
```

---

## What's Happening?

| Feature | How It Works |
|---------|------------|
| **Fallback Logging** | When DB fails, logs automatically write to `/tmp/wandweb_system.log` |
| **Dual Log Sources** | `get_system_logs` returns both DB logs AND file fallback logs combined |
| **DB Status Detection** | Every diagnostic test checks and reports DB connection status |
| **Error Persistence** | Errors logged even if database is down |

---

## File Locations

| Item | Location |
|------|----------|
| **System Logs (Fallback)** | `/tmp/wandweb_system.log` |
| **Database Config** | `/workspaces/WWM-Portal/private/secrets.php` |
| **API Router** | `/workspaces/WWM-Portal/api/portal_api.php` |
| **Emergency Guide** | `/workspaces/WWM-Portal/EMERGENCY_DIAGNOSTICS.md` |

---

## Common Issues & Fixes

### "No Data Showing"
1. Run `emergency_status` test
2. Check if DB status shows "disconnected"
3. If disconnected:
   - Check DB service: `sudo systemctl status mysql`
   - Restart if needed: `sudo systemctl restart mysql`
   - Re-run `database_status` test

### "Can't View Logs"
1. File logs should always be available at `/tmp/wandweb_system.log`
2. Check file permissions: `ls -la /tmp/wandweb_system.log`
3. If missing, trigger a test to create: `api_connection` test
4. Use `get_system_logs` endpoint to see via API

### "Database Connection Errors"
1. Run `database_status` test to see exact error
2. Verify credentials in `secrets.php`
3. Test MySQL directly:
   ```bash
   mysql -h HOST -u USER -p -e "SELECT 1;"
   ```
4. Check MySQL service: `sudo systemctl status mysql`

---

## Recovery Steps

```bash
# 1. SSH to server
ssh user@server

# 2. Check system logs locally
tail -50 /tmp/wandweb_system.log

# 3. Check MySQL status
sudo systemctl status mysql

# 4. If MySQL is down, restart it
sudo systemctl restart mysql

# 5. Run database status test via API
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"debug_test","test":"database_status","token":"ADMIN_TOKEN"}'

# 6. If still down, check MySQL logs
sudo tail -100 /var/log/mysql/error.log

# 7. Verify database exists
mysql -u root -p -e "SHOW DATABASES;"
```

---

## Key Improvements

✅ Logs are **never lost** - file fallback saves them even if DB is down  
✅ **Full visibility** - See both DB and file logs via API  
✅ **Auto-detection** - System automatically detects connection failure  
✅ **No data modification needed** - Emergency access is read-only  
✅ **Real-time monitoring** - Check status anytime from anywhere  

---

## Need Help?

1. Check `EMERGENCY_DIAGNOSTICS.md` for detailed procedures
2. Review `/tmp/wandweb_system.log` for error patterns
3. Run all diagnostic tests in sequence
4. Verify database credentials match actual database

**Important:** Admin token is required for all emergency access endpoints.

