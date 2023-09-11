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

(include dirname(__FILE__)."/vendor/autoload.php") or die("please run 'composer require' in the nextcloud_attachments plugin folder");

require_once dirname(__FILE__)."/Modifiable_Mail_mime.php";

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;

const NC_LOG_NAME = "nextcloud_attachments";
const NC_LOG_FILE = "ncattach";


/** @noinspection PhpUnused */
class nextcloud_attachments extends rcube_plugin
{
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
                $this->include_script("client.js");
                $this->include_stylesheet("client.css");
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
                $param["mimetype"] = "application/nextcloud_attachment"; //Mark attachment for later interception
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
        $rcmail = rcmail::get_instance();
        $prefs = $rcmail->user->get_prefs();
        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");
        $blocks = $param["blocks"];

        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($rcmail->get_user_name());

        $login_result = $this->__check_login();

        $can_disconnect = isset($prefs["nextcloud_login"]);

        if ($param["current"] == "compose") {

            /** @noinspection JSUnresolvedReference */
            $blocks["plugin.nextcloud_attachments"] = [
                "name" => "Cloud Attachments",
                "options" => [
                    "server" => [
                        "title" => "Server",
                        "content" => "<a href='".$server."' target='_blank'>".parse_url($server,  PHP_URL_HOST)."</a>"
                    ],
                    "connection" => [
                        "title" => "Status",
                        "content" => $login_result["status"] == "ok" ?
                            "<b style='color: green'>Connected</b> as ".$username.($can_disconnect ? " (<a href=\"#\" onclick=\"rcmail.http_post('plugin.nextcloud_disconnect')\">disconnect</a>)" : "" ):
                            "Not connected (<a href=\"#\" onclick=\"window.rcmail.nextcloud_login_button_click_handler(null)\">connect</a>)"
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
            if($part['c_type'] === "application/nextcloud_attachment") {
                $part["disposition"] = "inline";
                $part["c_type"] = "text/html";
                $part["encoding"] = "quoted-printable"; // We don't want the base64 overhead for the few kb HTML file
                $part["add_headers"] = [
                    "X-Mozilla-Cloud-Part" => "cloudFile"
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
        $rcmail = rcmail::get_instance();
        // files are marked to cloud upload
        if (isset($_REQUEST['_target'] ) && $_REQUEST['_target'] == "cloud") {
            if (isset($_FILES["_attachments"]) && count($_FILES["_attachments"]) > 0) {
                //set file sizes to 0 so rcmail_action_mail_attachment_upload::run() will not reject the files,
                //so we can get it from rcube_uploads::insert_uploaded_file() later
                $_FILES["_attachments"]["size"] = array_map(function ($e) {
                    return 0;
                }, $_FILES["_attachments"]["size"]);
            } else {
                self::log($rcmail->get_user_name()." - empty attachment array: ". print_r($_FILES, true));
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
            $client = new GuzzleHttp\Client([
                'headers' => [
                    'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
                ],
                'http_errors' => false
            ]);

            //poll it
            try {
                $res = $client->post($_SESSION['plugins']['nextcloud_attachments']['endpoint'] . "?token=" . $_SESSION['plugins']['nextcloud_attachments']['token']);

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
        $rcmail = rcmail::get_instance();

        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");

        if(empty($server)) {
            return;
        }

        $client = new GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
            ],
            'http_errors' => false
        ]);

        //start login flow
        try {
            $res = $client->post($server . "/index.php/login/v2");

            $body = $res->getBody()->getContents();
            $data = json_decode($body, true);

            if($res->getStatusCode() !== 200) {
                self::log($rcmail->get_user_name()." login check request failed: ". print_r($data, true));
                $rcmail->output->command('plugin.nextcloud_login', [
                    'status' => null, "message" => $res->getReasonPhrase(), "response" => $data]);
                return;
            }

            //save poll endpoint and token to session
            $_SESSION['plugins']['nextcloud_attachments'] = $data['poll'];

            $rcmail->output->command('plugin.nextcloud_login', ['status' => "ok", "url" => $data["login"]]);
        } catch (GuzzleException $e) {
            self::log($rcmail->get_user_name()." login request failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_login', ['status' => null]);
        }
    }

    /**
     * Action to log out and delete app password if possible
     * @return void
     */
    public function logout() : void
    {
        $rcmail = rcmail::get_instance();
        $prefs = $rcmail->user->get_prefs();

        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($rcmail->get_user_name());
        $password = $prefs["nextcloud_login"]["appPassword"];

        if (isset($password)) {
            $client = new GuzzleHttp\Client([
                'headers' => [
                    'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
                    'OCS-APIRequest' => 'true'
                ],
                'http_errors' => false,
                'auth' => [$username, $password]
            ]);

            $this->load_config();

            $server = $rcmail->config->get("nextcloud_attachment_server");

            if (!empty($server)) {
                try {
                    /** @noinspection SpellCheckingInspection */
                    $client->delete($server . "/ocs/v2.php/core/apppassword");
                } catch (GuzzleException) { }
            }
        }

        $prefs["nextcloud_login"] = null;
        $rcmail->user->save_prefs($prefs);
        $rcmail->output->command('command', 'save');
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
        $rcmail = rcmail::get_instance();

        $this->load_config();
        $method = $rcmail->config->get("nextcloud_attachment_username");

        return match ($method) {
            "%s" => $val,
            "%u" => explode("@", $val)[0],
            //"ldap" => false,
            default => false,
        };
    }

    private function __check_login(): array
    {
        $rcmail = rcmail::get_instance();

        $prefs = $rcmail->user->get_prefs();
        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");

        $username = $this->resolve_username($rcmail->get_user_name());

        //missing config
        if (empty($server) || $username === false) {
            return ['status' => null];
        }

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $rcmail->get_user_password();

        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        //test webdav login
        try {
            $res = $client->request("PROPFIND", $server . "/remote.php/dav/files/" . $username);

            switch ($res->getStatusCode()) {
                case 401:
                    unset($prefs["nextcloud_login"]);
                    $rcmail->user->save_prefs($prefs);
                    //we can't use the password
                    return ['status' => 'login_required'];
                case 404:
                    unset($prefs["nextcloud_login"]);
                    $rcmail->user->save_prefs($prefs);
                    //the username does not exist
                    return ['status' => 'invalid_user'];
                case 200:
                case 207:
                    //we can log in
                    return ['status' => 'ok'];
                default:
                    unset($prefs["nextcloud_login"]);
                    $rcmail->user->save_prefs($prefs);
                    //something weired happened
                    return ['status' => null, 'code' => $res->getStatusCode(), 'message' => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($rcmail->get_user_name()." login check request failed: ". print_r($e, true));
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
        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        $fn = $filename;
        $i = 0;

        try {
            //iterate the folder until the filename is unique.
            while (($code = $client->request("PROPFIND", $folder_uri . "/" . rawurlencode($fn))->getStatusCode()) != 404) {
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

        $rcmail = rcmail::get_instance();

        $prefs = $rcmail->user->get_prefs();
        $this->load_config();

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $rcmail->get_user_password();

        $server = $rcmail->config->get("nextcloud_attachment_server");
        $checksum = $rcmail->config->get("nextcloud_attachment_checksum", "sha256");

        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        //server not configured
        if (empty($server) || $username === false) {
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'no_config']);
            return ["status" => false, "abort" => true];
        }

        //get the attachment sub folder
        $folder = $rcmail->config->get("nextcloud_attachment_folder", "Mail Attachments");

        //full link with urlencoded folder (space must be %20 and not +)
        $folder_uri = $server."/remote.php/dav/files/".$username."/".rawurlencode($folder);

        //check folder
        try {
            $res = $client->request("PROPFIND", $folder_uri);

            if ($res->getStatusCode() == 404) { //folder does not exist
                //attempt to create the folder
                try {
                    $res = $client->request("MKCOL", $folder_uri);

                    if ($res->getStatusCode() != 201) { //creation failed
                        $body = $res->getBody()->getContents();
                        try {
                            $xml = new SimpleXMLElement($body);
                        } catch (Exception $e) {
                            self::log($username." xml parsing failed: ". print_r($e, true));
                            $xml = [];
                        }

                        $rcmail->output->command('plugin.nextcloud_upload_result', [
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
                $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." propfind failed ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
            return ["status" => false, "abort" => true];
        }

        //get unique filename
        $filename = $this->unique_filename($folder_uri, $data["name"], $username, $password);

        if ($filename === false) {
            self::log($username." filename determination failed");
            //it was not possible to find name
            //too many files?
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'name_error']);
            return ["status" => false, "abort" => true];
        }

        //upload file
        $body = Psr7\Utils::tryFopen($data["path"], 'r');
        try {
            $res = $client->put($folder_uri . "/" . rawurlencode($filename), ["body" => $body]);

            if ($res->getStatusCode() != 200 && $res->getStatusCode() != 201) {
                $body = $res->getBody()->getContents();
                try {
                    $xml = new SimpleXMLElement($body);
                } catch (Exception $e) {
                    self::log($username." xml parsing failed: ". print_r($e, true));
                    $xml = [];
                }

                $rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'upload_error', 'message' => $res->getReasonPhrase(), 'result' => json_encode($xml)]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." put failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'upload_error']);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //create share link
        $id = rand();
        try {
            $res = $client->post($server . "/ocs/v2.php/apps/files_sharing/api/v1/shares", [
                "headers" => [
                    "OCS-APIRequest" => "true"
                ],
                "form_params" => [
                    "path" => $folder . "/" . $filename,
                    "shareType" => 3,
                    "publicUpload" => "false",
                ]
            ]);

            $body = $res->getBody()->getContents();

            if($res->getStatusCode() == 200) { //upload successful
                $ocs = new SimpleXMLElement($body);
                //inform client for insert to body
                $rcmail->output->command("plugin.nextcloud_upload_result", [
                    'status' => 'ok',
                    'result' => [
                        'url' => (string) $ocs->data->url,
                        'file' => [
                            'name' => $data["name"],
                            'size' => filesize($data["path"]),
                            'mimetype' => $data["mimetype"],
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
                $rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'link_error',
                    'code' => $res->getStatusCode(),
                    'message' => $res->getReasonPhrase(),
                    'result' => json_encode($xml)
                ]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." share file failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        } catch (Exception $e) {
            self::log($username." xml parse failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        }

        //fill out template attachment HTML
        $tmpl = file_get_contents(dirname(__FILE__)."/attachment_tmpl.html");

        $fs = filesize($data["path"]);
        $u = ["", "k", "M", "G", "T"];
        for($i = 0; $fs > 800.0 && $i <= count($u); $i++){ $fs /= 1024; }

        $mime_name = str_replace("/", "-", $data["mimetype"]);
        $mime_generic_name = str_replace("/", "-", explode("/", $data["mimetype"])[0])."-x-generic";

        $icon_path = dirname(__FILE__)."/icons/Yaru-mimetypes/";
        $mime_icon = file_exists($icon_path.$mime_name.".png") ?
            file_get_contents($icon_path.$mime_name.".png") : (
                file_exists($icon_path.$mime_generic_name.".png") ?
                    file_get_contents($icon_path.$mime_generic_name.".png") :
                    file_get_contents($icon_path."unknown.png"));


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
            "break" => true //no other plugin should process this attachment future
        ];
    }
}
