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

namespace local_myddleware;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/myddleware/externallib.php');

/**
 * Tests for the PrePost platform read-only web services:
 * get_prepost_courses, get_prepost_questionnaires, get_prepost_questions.
 *
 * NOT EXECUTED in this environment (no Moodle/PHPUnit test harness available
 * here — only `php -l` syntax checks were run). Schema assumptions for
 * mod_questionnaire (questionnaire_question.position, questionnaire_quest_choice
 * columns, questionnaire.timemodified) must be verified against a real
 * mod_questionnaire install before this suite is trusted.
 *
 * @package    local_myddleware
 * @copyright  2026 JAA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prepost_external_test extends \advanced_testcase {

    /**
     * Creates a course inside a category named "Argentina" with one group
     * carrying an "arg" custom field, when the customfield API is available.
     *
     * @return array [\stdClass $course, \stdClass $group]
     */
    private function create_prepost_fixture(): array {
        global $DB;

        $generator = $this->getDataGenerator();

        $category = $generator->create_category(['name' => 'Argentina']);
        $course = $generator->create_course(['category' => $category->id]);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'Aula 1']);

        // Pin the group's timemodified to the course's timemodified. Without
        // this, groups_create_group() sets timemodified = time() a moment
        // after the course was created, and a test using the course's
        // timemodified as an incremental-sync watermark would flake whenever
        // the wall clock ticks over a second between the two calls.
        $DB->set_field('groups', 'timemodified', $course->timemodified, ['id' => $group->id]);
        $group->timemodified = $course->timemodified;

        if (class_exists('core_group\\customfield\\group_handler')) {
            $cfgenerator = $generator->get_plugin_generator('core_customfield');
            $fieldcategory = $cfgenerator->create_category([
                'component' => 'core_group',
                'area' => 'group',
                'itemid' => 0,
            ]);
            $field = $cfgenerator->create_field([
                'categoryid' => $fieldcategory->get('id'),
                'shortname' => 'arg',
                'type' => 'checkbox',
            ]);
            $cfgenerator->add_instance_data($field, $group->id, 1);
        }

        return [$course, $group];
    }

    public function test_get_prepost_courses_returns_argentina_courses_with_groups(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $group] = $this->create_prepost_fixture();

        $result = \local_myddleware_external::get_prepost_courses(0);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_courses_returns(), $result);

        $ids = array_column($result['courses'], 'id');
        $this->assertContains((int)$course->id, $ids);

        $key = array_search((int)$course->id, $ids, true);
        $groupids = array_column($result['courses'][$key]['groups'], 'id');
        $this->assertContains((int)$group->id, $groupids);

        if (class_exists('core_group\\customfield\\group_handler')) {
            // Exercises the batched customfield path (W5): the fixture group
            // has "arg" explicitly set to 1, so it must come back as true,
            // not just "not null".
            $groupkey = array_search((int)$group->id, $groupids, true);
            $this->assertSame(true, $result['courses'][$key]['groups'][$groupkey]['arg']);
        }
    }

    public function test_get_prepost_courses_excludes_courses_outside_argentina(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        [$argentinacourse] = $this->create_prepost_fixture();
        $othercategory = $generator->create_category(['name' => 'Brasil']);
        $othercourse = $generator->create_course(['category' => $othercategory->id]);

        $result = \local_myddleware_external::get_prepost_courses(0);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_courses_returns(), $result);

        $ids = array_column($result['courses'], 'id');
        $this->assertContains((int)$argentinacourse->id, $ids, 'the Argentina-category course must be returned');
        $this->assertNotContains((int)$othercourse->id, $ids);
    }

    public function test_get_prepost_courses_time_modified_is_strictly_greater(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $group] = $this->create_prepost_fixture();
        $watermark = max($course->timemodified, $group->timemodified);

        $result = \local_myddleware_external::get_prepost_courses($watermark);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_courses_returns(), $result);

        $ids = array_column($result['courses'], 'id');
        $this->assertNotContains((int)$course->id, $ids, 'time_modified filter must be strictly greater than, not >=');
    }
}
