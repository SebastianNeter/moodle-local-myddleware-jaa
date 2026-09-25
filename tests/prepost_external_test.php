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
 * get_prepost_courses, get_prepost_questionnaires, get_prepost_questions,
 * get_prepost_responses_by_date, get_prepost_group_members.
 *
 * NOT EXECUTED in this environment (no Moodle/PHPUnit test harness available
 * here — only `php -l` syntax checks were run). Schema assumptions for
 * mod_questionnaire (questionnaire_question.position, questionnaire_quest_choice
 * columns, questionnaire.timemodified, questionnaire_response and its
 * per-type answer tables) must be verified against a real mod_questionnaire
 * install before this suite is trusted. The role-archetype assertions
 * (student vs editingteacher) rely on Moodle's default site roles keeping
 * their default archetype, which is standard but technically
 * site-configurable.
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

    /**
     * mod_questionnaire is an optional plugin. Tests that create a
     * questionnaire activity, or that rely on get_prepost_questionnaires /
     * get_prepost_questions reaching their per-course/per-cmid logic (rather
     * than the moduleunavailable early return), must skip when it is absent.
     */
    private function skip_unless_questionnaire_installed(): void {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/mod/questionnaire/lib.php')) {
            $this->markTestSkipped('mod_questionnaire is not installed in this Moodle instance.');
        }
    }

    /**
     * Inserts a raw questionnaire_response row plus one Yes/No answer,
     * returning the response id. Mirrors mod_questionnaire's own schema
     * directly (same approach already used above for questions/choices)
     * since there is no public data generator for responses.
     *
     * @return int response id
     */
    private function insert_prepost_response(
            int $questionnaireid, int $userid, int $questionid, int $submitted, bool $complete = true): int {
        global $DB;

        $responseid = $DB->insert_record('questionnaire_response', (object)[
            'questionnaireid' => $questionnaireid,
            'submitted' => $submitted,
            'complete' => $complete ? 'y' : 'n',
            'grade' => 0,
            'userid' => $userid,
        ]);
        $DB->insert_record('questionnaire_response_bool', (object)[
            'response_id' => $responseid,
            'question_id' => $questionid,
            'choice_id' => 'y',
        ]);
        return $responseid;
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

    public function test_get_prepost_questionnaires_returns_instances_ordered_by_position(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $pre = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id, 'name' => 'Pre']);
        $post = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id, 'name' => 'Post']);

        $result = \local_myddleware_external::get_prepost_questionnaires([$course->id], 0);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_questionnaires_returns(), $result);

        $this->assertCount(2, $result['questionnaires']);
        $names = array_column($result['questionnaires'], 'name');
        $this->assertEqualsCanonicalizing(['Pre', 'Post'], $names);

        $positions = array_column($result['questionnaires'], 'position');
        $this->assertNotEquals($positions[0], $positions[1] ?? null, 'positions must be deterministic and distinct');
    }

    public function test_get_prepost_questionnaires_warns_on_missing_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        $result = \local_myddleware_external::get_prepost_questionnaires([999999], 0);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_questionnaires_returns(), $result);

        $this->assertEmpty($result['questionnaires']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('coursenotfound', $result['warnings'][0]['warningcode']);
    }

    public function test_get_prepost_questions_returns_content_and_choices_skips_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module(
            'questionnaire', ['course' => $course->id, 'name' => 'Pre']);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);

        $activequestion = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid,
            'name' => '',
            'type_id' => 1,
            'result_id' => 0,
            'length' => 0,
            'precise' => 0,
            'position' => 1,
            'content' => '¿Ahorrás mensualmente?',
            'required' => 'y',
            'deleted' => null,
        ]);
        $DB->insert_record('questionnaire_quest_choice', (object)[
            'question_id' => $activequestion,
            'content' => 'Sí',
            'value' => '1',
        ]);
        $DB->insert_record('questionnaire_quest_choice', (object)[
            'question_id' => $activequestion,
            'content' => 'No',
            'value' => '0',
        ]);
        $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid,
            'name' => '',
            'type_id' => 1,
            'result_id' => 0,
            'length' => 0,
            'precise' => 0,
            'position' => 2,
            'content' => 'Deleted question',
            'required' => 'n',
            'deleted' => time(),
        ]);

        $result = \local_myddleware_external::get_prepost_questions([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_questions_returns(), $result);

        $this->assertCount(1, $result['questions']);
        $question = $result['questions'][0];
        $this->assertEquals('¿Ahorrás mensualmente?', $question['content']);
        $this->assertTrue($question['required']);
        $this->assertCount(2, $question['choices']);
        $this->assertEqualsCanonicalizing(['Sí', 'No'], array_column($question['choices'], 'content'));
    }

    public function test_get_prepost_questions_excludes_page_breaks_and_section_text(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module(
            'questionnaire', ['course' => $course->id, 'name' => 'Pre']);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);

        $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid,
            'name' => '',
            'type_id' => 1,
            'result_id' => 0,
            'length' => 0,
            'precise' => 0,
            'position' => 1,
            'content' => '¿Ahorrás mensualmente?',
            'required' => 'y',
            'deleted' => null,
        ]);
        // Page break (99) and section text (100) are layout rows, not questions.
        $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid,
            'name' => '',
            'type_id' => 99,
            'result_id' => 0,
            'length' => 0,
            'precise' => 0,
            'position' => 2,
            'content' => '',
            'required' => 'n',
            'deleted' => null,
        ]);
        $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid,
            'name' => '',
            'type_id' => 100,
            'result_id' => 0,
            'length' => 0,
            'precise' => 0,
            'position' => 3,
            'content' => 'Some section intro text',
            'required' => 'n',
            'deleted' => null,
        ]);

        $result = \local_myddleware_external::get_prepost_questions([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_questions_returns(), $result);

        $this->assertCount(1, $result['questions']);
        $this->assertEquals(1, $result['questions'][0]['typeid']);
    }

    public function test_get_prepost_questions_warns_on_missing_questionnaire(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        $result = \local_myddleware_external::get_prepost_questions([999999]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_questions_returns(), $result);

        $this->assertEmpty($result['questions']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('cmnotfound', $result['warnings'][0]['warningcode']);
    }

    public function test_get_prepost_questions_warns_on_non_questionnaire_cmid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // The moduleunavailable early return in get_prepost_questions would
        // otherwise mask the cmid/module-mismatch check this test targets.
        $this->skip_unless_questionnaire_installed();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $result = \local_myddleware_external::get_prepost_questions([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_questions_returns(), $result);

        $this->assertEmpty($result['questions']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('cmnotfound', $result['warnings'][0]['warningcode']);
    }

    public function test_get_prepost_responses_by_date_excludes_non_student_roles(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $now = time();
        $this->insert_prepost_response($instance->id, $student->id, $questionid, $now);
        $this->insert_prepost_response($instance->id, $teacher->id, $questionid, $now);

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $userids = array_column($result['responses'], 'userid');
        $this->assertContains((int)$student->id, $userids);
        $this->assertNotContains((int)$teacher->id, $userids, 'teacher-role response must be excluded');
        foreach ($result['responses'] as $response) {
            $this->assertEquals('student', $response['courserole']);
        }
    }

    public function test_get_prepost_responses_by_date_time_modified_is_strictly_greater(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $submitted = time();
        $this->insert_prepost_response($instance->id, $student->id, $questionid, $submitted);

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id], $submitted);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);
        $this->assertEmpty($result['responses'], 'time_modified filter must be strictly greater than, not >=');

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id], $submitted - 1);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);
        $this->assertNotEmpty($result['responses']);
    }

    public function test_get_prepost_responses_by_date_excludes_incomplete_by_default(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->insert_prepost_response($instance->id, $student->id, $questionid, time(), false);

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);
        $this->assertEmpty($result['responses'], 'incomplete responses must be excluded by default');

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id], 0, 500, 0, true);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);
        $this->assertNotEmpty($result['responses']);
        $this->assertFalse($result['responses'][0]['complete']);
    }

    public function test_get_prepost_responses_by_date_pages_with_limit_and_offset(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $base = time();
        for ($i = 0; $i < 3; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $this->insert_prepost_response($instance->id, $student->id, $questionid, $base + $i);
        }

        $page1 = \local_myddleware_external::get_prepost_responses_by_date([$cm->id], 0, 2, 0);
        $page1 = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $page1);
        $this->assertCount(2, $page1['responses']);
        $this->assertEquals(2, $page1['next_offset']);

        $page2 = \local_myddleware_external::get_prepost_responses_by_date([$cm->id], 0, 2, $page1['next_offset']);
        $page2 = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $page2);
        $this->assertCount(1, $page2['responses']);
        $this->assertEquals(3, $page2['next_offset']);
    }

    public function test_get_prepost_responses_by_date_merges_answers_from_multiple_tables(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);

        $textquestionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 2, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Text Q', 'required' => 'n',
            'deleted' => null,
        ]);
        $choicequestionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 4, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 2, 'content' => 'Choice Q', 'required' => 'n',
            'deleted' => null,
        ]);
        $choiceid = $DB->insert_record('questionnaire_quest_choice', (object)[
            'question_id' => $choicequestionid, 'content' => 'Sí', 'value' => '1',
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $responseid = $DB->insert_record('questionnaire_response', (object)[
            'questionnaireid' => $instance->id, 'submitted' => time(), 'complete' => 'y',
            'grade' => 0, 'userid' => $student->id,
        ]);
        $DB->insert_record('questionnaire_response_text', (object)[
            'response_id' => $responseid, 'question_id' => $textquestionid, 'response' => 'Hola',
        ]);
        $DB->insert_record('questionnaire_resp_single', (object)[
            'response_id' => $responseid, 'question_id' => $choicequestionid, 'choice_id' => $choiceid,
        ]);

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $this->assertCount(1, $result['responses']);
        $answers = $result['responses'][0]['answers'];
        $this->assertCount(2, $answers);

        $byquestion = [];
        foreach ($answers as $a) {
            $byquestion[$a['question_id']] = $a;
        }
        $this->assertEquals('Hola', $byquestion[$textquestionid]['text']);
        $this->assertEquals($choiceid, $byquestion[$choicequestionid]['choice_id']);
    }

    public function test_get_prepost_responses_by_date_includes_group_ids(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course, $group] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        groups_add_member($group, $student);
        $this->insert_prepost_response($instance->id, $student->id, $questionid, time());

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $this->assertCount(1, $result['responses']);
        $this->assertContains((int)$group->id, $result['responses'][0]['groupids']);
    }

    public function test_get_prepost_responses_by_date_anonymous_questionnaire_masks_identity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        $DB->set_field('questionnaire', 'respondenttype', 'anonymous', ['id' => $questionnaire->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->insert_prepost_response($instance->id, $student->id, $questionid, time());

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $this->assertCount(1, $result['responses']);
        $response = $result['responses'][0];
        $this->assertTrue($response['anonymous']);
        $this->assertNull($response['username']);
        $this->assertNull($response['email']);
    }

    public function test_get_prepost_responses_by_date_fullname_questionnaire_keeps_identity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        [$course] = $this->create_prepost_fixture();
        $questionnaire = $this->getDataGenerator()->create_module('questionnaire', ['course' => $course->id]);
        // respondenttype defaults to "fullname" -- asserted explicitly since
        // the anonymity leak fix (C3) depends on this default.
        $this->assertEquals(
            'fullname', $DB->get_field('questionnaire', 'respondenttype', ['id' => $questionnaire->id]));
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->insert_prepost_response($instance->id, $student->id, $questionid, time());

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $this->assertCount(1, $result['responses']);
        $response = $result['responses'][0];
        $this->assertFalse($response['anonymous']);
        $this->assertEquals($student->username, $response['username']);
        $this->assertEquals($student->email, $response['email']);
    }

    public function test_get_prepost_responses_by_date_excludes_category_level_manager(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => 'Argentina']);
        $course = $generator->create_course(['category' => $category->id]);
        $questionnaire = $generator->create_module('questionnaire', ['course' => $course->id]);
        $instance = $DB->get_record('questionnaire', ['id' => $questionnaire->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('questionnaire', $questionnaire->id, $course->id, false, MUST_EXIST);
        $questionid = $DB->insert_record('questionnaire_question', (object)[
            'surveyid' => $instance->sid, 'name' => '', 'type_id' => 1, 'result_id' => 0,
            'length' => 0, 'precise' => 0, 'position' => 1, 'content' => 'Q', 'required' => 'n', 'deleted' => null,
        ]);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        // A manager role assigned at the CATEGORY context (not the course
        // context) must still exclude this user -- W5, category/system-level
        // archetype exclusion.
        $managerarchetyperoles = $DB->get_records('role', ['archetype' => 'manager'], 'id', 'id');
        $this->assertNotEmpty($managerarchetyperoles, 'a default manager-archetype role must exist');
        $managerroleid = (int)array_key_first($managerarchetyperoles);
        $categorycontext = \context_coursecat::instance($category->id);
        role_assign($managerroleid, $user->id, $categorycontext->id);

        $this->insert_prepost_response($instance->id, $user->id, $questionid, time());

        $result = \local_myddleware_external::get_prepost_responses_by_date([$cm->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $this->assertEmpty(
            $result['responses'],
            'category-level manager role must exclude the user even though the course role is student');
    }

    public function test_get_prepost_responses_by_date_warns_on_missing_cmid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->skip_unless_questionnaire_installed();

        $result = \local_myddleware_external::get_prepost_responses_by_date([999999]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_responses_by_date_returns(), $result);

        $this->assertEmpty($result['responses']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('cmnotfound', $result['warnings'][0]['warningcode']);
    }

    public function test_get_prepost_group_members_returns_members_with_timeadded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $group] = $this->create_prepost_fixture();
        $user = $this->getDataGenerator()->create_user();
        // groups_add_member() returns false (silently, without throwing) for
        // a user not enrolled in the group's course, so the fixture must
        // enrol first -- see the F2 review's C1 finding.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->assertTrue(groups_add_member($group, $user));

        $result = \local_myddleware_external::get_prepost_group_members([$group->id]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_group_members_returns(), $result);

        $this->assertCount(1, $result['groups']);
        $this->assertEquals((int)$group->id, $result['groups'][0]['groupid']);
        $userids = array_column($result['groups'][0]['members'], 'userid');
        $this->assertContains((int)$user->id, $userids);
        foreach ($result['groups'][0]['members'] as $member) {
            $this->assertGreaterThan(0, $member['timeadded']);
        }
    }

    public function test_get_prepost_group_members_warns_on_unknown_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = \local_myddleware_external::get_prepost_group_members([999999]);
        $result = \external_api::clean_returnvalue(
            \local_myddleware_external::get_prepost_group_members_returns(), $result);

        $this->assertEmpty($result['groups']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('groupnotfound', $result['warnings'][0]['warningcode']);
    }
}
