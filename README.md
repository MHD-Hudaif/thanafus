# Kauzariyya Musabaqa - Access Control Guide

This project utilizes a highly flexible, individual-based access control system to manage user permissions and custom event-specific authorities.

---

## 1. Architecture Overview

Access management is split into two distinct layers:
1. **Global Roles** (Main Event): System-wide roles like `admin`, `teacher`, or `student`. These define general dashboard access limits.
2. **Special Roles** (Event Spaces): Event-specific roles grouped under a dedicated **Event Space** (e.g. `Thanafus 2026-27`). Examples include `Score Uploader` or `TV Controller`.

### Direct User Mapping
Permissions and authorities are mapped **directly to individual users** inside the `user_permissions` and `user_authorities` tables. This enables you to grant highly granular, individual access rights without altering their global roles.

---

## 2. Access Verification API (Auth Helpers)

Two primary PHP helper functions are defined in [config/auth.php](file:///c:/laragon/www/kauzariyya-musabaqa/config/auth.php) to restrict access:

### `current_user_has_permission(string $permissionSlug): bool`
Checks if the logged-in user has a system-level permission.
- **Example**: `current_user_has_permission('manage-users')`

### `current_user_has_authority(string $authoritySlug): bool`
Checks if the logged-in user has a custom event-level authority.
- **Example**: `current_user_has_authority('upload-scores')`

> [!NOTE]
> Users with the global `admin` role automatically pass all checks and return `true` for any permission or authority query.

---

## 3. Step-by-Step Workflow: Creating a Page & Registering its Role

Follow this exact sequence whenever you build a new restricted page:

### Step 1: Register the Authority in the Database
Before writing the page code, insert a new authority record representing the page's privilege:
```sql
INSERT INTO authorities (name, slug, description, created_at, updated_at)
VALUES ('Program Entries Assigner', 'assign-entries', 'Assigns students to contest entries', NOW(), NOW());
```

### Step 2: Develop the Restricted Page
Create your page file (e.g., `admin/event/program-entries.php`) and add the security check at the very top:
```php
<?php
require_once __DIR__.'/../../config/auth.php';
require_login();

// Restrict access using the newly registered authority slug
if (!current_user_has_authority('assign-entries')) {
    http_response_code(403);
    exit('Access Denied: You do not have authority to access this page.');
}
```

### Step 3: Create the Special Role for the Event
To let users hold this authority under a specific event:
1. Open the **Special Roles** panel (`admin/roles/special.php`) in your browser.
2. Click **Add Special Role**:
   - **Role Name**: e.g., `Entries Assigner`
   - **Event / Role Space**: Select `Thanafus 2026-27` (or input a new event name under "+ Create Custom Event Space...").
   - **Description**: Add brief details of responsibilities.
   - Click **Create Role**.

### Step 4: Grant Access to Target Users
1. Open the **Users** management page (`admin/users/`).
2. Locate the user you wish to authorize and click the shield icon (**Manage Permissions & Authorities**).
3. Check the checkbox matching your new authority on the right-hand column checklist.
4. Click **Save Mappings**. The user will now have instant access to load and manage that page.
