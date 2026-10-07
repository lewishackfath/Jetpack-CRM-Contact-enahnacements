# Course Certificates for Jetpack CRM

A standalone WordPress plugin for **Jetpack CRM 6.8.5**, with **MailPoet** audience preparation.

## Install

1. In a staging copy of your WordPress site, activate Jetpack CRM 6.8.5 and MailPoet.
2. Upload `dist/jetpack-crm-courses-1.0.4.zip` through **Plugins → Add Plugin → Upload Plugin**, then activate it. If replacing an existing installation, choose **Replace current with uploaded**.
3. Open **Courses → Course types**. Add **First Aid**, valid for **1 year**.
4. Open a CRM contact, select **Courses & certificates**, and click **Add course date**.
5. Select the course, enter its date, and upload a PDF, JPEG or PNG certificate. The expiry is calculated when saved. The form stays on the contact, and saving returns to its **Courses & certificates** tab.

The source folder can also be installed as `wp-content/plugins/jetpack-crm-courses/`. It requires no build step or Composer dependencies. WordPress 6.5+, PHP 7.4+, PHP Fileinfo, and an InnoDB-capable database are required. Network activation is intentionally refused; activate separately on each multisite site.

## Updates from GitHub

Version **1.0.1** adds updates from this [GitHub repository](https://github.com/lewishackfath/Jetpack-CRM-Contact-enahnacements). Following the repository rename, existing versions **1.0.0–1.0.3** need a one-time manual upgrade: upload the **1.0.4** ZIP through WordPress and choose **Replace current with uploaded**. Earlier updaters check asset URLs against the old repository name, so GitHub's redirect alone cannot deliver this fix automatically. Keep the plugin active so its update checker runs. The installed directory should be `jetpack-crm-courses`; use the packaged ZIP rather than GitHub's **Code → Download ZIP** or **Source code** archives.

After that, newer published stable releases appear under **Plugins** and **Dashboard → Updates**. Click **Update now** to install, or turn on **Enable auto-updates** on the plugin row to let WordPress install future releases automatically. Your existing automatic-update preference is preserved. Course records and certificates stay in the database during updates.

Use **Check GitHub for updates** on this plugin's row to refresh immediately, or **Dashboard → Updates → Check again**. Otherwise WordPress checks on its normal schedule; GitHub metadata is cached for six hours. A failed request or incomplete release is cached for five minutes. Update checks need outbound HTTPS access to GitHub and its release download hosts. This implementation uses public releases without a GitHub token; changing the repository to private would require a different authenticated delivery setup. No contact or certificate data is included in update requests.

### Publishing a new version

1. Change the `Version` header and `JPCRM_COURSES_VERSION` in `jetpack-crm-courses.php`, and the `Stable tag` in `readme.txt`, to the same version, for example `1.0.4`. Add a changelog entry and adjust requirements if needed.
2. Run the tests and `python3 scripts/build.py --tag v1.0.4`. Commit and push the source changes, including `.github/workflows/release.yml`, to GitHub.
3. In **GitHub → Releases → Draft a new release**, create tag `v1.0.4` on that commit, write the release notes, and publish it as a stable release marked **Latest**. The first release for this update-enabled version is `v1.0.1`.
4. Wait for **Actions → Package plugin release** to succeed. It checks PHP syntax and updater behaviour, validates the tag against the plugin version, and attaches `jetpack-crm-courses-1.0.4.zip` plus `jetpack-crm-courses-update.json` to that release. WordPress only offers releases after both assets are present.
5. Check for updates on staging and install the new version there before updating production.

GitHub Actions must be enabled with permission for the workflow's `GITHUB_TOKEN` to write release assets (`contents: write`, declared in the workflow). No personal token or WordPress credentials are needed. This workflow uploads assets **after publishing**; if your repository enables immutable releases, instead build locally, attach both files to the draft, and publish the fully prepared release. The same manual asset-upload process works if Actions is unavailable. A published release should not have its version reused; publish a higher version for fixes.

Ordinary commits, bare tags, drafts and prereleases do not trigger WordPress updates. The updater uses GitHub's **Latest release**, requires a `major.minor.patch` tag (optional `v` prefix), checks asset URLs belong to this repository/tag, and reads the new release's WordPress/PHP requirements from the generated manifest. GitHub's automatic source archives are never used as install packages. Release notes are shown as escaped text in WordPress's plugin details dialog.

## January 2027 First Aid mailout

1. Open **Courses**, or **Contacts → Course certificates** in the CRM top menu.
2. Select **First Aid**, set **Expiry month** to **January 2027**, and apply the filters.
3. Optionally check **Latest course per contact only** to exclude historical certificates superseded by a later course record.
4. Check individual records, select every record on the displayed page, or choose **All records matching these filters**. The latter includes results on other pages.
5. Name the audience and click **Prepare audience**. The plugin creates a uniquely named MailPoet list and processes 25 contacts per request.
6. Review the added, skipped and failed counts. Open **MailPoet emails**, create a regular email, and select the exact generated list in MailPoet's recipient selector. Compose, preview and send there.

**The handoff creates an audience list; it does not create a draft campaign or preselect recipients inside the MailPoet editor.** MailPoet's supported public PHP API manages subscribers/lists, and this plugin leaves composition and delivery in MailPoet. No separate Jetpack CRM Mail Campaigns extension is needed. The CRM MailPoet Sync module is optional for this plugin, although it can help maintain your subscriber/contact data.

Only existing MailPoet subscribers with global status `subscribed` and no trash marker are included. CRM **Do Not Email** flags are respected. Missing/invalid email addresses, unsubscribed/unconfirmed/bounced/inactive subscribers and contacts not found in MailPoet are skipped and reported. The plugin does not silently subscribe or reactivate recipients. Review and establish subscriptions in MailPoet before a future mailout if contacts are missing there.

Duplicate records are collapsed by contact, and duplicate email addresses are included once. Each audience is a snapshot; later expiry edits or renewals do not automatically update it. Prepare a fresh audience before a later campaign. Lists remain in MailPoet until you remove them there. Subscription changes made later are still subject to MailPoet's sending rules.

Up to 5,000 matching course records can be prepared at once. Narrow the course or date range if necessary. Keep the preparation page open; it can resume using the same URL for 24 hours. Partial failures are reported rather than presented as a fully successful export. API confirmation/welcome emails and admin subscriber notifications are disabled for list additions. Existing site automations listening to list-membership changes may still run, so review those in staging.

## Course records and dates

- Each contact may have multiple records for each course, with a separate certificate on each record.
- Add and edit records inside the contact’s **Courses & certificates** tab. Drag one PDF, JPEG or PNG onto the upload box, or use **Choose file**. The chosen filename and size are displayed before saving.
- Save is disabled while submitting. A per-form submission token and a unique database key prevent repeated or simultaneous submissions from creating duplicate records or orphan certificate uploads. Opening a fresh form still allows an intentional additional record for the same course/date.
- **Courses → Course register** and **Courses → Course types** are separate admin pages. The register’s **Send an Email** section prepares the MailPoet audience described above.
- Configure expiry in days, calendar months, calendar years, or no expiry. February 29 plus one year becomes February 28; January 31 plus one month becomes February's final day.
- A certificate is current through its expiry date, using the WordPress site timezone. It becomes expired the following day. Dates are displayed as `YYYY-MM-DD`.
- Each record stores its validity rule and calculated expiry. Changing a course type's validity affects new records only. Editing an existing record's date uses its stored rule; choosing a different type uses that type's current rule.
- Course types can be archived without destroying history. Rename changes the name displayed on associated records. Archived types cannot be assigned to new records.
- Records can be edited, their certificates replaced, or explicitly deleted. Concurrent edits are checked using a record version.
- From version 1.0.3, creating, editing, replacing a certificate or deleting a course record adds a native Note in the contact's **Activity** log. Each entry shows the responsible CRM user and timestamp; expand it for the course, date, expiry and changes. The actor's name and WordPress user ID are also retained in the entry. Notes changes are identified without copying their contents or the certificate into the log.
- Logging starts after this upgrade; older activity is not reconstructed. Saving unchanged fields or retrying a submission does not add duplicate activity. A failed log write rolls back the course change and its upload. Course and CRM log tables must use InnoDB for transactional rollback. Global course-type configuration changes are not written to individual contacts' logs.
- The register supports course, contact name/email, expiry month, inclusive date range, current/expired/30-day/no-expiry status, and latest-record filters. Month and explicit date range are mutually exclusive.
- “Latest” means greatest course date, with record ID breaking same-date ties; future-dated records also count as later records.

## Permissions and certificate storage

| Action | Required WordPress capabilities |
| --- | --- |
| View register and certificates | `admin_zerobs_view_customers` |
| Add, edit, replace or delete records | View permission plus `admin_zerobs_customers` |
| Configure course types | `admin_zerobs_manage_options` and contact-view access to the admin page |
| Prepare MailPoet audiences | Edit permission plus `admin_zerobs_sendemails_contacts`, `mailpoet_manage_subscribers`, `mailpoet_manage_segments`, `mailpoet_manage_emails` |

Administrators normally have these capabilities when the relevant plugins are active. CRM contact managers may need their MailPoet permissions configured. The plugin does not grant additional role capabilities. It uses Jetpack CRM's contact access and ownership helpers; Jetpack CRM 6.8.5 itself currently allows shared contact visibility for users with its contact-view capability.

Certificates are stored as base64 data in a separate database table, **not in the public WordPress Media Library or uploads directory**. Viewing/downloading requires an authenticated user with contact access and a valid nonce. PDF/JPEG/PNG MIME types and signatures are checked; files are capped at 5 MB, also subject to PHP's upload limits. Database backups therefore include the certificates; base64 increases storage size by approximately one third. Allow enough space and a `max_allowed_packet` of at least 16 MB for full-size uploads.

## Retention and current boundaries

- Deactivating or deleting the plugin preserves its tables and certificates. Back up the database before upgrades. Reinstalling the plugin restores access to preserved records.
- Deleting a course record removes its certificate. Replacing a certificate removes the previous file after a successful save.
- Course activity remains in the contact's CRM log after a course record is deleted or this plugin is deactivated. These are standard CRM notes, governed by CRM's normal note permissions and retention.
- Contact deletion and CRM contact merging do not currently cascade or transfer course records. Records whose contact no longer exists are retained in the database but omitted from the register and inaccessible through the certificate endpoint. Preserve/reassign records before merging or removing contacts; there is no orphan-recovery UI in this release.
- These custom tables are not included in Jetpack CRM's standard CSV exports or WordPress personal-data export/erasure tools. Manage course data explicitly and include the tables in backups/retention procedures.
- No automatic reminders, expiry cron jobs, client-portal access, course CSV import or native CRM segment conditions are included.
- For MailPoet setup, sender authentication, test delivery and actual sending, use MailPoet's normal configuration. Production email delivery has not been tested by this project.

## Development and verification

See [tests/README.md](tests/README.md). The integration suite runs against real WordPress, Jetpack CRM and MailPoet, and requires an explicitly marked disposable localhost database. The initial release was verified with WordPress 7.1.3, Jetpack CRM 6.8.5, MailPoet 5.41.0, PHP 8.5.8 and MariaDB 12.3.2. Older supported WordPress/PHP versions have not been exercised.

Rebuild the distributable with:

```sh
python3 scripts/build.py
```

Integration references: [Jetpack CRM contact tabs](https://kb.jetpackcrm.com/knowledge-base/adding-custom-tabs-to-contact-view-or-company-view/), [MailPoet public PHP API](https://github.com/mailpoet/mailpoet/blob/trunk/doc/Readme.md), [MailPoet list subscriptions](https://github.com/mailpoet/mailpoet/blob/trunk/doc/api_methods/SubscribeToLists.md), [WordPress external plugin updates](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/), [GitHub release events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#release). CRM hooks, permissions and DAL calls were also checked directly against the distributed 6.8.5 source.
