<?php

namespace mod_cmi5launch\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_api;
use mod_cmi5launch\local\grade_helpers;

class get_cmi5_course_status extends external_api
{


    public static function execute_parameters()
    {
        return new external_function_parameters([
            'modulename' => new external_value(PARAM_TEXT, 'Module name', VALUE_OPTIONAL),
            'userid'     => new external_value(PARAM_INT, 'The user id', VALUE_OPTIONAL),
        ]);
    }
    public static function execute($modulename = null, $userid = null)
    {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/mod/cmi5launch/lib.php');

        $grader = new grade_helpers();


        // 1) Validate params and resolve target user.
        $in = self::validate_parameters(self::execute_parameters(), [
            'modulename' => $modulename,
            'userid'     => $userid,
        ]);
        $targetuserid = $in['userid'] ?: $USER->id;

        // 2) Build query (use a dedicated $sqlparams so we don't collide with $in).
        $sql = "SELECT id AS cmi5id, name AS modulename, course
                  FROM {cmi5launch}
                 WHERE 1=1";
        $sqlparams = [];
        if (!empty($in['modulename'])) {
            $sql .= " AND name LIKE :modulename";
            $sqlparams['modulename'] = "%{$in['modulename']}%";
        }


        $records = $DB->get_records_sql($sql, $sqlparams);
        $results = [];

        foreach ($records as $record) {

            // $course = $DB->get_record('course', $record->course, '*', MUST_EXIST);
            // $context = context_course::instance($course->id);
            // self::validate_context($context);

            $coursemodule = get_coursemodule_from_id('cmi5launch', $record->course, 0, false, MUST_EXIST);
            $cmi5launch = $DB->get_record('cmi5launch', ['id' => $coursemodule->instance], '*', MUST_EXIST);

            $cmi5_user = $DB->get_record('cmi5launch_usercourse', ['id' => $coursemodule->instance], '*', MUST_EXIST);

            // lets update the grades and get the status here
            // cmi5launch_update_grades($cmi5launch, $targetuserid);

            $user = $DB->get_record('user', ['id' => $userid]);
            $grades = $grader->cmi5launch_check_user_grades_for_updates_ws($cmi5launch, $user);

            $results[] = [
                'cmi5id' => $record->cmi5id,
                'modulename' => $record->modulename,
                'courseid' => $record->course,
                'registrationid' => $cmi5_user->registrationid,
                'cmi5_server_courseid' => $cmi5launch->courseid,
                'aus' => self::extract_aus($cmi5launch->courseinfo, $grades['auscores']),
                // 'debug' => json_encode($cmi5_user)
            ];
        }

        return $results;
    }
    private static function extract_aus(string $json, array $grades): array
    {
        $out = [];
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($decoded['metadata']['aus'])) {
            return $out;
        }

        foreach ($decoded['metadata']['aus'] as $au) {
            $title = $au['title'][0]['text'] ?? '';
            $auId  = $au['lmsId'] ?? '';

            // Safely get grade, default to []
            $grade = $grades[$auId][$title] ?? null;
            if (is_null($grade)) {
                $grade = [];
            } else {
                // If it's a JSON string like "[10,20]" decode it
                if (is_string($grade)) {
                    $decodedGrade = json_decode($grade, true);
                    $grade = is_array($decodedGrade) ? $decodedGrade : [];
                }
            }

            $out[] = [
                'id'          => $au['id'] ?? '',
                'url'         => $au['url'] ?? '',
                'title'       => $title,
                'description' => $au['description'][0]['text'] ?? '',
                'moveOn'      => $au['moveOn'] ?? '',
                'auIndex'     => $au['auIndex'] ?? -1,
                'grades'      => $grade,   // either an int array or []
            ];
        }
        return $out;
    }


    public static function execute_returns()
    {
        return new external_multiple_structure(
            new external_single_structure([
                'cmi5id' => new external_value(PARAM_INT, 'CMI5launch instance ID'),
                'modulename' => new external_value(PARAM_TEXT, 'Module name'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'registrationid' => new external_value(PARAM_TEXT, 'Course ID'),
                'cmi5_server_courseid' => new external_value(PARAM_INT, 'Course ID'),
                'aus' => new external_multiple_structure(
                    new external_single_structure([
                        'id'      => new external_value(PARAM_TEXT, 'AU ID'),
                        'url'     => new external_value(PARAM_TEXT, 'AU launch URL'),
                        'title'   => new external_value(PARAM_TEXT, 'AU title'),
                        'description'   => new external_value(PARAM_TEXT, 'AU description'),
                        'moveOn'  => new external_value(PARAM_TEXT, 'AU moveOn rule'),
                        'auIndex' => new external_value(PARAM_INT, 'AU index'),
                        'grades' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'Score')
                        )
                    ]),
                    'Simplified AU list',
                    VALUE_OPTIONAL
                ),

                // 'debug' => new external_value(PARAM_RAW, 'Full DB record (debug info)')

            ])
        );
    }
}
