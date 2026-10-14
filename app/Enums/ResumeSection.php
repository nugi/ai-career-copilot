<?php

namespace App\Enums;

enum ResumeSection: string
{
    case Summary = 'summary';
    case Experience = 'experience';
    case Education = 'education';
    case Skills = 'skills';
    case Projects = 'projects';
    case Other = 'other';
}
