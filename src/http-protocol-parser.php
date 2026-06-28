<?php

class HttpRequest
{
    private string $header;

    private string $body;

    public function __construct(string $header, string $body)
    {
        $this->header = $header;

        $this->body = $body;
    }

    public function getHeader()
    {
        return $this->header;
    }

    public function getBody()
    {
        return $this->body;
    }
}

class HttpRequestParser
{
    public static function parse(string $rawHttpRequest)
    {
        $breakLines = ["\n", "\r\n", "\r"];

        foreach ($breakLines as $breakLine) {
            $separator = str_repeat($breakLine, 2);

            $headerAndRequst = explode($separator, $rawHttpRequest);

            if (count($headerAndRequst) == 2) {
                return new HttpRequest($headerAndRequst[0], $headerAndRequst[1]);
            }
        }

        return false;
    }
}