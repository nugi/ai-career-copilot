import { useCallback, useEffect, useRef, useState } from 'react';
import type { Dispatch, SetStateAction } from 'react';
import { stream as copilotStream } from '@/routes/copilot';

export type CopilotStreamStatus = 'idle' | 'streaming' | 'done' | 'error';

export type UseCopilotStreamReturn = {
    answer: string;
    status: CopilotStreamStatus;
    toolStatus: string | null;
    error: string | null;
    fieldError: string | null;
    send: (message: string, conversationId: string | null) => Promise<void>;
    cancel: () => void;
};

type StreamEvent = {
    type?: string;
    delta?: string;
    id?: string;
    label?: string;
    message?: string;
};

type ValidationErrorResponse = {
    errors?: Record<string, string[] | undefined>;
};

type TooManyRequestsResponse = {
    message?: string;
    code?: string;
};

type StateSetters = {
    setAnswer: Dispatch<SetStateAction<string>>;
    setStatus: Dispatch<SetStateAction<CopilotStreamStatus>>;
    setToolStatus: Dispatch<SetStateAction<string | null>>;
    setError: Dispatch<SetStateAction<string | null>>;
    setFieldError: Dispatch<SetStateAction<string | null>>;
    onConversation: (conversationId: string) => void;
};

const UNAVAILABLE_MESSAGE =
    'The copilot is unavailable. Try again in a minute.';

const RATE_LIMITED_MESSAGE =
    'You sent too many messages. Wait a minute and try again.';

const SHOWN_RATE_LIMIT_CODES = [
    'ai_budget_exceeded',
    'ai_global_budget_exceeded',
    'ai_new_account_reserve_exceeded',
];

/**
 * Return the XSRF token that Laravel expects in the header of a request that is not sent by Inertia.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Return true when the failure is the abort of a request, which is not an error for the user.
 */
function isAbortError(thrown: unknown): boolean {
    return thrown instanceof DOMException && thrown.name === 'AbortError';
}

/**
 * Set the failure that a response with a status other than 200 describes.
 */
async function reportFailure(
    setters: StateSetters,
    response: Response,
): Promise<void> {
    if (response.status === 422) {
        const body = (await response
            .json()
            .catch(() => ({}))) as ValidationErrorResponse;

        setters.setFieldError(body.errors?.message?.[0] ?? UNAVAILABLE_MESSAGE);
    } else if (response.status === 429) {
        const body = (await response
            .json()
            .catch(() => ({}))) as TooManyRequestsResponse;

        setters.setError(
            body.code !== undefined &&
                SHOWN_RATE_LIMIT_CODES.includes(body.code) &&
                typeof body.message === 'string'
                ? body.message
                : RATE_LIMITED_MESSAGE,
        );
    } else {
        setters.setError(UNAVAILABLE_MESSAGE);
    }

    setters.setStatus('error');
}

/**
 * Apply one server-sent event block to the answer, the tool status, the error, or the status.
 *
 * Return true when the block is the `[DONE]` event that completes the stream.
 */
function applyEvent(setters: StateSetters, block: string): boolean {
    const line = block
        .split('\n')
        .find((candidate) => candidate.startsWith('data: '));

    if (line === undefined) {
        return false;
    }

    const payload = line.slice('data: '.length);

    if (payload === '[DONE]') {
        setters.setToolStatus(null);
        setters.setStatus((current) =>
            current === 'error' ? current : 'done',
        );

        return true;
    }

    let event: StreamEvent;

    try {
        event = JSON.parse(payload) as StreamEvent;
    } catch {
        return false;
    }

    if (event.type === 'conversation' && typeof event.id === 'string') {
        setters.onConversation(event.id);
    } else if (event.type === 'status' && typeof event.label === 'string') {
        setters.setToolStatus(event.label);
    } else if (event.type === 'text_delta' && typeof event.delta === 'string') {
        const delta = event.delta;

        setters.setToolStatus(null);
        setters.setAnswer((current) => current + delta);
    } else if (event.type === 'error') {
        setters.setToolStatus(null);
        setters.setError(
            typeof event.message === 'string'
                ? event.message
                : UNAVAILABLE_MESSAGE,
        );
        setters.setStatus('error');
    }

    return false;
}

/**
 * Read the server-sent events of a response until the stream ends or the request is aborted.
 */
async function readEvents(
    setters: StateSetters,
    response: Response,
    isStale: () => boolean,
): Promise<void> {
    const reader = response.body?.getReader();

    if (!reader) {
        setters.setError(UNAVAILABLE_MESSAGE);
        setters.setStatus('error');

        return;
    }

    const decoder = new TextDecoder();
    let buffer = '';
    let sawDone = false;

    try {
        for (;;) {
            const { done, value } = await reader.read();

            if (done || isStale()) {
                if (done && !sawDone && !isStale()) {
                    setters.setError(
                        'The answer stopped before it finished. Try again.',
                    );
                    setters.setStatus('error');
                }

                return;
            }

            buffer += decoder.decode(value, { stream: true });

            let boundary = buffer.indexOf('\n\n');

            while (boundary !== -1) {
                if (applyEvent(setters, buffer.slice(0, boundary))) {
                    sawDone = true;
                }

                buffer = buffer.slice(boundary + 2);
                boundary = buffer.indexOf('\n\n');
            }
        }
    } catch (thrown) {
        if (!isAbortError(thrown) && !isStale()) {
            setters.setError(UNAVAILABLE_MESSAGE);
            setters.setStatus('error');
        }
    } finally {
        if (!isStale()) {
            setters.setToolStatus(null);
        }
    }
}

/**
 * Stream the copilot's answer over server-sent events.
 *
 * The hook owns the request, so a new message or a cancel aborts the one that is still running.
 * The onConversation callback runs once per new conversation, with its ID.
 */
export function useCopilotStream(
    onConversation: (conversationId: string) => void,
): UseCopilotStreamReturn {
    const [answer, setAnswer] = useState('');
    const [status, setStatus] = useState<CopilotStreamStatus>('idle');
    const [toolStatus, setToolStatus] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [fieldError, setFieldError] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    const conversationHandler = useRef(onConversation);

    useEffect(() => {
        conversationHandler.current = onConversation;
    }, [onConversation]);

    const send = useCallback(
        async (
            message: string,
            conversationId: string | null,
        ): Promise<void> => {
            request.current?.abort();

            const controller = new AbortController();
            request.current = controller;

            const isStale = (): boolean => request.current !== controller;

            setAnswer('');
            setToolStatus(null);
            setError(null);
            setFieldError(null);
            setStatus('streaming');

            let response: Response;

            try {
                response = await fetch(copilotStream.url(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({
                        message,
                        ...(conversationId !== null
                            ? { conversation_id: conversationId }
                            : {}),
                    }),
                });
            } catch (thrown) {
                if (!isAbortError(thrown)) {
                    setError(UNAVAILABLE_MESSAGE);
                    setStatus('error');
                }

                return;
            }

            if (isStale()) {
                return;
            }

            const setters: StateSetters = {
                setAnswer,
                setStatus,
                setToolStatus,
                setError,
                setFieldError,
                onConversation: (id) => conversationHandler.current(id),
            };

            if (!response.ok) {
                await reportFailure(setters, response);

                return;
            }

            await readEvents(setters, response, isStale);
        },
        [],
    );

    const cancel = useCallback((): void => {
        request.current?.abort();
        request.current = null;

        setToolStatus(null);
        setStatus((current) => (current === 'streaming' ? 'idle' : current));
    }, []);

    useEffect(() => {
        return () => request.current?.abort();
    }, []);

    return { answer, status, toolStatus, error, fieldError, send, cancel };
}
