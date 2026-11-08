import { PIN } from '@/lib/brand';
import { cn } from '@/lib/utils';

// Griot's small mark (docs/brand.md): the pin of the provisional logo on a brown square with
// round corners. The brown is the band's token (bg-hero), the same in both themes; the square
// keeps a border in forced colours, where its background is gone. Decorative: the name of the
// site is always written next to it, or given to the link that holds it.
export default function AppLogoIcon({ className }: { className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex items-center justify-center rounded-[22%] bg-hero forced-colors:border',
                className,
            )}
        >
            <img
                src={PIN.src}
                srcSet={PIN.srcSet}
                width={PIN.width}
                height={PIN.height}
                alt=""
                className="h-[64%] w-auto"
            />
        </span>
    );
}
