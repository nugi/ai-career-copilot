import { useLayoutEffect, useRef } from 'react';
import type { ChangeEvent, ComponentProps, KeyboardEvent } from 'react';
import { Input } from '@/components/ui/input';

type MoneyInputProps = Omit<
    ComponentProps<'input'>,
    'value' | 'onChange' | 'type' | 'inputMode'
> & {
    /** The amount as plain digits, for example "25000000", or "" when empty. */
    value: string;
    onValueChange: (digits: string) => void;
    /** The locale whose digit grouping to show, for example "id-ID" (25.000.000) or "en-US" (25,000). */
    locale: string;
    /** The most digits the amount may have. */
    maxDigits?: number;
};

const NON_DIGITS = /\D/g;

function formatDigits(digits: string, locale: string): string {
    if (digits === '') {
        return '';
    }

    return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(
        BigInt(digits),
    );
}

/**
 * Return the caret position in a formatted value that follows the given number of digits.
 */
function caretAfterDigits(formatted: string, digitCount: number): number {
    if (digitCount === 0) {
        return 0;
    }

    let seen = 0;

    for (let position = 0; position < formatted.length; position++) {
        if (/\d/.test(formatted[position])) {
            seen++;
        }

        if (seen === digitCount) {
            return position + 1;
        }
    }

    return formatted.length;
}

/**
 * A whole-number amount input that shows digit grouping while keeping the value as plain digits.
 */
export default function MoneyInput({
    value,
    onValueChange,
    locale,
    maxDigits = 12,
    onKeyDown,
    ...props
}: MoneyInputProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const pendingDigitsBeforeCaret = useRef<number | null>(null);
    const formatted = formatDigits(value, locale);

    useLayoutEffect(() => {
        const input = inputRef.current;
        const digitsBeforeCaret = pendingDigitsBeforeCaret.current;

        if (input === null || digitsBeforeCaret === null) {
            return;
        }

        pendingDigitsBeforeCaret.current = null;
        const caret = caretAfterDigits(formatted, digitsBeforeCaret);
        input.setSelectionRange(caret, caret);
    }, [formatted]);

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        const raw = event.target.value;
        const caret = event.target.selectionStart ?? raw.length;
        const digits = raw
            .replace(NON_DIGITS, '')
            .replace(/^0+/, '')
            .slice(0, maxDigits);

        pendingDigitsBeforeCaret.current = Math.min(
            raw.slice(0, caret).replace(NON_DIGITS, '').length,
            digits.length,
        );
        onValueChange(digits);
    };

    // Step over a group separator so Backspace and Delete remove the digit next to it.
    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const start = input.selectionStart;

        if (start !== null && start === input.selectionEnd) {
            if (
                event.key === 'Backspace' &&
                start > 0 &&
                /\D/.test(input.value[start - 1])
            ) {
                input.setSelectionRange(start - 1, start - 1);
            } else if (
                event.key === 'Delete' &&
                start < input.value.length &&
                /\D/.test(input.value[start])
            ) {
                input.setSelectionRange(start + 1, start + 1);
            }
        }

        onKeyDown?.(event);
    };

    return (
        <Input
            {...props}
            ref={inputRef}
            type="text"
            inputMode="numeric"
            autoComplete="off"
            value={formatted}
            onChange={handleChange}
            onKeyDown={handleKeyDown}
        />
    );
}
