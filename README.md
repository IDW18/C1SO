# C1SO TECH

A PHP/MySQL web app for managing work logs, equipment inventory, and deployments to departments and staff. Built to run on **XAMPP** (Apache + MySQL/MariaDB).

---

## Features

- **Work Log** — log completed tasks by category, with photo attachments and a read-only public view (`public_worklog.php`) for sharing progress without giving out login access.
- **Inventory Management** — track items by kind, restock in **multi-item batches** (one delivery = one PN Number + one PO Number, with as many different kinds of items as that delivery actually contains — e.g. a projector, a PC set, and laptops added in one go), with an optional **Serial Number per physical unit** on every restocked item. Auto-flags items inactive 30+ days for disposal review. A dedicated **Deployed Items tab** on the Inventory page lists everything currently deployed with MR No., Kind of Item (filterable with one click), Location, Date Deployed, Remarks, and who deployed it — click any row to view that unit's full **spec sheet** (Casing, Processor, Motherboard, RAM, SSD, PSU, Note) plus any **Keyboard/Mouse/Monitor** deployed alongside it. Full restock/consumption/serial history is viewable in a popup on every item, and in Reports.
- **Deployments** — a guided wizard for assigning inventory to departments and staff. Every deployment records a **PN Number and an MR (Material Request) Number**. Supports four deployment types: **New**, **Replacement**, **Recondition**, and **Pullout Unit**. A quick-pick step for **PC / Laptop / Projector** brings up that Kind of Item's own Specifications fields (auto-filled from the model's preset specs where available), plus optional stock-linked Keyboard/Mouse/Monitor pickers for a PC deployment. Replacement deployments show the staff member's currently-deployed items and auto-mark the replaced item as *Returned*. Deployments consume inventory batches oldest-first (FIFO), record both date and time in Philippine Time, and never resume an in-progress submission — every "+ Start" opens fresh.
- **Departments** — browse departments → staff in that department → everything currently or previously deployed to them (with PO/PN/MR Numbers shown), with the Return action available inline.
- **Reports** — dashboards and breakdowns for inventory, deployments (split into New vs Replacement, with PO/PN/MR Numbers), and disposal history.
- **Messages** — internal messaging between accounts, with an unread badge in the header.
- **Activity Log** — a running audit trail of actions across every module: login/logout, work log, inventory, deployment, settings, staff management, and account changes.
- **Role-based access** — three roles: `staff`, `admin`, and `superadmin` (see below).
- **Account & Settings** — change your own login credentials; admins can manage staff accounts and app-wide settings (item types/kinds, departments, work log categories).

## Roles

| Role | Can do |
|---|---|
| **Staff** | View Inventory/Deployments/Reports; use the Deployments area fully (deploy items, mark returns, add departments) |
| **Admin** | Everything Staff can, plus Inventory item CRUD, Staff account management, and Settings |
| **Superadmin** | Everything Admin can, plus exclusive access to "Add" actions across Inventory/Deployments; cannot be disabled, deleted, or demoted by an Admin |

The superadmin account is **never created through the site's own UI** — it only exists after running `setup.php`.

## Requirements

- XAMPP (or any Apache + PHP + MySQL/MariaDB stack)
- PHP with the `mysqli` extension enabled

## Installation

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Place this project folder inside your `htdocs` directory (e.g. `htdocs/c1so_tech`).
3. Open `http://localhost/phpmyadmin`, click **Import**, choose `database.sql`, and click **Go**. This creates the `c1so_tech` database and all tables.
4. Visit `http://localhost/c1so_tech/setup.php` in your browser once. This generates the default **admin** and **superadmin** accounts with password hashes matching your server's PHP version. Safe to re-run — it won't overwrite a password that's already been set.
5. Go to `http://localhost/c1so_tech/login.php` and log in.

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

## Migrations

If you're upgrading an existing installation rather than doing a fresh import, run the migration scripts **in version order (v2 → v15)** in your browser — each is meant to be run once (e.g. `http://localhost/c1so_tech/migrate_v2_add_activity_log_module.php`). Fresh installs using the latest `database.sql` don't need these. What each one does:

| File | What it does |
|---|---|
| `migrate_v2_add_activity_log_module.php` | Adds a `module` column to `activity_log` so the sidebar log can filter by area, and backfills old rows |
| `migrate_v3_inventory_batches_and_property_number.php` | Adds `inventory_batches` (restock history), adds `date_added`/`low_stock_threshold` to items, moves property number to per-deployment entry |
| `migrate_v4_add_messages_table.php` | Adds the internal `messages` table |
| `migrate_v5_add_po_number_to_batches_and_deployments.php` | Adds a PO/Receipt Number field to batches and deployments |
| `migrate_v6_add_inventory_remarks_and_disposal_flags.php` | Adds `inventory_remarks` (quick per-item remarks) and `inventory_disposal_flags` (auto-flagging after 30+ days inactive) |
| `migrate_v7_add_deployment_batch_consumption_fifo.php` | Adds `deployment_batch_consumption` for FIFO batch traceability on deployments |
| `migrate_v8_add_department_people_and_recipient_name.php` | Adds `department_people` (faculty/staff list per department) and a `recipient_name` field on deployments |
| `migrate_v9_add_superadmin_role.php` | Adds the `superadmin` role and seeds the default Super Admin account |
| `migrate_v10_rename_po_to_pn_and_add_deployment_time_type.php` | Renames PO Number → PN Number, widens Details text, drops Condition, adds deployment date/time and deployment type |
| `migrate_v11_add_po_number_and_batch_time.php` | Re-adds PO Number alongside PN Number on batches/deployments, adds batch time |
| `migrate_v12_add_recondition_pullout_and_item_details.php` | Adds Recondition/Pullout Unit deployment types and a per-item Details field |
| `migrate_v13_add_serial_numbers_and_mr_number.php` | Adds `inventory_batch_serials` (one row per physical unit's Serial Number in a restock batch) and `mr_number` (Material Request Number) on `deployments` |
| `migrate_v14_add_deployment_specs.php` | Adds `deployment_specs` (older fixed-column spec sheet — superseded by v15, left in place for any data already saved under it) |
| `migrate_v15_add_flexible_specs_and_quick_pick_kinds.php` | Adds `deployment_spec_values` and `inventory_item_spec_values` (flexible key/value Specifications, one field set per Kind of Item — PC, Laptop, Projector), and seeds those three exact Kind of Item entries in Settings if missing |

**⚠️ Run all migrations up through `migrate_v15` before using the updated Add/Restock form, Deployment wizard, or Deployed Items tab** — without them, restock/deployment submissions will fail, and this can also surface as unrelated-looking symptoms elsewhere in the app (e.g. buttons on other pages appearing to do nothing) until they're run.

## Notes

- Built for local/LAN use via XAMPP; no external services required.
- The public work log view requires no login — don't put sensitive info in entries you don't want publicly visible.
- **Restocking**: one Add/Restock submission = one delivery = one PN Number + one PO Number, shared across every item row in that submission. Add as many item rows as the delivery actually contains (different Kind of Item, different Item Model, different Quantity each) — each row becomes its own batch under the same PN/PO. Serial Numbers are optional and per physical unit (a row with Quantity 3 gets 3 serial fields).
- **Deployments**: every deployment requires both a PN Number (selected from existing stock batches) and an MR Number (typed in, one per whole deployment submission — covers every item added to that deployment's cart). The wizard always starts fresh — no in-progress deployment is ever saved or resumed between opens.
- **Quick-pick Kind of Item (PC / Laptop / Projector)**: right after choosing New/Replacement/Recondition/Pullout, the wizard asks which of these three (or "Other") the deployment is for. Picking one pre-filters the next step straight to that exact Kind of Item and switches on that kind's own Specifications fields for the Review step. This requires "PC/System Unit", "Laptop", and "Projector" to exist as exact Kind of Item names in Settings → Item Types (seeded automatically by `migrate_v15` if missing) — otherwise that quick-pick button is shown disabled with a note.
- **Unit Specifications**: on the Review step, fill in that Kind of Item's own field set — **PC**: Casing, Processor, Motherboard, RAM, SSD, PSU, GPU, Specs Note; **Laptop**: Brand, Model, Processor, Storage; **Projector**: Brand, Class/Type, Remote. Fields are small placeholder-style text boxes, not a large form. If the picked Item Model already has preset specs from a prior restock, they auto-fill here (still editable). Click any row in Inventory → **Deployed Items** to view that unit's full specs in a popup. The same Specifications fields also appear on the Inventory Add/Restock form per row, once a PC/Laptop/Projector Kind of Item is picked there — whatever's filled in becomes that model's default preset for future deployments.
- **Keyboard, Mouse & Monitor**: for a PC deployment specifically, the Review step also shows optional Keyboard/Mouse/Monitor pickers — each is picked from stock (not typed), becomes its own deployment record, and is deducted from its own stock like any other item. Requires exact "Keyboard", "Mouse", and "Monitor" Kind of Item entries in Settings; if any are missing, that picker is disabled with a note. In the Deployed Items popup, any Keyboard/Mouse/Monitor deployed alongside a unit (same MR No., department, recipient, date, and time) shows under "Peripherals".
- **Philippine Time**: every date/time shown or defaulted anywhere in the app — deployment date/time, restock date/time, "today" in any form — uses Asia/Manila time (set once, globally, in `includes/db.php`), regardless of the server's own system timezone. The wizard's date/time fields also re-fetch the current Philippine Time from the server (not the browser) every time it's reset.
