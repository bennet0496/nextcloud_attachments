<?php
//define('RCUBE_CONFIG_DIR',  realpath(dirname(__FILE__)."/../../config"));
//define('RCUBE_PLUGINS_DIR', realpath(dirname(__FILE__)."/../../plugins"));
//
//require_once dirname(__FILE__).'/vendor/framework/bootstrap.php';

include dirname(__FILE__)."/vendor/autoload.php";

use GuzzleHttp\Psr7;

class nextcloud_attachments extends rcube_plugin
{
    public function init()
    {
        $this->add_hook("ready", function ($param) {
            // files are marked to cloud upload
            if (isset($_REQUEST['_target'] ) && $_REQUEST['_target'] == "cloud") {
                //set file sizes to 0 so rcmail_action_mail_attachment_upload::run() will not reject the files
                //so we can get it from rcube_uploads::insert_uploaded_file() later
                $_FILES["_attachments"]["size"] = array_map(function($e) { return 0; }, $_FILES["_attachments"]["size"]);
            }
        });

        //insert our client script and style
        $this->add_hook('html_editor', function ($params) {
            $this->include_script("client.js");
            $this->include_stylesheet("client.css");
            return $params;
        });

        //hook to upload the file
        $this->add_hook('attachment_upload', [$this, 'upload']);

        //action to check if we have a usable login
        $this->register_action('plugin.nextcloud_checklogin', [$this, 'check_login']);

        //action to trigger login flow
        $this->register_action('plugin.nextcloud_login', [$this, 'login']);

        //correct the cloud attachment size for retrieval
        $this->add_hook('attachment_get', function ($param) {
            if ($param["target"] === "cloud") {
                $param["mimetype"] = "text/html";
                $param["status"] = true;
                $param["size"] = strlen($param["data"]);
                unset($param["path"]);
            }
            return $param;
        });

        //login flow poll
        $this->add_hook("refresh", [$this, 'poll']);
    }

    public function poll($ignore) {
        //check if there is poll endpoint
        if (isset($_SESSION['plugins']['nextcloud_attachments']) &&
            isset($_SESSION['plugins']['nextcloud_attachments']['token']) &&
            isset($_SESSION['plugins']['nextcloud_attachments']['endpoint'])) {
            $client = new GuzzleHttp\Client([
                'headers' => [
                    'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
                ],
                'http_errors' => false
            ]);

            //poll it
            $res = $client->post($_SESSION['plugins']['nextcloud_attachments']['endpoint']."?token=".$_SESSION['plugins']['nextcloud_attachments']['token']);

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
        }
    }

    public function login() {
        $rcmail = rcmail::get_instance();

        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");

        if(empty($server)) {
            return null;
        }

        $client = new GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
            ],
            'http_errors' => false
        ]);

        //start login flow
        $res = $client->post($server."/index.php/login/v2");

        $body = $res->getBody()->getContents();
        $data = json_decode($body, true);

        if($res->getStatusCode() !== 200) {
            $rcmail->output->command('plugin.nextcloud_login', [
                'status' => null, "message" => $res->getReasonPhrase(), "response" => $data]);
            return null;
        }

        //save poll endpoint and token to session
        $_SESSION['plugins']['nextcloud_attachments'] = $data['poll'];

        $rcmail->output->command('plugin.nextcloud_login', ['status' => "ok", "url" => $data["login"]]);
    }

    private function resolve_username($val) {
        $rcmail = rcmail::get_instance();

        $this->load_config();
        $method = $rcmail->config->get("nextcloud_attachment_username");

        switch ($method) {
            case "%s":
            case "plain":
            case "asis":
            case "copy":
                return $val;
            case "%u":
            case "username":
            case "localpart":
            case "stripdomain":
                return explode("@", $val)[0];
            case "email":
                if(strpos($val, "@") !== false) {
                    return $val;
                }
            case "ldap":
                return false;
            default:
                return false;

        }
    }

    public function check_login() {
        $rcmail = rcmail::get_instance();

        $prefs = $rcmail->user->get_prefs();
        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");

        $username = $this->resolve_username($rcmail->get_user_name());

        //missing config
        if (empty($server) || $username === false) {
            $rcmail->output->command('plugin.nextcloud_login_result', ['status' => null]);
            return;
        }

        if (isset($prefs["nextcloud_login"]["appPassword"]) && isset($prefs["nextcloud_login"]["loginName"])) {
            //user as saved credentials
            $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'ok']);
        } else {
            $client = new GuzzleHttp\Client([
                'auth' => [$rcmail->get_user_name(), $rcmail->get_user_password()],
                'http_errors' => false
            ]);

            //test webdav login
            $res = $client->request("PROPFIND", $server."/remote.php/dav/files/".$username);

            switch ($res->getStatusCode()) {
                case 401:
                    //we can't use the password
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'login_required']);
                    break;
                case 404:
                    //the username does not exist
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'invalid_user']);
                    break;
                case 200:
                case 207:
                    //we can log in
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'ok']);
                    break;
                default:
                    //something weired happened
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => null, 'code' => $res->getStatusCode(), 'message' => $res->getReasonPhrase()]);
            }

        }
    }

    private function unique_filename($folder_uri, $filename, $username, $password) {
        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        $fn = $filename;
        $i = 0;

        //iterate the folder until the filename is unique.
        while (($code = $client->request("PROPFIND", $folder_uri."/".rawurlencode($fn))->getStatusCode()) != 404) {
            $d = strrpos($filename, ".");
            $fn = substr($filename, 0, $d)." ". ++$i.substr($filename, $d);
            if($i > 100 || $code >= 500) {
                return false;
            }
        }

        return $fn;
    }

    public function upload($data) {
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
        $res = $client->request("PROPFIND", $folder_uri);

        if ($res->getStatusCode() == 404) { //folder does not exist
            //attempt to create the folder
            $res = $client->request("MKCOL", $folder_uri);
            if ($res->getStatusCode() != 201) { //creation failed
                $body = $res->getBody()->getContents();
                $xml = new SimpleXMLElement($body);
                $rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'mkdir_error',
                    'code' => $res->getStatusCode(),
                    'message' => $res->getReasonPhrase(),
                    'result' => json_encode($xml)
                ]);
                return null;
            }
        } else if ($res->getStatusCode() > 400) { //we can't access the folder
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //get unique filename
        $filename = $this->unique_filename($folder_uri, $data["name"], $username, $password);

        if ($filename === false) {
            //it was not possible to find name
            //too many files?
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'name_error']);
            return ["status" => false, "abort" => true];
        }

        //upload file
        $body = Psr7\Utils::tryFopen($data["path"], 'r');
        $res = $client->put($folder_uri."/".rawurlencode($filename), ["body" => $body]);

        if ($res->getStatusCode() != 200 && $res->getStatusCode() != 201) {
            $body = $res->getBody()->getContents();
            $xml = new SimpleXMLElement($body);

            $rcmail->output->command('plugin.nextcloud_upload_result', [
                'status' => 'upload_error', 'message' => $res->getReasonPhrase(), 'result' => json_encode($xml)]);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //create share link
        $res = $client->post($server."/ocs/v2.php/apps/files_sharing/api/v1/shares" ,[
            "headers" => [
                "OCS-APIRequest" => "true"
            ],
            "form_params" => [
                "path" => $folder."/".$filename,
                "shareType" => 3,
                "publicUpload" => "false",
            ]
        ]);

        $body = $res->getBody()->getContents();
        $url = "";
        $id = rand();
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
            $xml = new SimpleXMLElement($body);
            $rcmail->output->command('plugin.nextcloud_upload_result', [
                'status' => 'link_error',
                'code' => $res->getStatusCode(),
                'message' => $res->getReasonPhrase(),
                'result' => json_encode($xml)
            ]);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //fill out template attachment HTML
        $tmpl = file_get_contents(dirname(__FILE__)."/attachment_tmpl.html");

        $fs = filesize($data["path"]);
        $u = ["", "k", "M", "G", "T"];
        for($i = 0; $fs > 800.0 && $i <= count($u); $i++){ $fs /= 1024; }

        $tmpl = str_replace("%FILENAME%", $data["name"], $tmpl);
        $tmpl = str_replace("%FILEURL%", $url, $tmpl);
        $tmpl = str_replace("%FILESIZE%", round($fs,1)." ".$u[$i]."B", $tmpl);

        unlink($data["path"]);

        //return a html page as attachment that provides the download link
        return [
            "id" => $id,
            "group" => $data["group"],
            "status" => true,
            "name" => $data["name"].".html", //append html suffix
            "mimetype" => "text/html",
            "data" => $tmpl, //just return the few KB text, we deleted the file
            "size" => strlen($tmpl),
            "target" => "cloud", //cloud attachment meta data
            "break" => true //no other plugin should process this attachment future
        ];
    }
}
