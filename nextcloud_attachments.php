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

use NextcloudAttachments\Traits\DeleteAttachment;
use NextcloudAttachments\Traits\InterceptAttachment;
use NextcloudAttachments\Traits\NextcloudLogin;
use NextcloudAttachments\Traits\Preferences;
use NextcloudAttachments\Traits\UploadFile;
use NextcloudAttachments\Utilities;
use function NextcloudAttachments\__;

if (!class_exists("GuzzleHttp\Client")) {
    (include dirname(__FILE__) . "/vendor/autoload.php") or die("please run 'composer require' in the nextcloud_attachments plugin folder");
}

const NC_ATTACH_PREFIX = "nextcloud_attachment";
const NC_ATTACH_LOG_FILE = "ncattach";
const NC_ATTACH_VERSION = "1.5";

require_once dirname(__FILE__) . "/traits/Preferences.php";
require_once dirname(__FILE__) . "/traits/InterceptAttachment.php";
require_once dirname(__FILE__) . "/traits/UploadFile.php";
require_once dirname(__FILE__) . "/traits/DeleteAttachment.php";
require_once dirname(__FILE__) . "/traits/NextcloudLogin.php";
require_once dirname(__FILE__) . "/traits/Utilities.php";


/** @noinspection PhpUnused */

class nextcloud_attachments extends rcube_plugin
{
    use Preferences, InterceptAttachment, UploadFile, DeleteAttachment, NextcloudLogin, Utilities;

    private rcmail $rcmail;
    private GuzzleHttp\Client $client;


    public function init(): void
    {
        $this->rcmail = rcmail::get_instance();
        $this->load_config("config.inc.php.dist");
        $this->load_config();

        if (empty($this->rcmail->config->get(__("server")))) {
            self::log("invalid config. server is empty");
            return;
        }

        if ($this->is_disabled()) {
            return;
        }

        $this->client = new GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Roundcube Nextcloud Attachment Connector/' . NC_ATTACH_VERSION,
            ],
            'http_errors' => false,
            'verify' => $this->rcmail->config->get(__("verify_https"), true)
        ]);


        $this->add_texts("l10n/", true);

        //action to check if we have a usable login
        /** @noinspection SpellCheckingInspection */
        $this->register_action('plugin.nextcloud_checklogin', function () { $this->check_login(); });

        //action to trigger login flow
        $this->register_action('plugin.nextcloud_login', function () { $this->login(); });

        //action to log out
        $this->register_action('plugin.nextcloud_disconnect', function () { $this->logout(); });

        //Intercept filesize for marked files
        $this->add_hook("ready", function ($param) { $this->intercept_filesize($param); });

        //insert our client script and style
        $this->add_hook("ready", function ($param) { $this->insert_client_code($param); });

        //TODO actually implement the following
        $this->add_hook("render_page", function ($param) {
            if($param["template"] == "compose") {
                $this->rcmail->output->add_footer(file_get_contents($this->home . "/templates/inline.html"));
            }
        });

        //correct the cloud attachment size for retrieval
        $this->add_hook('attachment_get', function ($param) {
            if ($param["target"] === "cloud") {
                $param["mimetype"] = "application/nextcloud_attachment; url=" . $param["uri"]; //Mark attachment for later interception
                $param["status"] = true;
                $param["size"] = strlen($param["data"]);
                $param["path"] = null;
            }
            return $param;
        });

        //intercept to change attachment encoding
        $this->add_hook("message_ready", function ($param) { return $this->fix_attachment($param); });

        //login flow poll
        $this->add_hook("refresh", function ($param) { $this->poll($param); });

        //hook to upload the file
        $this->add_hook("attachment_upload", function ($param) { return $this->upload($param); });

        $this->add_hook("preferences_list", function ($param) { return $this->add_preferences($param); });

        $this->add_hook("preferences_save", function ($param) { return $this->save_preferences($param); });

        $this->add_hook("attachment_delete", function ($param) { return $this->delete($param); });

    }

    /**
     * Ready hook to insert our client script and style.
     *
     * @param $param mixed page information
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
}
