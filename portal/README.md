# Agent Activity Portal

## Features
- Role based Admin / Agent login
- Admin can create/disable agents
- Admin can reset agent passwords
- Admin can create/disable pause codes
- Active pause codes auto-refresh on agent panel every 30 seconds
- Agent login duration
- Idle duration
- Break duration
- Pause code wise tracking
- Live admin dashboard
- Realtime Activity panel with 2-second updates, agent search and activity filters; active sessions remain listed for the 9-hour session window even when heartbeats are delayed
- Login / Logout report with session timestamps, last seen, status, IP and CSV export
- Date range + Agent ID report
- CSV export
- Admin CSV/TSV upload for agent annual leave entitlement and monthly usage; agents can view their own total, used and pending leave
- Heartbeat / last_seen tracking
- Agent sessions automatically expire after 9 hours; dashboard heartbeats keep active agent sessions connected while the agent is idle.
- Expired sessions get their `logout_at` and open activity end time recorded on the next realtime/dashboard refresh, even if the agent browser has already stopped sending heartbeats.
- CSRF protection
- Passwords stored securely with PHP password_hash()

## Requirements
- Apache/Nginx + PHP 8.x
- PHP extensions: pdo_pgsql, pgsql
- PostgreSQL 12+

## Database
Configured in config.php:
Host: 192.168.128.151
Port: 5432
Database: mydb
User: postgres

## Install
1. Copy folder into your web root.
2. Run:
   psql -h 192.168.128.151 -p 5432 -U postgres -d mydb -f install.sql

3. Check PHP PostgreSQL extension:
   php -m | grep -E 'pgsql|pdo_pgsql'

4. Open:
   http://YOUR-SERVER/create_admin.php

5. Create first admin.

6. IMPORTANT: Delete create_admin.php after admin creation:
   rm create_admin.php

7. Login:
   http://YOUR-SERVER/login.php

## Apache example
If deployed under:
  /var/www/html/agentportal

Then either:
- configure a VirtualHost DocumentRoot to that folder, OR
- update absolute links `/login.php`, `/admin/...`, `/agent/...`, `/assets/...`
  to include `/agentportal`.

Best deployment is a dedicated vhost/subdomain.

## Important production recommendations
- Move DB password from config.php to environment variables or a protected config outside web root.
- Use HTTPS.
- Restrict database access by firewall.
- Do not store plaintext passwords.
- Back up PostgreSQL regularly.

## Supervisor role (existing installations)
Run `migrations/001_supervisor_role.sql` against the portal database before creating supervisor accounts:

```sh
psql -h YOUR_DB_HOST -U YOUR_DB_USER -d YOUR_DB_NAME -f migrations/001_supervisor_role.sql
```

Fresh installations already include this role in `install.sql`. Re-running `install.sql` does not update the role constraint on an existing users table; use the migration above.

As admin, open **User Management**, choose **Supervisor**, and create the account. Supervisors land on **Realtime Activity** and can access **Reports** (activity summary, login/logout history and CSV export), plus **LOB Assignments**. Overview, user creation, pause-code management, agent activity actions and admin creation remain restricted. The bootstrap admin page requires admin access once an admin already exists.

## LOB assignment
For an existing database, run `migrations/002_user_lob.sql` if it has not already been applied, then run `migrations/003_multi_lob_assignment.sql` before deploying these PHP files. The supervisor migration is also required if not already applied.

Admins can assign LOBs in **User Management**; supervisors can assign them in **LOB Assignments**. Both roles can use checkboxes to assign any combination of Sales, Collection and Backend to agents and supervisors. New accounts require at least one LOB. Existing agents and supervisors start unassigned and cannot log in again until an admin or supervisor assigns one. Admin login does not require a LOB.

Assigned LOBs are stored in canonical order and captured together at login, never taken from browser input. Assignment changes apply on the next login. Agent session history retains the combined LOB assignment captured at login; older sessions remain `Unassigned` because their original LOB is unknown. Realtime LOB filters match sessions assigned to one or more selected lines. Login/logout reports and CSV use the session's combined LOBs; activity summaries display the user's current assigned LOBs. LOB assignment does not introduce per-LOB report access restrictions. Activity summary reports include one row per agent per day in the selected date range, with daily login, idle and break time in both the page and CSV. Agent dashboards display live New York and India clocks after login.

## Remote agent logout
Admins and supervisors can use **Realtime Activity ? Logout user** (also available on the admin overview). Confirming closes only that selected agent session and its open activity. Agent heartbeats check every 2 seconds and redirect to login when the session has ended; background browser throttling or lost connectivity may delay the visible redirect. Closed sessions cannot resume activity. Logout time is included in the existing Login / Logout report. No additional database migration is needed for this feature.

## Agent leave balances
Fresh installs create the leave-balance table automatically. Existing databases must run `migrations/004_agent_leave_balances.sql` before deploying the leave pages.

Admins can open **Agent Leaves**, download the CSV template, add each agent's annual **Total Entitlement**, and fill the monthly leave used plus Grand Total. Upload a CSV or tab-separated TSV for the selected leave year. Required columns are `EMP ID`, Jan through Dec, `Grand Total`, and `Total Entitlement`; `Name`, `Status`, `Process`, `DOJ`, and `Tenure` are also accepted as optional metadata columns. The Grand Total must match the sum of the monthly values. EMP ID must match an agent account ID; an `OIT` prefix is supported for numeric IDs. Uploading the same agent/year again updates that balance.

Agents can choose **My Leave Balance** from their dashboard to see their own total entitlement, leave used, pending balance, any overuse, and the monthly breakdown. Agents cannot view other users' leave records.
