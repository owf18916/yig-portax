# PorTax Authentication & Authorization Setup

## Overview
Complete custom authentication system implemented using Laravel 12.x with PortaxUser model, role-based access control, and policy-based authorization for the tax case management workflow.

## Components Implemented

### 1. Authentication Configuration
**File**: `config/auth.php`
- Custom provider pointing to `PortaxUser` model instead of default `User`
- Session guard configured for web routes
- Password reset configuration

### 2. User Model
**File**: `app/Models/PortaxUser.php`
- Relationships: Entity (parent), Role (role)
- Helper Methods:
  - `isAdmin()` - Check if user is administrator
  - `isReviewer()` - Check if user is reviewer
  - `isStaff()` - Check if user is staff
  - `isReadOnly()` - Check if user has read-only access
  - `canCreateTaxCase()` - Check if user can create cases
  - `canApproveTaxCase()` - Check if user can approve cases
  - `canRejectTaxCase()` - Check if user can reject cases
  - `hasPermission(string)` - Check specific permission

### 3. Role-Based Access Control
**File**: `app/Models/Role.php`
- Role Model with permissions column (JSON)
- Four roles defined:
  - `ADMIN` - Full system access
  - `REVIEWER` - Approval rights
  - `STAFF` - Creation rights
  - `VIEWER` - Read-only access

### 4. Authorization Policies
**File**: `app/Policies/TaxCasePolicy.php`
- Authorization gates for TaxCase resource:
  - `viewAny()` - List tax cases (entity filtered for non-admins)
  - `view()` - View single case (entity isolation)
  - `create()` - Create new case (STAFF/REVIEWER only)
  - `update()` - Edit case (owner or admin)
  - `delete()` - Delete case (admin only)
  - `approve()` - Approve case (segregation of duties)
  - `reject()` - Reject case
  - `viewAuditLog()` - View audit trail

**Key Feature**: Segregation of duties - users cannot approve their own entity's cases.

### 5. Middleware
**File**: `app/Http/Middleware/CheckRole.php`
- Parameter-based role validation
- Usage: `middleware('role:admin,reviewer')`

**File**: `app/Http/Middleware/EnsureUserIsActive.php`
- Validates user account is active (is_active flag)
- Logs out inactive users

### 6. Controllers
**File**: `app/Http/Controllers/AuthController.php`
- Methods:
  - `showLoginForm()` - Display login page
  - `login()` - Authenticate user (validates credentials and is_active flag)
  - `logout()` - Terminate session
  - `showForgotPasswordForm()` - Display password reset form
  - `forgotPassword()` - Handle password reset request

**File**: `app/Http/Controllers/TaxCaseController.php`
- Methods:
  - `index()` - List cases with authorization and entity filtering
  - `show()` - Display case detail with all relationships
  - `create()` - Show create form
  - `edit()` - Show edit form
  - `auditLog()` - Display audit trail
  - `statusHistory()` - Display workflow history

All methods use `$this->authorize()` for policy-based access control.

### 7. Routes Configuration
**File**: `routes/web.php`

Public Routes (guest middleware):
```
GET  /login                           - Show login form
POST /login                           - Process login
GET  /forgot-password                 - Show password reset form
POST /forgot-password                 - Process password reset
```

Authenticated Routes (auth + active middleware):
```
POST /logout                          - Logout user
GET  /dashboard                       - Redirect to tax cases
GET  /tax-cases                       - List tax cases
GET  /tax-cases/{id}                  - View case detail
GET  /tax-cases/{id}/audit-log        - View audit trail
GET  /tax-cases/{id}/status-history   - View status history
GET  /tax-cases/create                - Create form
GET  /tax-cases/{id}/edit             - Edit form
```

### 8. Blade Templates
**File**: `resources/views/layouts/app.blade.php`
- Main layout with navigation bar
- Authenticated user info display
- Logout button
- Flash message display

**File**: `resources/views/auth/login.blade.php`
- Clean login form with Tailwind CSS
- Email and password inputs
- Remember me checkbox
- Password reset link

**File**: `resources/views/auth/forgot-password.blade.php`
- Password reset request form
- Email input
- Status/error messaging

**File**: `resources/views/tax-cases/index.blade.php`
- Tax cases list with filtering
- Status badges with color coding
- Pagination (20 items per page)
- Action links (View, Edit with authorization gates)
- Empty state with create button

### 9. Service Provider Configuration
**File**: `app/Providers/AppServiceProvider.php`
- Policy registration: `TaxCase::class => TaxCasePolicy::class`

**File**: `bootstrap/app.php`
- Middleware aliases:
  - `role` → `CheckRole::class`
  - `active` → `EnsureUserIsActive::class`

### 10. Database Seeder
**File**: `database/seeders/DatabaseSeeder.php`
- Creates 4 roles with permissions
- Creates holding company + 3 affiliates
- Creates 4 test users (one for each role):
  - admin@portax.local (ADMIN)
  - reviewer@portax.local (REVIEWER)
  - staff@portax.local (STAFF)
  - viewer@portax.local (VIEWER)
- All test users: Password = `password123`
- Creates currencies (IDR, USD)
- Creates fiscal years (2020-2025)
- Creates case statuses (Draft, Submitted, Under Review, Approved, Rejected)

## Authentication Flow

1. **Login Process**
   - User submits credentials on `/login`
   - AuthController validates email & password
   - Checks user is_active flag
   - Authenticates session via auth()->login()
   - Redirects to dashboard/tax-cases

2. **Authorization Check**
   - Controller calls `$this->authorize()` with policy gate
   - Policy checks user role and entity affiliation
   - Segregation of duties enforced (cannot approve own entity)
   - Throws 403 Forbidden if unauthorized

3. **Livewire Integration**
   - Components access `auth()->user()` for current user
   - Action classes receive `approver` parameter
   - Audit logs attribute changes to authenticated user

## Running the Application

1. **Setup Database**
```bash
php artisan migrate
php artisan db:seed
```

2. **Start Development Server**
```bash
php artisan serve
```

3. **Login Credentials**
- Admin: admin@portax.local / password123
- Reviewer: reviewer@portax.local / password123
- Staff: staff@portax.local / password123
- Viewer: viewer@portax.local / password123

## Security Features

✅ Role-based access control
✅ Policy-based authorization with segregation of duties
✅ Entity isolation (users see only their entity's cases)
✅ Account status validation (is_active flag)
✅ CSRF protection
✅ Authenticated session management
✅ Audit trail with user attribution
✅ Password hashing with Hash facade

## Next Steps

- [ ] Implement password reset functionality (send email)
- [ ] Add user management module
- [ ] Create role management interface
- [ ] Write feature tests for auth flows
- [ ] Write policy authorization tests
- [ ] Setup email notifications for approvals
- [ ] Implement activity logging dashboard
