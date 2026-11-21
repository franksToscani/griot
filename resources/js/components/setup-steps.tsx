import { Link, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { wrappingLabel } from '@/lib/form-classes';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import type { SetupStep, SetupStepKey, SetupStepsData } from '@/types';

// The guide to the first steps of a provider not published yet (#53, App\Profile\SetupSteps,
// docs/accessibility.md, « Guida ai primi passi »): the pages that already exist are the steps, so
// this is only an indicator at the top and "Back / Next" at the bottom. It never redirects nor
// blocks: every page works on its own, and published providers never see it.

// The h1 of the four pages: a step reached from the guide moves the focus there.
export const PAGE_TITLE_ID = 'page-title';

// Reached by script only (tabIndex -1), never with Tab: no ring, the outline kept for forced colours.
export const PAGE_TITLE_FOCUS = 'outline-none focus-visible:outline-hidden';

// The looks of the guide, in one place. A step is a circle: filled with a check when done, in the
// brand colour when it is the one to do next, neutral otherwise. The colour is never the only sign:
// the check or the number, and the state always written under the name.
const CIRCLE =
    'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-sm font-semibold';
const CIRCLE_DONE = 'border-primary bg-primary text-primary-foreground';
const CIRCLE_NEXT = 'border-primary bg-card text-primary';
const CIRCLE_LATER = 'border-border bg-card text-muted-foreground';

// A step as a link: 44 px on phones, the kit's focus ring.
const STEP_LINK = cn(
    'rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
    touchTarget,
);

// The page of the step the person is on: underlined, never shown by colour alone.
const ON_PAGE =
    'aria-[current=step]:underline aria-[current=step]:underline-offset-4';

// After a visit from the guide (a GET, no flash): the title of the new page. A save focuses its
// own item instead (useFlashFocus), with the flash.
export function focusPageTitle(): void {
    window.requestAnimationFrame(() =>
        document.getElementById(PAGE_TITLE_ID)?.focus(),
    );
}

function useSetupSteps(): SetupStepsData | null {
    return usePage().props.setup_steps ?? null;
}

type Look = 'done' | 'next' | 'later';

function lookOf(step: SetupStep, data: SetupStepsData): Look {
    if (step.state === 'done') {
        return 'done';
    }

    return step.key === data.current ? 'next' : 'later';
}

// The circle: a check when done, the number otherwise. Hidden from screen readers: the ordered list
// gives the position, the written state gives the rest.
function StepCircle({ look, number }: { look: Look; number: number }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                CIRCLE,
                look === 'done'
                    ? CIRCLE_DONE
                    : look === 'next'
                      ? CIRCLE_NEXT
                      : CIRCLE_LATER,
            )}
        >
            {look === 'done' ? <Check className="size-4" /> : number}
        </span>
    );
}

// The name of the step and its state, always written: "fatto", "da fare", "fatto, facoltativo".
function StepText({ step, look }: { step: SetupStep; look: Look }) {
    const { t } = useTranslation();

    return (
        <span className="flex flex-col">
            <span
                className={cn(
                    'text-sm font-medium',
                    look === 'next' && 'font-semibold text-primary',
                )}
            >
                {t(`setup.step.${step.key}`)}
            </span>
            <span className="text-xs text-muted-foreground">
                {t(`setup.state.${step.state}`)}
                {step.optional && `, ${t('setup.optional')}`}
            </span>
        </span>
    );
}

// From 768 px: the four steps on one line, never wrapping (a grid of 4 columns), each a circle with
// its name and state under it, joined by a line that is only decoration.
function StepsRow({
    data,
    page,
}: {
    data: SetupStepsData;
    page: SetupStepKey;
}) {
    return (
        <ol className="grid grid-cols-4">
            {data.steps.map((step, index) => {
                const look = lookOf(step, data);

                return (
                    <li key={step.key} className="relative flex justify-center">
                        {index > 0 && (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute top-4 right-1/2 left-[-50%] h-0.5',
                                    data.steps[index - 1].state === 'done'
                                        ? 'bg-primary'
                                        : 'bg-border',
                                )}
                            />
                        )}
                        <Link
                            href={step.url}
                            onSuccess={focusPageTitle}
                            aria-current={
                                step.key === page ? 'step' : undefined
                            }
                            className={cn(
                                'flex flex-col items-center gap-1 px-1 text-center',
                                STEP_LINK,
                                ON_PAGE,
                            )}
                        >
                            <StepCircle look={look} number={index + 1} />
                            <StepText step={step} look={look} />
                        </Link>
                    </li>
                );
            })}
        </ol>
    );
}

// Below 768 px, inside the details: one step per line, the circle on the left.
function StepsColumn({
    data,
    page,
}: {
    data: SetupStepsData;
    page: SetupStepKey;
}) {
    return (
        <ol className="flex flex-col gap-1">
            {data.steps.map((step, index) => {
                const look = lookOf(step, data);

                return (
                    <li key={step.key}>
                        <Link
                            href={step.url}
                            onSuccess={focusPageTitle}
                            aria-current={
                                step.key === page ? 'step' : undefined
                            }
                            className={cn(
                                'flex items-center gap-3 py-1',
                                STEP_LINK,
                                ON_PAGE,
                            )}
                        >
                            <StepCircle look={look} number={index + 1} />
                            <StepText step={step} look={look} />
                        </Link>
                    </li>
                );
            })}
        </ol>
    );
}

// At the top of the page. Below 768 px one line, "Passo 2 di 4: I tuoi servizi", a bar of four
// segments (decoration) and the steps folded in a native <details>; from 768 px the row of circles.
// Under the steps one button to the step to do next, when it is not this page.
export function SetupStepsNav({ page }: { page: SetupStepKey }) {
    const { t } = useTranslation();
    const data = useSetupSteps();

    if (!data) {
        return null;
    }

    const number = data.steps.findIndex((step) => step.key === page) + 1;
    const next = data.steps.find((step) => step.key === data.current);

    return (
        <nav
            aria-labelledby="setup-steps-title"
            className="flex max-w-2xl flex-col gap-4 rounded-xl border bg-card p-4 text-card-foreground"
        >
            {/* A line of text, not a heading: the page's h1 stays its first heading. */}
            <p id="setup-steps-title" className="font-semibold">
                {t('setup.title')}
            </p>

            <details className="md:hidden">
                <summary
                    className={`cursor-pointer rounded-sm text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden ${touchTarget}`}
                >
                    {t('setup.compact', {
                        number,
                        total: data.steps.length,
                        step: t(`setup.step.${page}`),
                    })}
                </summary>
                <div className="mt-3">
                    <StepsColumn data={data} page={page} />
                </div>
            </details>
            {/* Decoration: the same states as the list, which says them in words. */}
            <div
                aria-hidden="true"
                className="grid grid-cols-4 gap-1 md:hidden"
            >
                {data.steps.map((step) => (
                    <span
                        key={step.key}
                        className={cn(
                            'h-1.5 rounded-full',
                            step.state === 'done' ? 'bg-primary' : 'bg-border',
                        )}
                    />
                ))}
            </div>

            <div className="hidden md:block">
                <StepsRow data={data} page={page} />
            </div>

            {next &&
                next.key !== page && (
                    // On the dashboard "Pubblica il profilo" is the main button of the screen: here an
                    // outline one, never two main buttons together.
                    <Button
                        asChild
                        variant={page === 'publish' ? 'outline' : 'default'}
                        className={`self-start ${wrappingLabel}`}
                    >
                        <Link href={next.url} onSuccess={focusPageTitle}>
                            {t('setup.go_to', {
                                step: t(`setup.step.${next.key}`),
                            })}
                        </Link>
                    </Button>
                )}
        </nav>
    );
}

// At the bottom of the page: "Back" (outline) and "Next" (the main button), in the order of the
// document. On the dashboard, the last step, only "Back".
export function SetupStepsFooter({ page }: { page: SetupStepKey }) {
    const { t } = useTranslation();
    const data = useSetupSteps();

    if (!data) {
        return null;
    }

    const index = data.steps.findIndex((step) => step.key === page);
    const previous = index > 0 ? data.steps[index - 1] : undefined;
    const following =
        index >= 0 && index < data.steps.length - 1
            ? data.steps[index + 1]
            : undefined;

    return (
        <nav
            aria-labelledby="setup-steps-footer-title"
            className="flex max-w-2xl flex-wrap items-center justify-between gap-3"
        >
            <p id="setup-steps-footer-title" className="sr-only">
                {t('setup.title')}
            </p>
            {previous ? (
                <Button asChild variant="outline" className={wrappingLabel}>
                    <Link href={previous.url} onSuccess={focusPageTitle}>
                        {t('setup.back', {
                            step: t(`setup.step.${previous.key}`),
                        })}
                    </Link>
                </Button>
            ) : (
                <span />
            )}
            {following && (
                <Button asChild className={wrappingLabel}>
                    <Link href={following.url} onSuccess={focusPageTitle}>
                        {t('setup.forward', {
                            step: t(`setup.step.${following.key}`),
                        })}
                    </Link>
                </Button>
            )}
        </nav>
    );
}
