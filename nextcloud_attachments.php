<?php
// Copyright (c) 2023 Bennet Becker <dev@bennet.cc>
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

if(!class_exists("GuzzleHttp\Client")) {
    (include dirname(__FILE__) . "/vendor/autoload.php") or die("please run 'composer require' in the nextcloud_attachments plugin folder");
}

require_once dirname(__FILE__)."/Modifiable_Mail_mime.php";

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;

const NC_LOG_NAME = "nextcloud_attachments";
const NC_LOG_FILE = "ncattach";

const VERSION = "1.2.1";


/** @noinspection PhpUnused */
class nextcloud_attachments extends rcube_plugin
{

    private rcmail $rcmail;
    private GuzzleHttp\Client $client;

    private static function log($line): void
    {
        $lines = explode(PHP_EOL, $line);
        rcmail::write_log(NC_LOG_FILE, "[".NC_LOG_NAME."] ".$lines[0]);
        unset($lines[0]);
        if (count($lines) > 0) {
            foreach ($lines as $l) {
                rcmail::write_log(NC_LOG_FILE, str_pad("...",strlen("[".NC_LOG_NAME."] "), " ", STR_PAD_BOTH).$l);
            }
        }
    }
    public function init(): void
    {
        $this->rcmail = rcmail::get_instance();
        $this->load_config();

        $ex = $this->rcmail->config->get("nextcloud_attachment_exclude_users", []);

        if (is_array($ex) && in_array($this->rcmail->get_user_name(), $ex)) {
           return;
        }

        $this->client = new GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Roundcube Nextcloud Attachment Connector/'.VERSION,
            ],
            'http_errors' => false,
            'verify' => $this->rcmail->config->get("nextcloud_attachment_verify_https", true)
        ]);


        $this->add_texts("l10n/", true);

        //action to check if we have a usable login
        /** @noinspection SpellCheckingInspection */
        $this->register_action('plugin.nextcloud_checklogin', [$this, 'check_login']);

        //action to trigger login flow
        $this->register_action('plugin.nextcloud_login', [$this, 'login']);

        //action to log out
        $this->register_action('plugin.nextcloud_disconnect', [$this, 'logout']);

        //Intercept filesize for marked files
        $this->add_hook("ready", [$this, 'intercept_filesize']);

        //insert our client script and style
        $this->add_hook("ready",function ($param) {
            $section = rcube_utils::get_input_string('_section', rcube_utils::INPUT_GPC);

            if (($param["task"] == "mail" && $param["action"] == "compose") ||
                ($param["task"] == "settings" && $param["action"] == "edit-prefs" && $section == "compose")){

                $rcmail = rcmail::get_instance();
                $this->load_config();


                $this->include_script("client.js");
                $this->include_stylesheet("client.css");

                $softllimit = parse_bytes($rcmail->config->get("nextcloud_attachment_softlimit", null));
                $limit  = parse_bytes($rcmail->config->get('max_message_size'));
                $rcmail->output->set_env("nextcloud_attachment_softlimit", $softllimit > $limit ? null : $softllimit);
                $rcmail->output->set_env("nextcloud_attachment_behavior", $rcmail->config->get("nextcloud_attachment_behavior", "prompt"));
            }
        });

        //insert our client script and style
        $this->add_hook('settings_actions', function ($params) {
            $this->include_script("client.js");
            $this->include_stylesheet("client.css");
            return $params;
        });



        //correct the cloud attachment size for retrieval
        $this->add_hook('attachment_get', function ($param) {
//            self::log(print_r($param, true));
            if ($param["target"] === "cloud") {
                $param["mimetype"] = "application/nextcloud_attachment; url=".$param["uri"]; //Mark attachment for later interception
                $param["status"] = true;
                $param["size"] = strlen($param["data"]);
                $param["path"] = null;
            }
            return $param;
        });

        //intercept to change attachment encoding
        $this->add_hook("message_ready", [$this, 'fix_attachment']);

        //login flow poll
        $this->add_hook("refresh", [$this, 'poll']);

        //hook to upload the file
        $this->add_hook('attachment_upload', [$this, 'upload']);

        $this->add_hook('preferences_list', [$this, 'add_preferences']);


    }

    /**
     * Hook to add info to compose preferences
     * @param $param array preferences list
     * @return array preferences list
     */
    public function add_preferences(array $param): array
    {
        $prefs = $this->rcmail->user->get_prefs();
//        $this->load_config();

        $server = $this->rcmail->config->get("nextcloud_attachment_server");
        $blocks = $param["blocks"];

        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());

        $login_result = $this->__check_login();

        $can_disconnect = isset($prefs["nextcloud_login"]);

        if ($param["current"] == "compose") {

            /** @noinspection JSUnresolvedReference */
            $blocks["plugin.nextcloud_attachments"] = [
                "name" => $this->gettext("cloud_attachments"),
                "options" => [
                    "server" => [
                        "title" => $this->gettext("cloud_server"),
                        "content" => "<a href='".$server."' target='_blank'>".parse_url($server,  PHP_URL_HOST)."</a>"
                    ],
                    "connection" => [
                        "title" => $this->gettext("status"),
                        "content" => $login_result["status"] == "ok" ?
                            $this->gettext("connected_as")." ".$username.($can_disconnect ? " (<a href=\"#\" onclick=\"rcmail.http_post('plugin.nextcloud_disconnect')\">".$this->gettext("disconnect")."</a>)" : "" ):
                            $this->gettext("not_connected")." (<a href=\"#\" onclick=\"window.rcmail.nextcloud_login_button_click_handler(null, null)\">".$this->gettext("connect")."</a>)"
                    ]
                ]
            ];
        }

        return ["blocks" => $blocks];
    }

    /**
     * correct attachment parameters for nextcloud attachments where
     * parameters couldn't be set otherwise
     *
     * @param array $args original message
     * @return Modifiable_Mail_mime[] corrected message
     */
    public function fix_attachment(array $args): array
    {
        $msg = new Modifiable_Mail_mime($args["message"]);

        foreach ($msg->getParts() as $key => $part) {
            if(str_starts_with($part['c_type'], "application/nextcloud_attachment")) {
                $url = substr(trim(explode(";", $part['c_type'])[1]), strlen("url="));
                $part["disposition"] = "inline";
                $part["c_type"] = "text/html";
                $part["encoding"] = "quoted-printable"; // We don't want the base64 overhead for the few kb HTML file
                $part["add_headers"] = [
                    "X-Mozilla-Cloud-Part" => "cloudFile; url=".$url
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
//        self::log(print_r($param, true));
//        $rcmail = rcmail::get_instance();
        // files are marked to cloud upload
        if (isset($_REQUEST['_target'] ) && $_REQUEST['_target'] == "cloud") {
            if (isset($_FILES["_attachments"]) && count($_FILES["_attachments"]) > 0) {
                //set file sizes to 0 so rcmail_action_mail_attachment_upload::run() will not reject the files,
                //so we can get it from rcube_uploads::insert_uploaded_file() later
                $_FILES["_attachments"]["size"] = array_map(function ($e) {
                    return 0;
                }, $_FILES["_attachments"]["size"]);
            } else {
                self::log($this->rcmail->get_user_name()." - empty attachment array: ". print_r($_FILES, true));
            }
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
                if($res->getStatusCode() == 200) {
                    $body = $res->getBody()->getContents();
                    $data = json_decode($body, true);
                    if (isset($data['appPassword']) && isset($data['loginName'])) {
                        $rcmail = rcmail::get_instance();
                        //save app password to user preferences
                        $prefs = $rcmail->user->get_prefs();
                        $prefs["nextcloud_login"] = $data;
                        $rcmail->user->save_prefs($prefs);
                        unset($_SESSION['plugins']['nextcloud_attachments']);
                        $rcmail->output->command('plugin.nextcloud_login_result', ['status' => "ok"]);
                    }
                } else if ($res->getStatusCode() != 404) { //login timed out
                    unset($_SESSION['plugins']['nextcloud_attachments']);
                }
            } catch (GuzzleException $e) {
                self::log("poll failed: ". print_r($e, true));
            }
        }
    }

    /**
     * Action to start nextcloud login process
     * @return void
     */
    public function login() : void
    {
        $server = $this->rcmail->config->get("nextcloud_attachment_server");

        if(empty($server)) {
            return;
        }

        //start login flow
        try {
            $res = $this->client->post($server . "/index.php/login/v2");

            $body = $res->getBody()->getContents();
            $data = json_decode($body, true);

            if($res->getStatusCode() !== 200) {
                self::log($this->rcmail->get_user_name()." login check request failed: ". print_r($data, true));
                $this->rcmail->output->command('plugin.nextcloud_login', [
                    'status' => null, "message" => $res->getReasonPhrase(), "response" => $data]);
                return;
            }

            //save poll endpoint and token to session
            $_SESSION['plugins']['nextcloud_attachments'] = $data['poll'];
            unset($_SESSION['plugins']['nextcloud_attachments']['login_result']);

            $this->rcmail->output->command('plugin.nextcloud_login', ['status' => "ok", "url" => $data["login"]]);
        } catch (GuzzleException $e) {
            self::log($this->rcmail->get_user_name()." login request failed: ". print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_login', ['status' => null]);
        }
    }

    /**
     * Action to log out and delete app password if possible
     * @return void
     */
    public function logout() : void
    {
        $prefs = $this->rcmail->user->get_prefs();

        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());
        $password = $prefs["nextcloud_login"]["appPassword"];

        if (isset($password)) {
            $server = $this->rcmail->config->get("nextcloud_attachment_server");

            if (!empty($server)) {
                try {
                    /** @noinspection SpellCheckingInspection */
                    $this->client->delete($server . "/ocs/v2.php/core/apppassword", [
                        'headers' => [
                            'OCS-APIRequest' => 'true'
                        ],
                        'auth' => [$username, $password]
                    ]);
                } catch (GuzzleException) { }
            }
        }

        $prefs["nextcloud_login"] = null;
        unset($_SESSION['plugins']['nextcloud_attachments']);
        $this->rcmail->user->save_prefs($prefs);
        $this->rcmail->output->command('command', 'save');
    }

    /**
     * Helper to resolve Roundcube username (email) to Nextcloud username
     *
     * Returns resolved name or false on configuration error.
     *
     * @param $val
     * @return bool|string
     */
    private function resolve_username($val): bool|string
    {
        $username_tmpl = $this->rcmail->config->get("nextcloud_attachment_username");

        return str_replace(['%s', '%u'], [$val, explode("@", $val)[0]], $username_tmpl);
    }

    private function __check_login(): array
    {
        //Cached Result
        if( $_SESSION['plugins']['nextcloud_attachments']['login_result'] ) {
            return $_SESSION['plugins']['nextcloud_attachments']['login_result'];
        }

        $prefs = $this->rcmail->user->get_prefs();

        $server = $this->rcmail->config->get("nextcloud_attachment_server");

        $username = $this->resolve_username($this->rcmail->get_user_name());

        //missing config
        if (empty($server) || $username === false) {
            return ['status' => null];
        }

        //always prompt for app password, as mail passwords are determined to not work regardless
        if ($this->rcmail->config->get("nextcloud_attachment_dont_try_mail_password", false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"])||
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
            self::log($this->rcmail->get_user_name()." login check request failed: ". print_r($e, true));
            return ['status' => null];
        }
    }

    /**
     * Action to check nextcloud login status
     * @return void
     */
    public function check_login(): void
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->command('plugin.nextcloud_login_result', $this->__check_login());
    }

    /**
     * Helper to find unique filename in upload folder.
     *
     * Returns filename or false if resolution failed.
     * Resolution fails after >100 iterations or on server error
     *
     * @param $folder_uri string base uri
     * @param $filename string start filename
     * @param $username string login
     * @param $password string login
     * @return bool|string unique filename or false on error
     */
    private function unique_filename(string $folder_uri, string $filename, string $username, string $password): bool|string
    {
        $fn = $filename;
        $i = 0;

        try {
            //iterate the folder until the filename is unique.
            while (($code = $this->client->request("PROPFIND", $folder_uri . "/" . rawurlencode($fn),
                    ['auth' => [$username, $password]])->getStatusCode()) != 404) {
                $d = strrpos($filename, ".");
                $fn = substr($filename, 0, $d) . " " . ++$i . substr($filename, $d);
                if ($i > 100 || $code >= 500) {
                    return false;
                }
            }
        } catch (GuzzleException $e) {
            self::log($username." file request failed: ". print_r($e, true));
            return false;
        }

        return $fn;
    }

    /**
     * Hook to upload file
     *
     * Return upload information
     *
     * @param $data array attachment info
     * @return array attachment info
     */
    public function upload(array $data) : array
    {
        if (!isset($_REQUEST['_target'] ) || $_REQUEST['_target'] !== "cloud") {
            //file not marked to cloud. we won't touch it.
            return $data;
        }

        $prefs = $this->rcmail->user->get_prefs();

        // we are not logged in, and know mail password won't work, so we are not trying anything
        if ($this->rcmail->config->get("nextcloud_attachment_dont_try_mail_password", false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"])||
                empty($prefs["nextcloud_login"]["appPassword"])) {
                return ["status" => false, "abort" => true];
            }
        }

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($this->rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $this->rcmail->get_user_password();

        $server = $this->rcmail->config->get("nextcloud_attachment_server");
        $checksum = $this->rcmail->config->get("nextcloud_attachment_checksum", "sha256");

        //server not configured
        if (empty($server) || $username === false) {
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'no_config']);
            return ["status" => false, "abort" => true];
        }

        // we are not logged in, and know mail password won't work, so we are not trying anything
        if ($this->rcmail->config->get("nextcloud_attachment_dont_try_mail_password", false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"])||
                empty($prefs["nextcloud_login"]["appPassword"])) {
                return ["status" => false, "abort" => true];
            }
        }

//        $rcmail->get_user_language()
        //get the attachment sub folder
        $folder = $this->rcmail->config->get("nextcloud_attachment_folder", "Mail Attachments");
        $tr_folder = $this->rcmail->config->get("nextcloud_attachment_folder_translate_name", false);
        if (is_array($folder)) {
            if($tr_folder && key_exists($this->rcmail->get_user_language(), $folder)) {
                $folder = $folder[$this->rcmail->get_user_language()];
            } else if ($tr_folder && key_exists("en_US", $folder)) {
                $folder = $folder["en_US"];
            } else {
                $folder = array_first($folder);
            }
        }

        //full link with urlencoded folder (space must be %20 and not +)
        $folder_uri = $server."/remote.php/dav/files/".$username."/".rawurlencode($folder);

        //check folder
        try {
            $res = $this->client->request("PROPFIND", $folder_uri, ['auth' => [$username, $password]]);

            if ($res->getStatusCode() == 404) { //folder does not exist
                //attempt to create the folder
                try {
                    $res = $this->client->request("MKCOL", $folder_uri, ['auth' => [$username, $password]]);

                    if ($res->getStatusCode() != 201) { //creation failed
                        $body = $res->getBody()->getContents();
                        try {
                            $xml = new SimpleXMLElement($body);
                        } catch (Exception $e) {
                            self::log($username." xml parsing failed: ". print_r($e, true));
                            $xml = [];
                        }

                        $this->rcmail->output->command('plugin.nextcloud_upload_result', [
                            'status' => 'mkdir_error',
                            'code' => $res->getStatusCode(),
                            'message' => $res->getReasonPhrase(),
                            'result' => json_encode($xml)
                        ]);

                        self::log($username." mkcol failed ". $res->getStatusCode(). PHP_EOL . $res->getBody()->getContents());
                        return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
                    }
                } catch (GuzzleException $e) {
                    self::log($username." mkcol request failed: ". print_r($e, true));
                }
            } else if ($res->getStatusCode() > 400) { //we can't access the folder
                self::log($username." propfind failed ". $res->getStatusCode(). PHP_EOL . $res->getBody()->getContents());
                $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." propfind failed ". print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
            return ["status" => false, "abort" => true];
        }

        //get unique filename
        $filename = $this->unique_filename($folder_uri, $data["name"], $username, $password);

        if ($filename === false) {
            self::log($username." filename determination failed");
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
                    self::log($username." xml parsing failed: ". print_r($e, true));
                    $xml = [];
                }

                $this->rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'upload_error', 'message' => $res->getReasonPhrase(), 'result' => json_encode($xml)]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." put failed: ". print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'upload_error']);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //create share link
        $id = rand();
        $mime_name = str_replace("/", "-", $data["mimetype"]);
        $mime_generic_name = str_replace("/", "-", explode("/", $data["mimetype"])[0])."-x-generic";

        $icon_path = dirname(__FILE__)."/icons/Yaru-mimetypes/";
        $mime_icon = file_exists($icon_path.$mime_name.".png") ?
            file_get_contents($icon_path.$mime_name.".png") : (
            file_exists($icon_path.$mime_generic_name.".png") ?
                file_get_contents($icon_path.$mime_generic_name.".png") :
                file_get_contents($icon_path."unknown.png"));

        try {
            $res = $this->client->post($server . "/ocs/v2.php/apps/files_sharing/api/v1/shares", [
                "headers" => [
                    "OCS-APIRequest" => "true"
                ],
                "form_params" => [
                    "path" => $folder . "/" . $filename,
                    "shareType" => 3,
                    "publicUpload" => "false",
                ],
                'auth' => [$username, $password]
            ]);

            $body = $res->getBody()->getContents();

            if($res->getStatusCode() == 200) { //upload successful
                $ocs = new SimpleXMLElement($body);
                //inform client for insert to body
                $this->rcmail->output->command("plugin.nextcloud_upload_result", [
                    'status' => 'ok',
                    'result' => [
                        'url' => (string) $ocs->data->url,
                        'file' => [
                            'name' => $data["name"],
                            'size' => filesize($data["path"]),
                            'mimetype' => $data["mimetype"],
                            'mimeicon' => base64_encode($mime_icon),
                            'id' => $id,
                            'group' => $data["group"],
                        ]
                    ]
                ]);
                $url = (string) $ocs->data->url;
            } else { //link creation failed. Permission issue?
                $body = $res->getBody()->getContents();
                try {
                    $xml = new SimpleXMLElement($body);
                } catch (Exception $e) {
                    self::log($username." xml parse failed: ". print_r($e, true));
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
            self::log($username." share file failed: ". print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        } catch (Exception $e) {
            self::log($username." xml parse failed: ". print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        }

        //fill out template attachment HTML
        $tmpl = file_get_contents(dirname(__FILE__)."/attachment_tmpl.html");

        $fs = filesize($data["path"]);
        $u = ["", "k", "M", "G", "T"];
        for($i = 0; $fs > 800.0 && $i <= count($u); $i++){ $fs /= 1024; }


        $tmpl = str_replace("%FILENAME%", $data["name"], $tmpl);
        /** @noinspection SpellCheckingInspection */
        $tmpl = str_replace("%FILEURL%", $url, $tmpl);
        /** @noinspection SpellCheckingInspection */
        $tmpl = str_replace("%SERVERURL%", $server, $tmpl);
        $tmpl = str_replace("%FILESIZE%", round($fs,1)." ".$u[$i]."B", $tmpl);
        /** @noinspection SpellCheckingInspection */
        $tmpl = str_replace("%ICONBLOB%", base64_encode($mime_icon), $tmpl);
        $tmpl = str_replace("%CHECKSUM%", strtoupper($checksum)." ".hash_file($checksum, $data["path"]), $tmpl);

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
            "name" => $data["name"].".html", //append html suffix
            "mimetype" => "text/html",
            "data" => $tmpl, //just return the few KB text, we deleted the file
            "path" => null,
            "size" => strlen($tmpl),
            "target" => "cloud", //cloud attachment meta data
            "uri" => $url."/download",
            "break" => true //no other plugin should process this attachment future
        ];
    }
}
