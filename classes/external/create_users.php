<?php
// Part of the local_myddleware plugin.
//
// Creates Moodle users by delegating to the core function core_user_create_users
// (identical validation, custom fields and events), then sends the branded HTML
// credentials email via local_adminreset. This avoids the core plain-text email
// that createpassword=true triggers (which arrives as raw HTML source).

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
}
