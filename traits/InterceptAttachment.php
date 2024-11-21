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

use Modifiable_Mail_mime;

trait InterceptAttachment {
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
}