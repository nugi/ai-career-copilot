import { router } from '@inertiajs/react';
import { AlertCircle, Send, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCopilotStream } from '@/hooks/use-copilot-stream';
import { cn } from '@/lib/utils';

const MAX_MESSAGE_LENGTH = 2000;

export type CopilotMessage = {
    role: 'user' | 'assistant';
    content: string;
};

export type CopilotConversation = {
    id: string;
    title: string;
    messages: CopilotMessage[];
};

type ChatProps = {
    conversation: CopilotConversation | null;
    onConversation: (conversationId: string) => void;
};

/**
 * Show the messages of a copilot chat, and send the user's messages to the copilot.
 *
 * The chat owns its stream, so a page change that remounts the chat aborts the answer that is still running.
 */
export function Chat({ conversation, onConversation }: ChatProps) {
    const [messages, setMessages] = useState<CopilotMessage[]>(
        conversation?.messages ?? [],
    );
    const [conversationId, setConversationId] = useState<string | null>(
        conversation?.id ?? null,
    );
    const [draft, setDraft] = useState('');
    const endOfMessages = useRef<HTMLDivElement>(null);

    const handleConversation = (id: string): void => {
        setConversationId(id);
        onConversation(id);
    };

    const { answer, status, toolStatus, error, fieldError, send, cancel } =
        useCopilotStream(handleConversation);

    const isStreaming = status === 'streaming';

    useEffect(() => {
        if (status === 'done' && answer !== '') {
            setMessages((current) => [
                ...current,
                { role: 'assistant', content: answer },
            ]);
        }

        if (
            (status === 'done' || status === 'error') &&
            conversationId !== null
        ) {
            router.reload({ only: ['conversations'] });
        }
    }, [status, answer, conversationId]);

    useEffect(() => {
        endOfMessages.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, answer, toolStatus]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const message = draft.trim();

        if (message === '' || isStreaming) {
            return;
        }

        setMessages((current) => [
            ...current,
            { role: 'user', content: message },
        ]);
        setDraft('');
        void send(message, conversationId);
    };

    const submitOnShortcut = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        // Ctrl+Enter and Cmd+Enter send the form, so the user can ask without leaving the textarea.
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-3">
                {messages.map((message, index) => (
                    <MessageBubble key={index} message={message} />
                ))}

                {isStreaming && (
                    <div className="flex justify-start" aria-live="polite">
                        <div className="flex max-w-[85%] items-center gap-2 rounded-lg bg-muted px-4 py-2 text-sm whitespace-pre-wrap">
                            {answer === '' ? (
                                <>
                                    <Spinner />
                                    {toolStatus !== null && (
                                        <span>{toolStatus}</span>
                                    )}
                                </>
                            ) : (
                                answer
                            )}
                        </div>
                    </div>
                )}

                {error !== null && (
                    <Alert variant="destructive">
                        <AlertCircle />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div ref={endOfMessages} />
            </div>

            <form onSubmit={submit} className="flex flex-col gap-2">
                <Textarea
                    name="message"
                    aria-label="Message"
                    value={draft}
                    rows={3}
                    maxLength={MAX_MESSAGE_LENGTH}
                    aria-invalid={fieldError !== null}
                    placeholder="Ask about your next step, your resume, or the roles you are aiming for."
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={submitOnShortcut}
                />
                <InputError message={fieldError ?? undefined} />

                <div className="flex justify-end gap-2">
                    {isStreaming ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={cancel}
                        >
                            <Square data-icon="inline-start" />
                            Stop
                        </Button>
                    ) : (
                        <Button type="submit" disabled={draft.trim() === ''}>
                            <Send data-icon="inline-start" />
                            Send
                        </Button>
                    )}
                </div>
            </form>
        </div>
    );
}

/**
 * Show one message as plain text. The user's messages align right and the copilot's align left.
 */
function MessageBubble({ message }: { message: CopilotMessage }) {
    const isUser = message.role === 'user';

    return (
        <div className={cn('flex', isUser ? 'justify-end' : 'justify-start')}>
            <p
                className={cn(
                    'max-w-[85%] rounded-lg px-4 py-2 text-sm whitespace-pre-wrap',
                    isUser ? 'bg-primary text-primary-foreground' : 'bg-muted',
                )}
            >
                {message.content}
            </p>
        </div>
    );
}
