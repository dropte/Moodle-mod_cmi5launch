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
 * CMI5 Launch Admin Dashboard
 *
 * Provides administrators with:
 * - User progress overview
 * - LRS statement analytics
 * - User registration reset functionality
 * - AI-powered insights (optional)
 *
 * @package mod_cmi5launch
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/lib.php');

use mod_cmi5launch\local\progress;
use mod_cmi5launch\local\cmi5_connectors;

$id = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('cmi5launch', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$cmi5launch = $DB->get_record('cmi5launch', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Check capability
require_capability('mod/cmi5launch:viewadmindashboard', $context);

$PAGE->set_url('/mod/cmi5launch/admin_dashboard.php', array('id' => $cm->id));
$PAGE->set_title(format_string($cmi5launch->name) . ' - Admin Dashboard');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/cmi5launch/styles.css');

// Handle actions
if ($action === 'reset' && $userid > 0) {
    require_capability('mod/cmi5launch:resetuserprogress', $context);
    require_sesskey();

    // Confirm action
    $confirm = optional_param('confirm', 0, PARAM_INT);
    if ($confirm) {
        // Delete user data
        $DB->delete_records('cmi5launch_usercourse', array(
            'moodlecourseid' => $cm->instance,
            'userid' => $userid
        ));
        $DB->delete_records('cmi5launch_aus', array(
            'moodlecourseid' => $cm->instance,
            'userid' => $userid
        ));
        $DB->delete_records('cmi5launch_sessions', array(
            'moodlecourseid' => $cm->instance,
            'userid' => $userid
        ));

        redirect($PAGE->url, 'User progress has been reset successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // Show confirmation page
        $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);

        echo $OUTPUT->header();
        echo $OUTPUT->heading('Confirm Reset');
        echo $OUTPUT->box_start('generalbox');
        echo html_writer::tag('p', 'Are you sure you want to reset all progress for user: ' . fullname($user) . '?');
        echo html_writer::tag('p', 'This will delete:');
        echo html_writer::tag('ul',
            html_writer::tag('li', 'User course registration') .
            html_writer::tag('li', 'All activity (AU) progress') .
            html_writer::tag('li', 'All session data')
        );
        echo html_writer::tag('p', 'This action cannot be undone!', array('class' => 'alert alert-danger'));

        $continueurl = new moodle_url($PAGE->url, array(
            'action' => 'reset',
            'userid' => $userid,
            'confirm' => 1,
            'sesskey' => sesskey()
        ));
        echo $OUTPUT->single_button($continueurl, 'Reset User Progress', 'post', array('class' => 'btn-danger'));
        echo $OUTPUT->single_button($PAGE->url, 'Cancel');
        echo $OUTPUT->box_end();
        echo $OUTPUT->footer();
        exit;
    }
}

// Start output
echo $OUTPUT->header();
echo $OUTPUT->heading('Admin Dashboard: ' . format_string($cmi5launch->name));

// Add tabs for different sections
$tabs = array();
$tabs[] = new tabobject('overview', new moodle_url($PAGE->url, array('tab' => 'overview')), 'User Progress');
$tabs[] = new tabobject('analytics', new moodle_url($PAGE->url, array('tab' => 'analytics')), 'Analytics');
$tabs[] = new tabobject('insights', new moodle_url($PAGE->url, array('tab' => 'insights')), 'AI Insights');

$currenttab = optional_param('tab', 'overview', PARAM_ALPHA);
echo $OUTPUT->tabtree($tabs, $currenttab);

// Get all users enrolled in this activity
$enrolledusers = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC');

if ($currenttab === 'overview') {
    // User Progress Overview
    echo html_writer::start_div('cmi5-admin-overview');

    // Summary statistics
    echo html_writer::start_div('cmi5-admin-stats');
    echo $OUTPUT->heading('Summary Statistics', 3);

    $totalusers = count($enrolledusers);
    $usersstarted = $DB->count_records('cmi5launch_usercourse', array('moodlecourseid' => $cm->instance));

    // Count users with completed status
    $completedcount = 0;
    $inprogresscount = 0;
    $notstartedcount = $totalusers - $usersstarted;

    $usercourses = $DB->get_records('cmi5launch_usercourse', array('moodlecourseid' => $cm->instance));
    foreach ($usercourses as $usercourse) {
        if (!empty($usercourse->aus)) {
            $aus = json_decode($usercourse->aus);
            $allcompleted = true;
            $anyprogress = false;

            foreach ($aus as $auid) {
                $au = $DB->get_record('cmi5launch_aus', array('id' => $auid));
                if ($au) {
                    if ($au->satisfied === 'Satisfied') {
                        $anyprogress = true;
                    } else {
                        $allcompleted = false;
                        if (!empty($au->scores)) {
                            $anyprogress = true;
                        }
                    }
                }
            }

            if ($allcompleted) {
                $completedcount++;
            } else if ($anyprogress) {
                $inprogresscount++;
            }
        }
    }

    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-3');
    echo html_writer::div(
        html_writer::tag('h2', $totalusers, array('class' => 'stat-number')) .
        html_writer::tag('p', 'Total Users', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3');
    echo html_writer::div(
        html_writer::tag('h2', $usersstarted, array('class' => 'stat-number')) .
        html_writer::tag('p', 'Users Started', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3');
    echo html_writer::div(
        html_writer::tag('h2', $inprogresscount, array('class' => 'stat-number')) .
        html_writer::tag('p', 'In Progress', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-3');
    echo html_writer::div(
        html_writer::tag('h2', $completedcount, array('class' => 'stat-number')) .
        html_writer::tag('p', 'Completed', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();
    echo html_writer::end_div(); // row

    echo html_writer::end_div(); // stats

    // User progress table
    echo $OUTPUT->heading('User Progress Details', 3);

    $table = new html_table();
    $table->head = array('User', 'Status', 'Activities Completed', 'Last Access', 'Actions');
    $table->attributes['class'] = 'generaltable cmi5-admin-table';

    foreach ($enrolledusers as $user) {
        $row = array();

        // User name with profile link
        $row[] = html_writer::link(
            new moodle_url('/user/profile.php', array('id' => $user->id)),
            fullname($user)
        );

        // Get user's progress
        $usercourse = $DB->get_record('cmi5launch_usercourse', array(
            'moodlecourseid' => $cm->instance,
            'userid' => $user->id
        ));

        if ($usercourse) {
            $aus = json_decode($usercourse->aus);
            $totalaus = count($aus);
            $completedaus = 0;
            $status = 'In Progress';
            $statusclass = 'badge-warning';

            foreach ($aus as $auid) {
                $au = $DB->get_record('cmi5launch_aus', array('id' => $auid));
                if ($au && $au->satisfied === 'Satisfied') {
                    $completedaus++;
                }
            }

            if ($completedaus == $totalaus && $totalaus > 0) {
                $status = 'Completed';
                $statusclass = 'badge-success';
            }

            $row[] = html_writer::tag('span', $status, array('class' => 'badge ' . $statusclass));
            $row[] = $completedaus . ' / ' . $totalaus;

            // Last access
            if (!empty($usercourse->timemodified)) {
                $row[] = userdate($usercourse->timemodified);
            } else {
                $row[] = 'Never';
            }
        } else {
            $row[] = html_writer::tag('span', 'Not Started', array('class' => 'badge badge-secondary'));
            $row[] = '0 / 0';
            $row[] = 'Never';
        }

        // Actions
        $actions = '';
        if (has_capability('mod/cmi5launch:resetuserprogress', $context)) {
            $reseturl = new moodle_url($PAGE->url, array(
                'action' => 'reset',
                'userid' => $user->id,
                'sesskey' => sesskey()
            ));
            $actions .= html_writer::link($reseturl, 'Reset', array('class' => 'btn btn-sm btn-danger'));
        }
        $row[] = $actions;

        $table->data[] = $row;
    }

    echo html_writer::table($table);
    echo html_writer::end_div(); // overview

} else if ($currenttab === 'analytics') {
    // LRS Analytics
    echo html_writer::start_div('cmi5-admin-analytics');
    echo $OUTPUT->heading('LRS Statement Analytics', 3);

    echo html_writer::tag('p', 'This section shows analytics based on xAPI statements from the LRS.');

    // Get all sessions for this activity
    $sessions = $DB->get_records('cmi5launch_sessions', array('moodlecourseid' => $cm->instance));

    echo html_writer::start_div('analytics-section');

    // Activity engagement metrics
    echo $OUTPUT->heading('Engagement Metrics', 4);
    echo html_writer::start_div('row');

    echo html_writer::start_div('col-md-4');
    echo html_writer::div(
        html_writer::tag('h3', count($sessions), array('class' => 'stat-number')) .
        html_writer::tag('p', 'Total Sessions', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();

    // Average session duration (if available in session data)
    $totalDuration = 0;
    $sessionsWithDuration = 0;
    foreach ($sessions as $session) {
        if (!empty($session->launchmode)) {
            // Calculate duration if we have timestamps
            // This is placeholder - actual duration calculation would depend on session tracking
            $sessionsWithDuration++;
        }
    }

    echo html_writer::start_div('col-md-4');
    echo html_writer::div(
        html_writer::tag('h3', $sessionsWithDuration, array('class' => 'stat-number')) .
        html_writer::tag('p', 'Active Sessions', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();

    // Unique users with sessions
    $uniqueusers = $DB->get_records_sql(
        "SELECT DISTINCT userid FROM {cmi5launch_sessions} WHERE moodlecourseid = ?",
        array($cm->instance)
    );

    echo html_writer::start_div('col-md-4');
    echo html_writer::div(
        html_writer::tag('h3', count($uniqueusers), array('class' => 'stat-number')) .
        html_writer::tag('p', 'Active Users', array('class' => 'stat-label')),
        'stat-card'
    );
    echo html_writer::end_div();

    echo html_writer::end_div(); // row

    // Activity completion breakdown
    echo $OUTPUT->heading('Activity Breakdown', 4);

    $aus = json_decode($cmi5launch->aus);
    if ($aus && is_array($aus)) {
        $activityTable = new html_table();
        $activityTable->head = array('Activity', 'Started', 'Completed', 'Completion Rate');
        $activityTable->attributes['class'] = 'generaltable';

        foreach ($aus as $index => $audata) {
            // Unwrap if needed
            $au = is_array($audata) && count($audata) > 0 ? $audata[0] : $audata;

            $title = 'Activity ' . ($index + 1);
            if (is_object($au) && isset($au->title)) {
                if (is_array($au->title) && count($au->title) > 0) {
                    $titleobj = $au->title[0];
                    if (is_object($titleobj) && isset($titleobj->text)) {
                        $title = $titleobj->text;
                    }
                }
            }

            // Count users who started/completed this AU
            $started = 0;
            $completed = 0;

            foreach ($usercourses as $usercourse) {
                $useraus = json_decode($usercourse->aus);
                if ($useraus && is_array($useraus)) {
                    foreach ($useraus as $auid) {
                        $userau = $DB->get_record('cmi5launch_aus', array('id' => $auid));
                        if ($userau && $userau->auindex == $index) {
                            $started++;
                            if ($userau->satisfied === 'Satisfied') {
                                $completed++;
                            }
                            break;
                        }
                    }
                }
            }

            $rate = $started > 0 ? round(($completed / $started) * 100, 1) : 0;

            $row = array();
            $row[] = $title;
            $row[] = $started;
            $row[] = $completed;
            $row[] = $rate . '%';

            $activityTable->data[] = $row;
        }

        echo html_writer::table($activityTable);
    }

    echo html_writer::end_div(); // analytics-section
    echo html_writer::end_div(); // analytics

} else if ($currenttab === 'insights') {
    // AI Insights
    echo html_writer::start_div('cmi5-admin-insights');
    echo $OUTPUT->heading('AI-Powered Insights', 3);

    echo html_writer::tag('p', 'This feature provides AI-generated insights based on user progress and LRS data.');

    // AI Provider Configuration
    echo $OUTPUT->heading('Configuration', 4);
    echo html_writer::start_div('alert alert-info');
    echo html_writer::tag('p', 'To enable AI insights, configure an AI provider in the plugin settings.');
    echo html_writer::tag('p', 'Supported providers: OpenAI, Anthropic Claude, Local LLM');
    echo html_writer::end_div();

    // Check if AI is configured
    $aiprovider = get_config('cmi5launch', 'ai_provider');
    $apikey = get_config('cmi5launch', 'ai_api_key');

    // Local LLM doesn't require API key
    $isconfigured = !empty($aiprovider) && ($aiprovider === 'local' || !empty($apikey));

    if ($isconfigured) {
        echo $OUTPUT->heading('AI-Generated Insights', 4);
        echo html_writer::tag('p', 'Automatically generated insights based on current activity data.');

        // Define all insight types
        $insighttypes = array(
            'progress' => 'Overall Progress Analysis',
            'engagement' => 'User Engagement Patterns',
            'recommendations' => 'Learning Recommendations',
            'at_risk' => 'At-Risk Users'
        );

        // Auto-generate all insights
        foreach ($insighttypes as $type => $title) {
            echo html_writer::start_div('insight-section mb-4');
            echo $OUTPUT->heading($title, 5);

            try {
                // Generate insights using AI
                $insights = \mod_cmi5launch\local\ai_insights::generate_insights(
                    $type,
                    $cmi5launch,
                    $enrolledusers,
                    $DB
                );

                echo html_writer::start_div('alert alert-info');
                echo html_writer::div(nl2br(htmlspecialchars($insights)), 'ai-insights-content');
                echo html_writer::end_div();

            } catch (\Exception $e) {
                echo html_writer::start_div('alert alert-danger');
                echo html_writer::tag('strong', 'Error: ');
                echo html_writer::tag('span', $e->getMessage());
                echo html_writer::end_div();
            }

            echo html_writer::end_div(); // insight-section
        }
    } else {
        echo html_writer::start_div('alert alert-warning');
        echo html_writer::tag('p', 'AI insights are not yet configured. Please contact your administrator to set up an AI provider.');
        echo html_writer::end_div();
    }

    echo html_writer::end_div(); // insights
}

echo $OUTPUT->footer();
