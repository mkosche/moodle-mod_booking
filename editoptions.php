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
 * Edit options form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->libdir . '/formslib.php');

use mod_booking\singleton_service;

global $DB, $OUTPUT, $PAGE, $USER;

$cmid = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$copyoptionid = optional_param('copyoptionid', 0, PARAM_INT);
$createfromoptiondates = optional_param('createfromoptiondates', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);
$mode = optional_param('mode', '', PARAM_RAW);

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
/* $PAGE->requires->jquery_plugin('ui-css'); */

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);

$url = new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid, 'optionid' => $optionid]);
$PAGE->set_url($url);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');

// Initialize bookingid.
$bookingid = (int) $cm->instance;
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cmid)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cmid)) {
    throw new moodle_exception('badcontext');
}

if ((has_capability('mod/booking:updatebooking', $context) || (has_capability(
    'mod/booking:addeditownoption', $context) && booking_check_if_teacher($optionid))) == false) {
    throw new moodle_exception('nopermissions');
}

if (has_capability('mod/booking:cantoggleformmode', $context)) {
    // Switch mode after button has been clicked.
    switch ($mode) {
        case 'formmodesimple':
            set_user_preference('optionform_mode', 'simple');
            break;
        case 'formmodeexpert':
            set_user_preference('optionform_mode', 'expert');
            break;
    }
} else {
    // Without the capability, we always use simple mode.
    set_user_preference('optionform_mode', 'simple');
}
// We don't need this anymore.
$optionid = $optionid < 0 ? 0 : $optionid;

// New code.
$params = [
    'cmid' => $cmid,
    'id' => $optionid, // In the context of option_form class, id always refers to optionid.
    'optionid' => $optionid, // Just kept on for legacy reasons.
    'bookingid' => $bookingid,
    'copyoptionid' => $copyoptionid,
    'returnurl' => $returnurl,
];

// In this example the form has arguments ['arg1' => 'val1'].
$form = new mod_booking\form\option_form(null, null, 'post', '', [], true, $params);
// Set the form data with the same method that is called when loaded from JS.
// It should correctly set the data for the supplied arguments.
$form->set_data_for_dynamic_submission();

echo $OUTPUT->header();

    $mform->display();

} else if ($fromform = $mform->get_data()) {
    // Validated data.
    if (confirm_sesskey() &&
            (has_capability('mod/booking:updatebooking', $context) ||
            has_capability('mod/booking:addeditownoption', $context))) {
        if (!isset($fromform->limitanswers)) {
            $fromform->limitanswers = 0;
        }

        dates_handler::add_values_from_post_to_form($fromform);

        // Todo: Should nbooking be renamed to $optionid?
        $nbooking = booking_update_options($fromform, $context);

        if ($draftitemid = file_get_submitted_draft_itemid('myfilemanageroption')) {
            file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption',
                    $nbooking, ['subdirs' => false, 'maxfiles' => 50]);
        }

        if ($draftimageid = file_get_submitted_draft_itemid('bookingoptionimage')) {
            file_save_draft_area_files($draftimageid, $context->id, 'mod_booking', 'bookingoptionimage',
                    $nbooking, ['subdirs' => false, 'maxfiles' => 1]);
        }

        if (isset($fromform->addastemplate) && $fromform->addastemplate == 1) {
            $fromform->bookingid = 0;
            $nbooking = booking_update_options($fromform, $context);
            if ($nbooking === 'BOOKING_OPTION_NOT_CREATED') {
                $redirecturl = new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid, 'optionid' => -1]);
                redirect($redirecturl, get_string('option_template_not_saved_no_valid_license', 'mod_booking'), 0,
                    notification::NOTIFY_ERROR);
            } else if (isset($fromform->submittandaddnew)) {
                $redirecturl = new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid, 'optionid' => -1]);
                redirect($redirecturl, get_string('newtemplatesaved', 'mod_booking'), 0);
            } else {
                $redirecturl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
                redirect($redirecturl, get_string('newtemplatesaved', 'mod_booking'), 0);
            }
        }

        $bookingdata = singleton_service::get_instance_of_booking_option($cmid, $nbooking);
        $bookingdata->sync_waiting_list();

        if (has_capability('mod/booking:addeditownoption', $context) && $optionid == -1 &&
                !has_capability('mod/booking:updatebooking', $context)) {
            subscribe_teacher_to_booking_option($USER->id, $nbooking, $cm->id);
        }

        // Recurring - Do we still need this? Currently we do not use this anymore.
        if ($optionid == -1 && isset($fromform->startendtimeknown) && $fromform->startendtimeknown == 1 &&
            isset($fromform->repeatthisbooking) && $fromform->repeatthisbooking == 1 && $fromform->howmanytimestorepeat > 0) {

            $fromform->parentid = $nbooking;
            $name = $fromform->text;

            // NOTICE: We currently do not handle bookingopeningtime. Maybe we will need to add it in a later release.

            // Handle booking closing time. Is this correct?
            $restrictanswerperiodclosing = 0;
            if (isset($fromform->restrictanswerperiodclosing) && $fromform->restrictanswerperiodclosing) {
                $restrictanswerperiodclosing = $fromform->coursestarttime - $fromform->bookingclosingtime;
            }

            for ($i = 0; $i < $fromform->howmanytimestorepeat; $i++) {
                $fromform->text = $name . " #" . ($i + 2);
                $fromform->coursestarttime = $fromform->coursestarttime + $fromform->howoftentorepeat;
                $fromform->courseendtime = $fromform->courseendtime + $fromform->howoftentorepeat;

                if ($restrictanswerperiodclosing != 0) {
                    $fromform->bookingclosingtime = $fromform->coursestarttime + $restrictanswerperiodclosing;
                }

                $nbooking = booking_update_options($fromform, $context, $cm);

                if ($draftitemid = file_get_submitted_draft_itemid('myfilemanageroption')) {
                    file_save_draft_area_files($draftitemid, $context->id, 'mod_booking', 'myfilemanageroption',
                            $nbooking, ['subdirs' => false, 'maxfiles' => 50]);
                }

                if ($draftimageid = file_get_submitted_draft_itemid('bookingoptionimage')) {
                    file_save_draft_area_files($draftimageid, $context->id, 'mod_booking', 'bookingoptionimage',
                            $nbooking, ['subdirs' => false, 'maxfiles' => 1]);
                }

                $bookingdata = singleton_service::get_instance_of_booking_option($cmid, $nbooking);
                $bookingdata->sync_waiting_list();

                if (has_capability('mod/booking:addeditownoption', $context) && $optionid == -1 &&
                        !has_capability('mod/booking:updatebooking', $context)) {
                    subscribe_teacher_to_booking_option($USER->id, $nbooking, $cm->id);
                }
            }
        }

        // Make sure we have the option id in the fromform.
        $fromform->optionid = $nbooking ?? $optionid;

        // Save the prices.
        $price = new price('option', $fromform->optionid);
        $price->save_from_form($fromform);

        // This is to save entity relation data.
        // The id key has to be set to option id.
        if (class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'option');
            $erhandler->instance_form_save($fromform, $fromform->optionid);
        }

        // This is to save customfield data
        // The id key has to be set to option id.
        $fromform->id = $nbooking ?? $optionid;
        $handler = booking_handler::create();
        $handler->instance_form_save($fromform, $optionid == -1);

        // Redirect after pressing one of the 3 submit buttons.
        if (isset($fromform->submittandaddnew)) {
            $redirecturl = new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid, 'optionid' => -1]);
        } else if (isset($fromform->submitandstay)) {
            $redirecturl = new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid, 'optionid' => $fromform->optionid]);
        } else {

            if (!empty($returnurl)) {
                $redirecturl = $returnurl;
            } else {
                $redirecturl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
            }
        }
        redirect($redirecturl, get_string('changessaved'), 0);
    }
} else {
    $PAGE->set_title(format_string($booking->settings->name));
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();

    if (has_capability('mod/booking:cantoggleformmode', $context)) {

        $currentpref = get_user_preferences('optionform_mode');
        switch ($currentpref) {
            case 'expert':
                $togglemode = 'formmodesimple';
                $formmodelabel = get_string('toggleformmode_simple', 'mod_booking');
                break;
            case 'simple':
            default:
                $togglemode = 'formmodeexpert';
                $formmodelabel = get_string('toggleformmode_expert', 'mod_booking');
                break;
        }

        // Add a button to toggle between simple and expert mode.
        $formmodeurl = new moodle_url('/mod/booking/editoptions.php', [
            'mode' => $togglemode,
            'id' => $cmid,
            'optionid' => $optionid,
        ]);

        echo html_writer::link($formmodeurl->out(false),
                            $formmodelabel,
                            ['id' => $cmid, 'optionid' => $optionid, 'class' => 'btn btn-secondary float-right']
                        );
    }

    // Heading.
    if (empty($optionid)) {
        $heading = get_string('createnewbookingoption', 'mod_booking');
    } else {
        $heading = get_string('editbookingoption', 'mod_booking');
    }

    echo $OUTPUT->heading($heading);

    if (isset($defaultvalues)) {

        // We need to set the returnurl if present.
        if (!empty($returnurl)) {
            $defaultvalues->returnurl = $returnurl;
        }

        $mform->set_data($defaultvalues);
    }
    echo '<div class="container-fluid" data-spy="scroll" data-target="#bo-edit-scrollspy" data-offset="0"><div class="row"><div class="col-md-3">';
    echo '<div id="bo-edit-scrollspy" class="list-group sticky-top">';

    $is_first = true;
    foreach ($mform->get_headers() as $header){
        $active = $is_first ? 'active' : '';
        echo '<a class="list-group-item list-group-item-action '.$active.'" href="#id_'.$header->_attributes["name"].'">'.$header->_text.'</a>';
        $is_first = false;
    }
    echo '</div></div><div  class=" col-md-9">';
    $mform->expand_all();
    $mform->display();
    echo '</div></div></div>';
}

// Initialize dynamic optiondate form.
$PAGE->requires->js_call_amd(
    'mod_booking/dynamicoptiondateform',
    'initdynamicoptiondateform',
    [$cmid, $bookingid, $optionid, get_string('modaloptiondateformtitle', 'mod_booking'), modaloptiondateform::class]
);

echo $OUTPUT->footer();
