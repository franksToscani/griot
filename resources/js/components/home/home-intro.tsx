import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/providers';

// The opening block of the home page, with its one h1 and the main action. On its own, so that
// the carousel (issue #144, step 5) can go above it or take its place.
export function HomeIntro() {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col items-start gap-4">
            <h1 className="text-3xl font-semibold">{t('home.intro.title')}</h1>
            <p className="max-w-2xl text-muted-foreground">
                {t('home.intro.text')}
            </p>
            {/* A link that looks like a button: it opens a page. The kit's Button is 44px high on
                phones, full width there. */}
            <Button asChild className="max-md:w-full">
                <Link href={index()}>{t('home.intro.cta')}</Link>
            </Button>
        </div>
    );
}
