<?php

namespace App\Actions\Jobs;

use App\Enums\WorkArrangement;
use App\Models\Skill;

/**
 * The filters of one jobs list request.
 */
final readonly class JobSearch
{
    /**
     * @param  list<WorkArrangement>  $arrangements  Empty means every arrangement.
     * @param  string|null  $country  ISO 3166-1 alpha-2, uppercase: the country the user lives in.
     * @param  bool  $meetsFloor  Remove pay that is certainly below the user's floor.
     */
    public function __construct(
        public array $arrangements,
        public ?string $country,
        public ?Skill $skill,
        public bool $meetsFloor,
    ) {}
}
