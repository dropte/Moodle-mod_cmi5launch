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
 * Prints an AUs session information and allows start of new one.
 * @copyright  2023 Megan Bohland
 * @copyright  Based on work by 2013 Andrew Downes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_cmi5launch\local\session_helpers;
use mod_cmi5launch\local\customException;
use mod_cmi5launch\local\au_helpers;
use mod_cmi5launch\local\progress;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require('header.php');
require_once($CFG->dirroot . '/mod/cmi5launch/classes/local/errorover.php');

require_login($course, false, $cm);

global $cmi5launch, $USER; 

// Classes and functions.
$auhelper = new au_helpers;
$sessionhelper = new session_helpers;
$retrievesession = $sessionhelper->cmi5launch_get_retrieve_sessions_from_db();
$retrieveaus = $auhelper->get_cmi5launch_retrieve_aus_from_db();
$progress = new progress;


// Print the page header.
$PAGE->set_url('/mod/cmi5launch/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($cmi5launch->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->jquery();
$PAGE->requires->css('/mod/cmi5launch/styles.css');

$initialVisibleAUCount = 5;

// Output starts here.
echo $OUTPUT->header();

// Back Button
?>
<form action="view.php" method="get">
    <input id="id" name="id" type="hidden" value="<?php echo $id; ?>">
    <button type="submit" class="btn resume-btn">Back</button>
</form>
<?php

// Get configurable polling interval.
$pollinginterval = get_config('cmi5launch', 'polling_interval') ?? 10;
$pollinginterval = $pollinginterval * 1000; // Convert to milliseconds.

// Get AU term for button labels.
$auterm = cmi5launch_get_term('au', false);
?>

    <!-- Progress status indicator -->
    <div id="progress-status" style="display: none; margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 5px;">
        <span id="progress-spinner" class="spinner-border spinner-border-sm" role="status" style="display:none;">
            <span class="sr-only"><?php echo get_string('progress_checking', 'cmi5launch'); ?></span>
        </span>
        <span id="last-update-text"><?php echo get_string('last_updated', 'cmi5launch', get_string('just_now', 'cmi5launch')); ?></span>
    </div>

    <script>
        const initialVisibleAUCount = <?php echo $initialVisibleAUCount; ?>;
        var lastUpdateTime = Date.now();

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
        function toggleProgress(progressCellId) {
            const content = document.getElementById(progressCellId);
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
                content.previousElementSibling.querySelector('button').textContent = '<?php echo get_string('hide_details', 'cmi5launch'); ?>';
            } else {
                content.style.display = 'none';
                content.previousElementSibling.querySelector('button').textContent = '<?php echo get_string('view_details', 'cmi5launch'); ?>';
            }
        }

        // function key_test(registration) {

        //     if (event.keyCode === 13 || event.keyCode === 32) {
        //         mod_cmi5launch_launchexperience(registration);
        //     }
        // }

        // function launch_session(auid, restart) {
        //     $('#launchform_registration').val(auid);
        //     $('#launchform_restart').val(restart);
        //     $('#launchform').submit();
        // }

        function launch_session(auid, restart) {
            // Show launching message
            showNotification('<?php echo get_string('launching', 'cmi5launch'); ?>', 'info');

            // Construct the URL with parameters
            const url = `launch.php?launchform_registration=${encodeURIComponent(auid)}&restart=${encodeURIComponent(restart)}&id=<?php echo $id; ?>&n=<?php echo $n; ?>`;

            // Open in modal player instead of new tab
            openPlayerModal(url);

            // Show success notification and start checking for updates
            setTimeout(function() {
                showNotification('Activity loaded in player', 'success');
                checkProgress();
            }, 500);
        }

        var playerState = {
            isMaximized: false,
            isMinimized: false,
            lastPosition: { width: '80%', height: '80%', top: '10%', left: '10%' }
        };

        function openPlayerModal(url) {
            // Create modal if it doesn't exist
            if (!document.getElementById('cmi5-player-modal')) {
                const modal = document.createElement('div');
                modal.id = 'cmi5-player-modal';
                modal.className = 'cmi5-modal';
                modal.innerHTML = `
                    <div class="cmi5-modal-content cmi5-window" id="cmi5-window">
                        <div class="cmi5-modal-header" id="cmi5-window-header">
                            <div class="window-controls-left">
                                <span class="window-drag-icon">⋮⋮</span>
                                <h3>Activity Player</h3>
                            </div>
                            <div class="window-controls-right">
                                <button class="window-control-btn" onclick="minimizePlayer()" title="Minimize">−</button>
                                <button class="window-control-btn" onclick="toggleMaximize()" title="Maximize/Restore">□</button>
                                <button class="window-control-btn" onclick="popOutPlayer()" title="Pop Out to New Window">⧉</button>
                                <button class="cmi5-modal-close" onclick="closePlayerModal()" title="Close">&times;</button>
                            </div>
                        </div>
                        <div class="cmi5-modal-body">
                            <iframe id="cmi5-player-iframe" src="" frameborder="0" allowfullscreen></iframe>
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
                makeWindowDraggable();
                // Make window resizable
                makeWindowResizable();
            }

            // Set iframe source and show modal
            const iframe = document.getElementById('cmi5-player-iframe');
            iframe.src = url;

            const modal = document.getElementById('cmi5-player-modal');
            const windowEl = document.getElementById('cmi5-window');

            // Reset to default size if not maximized
            if (!playerState.isMaximized) {
                windowEl.style.width = '80%';
                windowEl.style.height = '80%';
                windowEl.style.top = '10%';
                windowEl.style.left = '10%';
                windowEl.style.transform = 'none';
            }

            modal.style.display = 'flex';
            windowEl.style.display = 'flex';
            playerState.isMinimized = false;
        }

        function closePlayerModal() {
            const modal = document.getElementById('cmi5-player-modal');
            const iframe = document.getElementById('cmi5-player-iframe');

            if (modal) {
                modal.style.display = 'none';
                iframe.src = ''; // Clear iframe to stop any running content

                // Refresh progress after closing
                showNotification('Checking progress...', 'info');
                checkProgress();
            }
        }

        function minimizePlayer() {
            const windowEl = document.getElementById('cmi5-window');
            if (playerState.isMinimized) {
                // Restore
                windowEl.style.display = 'flex';
                playerState.isMinimized = false;
            } else {
                // Minimize
                windowEl.style.display = 'none';
                playerState.isMinimized = true;
                showNotification('Player minimized. Click to restore.', 'info');
            }
        }

        function toggleMaximize() {
            const windowEl = document.getElementById('cmi5-window');

            if (playerState.isMaximized) {
                // Restore to previous size
                windowEl.style.width = playerState.lastPosition.width;
                windowEl.style.height = playerState.lastPosition.height;
                windowEl.style.top = playerState.lastPosition.top;
                windowEl.style.left = playerState.lastPosition.left;
                windowEl.classList.remove('maximized');
                playerState.isMaximized = false;
            } else {
                // Save current position
                playerState.lastPosition = {
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
                playerState.isMaximized = true;
            }
        }

        function popOutPlayer() {
            const iframe = document.getElementById('cmi5-player-iframe');
            const url = iframe.src;

            if (url) {
                // Open in new window
                window.open(url, 'CMI5Player', 'width=1200,height=800,menubar=no,toolbar=no,location=no,status=no');
                // Close modal
                closePlayerModal();
            }
        }

        function makeWindowDraggable() {
            const windowEl = document.getElementById('cmi5-window');
            const header = document.getElementById('cmi5-window-header');
            let isDragging = false;
            let currentX, currentY, initialX, initialY;

            header.addEventListener('mousedown', dragStart);

            function dragStart(e) {
                // Don't drag if clicking on buttons
                if (e.target.tagName === 'BUTTON') return;

                isDragging = true;
                initialX = e.clientX - (parseFloat(windowEl.style.left) || 0);
                initialY = e.clientY - (parseFloat(windowEl.style.top) || 0);

                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', dragEnd);
                header.style.cursor = 'grabbing';
            }

            function drag(e) {
                if (!isDragging) return;

                e.preventDefault();
                currentX = e.clientX - initialX;
                currentY = e.clientY - initialY;

                windowEl.style.left = currentX + 'px';
                windowEl.style.top = currentY + 'px';
            }

            function dragEnd() {
                isDragging = false;
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', dragEnd);
                header.style.cursor = 'grab';
            }
        }

        function makeWindowResizable() {
            const windowEl = document.getElementById('cmi5-window');
            const handles = document.querySelectorAll('.resize-handle');

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

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePlayerModal();
            }
        });

        // Restore minimized window when clicking backdrop
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('cmi5-player-modal');
            if (e.target === modal && playerState.isMinimized) {
                minimizePlayer(); // Restore
            }
        });

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
                    'box-shadow': '0 4px 6px rgba(0,0,0,0.2)',
                    'z-index': '10000',
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


        // // Function to run when the experience is launched.
        // function mod_cmi5launch_launchexperience(registration) {
            
        //     // Set the form paramters.
        //     $('#launchform_registration').val(registration);
        //     // Post it.
        //     $('#launchform').submit();
        // }

        $(document).ready(function() {
            // Update timestamp display every second
            setInterval(updateTimestamp, 1000);

            // Check for progress updates at configured interval
            setInterval(checkProgress, <?php echo $pollinginterval; ?>);

            const rows = document.querySelectorAll('#cmi5launch_auSessionTable tbody tr');

            if (rows.length > initialVisibleAUCount)
            {
                const toggleButton = document.getElementById('toggleRowsButton');
                // Add click event to the button to toggle rows
                toggleButton.addEventListener('click', toggleRows);

                        // Function to toggle rows and button text
                function toggleRows() {
                    const isShowingMore = toggleButton.textContent === 'Show More';
                    if (isShowingMore) {
                        // Show all rows
                        rows.forEach(row => row.classList.add('visible'));
                        toggleButton.textContent = 'Show Less';
                    } else {
                        // Show only the first 5 rows
                        rows.forEach((row, index) => {
                            if (index < initialVisibleAUCount) {
                                row.classList.add('visible');
                            } else {
                                row.classList.remove('visible');
                            }
                        });
                        toggleButton.textContent = 'Show More';
                    }
                }
            }

            // Show only the first 5 rows initially
            for (let i = 0; i < initialVisibleAUCount && i < rows.length; i++) {
                rows[i].classList.add('visible');
            }
            
        });

    </script>
<?php

// Is this all necessary? Cant the data come through on its own

// Retrieve the registration and AU ID from view.php.
$auid = required_param('AU_view', PARAM_TEXT);

// First thing check for updates.
cmi5launch_update_grades($cmi5launch, $USER->id);

// Retrieve appropriate AU from DB.
$au = $retrieveaus($auid);

// Array to hold session scores for the AU.
$sessionscores = array();

// Reload cmi5 instance.
$record = $DB->get_record('cmi5launch', array('id' => $cmi5launch->id));

// Reload user course instance.
$userscourse = $DB->get_record('cmi5launch_usercourse', ['courseid'  => $record->courseid, 'userid'  => $USER->id]);

// If it is null there have been no previous sessions.
if (!is_null($au->sessions)) {
    try {
        // Set custom error and exception handlers
        set_error_handler('mod_cmi5launch\local\custom_warningAU', E_WARNING);
        set_exception_handler('mod_cmi5launch\local\custom_warningAU');

       // Set custom error and exception handlers
       set_error_handler('mod_cmi5launch\local\custom_warningAU', E_WARNING);
       set_exception_handler('mod_cmi5launch\local\custom_warningAU');

       // Prepare table structure
       $tabledata = array();
       $table = new html_table();
       $table->id = 'cmi5launch_auSessionTable';
       $table->attributes['class'] = 'generaltable cmi5launch-table launch-table';
       $table->caption = get_string('session_history', 'cmi5launch');
       $table->head = array(
           get_string('attempt_date', 'cmi5launch'),
           get_string('attempt_progress', 'cmi5launch'),
           get_string('attempt_score', 'cmi5launch'),
       );
       $table->colclasses = array('date-column', 'progress-column', 'score-column');


       // Decode and iterate through session IDs
       $sessionids = json_decode($au->sessions);
       $sessionids = array_reverse($sessionids);
       foreach ($sessionids as $sessionid) {
           $session = $DB->get_record('cmi5launch_sessions', ['sessionid' => $sessionid]);

           if ($session) {
               $sessioninfo = [];

               // Format and add session created date
               if ($session->createdat) {
                   $createdAt = new DateTime($session->createdat, new DateTimeZone('US/Eastern'));
                   $createdAt->setTimezone(new DateTimeZone('America/New_York'));
                   $sessioninfo[] = "<span class='date-cell'>" . $createdAt->format('D d M Y H:i:s') . "</span>";
               }

               // Add formatted progress information with a toggle button
               $progressData = json_decode($session->progress);
               $progressContent = "<div class='progress-details'>";

               // Parse and format the progress data in a user-friendly way
               if (!empty($progressData)) {
                   // Map of xAPI verbs to user-friendly descriptions
                   $verbMap = array(
                       'completed' => 'Completed activity',
                       'passed' => 'Passed',
                       'failed' => 'Failed',
                       'initialized' => 'Started',
                       'launched' => 'Launched',
                       'terminated' => 'Finished session',
                       'progressed' => 'Made progress',
                       'auComplete' => 'Finished all content',
                       'slideCompleted' => 'Completed slide',
                       'SlideViewed' => 'Viewed slide',
                       'slideEvent' => 'Interacted with slide',
                       'satisfied' => 'Met requirements'
                   );

                   $importantEvents = array();
                   $detailEvents = array();

                   foreach ($progressData as $item) {
                       // Parse the progress item - typically format: "username verb URL on timestamp"
                       // Extract verb and timestamp
                       $parts = explode(' ', $item);
                       $verb = '';
                       $timestamp = '';

                       // Find the verb (usually the second word after username)
                       if (count($parts) >= 2) {
                           $verb = $parts[1];
                       }

                       // Extract timestamp (after "on")
                       $onPos = strpos($item, ' on ');
                       if ($onPos !== false) {
                           $timestamp = substr($item, $onPos + 4);
                       }

                       // Get friendly description
                       $description = isset($verbMap[strtolower($verb)]) ? $verbMap[strtolower($verb)] : ucfirst($verb);

                       // Categorize events
                       $isImportant = in_array(strtolower($verb), array('completed', 'passed', 'failed', 'aucomplete', 'satisfied'));

                       if ($isImportant) {
                           $importantEvents[] = array('desc' => $description, 'time' => $timestamp, 'verb' => strtolower($verb));
                       } else {
                           $detailEvents[] = array('desc' => $description, 'time' => $timestamp, 'verb' => strtolower($verb));
                       }
                   }

                   // Display important events first
                   if (!empty($importantEvents) || !empty($detailEvents)) {
                       $progressContent .= "<ul class='progress-list'>";

                       foreach ($importantEvents as $event) {
                           $icon = in_array($event['verb'], array('completed', 'passed', 'aucomplete', 'satisfied')) ? '✓' : '✗';
                           $class = in_array($event['verb'], array('completed', 'passed', 'aucomplete', 'satisfied')) ? 'progress-success' : 'progress-error';
                           $progressContent .= "<li class='{$class}'>{$icon} {$event['desc']}</li>";
                       }

                       // Show first 3 detail events
                       $count = 0;
                       foreach ($detailEvents as $event) {
                           if ($count++ >= 3) break;
                           $progressContent .= "<li class='progress-info'>• {$event['desc']}</li>";
                       }

                       if (count($detailEvents) > 3) {
                           $remaining = count($detailEvents) - 3;
                           $progressContent .= "<li class='text-muted' style='border: none; background: none; font-size: 12px;'>+ {$remaining} more activities</li>";
                       }

                       $progressContent .= "</ul>";
                   } else {
                       $progressContent .= "<p class='text-muted'>No detailed progress information available.</p>";
                   }
               } else {
                   $progressContent .= "<p class='text-muted'>No detailed progress information available.</p>";
               }
               $progressContent .= "</div>";

               $progressCellId = "progress-cell-" . $sessionid;

               $sessioninfo[] = "
                   <button type='button' class='btn btn-sm resume-btn' onclick='toggleProgress(\"$progressCellId\")'>" . get_string('view_details', 'cmi5launch') . "</button>
                   <div id='$progressCellId' class='progress-cell hidden-content' style='display: none;'>$progressContent</div>
               ";

               // Add score
               $sessioninfo[] = "<span class='score-cell'>" . $session->score . "</span>";
               $sessionscores[] = $session->score;

               // Add session info to table data
               $tabledata[] = $sessioninfo;
           }
       }

        // Output table
        $table->data = $tabledata;
        echo "<div class=\"cmi5launch-table-container\">";
        echo html_writer::table($table);
        echo "</div>";
        // Update AU record in the database
        $DB->update_record('cmi5launch_aus', $au);

    } catch (Exception $e) {
        restore_exception_handler();
        restore_error_handler();
        throw new customException(
            'Error loading session table on AU view page. Contact the system administrator with this message: ' .
            $e->getMessage() . '. Check that session information is present in DB and session ID is correct.', 0
        );
    } finally {
        restore_exception_handler();
        restore_error_handler();
    }
}

if ($au->sessions && count(json_decode($au->sessions)) > $initialVisibleAUCount)
{
    echo "<button id='toggleRowsButton' class='btn btn-secondary'>Show More</button>";
}

// Pass the auid and new session info to next page (launch.php).
// New attempt button.

echo "<div class='button-container' tabindex='0' onkeyup=\"key_test('" . $auid . "')\" id='cmi5launch_newattempt'>
        <button class='btn resume-btn' onclick=\"launch_session('" . $auid . "', false)\">"
        . ($au->sessions === null ? get_string('start', 'cmi5launch') . ' ' . $auterm : get_string('resume', 'cmi5launch') . ' ' . $auterm)
        . "</button>";

if ($au->sessions) {
    echo "<button class='btn restart-btn' onclick=\"launch_session('" . $auid . "', true)\">"
        . get_string('restart', 'cmi5launch') . ' ' . $auterm
        . "</button>";
}

echo "</div>";

// Add a form to be posted based on the attempt selected.
?>
    <form id="launchform" action="launch.php" method="get">
        <input id="launchform_registration" name="launchform_registration" type="hidden" value="default">
        <input id="launchform_restart" name="restart" type="hidden" value="false">
        <input id="id" name="id" type="hidden" value="<?php echo $id ?>">
        <input id="n" name="n" type="hidden" value="<?php echo $n ?>">
    </form>

<?php

echo $OUTPUT->footer();
