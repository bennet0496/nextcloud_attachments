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


namespace NextcloudAttachments;

use GuzzleHttp\Exception\GuzzleException;

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
            $_SESSION['plugins']['nextcloud_attachments']['login'] = $data['poll'];

            $this->rcmail->output->command('plugin.nextcloud_login', ['status' => "ok", "url" => $data["login"]]);
        } catch (GuzzleException $e) {
            self::log_exception($this->rcmail->get_user_name() . " login request failed", $e);
            $this->rcmail->output->command('plugin.nextcloud_login', ['status' => null]);
        }
    }

    private function login_status(): array
    {
        //Cached Result
        if (!empty($_SESSION['plugins']['nextcloud_attachments']['login']['login_result'])) {
            return $_SESSION['plugins']['nextcloud_attachments']['login']['login_result'];
        }

        $server = $this->rcmail->config->get(__("server"));

        //missing config
        if (empty($server) || empty($this->resolve_username())) {
            return ['status' => null];
        }

        //always prompt for app password, as mail passwords are determined to not work regardless
        if ($this->rcmail->config->get(__("dont_try_mail_password"), false) && !$this->has_app_password()) {
            return ['status' => 'login_required'];
        }

        $creds = $this->get_credentials();

        //test webdav login
        try {
            $res = $this->client->request("PROPFIND", $this->dav_files_uri($creds['user']), ['auth' => [$creds["user"], $creds["password"]]]);
            /** @noinspection SpellCheckingInspection */
            $scode = $res->getStatusCode();
            switch ($scode) {
                case 401:
                    //we can't use the password. an invalid app password can't be revoked anymore
                    $this->forget_app_password(false);
                    $_SESSION['plugins']['nextcloud_attachments']['login']['login_result'] = ['status' => 'login_required'];
                    return ['status' => 'login_required'];
                case 404:
                    $this->forget_app_password(true);
                    //the username does not exist
                    $_SESSION['plugins']['nextcloud_attachments']['login']['login_result'] = ['status' => 'invalid_user'];
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
                    //something weired happened
                    return ['status' => null, 'code' => $scode, 'message' => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log_exception($this->rcmail->get_user_name() . " login check request failed", $e);
            return ['status' => null];
        }
    }

    /**
     * Action to log out and delete app password if possible
     * @return void
     */
    public function logout(): void
    {
        $this->forget_app_password(true);
        unset($_SESSION['plugins']['nextcloud_attachments']['login']);
        $this->rcmail->output->command('command', 'save');
    }

    /**
     * Hook to periodically check login result
     *
     */
    public function poll($param): void
    {
        //check if there is poll endpoint
        if (isset($_SESSION['plugins']['nextcloud_attachments']['login']['endpoint']) && isset($_SESSION['plugins']['nextcloud_attachments']['login']['token'])) {
            //poll it
            try {
                $res = $this->client->post($_SESSION['plugins']['nextcloud_attachments']['login']['endpoint'] . "?token=" . $_SESSION['plugins']['nextcloud_attachments']['login']['token']);

                //user finished login
                if ($res->getStatusCode() == 200) {
                    $body = $res->getBody()->getContents();
                    $data = json_decode($body, true);
                    if (isset($data['appPassword']) && isset($data['loginName'])) {
                        //save app password to user preferences
                        $this->store_app_password($data['loginName'], $data['appPassword']);
                        unset($_SESSION['plugins']['nextcloud_attachments']['login']);
                        if ($param["task"] == "settings") {
                            $this->rcmail->output->command('command', 'save');
                        }
                        $this->rcmail->output->command('plugin.nextcloud_login_result', ['status' => "ok"]);
                    }
                } else if ($res->getStatusCode() != 404) { //login timed out
                    unset($_SESSION['plugins']['nextcloud_attachments']['login']);
                }
            } catch (GuzzleException $e) {
                self::log_exception("poll failed", $e);
            }
        }
    }

    private function has_app_password(): bool
    {
        $prefs = $this->rcmail->user->get_prefs()["nextcloud_login"] ?? null;
        return !empty($prefs["loginName"]) && !empty($prefs["appPassword"]);
    }

    /**
     * Credentials to use for Nextcloud.
     * app password from the login flow if present,the Roundcube login otherwise.
     *
     */
    private function get_credentials(): array
    {
        if ($this->has_app_password()) {
            $prefs = $this->rcmail->user->get_prefs();

            // migrate to encrypted passwords
            if (empty($prefs["nextcloud_login"]["encrypted"])) {
                if ($prefs["nextcloud_login"]["appPassword"] = $this->rcmail->encrypt($prefs["nextcloud_login"]["appPassword"])){
                    $prefs["nextcloud_login"]["encrypted"] = true;
                    $this->rcmail->user->save_prefs($prefs);
                } else {
                    $prefs = $this->rcmail->user->get_prefs(); //error: discard changes
                }
            }

            $nc_prefs = $prefs["nextcloud_login"];

            $password = empty($nc_prefs["encrypted"]) ? $nc_prefs["appPassword"] : $this->rcmail->decrypt($nc_prefs["appPassword"]);
            return [
                "user" => $nc_prefs["loginName"],
                "password" => (string)$password,
            ];
        }

        $username = $this->resolve_username($this->rcmail->get_user_name());
        return ["user" => $username, "password" => (string)$this->rcmail->get_user_password()];
    }

    private function store_app_password(string $login_name, string $app_password): void
    {
        $encrypted = $this->rcmail->encrypt($app_password);

        $prefs = $this->rcmail->user->get_prefs();
        $prefs["nextcloud_login"] = [
            "loginName" => $login_name,
            "appPassword" => $encrypted === false ? $app_password : $encrypted,
            "encrypted" => $encrypted !== false,
        ];
        $this->rcmail->user->save_prefs($prefs);
    }

    /**
     * Remove the stored app password, and revoke it on the server if requested
     */
    private function forget_app_password(bool $revoke): void
    {
        if (!$this->has_app_password()) {
            return;
        }

        if ($revoke) {
            $creds = $this->get_credentials();
            try {
                /** @noinspection SpellCheckingInspection */
                $this->client->delete($this->rcmail->config->get(__("server")) . "/ocs/v2.php/core/apppassword", [
                    'headers' => ['OCS-APIRequest' => 'true'],
                    'auth' => [$creds["user"], $creds["password"]]
                ]);
            } catch (GuzzleException $e) {
                self::log_exception($creds["user"] . " app password revocation failed", $e);
            }
        }

        $prefs = $this->rcmail->user->get_prefs();
        // must be null, unset keys are merged back by save_prefs
        $prefs["nextcloud_login"] = null;
        $this->rcmail->user->save_prefs($prefs);
    }
}