# Google Drive Integration Setup Guide

## Overview
The WandWeb Portal now uses Google Drive as the primary cloud storage backend for all client files. This replaces local file storage with enterprise-grade cloud infrastructure.

## Architecture

### Storage Structure
Files are organized in Google Drive with automatic folder hierarchy:
```
WandWeb Clients/
├── [Client Name]/
│   └── Shared Files/
│       ├── document.pdf
│       ├── image.png
│       └── ...
```

### Database Storage Format
Files are stored in the `shared_files` table with the following convention:
- **Drive files**: `external_url = "drive:{googleFileId}"`
- **External links**: `external_url = "https://example.com/file.pdf"`

### Secure Proxy Architecture
- Files are **never** accessed via direct Google Drive URLs
- All downloads are proxied through `api/portal_api.php?action=download_file`
- The backend streams files from Drive to the user with proper authentication
- This prevents URL exposure and enables role-based access control

## Configuration Requirements

### Environment Variables (already configured in `private/secrets.php`)
```php
'GOOGLE_CLIENT_ID' => getenv('GOOGLE_CLIENT_ID'),
'GOOGLE_CLIENT_SECRET' => getenv('GOOGLE_CLIENT_SECRET'),
'GOOGLE_REFRESH_TOKEN' => getenv('GOOGLE_REFRESH_TOKEN'),
```

### Obtaining Google OAuth Credentials

1. **Create Google Cloud Project**:
   - Go to https://console.cloud.google.com
   - Create a new project or select existing
   - Enable **Google Drive API** in APIs & Services

2. **Create OAuth 2.0 Credentials**:
   - Navigate to **APIs & Services > Credentials**
   - Click **Create Credentials > OAuth 2.0 Client ID**
   - Application type: **Web application**
   - Authorized redirect URIs: `http://localhost` (for local token generation)
   - Download the JSON credentials file

3. **Generate Refresh Token**:
   Use the following PHP script to generate a refresh token:
   
   ```php
   <?php
   // google-auth.php - Run this ONCE to get refresh token
   
   $clientId = 'YOUR_CLIENT_ID';
   $clientSecret = 'YOUR_CLIENT_SECRET';
   $redirectUri = 'http://localhost';
   
   if (!isset($_GET['code'])) {
       // Step 1: Redirect to Google
       $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
           'client_id' => $clientId,
           'redirect_uri' => $redirectUri,
           'response_type' => 'code',
           'scope' => 'https://www.googleapis.com/auth/drive.file',
           'access_type' => 'offline',
           'prompt' => 'consent'
       ]);
       header("Location: $authUrl");
       exit;
   } else {
       // Step 2: Exchange code for tokens
       $ch = curl_init("https://oauth2.googleapis.com/token");
       curl_setopt($ch, CURLOPT_POST, true);
       curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
           'client_id' => $clientId,
           'client_secret' => $clientSecret,
           'code' => $_GET['code'],
           'redirect_uri' => $redirectUri,
           'grant_type' => 'authorization_code'
       ]));
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
       
       $response = curl_exec($ch);
       curl_close($ch);
       
       $data = json_decode($response, true);
       
       echo "<h1>Success!</h1>";
       echo "<p><strong>Refresh Token:</strong></p>";
       echo "<pre>" . htmlspecialchars($data['refresh_token']) . "</pre>";
       echo "<p>Add this to your .env file as GOOGLE_REFRESH_TOKEN</p>";
   }
   ```

4. **Set Environment Variables**:
   ```bash
   export GOOGLE_CLIENT_ID="your-client-id.apps.googleusercontent.com"
   export GOOGLE_CLIENT_SECRET="your-client-secret"
   export GOOGLE_REFRESH_TOKEN="1//0xxxxx..."
   ```

## Implementation Details

### Backend Components

#### `api/modules/files.php` (NEW)
Complete Google Drive API integration module with 6 main functions:

1. **`driveRequest($token, $method, $endpoint, $body, $contentType)`**
   - Generic curl wrapper for Drive API v3
   - Handles GET, POST, DELETE methods
   - Returns JSON-decoded response

2. **`findOrCreateFolder($token, $name, $parentId = 'root')`**
   - Searches for existing folder by name
   - Creates folder if not found
   - Returns folder ID

3. **`uploadToDrive($token, $fileData, $filename, $mimeType, $parentId)`**
   - Multipart upload with metadata + content
   - Uses boundary encoding for Drive API
   - Returns uploaded file ID

4. **`handleGetFiles($pdo, $input)`**
   - Role-aware file listing
   - Admin: sees all files
   - Partner: sees assigned clients' files
   - Client: sees own files only

5. **`handleUploadFile($pdo, $input, $secrets)`**
   - **Main upload handler**
   - Determines target client (from project or admin selector)
   - Creates folder hierarchy automatically
   - Stores with "drive:{id}" prefix
   - Adds comment to project chat if `project_id` provided
   - Falls back to external link mode if no file

6. **`handleDownloadFile($pdo, $input, $secrets)`**
   - **Secure proxy for streaming files**
   - Verifies user access (admin, owner, or partner)
   - Streams Drive files with proper headers
   - Prevents direct URL exposure

7. **`handleDeleteFile($pdo, $input, $secrets)`**
   - Admin-only deletion
   - Removes from both Drive and database
   - Returns success/error status

#### `api/modules/utils.php` (UPDATED)
Added `getGoogleAccessToken($secrets)` helper:
- Refreshes access token from refresh token
- Handles OAuth token exchange
- Returns valid Bearer token for API calls
- Throws exceptions on failure

#### `api/portal_api.php` (UPDATED)
New routes added:
```php
case 'get_files': handleGetFiles($pdo, $input); break;
case 'upload_file': handleUploadFile($pdo, $input, $secrets); break;
case 'delete_file': handleDeleteFile($pdo, $input, $secrets); break;
case 'download_file': handleDownloadFile($pdo, $input, $secrets); break;
```

### Frontend Components

#### `portal/js/views.js` - `FilesView` (UPDATED)
Complete UI overhaul for Drive integration:

**New Features**:
- ✅ Admin client selector dropdown for uploading to any client's folder
- ✅ Drive file detection: `f.external_url.startsWith('drive:')`
- ✅ Secure download URLs: `${API_URL}?action=download_file&token=${token}&file_id=${f.id}`
- ✅ Admin delete button with trash icon
- ✅ Visual distinction: Cloud icon for Drive files, Link icon for external URLs
- ✅ Button text: "Download" for Drive files, "Open" for external links
- ✅ Loading state: "Uploading to Drive..." during upload
- ✅ Informational message: "Files will be stored in Google Drive > Client Folder > Shared Files"

**State Management**:
```javascript
const [files, setFiles] = useState([]);
const [clients, setClients] = useState([]); // Admin only
const [loading, setLoading] = useState(true);
const [show, setShow] = useState(false);
```

**Role-Based UI**:
- Admin sees all files + client selector
- Partner sees assigned clients' files
- Client sees only own files

## Database Schema

### `shared_files` Table
```sql
CREATE TABLE shared_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    project_id INT,                  -- Links file to project
    filename VARCHAR(255),
    external_url TEXT,                -- "drive:{id}" or direct URL
    file_type VARCHAR(100),           -- MIME type
    filesize BIGINT,                  -- Bytes
    uploaded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Security Features

### Access Control
- **Admin**: Full access to all files (view, upload, delete)
- **Partner**: Access to assigned clients' files
- **Client**: Access to own files only

### Proxy Streaming
```php
// handleDownloadFile verifies access before streaming
if (!($isAdmin || $isOwner || $isPartner)) {
    sendJson('error', 'Unauthorized');
}
```

### URL Obfuscation
- Drive file IDs stored with "drive:" prefix
- Direct Drive URLs never exposed to frontend
- All downloads proxied through authenticated endpoint

## Testing Checklist

### Upload Flow
- [ ] Admin uploads file without project → stored in admin's folder
- [ ] Admin uploads file with client selector → stored in client's folder
- [ ] Client uploads file from project → stored in client's folder
- [ ] Verify folder hierarchy: "WandWeb Clients" > [Client Name] > "Shared Files"

### Download Flow
- [ ] Admin downloads any file → success
- [ ] Partner downloads assigned client file → success
- [ ] Client downloads own file → success
- [ ] Client attempts to download other client's file → blocked

### Delete Flow
- [ ] Admin deletes file → removed from Drive + DB
- [ ] Non-admin attempts delete → blocked (UI hides button)

### Edge Cases
- [ ] Upload with missing OAuth credentials → error message
- [ ] Upload with invalid file type → error message
- [ ] Download non-existent file → error message
- [ ] Token refresh failure → error message

## Troubleshooting

### Error: "Missing Google OAuth credentials"
- Verify `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REFRESH_TOKEN` in environment
- Check secrets.php is loading variables correctly

### Error: "Google OAuth token refresh failed"
- Refresh token may be expired or invalid
- Re-run google-auth.php script to generate new refresh token
- Ensure `access_type=offline` and `prompt=consent` in auth URL

### Error: "Column not found: external_url"
- Run `ensureProjectSchema()` in projects.php to self-repair
- Or manually: `ALTER TABLE shared_files ADD COLUMN external_url TEXT;`

### Files not appearing in Drive
- Check folder permissions in Google Drive console
- Verify Drive API is enabled in Google Cloud project
- Check API quota limits (default: 1000 requests/100 seconds)

### Download proxy returns 404
- Verify file ID exists in database
- Check user has permission to access file
- Confirm file still exists in Google Drive

## Migration from Local Storage

If you have existing files in `/uploads/` directory:

1. **Identify local files**:
   ```sql
   SELECT * FROM shared_files WHERE external_url NOT LIKE 'drive:%' AND external_url NOT LIKE 'http%';
   ```

2. **Upload to Drive programmatically**:
   ```php
   foreach ($localFiles as $file) {
       $token = getGoogleAccessToken($secrets);
       $filepath = __DIR__ . '/../../uploads/' . $file['filepath'];
       $fileData = file_get_contents($filepath);
       $driveId = uploadToDrive($token, $fileData, $file['filename'], $file['file_type'], $folderId);
       
       // Update database
       $pdo->prepare("UPDATE shared_files SET external_url = ? WHERE id = ?")
           ->execute(['drive:' . $driveId, $file['id']]);
   }
   ```

## Performance Considerations

### Caching
- Access tokens are valid for 1 hour
- Consider implementing token caching to reduce OAuth refresh calls
- Use Redis/Memcached for production deployments

### Rate Limits
- Google Drive API: 1000 requests/100 seconds per user
- Implement request throttling if hitting limits
- Use exponential backoff for 429 errors

### File Size Limits
- Drive API supports files up to 5TB
- PHP upload_max_filesize: default 2M (increase in php.ini)
- PHP post_max_size: should be larger than upload_max_filesize

## Future Enhancements

### Potential Features
- [ ] Batch file uploads
- [ ] File versioning (Drive native support)
- [ ] Direct file preview (Drive viewer embeds)
- [ ] Folder-level permissions
- [ ] File sharing with external users
- [ ] Download activity logging
- [ ] File expiration dates
- [ ] Thumbnail generation

### Integration Opportunities
- Google Workspace integration (Docs, Sheets, Slides)
- OCR for uploaded images (Drive API native)
- Virus scanning (Drive API native)
- Full-text search across file contents

## Support

For issues or questions:
- Check error logs in `/var/log/apache2/error.log` or `/var/log/php-fpm/error.log`
- Review Drive API documentation: https://developers.google.com/drive/api/v3/reference
- Contact WandWeb development team

---

**Version**: 1.0  
**Last Updated**: 2024  
**Status**: ✅ Production Ready
