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
 * ajax server
 *
 * @package     block_helpmenow
 * @copyright   2012 VLACS
 * @author      David Zaharee <dzaharee@vlacs.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

# capture output
ob_start();

require_once((dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once(dirname(__FILE__) . '/lib.php');

if (!isloggedin()) {
    ob_end_clean();
    header('HTTP/1.1 401 Unauthorized');
    die;
}

require_login(0, false);

try {
    # get the request body
    $request = json_decode(file_get_contents('php://input'));

    # generate response
    $response = new stdClass;
    switch ($request->function) {
    case 'message':
        # verify sesion
        if (!helpmenow_verify_session($request->session)) {
            throw new Exception('Invalid session');
        }

        $message_rec = (object) array(
            'userid' => $USER->id,
            'sessionid' => $request->session,
            'time' => time(),
            'message' => addslashes($request->message),
        );
        if (!insert_record('block_helpmenow_message', $message_rec)) {
            throw new Exception('Could insert message record');
        }
        break;
    case 'history':
        $sql = '';
        $request->last_message = -1;
        $response->last_message = 0;
        # fall through here
    case 'refresh':
        # verify sesion
        if (!helpmenow_verify_session($request->session)) {
            throw new Exception('Invalid session');
        }

        set_field('block_helpmenow_session2user', 'last_refresh', time(), 'sessionid', $request->session, 'userid', $USER->id);

        if ($request->function == 'refresh') {
            $sql = "AND u.id <> $USER->id";
        }
        $sql = "
            SELECT m.*, u.id AS userid, u.firstname, u.lastname
            FROM {$CFG->prefix}block_helpmenow_message m
            JOIN {$CFG->prefix}user u ON m.userid = u.id
            WHERE m.sessionid = $request->session
            AND m.id > $request->last_message
            $sql
            ORDER BY m.time ASC
        ";
        $messages = get_records_sql($sql);

        $response->html = '';
        foreach ($messages as $m) {
            $msg = $m->message;
            if ($m->userid == get_admin()->id) {
                $msg = "<i>$msg</i>";
            } else {
                if ($m->userid == $USER->id) {
                    $name = "Me";
                } else {
                    $name = "$m->firstname $m->lastname";
                }
                $msg = "<b>$name:</b> $msg";
            }
            $response->html .= "<div>$msg</div>";
            $response->last_message = $m->id;
        }
        break;
    case 'block':
        # datetime for debugging
        date_default_timezone_set('America/New_York');
        $response->last_refresh = 'Updated: '.date('g:i:s a T');

        # clean up sessions
        helpmenow_clean_sessions();

        # meetingid for helpers and instructors
        if ($record = get_record('block_helpmenow_user2plugin', 'userid', $USER->id, 'plugin', 'gotomeeting')) {
            require_once(dirname(__FILE__) . '/plugins/gotomeeting/lib.php');   # todo: fix this
            $user2plugin = new helpmenow_user2plugin_gotomeeting(null, $record);
            $response->meetingid = preg_replace("/^(\d{3})(\d{3})(\d{3})$/", "$1-$2-$3", $user2plugin->meetingid);
        }

        $response->pending = false;

        # queues
        $response->queues_html = '';
        $connect = new moodle_url("$CFG->wwwroot/blocks/helpmenow/connect.php");
        $queues = helpmenow_queue::get_queues();
        foreach ($queues as $q) {
            $response->queues_html .= '<div>';
            switch ($q->get_privilege()) {
            case HELPMENOW_QUEUE_HELPER:
                $instyle = $outstyle = '';
                if ($q->helpers[$USER->id]->isloggedin) {
                    $outstyle = 'style="display: none;"';
                } else {
                    $instyle = 'style="display: none;"';
                }
                $login_url = new moodle_url("$CFG->wwwroot/blocks/helpmenow/login.php");
                $login_url->param('queueid', $q->id);
                $login_url->param('login', 0);
                $logout = link_to_popup_window($login_url->out(), "login", 'Log Out', 400, 500, null, null, true);
                $login_url->param('login', 1);
                $login = link_to_popup_window($login_url->out(), "login", 'Log In', 400, 500, null, null, true);
                $response->queues_html .= <<<EOF
<div>$q->name</div>
<div style="text-align: center; font-size:small; margin-top:.5em; margin-bottom:.5em;">
    <div id="helpmenow_logged_in_div_$q->id" $instyle>$logout</div>
    <div id="helpmenow_logged_out_div_$q->id" $outstyle>You're Logged Out | $login</div>
</div>
EOF;

                # sessions
                $sql = "
                    SELECT u.*, s.id AS sessionid, m.message
                    FROM {$CFG->prefix}block_helpmenow_session s
                    JOIN {$CFG->prefix}user u ON u.id = s.createdby
                    JOIN {$CFG->prefix}block_helpmenow_message m ON m.id = (
                        SELECT MAX(id) FROM {$CFG->prefix}block_helpmenow_message m2 WHERE m2.sessionid = s.id AND m2.userid = s.createdby
                    )
                    WHERE s.queueid = $q->id
                    AND s.iscurrent = 1
                ";
                if (!$sessions = get_records_sql($sql)) {
                    break;
                }
                $response->queues_html .= '<div style="margin-left: 1em;">';

                foreach ($sessions as &$s) {
                    $s->pending = true;
                    $sql = "
                        SELECT *
                        FROM {$CFG->prefix}block_helpmenow_session2user s2u
                        JOIN {$CFG->prefix}user u ON u.id = s2u.userid
                        WHERE s2u.sessionid = $s->sessionid
                        AND s2u.userid <> $s->id
                    ";
                    $s->helpers = get_records_sql($sql);
                    foreach ($s->helpers as $h) {
                        if (($h->last_refresh + 20) > time()) {
                            $s->pending = false;
                            continue 2;
                        }
                        if (($h->last_refresh > $s->time)) {
                            $s->pending = false;
                            continue 2;
                        }
                    }
                }

                unset($s);

                # sort by unseen messages, lastname, firstname
                usort($sessions, function($a, $b) {
                    if (!($a->pending xor $b->pending)) {
                        return strcmp(strtolower("$a->lastname $a->firstname"), strtolower("$b->lastname $b->firstname"));
                    }
                    return $a->pending ? -1 : 1;
                });

                foreach ($sessions as $s) {
                    $connect->remove_params('queueid');
                    $connect->param('sessionid', $s->sessionid);
                    $message = $style = '';
                    if ($s->pending) {
                        $style = ' style="background-color:yellow"';
                        $message = '<div style="margin-left: 1em;">' . $s->message . '</div>';
                        if ($q->helpers[$USER->id]->isloggedin) {
                            $response->pending = true;
                        }
                    }
                    $response->queues_html .= "<div$style>" . link_to_popup_window($connect->out(), $s->sessionid, fullname($s), 400, 500, null, null, true) . "$message</div>";
                }
                $response->queues_html .= '</div>';
                break;
            case HELPMENOW_QUEUE_HELPEE:
                $sql = "
                    SELECT s.*, m.message
                    FROM {$CFG->prefix}block_helpmenow_session s
                    JOIN {$CFG->prefix}block_helpmenow_session2user s2u ON s2u.sessionid = s.id AND s2u.userid = s.createdby
                    JOIN {$CFG->prefix}block_helpmenow_message m ON m.id = (
                        SELECT MAX(id) FROM {$CFG->prefix}block_helpmenow_message m2 WHERE m2.sessionid = s.id AND m2.userid <> s.createdby
                    )
                    WHERE s.iscurrent = 1
                    AND s.createdby = $USER->id
                    AND s.queueid = $q->id
                    AND (s2u.last_refresh + 20) < ".time()."
                    AND s2u.last_refresh < m.time
                ";
                if ($session = get_record_sql($sql) or $q->is_open()) {
                    $connect->remove_params('sessionid');
                    $connect->param('queueid', $q->id);
                    $style = '';
                    if ($session) {
                        $style = ' style="background-color:yellow"';
                        $message = '<div style="margin-left: 1em;">' . $session->message . '</div>' . $message;
                        $response->pending = true;
                    }
                    $response->queues_html .= "<div$style>" . link_to_popup_window($connect->out(), "queue{$q->id}", $q->name, 400, 500, null, null, true) . "$message</div>";
                } else {
                    $response->queues_html .= "<div>$q->name</div>$message";
                }
                break;
            }
            $desc_message = '<div style="margin-left: 1em; font-size: smaller;">' . $q->description . '</div>';
            $response->queues_html .= '</div>' . $desc_message . '<hr />';
        }

        # user lists for students and instructors
        $privilege = get_field('sis_user', 'privilege', 'sis_user_idstr', $USER->idnumber);
        switch ($privilege) {
        case 'TEACHER':
            $users = helpmenow_get_students();
            $isloggedin = get_field('block_helpmenow_user', 'isloggedin', 'userid', $USER->id);
            $response->isloggedin = $isloggedin ? true : false;
            break;
        case 'STUDENT':
            $users = helpmenow_get_instructors();
            $isloggedin = true;
            break;
        default:
            break 2;
        }

        # get any unseen messages
        foreach ($users as $u) {
            $sql = "
                SELECT s.*, m.message
                FROM {$CFG->prefix}block_helpmenow_session2user s2u
                JOIN {$CFG->prefix}block_helpmenow_session s ON s.id = s2u.sessionid
                JOIN {$CFG->prefix}block_helpmenow_session2user s2u2 ON s2u2.sessionid = s.id AND s2u2.userid <> s2u.userid
                JOIN {$CFG->prefix}block_helpmenow_message m ON m.id = (
                    SELECT MAX(id) FROM {$CFG->prefix}block_helpmenow_message m2 WHERE m2.sessionid = s.id AND m2.userid <> s2u.userid
                )
                WHERE s2u.userid = $USER->id
                AND s.iscurrent = 1
                AND s2u2.userid = $u->id
                AND s.queueid IS NULL
                AND (s2u.last_refresh + 20) < ".time()."
                AND s2u.last_refresh < m.time
            ";
            if (!$session = get_record_sql($sql)) {
                continue;
            }
            $u->sessionid = $session->id;
            $u->message = $session->message;
        }

        # sort by unseen messages, lastname, firstname
        usort($users, function($a, $b) {
            if (!(isset($a->sessionid) xor isset($b->sessionid))) {
                return strcmp(strtolower("$a->lastname $a->firstname"), strtolower("$b->lastname $b->firstname"));
            }
            return isset($a->sessionid) ? -1 : 1;
        });

        # build the list
        $response->users_html = '';
        $connect->remove_params('queueid');
        $connect->remove_params('sessionid');
        foreach ($users as $u) {
            $connect->param('userid', $u->id);
            $message = '';
            $style = 'margin-left: 1em;';
            if (isset($u->motd)) {
                $message .= '<div style="font-size: smaller;">' . $u->motd . '</div>';
            }
            if (isset($u->sessionid)) {
                $style .= 'background-color:yellow;';
                $message .= '<div>' . $u->message . '</div>';
                $response->pending = true;
            }
            $message = '<div style="margin-left: 1em;">'.$message.'</div>';
            if ($isloggedin) {
                $link = link_to_popup_window($connect->out(), $u->id, fullname($u), 400, 500, null, null, true);
            } else {
                $link = fullname($u);
            }
            $response->users_html .= "<div style=\"$style\">".$link.$message."</div>";
        }
        break;
    case 'motd':
        if (!$helpmenow_user = get_record('block_helpmenow_user', 'userid', $USER->id)) {
            throw new Exception('No helpmenow_user record');
        }
        $helpmenow_user->motd = addslashes($request->motd);
        if (!update_record('block_helpmenow_user', $helpmenow_user)) {
            throw new Exception('Could not update user record');
        }
        $response->motd = $request->motd;
        break;
    case 'plugin':
        $plugin = $request->plugin;
        $class = "helpmenow_plugin_$plugin";
        $plugin_function = $request->plugin_function;
        if (!in_array($plugin, helpmenow_plugin::get_plugins())) {
            throw new Exception('Unknown plugin');
        }
        if (!in_array($plugin_function, $class::get_ajax_functions())) {
            throw new Exception('Unknown function');
        }
        $response = $plugin_function($request);
        break;
    default:
        throw new Exception('Unknown function');
    }
} catch (Exception $e) {
    $debugging = ob_get_clean();
    header('HTTP/1.1 400 Bad Request');
    if ($CFG->debugdisplay) {
        $response = new stdClass;
        $response->error = $e->getMessage();
        $response->debugging = $debugging;
        echo json_encode($response);
    }
    die;
}

$debugging = ob_get_clean();
if ($CFG->debugdisplay) {
    $response->debugging = $debugging;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Expires: '. gmdate('D, d M Y H:i:s', 0) .' GMT');
header('Pragma: no-cache');
echo json_encode($response);

?>
