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

namespace local_bservicesuite\utils;

use backup;
use backup_controller;
use local_bservicesuite\helper;
use moodle_exception;
use restore_controller;
use stdClass;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
/**
 * Class backup_helper
 *
 * @package    local_bservicesuite
 * @copyright  2026 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_helper {
    /**
     * Performs a backup of a Moodle course and uploads it to S3
     *
     * @param int $courseid The ID of the course to backup
     * @param int $userid The ID of the user performing the backup
     * @throws \moodle_exception If backup fails or backup file cannot be created
     * @return string $s3key The S3 key/path where the backup file was uploaded
     */
    public static function perform_backup($courseid, $userid) {

        // Create backup controller.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid, // Course ID.
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid
        );

        $plan = $bc->get_plan();

        // Disable user-related settings safely.
        $disablelist = [
            'users',
            'roleassignments',
            'groups',
            'comments',
            'completion',
            'logs',
            'gradehistories',
        ];

        $bc->set_status(backup::STATUS_AWAITING);
        foreach ($disablelist as $setting) {
            if ($plan->setting_exists($setting)) {
                $plan->get_setting($setting)->set_value(false);
            }
        }

        // Execute backup.
        $bc->execute_plan();

        // Get backup file (stored_file object).
        $results = $plan->get_results();
        $backupfile = $results['backup_destination'];

        if (!$backupfile) {
            throw new moodle_exception('errorbackup', 'local_bservicesuite');
        }

        return self::upload_to_s3($backupfile, $courseid);
    }

    /**
     * Restores a course from a backup file stored in S3
     *
     * @param string $s3key The S3 key/path where the backup file is stored
     * @param int $categoryid The category ID where the restored course should be placed
     * @throws moodle_exception If restore fails or backup file cannot be extracted
     * @return int The ID of the newly restored course
     */
    public static function perform_restore($s3key, $grade, $userid, $gradename, $ccourse, $schoolid) {

        $newgrade = self::check_grade_exists($gradename);
        $localfile = self::get_from_s3($s3key);

        $tempdir = restore_controller::get_tempdir_name($grade, $userid);
        $fulltempdir = make_backup_temp_directory($tempdir);
        $fb = get_file_packer('application/vnd.moodle.backup');
        $result = $fb->extract_to_pathname($localfile, $fulltempdir);

        if (!$result) {
            throw new moodle_exception('extractfailed', 'local_bservicesuite');
        }

        // Create new placeholder course.
        $newcourse = new stdClass();
        $newcourse->category = $newgrade->id;
        $newcourse->fullname = "temp_course_" . time() . "_" . $userid;
        $newcourse->shortname = "temp_" . time();
        $newcourse->visible = 1;
        $course = create_course($newcourse);

        $controller = new restore_controller(
            $tempdir,
            $course->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid,
            backup::TARGET_NEW_COURSE
        );

        if ($controller->get_status() == null) {
            throw new moodle_exception('controllererror', 'local_bservicesuite');
        }

        $controller->execute_precheck();
        $backupinfo = $controller->get_info();
        $originalshortname = $backupinfo->original_course_shortname;
        $uniqueshortname = $originalshortname;

        $controller->get_plan()->get_setting('course_fullname')->set_value($backupinfo->original_course_fullname);
        $controller->get_plan()->get_setting('course_shortname')->set_value($uniqueshortname);
        $controller->execute_plan();

        $newcourseid = $controller->get_courseid();
        $newcourse = get_course($newcourseid);

        $controller->destroy();

        // Clean up temp extraction directory.
        remove_dir($fulltempdir);
        self::update_school_course($ccourse, $newcourseid, $newgrade->idnumber, $schoolid);
        return $newcourse->id;
    }

    /**
     * Uploads a backup file to Amazon S3 storage
     *
     * @param \stored_file $backupfile The Moodle stored_file object containing the backup
     * @param int $courseid The ID of the course being backed up
     * @return string The S3 key/path where the file was uploaded
     * @throws moodle_exception If the upload fails
     */
    private static function upload_to_s3($backupfile, $courseid) {
        global $CFG;
        // Implement S3 upload logic here.
        $s3config = get_config('local_bservicesuite');
        $s3 = self::get_s3_bucket($s3config);

        $filename = 'course_' . $courseid . '_' . date('Ymd-His') . '.mbz';
        $s3key = "backups/course_$courseid/$filename";
        // For 500MB file, use multipart upload.
        $filesize = $backupfile->get_filesize();

        $deleted = self::clean_old_backups($s3, "backups/course_$courseid", $s3config);

        mtrace("deleted old backups: $deleted");
        mtrace("Uploading {$filename} ({$filesize} bytes) to S3...");

        // For large files (> 100MB), use multipart upload.
        if ($filesize > 100 * 1024 * 1024) {
            return self::multipart_upload($s3, $backupfile, $s3key, $s3config, $courseid);
        } else {
            return self::regular_upload($s3, $backupfile, $s3key, $s3config, $courseid);
        }
    }

    /**
     * Cleans up old backup files from S3 storage for a specific course
     *
     * @param \Aws\S3\S3Client $s3 The S3 client instance
     * @param string $directory The S3 directory path containing the backups
     * @param object $s3config Configuration object containing S3 settings
     * @param int $courseid The ID of the course whose old backups should be cleaned
     * @return int delete count
     */
    public static function clean_old_backups($s3, $directory, $s3config) {
        try {
            $objects = $s3->listObjects([
                'Bucket' => $s3config->aws_bucket,
                'Prefix' => $directory . '/',
            ]);

            if (!empty($objects['Contents'])) {  // Fixed: check 'Contents' key.
                $delete = ['Objects' => []];

                // Fixed: iterate over $objects['Contents'].
                foreach ($objects['Contents'] as $object) {
                    $delete['Objects'][] = ['Key' => $object['Key']];
                }

                // Only delete if there are objects to delete.
                if (!empty($delete['Objects'])) {
                    $s3->deleteObjects([
                        'Bucket' => $s3config->aws_bucket,
                        'Delete' => $delete,
                    ]);
                }

                return count($delete['Objects']);
            }

            return 0; // No files to delete.
        } catch (moodle_exception $e) {
            mtrace("Error cleaning old backups: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Handles multipart upload of large files to S3
     *
     * @param \Aws\S3\S3Client $s3 The S3 client instance
     * @param \stored_file $backupfile The Moodle stored_file object containing the backup
     * @param string $s3key The S3 key/path where the file should be uploaded
     * @param object $s3config Configuration object containing S3 settings
     * @return string The S3 key where the file was uploaded
     * @throws \moodle_exception If the upload fails
     */
    private static function multipart_upload($s3, $backupfile, $s3key, $s3config, $courseid) {
        global $CFG;
        mtrace("Using multipart upload for large file...");

        // Create a temporary local file since Moodle's stored_file doesn't support direct streaming.
        $tempfile = make_temp_directory('backups3') . '/' . uniqid('upload_');
        $backupfile->copy_content_to($tempfile);

        try {
            // Create MultipartUploader.
            $uploader = new \Aws\S3\MultipartUploader($s3, fopen($tempfile, 'rb'), [
                'bucket' => $s3config->aws_bucket,
                'key' => $s3key,
                'part_size' => ($s3config->chunk_size ?? 50) * 1024 * 1024,
                'concurrency' => $s3config->concurrent_uploads ?? 3,
                'params' => [
                    'ContentType' => 'application/vnd.moodle.backup',
                ],
            ]);

            // Execute upload.
            $result = $uploader->upload();

            mtrace("Multipart upload completed: " . $result['ObjectURL']);
        } catch (\Aws\Exception\MultipartUploadException $e) {
            mtrace("Multipart upload failed: " . $e->getMessage());
            throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', $e->getMessage());
        } finally {
            // Clean up temp file.
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }
        self::update_coursepath($courseid, $s3key);
        return $s3key;
    }

    /**
     * Handles regular upload of files to S3 (for files smaller than 100MB)
     *
     * @param \Aws\S3\S3Client $s3 The S3 client instance
     * @param \stored_file $backupfile The Moodle stored_file object containing the backup
     * @param string $s3key The S3 key/path where the file should be uploaded
     * @param object $s3config Configuration object containing S3 settings
     * @return string The S3 key where the file was uploaded
     * @throws \moodle_exception If the upload fails
     */
    private static function regular_upload($s3, $backupfile, $s3key, $s3config, $courseid) {
        global $CFG;
        mtrace("Using regular upload...");
        // Create temp file.
        $tempfile = make_temp_directory('backups3') . '/' . uniqid('upload_');
        $backupfile->copy_content_to($tempfile);

        try {
            $result = $s3->putObject([
                'Bucket' => $s3config->aws_bucket,
                'Key'    => $s3key,
                'Body'   => $backupfile->get_content(),
                'ContentType' => 'application/vnd.moodle.backup',
            ]);

            mtrace("Upload completed: " . $result['ObjectURL']);
        } catch (\Aws\Exception\AwsException $e) {
            mtrace("Upload failed: " . $e->getMessage());
            throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', $e->getMessage());
        }
        self::update_coursepath($courseid, $s3key);
        return $s3key;
    }

    /**
     * Downloads a backup file from Amazon S3 storage
     *
     * @param string $s3key The S3 key/path of the backup file to download
     * @return string The local file path where the backup was downloaded
     * @throws \moodle_exception If the download fails
     */
    private static function get_from_s3($s3key) {
        global $CFG;
        $s3config = get_config('local_bservicesuite');
        $s3 = self::get_s3_bucket($s3config);

        // Create temp directory.
        $tempdir = $CFG->tempdir . '/backups3_restore';
        if (!is_dir($tempdir)) {
            mkdir($tempdir, 0777, true);
        }

        $localfile = $tempdir . '/' . basename($s3key);

        try {
            $result = $s3->headObject([
                'Bucket' => $s3config->aws_bucket,
                'Key' => $s3key,
            ]);

            $filesize = $result['ContentLength'];
            mtrace("Downloading {$s3key} ({$filesize} bytes)...");

            // For large files, use getObject with SaveAs.
            $s3->getObject([
                'Bucket' => $s3config->aws_bucket,
                'Key' => $s3key,
                'SaveAs' => $localfile,
            ]);

            mtrace("Download completed: {$localfile}");
        } catch (\Aws\S3\Exception\S3Exception $e) {
            mtrace("Download failed: " . $e->getMessage());
            throw new moodle_exception('downloadfailed', 'local_bservicesuite', '', $e->getMessage());
        }

        return $localfile;
    }

    /**
     * Creates and returns an S3 client instance
     *
     * @param object $s3config Configuration object containing S3 settings (region, access key, secret key)
     * @return \Aws\S3\S3Client The configured S3 client instance
     */
    private static function get_s3_bucket($s3config) {
        $args = [
            'version' => 'latest',
            'region'  => $s3config->region,
        ];
        if (!empty($s3config->s3_access_key) && !empty($s3config->s3_secret_key)) {
            $args['credentials'] = [
                'key'    => $s3config->s3_access_key,
                'secret' => $s3config->s3_secret_key,
            ];
        }

        return new \Aws\S3\S3Client($args);
    }

    /**
     * Updates the course path in the database
     *
     * @param int $courseid The ID of the course to update
     * @param string $path The new path to set for the course
     * @return void
     */
    private static function update_coursepath($courseid, $path) {
        [$curl, $remoteurl, $options] = helper::get_curl(helper::UPDATE_COURSEPATH_ENDPOINT);

        if (!$curl) {
            throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', 'Platform URL not configured');
        }
        $data = [
            'courseid' => $courseid,
            'path' => $path,
            'status' => 'ready',
        ];
        try {
            $response = $curl->post(
                $remoteurl,
                json_encode($data),
                $options
            );

            // Check curl-level errors.
            if ($curl->get_errno()) {
                debugging(
                    'Update path error: ' . $curl->error,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', $curl->error);
            }

            // Check HTTP status.
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($httpcode < 200 || $httpcode >= 300) {
                debugging(
                    'Update path failed. HTTP Code: ' . $httpcode .
                    ' Response: ' . $response,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', 'HTTP Code: ' . $httpcode);
            }

            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                debugging(
                    'User sync invalid JSON response: ' . $response,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', 'Invalid JSON response');
            }

            return $decoded;
        } catch (moodle_exception $e) {
            debugging(
                'User sync exception: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', $e->getMessage());
        }
    }


    /**
     * Checks if a grade category exists and creates it if not found
     *
     * @param int $grade The grade category ID to check/update
     * @param string $gradename The name of the grade category
     * @return stdClass
     */
    private static function check_grade_exists($gradename) {
        // Generate idnumber from gradename.
        $idnumber = strtolower(str_replace(' ', '_', $gradename));

        // Step 1: Try to find category by idnumber (slug).
        $category = null;
        $allcats = \core_course_category::get_all();
        foreach ($allcats as $cat) {
            if (isset($cat->idnumber) && $cat->idnumber === $idnumber) {
                $category = $cat;
                break;
            }
        }

        // Step 2: If not found, create it.
        if (!$category) {
            $category = \core_course_category::create([
                'name' => $gradename,
                'idnumber' => $idnumber,
                'parent' => 0,
            ]);
        }

        // Update $grade with the category ID for later use.
        return $category;
    }

    /**
     * Updates the course path in the database
     *
     * @param int $courseid The ID of the course to update
     * @param string $path The new path to set for the course
     * @return void
     */
    private static function update_school_course($ccourse, $newcourseid, $grade, $schoolid) {
        [$curl, $remoteurl, $options] = helper::get_curl(helper::UPDATE_NEWCOURSE_ENDPOINT);

        if (!$curl) {
            throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', 'Platform URL not configured');
        }
        $data = [
            'courseid' => $ccourse,
            'school_id' => $schoolid,
            'school_courseid' => $newcourseid,
            'grade' => $grade,
            'status' => 'assigned',
        ];
        try {
            $response = $curl->post(
                $remoteurl,
                json_encode($data),
                $options
            );

            // Check curl-level errors.
            if ($curl->get_errno()) {
                debugging(
                    'Update path error: ' . $curl->error,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', $curl->error);
            }

            // Check HTTP status.
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($httpcode < 200 || $httpcode >= 300) {
                debugging(
                    'Update path failed. HTTP Code: ' . $httpcode .
                    ' Response: ' . $response,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', 'HTTP Code: ' . $httpcode);
            }

            $decoded = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                debugging(
                    'User sync invalid JSON response: ' . $response,
                    DEBUG_DEVELOPER
                );
                throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', 'Invalid JSON response');
            }

            return $decoded;
        } catch (\Exception $e) {
            debugging(
                'User sync exception: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            throw new moodle_exception('uploadfailed', 'local_bservicesuite', '', $e->getMessage());
        }
    }
}
