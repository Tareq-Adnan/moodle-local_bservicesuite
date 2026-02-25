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
 * TODO describe file upload_users
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_bservicesuite\forms\bservicesuite_uploaduser_form1;
require('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
require_capability('moodle/user:create', context_system::instance());

core_php_time_limit::raise(60 * 60); // 1 hour should be enough.
raise_memory_limit(MEMORY_HUGE);

$PAGE->set_url(new moodle_url('/local/bservicesuite/pages/upload_users.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('uploadusers', 'local_bservicesuite'));
$PAGE->set_heading(get_string('uploadusers', 'local_bservicesuite'));

$form = new bservicesuite_uploaduser_form1();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/bservicesuite/pages/upload_users.php'));
} else if ($data = $form->get_data()) {
    // Process the uploaded file.
    $content = $form->get_file_content('csvfile');

    if (!$content) {
        \core\notification::error(get_string('filenotreadable', 'local_bservicesuite'));
        redirect($PAGE->url);
    }

    // Process upload.
    $uploader = new \local_bservicesuite\upload\csv_user_upload();

    $options = [
        'encoding' => $data->encoding,
        'delimiter' => $data->delimiter,
        'updateexisting' => !empty($data->updateexisting),
        'createnewcohorts' => !empty($data->createnewcohorts),
    ];

    try {
        $results = $uploader->process_upload($content, $options);

        // Display results.
        echo $OUTPUT->header();
        // In upload.php results section:.

        // Display immediate results.
        echo $OUTPUT->heading(get_string('immediateimportresults', 'local_bservicesuite'), 3);

        $table = new html_table();
        $table->head = [
            get_string('operation', 'local_bservicesuite'),
            get_string('count', 'local_bservicesuite'),
        ];

        $table->data = [
            [get_string('studentscreated', 'local_bservicesuite'), $results['students_created']],
            [get_string('studentsupdated', 'local_bservicesuite'), $results['students_updated']],
            [get_string('parentscreated', 'local_bservicesuite'), $results['parents_created']],
            [get_string('parentsupdated', 'local_bservicesuite'), $results['parents_updated']],
            [get_string('cohortscreated', 'local_bservicesuite'), $results['cohorts_created']],
            [get_string('cohortsexisting', 'local_bservicesuite'), $results['cohorts_existing']],
            [get_string('enrollmentssuccess', 'local_bservicesuite'), $results['enrollments']],
            [get_string('passwordsgenerated', 'local_bservicesuite'), $results['passwords_generated']],
            [get_string('emailsqueued', 'local_bservicesuite'), $results['emails_queued']],
        ];

        echo html_writer::table($table);

        // Display generated passwords for immediate download.
        if (!empty($results['generated_passwords'])) {
            echo $OUTPUT->heading(get_string('generatedpasswords', 'local_bservicesuite'), 3);

            echo html_writer::tag('p', get_string('passwordswillbeemailed', 'local_bservicesuite'));

            // Show sample of passwords (first 5).
            $sample = array_slice($results['generated_passwords'], 0, 5);

            $passwordtable = new html_table();
            $passwordtable->head = [
                get_string('type', 'local_bservicesuite'),
                get_string('username'),
                get_string('name'),
                get_string('email'),
                get_string('password'),
            ];

            foreach ($sample as $pwd) {
                $passwordtable->data[] = [
                    get_string($pwd['type'], 'local_bservicesuite'),
                    $pwd['username'],
                    $pwd['name'],
                    $pwd['email'],
                    html_writer::tag('code', $pwd['password']),
                ];
            }

            echo html_writer::table($passwordtable);

            if (count($results['generated_passwords']) > 5) {
                echo html_writer::tag(
                    'p',
                    get_string(
                        'showingfirstxofy',
                        'local_bservicesuite',
                        ['x' => 5, 'y' => count($results['generated_passwords'])]
                    )
                );
            }
        }

        // Batch status and tracking.
        echo $OUTPUT->box_start('generalbox', 'batchinfo');
        echo html_writer::tag('h4', get_string('batchinfo', 'local_bservicesuite'));

        $batchurl = new moodle_url('/local/bservicesuite/batchstatus.php', ['id' => $results['batchid']]);
        echo html_writer::tag(
            'p',
            get_string('batchid', 'local_bservicesuite') . ': ' .
            html_writer::link($batchurl, $results['batchid'])
        );

        echo html_writer::tag(
            'p',
            get_string('emailsqueueddesc', 'local_bservicesuite')
        );

        if ($data->forcenotification) {
            echo html_writer::tag(
                'p',
                html_writer::tag('strong', get_string('notificationwillbesent', 'local_bservicesuite'))
            );
        }

        echo $OUTPUT->box_end();
        if (!empty($results['errors'])) {
            echo $OUTPUT->heading(get_string('errors', 'local_bservicesuite'), 3);
            echo html_writer::start_tag('ul', ['class' => 'upload-errors']);

            foreach ($results['errors'] as $error) {
                echo html_writer::tag('li', $error);
            }

            echo html_writer::end_tag('ul');
        }
        echo $OUTPUT->continue_button(new moodle_url('/local/bservicesuite/pages/upload_users.php'));

        echo $OUTPUT->footer();
    } catch (Exception $e) {
        \core\notification::error($e->getMessage());
        redirect($PAGE->url);
    }
} else {
    // Display the form.
    echo $OUTPUT->header();

    // Display CSV format instructions.
    echo $OUTPUT->box_start('generalbox', 'csvinstructions');
    echo $OUTPUT->heading(get_string('csvformat', 'local_bservicesuite'), 3);

    echo html_writer::tag('p', get_string('csvformatdesc', 'local_bservicesuite'));

    echo $OUTPUT->box_end();

    $form->display();

    echo $OUTPUT->footer();
}
