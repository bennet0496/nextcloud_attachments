<?php
/*
 * Copyright (c) 2023 Bennet Becker <dev@bennet.cc>
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

class Modifiable_Mail_mime extends Mail_mime
{
    public function __construct(Mail_mime $other)
    {
        parent::__construct($other->build_params);

        $this->txtbody = $other->txtbody;
        $this->htmlbody = $other->htmlbody;
        $this->calbody = $other->calbody;
        $this->html_images = $other->html_images;
        $this->parts = $other->parts;
        $this->headers = $other->headers;
    }

    public function getParts(): array
    {
        return $this->parts;
    }

    /** @noinspection PhpUnused */
    public function setParts(array $parts): void
    {
        $this->parts = $parts;
    }

    public function setPart(int $i, array $part): void
    {
        $this->parts[$i] = $part;
    }
}