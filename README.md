# C1SO TECH


| Role | Can do |
|---|---|
| **Staff** | View Inventory/Deployments/Reports; use the Deployments area fully (deploy items, mark returns, add departments) |
| **Admin** | Everything Staff can, plus Inventory item CRUD, Staff account management, and Settings |
| **Superadmin** | Everything Admin can, plus exclusive access to "Add" actions across Inventory/Deployments; cannot be disabled, deleted, or demoted by an Admin |

### Default credentials

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Superadmin | `superadmin` | `superadmin123` |

**Change both passwords immediately after first login** (Account menu → Change Password), and consider removing or restricting access to `setup.php` once setup is complete.

### Database connection

Defaults assume standard XAMPP MySQL settings (`localhost`, user `root`, no password). If yours differs, edit `includes/db.php`:

```php
$DB_HOST = 'localhost';
$DB_NAME = 'c1so_tech';
$DB_USER = 'root';
$DB_PASS = '';
```

## Project structure

```
├── index.php               Dashboard
├── login.php / logout.php  Authentication
├── setup.php                One-time admin/superadmin seeding
├── worklog.php               Work log (staff-facing)
├── public_worklog.php        Public, read-only work log view
├── inventory.php              Inventory items & batch management
├── deployments.php             Deployment wizard & records
├── departments.php              Department → staff → deployments browser
├── reports.php                   Dashboards & history
├── messages.php                  Internal messaging
├── activity.php                  Activity/audit log
├── staff.php                     Staff account management (admin+)
├── account.php                   Your own login/password
├── settings.php                  App settings (admin+)
├── includes/                     Shared: db connection, auth/session, header, footer
├── assets/css/style.css          Styling
├── database.sql                  Full schema + seed data
├── migrate_v2_add_activity_log_module.php
├── migrate_v3_inventory_batches_and_property_number.php
├── migrate_v4_add_messages_table.php
├── migrate_v5_add_po_number_to_batches_and_deployments.php
├── migrate_v6_add_inventory_remarks_and_disposal_flags.php
├── migrate_v7_add_deployment_batch_consumption_fifo.php
├── migrate_v8_add_department_people_and_recipient_name.php
├── migrate_v9_add_superadmin_role.php
├── migrate_v10_rename_po_to_pn_and_add_deployment_time_type.php
├── migrate_v11_add_po_number_and_batch_time.php
├── migrate_v12_add_recondition_pullout_and_item_details.php
├── migrate_v13_add_serial_numbers_and_mr_number.php
├── migrate_v14_add_deployment_specs.php
└── migrate_v15_add_flexible_specs_and_quick_pick_kinds.php
```

