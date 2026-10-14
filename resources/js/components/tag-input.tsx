import { X } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type TagInputProps = {
    id: string;
    values: string[];
    onChange: (values: string[]) => void;
    placeholder?: string;
    max?: number;
    invalid?: boolean;
};

/**
 * A text input that turns each entry into a removable tag. Press Enter or a comma to add a tag.
 */
export default function TagInput({
    id,
    values,
    onChange,
    placeholder,
    max,
    invalid = false,
}: TagInputProps) {
    const [draft, setDraft] = useState('');
    const isFull = max !== undefined && values.length >= max;

    const add = () => {
        const value = draft.replace(/\s+/g, ' ').trim();
        setDraft('');

        const isDuplicate = values.some(
            (existing) => existing.toLowerCase() === value.toLowerCase(),
        );

        if (value !== '' && !isDuplicate && !isFull) {
            onChange([...values, value]);
        }
    };

    const remove = (index: number) => {
        onChange(values.filter((_, position) => position !== index));
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            add();
        } else if (
            event.key === 'Backspace' &&
            draft === '' &&
            values.length > 0
        ) {
            remove(values.length - 1);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            {values.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {values.map((value, index) => (
                        <li key={value}>
                            <Badge variant="secondary" className="pr-0.5">
                                {value}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    onClick={() => remove(index)}
                                    aria-label={`Remove ${value}`}
                                >
                                    <X />
                                </Button>
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}

            <Input
                id={id}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={add}
                placeholder={isFull ? `Up to ${max}` : placeholder}
                disabled={isFull}
                aria-invalid={invalid}
            />
        </div>
    );
}
