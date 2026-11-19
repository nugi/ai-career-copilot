import { Deferred, Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { plan as makePlan, show as growShow } from '@/routes/grow';

type Gap = {
    skill: string;
    required_jobs: number;
    nice_jobs: number;
    of: number;
};
type Plan = {
    summary: string;
    weeks: { week: number; goal: string; skills: string[]; tasks: string[] }[];
    project: { title: string; description: string; skills: string[] };
    showcase: { resume_bullet: string; linkedin: string };
    dropped_skills: number;
};
type GrowProps = {
    plan: { plan: Plan; generated_at: string; available_at: string } | null;
    gaps?: Gap[];
};

export default function Grow({ plan, gaps }: GrowProps) {
    const availableAt = plan ? new Date(plan.available_at) : null;
    const coolingDown =
        availableAt !== null && availableAt.getTime() > Date.now();

    return (
        <>
            <Head title="Grow" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-8">
                <Heading title="Grow" />
                <Form {...makePlan.form()}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            disabled={processing || coolingDown}
                        >
                            {processing && <Spinner />}
                            {coolingDown && availableAt
                                ? `New plan on ${availableAt.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`
                                : plan
                                  ? 'Make a new plan'
                                  : 'Make a plan'}
                        </Button>
                    )}
                </Form>
                <section className="flex flex-col gap-4">
                    <h2 className="font-medium">Skill gaps</h2>
                    <Deferred
                        data="gaps"
                        fallback={
                            <div
                                className="flex flex-col gap-3"
                                aria-label="Loading skill gaps"
                            >
                                <Skeleton className="h-16 w-full" />
                                <Skeleton className="h-16 w-full" />
                            </div>
                        }
                    >
                        {gaps &&
                            (gaps.length === 0 ? (
                                <Card className="items-center px-6 py-10 text-center">
                                    <p className="font-medium">
                                        No skill gaps found in your top matches.
                                    </p>
                                </Card>
                            ) : (
                                <div className="flex flex-col gap-3">
                                    {gaps.map((gap) => (
                                        <Card
                                            key={gap.skill}
                                            className="gap-2 py-4"
                                        >
                                            <CardContent>
                                                <p className="font-medium">
                                                    {gap.skill}
                                                </p>
                                                {gap.required_jobs > 0 && (
                                                    <p className="text-sm text-muted-foreground">
                                                        Required in{' '}
                                                        {gap.required_jobs} of{' '}
                                                        {gap.of} top matches
                                                    </p>
                                                )}
                                                {gap.nice_jobs > 0 && (
                                                    <p className="text-sm text-muted-foreground">
                                                        Nice-to-have in{' '}
                                                        {gap.nice_jobs}
                                                    </p>
                                                )}
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            ))}
                    </Deferred>
                </section>
                {plan && (
                    <section className="flex flex-col gap-4">
                        <p>{plan.plan.summary}</p>
                        {plan.plan.weeks.map((week) => (
                            <Card key={week.week}>
                                <CardHeader>
                                    <CardTitle>
                                        Week {week.week}: {week.goal}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3">
                                    <div className="flex flex-wrap gap-1.5">
                                        {week.skills.map((skill) => (
                                            <Badge
                                                key={skill}
                                                variant="secondary"
                                            >
                                                {skill}
                                            </Badge>
                                        ))}
                                    </div>
                                    <ul className="list-disc pl-5 text-sm">
                                        {week.tasks.map((task, index) => (
                                            <li key={index}>{task}</li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        ))}
                        <Card>
                            <CardHeader>
                                <CardTitle>{plan.plan.project.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                <p>{plan.plan.project.description}</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {plan.plan.project.skills.map((skill) => (
                                        <Badge key={skill} variant="secondary">
                                            {skill}
                                        </Badge>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="flex flex-col gap-4">
                                <div>
                                    <h3 className="font-medium">Resume</h3>
                                    <p>{plan.plan.showcase.resume_bullet}</p>
                                </div>
                                <div>
                                    <h3 className="font-medium">LinkedIn</h3>
                                    <p>{plan.plan.showcase.linkedin}</p>
                                </div>
                            </CardContent>
                        </Card>
                    </section>
                )}
            </div>
        </>
    );
}

Grow.layout = {
    breadcrumbs: [{ title: 'Grow', href: growShow() }],
};
