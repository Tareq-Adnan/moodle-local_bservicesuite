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
 * Class upload_form1
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_bservicesuite\forms;

use core_text;
use csv_import_reader;
use html_writer;
use moodle_url;
use moodleform;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
/**
 * Form class for handling CSV file uploads and processing
 *
 * This form allows users to:
 * - Upload a CSV file containing user data
 * - Select the file encoding
 * - Choose the CSV delimiter
 * - Set options for updating existing records and creating new cohorts
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bservicesuite_uploaduser_form1 extends moodleform {
    /**
     * Defines the form elements and structure
     *
     * This method sets up all the form elements including:
     * - File upload field for CSV
     * - Encoding selection
     * - Delimiter selection
     * - Email notification options
     * - User update options
     * - Cohort creation options
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'settingsheader', get_string('upload'));

        $url = new moodle_url('example.csv');
        $link = html_writer::link($url, 'example.csv');
        $mform->addElement('static', 'examplecsv', get_string('examplecsv', 'tool_uploaduser'), $link);
        $mform->addHelpButton('examplecsv', 'examplecsv', 'tool_uploaduser');

          // File upload
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('csvfile', 'local_bservicesuite'),
            null,
            ['accepted_types' => '.csv']
        );
        $mform->addRule('csvfile', null, 'required');

        // Encoding selection
        $encodings = core_text::get_encodings();
        $mform->addElement(
            'select',
            'encoding',
            get_string('encoding', 'local_bservicesuite'),
            $encodings
        );
        $mform->setDefault('encoding', 'UTF-8');

        // Delimiter selection
        $delimiters = [
            'comma' => get_string('comma', 'local_bservicesuite'),
            'semicolon' => get_string('semicolon', 'local_bservicesuite'),
            'tab' => get_string('tab', 'local_bservicesuite'),
        ];
        $mform->addElement(
            'select',
            'delimiter',
            get_string('delimiter', 'local_bservicesuite'),
            $delimiters
        );
        $mform->setDefault('delimiter', 'comma');

        // Email options
        $mform->addElement('header', 'emailheader', get_string('emailoptions', 'local_bservicesuite'));

        $mform->addElement(
            'advcheckbox',
            'emailpassword',
            get_string('emailpassword', 'local_bservicesuite'),
            get_string('emailpassworddesc', 'local_bservicesuite')
        );
        $mform->setDefault('emailpassword', 1);

        $mform->addElement(
            'advcheckbox',
            'forcenotification',
            get_string('forcenotification', 'local_bservicesuite'),
            get_string('forcenotificationdesc', 'local_bservicesuite')
        );
        $mform->setDefault('forcenotification', 0);

        // User options
        $mform->addElement('header', 'userheader', get_string('useroptions', 'local_bservicesuite'));

        $mform->addElement(
            'advcheckbox',
            'updateexisting',
            get_string('updateexisting', 'local_bservicesuite'),
            get_string('updateexistingdesc', 'local_bservicesuite')
        );
        $mform->setDefault('updateexisting', 1);

        // $mform->addElement(
        //     'advcheckbox',
        //     'forcepasswordchange',
        //     get_string('forcepasswordchange', 'local_bservicesuite'),
        //     get_string('forcepasswordchangedesc', 'local_bservicesuite')
        // );
        $mform->setDefault('forcepasswordchange', 1);

        // Cohort options
        $mform->addElement('header', 'cohortheader', get_string('cohortoptions', 'local_bservicesuite'));

        $mform->addElement(
            'advcheckbox',
            'createnewcohorts',
            get_string('createnewcohorts', 'local_bservicesuite'),
            get_string('createnewcohortsdesc', 'local_bservicesuite')
        );
        $mform->setDefault('createnewcohorts', 1);

        // Submit button
        $this->add_action_buttons(false, get_string('uploadusers', 'local_bservicesuite'));
    }

    /**
     * Validates the form data
     *
     * @param array $data The form data to validate
     * @param array $files Any file upload data
     * @return array Array of validation errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $errors;
    }
}
