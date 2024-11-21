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

use IntlDateFormatter;
use function NextcloudAttachments\__;

trait Preferences {
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

        $login_result = $this->login_status();

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
                "flat" => htmlentities($this->gettext("folder_layout_flat")),
                "date" => htmlentities($this->gettext("folder_layout_date")). " (/" . $folder . "/" . (
                    function ($fmt) use ($date_format) {
                        $date_format->setPattern($fmt ?? "Y/LLLL");
                        return $date_format->format(time());
                    }
                    )($server_format_tokens[1] ?? "Y/LLLL")."/...)",
                "hash" => htmlentities($this->gettext("folder_layout_hash")). " (/" . $folder . "/" .(
                    function ($method, $depth) {
                        $hash = hash($method, "");
                        $hash_bytes = str_split($hash, 2);
                        $hash_n_bytes = array_slice($hash_bytes, 0, $depth);
                        return implode("/", $hash_n_bytes) . substr($hash, 2 * $depth);
                    })($server_format_tokens[1] ?? "sha1", $server_format_tokens[2] ?? 2)."/...)"
            };

            $layout_select->add([
                htmlentities($this->gettext("folder_layout_default")).$server_format,
                htmlentities($this->gettext("folder_layout_flat"))
            ], ["default", "flat"]);

            $formats = ["Y", "Y/LLLL", "Y/LLLL/dd", "Y/LL", "Y/LL/dd", "Y/ww","Y/ww/EEEE","Y/ww/E"];

            foreach ($formats as $format) {
                $date_format->setPattern($format);
                $ex = $date_format->format(time());
                $layout_select->add(htmlentities($this->gettext("folder_layout_date_".$format))." (/".$folder."/".$ex."/...)", "date:".$format);
            }

            $layout_select->add([
                htmlentities($this->gettext("folder_layout_hash"))." (/".$folder."/ad/c8/...)",
                htmlentities($this->gettext("folder_layout_hash"))." (/".$folder."/ad/c8/3b/...)",
                htmlentities($this->gettext("folder_layout_hash"))." (/".$folder."/ad/c8/3b/19/...)",
                htmlentities($this->gettext("folder_layout_hash"))." (/".$folder."/ad/c8/3b/19/e7/...)",
            ], ["hash:sha1:2", "hash:sha1:3", "hash:sha1:4", "hash:sha1:5"]);

            /** @noinspection JSUnresolvedReference */
            $blocks["plugin.nextcloud_attachments"] = [
                "name" => htmlentities($this->gettext("cloud_attachments")),
                "options" => [
                    "server" => [
                        "title" => htmlentities($this->gettext("cloud_server")),
                        "content" => "<a href='" . $server . "' target='_blank'>" . parse_url($server, PHP_URL_HOST) . "</a>"
                    ],
                    "connection" => [
                        "title" => htmlentities($this->gettext("status")),
                        "content" => $login_result["status"] == "ok" ?
                            htmlentities($this->gettext("connected_as")) . " " . $username . ($can_disconnect ? " (<a href=\"#\" onclick=\"rcmail.http_post('plugin.nextcloud_disconnect')\">" . htmlentities($this->gettext("disconnect")) . "</a>)" : "") :
                            htmlentities($this->gettext("not_connected")) . " (<a href=\"#\" onclick=\"window.rcmail.nextcloud_login_button_click_handler(null, null)\">" . htmlentities($this->gettext("connect")) . "</a>)"
                    ],


                ]
            ];

            if (!$this->rcmail->config->get(__("folder_layout_locked"), true)) {
                $blocks["plugin.nextcloud_attachments"]["options"]["folder_layout"] = [
                    "title" => htmlentities($this->gettext("folder_layout")),
                    "content" => $layout_select->show([$prefs[__("user_folder_layout")] ?? "default"])
                ];
            }

            if (!$this->rcmail->config->get(__("password_protected_links_locked"), true)) {
                $def = $this->rcmail->config->get(__("password_protected_links"), false) ? "1" : "0";
                $blocks["plugin.nextcloud_attachments"]["options"]["password_protected_links"] = [
                    "title" => htmlentities($this->gettext("password_protected_links")),
                    "content" => $pp_links->show($prefs[__("user_password_protected_links")] ?? $def)
                ];
            }

            if (!$this->rcmail->config->get(__("expire_links_locked"), true)) {
                $def = $this->rcmail->config->get(__("expire_links"), false);
                $blocks["plugin.nextcloud_attachments"]["options"]["expire_links"] = [
                    "title" => htmlentities($this->gettext("expire_links")),
                    "content" => $exp_links->show($prefs[__("user_expire_links")] ?? ($def === false ? "0" : "1"))
                ];
                $blocks["plugin.nextcloud_attachments"]["options"]["expire_links_after"] = [
                    "title" => htmlentities($this->gettext("expire_links_after")),
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
        if (!$this->rcmail->config->get(__("folder_layout_locked"), true) &&
            in_array($folder_layout, $formats)) {
            $param["prefs"][__("user_folder_layout")] = $folder_layout;
        }

        $pass_prot = $_POST["_".__("password_protected_links")] ?? null;
        if (!$this->rcmail->config->get(__("password_protected_links_locked"), true) && $pass_prot == 1) {
            $param["prefs"][__("user_password_protected_links")] = true;
        }

        $expire_links = $_POST["_".__("expire_links")] ?? null;
        $expire_links_after = $_POST["_".__("expire_links_after")] ?? null;
        if (!$this->rcmail->config->get(__("expire_links_locked"), true)) {
            $param["prefs"][__("user_expire_links")] = ($expire_links == 1 && intval($expire_links_after) > 0);
            if (intval($expire_links_after) > 0) {
                $param["prefs"][__("user_expire_links_after")] = intval($expire_links_after);
            }
        }

        return $param;
    }
}