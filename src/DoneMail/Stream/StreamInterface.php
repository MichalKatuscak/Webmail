<?php

namespace DoneMail\Stream;

interface StreamInterface
{
    public function __construct($server, $username, $password);
    public function getFolders($search);
    public function getMessages($folder, $search);
    public function changeFolder($folder);
}