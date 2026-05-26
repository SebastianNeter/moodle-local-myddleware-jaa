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
 * External Web Service Template
 *
 * @package    local_myddleware
 * @copyright  2017 Myddleware
 * @author     Myddleware ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/externallib.php");

use core_competency\user_competency;

/**
 * Myddleware external functions
 *
 * @package    local_myddleware
 * @category   external
 * @copyright  2017 Myddleware
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_myddleware_external extends external_api {

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_users_completion_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search completion created after the date $timemodified in parameters.
     * @param int $timemodified
     * @param int $id
     * @return an array with the detail of each completion (id,userid,completionstate,timemodified,moduletype,instance,courseid).
     */
    public static function get_users_completion($timemodified, $id) {
        global $DB;

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_users_completion_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'cmc.userid'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." cmc.id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." cmc.timemodified > :timemodified ";
        }

        // Retrieve token list (including linked users firstname/lastname and linked services name).
        $sql = "
            SELECT
                cmc.id,
                cmc.userid,
                cmc.completionstate,
                cmc.timemodified,
                cm.id coursemoduleid,
                cm.module moduletype,
                cm.instance,
                cm.section,
                cm.course courseid
            FROM {course_modules_completion} cmc
            INNER JOIN {course_modules} cm
                ON cm.id = cmc.coursemoduleid
            WHERE
                ".$where."
            ORDER BY timemodified ASC
                ";

        $queryparams = [
                             'id' => (!empty($params['id']) ? $params['id'] : ''),
                             'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $completions = [];
        if (!empty($rs)) {
            foreach ($rs as $completionrecords) {
                foreach ($completionrecords as $key => $value) {
                    $completion[$key] = $value;
                }

                // Security check to validate the course.
                list($courses, $warnings) = core_external\util::validate_courses([$completion['courseid']], [], true);
                if (empty($courses[$completion['courseid']])) {
                    continue;
                }
                // Add information about the module.
                $modinfo = get_fast_modinfo($completion['courseid']);
                $cm = $modinfo->get_cm($completion['coursemoduleid']);
                $completion['modulename'] = $cm->modname;
                $completion['coursemodulename'] = $cm->name;
                $completions[] = $completion;
            }
        }
        return $completions;
    }

    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_users_completion_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'instance' => new external_value(PARAM_INT, get_string('return_instance', 'local_myddleware')),
                    'section' => new external_value(PARAM_INT, get_string('return_section', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'coursemoduleid' => new external_value(PARAM_INT, get_string('return_coursemoduleid', 'local_myddleware')),
                    'moduletype' => new external_value(PARAM_INT, get_string('return_moduletype', 'local_myddleware')),
                    'modulename' => new external_value(PARAM_TEXT, get_string('return_modulename', 'local_myddleware')),
                    'coursemodulename' => new external_value(PARAM_TEXT, get_string('return_coursemodulename', 'local_myddleware')),
                    'completionstate' => new external_value(PARAM_INT, get_string('return_completionstate', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                ]
            )
        );
    }


    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_users_last_access_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }



    /**
     * This function search the last access for all users and courses.
     * Only access after the $timemodified are returned.
     * @param int $timemodified
     * @param int $id
     * @return an array with the detail of each access (id, userid, access time and courseid).
     */
    public static function get_users_last_access($timemodified, $id) {
        global $DB;
        // Parameter validation.
        $params = self::validate_parameters(
            self::get_users_last_access_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:viewdetails', $context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'la.userid'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." la.id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." la.timeaccess > :timemodified ";
        }

        $sql = "
            SELECT
                la.id,
                la.userid,
                la.courseid,
                la.timeaccess lastaccess
            FROM {user_lastaccess} la
            WHERE
                ".$where."
            ";
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $lastaccess = [];
        if (!empty($rs)) {
            foreach ($rs as $lastaccessrecords) {
                foreach ($lastaccessrecords as $key => $value) {
                    $access[$key] = $value;
                }
                $lastaccess[] = $access;
            }
        }
        return $lastaccess;
    }



    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_users_last_access_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'lastaccess' => new external_value(PARAM_INT, get_string('return_lastaccess', 'local_myddleware')),
                ]
            )
        );
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_courses_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the courses created or modified after after the datime $timemodified.
     * @param int $timemodified
     * @param int $id
     * @return the list of course.
     */
    public static function get_courses_by_date($timemodified, $id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/course/externallib.php");

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_courses_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);

        // Prepare the query condition.
        if (!empty($id)) {
            $where = ' id = :id';
        } else {
            $where = ' timemodified > :timemodified';
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];

        // Select the courses modified after the datime $timemodified. We select them order by timemodified ascending.
        $selectedcourses = $DB->get_records_select('course', $where, $queryparams, ' timemodified ASC ', 'id');
        // Security check to validate the course.
        list($selectedcourses, $warnings) = core_external\util::validate_courses(
            array_keys($selectedcourses), $selectedcourses, true);

        $returnedcourses = [];
        if (!empty($selectedcourses)) {
            // Call the function get_courses for each course to keep the timemodified order.
            foreach ($selectedcourses as $key => $value) {
                // Call the standard API function to return the course detail.
                $coursedetails = core_course_external::get_courses(['ids' => [$value->id]]);
                $returnedcourses[] = $coursedetails[0];
            }
        }
        return $returnedcourses;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_courses_by_date_returns() {
        global $CFG;
        require_once($CFG->dirroot . "/course/externallib.php");
        // We use the same result than the function get_courses.
        return core_course_external::get_courses_returns();
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_groups_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the groups created or modified after after the datime $timemodified.
     * @param int $timemodified
     * @param int $id
     * @return the list of group.
     */
    public static function get_groups_by_date($timemodified, $id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/group/externallib.php");

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_groups_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);

        // Prepare the query condition.
        if (!empty($id)) {
            $where = ' id = :id';
        } else {
            $where = ' timemodified > :timemodified';
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];

        // Select the groups modified after the datime $timemodified. We select them order by timemodified ascending.
        $selectedgroups = $DB->get_records_select('groups', $where, $queryparams, ' timemodified ASC ', '*');

        $returnedgroups = [];
        if (!empty($selectedgroups)) {
            // Call the function get_groups for each group to keep the timemodified order.
            foreach ($selectedgroups as $key => $value) {
                // Call the standard API function to return the group detail.
                $groupdetails = core_group_external::get_groups([$value->id]);
                // Add the time modified to the standard structure.
                $groupdetails[0]['timemodified'] = $value->timemodified;
                $returnedgroups[] = $groupdetails[0];
            }
        }
        return $returnedgroups;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_groups_by_date_returns() {
        global $CFG;
        require_once($CFG->dirroot . "/group/externallib.php");
        // Get the standard structure for groups.
        $groupstandardstructure = core_group_external::get_groups_returns();
        // We add the time modified field into the standard structure.
        $groupstandardstructure->content->keys['timemodified'] = new external_value(
            PARAM_INT, get_string('return_timemodified', 'local_myddleware'));
        return $groupstandardstructure;
    }


    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_group_members_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }


    /**
     * This function search all the group members.
     * Only group members after the $timeadded are returned.
     * @param int $timemodified
     * @param int $id
     * @return an array with the detail of each group members (id, groupid, time added and userid).
     */
    public static function get_group_members_by_date($timemodified, $id) {
        global $DB;
        // Parameter validation.
        $params = self::validate_parameters(
            self::get_users_last_access_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'gm.userid'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." gm.id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." gm.timeadded > :timemodified ";
        }

        $sql = "
            SELECT
                gm.id,
                gm.groupid,
                gm.userid,
                gm.timeadded
            FROM {groups_members} gm
            WHERE
                ".$where."
            ";
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $groupmembers = [];
        if (!empty($rs)) {
            foreach ($rs as $groupmembersrecords) {
                foreach ($groupmembersrecords as $key => $value) {
                    $groupmember[$key] = $value;
                }
                $groupmembers[] = $groupmember;
            }
        }
        return $groupmembers;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_group_members_by_date_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'groupid' => new external_value(PARAM_INT, get_string('return_groupid', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'timeadded' => new external_value(PARAM_INT, get_string('return_timeadded', 'local_myddleware')),
                ]
            )
        );
    }


    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_users_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the users created or modified after after the datime $timemodified.
     * @param int $timemodified
     * @param int $id
     * @return the list of user.
     */
    public static function get_users_by_date($timemodified, $id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/user/externallib.php");

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_users_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:viewdetails', $context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, '{user}.id'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." {user}.deleted = 0 AND {user}.id = :id ";
        } else if (!empty($timemodified)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." {user}.deleted = 0 AND {user}.timemodified > :timemodified ";
        } else {
            return null;
        }

        // Prepare query parameters.
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        // Get users.
        $selectedusers = $DB->get_records_select('user', $where, $queryparams, ' timemodified ASC ', '*');

        // Select the users modified after the datime $timemodified.
        $additionalfields = 'id, timemodified, lastnamephonetic, firstnamephonetic, middlename, alternatename';
        $selectedusers = $DB->get_records_select('user', $where, $queryparams, ' timemodified ASC ', $additionalfields);
        $returnedusers = [];
        if (!empty($selectedusers)) {
            // Call function get_users for each user found.
            foreach ($selectedusers as $user) {
                $userdetails = [];
                $userdetails = core_user_external::get_users([ 'criteria' => [ 'key' => 'id', 'value' => $user->id ]]);
                // Add fields not returned by standard function.
                $userdetails['users'][0]['timemodified'] = $user->timemodified;
                $userdetails['users'][0]['lastnamephonetic'] = $user->lastnamephonetic;
                $userdetails['users'][0]['firstnamephonetic'] = $user->firstnamephonetic;
                $userdetails['users'][0]['middlename'] = $user->middlename;
                $userdetails['users'][0]['alternatename'] = $user->alternatename;
                $returnedusers[] = $userdetails['users'][0];
            }
        }
        return $returnedusers;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_users_by_date_returns() {
        global $CFG;
        require_once($CFG->dirroot . "/user/externallib.php");
        // Add fields not returned by standard function even if exists in the database, table user.
        $timemodified = [
                    'timemodified' => new external_value(
                        PARAM_INT,
                        get_string('param_timemodified', 'local_myddleware'),
                        VALUE_DEFAULT,
                        0,
                        NULL_NOT_ALLOWED
                    ),
                    'lastnamephonetic' => new external_value(
                        PARAM_TEXT,
                        get_string('param_lastnamephonetic', 'local_myddleware'),
                        VALUE_DEFAULT,
                        0
                    ),
                    'firstnamephonetic' => new external_value(
                        PARAM_TEXT,
                        get_string('param_firstnamephonetic', 'local_myddleware'),
                        VALUE_DEFAULT,
                        0
                    ),
                    'middlename' => new external_value(
                        PARAM_TEXT,
                        get_string('param_middlename', 'local_myddleware'),
                        VALUE_DEFAULT,
                        0
                    ),
                    'alternatename' => new external_value(
                        PARAM_TEXT,
                        get_string('param_alternatename', 'local_myddleware'),
                        VALUE_DEFAULT,
                        0
                    ),
                ];
        // We use the same structure than in the function get_users.
        $userfields = core_user_external::user_description($timemodified);
        return new external_multiple_structure($userfields);
    }


    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_users_statistics_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search completion created after the date $timemodified in parameters.
     * @param int $timemodified
     * @param int $id
     * @return array an array with the detail of each grades.
     */
    public static function get_users_statistics_by_date($timemodified, $id) {
        global $DB;
        $returnedusers = [];
        $users = [];

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_user_grades_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:viewdetails', $context);

        // Search every last access record
        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'la.userid'], '');
        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." la.userid = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." la.timeaccess > :timemodified ";
        }
        $sqllastaccess = "SELECT la.userid, la.timeaccess FROM {user_lastaccess} la WHERE ".$where;
        $queryparamslastaccess = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $urserlastaccess = $DB->get_recordset_sql($sqllastaccess, $queryparamslastaccess);
        if (!empty($urserlastaccess)) {
            foreach ($urserlastaccess as $urserla) {
                // Change the result only if the user isn't already in the array.
                // Or if its reference date is greater than the one in the results.
                if (
                        !array_key_exists($urserla->userid, $users)
                     || (
                            array_key_exists($urserla->userid, $users)
                        && $users[$urserla->userid] < $urserla->timeaccess
                    )
                ) {
                    $users[$urserla->userid] = $urserla->timeaccess;
                }
            }
        }
        // Search every user log.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'log.userid'], '');
        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." log.userid = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." log.timecreated > :timemodified ";
        }
        $sqluserlog = "SELECT log.userid, log.timecreated FROM {logstore_standard_log} log
                        WHERE
                                action IN ('viewed','loggedin')
                            AND ".$where;
        $queryparamsuserlog = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $urserlog = $DB->get_recordset_sql($sqluserlog, $queryparamsuserlog);
        if (!empty($urserlog)) {
            foreach ($urserlog as $urserlo) {
                // Change the result only if the user isn't already in the array.
                // Or if its reference date is greater than the one in the results.
                if (
                        !array_key_exists($urserlo->userid, $users)
                     || (
                            array_key_exists($urserlo->userid, $users)
                        && $users[$urserlo->userid] < $urserlo->timecreated
                    )
                ) {
                    $users[$urserlo->userid] = $urserlo->timecreated;
                }
            }
        }
        // Search every quizz attempts.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'qa.userid'], '');
        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." qa.userid = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." qa.timemodified > :timemodified ";
        }
        $sqlquizattempt = "SELECT qa.userid, qa.timemodified FROM {quiz_attempts} qa WHERE ".$where;
        $queryparamsquizattemp = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $urserquizattemp = $DB->get_recordset_sql($sqlquizattempt, $queryparamsquizattemp);
        if (!empty($urserquizattemp)) {
            foreach ($urserquizattemp as $urserqa) {
                // Change the result only if the user isn't already in the array.
                // Or if its reference date is greater than the one in the results.
                if (
                        !array_key_exists($urserqa->userid, $users)
                     || (
                            array_key_exists($urserqa->userid, $users)
                        && $users[$urserqa->userid] < $urserqa->timemodified
                    )
                ) {
                    $users[$urserqa->userid] = $urserqa->timemodified;
                }
            }
        }

        if (!empty($users)) {
            // Add statistics for each users.
             // Get the subquery to filter only records linked to the tenant of the current user.
            $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
                [true, true, '{user}.id'], '');
            // Prepare the query condition.
            $where = (!empty($wheretenant) ? $wheretenant : "")." {user}.deleted = 0 AND {user}.id = :id ";

            foreach ($users as $userid => $usertimemodified) {
                // Get the detail of each user.
                $queryparams = ['id' => $userid];
                $userres = $DB->get_records_select('user', $where, $queryparams, '', '*');
                if (!empty($userres)) {
                    foreach ($userres as $key => $user) {
                        $userid = $user->id;
                        $userstat['id'] = $userid;
                        $userstat['username'] = $user->username;
                        $userstat['email'] = $user->email;
                        $userstat['lastname'] = $user->lastname;
                        $userstat['timemodified'] = $usertimemodified;
                        $userstat['lastaccess'] = $user->lastaccess;
                        // Get the log stats.
                        $totallogins = $DB->get_field('logstore_standard_log',
                            'count(id)', ['action' => 'loggedin', 'userid' => $userid]);
                        $pagesviewed = $DB->get_field('logstore_standard_log',
                            'count(distinct contextinstanceid)', ['action' => 'viewed', 'userid' => $userid]);
                        $userstat['totallogins'] = $totallogins ? $totallogins : 0;
                        $userstat['pagesviewed'] = $pagesviewed ? $pagesviewed : 0;
                        // Get the timefinish for quizz from the database.
                        $sql = "
                            SELECT qa.timefinish
                            FROM {quiz_attempts} AS qa
                            WHERE qa.userid = {$userid} AND qa.state = 'finished'
                            ORDER BY qa.timefinish DESC
                            LIMIT 1";
                        $lastquizattempt = $DB->get_field_sql($sql);
                        $userstat['lastquizattempt'] = $lastquizattempt ? $lastquizattempt : 0;
                        // Build the results.
                        $returnedusers[] = $userstat;
                    }
                }
            }
        }
        return $returnedusers;
    }

     /**
      * Returns description of method result value.
      * @return external_description.
      */
    public static function get_users_statistics_by_date_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'username' => new external_value(PARAM_TEXT, get_string('return_username', 'local_myddleware')),
                    'email' => new external_value(PARAM_TEXT, get_string('return_email', 'local_myddleware')),
                    'lastname' => new external_value(PARAM_TEXT, get_string('return_lastname', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                    'totallogins' => new external_value(PARAM_INT, get_string('return_totallogins', 'local_myddleware')),
                    'pagesviewed' => new external_value(PARAM_INT, get_string('return_pagesviewed', 'local_myddleware')),
                    'lastquizattempt' => new external_value(PARAM_INT, get_string('return_lastquizattempt', 'local_myddleware')),
                    'lastaccess' => new external_value(PARAM_INT, get_string('return_lastaccess', 'local_myddleware')),
                ]
            )
        );
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_enrolments_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the enrolments modified after after the datime $timemodified.
     * @param int $timemodified
     * @param int $id
     * @return the list of user enrolments.
     */
    public static function get_enrolments_by_date($timemodified, $id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/course/externallib.php");

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_enrolments_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Prepare the query condition.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('enrol/manual:manage', $context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'userid'], '');

        // Prepare the query condition with the tenant.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." timemodified > :timemodified ";
        }

        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $returnenrolments = [];
        // Select enrolment modified after the date in input.
        $userenrolments = $DB->get_records_select('user_enrolments', $where, $queryparams, ' timemodified ASC ', '*');
        if (!empty($userenrolments)) {
            foreach ($userenrolments as $userenrolment) {
                $instance = [];
                // Get the enrolement detail (course, role and method).
                $instance = $DB->get_record('enrol', ['id' => $userenrolment->enrolid], '*', MUST_EXIST);
                // Prepare result.
                if (!empty($instance)) {
                    // Security check to validate the course.
                    list($courses, $warnings) = core_external\util::validate_courses([$instance->courseid], [], true);
                    if (empty($courses)) {
                        continue;
                    }
                    $userenroldata = [
                        'id' => $userenrolment->id,
                        'userid' => $userenrolment->userid,
                        'courseid' => $instance->courseid,
                        'roleid' => $instance->roleid,
                        'status' => $userenrolment->status,
                        'enrol' => $instance->enrol,
                        'timestart' => $userenrolment->timestart,
                        'timeend' => $userenrolment->timeend,
                        'timecreated' => $userenrolment->timecreated,
                        'timemodified' => $userenrolment->timemodified,
                    ];
                    $returnenrolments[] = $userenroldata;
                }
            }
        }

        return $returnenrolments;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_enrolments_by_date_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'roleid' => new external_value(PARAM_INT, get_string('return_roleid', 'local_myddleware')),
                    'status' => new external_value(PARAM_TEXT, get_string('return_status', 'local_myddleware')),
                    'enrol' => new external_value(PARAM_TEXT, get_string('return_enrol', 'local_myddleware')),
                    'timestart' => new external_value(PARAM_INT, get_string('return_timestart', 'local_myddleware')),
                    'timeend' => new external_value(PARAM_INT, get_string('return_timeend', 'local_myddleware')),
                    'timecreated' => new external_value(PARAM_INT, get_string('return_timecreated', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                ]
            )
        );
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function search_enrolment_parameters() {
        return new external_function_parameters(
            [
                'userid' => new external_value(PARAM_INT, get_string('userid', 'local_myddleware'), VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, get_string('courseid', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the enrolments using role_id, course_id and user_id
     * @param int $userid
     * @param int $courseid
     * @return array the list of user enrolments.
     */
    public static function search_enrolment($userid, $courseid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/course/externallib.php");

        // Parameter validation.
        $params = self::validate_parameters(
            self::search_enrolment_parameters(),
            ['userid' => $userid, 'courseid' => $courseid]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('enrol/manual:manage', $context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'ue.userid'], '');

        // Prepare the query condition with the tenant.
        $where = (!empty($wheretenant) ? $wheretenant : "")." ue.userid = :userid AND en.courseid = :courseid ";

        // Get the user enrolment id.
        $sql = "
            SELECT ue.id
            FROM {enrol} en
                INNER JOIN {user_enrolments} ue
                    ON en.id = ue.enrolid
            WHERE ".$where;
        $queryparams = [
                            'userid' => $params['userid'],
                            'courseid' => $params['courseid'],
                        ];
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        // If a result is found, we use the method get_enrolments_by_date to return the result.
        if (!empty($rs)) {
            foreach ($rs as $enrol) {
                foreach ($enrol as $key => $value) {
                    if (
                            $key == 'id'
                        && !empty($value)
                    ) {
                        return self::get_enrolments_by_date(0, $value);
                    }
                }
            }
        }
        return [];
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function search_enrolment_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'roleid' => new external_value(PARAM_INT, get_string('return_roleid', 'local_myddleware')),
                    'status' => new external_value(PARAM_TEXT, get_string('return_status', 'local_myddleware')),
                    'enrol' => new external_value(PARAM_TEXT, get_string('return_enrol', 'local_myddleware')),
                    'timestart' => new external_value(PARAM_INT, get_string('return_timestart', 'local_myddleware')),
                    'timeend' => new external_value(PARAM_INT, get_string('return_timeend', 'local_myddleware')),
                    'timecreated' => new external_value(PARAM_INT, get_string('return_timecreated', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                ]
            )
        );
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_course_completion_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search completion created after the date $timemodified in parameters.
     * @param int $timemodified
     * @param int $id
     * @return an array with the detail of each completion (id,userid,completionstate,timemodified,moduletype,instance,courseid).
     */
    public static function get_course_completion_by_date($timemodified, $id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/course/externallib.php");

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_course_completion_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'userid'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." timecompleted > :timemodified  OR reaggregate > 0 ";
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];
        $returncompletions = [];
        // Select enrolment modified after the date in input.
        $selectedcompletions = $DB->get_records_select('course_completions', $where, $queryparams, ' timecompleted ASC ', '*');

        // Security check to validate the courses.
        $courseids = array_unique(array_column($selectedcompletions, 'course'));
        list($courses, $warnings) = core_external\util::validate_courses($courseids, [], true);

        if (!empty($selectedcompletions)) {
            // Date ref management : date ref is usually the timecompleted.
            // But if reaggregate is not empty we have to keep the smaller value of this field.
            $daterefoverride = -1;
            // Reaggregate could be set.
            // In this case, the timecompleted will be updated with the time in reaggregate field the next time the cron job runs.
            // So we have to keep reaggregate as the reference date.
            // Because we have to read the completion after the next cron job runs.
            foreach ($selectedcompletions as $selectedcompletion) {
                // Security check to validate the courses.
                if (empty($courses[$selectedcompletion->course])) {
                    continue;
                }
                // Keep the smaller value of reaggregateif it exists.
                if ($selectedcompletion->reaggregate > 0 &&
                   ($selectedcompletion->reaggregate < $daterefoverride || $daterefoverride == -1)) {
                    $daterefoverride = $selectedcompletion->reaggregate;
                }
            }

            // Prepare result.
            foreach ($selectedcompletions as $selectedcompletion) {
                // Security check to validate the courses.
                if (empty($courses[$selectedcompletion->course])) {
                    continue;
                }
                // We keep only completion with timecompleted not null.
                // Ssome completion could have reaggregate not null and timecompleted null.
                if (empty($selectedcompletion->timecompleted)) {
                    continue;
                }
                // Set date_ref_override if there is at least one reaggregate value (-1 second because we use > in Myddleware).
                $completiondata = [
                    'id' => $selectedcompletion->id,
                    'userid' => $selectedcompletion->userid,
                    'courseid' => $selectedcompletion->course,
                    'timeenrolled' => $selectedcompletion->timeenrolled,
                    'timestarted' => $selectedcompletion->timestarted,
                    'timecompleted' => $selectedcompletion->timecompleted,
                    'date_ref_override' => ($daterefoverride != -1 ? $daterefoverride - 1 : 0),
                ];
                // Prepare result.
                $returncompletions[] = $completiondata;
            }
        }
        return $returncompletions;
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function get_course_completion_by_date_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'courseid' => new external_value(
                        PARAM_INT, get_string('return_courseid', 'local_myddleware'), VALUE_DEFAULT, 0),
                    'timeenrolled' => new external_value(PARAM_INT, get_string('return_timeenrolled', 'local_myddleware')),
                    'timestarted' => new external_value(PARAM_INT, get_string('return_timestarted', 'local_myddleware')),
                    'timecompleted' => new external_value(PARAM_INT, get_string('return_timecompleted', 'local_myddleware')),
                    'date_ref_override' => new external_value(
                        PARAM_INT, get_string('return_date_ref_override', 'local_myddleware')),
                ]
            )
        );
    }


     /**
      * Returns description of method parameters.
      * @return external_function_parameters.
      */
    public static function get_user_compentencies_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the user competencies created or modified after after the datime $timemodified.
     * @param int $timemodified
     * @param int $id
     * @return the list of user compentencies.
     */
    public static function get_user_compentencies_by_date($timemodified, $id) {
        global $DB;
        // Parameter validation.
        $params = self::validate_parameters(
            self::get_user_compentencies_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/competency:usercompetencyview', $context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'userid'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." timemodified > :timemodified ";
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];

        // Select the user compencies modified after the datime $timemodified. We select them order by timemodified ascending.
        $selectedusercompetencies = $DB->get_records_select('competency_usercomp', $where, $queryparams, ' timemodified ASC ');

        // Prepare result.
        $returnedusercompetencies = [];
        if (!empty($selectedusercompetencies)) {
            foreach ($selectedusercompetencies as $selectedusercompetency) {
                // Add competency header data to the user compentency.
                $competency = user_competency::get_competency_by_usercompetencyid($selectedusercompetency->id);
                $selectedusercompetency->competency_shortname = $competency->get('shortname');
                $selectedusercompetency->competency_description = $competency->get('description');
                $selectedusercompetency->competency_descriptionformat = $competency->get('descriptionformat');
                $selectedusercompetency->competency_idnumber = $competency->get('idnumber');
                $selectedusercompetency->competency_competencyframeworkid = $competency->get('competencyframeworkid');
                $selectedusercompetency->competency_parentid = $competency->get('parentid');
                $selectedusercompetency->competency_path = $competency->get('path');
                $selectedusercompetency->competency_sortorder = $competency->get('sortorder');
                $selectedusercompetency->competency_ruletype = $competency->get('ruletype');
                $selectedusercompetency->competency_ruleoutcome = $competency->get('ruleoutcome');
                $selectedusercompetency->competency_ruleconfig = $competency->get('ruleconfig');
                $selectedusercompetency->competency_scaleid = $competency->get('scaleid');
                $selectedusercompetency->competency_scaleconfiguration = $competency->get('scaleconfiguration');
                $selectedusercompetency->competency_timecreated = $competency->get('timecreated');
                $selectedusercompetency->competency_timemodified = $competency->get('timemodified');
                $selectedusercompetency->competency_usermodified = $competency->get('usermodified');
                $returnedusercompetencies[] = $selectedusercompetency;
            }
        }
        return $returnedusercompetencies;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_user_compentencies_by_date_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'competencyid' => new external_value(PARAM_INT, get_string('return_competencyid', 'local_myddleware')),
                    'status' => new external_value(PARAM_INT, get_string('return_status', 'local_myddleware')),
                    'reviewerid' => new external_value(PARAM_INT, get_string('return_reviewerid', 'local_myddleware')),
                    'proficiency' => new external_value(PARAM_INT, get_string('return_proficiency', 'local_myddleware')),
                    'grade' => new external_value(PARAM_INT, get_string('return_grade', 'local_myddleware')),
                    'timecreated' => new external_value(PARAM_INT, get_string('return_timecreated', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                    'usermodified' => new external_value(PARAM_INT, get_string('return_usermodified', 'local_myddleware')),
                    'competency_shortname' => new external_value(
                        PARAM_TEXT, get_string('return_competency_shortname', 'local_myddleware')),
                    'competency_description' => new external_value(
                        PARAM_CLEANHTML, get_string('return_competency_description', 'local_myddleware')),
                    'competency_descriptionformat' => new external_value(
                        PARAM_INT, get_string('return_competency_descriptionformat', 'local_myddleware')),
                    'competency_idnumber' => new external_value(
                        PARAM_TEXT, get_string('return_competency_idnumber', 'local_myddleware')),
                    'competency_competencyframeworkid' => new external_value(
                        PARAM_INT, get_string('return_competency_competencyframeworkid', 'local_myddleware')),
                    'competency_parentid' => new external_value(
                        PARAM_INT, get_string('return_competency_parentid', 'local_myddleware')),
                    'competency_path' => new external_value(
                        PARAM_TEXT, get_string('return_competency_path', 'local_myddleware')),
                    'competency_sortorder' => new external_value(
                        PARAM_INT, get_string('return_competency_sortorder', 'local_myddleware')),
                    'competency_ruletype' => new external_value(
                        PARAM_TEXT, get_string('return_competency_ruletype', 'local_myddleware')),
                    'competency_ruleoutcome' => new external_value(
                        PARAM_INT, get_string('return_competency_ruleoutcome', 'local_myddleware')),
                    'competency_ruleconfig' => new external_value(
                        PARAM_TEXT, get_string('return_competency_ruleconfig', 'local_myddleware')),
                    'competency_scaleid' => new external_value(
                        PARAM_INT, get_string('return_competency_scaleid', 'local_myddleware')),
                    'competency_scaleconfiguration' => new external_value(
                        PARAM_TEXT, get_string('return_competency_scaleconfiguration', 'local_myddleware')),
                    'competency_timecreated' => new external_value(
                        PARAM_INT, get_string('return_competency_timecreated', 'local_myddleware')),
                    'competency_timemodified' => new external_value(
                        PARAM_INT, get_string('return_competency_timemodified', 'local_myddleware')),
                    'competency_usermodified' => new external_value(
                        PARAM_INT, get_string('return_competency_usermodified', 'local_myddleware')),
                ]
            )
        );
    }

     /**
      * Returns description of method parameters.
      * @return external_function_parameters.
      */
    public static function get_competency_module_completion_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search the user competencies created or modified after after the datime $timemodified.
     * @param int $timemodified
     * @param int $id
     * @return the list of competency module completions
     */
    public static function get_competency_module_completion_by_date($timemodified, $id) {
        global $DB;
        // Parameter validation.
        $params = self::validate_parameters(
            self::get_user_compentencies_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/competency:usercompetencyview', $context);

        // Prepare the query condition.
        if (!empty($id)) {
            $where = ' competency_modulecomp.id = :id';
        } else {
            $where = ' competency_modulecomp.timemodified > :timemodified';
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];

        $sql = "
            SELECT
                competency_modulecomp.id,
                competency_modulecomp.cmid,
                competency_modulecomp.timecreated,
                competency_modulecomp.timemodified,
                competency_modulecomp.usermodified,
                competency_modulecomp.sortorder,
                competency_modulecomp.competencyid,
                competency_modulecomp.ruleoutcome,
                course.id courseid,
                modules.name as modulename,
                course.fullname as coursemodulename
            FROM {competency_modulecomp} competency_modulecomp
                LEFT OUTER JOIN {course_modules} course_modules
                    ON competency_modulecomp.cmid = course_modules.id
                    LEFT OUTER JOIN {modules} modules
                        ON course_modules.module = modules.id
                    LEFT OUTER JOIN {course} course
                        ON course_modules.course = course.id
            WHERE
                ".$where."
            ";

        // Select the user compencies modified after the datime $timemodified. We select them order by timemodified ascending.
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $competencymodulecompletions = [];
        if (!empty($rs)) {
            foreach ($rs as $competencymodulecompletionrecords) {
                $courseerror = false;
                foreach ($competencymodulecompletionrecords as $key => $value) {
                    // Validate course.
                    if ($key == 'courseid') {
                        list($courses, $warnings) = core_external\util::validate_courses([$value], [], true);
                        if (empty($courses[$value])) {
                            $courseerror = true;
                            break;
                        }
                    }
                    $competencymodulecompletion[$key] = $value;
                }
                // If error we go to the next record.
                if ($courseerror) {
                    continue;
                }
                $competencymodulecompletions[] = $competencymodulecompletion;
            }
        }
        return $competencymodulecompletions;
    }


    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_competency_module_completion_by_date_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'cmid' => new external_value(PARAM_INT, get_string('return_coursemoduleid', 'local_myddleware')),
                    'timecreated' => new external_value(PARAM_INT, get_string('return_timecreated', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                    'usermodified' => new external_value(PARAM_INT, get_string('return_usermodified', 'local_myddleware')),
                    'sortorder' => new external_value(PARAM_INT, get_string('return_sortorder', 'local_myddleware')),
                    'competencyid' => new external_value(PARAM_INT, get_string('return_competencyid', 'local_myddleware')),
                    'ruleoutcome' => new external_value(PARAM_INT, get_string('return_ruleoutcome', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'modulename' => new external_value(PARAM_TEXT, get_string('return_modulename', 'local_myddleware')),
                    'coursemodulename' => new external_value(PARAM_TEXT, get_string('return_coursemodulename', 'local_myddleware')),
                ]
            )
        );
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_user_grades_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search completion created after the date $timemodified in parameters.
     * @param int $timemodified
     * @param int $id
     * @return an array with the detail of each grades.
     */
    public static function get_user_grades($timemodified, $id) {
        global $DB;

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_user_grades_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get the subquery to filter only records linked to the tenant of the current user.
        $wheretenant = component_class_callback('tool_tenant\\tenancy', 'get_users_subquery',
            [true, true, 'grd.userid'], '');

        // Prepare the query condition.
        if (!empty($id)) {
            $where = (!empty($wheretenant) ? $wheretenant : "")." grd.id = :id ";
        } else {
            $where = (!empty($wheretenant) ? $wheretenant : "")." grd.timemodified > :timemodified ";
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];

        // Retrieve token list (including linked users firstname/lastname and linked services name).
        $sql = "
            SELECT
                grd.id,
                grd.itemid,
                grd.userid,
                grd.rawgrade,
                grd.rawgrademax,
                grd.rawgrademin,
                grd.rawscaleid,
                grd.usermodified,
                grd.finalgrade,
                grd.hidden,
                grd.locked,
                grd.locktime,
                grd.exported,
                grd.overridden,
                grd.excluded,
                grd.feedback,
                grd.feedbackformat,
                grd.information,
                grd.informationformat,
                grd.timecreated,
                grd.timemodified,
                grd.aggregationstatus,
                grd.aggregationweight,
                itm.courseid,
                itm.itemname,
                itm.itemtype,
                itm.itemmodule,
                itm.iteminstance,
                crs.fullname course_fullname,
                crs.shortname course_shortname
            FROM {grade_grades} grd
            INNER JOIN {grade_items} itm
                ON grd.itemid = itm.id
            LEFT OUTER JOIN {course} crs
                ON itm.courseid = crs.id
            WHERE
                ".$where."
            ORDER BY grd.timemodified ASC, grd.id ASC
                ";
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $grades = [];
        if (!empty($rs)) {
            foreach ($rs as $graderecords) {
                foreach ($graderecords as $key => $value) {
                    $grade[$key] = $value;
                }
                $grades[] = $grade;
            }
        }
        return $grades;
    }

     /**
      * Returns description of method result value.
      * @return external_description.
      */
    public static function get_user_grades_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'itemid' => new external_value(PARAM_INT, get_string('return_itemid', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'rawgrade' => new external_value(PARAM_FLOAT, get_string('return_rawgrade', 'local_myddleware')),
                    'rawgrademax' => new external_value(PARAM_FLOAT, get_string('return_rawgrademax', 'local_myddleware')),
                    'rawgrademin' => new external_value(PARAM_FLOAT, get_string('return_rawgrademin', 'local_myddleware')),
                    'rawscaleid' => new external_value(PARAM_INT, get_string('return_rawscaleid', 'local_myddleware')),
                    'usermodified' => new external_value(PARAM_INT, get_string('return_usermodified', 'local_myddleware')),
                    'finalgrade' => new external_value(PARAM_FLOAT, get_string('return_finalgrade', 'local_myddleware')),
                    'hidden' => new external_value(PARAM_INT, get_string('return_hidden', 'local_myddleware')),
                    'locked' => new external_value(PARAM_INT, get_string('return_locked', 'local_myddleware')),
                    'locktime' => new external_value(PARAM_INT, get_string('return_locktime', 'local_myddleware')),
                    'exported' => new external_value(PARAM_INT, get_string('return_exported', 'local_myddleware')),
                    'overridden' => new external_value(PARAM_INT, get_string('return_overridden', 'local_myddleware')),
                    'excluded' => new external_value(PARAM_INT, get_string('return_excluded', 'local_myddleware')),
                    'feedback' => new external_value(PARAM_TEXT, get_string('return_feedback', 'local_myddleware')),
                    'feedbackformat' => new external_value(PARAM_INT, get_string('return_feedbackformat', 'local_myddleware')),
                    'information' => new external_value(PARAM_TEXT, get_string('return_information', 'local_myddleware')),
                    'informationformat' => new external_value(
                        PARAM_INT, get_string('return_informationformat', 'local_myddleware')),
                    'timecreated' => new external_value(PARAM_INT, get_string('return_timecreated', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                    'aggregationstatus' => new external_value(
                        PARAM_TEXT, get_string('return_aggregationstatus', 'local_myddleware')),
                    'aggregationweight' => new external_value(
                        PARAM_FLOAT, get_string('return_aggregationweight', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'itemname' => new external_value(PARAM_TEXT, get_string('return_itemname', 'local_myddleware')),
                    'itemtype' => new external_value(PARAM_TEXT, get_string('return_itemtype', 'local_myddleware')),
                    'itemmodule' => new external_value(PARAM_TEXT, get_string('return_itemmodule', 'local_myddleware')),
                    'iteminstance' => new external_value(PARAM_TEXT, get_string('return_iteminstance', 'local_myddleware')),
                    'course_fullname' => new external_value(PARAM_TEXT, get_string('return_fullname', 'local_myddleware')),
                    'course_shortname' => new external_value(PARAM_TEXT, get_string('return_shortname', 'local_myddleware')),
                ]
            )
        );
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_quiz_attempts_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function search completion created after the date $timemodified in parameters.
     * @param int $timemodified
     * @param int $id
     * @return an array with the detail of each quizzes.
     */
    public static function get_quiz_attempts($timemodified, $id) {
        global $USER, $DB;

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_users_completion_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );

        // Context validation.
        $context = context_user::instance($USER->id);
        self::validate_context($context);

        require_capability('moodle/user:viewdetails', $context);

        // Prepare the query condition.
        if (!empty($id)) {
            $where = ' att.id = :id';
        } else {
            $where = ' att.timemodified > :timemodified';
        }
        $queryparams = [
                            'id' => (!empty($params['id']) ? $params['id'] : ''),
                            'timemodified' => (!empty($params['time_modified']) ? $params['time_modified'] : ''),
                        ];

        // Retrieve token list (including linked users firstname/lastname and linked services name).
        $sql = "
            SELECT
                att.id,
                att.userid,
                att.attempt,
                att.state,
                att.timefinish,
                att.timemodified,
                att.timemodifiedoffline,
                att.timecheckstate,
                att.sumgrades,
                att.gradednotificationsenttime,
                att.uniqueid,
                qz.id as quizid,
                qz.course as courseid,
                qz.name,
                grd.grade,
                cm.id as cmid
            FROM {quiz_attempts} att
            INNER JOIN {quiz} qz
                ON qz.id = att.quiz
            LEFT JOIN {quiz_grades} grd
                ON grd.quiz = att.quiz
                AND grd.userid = att.userid
            INNER JOIN {course_modules} cm
                ON cm.instance = qz.id
                INNER JOIN {modules} md
                    ON md.id = cm.module
                    AND md.name = 'quiz'
            WHERE
                ".$where."
            ORDER BY att.timemodified ASC, att.id ASC
                ";
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $quizattempts = [];
        if (!empty($rs)) {
            foreach ($rs as $attemps) {
                foreach ($attemps as $key => $value) {
                    $attemp[$key] = $value;
                }
                $quizattempts[] = $attemp;
            }
        }
        return $quizattempts;
    }

     /**
      * Returns description of method result value.
      * @return external_description.
      */
    public static function get_quiz_attempts_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'attempt' => new external_value(PARAM_INT, get_string('return_attempt', 'local_myddleware')),
                    'state' => new external_value(PARAM_TEXT, get_string('return_state', 'local_myddleware')),
                    'timefinish' => new external_value(PARAM_INT, get_string('return_timefinish', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                    'timemodifiedoffline' => new external_value(
                        PARAM_INT, get_string('return_timemodifiedoffline', 'local_myddleware')),
                    'timecheckstate' => new external_value(PARAM_INT, get_string('return_timecheckstate', 'local_myddleware')),
                    'sumgrades' => new external_value(PARAM_FLOAT, get_string('return_sumgrades', 'local_myddleware')),
                    'gradednotificationsenttime' => new external_value(
                        PARAM_INT, get_string('return_gradednotificationsenttime', 'local_myddleware')),
                    'uniqueid' => new external_value(PARAM_INT, get_string('return_uniqueid', 'local_myddleware')),
                    'quizid' => new external_value(PARAM_INT, get_string('return_quizid', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'name' => new external_value(PARAM_TEXT, get_string('return_name', 'local_myddleware')),
                    'grade' => new external_value(PARAM_FLOAT, get_string('return_grade', 'local_myddleware')),
                    'cmid' => new external_value(PARAM_INT, get_string('return_cmid', 'local_myddleware')),
                ]
            )
        );
    }



    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_course_completion_percentage_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'userid_courseid' => new external_value(PARAM_TEXT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * This function calculates the completion percentage for course completions.
     * @param int $timemodified
     * @param int $userid_courseid
     * @return array with completion percentage details
     */
    public static function get_course_completion_percentage($timemodified, $userid_courseid) {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $returncompletions = [];

        $id = 0;
        // Parameter validation.
        $params = self::validate_parameters(
            self::get_course_completion_percentage_parameters(),
            ['time_modified' => $timemodified, 'userid_courseid' => $userid_courseid]
        );

        // Context validation.
        $context = context_system::instance();
        self::validate_context($context);

        // Get the last module completion id for the user/course ids.
        if (!empty($params['userid_courseid'])) {
            $sql = "
                SELECT
                    cmc.id,
                    cmc.userid,
                    cmc.completionstate,
                    cmc.timemodified,
                    cm.id coursemoduleid,
                    cm.module moduletype,
                    cm.instance,
                    cm.section,
                    cm.course courseid
                FROM {course_modules_completion} cmc
                INNER JOIN {course_modules} cm
                    ON cm.id = cmc.coursemoduleid
                WHERE
                        cmc.userid = :userid
                    AND cm.course = :courseid
                ORDER BY timemodified DESC
                LIMIT 1
                ";
            // Get user from id  (id format <user_id>_<course_id>.
            $ids = explode('_', $params['userid_courseid']);
            $queryparams = [
                                 'userid' => $ids[0],
                                 'courseid' => $ids[1],
                            ];
            $rs = $DB->get_recordset_sql($sql, $queryparams);
            if (!empty($rs)) {
                // Get the id of the completion found.
                foreach ($rs as $value) {
                    $id = current($value);
                }
            }
        }
        // Tenant filter and course validation are done in this function.
        $selectedcompletions = self::get_users_completion($timemodified, $id);

        if (!empty($selectedcompletions)) {
            // Remove duplicate completion with key user/course (keep the newest one).
            // In case of several modules have been completed by the same user in the same course.
            $selectedcompletionsclean = [];
            foreach ($selectedcompletions as $key => $selectedcompletion) {
                $key = $selectedcompletion['userid'].'_'.$selectedcompletion['courseid'];
                if (!isset($selectedcompletionsclean[$key]) ||
                    $selectedcompletion['timemodified'] > $selectedcompletionsclean[$key]['timemodified']) {
                    $selectedcompletionsclean[$key] = $selectedcompletion;
                }
            }
            // Should never happen.
            if (empty($selectedcompletionsclean)) {
                return [];
            }

            // Process each completion record and calculate percentage.
            foreach ($selectedcompletionsclean as $selectedcompletion) {
                // Calculate completion percentage for this user/course combination.
                $percentage = 0;
                $completedactivities = 0;
                $totalactivities = 0;
                $overallstatus = 'Unknown';
                $error = '';
                try {
                    // Get the course object.
                    $course = $DB->get_record('course', ['id' => $selectedcompletion['courseid']], '*', MUST_EXIST);
                    // Check if completion is enabled for this course.
                    $completion = new completion_info($course);
                    if ($completion->is_enabled()) {
                        // Get course completion status.
                        $iscomplete = $completion->is_course_complete($selectedcompletion['userid']);
                        $overallstatus = $iscomplete ? 'Complete' : 'Incomplete';

                        // Get all activities with completion tracking.
                        $modinfo = get_fast_modinfo($course, $selectedcompletion['userid']);

                        foreach ($modinfo->get_cms() as $cm) {
                            // Only count activities that have completion tracking enabled.
                            if ($cm->completion != COMPLETION_TRACKING_NONE) {
                                $totalactivities++;
                                // Get completion data for this activity.
                                $completiondata = $completion->get_data($cm, false, $selectedcompletion['userid']);
                                // Check if activity is completed.
                                if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                                    $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                                    $completedactivities++;
                                }
                            }
                        }
                        // Calculate percentage.
                        if ($totalactivities > 0) {
                            $percentage = round(($completedactivities / $totalactivities) * 100, 2);
                        }
                        // If no activities with completion tracking, try course-level completion criteria.
                        if ($totalactivities == 0) {
                            $criteria = completion_criteria::fetch_all(['course' => $selectedcompletion['courseid']]);
                            $totalcriteria = count($criteria);
                            $completedcriteria = 0;
                            foreach ($criteria as $criterion) {
                                $completioncriterion = $criterion->get_completion($selectedcompletion['userid']);
                                if ($completioncriterion && $completioncriterion->is_complete()) {
                                    $completedcriteria++;
                                }
                            }
                            $totalactivities = $totalcriteria;
                            $completedactivities = $completedcriteria;

                            if ($totalcriteria > 0) {
                                $percentage = round(($completedcriteria / $totalcriteria) * 100, 2);
                            }
                        }
                    } else {
                        $error = 'Completion tracking not enabled';
                    }
                } catch (Exception $e) {
                    $error = 'Exception: ' . $e->getMessage();
                }

                // Prepare result with same structure as course_completion_by_date but with percentage.
                $completiondata = [
                    'id' => $selectedcompletion['userid'].'_'.$selectedcompletion['courseid'],
                    'userid' => $selectedcompletion['userid'],
                    'courseid' => $selectedcompletion['courseid'],
                    'percentage' => $percentage,
                    'completed_activities' => $completedactivities,
                    'total_activities' => $totalactivities,
                    'overall_status' => $overallstatus,
                    'timemodified' => $selectedcompletion['timemodified'],
                    'error' => $error,
                ];
                $returncompletions[] = $completiondata;
            }
        }
        return $returncompletions;
    }

    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_course_completion_percentage_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_TEXT, get_string('return_id', 'local_myddleware')),
                    'userid' => new external_value(PARAM_INT, get_string('return_userid', 'local_myddleware')),
                    'courseid' => new external_value(PARAM_INT, get_string('return_courseid', 'local_myddleware')),
                    'timemodified' => new external_value(PARAM_INT, get_string('return_timemodified', 'local_myddleware')),
                    'percentage' => new external_value(PARAM_FLOAT, get_string('return_percentage', 'local_myddleware')),
                    'completed_activities' => new external_value(
                        PARAM_INT, get_string('return_completedactivities', 'local_myddleware')),
                    'total_activities' => new external_value(PARAM_INT, get_string('return_totalactivities', 'local_myddleware')),
                    'overall_status' => new external_value(PARAM_TEXT, get_string('return_overallstatus', 'local_myddleware')),
                    'error' => new external_value(PARAM_TEXT, 'Error message if any'),
                ]
            )
        );
    }

    // =========================================================================
    // Method: get_course_completion_percentage_by_country
    // Custom method to filter completion percentage by user country profile field.
    // Added by JAA patch 2026-03-23.
    // =========================================================================

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_course_completion_percentage_by_country_parameters() {
        return new external_function_parameters(
            [
                "time_modified" => new external_value(
                    PARAM_INT, get_string("param_timemodified", "local_myddleware"), VALUE_DEFAULT, 0),
                "country_filter" => new external_value(
                    PARAM_TEXT, "Country profile field shortname (e.g. arg, mex, col)", VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Calculates completion percentage for course completions,
     * filtered by a user custom profile field (country).
     * @param int $timemodified
     * @param string $country_filter
     * @return array with completion percentage details
     */
    public static function get_course_completion_percentage_by_country($timemodified, $country_filter) {
        global $DB, $CFG;
        require_once($CFG->libdir . "/completionlib.php");
        $returncompletions = [];

        $params = self::validate_parameters(
            self::get_course_completion_percentage_by_country_parameters(),
            ["time_modified" => $timemodified, "country_filter" => $country_filter]
        );

        $context = context_system::instance();
        self::validate_context($context);

        $sql = "
            SELECT
                cmc.id,
                cmc.userid,
                cmc.completionstate,
                cmc.timemodified,
                cm.id coursemoduleid,
                cm.module moduletype,
                cm.instance,
                cm.section,
                cm.course courseid
            FROM {course_modules_completion} cmc
            INNER JOIN {course_modules} cm
                ON cm.id = cmc.coursemoduleid
            INNER JOIN {user_info_data} uid
                ON uid.userid = cmc.userid
            INNER JOIN {user_info_field} uif
                ON uif.id = uid.fieldid
            WHERE
                    cmc.timemodified > :timemodified
                AND uif.shortname = :country_filter
                AND uid.data = 1
            ORDER BY cmc.timemodified ASC
        ";
        $queryparams = [
            "timemodified" => $params["time_modified"],
            "country_filter" => $params["country_filter"],
        ];
        $rs = $DB->get_recordset_sql($sql, $queryparams);

        $selectedcompletions = [];
        foreach ($rs as $record) {
            $key = $record->userid . "_" . $record->courseid;
            if (!isset($selectedcompletions[$key]) ||
                $record->timemodified > $selectedcompletions[$key]["timemodified"]) {
                $selectedcompletions[$key] = [
                    "userid" => $record->userid,
                    "courseid" => $record->courseid,
                    "timemodified" => $record->timemodified,
                ];
            }
        }
        $rs->close();

        if (empty($selectedcompletions)) {
            return [];
        }

        foreach ($selectedcompletions as $selectedcompletion) {
            $percentage = 0;
            $completedactivities = 0;
            $totalactivities = 0;
            $overallstatus = "Unknown";
            $error = "";

            try {
                $course = $DB->get_record("course", ["id" => $selectedcompletion["courseid"]], "*", MUST_EXIST);
                $completion = new completion_info($course);
                if ($completion->is_enabled()) {
                    $iscomplete = $completion->is_course_complete($selectedcompletion["userid"]);
                    $overallstatus = $iscomplete ? "Complete" : "Incomplete";
                    $modinfo = get_fast_modinfo($course, $selectedcompletion["userid"]);
                    foreach ($modinfo->get_cms() as $cm) {
                        if ($cm->completion != COMPLETION_TRACKING_NONE) {
                            $totalactivities++;
                            $completiondata = $completion->get_data($cm, false, $selectedcompletion["userid"]);
                            if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                                $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                                $completedactivities++;
                            }
                        }
                    }
                    if ($totalactivities > 0) {
                        $percentage = round(($completedactivities / $totalactivities) * 100, 2);
                    }
                } else {
                    $error = "Completion tracking not enabled";
                }
            } catch (Exception $e) {
                $error = "Exception: " . $e->getMessage();
            }

            $completiondata = [
                "id" => $selectedcompletion["userid"] . "_" . $selectedcompletion["courseid"],
                "userid" => $selectedcompletion["userid"],
                "courseid" => $selectedcompletion["courseid"],
                "percentage" => $percentage,
                "completed_activities" => $completedactivities,
                "total_activities" => $totalactivities,
                "overall_status" => $overallstatus,
                "timemodified" => $selectedcompletion["timemodified"],
                "error" => $error,
            ];
            $returncompletions[] = $completiondata;
        }

        return $returncompletions;
    }

    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_course_completion_percentage_by_country_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    "id" => new external_value(PARAM_TEXT, get_string("return_id", "local_myddleware")),
                    "userid" => new external_value(PARAM_INT, get_string("return_userid", "local_myddleware")),
                    "courseid" => new external_value(PARAM_INT, get_string("return_courseid", "local_myddleware")),
                    "timemodified" => new external_value(PARAM_INT, get_string("return_timemodified", "local_myddleware")),
                    "percentage" => new external_value(PARAM_FLOAT, get_string("return_percentage", "local_myddleware")),
                    "completed_activities" => new external_value(
                        PARAM_INT, get_string("return_completedactivities", "local_myddleware")),
                    "total_activities" => new external_value(PARAM_INT, get_string("return_totalactivities", "local_myddleware")),
                    "overall_status" => new external_value(PARAM_TEXT, get_string("return_overallstatus", "local_myddleware")),
                    "error" => new external_value(PARAM_TEXT, "Error message if any"),
                ]
            )
        );
    }

    // =========================================================================
    // Method: get_courses_with_users_progress
    // Returns full course info + groups + enrolled students + completion + custom fields.
    // Designed for on-demand pull from Salesforce (button on Course record).
    // Added by JAA patch 2026-04-14.
    // =========================================================================

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_courses_with_users_progress_parameters() {
        return new external_function_parameters(
            [
                "courseids" => new external_multiple_structure(
                    new external_value(PARAM_INT, "Moodle course ID"),
                    "List of course IDs to fetch",
                    VALUE_REQUIRED
                ),
            ]
        );
    }

    /**
     * For each course ID provided, returns the course info, its groups, and all enrolled
     * students with their completion data, last access and selected custom profile fields.
     * Only users with the "student" role are included.
     *
     * @param array $courseids Array of Moodle course IDs.
     * @return array
     */
    public static function get_courses_with_users_progress($courseids) {
        global $DB, $CFG;
        require_once($CFG->libdir . "/completionlib.php");
        require_once($CFG->libdir . "/enrollib.php");

        $params = self::validate_parameters(
            self::get_courses_with_users_progress_parameters(),
            ["courseids" => $courseids]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability("moodle/user:viewdetails", $context);

        // Custom profile fields we want to include in the response.
        $customfieldshortnames = ["residencia", "genero", "nacimiento"];

        // Resolve the "student" archetype role IDs once.
        $studentroleids = [];
        $studentroles = $DB->get_records("role", ["archetype" => "student"], "", "id");
        foreach ($studentroles as $r) {
            $studentroleids[] = (int)$r->id;
        }
        // Fallback: if no archetype student roles found, default to roleid 5 (Moodle default).
        if (empty($studentroleids)) {
            $studentroleids = [5];
        }

        // Pre-load the user_info_field IDs for the custom fields we care about.
        list($insql, $inparams) = $DB->get_in_or_equal($customfieldshortnames, SQL_PARAMS_NAMED, "cf");
        $customfields = $DB->get_records_select(
            "user_info_field",
            "shortname $insql",
            $inparams,
            "",
            "id, shortname"
        );
        $customfieldidtoshortname = [];
        foreach ($customfields as $cf) {
            $customfieldidtoshortname[(int)$cf->id] = $cf->shortname;
        }

        $result = [];

        foreach ($params["courseids"] as $courseid) {
            $coursebase = [
                "courseid" => (int)$courseid,
                "course_fullname" => null,
                "course_shortname" => null,
                "course_idnumber" => null,
                "course_visible" => 0,
                "course_startdate" => 0,
                "groups" => [],
                "users" => [],
                "error" => "",
            ];

            // 1) Course basic info ---------------------------------------------------
            $course = $DB->get_record("course", ["id" => $courseid], "*");
            if (!$course) {
                $coursebase["error"] = "Course not found";
                $result[] = $coursebase;
                continue;
            }

            $coursebase["course_fullname"] = $course->fullname;
            $coursebase["course_shortname"] = $course->shortname;
            $coursebase["course_idnumber"] = $course->idnumber;
            $coursebase["course_visible"] = (int)$course->visible;
            $coursebase["course_startdate"] = (int)$course->startdate;

            // 2) Groups in this course ----------------------------------------------
            $groups = $DB->get_records("groups", ["courseid" => $courseid], "name ASC",
                "id, name, description, idnumber");
            foreach ($groups as $g) {
                $coursebase["groups"][] = [
                    "groupid" => (int)$g->id,
                    "name" => $g->name,
                    "description" => format_text($g->description ?? "", FORMAT_PLAIN),
                    "idnumber" => $g->idnumber,
                ];
            }

            // 3) Enrolled students with role + enrolment + lastaccess --------------
            list($roleinsql, $roleparams) = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, "role");
            $sql = "
                SELECT
                    u.id AS userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.timecreated AS user_timecreated,
                    ra.roleid,
                    r.shortname AS rolename,
                    ue.status AS enrol_status,
                    ue.timestart AS enrol_timestart,
                    ue.timeend AS enrol_timeend,
                    ue.timecreated AS enrol_timecreated,
                    e.enrol AS enrol_method,
                    ula.timeaccess AS lastaccess
                FROM {user} u
                INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                INNER JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
                INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
                INNER JOIN {role} r ON r.id = ra.roleid
                LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
                WHERE u.deleted = 0
                  AND ra.roleid $roleinsql
                ORDER BY u.lastname ASC, u.firstname ASC
            ";
            $sqlparams = array_merge(["courseid" => $courseid], $roleparams);
            $users = $DB->get_records_sql($sql, $sqlparams);

            // Deduplicate by userid (a student can have multiple enrolments) keeping latest.
            $usersbyid = [];
            foreach ($users as $u) {
                $uid = (int)$u->userid;
                if (!isset($usersbyid[$uid]) ||
                    (int)$u->enrol_timecreated > (int)$usersbyid[$uid]->enrol_timecreated) {
                    $usersbyid[$uid] = $u;
                }
            }

            if (empty($usersbyid)) {
                $result[] = $coursebase;
                continue;
            }

            // 4) Custom profile fields for these users (one query) -----------------
            $customfieldsbyuser = [];
            if (!empty($customfieldidtoshortname)) {
                list($useridsql, $useridparams) = $DB->get_in_or_equal(
                    array_keys($usersbyid), SQL_PARAMS_NAMED, "uid");
                list($fieldidsql, $fieldidparams) = $DB->get_in_or_equal(
                    array_keys($customfieldidtoshortname), SQL_PARAMS_NAMED, "fid");
                $cfsql = "SELECT id, userid, fieldid, data
                          FROM {user_info_data}
                          WHERE userid $useridsql AND fieldid $fieldidsql";
                $cfparams = array_merge($useridparams, $fieldidparams);
                $cfrecords = $DB->get_records_sql($cfsql, $cfparams);
                foreach ($cfrecords as $cfr) {
                    $shortname = $customfieldidtoshortname[(int)$cfr->fieldid] ?? null;
                    if ($shortname !== null) {
                        $customfieldsbyuser[(int)$cfr->userid][$shortname] = $cfr->data;
                    }
                }
            }

            // 5) Completion data per user (reuse pattern from get_course_completion_percentage)
            $completioninfo = null;
            try {
                $completioninfo = new completion_info($course);
            } catch (Exception $e) {
                $completioninfo = null;
            }

            foreach ($usersbyid as $uid => $u) {
                $percentage = 0;
                $completedactivities = 0;
                $totalactivities = 0;
                $overallstatus = "Unknown";
                $completionerror = "";
                $completiontimemodified = 0;

                try {
                    if ($completioninfo && $completioninfo->is_enabled()) {
                        $iscomplete = $completioninfo->is_course_complete($uid);
                        $overallstatus = $iscomplete ? "Complete" : "Incomplete";
                        $modinfo = get_fast_modinfo($course, $uid);
                        foreach ($modinfo->get_cms() as $cm) {
                            if ($cm->completion != COMPLETION_TRACKING_NONE) {
                                $totalactivities++;
                                $completiondata = $completioninfo->get_data($cm, false, $uid);
                                if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                                    $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                                    $completedactivities++;
                                }
                                if (!empty($completiondata->timemodified) &&
                                    $completiondata->timemodified > $completiontimemodified) {
                                    $completiontimemodified = (int)$completiondata->timemodified;
                                }
                            }
                        }
                        if ($totalactivities > 0) {
                            $percentage = round(($completedactivities / $totalactivities) * 100, 2);
                        }
                    } else {
                        $completionerror = "Completion tracking not enabled";
                    }
                } catch (Exception $e) {
                    $completionerror = "Exception: " . $e->getMessage();
                }

                // Build the custom_fields object with all requested shortnames (null if missing).
                $userfields = [];
                foreach ($customfieldshortnames as $sn) {
                    $userfields[$sn] = $customfieldsbyuser[$uid][$sn] ?? null;
                }

                $coursebase["users"][] = [
                    "userid" => $uid,
                    "username" => $u->username,
                    "firstname" => $u->firstname,
                    "lastname" => $u->lastname,
                    "email" => $u->email,
                    "timecreated" => (int)$u->user_timecreated,
                    "enrolment" => [
                        "roleid" => (int)$u->roleid,
                        "rolename" => $u->rolename,
                        "status" => (int)$u->enrol_status,
                        "timestart" => (int)$u->enrol_timestart,
                        "timeend" => (int)$u->enrol_timeend,
                        "timecreated" => (int)$u->enrol_timecreated,
                        "enrolmethod" => $u->enrol_method,
                    ],
                    "completion" => [
                        "percentage" => $percentage,
                        "completed_activities" => $completedactivities,
                        "total_activities" => $totalactivities,
                        "overall_status" => $overallstatus,
                        "timemodified" => $completiontimemodified,
                        "error" => $completionerror,
                    ],
                    "lastaccess" => (int)($u->lastaccess ?? 0),
                    "custom_fields" => $userfields,
                ];
            }

            $result[] = $coursebase;
        }

        return $result;
    }

    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_courses_with_users_progress_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    "courseid" => new external_value(PARAM_INT, "Moodle course ID"),
                    "course_fullname" => new external_value(PARAM_TEXT, "Course full name", VALUE_OPTIONAL, null, NULL_ALLOWED),
                    "course_shortname" => new external_value(PARAM_TEXT, "Course short name", VALUE_OPTIONAL, null, NULL_ALLOWED),
                    "course_idnumber" => new external_value(PARAM_TEXT, "Course external ID", VALUE_OPTIONAL, null, NULL_ALLOWED),
                    "course_visible" => new external_value(PARAM_INT, "1 if visible, 0 otherwise"),
                    "course_startdate" => new external_value(PARAM_INT, "Course start date timestamp"),
                    "groups" => new external_multiple_structure(
                        new external_single_structure([
                            "groupid" => new external_value(PARAM_INT, "Group ID"),
                            "name" => new external_value(PARAM_TEXT, "Group name"),
                            "description" => new external_value(PARAM_RAW, "Group description"),
                            "idnumber" => new external_value(PARAM_TEXT, "Group external ID"),
                        ])
                    ),
                    "users" => new external_multiple_structure(
                        new external_single_structure([
                            "userid" => new external_value(PARAM_INT, "Moodle user ID"),
                            "username" => new external_value(PARAM_TEXT, "Username"),
                            "firstname" => new external_value(PARAM_TEXT, "First name"),
                            "lastname" => new external_value(PARAM_TEXT, "Last name"),
                            "email" => new external_value(PARAM_TEXT, "Email"),
                            "timecreated" => new external_value(PARAM_INT, "User creation timestamp"),
                            "enrolment" => new external_single_structure([
                                "roleid" => new external_value(PARAM_INT, "Role ID"),
                                "rolename" => new external_value(PARAM_TEXT, "Role short name"),
                                "status" => new external_value(PARAM_INT, "0 active, 1 suspended"),
                                "timestart" => new external_value(PARAM_INT, "Enrolment start timestamp"),
                                "timeend" => new external_value(PARAM_INT, "Enrolment end timestamp (0 = none)"),
                                "timecreated" => new external_value(PARAM_INT, "Enrolment created timestamp"),
                                "enrolmethod" => new external_value(PARAM_TEXT, "manual, self, cohort, etc"),
                            ]),
                            "completion" => new external_single_structure([
                                "percentage" => new external_value(PARAM_FLOAT, "0-100"),
                                "completed_activities" => new external_value(PARAM_INT, "Number of completed activities"),
                                "total_activities" => new external_value(PARAM_INT, "Number of activities tracked"),
                                "overall_status" => new external_value(PARAM_TEXT, "Complete | Incomplete | Unknown"),
                                "timemodified" => new external_value(PARAM_INT, "Latest activity completion timestamp"),
                                "error" => new external_value(PARAM_TEXT, "Error message if completion calc failed"),
                            ]),
                            "lastaccess" => new external_value(PARAM_INT, "Last access to this course timestamp (0 if never)"),
                            "custom_fields" => new external_single_structure([
                                "residencia" => new external_value(PARAM_RAW, "User residencia profile field", VALUE_OPTIONAL, null, NULL_ALLOWED),
                                "genero" => new external_value(PARAM_RAW, "User genero profile field", VALUE_OPTIONAL, null, NULL_ALLOWED),
                                "nacimiento" => new external_value(PARAM_RAW, "User nacimiento profile field", VALUE_OPTIONAL, null, NULL_ALLOWED),
                            ]),
                        ])
                    ),
                    "error" => new external_value(PARAM_TEXT, "Error message if course not found or other issue"),
                ]
            )
        );
    }

    // =========================================================================
    // Method: get_groups_with_users_progress
    //
    // Group-centric counterpart of get_courses_with_users_progress.
    // Returns members (only users with the student archetype role on the
    // group's course) for each requested group, with enrolment, completion,
    // last access to the course, and selected custom profile fields.
    //
    // Designed to be called from a Salesforce button on a GROUP record.
    // Per-group error model: a missing groupid returns members=[] + course=null
    // + error="Group not found" for that group, without failing the rest.
    //
    // Kept deliberately separate from get_courses_with_users_progress so that
    // existing integrations (button on Course record) keep working unchanged.
    // Added by JAA 2026-04-24.
    // =========================================================================

    /**
     * Returns description of method parameters.
     * @return external_function_parameters.
     */
    public static function get_groups_with_users_progress_parameters() {
        return new external_function_parameters(
            [
                "groupids" => new external_multiple_structure(
                    new external_value(PARAM_INT, "Moodle group ID"),
                    "List of group IDs to fetch",
                    VALUE_REQUIRED
                ),
            ]
        );
    }

    /**
     * For each group ID provided, returns the group info, the course it belongs
     * to, and all members of the group that have the student role on that
     * course, with their completion data, last access and selected custom
     * profile fields.
     *
     * Only users with the "student" archetype role are included — same policy
     * as get_courses_with_users_progress. Teachers/managers are excluded.
     *
     * @param array $groupids Array of Moodle group IDs.
     * @return array
     */
    public static function get_groups_with_users_progress($groupids) {
        global $DB, $CFG;
        require_once($CFG->libdir . "/completionlib.php");
        require_once($CFG->libdir . "/enrollib.php");

        $params = self::validate_parameters(
            self::get_groups_with_users_progress_parameters(),
            ["groupids" => $groupids]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability("moodle/user:viewdetails", $context);

        // Same 3 custom profile fields as get_courses_with_users_progress.
        $customfieldshortnames = ["residencia", "genero", "nacimiento"];

        // Resolve the "student" archetype role IDs once.
        $studentroleids = [];
        $studentroles = $DB->get_records("role", ["archetype" => "student"], "", "id");
        foreach ($studentroles as $r) {
            $studentroleids[] = (int)$r->id;
        }
        if (empty($studentroleids)) {
            $studentroleids = [5];
        }

        // Pre-load the user_info_field IDs for the custom fields we care about.
        list($insql, $inparams) = $DB->get_in_or_equal($customfieldshortnames, SQL_PARAMS_NAMED, "cf");
        $customfields = $DB->get_records_select(
            "user_info_field",
            "shortname $insql",
            $inparams,
            "",
            "id, shortname"
        );
        $customfieldidtoshortname = [];
        foreach ($customfields as $cf) {
            $customfieldidtoshortname[(int)$cf->id] = $cf->shortname;
        }

        $result = [];

        foreach ($params["groupids"] as $groupid) {
            $groupbase = [
                "groupid" => (int)$groupid,
                "name" => null,
                "description" => null,
                "idnumber" => null,
                "course" => null,
                "members" => [],
                "error" => "",
            ];

            // 1) Group basic info + parent course -----------------------------
            $group = $DB->get_record("groups", ["id" => $groupid], "*");
            if (!$group) {
                $groupbase["error"] = "Group not found";
                $result[] = $groupbase;
                continue;
            }

            $groupbase["name"] = $group->name;
            $groupbase["description"] = format_text($group->description ?? "", FORMAT_PLAIN);
            $groupbase["idnumber"] = $group->idnumber;

            $course = $DB->get_record("course", ["id" => $group->courseid], "*");
            if (!$course) {
                // Group exists but its course does not (rare, defensive).
                $groupbase["error"] = "Course not found for this group";
                $result[] = $groupbase;
                continue;
            }

            $groupbase["course"] = [
                "courseid" => (int)$course->id,
                "course_fullname" => $course->fullname,
                "course_shortname" => $course->shortname,
                "course_idnumber" => $course->idnumber,
                "course_visible" => (int)$course->visible,
                "course_startdate" => (int)$course->startdate,
            ];

            // 2) Members of the group that are enrolled students in the course
            list($roleinsql, $roleparams) = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, "role");
            $sql = "
                SELECT
                    u.id AS userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.timecreated AS user_timecreated,
                    ra.roleid,
                    r.shortname AS rolename,
                    ue.status AS enrol_status,
                    ue.timestart AS enrol_timestart,
                    ue.timeend AS enrol_timeend,
                    ue.timecreated AS enrol_timecreated,
                    e.enrol AS enrol_method,
                    ula.timeaccess AS lastaccess,
                    gm.timeadded AS group_joined_at
                FROM {groups_members} gm
                INNER JOIN {user} u ON u.id = gm.userid
                INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                INNER JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
                INNER JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
                INNER JOIN {role} r ON r.id = ra.roleid
                LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
                WHERE gm.groupid = :groupid
                  AND u.deleted = 0
                  AND ra.roleid $roleinsql
                ORDER BY u.lastname ASC, u.firstname ASC
            ";
            $sqlparams = array_merge(
                ["groupid" => $groupid, "courseid" => $course->id],
                $roleparams
            );
            $users = $DB->get_records_sql($sql, $sqlparams);

            // Deduplicate by userid (a student can have multiple enrolments)
            // keeping latest enrolment timestamp. Mirrors the course-centric method.
            $usersbyid = [];
            foreach ($users as $u) {
                $uid = (int)$u->userid;
                if (!isset($usersbyid[$uid]) ||
                    (int)$u->enrol_timecreated > (int)$usersbyid[$uid]->enrol_timecreated) {
                    $usersbyid[$uid] = $u;
                }
            }

            if (empty($usersbyid)) {
                $result[] = $groupbase;
                continue;
            }

            // 3) Custom profile fields for these users (one batch query) -----
            $customfieldsbyuser = [];
            if (!empty($customfieldidtoshortname)) {
                list($useridsql, $useridparams) = $DB->get_in_or_equal(
                    array_keys($usersbyid), SQL_PARAMS_NAMED, "uid");
                list($fieldidsql, $fieldidparams) = $DB->get_in_or_equal(
                    array_keys($customfieldidtoshortname), SQL_PARAMS_NAMED, "fid");
                $cfsql = "SELECT id, userid, fieldid, data
                          FROM {user_info_data}
                          WHERE userid $useridsql AND fieldid $fieldidsql";
                $cfparams = array_merge($useridparams, $fieldidparams);
                $cfrecords = $DB->get_records_sql($cfsql, $cfparams);
                foreach ($cfrecords as $cfr) {
                    $shortname = $customfieldidtoshortname[(int)$cfr->fieldid] ?? null;
                    if ($shortname !== null) {
                        $customfieldsbyuser[(int)$cfr->userid][$shortname] = $cfr->data;
                    }
                }
            }

            // 4) Completion per user — counts ONLY activities required for course completion
            //    (criteria type 4 = activity completion criterion). Activities outside the
            //    course_completion_criteria are ignored for the percentage. See API doc
            //    for the rationale.
            $completioninfo = null;
            try {
                $completioninfo = new completion_info($course);
            } catch (Exception $e) {
                $completioninfo = null;
            }

            // Pre-fetch required activities for this course (criteriatype=4). One query per course.
            $requiredcmidsmap = [];
            $requiredactivitieserror = "";
            try {
                $requiredcmids = $DB->get_fieldset_select(
                    "course_completion_criteria",
                    "moduleinstance",
                    "course = :courseid AND criteriatype = 4",
                    ["courseid" => $course->id]
                );
                if (empty($requiredcmids)) {
                    $requiredactivitieserror = "No required activities configured for this course";
                } else {
                    $requiredcmidsmap = array_flip($requiredcmids);
                }
            } catch (Exception $e) {
                $requiredactivitieserror = "Failed to load required activities: " . $e->getMessage();
            }

            foreach ($usersbyid as $uid => $u) {
                $percentage = 0;
                $completedactivities = 0;
                $totalactivities = 0;
                $overallstatus = "Unknown";
                $completionerror = "";
                $completiontimemodified = 0;

                try {
                    if ($completioninfo && $completioninfo->is_enabled()) {
                        $iscomplete = $completioninfo->is_course_complete($uid);
                        $overallstatus = $iscomplete ? "Complete" : "Incomplete";
                        if (!empty($requiredcmidsmap)) {
                            $modinfo = get_fast_modinfo($course, $uid);
                            foreach ($modinfo->get_cms() as $cm) {
                                // Only count activities REQUIRED for course completion (criteriatype=4).
                                if (!isset($requiredcmidsmap[$cm->id])) {
                                    continue;
                                }
                                if ($cm->completion == COMPLETION_TRACKING_NONE) {
                                    continue;
                                }
                                $totalactivities++;
                                $completiondata = $completioninfo->get_data($cm, false, $uid);
                                if ($completiondata->completionstate == COMPLETION_COMPLETE ||
                                    $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                                    $completedactivities++;
                                }
                                if (!empty($completiondata->timemodified) &&
                                    $completiondata->timemodified > $completiontimemodified) {
                                    $completiontimemodified = (int)$completiondata->timemodified;
                                }
                            }
                            if ($totalactivities > 0) {
                                $percentage = round(($completedactivities / $totalactivities) * 100, 2);
                            }
                        } else {
                            // No required activities (criteriatype=4) configured for this course.
                            $completionerror = $requiredactivitieserror;
                        }
                    } else {
                        $completionerror = "Completion tracking not enabled";
                    }
                } catch (Exception $e) {
                    $completionerror = "Exception: " . $e->getMessage();
                }

                $userfields = [];
                foreach ($customfieldshortnames as $sn) {
                    $userfields[$sn] = $customfieldsbyuser[$uid][$sn] ?? null;
                }

                $groupbase["members"][] = [
                    "userid" => $uid,
                    "username" => $u->username,
                    "firstname" => $u->firstname,
                    "lastname" => $u->lastname,
                    "email" => $u->email,
                    "timecreated" => (int)$u->user_timecreated,
                    "group_joined_at" => (int)$u->group_joined_at,
                    "enrolment" => [
                        "roleid" => (int)$u->roleid,
                        "rolename" => $u->rolename,
                        "status" => (int)$u->enrol_status,
                        "timestart" => (int)$u->enrol_timestart,
                        "timeend" => (int)$u->enrol_timeend,
                        "timecreated" => (int)$u->enrol_timecreated,
                        "enrolmethod" => $u->enrol_method,
                    ],
                    "completion" => [
                        "percentage" => $percentage,
                        "completed_activities" => $completedactivities,
                        "total_activities" => $totalactivities,
                        "overall_status" => $overallstatus,
                        "timemodified" => $completiontimemodified,
                        "error" => $completionerror,
                    ],
                    "lastaccess" => (int)($u->lastaccess ?? 0),
                    "custom_fields" => $userfields,
                ];
            }

            $result[] = $groupbase;
        }

        return $result;
    }

    /**
     * Returns description of method result value.
     * @return external_description.
     */
    public static function get_groups_with_users_progress_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    "groupid" => new external_value(PARAM_INT, "Moodle group ID (matches the requested ID)"),
                    "name" => new external_value(PARAM_TEXT, "Group name", VALUE_OPTIONAL, null, NULL_ALLOWED),
                    "description" => new external_value(PARAM_RAW, "Group description (plain)", VALUE_OPTIONAL, null, NULL_ALLOWED),
                    "idnumber" => new external_value(PARAM_TEXT, "Group external ID (at JAA stores SF Group Id)", VALUE_OPTIONAL, null, NULL_ALLOWED),
                    "course" => new external_single_structure(
                        [
                            "courseid" => new external_value(PARAM_INT, "Course ID that owns this group"),
                            "course_fullname" => new external_value(PARAM_TEXT, "Course full name", VALUE_OPTIONAL, null, NULL_ALLOWED),
                            "course_shortname" => new external_value(PARAM_TEXT, "Course short name", VALUE_OPTIONAL, null, NULL_ALLOWED),
                            "course_idnumber" => new external_value(PARAM_TEXT, "Course external ID", VALUE_OPTIONAL, null, NULL_ALLOWED),
                            "course_visible" => new external_value(PARAM_INT, "1 if visible, 0 otherwise"),
                            "course_startdate" => new external_value(PARAM_INT, "Course start date timestamp"),
                        ],
                        "Course this group belongs to",
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    "members" => new external_multiple_structure(
                        new external_single_structure([
                            "userid" => new external_value(PARAM_INT, "Moodle user ID"),
                            "username" => new external_value(PARAM_TEXT, "Username"),
                            "firstname" => new external_value(PARAM_TEXT, "First name"),
                            "lastname" => new external_value(PARAM_TEXT, "Last name"),
                            "email" => new external_value(PARAM_TEXT, "Email"),
                            "timecreated" => new external_value(PARAM_INT, "User creation timestamp"),
                            "group_joined_at" => new external_value(PARAM_INT, "When this user was added to the group"),
                            "enrolment" => new external_single_structure([
                                "roleid" => new external_value(PARAM_INT, "Role ID"),
                                "rolename" => new external_value(PARAM_TEXT, "Role short name"),
                                "status" => new external_value(PARAM_INT, "0 active, 1 suspended"),
                                "timestart" => new external_value(PARAM_INT, "Enrolment start timestamp"),
                                "timeend" => new external_value(PARAM_INT, "Enrolment end timestamp (0 = none)"),
                                "timecreated" => new external_value(PARAM_INT, "Enrolment created timestamp"),
                                "enrolmethod" => new external_value(PARAM_TEXT, "manual, self, cohort, etc"),
                            ]),
                            "completion" => new external_single_structure([
                                "percentage" => new external_value(PARAM_FLOAT, "0-100"),
                                "completed_activities" => new external_value(PARAM_INT, "Number of completed activities"),
                                "total_activities" => new external_value(PARAM_INT, "Number of activities tracked"),
                                "overall_status" => new external_value(PARAM_TEXT, "Complete | Incomplete | Unknown"),
                                "timemodified" => new external_value(PARAM_INT, "Latest activity completion timestamp"),
                                "error" => new external_value(PARAM_TEXT, "Error message if completion calc failed"),
                            ]),
                            "lastaccess" => new external_value(PARAM_INT, "Last access to this course timestamp (0 if never)"),
                            "custom_fields" => new external_single_structure([
                                "residencia" => new external_value(PARAM_RAW, "User residencia profile field", VALUE_OPTIONAL, null, NULL_ALLOWED),
                                "genero" => new external_value(PARAM_RAW, "User genero profile field", VALUE_OPTIONAL, null, NULL_ALLOWED),
                                "nacimiento" => new external_value(PARAM_RAW, "User nacimiento profile field", VALUE_OPTIONAL, null, NULL_ALLOWED),
                            ]),
                        ])
                    ),
                    "error" => new external_value(PARAM_TEXT, "Error message if group not found or other issue"),
                ]
            )
        );
    }

    // -------------------------------------------------------------------------
    // Method: get_roc_groups_by_date
    // Dedicated to rule 4.1 ROC. Returns groups modified after $timemodified
    // (or a specific group by id) that have customfield 'roc' = 1 AND
    // 'idaccioneducativaroc' non-empty. Each group includes all its customfields
    // (shortname + valueraw) so the Myddleware connector can map them as fields.
    //
    // This is intentionally a separate function from get_groups_by_date so any
    // existing rule using groups remains unaffected.
    // -------------------------------------------------------------------------

    public static function get_roc_groups_by_date_parameters() {
        return new external_function_parameters(
            [
                'time_modified' => new external_value(
                    PARAM_INT, get_string('param_timemodified', 'local_myddleware'), VALUE_DEFAULT, 0),
                'id' => new external_value(PARAM_INT, get_string('param_id', 'local_myddleware'), VALUE_DEFAULT, 0),
            ]
        );
    }

    public static function get_roc_groups_by_date($timemodified, $id) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/group/externallib.php");

        $params = self::validate_parameters(
            self::get_roc_groups_by_date_parameters(),
            ['time_modified' => $timemodified, 'id' => $id]
        );
        $context = context_system::instance();
        self::validate_context($context);

        // DB-level filter: only groups with roc=1 AND idaccioneducativaroc != ''.
        // Filtering at DB avoids loading all groups into PHP.
        $sql = "SELECT g.*
                FROM {groups} g
                WHERE " . (!empty($params['id'])
                            ? "g.id = :id"
                            : "g.timemodified > :timemodified") . "
                  AND EXISTS (
                      SELECT 1
                      FROM {customfield_data} d
                      JOIN {customfield_field} f ON f.id = d.fieldid
                      JOIN {customfield_category} c ON c.id = f.categoryid
                      WHERE d.instanceid = g.id
                        AND c.component = 'core_group' AND c.area = 'group'
                        AND f.shortname = 'roc'
                        AND d.intvalue = 1
                  )
                  AND EXISTS (
                      SELECT 1
                      FROM {customfield_data} d2
                      JOIN {customfield_field} f2 ON f2.id = d2.fieldid
                      JOIN {customfield_category} c2 ON c2.id = f2.categoryid
                      WHERE d2.instanceid = g.id
                        AND c2.component = 'core_group' AND c2.area = 'group'
                        AND f2.shortname = 'idaccioneducativaroc'
                        AND COALESCE(d2.shortcharvalue, d2.value, '') <> ''
                  )
                ORDER BY g.timemodified ASC";
        $queryparams = [
            'id' => !empty($params['id']) ? $params['id'] : 0,
            'timemodified' => !empty($params['time_modified']) ? $params['time_modified'] : 0,
        ];
        $selectedgroups = $DB->get_records_sql($sql, $queryparams);

        $returnedgroups = [];
        if (!empty($selectedgroups)) {
            foreach ($selectedgroups as $value) {
                $groupdetails = core_group_external::get_groups([$value->id]);
                $groupdetails[0]['timemodified'] = $value->timemodified;
                $groupdetails[0]['customfields'] = self::get_roc_group_customfields($value->id);
                $returnedgroups[] = $groupdetails[0];
            }
        }
        return $returnedgroups;
    }

    /**
     * Helper for get_roc_groups_by_date. Returns all customfields of a group
     * as an array of [shortname, name, type, value, valueraw].
     */
    private static function get_roc_group_customfields($groupid) {
        global $DB;
        $sql = "SELECT f.shortname, f.name, f.type,
                       d.intvalue, d.shortcharvalue, d.value
                FROM {customfield_field} f
                JOIN {customfield_category} c ON c.id = f.categoryid
                LEFT JOIN {customfield_data} d
                       ON d.fieldid = f.id AND d.instanceid = :groupid
                WHERE c.component = 'core_group' AND c.area = 'group'
                ORDER BY f.sortorder";
        $rows = $DB->get_records_sql($sql, ['groupid' => $groupid]);

        $result = [];
        foreach ($rows as $row) {
            if ($row->type === 'checkbox') {
                $raw = ($row->intvalue !== null) ? (string)$row->intvalue : '';
            } else if (in_array($row->type, ['text', 'textarea', 'select'])) {
                $raw = $row->shortcharvalue ?? ($row->value ?? '');
            } else {
                $raw = $row->value ?? '';
            }
            $result[] = [
                'shortname' => $row->shortname,
                'name' => $row->name,
                'type' => $row->type,
                'value' => (string)$raw,
                'valueraw' => (string)$raw,
            ];
        }
        return $result;
    }

    public static function get_roc_groups_by_date_returns() {
        global $CFG;
        require_once($CFG->dirroot . "/group/externallib.php");
        $structure = core_group_external::get_groups_returns();
        $structure->content->keys['timemodified'] = new external_value(
            PARAM_INT, get_string('return_timemodified', 'local_myddleware'));
        $structure->content->keys['customfields'] = new external_multiple_structure(
            new external_single_structure([
                'shortname' => new external_value(PARAM_TEXT, 'Custom field shortname'),
                'name'      => new external_value(PARAM_TEXT, 'Custom field name'),
                'type'      => new external_value(PARAM_TEXT, 'Custom field type'),
                'value'     => new external_value(PARAM_RAW, 'Custom field formatted value'),
                'valueraw'  => new external_value(PARAM_RAW, 'Custom field raw value'),
            ]),
            'Group custom fields',
            VALUE_OPTIONAL
        );
        return $structure;
    }
}
