import { CalendarCheck, MailCheck, Search } from 'lucide-react';
import { useReveal } from '@/hooks/use-reveal';
import { useTranslation } from '@/hooks/use-translation';
import { revealDelay } from '@/lib/motion';

// The id of the title: the help panel of the home page takes the focus there (floating-actions).
export const HOW_IT_WORKS_TITLE_ID = 'home-steps-title';

// The three steps of "Come funziona", in their order: the keys of their texts.
const STEPS = ['choose', 'book', 'confirm'] as const;

// The icon of each step: decorative, the step says it in words.
const STEP_ICONS = {
    choose: Search,
    book: CalendarCheck,
    confirm: MailCheck,
} as const;

// "Come funziona" on the home page (docs/home.md): three steps along a line. From 768 px the three
// circles with their icon stand in a row, joined by a thin line of the accent colour, and under each
// circle a card with the number, the title and the text. On phones the steps go one under the
// other, the circle on the left and the card on its right; the line runs down between the
// circles, never over a text. The line, the circles and the badges are decorative: the number is
// in the text of each h3 ("Passo 1"). The steps appear one after the other when scrolled to.
export function HowItWorks() {
    const { t } = useTranslation();
    const steps = useReveal<HTMLOListElement>();

    return (
        <section
            aria-labelledby={HOW_IT_WORKS_TITLE_ID}
            className="flex flex-col gap-6"
        >
            <div className="flex flex-col gap-2">
                {/* tabIndex -1: the focus can be put here by the help panel, never by the Tab. */}
                <h2
                    id={HOW_IT_WORKS_TITLE_ID}
                    tabIndex={-1}
                    className="text-xl font-semibold outline-none focus-visible:outline-hidden"
                >
                    {t('home.steps.title')}
                </h2>
                {/* A short orange bar under the title: decorative, no text on it, no meaning.
                    Gone in forced colours, where it would say nothing. */}
                <span
                    aria-hidden="true"
                    className="block h-1 w-12 rounded-full bg-brand forced-colors:hidden"
                />
            </div>
            <ol ref={steps} className="relative grid gap-6 md:grid-cols-3">
                {/* The line between the centres of the first and the last circle, from 768 px:
                    behind the circles, at half their height (3rem), never under a text. */}
                <span
                    aria-hidden="true"
                    className="absolute top-[1.4375rem] right-[calc((100%_-_3rem)/6)] left-[calc((100%_-_3rem)/6)] hidden h-0.5 bg-brand md:block forced-colors:hidden"
                />
                {STEPS.map((step, index) => {
                    const Icon = STEP_ICONS[step];

                    return (
                        <li
                            key={step}
                            className="reveal-item relative grid grid-cols-[3rem_1fr] items-start gap-4 md:flex md:flex-col md:items-center"
                            style={revealDelay(index)}
                        >
                            {/* On phones the line goes down from this circle to the next one,
                                in the column of the circles, beside the cards. */}
                            {index < STEPS.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className="absolute top-12 -bottom-6 left-[calc(1.5rem_-_1px)] w-0.5 bg-brand md:hidden forced-colors:hidden"
                                />
                            )}
                            {/* The icon in an amber circle: decorative, above the line. In forced
                                colours the circle keeps a border. */}
                            <span
                                aria-hidden="true"
                                className="relative flex size-12 items-center justify-center rounded-full bg-highlight text-highlight-foreground forced-colors:border"
                            >
                                <Icon className="size-6" />
                            </span>
                            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 text-card-foreground md:w-full">
                                {/* The number in a badge: decorative, it is in the heading too. */}
                                <span
                                    aria-hidden="true"
                                    className="flex size-7 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground forced-colors:border"
                                >
                                    {index + 1}
                                </span>
                                {/* The number of the step is in the heading: "Passo 1". */}
                                <h3 className="font-medium">
                                    <span className="block text-sm font-semibold text-muted-foreground">
                                        {t('home.steps.number', {
                                            number: index + 1,
                                        })}
                                    </span>
                                    {t(`home.steps.${step}.title`)}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {t(`home.steps.${step}.text`)}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
