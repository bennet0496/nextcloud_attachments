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

use Exception;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\GuzzleException;
use SimpleXMLElement;
use function NextcloudAttachments\__;

require_once dirname(__FILE__) . "/../Modifiable_Mail_mime.php";

trait UploadFile {
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
        if ($folder_suffix) {
            $folder .= "/" . $folder_suffix;
            $folder_uri .= "/" . $folder_suffix;
        }
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
        $id = $this->rcmail->user->ID . rand();
        $mime_name = str_replace("/", "-", $data["mimetype"]);
        $mime_generic_name = str_replace("/", "-", explode("/", $data["mimetype"])[0]) . "-x-generic";

        $icon_path = $this->home . "/icons/Yaru-mimetypes/";
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
        $pass_length = $this->rcmail->config->get(__("password_length"), 12);
        $pass_links_user = $prefs[__("user_password_protected_links")] ?? false;
        // default without lookalikes: 0O Il
        $pass_alphabets = $this->rcmail->config->get(__("password_alphabets"), ["123456789", "ABCDEFGHJKLMNPQRSTUVWXYZ", "abcdefghijkmnopqrstuvwxyz"]);

        if ($pass_links || (!$pass_links_lock && $pass_links_user)) {
            $min_pass = array_map(function ($alphabet) {
                return substr($alphabet, random_int(0, strlen($alphabet) - 1), 1);
            }, $pass_alphabets);
            $miss_len = $pass_length - count($min_pass);
            if ($miss_len > 0) {
                $fill_pass = str_split($this->random_from_alphabet($miss_len, implode($pass_alphabets)));
            } else {
                self::log("WARNING: requested password len smaller then number of character set to chose from");
                $fill_pass = [];
            }

            $share_password = array_merge($min_pass, $fill_pass);
            if(version_compare(PHP_VERSION, "8.2", ">=")) {
                $r = new \Random\Randomizer();
                $share_password = $r->shuffleArray($share_password);
            } else {
                shuffle($share_password);
            }

            $form_params["password"] = implode($share_password);
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

            $tmpl = file_get_contents($this->home . "/templates/attachment_pass.html");
        } else {
            //fill out template attachment HTML
            $tmpl = file_get_contents($this->home . "/templates/attachment.html");
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

        // apply template l10n
        $tmpl = str_replace("%templ_lang%", $this->gettext("templ_lang"), $tmpl);
        $tmpl = str_replace("%templ_title%", $this->gettext("templ_title"), $tmpl);
        $tmpl = str_replace("%templ_1%", $this->gettext("templ_1"), $tmpl);
        $tmpl = str_replace("%templ_2%", $this->gettext("templ_2"), $tmpl);
        $tmpl = str_replace("%templ_size%", $this->gettext("templ_size"), $tmpl);
        $tmpl = str_replace("%templ_link%", $this->gettext("templ_link"), $tmpl);
        $tmpl = str_replace("%templ_password%", $this->gettext("templ_password"), $tmpl);
        $tmpl = str_replace("%templ_checksum%", $this->gettext("templ_checksum"), $tmpl);
        $tmpl = str_replace("%templ_availuntil_1%", $this->gettext("templ_availuntil_1"), $tmpl);
        $tmpl = str_replace("%templ_availuntil_2%", $this->gettext("templ_availuntil_2"), $tmpl);
        $tmpl = str_replace("%templ_p%", $this->gettext("templ_p"), $tmpl);
        $tmpl = str_replace("%templ_p1%", $this->gettext("templ_p1"), $tmpl);
        $tmpl = str_replace("%templ_p2%", $this->gettext("templ_p2"), $tmpl);
        $tmpl = str_replace("%templ_p3%", $this->gettext("templ_p3"), $tmpl);
        $tmpl = str_replace("%templ_p4%", $this->gettext("templ_p4"), $tmpl);
        $tmpl = str_replace("%templ_p5%", $this->gettext("templ_p5"), $tmpl);


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
     * @return mixed
     */
    protected function get_folder(): mixed
    {
        $folder = $this->rcmail->config->get(__("folder"), "Mail Attachments");
        if (is_array($folder)) {
            if (key_exists($this->rcmail->get_user_language(), $folder)) {
                $folder = $folder[$this->rcmail->get_user_language()];
            } else if (key_exists("en_US", $folder)) {
                $folder = $folder["en_US"];
            } else {
                $folder = array_first($folder);
            }
        }
        return $folder;
    }
}
