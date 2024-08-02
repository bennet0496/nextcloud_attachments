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

trait Actions
{
    /**
     * Action to check nextcloud login status
     * @return void
     */
    public function check_login(): void
    {
        $this->rcmail->output->command('plugin.nextcloud_login_result', $this->__check_login());
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
}

