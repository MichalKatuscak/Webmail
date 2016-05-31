<?php

namespace DoneMail\Stream;

class IMAP implements StreamInterface
{
    protected $server, $username, $password;
    protected $stream;
    protected $actualFolder;

    public function __construct($server, $username, $password)
    {
        $stream = imap_open($server, $username, $password);

        if (!$stream) {
            new \Exception("IMAP - Bad credentials");
        }

        $this->server = $server;
        $this->username = $username;
        $this->password = $password;

        $this->stream = $stream;
    }

    public function getFolders($search)
    {
        $folders = imap_list($this->stream, $this->server, $search);

        $folders = str_replace($this->server, "", $folders);

        return $folders;
    }

    public function getMessages($folder, $search)
    {
        $this->changeFolder($folder);

        $emails = imap_search($this->stream, $search);

        if ($emails) {
            rsort($emails); // Newest mail on top

            foreach($emails as $i => $email_number) {
                $content = $this->getContent($email_number);
                $content["overview"] = imap_fetch_overview($this->stream, $email_number,0);

                $emails[$i] = $content;
            }
        }

        return $emails;
    }

    public function changeFolder($folder)
    {
        imap_reopen($this->stream, $this->server . $folder);

        $this->actualFolder = $folder;
    }

    protected function getContent($email_number)
    {
        $content = [
            "charset" => "",
            "html" => "",
            "plain" => "",
            "attachments" => "",
            "headers" => imap_header($this->stream, $email_number)
        ];

        $structure = imap_fetchstructure($this->stream, $email_number);

        if (empty($structure->parts)) {
            $content = $this->getContentPart($content, $email_number, $structure, 0);
        } else {
            foreach ($structure->parts as $part_number => $part) {
                $content = $this->getContentPart($content, $email_number, $part, $part_number + 1);
            }
        }

        return $content;
    }


    protected function getContentPart($content, $email_number, $part, $part_number) {
        // DECODE DATA
        $data = ($part_number)?
            imap_fetchbody($this->stream, $email_number, $part_number):  // multipart
            imap_body($this->stream, $email_number);  // simple

        switch ($part->encoding) {
            case 0:
            case 1:
                $data = imap_8bit($data);
                break;
            case 2:
                $data = imap_binary($data);
                break;
            case 3:
                $data = imap_base64($data);
                break;
            case 4:
                $data = quoted_printable_decode($data);
                break;
        }

        // PARAMETERS (charset, filenames of attachments)
        $params = array();

        if (!empty($part->parameters)) {
            foreach ($part->parameters as $x) {
                $params[strtolower($x->attribute)] = $x->value;
            }
        }

        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $x) {
                $params[strtolower($x->attribute)] = $x->value;
            }
        }

        // ATTACHMENT
        if (!empty($params['filename']) || !empty($params['name'])) {
            $filename = !empty($params['filename']) ? $params['filename'] : $params['name'];
            if (substr($filename,0,2) == "=?"){
                $elements = imap_mime_header_decode($filename);
                $filename = "";
                foreach ($elements as $element) {
                    $filename .= $element->text;
                }
            }
            $content["attachments"][$filename] = $filename;//$data;  // this is a problem if two files have same name
        }

        // TEXT
        if ($part->type==0 && $data) {
            if (strtolower($part->subtype)=='plain') {
                $content["plain"] .= trim($data) . "\n\n";
            } else {
                $content["html"] .= $data . "<br><br>";
            }
            $content["charset"] = $params['charset'];  // assume all parts are same charset
        }

        // EMBEDDED MESSAGE
        elseif ($part->type==2 && $data) {
            $content["plain"] .= $data."\n\n";
        }

        // SUBPART RECURSION
        if (!empty($part->parts)) {
            foreach ($part->parts as $part_number => $part_child) {
                $content = $this->getContentPart($content, $email_number, $part_child, $part_number . '.' . ($part_number + 1));
            }
        }

        return $content;
    }
}