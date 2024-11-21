<?php
/*
 * Copyright (c) 2024 Bennet Becker <dev@bennet.cc>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */


namespace NextcloudAttachments\Traits;

use GuzzleHttp\Exception\GuzzleException;
use function NextcloudAttachments\__;

trait NextcloudLogin {
    /**
     * Action to check nextcloud login status
     * @return void
     */
    public function check_login(): void
    {
        $this->rcmail->output->command('plugin.nextcloud_login_result', $this->login_status());
    }

    /**
     * Action to start nextcloud login process
     * @return void
     */
    public function login(): void
    {
        $server = $this->rcmail->config->get(__("server"));

        if (empty($server)) {
            return;
        }

        //start login flow
        try {
            $res = $this->client->post($server . "/index.php/login/v2");

            $body = $res->getBody()->getContents();
            $data = json_decode($body, true);

            if ($res->getStatusCode() !== 200) {
                self::log($this->rcmail->get_user_name() . " login check request failed: " . print_r($data, true));
                $this->rcmail->output->command('plugin.nextcloud_login', [
                    'status' => null, "message" => $res->getReasonPhrase(), "response" => $data]);
                return;
            }

            //save poll endpoint and token to session
            $_SESSION['plugins']['nextcloud_attachments'] = $data['poll'];
            unset($_SESSION['plugins']['nextcloud_attachments']['login_result']);

            $this->rcmail->output->command('plugin.nextcloud_login', ['status' => "ok", "url" => $data["login"]]);
        } catch (GuzzleException $e) {
            self::log($this->rcmail->get_user_name() . " login request failed: " . print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_login', ['status' => null]);
        }
    }

    private function login_status(): array
    {
        //Cached Result
        if (isset($_SESSION['plugins']['nextcloud_attachments']) &&
            $_SESSION['plugins']['nextcloud_attachments']['login_result']) {
            return $_SESSION['plugins']['nextcloud_attachments']['login_result'];
        }

        $prefs = $this->rcmail->user->get_prefs();

        $server = $this->rcmail->config->get(__("server"));

        $username = $this->resolve_username();

        //missing config
        if (empty($server) || empty($username)) {
            return ['status' => null];
        }

        //always prompt for app password, as mail passwords are determined to not work regardless
        if ($this->rcmail->config->get(__("dont_try_mail_password"), false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"]) ||
                empty($prefs["nextcloud_login"]["appPassword"])) {
                return ['status' => 'login_required'];
            }
        }

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $this->rcmail->get_user_password();

        //test webdav login
        try {
            $res = $this->client->request("PROPFIND", $server . "/remote.php/dav/files/" . $username, ['auth' => [$username, $password]]);
            /** @noinspection SpellCheckingInspection */
            $scode = $res->getStatusCode();
            switch ($scode) {
                case 401:
                case 403:
                    unset($prefs["nextcloud_login"]);
                    $this->rcmail->user->save_prefs($prefs);
                    //we can't use the password
                    $_SESSION['plugins']['nextcloud_attachments']['login_result'] = ['status' => 'login_required'];
                    return ['status' => 'login_required'];
                case 404:
                    unset($prefs["nextcloud_login"]);
                    $this->rcmail->user->save_prefs($prefs);
                    //the username does not exist
                    $_SESSION['plugins']['nextcloud_attachments']['login_result'] = ['status' => 'invalid_user'];
                    return ['status' => 'invalid_user'];
                case 200:
                case 207:
                    //we can log in
                    return ['status' => 'ok'];
                default:
                    // Persist for client error codes. keep trying for server errors
                    if ($scode < 500) {
                        $_SESSION['plugins']['nextcloud_attachments']['login_result'] =
                            ['status' => null, 'code' => $scode, 'message' => $res->getReasonPhrase()];
                    }
                    //Probably bad idea as a single 500 error will kill the logins of all active users
                    //unset($prefs["nextcloud_login"]);
                    //$this->rcmail->user->save_prefs($prefs);
                    //something weired happened
                    return ['status' => null, 'code' => $scode, 'message' => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($this->rcmail->get_user_name() . " login check request failed: " . print_r($e, true));
            return ['status' => null];
        }
    }

    /**
     * Action to log out and delete app password if possible
     * @return void
     */
    public function logout(): void
    {
        $prefs = $this->rcmail->user->get_prefs();

        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());
        $password = $prefs["nextcloud_login"]["appPassword"];

        if (isset($password)) {
            $server = $this->rcmail->config->get(__("server"));

            if (!empty($server)) {
                try {
                    /** @noinspection SpellCheckingInspection */
                    $this->client->delete($server . "/ocs/v2.php/core/apppassword", [
                        'headers' => [
                            'OCS-APIRequest' => 'true'
                        ],
                        'auth' => [$username, $password]
                    ]);
                } catch (GuzzleException) {
                }
            }
        }

        $prefs["nextcloud_login"] = null;
        unset($_SESSION['plugins']['nextcloud_attachments']);
        $this->rcmail->user->save_prefs($prefs);
        $this->rcmail->output->command('command', 'save');
    }

    /**
     * Hook to periodically check login result
     *
     */
    public function poll($param): void
    {
        //check if there is poll endpoint
        if (isset($_SESSION['plugins']['nextcloud_attachments']['endpoint']) && isset($_SESSION['plugins']['nextcloud_attachments']['token'])) {
            //poll it
            try {
                $res = $this->client->post($_SESSION['plugins']['nextcloud_attachments']['endpoint'] . "?token=" . $_SESSION['plugins']['nextcloud_attachments']['token']);

                //user finished login
                if ($res->getStatusCode() == 200) {
                    $body = $res->getBody()->getContents();
                    $data = json_decode($body, true);
                    if (isset($data['appPassword']) && isset($data['loginName'])) {
                        //save app password to user preferences
                        $prefs = $this->rcmail->user->get_prefs();
                        $prefs["nextcloud_login"] = $data;
                        $this->rcmail->user->save_prefs($prefs);
                        unset($_SESSION['plugins']['nextcloud_attachments']);
                        if ($param["task"] == "settings") {
                            $this->rcmail->output->command('command', 'save');
                        }
                        $this->rcmail->output->command('plugin.nextcloud_login_result', ['status' => "ok"]);
                    }
                } else if ($res->getStatusCode() != 404) { //login timed out
                    unset($_SESSION['plugins']['nextcloud_attachments']);
                }
            } catch (GuzzleException $e) {
                self::log("poll failed: " . print_r($e, true));
            }
        }
    }
}