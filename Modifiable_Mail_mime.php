<?php

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