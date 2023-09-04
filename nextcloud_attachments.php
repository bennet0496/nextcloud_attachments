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
        $this->add_hook('html_editor', function ($params) {
            $this->include_script("client.js");
            return $params;
        });

        $this->register_action("mail.upload", function () {
            $rcmail = rcmail::get_instance();

//            $rcmail->write_log("nc_attach","[mail.upload] ".$rcmail->get_user_name(). " ". $rcmail->get_user_password());
            $rcmail->write_log("nc_attach","[mail.upload] ". print_r([$_REQUEST, $_FILES], true));
        });

        $this->add_hook("ready", function ($param) {
            $rcmail = rcmail::get_instance();

            if (isset($_REQUEST['_target']) && $_REQUEST['_target'] == "cloud") {
                $_FILES["_attachments"]["size"] = array_map(function($e) { return 0; }, $_FILES["_attachments"]["size"]);
            }
//            $rcmail->write_log("nc_attach","[ready] ". $rcmail->get_user_name(). " ". $rcmail->get_user_password());
//            if($param["action"] != "refresh")
            $rcmail->write_log("nc_attach","[ready] ".print_r([$_REQUEST, $_FILES, $param], true));
        });

        $this->add_hook('attachment_upload', function ($data) {
            $rcmail = rcmail::get_instance();
            $rcmail->write_log("nc_attach","[attachment_upload] ".print_r($data, true));

            $client = new GuzzleHttp\Client([
                'auth' => [$rcmail->get_user_name(), $rcmail->get_user_password()],
                'http_errors' => false
            ]);

            $username = explode("@", $rcmail->get_user_name())[0];

            $res = $client->request("PROPFIND", "https://cloud.pks.mpg.de/remote.php/dav/files/".$username."/Mail%20Attachments");

            if ($res->getStatusCode() == 404) {
                $res = $client->request("MKCOL", "https://cloud.pks.mpg.de/remote.php/dav/files/".$username."/Mail%20Attachments");
                if ($res->getStatusCode() != 201) {
                    $rcmail->write_log("nc_attach","[attachment_upload1] ".print_r(["https://cloud.pks.mpg.de/remote.php/dav/files/".$username."/Mail%20Attachments",$res->getStatusCode(), $res->getReasonPhrase(), $res->getBody()->getContents()], true));
                }
            }

            $fn = $data["name"];
            $i = 0;
            while ($client->request("PROPFIND", "https://cloud.pks.mpg.de/remote.php/dav/files/" . $username . "/Mail%20Attachments/" . $fn)->getStatusCode() != 404) {
                $d = strrpos($data["name"], ".");
                $fn = substr($data["name"], 0, $d)." ". ++$i.substr($data["name"], $d);
            }

            $body = Psr7\Utils::tryFopen($data["path"], 'r');
            $res = $client->put("https://cloud.pks.mpg.de/remote.php/dav/files/" . $username . "/Mail%20Attachments/" . $fn, ["body" => $body]);

            $rcmail->write_log("nc_attach","[attachment_upload2] ".print_r($res, true));

            $res = $client->post("https://cloud.pks.mpg.de/ocs/v2.php/apps/files_sharing/api/v1/shares" ,[
                "headers" => [
                    "OCS-APIRequest" => "true"
                ],
                "form_params" => [
                    "path" => "/Mail Attachments/".$fn,
                    "shareType" => 3,
                    "publicUpload" => "false",
                ]
            ]);

            $body = $res->getBody()->getContents();
            if($res->getStatusCode() == 200) {
                $ocs = new SimpleXMLElement($body);
                $rcmail->output->command("plugin.nc_file_link", $ocs->data->url);
            }

            $rcmail->write_log("nc_attach","[attachment_upload3] ".print_r([$res->getStatusCode(), $res->getReasonPhrase(), $body], true));


            return array_merge(["status" => true, "data" => ["target" => $_REQUEST["_target"]]], $data);
        });

        $this->add_hook('attachment_get', function ($param) {
            $rcmail = rcmail::get_instance();
            $rcmail->write_log("nc_attach","[attachment_get] ".print_r($param, true));
            return $param;
        });
    }
}
