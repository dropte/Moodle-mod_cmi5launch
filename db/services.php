<?php

$functions = [
    'mod_cmi5launch_upload_cmi5_course' => [
        'classname' => 'mod_cmi5launch\external\upload_cmi5_course',
        'methodname' => 'execute',
        'description' => 'Upload a CMI5 course and create a module',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
    ],
    'mod_cmi5launch_update_cmi5_course' => [
        'classname' => 'mod_cmi5launch\external\update_cmi5_course',
        'methodname' => 'execute',
        'description' => 'Update a CMI5 module',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
    ],
    'mod_cmi5launch_get_cmi5_course' => [
        'classname' => 'mod_cmi5launch\external\get_cmi5_course',
        'methodname' => 'execute',
        'description' => 'Get a CMI5 course information',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
    ],
      'mod_cmi5launch_get_cmi5_course_status' => [
        'classname' => 'mod_cmi5launch\external\get_cmi5_course_status',
        'methodname' => 'execute',
        'description' => 'Get a CMI5 courses current progress',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
    ],
];
