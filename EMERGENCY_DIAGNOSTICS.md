# Emergency Diagnostics & Database Recovery Guide

## Overview
The WWM Portal API now includes comprehensive fallback logging and diagnostic capabilities that work even when the database is disconnected. This ensures admins can troubleshoot issues without losing visibility into system health.

---

## New Capabilities

### 1. **Fallback File Logging**
When the database is unavailable, all system events are automatically logged to:
```
/tmp/wandweb_system.log
```

**Features:**
- Timestamps all events: `[YYYY-MM-DD HH:MM:SS]`
- Captures error levels: `[info|success|warning|error]`
- Persists across API restarts
- Accessible via HTTP API even when DB is down

---

## API Endpoints for Emergency Access

### Get System Logs (with DB status)
```bash
POST /api/portal_api.php
{
  "action": "get_system_logs",
  "token": "your_admin_token"
}
```

**Response includes:**
- Logs from both database AND file fallback
- Database connection status
- Combined log stream sorted by timestamp
- Source indication (db vs file_fallback)

---

### Emergency System Status Check
```bash
POST /api/portal_api.php
{
  "action": "debug_test",
  "test": "emergency_status",
  "token": "your_admin_token"
}
```

**Returns:**
- PHP version & server software
- Database status (connected/disconnected with error message)
- Log file location & accessibility
- Log file size
- Temporary directory writability
- Last 10 recent log entries
- API uptime status

---

### Database Connection Test
```bash
POST /api/portal_api.php
{
  "action": "debug_test",
  "test": "database_status",
  "token": "your_admin_token"
}
```

**Returns:**
- `status`: "connected" or "disconnected"
- `message`: Error message if disconnected
- `log_count`: Number of logs in database (if connected)
- Timestamp of check

---

### API Connection Test
```bash
POST /api/portal_api.php
{
  "action": "debug_test",
  "test": "api_connection",
  "token": "your_admin_token"
}
```

**Returns:**
- Confirms API is responsive and processing requests normally

---

## Troubleshooting Database Issues

### Step 1: Check System Status
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "debug_test",
    "test": "emergency_status",
    "token": "admin_token"
  }'
```

### Step 2: Check Specific DB Connection
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "debug_test",
    "test": "database_status",
    "token": "admin_token"
  }'
```

### Step 3: Review Recent Logs
```bash
curl -X POST https://your-domain/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "get_system_logs",
    "token": "admin_token"
  }'
```

### Step 4: View Local Log File
```bash
# SSH into server and check:
tail -100 /tmp/wandweb_system.log
```

---

## Database Recovery Procedures

### If Database is Disconnected

1. **Check Database Service Status**
   ```bash
   sudo systemctl status mysql
   # or for MariaDB:
   sudo systemctl status mariadb
   ```

2. **Restart Database Service**
   ```bash
   sudo systemctl restart mysql
   # or
   sudo systemctl restart mariadb
   ```

3. **Verify Connection Credentials**
   - Check `/workspaces/WWM-Portal/private/secrets.php`
   - Verify: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - Test connection manually:
     ```bash
     mysql -h DB_HOST -u DB_USER -p DB_NAME
     ```

4. **Check Database Exists**
   ```bash
   mysql -u root -p -e "SHOW DATABASES LIKE 'DB_NAME';"
   ```

5. **Verify User Permissions**
   ```bash
   mysql -u root -p -e "SHOW GRANTS FOR 'DB_USER'@'DB_HOST';"
   ```

---

## Log File Management

### View Recent Errors
```bash
grep "\[error\]" /tmp/wandweb_system.log | tail -20
```

### View Last 10 Minutes
```bash
# Adjust timestamp as needed
awk '/2025-12-05 [0-9][0-9]:[5-9][0-9]/' /tmp/wandweb_system.log
```

### Archive Old Logs
```bash
# Create timestamped backup
cp /tmp/wandweb_system.log /tmp/wandweb_system.log.$(date +%s)

# Clear log file
> /tmp/wandweb_system.log
```

### Monitor Logs in Real-Time
```bash
tail -f /tmp/wandweb_system.log
```

---

## Diagnostic Tests Available

The following tests can be run via `action: debug_test`:

| Test | Purpose |
|------|---------|
| `emergency_status` | Full system health check (DB-independent) |
| `database_status` | Check DB connection & log count |
| `api_connection` | Verify API is responding |
| `database_query` | Test database queries |
| `resync_user_59` | Force resync of specific user |
| `rebuild_partners` | List all active partners |
| `permissions_audit` | Verify partner permission assignments |

---

## Important Notes

✅ **What works when DB is down:**
- View system logs from file fallback
- Check database status/error details
- Run diagnostic tests
- View API status
- Export recent logs

❌ **What requires working database:**
- User authentication
- Project/client data operations
- Billing operations
- Data modifications of any kind

⚠️ **File Logs:**
- Location: `/tmp/wandweb_system.log`
- Writable by: PHP/web server user
- Retained: Until manually deleted or system restart
- Format: `[YYYY-MM-DD HH:MM:SS] [LEVEL] Message`

---

## Emergency Recovery Checklist

- [ ] Confirm API is responding (`api_connection` test)
- [ ] Run emergency status check
- [ ] Check database service is running
- [ ] Verify database credentials in secrets.php
- [ ] Test direct database connection
- [ ] Review recent logs for error patterns
- [ ] Restart database service if needed
- [ ] Re-run database connection test
- [ ] Verify data integrity via diagnostics
- [ ] Run permissions audit if issues with role data

---

## Contact & Support
For persistent issues, review the logs systematically:
1. Check `/tmp/wandweb_system.log` for recent errors
2. Use `get_system_logs` API endpoint
3. Run all diagnostic tests in sequence
4. Archive logs and restart if needed

