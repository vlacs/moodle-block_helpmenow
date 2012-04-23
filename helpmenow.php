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
 * This script handles the main help me now interface.
 *
 * @package     block_helpmenow
 * @copyright   2012 VLACS
 * @author      David Zaharee <dzaharee@vlacs.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

# moodle stuff
require_once((dirname(dirname(dirname(__FILE__)))) . '/config.php');

# helpmenow library
require_once(dirname(__FILE__) . '/lib.php');

# require login
require_login(0, false);

# check for helper
if (!record_exists('block_helpmenow_helper', 'userid', $USER->id)) {
    # todo: close
    redirect();
}

# title, navbar, and a nice box
$title = get_string('helpmenow', 'block_helpmenow');
$nav = array(array('name' => $title));
print_header($title, $title, build_navigation($nav));
print_box_start('generalbox');

$queues = helpmenow_queue::get_queues_by_user();
foreach ($queues as $q) {
    print_box_start('generalbox');
    print_heading($q->name);
    $login = new moodle_url("$CFG->wwwroot/blocks/helpmenow/login.php");
    $login->params(array(
        'courseid' => $COURSE->id,
        'queueid' => $q->id,
    ));
    if ($q->helper[$USER->id]->isloggedin) {
        $login->param('login', 0);
        $login_status = get_string('loggedin', 'block_helpmenow');
        $login_text = get_string('logout', 'block_helpmenow');
    } else {
        $login->param('login', 1);
        $login_status = get_string('loggedout', 'block_helpmenow');
        $login_text = get_string('login', 'block_helpmenow');
    }
    $login = $login->out();
    echo "<p align='center'>$login_status <a href='$login'>$login_text</a></p>";
    echo "<ul>";
    # requests, these are in ascending order thanks to the queue object
    foreach ($q->request as $r) {
        $connect = new moodle_url("$CFG->wwwroot/blocks/helpmenow/connect.php");
        $connect->param('requestid', $r->id);
        $connect->param('connect', 1);
        $name = fullname(get_record('user', 'id', $r->userid));
        echo "<li>" .link_to_popup_window($connect->out(), 'meeting', $name, 400, 700, null, null, true) . "<br />";
        echo $r->description . "</li>";
    }
    echo "</ul>";
    print_box_end();
    break;
}

print_box_end();

# footer
print_footer();

?>
