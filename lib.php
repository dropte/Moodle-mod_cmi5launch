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
 * Library of interface functions and constants for module cmi5launch
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 * All the cmi5launch specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package mod_cmi5launch
 * @copyright  2013 Andrew Downes
 * @copyright 2024 Megan Bohland - added functions for cmi5launch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Cmi5PHP - required for interacting with the LRS in cmi5launch_get_statements.
require_once("$CFG->dirroot/mod/cmi5launch/cmi5PHP/autoload.php");

// SCORM library from the SCORM module. Required for its xml2Array class by cmi5launch_process_new_package.
require_once("$CFG->dirroot/mod/scorm/datamodels/scormlib.php");

use mod_cmi5launch\local\cmi5_connectors;
use mod_cmi5launch\local\au_helpers;
use mod_cmi5launch\local\grade_helpers;

global $cmi5launchsettings;
$cmi5launchsettings = null;

// Moodle Core API.

/**
 * Returns the information on whether the module supports a feature
 *
 * @see plugin_supports() in lib/moodlelib.php
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function cmi5launch_supports($feature) {
    switch($feature) {
        // True if module supports intro editor.
        case FEATURE_MOD_INTRO:
            return true;
            // MB - we do not currently support the next two, but leaving in, in case we start to.
            // True if module has code to track whether somebody viewed it
            // case FEATURE_COMPLETION_TRACKS_VIEWS:
            // return true;
            // True if module has custom completion rules
            // case FEATURE_COMPLETION_HAS_RULES:
            // return true;
        // True if module supports backup/restore of moodle2 format.
        case FEATURE_BACKUP_MOODLE2:
            return true;
        // True if module supports groups.
        case FEATURE_GROUPS:
            return true;
        // True if module supports groupings.
        case FEATURE_GROUPINGS:
            return true;
        // True if module can provide a grade.
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        // True if module supports outcomes.
        case FEATURE_GRADE_OUTCOMES:
            return true;
        // True if module can show description on course main page.
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        // Type of module.
        // This effects how icons are displayed? 
      //  case FEATURE_MOD_PURPOSE:
           // return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Return information for displaying on the course page
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|null
 */
function cmi5launch_get_coursemodule_info($coursemodule) {
    global $DB, $USER, $CFG;

    require_once($CFG->dirroot . '/mod/cmi5launch/locallib.php');

    $cmi5launch = $DB->get_record('cmi5launch', array('id' => $coursemodule->instance), '*', MUST_EXIST);

    $info = new cached_cm_info();
    $info->name = $cmi5launch->name;

    // Get AU helpers
    $auh = new au_helpers();
    $getaus = $auh->get_cmi5launch_retrieve_aus_from_db();

    $customhtml = html_writer::start_div('cmi5launch-course-card', array('id' => 'cmi5card-' . $coursemodule->id));
    $customhtml .= html_writer::start_div('cmi5launch-card-content');

    // Add the intro/description if available
    if (!empty($cmi5launch->intro)) {
        $customhtml .= html_writer::div(format_text($cmi5launch->intro, $cmi5launch->introformat), 'cmi5launch-card-intro');
    }

    // Try to load activities
    $activities = array();
    $userhasstarted = false;

    try {
        error_log('CMI5: Starting AU loading logic');

        // Check if user has started (has a user course record with AU IDs)
        $userscourse = $DB->get_record('cmi5launch_usercourse',
            array('courseid' => $cmi5launch->courseid, 'userid' => $USER->id));

        error_log('CMI5: userscourse=' . ($userscourse ? 'found' : 'notfound') .
                  ', userscourse_aus=' . ($userscourse && !empty($userscourse->aus) ? 'yes' : 'no') .
                  ', manifest_aus=' . (!empty($cmi5launch->aus) ? 'yes' : 'no'));

        if ($userscourse && !empty($userscourse->aus)) {
            // User has started and has AU IDs - load from database
            $userhasstarted = true;
            $auids = json_decode($userscourse->aus);

            if ($auids && is_array($auids)) {
                foreach ($auids as $index => $auid) {
                    try {
                        $au = $getaus($auid);
                        if ($au && isset($au->title)) {
                            // Determine activity status using same logic as view.php
                            // If sessions is null, not attempted. If satisfied=true, completed. Otherwise in progress.

                            // DEBUG: Log actual values
                            error_log("CMI5 Status Debug for AU {$auid}: satisfied=" .
                                var_export($au->satisfied ?? 'NOTSET', true) .
                                ", sessions=" . var_export($au->sessions ?? 'NOTSET', true));

                            $status = 'notstarted';

                            // Check if activity has been attempted (has sessions)
                            if ($au->sessions == null || !isset($au->sessions)) {
                                $status = 'notstarted';
                            } else if (!empty($au->satisfied) && ($au->satisfied === "true" || $au->satisfied === 1 || $au->satisfied === true)) {
                                $status = 'completed';
                            } else {
                                // Has sessions but not satisfied = in progress
                                $status = 'inprogress';
                            }

                            error_log("CMI5 Status Debug: Final status = {$status}");

                            $activities[] = array(
                                'id' => $auid,
                                'title' => $au->title,
                                'index' => $index,
                                'status' => $status
                            );
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            }
        } else if ($userscourse && !empty($cmi5launch->aus)) {
            // User has a record but AUs not saved yet - show from manifest
            // Parse AU data directly from the CMI5 launch record as preview
            error_log('CMI5: Entering fallback block');
            $ausdata = json_decode($cmi5launch->aus);
            error_log('CMI5: ausdata type=' . gettype($ausdata) . ', is_array=' . (is_array($ausdata) ? 'yes' : 'no'));
            if ($ausdata && is_array($ausdata)) {
                error_log('CMI5: Processing ' . count($ausdata) . ' AUs from manifest');
                foreach ($ausdata as $index => $audata) {
                    $title = '';

                    // Try to extract title from various possible structures
                    if (is_object($audata)) {
                        if (isset($audata->title)) {
                            // Title structure: title: [{ text: "Activity Name" }]
                            if (is_array($audata->title) && count($audata->title) > 0) {
                                $titleobj = $audata->title[0];
                                if (is_object($titleobj) && isset($titleobj->text)) {
                                    $title = $titleobj->text;
                                } else if (is_string($audata->title[0])) {
                                    $title = $audata->title[0];
                                }
                            } else if (is_string($audata->title)) {
                                $title = $audata->title;
                            }
                        }
                        // Also check other possible name fields
                        if (empty($title) && isset($audata->activityName)) {
                            $title = $audata->activityName;
                        }
                        if (empty($title) && isset($audata->name)) {
                            $title = $audata->name;
                        }
                    }

                    // Fallback to numbered activity
                    if (empty($title)) {
                        $title = 'Activity ' . ($index + 1);
                    }

                    // Always add the activity
                    $activities[] = array(
                        'id' => 'init_' . $index,  // Special ID to indicate needs init
                        'title' => $title,
                        'index' => $index,
                        'needsinit' => true,
                        'status' => 'notstarted'  // Fallback activities are always not started
                    );
                    error_log('CMI5: Added activity ' . $index . ': ' . $title);
                }
                error_log('CMI5: Total activities added: ' . count($activities));
            } else {
                error_log('CMI5: ausdata check failed - not array or empty');
            }
        }
        // For users who haven't started yet, we don't show the accordion
        // They'll see "Begin Exercise" button instead which takes them to view.php
        // Once they've started, they'll see this accordion on return visits
    } catch (Exception $e) {
        // No activities loaded - will show "Begin Exercise" button
        // Log error for debugging
        error_log('CMI5 coursemodule info error: ' . $e->getMessage());
    }

    // Debug comment
    $debuginfo = 'activities=' . count($activities);
    $debuginfo .= ', userhasstarted=' . ($userhasstarted ? 'yes' : 'no');
    if (isset($userscourse)) {
        $debuginfo .= ', usercourse=found';
        $debuginfo .= ', usercourse_aus=' . (!empty($userscourse->aus) ? 'yes' : 'no');
        if (!empty($userscourse->aus)) {
            $auids_count = is_array(json_decode($userscourse->aus)) ? count(json_decode($userscourse->aus)) : 0;
            $debuginfo .= ', auids_count=' . $auids_count;
        }
    } else {
        $debuginfo .= ', usercourse=notfound';
    }
    // Check manifest AUs
    $debuginfo .= ', manifest_aus=' . (!empty($cmi5launch->aus) ? 'yes' : 'no');
    if (!empty($cmi5launch->aus)) {
        $manifest_aus = json_decode($cmi5launch->aus);
        $debuginfo .= ', manifest_count=' . (is_array($manifest_aus) ? count($manifest_aus) : '0');
    }
    $customhtml .= '<!-- CMI5 Debug: ' . $debuginfo . ' -->';

    // Add accordion toggle button
    $customhtml .= html_writer::start_div('cmi5launch-card-actions');

    if (!empty($activities)) {
        $customhtml .= html_writer::tag('button',
            html_writer::span('▼', 'cmi5-toggle-icon', array('id' => 'toggle-icon-' . $coursemodule->id)) .
            html_writer::span('View Activities (' . count($activities) . ')', 'cmi5-toggle-text'),
            array(
                'class' => 'btn cmi5-course-toggle-btn',
                'onclick' => 'toggleCMI5Activities(' . $coursemodule->id . ')',
                'type' => 'button',
                'id' => 'toggle-btn-' . $coursemodule->id
            )
        );
    } else {
        $viewurl = new moodle_url('/mod/cmi5launch/view.php', array('id' => $coursemodule->id));
        $customhtml .= html_writer::link(
            $viewurl,
            html_writer::span('▶', 'cmi5-launch-icon') . html_writer::span('Begin Exercise', 'cmi5-launch-text'),
            array('class' => 'btn cmi5-course-launch-btn', 'title' => 'Launch ' . $cmi5launch->name)
        );
    }

    $customhtml .= html_writer::end_div(); // cmi5launch-card-actions

    // Add activities accordion (hidden by default)
    if (!empty($activities)) {
        $customhtml .= html_writer::start_div('cmi5-activities-accordion',
            array('id' => 'activities-' . $coursemodule->id, 'style' => 'display: none;'));

        foreach ($activities as $activity) {
            $needsinit = isset($activity['needsinit']) && $activity['needsinit'] ? 'true' : 'false';
            $status = $activity['status'] ?? 'notstarted';

            // Status icons and classes
            $statusIcon = '○';  // Not started
            $statusClass = 'status-notstarted';
            $statusText = 'Not Started';
            if ($status === 'completed') {
                $statusIcon = '✓';
                $statusClass = 'status-completed';
                $statusText = 'Completed';
            } else if ($status === 'inprogress') {
                $statusIcon = '▶';
                $statusClass = 'status-inprogress';
                $statusText = 'In Progress';
            }

            $customhtml .= html_writer::start_div('cmi5-activity-item ' . $statusClass);
            // Use href="#" with onclick that prevents default
            $customhtml .= html_writer::tag('a',
                html_writer::span($statusIcon, 'activity-status-icon') .
                html_writer::span($activity['title'], 'activity-title') .
                html_writer::span($statusText, 'activity-status-badge'),
                array(
                    'href' => '#',
                    'class' => 'cmi5-activity-launch',
                    'onclick' => 'return launchCMI5Activity(event, ' . $coursemodule->id . ', "' . $activity['id'] . '", ' . $activity['index'] . ', ' . $needsinit . ');',
                    'data-auid' => $activity['id'],
                    'data-auindex' => $activity['index'],
                    'title' => $statusText
                )
            );
            $customhtml .= html_writer::end_div();
        }

        $customhtml .= html_writer::end_div(); // activities accordion

        // Add inline JavaScript for this specific card
        $activitiesJson = json_encode($activities);
        $customhtml .= html_writer::script("
            if (typeof window.cmi5Activities === 'undefined') {
                window.cmi5Activities = {};
            }
            window.cmi5Activities[{$coursemodule->id}] = {$activitiesJson};

            // Add global functions if not already defined
            if (typeof window.toggleCMI5Activities === 'undefined') {
                window.toggleCMI5Activities = function(cmid) {
                    var accordion = document.getElementById('activities-' + cmid);
                    var icon = document.getElementById('toggle-icon-' + cmid);
                    var btn = document.getElementById('toggle-btn-' + cmid);

                    if (accordion.style.display === 'none') {
                        accordion.style.display = 'block';
                        if (icon) icon.textContent = '▲';
                        if (btn) btn.classList.add('expanded');
                    } else {
                        accordion.style.display = 'none';
                        if (icon) icon.textContent = '▼';
                        if (btn) btn.classList.remove('expanded');
                    }
                };

                // Store activities for this course module
                if (typeof window.cmi5ModalActivities === 'undefined') {
                    window.cmi5ModalActivities = {};
                }
                window.cmi5ModalActivities[" . $coursemodule->id . "] = " . json_encode($activities) . ";

                window.launchCMI5Activity = function(event, cmid, auid, auindex, needsinit) {
                    console.log('launchCMI5Activity called:', {cmid, auid, auindex, needsinit});

                    // Prevent default link behavior
                    if (event) {
                        event.preventDefault();
                        event.stopPropagation();
                    }

                    if (needsinit) {
                        // Navigate to view.php to initialize
                        window.location.href = '/mod/cmi5launch/view.php?id=' + cmid;
                        return false;
                    }

                    // Load activity directly in modal
                    loadCMI5ModalActivity(cmid, auindex);

                    return false;
                };

                function loadCMI5ModalActivity(cmid, auindex) {
                    var activities = window.cmi5ModalActivities[cmid];
                    if (!activities || auindex < 0 || auindex >= activities.length) {
                        console.error('Invalid activity index:', auindex);
                        return;
                    }

                    var activity = activities[auindex];
                    console.log('Loading activity:', activity);

                    // Build URL for launch.php
                    var url = '/mod/cmi5launch/launch.php?launchform_registration=' +
                              encodeURIComponent(activity.id) +
                              '&restart=false&id=' + cmid;

                    // Show modal and loading spinner
                    var modal = document.getElementById('cmi5-course-modal-' + cmid);
                    var iframe = document.getElementById('cmi5-course-iframe-' + cmid);
                    var loading = document.getElementById('cmi5-course-loading-' + cmid);
                    var dropdown = document.getElementById('cmi5-modal-dropdown-' + cmid);
                    var title = document.getElementById('cmi5-modal-title-' + cmid);

                    if (modal && iframe && loading) {
                        // Store current index
                        modal.dataset.currentIndex = auindex;

                        // Update UI
                        loading.style.display = 'flex';
                        modal.style.display = 'block';
                        document.body.style.overflow = 'hidden';

                        // Update title and dropdown
                        if (title) {
                            title.textContent = activity.title || ('Activity ' + (auindex + 1));
                        }
                        if (dropdown) {
                            populateCMI5Dropdown(cmid);
                            dropdown.value = auindex;
                        }

                        // Update navigation buttons
                        updateCMI5NavButtons(cmid);

                        // Load iframe
                        iframe.src = url;
                    }
                }

                function populateCMI5Dropdown(cmid) {
                    var dropdown = document.getElementById('cmi5-modal-dropdown-' + cmid);
                    var activities = window.cmi5ModalActivities[cmid];

                    if (!dropdown || !activities) return;

                    // Clear existing options except first
                    dropdown.innerHTML = '<option value=\"\">Select Activity...</option>';

                    // Add activity options
                    activities.forEach(function(activity, index) {
                        var option = document.createElement('option');
                        option.value = index;
                        option.textContent = (index + 1) + '. ' + (activity.title || 'Activity ' + (index + 1));
                        dropdown.appendChild(option);
                    });
                }

                function updateCMI5NavButtons(cmid) {
                    var modal = document.getElementById('cmi5-course-modal-' + cmid);
                    var activities = window.cmi5ModalActivities[cmid];
                    var prevBtn = document.getElementById('cmi5-modal-prev-' + cmid);
                    var nextBtn = document.getElementById('cmi5-modal-next-' + cmid);

                    if (!modal || !activities || !prevBtn || !nextBtn) return;

                    var currentIndex = parseInt(modal.dataset.currentIndex || 0);

                    // Disable/enable buttons
                    prevBtn.disabled = currentIndex === 0;
                    nextBtn.disabled = currentIndex === activities.length - 1;
                    prevBtn.style.opacity = currentIndex === 0 ? '0.3' : '1';
                    nextBtn.style.opacity = currentIndex === activities.length - 1 ? '0.3' : '1';
                }

                window.navigateCMI5Modal = function(cmid, direction) {
                    var modal = document.getElementById('cmi5-course-modal-' + cmid);
                    var activities = window.cmi5ModalActivities[cmid];

                    if (!modal || !activities) return;

                    var currentIndex = parseInt(modal.dataset.currentIndex || 0);
                    var newIndex = currentIndex + direction;

                    if (newIndex >= 0 && newIndex < activities.length) {
                        loadCMI5ModalActivity(cmid, newIndex);
                    }
                };

                window.jumpCMI5Modal = function(cmid, index) {
                    if (index === '') return;
                    loadCMI5ModalActivity(cmid, parseInt(index));
                };

                window.hideCMI5Loading = function(cmid) {
                    var loading = document.getElementById('cmi5-course-loading-' + cmid);
                    if (loading) {
                        loading.style.display = 'none';
                    }
                };

                window.closeCMI5CourseModal = function(cmid) {
                    var modal = document.getElementById('cmi5-course-modal-' + cmid);
                    var iframe = document.getElementById('cmi5-course-iframe-' + cmid);
                    var loading = document.getElementById('cmi5-course-loading-' + cmid);

                    if (modal && iframe) {
                        modal.style.display = 'none';
                        iframe.src = ''; // Clear iframe to stop activity
                        document.body.style.overflow = ''; // Restore scrolling
                        if (loading) {
                            loading.style.display = 'flex'; // Reset loading for next time
                        }
                    }
                };

                window.minimizeCMI5Modal = function(cmid) {
                    var content = document.getElementById('cmi5-course-modal-' + cmid).querySelector('.cmi5-course-modal-content');
                    if (!content) return;

                    if (content.style.height === '50px') {
                        // Restore
                        var state = window.cmi5ModalState && window.cmi5ModalState[cmid];
                        if (state && state.lastSize) {
                            content.style.width = state.lastSize.width;
                            content.style.height = state.lastSize.height;
                            content.style.top = state.lastSize.top;
                            content.style.left = state.lastSize.left;
                        }
                    } else {
                        // Minimize
                        if (!window.cmi5ModalState) window.cmi5ModalState = {};
                        if (!window.cmi5ModalState[cmid]) window.cmi5ModalState[cmid] = {};
                        window.cmi5ModalState[cmid].lastSize = {
                            width: content.style.width || '95%',
                            height: content.style.height || '95%',
                            top: content.style.top || '2%',
                            left: content.style.left || '2.5%'
                        };
                        content.style.height = '50px';
                        content.style.top = 'auto';
                        content.style.bottom = '20px';
                        content.style.left = '20px';
                        content.style.width = '400px';
                    }
                };

                window.toggleMaximizeCMI5Modal = function(cmid) {
                    var content = document.getElementById('cmi5-course-modal-' + cmid).querySelector('.cmi5-course-modal-content');
                    if (!content) return;

                    if (!window.cmi5ModalState) window.cmi5ModalState = {};
                    if (!window.cmi5ModalState[cmid]) window.cmi5ModalState[cmid] = { isMaximized: false };

                    if (window.cmi5ModalState[cmid].isMaximized) {
                        // Restore
                        var lastPos = window.cmi5ModalState[cmid].lastPosition;
                        if (lastPos) {
                            content.style.width = lastPos.width;
                            content.style.height = lastPos.height;
                            content.style.top = lastPos.top;
                            content.style.left = lastPos.left;
                            content.style.margin = '2% auto';
                        }
                        window.cmi5ModalState[cmid].isMaximized = false;
                    } else {
                        // Maximize
                        window.cmi5ModalState[cmid].lastPosition = {
                            width: content.style.width || '95%',
                            height: content.style.height || '95%',
                            top: content.style.top || '2%',
                            left: content.style.left || '2.5%'
                        };
                        content.style.width = '100%';
                        content.style.height = '100%';
                        content.style.top = '0';
                        content.style.left = '0';
                        content.style.margin = '0';
                        window.cmi5ModalState[cmid].isMaximized = true;
                    }
                };

                window.popOutCMI5Modal = function(cmid) {
                    var modal = document.getElementById('cmi5-course-modal-' + cmid);
                    var iframe = document.getElementById('cmi5-course-iframe-' + cmid);
                    if (!iframe || !iframe.src) return;

                    // Open current iframe URL in new window
                    var width = Math.min(1400, window.screen.width * 0.9);
                    var height = Math.min(900, window.screen.height * 0.9);
                    var left = (window.screen.width - width) / 2;
                    var top = (window.screen.height - height) / 2;

                    window.open(iframe.src, 'CMI5Activity_' + cmid + '_' + Date.now(),
                        'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top +
                        ',toolbar=no,menubar=no,scrollbars=yes,resizable=yes');

                    // Close the modal
                    closeCMI5CourseModal(cmid);
                };

                // Make modal draggable
                function makeCMI5ModalDraggable(cmid) {
                    var content = document.getElementById('cmi5-course-modal-' + cmid).querySelector('.cmi5-course-modal-content');
                    var header = document.getElementById('cmi5-modal-header-' + cmid);
                    if (!content || !header) return;

                    var isDragging = false;
                    var offsetX, offsetY;

                    header.style.cursor = 'grab';

                    header.addEventListener('mousedown', function(e) {
                        if (e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT' || e.target.closest('.cmi5-modal-nav-controls')) {
                            return;
                        }

                        isDragging = true;
                        header.style.cursor = 'grabbing';

                        var rect = content.getBoundingClientRect();
                        offsetX = e.clientX - rect.left;
                        offsetY = e.clientY - rect.top;

                        e.preventDefault();
                    });

                    document.addEventListener('mousemove', function(e) {
                        if (!isDragging) return;

                        var newLeft = e.clientX - offsetX;
                        var newTop = e.clientY - offsetY;

                        content.style.left = newLeft + 'px';
                        content.style.top = newTop + 'px';
                        content.style.margin = '0';
                    });

                    document.addEventListener('mouseup', function() {
                        if (isDragging) {
                            isDragging = false;
                            header.style.cursor = 'grab';
                        }
                    });
                }

                // Make modal resizable
                function makeCMI5ModalResizable(cmid) {
                    var content = document.getElementById('cmi5-course-modal-' + cmid).querySelector('.cmi5-course-modal-content');
                    var handles = content.querySelectorAll('.cmi5-resize-handle');

                    handles.forEach(function(handle) {
                        var isResizing = false;
                        var startX, startY, startWidth, startHeight, startLeft, startTop;

                        handle.addEventListener('mousedown', function(e) {
                            isResizing = true;
                            startX = e.clientX;
                            startY = e.clientY;

                            var rect = content.getBoundingClientRect();
                            startWidth = rect.width;
                            startHeight = rect.height;
                            startLeft = rect.left;
                            startTop = rect.top;

                            e.preventDefault();
                            e.stopPropagation();
                        });

                        document.addEventListener('mousemove', function(e) {
                            if (!isResizing) return;

                            var deltaX = e.clientX - startX;
                            var deltaY = e.clientY - startY;

                            if (handle.classList.contains('cmi5-resize-handle-r')) {
                                content.style.width = (startWidth + deltaX) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-l')) {
                                content.style.width = (startWidth - deltaX) + 'px';
                                content.style.left = (startLeft + deltaX) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-b')) {
                                content.style.height = (startHeight + deltaY) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-t')) {
                                content.style.height = (startHeight - deltaY) + 'px';
                                content.style.top = (startTop + deltaY) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-br')) {
                                content.style.width = (startWidth + deltaX) + 'px';
                                content.style.height = (startHeight + deltaY) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-bl')) {
                                content.style.width = (startWidth - deltaX) + 'px';
                                content.style.height = (startHeight + deltaY) + 'px';
                                content.style.left = (startLeft + deltaX) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-tr')) {
                                content.style.width = (startWidth + deltaX) + 'px';
                                content.style.height = (startHeight - deltaY) + 'px';
                                content.style.top = (startTop + deltaY) + 'px';
                            } else if (handle.classList.contains('cmi5-resize-handle-tl')) {
                                content.style.width = (startWidth - deltaX) + 'px';
                                content.style.height = (startHeight - deltaY) + 'px';
                                content.style.left = (startLeft + deltaX) + 'px';
                                content.style.top = (startTop + deltaY) + 'px';
                            }

                            content.style.margin = '0';
                        });

                        document.addEventListener('mouseup', function() {
                            isResizing = false;
                        });
                    });
                }

                // Initialize dragging and resizing when modal is first opened
                var originalLoadFunction = loadCMI5ModalActivity;
                loadCMI5ModalActivity = function(cmid, auindex) {
                    originalLoadFunction(cmid, auindex);

                    // Initialize on first load
                    setTimeout(function() {
                        if (!window.cmi5ModalInitialized) window.cmi5ModalInitialized = {};
                        if (!window.cmi5ModalInitialized[cmid]) {
                            makeCMI5ModalDraggable(cmid);
                            makeCMI5ModalResizable(cmid);
                            window.cmi5ModalInitialized[cmid] = true;
                        }
                    }, 100);
                };
            }
        ");
    }

    $customhtml .= html_writer::end_div(); // cmi5launch-card-content
    $customhtml .= html_writer::end_div(); // cmi5launch-course-card

    // Add modal container for course page player (hidden by default)
    $customhtml .= html_writer::start_div('cmi5-course-modal', array('id' => 'cmi5-course-modal-' . $coursemodule->id, 'style' => 'display: none;'));
    $customhtml .= html_writer::start_div('cmi5-course-modal-content');

    // Modal header with navigation controls
    $customhtml .= html_writer::start_div('cmi5-course-modal-header', array('id' => 'cmi5-modal-header-' . $coursemodule->id));
    $customhtml .= html_writer::start_div('cmi5-modal-header-left');
    $customhtml .= html_writer::tag('span', '⋮⋮', array('class' => 'cmi5-modal-drag-icon', 'title' => 'Drag to move'));
    $customhtml .= html_writer::start_div('cmi5-modal-nav-controls');
    $customhtml .= html_writer::tag('button', '‹', array(
        'class' => 'cmi5-modal-nav-btn',
        'onclick' => 'navigateCMI5Modal(' . $coursemodule->id . ', -1)',
        'title' => 'Previous Activity',
        'id' => 'cmi5-modal-prev-' . $coursemodule->id
    ));
    $customhtml .= html_writer::start_tag('select', array(
        'class' => 'cmi5-modal-dropdown',
        'id' => 'cmi5-modal-dropdown-' . $coursemodule->id,
        'onchange' => 'jumpCMI5Modal(' . $coursemodule->id . ', this.value)',
        'title' => 'Select Activity'
    ));
    $customhtml .= html_writer::tag('option', 'Select Activity...', array('value' => ''));
    $customhtml .= html_writer::end_tag('select');
    $customhtml .= html_writer::tag('button', '›', array(
        'class' => 'cmi5-modal-nav-btn',
        'onclick' => 'navigateCMI5Modal(' . $coursemodule->id . ', 1)',
        'title' => 'Next Activity',
        'id' => 'cmi5-modal-next-' . $coursemodule->id
    ));
    $customhtml .= html_writer::end_div(); // cmi5-modal-nav-controls
    $customhtml .= html_writer::tag('h3', 'Activity Player', array(
        'id' => 'cmi5-modal-title-' . $coursemodule->id,
        'class' => 'cmi5-modal-title'
    ));
    $customhtml .= html_writer::end_div(); // cmi5-modal-header-left
    $customhtml .= html_writer::start_div('cmi5-modal-header-right');
    $customhtml .= html_writer::tag('button', '−', array(
        'class' => 'cmi5-modal-control-btn',
        'onclick' => 'minimizeCMI5Modal(' . $coursemodule->id . ')',
        'title' => 'Minimize'
    ));
    $customhtml .= html_writer::tag('button', '□', array(
        'class' => 'cmi5-modal-control-btn',
        'onclick' => 'toggleMaximizeCMI5Modal(' . $coursemodule->id . ')',
        'title' => 'Maximize/Restore',
        'id' => 'cmi5-modal-maximize-' . $coursemodule->id
    ));
    $customhtml .= html_writer::tag('button', '⧉', array(
        'class' => 'cmi5-modal-control-btn',
        'onclick' => 'popOutCMI5Modal(' . $coursemodule->id . ')',
        'title' => 'Pop Out to New Window'
    ));
    $customhtml .= html_writer::tag('button', '×', array(
        'class' => 'cmi5-course-modal-close',
        'onclick' => 'closeCMI5CourseModal(' . $coursemodule->id . ')',
        'title' => 'Close'
    ));
    $customhtml .= html_writer::end_div(); // cmi5-modal-header-right
    $customhtml .= html_writer::end_div(); // cmi5-course-modal-header

    // Modal body with loading spinner and iframe
    $customhtml .= html_writer::start_div('cmi5-course-modal-body');
    // Loading spinner
    $customhtml .= html_writer::start_div('cmi5-course-loading', array('id' => 'cmi5-course-loading-' . $coursemodule->id));
    $customhtml .= html_writer::div('', 'cmi5-course-spinner');
    $customhtml .= html_writer::div('Loading activity...', 'cmi5-course-loading-text');
    $customhtml .= html_writer::end_div(); // cmi5-course-loading
    $customhtml .= html_writer::tag('iframe', '', array(
        'id' => 'cmi5-course-iframe-' . $coursemodule->id,
        'src' => '',
        'frameborder' => '0',
        'allowfullscreen' => 'true',
        'onload' => 'hideCMI5Loading(' . $coursemodule->id . ')'
    ));
    $customhtml .= html_writer::end_div(); // cmi5-course-modal-body

    // Add resize handles
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-br');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-bl');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-tr');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-tl');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-r');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-l');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-t');
    $customhtml .= html_writer::div('', 'cmi5-resize-handle cmi5-resize-handle-b');

    $customhtml .= html_writer::end_div(); // cmi5-course-modal-content
    $customhtml .= html_writer::end_div(); // cmi5-course-modal

    $info->content = $customhtml;

    // Moodle will automatically use pix/icon.svg which adapts to theme colors

    return $info;
}

/**
 * Saves a new instance of the cmi5launch into the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $cmi5launch An object from the form in mod_form.php
 * @param mod_cmi5launch_mod_form $mform
 * @return int The id of the newly inserted cmi5launch record
 */
function cmi5launch_add_instance(stdClass $cmi5launch, mod_cmi5launch_mod_form $mform = null) {

    global $DB, $CFG;

    $cmi5launch->timecreated = time();

    // Need the id of the newly created instance to return (and use if override defaults checkbox is checked).
    $cmi5launch->id = $DB->insert_record('cmi5launch', $cmi5launch);

    // Process uploaded file.
    if (!empty($cmi5launch->packagefile)) {

        cmi5launch_process_new_package($cmi5launch);
    }

    return $cmi5launch->id;
}

/**
 * Updates an instance of the cmi5launch in the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $cmi5launch An object from the form in mod_form.php
 * @param mod_cmi5launch_mod_form $mform
 * @return boolean Success/Fail
 */
function cmi5launch_update_instance(stdClass $cmi5launch, mod_cmi5launch_mod_form $mform = null) {
    global $DB, $CFG;

    $cmi5launch->timemodified = time();
    $cmi5launch->id = $cmi5launch->instance;

    // We removed this part of lrs box -MB
    // $cmi5launchlrs = cmi5launch_build_lrs_settings($cmi5launch);
    /*
    // Determine if override defaults checkbox is checked.
    if ($cmi5launch->overridedefaults == '1') {
        // Check to see if there is a record of this instance in the table.
        $cmi5launchlrsid = $DB->get_field(
            'cmi5launch_lrs',
            'id',
            array('cmi5launchid' => $cmi5launch->instance),
            IGNORE_MISSING
        );
        // If not, will need to insert_record.
        if (!$cmi5launchlrsid) {
            if (!$DB->insert_record('cmi5launch_lrs', $cmi5launchlrs)) {
                return false;
            }
        } else { // If it does exist, update it.
            $cmi5launchlrs->id = $cmi5launchlrsid;

            if (!$DB->update_record('cmi5launch_lrs', $cmi5launchlrs)) {
                return false;
            }
        }
    }
    */

    if (!$DB->update_record('cmi5launch', $cmi5launch)) {
        return false;
    }

    // Process uploaded file.
    if (!empty($cmi5launch->packagefile)) {
        cmi5launch_process_new_package($cmi5launch);
    }

    return true;
}

function cmi5launch_build_lrs_settings(stdClass $cmi5launch) {
    global $DB, $CFG;

    // Data for cmi5launch_lrs table.
    $cmi5launchlrs = new stdClass();
    $cmi5launchlrs->customacchp = $cmi5launch->cmi5launchcustomacchp;
    $cmi5launchlrs->useactoremail = $cmi5launch->cmi5launchuseactoremail;
    $cmi5launchlrs->cmi5launchid = $cmi5launch->instance;

    return $cmi5launchlrs;
}

/**
 * Removes an instance of the cmi5launch from the database
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function cmi5launch_delete_instance($id) {
    global $DB;

    // Currently it deletes only the cmi5launch, Do we want it to delete ALL tables? What is it suppossed to do>
    // Like if it deletes all tables is it expected to delete user data?
    
    if (! $cmi5launch = $DB->get_record('cmi5launch', array('id' => $id))) {
        return false;
    }

    // FORGET THE HARDCODED, THIS WORKS WITHOUT IT, IT APPEARS TO DELETE EVERYTHIN IN A"COURSE" BUT IF AN ACTIVITY IS DELETED IT DISAPEARS FROM MOODLE
    //BUT NOT FROM DB. is THIS SUPPOSSED TO DO DB? 
    
    ///huh? why is this hardcoded?
    // Determine if there is a record of this (ever) in the cmi5launch_lrs table.
  /*  $cmi5launchlrsid = $DB->get_field('cmi5launch_lrs', 'id', array('cmi5launchid' => $id), $strictness = IGNORE_MISSING);
    if ($cmi5launchlrsid) {
        // If there is, delete it.
        $DB->delete_records('cmi5launch_lrs', array('id' => $cmi5launchlrsid));
    }
*/
    $DB->delete_records('cmi5launch', array('id' => $cmi5launch->id));

    return true;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @return stdClass|null
 */
function cmi5launch_user_outline($course, $user, $mod, $cmi5launch) {
    $return = new stdClass();
    $return->time = 0;
    $return->info = '';
    return $return;
}

/**
 * Prints a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param stdClass $course the current course record
 * @param stdClass $user the record of the user we are generating report for
 * @param cm_info $mod course module info
 * @param stdClass $cmi5launch the module instance record
 * @return void, is supposed to echp directly
 */
function cmi5launch_user_complete($course, $user, $mod, $cmi5launch) {
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in cmi5launch activities and print it out.
 * Return true if there was output, or false is there was none.
 *
 * @return boolean
 */
function cmi5launch_print_recent_activity($course, $viewfullnames, $timestart) {
    return false;  // True if anything was printed, otherwise false.
}

/**
 * Prepares the recent activity data
 *
 * This callback function is supposed to populate the passed array with
 * custom activity records. These records are then rendered into HTML via
 * {@link cmi5launch_print_recent_mod_activity()}.
 *
 * @param array $activities sequentially indexed array of objects with the 'cmid' property
 * @param int $index the index in the $activities to use for the next record
 * @param int $timestart append activity since this time
 * @param int $courseid the id of the course we produce the report for
 * @param int $cmid course module id
 * @param int $userid check for a particular user's activity only, defaults to 0 (all users)
 * @param int $groupid check for a particular group's activity only, defaults to 0 (all groups)
 * @return void adds items into $activities and increases $index
 */
function cmi5launch_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid = 0, $groupid = 0) {
}

/**
 * Prints single activity item prepared by {@see cmi5launch_get_recent_mod_activity()}
 * @return void
 */
function cmi5launch_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
}

/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @return boolean
 * @todo Finish documenting this function
 **/
function cmi5launch_cron() {
    return true;
}

/**
 * Returns all other caps used in the module
 *
 * @example return array('moodle/site:accessallgroups');
 * @return array
 */
function cmi5launch_get_extra_capabilities() {
    return array();
}

// File API.

/**
 * Returns the lists of all browsable file areas within the given module context
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by {@link file_browser::get_file_info_context_module()}
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 */
function cmi5launch_get_file_areas($course, $cm, $context) {
    $areas = array();
    $areas['content'] = get_string('areacontent', 'scorm');
    $areas['package'] = get_string('areapackage', 'scorm');
    return $areas;
}

/**
 * File browsing support for cmi5launch file areas
 *
 * @package mod_cmi5launch
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info instance or null if not found
 */
function cmi5launch_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG;

    if (!has_capability('moodle/course:managefiles', $context)) {
        return null;
    }

    $fs = get_file_storage();

    if ($filearea === 'package') {
        $filepath = is_null($filepath) ? '/' : $filepath;
        $filename = is_null($filename) ? '.' : $filename;

        $urlbase = $CFG->wwwroot.'/pluginfile.php';
        if (!$storedfile = $fs->get_file($context->id, 'mod_cmi5launch', 'package', 0, $filepath, $filename)) {
            if ($filepath === '/' && $filename === '.') {
                $storedfile = new virtual_root_file($context->id, 'mod_cmi5launch', 'package', 0);
            } else {
                // Not found.
                return null;
            }
        }
        return new file_info_stored($browser, $context, $storedfile, $urlbase, $areas[$filearea], false, true, false, false);
    }

    return false;
}

/**
 * Serves cmi5 content, introduction images and packages. Implements needed access control ;-)
 *
 * @package  mod_cmi5launch
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - just send the file
 */
function cmi5launch_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);
    $canmanageactivity = has_capability('moodle/course:manageactivities', $context);

    $filename = array_pop($args);
    $filepath = implode('/', $args);
    if ($filearea === 'content') {
        $lifetime = null;
    } else if ($filearea === 'package') {
        $lifetime = 0; // No caching here.
    } else {
        return false;
    }

    $fs = get_file_storage();

    if (
        (!$file = $fs->get_file($context->id, 'mod_cmi5launch', $filearea, 0, '/'.$filepath.'/', $filename))
        || ($file->is_directory())
    ) {
        if ($filearea === 'content') { // Return file not found straight away to improve performance.
            send_header_404();
            die;
        }
        return false;
    }

    // Finally send the file.
    send_stored_file($file, $lifetime, 0, false, $options);
}

/**
 * Export file resource contents for web service access.
 *
 * @param cm_info $cm Course module object.
 * @param string $baseurl Base URL for Moodle.
 * @return array array of file content
 */
function cmi5launch_export_contents($cm, $baseurl) {
    global $CFG;
    $contents = array();
    $context = context_module::instance($cm->id);

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_cmi5launch', 'package', 0, 'sortorder DESC, id ASC', false);

    foreach ($files as $fileinfo) {
        $file = array();
        $file['type'] = 'file';
        $file['filename']     = $fileinfo->get_filename();
        $file['filepath']     = $fileinfo->get_filepath();
        $file['filesize']     = $fileinfo->get_filesize();
        $file['fileurl']      = file_encode_url("$CFG->wwwroot/" . $baseurl, '/'.$context->id.'/mod_cmi5launch/package'.
            $fileinfo->get_filepath().$fileinfo->get_filename(), true);
        $file['timecreated']  = $fileinfo->get_timecreated();
        $file['timemodified'] = $fileinfo->get_timemodified();
        $file['sortorder']    = $fileinfo->get_sortorder();
        $file['userid']       = $fileinfo->get_userid();
        $file['author']       = $fileinfo->get_author();
        $file['license']      = $fileinfo->get_license();
        $contents[] = $file;
    }

    return $contents;
}

// Navigation API.

/**
 * Extends the global navigation tree by adding cmi5launch nodes if there is a relevant content
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $navref An object representing the navigation tree node of the cmi5launch module instance
 * @param stdClass $course
 * @param stdClass $module
 * @param cm_info $cm
 */
function cmi5launch_extend_navigation(navigation_node $navref, stdclass $course, stdclass $module, cm_info $cm) {
}

/**
 * Extends the settings navigation with the cmi5launch settings
 *
 * This function is called when the context for the page is a cmi5launch module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav {@link settings_navigation}
 * @param navigation_node $cmi5launchnode {@link navigation_node}
 */
function cmi5launch_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $cmi5launchnode = null) {
}

// Called by Moodle core.
function cmi5launch_get_completion_state($course, $cm, $userid, $type) {
    global $CFG, $DB;
    $result = $type; // Default return value.

     // Get cmi5launch.
    if (!$cmi5launch = $DB->get_record('cmi5launch', array('id' => $cm->instance))) {
        throw new Exception("Can't find activity {$cm->instance}"); // TODO: localise this.
    }

    $cmi5launchsettings = cmi5launch_settings($cm->instance);

    $expirydate = null;
    $expirydays = $cmi5launch->cmi5expiry;
    if ($expirydays > 0) {
        $expirydatetime = new DateTime();
        $expirydatetime->sub(new DateInterval('P'.$expirydays.'D'));
        $expirydate = $expirydatetime->format('c');
    }

    if (!empty($cmi5launch->cmi5verbid)) {
        // Try to get a statement matching actor, verb and object specified in module settings.
        $statementquery = cmi5launch_get_statements(
            $cmi5launchsettings['cmi5launchlrsendpoint'],
            $cmi5launchsettings['cmi5launchlrslogin'],
            $cmi5launchsettings['cmi5launchlrspass'],
            $cmi5launchsettings['cmi5launchlrsversion'],
            $cmi5launch->cmi5activityid,
            cmi5launch_getactor($cm->instance),
            $cmi5launch->cmi5verbid,
            $expirydate
        );

        // If the statement exists, return true else return false.
        if (!empty($statementquery->content) && $statementquery->success) {
            $result = true;
        } else {
            $result = false;
        }
    }

    return $result;
}

// Cmi5Launch specific functions.
/*
The functions below should really be in locallib, however they are required for one
or more of the functions above so need to be here.
It looks like the standard Quiz module does that same thing, so I don't feel so bad.
*/

/**
 * Handles uploaded zip packages when a module is added or updated. Unpacks the zip contents
 * and extracts the launch url and activity id from the cmi5.xml file.
 * Note: This takes the *first* activity from the cmi5.xml file to be the activity intended
 * to be launched. It will not go hunting for launch URLs any activities listed below.
 * Based closely on code from the SCORM and (to a lesser extent) Resource modules.
 * @package  mod_cmi5launch
 * @category cmi5
 * @param object $cmi5launch An object from the form in mod_form.php
 * @return array empty if no issue is found. Array of error message otherwise
 */

function cmi5launch_process_new_package($cmi5launch) {

    global $DB, $CFG;
    $cmid = $cmi5launch->coursemodule;
    $context = context_module::instance($cmid);

    // Bring in functions from classes cmi5Connector and AU helpers.
    $connectors = new cmi5_connectors;
    $auhelper = new au_helpers;

    // Bring in functions from class cmi5_table_connectors and AU helpers.
    $createcourse = $connectors->cmi5launch_get_create_course();
    $retrieveaus = $auhelper->get_cmi5launch_retrieve_aus();
    // Reload cmi5 instance.
    $record = $DB->get_record('cmi5launch', array('id' => $cmi5launch->id));

    $fs = get_file_storage();

    $fs->delete_area_files($context->id, 'mod_cmi5launch', 'package');
    file_save_draft_area_files(
        $cmi5launch->packagefile,
        $context->id,
        'mod_cmi5launch',
        'package',
        0,
        array('subdirs' => 0, 'maxfiles' => 1)
    );
    // Get filename of zip that was uploaded.
    $files = $fs->get_area_files($context->id, 'mod_cmi5launch', 'package', 0, '', false);
    if (count($files) < 1) {
        return false;
    }

    $zipfile = reset($files);
    $zipfilename = $zipfile->get_filename();
    $packagefile = false;

    $packagefile = $fs->get_file($context->id, 'mod_cmi5launch', 'package', 0, '/', $zipfilename);

    // Retrieve user settings to apply to newly created record.
    $settings = cmi5launch_settings($cmi5launch->id);
    $token = $settings['cmi5launchtenanttoken'];
    // Create the course and retrieve info for saving to DB.
    $courseresults = $createcourse($context->id, $token, $packagefile);

        // Take the results of created course and save new course id to table.
        $record->courseinfo = $courseresults;

        $returnedinfo = json_decode($courseresults, true);
        // Retrieve the lmsId of course.
        $lmsid = $returnedinfo["lmsId"];
        $record->cmi5activityid = $lmsid;

        $record->courseid = $returnedinfo["id"];

        // TEST
    $test = "";
        // Create url for sending to when requesting launch url for course.
        $playerurl = $settings['cmi5launchplayerurl'];

        // Build and save launchurl.
        $url = $playerurl . "/api/v1/" . $record->courseid . "/launch-url/";
        $record->launchurl = $url;

   
        
        $aus = ($retrieveaus($returnedinfo));
        $record->aus = (json_encode($aus));


        $fs->delete_area_files($context->id, 'mod_cmi5launch', 'content');

        $packer = get_file_packer('application/zip');
        $packagefile->extract_to_storage($packer, $context->id, 'mod_cmi5launch', 'content', 0, '/');

        // If the cmi5.xml file isn't there, don't do try to use it.
        // This is unlikely as it should have been checked when the file was validated.
        if ($manifestfile = $fs->get_file($context->id, 'mod_cmi5launch', 'content', 0, '/', 'cmi5.xml')) {
            $xmltext = $manifestfile->get_content();

            $defaultorgid = 0;
            $firstinorg = 0;

            $pattern = '/&(?!\w{2,6};)/';
            $replacement = '&amp;';
            $xmltext = preg_replace($pattern, $replacement, $xmltext);

            $objxml = new xml2Array();
            $manifest = $objxml->parse($xmltext);

            // Update activity id from the first activity in cmi5.xml, if it is found.
            // Skip without error if not. (The Moodle admin will need to enter the id manually).
            if (isset ($manifest[0]["children"][0]["children"][0]["attrs"]["ID"])) {
                $record->cmi5activityid = $manifest[0]["children"][0]["children"][0]["attrs"]["ID"];
            }

            // Update launch from the first activity in cmi5.xml, if it is found.
            // Skip if not. (The Moodle admin will need to enter the url manually).
            foreach ($manifest[0]["children"][0]["children"][0]["children"] as $property) {
                if ($property["name"] === "LAUNCH") {
                    $record->cmi5launchurl = $CFG->wwwroot . "/pluginfile.php/" . $context->id . "/mod_cmi5launch/"
                        . $manifestfile->get_filearea() . "/" . $property["tagData"];
                }
            }

            // Save reference.
            // Turn off to trigger echo.
            return $DB->update_record('cmi5launch', $record);
    

    }
}

/**
 * Check that a Zip file contains a cmi5.xml file in the right place. Used in mod_form.php.
 * Heavily based on scorm_validate_package in /mod/scorm/lib.php
 * @package  mod_cmi5launch
 * @category cmi5
 * @param stored_file $file a Zip file.
 * @return array empty if no issue is found. Array of error message otherwise
 */
function cmi5launch_validate_package($file) {
    $packer = get_file_packer('application/zip');
    $errors = array();
    $filelist = $file->list_files($packer);
    if (!is_array($filelist)) {
        $errors['packagefile'] = get_string('badarchive', 'cmi5launch');
    } else {
        $badmanifestpresent = false;
        foreach ($filelist as $info) {
            if ($info->pathname == 'cmi5.xml') {
                return array();
            } else if (strpos($info->pathname, 'cmi5.xml') !== false) {
                // This package has cmi5 xml file inside a folder of the package.
                $badmanifestpresent = true;
            }
            if (preg_match('/\.cst$/', $info->pathname)) {
                return array();
            }
        }
        if ($badmanifestpresent) {
            $errors['packagefile'] = get_string('badimsmanifestlocation', 'cmi5launch');
        } else {
            $errors['packagefile'] = get_string('nomanifest', 'cmi5launch');
        }
    }
    return $errors;
}

/**
 * Check for AUs and their satisifed status in a block. Recursive to handle nested blocks.
 *
 * @package  mod_cmi5launch
 * @category cmi5
 * @param mixed bool|array - $auinfoin - the info containing a block, au, or true/false au satisfied value
 * @param string $aulmsid - the lms id of the au we are looking for.
 * @return mixed - returns an array of aus or au satisfied value.
 */

function cmi5launch_find_au_satisfied($auinfoin, $aulmsid) {

    $ausatisfied = "";

    // Check if auinfoin is a boolean or an array.
    // It will be an array if an AU was found on recursive call.
    if (is_bool($auinfoin)) {

        // Return value to func that called it.
        return $auinfoin;

        // If it's an array it is either a block we still need to break down, or an AU we need to find satisfied value for.
    } else if (is_array($auinfoin)) {

        // Check AU's satisifeid value and display accordingly.
        foreach ($auinfoin as $key => $auinfo) {

            $ausatisfied = "false";

            // If it's a block, we need to keep breaking it down.
            if ($auinfo["type"] == "block" ) {

                // Grab its children, this is what other blocks or AU's will be nested in.
                $auchildren = $auinfo["children"];

                // Now recursively call function again.
                $ausatisfied = cmi5launch_find_au_satisfied($auchildren, $aulmsid);

                // If it's an AU, we need to check if it's the one we are looking for.
            } else if ($auinfo["type"] == "au") {

                // Search for the correct lms id and take only the AU that matches.
                if ( $auinfo["lmsId"] == $aulmsid) {
                    // If it is, retrieve the satisfied value.
                    $ausatisfied = $auinfo["satisfied"];
                } else {

                    // If no ids match we have a problem, and need to return.
                    $ausatisfied = "No ids match";
                }
            } else {
                // This shouldn't be reachable, but in case add error message.
                echo "Type from statement does not equal either block or AU.";
            }
        }
    } else {

        echo"Incorrect value passed to function cmi5launch_find_au_satisfied. Correct values are a boolean or array";

    }

    return $ausatisfied;
}

/**
 * Fetches Statements from the LRS. This is used for completion tracking -
 * we check for a statement matching certain criteria for each learner.
 *
 * @package  mod_cmi5launch
 * @category cmi5
 * @param string $url LRS endpoint URL
 * @param string $basiclogin login/key for the LRS
 * @param string $basicpass pass/secret for the LRS
 * @param string $version version of xAPI to use
 * @param string $activityid Activity Id to filter by
 * @param cmi5 Agent $agent Agent to filter by
 * @param string $verb Verb Id to filter by
 * @param string $since Since date to filter by
 * @return cmi5 LRS Response
 */
function cmi5launch_get_statements($url, $basiclogin, $basicpass, $version, $activityid, $agent, $verb, $since = null) {

    $lrs = new \cmi5\RemoteLRS($url, $version, $basiclogin, $basicpass);

    $statementsquery = array(
        "agent" => $agent,
        "verb" => new \cmi5\Verb(array("id" => trim($verb))),
        "activity" => new \cmi5\Activity(array("id" => trim($activityid))),
        "related_activities" => "false",
        "format" => "ids",
    );

    if (!is_null($since)) {
        $statementsquery["since"] = $since;
    }

    // Get all the statements from the LRS.
    $statementsresponse = $lrs->queryStatements($statementsquery);

    if ($statementsresponse->success == false) {
        return $statementsresponse;
    }

    $allthestatements = $statementsresponse->content->getStatements();
    $morestatementsurl = $statementsresponse->content->getMore();
    while (!empty($morestatementsurl)) {
        $morestmtsresponse = $lrs->moreStatements($morestatementsurl);
        if ($morestmtsresponse->success == false) {
            return $morestmtsresponse;
        }
        $morestatements = $morestmtsresponse->content->getStatements();
        $morestatementsurl = $morestmtsresponse->content->getMore();
        // Note: due to the structure of the arrays, array_merge does not work as expected.
        foreach ($morestatements as $morestatement) {
            array_push($allthestatements, $morestatement);
        }
    }

    return new \cmi5\LRSResponse(
        $statementsresponse->success,
        $allthestatements,
        $statementsresponse->httpResponse
    );
}

/**
 * Build a cmi5 Agent based on the current user
 *
 * @package  mod_cmi5launch
 * @category cmi5
 * @return cmi5 Agent $agent Agent
 */

function cmi5launch_getactor($instance) {
    global $USER, $CFG;

    $settings = cmi5launch_settings($instance);

    if ($USER->idnumber && $settings['cmi5launchcustomacchp']) {
        $agent = array(
            "name" => fullname($USER),
            "account" => array(
                "homePage" => $settings['cmi5launchcustomacchp'],
                "name" => $USER->idnumber,
            ),
            "objectType" => "Agent",
        );
    } else if ($USER->email && $settings['cmi5launchuseactoremail']) {
        $agent = array(
            "name" => fullname($USER),
            "mbox" => "mailto:".$USER->email,
            "objectType" => "Agent",
        );
    } else {
        $agent = array(
            "name" => fullname($USER),
            "account" => array(
                "homePage" => $CFG->wwwroot,
                "name" => $USER->username,
            ),
            "objectType" => "Agent",
        );
    }

    return new \cmi5\Agent($agent);
}


/**
 * Returns the LRS settings relating to a cmi5 Launch module instance
 *
 * @package  mod_cmi5launch
 * @category cmi5
 * @param string $instance The Moodle id for the cmi5 module instance.
 * @return array LRS settings to use
 */
function cmi5launch_settings($instance) {

    global $DB, $CFG, $cmi5launchsettings;

    if (!is_null($cmi5launchsettings)) {
        return $cmi5launchsettings;
    }

    $expresult = array();

    $result = $DB->get_records('config_plugins', array('plugin' => 'cmi5launch'));

    foreach ($result as $value) {
        $expresult[$value->name] = $value->value;
    }

    $expresult['cmi5launchlrsversion'] = '1.0.0';

    $cmi5launchsettings = $expresult;

    return $expresult;
}


/**
 * Should the global LRS settings be used instead of the instance specific ones?
 *
 * @package  mod_cmi5launch
 * @category cmi5
 * @param string $instance The Moodle id for the cmi5 module instance.
 * @return bool
 */
function cmi5launch_use_global_cmi5_lrs_settings($instance) {
    global $DB;
    // Determine if there is a row in cmi5launch_lrs matching the current activity id.
    $activitysettings = $DB->get_record('cmi5launch', array('id' => $instance));

    /* Removed override defaults from db
    if ($activitysettings->overridedefaults == 1) {
        return false;
    }
    */
    return true;
}

// Grade functions.

/**
 * Return grade for given user or all users.
 *
 * @global stdClass
 * @global object
 * @param int $cmi5id id of scorm
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function cmi5launch_get_user_grades($cmi5launch, $userid=0) {

    // External class and functions.
    $gradehelpers = new grade_helpers;

    $updategrades = $gradehelpers->get_cmi5launch_check_user_grades_for_updates();

    global $CFG, $DB;

    $id = required_param('id', PARAM_INT);
    $contextmodule = context_module::instance($id);

    $grades = array();

    // If userid is empty it means we want all users.
    if (empty($userid)) {

        // If the userid is empty, use get_enrolled_users for this course then update all their grades.
        $users = get_enrolled_users($contextmodule);

        // If there is a list of users then iterate through it and make grade objects with them and their updated grades.
        if ($users) {

            foreach ($users as $user) {

                $grades[$user->id] = new stdClass();
                $grades[$user->id]->id = $user->id;
                $grades[$user->id]->userid = $user->id;
                $grades[$user->id]->rawgrade = $updategrades($user);
            }
        } else {
            // Return false to avoid null values if no users.
            return false;
        }
    } else {

        // This is if we have a specific user, so we need to retrieve their information.
        $user = $DB->get_record('user', ['id' => $userid]);

        // Make grade objects with the individual user and their updated grades.
        $grades[$userid] = new stdClass();
        $grades[$userid]->id         = $userid;
        $grades[$userid]->userid     = $userid;
        $grades[$userid]->rawgrade = $updategrades($user);
    }

    return $grades;
}

/**
 * Update grades in central gradebook.
 * @category grade
 * @param object $cmi5launch - mod object
 * @param int $userid - A user ID or 0 for all users.
 * @param bool $nullifnone - If true and a single user is specified with no grade, a grade item with a null rawgrade is inserted.
 */
// This function is called automatically by moodle if it needs the users grades updated.
// It can also be called manually when you want to push a new grade to gradebook.
// This function should do whatever is needed to generate the relevant grades to push into gradebook
// then call 'myplugin_grade_item_update with the grades to write.

function cmi5launch_update_grades($cmi5launch, $userid = 0, $nullifnone = true) {

    global $CFG, $DB;

    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->libdir . '/completionlib.php');
    $id = required_param('id', PARAM_INT);

    // Reload cmi5 course instance.
    $record = $DB->get_record('cmi5launch', array('id' => $cmi5launch->id));
    $cm = get_coursemodule_from_id('cmi5launch', $id, 0, false, MUST_EXIST);
    $contextmodule = context_module::instance($cm->id);
    $users = get_enrolled_users($contextmodule);

    // Retrieve user grades and update gradebook.
    if ($userid) {

        $grades = cmi5launch_get_user_grades($cmi5launch, $userid);

        // Grades come back nested in array, with keys being the user id.
        $grades = $grades[$userid];

        cmi5launch_grade_item_update($cmi5launch, $grades);

    } else if ($userid && $nullifnone) {

        // User has no grades so assign null.
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = null;

        cmi5launch_grade_item_update($cmi5launch, $grade);

    } else {

        cmi5launch_grade_item_update($cmi5launch);
    }
}

/**
 * Update/create grade item for given cmi5 activity.
 * Calls grade_update from moodle gradelib.php
 * @category grade
 * @param object $cmi5launch - mod object
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return object grade_item
 */
// This is the only place that grade_update should be called.
// And this function should be called from cmi5launch_add_instance, cmi5launch_update_instance and cmi5launch_update_grades.
// It should look at settings in the activity $activitydbrecord to determine grading type, max and min
// values, etc. Then setup gradeinfo to pass to grade_update, it should also pass on the optional grades value.
// $gradeinfo is an array containing: ['itemname' => $activityname, 'idnumber' => $activityidnumber,
// 'gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0].
function cmi5launch_grade_item_update($cmi5launch, $grades = null) {

    global $CFG, $DB, $USER, $cmi5launchsettings;

    // Bring in grade helpers.
    $gradehelpers = new grade_helpers;

    // Functions from other classes.
    $highestgrade = $gradehelpers->get_cmi5launch_highest_grade();
    $averagegrade = $gradehelpers->get_cmi5launch_average_grade();
    $settings = cmi5launch_settings($cmi5launch->id);

    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir . '/gradelib.php');
    }
    // Reload cmi5 course instance.
    $record = $DB->get_record('cmi5launch', array('id' => $cmi5launch->id));

    // Assign course instance info to grade params.
    $params = array('itemname' => $cmi5launch->name);
    if (isset($cmi5launch->id)) {
        $params['idnumber'] = $cmi5launch->id;
    }

    // Retrieve the settings for course grading.
    $gradetype = $cmi5launchsettings["grademethod"];
    $maxgrade = $settings['maxgrade'];

    // Assign settings to grade item param.
    $params['grademax'] = $maxgrade;
    $params['grademin'] = 0;

    // If there's a max grade, set it.
    if ($maxgrade) {
        $params['gradetype'] = $gradetype;
        $params['grademax'] = $maxgrade;
        $params['grademin'] = 0;
    } else {

        $params['gradetype'] = $gradetype;
    }

    // Check if it's call to reset.
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    } else {

        // Calculate grade based on grade type, and update rawgrade (a param of grade item).
        switch($gradetype){

            // 'GRADE_AUS_CMI5' = '0'.
            // 'GRADE_HIGHEST_CMI5' = '1'.
            // 'GRADE_AVERAGE_CMI5', =  '2'.
            // 'GRADE_SUM_CMI5', = '3'.

            case 1:
                foreach ($grades as $key => $grade) {

                    $grades->rawgrade = $highestgrade($grades->rawgrade);
                }
                break;

            case 2:
                foreach ($grades as $key => $grade) {

                    $grades->rawgrade = $averagegrade($grades->rawgrade);

                }
                break;
        }
    }

    // Call grade_update to update gradebook.
    return grade_update('mod/cmi5launch', $cmi5launch->course, 'mod', 'cmi5launch', $cmi5launch->id, 0, $grades, $params);
}

    /**
     * Wrapper function to allow for testing where file_get_contents cannot be overriden.
     * Also has create_stream as this makes a resource which interfers with testing.
     * @param mixed $url - the url to be sent to 
     * @param mixed $use_include_path
     * @param mixed $context - the data to be sent
     * @param mixed $offset
     * @param mixed $maxlen
     * @return mixed $result - either a string or false.
     */
    function cmi5launch_stream_and_send($options, $url)
    {

        // The options are placed into a stream to be sent.
        $context = stream_context_create($options);

        // Sends the stream to the specified URL and stores results.
        // The false is use_include_path, which we dont want in this case, we want to go to the url.
        $result = file_get_contents($url, false, $context);

        // Return result.
        return $result;
    }
  

