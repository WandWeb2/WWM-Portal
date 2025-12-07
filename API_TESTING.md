# API Testing Guide - WandWeb Portal 2.0

## Overview
This guide provides comprehensive testing procedures for all API endpoints in the WandWeb Portal 2.0 backend.

## Base URL
```
Production: https://yourdomain.com/api/portal_api.php
Local Dev:  http://localhost/api/portal_api.php
```

## Authentication
All protected endpoints require a JWT token obtained from the login endpoint.

### Token Format
```
Authorization: Bearer <token>
```
Or pass token in request body:
```json
{
  "action": "endpoint_name",
  "token": "YOUR_JWT_TOKEN",
  ...
}
```

---

## 1. Authentication Endpoints

### 1.1 Login
**Action:** `login`  
**Method:** POST  
**Auth Required:** No

**Request:**
```json
{
  "action": "login",
  "email": "user@example.com",
  "password": "password123"
}
```

**Response (Success):**
```json
{
  "status": "success",
  "message": "Login",
  "data": {
    "token": "eyJhbGc...",
    "user": {
      "id": 1,
      "name": "John Doe",
      "role": "admin"
    }
  }
}
```

**cURL Example:**
```bash
curl -X POST http://localhost/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{
    "action": "login",
    "email": "admin@example.com",
    "password": "admin123"
  }'
```

### 1.2 Request Password Reset
**Action:** `request_password_reset`  
**Method:** POST  
**Auth Required:** No

**Request:**
```json
{
  "action": "request_password_reset",
  "email": "user@example.com"
}
```

---

## 2. Dashboard Endpoints

### 2.1 Get Admin Dashboard
**Action:** `get_admin_dashboard`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "get_admin_dashboard",
  "token": "YOUR_JWT_TOKEN"
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "projects": {
      "total": 25,
      "active": 15,
      "completed": 8,
      "on_hold": 2
    },
    "revenue": {
      "monthly": 12500.00,
      "yearly": 125000.00
    },
    "tickets": {
      "open": 5,
      "in_progress": 3,
      "resolved": 45
    }
  }
}
```

---

## 3. Project Management Endpoints

### 3.1 Get Projects
**Action:** `get_projects`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_projects",
  "token": "YOUR_JWT_TOKEN"
}
```

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "title": "Website Redesign",
      "client_name": "Acme Corp",
      "status": "active",
      "progress": 75,
      "due_date": "2025-12-31"
    }
  ]
}
```

### 3.2 Create Project
**Action:** `create_project`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "create_project",
  "token": "YOUR_JWT_TOKEN",
  "title": "New Website",
  "description": "Build a modern website",
  "client_id": 5,
  "due_date": "2025-12-31",
  "status": "active"
}
```

### 3.3 Get Project Details
**Action:** `get_project_details`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_project_details",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1
}
```

### 3.4 Update Project Status
**Action:** `update_project_status`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "update_project_status",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1,
  "status": "completed"
}
```

### 3.5 Assign Project Manager
**Action:** `assign_project_manager`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "assign_project_manager",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1,
  "partner_id": 3
}
```

### 3.6 Delete Project
**Action:** `delete_project`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "delete_project",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1
}
```

### 3.7 AI Create Project
**Action:** `ai_create_project`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "ai_create_project",
  "token": "YOUR_JWT_TOKEN",
  "description": "Build a modern e-commerce website with shopping cart and payment integration"
}
```

---

## 4. Task Management Endpoints

### 4.1 Get Tasks
**Action:** `get_tasks`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_tasks",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1
}
```

### 4.2 Save Task
**Action:** `save_task`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "save_task",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1,
  "title": "Design homepage mockup",
  "description": "Create initial design concepts",
  "status": "pending"
}
```

### 4.3 Toggle Task
**Action:** `toggle_task`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "toggle_task",
  "token": "YOUR_JWT_TOKEN",
  "task_id": 5
}
```

### 4.4 Save Comment
**Action:** `save_comment`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "save_comment",
  "token": "YOUR_JWT_TOKEN",
  "project_id": 1,
  "comment": "This looks great! Please proceed."
}
```

---

## 5. File Management Endpoints

### 5.1 Get Files
**Action:** `get_files`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_files",
  "token": "YOUR_JWT_TOKEN",
  "client_id": 5
}
```

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "filename": "design.pdf",
      "external_url": "drive:1ABC_xyz123",
      "file_type": "application/pdf",
      "filesize": 1048576,
      "uploaded_at": "2025-12-01 10:30:00"
    }
  ]
}
```

### 5.2 Upload File
**Action:** `upload_file`  
**Method:** POST (multipart/form-data)  
**Auth Required:** Yes

**Request:**
```bash
curl -X POST http://localhost/api/portal_api.php \
  -F "action=upload_file" \
  -F "token=YOUR_JWT_TOKEN" \
  -F "client_id=5" \
  -F "file=@/path/to/file.pdf"
```

**Response:**
```json
{
  "status": "success",
  "message": "File uploaded successfully",
  "data": {
    "file_id": 10,
    "filename": "file.pdf",
    "external_url": "drive:1ABC_xyz123"
  }
}
```

### 5.3 Delete File
**Action:** `delete_file`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "delete_file",
  "token": "YOUR_JWT_TOKEN",
  "file_id": 10
}
```

---

## 6. Support Ticket Endpoints

### 6.1 Get Tickets
**Action:** `get_tickets`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_tickets",
  "token": "YOUR_JWT_TOKEN"
}
```

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "subject": "Login issue",
      "status": "open",
      "priority": "high",
      "created_at": "2025-12-01 09:00:00"
    }
  ]
}
```

### 6.2 Get Ticket Thread
**Action:** `get_ticket_thread`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_ticket_thread",
  "token": "YOUR_JWT_TOKEN",
  "ticket_id": 1
}
```

### 6.3 Create Ticket
**Action:** `create_ticket`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "create_ticket",
  "token": "YOUR_JWT_TOKEN",
  "subject": "Cannot access dashboard",
  "message": "Getting error 500 when trying to log in",
  "priority": "high"
}
```

### 6.4 Reply to Ticket
**Action:** `reply_ticket`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "reply_ticket",
  "token": "YOUR_JWT_TOKEN",
  "ticket_id": 1,
  "message": "I've fixed the issue. Please try again."
}
```

### 6.5 Update Ticket Status
**Action:** `update_ticket_status`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "update_ticket_status",
  "token": "YOUR_JWT_TOKEN",
  "ticket_id": 1,
  "status": "resolved"
}
```

---

## 7. Client Management Endpoints

### 7.1 Get Clients
**Action:** `get_clients`  
**Method:** POST  
**Auth Required:** Yes (Admin/Partner)

**Request:**
```json
{
  "action": "get_clients",
  "token": "YOUR_JWT_TOKEN"
}
```

### 7.2 Get Partners
**Action:** `get_partners`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "get_partners",
  "token": "YOUR_JWT_TOKEN"
}
```

### 7.3 Create Client
**Action:** `create_client`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "create_client",
  "token": "YOUR_JWT_TOKEN",
  "email": "newclient@example.com",
  "full_name": "John Doe",
  "business_name": "Acme Corp",
  "password": "temporary123"
}
```

---

## 8. Billing Endpoints

### 8.1 Get Billing Overview
**Action:** `get_billing_overview`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "get_billing_overview",
  "token": "YOUR_JWT_TOKEN"
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "invoices": [],
    "subscriptions": [],
    "total_outstanding": 0.00
  }
}
```

### 8.2 Refund Payment
**Action:** `refund_payment`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "refund_payment",
  "token": "YOUR_JWT_TOKEN",
  "payment_intent_id": "pi_xxx",
  "amount": 5000
}
```

---

## 9. Service/Product Endpoints

### 9.1 Get Services
**Action:** `get_services`  
**Method:** POST  
**Auth Required:** No (public products only)

**Request:**
```json
{
  "action": "get_services",
  "token": "YOUR_JWT_TOKEN"
}
```

### 9.2 Create Product
**Action:** `create_product`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "create_product",
  "token": "YOUR_JWT_TOKEN",
  "name": "Website Maintenance",
  "description": "Monthly website maintenance package",
  "price": 299.00,
  "stripe_price_id": "price_xxx"
}
```

### 9.3 Update Product
**Action:** `update_product`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "update_product",
  "token": "YOUR_JWT_TOKEN",
  "product_id": 5,
  "name": "Updated Product Name",
  "price": 399.00
}
```

### 9.4 Delete Product
**Action:** `delete_product`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "delete_product",
  "token": "YOUR_JWT_TOKEN",
  "product_id": 5
}
```

### 9.5 Toggle Product Visibility
**Action:** `toggle_product_visibility`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "toggle_product_visibility",
  "token": "YOUR_JWT_TOKEN",
  "product_id": 5
}
```

### 9.6 Create Checkout
**Action:** `create_checkout`  
**Method:** POST  
**Auth Required:** Yes

**Request:**
```json
{
  "action": "create_checkout",
  "token": "YOUR_JWT_TOKEN",
  "items": [
    {
      "price_id": "price_xxx",
      "quantity": 1
    }
  ],
  "success_url": "https://yourdomain.com/success",
  "cancel_url": "https://yourdomain.com/cancel"
}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "checkout_url": "https://checkout.stripe.com/pay/cs_xxx"
  }
}
```

---

## 10. System Endpoints

### 10.1 Get System Logs
**Action:** `get_system_logs`  
**Method:** POST  
**Auth Required:** Yes (Admin only)

**Request:**
```json
{
  "action": "get_system_logs",
  "token": "YOUR_JWT_TOKEN",
  "limit": 50
}
```

### 10.2 Debug Test
**Action:** `debug_test`  
**Method:** POST  
**Auth Required:** No

**Request:**
```json
{
  "action": "debug_test"
}
```

### 10.3 Debug Log
**Action:** `debug_log`  
**Method:** POST  
**Auth Required:** No (for debugging only)

**Request:**
```json
{
  "action": "debug_log",
  "message": "Test log entry",
  "level": "info"
}
```

---

## Testing Checklist

### Basic Functionality
- [ ] Login with valid credentials
- [ ] Login with invalid credentials (should fail)
- [ ] Token expiry handling
- [ ] CORS headers present

### Projects
- [ ] Create new project
- [ ] List all projects
- [ ] Get project details
- [ ] Update project status
- [ ] Delete project
- [ ] AI-generated project creation

### Files
- [ ] Upload file to Google Drive
- [ ] Upload file with Drive failure (local fallback)
- [ ] List files for client
- [ ] Download file (proxy)
- [ ] Delete file

### Tickets
- [ ] Create support ticket
- [ ] List tickets
- [ ] Reply to ticket
- [ ] Update ticket status
- [ ] Get ticket thread

### Billing
- [ ] Get billing overview
- [ ] Create checkout session
- [ ] Process refund (admin only)

### Security
- [ ] Protected endpoints reject invalid tokens
- [ ] Admin-only endpoints reject non-admin users
- [ ] SQL injection attempts blocked
- [ ] XSS attempts sanitized
- [ ] File upload size limits enforced

---

## Performance Testing

### Load Testing with Apache Bench
```bash
# Test login endpoint
ab -n 100 -c 10 -p login.json -T application/json http://localhost/api/portal_api.php

# Test authenticated endpoint
ab -n 100 -c 10 -p get_projects.json -T application/json http://localhost/api/portal_api.php
```

### Expected Performance
- Login: < 200ms
- Get Projects: < 100ms
- File Upload: < 2s (for 10MB file)
- Database queries: < 50ms

---

## Error Handling Tests

### Test Invalid JSON
```bash
curl -X POST http://localhost/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d 'invalid json'
```

Expected: Error response with proper JSON format

### Test Missing Action
```bash
curl -X POST http://localhost/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{}'
```

Expected: "Invalid Action" error

### Test Database Down
1. Stop database service
2. Make API request
3. Should get graceful error (not expose DB details)

---

## Automation Scripts

### Python Test Script
```python
import requests
import json

BASE_URL = "http://localhost/api/portal_api.php"

# Login
response = requests.post(BASE_URL, json={
    "action": "login",
    "email": "admin@example.com",
    "password": "admin123"
})

data = response.json()
token = data['data']['token']

# Get Projects
response = requests.post(BASE_URL, json={
    "action": "get_projects",
    "token": token
})

print(json.dumps(response.json(), indent=2))
```

### Bash Test Script
```bash
#!/bin/bash
API_URL="http://localhost/api/portal_api.php"

# Login
TOKEN=$(curl -s -X POST $API_URL \
  -H "Content-Type: application/json" \
  -d '{"action":"login","email":"admin@example.com","password":"admin123"}' \
  | jq -r '.data.token')

echo "Token: $TOKEN"

# Get Projects
curl -s -X POST $API_URL \
  -H "Content-Type: application/json" \
  -d "{\"action\":\"get_projects\",\"token\":\"$TOKEN\"}" \
  | jq .
```

---

## Continuous Integration

### GitHub Actions Example
```yaml
name: API Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - name: Run PHP Lint
        run: |
          php -l api/portal_api.php
          for file in api/modules/*.php; do php -l "$file"; done
      - name: Run API Tests
        run: |
          php -S localhost:8000 &
          sleep 2
          bash tests/api-test.sh
```

---

## Support & Documentation

- **Main Documentation:** README.md
- **Deployment Guide:** DEPLOYMENT.md
- **Google Drive Setup:** GOOGLE_DRIVE_SETUP.md
- **Emergency Access:** EMERGENCY_ACCESS_QUICK_REF.md
- **Log Debugging:** LOG_DEBUGGING_GUIDE.md

For issues or questions, refer to the troubleshooting section in DEPLOYMENT.md.
