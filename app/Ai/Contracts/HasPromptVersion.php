<?php

namespace App\Ai\Contracts;

interface HasPromptVersion
{
    /**
     * Get the version of the prompt file that the agent's instructions come from.
     */
    public function promptVersion(): string;
}
