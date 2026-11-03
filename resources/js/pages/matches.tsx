import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { type Job, JobItem } from '@/components/job-item';
import { Card } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as matchesIndex } from '@/routes/matches';

type MatchesProps = {
    matches: { data: Job[] };
    filters: { country: string | null };
    countries: { code: string; name: string }[];
    failed: boolean;
};

export default function Matches({
    matches,
    filters,
    countries,
    failed,
}: MatchesProps) {
    const changeCountry = (value: string) => {
        router.get(
            matchesIndex.url(),
            { country: value === 'any' ? '' : value },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    return (
        <>
            <Head title="Matches" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-8">
                <Heading title="Matches" />
                <div className="flex flex-wrap items-center gap-3">
                    <Select
                        value={filters.country ?? 'any'}
                        onValueChange={changeCountry}
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
                </div>
                {failed ? (
                    <Card className="items-center gap-4 px-6 py-10 text-center">
                        <p className="font-medium">
                            Matches are unavailable right now. Try again later.
                        </p>
                    </Card>
                ) : matches.data.length === 0 ? (
                    <Card className="items-center gap-4 px-6 py-10 text-center">
                        <p className="font-medium">No matches yet.</p>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-4">
                        {matches.data.map((job) => (
                            <JobItem key={job.opening_id} job={job} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Matches.layout = {
    breadcrumbs: [{ title: 'Matches', href: matchesIndex() }],
};
