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
use IntlDateFormatter;
use rcmail;

function __(string $val): string
{
    return NC_ATTACH_PREFIX . "_" . $val;
}

trait Utility
{

    private static function log(...$lines): void
    {
        foreach ($lines as $line) {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
            $func = $bt["function"];
            $cls = $bt["class"];
            if (!is_string($line)) {
                $line = print_r($line, true);
            }
            $llines = explode(PHP_EOL, $line);
            rcmail::write_log(NC_ATTACH_LOG_FILE, "[" . NC_ATTACH_PREFIX . "] {" . $cls . "::" . $func . "} " . $llines[0]);
            unset($llines[0]);
            if (count($llines) > 0) {
                foreach ($llines as $l) {
                    rcmail::write_log(NC_ATTACH_LOG_FILE, str_pad("...", strlen("[" . NC_ATTACH_PREFIX . "] "), " ", STR_PAD_BOTH) . "{" . $cls . "::" . $func . "} " . $l);
                }
            }
        }
    }

    /**
     * Detect whether the user should be allowed to interact with the plugin
     *
     * @return bool true if use is excluded
     */
    private function is_disabled(): bool
    {
        $ex = $this->rcmail->config->get(__("exclude_users"), []);
        $exg = $this->rcmail->config->get(__("exclude_users_in_addr_books"), []);
        $exa = $this->rcmail->config->get(__("exclude_users_with_addr_book_value"), []);
        /** @noinspection SpellCheckingInspection */
        $exag = $this->rcmail->config->get(__("exclude_users_in_addr_book_group"), []);

        // exclude directly deny listed users
        if (is_array($ex) && (in_array($this->rcmail->get_user_name(), $ex) || in_array($this->resolve_username(), $ex) || in_array($this->rcmail->get_user_email(), $ex))) {
            self::log("access for " . $this->resolve_username() . " disabled via direct deny list");
            return true;
        }

        // exclude directly deny listed address books
        if (is_array($exg) && count($exg) > 0) {
            foreach ($exg as $book) {
                /** @noinspection SpellCheckingInspection */
                $abook = $this->rcmail->get_address_book($book);
                if ($abook) {
                    if (array_key_exists("uid", $book->coltypes)) {
                        $entries = $book->search(["email", "uid"], [$this->rcmail->get_user_email(), $this->resolve_username()]);
                    } else {
                        $entries = $book->search("email", $this->rcmail->get_user_email());
                    }
                    if ($entries) {
                        self::log("access for " . $this->resolve_username() .
                            " disabled in " . $book->get_name() . " because they exist in there");
                        return true;
                    }
                }
            }
        }

        // exclude users with a certain attribute in an address book
        if (is_array($exa) && count($exa) > 0) {
            // value not properly formatted
            if (!is_array($exa[0])) {
                $exa = [$exa];
            }
            foreach ($exa as $val) {
                if (count($val) == 3) {
                    $book = $this->rcmail->get_address_book($val[0]);
                    $attr = $val[1];
                    $match = $val[2];

                    if (array_key_exists("uid", $book->coltypes)) {
                        $entries = $book->search(["email", "uid"], [$this->rcmail->get_user_email(), $this->resolve_username()]);
                    } else {
                        $entries = $book->search("email", $this->rcmail->get_user_email());
                    }

                    if ($entries) {
                        while ($e = $entries->iterate()) {
                            if (array_key_exists($attr, $e) && ($e[$attr] == $match ||
                                    (is_array($e[$attr]) && in_array($match, $e[$attr])))) {
                                self::log("access for " . $this->resolve_username() .
                                    " disabled in " . $book->get_name() . " because of " . $attr . "=" . $match);
                                return true;
                            }
                        }
                    }
                }
            }
        }

        // exclude users in groups
        if (is_array($exag) && count($exag) > 0) {
            if (!is_array($exag[0])) {
                /** @noinspection SpellCheckingInspection */
                $exag = [$exag];
            }
            foreach ($exag as $val) {
                if (count($val) == 2) {
                    $book = $this->rcmail->get_address_book($val[0]);
                    $group = $val[1];

                    $groups = $book->get_record_groups(base64_encode($this->resolve_username()));

                    if (in_array($group, $groups)) {
                        self::log("access for " . $this->resolve_username() .
                            " disabled in " . $book->get_name() . " because of group membership " . $group);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function __check_login(): array
    {
        //Cached Result
        if (isset($_SESSION['plugins']['nextcloud_attachments']) &&
            $_SESSION['plugins']['nextcloud_attachments']['login_result']) {
            return $_SESSION['plugins']['nextcloud_attachments']['login_result'];
        }

        $prefs = $this->rcmail->user->get_prefs();

        $server = $this->rcmail->config->get(__("server"));

        $username = $this->resolve_username();

        //missing config
        if (empty($server) || empty($username)) {
            return ['status' => null];
        }

        //always prompt for app password, as mail passwords are determined to not work regardless
        if ($this->rcmail->config->get(__("dont_try_mail_password"), false)) {
            if (!isset($prefs["nextcloud_login"]) ||
                empty($prefs["nextcloud_login"]["loginName"]) ||
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
            /** @noinspection SpellCheckingInspection */
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
            self::log($this->rcmail->get_user_name() . " login check request failed: " . print_r($e, true));
            return ['status' => null];
        }
    }

    /**
     * Helper to resolve Roundcube username (email) to Nextcloud username
     *
     * Returns resolved name.
     *
     * @param $user string The username
     * @return string
     */
    private function resolve_username(string $user = ""): string
    {
        if (empty($user)) {
            // verbatim roundcube username
            $user = $this->rcmail->user->get_username();
        }

        $username_tmpl = $this->rcmail->config->get(__("username"));

        $mail = $this->rcmail->user->get_username("mail");
        $mail_local = $this->rcmail->user->get_username("local");
        $mail_domain = $this->rcmail->user->get_username("domain");

        $imap_user = empty($_SESSION['username']) ? $mail_local : $_SESSION['username'];

        return str_replace(["%s", "%i", "%e", "%l", "%u", "%d", "%h"],
            [$user, $imap_user, $mail, $mail_local, $mail_local, $mail_domain, $_SESSION['storage_host'] ?? ""],
            $username_tmpl);
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
            self::log($username . " file request failed: " . print_r($e, true));
            return false;
        }

        return $fn;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function resolve_destination_folder(string $folder_uri, string $file_path, string $username, string $password) : string | false
    {
        $folder_layout_locked = $this->rcmail->config->get(__("folder_layout_locked"), true);

        if ($folder_layout_locked) {
            $folder_layout = $this->rcmail->config->get(__("folder_layout"), "flat");
        } else {
            $prefs = $this->rcmail->user->get_prefs();
            $user_folder_layout = $prefs[__("user_folder_layout")];
            $folder_layout = match ($user_folder_layout) {
                "default", null => $this->rcmail->config->get(__("folder_layout"), "flat"),
                default => $prefs[__("user_folder_layout")]
            };
        }

        if (str_starts_with($folder_layout, "hash")) {
            $tokens = explode(":", $folder_layout);
            $path_checksum = count($tokens) > 1 ? $tokens[1] : "sha1";
            $path_hash = strtolower(hash_file($path_checksum, $file_path));
            $hash_byte_len = strlen($path_hash) / 2;
            if ($hash_byte_len != floor($hash_byte_len)) {
                return false;
            }
            $depth = min(count($tokens) > 2 ? $tokens[2] : 2, $hash_byte_len);
            $path_tokens = array_slice(str_split($path_hash, 2), 0, $depth);

            //drop to folder creation below
        } else if (str_starts_with($folder_layout, "date")) {
            $tokens = explode(":", $folder_layout);
            $date_format = datefmt_create(
                $this->rcmail->get_user_language(),
                IntlDateFormatter::FULL,
                IntlDateFormatter::FULL,
                null,
                IntlDateFormatter::GREGORIAN,
                $tokens[1] ?? "Y/LLLL"
            );

            $path = datefmt_format($date_format, time());
            $path_tokens = explode("/", trim($path, "/"));
            //drop to folder creation below
        } else {
            return "";
        }

        for($i=1; $i <= count($path_tokens); $i++) {
            $path = implode("/", array_map(function($t){return rawurlencode($t);}, array_slice($path_tokens,0, $i)));
            $path = str_ends_with($path, "/") ? $path : $path."/";

            $res = $this->client->request("PROPFIND", $folder_uri."/".$path, ['auth' => [$username, $password]]);
            if ($res->getStatusCode() == 404) {
                $mkdir_res = $this->client->request("MKCOL", $folder_uri."/".$path, ['auth' => [$username, $password]]);
                if ($mkdir_res->getStatusCode() >= 400) {
                    return false;
                }
            } elseif ($res->getStatusCode() >= 400) {
                return false;
            }
        }

        return implode("/", $path_tokens);
    }

    private function update_exclude(string $username, string $password, string $base_uri, string $folder): void
    {
        try{
            $res = $this->client->get( $base_uri. "/.sync-exclude.lst", ['auth' => [$username, $password]]);
            $list = [$folder];
            if ($res->getStatusCode() < 300) {
                $list = explode(PHP_EOL, $res->getBody()->getContents());
                if (!preg_grep("/^".$folder."\/?$/", $list)) {
                    $list[] = $folder;
                }
            }
            $this->client->put($base_uri. "/.sync-exclude.lst", ['auth' => [$username, $password], 'body' => implode(PHP_EOL, $list)]);
        } catch (GuzzleException $e) {
            self::log($e);
        }
    }
}
