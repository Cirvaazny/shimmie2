<?php

declare(strict_types=1);

class MP3FileHandlerInfo extends ExtensionInfo
{
    public const KEY = "handle_mp3";

    public string $key = self::KEY;
    public string $name = "Handle MP3";
    public string $url = self::SHIMMIE_URL;
    public array $authors = self::SHISH_AUTHOR;
    public string $description = "Handle MP3 files";
}
