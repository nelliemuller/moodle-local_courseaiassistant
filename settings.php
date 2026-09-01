<?php

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.


/**
 * Site settings for the AI Course Assistant local plugin.
 *
 * @package   local_courseaiassistant
 * @copyright 2026 Nellie Deutsch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_courseaiassistant',
        get_string('pluginname', 'local_courseaiassistant')
    );

    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'local_courseaiassistant/identityheading',
        get_string('setting:identityheading', 'local_courseaiassistant'),
        get_string('setting:identityheading_desc', 'local_courseaiassistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_courseaiassistant/enabled',
        get_string('setting:enabled', 'local_courseaiassistant'),
        get_string('setting:enabled_desc', 'local_courseaiassistant'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_courseaiassistant/assistantname',
        get_string('setting:assistantname', 'local_courseaiassistant'),
        get_string('setting:assistantname_desc', 'local_courseaiassistant'),
        'AI Course Assistant',
        PARAM_TEXT
    ));

    $iconsources = [
        'default' => get_string('iconsource:default', 'local_courseaiassistant'),
        'sitelogo' => get_string('iconsource:sitelogo', 'local_courseaiassistant'),
        'custom' => get_string('iconsource:custom', 'local_courseaiassistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_courseaiassistant/iconsource',
        get_string('setting:iconsource', 'local_courseaiassistant'),
        get_string('setting:iconsource_desc', 'local_courseaiassistant'),
        'default',
        $iconsources
    ));
    $settings->add(new admin_setting_configstoredfile(
        'local_courseaiassistant/assistantimage',
        get_string('setting:assistantimage', 'local_courseaiassistant'),
        get_string('setting:assistantimage_desc', 'local_courseaiassistant'),
        'assistantimage',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['image']]
    ));

    $launcherplacements = [
        'askgemini' => get_string('launcherplacement:askgemini', 'local_courseaiassistant'),
        'bottomright' => get_string('launcherplacement:bottomright', 'local_courseaiassistant'),
        'bottomcenter' => get_string('launcherplacement:bottomcenter', 'local_courseaiassistant'),
        'bottomleft' => get_string('launcherplacement:bottomleft', 'local_courseaiassistant'),
        'courseheader' => get_string('launcherplacement:courseheader', 'local_courseaiassistant'),
        'fullpage' => get_string('launcherplacement:fullpage', 'local_courseaiassistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_courseaiassistant/launcherplacement',
        get_string('setting:launcherplacement', 'local_courseaiassistant'),
        get_string('setting:launcherplacement_desc', 'local_courseaiassistant'),
        'bottomright',
        $launcherplacements
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_courseaiassistant/allowdashboard',
        get_string('setting:allowdashboard', 'local_courseaiassistant'),
        get_string('setting:allowdashboard_desc', 'local_courseaiassistant'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_courseaiassistant/languageheading',
        get_string('setting:languageheading', 'local_courseaiassistant'),
        get_string('setting:languageheading_desc', 'local_courseaiassistant')
    ));

    $languages = [
        'user' => get_string('language:user', 'local_courseaiassistant'),
        'question' => get_string('language:question', 'local_courseaiassistant'),
        'site' => get_string('language:site', 'local_courseaiassistant'),
        'fixed' => get_string('language:fixed', 'local_courseaiassistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_courseaiassistant/responselanguage',
        get_string('setting:responselanguage', 'local_courseaiassistant'),
        get_string('setting:responselanguage_desc', 'local_courseaiassistant'),
        'user',
        $languages
    ));

    $settings->add(new admin_setting_configtext(
        'local_courseaiassistant/fixedlanguage',
        get_string('setting:fixedlanguage', 'local_courseaiassistant'),
        get_string('setting:fixedlanguage_desc', 'local_courseaiassistant'),
        'en',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_courseaiassistant/aiheading',
        get_string('setting:aiheading', 'local_courseaiassistant'),
        get_string('setting:aiheading_desc', 'local_courseaiassistant')
    ));

    $providers = [
        'gemini' => get_string('provider:gemini', 'local_courseaiassistant'),
        'openai' => get_string('provider:openai', 'local_courseaiassistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_courseaiassistant/aiprovider',
        get_string('setting:aiprovider', 'local_courseaiassistant'),
        get_string('setting:aiprovider_desc', 'local_courseaiassistant'),
        'gemini',
        $providers
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_courseaiassistant/geminiapikey',
        get_string('setting:geminiapikey', 'local_courseaiassistant'),
        get_string('setting:geminiapikey_desc', 'local_courseaiassistant'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_courseaiassistant/model',
        get_string('setting:model', 'local_courseaiassistant'),
        get_string('setting:model_desc', 'local_courseaiassistant'),
        'gemini-3.7-flash',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_courseaiassistant/openaiapikey',
        get_string('setting:openaiapikey', 'local_courseaiassistant'),
        get_string('setting:openaiapikey_desc', 'local_courseaiassistant'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_courseaiassistant/openaimodel',
        get_string('setting:openaimodel', 'local_courseaiassistant'),
        get_string('setting:openaimodel_desc', 'local_courseaiassistant'),
        'gpt-5.6-terra',
        PARAM_TEXT
    ));
    $reasoningoptions = [
        'none' => get_string('reasoning:none', 'local_courseaiassistant'),
        'low' => get_string('reasoning:low', 'local_courseaiassistant'),
        'medium' => get_string('reasoning:medium', 'local_courseaiassistant'),
        'high' => get_string('reasoning:high', 'local_courseaiassistant'),
        'xhigh' => get_string('reasoning:xhigh', 'local_courseaiassistant'),
        'max' => get_string('reasoning:max', 'local_courseaiassistant'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_courseaiassistant/openaireasoning',
        get_string('setting:openaireasoning', 'local_courseaiassistant'),
        get_string('setting:openaireasoning_desc', 'local_courseaiassistant'),
        'medium',
        $reasoningoptions
    ));

    $settings->add(new admin_setting_heading(
        'local_courseaiassistant/appearanceheading',
        get_string('setting:appearanceheading', 'local_courseaiassistant'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_courseaiassistant/usethemecolor',
        get_string('setting:usethemecolor', 'local_courseaiassistant'),
        get_string('setting:usethemecolor_desc', 'local_courseaiassistant'),
        1
    ));
    $settings->add(new admin_setting_configcolourpicker(
        'local_courseaiassistant/customprimary',
        get_string('setting:customprimary', 'local_courseaiassistant'),
        get_string('setting:customprimary_desc', 'local_courseaiassistant'),
        '#0f6cbf'
    ));

}
}
