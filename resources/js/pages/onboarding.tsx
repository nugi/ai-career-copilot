import { Head, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import OnboardingController from '@/actions/App/Http/Controllers/OnboardingController';
import Heading from '@/components/heading';
import MoneyInput from '@/components/money-input';
import TagInput from '@/components/tag-input';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSeparator,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemGroup,
    ItemTitle,
} from '@/components/ui/item';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { edit } from '@/routes/onboarding';

type Option = {
    value: string;
    label: string;
};

type SkillEntry = {
    name: string;
    proficiency: string;
};

type OnboardingForm = {
    headline: string;
    target_roles: string[];
    preferred_locations: string[];
    work_arrangements: string[];
    min_salary: string;
    salary_currency: string;
    salary_period: string;
    skills: SkillEntry[];
};

type OnboardingProps = {
    profile: {
        headline: string | null;
        target_roles: string[];
        preferred_locations: string[];
        work_arrangements: string[];
        min_salary: number | null;
        salary_currency: string;
        salary_period: string;
    };
    skills: SkillEntry[];
    isComplete: boolean;
    skillSuggestions: string[];
    options: {
        workArrangements: Option[];
        currencies: Option[];
        salaryPeriods: Option[];
        proficiencies: Option[];
    };
};

const DEFAULT_PROFICIENCY = 'intermediate';

const MAX_SUGGESTIONS = 8;

/**
 * The locale whose digit grouping each currency is usually written in.
 */
const CURRENCY_LOCALES: Partial<Record<string, string>> = {
    IDR: 'id-ID',
    USD: 'en-US',
};

/**
 * Return the first error for a list field or any of its items.
 */
function listError(
    errors: Partial<Record<string, string>>,
    field: string,
): string | undefined {
    return (
        errors[field] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}

export default function Onboarding({
    profile,
    skills,
    isComplete,
    skillSuggestions,
    options,
}: OnboardingProps) {
    const form = useForm<OnboardingForm>({
        headline: profile.headline ?? '',
        target_roles: profile.target_roles,
        preferred_locations: profile.preferred_locations,
        work_arrangements: profile.work_arrangements,
        min_salary: profile.min_salary?.toString() ?? '',
        salary_currency: profile.salary_currency,
        salary_period: profile.salary_period,
        skills,
    });
    const [skillDraft, setSkillDraft] = useState('');

    const targetRolesError = listError(form.errors, 'target_roles');
    const locationsError = listError(form.errors, 'preferred_locations');
    const arrangementsError = listError(form.errors, 'work_arrangements');
    const salaryError =
        form.errors.min_salary ??
        form.errors.salary_currency ??
        form.errors.salary_period;
    const skillsError = listError(form.errors, 'skills');

    const hasSkill = (name: string) =>
        form.data.skills.some(
            (skill) => skill.name.toLowerCase() === name.toLowerCase(),
        );

    const query = skillDraft.trim().toLowerCase();
    const suggestions = skillSuggestions
        .filter(
            (suggestion) =>
                suggestion.toLowerCase().includes(query) &&
                !hasSkill(suggestion),
        )
        .slice(0, MAX_SUGGESTIONS);

    const addSkill = (typed: string = skillDraft) => {
        const name = typed.replace(/\s+/g, ' ').trim();
        setSkillDraft('');

        if (name !== '' && !hasSkill(name)) {
            form.setData('skills', [
                ...form.data.skills,
                { name, proficiency: DEFAULT_PROFICIENCY },
            ]);
        }
    };

    const handleSkillKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addSkill();
        }
    };

    const setProficiency = (index: number, proficiency: string) => {
        form.setData(
            'skills',
            form.data.skills.map((skill, position) =>
                position === index ? { ...skill, proficiency } : skill,
            ),
        );
    };

    const removeSkill = (index: number) => {
        form.setData(
            'skills',
            form.data.skills.filter((_, position) => position !== index),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(OnboardingController.update(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={isComplete ? 'Job preferences' : 'Get started'} />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-8">
                <Heading
                    title={
                        isComplete
                            ? 'Job preferences'
                            : 'Tell us what you are looking for'
                    }
                    description="We use this to find and score jobs that fit you. You can change it any time."
                />

                <form onSubmit={submit} className="flex flex-col gap-8">
                    <FieldSet>
                        <FieldLegend>The job you want</FieldLegend>
                        <FieldDescription>
                            Roles you are aiming for and how you want to work.
                        </FieldDescription>

                        <FieldGroup>
                            <Field
                                data-invalid={targetRolesError !== undefined}
                            >
                                <FieldLabel htmlFor="target_roles">
                                    Target roles
                                </FieldLabel>
                                <TagInput
                                    id="target_roles"
                                    values={form.data.target_roles}
                                    onChange={(values) =>
                                        form.setData('target_roles', values)
                                    }
                                    placeholder="e.g. Backend Engineer"
                                    max={5}
                                    invalid={targetRolesError !== undefined}
                                />
                                <FieldDescription>
                                    Press Enter after each role. Up to 5.
                                </FieldDescription>
                                <FieldError>{targetRolesError}</FieldError>
                            </Field>

                            <Field
                                data-invalid={arrangementsError !== undefined}
                            >
                                <FieldTitle id="work_arrangements_label">
                                    Ways of working
                                </FieldTitle>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    spacing={2}
                                    value={form.data.work_arrangements}
                                    onValueChange={(values) =>
                                        form.setData(
                                            'work_arrangements',
                                            values,
                                        )
                                    }
                                    aria-labelledby="work_arrangements_label"
                                    aria-invalid={
                                        arrangementsError !== undefined
                                    }
                                >
                                    {options.workArrangements.map((option) => (
                                        <ToggleGroupItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <FieldError>{arrangementsError}</FieldError>
                            </Field>

                            <Field data-invalid={locationsError !== undefined}>
                                <FieldLabel htmlFor="preferred_locations">
                                    Preferred locations (optional)
                                </FieldLabel>
                                <TagInput
                                    id="preferred_locations"
                                    values={form.data.preferred_locations}
                                    onChange={(values) =>
                                        form.setData(
                                            'preferred_locations',
                                            values,
                                        )
                                    }
                                    placeholder="e.g. Jakarta"
                                    max={10}
                                    invalid={locationsError !== undefined}
                                />
                                <FieldError>{locationsError}</FieldError>
                            </Field>

                            <Field data-invalid={salaryError !== undefined}>
                                <FieldLabel htmlFor="min_salary">
                                    Minimum salary (optional)
                                </FieldLabel>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <Select
                                        value={form.data.salary_currency}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'salary_currency',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            className="sm:w-32"
                                            aria-label="Currency"
                                            aria-invalid={
                                                form.errors.salary_currency !==
                                                undefined
                                            }
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {options.currencies.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <MoneyInput
                                        id="min_salary"
                                        value={form.data.min_salary}
                                        onValueChange={(digits) =>
                                            form.setData('min_salary', digits)
                                        }
                                        locale={
                                            CURRENCY_LOCALES[
                                                form.data.salary_currency
                                            ] ?? 'en-US'
                                        }
                                        placeholder={
                                            form.data.salary_currency === 'IDR'
                                                ? '20.000.000'
                                                : '60,000'
                                        }
                                        aria-invalid={
                                            form.errors.min_salary !== undefined
                                        }
                                    />
                                    <Select
                                        value={form.data.salary_period}
                                        onValueChange={(value) =>
                                            form.setData('salary_period', value)
                                        }
                                    >
                                        <SelectTrigger
                                            className="sm:w-36"
                                            aria-label="Salary period"
                                            aria-invalid={
                                                form.errors.salary_period !==
                                                undefined
                                            }
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {options.salaryPeriods.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <FieldDescription>
                                    Gross, before tax. Jobs that do not list a
                                    salary are still shown.
                                </FieldDescription>
                                <FieldError>{salaryError}</FieldError>
                            </Field>
                        </FieldGroup>
                    </FieldSet>

                    <FieldSeparator />

                    <FieldSet>
                        <FieldLegend>Your skills</FieldLegend>
                        <FieldDescription>
                            We match these against what each job asks for.
                        </FieldDescription>

                        <FieldGroup>
                            <Field data-invalid={skillsError !== undefined}>
                                <FieldLabel htmlFor="skill">
                                    Add a skill
                                </FieldLabel>
                                <InputGroup>
                                    <InputGroupInput
                                        id="skill"
                                        value={skillDraft}
                                        onChange={(event) =>
                                            setSkillDraft(event.target.value)
                                        }
                                        onKeyDown={handleSkillKeyDown}
                                        placeholder="e.g. Laravel"
                                        aria-invalid={skillsError !== undefined}
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            onClick={() => addSkill()}
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                </InputGroup>
                                {suggestions.length > 0 && (
                                    <div
                                        className="flex flex-wrap gap-2"
                                        aria-label="Suggested skills"
                                    >
                                        {suggestions.map((suggestion) => (
                                            <Button
                                                key={suggestion}
                                                type="button"
                                                variant="outline"
                                                size="xs"
                                                onClick={() =>
                                                    addSkill(suggestion)
                                                }
                                            >
                                                <Plus data-icon="inline-start" />
                                                {suggestion}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                                <FieldError>{skillsError}</FieldError>
                            </Field>

                            {form.data.skills.length > 0 && (
                                <ItemGroup className="gap-2">
                                    {form.data.skills.map((skill, index) => (
                                        <Item
                                            key={skill.name}
                                            variant="outline"
                                            size="sm"
                                        >
                                            <ItemContent>
                                                <ItemTitle>
                                                    {skill.name}
                                                </ItemTitle>
                                            </ItemContent>
                                            <ItemActions>
                                                <Select
                                                    value={skill.proficiency}
                                                    onValueChange={(value) =>
                                                        setProficiency(
                                                            index,
                                                            value,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="w-36"
                                                        aria-label={`Proficiency in ${skill.name}`}
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            {options.proficiencies.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    onClick={() =>
                                                        removeSkill(index)
                                                    }
                                                    aria-label={`Remove ${skill.name}`}
                                                >
                                                    <X />
                                                </Button>
                                            </ItemActions>
                                        </Item>
                                    ))}
                                </ItemGroup>
                            )}
                        </FieldGroup>
                    </FieldSet>

                    <Field orientation="horizontal">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="save-onboarding-button"
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            {isComplete ? 'Save preferences' : 'Find my jobs'}
                        </Button>
                    </Field>
                </form>
            </div>
        </>
    );
}

Onboarding.layout = {
    breadcrumbs: [
        {
            title: 'Job preferences',
            href: edit(),
        },
    ],
};
