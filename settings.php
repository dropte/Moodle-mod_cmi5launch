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

/* For global cmi5 settings  */

/**
 * Cmi5 settings. Ability to update and change 
 *
 * This code fragment is called by moodle_needs_upgrading() and
 * /admin/index.php
 *
 * @package mod_cmi5launch
 * @copyright  2023 Megan Bohland
 * @copyright  Based on work by 2013 Andrew Downes as well as some code from the scorm module (Source code was uncredited).
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

?>

<script>

    function totokenpage(){

        // Post it.
        document.getElementById('settingformtoken').submit();
    }

    function tosetup(){

    // Post it.
    document.getElementById('setupform').submit();
    }

</script>
<?php


// maybe add if ($hassiteconfig?) Can regulare users access this? TODO -MB
if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/mod/cmi5launch/locallib.php');
    require_once($CFG->dirroot . '/mod/cmi5launch/settingslib.php');

    // MB
    // From scorm grading stuff.
    $yesno = array(0 => get_string('no'),
                   1 => get_string('yes'));
    
    // Default display settings.
    $settings->add(new admin_setting_heading('cmi5launch/cmi5launchlrsfieldset',
        get_string('cmi5launchlrsfieldset', 'cmi5launch'),
        get_string('cmi5launchlrsfieldset_help', 'cmi5launch')));

        // LRS settings 
    $settings->add(new admin_setting_heading('cmi5launch/cmi5lrssettings', get_string('cmi5lrssettingsheader', 'cmi5launch'), ''));

    $settings->add(new admin_setting_configtext_mod_cmi5launch('cmi5launch/cmi5launchlrsendpoint',
        get_string('cmi5launchlrsendpoint', 'cmi5launch'),
        get_string('cmi5launchlrsendpoint_help', 'cmi5launch'),
        get_string('cmi5launchlrsendpoint_default', 'cmi5launch'), PARAM_URL));

    $options = array(
        1 => get_string('cmi5launchlrsauthentication_option_0', 'cmi5launch'),
        2 => get_string('cmi5launchlrsauthentication_option_1', 'cmi5launch'),
        0 => get_string('cmi5launchlrsauthentication_option_2', 'cmi5launch'),
    );
    // Note the numbers above are deliberately mis-ordered for reasons of backwards compatibility with older settings.

    $setting = new admin_setting_configselect('cmi5launch/cmi5launchlrsauthentication',
        get_string('cmi5launchlrsauthentication', 'cmi5launch'),
        get_string('cmi5launchlrsauthentication_help', 'cmi5launch').'<br/>'
        .get_string('cmi5launchlrsauthentication_watershedhelp', 'cmi5launch')
        , 1, $options);
    $settings->add($setting);

    $setting = new admin_setting_configtext('cmi5launch/cmi5launchlrslogin',
        get_string('cmi5launchlrslogin', 'cmi5launch'),
        get_string('cmi5launchlrslogin_help', 'cmi5launch'),
        get_string('cmi5launchlrslogin_default', 'cmi5launch'));
    $settings->add($setting);

    $setting = new admin_setting_configtext('cmi5launch/cmi5launchlrspass',
        get_string('cmi5launchlrspass', 'cmi5launch'),
        get_string('cmi5launchlrspass_help', 'cmi5launch'),
        get_string('cmi5launchlrspass_default', 'cmi5launch'));
    $settings->add($setting);

    $settings->add(new admin_setting_configtext('cmi5launch/cmi5launchlrsduration',
        get_string('cmi5launchlrsduration', 'cmi5launch'),
        get_string('cmi5launchlrsduration_help', 'cmi5launch'),
        get_string('cmi5launchlrsduration_default', 'cmi5launch')));

    $settings->add(new admin_setting_configtext('cmi5launch/cmi5launchcustomacchp',
        get_string('cmi5launchcustomacchp', 'cmi5launch'),
        get_string('cmi5launchcustomacchp_help', 'cmi5launch'),
        get_string('cmi5launchcustomacchp_default', 'cmi5launch')));

    $settings->add(new admin_setting_configcheckbox('cmi5launch/cmi5launchuseactoremail',
        get_string('cmi5launchuseactoremail', 'cmi5launch'),
        get_string('cmi5launchuseactoremail_help', 'cmi5launch'),
        1));

    // The first time user logs in there will be a button for setup.
    $showbutton = false;
    // If tenantname or id is false, this is a first time setup we'll have the new button.
    $tenantid = get_config('cmi5launch', 'cmi5launchtenantid');
    $tenantname = get_config('cmi5launch', 'cmi5launchtenantname');
    if ($tenantid == null || $tenantid == false) {
        $showbutton = true;

    }
    $settings->add(new admin_setting_heading('cmi5launch/cmi5launchsettings', get_string('cmi5launchsettingsheader', 'cmi5launch'), ''));

  // We need to puck a diff thing for this button, we dont want the text baox available
if ($showbutton) {
    // show only a button, otherwise reg showing
    $linktocmi5 = "</br>
    <p id=name >
        <div class='input-group rounded'>
          <button class='btn btn-secondary' type='reset' name='cmi5button' onclick='tosetup()'>
            <span class='button-label'>Enter cmi5 player info</span>
            </button>
        </div>
    </p>
      ";

      //use this for the button instead of config text
      $setting = new admin_setting_description('cmi5launchsetup', "<br><br>First time setup:", $linktocmi5);
            $settings->add($setting);

    } else {

        $settings->add(
            new admin_setting_configtext_mod_cmi5launch(
                'cmi5launch/cmi5launchplayerurl',
                get_string('cmi5launchplayerurl', 'cmi5launch'),
                get_string('cmi5launchplayerurl_help', 'cmi5launch'),
                get_string('cmi5launchplayerurl_default', 'cmi5launch'),
                PARAM_URL
            )
        );

        $setting = new admin_setting_configtext(
            'cmi5launch/cmi5launchbasicname',
            get_string('cmi5launchbasicname', 'cmi5launch'),
            get_string('cmi5launchbasicname_help', 'cmi5launch'),
            get_string('cmi5launchbasicname_default', 'cmi5launch')
        );
        $settings->add($setting);

        $setting = new admin_setting_configtext(
            'cmi5launch/cmi5launchbasepass',
            get_string('cmi5launchbasepass', 'cmi5launch'),
            get_string('cmi5launchbasepass_help', 'cmi5launch'),
            get_string('cmi5launchbasepass_default', 'cmi5launch')
        );
        $settings->add($setting);

     // Display tenant info
        $todisplay = "<b>Tenant name is: " . $tenantname . ". Tenant id is: " . $tenantid . "</b><div><br> The tenant name and ID have been set. They cannot be changed without causing problems with existing cmi5 launch link activities. To change, plugin must be uninstalled and reinstalled.</div> <div><br></div>";
        $setting = new admin_setting_description('cmi5launchtenantmessage', "cmi5launch tenant name and id:", $todisplay);
        $settings->add($setting);


        // Token generate button.
        $linktotoken = "</br>
        <p id=name >
            <div class='input-group rounded'>
              <button class='btn btn-secondary' type='reset' name='tokenbutton' onclick='totokenpage()'>
                <span class='button-label'>Generate new bearer token</span>
                </button>
            </div>
        </p>
          ";
        $setting = new admin_setting_configtext(
            'cmi5launch/cmi5launchtenanttoken',
            get_string('cmi5launchtenanttoken', 'cmi5launch'),
            get_string('cmi5launchtenanttoken_help', 'cmi5launch') . $linktotoken,
            get_string('cmi5launchtenanttoken_default', 'cmi5launch')
        );
        $settings->add($setting);

    }


    // MB.
    // Grade stuff I'm bringing over.
        // Default grade settings.
    $settings->add(new admin_setting_heading('cmi5launch/gradesettings', get_string('defaultgradesettings', 'cmi5launch'), ''));
    $settings->add(new admin_setting_configselect('cmi5launch/grademethod',
        get_string('grademethod', 'cmi5launch'), get_string('grademethoddesc', 'cmi5launch'),
        MOD_CMI5LAUNCH_GRADE_HIGHEST, cmi5launch_get_grade_method_array()));

    for ($i = 0; $i <= 100; $i++) {
        $grades[$i] = "$i";
    }

    $settings->add(new admin_setting_configselect('cmi5launch/maxgrade',
        get_string('maximumgrade'), get_string('maximumgradedesc', 'cmi5launch'), 100, $grades));

    $settings->add(new admin_setting_heading('cmi5launch/othersettings', get_string('defaultothersettings', 'cmi5launch'), ''));

    // Default attempts settings.
    $settings->add(new admin_setting_configselect('cmi5launch/maxattempt',
        get_string('maximumattempts', 'cmi5launch'), '', '0', cmi5launch_get_attempts_array()),
        get_string('whatmaxdesc', 'cmi5launch'), );

    $settings->add(new admin_setting_configselect('cmi5launch/whatgrade',
        get_string('whatgrade', 'cmi5launch'), get_string('whatgradedesc', 'cmi5launch'),
        MOD_CMI5LAUNCH_HIGHEST_ATTEMPT, cmi5launch_get_what_grade_array()));

    // Not sure if we want to implement mastery override at this time -MB.
    /*
    $settings->add(new admin_setting_configselect('cmi5launch/masteryoverride',
    get_string('masteryoverride', 'cmi5launch'), get_string('masteryoverridedesc', 'cmi5launch'), 1, $yesno));
    */

    $settings->add(new admin_setting_configselect('cmi5launch/MOD_CMI5LAUNCH_LAST_ATTEMPTlock',
        get_string('mod_cmi5launch_last_attempt_lock', 'cmi5launch'), get_string('mod_cmi5launch_last_attempt_lockdesc', 'cmi5launch'), 0, $yesno));

    // User Experience Settings - Terminology Customization.
    $settings->add(new admin_setting_heading('cmi5launch/terminology',
        get_string('terminologyheading', 'cmi5launch'),
        get_string('terminologyheading_help', 'cmi5launch')));

    // AU terminology.
    $auoptions = array(
        'activity' => get_string('au_term_activity', 'cmi5launch'),
        'lesson' => get_string('au_term_lesson', 'cmi5launch'),
        'module' => get_string('au_term_module', 'cmi5launch'),
        'unit' => get_string('au_term_unit', 'cmi5launch'),
        'content' => get_string('au_term_content', 'cmi5launch'),
        'au' => get_string('au_term_au', 'cmi5launch'),
        'custom' => get_string('au_term_custom', 'cmi5launch')
    );

    $settings->add(new admin_setting_configselect('cmi5launch/au_terminology',
        get_string('au_terminology', 'cmi5launch'),
        get_string('au_terminology_help', 'cmi5launch'),
        'activity',
        $auoptions));

    // Custom AU term (if selected).
    $settings->add(new admin_setting_configtext('cmi5launch/au_terminology_custom',
        get_string('au_terminology_custom', 'cmi5launch'),
        get_string('au_terminology_custom_help', 'cmi5launch'),
        ''));

    // Status terminology.
    $statusoptions = array(
        'completed' => get_string('status_term_completed', 'cmi5launch'),
        'passed' => get_string('status_term_passed', 'cmi5launch'),
        'finished' => get_string('status_term_finished', 'cmi5launch'),
        'satisfied' => get_string('status_term_satisfied', 'cmi5launch'),
        'custom' => get_string('status_term_custom', 'cmi5launch')
    );

    $settings->add(new admin_setting_configselect('cmi5launch/status_terminology',
        get_string('status_terminology', 'cmi5launch'),
        get_string('status_terminology_help', 'cmi5launch'),
        'completed',
        $statusoptions));

    // Custom status term.
    $settings->add(new admin_setting_configtext('cmi5launch/status_terminology_custom',
        get_string('status_terminology_custom', 'cmi5launch'),
        get_string('status_terminology_custom_help', 'cmi5launch'),
        ''));

    // Module display name.
    $settings->add(new admin_setting_configtext('cmi5launch/module_displayname',
        get_string('module_displayname', 'cmi5launch'),
        get_string('module_displayname_help', 'cmi5launch'),
        'Interactive Content'));

    // Technical mode toggle.
    $settings->add(new admin_setting_configcheckbox('cmi5launch/show_technical_terms',
        get_string('show_technical_terms', 'cmi5launch'),
        get_string('show_technical_terms_help', 'cmi5launch'),
        0));

    // Progress polling interval.
    $settings->add(new admin_setting_configtext('cmi5launch/polling_interval',
        get_string('polling_interval', 'cmi5launch'),
        get_string('polling_interval_help', 'cmi5launch'),
        '10',
        PARAM_INT));

    // AI Insights Settings
    $settings->add(new admin_setting_heading('cmi5launch/ai_insights',
        get_string('ai_insights_heading', 'cmi5launch'),
        get_string('ai_insights_heading_help', 'cmi5launch')));

    // AI Provider selection
    $ai_providers = array(
        '' => get_string('ai_provider_none', 'cmi5launch'),
        'openai' => get_string('ai_provider_openai', 'cmi5launch'),
        'claude' => get_string('ai_provider_claude', 'cmi5launch'),
        'local' => get_string('ai_provider_local', 'cmi5launch')
    );

    $settings->add(new admin_setting_configselect('cmi5launch/ai_provider',
        get_string('ai_provider', 'cmi5launch'),
        get_string('ai_provider_help', 'cmi5launch'),
        '',
        $ai_providers));

    // AI API Key
    $settings->add(new admin_setting_configpasswordunmask('cmi5launch/ai_api_key',
        get_string('ai_api_key', 'cmi5launch'),
        get_string('ai_api_key_help', 'cmi5launch'),
        ''));

    // AI Model selection (optional)
    $settings->add(new admin_setting_configtext('cmi5launch/ai_model',
        get_string('ai_model', 'cmi5launch'),
        get_string('ai_model_help', 'cmi5launch'),
        ''));

    // Local AI endpoint (for local LLM)
    $settings->add(new admin_setting_configtext('cmi5launch/ai_local_endpoint',
        get_string('ai_local_endpoint', 'cmi5launch'),
        get_string('ai_local_endpoint_help', 'cmi5launch'),
        ''));

    }

    ?> 
    
    <form id="setupform" action="../mod/cmi5launch/setupform.php" method="get">
 
 </form>


    <form id="settingformtoken" action="../mod/cmi5launch/tokensetup.php" method="get">
 
</form>


 <form id="settingform" action="../mod/cmi5launch/tenantsetup.php" method="get">
        
        <input id="variableName" name="variableName" type="hidden" value="default">

    </form>