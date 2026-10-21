import { useCallback, useEffect, useRef, useState } from 'react';
import type { Dispatch, SetStateAction } from 'react';
import { stream as advisorStream } from '@/routes/advisor';

export type AdvisorStreamStatus = 'idle' | 'streaming' | 'done' | 'error';

export type UseAdvisorStreamReturn = {
    answer: string;
    status: AdvisorStreamStatus;
    error: string | null;
    fieldError: string | null;
    send: (question: string) => Promise<void>;
    cancel: () => void;
};

type StreamEvent = {
    type?: string;
    delta?: string;
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
    setStatus: Dispatch<SetStateAction<AdvisorStreamStatus>>;
    setError: Dispatch<SetStateAction<string | null>>;
    setFieldError: Dispatch<SetStateAction<string | null>>;
};

const UNAVAILABLE_MESSAGE =
    'The advisor is unavailable. Try again in a minute.';

const RATE_LIMITED_MESSAGE =
    'You asked too many questions. Wait a minute and try again.';

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

        setters.setFieldError(
            body.errors?.question?.[0] ?? UNAVAILABLE_MESSAGE,
        );
    } else if (response.status === 429) {
        const body = (await response
            .json()
            .catch(() => ({}))) as TooManyRequestsResponse;

        setters.setError(
            body.code === 'ai_budget_exceeded' &&
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
 * Apply one server-sent event block to the answer, the error, or the status.
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

    const delta = event.delta;

    if (event.type === 'text_delta' && typeof delta === 'string') {
        setters.setAnswer((current) => current + delta);
    } else if (event.type === 'error') {
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
    }
}

/**
 * Stream the career advisor's answer over server-sent events.
 *
 * The hook owns the request, so a new question or a cancel aborts the one that is still running.
 */
export function useAdvisorStream(): UseAdvisorStreamReturn {
    const [answer, setAnswer] = useState('');
    const [status, setStatus] = useState<AdvisorStreamStatus>('idle');
    const [error, setError] = useState<string | null>(null);
    const [fieldError, setFieldError] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);

    const send = useCallback(async (question: string): Promise<void> => {
        request.current?.abort();

        const controller = new AbortController();
        request.current = controller;

        const isStale = (): boolean => request.current !== controller;

        setAnswer('');
        setError(null);
        setFieldError(null);
        setStatus('streaming');

        let response: Response;

        try {
            response = await fetch(advisorStream.url(), {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ question }),
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

        const setters = { setAnswer, setStatus, setError, setFieldError };

        if (!response.ok) {
            await reportFailure(setters, response);

            return;
        }

        await readEvents(setters, response, isStale);
    }, []);

    const cancel = useCallback((): void => {
        request.current?.abort();
        request.current = null;

        setStatus((current) => (current === 'streaming' ? 'idle' : current));
    }, []);

    useEffect(() => {
        return () => request.current?.abort();
    }, []);

    return { answer, status, error, fieldError, send, cancel };
}
