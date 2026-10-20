import { Head, useForm, useHttp } from '@inertiajs/react';
import { FileText, FileUp, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent, FormEvent, MouseEvent } from 'react';
import ResumeController from '@/actions/App/Http/Controllers/ResumeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    content as resumeContent,
    index as resumesIndex,
} from '@/routes/resumes';

type Resume = {
    id: number;
    label: string;
    file_name: string;
    file_size: number;
    is_primary: boolean;
    created_at: string;
    has_content: boolean;
};

type ResumesProps = {
    resumes: Resume[];
};

type ResumeContentResponse = {
    content: string;
};

type ResumeForm = {
    file: File | null;
};

const MAX_FILE_SIZE = 2 * 1024 * 1024;

const dateFormatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
});

/**
 * Format a byte count as KB, or as MB from 1 MB up. Show at least 1 KB for any file that is not empty.
 */
function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * Return true when the file is a PDF by MIME type or by file name.
 */
function isPdf(file: File): boolean {
    return (
        file.type === 'application/pdf' ||
        file.name.toLowerCase().endsWith('.pdf')
    );
}

function ResumeItem({ resume }: { resume: Resume }) {
    const [isOpen, setIsOpen] = useState(false);
    const [text, setText] = useState<string | null>(null);
    const [hasLoadFailed, setHasLoadFailed] = useState(false);
    const { submit, processing } = useHttp();

    const loadText = async (): Promise<void> => {
        setHasLoadFailed(false);

        try {
            const response = (await submit(
                resumeContent(resume.id),
            )) as ResumeContentResponse;

            setText(response.content);
        } catch {
            setHasLoadFailed(true);
        }
    };

    const handleOpenChange = (open: boolean): void => {
        setIsOpen(open);

        // Fetch the text once. A second open keeps the text that is already loaded.
        if (open && text === null && !processing) {
            void loadText();
        }
    };

    return (
        <Card className="gap-4 py-5">
            <div className="flex flex-col gap-1 px-6">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-medium">{resume.label}</h3>
                    {resume.is_primary && <Badge>Primary</Badge>}
                </div>
                <p className="text-sm text-muted-foreground">
                    {resume.file_name} · {formatSize(resume.file_size)} ·{' '}
                    {dateFormatter.format(new Date(resume.created_at))}
                </p>
            </div>

            {resume.has_content && (
                <Collapsible
                    open={isOpen}
                    onOpenChange={handleOpenChange}
                    className="flex flex-col gap-3 px-6"
                >
                    <div>
                        <CollapsibleTrigger asChild>
                            <Button variant="outline" size="sm">
                                {isOpen
                                    ? 'Hide redacted text'
                                    : 'Show redacted text'}
                            </Button>
                        </CollapsibleTrigger>
                    </div>
                    <CollapsibleContent className="flex flex-col gap-2">
                        <p className="text-xs text-muted-foreground">
                            Personal details are replaced with placeholders such
                            as [EMAIL], [PHONE] and [NAME].
                        </p>
                        {text !== null ? (
                            <pre className="max-h-96 overflow-auto rounded-md bg-muted p-4 font-mono text-xs whitespace-pre-wrap">
                                {text}
                            </pre>
                        ) : hasLoadFailed ? (
                            <div className="flex flex-col items-start gap-2">
                                <p className="text-sm text-destructive">
                                    We couldn't load the text. Try again.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    onClick={() => void loadText()}
                                >
                                    Retry
                                </Button>
                            </div>
                        ) : (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner />
                                Loading…
                            </p>
                        )}
                    </CollapsibleContent>
                </Collapsible>
            )}
        </Card>
    );
}

export default function Resumes({ resumes }: ResumesProps) {
    const form = useForm<ResumeForm>({ file: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const [clientError, setClientError] = useState<string>();
    const [isDragging, setIsDragging] = useState(false);

    const percentage = form.progress?.percentage ?? 0;
    const isReading = form.processing && percentage >= 100;
    const errorMessage = clientError ?? form.errors.file;

    const clearInput = () => {
        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const selectFile = (file: File | null) => {
        if (file === null) {
            setClientError(undefined);
            form.setData('file', null);
            clearInput();

            return;
        }

        if (!isPdf(file)) {
            setClientError('Choose a PDF file.');
            form.setData('file', null);
            clearInput();

            return;
        }

        if (file.size > MAX_FILE_SIZE) {
            setClientError('This file is larger than 2 MB.');
            form.setData('file', null);
            clearInput();

            return;
        }

        setClientError(undefined);
        form.clearErrors('file');
        form.setData('file', file);
    };

    const removeFile = (event: MouseEvent<HTMLButtonElement>) => {
        // Stop the surrounding label from opening the file picker.
        event.preventDefault();
        event.stopPropagation();
        selectFile(null);
    };

    const handleDragOver = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();

        if (!form.processing) {
            setIsDragging(true);
        }
    };

    const handleDragLeave = (event: DragEvent<HTMLDivElement>) => {
        // Moving onto a child element also fires dragleave. Keep the highlight then.
        if (
            event.relatedTarget instanceof Node &&
            event.currentTarget.contains(event.relatedTarget)
        ) {
            return;
        }

        setIsDragging(false);
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);

        if (!form.processing) {
            selectFile(event.dataTransfer.files[0] ?? null);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ResumeController.store(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                clearInput();
                setClientError(undefined);
            },
        });
    };

    return (
        <>
            <Head title="Resumes" />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-8">
                <Heading
                    title="Resumes"
                    description="Upload your resume as a PDF. Contact details are removed before any AI feature reads it."
                />

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        onDrop={handleDrop}
                        className={cn(
                            'rounded-xl border border-dashed transition-colors',
                            isDragging
                                ? 'border-primary bg-muted'
                                : 'border-input',
                            form.processing && 'pointer-events-none opacity-50',
                        )}
                    >
                        <label
                            htmlFor="file"
                            aria-disabled={form.processing}
                            className="flex cursor-pointer flex-col items-center gap-3 rounded-xl p-6 text-center focus-within:ring-[3px] focus-within:ring-ring/50"
                        >
                            <input
                                id="file"
                                ref={fileInput}
                                type="file"
                                accept="application/pdf"
                                className="sr-only"
                                disabled={form.processing}
                                aria-invalid={errorMessage !== undefined}
                                onChange={(event) =>
                                    selectFile(event.target.files?.[0] ?? null)
                                }
                            />

                            {form.data.file ? (
                                <div className="flex w-full items-center gap-3 text-left">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted">
                                        <FileText
                                            className="size-5 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate font-medium">
                                            {form.data.file.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {formatSize(form.data.file.size)}
                                        </span>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Remove file"
                                        disabled={form.processing}
                                        onClick={removeFile}
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <FileUp
                                            className="size-6 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <span className="font-medium">
                                        Drag your PDF here, or browse
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        PDF with selectable text, up to 2 MB
                                    </span>
                                </>
                            )}
                        </label>
                    </div>

                    <InputError message={errorMessage} />

                    {form.processing && form.progress && (
                        <div className="flex flex-col gap-2">
                            <div
                                role="progressbar"
                                aria-label="Upload progress"
                                aria-valuenow={percentage}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                className="h-2 w-full overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{ width: `${percentage}%` }}
                                />
                            </div>
                            <p className="text-sm text-muted-foreground tabular-nums">
                                {percentage}%
                            </p>
                        </div>
                    )}

                    {isReading && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner />
                            Reading and removing contact details…
                        </p>
                    )}

                    {form.data.file && (
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Upload resume
                            </Button>
                        </div>
                    )}
                </form>

                <section className="flex flex-col gap-4">
                    {resumes.length === 0 ? (
                        <Card className="items-center gap-2 px-6 py-10 text-center">
                            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                                <FileText
                                    className="size-6 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </div>
                            <p className="font-medium">No resumes yet</p>
                            <p className="text-sm text-muted-foreground">
                                Upload your first resume above. It becomes your
                                primary resume.
                            </p>
                        </Card>
                    ) : (
                        resumes.map((resume) => (
                            <ResumeItem key={resume.id} resume={resume} />
                        ))
                    )}
                </section>
            </div>
        </>
    );
}

Resumes.layout = {
    breadcrumbs: [
        {
            title: 'Resumes',
            href: resumesIndex(),
        },
    ],
};
