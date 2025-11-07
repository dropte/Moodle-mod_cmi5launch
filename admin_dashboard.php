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
require_once($CFG->libdir.'/completionlib.php');

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

        // Reset Moodle's activity completion tracking
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid);
        }

        // Reset grade
        cmi5launch_grade_item_update($cmi5launch, (object)array('userid' => $userid, 'rawgrade' => null));

        // Clear viewed/completion cache
        $DB->delete_records('course_modules_completion', array(
            'coursemoduleid' => $cm->id,
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
    $table->head = array('', 'User', 'Status', 'Activities Completed', 'Last Access', 'Actions');
    $table->attributes['class'] = 'generaltable cmi5-admin-table';

    foreach ($enrolledusers as $user) {
        $row = new html_table_row();
        $row->attributes['class'] = 'user-row';
        $row->attributes['data-userid'] = $user->id;

        // Expand/collapse icon
        $row->cells[] = html_writer::tag('span', '+', array(
            'class' => 'expand-icon',
            'style' => 'cursor: pointer; font-weight: bold; font-size: 16px; user-select: none;',
            'title' => 'Show session details'
        ));

        // User name with profile link
        $row->cells[] = html_writer::link(
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

            $row->cells[] = html_writer::tag('span', $status, array('class' => 'badge ' . $statusclass));
            $row->cells[] = $completedaus . ' / ' . $totalaus;

            // Last access
            if (!empty($usercourse->timemodified)) {
                $row->cells[] = userdate($usercourse->timemodified);
            } else {
                $row->cells[] = 'Never';
            }
        } else {
            $row->cells[] = html_writer::tag('span', 'Not Started', array('class' => 'badge badge-secondary'));
            $row->cells[] = '0 / 0';
            $row->cells[] = 'Never';
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
        $row->cells[] = $actions;

        $table->data[] = $row;

        // Add expandable row with session details
        $detailrow = new html_table_row();
        $detailrow->attributes['class'] = 'session-details-row hidden-row';
        $detailrow->attributes['data-userid'] = $user->id;
        $detailrow->attributes['style'] = 'display: none;';

        // Create session details content
        $sessionscontent = '';
        $sessions = $DB->get_records('cmi5launch_sessions', array(
            'moodlecourseid' => $cm->instance,
            'userid' => $user->id
        ), 'createdat DESC');

        if ($sessions && count($sessions) > 0) {
            $sessionscontent .= html_writer::start_tag('div', array('style' => 'padding: 10px;'));
            $sessionscontent .= html_writer::tag('strong', 'Session Details (' . count($sessions) . ' sessions)');
            $sessionscontent .= html_writer::start_tag('table', array(
                'class' => 'table table-sm table-bordered',
                'style' => 'margin-top: 10px; background: #f9f9f9;'
            ));
            $sessionscontent .= html_writer::start_tag('thead');
            $sessionscontent .= html_writer::start_tag('tr');
            $sessionscontent .= html_writer::tag('th', 'Session ID');
            $sessionscontent .= html_writer::tag('th', 'Created');
            $sessionscontent .= html_writer::tag('th', 'Duration');
            $sessionscontent .= html_writer::tag('th', 'Score');
            $sessionscontent .= html_writer::tag('th', 'Completed');
            $sessionscontent .= html_writer::tag('th', 'Passed');
            $sessionscontent .= html_writer::tag('th', 'Status');
            $sessionscontent .= html_writer::end_tag('tr');
            $sessionscontent .= html_writer::end_tag('thead');
            $sessionscontent .= html_writer::start_tag('tbody');

            foreach ($sessions as $session) {
                $sessionscontent .= html_writer::start_tag('tr');
                $sessionscontent .= html_writer::tag('td', $session->sessionid);
                $sessionscontent .= html_writer::tag('td', !empty($session->createdat) ? date('Y-m-d H:i', strtotime($session->createdat)) : 'N/A');
                $sessionscontent .= html_writer::tag('td', !empty($session->duration) ? $session->duration : 'N/A');
                $sessionscontent .= html_writer::tag('td', !is_null($session->score) ? $session->score : 'N/A');
                $sessionscontent .= html_writer::tag('td', $session->iscompleted ? 'Yes' : 'No');
                $sessionscontent .= html_writer::tag('td', $session->ispassed ? 'Yes' : 'No');

                // Determine status
                $status = array();
                if ($session->iscompleted) $status[] = 'Completed';
                if ($session->ispassed) $status[] = 'Passed';
                if ($session->isfailed) $status[] = 'Failed';
                if ($session->isterminated) $status[] = 'Terminated';
                if ($session->isabandoned) $status[] = 'Abandoned';
                $statustext = !empty($status) ? implode(', ', $status) : 'Active';

                $sessionscontent .= html_writer::tag('td', $statustext);
                $sessionscontent .= html_writer::end_tag('tr');
            }

            $sessionscontent .= html_writer::end_tag('tbody');
            $sessionscontent .= html_writer::end_tag('table');
            $sessionscontent .= html_writer::end_tag('div');
        } else {
            $sessionscontent = html_writer::div('No sessions found for this user.', '', array('style' => 'padding: 10px; color: #666;'));
        }

        $detailcell = new html_table_cell($sessionscontent);
        $detailcell->colspan = 6;
        $detailrow->cells[] = $detailcell;

        $table->data[] = $detailrow;
    }

    echo html_writer::table($table);

    // Add JavaScript for expand/collapse functionality
    echo html_writer::start_tag('script');
    ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Add click handlers to expand icons
        document.querySelectorAll('.expand-icon').forEach(function(icon) {
            icon.addEventListener('click', function() {
                const userRow = this.closest('tr');
                const userid = userRow.getAttribute('data-userid');
                const detailRow = document.querySelector('.session-details-row[data-userid="' + userid + '"]');

                if (detailRow) {
                    if (detailRow.style.display === 'none') {
                        // Expand
                        detailRow.style.display = '';
                        this.textContent = '−';
                        this.setAttribute('title', 'Hide session details');
                    } else {
                        // Collapse
                        detailRow.style.display = 'none';
                        this.textContent = '+';
                        this.setAttribute('title', 'Show session details');
                    }
                }
            });
        });
    });
    <?php
    echo html_writer::end_tag('script');

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
    // AI Insights with visualizations
    echo html_writer::start_div('cmi5-admin-insights');
    echo $OUTPUT->heading('AI-Powered Insights', 3);

    // Check if AI is configured
    $aiprovider = get_config('cmi5launch', 'ai_provider');
    $apikey = get_config('cmi5launch', 'ai_api_key');
    $isconfigured = !empty($aiprovider) && ($aiprovider === 'local' || !empty($apikey));

    if (!$isconfigured) {
        echo html_writer::start_div('alert alert-info');
        echo html_writer::tag('p', '💡 Configure an AI provider in plugin settings for intelligent recommendations.');
        echo html_writer::tag('p', 'Supported: OpenAI, Anthropic Claude, Local LLM');
        echo html_writer::end_div();
    }

    // Calculate metrics for visualizations
    $totalausers = count($enrolledusers);
    $usersstarted = $DB->count_records('cmi5launch_usercourse', array('moodlecourseid' => $cm->instance));
    $usercourses = $DB->get_records('cmi5launch_usercourse', array('moodlecourseid' => $cm->instance));

    $completedcount = 0;
    $inprogresscount = 0;
    $atriskcount = 0;
    $weekago = time() - (7 * 24 * 60 * 60);

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
                // Check if stalled
                if ($usercourse->timemodified < $weekago) {
                    $atriskcount++;
                }
            }
        }
    }

    $notstartedcount = $totalausers - $usersstarted;
    $completionrate = $totalausers > 0 ? round(($completedcount / $totalausers) * 100) : 0;

    // Row 1: Key Metrics with Charts
    echo html_writer::start_div('row mb-4');

    // Progress Overview Chart
    echo html_writer::start_div('col-md-6');
    echo html_writer::start_div('card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', '📊 Progress Distribution', array('class' => 'card-title'));
    echo html_writer::tag('canvas', '', array('id' => 'progressChart', 'style' => 'height: 250px;'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Completion Rate Gauge
    echo html_writer::start_div('col-md-6');
    echo html_writer::start_div('card');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', '🎯 Completion Rate', array('class' => 'card-title'));
    echo html_writer::tag('canvas', '', array('id' => 'completionGauge', 'style' => 'height: 250px;'));
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // row

    // Row 2: AI Insights Cards
    if ($isconfigured) {
        echo html_writer::start_div('row mb-4');

        // Generate concise AI insights
        try {
            $progressinsight = \mod_cmi5launch\local\ai_insights::generate_insights('progress', $cmi5launch, $enrolledusers, $DB);
            $atriskinsight = \mod_cmi5launch\local\ai_insights::generate_insights('at_risk', $cmi5launch, $enrolledusers, $DB);

            // Progress Insight Card
            echo html_writer::start_div('col-md-6');
            echo html_writer::start_div('card border-primary');
            echo html_writer::start_div('card-body');
            echo html_writer::tag('h5', '💡 Key Insight', array('class' => 'card-title'));
            $shortinsight = substr($progressinsight, 0, 300) . '...';
            echo html_writer::div(format_text($shortinsight, FORMAT_MARKDOWN), 'card-text');
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();

            // At-Risk Alert Card
            echo html_writer::start_div('col-md-6');
            $cardclass = $atriskcount > 0 ? 'border-danger' : 'border-success';
            echo html_writer::start_div('card ' . $cardclass);
            echo html_writer::start_div('card-body');
            echo html_writer::tag('h5', '⚠️ Attention Needed', array('class' => 'card-title'));
            $shortrisk = substr($atriskinsight, 0, 300) . '...';
            echo html_writer::div(format_text($shortrisk, FORMAT_MARKDOWN), 'card-text');
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();

        } catch (\Exception $e) {
            echo html_writer::start_div('col-12');
            echo html_writer::start_div('alert alert-warning');
            echo 'AI insights temporarily unavailable: ' . $e->getMessage();
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        echo html_writer::end_div(); // row
    }

    // Row 3: Activity Performance Chart
    if (!empty($cmi5launch->aus)) {
        $aus = json_decode($cmi5launch->aus);
        if ($aus && is_array($aus) && count($aus) > 0) {
            echo html_writer::start_div('row mb-4');
            echo html_writer::start_div('col-12');
            echo html_writer::start_div('card');
            echo html_writer::start_div('card-body');
            echo html_writer::tag('h5', '📈 Activity Performance', array('class' => 'card-title'));
            echo html_writer::tag('canvas', '', array('id' => 'activityChart', 'style' => 'height: 300px;'));
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();
            echo html_writer::end_div();

            // Prepare activity data for chart
            $activitylabels = array();
            $activitystarted = array();
            $activitycompleted = array();

            foreach ($aus as $index => $audata) {
                $au = is_array($audata) && count($audata) > 0 ? $audata[0] : $audata;
                $title = 'Activity ' . ($index + 1);
                if (is_object($au) && isset($au->title)) {
                    if (is_array($au->title) && count($au->title) > 0) {
                        $titleobj = $au->title[0];
                        if (is_object($titleobj) && isset($titleobj->text)) {
                            $title = strlen($titleobj->text) > 20 ? substr($titleobj->text, 0, 20) . '...' : $titleobj->text;
                        }
                    }
                }
                $activitylabels[] = $title;

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
                $activitystarted[] = $started;
                $activitycompleted[] = $completed;
            }
        }
    }

    // Load Chart.js and initialize charts
    echo html_writer::start_tag('script');
    ?>
    // Load Chart.js from CDN
    (function() {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
        script.onload = function() {
            // Ensure DOM is ready before initializing charts
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initCharts);
            } else {
                initCharts();
            }
        };
        document.head.appendChild(script);
    })();

    function initCharts() {
        console.log('Initializing charts...');
        console.log('Chart.js version:', Chart.version);

        // Progress Distribution Pie Chart
        const progressCtx = document.getElementById('progressChart');
        console.log('Progress chart canvas:', progressCtx);
        if (progressCtx) {
            const progressData = [<?php echo $completedcount; ?>, <?php echo $inprogresscount; ?>, <?php echo $notstartedcount; ?>];
            console.log('Progress chart data:', progressData);
            new Chart(progressCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'In Progress', 'Not Started'],
                    datasets: [{
                        data: progressData,
                        backgroundColor: ['#28a745', '#ffc107', '#6c757d'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
            console.log('Progress chart created successfully');
        } else {
            console.error('Progress chart canvas not found');
        }

        // Completion Rate Gauge
        const gaugeCtx = document.getElementById('completionGauge');
        console.log('Completion gauge canvas:', gaugeCtx);
        if (gaugeCtx) {
            const completionData = [<?php echo $completionrate; ?>, <?php echo 100 - $completionrate; ?>];
            console.log('Completion gauge data:', completionData);
            new Chart(gaugeCtx, {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: completionData,
                        backgroundColor: [
                            <?php echo $completionrate >= 70 ? "'#28a745'" : ($completionrate >= 40 ? "'#ffc107'" : "'#dc3545'"); ?>,
                            '#e9ecef'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    }
                },
                plugins: [{
                    beforeDraw: function(chart) {
                        const width = chart.width;
                        const height = chart.height;
                        const ctx = chart.ctx;
                        ctx.restore();
                        const fontSize = (height / 114).toFixed(2);
                        ctx.font = fontSize + "em sans-serif";
                        ctx.textBaseline = "middle";
                        const text = "<?php echo $completionrate; ?>%";
                        const textX = Math.round((width - ctx.measureText(text).width) / 2);
                        const textY = height / 2;
                        ctx.fillText(text, textX, textY);
                        ctx.save();
                    }
                }]
            });
            console.log('Completion gauge created successfully');
        } else {
            console.error('Completion gauge canvas not found');
        }

        // Activity Performance Bar Chart
        const activityCtx = document.getElementById('activityChart');
        console.log('Activity chart canvas:', activityCtx);
        if (activityCtx) {
            const activityLabels = <?php echo json_encode($activitylabels ?? []); ?>;
            const activityStarted = <?php echo json_encode($activitystarted ?? []); ?>;
            const activityCompleted = <?php echo json_encode($activitycompleted ?? []); ?>;
            console.log('Activity chart labels:', activityLabels);
            console.log('Activity chart started:', activityStarted);
            console.log('Activity chart completed:', activityCompleted);
            new Chart(activityCtx, {
                type: 'bar',
                data: {
                    labels: activityLabels,
                    datasets: [
                        {
                            label: 'Started',
                            data: activityStarted,
                            backgroundColor: '#007bff'
                        },
                        {
                            label: 'Completed',
                            data: activityCompleted,
                            backgroundColor: '#28a745'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    },
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
            console.log('Activity chart created successfully');
        } else {
            console.error('Activity chart canvas not found');
        }
    }
    <?php
    echo html_writer::end_tag('script');

    echo html_writer::end_div(); // insights
}

echo $OUTPUT->footer();
