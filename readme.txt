=== Course Certificates for Jetpack CRM ===
Tags: jetpack crm, courses, certificates, expiry, mailpoet
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: zero-bs-crm
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Contact course history, private certificate uploads, expiry reporting and MailPoet audience preparation.

== Description ==

Adds a Courses & certificates tab to Jetpack CRM contact views and a Courses register in WordPress admin. Course types have configurable validity periods. Filter expiry dates and prepare selected contacts as a MailPoet list for an email campaign.

MailPoet recipients must already be active subscribers. Composition and sending take place in MailPoet after manually selecting the generated list. See README.md for requirements, access permissions, retention details and limitations.

== Installation ==

1. Activate Jetpack CRM, then upload and activate this plugin.
2. Configure Courses > Course types.
3. Open a CRM contact > Courses & certificates > Add course date.
4. Use Courses to filter expiry dates and prepare a MailPoet audience.

== Changelog ==

= 1.0.4 =
* Update GitHub repository and release URLs following the repository rename, and refresh cached release metadata.
* Existing installations require one manual ZIP upgrade because earlier updaters reject the renamed repository's asset URLs.

= 1.0.3 =
* Record course creation, edits, certificate replacements and deletions in the contact's native CRM activity log, with the responsible user and timestamp.
* Include course/date/expiry snapshots and changes, retaining the activity after a course record is deleted.
* Keep activity logging within the course transaction so failed saves and repeated submissions do not create misleading or duplicate entries.

= 1.0.2 =
* Add and edit certificates on the contact page and return to its Courses & certificates tab after saving.
* Prevent duplicate creation from repeated or simultaneous form submissions.
* Rename CRM Courses to Courses, with separate Course register and Course types pages.
* Rename the register's email section to Send an Email.
* Add certificate drag-and-drop upload with file selection feedback.

= 1.0.1 =
* Updates from published GitHub releases through the WordPress plugin updater.
* Release packaging workflow with version and compatibility metadata validation.

= 1.0.0 =
* Initial release for Jetpack CRM 6.8.5 and MailPoet.
