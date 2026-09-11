# Assembly by iBarakoTech — PHP / MySQL officer elections

A Bootstrap application for registered-member nominations, nominee acceptance, and officer voting. Designed for PHP 8.2+ with MySQL 8 or MariaDB, including Hostinger PHP hosting. The app uses locally served Bootstrap, PHPMailer for SMTP, and Endroid for QR codes; no JavaScript build server is needed.

## Open the local installation

XAMPP Apache and MySQL must be running.

1. Open **http://localhost/codite/setup.php**.
2. Copy the private setup key from `storage/setup-key.txt`.
3. Enter your name, your own admin email, and a password of at least 12 characters.
4. Create the account. Setup locks automatically after the first admin is created.

There is no shared default admin password. The local configuration and database have already been prepared. On another XAMPP machine, install dependencies with `composer install`, then run `php bin/configure-local.php` once.

The local configuration uses `mail.transport = log`. It does **not** send real emails. Read verification codes from `storage/mail.log` on your computer. This directory is blocked from web access by `.htaccess`. Local database name: `codite_assembly`.

## Election workflow

1. **Settings:** Choose an election title, maximum nominees per member, maximum votes per member, and number of officer positions. These are separate limits from 1 to 100. They lock once nominations open.
2. **Members:** Add first name, last name, optional middle initial, email, School, and Position individually, or import a CSV. CSV imports update existing members matched by email; individual entry still rejects duplicates. The register can be expanded through the nomination phase and locks afterward.
3. **Open nominations:** Share the nomination link or download its QR code. Emails not in the active member register display "This email is not registered. Please contact your administrator." and remain on the email-entry page. Registered members verify a six-digit email code, select between one and the configured maximum number of members, and submit once. Self-nomination is allowed.
4. **Close nominations:** The app enters nominee review and queues a private invitation email for every nominated member. Each nominee opens their link and confirms **Accept nomination** or **Decline nomination**. Acceptance automatically adds them to the official voting candidates. The admin can still record a confirmed response manually. Pending responses block voting.
5. **Open voting:** At least one nominee must accept. Share the separate voting QR/link. Members verify their email again. Only accepted nominees appear, and each member can submit one voting ballot with up to the configured number of selections.
6. **Close voting:** Results change from provisional to final. The top candidates with positive vote totals are marked elected up to the configured officer count. A tie across the last available position is flagged for administrator resolution under the organization's rules; tied candidates are not arbitrarily declared elected. This app does not automate a runoff. Fewer positive-vote candidates than positions leaves vacancies.
7. **Export:** Download nomination and voting tallies as CSV. Live tallies and exports are admin-only. Refresh a tally page to obtain current counts.

Submission is final. A member cannot submit again by refreshing, re-verifying, changing browsers, or scanning the QR again. QR codes contain participation URLs, not member information. Email addresses are shown in the admin register, not on member ballots. The database records which member submitted each ballot; this is not an anonymous ballot system.

## CSV template

Use UTF-8 CSV with these headers (the initial column may be blank):

```csv
first_name,last_name,middle_initial,email,school,position
Juan,Dela Cruz,A,juan@example.com,Central School,Teacher
Maria,Santos,,maria@example.com,Central School,Principal
```

The Members page provides a template download. Uploads are limited to 2 MB and 5,000 rows. All rows are validated before importing; malformed rows prevent the whole import. Quoted commas and a UTF-8 BOM are supported. Email addresses are normalized to lowercase.

## Verified resets

Every reset requires the current admin password, a fresh code delivered to the admin email, and typing `RESET`. No data is deleted when the code is requested.

| Reset option | Removes | Preserves | Next phase |
| --- | --- | --- | --- |
| Voting only | Voting submissions and choices | Members, nominations, nominee responses | Review |
| Nominations and votes | Both stages and nominee responses | Members | Draft |
| Everything | Members and all election participation | Admin account, settings, activity log | Draft |

All reset options expire existing member verifications and outstanding OTPs. Reset codes are tied to the selected scope and election generation. Deleted records cannot be recovered through the app; export results and retain a database backup when needed.

## Deploy to Hostinger

Use the packaged `release/assembly-hostinger.zip`, which includes the installed PHP dependencies but excludes local secrets, sessions, test data, and logs.

1. Select PHP **8.2 or newer** with `pdo_mysql`, `mbstring`, `openssl`, `iconv`, and sessions enabled. Create an empty MySQL/MariaDB database and a database user.
2. Upload and extract the release ZIP into the intended site folder, commonly `public_html` or a subfolder. Preserve all `.htaccess` files, including the files inside `app`, `vendor`, and `storage`.
3. Copy `config.example.php` to `config.local.php`. Keep `environment` as `production`. The public URL is centrally configured as **https://voting.ibarakotech.com** in `app/site.php`; production redirects, member links, and QR codes use it automatically.
4. Enter your hosting database host, port, database name, username, and password.
5. Generate different random values for `app_key` and `setup_key`. For example, run `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` twice on a trusted machine. Do not reuse the local setup key.
6. Create a sending mailbox. Set `mail.transport` to `smtp`, enter its full email address and mailbox password, and set `from_email` to that mailbox.
7. Enable HTTPS and force HTTPS access in your hosting settings. Allow PHP to write to `storage` (normally owner-writable directories, not world-writable permissions).
8. Visit `https://voting.ibarakotech.com/setup.php`, supply the private setup key, and create your administrator. The installer creates the tables in the configured database and then locks itself.
9. Add a test member you control. Open nominations and verify a real email delivery, ballot submission, and the admin reset code before inviting your members. Set final election limits in draft after your test reset.
10. Confirm private files return HTTP 403: `/storage/setup-key.txt`, `/app/schema.sql`, and `/config.local.php`. Download fresh QR codes after setting the public URL; a localhost QR cannot direct another phone to your hosted site.

For Hostinger Email, the supplied configuration defaults to `smtp.hostinger.com`, port `465`, encryption `ssl`. Port `587` with `tls` is also supported. Use the mailbox password, not your hosting account password. For a different email provider, use that provider's SMTP details. See [Hostinger's PHPMailer instructions](https://www.hostinger.com/tutorials/how-to-send-emails-using-phpmailer) and [Hostinger Email connection settings](https://www.hostinger.com/support/4305847-set-up-hostinger-email-on-your-applications-and-devices/).

Actual Hostinger deployment and outbound SMTP delivery have not been performed in this local build; they require your domain, database settings, and mailbox credentials.

## Technical behavior

- Prepared database queries, escaped output, CSRF protection, password hashing, and HttpOnly / SameSite cookies.
- In production, secure cookies require HTTPS. Admin sessions expire after 30 minutes of inactivity. Member verification also expires after 30 minutes.
- OTPs are stored as password hashes, expire after 10 minutes, are tied to email, stage, session, and election generation, and lock after five failures. Successful verification consumes the code.
- One OTP per email per 60 seconds; at most five email OTP requests per hour. IP request limits default to 1,000/hour for OTP requests and 1,000/15 minutes for verification, allowing shared event Wi-Fi. Optional `rate_limits.otp_ip_per_hour` and `rate_limits.verification_ip_per_15min` configuration values can tune these limits. Admin login has separate IP and email limits.
- Database transactions lock the election row when accepting ballots, changing phases, reviewing nominees, importing members, or resetting. A unique database constraint permits one submission per member and stage; another prevents duplicate candidate choices per submission.
- Atomic reset operations retain an admin activity record. Completed ballots and accepted nominee lists cannot be changed by the admin without a verified reset.
- SMTP errors are shown generically to members and written to the private application log. SMTP credentials are never rendered in the UI.
- Member names, membership status, and ballot choices are server-validated, even if someone bypasses the browser selection limits.
- Bootstrap and QR assets are served locally. No external QR service receives the election URL.
- For a non-Apache server, configure equivalent private-directory restrictions. PHP's bare development server does not enforce `.htaccess`; use XAMPP for the local installation.

## Tests and packaging

Run `composer test` to execute the core and HTTP integration suites. Tests require local mode and permission to create/drop isolated test databases; they do not reset the application's database. The HTTP suite uses a temporary local server and fixture directory and cleans up its own data.

Test coverage includes CSV validation/atomicity, membership eligibility, limits, duplicate submissions, phase restrictions, nominee acceptance, ranking and cutoff ties, OTP binding/expiry/lockout/replay, CSRF, full HTTP member and admin flows, exports, QR generation, and all reset scopes. Core queries are also tested with strict SQL grouping enabled.

Run `php bin/package.php` to rebuild the Hostinger ZIP. The release contains dependencies, so Composer is not required on the hosting server. For source installs, run `composer install --no-dev --optimize-autoloader` instead.

Application entry points: `index.php` and the one-time `setup.php`. Shared application logic is in `app/core.php`, page rendering in `app/views.php`, database tables in `app/schema.sql`, and Bootstrap customizations in `assets/app.css`.

## Deploy from this GitHub repository

The repository intentionally excludes `config.local.php`, installed Composer packages, generated ZIPs, and all private files in `storage`. Do not upload your local configuration to GitHub.

1. Deploy the `main` branch from `https://github.com/boybilis/codite-voting.git` into your Hostinger site folder.
2. In that folder, run `composer install --no-dev --optimize-autoloader` with PHP 8.2 or newer. If your hosting plan does not provide Composer/SSH, run this locally and upload the resulting `vendor` folder, or build and upload the release ZIP instead.
3. Create `config.local.php` on the server from `config.example.php`, entering Hostinger database credentials, SMTP mailbox credentials, and freshly generated keys. Keep `environment` as `production` to use the configured public domain.
4. Make `storage` writable by PHP, preserve the `.htaccess` files, and open `/setup.php` to create your admin account.
5. Follow the real email and private-file checks in the Hostinger deployment section above. The app does not automatically read Hostinger database or email settings.

Future pulls preserve your untracked configuration and local data. Keep server-side backups separately. Do not run `bin/configure-local.php` on production; that helper is only for XAMPP.

## School and Position upgrade

School (up to 160 characters) and Position (up to 120 characters, such as Teacher or Principal) are optional member profile fields. The Members page allows administrators to edit these fields during draft and nominations. They are shown on member ballots, nominee lists, results, and tally exports. Member and ballot searches include these fields.

Older four-column CSV files still work; add optional school and position columns to import these details. Matching emails update existing records; omitted optional columns keep their saved values. You can also use Save profile in the directory.

Existing deployments add the two database columns automatically on the first request after updating. This preserves members, nominations, and votes, and requires the application's database user to have ALTER permission on the members table. Existing profiles start with blank School and Position values.

## Production domain

The production website is **https://voting.ibarakotech.com**. The canonical URL is defined once in `app/site.php` and is used whenever `environment` is `production`, even if an older private configuration still contains a placeholder base URL. Local mode continues to use its own configured localhost address.

- Setup: https://voting.ibarakotech.com/setup.php
- Admin login: https://voting.ibarakotech.com/index.php?page=login
- Nominations: https://voting.ibarakotech.com/index.php?page=participate&stage=nomination
- Voting: https://voting.ibarakotech.com/index.php?page=participate&stage=voting

Deploy the latest main branch and download fresh QR codes if older codes were distributed. DNS, the Hostinger domain connection, and its HTTPS certificate are managed in your hosting account. Your SMTP sender must be an actual mailbox you control; the website domain does not create that mailbox.

## Application key error

The app reads `app_key` from the private `config.local.php` next to `index.php`. Editing `config.example.php` does not change an existing installation. A key must be a nonempty string, contain at least 32 characters, and no longer contain the template word REPLACE. The error explains which check failed without displaying the key.

To generate a key once, run `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` on a trusted machine or your hosting terminal. Paste the resulting 64-character value into the existing `app_key` entry, inside single quotes. Store the literal value; do not put a fresh random generation call in the configuration, since the key must stay the same across requests. Do not share or commit this private file.

If the loaded value still differs from the file you edited, confirm you edited the domain's actual deployed folder and that no later duplicate `app_key` entry overrides it. If necessary, restart PHP or clear its opcode cache through your hosting controls after saving.

## Download and restore the member register

Use **Members → Download members CSV**, or the same button beside the reset controls, to download all registered members regardless of search filters, pagination, or election phase. This download is restricted to signed-in administrators. It includes first name, last name, middle initial, email, School, and Position.

After an Everything reset, upload the downloaded file through **Members → Import a CSV**. A Voting only reset or Nominations and votes reset already preserves members. This backup restores member profiles, not nominations, votes, or results.

The export includes an assembly_backup_version column so the importer can reverse spreadsheet-safety escaping and restore the original text, including leading apostrophes. Keep that column intact. Existing ordinary CSV templates still work. The usual import limits apply: up to 5,000 members and 2 MB per upload; split larger registers into files retaining the header before importing.

## Automatic nomination invitations

Closing nominations queues one email per nominated member for the current election round, even if a member was nominated multiple times. Sending starts immediately and the admin page automatically processes the remaining queue in small requests. Keep an admin page open until it reports completion. Use the Nomination tally's Invitation email column to inspect delivery status, or **Resend email** to send another invitation to a pending nominee.

The private link opens a mobile-friendly confirmation page. **Accept nomination** saves Accepted immediately and places the member on the official voting candidate list; **Decline nomination** saves Denied. Merely opening the link never records a response, which prevents email-link scanners from making a choice. The link authenticates possession of the nominee's registered inbox, so another OTP is not required for this response. Regular voting still requires email OTP verification.

Links expire after 14 days and stop accepting responses when voting opens. Resets invalidate all old links. Responses cannot be overwritten by replaying a link or bypassing the confirmation form. The admin may correct a recorded response during review. If the admin changes a response back to Pending, use Resend email to issue a fresh usable invitation.

Failed emails stay visible for retry; a delivery problem does not reopen nominations or discard the queue. Sent means the SMTP server accepted the message, not guaranteed inbox delivery. Check mailbox credentials, sending quotas, and spam folders when needed. Retries normally reuse the same private link; resending an expired link replaces it. Mail delivery is at-least-once: a server interruption after SMTP accepts an email but before its status is saved can result in a duplicate invitation, but it cannot create a duplicate nominee or response.

If nominations were already closed before this update, click **Send / retry pending invitations** once to queue invitations for undecided nominees.

### Optional Hostinger background delivery

To continue sending even after the administrator closes the browser, configure a Hostinger PHP cron job to run **bin/send-invitations.php** every minute. Use the actual absolute path of your deployed script and a PHP 8.2+ interpreter, for example:

```text
php /absolute/path/to/your/site/bin/send-invitations.php
```

The worker is included in the Hostinger ZIP. It only runs from the command line, reads the existing private SMTP/database configuration, and processes queued invitations with a 45-second budget per run (an in-progress SMTP attempt may take longer). It shares claims with the browser sender so two workers do not normally send the same job concurrently. Failed emails require the admin's retry action; this prevents repeated automatic failures from exhausting mailbox quotas.

The cron job and actual Hostinger SMTP delivery must be configured/tested in your hosting account. Without the cron job, queued sending continues while an admin page is open and resumes when an admin reopens the application.

This update automatically creates the nominee_invitations table on an existing installation. The configured database account needs CREATE permission for this upgrade; existing membership and election data are retained.

### Nominee profile pictures
Nominees without a saved picture must upload a JPG, PNG, or WebP picture when accepting by email link (maximum 2 MB, 4096 pixels per side). A saved member picture is reused automatically; nominees can optionally upload a replacement. Resetting nominations and votes retains saved pictures when members are retained. Declining does not require a picture. Verified voters see pictures beside accepted candidates. Photos are stored privately in MySQL; the upgrade automatically creates the photo table. PHP fileinfo must be enabled, and upload_max_filesize must be at least 2M with post_max_size greater than 2M. A full member reset removes photos. CSV backups contain text profiles only; photos require a database backup or a fresh upload in the next nomination round. Existing manually accepted candidates remain eligible without a picture.

### Member status and CSV updates
Member profiles now include member_status: Officer or Member (case-insensitive on import). Existing and new profiles default to Member. CSV uploads match email addresses and update supplied profile columns; missing optional columns preserve existing values. Blank status values are rejected. New emails create members. Photos, IDs, nomination responses and ballots are retained. The member register remains editable only in draft or nomination phases. Download the updated CSV template from Members; backups now include member_status. The database column is added automatically on upgrade.

Only active profiles with member_status Member appear as nomination candidates. Officer profiles cannot be nominated, including by a manually submitted request. Officers retain their ability to verify membership and participate. Previously recorded nominations are not deleted when a profile status changes.

OTP request limits: a 60-second per-email cooldown and a configurable default of 20 requests per email per hour. Cooldown retries do not consume the hourly email allowance. Error messages identify the limit and remaining wait. Set rate_limits.otp_email_per_hour in config.local.php to customize; shared-network request and verification limits remain configurable separately. OTPs still expire in 10 minutes and allow five guesses.

### Temporary email-only member access
Member OTP is temporarily disabled by default for testing. Registered active members enter their email and proceed to nomination or voting without a code; unknown emails remain blocked. This does not verify mailbox ownership. Set member_otp_enabled to true in config.local.php to restore OTP; email-only sessions must then verify again. Administrator login, reset OTP, ballot limits and once-per-stage rules remain unchanged. Nominee invitation emails continue normally.
