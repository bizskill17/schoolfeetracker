# School Manager – Student & Fee Management

A web app for a school office: complete student records, and the full fee cycle, from fee setup to collection, receipts, dues, late fees, concessions and year-end promotion.

Built with PHP 8 + MySQL. Runs on any normal shared hosting (Hostinger, etc.). No extra software needed.

---

## Deploying from GitHub to Hostinger

This repository deploys to Hostinger shared hosting over FTP with `.github/workflows/deploy.yml`.

1. **Repository secrets** (GitHub → Settings → Secrets and variables → Actions): `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, and `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. The workflow checks these exist; values are never printed.
2. **Where files go:** every push to `main` uploads the app with `lftp` into the FTP account's root folder, which on this Hostinger account is the website folder (`public_html`). Uploads only – nothing on the server is deleted.
3. **Never deployed (kept on the server):** `uploads/` (student photos and documents), `.env` files, `*.sql`, `*.zip`. Redeploying never overwrites school data or uploaded files, and the database is never touched by the deploy.
4. **First visit after the first deploy:** open `https://yourdomain/install/` once (see below). The database password is entered there and saved **on the server only**, in `schoolfee-config.php` one folder above `public_html`, where a browser can never reach it. Later deploys do not overwrite it.

### Database connection – how the app finds its settings
In this order: (1) environment variables `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`; (2) `schoolfee-config.php` one folder **above** the app; (3) `config.php` in the app folder (blocked from the web by `.htaccess`). No credentials are stored in this repository – see `config.sample.php` for the format.

### Existing data is safe
The installer only creates this app's own tables. If the database already has tables with the same names it stops **without changing anything**; if they are this app's own data you can tick "Connect to the existing School Manager data" to reuse them untouched. Tables from other applications are never touched. Database upgrades in new versions only add new tables/columns for this app.

## Install on Hostinger (about 15 minutes)

1. **Create a database**
   hPanel → **Databases → MySQL Databases** → create a database and user. Note the database name, username and password (host is usually `localhost`).

2. **Upload the files**
   Either push to `main` (GitHub Actions deploys over FTP, see above), or by hand: hPanel → **Files → File Manager** → open `public_html` (or a sub-domain folder) and upload/extract the files there. You should see `index.php`, `install`, `pages`, `assets`… directly inside that folder.

3. **Run the installer**
   Open `https://yourdomain/install/` (or `https://yourdomain/school/install/`).
   Enter the database details, school name, and create the administrator login. Tick "Add standard classes…" to start with Nursery–12 and common fee heads.

4. **Delete the `install` folder** in File Manager after it says "Installation complete".

5. **Turn on SSL** (hPanel → Security → SSL) so logins are encrypted. Use `https://`.

Requirements: PHP 8.0 or newer (hPanel → Advanced → PHP Configuration), MySQL 5.7+ / MariaDB 10.3+. Both are default on Hostinger.

---

## First-time setup (in this order)

| Step | Menu | What to do |
|---|---|---|
| 1 | Settings → School Profile | Name, address, logo, receipt prefix, payment modes, WhatsApp reminder text |
| 2 | Settings → Academic Years | Check the current year (e.g. 2026-27, 1 Apr – 31 Mar) |
| 3 | Settings → Classes & Sections | Add/rename classes and sections; set "promotes to" |
| 4 | Settings → Fee Heads | Tuition, transport, exam, annual, admission… and how often each is charged |
| 5 | Settings → Fee Structure | Amount of each fee for each class (per instalment) |
| 6 | Settings → Transport Routes | Routes and their fee |
| 7 | Settings → Concession Types | Sibling, staff ward, scholarship… |
| 8 | Settings → Due Date & Late Fee | Due day of month, late fee rule, grace days, RTE |
| 9 | Settings → Users & Roles | Logins for accountant, clerk, principal |
| 10 | Students → Import / New Admission | Add students; their fee schedule is created automatically |

---

## How fees work

- **Fee schedule:** each student gets a list of instalments for the year (e.g. Tuition Apr, Tuition May… Exam Half 1…) built from the class fee structure, the transport route, new/old student, and concessions. A student joining mid-year is charged from the joining month.
- **Collect Fee:** find the student → tick the instalments being paid (or type the amount the parent is paying and it fills the oldest first) → choose Cash / UPI / Cheque… → a numbered receipt prints (A5, with amount in words; 2-copy option; send on WhatsApp).
- **Partial payments and advance payments** are both supported.
- **Late fee:** worked out automatically (per day / per month / flat, grace days, maximum). Charged once per due date or per fee item, as you choose. Accountants and admins can reduce it; the reduction is recorded as a waiver.
- **Concessions:** % or fixed, on one fee head or all; RTE students are exempt automatically. Only unpaid instalments change.
- **Waivers and extra charges:** admin can waive part of a fee (with reason) or add a charge (books, trip, fine).
- **Receipt cancellation and cheque bounce:** admin only, with a reason. The fees become unpaid again, and a bounce charge can be added. Cancelled receipts stay on record.
- **Refunds:** caution deposit, excess payment, etc., with a refund number.
- **Year end:** Students → Promote / New Year moves students to the next class. Unpaid dues come along as "Previous Year Dues" and concessions continue.
- **Dues & Reminders:** who owes what as of any date, with late fee, oldest due date, a WhatsApp reminder button, CSV and print.

## Reports
Daily collection (by mode and by staff, for cash hand-over), day-wise totals, fee head-wise, class-wise summary, concessions & waivers, cancelled receipts, student strength. Every report exports to CSV (opens in Excel) and prints.

## Roles
| Role | Can do |
|---|---|
| Administrator | Everything |
| Accountant / Cashier | Collect fees, concessions, reduce late fees, view students and reports |
| Office Clerk | Add/edit students, view fees (cannot collect) |
| Viewer | View only (principal / management) |

Every important action (receipts, cancellations, waivers, concessions, logins, settings) is recorded in **Settings → Activity Log** with user, time and IP.

## Security
- Passwords are hashed, there is a login lockout after 5 wrong tries, and users are logged out after 2 hours idle.
- All forms are protected against forged requests, and all database queries are parameterised.
- Student photos and documents can only be opened by logged-in users.
- `config.php`, internal folders and the database schema are blocked from the web by `.htaccess`.

## Backups
**Settings → Backup** downloads the whole database as a `.sql` file. Do this weekly and always before year-end promotion. Also download the `uploads` folder (photos/documents) from File Manager now and then. To restore, import the `.sql` file in hPanel → phpMyAdmin.

## Troubleshooting
- **Blank page / "Something went wrong":** edit `schoolfee-config.php` (one folder above the app), set `'debug' => true`, reload to see the error, then set it back to `false`.
- **Forgot admin password:** in phpMyAdmin, run
  `UPDATE users SET password_hash = '$2y$10$M9Nx2PokIjXJiMOxWAudreToMsTwLlHSWDftjLUf9WG/FoY5ubUIi' WHERE username = 'admin';`
  This sets the password to `ChangeMe123`. Log in, then change it at once from the user menu.
- **Changed fees after admissions?** Fees → Update Fee Schedules applies the new amounts to unpaid instalments.
