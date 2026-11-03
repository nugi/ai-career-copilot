import { Head, InfiniteScroll, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import type { Arrangement, Job } from '@/components/job-item';
import { JobItem } from '@/components/job-item';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index as jobsIndex } from '@/routes/jobs';

type Filters = {
    arrangement: Arrangement[];
    country: string | null;
    skill: number | null;
    floor: boolean;
};
type JobsProps = {
    jobs: { data: Job[] };
    filters: Filters;
    skills: { id: number; name: string; postings: number }[];
    countries: { code: string; name: string }[];
    floor: {
        amount: number;
        currency: string;
        period: 'monthly' | 'yearly';
    } | null;
};

export default function Jobs({
    jobs,
    filters,
    skills,
    countries,
    floor,
}: JobsProps) {
    const changeFilters = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query = {
            ...(next.arrangement.length > 0
                ? { arrangement: next.arrangement }
                : {}),
            country: next.country ?? '',
            ...(next.skill !== null ? { skill: next.skill } : {}),
            ...(next.floor ? { floor: 1 } : {}),
        };

        router.get(jobsIndex.url(), query, {
            preserveState: true,
            replace: true,
            reset: ['jobs'],
        });
    };

    return (
        <>
            <Head title="Jobs" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-8">
                <Heading title="Jobs" />
                <div
                    className="flex flex-wrap items-center gap-3"
                    role="group"
                    aria-label="Job filters"
                >
                    <ToggleGroup
                        type="multiple"
                        variant="outline"
                        aria-label="Work arrangement"
                        value={filters.arrangement}
                        onValueChange={(values: Arrangement[]) =>
                            changeFilters({ arrangement: values })
                        }
                    >
                        <ToggleGroupItem value="remote">Remote</ToggleGroupItem>
                        <ToggleGroupItem value="hybrid">Hybrid</ToggleGroupItem>
                        <ToggleGroupItem value="onsite">
                            On-site
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <Select
                        value={filters.country ?? 'any'}
                        onValueChange={(value) =>
                            changeFilters({
                                country: value === 'any' ? null : value,
                            })
                        }
                    >
                        <SelectTrigger aria-label="Country">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem value="any">Any country</SelectItem>
                                {countries.map((country) => (
                                    <SelectItem
                                        key={country.code}
                                        value={country.code}
                                    >
                                        {country.name}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.skill?.toString() ?? 'any'}
                        onValueChange={(value) =>
                            changeFilters({
                                skill: value === 'any' ? null : Number(value),
                            })
                        }
                    >
                        <SelectTrigger aria-label="Skill">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem value="any">Any skill</SelectItem>
                                {skills.map((skill) => (
                                    <SelectItem
                                        key={skill.id}
                                        value={skill.id.toString()}
                                    >
                                        {skill.name}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    {floor !== null && (
                        <Toggle
                            variant="outline"
                            pressed={filters.floor}
                            onPressedChange={(pressed) =>
                                changeFilters({ floor: pressed })
                            }
                        >
                            Meets my minimum
                        </Toggle>
                    )}
                </div>
                {jobs.data.length === 0 ? (
                    <Card className="items-center gap-4 px-6 py-10 text-center">
                        <p className="font-medium">
                            No jobs match these filters.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() =>
                                changeFilters({
                                    arrangement: [],
                                    country: null,
                                    skill: null,
                                    floor: false,
                                })
                            }
                        >
                            Clear filters
                        </Button>
                    </Card>
                ) : (
                    <InfiniteScroll data="jobs" className="flex flex-col gap-4">
                        {jobs.data.map((job) => (
                            <JobItem key={job.opening_id} job={job} />
                        ))}
                    </InfiniteScroll>
                )}
            </div>
        </>
    );
}

Jobs.layout = {
    breadcrumbs: [{ title: 'Jobs', href: jobsIndex() }],
};
