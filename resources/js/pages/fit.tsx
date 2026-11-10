import { Deferred, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index as matchesIndex } from '@/routes/matches';

type Excerpt = { id: number; section: string; content: string };
type Verdict = 'strong_fit' | 'partial_fit' | 'weak_fit';
type Explanation = {
    status:
        | 'ready'
        | 'no_resume'
        | 'resume_not_ready'
        | 'posting_not_ready'
        | 'budget'
        | 'failed';
    verdict: Verdict | null;
    summary: string;
    reasons: { claim: string; excerpts: Excerpt[] }[];
    missing_requirements: string[];
    dropped_reasons: number;
    downgraded: boolean;
};
type FitProps = {
    posting: {
        id: number;
        title: string;
        company: string;
        apply_url: string;
        skills: { name: string; required: boolean }[];
    };
    explanation?: Explanation;
};

const verdicts: Record<
    Verdict,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    strong_fit: { label: 'Strong fit', variant: 'default' },
    partial_fit: { label: 'Partial fit', variant: 'secondary' },
    weak_fit: { label: 'Weak fit', variant: 'outline' },
};

const unavailable: Record<Exclude<Explanation['status'], 'ready'>, string> = {
    no_resume: 'Upload a resume to see why this job fits you.',
    resume_not_ready:
        'Your resume is still being processed. Try again in a minute.',
    posting_not_ready: 'This job is still being processed. Try again later.',
    budget: "You have used today's AI budget. It resets at midnight UTC.",
    failed: 'The explanation is unavailable right now. Try again later.',
};

const sections: Record<string, string> = {
    summary: 'Summary',
    experience: 'Experience',
    education: 'Education',
    skills: 'Skills',
    projects: 'Projects',
    other: 'Resume',
};

function ExplanationView({ explanation }: { explanation: Explanation }) {
    if (explanation.status !== 'ready' || explanation.verdict === null) {
        return (
            <Card className="items-center px-6 py-10 text-center">
                <p className="font-medium">
                    {
                        unavailable[
                            explanation.status === 'ready'
                                ? 'failed'
                                : explanation.status
                        ]
                    }
                </p>
            </Card>
        );
    }

    const verdict = verdicts[explanation.verdict];

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-2">
                <div>
                    <Badge variant={verdict.variant}>{verdict.label}</Badge>
                </div>
                <p>{explanation.summary}</p>
                {explanation.downgraded && (
                    <p className="text-sm text-muted-foreground">
                        Lowered to a weak fit: none of the reasons was backed by
                        your resume.
                    </p>
                )}
            </div>

            <section className="flex flex-col gap-3">
                <h2 className="font-medium">Why it fits</h2>
                {explanation.reasons.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing in your resume matches this job's core
                        requirements.
                    </p>
                ) : (
                    explanation.reasons.map((reason, index) => (
                        <Card key={index} className="gap-3 py-4">
                            <CardContent className="flex flex-col gap-3">
                                <p>{reason.claim}</p>
                                {reason.excerpts.map((excerpt) => (
                                    <blockquote
                                        key={excerpt.id}
                                        className="border-l-2 pl-3 text-sm whitespace-pre-line text-muted-foreground"
                                    >
                                        <span className="mb-1 block text-xs font-medium tracking-wide uppercase">
                                            {sections[excerpt.section] ??
                                                'Resume'}
                                        </span>
                                        {excerpt.content}
                                    </blockquote>
                                ))}
                            </CardContent>
                        </Card>
                    ))
                )}
                {explanation.dropped_reasons > 0 && (
                    <p className="text-xs text-muted-foreground">
                        {explanation.dropped_reasons}{' '}
                        {explanation.dropped_reasons === 1
                            ? 'reason was'
                            : 'reasons were'}{' '}
                        left out because they did not cite your resume.
                    </p>
                )}
            </section>

            {explanation.missing_requirements.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="font-medium">Not shown in your resume</h2>
                    <ul className="list-disc pl-5 text-sm">
                        {explanation.missing_requirements.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

export default function Fit({ posting, explanation }: FitProps) {
    return (
        <>
            <Head title={`Fit: ${posting.title}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Why is this job a fit?"
                    description="Each reason quotes the part of your resume it comes from."
                />
                <Card className="gap-3 py-5">
                    <CardHeader className="gap-2">
                        <CardTitle>
                            <a
                                href={posting.apply_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="rounded-sm underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                            >
                                {posting.title}
                            </a>
                        </CardTitle>
                        <p className="text-sm">{posting.company}</p>
                    </CardHeader>
                    {posting.skills.length > 0 && (
                        <CardContent className="flex flex-wrap gap-1.5">
                            {posting.skills.map((skill) => (
                                <Badge
                                    key={skill.name}
                                    variant={
                                        skill.required ? 'default' : 'outline'
                                    }
                                >
                                    {skill.name}
                                </Badge>
                            ))}
                        </CardContent>
                    )}
                </Card>
                <Deferred
                    data="explanation"
                    fallback={
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Reading your resume against this job…
                        </div>
                    }
                >
                    {explanation && (
                        <ExplanationView explanation={explanation} />
                    )}
                </Deferred>
            </div>
        </>
    );
}

Fit.layout = {
    breadcrumbs: [{ title: 'Matches', href: matchesIndex() }],
};
