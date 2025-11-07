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
 * Displays the AU's of a course and their current progress (satisfied/ not satisifed etc.).
 * Also allows for launching of the AU.
 * @copyright  2023 Megan Bohland
 * @copyright  Based on work by 2013 Andrew Downes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_cmi5launch\local\customException;
use mod_cmi5launch\local\progress;
use mod_cmi5launch\local\course;
use mod_cmi5launch\local\cmi5_connectors;
use mod_cmi5launch\local\au_helpers;
use mod_cmi5launch\local\session_helpers;

require_once("../../config.php");
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require('header.php');

// Include the errorover (error override) funcs.
require_once ($CFG->dirroot . '/mod/cmi5launch/classes/local/errorover.php');

require_login($course, false, $cm);

// Bring in functions and classes.
$progress = new progress;
$aushelpers = new au_helpers;
$connectors = new cmi5_connectors;

// Functions from other classes.
$saveaus = $aushelpers->get_cmi5launch_save_aus();
$createaus = $aushelpers->get_cmi5launch_create_aus();
$getaus = $aushelpers->get_cmi5launch_retrieve_aus_from_db();
$getregistration = $connectors->cmi5launch_get_registration_with_post();
$getregistrationinfo = $connectors->cmi5launch_get_registration_with_get();

global $cmi5launch, $USER, $mod;

// MB - Not currently using events, but may in future.
/*
// Trigger module viewed event.
$event = \mod_cmi5launch\event\course_module_viewed::create(array(
    'objectid' => $cmi5launch->id,
    'context' => $context,
));

$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('cmi5launch', $cmi5launch);
$event->add_record_snapshot('course_modules', $cm);
$event->trigger();
*/

// Check if in embed mode (loaded in iframe from course page)
$embedmode = optional_param('embed', 0, PARAM_INT);

// Check if auto-launch from course page
$autolaunchauid = optional_param('launch', '', PARAM_TEXT);
$autolaunchindex = optional_param('auindex', -1, PARAM_INT); // -1 means not provided

// Print the page header.
$PAGE->set_url('/mod/cmi5launch/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($cmi5launch->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/cmi5launch/styles.css');
$PAGE->requires->jquery();

// Output starts here - skip header in embed mode
if (!$embedmode) {
    echo $OUTPUT->header();
}

// Reload cmi5 course instance.
$record = $DB->get_record('cmi5launch', array('id' => $cmi5launch->id));

// Get configurable polling interval.
$pollinginterval = get_config('cmi5launch', 'polling_interval') ?? 10;
$pollinginterval = $pollinginterval * 1000; // Convert to milliseconds.
?>

    <!-- Progress status indicator -->
    <div id="progress-status" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 5px;">
        <span id="progress-spinner" class="spinner-border spinner-border-sm" role="status" style="display:none;">
            <span class="sr-only"><?php echo get_string('progress_checking', 'cmi5launch'); ?></span>
        </span>
        <span id="last-update-text"></span>
    </div>

    <script>
        var lastUpdateTime = Date.now();

        // Initialize timestamp on page load
        $(document).ready(function() {
            updateTimestamp();
        });

        function key_test(registration) {
            //Onclick calls this
            if (event.keyCode === 13 || event.keyCode === 32) {
                mod_cmi5launch_launchexperience(registration);
            }
        }

        // Function to update the "last updated" timestamp
        function updateTimestamp() {
            var now = Date.now();
            var elapsed = Math.floor((now - lastUpdateTime) / 1000);
            var message;

            if (elapsed < 5) {
                message = '<?php echo get_string('just_now', 'cmi5launch'); ?>';
            } else if (elapsed < 60) {
                message = elapsed + ' <?php echo get_string('seconds_ago', 'cmi5launch', ''); ?>'.replace('{$a}', elapsed);
            } else {
                var minutes = Math.floor(elapsed / 60);
                message = minutes + ' <?php echo get_string('minutes_ago', 'cmi5launch', ''); ?>'.replace('{$a}', minutes);
            }

            $('#last-update-text').text('<?php echo get_string('last_updated', 'cmi5launch', ''); ?>'.replace('{$a}', message));
        }

        var playerState = {
            isMaximized: false,
            isMinimized: false,
            lastPosition: { width: '80%', height: '80%', top: '10%', left: '10%' },
            currentAUIndex: 0,
            auList: [],
            windowId: null
        };

        // Build AU list from PHP - will be populated after $auids is available
        var availableAUs = [];

        // Debug: Log the AU list to console
        console.log('Available AUs:', availableAUs);

        // Function to run when the experience is launched (on click).
        function mod_cmi5launch_launchexperience(auid, windowId) {
            console.log('Launch called with auid:', auid, 'availableAUs:', availableAUs);

            // Check if we have any AUs available
            if (!availableAUs || availableAUs.length === 0) {
                console.error('No AUs available!');
                showNotification('No activities available', 'error');
                return;
            }

            // Show launching notification
            showNotification('<?php echo get_string('launching', 'cmi5launch'); ?>', 'info');

            // Find the AU index
            const auIndex = availableAUs.findIndex(au => au.id === auid);
            console.log('Found AU at index:', auIndex);
            const finalIndex = auIndex >= 0 ? auIndex : 0;

            // Launch directly in modal player
            const url = `launch.php?launchform_registration=${encodeURIComponent(auid)}&restart=false&id=<?php echo $id; ?>&n=<?php echo $n; ?>`;
            openPlayerModal(url, finalIndex, windowId);

            // Show success notification and start checking for updates
            setTimeout(function() {
                showNotification('Activity loaded in player', 'success');
                checkProgress();
            }, 500);
        }

        function openPlayerModal(url, auIndex, windowId) {
            windowId = windowId || 'main';
            const modalId = 'cmi5-player-modal-' + windowId;

            // Create modal if it doesn't exist
            if (!document.getElementById(modalId)) {
                const modal = document.createElement('div');
                modal.id = modalId;
                modal.className = 'cmi5-modal';
                modal.dataset.windowId = windowId;
                modal.innerHTML = `
                    <div class="cmi5-modal-content cmi5-window" id="cmi5-window-${windowId}">
                        <div class="cmi5-modal-header" id="cmi5-window-header-${windowId}">
                            <div class="window-controls-left">
                                <span class="window-drag-icon">⋮⋮</span>
                                <div class="activity-nav-controls">
                                    <button class="nav-btn" onclick="navigateAU('${windowId}', -1)" title="Previous Activity" id="nav-prev-${windowId}">‹</button>
                                    <select class="activity-dropdown" id="activity-dropdown-${windowId}" onchange="jumpToAU('${windowId}', this.value)" title="Select Activity">
                                        <option value="">Select Activity...</option>
                                    </select>
                                    <button class="nav-btn" onclick="navigateAU('${windowId}', 1)" title="Next Activity" id="nav-next-${windowId}">›</button>
                                </div>
                                <h3 id="activity-title-${windowId}">Activity Player</h3>
                            </div>
                            <div class="window-controls-right">
                                <button class="window-control-btn" onclick="openNewWindow()" title="Open New Window">+</button>
                                <button class="window-control-btn" onclick="minimizePlayer('${windowId}')" title="Minimize">−</button>
                                <button class="window-control-btn" onclick="toggleMaximize('${windowId}')" title="Maximize/Restore">□</button>
                                <button class="window-control-btn" onclick="popOutPlayer('${windowId}')" title="Pop Out to New Window">⧉</button>
                                <button class="cmi5-modal-close" onclick="closePlayerModal('${windowId}')" title="Close">&times;</button>
                            </div>
                        </div>
                        <div class="cmi5-modal-body">
                            <div class="loading-overlay" id="loading-overlay-${windowId}" style="display: none;">
                                <div class="loading-spinner"></div>
                                <div class="loading-text">Loading activity...</div>
                            </div>
                            <iframe id="cmi5-player-iframe-${windowId}" src="" frameborder="0" allowfullscreen></iframe>
                        </div>
                        <div class="resize-handle resize-handle-br"></div>
                        <div class="resize-handle resize-handle-bl"></div>
                        <div class="resize-handle resize-handle-tr"></div>
                        <div class="resize-handle resize-handle-tl"></div>
                        <div class="resize-handle resize-handle-r"></div>
                        <div class="resize-handle resize-handle-l"></div>
                        <div class="resize-handle resize-handle-t"></div>
                        <div class="resize-handle resize-handle-b"></div>
                    </div>
                `;
                document.body.appendChild(modal);

                // Make window draggable
                makeWindowDraggable(windowId);
                // Make window resizable
                makeWindowResizable(windowId);

                // Store window state
                if (!window.playerWindows) window.playerWindows = {};
                window.playerWindows[windowId] = {
                    isMaximized: false,
                    isMinimized: false,
                    currentAUIndex: auIndex,
                    lastPosition: { width: '80%', height: '80%', top: '10%', left: '10%' }
                };
            }

            // Update current AU index for this window
            window.playerWindows[windowId].currentAUIndex = auIndex;

            // Set iframe source and show modal
            const iframe = document.getElementById('cmi5-player-iframe-' + windowId);
            const loadingOverlay = document.getElementById('loading-overlay-' + windowId);

            // Show loading spinner
            if (loadingOverlay) loadingOverlay.style.display = 'flex';

            // Hide spinner when iframe loads
            iframe.onload = function() {
                if (loadingOverlay) loadingOverlay.style.display = 'none';
            };

            iframe.src = url;

            // Update navigation controls
            updateNavigationControls(windowId);

            const modal = document.getElementById(modalId);
            const windowEl = document.getElementById('cmi5-window-' + windowId);

            // Reset to default size if not maximized, offset each new window
            if (!window.playerWindows[windowId].isMaximized) {
                const offset = Object.keys(window.playerWindows).length * 30;
                windowEl.style.width = '80%';
                windowEl.style.height = '80%';
                windowEl.style.top = (10 + offset) + 'px';
                windowEl.style.left = (10 + offset) + 'px';
                windowEl.style.transform = 'none';
            }

            modal.style.display = 'flex';
            windowEl.style.display = 'flex';
            window.playerWindows[windowId].isMinimized = false;
        }

        function navigateAU(windowId, direction) {
            if (!window.playerWindows || !window.playerWindows[windowId]) return;
            if (!availableAUs || availableAUs.length === 0) return;

            const state = window.playerWindows[windowId];
            const newIndex = state.currentAUIndex + direction;

            if (newIndex >= 0 && newIndex < availableAUs.length) {
                const au = availableAUs[newIndex];
                const url = `launch.php?launchform_registration=${encodeURIComponent(au.id)}&restart=false&id=<?php echo $id; ?>&n=<?php echo $n; ?>`;

                // Show loading spinner
                const loadingOverlay = document.getElementById('loading-overlay-' + windowId);
                if (loadingOverlay) loadingOverlay.style.display = 'flex';

                // Update iframe
                const iframe = document.getElementById('cmi5-player-iframe-' + windowId);
                if (iframe) {
                    // Hide spinner when loaded
                    iframe.onload = function() {
                        if (loadingOverlay) loadingOverlay.style.display = 'none';
                    };
                    iframe.src = url;
                }

                // Update state
                state.currentAUIndex = newIndex;

                // Update controls
                updateNavigationControls(windowId);

                // Show notification
                showNotification(`Loading ${au.title}`, 'info');

                // Refresh progress
                setTimeout(() => checkProgress(), 1000);
            }
        }

        function jumpToAU(windowId, targetIndex) {
            if (!targetIndex || targetIndex === '') return;

            targetIndex = parseInt(targetIndex);
            if (isNaN(targetIndex)) return;

            if (!window.playerWindows || !window.playerWindows[windowId]) return;
            if (!availableAUs || availableAUs.length === 0) return;

            if (targetIndex >= 0 && targetIndex < availableAUs.length) {
                const au = availableAUs[targetIndex];
                const url = `launch.php?launchform_registration=${encodeURIComponent(au.id)}&restart=false&id=<?php echo $id; ?>&n=<?php echo $n; ?>`;

                // Show loading spinner
                const loadingOverlay = document.getElementById('loading-overlay-' + windowId);
                if (loadingOverlay) loadingOverlay.style.display = 'flex';

                // Update iframe
                const iframe = document.getElementById('cmi5-player-iframe-' + windowId);
                if (iframe) {
                    // Hide spinner when loaded
                    iframe.onload = function() {
                        if (loadingOverlay) loadingOverlay.style.display = 'none';
                    };
                    iframe.src = url;
                }

                // Update state
                window.playerWindows[windowId].currentAUIndex = targetIndex;

                // Update controls
                updateNavigationControls(windowId);

                // Show notification
                showNotification(`Loading ${au.title}`, 'info');

                // Refresh progress
                setTimeout(() => checkProgress(), 1000);
            }
        }

        function updateNavigationControls(windowId) {
            if (!window.playerWindows || !window.playerWindows[windowId]) return;
            if (!availableAUs || availableAUs.length === 0) return;

            const state = window.playerWindows[windowId];
            const currentAU = availableAUs[state.currentAUIndex];

            if (!currentAU) return;

            // Update title
            const titleEl = document.getElementById('activity-title-' + windowId);
            if (titleEl) titleEl.textContent = currentAU.title;

            // Populate and update dropdown
            const dropdown = document.getElementById('activity-dropdown-' + windowId);
            if (dropdown) {
                // Only populate if empty (first time)
                if (dropdown.options.length <= 1) {
                    availableAUs.forEach((au, index) => {
                        const option = document.createElement('option');
                        option.value = index;
                        option.textContent = `${index + 1}. ${au.title}`;
                        dropdown.appendChild(option);
                    });
                }
                // Set current selection
                dropdown.value = state.currentAUIndex;
            }

            // Enable/disable navigation buttons
            const prevBtn = document.getElementById('nav-prev-' + windowId);
            const nextBtn = document.getElementById('nav-next-' + windowId);

            if (prevBtn) {
                prevBtn.disabled = state.currentAUIndex === 0;
                prevBtn.style.opacity = state.currentAUIndex === 0 ? '0.3' : '1';
            }

            if (nextBtn) {
                nextBtn.disabled = state.currentAUIndex === availableAUs.length - 1;
                nextBtn.style.opacity = state.currentAUIndex === availableAUs.length - 1 ? '0.3' : '1';
            }
        }

        function openNewWindow() {
            if (!availableAUs || availableAUs.length === 0) {
                showNotification('No activities available', 'error');
                return;
            }
            const newWindowId = 'window-' + Date.now();
            // Launch the first AU in the new window
            mod_cmi5launch_launchexperience(availableAUs[0].id, newWindowId);
        }

        function closePlayerModal(windowId) {
            windowId = windowId || 'main';
            const modal = document.getElementById('cmi5-player-modal-' + windowId);
            const iframe = document.getElementById('cmi5-player-iframe-' + windowId);

            if (modal) {
                modal.style.display = 'none';
                if (iframe) iframe.src = ''; // Clear iframe to stop any running content

                // Remove from window state
                if (window.playerWindows) {
                    delete window.playerWindows[windowId];
                }

                // Refresh progress after closing
                showNotification('Checking progress...', 'info');
                checkProgress();
            }
        }

        function minimizePlayer(windowId) {
            windowId = windowId || 'main';
            const windowEl = document.getElementById('cmi5-window-' + windowId);
            const state = window.playerWindows[windowId];

            if (state.isMinimized) {
                // Restore
                windowEl.style.display = 'flex';
                state.isMinimized = false;
            } else {
                // Minimize
                windowEl.style.display = 'none';
                state.isMinimized = true;
                showNotification('Player minimized. Click to restore.', 'info');
            }
        }

        function toggleMaximize(windowId) {
            windowId = windowId || 'main';
            const windowEl = document.getElementById('cmi5-window-' + windowId);
            const state = window.playerWindows[windowId];

            if (state.isMaximized) {
                // Restore to previous size
                windowEl.style.width = state.lastPosition.width;
                windowEl.style.height = state.lastPosition.height;
                windowEl.style.top = state.lastPosition.top;
                windowEl.style.left = state.lastPosition.left;
                windowEl.classList.remove('maximized');
                state.isMaximized = false;
            } else {
                // Save current position
                state.lastPosition = {
                    width: windowEl.style.width,
                    height: windowEl.style.height,
                    top: windowEl.style.top,
                    left: windowEl.style.left
                };
                // Maximize
                windowEl.style.width = '100%';
                windowEl.style.height = '100%';
                windowEl.style.top = '0';
                windowEl.style.left = '0';
                windowEl.classList.add('maximized');
                state.isMaximized = true;
            }
        }

        function popOutPlayer(windowId) {
            windowId = windowId || 'main';
            const iframe = document.getElementById('cmi5-player-iframe-' + windowId);
            const url = iframe ? iframe.src : '';

            if (url) {
                // Open in new window
                window.open(url, 'CMI5Player', 'width=1200,height=800,menubar=no,toolbar=no,location=no,status=no');
                // Close modal
                closePlayerModal(windowId);
            }
        }

        function makeWindowDraggable(windowId) {
            windowId = windowId || 'main';
            const windowEl = document.getElementById('cmi5-window-' + windowId);
            const header = document.getElementById('cmi5-window-header-' + windowId);
            let isDragging = false;
            let offsetX, offsetY;

            header.addEventListener('mousedown', dragStart);

            function dragStart(e) {
                // Don't drag if clicking on buttons or other interactive elements
                if (e.target.tagName === 'BUTTON' || e.target.tagName === 'SPAN') return;

                isDragging = true;

                // Get current position using getBoundingClientRect for accurate pixel values
                const rect = windowEl.getBoundingClientRect();
                offsetX = e.clientX - rect.left;
                offsetY = e.clientY - rect.top;

                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', dragEnd);
                header.style.cursor = 'grabbing';
                e.preventDefault(); // Prevent text selection while dragging
            }

            function drag(e) {
                if (!isDragging) return;

                e.preventDefault();

                // Calculate new position
                const newX = e.clientX - offsetX;
                const newY = e.clientY - offsetY;

                windowEl.style.left = newX + 'px';
                windowEl.style.top = newY + 'px';
            }

            function dragEnd(e) {
                if (!isDragging) return;

                isDragging = false;
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', dragEnd);
                header.style.cursor = 'grab';
            }
        }

        function makeWindowResizable(windowId) {
            windowId = windowId || 'main';
            const windowEl = document.getElementById('cmi5-window-' + windowId);
            const handles = windowEl.querySelectorAll('.resize-handle');

            handles.forEach(handle => {
                handle.addEventListener('mousedown', initResize);
            });

            let isResizing = false;
            let currentHandle = null;
            let startX, startY, startWidth, startHeight, startLeft, startTop;

            function initResize(e) {
                isResizing = true;
                currentHandle = e.target;
                startX = e.clientX;
                startY = e.clientY;

                const rect = windowEl.getBoundingClientRect();
                startWidth = rect.width;
                startHeight = rect.height;
                startLeft = rect.left;
                startTop = rect.top;

                document.addEventListener('mousemove', resize);
                document.addEventListener('mouseup', stopResize);
                e.preventDefault();
            }

            function resize(e) {
                if (!isResizing) return;

                const dx = e.clientX - startX;
                const dy = e.clientY - startY;

                if (currentHandle.classList.contains('resize-handle-r')) {
                    windowEl.style.width = (startWidth + dx) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-l')) {
                    windowEl.style.width = (startWidth - dx) + 'px';
                    windowEl.style.left = (startLeft + dx) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-b')) {
                    windowEl.style.height = (startHeight + dy) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-t')) {
                    windowEl.style.height = (startHeight - dy) + 'px';
                    windowEl.style.top = (startTop + dy) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-br')) {
                    windowEl.style.width = (startWidth + dx) + 'px';
                    windowEl.style.height = (startHeight + dy) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-bl')) {
                    windowEl.style.width = (startWidth - dx) + 'px';
                    windowEl.style.height = (startHeight + dy) + 'px';
                    windowEl.style.left = (startLeft + dx) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-tr')) {
                    windowEl.style.width = (startWidth + dx) + 'px';
                    windowEl.style.height = (startHeight - dy) + 'px';
                    windowEl.style.top = (startTop + dy) + 'px';
                } else if (currentHandle.classList.contains('resize-handle-tl')) {
                    windowEl.style.width = (startWidth - dx) + 'px';
                    windowEl.style.height = (startHeight - dy) + 'px';
                    windowEl.style.left = (startLeft + dx) + 'px';
                    windowEl.style.top = (startTop + dy) + 'px';
                }
            }

            function stopResize() {
                isResizing = false;
                document.removeEventListener('mousemove', resize);
                document.removeEventListener('mouseup', stopResize);
            }
        }

        function showNotification(message, type) {
            type = type || 'info';
            var bgColor = type === 'success' ? '#4CAF50' : (type === 'info' ? '#2196F3' : '#ff9800');

            var toast = $('<div></div>')
                .addClass('cmi5-toast')
                .text(message)
                .css({
                    'position': 'fixed',
                    'top': '20px',
                    'right': '20px',
                    'padding': '15px 20px',
                    'background': bgColor,
                    'color': 'white',
                    'border-radius': '5px',
                    'box-shadow': '0 6px 16px rgba(0,0,0,0.4)',
                    'z-index': '99999',
                    'font-weight': 'bold',
                    'font-size': '14px',
                    'animation': 'slideIn 0.3s ease'
                });

            $('body').append(toast);

            // Auto-remove after 3 seconds
            setTimeout(function() {
                toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }

        function checkProgress() {
            // Show spinner
            $('#progress-status').show();
            $('#progress-spinner').show();

            $('#cmi5launch_completioncheck').load('completion_check.php?id=<?php echo $id ?>&n=<?php echo $n ?>', function(response, status, xhr) {
                // Always hide spinner, regardless of success or failure
                $('#progress-spinner').hide();

                if (status === "success") {
                    lastUpdateTime = Date.now();
                    updateTimestamp();
                } else if (status === "error") {
                    console.log("Progress check failed: " + xhr.status + " " + xhr.statusText);
                    // Still update timestamp to show we tried
                    lastUpdateTime = Date.now();
                    updateTimestamp();
                }
            });
        }

        $(document).ready(function() {
            // Update timestamp display every second
            setInterval(updateTimestamp, 1000);

            // Check for progress updates at configured interval
            setInterval(checkProgress, <?php echo $pollinginterval; ?>);

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Find the topmost visible window
                let topmostWindow = null;
                if (window.playerWindows) {
                    for (let wid in window.playerWindows) {
                        const modal = document.getElementById('cmi5-player-modal-' + wid);
                        if (modal && modal.style.display === 'flex' && !window.playerWindows[wid].isMinimized) {
                            topmostWindow = wid;
                        }
                    }
                }

                if (topmostWindow) {
                    // ESC - Close window
                    if (e.key === 'Escape') {
                        closePlayerModal(topmostWindow);
                    }
                    // Arrow Left - Previous activity
                    else if (e.key === 'ArrowLeft' && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();
                        navigateAU(topmostWindow, -1);
                    }
                    // Arrow Right - Next activity
                    else if (e.key === 'ArrowRight' && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();
                        navigateAU(topmostWindow, 1);
                    }
                }
            });

            // Restore minimized window when clicking backdrop
            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('cmi5-modal')) {
                    const windowId = e.target.dataset.windowId;
                    if (window.playerWindows && window.playerWindows[windowId] && window.playerWindows[windowId].isMinimized) {
                        minimizePlayer(windowId); // Restore
                    }
                }
            });
        });
    </script>
<?php

// Check for updates.
cmi5launch_update_grades($cmi5launch, $USER->id);

// Check if a course record exists for this user yet.
$exists = $DB->record_exists('cmi5launch_usercourse', ['courseid'  => $record->courseid, 'userid'  => $USER->id]);
  
// Set error and exception handler to catch and override the default PHP error messages, to make messages more user friendly.
set_error_handler('mod_cmi5launch\local\custom_warningview', E_WARNING);
set_exception_handler('mod_cmi5launch\local\custom_warningview');

try {
    // If it does not exist, create it.
    if ($exists == false) {

        // Make a new course record.
        $userscourse = new course($record);

        // Retreive user id.
        $userscourse->userid = $USER->id;

        // Build url to pass as returnUrl.
        $returnurl = $CFG->wwwroot . '/mod/cmi5launch/view.php' . '?id=' . $cm->id;
        $userscourse->returnurl = $returnurl;

        // Assign new record a registration id.
        $registrationid = $getregistration($userscourse->courseid, $cmi5launch->id);
        $userscourse->registrationid = $registrationid;

        // Retreive the Moodle course id

        $userscourse->moodlecourseid = $cm->instance;

        // Retrieve AU ids for this user/course.
        $aus = json_decode($record->aus);

        // We should not even be able to et here if these are false on record
        $auids = $saveaus($createaus($aus));
        $userscourse->aus = (json_encode($auids));

        // Save new record to DB.
        $newid = $DB->insert_record('cmi5launch_usercourse', $userscourse);

        // Now assign id created by DB.
        $userscourse->id = $newid;

    } else { // Record exists.

        // We have a record, so we need to retrieve it.
        $userscourse = $DB->get_record('cmi5launch_usercourse', ['courseid' => $record->courseid, 'userid' => $USER->id]);

        // Retrieve registration id.
        $registrationid = $userscourse->registrationid;

        // We need to verify if there is a registration id. Sometimes errors with player can cause a null id, in that case we want to
        // retrieve a new one.
        if ($registrationid == null) {
            // Retrieve registration id.
            $registrationid = $getregistration($record->courseid, $cmi5launch->id);
            // Update course record.
            $userscourse->registrationid = $registrationid;
            // Update DB.
            $DB->update_record("cmi5launch_usercourse", $userscourse);
        }
        // Retrieve AU ids.
        $auids = (json_decode($userscourse->aus));
//        $auids = (json_decode($userscourse));

    }
    
} catch (Exception $e) {

    // Restore default hadlers.
    restore_exception_handler();
    restore_error_handler();

    // If there is an error, display it.
    throw new customException('Creating or retrieving user course record. Contact your system administrator with error: ' . $e->getMessage(), 0);
}

// Build JavaScript array of available AUs now that $auids is populated
$auListJS = array();
if (isset($auids) && is_array($auids)) {
    foreach ($auids as $index => $auid) {
        try {
            $au = $getaus($auid);
            if ($au && isset($au->title)) {
                // Use proper JSON encoding for safety
                $auListJS[] = array(
                    'id' => $auid,
                    'title' => $au->title,
                    'index' => $index
                );
            }
        } catch (Exception $e) {
            // Skip AUs that can't be loaded
            continue;
        }
    }
}

// Output JavaScript to populate the availableAUs array
echo '<script>';
echo 'availableAUs = ' . json_encode($auListJS) . ';';
echo 'console.log("AUs loaded:", availableAUs);';

// Auto-launch if coming from course page
if (!empty($autolaunchauid)) {
    echo '
    // Auto-launch activity from course page by AU ID
    window.addEventListener("DOMContentLoaded", function() {
        console.log("Auto-launching AU:", "' . $autolaunchauid . '");
        setTimeout(function() {
            mod_cmi5launch_launchexperience("' . $autolaunchauid . '", "main");
        }, 500);
    });
    ';
} else if ($autolaunchindex >= 0) {
    // Auto-launch by index (0-based)
    $actualIndex = $autolaunchindex;
    echo '
    // Auto-launch activity from course page by index
    window.addEventListener("DOMContentLoaded", function() {
        console.log("Auto-launching AU at index:", ' . $actualIndex . ');
        if (availableAUs && availableAUs.length > ' . $actualIndex . ') {
            var auToLaunch = availableAUs[' . $actualIndex . '];
            console.log("Found AU to launch:", auToLaunch);
            setTimeout(function() {
                mod_cmi5launch_launchexperience(auToLaunch, "main");
            }, 500);
        } else {
            console.error("No AU found at index ' . $actualIndex . '");
        }
    });
    ';
}

echo '</script>';

// Array to hold info for table population.
$tabledata = array();

// We need id to get progress.
$cmid = $cmi5launch->id;

// Only show activity table in normal mode, not embed mode
if (!$embedmode) {
// Create table to display on page.
$table = new html_table();
$table->id = 'cmi5launch_autable';
$table->caption = cmi5launch_get_term('au', true);  // "Activities" or configured term
$table->attributes['class'] = 'generaltable cmi5launch-table au-table';
$table->head = array(
    get_string('name'),  // Generic "Name"
    cmi5launch_get_term('satisfied', false) . ' Status',  // "Completed Status"
    get_string('cmi5launchviewgradeheader', 'cmi5launch'),  // "Grade"
    get_string('cmi5launchviewregistrationheader', 'cmi5launch'),  // "Sessions"
);

// Array to hold Au scores.
$auscores = array();
try {
    // Query CMI5 player for updated registration info.
    $registrationinfofromcmi5 = json_decode($getregistrationinfo($registrationid, $cmi5launch->id), true);

    // Take only info about AUs out of registrationinfofromcmi5.
    $ausfromcmi5 = array_chunk($registrationinfofromcmi5["metadata"]["moveOn"]["children"], 1, true);

    // Cycle through AU IDs making AU objects and checking progress.
    foreach ($auids as $key => $auid) {

        // Array to hold scores for AU.
        $sessionscores = array();
        $au = $getaus($auid);

        // Verify object is an au object.
        if (!is_a($au, 'mod_cmi5launch\local\au', false)) {

            $reason = "Excepted AU, found ";
            var_dump($au);
            throw new moodle_exception($reason, 'cmi5launch', '', $warnings[$reason]);
        }

        // Retrieve AU's lmsID.
        $aulmsid = $au->lmsid;

        // To hold if the au is satisfied.
        $ausatisfied = "";

        // Cycle through AUs (or blocks) in registration info from player, we are looking for the one
        // that matches our AU lmsID.
        foreach ($ausfromcmi5 as $key => $value) {

            // Check for the AUs satisfied status. Compare with lmsId to find status for that instance.
            $ausatisfied = cmi5launch_find_au_satisfied($value, $aulmsid);
            // If au satisfied is ever true then we found it, once satisified it
            // doesn't matter if others have failed or were also satisified.
            if ($ausatisfied == "true") {
                break;

                // This elseif was built as a failsafe. Very rarely there may be an instance where the player issues
                // a duplicate lms id or registration number. For example, this can happen if the server crashes while a course
                // is being made or updated.
                // However, under normal circumstances, the AU LMSID should always match at least one of the AUs returned by player.
            } else if ($ausatisfied = "No ids match") {

                // If there are sessions for this AU.
                if ($au->sessions != null) {

                    // Retrieve session ids for this AU from DB.
                    $sessions = json_decode($au->sessions, true);
                    $sessionhelpers = new session_helpers;
                    $getsessioninfo = $sessionhelpers->cmi5launch_get_retrieve_sessions_from_db();

                    // Retrieve what this AU needs to moveon. We will search through the session data to see if it is fulfilled.
                    $aumoveon = $au->moveon;

                    // Hold if completed or passed is found.
                    $completedfound = false;
                    $passedfound = false;

                    // Cycle through them looking to see if any were passed and/or completed.
                    foreach ($sessions as $key => $value) {

                        // Get the session from DB with session id.
                        $ausession = $DB->get_record('cmi5launch_sessions', array('sessionid' => $value));

                        // Skip if session doesn't exist (may have been reset)
                        if (!$ausession) {
                            continue;
                        }

                        if ($ausession->iscompleted == "1") {
                            $completedfound = true;
                        }
                        if ($ausession->ispassed == "1") {
                            $passedfound = true;
                        }

                        // See if the pass and completed fulfill move on value for AU.
                        switch ($aumoveon) {
                            case "Completed":
                                if ($completedfound == true) {
                                    $ausatisfied = "true";
                                }
                                ;
                                break;
                            case "Passed":
                                if ($passedfound == true) {
                                    $ausatisfied = "true";
                                }
                                ;
                                break;
                            case "CompletedOrPassed":
                                if ($completedfound == true || $passedfound == true) {
                                    $ausatisfied = "true";
                                }
                                ;
                                break;
                            case "CompletedAndPassed":
                                if ($completedfound == true && $passedfound == true) {
                                    $ausatisfied = "true";
                                }
                                ;
                                break;
                        }

                        // If even one AU satisifed is met, then the AU is satisfied overall. Later or earlier sessions don't matter.
                        if ($ausatisfied == "true") {
                            break;
                        }
                    }
                }
            }
        }
        // If the 'sessions' in this AU are null we know this hasn't even been attempted.
        if ($au->sessions == null) {

            $austatus = get_string('not_attempted', 'cmi5launch');

        } else {
            // Check if any sessions actually exist in DB (they may have been deleted after reset)
            $sessions = json_decode($au->sessions, true);
            $hassessions = false;
            if ($sessions && is_array($sessions)) {
                foreach ($sessions as $sessionid) {
                    if ($DB->record_exists('cmi5launch_sessions', array('sessionid' => $sessionid))) {
                        $hassessions = true;
                        break;
                    }
                }
            }

            // If no valid sessions exist, treat as not attempted
            if (!$hassessions) {
                $austatus = get_string('not_attempted', 'cmi5launch');
                // Continue to next AU
                $auinfo = array();
                $auinfo[] = $au->title;
                $auinfo[] = $austatus;
                $auinfo[] = " ";
                $auindex = $au->auindex;
                $auinfo[] = $auindex;
                $table->data[] = $auinfo;
                continue;
            }

            // Retrieve AUs moveon specification.
            $aumoveon = $au->moveon;

            // If it's been attempted but no moveon value.
            if ($aumoveon == "NotApplicable") {
                $austatus = get_string('viewed', 'core');
            } else {
                // IF it DOES have a moveon value.
                // If satisifed is returned true.
                if ($ausatisfied == "true") {

                    $austatus = cmi5launch_get_term('satisfied');  // "Completed" or configured
                    // Also update AU.
                    $au->satisfied = "true";
                } else {

                    // If not, its in progress.
                    $austatus = get_string('in_progress', 'cmi5launch');
                    // Also update AU.
                    $au->satisfied = "false";
                }
            }
        }

        // Create array of info to place in table.
        $auinfo = array();

        // Assign au name, progress, and index.
        $auinfo[] = $au->title;
        $auinfo[] = ($austatus);

        $grade = 0;

        // Retrieve grade.
        if (!$au->grade == 0 || $au->grade == null) {

            $grade = $au->grade;

            $auinfo[] = ($grade);
        } else if ($au->grade == 0) {

            // Display the 0.
            $auinfo[] = ($grade);
            // TODO - This needs to be more interactive, course creators need to be able top control
            // whether a satisified AU is considered a 0 or not, but for now, if satisfied, don't show 0.
            if ($austatus == "Satisfied") {
                $auinfo[2] = " ";
            }

        } else {
            // There is no grade, leave blank.
            $auinfo[] = (" ");
        }

        $auindex = $au->auindex;

        // AU id for next page (to be loaded).
        //  $infofornextpage = $auid;

        // Assign au link to auviews with styled button
        $launchButtonText = ($austatus == "Satisfied") ? "Review" : (($austatus == "In Progress") ? "Continue" : "Start");
        $launchIcon = ($austatus == "Satisfied") ? "✓" : (($austatus == "In Progress") ? "▶" : "▶");
        $buttonClass = ($austatus == "Satisfied") ? "launch-btn-completed" : (($austatus == "In Progress") ? "launch-btn-progress" : "launch-btn-new");

        $auinfo[] = "<button class=\"btn launch-btn {$buttonClass}\" tabindex=\"0\" id='cmi5relaunch_attempt'
            onkeyup=\"key_test('" . $auid . "')\"
            onclick=\"mod_cmi5launch_launchexperience('" . $auid . "')\"
            title='Click to launch this activity'>
            <span class='launch-icon'>{$launchIcon}</span>
            <span class='launch-text'>{$launchButtonText}</span>
        </button>";

        // Add to be fed to table.
        $tabledata[] = $auinfo;

        // Update AU scores.
        $auscores[$au->lmsid] = array($au->title => $au->scores);

        // Update the AU in DB.
        $DB->update_record("cmi5launch_aus", $au);
    }

    // Add our newly updated auscores array to the course record.
    $userscourse->ausgrades = json_encode($auscores);
} catch (Exception $e) {

    // Restore default hadlers.
    restore_exception_handler();
    restore_error_handler();

    // If there is an error, display it.
    throw new customException('retrieving and displaying AU satisfied status and grade. Contact your system administrator with error: ' . $e->getMessage(), 0);
}
// Lastly, update our course table.
$updated = $DB->update_record("cmi5launch_usercourse", $userscourse);

// This feeds the table.
$table->data = $tabledata;

// Restore default hadlers.
restore_exception_handler();
restore_error_handler();

echo html_writer::table($table);

// Add a form to be posted based on the attempt selected.
?>
    <form id="launchform" action="AUview.php" method="get">
        <input id="AU_view" name="AU_view" type="hidden" value="default">
        <input id="AU_view_id" name="AU_view_id" type="hidden" value="default">
        <input id="id" name="id" type="hidden" value="<?php echo $id ?>">
        <input id="n" name="n" type="hidden" value="<?php echo $n ?>">
    </form>
<?php
} // End if (!$embedmode) - close the table/form section

// Output footer only in normal mode
if (!$embedmode) {
    echo $OUTPUT->footer();
}
