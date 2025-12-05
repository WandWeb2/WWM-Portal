# Log/Debugging Tab - Quick Start

## Location
Settings → **Log/Debugging** (renamed from "logs")

## 8 Common Debugging Buttons

### 1. ✓ API Status
- **What it does:** Tests if the API is responding
- **When to use:** Check if the entire system is responding
- **Expected result:** "API Connection: ✓ WORKING"

### 2. 🔌 Database  
- **What it does:** Tests database connectivity
- **When to use:** Data not showing, connections timing out
- **Expected result:** "Database Status: ✓ CONNECTED" or shows error

### 3. ⚠️ Emergency
- **What it does:** Full system diagnostics (works without DB)
- **When to use:** Portal experiencing huge problems
- **Expected result:** PHP version, server info, log file status, last 10 logs

### 4. 👥 Permissions
- **What it does:** Audits all partner assignments
- **When to use:** Partners can't see clients, permission issues
- **Expected result:** List of partners and their assigned clients

### 5. 🔄 Rebuild Partners
- **What it does:** Forces fresh rebuild of partner list
- **When to use:** Partner list seems corrupted or out of sync
- **Expected result:** Shows all active partners with counts

### 6. 🔍 Check User #59
- **What it does:** Diagnostic check for specific user
- **When to use:** Debugging specific user issues
- **Expected result:** User details, role, status, assignments

### 7. 🔄 Refresh Logs
- **What it does:** Clears and reloads all logs
- **When to use:** Logs are stale, want to see latest events
- **Expected result:** Fresh log view from database + file

### 8. 📝 Log Event
- **What it does:** Manually create a log entry
- **When to use:** Mark test timestamps, correlate with events
- **Expected result:** New entry appears at top of logs

## Reading the Logs

### Log Color Legend
- 🔴 **RED** = Error (critical issue)
- 🟢 **GREEN** = Success (operation worked)
- 🟡 **YELLOW** = Warning (something unusual)
- 🔵 **BLUE** = Info (regular events)

### Log Sources
- **(db)** = From database system_logs table
- **(file_fallback)** = From /tmp/wandweb_system.log
- **(system)** = System-generated messages

## Troubleshooting Guide

### Scenario: "Portal is down, can't access data"
1. Click **⚠️ Emergency** → See system status
2. Click **🔌 Database** → Check if DB is connected
3. If DB error shown → Server is down, restart MySQL
4. Logs always visible even if DB is down

### Scenario: "Partners can't see their clients"
1. Click **👥 Permissions** → See partner assignments
2. Click **🔄 Rebuild Partners** → Refresh partner list
3. Check if clients assigned to partners
4. Review logs for permission-related errors

### Scenario: "Specific user having issues"
1. Click **🔍 Check User #59** → See user details
2. Review user role and status in logs
3. Check if partner assignments exist
4. Use Audit tab to force fix if needed

### Scenario: "Not sure what's wrong"
1. Click **✓ API Status** → Check API working
2. Click **⚠️ Emergency** → Full system health
3. Review all logs for error patterns
4. Click **🔄 Refresh Logs** → Get latest data
5. Look for red errors in log stream

## Key Features

✅ **Logs Always Available** - Works even when database is down  
✅ **File Fallback** - All events logged to /tmp/wandweb_system.log  
✅ **No Data Loss** - Combined DB + file logs shown  
✅ **One-Click Diagnostics** - 8 common checks ready to run  
✅ **Clear Error Messages** - Exact error details for troubleshooting  
✅ **Visual Formatting** - Color-coded, timestamped, easy to scan  

## Still Not Working?

1. Check `/tmp/wandweb_system.log` directly on server
2. Verify database service is running: `sudo systemctl status mysql`
3. Check database credentials in `/workspaces/WWM-Portal/private/secrets.php`
4. Review EMERGENCY_DIAGNOSTICS.md for detailed procedures
5. Use EMERGENCY_ACCESS_QUICK_REF.md for common commands

