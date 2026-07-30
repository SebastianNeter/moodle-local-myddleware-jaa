<?php
// Part of the local_myddleware plugin.
//
// Creates Moodle users by delegating to the core function core_user_create_users
// (identical validation, custom fields and events), then sends the branded HTML
// credentials email via local_adminreset. This avoids the core plain-text email
// that createpassword=true triggers (which arrives as raw HTML source).
//
// It also converts menu-type custom profile fields (e.g. genero, residencia) from
// the plain value Myddleware sends to the exact multilang option Moodle requires.

namespace local_myddleware\external;

defined('MOODLE_INTERNAL') || die();

class create_users extends \core_external\external_api {

    /** Same input contract as core_user_create_users (reused verbatim). */
    public static function execute_parameters() {
        global $CFG;
        require_once($CFG->dirroot . '/user/externallib.php');
        return \core_user_external::create_users_parameters();
    }

    /** Same return contract as core_user_create_users (reused verbatim). */
    public static function execute_returns() {
        global $CFG;
        require_once($CFG->dirroot . '/user/externallib.php');
        return \core_user_external::create_users_returns();
    }

    /**
     * Create each user via core, then send the HTML credentials email.
     * The email is best-effort: it never blocks or fails the user creation.
     *
     * @param array $users list of users (same shape as core_user_create_users)
     * @return array list of ['id' => int, 'username' => string]
     */
    public static function execute($users) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/externallib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['users' => $users]);

        $result = [];
        foreach ($params['users'] as $user) {
            // Never use createpassword: it makes the core send its own plain-text mail.
            // Generate the password ourselves so we can include it in the HTML mail.
            $plainpassword = !empty($user['password']) ? $user['password'] : generate_password();
            $user['password'] = $plainpassword;
            unset($user['createpassword']);

            // Convert menu-type custom profile fields (e.g. genero, residencia) from the
            // plain value Myddleware sends to the exact multilang option Moodle stores.
            // Scoped to menu fields only; defensive so it never blocks the user creation.
            if (!empty($user['customfields']) && is_array($user['customfields'])) {
                foreach ($user['customfields'] as $i => $cf) {
                    if (!isset($cf['type']) || !array_key_exists('value', $cf)) {
                        continue;
                    }
                    try {
                        $user['customfields'][$i]['value'] = self::map_menu_value($cf['type'], $cf['value']);
                    } catch (\Throwable $e) {
                        debugging('local_myddleware/create_users: map_menu_value failed for ' .
                            $cf['type'] . ': ' . $e->getMessage(), DEBUG_NORMAL);
                    }
                }
            }

            // Delegate creation to the core function: identical behaviour, no email.
            $created = \core_user_external::create_users([$user]);
            $created = json_decode(json_encode($created), true);
            $newid = (int) $created[0]['id'];
            $username = $created[0]['username'];

            // Mirror the createpassword UX: force a password change on first login.
            set_user_preference('auth_forcepasswordchange', 1, $newid);

            // Send the credentials email using the site's MAINTAINED template
            // (core 'newusernewpasswordtext', edited via Language customization),
            // rendered as HTML. Best-effort: never blocks or fails the creation.
            try {
                $mailuser = $DB->get_record('user', ['id' => $newid], '*', MUST_EXIST);
                force_current_language(empty($mailuser->lang) ? 'es' : $mailuser->lang);
                $site = get_site();
                $a = new \stdClass();
                $a->firstname   = $mailuser->firstname;
                $a->fullname    = fullname($mailuser);
                $a->sitename    = format_string($site->fullname);
                $a->username    = $mailuser->username;
                $a->newpassword = $plainpassword;
                $a->link        = $CFG->wwwroot . '/login/';
                $a->signoff     = generate_email_signoff();
                $messagehtml = get_string('newusernewpasswordtext', 'core', $a);
                $subject     = format_string($site->fullname) . ': ' . get_string('newusernewpasswordsubj');
                email_to_user($mailuser, \core_user::get_support_user(), $subject,
                    html_to_text($messagehtml), $messagehtml);
            } catch (\Throwable $e) {
                debugging('local_myddleware/create_users: email failed for user ' . $newid .
                    ': ' . $e->getMessage(), DEBUG_NORMAL);
            }

            $result[] = ['id' => $newid, 'username' => $username];
        }
        return $result;
    }

    /**
     * Convert an incoming plain value to the exact option string Moodle expects for
     * a custom profile field of type "menu" whose options are multilang ({mlang}
     * blocks). Matches the value against every language label of every option,
     * case-insensitively. Returns the value UNCHANGED for non-menu fields or when
     * there is no confident match, so it can never alter another field or break
     * the user creation.
     *
     * @param string $shortname custom field shortname (the WS 'type')
     * @param mixed $value incoming plain value
     * @return string the exact option (mlang block) or the original value
     */
    private static function map_menu_value($shortname, $value): string {
        global $DB;
        $value = (string) $value;
        if (trim($value) === '') {
            return $value;
        }
        $field = $DB->get_record('user_info_field',
            ['shortname' => $shortname], 'id, datatype, param1');
        if (!$field || $field->datatype !== 'menu') {
            return $value; // only menu fields are touched
        }
        $needle = \core_text::strtolower(trim($value));
        foreach (preg_split('/\r\n|\r|\n/', (string) $field->param1) as $option) {
            $option = trim($option);
            if ($option === '') {
                continue;
            }
            if (preg_match_all('/\{mlang\s+[\w-]+\}(.*?)\{mlang\}/s', $option, $labels)) {
                foreach ($labels[1] as $label) {
                    if (\core_text::strtolower(trim($label)) === $needle) {
                        return $option;
                    }
                }
            } else if (\core_text::strtolower($option) === $needle) {
                return $option;
            }
        }
        return $value; // no confident match: leave as-is (same behaviour as today)
    }
}
