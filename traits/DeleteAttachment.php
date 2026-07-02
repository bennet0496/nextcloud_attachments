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

trait DeleteAttachment {
    /**
     * Hook to delete removed attachment from nextcloud
     *
     * @param array $param attachment info
     * @return array|string[] status info
     */
    public function delete(array $param): array
    {
        if (self::verify_path($param['path']))
            @unlink($param['path']);

        if (@$_POST["_ncremove"] === "true" && @$param["target"] === "cloud") {
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

    public function cleanup(array $param): array
    {
        // taken from filesystem_attachments
        if (!empty($_SESSION['plugins']['nextcloud_attachments'])) {
            foreach ($_SESSION['plugins']['nextcloud_attachments'] as $group => $files) {
                if (!empty($param['group']) && $param['group'] != $group) {
                    continue;
                }

                foreach ((array) $files as $filename) {
                    if (file_exists($filename)) {
                        unlink($filename);
                    }
                }

                unset($_SESSION['plugins']['nextcloud_attachments'][$group]);
            }
        }

        return $param;

    }

    // from filesystem_attachments
    protected static function verify_path($path)
    {
        if (empty($path)) {
            return false;
        }

        $rcmail    = \rcube::get_instance();
        $temp_dir  = $rcmail->config->get('temp_dir');
        $file_path = pathinfo($path, PATHINFO_DIRNAME);

        if ($temp_dir !== $file_path) {
            // When the configured directory is not writable, or out of open_basedir path
            // tempnam() fallbacks to system temp without a warning.
            // We allow that, but we'll let to know the user about the misconfiguration.
            if ($file_path == sys_get_temp_dir()) {
                \rcube::raise_error([
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'message' => "Detected 'temp_dir' change. "
                        . "Access to '$temp_dir' restricted by filesystem permissions or open_basedir",
                ], true, false
                );

                return true;
            }

            \rcube::raise_error([
                'file'    => __FILE__,
                'line'    => __LINE__,
                'message' => sprintf("%s can't read %s (not in temp_dir)",
                    $rcmail->get_user_name(), substr($path, 0, 512))
            ], true, false
            );

            return false;
        }

        return true;
    }

}