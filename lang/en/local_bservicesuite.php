<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language pack for BS Service Suite
 *
 * @package    local_bservicesuite
 * @category   string
 * @copyright  2025 Brain Station 23 ltd <sales@brainstation-23.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Tarekul Islam <tarekul.islam@brainstation-23.com>
 */

defined('MOODLE_INTERNAL') || die();

$string['bsservicessuite:updateownprofile'] = 'Update own profile';
$string['bsservicessuite:view'] = 'View Analytics';
$string['cohortscreated'] = 'Cohorts Created';
$string['cohortsexisting'] = 'Cohorts Already Existed';
$string['comma'] = 'Comma';
$string['controllererror'] = 'Controller error occurred';
$string['count'] = 'Count';
$string['coursecreatefail'] = "Failed to create platform course";
$string['createnewcohorts'] = 'Create new cohorts';
$string['createnewcohortsdesc'] = 'Create cohorts if they don\'t exist';
$string['csvfile'] = 'CSV file';
$string['csvformat'] = 'CSV Format';
$string['csvformatdesc'] = 'Your CSV file must have the following columns in this exact order:';
$string['csvloaderror'] = 'Error loading CSV file';
$string['delimiter'] = 'Delimiter';
$string['downloadfailed'] = 'Failed to download backup from S3';
$string['editprofile'] = 'Edit Profile';
$string['encoding'] = 'Encoding';
$string['enrollmentssuccess'] = 'Cohort Enrollments';
$string['errorbackup'] = 'Error during backup process';
$string['errorrestore'] = 'Error during restore process';
$string['errors'] = 'Errors';
$string['extractfailed'] = 'Failed to extract backup file';
$string['filenotreadable'] = 'Could not read the uploaded file';
$string['missingcolumn'] = 'Missing required column: {$a}';
$string['operation'] = 'Operation';
$string['options'] = 'Options';
$string['parent'] = 'Parent';
$string['parentdescription'] = 'Parent role for viewing student information';
$string['parentscreated'] = 'Parents Created';
$string['parentsupdated'] = 'Parents Updated';
$string['pluginname'] = 'BS Service Suite';
$string['profileupdated'] = 'Profile updated successfully';
$string['semicolon'] = 'Semicolon';
$string['studentscreated'] = 'Students Created';
$string['studentsupdated'] = 'Students Updated';
$string['sync_user_task'] = 'Sync School user to platform';
$string['tab'] = 'Tab';
$string['updateexisting'] = 'Update existing users';
$string['updateexistingdesc'] = 'Update user information if username already exists';
$string['uploadfailed'] = 'Failed to upload backup to S3';
$string['uploadresults'] = 'Upload Results';
$string['uploadusers'] = 'Upload Users from CSV';
$string['useremailinvalid'] = 'User email invalid';
$string['usernotupdateddeleted'] = 'User not updated or deleted';
$string['usernotupdatedguest'] = 'User not updated or guest';
// Add to local_bservicesuite/lang/en/local_bservicesuite.php

$string['emailoptions'] = 'Email Options';
$string['emailpassword'] = 'Email password';
$string['emailpassworddesc'] = 'Email generated password to the user';
$string['useroptions'] = 'User Options';
$string['cohortoptions'] = 'Cohort Options';
$string['forcepasswordchange'] = 'Force password change';
$string['forcepasswordchangedesc'] = 'Users must change password on first login';
$string['forcenotification'] = 'Send completion notification';
$string['forcenotificationdesc'] = 'Send email notification when batch processing is complete';
$string['passwordsgenerated'] = 'Passwords Generated';
$string['emailsqueued'] = 'Emails Queued';
$string['emailsqueueddesc'] = 'Password emails have been queued for sending. They will be sent in the background.';
$string['passwordswillbeemailed'] = 'Passwords will be automatically emailed to users.';
$string['downloadallpasswords'] = 'Download All Passwords (CSV)';
$string['batchinfo'] = 'Batch Information';
$string['batchid'] = 'Batch ID';
$string['notificationwillbesent'] = 'You will receive an email notification when all emails have been sent.';
$string['immediateimportresults'] = 'Immediate Import Results';
$string['showingfirstxofy'] = 'Showing first {$a->x} of {$a->y} passwords';
$string['type'] = 'Type';
$string['student'] = 'Student';
// Email templates
$string['newuserpasswordemailgenerated'] = 'Welcome to {$a->site}!

Your account has been created with the following details:

Username: {$a->username}
Password: {$a->password}

You can log in at: {$a->url}

For security reasons, please change your password after first login.

{$a->signoff}';

$string['adminnotificationemail'] = 'Hello {$a->adminname},

The user import batch #{$a->batchid} has been completed.

Details:
- Completion time: {$a->timecompleted}
- Total users created: {$a->totalusers}
- Emails queued: {$a->emailsqueued}

You can view the full report at: {$a->url}

{$a->site}';

$string['adminnotificationemailsubject'] = 'User Import Completed - {$a}';
$string['generatedpasswords'] = "Generated Passwords";
$string['newuserpasswordemailsubject'] = "New user account";
$string['sent_assesment_report'] = "Sent Assessment Report";
