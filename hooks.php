<?php
/*
 * Copyright (c) 2024 bbecker
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
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use IntlDateFormatter;
use Modifiable_Mail_mime;
use rcube_utils;
use SimpleXMLElement;

require_once dirname(__FILE__) . "/Modifiable_Mail_mime.php";

trait Hooks
{
    /**
     * Hook to add info to compose preferences
     * @param $param array preferences list
     * @return array preferences list
     */
    public function add_preferences(array $param): array
    {
        $prefs = $this->rcmail->user->get_prefs();

//        self::log($prefs);

        $server = $this->rcmail->config->get(__("server"));
        $blocks = $param["blocks"];

        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());

        $login_result = $this->__check_login();

        $can_disconnect = isset($prefs["nextcloud_login"]);

        if ($param["current"] == "compose") {
            //get the attachment sub folder
            $folder = $this->get_folder();

            $date_format = datefmt_create(
                $this->rcmail->get_user_language(),
                IntlDateFormatter::FULL,
                IntlDateFormatter::FULL,
                null,
                IntlDateFormatter::GREGORIAN,
                $tokens[1] ?? "Y/LLLL"
            );

            $layout_select = new \html_select(["id" => __("folder_layout"), "value" => $prefs[__("user_folder_layout")] ?? "default", "name" => "_".__("folder_layout")]);
            $pp_links = new \html_checkbox(["id" => __("password_protected_links"), "value" => "1", "name" => "_".__("password_protected_links")]);
            $exp_links = new \html_checkbox(["id" => __("expire_links"), "value" => "1", "name" => "_".__("expire_links")]);

            $server_format_tokens = explode(":", $this->rcmail->config->get(__("folder_layout"), "flat"));

//            self::log($server_format_tokens);

            $server_format = match ($server_format_tokens[0]) {
                "flat" => $this->gettext("folder_layout_flat"),
                "date" => $this->gettext("folder_layout_date"). " (/" . $folder . "/" . (
                    function ($fmt) use ($date_format) {
                        $date_format->setPattern($fmt ?? "Y/LLLL");
                        return $date_format->format(time());
                    }
                    )($server_format_tokens[1] ?? "Y/LLLL")."/...)",
                "hash" => $this->gettext("folder_layout_hash"). " (/" . $folder . "/" .(
                    function ($method, $depth) {
                        $hash = hash($method, "");
                        $hash_bytes = str_split($hash, 2);
                        $hash_n_bytes = array_slice($hash_bytes, 0, $depth);
                        return implode("/", $hash_n_bytes) . substr($hash, 2 * $depth);
                    })($server_format_tokens[1] ?? "sha1", $server_format_tokens[2] ?? 2)."/...)"
            };

            $layout_select->add([
                $this->gettext("folder_layout_default").$server_format,
                $this->gettext("folder_layout_flat")
            ], ["default", "flat"]);

            $formats = ["Y", "Y/LLLL", "Y/LLLL/dd", "Y/LL", "Y/LL/dd", "Y/ww","Y/ww/EEEE","Y/ww/E"];

            foreach ($formats as $format) {
                $date_format->setPattern($format);
                $ex = $date_format->format(time());
                $layout_select->add($this->gettext("folder_layout_date_".$format)." (/".$folder."/".$ex."/...)", "date:".$format);
            }

            $layout_select->add([
                $this->gettext("folder_layout_hash")." (/".$folder."/ad/c8/...)",
                $this->gettext("folder_layout_hash")." (/".$folder."/ad/c8/3b/...)",
                $this->gettext("folder_layout_hash")." (/".$folder."/ad/c8/3b/19/...)",
                $this->gettext("folder_layout_hash")." (/".$folder."/ad/c8/3b/19/e7/...)",
            ], ["hash:sha1:2", "hash:sha1:3", "hash:sha1:4", "hash:sha1:5"]);

            /** @noinspection JSUnresolvedReference */
            $blocks["plugin.nextcloud_attachments"] = [
                "name" => $this->gettext("cloud_attachments"),
                "options" => [
                    "server" => [
                        "title" => $this->gettext("cloud_server"),
                        "content" => "<a href='" . $server . "' target='_blank'>" . parse_url($server, PHP_URL_HOST) . "</a>"
                    ],
                    "connection" => [
                        "title" => $this->gettext("status"),
                        "content" => $login_result["status"] == "ok" ?
                            $this->gettext("connected_as") . " " . $username . ($can_disconnect ? " (<a href=\"#\" onclick=\"rcmail.http_post('plugin.nextcloud_disconnect')\">" . $this->gettext("disconnect") . "</a>)" : "") :
                            $this->gettext("not_connected") . " (<a href=\"#\" onclick=\"window.rcmail.nextcloud_login_button_click_handler(null, null)\">" . $this->gettext("connect") . "</a>)"
                    ],


                ]
            ];

            if (!$this->rcmail->config->get("nextcloud_attachment_folder_layout_locked", true)) {
                $blocks["plugin.nextcloud_attachments"]["options"]["folder_layout"] = [
                    "title" => $this->gettext("folder_layout"),
                    "content" => $layout_select->show()
                ];
            }

            if (!$this->rcmail->config->get("nextcloud_attachment_password_protected_links_locked", true)) {
                $def = $this->rcmail->config->get("nextcloud_attachment_password_protected_links", false) ? "1" : "0";
                $blocks["plugin.nextcloud_attachments"]["options"]["password_protected_links"] = [
                    "title" => $this->gettext("password_protected_links"),
                    "content" => $pp_links->show($prefs[__("user_password_protected_links")] ?? $def)
                ];
            }

            if (!$this->rcmail->config->get(__("expire_links_locked"), true)) {
                $def = $this->rcmail->config->get(__("expire_links"), false);
                $blocks["plugin.nextcloud_attachments"]["options"]["expire_links"] = [
                    "title" => $this->gettext("expire_links"),
                    "content" => $exp_links->show($prefs[__("user_expire_links")] ?? ($def === false ? "0" : "1"))
                ];
                $blocks["plugin.nextcloud_attachments"]["options"]["expire_links_after"] = [
                    "title" => $this->gettext("expire_links_after"),
                    "content" => (new \html_inputfield([
                        "type" => "number",
                        "min" => "1",
                        "id" => __("expire_links_after"),
                        "name" => "_".__("expire_links_after"),
                        "value" => $prefs[__("user_expire_links_after")] ?? ($def !== false ? $def : 7)]))->show()
                ];
            }
        }

        return ["blocks" => $blocks];
    }

    private function save_preferences(array $param) : array
    {
        if ($param["section"] != "compose") {
            return $param;
        }

        $formats = ["default", "flat", "date:Y", "date:Y/LLLL", "date:Y/LLLL/dd", "date:Y/LL", "date:Y/LL/dd",
            "date:Y/ww","date:Y/ww/EEEE","date:Y/ww/E", "hash:sha1:2", "hash:sha1:3", "hash:sha1:4", "hash:sha1:5"];
        $folder_layout = $_POST["_".__("folder_layout")] ?? null;
        if (!$this->rcmail->config->get("nextcloud_attachment_folder_layout_locked", true) &&
            in_array($folder_layout, $formats)) {
            $param["prefs"][__("user_folder_layout")] = $folder_layout;
        }

        $pass_prot = $_POST["_".__("password_protected_links")] ?? null;
        if (!$this->rcmail->config->get("nextcloud_attachment_password_protected_links_locked", true) && $pass_prot == 1) {
            $param["prefs"][__("user_password_protected_links")] = true;
        }

        $expire_links = $_POST["_".__("expire_links")] ?? null;
        $expire_links_after = $_POST["_".__("expire_links_after")] ?? null;
        if (!$this->rcmail->config->get("nextcloud_attachment_expire_links_locked", true)) {
            $param["prefs"][__("user_expire_links")] = ($expire_links == 1 && intval($expire_links_after) > 0);
            if (intval($expire_links_after) > 0) {
                $param["prefs"][__("user_expire_links_after")] = intval($expire_links_after);
            }
        }

//        self::log($param);

        return $param;
    }

    /**
     * (message_ready hook) correct attachment parameters for nextcloud attachments where
     * parameters couldn't be set otherwise
     *
     * @param array $args original message
     * @return Modifiable_Mail_mime[] corrected message
     */
    public function fix_attachment(array $args): array
    {
        $msg = new Modifiable_Mail_mime($args["message"]);

        foreach ($msg->getParts() as $key => $part) {
            if (str_starts_with($part['c_type'], "application/nextcloud_attachment")) {
                $url = substr(trim(explode(";", $part['c_type'])[1]), strlen("url="));
                $part["disposition"] = "inline";
                $part["c_type"] = "text/html";
                $part["encoding"] = "quoted-printable"; // We don't want the base64 overhead for the few kb HTML file
                $part["add_headers"] = [
                    "X-Mozilla-Cloud-Part" => "cloudFile; url=" . $url
                ];
                $msg->setPart($key, $part);
            }
        }
        return ["message" => $msg];
    }

    /**
     * Ready hook to intercept files marked for cloud upload.
     *
     * We set the filesize to 0 to pass the internal filesize checking.
     * Luckily they don't check the actual file
     *
     * @param $param mixed ignored
     * @noinspection PhpUnusedParameterInspection
     */
    public function intercept_filesize(mixed $param): void
    {
        // files are marked to cloud upload
        if (isset($_REQUEST['_target']) && $_REQUEST['_target'] == "cloud") {
            if (isset($_FILES["_attachments"]) && count($_FILES["_attachments"]) > 0) {
                //set file sizes to 0 so rcmail_action_mail_attachment_upload::run() will not reject the files,
                //so we can get it from rcube_uploads::insert_uploaded_file() later
                $_FILES["_attachments"]["size"] = array_map(function ($e) {
                    return 0;
                }, $_FILES["_attachments"]["size"]);
            } else {
                self::log($this->rcmail->get_user_name() . " - empty attachment array: " . print_r($_FILES, true));
            }
        }
    }

    /**
     * Ready hook to insert our client script and style.
     *
     * @param $param mixed page information
     * @noinspection PhpUnusedParameterInspection
     */
    public function insert_client_code(mixed $param): void
    {
        $section = rcube_utils::get_input_string('_section', rcube_utils::INPUT_GPC);

        if ((($param["task"] == "mail" && $param["action"] == "compose") ||
                ($param["task"] == "settings" && $param["action"] == "edit-prefs" && $section == "compose")) &&
            !$this->is_disabled()) {

            $this->load_config();


            $this->include_script("client.js");
            $this->include_stylesheet("client.css");

            $softlimit = parse_bytes($this->rcmail->config->get(__("softlimit")));
            $limit = parse_bytes($this->rcmail->config->get('max_message_size'));
            $this->rcmail->output->set_env(__("softlimit"), $softlimit > $limit ? null : $softlimit);
            $this->rcmail->output->set_env(__("behavior"), $this->rcmail->config->get(__("behavior"), "prompt"));
        }
    }

    /**
     * Hook to periodically check login result
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function poll($ignore): void
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

    /**
     * Hook to upload file
     *
     * Return upload information
     *
     * @param $data array attachment info
     * @return array attachment info
     */
    public function upload(array $data): array
    {
        if (!isset($_REQUEST['_target']) || $_REQUEST['_target'] !== "cloud") {
            //file not marked to cloud. we won't touch it.
            return $data;
        }

        $prefs = $this->rcmail->user->get_prefs();

        // we are not logged in, and know mail password won't work, so we are not trying anything
        if ($this->rcmail->config->get(__("dont_try_mail_password"), false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"]) ||
                empty($prefs["nextcloud_login"]["appPassword"])) {
                return ["status" => false, "abort" => true];
            }
        }

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $this->rcmail->get_user_password();

        $server = $this->rcmail->config->get(__("server"));
        $checksum = $this->rcmail->config->get(__("checksum"), "sha256");

        //server not configured
        if (empty($server) || $username === false) {
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'no_config']);
            return ["status" => false, "abort" => true];
        }

        // we are not logged in, and know mail password won't work, so we are not trying anything
        if ($this->rcmail->config->get(__("dont_try_mail_password"), false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"]) ||
                empty($prefs["nextcloud_login"]["appPassword"])) {
                return ["status" => false, "abort" => true];
            }
        }

        //get the attachment sub folder
        $folder = $this->get_folder();

        //full link with urlencoded folder (space must be %20 and not +)
        $folder_uri = $server . "/remote.php/dav/files/" . $username . "/" . rawurlencode($folder);

        //check folder
        try {
            $res = $this->client->request("PROPFIND", $folder_uri, ['auth' => [$username, $password]]);

            if ($res->getStatusCode() == 404) { //folder does not exist
                //attempt to create the folder
                try {
                    if ($this->rcmail->config->get(__("sync_exclude_lst"), false)) {
                        $this->update_exclude($username, $password, $server . "/remote.php/dav/files/" . $username, $folder);
                    }
                    $res = $this->client->request("MKCOL", $folder_uri, ['auth' => [$username, $password]]);

                    if ($res->getStatusCode() != 201) { //creation failed
                        $body = $res->getBody()->getContents();
                        try {
                            $xml = new SimpleXMLElement($body);
                        } catch (Exception $e) {
                            self::log($username . " xml parsing failed: " . print_r($e, true));
                            $xml = [];
                        }

                        $this->rcmail->output->command('plugin.nextcloud_upload_result', [
                            'status' => 'mkdir_error',
                            'code' => $res->getStatusCode(),
                            'message' => $res->getReasonPhrase(),
                            'result' => json_encode($xml)
                        ]);

                        self::log($username . " mkcol failed " . $res->getStatusCode() . PHP_EOL . $res->getBody()->getContents());
                        return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
                    }
                } catch (GuzzleException $e) {
                    self::log($username . " mkcol request failed: " . print_r($e, true));
                }
            } else if ($res->getStatusCode() > 400) { //we can't access the folder
                self::log($username . " propfind failed " . $res->getStatusCode() . PHP_EOL . $res->getBody()->getContents());
                $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username . " propfind failed " . print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
            return ["status" => false, "abort" => true];
        }

        // resolve the folder layout
        $folder_suffix = $this->resolve_destination_folder($folder_uri, $data["path"], $username, $password);
        $folder .= "/".$folder_suffix;
        $folder_uri .= "/".$folder_suffix;
//        self::log($folder_uri);

        //get unique filename
        $filename = $this->unique_filename($folder_uri, $data["name"], $username, $password);

        if ($filename === false) {
            self::log($username . " filename determination failed");
            //it was not possible to find name
            //too many files?
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'name_error']);
            return ["status" => false, "abort" => true];
        }

        //upload file
        $body = Psr7\Utils::tryFopen($data["path"], 'r');
        try {
            $res = $this->client->put($folder_uri . "/" . rawurlencode($filename), ["body" => $body, 'auth' => [$username, $password]]);

            if ($res->getStatusCode() != 200 && $res->getStatusCode() != 201) {
                $body = $res->getBody()->getContents();
                try {
                    $xml = new SimpleXMLElement($body);
                } catch (Exception $e) {
                    self::log($username . " xml parsing failed: " . print_r($e, true));
                    $xml = [];
                }

                $this->rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'upload_error', 'message' => $res->getReasonPhrase(), 'result' => json_encode($xml)]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username . " put failed: " . print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'upload_error']);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //create share link
        $id = rand();
        $mime_name = str_replace("/", "-", $data["mimetype"]);
        $mime_generic_name = str_replace("/", "-", explode("/", $data["mimetype"])[0]) . "-x-generic";

        $icon_path = dirname(__FILE__) . "/icons/Yaru-mimetypes/";
        $mime_icon = file_exists($icon_path . $mime_name . ".png") ?
            file_get_contents($icon_path . $mime_name . ".png") : (
            file_exists($icon_path . $mime_generic_name . ".png") ?
                file_get_contents($icon_path . $mime_generic_name . ".png") :
                file_get_contents($icon_path . "unknown.png"));


        $exp_links = $this->rcmail->config->get(__("expire_links"), false);
        $exp_links_lock = $this->rcmail->config->get(__("expire_links_locked"), false);
        $exp_links_user = $prefs[__("user_expire_links")] ? $prefs[__("user_expire_links_after")] : false;

        $form_params = [];
        if ($exp_links > 0 || (!$exp_links_lock && $exp_links_user)) {
            $expiry_days = $exp_links ?
                                ($exp_links_lock ? $exp_links : ($exp_links_user ?: 7)) : // global defined
                                ($exp_links_lock ? 7 : (($exp_links_user ?: 7))); // global undefined
            $expire_date = new \DateTime();
            $interval = \DateInterval::createFromDateString($expiry_days ." days");

            $expire_date->add($interval);

            $form_params["expireDate"] = $expire_date->format(DATE_ATOM);
        }

        $pass_links = $this->rcmail->config->get(__("password_protected_links"), false);
        $pass_links_lock = $this->rcmail->config->get(__("password_protected_links_locked"), false);
        $pass_links_user = $prefs[__("user_password_protected_links")] ?? false;

        if ($pass_links || (!$pass_links_lock && $pass_links_user)) {
            $share_password = "";
            while(strlen($share_password) <= 12) {
                $char = openssl_random_pseudo_bytes(1); //ascii 0-z
                if (preg_match("/[a-zA-Z0-9]/", $char)) {
                    $share_password .= $char;
                }
            }
            $form_params["password"] = $share_password;
//            self::log($share_password);
        }

        try {
//            self::log($form_params);
            $res = $this->client->post($server . "/ocs/v2.php/apps/files_sharing/api/v1/shares", [
                "headers" => [
                    "OCS-APIRequest" => "true"
                ],
                "form_params" => [
                    "path" => $folder . "/" . $filename,
                    "shareType" => 3,
                    "publicUpload" => "false",
                    ...$form_params
                ],
                'auth' => [$username, $password]
            ]);

            $body = $res->getBody()->getContents();
//            self::log($form_params);
            if ($res->getStatusCode() == 200) { //upload successful
                $ocs = new SimpleXMLElement($body);
                //inform client for insert to body
                $this->rcmail->output->command("plugin.nextcloud_upload_result", [
                    'status' => 'ok',
                    'result' => [
                        'url' => (string)$ocs->data->url,
                        'file' => [
                            'name' => $data["name"],
                            'size' => filesize($data["path"]),
                            'mimetype' => $data["mimetype"],
                            'mimeicon' => base64_encode($mime_icon),
                            'id' => $id,
                            'group' => $data["group"],
                            ...$form_params
                        ]
                    ]
                ]);
                $url = (string)$ocs->data->url;
            } else { //link creation failed. Permission issue?
                $body = $res->getBody()->getContents();
                try {
                    $xml = new SimpleXMLElement($body);
                } catch (Exception $e) {
                    self::log($body);
                    self::log($username . " xml parse failed: " . print_r($e, true));
                    $xml = [];
                }
                $this->rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'link_error',
                    'code' => $res->getStatusCode(),
                    'message' => $res->getReasonPhrase(),
                    'result' => json_encode($xml)
                ]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username . " share file failed: " . print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        } catch (Exception $e) {
            self::log($username . " xml parse failed: " . print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        }

        $tmpl = "";

        if (isset($form_params["password"])) {
            //fill out template attachment HTML with Password
            $tmpl = file_get_contents(dirname(__FILE__) . "/attachment_tmpl_pass.html");
        } else {
            //fill out template attachment HTML
            $tmpl = file_get_contents(dirname(__FILE__) . "/attachment_tmpl.html");
        }

        $fs = filesize($data["path"]);
        $u = ["", "k", "M", "G", "T"];
        for ($i = 0; $fs > 800.0 && $i <= count($u); $i++) {
            $fs /= 1024;
        }


        $tmpl = str_replace("%FILENAME%", $data["name"], $tmpl);
        /** @noinspection SpellCheckingInspection */
        $tmpl = str_replace("%FILEURL%", $url, $tmpl);
        /** @noinspection SpellCheckingInspection */
        $tmpl = str_replace("%SERVERURL%", $server, $tmpl);
        $tmpl = str_replace("%FILESIZE%", round($fs, 1) . " " . $u[$i] . "B", $tmpl);
        /** @noinspection SpellCheckingInspection */
        $tmpl = str_replace("%ICONBLOB%", base64_encode($mime_icon), $tmpl);
        $tmpl = str_replace("%CHECKSUM%", strtoupper($checksum) . " " . hash_file($checksum, $data["path"]), $tmpl);

        if (isset($form_params["password"])){
            $tmpl = str_replace("%PASSWORD%", $form_params["password"], $tmpl);
        }

        if (isset($form_params["expireDate"]) && isset($expire_date)){
            $tmpl = str_replace("%VALIDUNTIL%", $expire_date->format("Y-m-d"), $tmpl);
        } else {
            $tmpl = str_replace("%VALIDUNTIL%", "deletion", $tmpl);
        }

        // Minimize HTML
        // https://stackoverflow.com/a/6225706
        $search = array(
            '/>[^\S ]+/',     // strip whitespaces after tags, except space
            '/[^\S ]+</',     // strip whitespaces before tags, except space
            '/(\s)+/',         // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/' // Remove HTML comments
        );

        $replace = array(
            '>',
            '<',
            '\\1',
            ''
        );

        $tmpl = preg_replace($search, $replace, $tmpl);

        unlink($data["path"]);

        //return a html page as attachment that provides the download link
        return [
            "id" => $id,
            "group" => $data["group"],
            "status" => true,
            "name" => $data["name"] . ".html", //append html suffix
            "mimetype" => "text/html",
            "data" => $tmpl, //just return the few KB text, we deleted the file
            "path" => "cloud:".$folder . "/" . $filename,
            "size" => strlen($tmpl),
            "target" => "cloud", //cloud attachment meta data
            "uri" => $url . "/download",
            "break" => true //no other plugin should process this attachment future
        ];
    }

    /**
     * Hook to delete removed attachment from nextcloud
     *
     * @param array $param attachment info
     * @return array|string[] status info
     */
    public function delete(array $param): array
    {
        if ($_POST["_ncremove"] === "true" &&
            str_starts_with($param["path"], "cloud:") && $param["target"] === "cloud") {
            $prefs = $this->rcmail->user->get_prefs();

            $server = $this->rcmail->config->get(__("server"));
            $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());
            $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $this->rcmail->get_user_password();

            $path = implode("/", array_map(function ($tok) { return rawurlencode($tok); },
                explode("/", substr($param["path"], strlen("cloud:")))));
            try {
                $res = $this->client->delete($server . "/remote.php/dav/files/" . $username . "/" . $path, ["auth" => [$username, $password]]);
                if($res->getStatusCode() >= 400) {
                    $this->rcmail->output->command('plugin.nextcloud_delete_result', ['status' => 'error', 'message' => $res->getReasonPhrase(), 'file' => $param['id']]);
                    return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
                } else {
                    return ["status" => "ok"];
                }
            } catch (GuzzleException $e) {
                $this->rcmail->output->command('plugin.nextcloud_delete_result', ['status' => 'error', 'file' => $param['id']]);
                self::log($username . " delete request failed: " . print_r($e, true));
            }
        }

        return $param;
    }

    /**
     * @return mixed
     */
    protected function get_folder(): mixed
    {
        $folder = $this->rcmail->config->get(__("folder"), "Mail Attachments");
        $tr_folder = $this->rcmail->config->get(__("folder_translate_name"), false);
        if (is_array($folder)) {
            if ($tr_folder && key_exists($this->rcmail->get_user_language(), $folder)) {
                $folder = $folder[$this->rcmail->get_user_language()];
            } else if ($tr_folder && key_exists("en_US", $folder)) {
                $folder = $folder["en_US"];
            } else {
                $folder = array_first($folder);
            }
        }
        return $folder;
    }
}