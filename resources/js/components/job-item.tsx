import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export type Arrangement = 'remote' | 'hybrid' | 'onsite';
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
export type Job = {
    opening_id: number;
    title: string;
    company: string;
    location: string;
    work_arrangement: { value: Arrangement; label: string };
    eligibility: 'open' | 'unclear' | 'restricted' | null;
    eligibility_evidence: string | null;
    posted_at: string;
    apply_url: string;
    pay: Pay | null;
    converted: ConvertedPay | null;
    skills: { name: string; required: boolean }[];
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

export function JobItem({ job }: { job: Job }) {
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
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {job.work_arrangement.label}
                        </Badge>
                        {job.eligibility === 'unclear' && (
                            <Badge variant="outline">Country not stated</Badge>
                        )}
                        {job.eligibility === 'restricted' && (
                            <Badge variant="outline">
                                May not accept your country
                            </Badge>
                        )}
                    </div>
                </div>
                <p className="text-sm">{job.company}</p>
                {job.location && (
                    <p className="text-sm text-muted-foreground">
                        {job.location}
                    </p>
                )}
                {job.eligibility === 'restricted' &&
                    job.eligibility_evidence && (
                        <p className="text-sm text-muted-foreground">
                            The posting says: “{job.eligibility_evidence}”
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
