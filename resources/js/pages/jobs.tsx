import { Head, InfiniteScroll, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

type Arrangement = 'remote' | 'hybrid' | 'onsite';
type PayPeriod =
    | 'hourly'
    | 'daily'
    | 'weekly'
    | 'fortnightly'
    | 'monthly'
    | 'yearly';
type Amounts = { min: number | null; max: number | null };
type Pay = Amounts & { currency: string | null; period: PayPeriod | null };
type ConvertedPay = Amounts & {
    currency: string;
    period: 'monthly' | 'yearly';
    rate: number;
    rate_date: string;
    is_stale: boolean;
};
type Job = {
    opening_id: number;
    title: string;
    company: string;
    location: string;
    work_arrangement: { value: Arrangement; label: string };
    posted_at: string;
    apply_url: string;
    pay: Pay | null;
    converted: ConvertedPay | null;
    skills: { name: string; required: boolean }[];
};
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

const periods: Record<PayPeriod, string> = {
    hourly: 'hour',
    daily: 'day',
    weekly: 'week',
    fortnightly: 'fortnight',
    monthly: 'month',
    yearly: 'year',
};
const numberFormatter = new Intl.NumberFormat('en-US');
const rateFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});
const dateFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatRange(
    amounts: Amounts,
    format: (value: number) => string,
): string {
    if (amounts.min !== null && amounts.max !== null) {
        return `${format(amounts.min)}–${format(amounts.max)}`;
    }

    if (amounts.min !== null) {
        return `From ${format(amounts.min)}`;
    }

    return amounts.max === null ? '' : `Up to ${format(amounts.max)}`;
}

function PostedPay({ pay }: { pay: Pay }) {
    const format = (amount: number) =>
        `${pay.currency ? `${pay.currency} ` : ''}${numberFormatter.format(amount)}`;
    const range =
        pay.min !== null && pay.max !== null
            ? `${pay.currency ? `${pay.currency} ` : ''}${numberFormatter.format(pay.min)}–${numberFormatter.format(pay.max)}`
            : formatRange(pay, format);

    return (
        <span>
            {range}
            {pay.period && ` / ${periods[pay.period]}`}
        </span>
    );
}

function ConvertedAmount({
    converted,
    currency,
}: {
    converted: ConvertedPay;
    currency: string | null;
}) {
    const formatter = new Intl.NumberFormat(
        converted.currency === 'IDR' ? 'id-ID' : 'en-US',
        {
            style: 'currency',
            currency: converted.currency,
            currencyDisplay: 'narrowSymbol',
            notation: 'compact',
            maximumFractionDigits: 1,
        },
    );
    const tooltip = `at ${rateFormatter.format(converted.rate)} ${converted.currency} per ${currency ?? ''} (${dateFormatter.format(new Date(`${converted.rate_date}T00:00:00Z`))})`;

    return (
        <span className="text-muted-foreground" title={tooltip}>
            ≈ {formatRange(converted, (amount) => formatter.format(amount))} /{' '}
            {periods[converted.period]}
            {converted.is_stale && ' (old rate)'}
        </span>
    );
}

function JobItem({ job }: { job: Job }) {
    return (
        <Card className="gap-4 py-5">
            <CardHeader className="gap-2">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <CardTitle>
                        <a
                            href={job.apply_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="rounded-sm underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                        >
                            {job.title}
                        </a>
                    </CardTitle>
                    <Badge variant="secondary">
                        {job.work_arrangement.label}
                    </Badge>
                </div>
                <p className="text-sm">{job.company}</p>
                {job.location && (
                    <p className="text-sm text-muted-foreground">
                        {job.location}
                    </p>
                )}
            </CardHeader>
            {(job.pay !== null || job.skills.length > 0) && (
                <CardContent className="flex flex-col gap-3">
                    {job.pay && (
                        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm tabular-nums">
                            <PostedPay pay={job.pay} />
                            {job.converted && (
                                <ConvertedAmount
                                    converted={job.converted}
                                    currency={job.pay.currency}
                                />
                            )}
                        </p>
                    )}
                    {job.skills.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {job.skills.slice(0, 8).map((skill) => (
                                <Badge
                                    key={skill.name}
                                    variant={
                                        skill.required ? 'default' : 'outline'
                                    }
                                >
                                    {skill.name}
                                </Badge>
                            ))}
                        </div>
                    )}
                </CardContent>
            )}
        </Card>
    );
}

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
            ...(next.country ? { country: next.country } : {}),
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
