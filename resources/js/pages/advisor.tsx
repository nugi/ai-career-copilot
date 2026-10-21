import { Head } from '@inertiajs/react';
import { AlertCircle, Send, Square } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAdvisorStream } from '@/hooks/use-advisor-stream';
import { chat as advisorChat } from '@/routes/advisor';

const MAX_QUESTION_LENGTH = 2000;

export default function Advisor() {
    const { answer, status, error, fieldError, send, cancel } =
        useAdvisorStream();
    const [question, setQuestion] = useState('');

    const isStreaming = status === 'streaming';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const trimmed = question.trim();

        if (trimmed === '' || isStreaming) {
            return;
        }

        void send(trimmed);
    };

    const submitOnShortcut = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        // Ctrl+Enter and Cmd+Enter send the form, so the user can ask without leaving the textarea.
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <>
            <Head title="Career advisor" />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-8">
                <Heading
                    title="Career advisor"
                    description="Ask about your next step, your resume, or the roles you are aiming for."
                />

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="question">Your question</Label>
                        <Textarea
                            id="question"
                            name="question"
                            value={question}
                            rows={4}
                            required
                            maxLength={MAX_QUESTION_LENGTH}
                            aria-invalid={fieldError !== null}
                            placeholder="What should I learn next to become a backend engineer?"
                            onChange={(event) =>
                                setQuestion(event.target.value)
                            }
                            onKeyDown={submitOnShortcut}
                        />
                        <InputError message={fieldError ?? undefined} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={isStreaming}>
                            {isStreaming ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <Send data-icon="inline-start" />
                            )}
                            Send
                        </Button>

                        {isStreaming && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={cancel}
                            >
                                <Square data-icon="inline-start" />
                                Stop
                            </Button>
                        )}
                    </div>
                </form>

                {error !== null && (
                    <Alert variant="destructive">
                        <AlertCircle />
                        <AlertTitle>Could not get an answer</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {answer !== '' && (
                    <Card className="px-6" aria-live="polite">
                        <p className="whitespace-pre-wrap">{answer}</p>
                    </Card>
                )}
            </div>
        </>
    );
}

Advisor.layout = {
    breadcrumbs: [
        {
            title: 'Career advisor',
            href: advisorChat(),
        },
    ],
};
