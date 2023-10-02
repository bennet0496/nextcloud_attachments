<?php
/*
 * Copyright (c) 2023 Bennet Becker
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

enum body_parser {
    case PLAIN;
    case JSON;
    case JSON_ASSOC;
    case XML;
    case XML_ASSOC;
}
class webdav_client
{
//    private string $username;
//    private string $password;
//    private string $server;
//    private string $basedir;
    private $error_callback;

    private \GuzzleHttp\Client $client;

    /**
     * @param string $username
     * @param string $password
     * @param string $server
     * @param string $basedir
     * @param callable | null $error_callback
     */
    public function __construct(string $username, string $password, string $server, string $basedir, callable | null $error_callback = null)
    {
//        $this->username = $username;
//        $this->password = $password;
//        $this->server = $server;
//        $this->basedir = $basedir;
        $this->error_callback = $error_callback;

        $this->client = new GuzzleHttp\Client([
            'base_uri' => rtrim($server, "/")."/".ltrim($basedir, "/"),
            'auth' => [$username, $password],
            'http_errors' => false
        ]);
    }


    public function put($filename, $body) : bool
    {
        try {
            $res = $this->client->put($filename, ["body" => $body]);
            if ($res->getStatusCode() > 299) {
                call_user_func($this->error_callback, $res->getStatusCode(), "PUT ".$filename." non 200 return code");
                return false;
            }
            return true;
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            call_user_func($this->error_callback, $e->getCode(), "PUT ".$filename." threw client exception: ".$e->getMessage());
            return false;
        }
    }

    public function get($filename, $parser = body_parser::PLAIN) : string | false
    {
        try {
            $res = $this->client->get($filename);
            if ($res->getStatusCode() > 299) {
                call_user_func($this->error_callback, $res->getStatusCode(), "GET ".$filename." non 200 return code");
                return false;
            }
            return match ($parser) {
                body_parser::JSON => json_decode($res->getBody()->getContents()),
                body_parser::JSON_ASSOC => json_decode($res->getBody()->getContents(), true),
                body_parser::XML => simplexml_load_string($res->getBody()->getContents()),
                body_parser::XML_ASSOC => json_decode(json_encode(simplexml_load_string($res->getBody()->getContents())), true),
                default => $res->getBody()->getContents(),
            };
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            call_user_func($this->error_callback, $e->getCode(), "PUT ".$filename." threw client exception: ".$e->getMessage());
            return false;
        } catch (Exception $e) {
            call_user_func($this->error_callback, $e->getCode(), "PUT ".$filename." body parsing failed: ".$e->getMessage());
            return false;
        }
    }

    public function mkdir($dirname, $make_parent = false) : bool
    {
        try {
            if ($make_parent) {
                $dirname = ltrim($dirname, "/");
                $parts = explode("/", $dirname);
                $parent = implode("/", array_slice($parts, 0 , -1));
                if (!$this->exists($parent) && $parent != "" && $parent != "/") {
                    $this->mkdir($parent, true);
                }
            }

            $res = $this->client->request("MKCOL", rawurlencode(ltrim($dirname, "/")));

            if ($res->getStatusCode() != 201) { // creation failed
                call_user_func($this->error_callback, $res->getStatusCode(), "MKCOL " . $dirname . " non 201 return code");
                return false;
            }

            return true;
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            call_user_func($this->error_callback, $e->getCode(), "MKCOL ".$dirname." threw client exception: ".$e->getMessage());
            return false;
        }
    }

    public function exists($filename) : array | false {
        try {
            $res = $this->client->request("PROPFIND", $filename);
            $code = $res->getStatusCode();
            if ($code == 404) {
                // does not exist
                return false;
            } elseif ($code < 300 && $code >= 200) {
                // exists
                $body = simplexml_load_string($res->getBody()->getContents());
                $chls = $body->children('d', true)->response;
                return [
                    "type" => count($chls) > 1 ? "dir" : "file",
                    "size" => is_array($chls) ? intval($chls[0]->propstat->prop->getcontentlength) : intval($chls->propstat->prop->getcontentlength)
                ];
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            call_user_func($this->error_callback, $e->getCode(), "PROPFIND ".$filename." threw client exception: ".$e->getMessage());
            return false;
        } catch (Exception $e) {
            call_user_func($this->error_callback, $e->getCode(), "PROPFIND ".$filename." body parsing failed: ".$e->getMessage());
            return false;
        }

        return false;
    }

    public function append($filename, $body) : bool {
        if ($obody = $this->get($filename)) {
            if(str_ends_with($obody, "\n")) {
                $obody .= $body;
            } else {
                $obody .= PHP_EOL.$body;
            }

            return $this->put($filename, $obody);
        } else {
            return $this->put($filename, $body);
        }
    }
}