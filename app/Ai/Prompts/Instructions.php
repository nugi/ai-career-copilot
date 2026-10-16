<?php

namespace App\Ai\Prompts;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;

class Instructions
{
    /**
     * Read the instructions of an agent from its versioned prompt file.
     *
     * @throws FileNotFoundException when no prompt file exists for the agent and version
     */
    public static function load(string $agent, string $version): string
    {
        return File::get(resource_path("prompts/{$agent}/{$version}.md"));
    }
}
